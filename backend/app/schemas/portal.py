"""Pydantic request/response models for Wajiha's modules.

(One file for the portal modules keeps the review small. Split per module later if it grows.)
"""
from datetime import date, datetime, time
from enum import Enum
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator


class ORM(BaseModel):
    model_config = ConfigDict(from_attributes=True)


# ----------------------------------------------------------------- reference data
class DepartmentOut(ORM):
    id: int
    name: str
    code: str


class CourseOut(ORM):
    id: int
    code: str
    title: str
    credit_hours: int | None = None
    department_id: int | None = None


class RoomOut(ORM):
    id: int
    name: str
    capacity: int
    building: str | None = None


class RoomIn(BaseModel):
    name: str = Field(min_length=1, max_length=50)
    capacity: int = Field(gt=0, le=1000)
    building: str | None = Field(default=None, max_length=50)


class RoomUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=50)
    capacity: int | None = Field(default=None, gt=0, le=1000)
    building: str | None = Field(default=None, max_length=50)


# ----------------------------------------------------------------- timetable
class TimetableEntryOut(ORM):
    id: int
    section_id: int
    course_id: int
    teacher_id: int | None
    room_id: int | None
    day_of_week: str
    start_time: time
    end_time: time


class ImportIssueOut(BaseModel):
    sheet: str
    row: int
    message: str
    level: str


class ImportPreviewRow(BaseModel):
    section: str
    day: str
    start_time: str
    end_time: str
    course_code: str
    course_title: str
    teacher: str
    room: str


class ImportReport(BaseModel):
    dry_run: bool
    sheets_read: list[str]
    sheets_skipped: list[str]
    rows_parsed: int
    rows_valid: int
    issues: list[ImportIssueOut]
    preview: list[ImportPreviewRow]
    entries_created: int = 0
    entries_skipped_existing: int = 0
    sections_created: int = 0
    courses_created: int = 0
    teachers_created: int = 0
    rooms_created: list[str] = []


# ----------------------------------------------------------------- exam papers / materials
class ExamType(str, Enum):
    quiz = "quiz"
    midterm = "midterm"
    final = "final"
    sessional = "sessional"
    other = "other"


class MaterialType(str, Enum):
    notes = "notes"
    slides = "slides"
    assignment = "assignment"
    other = "other"


class ExamPaperOut(ORM):
    id: int
    course_id: int
    title: str
    term: str
    exam_type: str
    year: int | None
    instructor_name: str | None
    original_filename: str
    file_size: int
    uploaded_by: int
    created_at: datetime | None = None


class LectureMaterialOut(ORM):
    id: int
    course_id: int
    title: str
    material_type: str
    instructor_name: str
    term: str | None
    description: str | None
    original_filename: str
    file_size: int
    uploaded_by: int
    created_at: datetime | None = None


class MaterialsByInstructor(BaseModel):
    instructor_name: str
    materials: list[LectureMaterialOut]


class MaterialsByCourse(BaseModel):
    course_id: int
    course_code: str
    course_title: str
    instructors: list[MaterialsByInstructor]


# ----------------------------------------------------------------- GPA
class CourseGradeIn(BaseModel):
    credit_hours: float = Field(gt=0, le=6)
    grade: str = Field(min_length=1, max_length=3)
    course_code: str = Field(default="", max_length=20)
    course_title: str = Field(default="", max_length=150)


class TermIn(BaseModel):
    term: str = Field(min_length=1, max_length=40)
    courses: list[CourseGradeIn] = Field(min_length=1, max_length=20)


class GpaRequest(BaseModel):
    terms: list[TermIn] = Field(min_length=1, max_length=20)
    previous_cgpa: float | None = Field(default=None, ge=0, le=4)
    previous_credit_hours: float | None = Field(default=None, ge=0)
    repeat_policy: Literal["all", "latest", "best"] = "all"

    @model_validator(mode="after")
    def _previous_together(self):
        if (self.previous_cgpa is None) != (self.previous_credit_hours is None):
            raise ValueError("previous_cgpa and previous_credit_hours must be given together")
        return self


class TermResultOut(BaseModel):
    term: str
    gpa: float | None
    credit_hours: float
    quality_points: float


class GpaResponse(BaseModel):
    cgpa: float | None
    total_credit_hours: float
    total_quality_points: float
    terms: list[TermResultOut]


class GradeRecordIn(BaseModel):
    term: str = Field(min_length=1, max_length=40)
    course_code: str = Field(min_length=1, max_length=20)
    course_title: str | None = Field(default=None, max_length=150)
    credit_hours: float = Field(gt=0, le=6)
    grade: str = Field(min_length=1, max_length=3)


class GradeRecordOut(ORM):
    id: int
    term: str
    course_code: str
    course_title: str | None
    credit_hours: float
    grade: str


# ----------------------------------------------------------------- software / events
class SoftwareIn(BaseModel):
    name: str = Field(min_length=1, max_length=120)
    category: str | None = Field(default=None, max_length=50)
    description: str | None = None
    download_url: str = Field(min_length=8, max_length=500, pattern=r"^https?://")
    instructions: str | None = None
    course_id: int | None = None


class SoftwareUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=120)
    category: str | None = Field(default=None, max_length=50)
    description: str | None = None
    download_url: str | None = Field(default=None, min_length=8, max_length=500, pattern=r"^https?://")
    instructions: str | None = None
    course_id: int | None = None


class SoftwareOut(ORM):
    id: int
    name: str
    category: str | None
    description: str | None
    download_url: str
    instructions: str | None
    course_id: int | None


class EventType(str, Enum):
    deadline = "deadline"
    event = "event"
    holiday = "holiday"
    department = "department"


class EventIn(BaseModel):
    title: str = Field(min_length=1, max_length=200)
    event_type: EventType
    description: str | None = None
    start_date: date
    end_date: date | None = None
    location: str | None = Field(default=None, max_length=150)
    department_id: int | None = None

    @model_validator(mode="after")
    def _dates(self):
        if self.end_date and self.end_date < self.start_date:
            raise ValueError("end_date cannot be before start_date")
        return self


class EventUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=200)
    event_type: EventType | None = None
    description: str | None = None
    start_date: date | None = None
    end_date: date | None = None
    location: str | None = Field(default=None, max_length=150)
    department_id: int | None = None


class EventOut(ORM):
    id: int
    title: str
    event_type: str
    description: str | None
    start_date: date
    end_date: date | None
    location: str | None
    department_id: int | None
