"""API tests: GPA / CGPA endpoints."""


def term(name, *courses):
    return {"term": name, "courses": [{"credit_hours": c[0], "grade": c[1], **({"course_code": c[2]} if len(c) > 2 else {})}
                                      for c in courses]}


def test_gpa_calculate_endpoint(client):
    r = client("student").post("/api/gpa/calculate", json={"terms": [term("T1", (3, "A")), term("T2", (6, "C"))]})
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["cgpa"] == 2.67 and body["total_credit_hours"] == 9
    assert [t["gpa"] for t in body["terms"]] == [4.0, 2.0]


def test_gpa_calculate_options_and_errors(client):
    c = client("student")
    prev = c.post("/api/gpa/calculate", json={"terms": [term("T1", (3, "A"))],
                                              "previous_cgpa": 3.0, "previous_credit_hours": 60})
    assert prev.json()["cgpa"] == 3.05
    rep = c.post("/api/gpa/calculate", json={"terms": [term("T1", (3, "F", "CS1")), term("T2", (3, "B", "CS1"))],
                                             "repeat_policy": "latest"})
    assert rep.json()["cgpa"] == 3.0
    assert c.post("/api/gpa/calculate", json={"terms": [term("T1", (3, "Q"))]}).status_code == 422
    assert c.post("/api/gpa/calculate", json={"terms": [term("T1", (0, "A"))]}).status_code == 422
    assert c.post("/api/gpa/calculate", json={"terms": [term("T1", (3, "A"))], "previous_cgpa": 3.0}).status_code == 422
    assert c.post("/api/gpa/calculate", json={"terms": []}).status_code == 422
    assert c.post("/api/gpa/calculate", json={"terms": [term("T1", (3, "W"))]}).json()["cgpa"] is None


def test_grade_scale_endpoint(client):
    body = client("student").get("/api/gpa/grade-scale").json()
    assert body["grade_points"]["A"] == 4.0 and "W" in body["excluded_grades"]


def test_saved_records_and_summary(client):
    s = client("student")
    rec = lambda term_, code, cr, g: s.post("/api/gpa/records", json={  # noqa: E731
        "term": term_, "course_code": code, "credit_hours": cr, "grade": g})
    assert rec("Fall 2025", "cs101", 3, "a").status_code == 201
    assert rec("Fall 2025", "MT101", 3, "B").status_code == 201
    assert rec("Spring 2026", "CS102", 3, "c").status_code == 201
    assert rec("Fall 2025", "CS101", 3, "A").status_code == 409        # duplicate (code is upper-cased)
    assert rec("Fall 2025", "XX1", 3, "Q").status_code == 422
    records = s.get("/api/gpa/records").json()
    assert [r["course_code"] for r in records] == ["CS101", "MT101", "CS102"]
    assert records[0]["grade"] == "A"
    summary = s.get("/api/gpa/summary").json()
    assert [t["term"] for t in summary["terms"]] == ["Fall 2025", "Spring 2026"]
    assert summary["terms"][0]["gpa"] == 3.5
    assert summary["cgpa"] == 3.0  # (12 + 9 + 6) / 9


def test_records_are_private_per_student(client):
    a, b = client("student"), client("student2")
    rid = a.post("/api/gpa/records", json={"term": "T", "course_code": "C1", "credit_hours": 3, "grade": "A"}).json()["id"]
    assert b.get("/api/gpa/records").json() == []
    assert b.delete(f"/api/gpa/records/{rid}").status_code == 404
    assert len(a.get("/api/gpa/records").json()) == 1
    assert a.delete(f"/api/gpa/records/{rid}").status_code == 204
    assert a.get("/api/gpa/summary").json()["cgpa"] is None


def test_summary_repeat_policy(client):
    s = client("student")
    for t, g in (("T1", "F"), ("T2", "B")):
        s.post("/api/gpa/records", json={"term": t, "course_code": "CS1", "credit_hours": 3, "grade": g})
    assert s.get("/api/gpa/summary").json()["cgpa"] == 1.5
    assert s.get("/api/gpa/summary", params={"repeat_policy": "latest"}).json()["cgpa"] == 3.0
    assert s.get("/api/gpa/summary", params={"repeat_policy": "nope"}).status_code == 422
