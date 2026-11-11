"""API tests: lecture material archive."""
from test.helpers import PDF, PPTX, any_file, pdf_file


def upload_material(c, seed, **over):
    data = {"course_id": seed.c1.id, "title": "Week 1 slides", "material_type": "slides"}
    data.update(over)
    return c.post("/api/lecture-materials", data=data, files=pdf_file("week1.pdf"))


def test_materials_grouped_by_instructor(client, seed):
    # Only HOD/admin may file material under another instructor's name (a teacher's
    # upload is always filed under their own name, see test below).
    upload_material(client("hod"), seed, instructor_name="Dr. Ali")
    upload_material(client("hod"), seed, title="Week 2 notes", material_type="notes", instructor_name="Dr. Ali")
    upload_material(client("admin"), seed, title="My slides", instructor_name="Ms. Sara")
    upload_material(client("teacher"), seed, course_id=seed.c2.id, title="Other course")
    r = client("student").get(f"/api/lecture-materials/by-course/{seed.c1.id}")
    assert r.status_code == 200
    body = r.json()
    assert body["course_code"] == "CS101"
    assert [(g["instructor_name"], len(g["materials"])) for g in body["instructors"]] == [("Dr. Ali", 2), ("Ms. Sara", 1)]
    assert client("student").get("/api/lecture-materials/by-course/999").status_code == 404


def test_material_defaults_instructor_to_uploader_and_filters(client, seed):
    m = upload_material(client("teacher"), seed).json()
    assert m["instructor_name"] == "Dr. Teacher"
    # a teacher cannot file material under someone else's name
    m = upload_material(client("teacher"), seed, title="Spoof", instructor_name="Dr. Ali").json()
    assert m["instructor_name"] == "Dr. Teacher"
    upload_material(client("teacher2"), seed, title="Notes", material_type="notes", term="Fall 2025")
    s = client("student")
    assert len(s.get("/api/lecture-materials").json()) == 3
    assert [x["title"] for x in s.get("/api/lecture-materials", params={"material_type": "notes"}).json()] == ["Notes"]
    assert len(s.get("/api/lecture-materials", params={"instructor_name": "dr. oth"}).json()) == 1
    assert len(s.get("/api/lecture-materials", params={"term": "fall 2025"}).json()) == 1
    assert len(s.get("/api/lecture-materials", params={"q": "notes"}).json()) == 1


def test_material_permissions_download_delete(client, seed):
    assert upload_material(client("student"), seed).status_code == 403
    mid = upload_material(client("teacher"), seed).json()["id"]
    assert client("student").get(f"/api/lecture-materials/{mid}/download").content == PDF
    assert client("teacher2").delete(f"/api/lecture-materials/{mid}").status_code == 403
    assert client("admin").delete(f"/api/lecture-materials/{mid}").status_code == 204
    assert client("student").get(f"/api/lecture-materials/{mid}").status_code == 404
    pptx = client("teacher").post("/api/lecture-materials", files=any_file("a.pptx", PPTX), data={
        "course_id": seed.c1.id, "title": "ppt", "material_type": "slides"})
    assert pptx.status_code == 201  # slides are allowed here (not in exam papers)


def test_material_instructor_name_rule(client, seed):
    """A teacher's file is filed under their own name; HOD/admin may name another instructor."""
    teacher = client("teacher")
    name_of = lambda r: (r.status_code, r.json()["instructor_name"])  # noqa: E731
    assert name_of(upload_material(teacher, seed, instructor_name="Ms. Sara")) == (201, "Dr. Teacher")   # ignored
    assert name_of(upload_material(teacher, seed)) == (201, "Dr. Teacher")                                # omitted
    assert name_of(upload_material(teacher, seed, instructor_name="   ")) == (201, "Dr. Teacher")        # blank
    assert name_of(upload_material(client("admin"), seed, instructor_name="Dr. Ali")) == (201, "Dr. Ali")
    assert name_of(upload_material(client("hod"), seed, instructor_name="  Ms. Sara  ")) == (201, "Ms. Sara")  # trimmed
