"""API tests: exam repository (upload, search, download, delete, file safety)."""
from test.helpers import PDF, pdf_file


def upload_paper(c, seed, **over):
    data = {"course_id": seed.c1.id, "title": "CS101 Midterm", "term": "Fall 2025",
            "exam_type": "midterm", "year": 2025, "instructor_name": "Dr. Ali"}
    data.update(over)
    files = over.pop("_files", None) or pdf_file()
    return c.post("/api/exam-papers", data=data, files=files)


def test_exam_upload_permissions_and_validation(client, seed):
    assert upload_paper(client("student"), seed).status_code == 403
    assert upload_paper(client("teacher"), seed, course_id=999).status_code == 404
    assert upload_paper(client("teacher"), seed, exam_type="bogus").status_code == 422
    bad_ext = client("teacher").post("/api/exam-papers", files=pdf_file("evil.exe"), data={
        "course_id": seed.c1.id, "title": "t", "term": "Fall 2025", "exam_type": "final"})
    assert bad_ext.status_code == 400
    empty = client("teacher").post("/api/exam-papers", files=pdf_file(data=b""), data={
        "course_id": seed.c1.id, "title": "t", "term": "Fall 2025", "exam_type": "final"})
    assert empty.status_code == 400


def test_exam_upload_download_roundtrip(client, seed, tmp_path):
    r = upload_paper(client("teacher"), seed)
    assert r.status_code == 201, r.text
    paper = r.json()
    assert paper["original_filename"] == "paper.pdf" and paper["file_size"] == len(PDF)
    assert "file_path" not in paper  # server paths are never exposed
    dl = client("student").get(f"/api/exam-papers/{paper['id']}/download")
    assert dl.status_code == 200 and dl.content == PDF
    stored = list((tmp_path / "uploads" / "exam_papers").iterdir())
    assert len(stored) == 1 and stored[0].name != "paper.pdf"  # random name on disk


def test_hostile_filename_cannot_escape_upload_folder(client, seed, tmp_path):
    r = client("teacher").post("/api/exam-papers", files=pdf_file("../../etc/passwd.pdf"), data={
        "course_id": seed.c1.id, "title": "t", "term": "Fall 2025", "exam_type": "final"})
    assert r.status_code == 201
    assert r.json()["original_filename"] == "passwd.pdf"
    assert not (tmp_path / "etc").exists()


def test_exam_search_and_filters(client, seed):
    t = client("teacher")
    upload_paper(t, seed)
    upload_paper(t, seed, title="CS101 Final", exam_type="final", term="Spring 2025", year=2025)
    upload_paper(t, seed, course_id=seed.c2.id, title="Design Quiz 1", exam_type="quiz", year=2024,
                 instructor_name="Ms. Sara")
    s = client("student")
    get = lambda **p: s.get("/api/exam-papers", params=p).json()  # noqa: E731
    assert len(get()) == 3
    assert len(get(course_id=seed.c1.id)) == 2
    assert [x["title"] for x in get(exam_type="final")] == ["CS101 Final"]
    assert len(get(term="fall 2025")) == 2                      # case-insensitive
    assert [x["title"] for x in get(year=2024)] == ["Design Quiz 1"]
    assert [x["title"] for x in get(q="sara")] == ["Design Quiz 1"]
    assert len(get(q="SE201")) == 1                              # matches course code
    assert len(get(limit=1)) == 1 and len(get(skip=2)) == 1


def test_exam_delete_rules_and_file_cleanup(client, seed, tmp_path):
    pid = upload_paper(client("teacher"), seed).json()["id"]
    assert client("student").delete(f"/api/exam-papers/{pid}").status_code == 403
    assert client("teacher2").delete(f"/api/exam-papers/{pid}").status_code == 403
    assert list((tmp_path / "uploads" / "exam_papers").iterdir())
    assert client("hod").delete(f"/api/exam-papers/{pid}").status_code == 204
    assert not list((tmp_path / "uploads" / "exam_papers").iterdir())
    assert client("student").get(f"/api/exam-papers/{pid}").status_code == 404


def test_uploader_can_delete_own_paper(client, seed):
    pid = upload_paper(client("teacher"), seed).json()["id"]
    assert client("teacher").delete(f"/api/exam-papers/{pid}").status_code == 204


def test_upload_size_limit(client, seed, monkeypatch):
    monkeypatch.setenv("MAX_UPLOAD_MB", "1")
    big = pdf_file(data=b"x" * (1024 * 1024 + 10))
    r = client("teacher").post("/api/exam-papers", files=big, data={
        "course_id": seed.c1.id, "title": "t", "term": "Fall 2025", "exam_type": "final"})
    assert r.status_code == 413
