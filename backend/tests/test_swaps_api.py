"""API tests: swap requests (create, approve, reject, cancel, list).

Builds its own small world instead of using the shared `seed`: approve/reject
look the decider up as a real User row (department check), and the permission
rule needs Teacher rows linked to those users.
"""
from datetime import time

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient
from sqlalchemy import select

from app.database import get_db
from app.dependencies import get_current_user
from app.models import makeup_class, notification, swap_request  # noqa: F401  (register tables)
from app.models.course import Course
from app.models.department import Department
from app.models.notification import Notification
from app.models.room import Room
from app.models.section import Section
from app.models.swap_request import SwapRequest
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User
from app.routers import swaps


def _entry(section, course, teacher, room, day, start, end):
    return TimetableEntry(
        section_id=section.id, course_id=course.id, teacher_id=teacher.id, room_id=room.id,
        day_of_week=day, start_time=time(start), end_time=time(end),
    )


@pytest.fixture()
def world(session_factory):
    """Two departments. Teacher t1 (user `teacher`) owns e1, e2 and e5 in SE.

    e1  t1  sec_a  c1  r1  Monday    09-10
    e2  t1  sec_a  c2  r1  Tuesday   11-12
    e3  t2  sec_b  c1  r2  Wednesday 09-10   (another teacher's class)
    e4  t2  sec_b  c2  r1  Tuesday   11-12   (r1 busy when e1 takes e2's slot -> hard clash)
    e5  t1  sec_a  c1  r1  Thursday  09-10   (a second session of e1's class)
    e6  t2  sec_b  c1  r2  Friday    09-10   (a clash-free partner for e4)
    """
    db = session_factory()
    se = Department(name="Software Engineering", code="SE")
    cs = Department(name="Computer Science", code="CS")
    db.add_all([se, cs])
    db.flush()

    users = {
        "teacher": User(name="Dr. Teacher", identifier="t-1", role="teacher", department_id=se.id),
        "teacher2": User(name="Dr. Other", identifier="t-2", role="teacher", department_id=se.id),
        "hod": User(name="SE HOD", identifier="hod-se", role="hod", department_id=se.id),
        "hod_cs": User(name="CS HOD", identifier="hod-cs", role="hod", department_id=cs.id),
        "admin": User(name="Admin", identifier="admin", role="admin"),
    }
    db.add_all(users.values())
    db.flush()

    sec_a = Section(name="BSSE-5A", department_id=se.id, student_count=40)
    sec_b = Section(name="BSSE-5B", department_id=se.id, student_count=40)
    db.add_all([sec_a, sec_b])
    db.flush()
    users["student"] = User(name="Student", identifier="s-1", role="student", section_id=sec_a.id)
    db.add(users["student"])

    c1 = Course(code="SE301", title="Web Eng", department_id=se.id)
    c2 = Course(code="SE302", title="Databases", department_id=se.id)
    t1 = Teacher(name="Dr. Teacher", user_id=users["teacher"].id, department_id=se.id)
    t2 = Teacher(name="Dr. Other", user_id=users["teacher2"].id, department_id=se.id)
    r1 = Room(name="R-1", capacity=50, room_type="lecture")
    r2 = Room(name="R-2", capacity=50, room_type="lecture")
    db.add_all([c1, c2, t1, t2, r1, r2])
    db.flush()

    e1 = _entry(sec_a, c1, t1, r1, "Monday", 9, 10)
    e2 = _entry(sec_a, c2, t1, r1, "Tuesday", 11, 12)
    e3 = _entry(sec_b, c1, t2, r2, "Wednesday", 9, 10)
    e4 = _entry(sec_b, c2, t2, r1, "Tuesday", 11, 12)
    e5 = _entry(sec_a, c1, t1, r1, "Thursday", 9, 10)
    e6 = _entry(sec_b, c1, t2, r2, "Friday", 9, 10)
    db.add_all([e1, e2, e3, e4, e5, e6])
    db.commit()

    app = FastAPI()
    app.include_router(swaps.router)

    def _db():
        session = session_factory()
        try:
            yield session
        finally:
            session.close()

    app.dependency_overrides[get_db] = _db
    client = TestClient(app)

    def as_user(role):
        app.dependency_overrides[get_current_user] = lambda: users[role]
        return client

    def fresh(entry):
        return session_factory().get(TimetableEntry, entry.id)

    return {
        "as": as_user, "db": session_factory, "fresh": fresh, "users": users,
        "sec_b": sec_b, "c2": c2, "t2": t2, "r2": r2,
        "e1": e1, "e2": e2, "e3": e3, "e4": e4, "e5": e5, "e6": e6,
    }


