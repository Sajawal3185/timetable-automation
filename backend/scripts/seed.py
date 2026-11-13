"""Demo data for staging and local runs (task BE-11).

Run from backend/, after `alembic upgrade head`:

    SEED_PASSWORD='choose-one' python -m scripts.seed           # first load
    SEED_PASSWORD='choose-one' python -m scripts.seed --reset   # before every demo run

What it creates (the same small university as frontend/mock/db/, so the pages
look the same in mock mode and against the real backend):

    2 departments, 3 sections, 5 rooms, 6 courses, 4 teachers, 14 timetable entries
    7 accounts: admin, HOD (SE), 2 teachers, 2 activated students and
                1 student who has NOT activated yet (for the activation demo)

Plain run: safe to repeat. Missing rows are added, existing rows are left alone,
and the timetable is only loaded while the seeded sections have no entries.

--reset: puts the demo back to its starting state. It
    * DELETES EVERY swap request, makeup class and notification in the database,
    * reloads the timetable of the seeded sections (approved swaps moved it),
    * sets every demo account's password back (and un-activates the last student).
  Use it on a demo database only, never on one that holds real users' data.

Passwords are NOT in this file: the deploy is public and the repository may be
too, so a password written here would be an open door to the admin account.
Every activated demo account gets SEED_PASSWORD (environment variable, 8+ characters).
"""
import argparse
import os
import sys
from datetime import time

from sqlalchemy import delete, func, select
from sqlalchemy.orm import Session

from app.core.security import hash_password
from app.models.course import Course
from app.models.department import Department
from app.models.makeup_class import MakeupClass
from app.models.notification import Notification
from app.models.room import Room
from app.models.section import Section
from app.models.swap_request import SwapRequest
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User

TERM = "Fall 2026"
MIN_PASSWORD = 8

# (code, name)
DEPARTMENTS = [("SE", "Software Engineering"), ("CS", "Computer Science")]

# (name, department code, semester, student_count)
SECTIONS = [("BSSE-5A", "SE", 5, 45), ("BSSE-5B", "SE", 5, 38), ("BSCS-3A", "CS", 3, 50)]

# (name, capacity, room_type, building)
ROOMS = [
    ("R-101", 50, "lecture", "Block A"),
    ("R-102", 40, "lecture", "Block A"),
    ("R-201", 60, "lecture", "Block B"),
    ("Lab-1", 50, "lab", "Block B"),
    ("Lab-2", 30, "lab", "Block B"),
]

# (code, title, credit_hours, is_lab, department code)
COURSES = [
    ("SE-311", "Software Design and Architecture", 3, False, "SE"),
    ("SE-312", "Software Quality Engineering", 3, False, "SE"),
    ("SE-313", "Operating Systems Lab", 1, True, "SE"),
    ("SE-314", "Database Systems", 3, False, "SE"),
    ("SE-315", "Software Requirements Engineering", 3, False, "SE"),
    ("CS-201", "Data Structures and Algorithms", 3, False, "CS"),
]

# (identifier, name, role, department code, section name, activated)
# Identifiers are stored lowercase, exactly as routers/auth.py normalises them.
USERS = [
    ("adm-100001", "Admin Office", "admin", None, None, True),
    ("hod-100301", "Ms. Sara Khan", "hod", "SE", None, True),
    ("tr-100201", "Dr. Ahmed Raza", "teacher", "SE", None, True),
    ("tr-200401", "Dr. Usman Ali", "teacher", "CS", None, True),
    ("se-9244330", "Ali Hassan", "student", "SE", "BSSE-5A", True),
    ("cs-9255101", "Hina Malik", "student", "CS", "BSCS-3A", True),
    # On the roster but has not chosen a password: POST /api/auth/activate claims it.
    ("se-9244331", "Fatima Noor", "student", "SE", "BSSE-5B", False),
]

