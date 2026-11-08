"""API tests: rooms, empty-room search, departments, courses."""
from datetime import time

from app.models.timetable_entry import TimetableEntry


def test_list_and_filter_rooms(client, seed):
    c = client("student")
    assert [r["name"] for r in c.get("/api/rooms").json()] == ["LT-1", "R-1", "R-2"]
    big = c.get("/api/rooms", params={"min_capacity": 50}).json()
    assert {r["name"] for r in big} == {"R-2", "LT-1"}
    assert c.get("/api/rooms", params={"search": "lt"}).json()[0]["name"] == "LT-1"


def test_get_room_404(client, seed):
    assert client("student").get("/api/rooms/999").status_code == 404


def test_create_and_update_room_permissions(client, seed):
    body = {"name": "R-9", "capacity": 45, "building": "Block A"}
    assert client("student").post("/api/rooms", json=body).status_code == 403
    assert client("teacher").post("/api/rooms", json=body).status_code == 403
    created = client("admin").post("/api/rooms", json=body)
    assert created.status_code == 201
    rid = created.json()["id"]
    assert client("hod").post("/api/rooms", json=body).status_code == 409  # duplicate name
    upd = client("hod").put(f"/api/rooms/{rid}", json={"capacity": 80})
    assert upd.status_code == 200 and upd.json()["capacity"] == 80
    assert client("hod").put(f"/api/rooms/{rid}", json={"name": "R-1"}).status_code == 409
    assert client("admin").post("/api/rooms", json={"name": "X", "capacity": 0}).status_code == 422


def test_empty_rooms_respects_overlap_boundaries(client, seed, db):
    # R-1 is busy Monday 10:00-11:00
    db.add(TimetableEntry(section_id=1, course_id=seed.c1.id, room_id=seed.small.id,
                          day_of_week="Monday", start_time=time(10, 0), end_time=time(11, 0)))
    db.commit()
    c = client("student")

    def free(day, start, end, **extra):
        r = c.get("/api/rooms/empty", params={"day": day, "start": start, "end": end, **extra})
        assert r.status_code == 200, r.text
        return {x["name"] for x in r.json()}

    assert free("Monday", "10:30", "11:30") == {"R-2", "LT-1"}        # overlaps R-1
    assert free("Monday", "09:00", "10:30") == {"R-2", "LT-1"}        # overlaps at the start
    assert free("Monday", "11:00", "12:00") == {"R-1", "R-2", "LT-1"}  # starts exactly when it ends
    assert free("Monday", "09:00", "10:00") == {"R-1", "R-2", "LT-1"}  # ends exactly when it starts
    assert free("Tuesday", "10:00", "11:00") == {"R-1", "R-2", "LT-1"}  # other day
    assert free("mon", "10:00", "11:00", min_capacity=100) == {"LT-1"}


def test_empty_rooms_validation(client, seed):
    c = client("student")
    assert c.get("/api/rooms/empty", params={"day": "Funday", "start": "9:00", "end": "10:00"}).status_code == 422
    assert c.get("/api/rooms/empty", params={"day": "Monday", "start": "xx", "end": "10:00"}).status_code == 422
    assert c.get("/api/rooms/empty", params={"day": "Monday", "start": "11:00", "end": "10:00"}).status_code == 422


def test_departments_and_courses(client, seed):
    c = client("student")
    assert c.get("/api/departments").json()[0]["code"] == "SE"
    assert len(c.get("/api/courses").json()) == 2
    assert [x["code"] for x in c.get("/api/courses", params={"search": "design"}).json()] == ["SE201"]
    assert len(c.get("/api/courses", params={"department_id": seed.dept.id}).json()) == 2
    assert c.get("/api/courses", params={"department_id": 999}).json() == []
