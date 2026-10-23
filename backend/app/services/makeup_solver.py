"""Makeup Slot Solver with room-capacity fallback (Sajawal) - proposal 2.3."""
from collections.abc import Sequence
from dataclasses import dataclass
from datetime import time
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.course import Course
from app.models.makeup_class import MakeupClass
from app.models.room import Room
from app.models.section import Section
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.services.scheduler_rules import (
    ScheduleSlot,
    check_hard_clashes,
    check_room_suitability,
    check_soft_section_gaps,
)

WEEKDAYS = ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday")

# Standard NUML starting times from 08:00 to 15:00 in 30-minute steps
CANDIDATE_STARTS: tuple[time, ...] = (
    time(8, 0), time(8, 30), time(9, 0), time(9, 30),
    time(10, 0), time(10, 30), time(10, 45), time(11, 0),
    time(11, 30), time(11, 40), time(12, 0), time(12, 30),
    time(13, 0), time(13, 30), time(14, 0), time(14, 30), time(15, 0),
)
CAMPUS_CLOSE_TIME = time(16, 30)


@dataclass(frozen=True)
class RoomOption:
    room_id: int
    room_name: str
    capacity: int
    room_type: str
    building: str | None
    is_preferred: bool


@dataclass(frozen=True)
class MakeupSlotSuggestion:
    day_of_week: str
    start_time: time
    end_time: time
    recommended_room: RoomOption
    fallback_rooms: list[RoomOption]
    soft_warnings: list[str]


def _add_minutes(t: time, minutes: int) -> time | None:
    total = t.hour * 60 + t.minute + minutes
    if total >= 24 * 60:
        return None
    return time(total // 60, total % 60)


def _load_active_slots(db: Session, term: str) -> list[ScheduleSlot]:
    """Load both regular timetable entries for `term` and active scheduled makeup classes."""
    slots: list[ScheduleSlot] = []
    entries = db.execute(select(TimetableEntry).where(TimetableEntry.term == term)).scalars().all()
    for e in entries:
        slots.append(ScheduleSlot(
            day_of_week=e.day_of_week, start_time=e.start_time, end_time=e.end_time,
            section_id=e.section_id, course_id=e.course_id, teacher_id=e.teacher_id,
            room_id=e.room_id, elective_group=e.elective_group, group_label=e.group_label,
            entry_id=e.id, entry_type="timetable",
        ))

    makeups = db.execute(
        select(MakeupClass).where(MakeupClass.status == "scheduled")
    ).scalars().all()
    for m in makeups:
        slots.append(ScheduleSlot(
            day_of_week=m.day_of_week, start_time=m.start_time, end_time=m.end_time,
            section_id=m.section_id, course_id=m.course_id, teacher_id=m.teacher_id,
            room_id=m.room_id, entry_id=m.id, entry_type="makeup",
        ))
    return slots


def find_makeup_slots(
    db: Session,
    *,
    section_id: int,
    teacher_id: int,
    course_id: int,
    duration_minutes: int,
    preferred_room_id: int | None = None,
    days: Sequence[str] = WEEKDAYS,
    term: str = "Fall 2026",
) -> list[MakeupSlotSuggestion]:
    """Return all clash-free (day, start, end) slots with smallest-fit room fallback."""
    section = db.get(Section, section_id)
    teacher = db.get(Teacher, teacher_id)
    course = db.get(Course, course_id)
    if section is None:
        raise ValueError("Section not found")
    if teacher is None:
        raise ValueError("Teacher not found")
    if course is None:
        raise ValueError("Course not found")
    if section.student_count is None:
        raise ValueError("Section student_count is unknown (None); set student_count before running the solver")

    all_rooms = db.execute(select(Room).order_by(Room.capacity, Room.name)).scalars().all()
    # Filter rooms that pass suitability (known capacity >= student_count and matching lab/lecture type)
    suitable_rooms = [
        r for r in all_rooms
        if not check_room_suitability(
            student_count=section.student_count,
            room_capacity=r.capacity,
            requires_lab=course.is_lab,
            room_type=r.room_type,
        )
    ]
    if not suitable_rooms:
        return []

    existing_slots = _load_active_slots(db, term=term)
    suggestions: list[MakeupSlotSuggestion] = []

    for day in days:
        day_section_slots = [s for s in existing_slots if s.day_of_week == day and s.section_id == section_id]
        for start_t in CANDIDATE_STARTS:
            end_t = _add_minutes(start_t, duration_minutes)
            if end_t is None or end_t > CAMPUS_CLOSE_TIME:
                continue

            # 1. Check if teacher and section are free during [start_t, end_t)
            probe = ScheduleSlot(
                day_of_week=day, start_time=start_t, end_time=end_t,
                section_id=section_id, course_id=course_id, teacher_id=teacher_id,
            )
            if check_hard_clashes(probe, existing_slots):
                continue

            # 2. Find all suitable rooms that are also free during [start_t, end_t)
            free_options: list[RoomOption] = []
            for room in suitable_rooms:
                room_probe = ScheduleSlot(
                    day_of_week=day, start_time=start_t, end_time=end_t,
                    section_id=section_id, course_id=course_id, teacher_id=teacher_id,
                    room_id=room.id,
                )
                if not check_hard_clashes(room_probe, existing_slots):
                    free_options.append(RoomOption(
                        room_id=room.id,
                        room_name=room.name,
                        capacity=room.capacity or 0,
                        room_type=room.room_type,
                        building=room.building,
                        is_preferred=(room.id == preferred_room_id),
                    ))

            if not free_options:
                continue

            # Sort so preferred_room_id (if free) comes first, then smallest sufficient capacity
            free_options.sort(key=lambda r: (not r.is_preferred, r.capacity, r.room_name))

            # 3. Evaluate Soft Rules (student gap warnings if this slot is chosen)
            soft_violations = check_soft_section_gaps(day_section_slots + [probe])
            suggestions.append(MakeupSlotSuggestion(
                day_of_week=day,
                start_time=start_t,
                end_time=end_t,
                recommended_room=free_options[0],
                fallback_rooms=free_options[1:],
                soft_warnings=[v.message for v in soft_violations],
            ))

    return suggestions