def _create(w, who="teacher", entry="e1", target="e3", **extra):
    return w["as"](who).post(
        "/api/swap-requests", json={"entry_id": w[entry].id, "target_entry_id": w[target].id, **extra}
    )


# ---- create ---------------------------------------------------------------

def test_teacher_creates_swap_for_own_class(world):
    r = _create(world, reason="Conference on Monday")
    assert r.status_code == 201, r.text
    body = r.json()
    assert body["status"] == "pending" and body["warnings"] == []
    assert body["entry_id"] == world["e1"].id and body["target_entry_id"] == world["e3"].id
    # both teachers and the students of both sections are told
    recipients = {n.user_id for n in world["db"]().execute(select(Notification)).scalars()}
    u = world["users"]
    assert recipients == {u["teacher"].id, u["teacher2"].id, u["student"].id}


def test_create_permission_refusals(world):
    assert _create(world, who="teacher2").status_code == 403   # not the owner of e1
    assert _create(world, who="student").status_code == 403    # wrong role
    assert _create(world, who="hod").status_code == 403


def test_create_invalid_states(world):
    assert _create(world, target="e1").status_code == 400      # with itself
    r = world["as"]("teacher").post(
        "/api/swap-requests", json={"entry_id": world["e1"].id, "target_entry_id": 999}
    )
    assert r.status_code == 404
    assert _create(world).status_code == 201
    assert _create(world, target="e3").status_code == 409      # pending request already on e1/e3
    assert "pending" in _create(world, entry="e5", target="e3").json()["detail"]


def test_create_rejects_swapping_two_sessions_of_the_same_class(world):
    r = _create(world, entry="e1", target="e5")
    assert r.status_code == 400
    assert "same course" in r.json()["detail"]


def test_hard_clash_is_refused_with_conflict_list(world):
    # e1 would move to Tuesday 11-12 in R-1, but e4 already has R-1 then.
    r = _create(world, entry="e1", target="e2")
    assert r.status_code == 409, r.text
    detail = r.json()["detail"]
    assert detail["message"] == "Swap would cause scheduling conflicts"
    kinds = {(c["type"], c["entry_id"], c["severity"]) for c in detail["conflicts"]}
    assert ("room", world["e4"].id, "hard") in kinds
    assert world["db"]().execute(select(SwapRequest)).first() is None   # nothing stored


# ---- approve --------------------------------------------------------------

def test_hod_of_same_department_approves_and_times_are_exchanged(world):
    sid = _create(world).json()["id"]
    r = world["as"]("hod").post(f"/api/swap-requests/{sid}/approve", json={"note": "ok"})
    assert r.status_code == 200, r.text
    assert r.json()["status"] == "approved"
    assert r.json()["decided_by"] == world["users"]["hod"].id
    a, b = world["fresh"](world["e1"]), world["fresh"](world["e3"])
    assert (a.day_of_week, a.start_time, a.end_time) == ("Wednesday", time(9), time(10))
    assert (b.day_of_week, b.start_time, b.end_time) == ("Monday", time(9), time(10))
    # owners, sections and rooms stay put: only the time moved
    assert a.teacher_id != b.teacher_id and a.room_id != b.room_id
    # cannot be decided twice
    assert world["as"]("hod").post(f"/api/swap-requests/{sid}/approve").status_code == 409


def test_approve_permission_refusals(world):
    sid = _create(world).json()["id"]
    assert world["as"]("teacher").post(f"/api/swap-requests/{sid}/approve").status_code == 403  # role
    assert world["as"]("hod_cs").post(f"/api/swap-requests/{sid}/approve").status_code == 403   # other dept
    assert world["as"]("hod").post("/api/swap-requests/999/approve").status_code == 404
    assert world["fresh"](world["e1"]).day_of_week == "Monday"                                 # untouched
    assert world["as"]("admin").post(f"/api/swap-requests/{sid}/approve").status_code == 200   # admin: any dept


