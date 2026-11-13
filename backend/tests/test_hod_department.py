"""BE-07 tests: a HOD needs a department, and may only decide their own department's swaps."""
from datetime import time

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.database import get_db
from app.dependencies import get_current_user
from app.models import makeup_class, notification, swap_request  # noqa: F401  (register tables)
from app.models.course import Course
from app.models.department import Department
from app.models.room import Room
from app.models.section import Section
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User
from app.routers import auth, swaps


def _entry(section, course, teacher, room, day, start, end):
    return TimetableEntry(
        section_id=section.id, course_id=course.id, teacher_id=teacher.id, room_id=room.id,
        day_of_week=day, start_time=time(start), end_time=time(end),
    )


@pytest.fixture()
def world(session_factory):
    """Two departments (SE, CS), a teacher, two HODs, a student, an admin and two classes."""
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
    app.include_router(auth.router)

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

    return {"as": as_user, "users": users, "se": se, "e1": e1, "e3": e3}


def _new_swap(w):
    r = w["as"]("teacher").post(
        "/api/swap-requests", json={"entry_id": w["e1"].id, "target_entry_id": w["e3"].id}
    )
    assert r.status_code == 201, r.text
    return r.json()["id"]


def _new_user(role, **extra):
    return {"name": "New Person", "identifier": "new-person-1", "password": "password123",
            "role": role, **extra}


# ---- who may approve ------------------------------------------------------

def test_hod_of_same_department_can_approve(world):
    sid = _new_swap(world)
    r = world["as"]("hod").post(f"/api/swap-requests/{sid}/approve", json={})
    assert r.status_code == 200, r.text
    assert r.json()["status"] == "approved"


def test_hod_of_another_department_is_refused(world):
    sid = _new_swap(world)
    r = world["as"]("hod_cs").post(f"/api/swap-requests/{sid}/approve", json={})
    assert r.status_code == 403


def test_student_cannot_approve(world):
    sid = _new_swap(world)
    r = world["as"]("student").post(f"/api/swap-requests/{sid}/approve", json={})
    assert r.status_code == 403


# ---- creating a HOD account -----------------------------------------------

def test_creating_hod_without_department_gives_400(world):
    r = world["as"]("admin").post("/api/auth/users", json=_new_user("hod"))
    assert r.status_code == 400


def test_creating_hod_with_department_saves_it(world):
    r = world["as"]("admin").post(
        "/api/auth/users", json=_new_user("hod", department_id=world["se"].id)
    )
    assert r.status_code == 201, r.text
    assert r.json()["role"] == "hod"
    assert r.json()["department_id"] == world["se"].id


def test_creating_hod_with_unknown_department_gives_400(world):
    r = world["as"]("admin").post("/api/auth/users", json=_new_user("hod", department_id=9999))
    assert r.status_code == 400


def test_teacher_account_does_not_need_a_department(world):
    r = world["as"]("admin").post("/api/auth/users", json=_new_user("teacher"))
    assert r.status_code == 201, r.text
    

