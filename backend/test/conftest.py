"""Shared test fixtures for Wajiha's tests.

Tests run on an in-memory SQLite database, so they need no MySQL.  The logged-in
user is faked by overriding get_current_user, so the tests do not depend on how
Sajawal finally implements JWT.

If Sajawal's real core models need extra required columns (e.g. a password hash
on User), add them in the `seed` fixture below only.
"""
from types import SimpleNamespace

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

from app.core.security import get_current_user
from app.database import Base, get_db

# importing the modules registers every table on Base.metadata
from app.models import (  # noqa: F401
    course, department, event, exam_paper, grade_record, lecture_material,
    room, section, software_resource, teacher, timetable_entry, user,
)
from app.models.course import Course
from app.models.department import Department
from app.models.room import Room
from app.routers import events, exams, gpa, materials, rooms, software, timetable

USERS = {
    "student": SimpleNamespace(id=1, name="Student One", role="student"),
    "student2": SimpleNamespace(id=5, name="Student Two", role="student"),
    "teacher": SimpleNamespace(id=2, name="Dr. Teacher", role="teacher"),
    "teacher2": SimpleNamespace(id=4, name="Dr. Other", role="teacher"),
    "hod": SimpleNamespace(id=3, name="HOD", role="hod"),
    "admin": SimpleNamespace(id=6, name="Admin", role="admin"),
}


@pytest.fixture()
def session_factory():
    engine = create_engine("sqlite://", connect_args={"check_same_thread": False}, poolclass=StaticPool)
    Base.metadata.create_all(engine)
    yield sessionmaker(bind=engine, autoflush=False, expire_on_commit=False)
    engine.dispose()


@pytest.fixture()
def db(session_factory):
    session = session_factory()
    yield session
    session.close()


@pytest.fixture()
def seed(db):
    """A small university: one department, two courses, three rooms."""
    dept = Department(name="Software Engineering", code="SE")
    db.add(dept)
    db.flush()
    c1 = Course(code="CS101", title="Programming Fundamentals", department_id=dept.id)
    c2 = Course(code="SE201", title="Software Design", department_id=dept.id)
    r_small, r_mid, r_big = Room(name="R-1", capacity=30), Room(name="R-2", capacity=60), Room(name="LT-1", capacity=150)
    db.add_all([c1, c2, r_small, r_mid, r_big])
    db.commit()
    return SimpleNamespace(dept=dept, c1=c1, c2=c2, small=r_small, mid=r_mid, big=r_big)


@pytest.fixture()
def app(session_factory, tmp_path, monkeypatch):
    monkeypatch.setenv("UPLOAD_DIR", str(tmp_path / "uploads"))
    application = FastAPI()
    for module in (timetable, rooms, exams, materials, gpa, software, events):
        application.include_router(module.router)

    def _db():
        session = session_factory()
        try:
            yield session
        finally:
            session.close()

    application.dependency_overrides[get_db] = _db
    return application


class _AsUser:
    """A test client that sends every request as one fixed role.

    The user is switched right before each call, so a test can hold a teacher
    client and a student client at the same time.
    """

    def __init__(self, app: FastAPI, role: str):
        self._app, self._role, self._client = app, role, TestClient(app)

    def __getattr__(self, name):
        method = getattr(self._client, name)

        def call(*args, **kwargs):
            self._app.dependency_overrides[get_current_user] = lambda: USERS[self._role]
            return method(*args, **kwargs)
        return call


@pytest.fixture()
def client(app):
    """client('teacher') -> a client that acts as that role."""
    return lambda role="student": _AsUser(app, role)
