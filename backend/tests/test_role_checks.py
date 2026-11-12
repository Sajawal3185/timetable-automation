"""There is ONE role-check implementation: app/core/permissions.py (review item M6)."""
import re
from pathlib import Path

from app.core.permissions import ADMINS, STAFF

APP = Path(__file__).resolve().parents[1] / "app"
DENIED = "You do not have permission for this action"


def test_role_groups():
    assert STAFF == ("teacher", "hod", "admin")
    assert ADMINS == ("hod", "admin")


def test_denials_use_the_shared_message(client, seed):
    assert client("student").post("/api/rooms", json={"name": "X-1", "capacity": 10}).json()["detail"] == DENIED
    assert client("teacher").post("/api/events", json={
        "title": "t", "event_type": "event", "start_date": "2026-10-20"}).json()["detail"] == DENIED
    assert client("student").post("/api/software-resources", json={
        "name": "VS Code", "download_url": "https://code.visualstudio.com"}).json()["detail"] == DENIED


def test_no_second_role_check_implementation():
    """Routers must import require_roles from app.core.permissions, not define their own."""
    assert not (APP / "routers" / "portal_deps.py").exists()
    for path in (APP / "routers").glob("*.py"):
        source = path.read_text(encoding="utf-8")
        assert not re.search(r"def\s+(require_roles?|_require_staff)\b", source), path.name
        assert "portal_deps" not in source, path.name
