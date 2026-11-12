"""Timetable import (Wajiha) - reads department sheets into ONE common dataset.

Three stages, each usable on its own:

  1. parse_workbook()  file -> list[TimetableRow] (+ issues). No database.
  2. validate_rows()   checks the rows (times, days, duplicates).
  3. load_rows()       writes rows into Sajawal's tables inside the caller's
                       transaction (the router commits or rolls back).

Two sheet layouts are recognised automatically:

  FLAT  one row per class:   Day | Time (or Start/End) | Course | Teacher | Room [| Section]
  GRID  a timetable grid:    days down the side, time slots across the top (or the
                             reverse); each cell holds  "Course / Teacher / Room"
                             on separate lines.

ASSUMPTION: the real SE/AI/CS/IT sheets were not available when this was written.
If a real sheet is not recognised, the fix is almost always one of:
  * add a header spelling to HEADER_ALIASES,
  * add a sheet name to DEPARTMENT_CONFIG[...]["skip_sheets"],
  * adjust parse_cell() for the way cells are written.
The pure parsing functions are covered by tests/test_timetable_import.py.
"""
from __future__ import annotations

import csv
import re
from dataclasses import dataclass, field
from datetime import datetime, time
from pathlib import Path
from typing import Any

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.course import Course
from app.models.department import Department
from app.models.room import Room
from app.models.section import Section
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry

DEFAULT_NEW_ROOM_CAPACITY = 40  # used when the sheet names a room we have never seen

DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
_DAY_BY_PREFIX = {d[:3].lower(): d for d in DAYS}

DEPARTMENT_CONFIG: dict[str, dict[str, Any]] = {
    # SE sheets are the known, consistent format: start here.
    "SE": {"skip_sheets": {"instructions", "summary", "rooms", "legend"}},
    "AI": {"skip_sheets": {"instructions", "summary", "rooms", "legend"}},
    "CS": {"skip_sheets": {"instructions", "summary", "rooms", "legend"}},
    "IT": {"skip_sheets": {"instructions", "summary", "rooms", "legend"}},
}

HEADER_ALIASES: dict[str, set[str]] = {
    "section": {"section", "class", "batch", "group", "semester section"},
    "day": {"day", "days", "weekday"},
    "time": {"time", "timing", "timings", "slot", "time slot", "period", "lecture time"},
    "start_time": {"start", "start time", "from", "begin"},
    "end_time": {"end", "end time", "to", "until"},
    "course_code": {"course code", "code", "subject code", "course id"},
    "course": {"course", "course title", "course name", "subject", "subject name", "title"},
    "teacher": {"teacher", "instructor", "faculty", "lecturer", "teacher name", "instructor name", "taught by"},
    "room": {"room", "venue", "classroom", "location", "room no", "room number"},
}

EMPTY_CELLS = {"", "-", "--", "x", "free", "break", "lunch", "prayer", "prayer break", "n/a", "none"}


# --------------------------------------------------------------------------- data

@dataclass
class TimetableRow:
    department: str
    section: str
    day: str
    start_time: time
    end_time: time
    course_title: str
    course_code: str = ""
    teacher: str = ""
    room: str = ""
    source_sheet: str = ""
    source_row: int = 0


@dataclass
class Issue:
    sheet: str
    row: int
    message: str
    level: str = "error"  # "error" skips the row, "warning" keeps it


@dataclass
class ParseResult:
    rows: list[TimetableRow] = field(default_factory=list)
    issues: list[Issue] = field(default_factory=list)
    sheets_read: list[str] = field(default_factory=list)
    sheets_skipped: list[str] = field(default_factory=list)


@dataclass
class LoadSummary:
    entries_created: int = 0
    entries_skipped_existing: int = 0
    departments_created: int = 0
    sections_created: int = 0
    courses_created: int = 0
    teachers_created: int = 0
    rooms_created: list[str] = field(default_factory=list)  # their capacity is a placeholder


