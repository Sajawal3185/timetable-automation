"""Pydantic schemas for Makeup Slot Solver and booking (Sajawal)."""
from datetime import date, time
from pydantic import BaseModel, ConfigDict, Field


class MakeupSearchIn(BaseModel):
    section_id: int
    teacher_id: int
    course_id: int
    duration_minutes: int = Field(default=60, ge=30, le=240)
    preferred_room_id: int | None = None
    days: list[str] | None = None
    term: str = "Fall 2026"


class RoomOptionOut(BaseModel):
    room_id: int
    room_name: str
    capacity: int
    room_type: str
    building: str | None = None
    is_preferred: bool


class MakeupSlotSuggestionOut(BaseModel):
    day_of_week: str
    start_time: time
    end_time: time
    recommended_room: RoomOptionOut
    fallback_rooms: list[RoomOptionOut]
    soft_warnings: list[str]


class MakeupBookIn(BaseModel):
    course_id: int
    section_id: int
    teacher_id: int
    room_id: int
    day_of_week: str
    start_time: time
    end_time: time
    class_date: date | None = None
    reason: str | None = Field(default=None, max_length=500)
    term: str = "Fall 2026"


class MakeupClassOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    course_id: int
    section_id: int
    teacher_id: int
    room_id: int
    class_date: date | None
    day_of_week: str
    start_time: time
    end_time: time
    reason: str | None
    status: str