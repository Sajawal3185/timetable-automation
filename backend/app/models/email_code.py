"""email_codes and pending_signups tables (Login & Signup Plan, 12 Nov).

There is no university roster, so a 6-digit code sent to an @numls.edu.pk inbox
is what proves a person is who they say they are.

email_codes      one live code per (email, purpose). Only an HMAC of the code is
                 stored, so a database leak does not hand out working codes.
pending_signups  a signup waiting for its code. It is NOT an account yet: the
                 users row is only created when the right code is entered, so
                 nobody can squat on an address they cannot read.
"""
from datetime import datetime

from sqlalchemy import CheckConstraint, DateTime, ForeignKey, Integer, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class EmailCode(Base):
    __tablename__ = "email_codes"
    __table_args__ = (
        UniqueConstraint("email", "purpose", name="uq_email_code_email_purpose"),
        CheckConstraint("purpose IN ('signup', 'reset')", name="email_code_purpose"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    email: Mapped[str] = mapped_column(String(150))
    purpose: Mapped[str] = mapped_column(String(10))
    code_hash: Mapped[str] = mapped_column(String(64))       # hex HMAC-SHA256, never the code
    expires_at: Mapped[datetime] = mapped_column(DateTime)
    attempts: Mapped[int] = mapped_column(Integer, default=0)  # wrong guesses so far
    sent_at: Mapped[datetime] = mapped_column(DateTime)        # for "one code a minute"


class PendingSignup(Base):
    __tablename__ = "pending_signups"

    id: Mapped[int] = mapped_column(primary_key=True)
    email: Mapped[str] = mapped_column(String(150), unique=True)
    kind: Mapped[str] = mapped_column(String(10))              # "student" or "staff"
    identifier: Mapped[str] = mapped_column(String(150))
    name: Mapped[str] = mapped_column(String(100))
    password_hash: Mapped[str] = mapped_column(String(255))    # bcrypt, like users
    department_id: Mapped[int] = mapped_column(ForeignKey("departments.id", ondelete="CASCADE"))
    section_id: Mapped[int | None] = mapped_column(ForeignKey("sections.id", ondelete="CASCADE"), nullable=True)
    roll_number: Mapped[str | None] = mapped_column(String(20), nullable=True)
    sys_id: Mapped[str | None] = mapped_column(String(20), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime)