# --------------------------------------------------------------------------- cell helpers

def cell_str(value: Any) -> str:
    """Any spreadsheet cell value -> clean string."""
    if value is None:
        return ""
    if isinstance(value, datetime):
        return value.strftime("%H:%M") if value.year <= 1900 else value.strftime("%Y-%m-%d %H:%M")
    if isinstance(value, time):
        return value.strftime("%H:%M")
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    return re.sub(r"[ \t\r\f\v]+", " ", str(value)).strip()


_TIME_RE = re.compile(r"(?<!\d)(\d{1,2})\s*[:.]\s*(\d{2})\s*([ap]\.?m\.?)?|(?<!\d)(\d{1,2})\s*([ap]\.?m\.?)", re.I)


def _to_time(hour: int, minute: int, meridiem: str | None) -> time | None:
    meridiem = (meridiem or "").lower().replace(".", "")
    if meridiem == "pm" and hour < 12:
        hour += 12
    elif meridiem == "am" and hour == 12:
        hour = 0
    elif not meridiem and 1 <= hour <= 7:
        hour += 12  # no am/pm written: classes run roughly 08:00-19:00, so 1-7 means afternoon
    if 0 <= hour <= 23 and 0 <= minute <= 59:
        return time(hour, minute)
    return None


def parse_time(text: str) -> time | None:
    """'8:30', '08.30', '8:30 AM', '2 pm' -> time. None if it is not a time."""
    m = _TIME_RE.search(text or "")
    if not m:
        return None
    if m.group(1) is not None:
        return _to_time(int(m.group(1)), int(m.group(2)), m.group(3))
    return _to_time(int(m.group(4)), 0, m.group(5))


def parse_time_range(text: str) -> tuple[time, time] | None:
    """'08:30 - 10:00', '8:30 to 10', '1:00-2:30 PM' -> (start, end)."""
    text = (text or "").strip()
    if not text:
        return None
    parts = re.split(r"\s*(?:-|\u2013|\u2014|\bto\b)\s*", text, maxsplit=1, flags=re.I)
    if len(parts) != 2:
        return None
    left, right = parts
    start, end = parse_time(left), parse_time(right)
    if start is None or end is None:
        return None
    return start, end


def normalize_day(text: str) -> str | None:
    t = re.sub(r"[^a-z]", "", (text or "").lower())
    return _DAY_BY_PREFIX.get(t[:3]) if len(t) >= 3 and t[:3] in _DAY_BY_PREFIX else None


_CODE_RE = re.compile(r"^\s*([A-Za-z]{2,4}\s?-?\s?\d{3,4}[A-Za-z]?)\s*[-:\u2013]?\s*(.*)$")


def split_course(text: str) -> tuple[str, str]:
    """'CS-101 Programming Fundamentals' -> ('CS-101', 'Programming Fundamentals')."""
    m = _CODE_RE.match(text or "")
    if m and m.group(2):
        return m.group(1).strip(), m.group(2).strip()
    return "", (text or "").strip()


_ROOM_HINT = re.compile(r"\b(room|rm|lab|hall|lt|cr|lecture theatre)\b|^[A-Za-z]{0,2}-?\d{1,3}[A-Za-z]?$", re.I)


def parse_cell(text: str) -> tuple[str, str, str, str] | None:
    """One grid cell -> (course_code, course_title, teacher, room). None if empty.

    Layouts understood (lines separated by newline or '|'):
        Course            Course             Course
        Teacher           Teacher            Room            (2nd line looks like a room)
        Room
    """
    raw = text or ""
    if raw.strip().lower() in EMPTY_CELLS:
        return None
    lines = [ln.strip() for ln in re.split(r"[\n|]+", raw) if ln.strip()]
    if not lines:
        return None
    code, title = split_course(lines[0])
    teacher = room = ""
    if len(lines) == 2:
        if _ROOM_HINT.search(lines[1]):
            room = lines[1]
        else:
            teacher = lines[1]
    elif len(lines) >= 3:
        teacher, room = lines[1], lines[-1]
    return code, title, teacher, room


