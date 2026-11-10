"""Auth API tests: login, activate, /me and admin user creation (BE-04).

Unlike the portal tests, these must NOT fake the logged-in user: login and
/me are the code that produces and checks the token. So this file builds its
own small app with only the auth router, on conftest's in-memory database,
and leaves the real get_current_user in place.
"""
import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.core.security import hash_password
from app.database import get_db
from app.models.department import Department
from app.models.section import Section
from app.models.user import User
from app.routers import auth

PASSWORD = "correct-horse-1"


@pytest.fixture()
def api(session_factory):
    application = FastAPI()
    application.include_router(auth.router)

    # Same database as the `db` fixture, so rows a test adds are visible here.
    def _db():
        session = session_factory()
        try:
            yield session
        finally:
            session.close()

    application.dependency_overrides[get_db] = _db
    return TestClient(application)


@pytest.fixture()
def people(db):
    """One active account per role, plus one roster student with no password yet."""
    dept = Department(name="Software Engineering", code="SE")
    db.add(dept)
    db.flush()
    sec = Section(name="BSSE-5A", department_id=dept.id)
    db.add(sec)
    db.flush()

    def user(identifier, role, password=PASSWORD, **extra):
        # Stored the way auth.py stores identifiers: trimmed and lowercased.
        row = User(name=identifier.upper(), identifier=identifier, role=role,
                   password_hash=hash_password(password) if password else None, **extra)
        db.add(row)
        return row

    rows = {
        "student": user("se-123456", "student", section_id=sec.id, department_id=dept.id),
        "roster": user("se-654321", "student", password=None, section_id=sec.id),
        "teacher": user("t-ali", "teacher", department_id=dept.id),
        "hod": user("h-sara", "hod", department_id=dept.id),
        "admin": user("a-root", "admin"),
    }
    db.commit()
    return rows


def login(api, identifier, password=PASSWORD):
    return api.post("/api/auth/login", json={"identifier": identifier, "password": password})


def bearer(api, identifier):
    """Authorization header for a real token obtained through /login."""
    token = login(api, identifier).json()["token"]
    return {"Authorization": f"Bearer {token}"}


def new_staff(**overrides):
    body = {"name": "Dr. New", "identifier": "t-new", "password": "long-enough-1", "role": "teacher"}
    return {**body, **overrides}


# --------------------------------------------------------------------------- login

def test_login_success_returns_token_and_user(api, people):
    r = login(api, "se-123456")
    assert r.status_code == 200
    body = r.json()
    assert body["token"]
    assert body["user"]["identifier"] == "se-123456"
    assert body["user"]["role"] == "student"


def test_login_wrong_password_and_unknown_identifier_are_both_401(api, people):
    wrong = login(api, "se-123456", "not-the-password")
    unknown = login(api, "se-000000")
    assert wrong.status_code == unknown.status_code == 401
    # Same message for both, so login can't be used to find out which roll numbers exist.
    assert wrong.json()["detail"] == unknown.json()["detail"]


def test_login_for_unactivated_student_is_401(api, people):
    # The roster student has no password_hash; no password may log them in.
    assert login(api, "se-654321", "").status_code == 422  # empty password is not even accepted
    assert login(api, "se-654321", "anything-at-all").status_code == 401


def test_login_identifier_ignores_case_and_spaces(api, people):
    r = login(api, " SE-123456 ")
    assert r.status_code == 200
    assert r.json()["user"]["identifier"] == "se-123456"


# --------------------------------------------------------------------------- activate

def test_activate_logs_the_student_in_and_works_only_once(api, people):
    first = api.post("/api/auth/activate", json={"identifier": "se-654321", "new_password": "my-new-pass"})
    assert first.status_code == 200
    assert first.json()["user"]["identifier"] == "se-654321"
    assert login(api, "se-654321", "my-new-pass").status_code == 200

    # The account now has a password, so it can't be claimed again (which would
    # let anyone who knows a roll number take over an activated account).
    again = api.post("/api/auth/activate", json={"identifier": "se-654321", "new_password": "hijack-pass"})
    assert again.status_code == 400
    assert login(api, "se-654321", "hijack-pass").status_code == 401


def test_activate_on_a_teacher_account_is_rejected(api, people):
    r = api.post("/api/auth/activate", json={"identifier": "t-ali", "new_password": "takeover-pass"})
    assert r.status_code == 400


# --------------------------------------------------------------------------- POST /users

def test_only_admin_can_create_users(api, people):
    for identifier in ("se-123456", "t-ali", "h-sara"):
        r = api.post("/api/auth/users", json=new_staff(), headers=bearer(api, identifier))
        assert r.status_code == 403, identifier

    r = api.post("/api/auth/users", json=new_staff(identifier=" T-NEW "), headers=bearer(api, "a-root"))
    assert r.status_code == 201
    assert r.json()["identifier"] == "t-new"
    assert r.json()["role"] == "teacher"
    assert login(api, "t-new", "long-enough-1").status_code == 200


def test_admin_cannot_create_a_student(api, people):
    # Students come from the roster import, never from the admin form.
    r = api.post("/api/auth/users", json=new_staff(role="student"), headers=bearer(api, "a-root"))
    assert r.status_code == 400


def test_duplicate_identifier_is_409(api, people):
    admin = bearer(api, "a-root")
    r = api.post("/api/auth/users", json=new_staff(identifier="t-ali"), headers=admin)
    assert r.status_code == 409
    # Case and spaces are normalised first, so this is the same identifier.
    r = api.post("/api/auth/users", json=new_staff(identifier=" T-Ali "), headers=admin)
    assert r.status_code == 409


# --------------------------------------------------------------------------- /me

def test_me_returns_the_new_fields(api, people):
    r = api.get("/api/auth/me", headers=bearer(api, "se-123456"))
    assert r.status_code == 200
    me = r.json()
    student = people["student"]
    assert me["identifier"] == "se-123456"
    assert me["role"] == "student"
    assert me["section_id"] == student.section_id
    assert me["department_id"] == student.department_id

    hod = api.get("/api/auth/me", headers=bearer(api, "h-sara")).json()
    assert hod["department_id"] == people["hod"].department_id
    assert hod["section_id"] is None


def test_me_without_a_token_is_401(api, people):
    assert api.get("/api/auth/me").status_code == 401


# --------------------------------------------------------------------------- removed endpoint

def test_register_endpoint_is_gone(api, people):
    r = api.post("/api/auth/register", json={
        "name": "Anyone", "identifier": "se-999999", "section_id": 1, "password": "long-enough-1"})
    assert r.status_code in (404, 405)