# (name, department code, identifier of the linked login or None)
# Mr. Bilal Ahmed has no login on purpose: imported teachers look like that.
# Ms. Sara Khan is the SE HOD and also teaches.
TEACHERS = [
    ("Dr. Ahmed Raza", "SE", "tr-100201"),
    ("Ms. Sara Khan", "SE", "hod-100301"),
    ("Mr. Bilal Ahmed", "SE", None),
    ("Dr. Usman Ali", "CS", "tr-200401"),
]

# (section, course code, teacher, room, day, start, end)
# Two things the demo relies on:
#   * clash:  swapping Mon 08:30 SE-311 (5A) with Mon 10:00 SE-312 (5A) is refused,
#             because Ms. Sara Khan already teaches 5B on Monday 08:30.
#   * free:   swapping Wed 10:00 SE-311 (5A) with Wed 08:30 SE-315 (5B) is clean.
TIMETABLE = [
    ("BSSE-5A", "SE-311", "Dr. Ahmed Raza", "R-101", "Monday", "08:30", "10:00"),
    ("BSSE-5A", "SE-312", "Ms. Sara Khan", "R-101", "Monday", "10:00", "11:30"),
    ("BSSE-5A", "SE-314", "Mr. Bilal Ahmed", "R-201", "Tuesday", "08:30", "10:00"),
    ("BSSE-5A", "SE-313", "Dr. Ahmed Raza", "Lab-1", "Tuesday", "11:40", "13:10"),
    ("BSSE-5A", "SE-311", "Dr. Ahmed Raza", "R-101", "Wednesday", "10:00", "11:30"),
    ("BSSE-5A", "SE-315", "Ms. Sara Khan", "R-201", "Thursday", "08:30", "10:00"),
    ("BSSE-5A", "SE-314", "Mr. Bilal Ahmed", "R-201", "Friday", "10:00", "11:30"),
    ("BSSE-5B", "SE-312", "Ms. Sara Khan", "R-102", "Monday", "08:30", "10:00"),
    ("BSSE-5B", "SE-311", "Dr. Ahmed Raza", "R-102", "Monday", "11:40", "13:10"),
    ("BSSE-5B", "SE-315", "Ms. Sara Khan", "R-102", "Wednesday", "08:30", "10:00"),
    ("BSSE-5B", "SE-314", "Mr. Bilal Ahmed", "R-102", "Thursday", "10:00", "11:30"),
    ("BSSE-5B", "SE-311", "Dr. Ahmed Raza", "R-102", "Friday", "08:30", "10:00"),
    ("BSCS-3A", "CS-201", "Dr. Usman Ali", "R-201", "Monday", "10:00", "11:30"),
    ("BSCS-3A", "CS-201", "Dr. Usman Ali", "R-201", "Wednesday", "11:40", "13:10"),
]


def _hhmm(text: str) -> time:
    hours, minutes = text.split(":")
    return time(int(hours), int(minutes))


def _one(db: Session, model, *where):
    return db.execute(select(model).where(*where)).scalars().first()