# --------------------------------------------------------------------------- reading files

def read_workbook(path: str | Path) -> list[tuple[str, list[list[str]]]]:
    """Return [(sheet_name, matrix_of_strings)]. Supports .xlsx/.xlsm and .csv."""
    path = Path(path)
    ext = path.suffix.lower()
    if ext == ".csv":
        with open(path, newline="", encoding="utf-8-sig") as fh:
            return [(path.stem, [[cell_str(c) for c in row] for row in csv.reader(fh)])]
    if ext in {".xlsx", ".xlsm"}:
        from openpyxl import load_workbook
        wb = load_workbook(path, data_only=True, read_only=False)
        sheets = []
        for ws in wb.worksheets:
            matrix = [[cell_str(v) for v in row] for row in ws.iter_rows(values_only=True)]
            # Merged cells only keep their value in the top-left cell: copy it into the rest.
            for rng in ws.merged_cells.ranges:
                top = cell_str(ws.cell(rng.min_row, rng.min_col).value)
                for r in range(rng.min_row, rng.max_row + 1):
                    for c in range(rng.min_col, rng.max_col + 1):
                        if r - 1 < len(matrix) and c - 1 < len(matrix[r - 1]):
                            matrix[r - 1][c - 1] = top
            sheets.append((ws.title, matrix))
        return sheets
    raise ValueError(f"Unsupported file type {ext!r}. Upload .xlsx or .csv")


# --------------------------------------------------------------------------- FLAT layout

def _find_flat_header(matrix: list[list[str]]) -> tuple[int, dict[str, int]] | None:
    for r, row in enumerate(matrix[:20]):
        cols: dict[str, int] = {}
        for c, cell in enumerate(row):
            key = cell.strip().lower()
            for field_name, names in HEADER_ALIASES.items():
                if key in names and field_name not in cols:
                    cols[field_name] = c
        has_time = "time" in cols or ("start_time" in cols and "end_time" in cols)
        has_course = "course" in cols or "course_code" in cols
        if "day" in cols and has_time and has_course:
            return r, cols
    return None


def _parse_flat(sheet: str, matrix, header, department: str, result: ParseResult) -> None:
    h_row, cols = header
    last_day = ""
    for r in range(h_row + 1, len(matrix)):
        row = matrix[r]
        get = lambda f: row[cols[f]].strip() if f in cols and cols[f] < len(row) else ""  # noqa: E731
        if not any(row):
            continue
        day_text = get("day") or last_day  # a blank day = merged cell, same day as above
        day = normalize_day(day_text)
        last_day = day_text
        if day is None:
            result.issues.append(Issue(sheet, r + 1, f"Unrecognised day {day_text!r}"))
            continue
        if "time" in cols:
            span = parse_time_range(get("time"))
        else:
            s, e = parse_time(get("start_time")), parse_time(get("end_time"))
            span = (s, e) if s and e else None
        if span is None:
            result.issues.append(Issue(sheet, r + 1, "Could not read the time / start / end"))
            continue
        code, title = get("course_code"), get("course")
        if not code and title:
            code, title = split_course(title)
        if not title and not code:
            continue  # empty slot
        result.rows.append(TimetableRow(
            department=department, section=get("section") or sheet, day=day,
            start_time=span[0], end_time=span[1], course_title=title or code, course_code=code,
            teacher=get("teacher"), room=get("room"), source_sheet=sheet, source_row=r + 1,
        ))


# --------------------------------------------------------------------------- GRID layout

def _transpose(matrix: list[list[str]]) -> list[list[str]]:
    width = max((len(r) for r in matrix), default=0)
    return [[(row[c] if c < len(row) else "") for row in matrix] for c in range(width)]


