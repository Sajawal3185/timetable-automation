"""Self-signup and forgot password (Login & Signup Plan, 12 Nov; routers/signup.py).

Like test_auth.py, these use the real get_current_user and real tokens. Emails
are not sent: send_email is replaced by a list that records them, and the tests
read the 6-digit code out of the recorded text, exactly like a person would.
"""
import re
from datetime import timedelta

import httpx
import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.core.security import hash_password
from app.database import get_db
from app.models.department import Department
from app.models.email_code import EmailCode, PendingSignup
from app.models.section import Section
from app.models.user import User
from app.routers import auth, signup
from app.services import email_sender
from app.services.email_sender import EmailError

STUDENT = "numl-f24-26789@numls.edu.pk"
STAFF = "ali.khan@numls.edu.pk"
PASSWORD = "long-enough-1"


@pytest.fixture()
def outbox(monkeypatch):
    sent = []
    monkeypatch.setattr(signup, "send_email", lambda to, subject, text: sent.append({"to": to, "text": text}))
    return sent


@pytest.fixture()
def api(session_factory, outbox):
    application = FastAPI()
    application.include_router(auth.router)
    application.include_router(signup.router)

    def _db():
        session = session_factory()
        try:
            yield session
        finally:
            session.close()

    application.dependency_overrides[get_db] = _db
    return TestClient(application)


@pytest.fixture()
def uni(db):
    se = Department(name="Software Engineering", code="SE")
    cs = Department(name="Computer Science", code="CS")
    db.add_all([se, cs])
    db.flush()
    sec, other = Section(name="BSSE-5A", department_id=se.id), Section(name="BSCS-3B", department_id=cs.id)
    db.add_all([sec, other])
    db.commit()
    return se, cs, sec, other


def last_code(outbox, to):
    mails = [m for m in outbox if m["to"] == to]
    assert mails, f"no email was sent to {to}"
    return re.search(r"\b(\d{6})\b", mails[-1]["text"]).group(1)


def student_body(uni, **over):
    se, _, sec, _ = uni
    body = {"email": STUDENT, "name": "Sara Ahmed", "department_id": se.id, "section_id": sec.id,
            "roll_number": "SE-9244330", "password": PASSWORD}
    return {**body, **over}


def staff_body(uni, **over):
    return {"email": STAFF, "name": "Ali Khan", "department_id": uni[0].id, "password": PASSWORD, **over}


def make_older(db, model, seconds, **where):
    """Move a row's timestamps into the past, instead of waiting in the test."""
    row = db.query(model).filter_by(**where).one()
    for col in ("sent_at", "expires_at", "created_at"):
        if hasattr(row, col):
            setattr(row, col, getattr(row, col) - timedelta(seconds=seconds))
    db.commit()


def login(api, identifier, password=PASSWORD):
    return api.post("/api/auth/login", json={"identifier": identifier, "password": password})


# ---- signup options ---------------------------------------------------------

def test_signup_options_lists_departments_and_sections(api, uni):
    data = api.get("/api/auth/signup-options").json()
    assert [d["code"] for d in data["departments"]] == ["CS", "SE"]   # by name
    assert {s["name"]: s["department_id"] for s in data["sections"]} == {"BSSE-5A": uni[0].id, "BSCS-3B": uni[1].id}


# ---- student and staff signup ----------------------------------------------

def test_student_signup_creates_a_logged_in_student(api, uni, outbox, db):
    res = api.post("/api/auth/signup", json=student_body(uni))
    assert res.status_code == 202, res.text
    assert res.json() == {"email": STUDENT, "kind": "student", "expires_in": 600}
    assert db.query(User).count() == 0            # nothing is an account before the code

    res = api.post("/api/auth/verify-email", json={"email": STUDENT, "code": last_code(outbox, STUDENT)})
    assert res.status_code == 200, res.text
    user = res.json()["user"]
    assert user["role"] == "student" and user["identifier"] == "numl-f24-26789"
    assert user["section_id"] == uni[2].id and user["department_id"] == uni[0].id

    me = api.get("/api/auth/me", headers={"Authorization": "Bearer " + res.json()["token"]})
    assert me.status_code == 200 and me.json()["id"] == user["id"]
    row = db.get(User, user["id"])
    assert row.email == STUDENT and row.roll_number == "se-9244330" and row.sys_id == "26789"
    assert row.email_verified_at is not None and row.password_hash != PASSWORD
    assert db.query(PendingSignup).count() == 0 and db.query(EmailCode).count() == 0   # code is single use


