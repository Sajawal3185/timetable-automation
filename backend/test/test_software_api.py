"""API tests: software resource directory."""


SW = {"name": "Visual Studio Code", "category": "IDE", "download_url": "https://code.visualstudio.com",
      "instructions": "Install with defaults."}


def test_software_crud_and_permissions(client, seed):
    assert client("student").post("/api/software-resources", json=SW).status_code == 403
    r = client("teacher").post("/api/software-resources", json={**SW, "course_id": seed.c1.id})
    assert r.status_code == 201
    sid = r.json()["id"]
    assert client("student").get(f"/api/software-resources/{sid}").json()["name"] == "Visual Studio Code"
    upd = client("teacher").put(f"/api/software-resources/{sid}", json={"category": "Editor"})
    assert upd.json()["category"] == "Editor" and upd.json()["name"] == "Visual Studio Code"
    assert client("student").delete(f"/api/software-resources/{sid}").status_code == 403
    assert client("hod").delete(f"/api/software-resources/{sid}").status_code == 204
    assert client("student").get(f"/api/software-resources/{sid}").status_code == 404


def test_software_validation_and_filters(client, seed):
    t = client("teacher")
    assert t.post("/api/software-resources", json={**SW, "download_url": "javascript:alert(1)"}).status_code == 422
    assert t.post("/api/software-resources", json={**SW, "download_url": "not a url"}).status_code == 422
    assert t.post("/api/software-resources", json={**SW, "course_id": 999}).status_code == 404
    t.post("/api/software-resources", json={**SW, "course_id": seed.c1.id})
    t.post("/api/software-resources", json={"name": "MySQL Workbench", "category": "Database",
                                            "download_url": "https://dev.mysql.com/downloads/workbench/"})
    s = client("student")
    assert len(s.get("/api/software-resources").json()) == 2
    assert [x["name"] for x in s.get("/api/software-resources", params={"category": "database"}).json()] == ["MySQL Workbench"]
    assert [x["name"] for x in s.get("/api/software-resources", params={"q": "visual"}).json()] == ["Visual Studio Code"]
    assert len(s.get("/api/software-resources", params={"course_id": seed.c1.id}).json()) == 1
