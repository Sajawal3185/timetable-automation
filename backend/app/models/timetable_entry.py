"""timetable_entries table (Sajawal).

NOTE: The unique constraint prevents identical duplicate rows on re-import.
Time-range overlaps (e.g. 09:00-10:30 vs 10:00-11:30) and parallel elective
exemptions (same elective_group) are enforced by conflict_checker.py + row locks.
"""
from datetime import datetime, time
from sqlalchemy import CheckConstraint, DateTime, ForeignKey, Index, Integer, String, Time, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class TimetableEntry(Base):
    __tablename__ = "timetable_entries"
    __table_args__ = (
        UniqueConstraint(
            "term", "section_id", "course_id", "day_of_week",
            "start_time", "end_time", "group_label",
            name="uq_timetable_term_sec_course_slot_grp",
        ),
        CheckConstraint("end_time > start_time", name="timetable_end_after_start"),
        CheckConstraint(
            "day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')",
            name="timetable_valid_weekday",
        ),
        Index("ix_timetable_room_day", "room_id", "day_of_week"),
        Index("ix_timetable_teacher_day", "teacher_id", "day_of_week"),
        Index("ix_timetable_section_day", "section_id", "day_of_week"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    term: Mapped[str] = mapped_column(String(40), default="Fall 2026", index=True)
    section_id: Mapped[int] = mapped_column(ForeignKey("sections.id", ondelete="CASCADE"))
    course_id: Mapped[int] = mapped_column(ForeignKey("courses.id", ondelete="RESTRICT"), index=True)
    teacher_id: Mapped[int | None] = mapped_column(
        ForeignKey("teachers.id", ondelete="SET NULL"),
        nullable=True,
    )
    room_id: Mapped[int | None] = mapped_column(
        ForeignKey("rooms.id", ondelete="SET NULL"),
        nullable=True,
    )
    day_of_week: Mapped[str] = mapped_column(String(10))
    start_time: Mapped[time] = mapped_column(Time)
    end_time: Mapped[time] = mapped_column(Time)
    # Non-null default "" so parallel lab groups (Group A / Group B) don't open a NULL hole in UNIQUE
    group_label: Mapped[str] = mapped_column(String(20), default="")
    slot_index: Mapped[int | None] = mapped_column(Integer, nullable=True)
    block_id: Mapped[str | None] = mapped_column(String(64), nullable=True, index=True)
    elective_group: Mapped[str | None] = mapped_column(String(64), nullable=True, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())