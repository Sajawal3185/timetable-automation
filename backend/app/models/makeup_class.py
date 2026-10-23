"""makeup_classes table (Sajawal) - booked makeup sessions (proposal 2.3)."""
from datetime import date, datetime, time
from sqlalchemy import CheckConstraint, Date, DateTime, ForeignKey, Index, String, Text, Time, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class MakeupClass(Base):
    __tablename__ = "makeup_classes"
    __table_args__ = (
        CheckConstraint("end_time > start_time", name="makeup_end_after_start"),
        CheckConstraint(
            "day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')",
            name="makeup_valid_weekday",
        ),
        CheckConstraint(
            "status IN ('scheduled', 'cancelled')",
            name="makeup_status_lowercase",
        ),
        Index("ix_makeup_room_day", "room_id", "day_of_week"),
        Index("ix_makeup_teacher_day", "teacher_id", "day_of_week"),
        Index("ix_makeup_section_day", "section_id", "day_of_week"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    course_id: Mapped[int] = mapped_column(ForeignKey("courses.id", ondelete="RESTRICT"), index=True)
    section_id: Mapped[int] = mapped_column(ForeignKey("sections.id", ondelete="CASCADE"))
    teacher_id: Mapped[int] = mapped_column(ForeignKey("teachers.id", ondelete="CASCADE"))
    room_id: Mapped[int] = mapped_column(ForeignKey("rooms.id", ondelete="RESTRICT"))
    class_date: Mapped[date | None] = mapped_column(Date, nullable=True, index=True)
    day_of_week: Mapped[str] = mapped_column(String(10))
    start_time: Mapped[time] = mapped_column(Time)
    end_time: Mapped[time] = mapped_column(Time)
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="scheduled")
    created_by: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())