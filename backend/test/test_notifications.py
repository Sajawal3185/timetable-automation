"""BE-09 tests: notifications are saved, counted, marked read, and private to their owner."""
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
from app.models.notification import Notification, NotificationType
from app.models.room import Room
from app.models.section import Section
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User
from app.routers import notifications, swaps


def _entry(section, course, teacher, room, day, start, end):
    return TimetableEntry(
        section_id=section.id, course_id=course.id, teacher_id=teacher.id, room_id=room.id,
        day_of_week=day, start_time=time(start), end_time=time(end),
    )


@pytest.fixture()
def world(session_factory):
    """One department, two teachers, an HOD, a student and two classes (e1, e3)."""
    db = session_factory()
    se = Department(name="Software Engineering", code="SE")
    db.add(se)
    db.flush()

    users = {
        "teacher": User(name="Dr. Teacher", identifier="t-1", role="teacher", department_id=se.id),
        "teacher2": User(name="Dr. Other", identifier="t-2", role="teacher", department_id=se.id),
        "hod": User(name="SE HOD", identifier="hod-se", role="hod", department_id=se.id),
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
    t1 = Teacher(name="Dr. Teacher", user_id=users["teacher"].id, department_id=se.id)
    t2 = Teacher(name="Dr. Other", user_id=users["teacher2"].id, department_id=se.id)
    r1 = Room(name="R-1", capacity=50, room_type="lecture")
    r2 = Room(name="R-2", capacity=50, room_type="lecture")
    db.add_all([c1, t1, t2, r1, r2])
    db.flush()

    e1 = _entry(sec_a, c1, t1, r1, "Monday", 9, 10)
    e3 = _entry(sec_b, c1, t2, r2, "Wednesday", 9, 10)
    db.add_all([e1, e3])
    db.commit()

    app = FastAPI()
    app.include_router(swaps.router)
    app.include_router(notifications.router)

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

    return {"as": as_user, "db": session_factory, "users": users, "e1": e1, "e3": e3}


def _add_unread(w, role, how_many=1):
    """Put unread notifications straight into the database for one user."""
    db = w["db"]()
    rows = [
        Notification(user_id=w["users"][role].id, type=NotificationType.schedule_changed,
                     message=f"test message {i}")
        for i in range(how_many)
    ]
    db.add_all(rows)
    db.commit()
    return [row.id for row in rows]


def _count(w, role):
    r = w["as"](role).get("/api/notifications/unread-count")
    assert r.status_code == 200, r.text
    return r.json()["count"]


# ---- saved when something happens -----------------------------------------

def test_swap_approval_saves_a_notification_for_the_teacher(world):
    r = world["as"]("teacher").post(
        "/api/swap-requests", json={"entry_id": world["e1"].id, "target_entry_id": world["e3"].id}
    )
    assert r.status_code == 201, r.text
    swap_id = r.json()["id"]

    r = world["as"]("hod").post(f"/api/swap-requests/{swap_id}/approve", json={})
    assert r.status_code == 200, r.text

    rows = world["db"]().execute(
        select(Notification).where(
            Notification.user_id == world["users"]["teacher"].id,
            Notification.type == NotificationType.swap_approved,
        )
    ).scalars().all()
    assert len(rows) == 1
    assert rows[0].related_id == swap_id
    assert rows[0].is_read is False


# ---- unread count and mark-read -------------------------------------------

def test_unread_count_only_counts_my_own_notifications(world):
    _add_unread(world, "teacher", 2)
    _add_unread(world, "teacher2", 1)
    assert _count(world, "teacher") == 2
    assert _count(world, "teacher2") == 1
    assert _count(world, "student") == 0


def test_mark_one_notification_read(world):
    first, _second = _add_unread(world, "teacher", 2)
    r = world["as"]("teacher").patch(f"/api/notifications/{first}/read")
    assert r.status_code == 200, r.text
    assert world["db"]().get(Notification, first).is_read is True
    assert _count(world, "teacher") == 1


def test_mark_all_read(world):
    _add_unread(world, "teacher", 3)
    other = _add_unread(world, "teacher2", 1)[0]
    r = world["as"]("teacher").post("/api/notifications/read-all")
    assert r.status_code == 200, r.text
    assert r.json()["updated"] == 3
    assert _count(world, "teacher") == 0
    assert world["db"]().get(Notification, other).is_read is False   # someone else's: untouched


# ---- privacy ----------------------------------------------------------------

def test_cannot_mark_another_users_notification_read(world):
    theirs = _add_unread(world, "teacher2", 1)[0]
    r = world["as"]("teacher").patch(f"/api/notifications/{theirs}/read")
    assert r.status_code == 404
    assert world["db"]().get(Notification, theirs).is_read is False


def test_unknown_notification_id_gives_404(world):
    r = world["as"]("teacher").patch("/api/notifications/99999/read")
    assert r.status_code == 404