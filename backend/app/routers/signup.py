"""
routers/signup.py  -  self-signup and forgot password (Login & Signup Plan, 12 Nov).

Endpoints (all public, no token):
    GET  /api/auth/signup-options   departments + sections for the signup form
    POST /api/auth/signup           start a signup; a 6-digit code is emailed
    POST /api/auth/verify-email     enter the code; the account is created and logged in
    POST /api/auth/resend-code      a new signup code, at most once a minute
    POST /api/auth/forgot-password  email a reset code (same answer for everyone)
    POST /api/auth/reset-password   set a new password with that code

Why this is safe when the old self-signup was not (BE-04 / BR-M8): that one
trusted a typed roll number. Here the person must read a code sent to an
@numls.edu.pk inbox, so they really own that address. The role is never sent:
it comes from the email's shape (student email -> student, any other NUML
address -> teacher). Teachers need no approval (decided 14 Nov); an admin links
them to their teacher record later, and only an admin makes anyone HOD/admin.
"""
import logging
import re

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, Field, field_validator
from sqlalchemy import or_, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.core.security import create_access_token, hash_password
from app.database import get_db
from app.models.department import Department
from app.models.email_code import PendingSignup
from app.models.section import Section
from app.models.user import User
from app.routers.auth import (
    ROLL_NUMBER_PATTERN,
    LoginResponse,
    _check_password_length,
    _normalize,
    _user_out,
    find_account,
)
from app.services import email_codes
from app.services.email_codes import CODE_MINUTES, CodeError, utcnow
from app.services.email_sender import EmailError, send_email

log = logging.getLogger(__name__)

router = APIRouter(prefix="/api/auth", tags=["signup"])

EMAIL_DOMAIN = "@numls.edu.pk"
# numl-<term letter><yy>-<sys id>@numls.edu.pk, e.g. numl-f24-26789. Any single
# letter: f = fall is confirmed, s = spring is only assumed, so no spring
# student gets pushed onto the staff path by a guess.
STUDENT_EMAIL = re.compile(r"^numl-([a-z])(\d{2})-(\d+)@numls\.edu\.pk$")
EMAIL_SHAPE = re.compile(r"^[^@\s]+@numls\.edu\.pk$")

ALREADY_REGISTERED = "This email already has an account. Log in instead."
SIGNUP_EXPIRED = "This signup has expired. Start again."
CANT_SEND = "We couldn't send the email right now. Try again in a minute."
RESET_SENT = "If that account exists, we sent a code to its email."


# ---------------------------------------------------------------------------
# Schemas
# ---------------------------------------------------------------------------

class SignupRequest(BaseModel):
    email: str = Field(min_length=1, max_length=150)
    name: str = Field(min_length=1, max_length=100)
    department_id: int
    password: str = Field(min_length=8)
    # students only (ignored for staff)
    section_id: int | None = None
    roll_number: str | None = Field(default=None, max_length=20)

    _pw_len = field_validator("password")(_check_password_length)


class EmailRequest(BaseModel):
    email: str = Field(min_length=1, max_length=150)


class VerifyRequest(EmailRequest):
    code: str = Field(min_length=1, max_length=20)


class ForgotRequest(BaseModel):
    identifier: str = Field(min_length=1, max_length=150)


class ResetRequest(BaseModel):
    identifier: str = Field(min_length=1, max_length=150)
    code: str = Field(min_length=1, max_length=20)
    new_password: str = Field(min_length=8)

    _pw_len = field_validator("new_password")(_check_password_length)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _numl_email(raw: str) -> str:
    email = _normalize(raw)
    if not EMAIL_SHAPE.match(email):
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"Use your NUML email (ending in {EMAIL_DOMAIN}).")
    return email


def _email_taken(db: Session, email: str, identifier: str) -> bool:
    return db.execute(
        select(User.id).where(or_(User.email == email, User.identifier == identifier, User.identifier == email))
    ).first() is not None


def _open_signup(db: Session, email: str) -> PendingSignup | None:
    """The unfinished signup for this email, or None if there is none or it expired."""
    pending = db.execute(select(PendingSignup).where(PendingSignup.email == email)).scalar_one_or_none()
    if pending is None or (utcnow() - pending.created_at).total_seconds() > CODE_MINUTES * 60:
        return None
    return pending


def _send_code(to: str, code: str, purpose: str) -> None:
    if purpose == "signup":
        subject = "Your NUML Timetable signup code"
        why = "Use this code to finish creating your account"
    else:
        subject = "Your NUML Timetable password reset code"
        why = "Use this code to set a new password"
    send_email(to, subject, (
        f"{why}: {code}\n\n"
        f"It works for {CODE_MINUTES} minutes and only once.\n"
        "If you didn't ask for this, you can ignore this email."
    ))


def _issue_and_send(db: Session, email: str, purpose: str) -> None:
    """New code -> email -> commit. If the email can't be sent, nothing is saved."""
    code = email_codes.issue_code(db, email, purpose)
    try:
        _send_code(email, code, purpose)
    except EmailError as exc:
        db.rollback()
        log.error("%s code not sent to %s: %s", purpose, email, exc)
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, CANT_SEND)


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@router.get("/signup-options")
def signup_options(db: Session = Depends(get_db)):
    departments = db.execute(select(Department).order_by(Department.name)).scalars().all()
    sections = db.execute(select(Section).order_by(Section.name)).scalars().all()
    return {
        "departments": [{"id": d.id, "name": d.name, "code": d.code} for d in departments],
        "sections": [{"id": s.id, "name": s.name, "department_id": s.department_id} for s in sections],
    }


