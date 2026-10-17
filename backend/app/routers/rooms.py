"""Rooms and reference data (Wajiha).

Needed by Amna's empty-room dashboard and by Sajawal's makeup solver
(room capacities).  Endpoints:

  GET    /api/rooms                 list (filter: min_capacity, building, search)
  GET    /api/rooms/empty           rooms free on a day between two times  (dashboard)
  GET    /api/rooms/{id}
  POST   /api/rooms                 admin/HOD
  PUT    /api/rooms/{id}            admin/HOD
  GET    /api/departments
  GET    /api/courses               filter: department_id, search
"""
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import exists, select
from sqlalchemy.orm import Session

from database import get_db
from app.models.course import Course
from app.models.department import Department
from app.models.room import Room
from app.models.timetable_entry import TimetableEntry
from app.routers.portal_deps import ADMINS, get_current_user, require_roles
from app.schemas.portal import CourseOut, DepartmentOut, RoomIn, RoomOut, RoomUpdate
from app.services.timetable_import import normalize_day, parse_time

router = APIRouter(prefix="/api", tags=["Rooms & Reference Data"], dependencies=[Depends(get_current_user)])


@router.get("/rooms", response_model=list[RoomOut])
def list_rooms(
    min_capacity: int | None = Query(None, gt=0),
    building: str | None = None,
    search: str | None = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(100, ge=1, le=500),
    db: Session = Depends(get_db),
):
    stmt = select(Room).order_by(Room.name)
    if min_capacity:
        stmt = stmt.where(Room.capacity >= min_capacity)
    if building:
        stmt = stmt.where(Room.building == building)
    if search:
        stmt = stmt.where(Room.name.ilike(f"%{search}%"))
    return db.execute(stmt.offset(skip).limit(limit)).scalars().all()


@router.get("/rooms/empty", response_model=list[RoomOut])
def empty_rooms(
    day: str = Query(..., description="Monday ... Sunday (Mon, Tue ... also accepted)"),
    start: str = Query(..., description="HH:MM, e.g. 10:00"),
    end: str = Query(..., description="HH:MM, e.g. 11:30"),
    min_capacity: int | None = Query(None, gt=0),
    db: Session = Depends(get_db),
):
    """Rooms with NO timetable entry overlapping [start, end) on that day."""
    day_name = normalize_day(day)
    start_t, end_t = parse_time(start), parse_time(end)
    if day_name is None:
        raise HTTPException(422, f"Unrecognised day: {day!r}")
    if start_t is None or end_t is None:
        raise HTTPException(422, "start and end must look like HH:MM")
    if end_t <= start_t:
        raise HTTPException(422, "end must be after start")

    busy = exists().where(
        TimetableEntry.room_id == Room.id,
        TimetableEntry.day_of_week == day_name,
        TimetableEntry.start_time < end_t,   # overlap rule: A starts before B ends
        TimetableEntry.end_time > start_t,   # and A ends after B starts
    )
    stmt = select(Room).where(~busy).order_by(Room.capacity, Room.name)
    if min_capacity:
        stmt = stmt.where(Room.capacity >= min_capacity)
    return db.execute(stmt).scalars().all()


@router.get("/rooms/{room_id}", response_model=RoomOut)
def get_room(room_id: int, db: Session = Depends(get_db)):
    room = db.get(Room, room_id)
    if room is None:
        raise HTTPException(404, "Room not found")
    return room


@router.post("/rooms", response_model=RoomOut, status_code=201, dependencies=[Depends(require_roles(*ADMINS))])
def create_room(body: RoomIn, db: Session = Depends(get_db)):
    if db.execute(select(Room.id).where(Room.name == body.name)).first():
        raise HTTPException(409, "A room with this name already exists")
    room = Room(**body.model_dump())
    db.add(room)
    db.commit()
    db.refresh(room)
    return room


@router.put("/rooms/{room_id}", response_model=RoomOut, dependencies=[Depends(require_roles(*ADMINS))])
def update_room(room_id: int, body: RoomUpdate, db: Session = Depends(get_db)):
    room = db.get(Room, room_id)
    if room is None:
        raise HTTPException(404, "Room not found")
    data = body.model_dump(exclude_unset=True)
    if "name" in data and data["name"] != room.name:
        if db.execute(select(Room.id).where(Room.name == data["name"])).first():
            raise HTTPException(409, "A room with this name already exists")
    for key, value in data.items():
        setattr(room, key, value)
    db.commit()
    db.refresh(room)
    return room


@router.get("/departments", response_model=list[DepartmentOut])
def list_departments(db: Session = Depends(get_db)):
    return db.execute(select(Department).order_by(Department.name)).scalars().all()


@router.get("/courses", response_model=list[CourseOut])
def list_courses(
    department_id: int | None = None,
    search: str | None = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(100, ge=1, le=500),
    db: Session = Depends(get_db),
):
    stmt = select(Course).order_by(Course.code)
    if department_id:
        stmt = stmt.where(Course.department_id == department_id)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(Course.code.ilike(like) | Course.title.ilike(like))
    return db.execute(stmt.offset(skip).limit(limit)).scalars().all()
