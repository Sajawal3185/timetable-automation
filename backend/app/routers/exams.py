"""Exam repository (Wajiha) - proposal 4.1.

  POST   /api/exam-papers                 upload (teacher / HOD / admin), multipart form
  GET    /api/exam-papers                 search + filter (course_id, term, exam_type, year, q)
  GET    /api/exam-papers/{id}
  GET    /api/exam-papers/{id}/download   returns the file
  DELETE /api/exam-papers/{id}            uploader or admin/HOD
"""
from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, UploadFile
from fastapi.responses import FileResponse
from sqlalchemy import select
from sqlalchemy.orm import Session

from database import get_db
from app.models.course import Course
from app.models.exam_paper import ExamPaper
from app.routers.portal_deps import ADMINS, STAFF, get_current_user, require_roles
from app.schemas.portal import ExamPaperOut, ExamType
from app.utils.file_storage import PAPER_EXTENSIONS, delete_file, resolve_path, save_upload

router = APIRouter(prefix="/api/exam-papers", tags=["Exam Repository"])


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
    if db.get(Course, course_id) is None:
        raise HTTPException(404, "Course not found")
    saved = save_upload(file, "exam_papers", PAPER_EXTENSIONS)
    paper = ExamPaper(
        course_id=course_id, title=title.strip(), term=term.strip(), exam_type=exam_type.value,
        year=year, instructor_name=instructor_name, file_path=saved.relative_path,
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
    course_id: int | None = None,
    term: str | None = None,
    exam_type: ExamType | None = None,
    year: int | None = None,
    q: str | None = Query(None, description="searches title, instructor and course code/title"),
    skip: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=200),
    db: Session = Depends(get_db),
):
    stmt = select(ExamPaper).join(Course, Course.id == ExamPaper.course_id)
    if course_id:
        stmt = stmt.where(ExamPaper.course_id == course_id)
    if term:
        stmt = stmt.where(ExamPaper.term.ilike(term))
    if exam_type:
        stmt = stmt.where(ExamPaper.exam_type == exam_type.value)
    if year:
        stmt = stmt.where(ExamPaper.year == year)
    if q:
        like = f"%{q}%"
        stmt = stmt.where(
            ExamPaper.title.ilike(like) | ExamPaper.instructor_name.ilike(like)
            | Course.code.ilike(like) | Course.title.ilike(like)
        )
    stmt = stmt.order_by(ExamPaper.year.desc(), ExamPaper.id.desc()).offset(skip).limit(limit)
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
    return FileResponse(
        resolve_path(paper.file_path), filename=paper.original_filename,
        media_type=paper.content_type or "application/octet-stream",
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
