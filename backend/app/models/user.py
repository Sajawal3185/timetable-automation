"""users table."""
from datetime import datetime
from sqlalchemy import Boolean, CheckConstraint, DateTime, ForeignKey, String, func
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
    # The login key: roll number for students, staff id for staff. routers/auth.py
    # looks users up by this column (stored trimmed + lowercased), so it must be
    # unique, and it is indexed because every login and activation queries it.
    identifier: Mapped[str] = mapped_column(String(50), unique=True, index=True)
    # Optional contact detail, no longer a login key. Roster students are imported
    # without one. Still unique: SQLite and MySQL both allow many NULLs in a
    # UNIQUE column, so "no email" never clashes with another "no email".
    email: Mapped[str | None] = mapped_column(String(150), unique=True, nullable=True)
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
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())