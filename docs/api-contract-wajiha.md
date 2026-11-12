# API contract: Wajiha's endpoints

> Paste this section into `docs/api-contract.md` in your first PR (Part A, section 1.7: the backend owner writes the contract and one backend + one frontend member review it).
> **Auth:** every endpoint needs `Authorization: Bearer <JWT>` (Sajawal's login). Roles: `student`, `teacher`, `hod`, `admin`.
> **Errors** always look like `{"detail": "message"}`. Validation errors (422) carry a list in `detail`.
> **Dates:** `YYYY-MM-DD`. **Times:** `HH:MM` (24 hour). **Days:** `Monday` ... `Sunday` (short forms like `Mon` accepted in query strings).

## 1. Rooms and reference data (`rooms.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/rooms` | any user | query: `min_capacity`, `building`, `search`, `skip`, `limit` |
| GET | `/api/rooms/empty` | any user | query: `day`, `start`, `end` (all required), `min_capacity`. Rooms with no class overlapping `[start, end)` |
| GET | `/api/rooms/{id}` | any user | 404 if missing |
| POST | `/api/rooms` | hod, admin | body `{name, capacity, building?}`; 409 if name exists |
| PUT | `/api/rooms/{id}` | hod, admin | any subset of the body above |
| GET | `/api/departments` | any user | `[{id, name, code}]` |
| GET | `/api/courses` | any user | query: `department_id`, `search`, `skip`, `limit` |

Room object: `{id, name, capacity, building}`. Course object: `{id, code, title, credit_hours, department_id}`.

## 2. Timetable (`timetable.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/timetable` | any user | query: `section_id`, `teacher_id`, `room_id`, `course_id`, `department_id`, `day`, `skip`, `limit` |
| POST | `/api/timetable/import` | hod, admin | multipart form: `department_code` (SE/AI/CS/IT), `dry_run` (default `true`), `file` (.xlsx/.csv, max 10 MB) |

Entry object: `{id, section_id, course_id, teacher_id, room_id, day_of_week, start_time, end_time}`.

Import response (`dry_run=true` saves nothing):

```json
{
  "dry_run": true,
  "sheets_read": ["BSSE-4A"], "sheets_skipped": ["Instructions"],
  "rows_parsed": 5, "rows_valid": 5,
  "issues": [{"sheet": "BSSE-4A", "row": 4, "message": "Could not read the time / start / end", "level": "error"}],
  "preview": [{"section": "BSSE-4A", "day": "Monday", "start_time": "08:30", "end_time": "10:00",
               "course_code": "SE301", "course_title": "Software Design", "teacher": "Dr. Ahmed Raza", "room": "R-12"}],
  "entries_created": 0, "entries_skipped_existing": 0, "sections_created": 0,
  "courses_created": 0, "teachers_created": 0, "rooms_created": []
}
```

`level: "error"` rows are skipped; `"warning"` rows are kept. `rooms_created` lists rooms invented by the import: their capacity is a **placeholder (40)**, so fix them with `PUT /api/rooms/{id}`. Re-importing the same file is safe (identical entries are skipped).

## 3. Exam repository (`exams.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/exam-papers` | teacher, hod, admin | multipart: `course_id`, `title`, `term`, `exam_type` (`quiz/midterm/final/sessional/other`), `year?`, `instructor_name?`, `file` (pdf/png/jpg/doc/docx, max 20 MB) |
| GET | `/api/exam-papers` | any user | query: `course_id`, `term`, `exam_type`, `year`, `q`, `skip`, `limit` |
| GET | `/api/exam-papers/{id}` | any user | |
| GET | `/api/exam-papers/{id}/download` | any user | returns the file |
| DELETE | `/api/exam-papers/{id}` | uploader, hod, admin | 204 |

Paper object: `{id, course_id, title, term, exam_type, year, instructor_name, original_filename, file_size, uploaded_by, created_at}`.

## 4. Lecture material archive (`materials.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/lecture-materials` | teacher, hod, admin | multipart: `course_id`, `title`, `material_type` (`notes/slides/assignment/other`), `instructor_name?` (defaults to the uploader), `term?`, `description?`, `file` (pdf/ppt/pptx/doc/docx/txt/zip/png/jpg) |
| GET | `/api/lecture-materials` | any user | query: `course_id`, `instructor_name`, `material_type`, `term`, `q`, `skip`, `limit` |
| GET | `/api/lecture-materials/by-course/{course_id}` | any user | `{course_id, course_code, course_title, instructors: [{instructor_name, materials: [...]}]}` |
| GET | `/api/lecture-materials/{id}` | any user | |
| GET | `/api/lecture-materials/{id}/download` | any user | returns the file |
| DELETE | `/api/lecture-materials/{id}` | uploader, hod, admin | 204 |

## 5. GPA / CGPA (`gpa.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/gpa/grade-scale` | any user | `{grade_points: {"A": 4.0, ...}, excluded_grades: [...]}` |
| POST | `/api/gpa/calculate` | any user | nothing is saved (see below) |
| GET | `/api/gpa/records` | self | the logged-in student's saved grades |
| POST | `/api/gpa/records` | self | body `{term, course_code, course_title?, credit_hours, grade}`; 409 if that course is already saved for that term |
| DELETE | `/api/gpa/records/{id}` | self | 404 if it is not yours |
| GET | `/api/gpa/summary` | self | query `repeat_policy` (`all/latest/best`); same shape as the calculate response |

`POST /api/gpa/calculate` request:

```json
{
  "terms": [
    {"term": "Fall 2025", "courses": [{"credit_hours": 3, "grade": "A", "course_code": "CS101"},
                                      {"credit_hours": 3, "grade": "B+"}]}
  ],
  "previous_cgpa": 3.0, "previous_credit_hours": 60,
  "repeat_policy": "all"
}
```

`previous_*` are optional but must be sent together. Response:

```json
{"cgpa": 3.5, "total_credit_hours": 6, "total_quality_points": 21.0,
 "terms": [{"term": "Fall 2025", "gpa": 3.5, "credit_hours": 6, "quality_points": 21.0}]}
```

`gpa`/`cgpa` is `null` when no course counts (all withdrawn/incomplete).

## 6. Software directory (`software.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/software-resources` | any user | query: `course_id`, `category`, `q`, `skip`, `limit` |
| GET | `/api/software-resources/{id}` | any user | |
| POST | `/api/software-resources` | teacher, hod, admin | `{name, category?, description?, download_url (http/https), instructions?, course_id?}` |
| PUT | `/api/software-resources/{id}` | teacher, hod, admin | any subset |
| DELETE | `/api/software-resources/{id}` | teacher, hod, admin | 204 |

## 7. Event calendar (`events.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/events` | any user | query: `month` (`2026-10`), or `from_date`/`to_date`, plus `event_type`, `department_id` (also returns university-wide events) |
| GET | `/api/events/{id}` | any user | |
| POST | `/api/events` | hod, admin | `{title, event_type (deadline/event/holiday/department), description?, start_date, end_date?, location?, department_id?}` |
| PUT | `/api/events/{id}` | hod, admin | any subset |
| DELETE | `/api/events/{id}` | hod, admin | 204 |

An event that starts before the month but runs into it is included in that month.
