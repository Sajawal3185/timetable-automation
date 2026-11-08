"""Unit tests for the one instructor_name rule shared by exam papers and lecture materials."""
from types import SimpleNamespace

from app.utils.instructor import resolve_instructor_name

UPLOADER = SimpleNamespace(name="Dr. Teacher")


def test_given_name_is_used_and_trimmed():
    assert resolve_instructor_name("Ms. Sara", UPLOADER) == "Ms. Sara"
    assert resolve_instructor_name("  Ms. Sara  ", UPLOADER) == "Ms. Sara"


def test_missing_or_blank_name_falls_back_to_uploader():
    for given in (None, "", "   "):
        assert resolve_instructor_name(given, UPLOADER) == "Dr. Teacher"


def test_uploader_without_a_name_is_unknown():
    assert resolve_instructor_name(None, SimpleNamespace(name="")) == "Unknown"
    assert resolve_instructor_name(None, SimpleNamespace(name="  ")) == "Unknown"
    assert resolve_instructor_name(None, SimpleNamespace()) == "Unknown"
