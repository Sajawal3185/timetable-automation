"""Tests for PR #2: scheduler_rules.py, makeup_solver.py, and routers/makeup.py (Sajawal)."""
from datetime import time
from types import SimpleNamespace
import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient
from sqlalchemy import create_engine, event
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

from app.database import Base, get_db
from app.dependencies import get_current_user
from app.models import Course, Department, Room, Section, Teacher, TimetableEntry, User
from app.models.makeup_class import MakeupClass  # noqa: F401
from app.routers import makeup
from app.services.scheduler_rules import (
    ScheduleSlot,
    check_hard_clashes,
    check_room_suitability,
    check_soft_section_gaps,
)


@pytest.fixture()
def session_factory():
    engine = create_engine("sqlite://", connect_args={"check_same_thread": False}, poolclass=StaticPool)

    @event.listens_for(engine, "connect")
    def _fk_on(dbapi_conn, _):
        dbapi_conn.execute("PRAGMA foreign_keys=ON")

    Base.metadata.create_all(engine)
    yield sessionmaker(bind=engine, autoflush=True, expire_on_commit=False)
    engine.dispose()


@pytest.fixture()
def db(session_factory):
    s = session_factory()
    yield s
    s.close()


@pytest.fixture()
def seed(db):
    dept = Department(name="Software Engineering", code="SE")
    db.add(dept)
    db.flush()
    sec = Section(name="BSSE-5A", department_id=dept.id, student_count=45)
    t1 = Teacher(name="Dr. Ali", department_id=dept.id)
    t2 = Teacher(name="Ms. Sara", department_id=dept.id)
    c_lec = Course(code="SE301", title="Web Eng", department_id=dept.id, is_lab=False)
    c_lab = Course(code="SE301L", title="Web Eng Lab", department_id=dept.id, is_lab=True)
    r_small = Room(name="R-Small", capacity=30, room_type="lecture")
    r_pref = Room(name="R-Pref", capacity=50, room_type="lecture")
    r_fallback = Room(name="R-Fallback", capacity=60, room_type="lecture")
    r_unknown = Room(name="R-Unknown", capacity=None, room_type="lecture")
    r_lab = Room(name="Lab-1", capacity=50, room_type="lab")
    u_teacher = User(name="Dr. Ali", email="ali@numl.edu.pk", password_hash="x", role="teacher")
    db.add_all([sec, t1, t2, c_lec, c_lab, r_small, r_pref, r_fallback, r_unknown, r_lab, u_teacher])
    db.commit()
    return SimpleNamespace(
        dept=dept, sec=sec, t1=t1, t2=t2, c_lec=c_lec, c_lab=c_lab,
        r_small=r_small, r_pref=r_pref, r_fallback=r_fallback, r_unknown=r_unknown, r_lab=r_lab,
        u_teacher=u_teacher,
    )


def test_elective_group_exemption_and_hard_clashes():
    existing = [ScheduleSlot(
        day_of_week="Monday", start_time=time(8, 30), end_time=time(10, 0),
        section_id=1, course_id=10, teacher_id=1, room_id=1, elective_group="ELEC-1",
    )]
    # Parallel elective in same section with different teacher/room and same elective_group -> 0 clashes
    parallel = ScheduleSlot(
        day_of_week="Monday", start_time=time(8, 30), end_time=time(10, 0),
        section_id=1, course_id=11, teacher_id=2, room_id=2, elective_group="ELEC-1",
    )
    assert check_hard_clashes(parallel, existing) == []

    # Without elective_group -> section clash
    regular = ScheduleSlot(
        day_of_week="Monday", start_time=time(9, 0), end_time=time(10, 30),
        section_id=1, course_id=12, teacher_id=3, room_id=3,
    )
    assert [v.kind for v in check_hard_clashes(regular, existing)] == ["section_clash"]


