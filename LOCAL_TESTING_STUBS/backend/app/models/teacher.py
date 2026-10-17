"""TEMPORARY STUB of Sajawal's Teacher model - local testing only. Delete when his real file is merged."""
from sqlalchemy import ForeignKey, String, Integer, Time
from sqlalchemy.orm import Mapped, mapped_column
from app.database import Base


class Teacher(Base):
    __tablename__ = "teachers"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(100))
    department_id: Mapped[int | None] = mapped_column(ForeignKey("departments.id"), nullable=True)