def test_staff_signup_is_a_teacher_with_no_approval_step(api, uni, outbox):
    assert api.post("/api/auth/signup", json=staff_body(uni)).json()["kind"] == "staff"
    res = api.post("/api/auth/verify-email", json={"email": STAFF, "code": last_code(outbox, STAFF)})
    assert res.status_code == 200, res.text
    user = res.json()["user"]
    assert user["role"] == "teacher" and user["identifier"] == STAFF
    assert user["teacher_id"] is None and user["section_id"] is None   # an admin links them later
    assert login(api, STAFF).status_code == 200                        # can log in at once


def test_role_is_never_taken_from_the_request(api, uni, outbox):
    api.post("/api/auth/signup", json=staff_body(uni, role="admin"))
    res = api.post("/api/auth/verify-email", json={"email": STAFF, "code": last_code(outbox, STAFF)})
    assert res.json()["user"]["role"] == "teacher"


def test_login_works_with_the_id_or_the_full_email(api, uni, outbox):
    api.post("/api/auth/signup", json=student_body(uni))
    api.post("/api/auth/verify-email", json={"email": STUDENT, "code": last_code(outbox, STUDENT)})
    assert login(api, "numl-f24-26789").status_code == 200
    assert login(api, "  NUML-F24-26789@numls.edu.pk ").status_code == 200
    bad = login(api, STUDENT, "wrong-password")
    assert bad.status_code == 401 and bad.json()["detail"] == "Invalid credentials"


@pytest.mark.parametrize("email", ["sara@gmail.com", "numl-f24-1@numls.edu.pk.evil.com", "a b@numls.edu.pk", "@numls.edu.pk"])
def test_only_numl_emails_can_sign_up(api, uni, outbox, email):
    res = api.post("/api/auth/signup", json=student_body(uni, email=email))
    assert res.status_code == 400 and "NUML email" in res.json()["detail"]
    assert outbox == []


@pytest.mark.parametrize("over, msg", [
    ({"roll_number": "9244330"}, "Roll number"),
    ({"roll_number": None}, "Roll number"),
    ({"section_id": None}, "section"),
    ({"section_id": 9999}, "section"),
])
def test_student_details_are_checked(api, uni, outbox, over, msg):
    res = api.post("/api/auth/signup", json=student_body(uni, **over))
    assert res.status_code == 422 and msg in res.json()["detail"]
    assert outbox == []


def test_section_must_belong_to_the_chosen_department(api, uni):
    res = api.post("/api/auth/signup", json=student_body(uni, section_id=uni[3].id))   # a CS section, SE department
    assert res.status_code == 422


def test_unknown_department_and_short_password_are_refused(api, uni):
    assert api.post("/api/auth/signup", json=staff_body(uni, department_id=9999)).status_code == 422
    assert api.post("/api/auth/signup", json=staff_body(uni, password="short")).status_code == 422


def test_an_email_that_has_an_account_gets_409(api, uni, outbox, db):
    db.add(User(name="Old", identifier=STAFF, email=STAFF, role="teacher", password_hash=hash_password(PASSWORD)))
    db.commit()
    res = api.post("/api/auth/signup", json=staff_body(uni))
    assert res.status_code == 409 and "already has an account" in res.json()["detail"]
    assert outbox == []


# ---- codes --------------------------------------------------------------------

def test_codes_are_stored_hashed(api, uni, outbox, db):
    api.post("/api/auth/signup", json=staff_body(uni))
    row = db.query(EmailCode).one()
    assert last_code(outbox, STAFF) not in row.code_hash and len(row.code_hash) == 64


