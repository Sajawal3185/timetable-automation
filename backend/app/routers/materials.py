"""Lecture material archive (Wajiha) - proposal 4.2.

Notes and slides from DIFFERENT instructors for the SAME course, side by side.

  POST   /api/lecture-materials                      upload (teacher / HOD / admin)
  GET    /api/lecture-materials                      flat list (course_id, instructor_name,
                                                     material_type, term, q)
  GET    /api/lecture-materials/by-course/{course_id} grouped: course -> instructor -> files
  GET    /api/lecture-materials/{id}
  GET    /api/lecture-materials/{id}/download
  DELETE /api/lecture-materials/{id}                  uploader or admin/HOD
"""
import mimetypes
from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, Response, UploadFile
from fastapi.responses import FileResponse
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from database import get_db
from app.models.course import Course
from app.models.lecture_material import LectureMaterial
from app.routers.portal_deps import ADMINS, STAFF, get_current_user, require_roles
from app.schemas.portal import LectureMaterialOut, MaterialsByCourse, MaterialsByInstructor, MaterialType
from app.utils.file_storage import MATERIAL_EXTENSIONS, delete_file, resolve_path, save_upload

router = APIRouter(prefix="/api/lecture-materials", tags=["Lecture Material Archive"])


def _escape_like(val: str) -> str:
    """Escape SQL LIKE special characters (% and _)."""
    return val.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")


@router.post("", response_model=LectureMaterialOut, status_code=201)
def upload_material(
    course_id: int = Form(...),
    title: str = Form(..., min_length=1, max_length=200),
    material_type: MaterialType = Form(...),
    instructor_name: str | None = Form(None, max_length=100, description="defaults to the uploader's name"),
    term: str | None = Form(None, max_length=40),
    description: str | None = Form(None, max_length=2000),
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

    saved = save_upload(file, "lecture_materials", MATERIAL_EXTENSIONS)
    material = LectureMaterial(
        course_id=course_id, title=title.strip(), material_type=material_type.value,
        instructor_name=final_instructor_name,
        term=term.strip() if term else None, description=description, file_path=saved.relative_path,
        original_filename=saved.original_filename, content_type=saved.content_type,
        file_size=saved.size, uploaded_by=user.id,
    )
    try:
        db.add(material)
        db.commit()
    except Exception:
        db.rollback()
        delete_file(saved.relative_path)
        raise
    db.refresh(material)
    return material


@router.get("", response_model=list[LectureMaterialOut], dependencies=[Depends(get_current_user)])
def list_materials(
    response: Response,
    course_id: int | None = None,
    instructor_name: str | None = None,
    material_type: MaterialType | None = None,
    term: str | None = None,
    q: str | None = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=200),
    db: Session = Depends(get_db),
):
    base_stmt = select(LectureMaterial)
    if course_id:
        base_stmt = base_stmt.where(LectureMaterial.course_id == course_id)
    if instructor_name:
        escaped_inst = _escape_like(instructor_name.strip())
        base_stmt = base_stmt.where(LectureMaterial.instructor_name.ilike(f"%{escaped_inst}%", escape="\\"))
    if material_type:
        base_stmt = base_stmt.where(LectureMaterial.material_type == material_type.value)
    if term:
        base_stmt = base_stmt.where(LectureMaterial.term.ilike(term.strip()))
    if q:
        escaped_q = _escape_like(q.strip())
        like_expr = f"%{escaped_q}%"
        base_stmt = base_stmt.where(
            LectureMaterial.title.ilike(like_expr, escape="\\") | 
            LectureMaterial.description.ilike(like_expr, escape="\\")
        )

    # Calculate total count for pagination header
    count_stmt = select(func.count()).select_from(base_stmt.subquery())
    total_count = db.scalar(count_stmt) or 0
    response.headers["X-Total-Count"] = str(total_count)

    stmt = base_stmt.order_by(LectureMaterial.id.desc()).offset(skip).limit(limit)
    return db.execute(stmt).scalars().all()


@router.get("/by-course/{course_id}", response_model=MaterialsByCourse, dependencies=[Depends(get_current_user)])
def materials_by_course(course_id: int, db: Session = Depends(get_db)):
    course = db.get(Course, course_id)
    if course is None:
        raise HTTPException(404, "Course not found")
        
    rows = db.execute(
        select(LectureMaterial).where(LectureMaterial.course_id == course_id)
        .order_by(LectureMaterial.instructor_name, LectureMaterial.id)
    ).scalars().all()
    
    # Group by normalized casefolded key to prevent fragmentation while preserving display names
    grouped: dict[str, list[LectureMaterial]] = {}
    display_names: dict[str, str] = {}
    for m in rows:
        key = m.instructor_name.strip().casefold()
        if key not in grouped:
            grouped[key] = []
            display_names[key] = m.instructor_name
        grouped[key].append(m)
        
    return MaterialsByCourse(
        course_id=course.id, course_code=course.code, course_title=course.title,
        instructors=[
            MaterialsByInstructor(
                instructor_name=display_names[key], 
                materials=[LectureMaterialOut.model_validate(m) for m in items]
            )
            for key, items in grouped.items()
        ],
    )


def _get_or_404(db: Session, material_id: int) -> LectureMaterial:
    material = db.get(LectureMaterial, material_id)
    if material is None:
        raise HTTPException(404, "Material not found")
    return material


@router.get("/{material_id}", response_model=LectureMaterialOut, dependencies=[Depends(get_current_user)])
def get_material(material_id: int, db: Session = Depends(get_db)):
    return _get_or_404(db, material_id)


@router.get("/{material_id}/download", dependencies=[Depends(get_current_user)])
def download_material(material_id: int, db: Session = Depends(get_db)):
    m = _get_or_404(db, material_id)
    file_path = resolve_path(m.file_path)
    media_type, _ = mimetypes.guess_type(m.original_filename)
    return FileResponse(
        file_path, filename=m.original_filename,
        media_type=media_type or "application/octet-stream",
    )


@router.delete("/{material_id}", status_code=204)
def delete_material(material_id: int, db: Session = Depends(get_db), user=Depends(get_current_user)):
    m = _get_or_404(db, material_id)
    if m.uploaded_by != user.id and user.role not in ADMINS:
        raise HTTPException(403, "Only the uploader or an admin/HOD can delete this material")
    path = m.file_path
    db.delete(m)
    db.commit()
    delete_file(path)