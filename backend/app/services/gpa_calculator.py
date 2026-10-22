"""GPA / CGPA calculation (Wajiha) - proposal 4.3.

Pure functions: no database, no HTTP. That makes them easy to test.

IMPORTANT: GRADE_POINTS below is a common 4.0 letter scale. Compare it with the
official NUML grading policy and edit ONLY this dictionary if the university
uses different values. Nothing else in the code needs to change.
"""
from dataclasses import dataclass, field
from decimal import ROUND_HALF_UP, Decimal
from typing import Iterable, Literal, Sequence

GRADE_POINTS: dict[str, float] = {
    "A": 4.00, "A-": 3.67,
    "B+": 3.33, "B": 3.00, "B-": 2.67,
    "C+": 2.33, "C": 2.00, "C-": 1.67,
    "D+": 1.33, "D": 1.00,
    "F": 0.00,
}
# Grades that carry no points and are left out of GPA (withdrawn, incomplete, pass).
EXCLUDED_GRADES = {"W", "I", "P", "NC", "AUD"}

RepeatPolicy = Literal["all", "latest", "best"]


@dataclass(frozen=True)
class CourseGrade:
    credit_hours: float
    grade: str
    course_code: str = ""
    course_title: str = ""


@dataclass
class TermResult:
    term: str
    gpa: float | None          # None when no course counts (e.g. everything withdrawn)
    credit_hours: float        # credit hours that counted
    quality_points: float


@dataclass
class CgpaResult:
    cgpa: float | None
    total_credit_hours: float
    total_quality_points: float
    terms: list[TermResult] = field(default_factory=list)


def normalize_grade(grade: str) -> str:
    g = (grade or "").strip().upper().replace(" ", "")
    if g in GRADE_POINTS or g in EXCLUDED_GRADES:
        return g
    allowed = sorted(GRADE_POINTS) + sorted(EXCLUDED_GRADES)
    raise ValueError(f"Unknown grade: {grade!r}. Allowed: {allowed}")


def grade_to_points(grade: str) -> float | None:
    """Grade points for a letter grade, or None if the grade is excluded."""
    g = normalize_grade(grade)
    return None if g in EXCLUDED_GRADES else GRADE_POINTS[g]


def round2(value: float) -> float:
    """Round half up to 2 decimals (avoids Python's banker's rounding surprises)."""
    return float(Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP))


def _validate_credit(credit_hours: float) -> None:
    if credit_hours is None or credit_hours <= 0:
        raise ValueError(f"credit_hours must be greater than 0, got {credit_hours!r}")


def _sum_points(courses: Iterable[CourseGrade]) -> tuple[float, float]:
    credits = quality = 0.0
    for c in courses:
        _validate_credit(c.credit_hours)
        pts = grade_to_points(c.grade)
        if pts is None:
            continue
        credits += c.credit_hours
        quality += c.credit_hours * pts
    return credits, quality


def calculate_gpa(courses: Sequence[CourseGrade], term: str = "") -> TermResult:
    """GPA = sum(credit_hours * grade_points) / sum(credit_hours)."""
    credits, quality = _sum_points(courses)
    gpa = round2(quality / credits) if credits > 0 else None
    return TermResult(term=term, gpa=gpa, credit_hours=credits, quality_points=round2(quality))


def _apply_repeat_policy(terms: list[tuple[str, list[CourseGrade]]], policy: RepeatPolicy) -> list[CourseGrade]:
    flat = [c for _, courses in terms for c in courses]
    if policy == "all":
        return flat
    chosen: dict[str, CourseGrade] = {}
    result: list[CourseGrade] = []
    for c in flat:
        key = c.course_code.strip().upper()
        if not key:  # no code: a repeat cannot be detected, keep the course
            result.append(c)
            continue
        old = chosen.get(key)
        if old is None:
            chosen[key] = c
        elif policy == "latest":
            if grade_to_points(c.grade) is not None:  # a later W must not wipe a real grade
                chosen[key] = c
        elif policy == "best":
            new_p, old_p = grade_to_points(c.grade), grade_to_points(old.grade)
            if new_p is not None and (old_p is None or new_p > old_p):
                chosen[key] = c
    return result + list(chosen.values())


def calculate_cgpa(
    terms: Sequence[tuple[str, Sequence[CourseGrade]]],
    previous_cgpa: float | None = None,
    previous_credit_hours: float | None = None,
    repeat_policy: RepeatPolicy = "all",
) -> CgpaResult:
    """CGPA over all terms, optionally on top of an earlier CGPA ("what if" planning).

    repeat_policy: "all" counts every attempt; "latest" counts only the newest
    attempt of a repeated course; "best" counts the highest grade.
    """
    if (previous_cgpa is None) != (previous_credit_hours is None):
        raise ValueError("previous_cgpa and previous_credit_hours must be given together")
    if previous_cgpa is not None:
        if not 0 <= previous_cgpa <= 4:
            raise ValueError("previous_cgpa must be between 0 and 4")
        if previous_credit_hours < 0:
            raise ValueError("previous_credit_hours cannot be negative")

    term_list = [(label, list(courses)) for label, courses in terms]
    term_results = [calculate_gpa(courses, label) for label, courses in term_list]

    credits, quality = _sum_points(_apply_repeat_policy(term_list, repeat_policy))
    if previous_cgpa is not None:
        credits += previous_credit_hours
        quality += previous_cgpa * previous_credit_hours
    cgpa = round2(quality / credits) if credits > 0 else None
    return CgpaResult(
        cgpa=cgpa,
        total_credit_hours=credits,
        total_quality_points=round2(quality),
        terms=term_results,
    )
