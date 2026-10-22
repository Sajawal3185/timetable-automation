"""
routers/auth.py  (owner: Sajawal)

Endpoints:
    POST /api/auth/register   a student creates their own account (role forced to student)
    POST /api/auth/login      any user logs in, gets a JWT + their role
    POST /api/auth/activate   a rostered student sets their first password
    GET  /api/auth/me         who am I (used by the frontend on page refresh)
    POST /api/auth/users      admin creates teacher / hod / admin accounts

Design rules this file enforces:
  * ONE login endpoint for every role. The role comes from the database row,
    never from the request body.
  * There is NO public "register" endpoint that accepts a role. Students claim
    a pre-imported account (activate); staff accounts are created by an admin.
"""

import re

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, Field, field_validator
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.database import get_db
from app.dependencies import get_current_user
from app.core.security import (
    DUMMY_HASH,
    create_access_token,
    hash_password,
    verify_password,
)
from app.models.section import Section
from app.models.user import User

router = APIRouter(prefix="/api/auth", tags=["auth"])

# Roles an admin is allowed to create. "student" is deliberately missing:
# students come from the roster import, not from the admin form.
STAFF_ROLES = {"teacher", "hod", "admin"}

# NUML roll numbers look like "Se-9244330": letters, a hyphen, digits.
# Matched AFTER lowercasing. This only checks the SHAPE, it cannot prove the
# roll number really belongs to the person typing it (see README limitation).
# Loosen or tighten the digit range if other departments format theirs differently.
ROLL_NUMBER_PATTERN = re.compile(r"^[a-z]{2,4}-\d{6,8}$")


# ---------------------------------------------------------------------------
# Request / response schemas
# (small enough to live here; move to schemas/auth.py if the file grows)
# ---------------------------------------------------------------------------

def _check_password_length(value: str) -> str:
    # bcrypt silently ignores anything past 72 BYTES (and newer versions raise).
    # Count bytes, not characters, because non-ASCII characters take 2+ bytes.
    if len(value.encode("utf-8")) > 72:
        raise ValueError("Password is too long (max 72 bytes)")
    return value


class LoginRequest(BaseModel):
    identifier: str = Field(min_length=1)   # roll number or email
    password: str = Field(min_length=1)


class RegisterRequest(BaseModel):
    name: str = Field(min_length=1, max_length=100)
    identifier: str = Field(min_length=1)   # roll number
    section_id: int                          # must exist in the section table
    password: str = Field(min_length=8)
    # NOTE: there is deliberately NO role field. Pydantic ignores extra keys,
    # so even if a client sends {"role": "admin"} it is thrown away.

    _pw_len = field_validator("password")(_check_password_length)


class ActivateRequest(BaseModel):
    identifier: str = Field(min_length=1)
    # min_length 8 is a floor, not a policy; raise it if the team wants
    new_password: str = Field(min_length=8)

    _pw_len = field_validator("new_password")(_check_password_length)


class CreateUserRequest(BaseModel):
    name: str = Field(min_length=1)
    identifier: str = Field(min_length=1)
    password: str = Field(min_length=8)
    role: str

    _pw_len = field_validator("password")(_check_password_length)


class UserOut(BaseModel):
    id: int
    name: str
    role: str

    # lets Pydantic read straight from a SQLAlchemy User object
    model_config = {"from_attributes": True}


class LoginResponse(BaseModel):
    token: str
    user: UserOut


def _normalize(identifier: str) -> str:
    # Roll numbers / emails must match regardless of case or stray spaces,
    # otherwise "SE-123 " and "se-123" become two different users.
    return identifier.strip().lower()


# ---------------------------------------------------------------------------
# POST /api/auth/register  (student self-signup)
# ---------------------------------------------------------------------------

