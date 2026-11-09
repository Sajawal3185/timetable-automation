"""Shared FastAPI dependencies. The single home of get_current_user.

Every router, permission helper and test imports it from here:
    from app.dependencies import get_current_user

Why a separate module instead of putting it in core/security.py:
  * security.py is pure functions (hashing, JWT) with no database or model
    imports. Keeping it that way means models and services can import it
    without circular-import problems.
  * get_current_user needs the database session and the User model, so it
    lives one layer up, here.
  * Tests override this exact function object
    (app.dependency_overrides[get_current_user] = ...). If two modules each
    defined their own copy, an override of one would silently not apply to
    routes using the other. One definition avoids that trap.
"""
import jwt
from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from sqlalchemy.orm import Session

from app.core.security import decode_access_token
from app.database import get_db
from app.models.user import User

# auto_error=False: by default HTTPBearer answers a missing header with 403.
# A missing or bad token means "not logged in", which is 401, and the frontend
# relies on 401 to send the user back to the login page. So we let the
# dependency return None and raise the 401 ourselves.
# Using HTTPBearer (not a hand-written header parser) also adds the
# "Authorize" button to /docs, so endpoints can be tried with a token there.
_bearer_scheme = HTTPBearer(auto_error=False)


def _unauthorized(detail: str) -> HTTPException:
    """Build a 401 with the WWW-Authenticate header the HTTP spec asks for."""
    return HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail=detail,
        headers={"WWW-Authenticate": "Bearer"},
    )


def get_current_user(
    credentials: HTTPAuthorizationCredentials | None = Depends(_bearer_scheme),
    db: Session = Depends(get_db),
) -> User:
    """Return the logged-in User for this request, or raise 401.

    The returned object is the live database row, so callers read
    user.role / user.department_id / user.is_active from the database.
    """
    # Step 1: a Bearer token must be present.
    if credentials is None:
        raise _unauthorized("Not authenticated")

    # Step 2: the token must be correctly signed, unexpired, and carry a
    # numeric `sub`. decode_access_token raises a PyJWTError subclass for a
    # bad signature, garbage input, expiry or a missing claim, and ValueError
    # if `sub` is not an integer. All of these mean the same thing to the
    # client, so they get one generic message (no hint about which check failed).
    try:
        payload = decode_access_token(credentials.credentials)
        user_id = int(payload["sub"])
    except (jwt.PyJWTError, ValueError):
        raise _unauthorized("Invalid or expired token")

    # Step 3: load the user from the DATABASE on every request.
    # The token also contains a "role" claim, but we deliberately ignore it.
    # Why: a token stays valid until it expires. If an admin demotes a HOD or
    # deactivates an account, trusting the token's role would keep the old
    # powers alive for up to ACCESS_TOKEN_EXPIRE_MINUTES. Reading the row
    # makes demotion and deactivation take effect on the very next request.
    # The cost is one primary-key lookup per request, which is negligible.
    user = db.get(User, user_id)

    # Step 4: the account must still exist and be active. Both cases get the
    # same 401 so a caller cannot tell "deleted" from "disabled".
    if user is None or not user.is_active:
        raise _unauthorized("User not found or inactive")

    return user
