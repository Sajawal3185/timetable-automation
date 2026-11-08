"""makeup_classes table - booked makeup sessions (proposal 2.3)."""
from datetime import date, datetime, time
from sqlalchemy import (
    CheckConstraint, Computed, Date, DateTime, ForeignKey, Index, Integer, String, Text, Time,
    UniqueConstraint, func,
)
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
        # Last line of defence against double booking (B-8): the database itself
        # refuses a second *scheduled* makeup in the same room, day and start time,
        # even if two requests pass the clash check at the same moment.
        # `active_slot` is 1 for scheduled rows and NULL for cancelled ones, and
        # NULLs never collide in a UNIQUE key, so cancelled bookings don't block
        # the slot. (MySQL has no partial indexes, so this is the portable way.)
        # day_of_week, not class_date: class_date is optional, and the clash check
        # also compares makeups by weekday.
        UniqueConstraint(
            "room_id", "day_of_week", "start_time", "active_slot",
            name="uq_makeup_room_slot",
        ),
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
    # Computed by the database from status; never set it in code (see uq_makeup_room_slot).
    active_slot: Mapped[int | None] = mapped_column(
        Integer,
        Computed("CASE WHEN status = 'scheduled' THEN 1 END", persisted=True),
        nullable=True,
    )
    created_by: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())