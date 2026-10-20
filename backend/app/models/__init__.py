"""Core SQLAlchemy models package (Sajawal)."""
from app.models.course import Course
from app.models.department import Department
from app.models.room import Room
from app.models.section import Section
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User

__all__ = [
    "Department",
    "Section",
    "User",
    "Teacher",
    "Room",
    "Course",
    "TimetableEntry",
]