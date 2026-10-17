"""TEMPORARY STUB of Sajawal's TimetableEntry model - local testing only. Delete when his real file is merged."""
from sqlalchemy import ForeignKey, String, Integer, Time
from sqlalchemy.orm import Mapped, mapped_column
from database import Base


class TimetableEntry(Base):
    __tablename__ = "timetable_entries"
    id: Mapped[int] = mapped_column(primary_key=True)
    section_id: Mapped[int] = mapped_column(ForeignKey("sections.id"))
    course_id: Mapped[int] = mapped_column(ForeignKey("courses.id"))
    teacher_id: Mapped[int | None] = mapped_column(ForeignKey("teachers.id"), nullable=True)
    room_id: Mapped[int | None] = mapped_column(ForeignKey("rooms.id"), nullable=True)
    day_of_week: Mapped[str] = mapped_column(String(10))
    start_time = mapped_column(Time)
    end_time = mapped_column(Time)
