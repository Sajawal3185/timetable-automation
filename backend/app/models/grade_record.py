"""grade_records table (Wajiha) - a student saved grades, proposal 4.3."""
from datetime import datetime
from sqlalchemy import DateTime, Float, ForeignKey, String, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column
from database import Base


class GradeRecord(Base):
    __tablename__ = "grade_records"
    __table_args__ = (UniqueConstraint("user_id", "term", "course_code", name="uq_grade_user_term_course"),)

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), index=True)
    term: Mapped[str] = mapped_column(String(40))  # e.g. "Fall 2025"
    course_code: Mapped[str] = mapped_column(String(20))
    course_title: Mapped[str | None] = mapped_column(String(150), nullable=True)
    credit_hours: Mapped[float] = mapped_column(Float)
    grade: Mapped[str] = mapped_column(String(3))  # letter grade, e.g. "B+"
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
