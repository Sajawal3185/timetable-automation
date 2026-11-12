"""API tests: timetable import endpoint and timetable listing."""
import io

from openpyxl import Workbook

from app.models.timetable_entry import TimetableEntry
from test.helpers import XLSX_MIME


def flat_xlsx_bytes():
    wb = Workbook()
    ws = wb.active
    ws.title = "BSSE-4A"
    ws.append(["Day", "Time", "Course", "Teacher", "Room"])
    ws.append(["Monday", "08:30 - 10:00", "SE201 Software Design", "Dr. Ali", "R-2"])
    ws.append(["Monday", "10:00 - 11:30", "CS101 Programming Fundamentals", "Ms. Sara", "NEW-7"])
    ws.append(["Tuesday", "bad", "SE201 Software Design", "Dr. Ali", "R-2"])
    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()


def test_import_dry_run_saves_nothing(client, seed, db):
    r = client("admin").post("/api/timetable/import", data={"department_code": "se"},
                             files={"file": ("se.xlsx", flat_xlsx_bytes(), XLSX_MIME)})
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["dry_run"] is True
    assert (body["rows_parsed"], body["rows_valid"]) == (2, 2)
    assert len(body["issues"]) == 1 and body["issues"][0]["row"] == 4
    assert body["preview"][0]["start_time"] == "08:30"
    assert body["entries_created"] == 0
    assert db.query(TimetableEntry).count() == 0


def test_import_for_real_then_again(client, seed, db):
    send = lambda: client("hod").post(  # noqa: E731
        "/api/timetable/import", data={"department_code": "SE", "dry_run": "false"},
        files={"file": ("se.xlsx", flat_xlsx_bytes(), XLSX_MIME)})
    first = send().json()
    assert first["entries_created"] == 2 and first["rooms_created"] == ["NEW-7"]
    assert db.query(TimetableEntry).count() == 2
    second = send().json()
    assert second["entries_created"] == 0 and second["entries_skipped_existing"] == 2
    assert db.query(TimetableEntry).count() == 2


def test_import_rejects_bad_requests(client, seed):
    admin = client("admin")
    f = {"file": ("se.xlsx", flat_xlsx_bytes(), XLSX_MIME)}
    assert client("teacher").post("/api/timetable/import", data={"department_code": "SE"}, files=f).status_code == 403
    assert admin.post("/api/timetable/import", data={"department_code": "ZZ"}, files=f).status_code == 422
    assert admin.post("/api/timetable/import", data={"department_code": "SE"},
                      files={"file": ("x.pdf", b"abc", "application/pdf")}).status_code == 400
    assert admin.post("/api/timetable/import", data={"department_code": "SE"},
                      files={"file": ("x.xlsx", b"", XLSX_MIME)}).status_code == 400
    corrupt = admin.post("/api/timetable/import", data={"department_code": "SE"},
                         files={"file": ("x.xlsx", b"not really a workbook", XLSX_MIME)})
    assert corrupt.status_code == 400 and "Could not read" in corrupt.json()["detail"]


def test_list_timetable_filters(client, seed, db):
    client("admin").post("/api/timetable/import", data={"department_code": "SE", "dry_run": "false"},
                         files={"file": ("se.xlsx", flat_xlsx_bytes(), XLSX_MIME)})
    c = client("student")
    assert len(c.get("/api/timetable").json()) == 2
    assert len(c.get("/api/timetable", params={"day": "mon"}).json()) == 2
    assert c.get("/api/timetable", params={"day": "Tuesday"}).json() == []
    assert len(c.get("/api/timetable", params={"department_id": seed.dept.id}).json()) == 2
    assert c.get("/api/timetable", params={"department_id": 999}).json() == []
    assert c.get("/api/timetable", params={"day": "xyz"}).status_code == 422
