"""API tests: event calendar."""


def ev(**over):
    base = {"title": "Midterm exams", "event_type": "deadline", "start_date": "2026-10-20"}
    base.update(over)
    return base


def test_event_crud_and_permissions(client, seed):
    assert client("student").post("/api/events", json=ev()).status_code == 403
    assert client("teacher").post("/api/events", json=ev()).status_code == 403
    r = client("hod").post("/api/events", json=ev(end_date="2026-10-25", location="Main hall"))
    assert r.status_code == 201
    eid = r.json()["id"]
    assert client("student").get(f"/api/events/{eid}").json()["location"] == "Main hall"
    assert client("admin").put(f"/api/events/{eid}", json={"title": "Mid-terms"}).json()["title"] == "Mid-terms"
    assert client("admin").put(f"/api/events/{eid}", json={"end_date": "2026-10-01"}).status_code == 422
    assert client("student").get(f"/api/events/{eid}").json()["end_date"] == "2026-10-25"  # failed update changed nothing
    assert client("student").delete(f"/api/events/{eid}").status_code == 403
    assert client("admin").delete(f"/api/events/{eid}").status_code == 204
    assert client("student").get(f"/api/events/{eid}").status_code == 404


def test_event_validation(client, seed):
    a = client("admin")
    assert a.post("/api/events", json=ev(end_date="2026-10-01")).status_code == 422
    assert a.post("/api/events", json=ev(event_type="party")).status_code == 422
    assert a.post("/api/events", json=ev(department_id=999)).status_code == 404


def test_event_month_and_range_filters(client, seed):
    a = client("admin")
    a.post("/api/events", json=ev(title="Oct deadline", start_date="2026-10-20"))
    a.post("/api/events", json=ev(title="Winter break", event_type="holiday", start_date="2026-12-28", end_date="2027-01-05"))
    a.post("/api/events", json=ev(title="SE seminar", event_type="department", start_date="2027-01-02",
                                  department_id=seed.dept.id))
    s = client("student")
    titles = lambda **p: [e["title"] for e in s.get("/api/events", params=p).json()]  # noqa: E731
    assert titles(month="2026-10") == ["Oct deadline"]
    assert titles(month="2026-12") == ["Winter break"]            # starts in Dec
    assert titles(month="2027-01") == ["Winter break", "SE seminar"]  # spans into Jan
    assert titles(month="2026-11") == []
    assert titles(from_date="2026-10-21") == ["Winter break", "SE seminar"]
    assert titles(to_date="2026-10-19") == []
    assert titles(event_type="holiday") == ["Winter break"]
    assert titles(department_id=seed.dept.id) == ["Oct deadline", "Winter break", "SE seminar"]  # + university-wide
    assert s.get("/api/events", params={"month": "2026-13"}).status_code == 422
    assert s.get("/api/events", params={"month": "bad"}).status_code == 422
    assert s.get("/api/events", params={"from_date": "2026-10-10", "to_date": "2026-10-01"}).status_code == 422
