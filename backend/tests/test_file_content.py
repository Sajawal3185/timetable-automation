"""Uploads are accepted by what the file contains, not only by its extension (review item D7)."""
from app.utils.file_storage import MATERIAL_EXTENSIONS, PAPER_EXTENSIONS, content_matches_extension
from tests.helpers import PDF, PNG, PPTX, any_file

JPEG = b"\xff\xd8\xff\xe0" + b"\x00" * 8
OLE = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" + b"\x00" * 8
EXE = b"MZ\x90\x00" + b"\x00" * 16
EXAM = {"course_id": 1, "title": "t", "term": "Fall 2025", "exam_type": "final"}
MATERIAL = {"course_id": 1, "title": "t", "material_type": "slides"}


# ------------------------------------------------------------- the check itself
def test_real_files_match_their_extension():
    good = {".pdf": PDF, ".png": PNG, ".jpg": JPEG, ".jpeg": JPEG, ".docx": PPTX, ".pptx": PPTX,
            ".zip": PPTX, ".doc": OLE, ".ppt": OLE, ".txt": b"just some notes\n"}
    for ext, data in good.items():
        assert content_matches_extension(ext, data), ext


def test_wrong_content_is_refused():
    bad = [(".pdf", PNG), (".pdf", EXE), (".pdf", b"not a pdf at all"), (".png", PDF), (".jpg", PNG),
           (".docx", EXE), (".pptx", PDF), (".doc", PDF), (".zip", EXE), (".txt", EXE + b"\x00")]
    for ext, data in bad:
        assert not content_matches_extension(ext, data), (ext, data[:8])


def test_text_and_rtf_special_cases():
    assert content_matches_extension(".txt", "héllo".encode("utf-8"))
    assert content_matches_extension(".txt", b"\xff\xfe" + "hi".encode("utf-16-le"))  # UTF-16 has NULs but a BOM
    assert content_matches_extension(".doc", b"{\\rtf1\\ansi hello}")                # RTF saved as .doc


def test_every_allowed_extension_has_a_rule():
    """Adding an extension to the allowed sets without a signature rule would refuse every such upload."""
    from app.utils.file_storage import SIGNATURES
    for ext in (PAPER_EXTENSIONS | MATERIAL_EXTENSIONS) - {".txt"}:
        assert ext in SIGNATURES, f"{ext} is allowed but has no signature rule"
    assert not content_matches_extension(".exe", EXE)  # unknown extension: refused


# ------------------------------------------------------------- through the API
def test_exam_upload_refuses_disguised_file_and_leaves_nothing_behind(client, seed, tmp_path):
    exam = {**EXAM, "course_id": seed.c1.id}
    for name, data in (("fake.pdf", EXE), ("fake.pdf", PNG), ("fake.png", PDF), ("fake.docx", b"plain text")):
        r = client("teacher").post("/api/exam-papers", files=any_file(name, data), data=exam)
        assert r.status_code == 400, (name, r.text)
        assert "content" in r.json()["detail"]
    folder = tmp_path / "uploads" / "exam_papers"
    assert not folder.exists() or not list(folder.iterdir())  # refused files are not kept on disk
    assert client("student").get("/api/exam-papers").json() == []


def test_exam_upload_accepts_real_png(client, seed):
    r = client("teacher").post("/api/exam-papers", files=any_file("scan.png", PNG), data={**EXAM, "course_id": seed.c1.id})
    assert r.status_code == 201, r.text


def test_material_upload_checks_content_for_every_type(client, seed):
    material = {**MATERIAL, "course_id": seed.c1.id}
    post = lambda name, data: client("teacher").post(  # noqa: E731
        "/api/lecture-materials", files=any_file(name, data), data=material).status_code
    assert post("slides.pptx", PPTX) == 201
    assert post("notes.txt", b"week 1 notes") == 201
    assert post("slides.pptx", PDF) == 400          # a PDF renamed .pptx
    assert post("bundle.zip", EXE) == 400           # an .exe renamed .zip
    assert post("notes.txt", EXE + b"\x00\x00") == 400


def test_downloads_are_attachments(client, seed):
    pid = client("teacher").post("/api/exam-papers", files=any_file("paper.pdf", PDF),
                                 data={**EXAM, "course_id": seed.c1.id}).json()["id"]
    mid = client("teacher").post("/api/lecture-materials", files=any_file("week1.pdf", PDF),
                                 data={**MATERIAL, "course_id": seed.c1.id}).json()["id"]
    for url in (f"/api/exam-papers/{pid}/download", f"/api/lecture-materials/{mid}/download"):
        r = client("student").get(url)
        assert r.status_code == 200
        assert r.headers["content-disposition"].startswith("attachment"), url
