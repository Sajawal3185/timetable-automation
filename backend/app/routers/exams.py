"""Exam repository (Wajiha) - proposal 4.1.

  POST   /api/exam-papers                    upload (teacher / HOD / admin), multipart form
  GET    /api/exam-papers                    search + filter (course_id, term, exam_type, year, q)
  GET    /api/exam-papers/{id}
  GET    /api/exam-papers/{id}/download      returns the file
  DELETE /api/exam-papers/{id}            uploader or admin/HOD
"""
import mimetypes
from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, Response, UploadFile
from fastapi.responses import FileResponse
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from database import get_db
from app.models.course import Course
from app.models.exam_paper import ExamPaper
from app.routers.portal_deps import ADMINS, STAFF, get_current_user, require_roles
from app.schemas.portal import ExamPaperOut, ExamType
from app.utils.file_storage import PAPER_EXTENSIONS, delete_file, resolve_path, save_upload

router = APIRouter(prefix="/api/exam-papers", tags=["Exam Repository"])


def _escape_like(val: str) -> str:
    """Escape SQL LIKE special characters (% and _)."""
    return val.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")


@router.post("", response_model=ExamPaperOut, status_code=201)
def upload_exam_paper(
    course_id: int = Form(...),
    title: str = Form(..., min_length=1, max_length=200),
    term: str = Form(..., min_length=1, max_length=40, description='e.g. "Fall 2025"'),
    exam_type: ExamType = Form(...),
    year: int | None = Form(None, ge=1990, le=2100),
    instructor_name: str | None = Form(None, max_length=100),
    file: UploadFile = File(...),
    db: Session = Depends(get_db),
    user=Depends(require_roles(*STAFF)),
):
    course = db.get(Course, course_id)
    if course is None:
        raise HTTPException(404, "Course not found")
        
    user_role = (getattr(user, "role", "") or "").lower()
    if user_role == "teacher" or not instructor_name:
        final_instructor_name = (getattr(user, "name", "") or "Unknown").strip()
    else:
        final_instructor_name = instructor_name.strip()

    saved = save_upload(file, "exam_papers", PAPER_EXTENSIONS)
    paper = ExamPaper(
        course_id=course_id, title=title.strip(), term=term.strip(), exam_type=exam_type.value,
        year=year, instructor_name=final_instructor_name, file_path=saved.relative_path,
        original_filename=saved.original_filename, content_type=saved.content_type,
        file_size=saved.size, uploaded_by=user.id,
    )
    try:
        db.add(paper)
        db.commit()
    except Exception:
        db.rollback()
        delete_file(saved.relative_path)  # do not leave an orphan file behind
        raise
    db.refresh(paper)
    return paper


@router.get("", response_model=list[ExamPaperOut], dependencies=[Depends(get_current_user)])
def search_exam_papers(
    response: Response,
    course_id: int | None = None,
    term: str | None = None,
    exam_type: ExamType | None = None,
    year: int | None = None,
    q: str | None = Query(None, description="searches title, instructor and course code/title"),
    skip: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=200),
    db: Session = Depends(get_db),
):
    base_stmt = select(ExamPaper).join(Course, Course.id == ExamPaper.course_id)
    
    if course_id:
        base_stmt = base_stmt.where(ExamPaper.course_id == course_id)
    if term:
        base_stmt = base_stmt.where(ExamPaper.term.ilike(term.strip()))
    if exam_type:
        base_stmt = base_stmt.where(ExamPaper.exam_type == exam_type.value)
    if year:
        base_stmt = base_stmt.where(ExamPaper.year == year)
    if q:
        escaped_q = _escape_like(q.strip())
        like_expr = f"%{escaped_q}%"
        base_stmt = base_stmt.where(
            ExamPaper.title.ilike(like_expr, escape="\\") | 
            ExamPaper.instructor_name.ilike(like_expr, escape="\\") |
            Course.code.ilike(like_expr, escape="\\") | 
            Course.title.ilike(like_expr, escape="\\")
        )

    # Calculate total count for pagination header
    count_stmt = select(func.count()).select_from(base_stmt.subquery())
    total_count = db.scalar(count_stmt) or 0
    response.headers["X-Total-Count"] = str(total_count)

    stmt = base_stmt.order_by(ExamPaper.year.desc(), ExamPaper.id.desc()).offset(skip).limit(limit)
    return db.execute(stmt).scalars().all()


def _get_or_404(db: Session, paper_id: int) -> ExamPaper:
    paper = db.get(ExamPaper, paper_id)
    if paper is None:
        raise HTTPException(404, "Exam paper not found")
    return paper


@router.get("/{paper_id}", response_model=ExamPaperOut, dependencies=[Depends(get_current_user)])
def get_exam_paper(paper_id: int, db: Session = Depends(get_db)):
    return _get_or_404(db, paper_id)


@router.get("/{paper_id}/download", dependencies=[Depends(get_current_user)])
def download_exam_paper(paper_id: int, db: Session = Depends(get_db)):
    paper = _get_or_404(db, paper_id)
    file_path = resolve_path(paper.file_path)
    media_type, _ = mimetypes.guess_type(paper.original_filename)
    return FileResponse(
        file_path, filename=paper.original_filename,
        media_type=media_type or "application/octet-stream",
    )


@router.delete("/{paper_id}", status_code=204)
def delete_exam_paper(paper_id: int, db: Session = Depends(get_db), user=Depends(get_current_user)):
    paper = _get_or_404(db, paper_id)
    if paper.uploaded_by != user.id and user.role not in ADMINS:
        raise HTTPException(403, "Only the uploader or an admin/HOD can delete this paper")
    path = paper.file_path
    db.delete(paper)
    db.commit()
    delete_file(path)