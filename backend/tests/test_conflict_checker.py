"""Unit tests: conflict_checker (teacher / section / room clashes)."""
from datetime import time

import pytest

from app.models.course import Course
from app.models.department import Department
from app.models.room import Room
from app.models.section import Section
from app.models.timetable_entry import TimetableEntry
from app.services.conflict_checker import ProposedSlot, check_conflicts
from app.services.swap_service import build_swap_slots


@pytest.fixture()
def w(db):
    """One department, two sections, two courses, two rooms. Teachers are plain ids."""
    dept = Department(name="SE", code="SE")
    db.add(dept)
    db.flush()
    sec = Section(name="A", department_id=dept.id)
    sec2 = Section(name="B", department_id=dept.id)
    c1 = Course(code="C1", title="One", department_id=dept.id)
    c2 = Course(code="C2", title="Two", department_id=dept.id)
    r1 = Room(name="R1", capacity=40, room_type="lecture")
    r2 = Room(name="R2", capacity=40, room_type="lecture")
    db.add_all([sec, sec2, c1, c2, r1, r2])
    db.commit()

    def entry(day, start, end, *, teacher=1, section=sec, room=r1, course=c1):
        e = TimetableEntry(
            section_id=section.id, course_id=course.id, teacher_id=teacher, room_id=room.id,
            day_of_week=day, start_time=time(start), end_time=time(end),
        )
        db.add(e)
        db.commit()
        return e

    def slot(day, start, end, *, teacher=1, section=sec, room=r1, entry_id=None):
        return ProposedSlot(day, time(start), time(end), teacher, section.id, room.id, entry_id)

    return {"db": db, "entry": entry, "slot": slot, "sec": sec, "sec2": sec2,
            "c1": c1, "c2": c2, "r1": r1, "r2": r2}


def test_free_slot_has_no_conflicts(w):
    w["entry"]("Monday", 9, 10)
    assert check_conflicts(w["db"], [w["slot"]("Tuesday", 9, 10)]) == []          # other day
    assert check_conflicts(w["db"], [w["slot"]("Monday", 10, 11)]) == []          # touches, no overlap
    assert check_conflicts(w["db"], [w["slot"]("Monday", 9, 10, teacher=2, section=w["sec2"], room=w["r2"])]) == []


def test_each_shared_resource_is_reported_as_hard(w):
    e = w["entry"]("Monday", 9, 10)
    found = check_conflicts(w["db"], [w["slot"]("Monday", 9, 11)])
    assert {(c.type, c.entry_id, c.severity) for c in found} == {
        ("teacher", e.id, "hard"), ("section", e.id, "hard"), ("room", e.id, "hard"),
    }
    only_room = check_conflicts(w["db"], [w["slot"]("Monday", 8, 10, teacher=2, section=w["sec2"])])
    assert [(c.type, c.entry_id) for c in only_room] == [("room", e.id)]


def test_partial_overlap_counts(w):
    e = w["entry"]("Monday", 9, 11)
    found = check_conflicts(w["db"], [w["slot"]("Monday", 10, 12, section=w["sec2"], room=w["r2"])])
    assert [(c.type, c.entry_id) for c in found] == [("teacher", e.id)]


def test_moved_entries_are_excluded_from_the_existing_rows(w):
    a = w["entry"]("Monday", 9, 10)
    b = w["entry"]("Tuesday", 11, 12, course=w["c2"])
    # a and b trade times: without the exclusion each would clash with its own old row.
    assert check_conflicts(w["db"], build_swap_slots(a, b), exclude_entry_ids=(a.id, b.id)) == []
    assert check_conflicts(w["db"], build_swap_slots(a, b)) != []


def test_two_proposed_slots_can_clash_with_each_other(w):
    slots = [
        w["slot"]("Monday", 9, 10, entry_id=1),
        w["slot"]("Monday", 9, 10, teacher=2, section=w["sec2"], entry_id=2),   # same room
    ]
    found = check_conflicts(w["db"], slots)
    assert [(c.type, c.entry_id, c.severity) for c in found] == [("room", 2, "hard")]


def test_build_swap_slots_exchanges_time_only(w):
    a = w["entry"]("Monday", 9, 10, teacher=1)
    b = w["entry"]("Tuesday", 11, 12, teacher=2, section=w["sec2"], room=w["r2"], course=w["c2"])
    new_a, new_b = build_swap_slots(a, b)
    assert (new_a.day, new_a.start_time, new_a.end_time) == ("Tuesday", time(11), time(12))
    assert (new_a.teacher_id, new_a.section_id, new_a.room_id, new_a.entry_id) == (1, w["sec"].id, w["r1"].id, a.id)
    assert (new_b.day, new_b.start_time, new_b.end_time) == ("Monday", time(9), time(10))
    assert (new_b.teacher_id, new_b.section_id, new_b.room_id, new_b.entry_id) == (2, w["sec2"].id, w["r2"].id, b.id)
