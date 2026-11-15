"""6-digit email codes for signup and forgot password (Login & Signup Plan).

Rules: 6 digits, valid 10 minutes, single use, 5 wrong tries, at most one new
code a minute per (email, purpose). Only an HMAC of the code is stored, keyed
with JWT_SECRET_KEY, so someone reading the database still can't use a code.

Nothing here commits, except a wrong guess: that attempt must be saved even
though the request fails, otherwise the 5-try limit could never trigger.
"""
import hashlib
import hmac
import secrets
from datetime import datetime, timedelta, timezone

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.config import settings
from app.models.email_code import EmailCode

CODE_LENGTH = 6
CODE_MINUTES = 10
MAX_ATTEMPTS = 5
RESEND_SECONDS = 60

WRONG_CODE = "The code is wrong or has expired. Ask for a new one."
TOO_MANY = "Too many attempts. Try again later."
WAIT_A_MINUTE = "Please wait a minute before asking for a new code."


class CodeError(Exception):
    def __init__(self, status_code: int, detail: str):
        super().__init__(detail)
        self.status_code, self.detail = status_code, detail


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)  # MySQL DATETIME is naive


def _hash(email: str, purpose: str, code: str) -> str:
    msg = f"{email}:{purpose}:{code}".encode("utf-8")
    return hmac.new(settings.JWT_SECRET_KEY.encode("utf-8"), msg, hashlib.sha256).hexdigest()


def _row(db: Session, email: str, purpose: str) -> EmailCode | None:
    return db.execute(
        select(EmailCode).where(EmailCode.email == email, EmailCode.purpose == purpose)
    ).scalar_one_or_none()


def sent_recently(db: Session, email: str, purpose: str) -> bool:
    """True if a code for this email and purpose went out less than a minute ago."""
    row = _row(db, email, purpose)
    return row is not None and (utcnow() - row.sent_at).total_seconds() < RESEND_SECONDS


def issue_code(db: Session, email: str, purpose: str) -> str:
    """Create (or replace) the code for this email and purpose and return it.

    The caller emails it and commits. Replacing resets the attempt count, which
    is fine: a new code means the old guesses were against a different number.
    """
    code = f"{secrets.randbelow(10 ** CODE_LENGTH):0{CODE_LENGTH}d}"
    now = utcnow()
    row = _row(db, email, purpose)
    if row is None:
        row = EmailCode(email=email, purpose=purpose)
        db.add(row)
    row.code_hash = _hash(email, purpose, code)
    row.expires_at = now + timedelta(minutes=CODE_MINUTES)
    row.attempts = 0
    row.sent_at = now
    db.flush()
    return code


def use_code(db: Session, email: str, purpose: str, code: str) -> None:
    """Accept the code (and delete it: single use) or raise CodeError."""
    row = _row(db, email, purpose)
    if row is None or row.expires_at < utcnow():
        raise CodeError(400, WRONG_CODE)
    if row.attempts >= MAX_ATTEMPTS:
        raise CodeError(429, TOO_MANY)
    typed = str(code or "").strip()
    if not hmac.compare_digest(_hash(email, purpose, typed), row.code_hash):
        row.attempts += 1
        db.commit()  # the wrong guess counts even though this request fails
        if row.attempts >= MAX_ATTEMPTS:
            raise CodeError(429, TOO_MANY)
        raise CodeError(400, WRONG_CODE)
    db.delete(row)
