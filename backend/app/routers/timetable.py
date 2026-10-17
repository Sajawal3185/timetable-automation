"""Timetable view + import (Wajiha).

  POST /api/timetable/import   upload a department sheet (.xlsx/.csv). Admin/HOD only.
                               dry_run=true (default) only previews; nothing is saved.
  GET  /api/timetable          list entries (filter: section_id, teacher_id, room_id,
                               course_id, department_id, day)

Amna's personal-timetable screen calls GET /api/timetable with teacher_id or section_id.
"""
import os
import tempfile
from pathlib import Path

from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, UploadFile
from sqlalchemy import select
from sqlalchemy.orm import Session

from database import get_db
from app.models.section import Section
from app.models.timetable_entry import TimetableEntry
from app.routers.portal_deps import ADMINS, get_current_user, require_roles
from app.schemas.portal import ImportIssueOut, ImportPreviewRow, ImportReport, TimetableEntryOut
from app.services import timetable_import as ti

router = APIRouter(prefix="/api/timetable", tags=["Timetable"])

MAX_IMPORT_MB = 10
PREVIEW_ROWS = 25


@router.get("", response_model=list[TimetableEntryOut], dependencies=[Depends(get_current_user)])
def list_timetable(
    section_id: int | None = None,
    teacher_id: int | None = None,
    room_id: int | None = None,
    course_id: int | None = None,
    department_id: int | None = None,
    day: str | None = None,
    skip: int = Query(0, ge=0),
    limit: int = Query(500, ge=1, le=2000),
    db: Session = Depends(get_db),
):
    stmt = select(TimetableEntry)
    if section_id:
        stmt = stmt.where(TimetableEntry.section_id == section_id)
    if teacher_id:
        stmt = stmt.where(TimetableEntry.teacher_id == teacher_id)
    if room_id:
        stmt = stmt.where(TimetableEntry.room_id == room_id)
    if course_id:
        stmt = stmt.where(TimetableEntry.course_id == course_id)
    if department_id:
        stmt = stmt.where(TimetableEntry.section_id.in_(select(Section.id).where(Section.department_id == department_id)))
    if day:
        day_name = ti.normalize_day(day)
        if day_name is None:
            raise HTTPException(422, f"Unrecognised day: {day!r}")
        stmt = stmt.where(TimetableEntry.day_of_week == day_name)
    stmt = stmt.order_by(TimetableEntry.day_of_week, TimetableEntry.start_time)
    return db.execute(stmt.offset(skip).limit(limit)).scalars().all()


@router.post("/import", response_model=ImportReport, dependencies=[Depends(require_roles(*ADMINS))])
def import_timetable(
    department_code: str = Form(..., description="SE, AI, CS or IT"),
    dry_run: bool = Form(True),
    file: UploadFile = File(...),
    db: Session = Depends(get_db),
):
    suffix = Path(file.filename or "").suffix.lower()
    if suffix not in {".xlsx", ".xlsm", ".csv"}:
        raise HTTPException(400, "Upload an .xlsx or .csv file")
    code = department_code.strip().upper()
    if code not in ti.DEPARTMENT_CONFIG:
        raise HTTPException(422, f"department_code must be one of {sorted(ti.DEPARTMENT_CONFIG)}")

    data = file.file.read(MAX_IMPORT_MB * 1024 * 1024 + 1)
    if len(data) > MAX_IMPORT_MB * 1024 * 1024:
        raise HTTPException(413, f"File too large (max {MAX_IMPORT_MB} MB)")
    if not data:
        raise HTTPException(400, "Uploaded file is empty")

    # The sheet is only needed while parsing, so it goes to a temp file, not the uploads folder.
    fd, tmp_name = tempfile.mkstemp(suffix=suffix)
    try:
        with os.fdopen(fd, "wb") as tmp:
            tmp.write(data)
        try:
            parsed = ti.parse_workbook(tmp_name, code)
        except Exception as exc:  # corrupt workbook, wrong format, ...
            raise HTTPException(400, f"Could not read the file: {exc}") from exc
    finally:
        os.unlink(tmp_name)

    good_rows, validation_issues = ti.validate_rows(parsed.rows)
    issues = parsed.issues + validation_issues
    report = ImportReport(
        dry_run=dry_run,
        sheets_read=parsed.sheets_read,
        sheets_skipped=parsed.sheets_skipped,
        rows_parsed=len(parsed.rows),
        rows_valid=len(good_rows),
        issues=[ImportIssueOut(sheet=i.sheet, row=i.row, message=i.message, level=i.level) for i in issues],
        preview=[
            ImportPreviewRow(
                section=r.section, day=r.day, start_time=r.start_time.strftime("%H:%M"),
                end_time=r.end_time.strftime("%H:%M"), course_code=r.course_code,
                course_title=r.course_title, teacher=r.teacher, room=r.room,
            )
            for r in good_rows[:PREVIEW_ROWS]
        ],
    )
    if dry_run or not good_rows:
        return report

    # One transaction: if anything fails halfway, nothing is saved (same idea as proposal 3.2).
    try:
        summary = ti.load_rows(db, good_rows, code)
        db.commit()
    except Exception as exc:
        db.rollback()
        raise HTTPException(500, f"Import failed and was rolled back: {exc}") from exc

    report.entries_created = summary.entries_created
    report.entries_skipped_existing = summary.entries_skipped_existing
    report.sections_created = summary.sections_created
    report.courses_created = summary.courses_created
    report.teachers_created = summary.teachers_created
    report.rooms_created = summary.rooms_created
    return report