def test_cross_table_id_collision_not_skipped():
    # TimetableEntry(id=1) and MakeupClass(id=1) share entry_id=1 but have different entry_type
    existing_makeup = [ScheduleSlot(
        day_of_week="Monday", start_time=time(9, 0), end_time=time(10, 0),
        section_id=2, course_id=10, teacher_id=1, room_id=5,
        entry_id=1, entry_type="makeup",
    )]
    candidate_timetable = ScheduleSlot(
        day_of_week="Monday", start_time=time(9, 0), end_time=time(10, 0),
        section_id=3, course_id=11, teacher_id=1, room_id=6,
        entry_id=1, entry_type="timetable",
    )
    clashes = check_hard_clashes(candidate_timetable, existing_makeup)
    assert [v.kind for v in clashes] == ["teacher_clash"]


def test_unknown_capacity_and_lab_type_rejected():
    v_unknown = check_room_suitability(student_count=40, room_capacity=None)
    assert v_unknown and v_unknown[0].kind == "unknown_capacity"

    v_lab = check_room_suitability(student_count=40, room_capacity=50, requires_lab=True, room_type="lecture")
    assert v_lab and v_lab[0].kind == "room_type_mismatch"


def test_soft_rule_long_student_gap():
    slots = [
        ScheduleSlot("Monday", time(8, 0), time(9, 0), section_id=1, course_id=1),
        ScheduleSlot("Monday", time(12, 0), time(13, 0), section_id=1, course_id=2),
    ]
    warnings = check_soft_section_gaps(slots, max_allowed_gap_minutes=120)
    assert len(warnings) == 1 and warnings[0].kind == "long_student_gap"


def test_makeup_solver_room_fallback_and_booking(session_factory, db, seed):
    # Book R-Pref on Monday 08:00-09:00 for another section so R-Fallback (cap 60) must be chosen
    other_sec = Section(name="BSSE-5B", department_id=seed.dept.id, student_count=40)
    db.add(other_sec)
    db.flush()
    db.add(TimetableEntry(
        section_id=other_sec.id, course_id=seed.c_lec.id, teacher_id=seed.t2.id,
        room_id=seed.r_pref.id, day_of_week="Monday", start_time=time(8, 0), end_time=time(9, 0),
    ))
    db.commit()

    app = FastAPI()
    app.include_router(makeup.router)
    app.dependency_overrides[get_db] = lambda: db
    app.dependency_overrides[get_current_user] = lambda: seed.u_teacher
    client = TestClient(app)

    res = client.post("/api/makeup/find-slots", json={
        "section_id": seed.sec.id, "teacher_id": seed.t1.id, "course_id": seed.c_lec.id,
        "duration_minutes": 60, "preferred_room_id": seed.r_pref.id, "days": ["Monday"],
    })
    assert res.status_code == 200
    slots = res.json()
    first = next(s for s in slots if s["start_time"] == "08:00:00")
    # R-Small (30) and R-Unknown (None) are excluded; R-Pref is busy at 08:00 -> recommends R-Fallback (60)
    assert first["recommended_room"]["room_name"] == "R-Fallback"

    # At 09:00 R-Pref is free -> recommends R-Pref with R-Fallback in fallback_rooms
    nine_am = next(s for s in slots if s["start_time"] == "09:00:00")
    assert nine_am["recommended_room"]["room_name"] == "R-Pref"
    assert nine_am["fallback_rooms"][0]["room_name"] == "R-Fallback"

    # Book the 09:00 makeup class, then verify re-booking the same slot returns 409 clash
    book_payload = {
        "course_id": seed.c_lec.id, "section_id": seed.sec.id, "teacher_id": seed.t1.id,
        "room_id": seed.r_pref.id, "day_of_week": "Monday", "start_time": "09:00", "end_time": "10:00",
    }
    assert client.post("/api/makeup", json=book_payload).status_code == 201
    assert client.post("/api/makeup", json=book_payload).status_code == 409