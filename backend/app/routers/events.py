"""Institutional event calendar (Wajiha) - proposal 4.6.

  GET    /api/events          list (filter: month=YYYY-MM, from_date, to_date, event_type,
                              department_id).  An event overlapping the range is included.
  GET    /api/events/{id}
  POST   /api/events          admin / HOD
  PUT    /api/events/{id}     admin / HOD
  DELETE /api/events/{id}     admin / HOD
"""
import calendar
from datetime import date

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import func, or_, select
from sqlalchemy.orm import Session

from database import get_db
from app.models.department import Department
from app.models.event import Event
from app.routers.portal_deps import ADMINS, get_current_user, require_roles
from app.schemas.portal import EventIn, EventOut, EventType, EventUpdate

router = APIRouter(prefix="/api/events", tags=["Event Calendar"])


def _month_range(month: str) -> tuple[date, date]:
    try:
        year, mon = (int(x) for x in month.split("-"))
        return date(year, mon, 1), date(year, mon, calendar.monthrange(year, mon)[1])
    except (ValueError, TypeError):
        raise HTTPException(422, "month must look like 2026-10") from None


@router.get("", response_model=list[EventOut], dependencies=[Depends(get_current_user)])
def list_events(
    month: str | None = Query(None, description="YYYY-MM"),
    from_date: date | None = None,
    to_date: date | None = None,
    event_type: EventType | None = None,
    department_id: int | None = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(200, ge=1, le=500),
    db: Session = Depends(get_db),
):
    if month:
        from_date, to_date = _month_range(month)
    if from_date and to_date and to_date < from_date:
        raise HTTPException(422, "to_date cannot be before from_date")
    stmt = select(Event).order_by(Event.start_date, Event.id)
    if to_date:
        stmt = stmt.where(Event.start_date <= to_date)
    if from_date:
        stmt = stmt.where(func.coalesce(Event.end_date, Event.start_date) >= from_date)
    if event_type:
        stmt = stmt.where(Event.event_type == event_type.value)
    if department_id:
        # department events for that department, plus university-wide ones (no department)
        stmt = stmt.where(or_(Event.department_id == department_id, Event.department_id.is_(None)))
    return db.execute(stmt.offset(skip).limit(limit)).scalars().all()


def _get_or_404(db: Session, event_id: int) -> Event:
    event = db.get(Event, event_id)
    if event is None:
        raise HTTPException(404, "Event not found")
    return event


def _check_department(db: Session, department_id: int | None) -> None:
    if department_id is not None and db.get(Department, department_id) is None:
        raise HTTPException(404, "Department not found")


@router.get("/{event_id}", response_model=EventOut, dependencies=[Depends(get_current_user)])
def get_event(event_id: int, db: Session = Depends(get_db)):
    return _get_or_404(db, event_id)


@router.post("", response_model=EventOut, status_code=201)
def create_event(body: EventIn, db: Session = Depends(get_db), user=Depends(require_roles(*ADMINS))):
    _check_department(db, body.department_id)
    data = body.model_dump()
    data["event_type"] = body.event_type.value
    event = Event(**data, created_by=user.id)
    db.add(event)
    db.commit()
    db.refresh(event)
    return event


@router.put("/{event_id}", response_model=EventOut, dependencies=[Depends(require_roles(*ADMINS))])
def update_event(event_id: int, body: EventUpdate, db: Session = Depends(get_db)):
    event = _get_or_404(db, event_id)
    data = body.model_dump(exclude_unset=True)
    if "event_type" in data and data["event_type"] is not None:
        data["event_type"] = data["event_type"].value
    _check_department(db, data.get("department_id"))
    for key, value in data.items():
        setattr(event, key, value)
    if event.end_date and event.end_date < event.start_date:
        db.rollback()
        raise HTTPException(422, "end_date cannot be before start_date")
    db.commit()
    db.refresh(event)
    return event


@router.delete("/{event_id}", status_code=204, dependencies=[Depends(require_roles(*ADMINS))])
def delete_event(event_id: int, db: Session = Depends(get_db)):
    db.delete(_get_or_404(db, event_id))
    db.commit()
