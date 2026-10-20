"""courses table (Sajawal)."""
from datetime import datetime
from sqlalchemy import Boolean, CheckConstraint, DateTime, ForeignKey, Integer, String, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class Course(Base):
    __tablename__ = "courses"
    __table_args__ = (
        UniqueConstraint("code", "department_id", name="uq_course_code_dept"),
        CheckConstraint(
            "credit_hours IS NULL OR credit_hours > 0",
            name="course_credits_positive",
        ),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(20))
    title: Mapped[str] = mapped_column(String(150), index=True)
    # Nullable with no fake default: importer sets real credits or leaves None
    credit_hours: Mapped[int | None] = mapped_column(Integer, nullable=True)
    is_lab: Mapped[bool] = mapped_column(Boolean, default=False)
    # Non-nullable so (code, department_id) uniqueness is strictly enforced
    department_id: Mapped[int] = mapped_column(
        ForeignKey("departments.id", ondelete="RESTRICT"),
        index=True,
    )
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())