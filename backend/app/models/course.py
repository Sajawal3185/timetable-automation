"""TEMPORARY STUB of Sajawal's Course model - local testing only. Delete when his real file is merged."""
from sqlalchemy import ForeignKey, String, Integer, Time
from sqlalchemy.orm import Mapped, mapped_column
from app.database import Base


class Course(Base):
    __tablename__ = "courses"
    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(20))
    title: Mapped[str] = mapped_column(String(150))
    credit_hours: Mapped[int] = mapped_column(Integer, default=3)
    department_id: Mapped[int | None] = mapped_column(ForeignKey("departments.id"), nullable=True)
