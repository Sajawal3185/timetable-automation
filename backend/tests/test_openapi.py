"""The /docs page must build for every route."""


def test_openapi_docs_generate_for_all_routes(app):
    """/docs (Swagger) is built from /openapi.json; it must not crash on any of our routes."""
    from fastapi.testclient import TestClient
    schema = TestClient(app).get("/openapi.json")
    assert schema.status_code == 200
    paths = schema.json()["paths"]
    for expected in ("/api/rooms/empty", "/api/timetable/import", "/api/exam-papers/{paper_id}/download",
                     "/api/lecture-materials/by-course/{course_id}", "/api/gpa/calculate",
                     "/api/software-resources", "/api/events"):
        assert expected in paths, expected
