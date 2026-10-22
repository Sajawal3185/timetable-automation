"""Course software resource directory (Wajiha) - proposal 4.4.

  GET    /api/software-resources        list (filter: course_id, category, q)
  GET    /api/software-resources/{id}
  POST   /api/software-resources        teacher / HOD / admin
  PUT    /api/software-resources/{id}   teacher / HOD / admin
  DELETE /api/software-resources/{id}   teacher / HOD / admin
"""
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from database import get_db
from app.models.course import Course
from app.models.software_resource import SoftwareResource
from app.routers.portal_deps import STAFF, get_current_user, require_roles
from app.schemas.portal import SoftwareIn, SoftwareOut, SoftwareUpdate

router = APIRouter(prefix="/api/software-resources", tags=["Software Directory"])


@router.get("", response_model=list[SoftwareOut], dependencies=[Depends(get_current_user)])
def list_software(
    course_id: int | None = None,
    category: str | None = None,
    q: str | None = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(100, ge=1, le=300),
    db: Session = Depends(get_db),
):
    stmt = select(SoftwareResource).order_by(SoftwareResource.name)
    if course_id:
        stmt = stmt.where(SoftwareResource.course_id == course_id)
    if category:
        stmt = stmt.where(SoftwareResource.category.ilike(category))
    if q:
        like = f"%{q}%"
        stmt = stmt.where(SoftwareResource.name.ilike(like) | SoftwareResource.description.ilike(like))
    return db.execute(stmt.offset(skip).limit(limit)).scalars().all()


def _get_or_404(db: Session, item_id: int) -> SoftwareResource:
    item = db.get(SoftwareResource, item_id)
    if item is None:
        raise HTTPException(404, "Software resource not found")
    return item


def _check_course(db: Session, course_id: int | None) -> None:
    if course_id is not None and db.get(Course, course_id) is None:
        raise HTTPException(404, "Course not found")


@router.get("/{item_id}", response_model=SoftwareOut, dependencies=[Depends(get_current_user)])
def get_software(item_id: int, db: Session = Depends(get_db)):
    return _get_or_404(db, item_id)


@router.post("", response_model=SoftwareOut, status_code=201, dependencies=[Depends(require_roles(*STAFF))])
def create_software(body: SoftwareIn, db: Session = Depends(get_db)):
    _check_course(db, body.course_id)
    item = SoftwareResource(**body.model_dump())
    db.add(item)
    db.commit()
    db.refresh(item)
    return item


@router.put("/{item_id}", response_model=SoftwareOut, dependencies=[Depends(require_roles(*STAFF))])
def update_software(item_id: int, body: SoftwareUpdate, db: Session = Depends(get_db)):
    item = _get_or_404(db, item_id)
    data = body.model_dump(exclude_unset=True)
    _check_course(db, data.get("course_id"))
    for key, value in data.items():
        setattr(item, key, value)
    db.commit()
    db.refresh(item)
    return item


@router.delete("/{item_id}", status_code=204, dependencies=[Depends(require_roles(*STAFF))])
def delete_software(item_id: int, db: Session = Depends(get_db)):
    db.delete(_get_or_404(db, item_id))
    db.commit()
