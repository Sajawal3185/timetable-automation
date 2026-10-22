"""GPA / CGPA endpoints (Wajiha) - proposal 4.3.

  GET    /api/gpa/grade-scale    letter grade -> points table the server uses
  POST   /api/gpa/calculate      stateless calculator (nothing is saved)
  GET    /api/gpa/records        the logged-in student's saved grades
  POST   /api/gpa/records        save one grade
  DELETE /api/gpa/records/{id}
  GET    /api/gpa/summary        term GPAs + CGPA computed from the saved grades
"""
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from database import get_db
from app.models.grade_record import GradeRecord
from app.routers.portal_deps import get_current_user
from app.schemas.portal import GpaRequest, GpaResponse, GradeRecordIn, GradeRecordOut, TermResultOut
from app.services import gpa_calculator as calc

router = APIRouter(prefix="/api/gpa", tags=["GPA / CGPA"], dependencies=[Depends(get_current_user)])

SEASON_RANKS = {"winter": 0, "spring": 1, "summer": 2, "fall": 3}


def _normalize_term(term_str: str) -> str:
    """Canonicalize term string format (e.g., 'fall 2025' -> 'Fall 2025')."""
    parts = term_str.strip().split()
    if len(parts) == 2:
        return f"{parts[0].capitalize()} {parts[1]}"
    return term_str.strip()


def _term_sort_key(term_str: str) -> tuple[int, int]:
    """Sort key for chronological term ordering: (year, season_order)."""
    parts = term_str.strip().lower().split()
    if len(parts) == 2:
        season, year_str = parts[0], parts[1]
        try:
            year = int(year_str)
        except ValueError:
            year = 0
        rank = SEASON_RANKS.get(season, 99)
        return (year, rank)
    return (0, 0)


def _to_response(result: calc.CgpaResult) -> GpaResponse:
    return GpaResponse(
        cgpa=result.cgpa,
        total_credit_hours=result.total_credit_hours,
        total_quality_points=result.total_quality_points,
        terms=[TermResultOut(**vars(t)) for t in result.terms],
    )


@router.get("/grade-scale")
def grade_scale():
    return {"grade_points": calc.GRADE_POINTS, "excluded_grades": sorted(calc.EXCLUDED_GRADES)}


@router.post("/calculate", response_model=GpaResponse)
def calculate(body: GpaRequest):
    terms = [
        (t.term, [calc.CourseGrade(c.credit_hours, c.grade, c.course_code, c.course_title) for c in t.courses])
        for t in body.terms
    ]
    try:
        result = calc.calculate_cgpa(terms, body.previous_cgpa, body.previous_credit_hours, body.repeat_policy)
    except ValueError as exc:
        raise HTTPException(422, str(exc)) from exc
    return _to_response(result)


@router.get("/records", response_model=list[GradeRecordOut])
def my_records(db: Session = Depends(get_db), user=Depends(get_current_user)):
    stmt = select(GradeRecord).where(GradeRecord.user_id == user.id).order_by(GradeRecord.id)
    return db.execute(stmt).scalars().all()


@router.post("/records", response_model=GradeRecordOut, status_code=201)
def add_record(body: GradeRecordIn, db: Session = Depends(get_db), user=Depends(get_current_user)):
    try:
        grade = calc.normalize_grade(body.grade)
    except ValueError as exc:
        raise HTTPException(422, str(exc)) from exc
        
    normalized_term = _normalize_term(body.term)
    
    record = GradeRecord(
        user_id=user.id, term=normalized_term, course_code=body.course_code.strip().upper(),
        course_title=body.course_title, credit_hours=body.credit_hours, grade=grade,
    )
    db.add(record)
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(409, "This course is already saved for that term") from None
    db.refresh(record)
    return record


@router.delete("/records/{record_id}", status_code=204)
def delete_record(record_id: int, db: Session = Depends(get_db), user=Depends(get_current_user)):
    record = db.get(GradeRecord, record_id)
    if record is None or record.user_id != user.id:  # same answer for "not yours": do not leak ids
        raise HTTPException(404, "Record not found")
    db.delete(record)
    db.commit()


@router.get("/summary", response_model=GpaResponse)
def my_summary(
    repeat_policy: str = Query("all", pattern="^(all|latest|best)$"),
    db: Session = Depends(get_db),
    user=Depends(get_current_user),
):
    rows = db.execute(
        select(GradeRecord).where(GradeRecord.user_id == user.id).order_by(GradeRecord.id)
    ).scalars().all()
    
    terms_dict: dict[str, list[calc.CourseGrade]] = {}
    for r in rows:
        terms_dict.setdefault(r.term, []).append(
            calc.CourseGrade(r.credit_hours, r.grade, r.course_code, r.course_title or "")
        )
        
    # Sort terms chronologically by (year, season_order) before computing CGPA
    sorted_terms = sorted(terms_dict.items(), key=lambda item: _term_sort_key(item[0]))
    
    result = calc.calculate_cgpa(sorted_terms, repeat_policy=repeat_policy)  # type: ignore[arg-type]
    return _to_response(result)