def test_wrong_code_then_lockout_after_five_tries(api, uni, outbox):
    api.post("/api/auth/signup", json=staff_body(uni))
    good = last_code(outbox, STAFF)
    bad = "000000" if good != "000000" else "111111"
    for _ in range(4):
        assert api.post("/api/auth/verify-email", json={"email": STAFF, "code": bad}).status_code == 400
    assert api.post("/api/auth/verify-email", json={"email": STAFF, "code": bad}).status_code == 429
    # even the right code is refused once the limit is reached
    res = api.post("/api/auth/verify-email", json={"email": STAFF, "code": good})
    assert res.status_code == 429 and res.json()["detail"] == "Too many attempts. Try again later."


def test_expired_code_is_refused(api, uni, outbox, db):
    api.post("/api/auth/signup", json=staff_body(uni))
    code = last_code(outbox, STAFF)
    make_older(db, EmailCode, 11 * 60, email=STAFF)
    assert api.post("/api/auth/verify-email", json={"email": STAFF, "code": code}).status_code == 400


def test_expired_signup_must_start_again(api, uni, outbox, db):
    api.post("/api/auth/signup", json=staff_body(uni))
    code = last_code(outbox, STAFF)
    make_older(db, PendingSignup, 11 * 60, email=STAFF)
    res = api.post("/api/auth/verify-email", json={"email": STAFF, "code": code})
    assert res.status_code == 400 and "Start again" in res.json()["detail"]


def test_verify_without_a_signup_is_refused(api, uni):
    res = api.post("/api/auth/verify-email", json={"email": STAFF, "code": "123456"})
    assert res.status_code == 400


def test_resend_waits_a_minute_and_replaces_the_old_code(api, uni, outbox, db):
    api.post("/api/auth/signup", json=staff_body(uni))
    old = last_code(outbox, STAFF)
    assert api.post("/api/auth/resend-code", json={"email": STAFF}).status_code == 429
    make_older(db, EmailCode, 61, email=STAFF)
    assert api.post("/api/auth/resend-code", json={"email": STAFF}).status_code == 202
    new = last_code(outbox, STAFF)
    if new != old:   # 1 in a million they match
        assert api.post("/api/auth/verify-email", json={"email": STAFF, "code": old}).status_code == 400
    assert api.post("/api/auth/verify-email", json={"email": STAFF, "code": new}).status_code == 200


def test_resend_without_a_signup_is_refused(api, uni):
    assert api.post("/api/auth/resend-code", json={"email": STAFF}).status_code == 400


def test_signup_again_within_a_minute_is_refused(api, uni, outbox):
    assert api.post("/api/auth/signup", json=staff_body(uni)).status_code == 202
    assert api.post("/api/auth/signup", json=staff_body(uni)).status_code == 429   # can't flood an inbox
    assert len(outbox) == 1


def test_signup_again_later_replaces_the_unfinished_one(api, uni, outbox, db):
    api.post("/api/auth/signup", json=staff_body(uni, name="First"))
    make_older(db, EmailCode, 61, email=STAFF)
    assert api.post("/api/auth/signup", json=staff_body(uni, name="Second")).status_code == 202
    res = api.post("/api/auth/verify-email", json={"email": STAFF, "code": last_code(outbox, STAFF)})
    assert res.json()["user"]["name"] == "Second"


def test_if_the_email_cannot_be_sent_nothing_is_saved(api, uni, db, monkeypatch):
    def fail(*_args):
        raise EmailError("provider down")
    monkeypatch.setattr(signup, "send_email", fail)
    res = api.post("/api/auth/signup", json=staff_body(uni))
    assert res.status_code == 503
    assert db.query(PendingSignup).count() == 0 and db.query(EmailCode).count() == 0


# ---- forgot password --------------------------------------------------------

@pytest.fixture()
def member(api, uni, outbox):
    """A signed-up student with a real token."""
    api.post("/api/auth/signup", json=student_body(uni))
    res = api.post("/api/auth/verify-email", json={"email": STUDENT, "code": last_code(outbox, STUDENT)})
    outbox.clear()
    return res.json()["token"]


def test_forgot_password_gives_the_same_answer_for_everyone(api, uni, member, outbox, db):
    db.add(User(name="Roster", identifier="se-111111", role="student", password_hash=hash_password(PASSWORD)))
    db.commit()
    answers = [api.post("/api/auth/forgot-password", json={"identifier": who})
               for who in ("numl-f24-26789", "nobody-here", "se-111111")]   # known, unknown, no email
    assert {(r.status_code, r.json()["detail"]) for r in answers} == {
        (202, "If that account exists, we sent a code to its email.")}
    assert [m["to"] for m in outbox] == [STUDENT]   # only the real account got an email