def _find_grid_header(matrix) -> tuple[int, dict[int, tuple[time, time]]] | None:
    for r, row in enumerate(matrix[:15]):
        slots = {c: span for c, cell in enumerate(row) if (span := parse_time_range(cell))}
        if len(slots) >= 2:
            return r, slots
    return None


def _parse_grid(sheet: str, matrix, department: str, result: ParseResult) -> bool:
    header = _find_grid_header(matrix)
    transposed = False
    if header is None:
        matrix, transposed = _transpose(matrix), True
        header = _find_grid_header(matrix)
        if header is None:
            return False
    h_row, slots = header
    day_col = None
    for c in range(0, min(4, max((len(r) for r in matrix), default=0))):
        if any(normalize_day(matrix[r][c]) for r in range(h_row + 1, len(matrix)) if c < len(matrix[r])):
            day_col = c
            break
    if day_col is None:
        return False
    last_day = None
    for r in range(h_row + 1, len(matrix)):
        row = matrix[r]
        day = normalize_day(row[day_col]) if day_col < len(row) else None
        day = day or last_day
        if day is None:
            continue
        last_day = day
        for c, (start, end) in slots.items():
            cell = row[c] if c < len(row) else ""
            parsed = parse_cell(cell)
            if parsed is None:
                continue
            code, title, teacher, room = parsed
            # report the position in the ORIGINAL sheet (swap back when transposed)
            src_row = c + 1 if transposed else r + 1
            result.rows.append(TimetableRow(
                department=department, section=sheet, day=day, start_time=start, end_time=end,
                course_title=title or code, course_code=code, teacher=teacher, room=room,
                source_sheet=sheet, source_row=src_row,
            ))
    return True


# --------------------------------------------------------------------------- public API

def parse_workbook(path: str | Path, department_code: str) -> ParseResult:
    """Stage 1: read a department workbook into the common dataset."""
    code = department_code.strip().upper()
    if code not in DEPARTMENT_CONFIG:
        raise ValueError(f"Unknown department {department_code!r}. Use one of {sorted(DEPARTMENT_CONFIG)}")
    skip = DEPARTMENT_CONFIG[code]["skip_sheets"]
    result = ParseResult()
    for name, matrix in read_workbook(path):
        if name.strip().lower() in skip or not any(any(r) for r in matrix):
            result.sheets_skipped.append(name)
            continue
        before = len(result.rows)
        flat = _find_flat_header(matrix)
        if flat is not None:
            _parse_flat(name, matrix, flat, code, result)
        elif not _parse_grid(name, matrix, code, result):
            result.issues.append(Issue(name, 0, "Sheet layout not recognised (no flat header or time grid found)"))
            result.sheets_skipped.append(name)
            continue
        result.sheets_read.append(name)
        if len(result.rows) == before:
            result.issues.append(Issue(name, 0, "Sheet was read but no classes were found", "warning"))
    return result


def validate_rows(rows: list[TimetableRow]) -> tuple[list[TimetableRow], list[Issue]]:
    """Stage 2: drop impossible rows and exact duplicates. Returns (good_rows, issues).

    Teacher/room/section CLASH detection is Taha's conflict_checker.py. Run it
    after the import; this stage only checks that each row makes sense by itself.
    """
    good: list[TimetableRow] = []
    issues: list[Issue] = []
    seen: set[tuple] = set()
    for row in rows:
        if row.end_time <= row.start_time:
            issues.append(Issue(row.source_sheet, row.source_row, "End time is not after start time"))
            continue
        if not row.course_title.strip():
            issues.append(Issue(row.source_sheet, row.source_row, "Missing course"))
            continue
        key = (row.section.lower(), row.day, row.start_time, row.end_time, (row.course_code or row.course_title).lower())
        if key in seen:
            issues.append(Issue(row.source_sheet, row.source_row, "Duplicate row in the file, ignored", "warning"))
            continue
        seen.add(key)
        good.append(row)
    return good, issues


