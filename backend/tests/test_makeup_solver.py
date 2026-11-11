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
    u_teacher = User(name="Dr. Ali", identifier="t-ali", email="ali@numl.edu.pk", password_hash="x", role="teacher")
    db.add(u_teacher)
    db.flush()
    sec = Section(name="BSSE-5A", department_id=dept.id, student_count=45)
    # Dr. Ali's teacher row is linked to his login, so he may book makeups for himself (B-8)
    t1 = Teacher(name="Dr. Ali", department_id=dept.id, user_id=u_teacher.id)
    t2 = Teacher(name="Ms. Sara", department_id=dept.id)
    c_lec = Course(code="SE301", title="Web Eng", department_id=dept.id, is_lab=False)
    c_lab = Course(code="SE301L", title="Web Eng Lab", department_id=dept.id, is_lab=True)
    r_small = Room(name="R-Small", capacity=30, room_type="lecture")
    r_pref = Room(name="R-Pref", capacity=50, room_type="lecture")
    r_fallback = Room(name="R-Fallback", capacity=60, room_type="lecture")
    r_unknown = Room(name="R-Unknown", capacity=None, room_type="lecture")
    r_lab = Room(name="Lab-1", capacity=50, room_type="lab")
    db.add_all([sec, t1, t2, c_lec, c_lab, r_small, r_pref, r_fallback, r_unknown, r_lab])
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

# ---------------------------------------------------------------------------
# B-8: makeup booking safety (right rooms, owner check, no double booking)
# ---------------------------------------------------------------------------

def _client_as(db, user):
    app = FastAPI()
    app.include_router(makeup.router)
    app.dependency_overrides[get_db] = lambda: db
    app.dependency_overrides[get_current_user] = lambda: user
    return TestClient(app)


def _booking(seed, **overrides):
    payload = {
        "course_id": seed.c_lec.id, "section_id": seed.sec.id, "teacher_id": seed.t1.id,
        "room_id": seed.r_pref.id, "day_of_week": "Tuesday", "start_time": "11:00", "end_time": "12:00",
    }
    payload.update(overrides)
    return payload


def _staff_user(db, identifier, role, department_id=None):
    user = User(name=identifier, identifier=identifier, password_hash="x", role=role, department_id=department_id)
    db.add(user)
    db.commit()
    return user


def test_lecture_course_refused_in_lab_room():
    v = check_room_suitability(student_count=40, room_capacity=50, requires_lab=False, room_type="lab")
    assert [x.kind for x in v] == ["room_type_mismatch"]
    # a lab course in a lab and a lecture course in a lecture room are both fine
    assert check_room_suitability(student_count=40, room_capacity=50, requires_lab=True, room_type="lab") == []
    assert check_room_suitability(student_count=40, room_capacity=50, requires_lab=False, room_type="lecture") == []


def test_find_slots_never_offers_a_lab_for_a_lecture_course(db, seed):
    res = _client_as(db, seed.u_teacher).post("/api/makeup/find-slots", json={
        "section_id": seed.sec.id, "teacher_id": seed.t1.id, "course_id": seed.c_lec.id,
        "duration_minutes": 60, "days": ["Monday"],
    })
    assert res.status_code == 200 and res.json()
    offered = {r["room_name"] for s in res.json() for r in [s["recommended_room"], *s["fallback_rooms"]]}
    assert "Lab-1" not in offered


def test_booking_lecture_course_in_lab_refused(db, seed):
    res = _client_as(db, seed.u_teacher).post("/api/makeup", json=_booking(seed, room_id=seed.r_lab.id))
    assert res.status_code == 409
    assert "lab room" in res.json()["detail"]


def test_lab_course_can_book_a_lab(db, seed):
    res = _client_as(db, seed.u_teacher).post(
        "/api/makeup", json=_booking(seed, course_id=seed.c_lab.id, room_id=seed.r_lab.id),
    )
    assert res.status_code == 201


def test_teacher_cannot_search_or_book_for_another_teacher(db, seed):
    client = _client_as(db, seed.u_teacher)  # Dr. Ali, trying to act for Ms. Sara (t2)
    search = client.post("/api/makeup/find-slots", json={
        "section_id": seed.sec.id, "teacher_id": seed.t2.id, "course_id": seed.c_lec.id, "duration_minutes": 60,
    })
    assert search.status_code == 403
    book = client.post("/api/makeup", json=_booking(seed, teacher_id=seed.t2.id))
    assert book.status_code == 403
    assert db.query(MakeupClass).count() == 0


def test_hod_books_only_within_own_department_admin_anywhere(db, seed):
    other_dept = Department(name="Computer Science", code="CS")
    db.add(other_dept)
    db.commit()
    hod_same = _staff_user(db, "hod-se", "hod", department_id=seed.dept.id)
    hod_other = _staff_user(db, "hod-cs", "hod", department_id=other_dept.id)
    admin = _staff_user(db, "admin-1", "admin")

    assert _client_as(db, hod_other).post("/api/makeup", json=_booking(seed, teacher_id=seed.t2.id)).status_code == 403
    assert _client_as(db, hod_same).post("/api/makeup", json=_booking(seed, teacher_id=seed.t2.id)).status_code == 201
    assert _client_as(db, admin).post(
        "/api/makeup", json=_booking(seed, teacher_id=seed.t1.id, day_of_week="Wednesday"),
    ).status_code == 201


def test_second_booking_of_same_slot_refused(db, seed):
    client = _client_as(db, seed.u_teacher)
    assert client.post("/api/makeup", json=_booking(seed)).status_code == 201
    again = client.post("/api/makeup", json=_booking(seed))
    assert again.status_code == 409
    assert db.query(MakeupClass).count() == 1


def test_database_refuses_double_booking_when_clash_check_is_bypassed(db, seed, monkeypatch):
    """Simulates two requests racing past the clash check at the same moment:
    the unique key still stops the second one, and the API answers 409, not 500."""
    monkeypatch.setattr(makeup, "_load_active_slots", lambda *_a, **_k: [])
    other_sec = Section(name="BSSE-5B", department_id=seed.dept.id, student_count=40)
    db.add(other_sec)
    db.commit()
    client = _client_as(db, seed.u_teacher)
    assert client.post("/api/makeup", json=_booking(seed)).status_code == 201
    # same room, day and start time, different section: only the database can catch it now
    res = client.post("/api/makeup", json=_booking(seed, section_id=other_sec.id))
    assert res.status_code == 409
    assert "just booked" in res.json()["detail"]
    assert db.query(MakeupClass).count() == 1


def test_cancelled_makeup_does_not_block_the_slot_in_the_database(db, seed):
    from sqlalchemy.exc import IntegrityError

    def row(status):
        return MakeupClass(
            course_id=seed.c_lec.id, section_id=seed.sec.id, teacher_id=seed.t1.id, room_id=seed.r_pref.id,
            day_of_week="Thursday", start_time=time(9, 0), end_time=time(10, 0), status=status,
        )

    db.add_all([row("cancelled"), row("cancelled"), row("scheduled")])
    db.commit()  # cancelled rows never collide, one scheduled row is fine

    db.add(row("scheduled"))
    with pytest.raises(IntegrityError):
        db.commit()
    db.rollback()
