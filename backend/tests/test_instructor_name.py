"""Unit tests for the one instructor_name rule shared by exam papers and lecture materials."""
from types import SimpleNamespace

from app.utils.instructor import resolve_instructor_name

TEACHER = SimpleNamespace(name="Dr. Teacher", role="teacher")
HOD = SimpleNamespace(name="HOD", role="hod")
ADMIN = SimpleNamespace(name="Admin", role="Admin")  # role check ignores case


def test_hod_and_admin_given_name_is_used_and_trimmed():
    assert resolve_instructor_name("Ms. Sara", HOD) == "Ms. Sara"
    assert resolve_instructor_name("  Ms. Sara  ", ADMIN) == "Ms. Sara"


def test_teacher_cannot_name_another_instructor():
    assert resolve_instructor_name("Ms. Sara", TEACHER) == "Dr. Teacher"


def test_missing_or_blank_name_falls_back_to_uploader():
    for given in (None, "", "   "):
        assert resolve_instructor_name(given, TEACHER) == "Dr. Teacher"
        assert resolve_instructor_name(given, HOD) == "HOD"


def test_uploader_without_a_name_is_unknown():
    assert resolve_instructor_name(None, SimpleNamespace(name="", role="hod")) == "Unknown"
    assert resolve_instructor_name(None, SimpleNamespace(name="  ")) == "Unknown"
    assert resolve_instructor_name("Ms. Sara", SimpleNamespace(role="teacher")) == "Unknown"