_EMPTY_NAMES = {"", "tba", "tbd", "n/a", "-"}


def _ci_first(db: Session, model, name_column, value: str, *extra):
    stmt = select(model).where(func.lower(name_column) == value.lower(), *extra)
    return db.execute(stmt).scalars().first()


def load_rows(db: Session, rows: list[TimetableRow], department_code: str) -> LoadSummary:
    """Stage 3: write rows into the database. Caller commits (or rolls back on error).

    Re-importing the same sheet is safe: an identical entry is skipped, not duplicated.
    ASSUMPTION: TimetableEntry has section_id, course_id, teacher_id, room_id,
    day_of_week (full day name), start_time, end_time. Adjust the names below if
    Sajawal's final model differs.
    """
    summary = LoadSummary()
    code = department_code.strip().upper()

    dept = _ci_first(db, Department, Department.code, code)
    if dept is None:
        dept = Department(code=code, name=code)
        db.add(dept)
        db.flush()
        summary.departments_created += 1

    sections: dict[str, Section] = {}
    courses: dict[str, Course] = {}
    teachers: dict[str, Teacher] = {}
    rooms: dict[str, Room] = {}

    for row in rows:
        skey = row.section.lower()
        if skey not in sections:
            sec = _ci_first(db, Section, Section.name, row.section, Section.department_id == dept.id)
            if sec is None:
                sec = Section(name=row.section, department_id=dept.id)
                db.add(sec)
                db.flush()
                summary.sections_created += 1
            sections[skey] = sec

        ckey = (row.course_code or row.course_title).lower()
        if ckey not in courses:
            course = (_ci_first(db, Course, Course.code, row.course_code) if row.course_code
                      else _ci_first(db, Course, Course.title, row.course_title, Course.department_id == dept.id))
            if course is None:
                new_code = row.course_code or re.sub(r"[^A-Za-z0-9]", "", row.course_title).upper()[:20]
                course = Course(code=new_code, title=row.course_title, department_id=dept.id)
                db.add(course)
                db.flush()
                summary.courses_created += 1
            courses[ckey] = course

        teacher_id = None
        if row.teacher.strip().lower() not in _EMPTY_NAMES:
            tkey = row.teacher.lower()
            if tkey not in teachers:
                t = _ci_first(db, Teacher, Teacher.name, row.teacher)
                if t is None:
                    t = Teacher(name=row.teacher, department_id=dept.id)
                    db.add(t)
                    db.flush()
                    summary.teachers_created += 1
                teachers[tkey] = t
            teacher_id = teachers[tkey].id

        room_id = None
        if row.room.strip().lower() not in _EMPTY_NAMES:
            rkey = row.room.lower()
            if rkey not in rooms:
                rm = _ci_first(db, Room, Room.name, row.room)
                if rm is None:
                    rm = Room(name=row.room, capacity=DEFAULT_NEW_ROOM_CAPACITY)
                    db.add(rm)
                    db.flush()
                    summary.rooms_created.append(row.room)
                rooms[rkey] = rm
            room_id = rooms[rkey].id

        section, course = sections[skey], courses[ckey]
        exists = db.execute(select(TimetableEntry.id).where(
            TimetableEntry.section_id == section.id, TimetableEntry.course_id == course.id,
            TimetableEntry.day_of_week == row.day, TimetableEntry.start_time == row.start_time,
            TimetableEntry.end_time == row.end_time,
        )).first()
        if exists:
            summary.entries_skipped_existing += 1
            continue
        db.add(TimetableEntry(
            section_id=section.id, course_id=course.id, teacher_id=teacher_id, room_id=room_id,
            day_of_week=row.day, start_time=row.start_time, end_time=row.end_time,
        ))
        summary.entries_created += 1
    db.flush()
    return summary
