"""users table."""
from datetime import datetime
from sqlalchemy import Boolean, CheckConstraint, DateTime, ForeignKey, Integer, String, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class User(Base):
    __tablename__ = "users"
    __table_args__ = (
        CheckConstraint(
            "role IN ('student', 'teacher', 'hod', 'admin')",
            name="user_role_lowercase",
        ),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(100))
    # The login key, stored trimmed + lowercased. routers/auth.py looks users up
    # by it, so it is unique and indexed. Roster students and seeded staff have
    # a roll number / staff id; accounts made by self-signup (routers/signup.py)
    # get the part of their NUML email before "@" (students, e.g.
    # numl-f24-26789) or the full email (staff), hence the length of 150.
    identifier: Mapped[str] = mapped_column(String(150), unique=True, index=True)
    # The NUML email the account proved it owns (signup). Login also accepts it.
    # NULL for roster students and seeded accounts. Still unique: SQLite and
    # MySQL both allow many NULLs in a UNIQUE column.
    email: Mapped[str | None] = mapped_column(String(150), unique=True, nullable=True)
    # When the email code was entered (signup). NULL = email never verified.
    email_verified_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    # Students only, given at signup. Profile data: the shape is checked, but
    # nothing proves the roll number is theirs, so it is NOT a login key.
    roll_number: Mapped[str | None] = mapped_column(String(20), nullable=True)
    # The digits at the end of a student's NUML email (numl-f24-<sys_id>).
    sys_id: Mapped[str | None] = mapped_column(String(20), nullable=True)
    # NULL means "not activated yet": roster students exist before they choose a
    # password, and /activate only claims accounts whose hash is still NULL.
    # Login treats NULL as a failed login (see the DUMMY_HASH guard in auth.py).
    password_hash: Mapped[str | None] = mapped_column(String(255), nullable=True)
    role: Mapped[str] = mapped_column(String(20), default="student", index=True)
    department_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )
    section_id: Mapped[int | None] = mapped_column(
        ForeignKey("sections.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    # Bumped by reset-password. Every token carries the number it was issued
    # with, and get_current_user refuses a mismatch, so a reset logs the
    # account out everywhere.
    token_version: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())