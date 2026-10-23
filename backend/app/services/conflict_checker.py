"""Conflict detection: teacher, section and room clashes.

READ-ONLY and side-effect free (no add/flush/commit). Three callers use it:
the swap service, the makeup solver and the ConflictBanner endpoint.
"""
from dataclasses import dataclass
from typing import Iterable

from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.models.timetable_entry import TimetableEntry
from app.services import scheduler_rules

TEACHER, SECTION, ROOM = "teacher", "section", "room"


@dataclass(frozen=True)
class ProposedSlot:
    """One class as it WOULD be after the change (the post-change state)."""
    day: object          # same type as TimetableEntry.day
    start_time: object   # same type as TimetableEntry.start_time
    end_time: object
    teacher_id: int
    section_id: int
    room_id: int
    entry_id: int | None = None  # set when the slot is an existing entry being moved


@dataclass(frozen=True)
class Conflict:
    type: str            # teacher | section | room
    entry_id: int | None # the entry we clash with
    detail: str
    severity: str = "hard"  # hard blocks; soft is only a warning


def _severity(conflict_type: str) -> str:
    # The only place that reads scheduler_rules, so adapting to its final API is one edit.
    return "hard" if conflict_type in scheduler_rules.HARD_CONFLICT_TYPES else "soft"


def _overlaps(start_a, end_a, start_b, end_b) -> bool:
    # Strict inequalities: 09:00-10:00 and 10:00-11:00 touch but do NOT overlap.
    # Equality would miss 09:00-11:00 vs 10:00-12:00 entirely.
    return start_a < end_b and end_a > start_b


def check_conflicts(
    db: Session,
    proposed_slots: Iterable[ProposedSlot],
    exclude_entry_ids: Iterable[int] = (),
) -> list[Conflict]:
    """Return every clash the proposed slots would cause.

    exclude_entry_ids: entries being moved by this change. They are ignored as
    "existing" rows, otherwise a swapped entry would clash with its own old self.
    """
    slots = list(proposed_slots)
    excluded = set(exclude_entry_ids)
    conflicts: list[Conflict] = []

    # 1) Proposed slot vs the entries already in the database.
    for slot in slots:
        stmt = select(TimetableEntry).where(
            TimetableEntry.day == slot.day,
            TimetableEntry.start_time < slot.end_time,   # overlap test, not equality
            TimetableEntry.end_time > slot.start_time,
            or_(
                TimetableEntry.teacher_id == slot.teacher_id,
                TimetableEntry.section_id == slot.section_id,
                TimetableEntry.room_id == slot.room_id,
            ),
        )
        if excluded:
            stmt = stmt.where(TimetableEntry.id.not_in(list(excluded)))

        for existing in db.execute(stmt).scalars():
            when = f"on {slot.day} {existing.start_time}-{existing.end_time}"
            for ctype, same in (
                (TEACHER, existing.teacher_id == slot.teacher_id),
                (SECTION, existing.section_id == slot.section_id),
                (ROOM, existing.room_id == slot.room_id),
            ):
                if same:
                    conflicts.append(Conflict(
                        ctype, existing.id, f"{ctype} clash with entry {existing.id} {when}",
                        _severity(ctype),
                    ))

    # 2) Proposed slots vs each other. Moved entries were excluded in step 1, so
    #    without this a swap that makes the two moved classes collide goes unseen.
    for i, a in enumerate(slots):
        for b in slots[i + 1:]:
            if a.day != b.day or not _overlaps(a.start_time, a.end_time, b.start_time, b.end_time):
                continue
            for ctype, same in (
                (TEACHER, a.teacher_id == b.teacher_id),
                (SECTION, a.section_id == b.section_id),
                (ROOM, a.room_id == b.room_id),
            ):
                if same:
                    conflicts.append(Conflict(
                        ctype, b.entry_id, f"{ctype} clash between two proposed slots on {a.day}",
                        _severity(ctype),
                    ))

    return conflicts
