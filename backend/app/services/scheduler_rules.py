"""Hard and Soft scheduling rules (Sajawal) - proposal section 3.1.

Pure functions with zero database or HTTP dependencies so conflict_checker.py
and makeup_solver.py can call them directly.
"""
from dataclasses import dataclass
from datetime import time
from typing import Literal, Sequence

RuleLevel = Literal["hard", "soft"]
RuleKind = Literal[
    "teacher_clash",
    "room_clash",
    "section_clash",
    "room_capacity",
    "room_type_mismatch",
    "unknown_capacity",
    "long_student_gap",
]


@dataclass(frozen=True)
class ScheduleSlot:
    """Normalized representation of any scheduled class or candidate slot."""
    day_of_week: str
    start_time: time
    end_time: time
    section_id: int
    course_id: int
    teacher_id: int | None = None
    room_id: int | None = None
    elective_group: str | None = None
    group_label: str = ""
    entry_id: int | None = None


@dataclass(frozen=True)
class RuleViolation:
    level: RuleLevel
    kind: RuleKind
    message: str
    conflicting_entry_id: int | None = None


def times_overlap(start_a: time, end_a: time, start_b: time, end_b: time) -> bool:
    """Half-open interval [start, end) overlap check."""
    return start_a < end_b and end_a > start_b


def _minutes_between(earlier_end: time, later_start: time) -> int:
    return (later_start.hour * 60 + later_start.minute) - (earlier_end.hour * 60 + earlier_end.minute)


def check_hard_clashes(
    candidate: ScheduleSlot,
    existing_slots: Sequence[ScheduleSlot],
) -> list[RuleViolation]:
    """Hard Rules: teacher, room, or section cannot be double-booked at overlapping times.

    Exemptions:
    - Parallel electives sharing a non-empty `elective_group` in the same section.
    - Split lab groups of the same section where `group_label` differs (e.g. Group A vs Group B)
      in different rooms with different teachers.
    """
    violations: list[RuleViolation] = []
    for slot in existing_slots:
        if candidate.entry_id is not None and slot.entry_id == candidate.entry_id:
            continue
        if slot.day_of_week != candidate.day_of_week:
            continue
        if not times_overlap(candidate.start_time, candidate.end_time, slot.start_time, slot.end_time):
            continue

        if candidate.teacher_id is not None and candidate.teacher_id == slot.teacher_id:
            violations.append(RuleViolation(
                level="hard",
                kind="teacher_clash",
                message=f"Teacher #{candidate.teacher_id} is already booked {slot.start_time:%H:%M}-{slot.end_time:%H:%M}",
                conflicting_entry_id=slot.entry_id,
            ))

        if candidate.room_id is not None and candidate.room_id == slot.room_id:
            violations.append(RuleViolation(
                level="hard",
                kind="room_clash",
                message=f"Room #{candidate.room_id} is already booked {slot.start_time:%H:%M}-{slot.end_time:%H:%M}",
                conflicting_entry_id=slot.entry_id,
            ))

        if candidate.section_id == slot.section_id:
            same_elective_group = (
                bool(candidate.elective_group)
                and candidate.elective_group == slot.elective_group
            )
            distinct_lab_groups = (
                bool(candidate.group_label)
                and bool(slot.group_label)
                and candidate.group_label != slot.group_label
            )
            if not same_elective_group and not distinct_lab_groups:
                violations.append(RuleViolation(
                    level="hard",
                    kind="section_clash",
                    message=f"Section #{candidate.section_id} is already booked {slot.start_time:%H:%M}-{slot.end_time:%H:%M}",
                    conflicting_entry_id=slot.entry_id,
                ))
    return violations


def check_room_suitability(
    *,
    student_count: int | None,
    room_capacity: int | None,
    requires_lab: bool = False,
    room_type: str = "lecture",
) -> list[RuleViolation]:
    """Hard Rules for room capacity and lab requirement (proposal 2.3 & 3.1)."""
    violations: list[RuleViolation] = []
    if requires_lab and room_type.lower() != "lab":
        violations.append(RuleViolation(
            level="hard",
            kind="room_type_mismatch",
            message="Lab course requires a room with room_type='lab'",
        ))

    if student_count is None or room_capacity is None:
        violations.append(RuleViolation(
            level="hard",
            kind="unknown_capacity",
            message="Cannot verify seating fit because section student_count or room capacity is unknown (None)",
        ))
    elif room_capacity < student_count:
        violations.append(RuleViolation(
            level="hard",
            kind="room_capacity",
            message=f"Room capacity ({room_capacity}) is smaller than section size ({student_count})",
        ))
    return violations


def check_soft_section_gaps(
    section_day_slots: Sequence[ScheduleSlot],
    max_allowed_gap_minutes: int = 120,
) -> list[RuleViolation]:
    """Soft Rule: flag gaps longer than `max_allowed_gap_minutes` for a section on a single day."""
    if len(section_day_slots) < 2:
        return []
    ordered = sorted(section_day_slots, key=lambda s: (s.start_time, s.end_time))
    violations: list[RuleViolation] = []
    latest_end = ordered[0].end_time

    for slot in ordered[1:]:
        gap = _minutes_between(latest_end, slot.start_time)
        if gap > max_allowed_gap_minutes:
            violations.append(RuleViolation(
                level="soft",
                kind="long_student_gap",
                message=(
                    f"Section #{slot.section_id} has a {gap}-minute gap on {slot.day_of_week} "
                    f"between {latest_end:%H:%M} and {slot.start_time:%H:%M}"
                ),
                conflicting_entry_id=slot.entry_id,
            ))
        if slot.end_time > latest_end:
            latest_end = slot.end_time
    return violations