def test_approve_rechecks_conflicts_at_decision_time(world):
    sid = _create(world).json()["id"]      # e1 <-> e3, clean at request time
    db = world["db"]()
    # R-2 becomes busy on Monday 09-10, so e3 can no longer move into e1's slot.
    db.add(_entry(world["sec_b"], world["c2"], world["t2"], world["r2"], "Monday", 9, 10))
    db.commit()
    r = world["as"]("hod").post(f"/api/swap-requests/{sid}/approve")
    assert r.status_code == 409, r.text
    assert any(c["type"] == "room" for c in r.json()["detail"]["conflicts"])
    assert world["as"]("hod").get("/api/swap-requests").json()[0]["status"] == "pending"


# ---- reject / cancel ------------------------------------------------------

def test_reject_needs_a_note_and_leaves_timetable_alone(world):
    sid = _create(world).json()["id"]
    hod = world["as"]("hod")
    assert hod.post(f"/api/swap-requests/{sid}/reject", json={}).status_code == 422
    assert hod.post(f"/api/swap-requests/{sid}/reject", json={"note": "   "}).status_code == 422
    assert world["as"]("hod_cs").post(f"/api/swap-requests/{sid}/reject", json={"note": "no"}).status_code == 403
    r = world["as"]("hod").post(f"/api/swap-requests/{sid}/reject", json={"note": "Room needed"})
    assert r.status_code == 200
    assert r.json()["status"] == "rejected" and r.json()["decision_note"] == "Room needed"
    assert world["fresh"](world["e1"]).day_of_week == "Monday"
    assert world["as"]("hod").post(f"/api/swap-requests/{sid}/approve").status_code == 409  # already rejected


def test_cancel_is_for_the_requester_while_pending(world):
    sid = _create(world).json()["id"]
    assert world["as"]("teacher2").post(f"/api/swap-requests/{sid}/cancel").status_code == 403
    assert world["as"]("hod").post(f"/api/swap-requests/{sid}/cancel").status_code == 403      # role
    r = world["as"]("teacher").post(f"/api/swap-requests/{sid}/cancel")
    assert r.status_code == 200 and r.json()["status"] == "cancelled"
    assert world["as"]("teacher").post(f"/api/swap-requests/{sid}/cancel").status_code == 409
    assert world["as"]("hod").post(f"/api/swap-requests/{sid}/approve").status_code == 409
    assert _create(world).status_code == 201                                                    # slot free again


# ---- list -----------------------------------------------------------------

def test_list_is_scoped_by_role(world):
    mine = _create(world).json()["id"]                                                  # t1: e1 <-> e3
    theirs = _create(world, who="teacher2", entry="e4", target="e6").json()["id"]      # t2: e4 <-> e6
    world["as"]("hod").post(f"/api/swap-requests/{theirs}/reject", json={"note": "no"})

    ids = lambda who: [s["id"] for s in world["as"](who).get("/api/swap-requests").json()]  # noqa: E731
    assert ids("teacher") == [mine]
    assert ids("teacher2") == [theirs]
    assert ids("hod") == [mine]                   # pending only
    assert ids("hod_cs") == []                    # other department
    assert ids("admin") == [mine]
    assert world["as"]("student").get("/api/swap-requests").status_code == 403


# ---- entries and teachers that are not linked to anyone --------------------

def test_swap_for_an_entry_with_no_teacher_is_refused_not_a_crash(world):
    db = world["db"]()
    orphan = _entry(world["sec_b"], world["c2"], world["t2"], world["r2"], "Thursday", 14, 15)
    orphan.teacher_id = None  # as left by an import row with a blank teacher cell
    db.add(orphan)
    db.commit()
    r = world["as"]("teacher").post(
        "/api/swap-requests", json={"entry_id": orphan.id, "target_entry_id": world["e3"].id}
    )
    assert r.status_code == 403


def test_swap_with_a_teacher_who_has_no_login_still_works(world):
    db = world["db"]()
    db.get(Teacher, world["t2"].id).user_id = None  # imported teacher, account not linked yet
    db.commit()
    assert _create(world).status_code == 201
    recipients = {n.user_id for n in world["db"]().execute(select(Notification)).scalars()}
    u = world["users"]
    assert recipients == {u["teacher"].id, u["student"].id}
