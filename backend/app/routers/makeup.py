"""Makeup slot search and booking endpoints - proposal 2.3."""
from dataclasses import asdict
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.core.permissions import STAFF, require_roles, role_of
from app.database import get_db
from app.models.course import Course
from app.models.makeup_class import MakeupClass
from app.models.notification import NotificationType
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
from app.services.notification_service import notify_many, recipients_for_makeup
from app.services.makeup_solver import WEEKDAYS, _load_active_slots, find_makeup_slots
from app.services.scheduler_rules import ScheduleSlot, check_hard_clashes, check_room_suitability

router = APIRouter(prefix="/api/makeup", tags=["Makeup Classes"])

VALID_DAYS = (*WEEKDAYS, "Saturday", "Sunday")


def _check_books_for(user: User, teacher: Teacher) -> None:
    """403 unless `user` may book makeups for `teacher` (B-8).

    - a teacher only for themselves (their Teacher row is linked by user_id)
    - an HOD for themselves or any teacher in their own department
    - an admin for anyone
    """
    role = role_of(user)
    if role == "admin" or teacher.user_id == user.id:
        return
    dept = getattr(user, "department_id", None)
    if role == "hod" and dept is not None and dept == teacher.department_id:
        return
    raise HTTPException(
        status_code=status.HTTP_403_FORBIDDEN,
        detail="You can only book makeup classes for yourself",
    )


def _lock_booking_rows(db: Session, body: MakeupBookIn) -> None:
    """Lock the room, teacher and section rows until this booking commits (B-8).

    Two requests for the same room (or teacher, or section) now run one after
    the other: the second one waits here, then its clash check sees the first
    booking. Always lock in the same order (room, teacher, section) so two
    bookings can never wait on each other in a circle (deadlock).
    SQLite ignores FOR UPDATE; there the unique key on makeup_classes is the guard.
    """
    for model, row_id in ((Room, body.room_id), (Teacher, body.teacher_id), (Section, body.section_id)):
        db.execute(select(model.id).where(model.id == row_id).with_for_update())


@router.post("/find-slots", response_model=list[MakeupSlotSuggestionOut])
def search_makeup_slots(
    body: MakeupSearchIn,
    db: Session = Depends(get_db),
    user: User = Depends(require_roles(*STAFF)),
):
    days = body.days or list(WEEKDAYS)
    for d in days:
        if d not in VALID_DAYS:
            raise HTTPException(status_code=422, detail=f"Invalid day_of_week: {d!r}")
    teacher = db.get(Teacher, body.teacher_id)
    if teacher is not None:  # a missing teacher is reported by the solver below (422)
        _check_books_for(user, teacher)
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
    user: User = Depends(require_roles(*STAFF)),
):
    if body.end_time <= body.start_time:
        raise HTTPException(status_code=422, detail="end_time must be after start_time")
    if body.day_of_week not in VALID_DAYS:
        raise HTTPException(status_code=422, detail=f"Invalid day_of_week: {body.day_of_week!r}")

    section = db.get(Section, body.section_id)
    teacher = db.get(Teacher, body.teacher_id)
    course = db.get(Course, body.course_id)
    room = db.get(Room, body.room_id)
    if not all([section, teacher, course, room]):
        raise HTTPException(status_code=404, detail="Section, teacher, course, or room not found")

    _check_books_for(user, teacher)

    suitability = check_room_suitability(
        student_count=section.student_count,
        room_capacity=room.capacity,
        requires_lab=course.is_lab,
        room_type=room.room_type,
    )
    if suitability:
        raise HTTPException(status_code=409, detail=suitability[0].message)

    user_id = user.id
    # End the read-only transaction so far before locking. On MySQL (REPEATABLE
    # READ) a transaction keeps reading the snapshot from its first SELECT, so
    # without this the clash check below could miss a booking that committed
    # while we were waiting for the lock. Nothing has been written yet.
    db.rollback()
    _lock_booking_rows(db, body)

    existing = _load_active_slots(db, term=body.term)
    candidate = ScheduleSlot(
        day_of_week=body.day_of_week, start_time=body.start_time, end_time=body.end_time,
        section_id=body.section_id, course_id=body.course_id, teacher_id=body.teacher_id,
        room_id=body.room_id,
    )
    clashes = check_hard_clashes(candidate, existing)
    if clashes:
        db.rollback()  # release the locks
        raise HTTPException(status_code=409, detail=clashes[0].message)

    makeup = MakeupClass(
        course_id=body.course_id, section_id=body.section_id, teacher_id=body.teacher_id,
        room_id=body.room_id, class_date=body.class_date, day_of_week=body.day_of_week,
        start_time=body.start_time, end_time=body.end_time, reason=body.reason,
        status="scheduled", created_by=user_id,
    )
    db.add(makeup)
    # Tell the teacher (staff only, no students), in the SAME transaction as the
    # booking: if the booking is refused below, no notification is left behind.
    when = f"{body.day_of_week} {body.start_time:%H:%M}-{body.end_time:%H:%M}"
    if body.class_date:
        when = f"{body.class_date:%d %b %Y}, {when}"
    try:
        db.flush()  # gives makeup.id for the notification's related_id
        notify_many(
            db, recipients_for_makeup(db, body.teacher_id),
            NotificationType.makeup_added,
            f"Makeup class added: {course.code} on {when} in {room.name}.",
            related_id=makeup.id,
        )
        db.commit()
    except IntegrityError as exc:
        # uq_makeup_room_slot: someone booked this room at this time a moment ago.
        db.rollback()
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="This room was just booked for that time. Search again for a free slot.",
        ) from exc
    db.refresh(makeup)
    return makeup


@router.get("", response_model=list[MakeupClassOut], dependencies=[Depends(require_roles(*STAFF))])
def list_makeup_classes(section_id: int | None = None, teacher_id: int | None = None, db: Session = Depends(get_db)):
    stmt = select(MakeupClass).where(MakeupClass.status == "scheduled").order_by(MakeupClass.day_of_week, MakeupClass.start_time)
    if section_id:
        stmt = stmt.where(MakeupClass.section_id == section_id)
    if teacher_id:
        stmt = stmt.where(MakeupClass.teacher_id == teacher_id)
    return db.execute(stmt).scalars().all()
