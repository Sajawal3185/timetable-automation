"""exam_papers table (Wajiha) - past examination papers, proposal 4.1."""
from datetime import datetime
from sqlalchemy import DateTime, ForeignKey, Integer, String, func
from sqlalchemy.orm import Mapped, mapped_column
from database import Base


class ExamPaper(Base):
    __tablename__ = "exam_papers"

    id: Mapped[int] = mapped_column(primary_key=True)
    course_id: Mapped[int] = mapped_column(ForeignKey("courses.id"), index=True)
    title: Mapped[str] = mapped_column(String(200))
    term: Mapped[str] = mapped_column(String(40), index=True)  # e.g. "Fall 2025"
    exam_type: Mapped[str] = mapped_column(String(20), index=True)  # midterm/final/quiz/...
    year: Mapped[int | None] = mapped_column(Integer, nullable=True)
    instructor_name: Mapped[str | None] = mapped_column(String(100), nullable=True)
    file_path: Mapped[str] = mapped_column(String(300))  # relative path inside UPLOAD_DIR
    original_filename: Mapped[str] = mapped_column(String(255))
    content_type: Mapped[str | None] = mapped_column(String(100), nullable=True)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    uploaded_by: Mapped[int] = mapped_column(ForeignKey("users.id"))
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