def seed(db: Session, password: str, reset: bool = False) -> dict[str, int]:
    """Load the demo data into `db` and commit. Returns what was added / removed."""
    if len(password) < MIN_PASSWORD:
        raise ValueError(f"The seed password needs at least {MIN_PASSWORD} characters")
    done = {"added": 0, "timetable_entries": 0, "swaps_deleted": 0, "makeups_deleted": 0,
            "notifications_deleted": 0}

    def add(row):
        db.add(row)
        db.flush()  # gives the row its id for the rows that point at it
        done["added"] += 1
        return row

    # ---- reference data: add what is missing, never change what is there ----
    depts = {}
    for code, name in DEPARTMENTS:
        depts[code] = _one(db, Department, Department.code == code) or add(Department(code=code, name=name))

    sections = {}
    for name, dept, semester, count in SECTIONS:
        sections[name] = _one(db, Section, Section.name == name, Section.department_id == depts[dept].id) or add(
            Section(name=name, department_id=depts[dept].id, semester=semester, student_count=count))

    rooms = {}
    for name, capacity, room_type, building in ROOMS:
        rooms[name] = _one(db, Room, Room.name == name) or add(
            Room(name=name, capacity=capacity, room_type=room_type, building=building))

    courses = {}
    for code, title, credits, is_lab, dept in COURSES:
        courses[code] = _one(db, Course, Course.code == code, Course.department_id == depts[dept].id) or add(
            Course(code=code, title=title, credit_hours=credits, is_lab=is_lab, department_id=depts[dept].id))

    # ---- accounts -----------------------------------------------------------
    # The hash is computed once: bcrypt is slow on purpose, and one shared hash
    # for the demo accounts is fine because they share the password anyway.
    hashed = hash_password(password)
    users = {}
    for identifier, name, role, dept, section, activated in USERS:
        user = _one(db, User, User.identifier == identifier)
        if user is None:
            user = add(User(identifier=identifier, name=name, password_hash=hashed if activated else None))
        elif reset:
            user.password_hash = hashed if activated else None
        # Role, department and section are what the demo depends on (an HOD with
        # no department can approve nothing), so they are always put right.
        user.role, user.is_active = role, True
        user.department_id = depts[dept].id if dept else None
        user.section_id = sections[section].id if section else None
        users[identifier] = user

    teachers = {}
    for name, dept, identifier in TEACHERS:
        # Same lookup as the timetable importer (case-insensitive name), so a
        # later import of a real sheet reuses these rows instead of duplicating them.
        teacher = _one(db, Teacher, func.lower(Teacher.name) == name.lower()) or add(Teacher(name=name))
        teacher.department_id = depts[dept].id
        teacher.user_id = users[identifier].id if identifier else None
        teachers[name] = teacher

    # ---- back to the starting state ------------------------------------------
    section_ids = [s.id for s in sections.values()]
    seeded_entries = TimetableEntry.section_id.in_(section_ids), TimetableEntry.term == TERM
    if reset:
        # Swap requests point at timetable entries, so they go first.
        done["swaps_deleted"] = db.execute(delete(SwapRequest)).rowcount
        done["makeups_deleted"] = db.execute(delete(MakeupClass)).rowcount
        done["notifications_deleted"] = db.execute(delete(Notification)).rowcount
        db.execute(delete(TimetableEntry).where(*seeded_entries))

    # ---- timetable: only into empty sections, so a plain re-run adds nothing --
    if db.execute(select(func.count()).select_from(TimetableEntry).where(*seeded_entries)).scalar_one() == 0:
        for section, course, teacher, room, day, start, end in TIMETABLE:
            db.add(TimetableEntry(
                term=TERM, section_id=sections[section].id, course_id=courses[course].id,
                teacher_id=teachers[teacher].id, room_id=rooms[room].id,
                day_of_week=day, start_time=_hhmm(start), end_time=_hhmm(end),
            ))
        done["timetable_entries"] = len(TIMETABLE)

    db.commit()
    return done


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Load the demo data (see the top of scripts/seed.py).")
    parser.add_argument("--reset", action="store_true",
                        help="delete ALL swaps, makeups and notifications and reload the demo timetable")
    args = parser.parse_args(argv)

    password = os.getenv("SEED_PASSWORD", "")
    if len(password) < MIN_PASSWORD:
        print(f"Set SEED_PASSWORD ({MIN_PASSWORD}+ characters): it becomes the password of every demo account.",
              file=sys.stderr)
        return 2

    from app.database import SessionLocal  # here, so importing this module needs no database

    with SessionLocal() as db:
        done = seed(db, password, reset=args.reset)

    print(f"Seed finished: {done['added']} rows added, {done['timetable_entries']} timetable entries loaded.")
    if args.reset:
        print(f"Reset removed {done['swaps_deleted']} swap requests, {done['makeups_deleted']} makeup classes "
              f"and {done['notifications_deleted']} notifications.")
    print("Accounts (password = SEED_PASSWORD, except the last one, which is not activated):")
    for identifier, name, role, *_ in USERS:
        print(f"  {role:8} {identifier:12} {name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