def test_reset_password_changes_it_and_logs_out_old_tokens(api, member, outbox):
    api.post("/api/auth/forgot-password", json={"identifier": STUDENT})
    code = last_code(outbox, STUDENT)
    res = api.post("/api/auth/reset-password",
                   json={"identifier": "numl-f24-26789", "code": code, "new_password": "brand-new-pass"})
    assert res.status_code == 200, res.text

    assert login(api, STUDENT).status_code == 401                    # old password
    assert login(api, STUDENT, "brand-new-pass").status_code == 200  # new password
    old = api.get("/api/auth/me", headers={"Authorization": "Bearer " + member})
    assert old.status_code == 401                                    # signed out everywhere
    # the code is single use
    again = api.post("/api/auth/reset-password", json={"identifier": STUDENT, "code": code, "new_password": "another-pass1"})
    assert again.status_code == 400


def test_reset_with_a_wrong_code_or_unknown_account_is_refused(api, member, outbox):
    api.post("/api/auth/forgot-password", json={"identifier": STUDENT})
    good = last_code(outbox, STUDENT)
    bad = "000000" if good != "000000" else "111111"
    body = {"identifier": STUDENT, "code": bad, "new_password": "brand-new-pass"}
    assert api.post("/api/auth/reset-password", json=body).status_code == 400
    unknown = api.post("/api/auth/reset-password", json={**body, "identifier": "nobody", "code": good})
    assert unknown.status_code == 400 and unknown.json()["detail"] == "The code is wrong or has expired. Ask for a new one."
    assert login(api, STUDENT).status_code == 200                    # password unchanged


def test_forgot_password_sends_at_most_one_code_a_minute(api, member, outbox):
    for _ in range(3):
        assert api.post("/api/auth/forgot-password", json={"identifier": STUDENT}).status_code == 202
    assert len(outbox) == 1


def test_forgot_password_still_answers_202_if_the_email_fails(api, member, monkeypatch, db):
    def fail(*_args):
        raise EmailError("provider down")
    monkeypatch.setattr(signup, "send_email", fail)
    assert api.post("/api/auth/forgot-password", json={"identifier": STUDENT}).status_code == 202
    assert db.query(EmailCode).count() == 0   # no code saved that nobody received


# ---- email sender ------------------------------------------------------------

def test_brevo_sender_posts_to_the_api(monkeypatch):
    calls = []

    def fake_post(url, headers, json, timeout):
        calls.append((url, headers, json))
        return httpx.Response(201, json={"messageId": "x"})

    monkeypatch.setattr(email_sender.settings, "EMAIL_PROVIDER", "brevo")
    monkeypatch.setattr(email_sender.settings, "BREVO_API_KEY", "key-123")
    monkeypatch.setattr(email_sender.settings, "EMAIL_FROM", "team@example.com")
    monkeypatch.setattr(email_sender.httpx, "post", fake_post)
    email_sender.send_email(STAFF, "Subject", "Body 123456")
    url, headers, body = calls[0]
    assert url == email_sender.BREVO_URL and headers["api-key"] == "key-123"
    assert body["to"] == [{"email": STAFF}] and body["sender"]["email"] == "team@example.com"


def test_brevo_errors_become_email_errors(monkeypatch):
    monkeypatch.setattr(email_sender.settings, "EMAIL_PROVIDER", "brevo")
    monkeypatch.setattr(email_sender.settings, "BREVO_API_KEY", None)
    with pytest.raises(EmailError):   # not configured
        email_sender.send_email(STAFF, "s", "t")
    monkeypatch.setattr(email_sender.settings, "BREVO_API_KEY", "key-123")
    monkeypatch.setattr(email_sender.settings, "EMAIL_FROM", "team@example.com")
    monkeypatch.setattr(email_sender.httpx, "post", lambda *a, **k: httpx.Response(400, json={"message": "sender not verified"}))
    with pytest.raises(EmailError):   # refused by Brevo
        email_sender.send_email(STAFF, "s", "t")
