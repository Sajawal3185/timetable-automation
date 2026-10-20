"""sections table (Sajawal)."""
from datetime import datetime
from sqlalchemy import CheckConstraint, DateTime, ForeignKey, Integer, String, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class Section(Base):
    __tablename__ = "sections"
    __table_args__ = (
        UniqueConstraint("name", "department_id", name="uq_section_name_dept"),
        CheckConstraint(
            "student_count IS NULL OR student_count > 0",
            name="student_count_positive",
        ),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(50))
    # Non-nullable so UniqueConstraint(name, department_id) is never bypassed by NULL
    department_id: Mapped[int] = mapped_column(
        ForeignKey("departments.id", ondelete="RESTRICT"),
        index=True,
    )
    semester: Mapped[int | None] = mapped_column(Integer, nullable=True)
    # Nullable with no fake default: unknown student_count is None so solver flags it
    student_count: Mapped[int | None] = mapped_column(Integer, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())