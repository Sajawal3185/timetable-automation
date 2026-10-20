"""Makeup slot search and booking endpoints (Sajawal) - proposal 2.3."""
from dataclasses import asdict
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.database import get_db
from app.dependencies import get_current_user
from app.models.course import Course
from app.models.makeup_class import MakeupClass
from app.models.room import Room
from app.models.section import Section
from app.models.teacher import Teacher
from app.models.user import User
from app.schemas.makeup import (
    MakeupBookIn,
    MakeupClassOut,
    MakeupSearchIn,
    MakeupSlotSuggestionOut,
)
from app.services.makeup_solver import WEEKDAYS, _load_active_slots, find_makeup_slots
from app.services.scheduler_rules import ScheduleSlot, check_hard_clashes, check_room_suitability

router = APIRouter(prefix="/api/makeup", tags=["Makeup Classes"])

STAFF_ROLES = {"teacher", "hod", "admin"}


def _require_staff(user: User = Depends(get_current_user)) -> User:
    if (user.role or "").lower() not in STAFF_ROLES:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Staff role required")
    return user


@router.post("/find-slots", response_model=list[MakeupSlotSuggestionOut])
def search_makeup_slots(
    body: MakeupSearchIn,
    db: Session = Depends(get_db),
    _user: User = Depends(_require_staff),
):
    days = body.days or list(WEEKDAYS)
    for d in days:
        if d not in WEEKDAYS and d not in ("Saturday", "Sunday"):
            raise HTTPException(status_code=422, detail=f"Invalid day_of_week: {d!r}")
    try:
        results = find_makeup_slots(
            db,
            section_id=body.section_id,
            teacher_id=body.teacher_id,
            course_id=body.course_id,
            duration_minutes=body.duration_minutes,
            preferred_room_id=body.preferred_room_id,
            days=days,
            term=body.term,
        )
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    return [MakeupSlotSuggestionOut(**asdict(r)) for r in results]


@router.post("", response_model=MakeupClassOut, status_code=status.HTTP_201_CREATED)
def book_makeup_class(
    body: MakeupBookIn,
    db: Session = Depends(get_db),
    user: User = Depends(_require_staff),
):
    if body.end_time <= body.start_time:
        raise HTTPException(status_code=422, detail="end_time must be after start_time")

    section = db.get(Section, body.section_id)
    teacher = db.get(Teacher, body.teacher_id)
    course = db.get(Course, body.course_id)
    room = db.get(Room, body.room_id)
    if not all([section, teacher, course, room]):
        raise HTTPException(status_code=404, detail="Section, teacher, course, or room not found")

    suitability = check_room_suitability(
        student_count=section.student_count,
        room_capacity=room.capacity,
        requires_lab=course.is_lab,
        room_type=room.room_type,
    )
    if suitability:
        raise HTTPException(status_code=409, detail=suitability[0].message)

    existing = _load_active_slots(db, term=body.term)
    candidate = ScheduleSlot(
        day_of_week=body.day_of_week, start_time=body.start_time, end_time=body.end_time,
        section_id=body.section_id, course_id=body.course_id, teacher_id=body.teacher_id,
        room_id=body.room_id,
    )
    clashes = check_hard_clashes(candidate, existing)
    if clashes:
        raise HTTPException(status_code=409, detail=clashes[0].message)

    makeup = MakeupClass(
        course_id=body.course_id, section_id=body.section_id, teacher_id=body.teacher_id,
        room_id=body.room_id, class_date=body.class_date, day_of_week=body.day_of_week,
        start_time=body.start_time, end_time=body.end_time, reason=body.reason,
        status="scheduled", created_by=user.id,
    )
    db.add(makeup)
    db.commit()
    db.refresh(makeup)
    return makeup


@router.get("", response_model=list[MakeupClassOut], dependencies=[Depends(get_current_user)])
def list_makeup_classes(section_id: int | None = None, teacher_id: int | None = None, db: Session = Depends(get_db)):
    stmt = select(MakeupClass).where(MakeupClass.status == "scheduled").order_by(MakeupClass.day_of_week, MakeupClass.start_time)
    if section_id:
        stmt = stmt.where(MakeupClass.section_id == section_id)
    if teacher_id:
        stmt = stmt.where(MakeupClass.teacher_id == teacher_id)
    return db.execute(stmt).scalars().all()