@router.post("/signup", status_code=status.HTTP_202_ACCEPTED)
def signup(data: SignupRequest, db: Session = Depends(get_db)):
    email = _numl_email(data.email)
    dept = db.get(Department, data.department_id)
    if dept is None:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, "Choose your department.")

    student = STUDENT_EMAIL.match(email)
    pending = PendingSignup(email=email, name=data.name.strip(), department_id=dept.id)
    if student:
        section = db.get(Section, data.section_id) if data.section_id is not None else None
        if section is None or section.department_id != dept.id:
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, "Choose a section of your department.")
        roll = _normalize(data.roll_number or "")
        if not ROLL_NUMBER_PATTERN.match(roll):
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, "Roll number should look like se-9244330.")
        pending.kind = "student"
        pending.identifier = email.split("@", 1)[0]       # numl-f24-26789
        pending.section_id, pending.roll_number, pending.sys_id = section.id, roll, student.group(3)
    else:
        pending.kind = "staff"
        pending.identifier = email                        # staff log in with the full email

    if _email_taken(db, email, pending.identifier):
        raise HTTPException(status.HTTP_409_CONFLICT, ALREADY_REGISTERED)
    # /signup sends an email too, so it shares resend-code's one-a-minute limit;
    # otherwise anyone could flood someone's inbox through this endpoint.
    if email_codes.sent_recently(db, email, "signup"):
        raise HTTPException(status.HTTP_429_TOO_MANY_REQUESTS, email_codes.WAIT_A_MINUTE)

    pending.password_hash = hash_password(data.password)
    pending.created_at = utcnow()
    # A new signup replaces an unfinished one for the same email.
    old = db.execute(select(PendingSignup).where(PendingSignup.email == email)).scalar_one_or_none()
    if old is not None:
        db.delete(old)
        db.flush()
    db.add(pending)
    _issue_and_send(db, email, "signup")
    try:
        db.commit()
    except IntegrityError:   # two signups for the same email at the same moment
        db.rollback()
        raise HTTPException(status.HTTP_409_CONFLICT, "A signup for this email is already in progress.")
    return {"email": email, "kind": pending.kind, "expires_in": CODE_MINUTES * 60}


@router.post("/verify-email", response_model=LoginResponse)
def verify_email(data: VerifyRequest, db: Session = Depends(get_db)):
    email = _numl_email(data.email)
    pending = _open_signup(db, email)
    if pending is None:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, SIGNUP_EXPIRED)
    try:
        email_codes.use_code(db, email, "signup", data.code)
    except CodeError as exc:
        raise HTTPException(exc.status_code, exc.detail)
    if _email_taken(db, email, pending.identifier):
        raise HTTPException(status.HTTP_409_CONFLICT, ALREADY_REGISTERED)

    user = User(
        name=pending.name,
        identifier=pending.identifier,
        email=email,
        email_verified_at=utcnow(),
        password_hash=pending.password_hash,
        role="student" if pending.kind == "student" else "teacher",
        department_id=pending.department_id,
        section_id=pending.section_id,
        roll_number=pending.roll_number,
        sys_id=pending.sys_id,
        is_active=True,
    )
    db.add(user)
    db.delete(pending)
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(status.HTTP_409_CONFLICT, ALREADY_REGISTERED)
    db.refresh(user)
    token = create_access_token(user_id=user.id, role=user.role, token_version=user.token_version)
    return LoginResponse(token=token, user=_user_out(db, user))


@router.post("/resend-code", status_code=status.HTTP_202_ACCEPTED)
def resend_code(data: EmailRequest, db: Session = Depends(get_db)):
    email = _numl_email(data.email)
    if _open_signup(db, email) is None:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, SIGNUP_EXPIRED)
    if email_codes.sent_recently(db, email, "signup"):
        raise HTTPException(status.HTTP_429_TOO_MANY_REQUESTS, email_codes.WAIT_A_MINUTE)
    _issue_and_send(db, email, "signup")
    db.commit()
    return {}


@router.post("/forgot-password", status_code=status.HTTP_202_ACCEPTED)
def forgot_password(data: ForgotRequest, db: Session = Depends(get_db)):
    # Always the same answer, whatever happens below (even if the email fails),
    # so this endpoint can't be used to find out which accounts exist.
    user = find_account(db, data.identifier)
    if user is not None and user.email and user.is_active and not email_codes.sent_recently(db, user.email, "reset"):
        try:
            _issue_and_send(db, user.email, "reset")
            db.commit()
        except HTTPException:
            pass  # already logged and rolled back in _issue_and_send
    return {"detail": RESET_SENT}


@router.post("/reset-password")
def reset_password(data: ResetRequest, db: Session = Depends(get_db)):
    user = find_account(db, data.identifier)
    if user is None or not user.email or not user.is_active:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, email_codes.WRONG_CODE)
    try:
        email_codes.use_code(db, user.email, "reset", data.code)
    except CodeError as exc:
        raise HTTPException(exc.status_code, exc.detail)
    user.password_hash = hash_password(data.new_password)
    user.token_version = (user.token_version or 0) + 1   # older tokens stop working
    db.commit()
    return {"detail": "Password changed. You can log in now."}
