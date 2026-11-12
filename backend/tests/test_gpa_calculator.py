"""Tests for the GPA / CGPA formulas (proposal 4.3: 'with tests for the formulas')."""
import pytest

from app.services.gpa_calculator import (
    CourseGrade, calculate_cgpa, calculate_gpa, grade_to_points, normalize_grade, round2,
)


def cg(credits, grade, code=""):
    return CourseGrade(credit_hours=credits, grade=grade, course_code=code)


# ---------------------------------------------------------------- grade lookup
def test_grade_points_basic():
    assert grade_to_points("A") == 4.0
    assert grade_to_points("B+") == 3.33
    assert grade_to_points("F") == 0.0


def test_grade_is_case_and_space_insensitive():
    assert normalize_grade(" b + ") == "B+"
    assert normalize_grade("a-") == "A-"


def test_excluded_grades_have_no_points():
    for g in ("W", "I", "P"):
        assert grade_to_points(g) is None


def test_unknown_grade_rejected():
    with pytest.raises(ValueError):
        grade_to_points("Z")


# ---------------------------------------------------------------- rounding
def test_round_half_up_not_bankers():
    assert round2(3.125) == 3.13
    assert round2(2.675) == 2.68
    assert round2(3.0) == 3.0


# ---------------------------------------------------------------- term GPA
def test_term_gpa_weighted_by_credit_hours():
    # (3*4.0 + 3*3.0 + 4*2.33) / 10 = 3.032
    result = calculate_gpa([cg(3, "A"), cg(3, "B"), cg(4, "C+")], "Fall 2025")
    assert result.gpa == 3.03
    assert result.credit_hours == 10
    assert result.term == "Fall 2025"


def test_single_course_gpa_equals_its_points():
    assert calculate_gpa([cg(3, "B-")]).gpa == 2.67


def test_fail_counts_as_zero_points_but_counts_credits():
    # (3*4.0 + 3*0) / 6 = 2.0
    assert calculate_gpa([cg(3, "A"), cg(3, "F")]).gpa == 2.0


def test_withdrawn_course_is_ignored():
    result = calculate_gpa([cg(3, "A"), cg(3, "W")])
    assert result.gpa == 4.0
    assert result.credit_hours == 3


def test_term_with_only_excluded_grades_has_no_gpa():
    result = calculate_gpa([cg(3, "W"), cg(3, "I")])
    assert result.gpa is None
    assert result.credit_hours == 0


def test_empty_term_has_no_gpa():
    assert calculate_gpa([]).gpa is None


@pytest.mark.parametrize("bad", [0, -3, None])
def test_credit_hours_must_be_positive(bad):
    with pytest.raises(ValueError):
        calculate_gpa([cg(bad, "A")])


# ---------------------------------------------------------------- CGPA
def test_cgpa_is_weighted_not_average_of_gpas():
    terms = [("T1", [cg(3, "A")]), ("T2", [cg(6, "C")])]
    result = calculate_cgpa(terms)
    # (12 + 12) / 9 = 2.67   (a plain average of 4.0 and 2.0 would wrongly give 3.0)
    assert result.cgpa == 2.67
    assert [t.gpa for t in result.terms] == [4.0, 2.0]
    assert result.total_credit_hours == 9


def test_cgpa_with_one_term_equals_term_gpa():
    result = calculate_cgpa([("T1", [cg(3, "A"), cg(3, "B")])])
    assert result.cgpa == result.terms[0].gpa == 3.5


def test_cgpa_on_top_of_previous_record():
    # previous: 3.0 over 60 credits.  New: 3 credits of A.  (180 + 12) / 63 = 3.0476...
    result = calculate_cgpa([("T1", [cg(3, "A")])], previous_cgpa=3.0, previous_credit_hours=60)
    assert result.cgpa == 3.05
    assert result.total_credit_hours == 63


def test_previous_values_must_come_together():
    with pytest.raises(ValueError):
        calculate_cgpa([("T1", [cg(3, "A")])], previous_cgpa=3.0)
    with pytest.raises(ValueError):
        calculate_cgpa([("T1", [cg(3, "A")])], previous_credit_hours=30)


def test_previous_cgpa_range_checked():
    with pytest.raises(ValueError):
        calculate_cgpa([("T1", [cg(3, "A")])], previous_cgpa=4.5, previous_credit_hours=30)


def test_no_counted_credits_means_no_cgpa():
    assert calculate_cgpa([("T1", [cg(3, "W")])]).cgpa is None


# ---------------------------------------------------------------- repeated courses
REPEAT = [("T1", [cg(3, "F", "CS101")]), ("T2", [cg(3, "B", "CS101")])]


def test_repeat_policy_all_counts_every_attempt():
    assert calculate_cgpa(REPEAT, repeat_policy="all").cgpa == 1.5  # (0 + 9) / 6


def test_repeat_policy_latest_counts_newest_attempt():
    assert calculate_cgpa(REPEAT, repeat_policy="latest").cgpa == 3.0


def test_repeat_policy_best_counts_highest_grade():
    reverse = [("T1", [cg(3, "B", "CS101")]), ("T2", [cg(3, "D", "CS101")])]
    assert calculate_cgpa(reverse, repeat_policy="best").cgpa == 3.0
    assert calculate_cgpa(reverse, repeat_policy="latest").cgpa == 1.0


def test_later_withdrawal_does_not_erase_a_real_grade():
    terms = [("T1", [cg(3, "B", "CS101")]), ("T2", [cg(3, "W", "CS101")])]
    assert calculate_cgpa(terms, repeat_policy="latest").cgpa == 3.0


def test_repeat_detection_ignores_course_code_case():
    terms = [("T1", [cg(3, "F", "cs101")]), ("T2", [cg(3, "A", "CS101")])]
    assert calculate_cgpa(terms, repeat_policy="latest").cgpa == 4.0


def test_courses_without_code_are_never_merged():
    terms = [("T1", [cg(3, "A")]), ("T2", [cg(3, "C")])]
    assert calculate_cgpa(terms, repeat_policy="latest").cgpa == 3.0