@router.post("/register", response_model=LoginResponse, status_code=status.HTTP_201_CREATED)
def register(data: RegisterRequest, db: Session = Depends(get_db)):
    identifier = _normalize(data.identifier)

    # Shape check on the roll number. Rejects emails, junk, and staff-style
    # identifiers, so this endpoint can only ever produce roll-number accounts.
    if not ROLL_NUMBER_PATTERN.match(identifier):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Roll number format is invalid (example: Se-9244330)",
        )

    # The section decides which timetable the student sees, so it must be a
    # real row, not a number the client made up.
    if db.get(Section, data.section_id) is None:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Unknown section")

    if db.query(User).filter(User.identifier == identifier).first():
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="This roll number is already registered",
        )

    user = User(
        name=data.name.strip(),
        identifier=identifier,
        password_hash=hash_password(data.password),
        role="student",           # hardcoded: the ONLY way role is set on this path
        section_id=data.section_id,
        is_active=True,
    )
    db.add(user)
    try:
        db.commit()
    except IntegrityError:
        # Two requests with the same roll number can both pass the check above
        # before either commits. The UNIQUE constraint on identifier catches the
        # loser here, so we return a clean 409 instead of a 500.
        db.rollback()
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="This roll number is already registered",
        )
    db.refresh(user)

    # Log them in immediately so signup is one step for the student.
    token = create_access_token(user_id=user.id, role=user.role)
    return LoginResponse(token=token, user=UserOut.model_validate(user))


# ---------------------------------------------------------------------------
# POST /api/auth/login
# ---------------------------------------------------------------------------

@router.post("/login", response_model=LoginResponse)
def login(data: LoginRequest, db: Session = Depends(get_db)):
    user = db.query(User).filter(User.identifier == _normalize(data.identifier)).first()

    # Always run ONE bcrypt comparison, even when the user doesn't exist or has
    # no password yet. If we returned early, "unknown user" would answer in ~1ms
    # and "wrong password" in ~100ms, letting an attacker discover which roll
    # numbers exist just by timing the responses.
    stored_hash = user.password_hash if (user and user.password_hash) else DUMMY_HASH
    password_ok = verify_password(data.password, stored_hash)

    # One identical error for every failure reason (unknown user, wrong
    # password, not activated, disabled) so nothing leaks about which it was.
    if not user or not user.password_hash or not password_ok or not user.is_active:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid credentials",
        )

    # The role is read from the DB row and signed into the token.
    token = create_access_token(user_id=user.id, role=user.role)
    return LoginResponse(token=token, user=UserOut.model_validate(user))


# ---------------------------------------------------------------------------
# POST /api/auth/activate  (students claim a pre-imported account)
# ---------------------------------------------------------------------------

@router.post("/activate", response_model=LoginResponse)
def activate(data: ActivateRequest, db: Session = Depends(get_db)):
    user = db.query(User).filter(User.identifier == _normalize(data.identifier)).first()

    # A claimable account is: exists + is a student + has no password yet.
    # Same vague message for every failure so this endpoint can't be used to
    # check which roll numbers are on the roster.
    claimable = user is not None and user.role == "student" and user.password_hash is None
    if not claimable or not user.is_active:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="This account cannot be activated",
        )

    user.password_hash = hash_password(data.new_password)
    db.commit()  # the account now has a password, so it can no longer be claimed

    # Log them straight in so the student doesn't have to type it all again.
    token = create_access_token(user_id=user.id, role=user.role)
    return LoginResponse(token=token, user=UserOut.model_validate(user))


# ---------------------------------------------------------------------------
# GET /api/auth/me
# ---------------------------------------------------------------------------

@router.get("/me", response_model=UserOut)
def me(current_user: User = Depends(get_current_user)):
    # The frontend calls this on page refresh to rebuild AuthContext
    # from the stored token.
    return current_user


# ---------------------------------------------------------------------------
# POST /api/auth/users  (admin only)
# ---------------------------------------------------------------------------

@router.post("/users", response_model=UserOut, status_code=status.HTTP_201_CREATED)
def create_staff_user(
    data: CreateUserRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(get_current_user),
):
    # Inline role check so this file has no dependency on Taha's permissions.py.
    # Once require_roles() exists there, replace these lines with:
    #     current_user = Depends(require_roles("admin"))
    if current_user.role != "admin":
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Not allowed")

    if data.role not in STAFF_ROLES:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"role must be one of: {', '.join(sorted(STAFF_ROLES))}",
        )

    identifier = _normalize(data.identifier)
    if db.query(User).filter(User.identifier == identifier).first():
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Identifier already exists")

    user = User(
        name=data.name.strip(),
        identifier=identifier,
        password_hash=hash_password(data.password),
        role=data.role,
        is_active=True,
    )
    db.add(user)
    db.commit()
    db.refresh(user)  # loads the generated id
    return user