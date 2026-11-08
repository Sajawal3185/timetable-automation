# API contract

The single source of truth for every endpoint. If code and this file disagree, that is a bug: fix one of them in the same PR.
FastAPI's live docs at `/docs` can be used to check it.

## Rules for everyone

- **Auth:** every endpoint except `POST /api/auth/login`, `/activate`, `/register` and `GET /api/health` needs `Authorization: Bearer <JWT>`. Roles (lowercase): `student`, `teacher`, `hod`, `admin`. The role always comes from the database, never from the request.
- **Errors** look like `{"detail": "message"}`. Validation errors (422) carry a list in `detail`. A role that is not allowed gets `403 {"detail": "You do not have permission for this action"}`. Swap conflicts (409) carry an object: `{"detail": {"message": "...", "conflicts": [...]}}`.
- **Formats:** dates `YYYY-MM-DD`; times `HH:MM` (24 hour); days `Monday` ... `Sunday` (short forms like `Mon` are accepted only in query strings); timestamps ISO 8601.
- **Pagination:** list endpoints take `skip` and `limit`. Exam papers and materials also return `X-Total-Count`.
- **Updates** use `PUT` with any subset of fields. The only `PATCH` is marking one notification read.
- **Changing this file:** any PR that adds or changes an endpoint, a field or a status code updates this file in the same PR, and the PR reviewer checks it (add "API contract updated" to the PR checklist).

## 1. Health

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/health` | anyone, no token | `200 {"status": "ok"}`. Used by the host's health check. |

## 2. Authentication (`auth.py`)

| Method | URL | Who | Body | Success | Errors |
| --- | --- | --- | --- | --- | --- |
| POST | `/api/auth/login` | anyone | `{identifier, password}` | `200 {token, user: {id, name, role}}` | `401 Invalid credentials` (same message for unknown user, wrong password, not activated, disabled) |
| POST | `/api/auth/activate` | anyone | `{identifier, new_password}` (8+ chars, max 72 bytes) | `200` same as login | `400 This account cannot be activated` (not on the roster, not a student, already has a password) |
| POST | `/api/auth/register` | anyone | `{name, identifier, section_id, password}` | `201` same as login | `400` bad roll-number format or unknown section, `409` already registered |
| GET | `/api/auth/me` | any user | | `200 {id, name, role}` | `401` |
| POST | `/api/auth/users` | admin | `{name, identifier, password, role}` (`role` is `teacher`, `hod` or `admin`) | `201 {id, name, role}` | `403`, `400` bad role, `409` identifier exists |

`identifier` is the login key: the roll number for students (for example `se-9244330`), and the staff identifier for staff. It is trimmed and lowercased before use. Send `Authorization: Bearer <token>` afterwards.

## 3. Swap requests (`swaps.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/swap-requests` | teacher (owner of the class) | body `{entry_id, target_entry_id, reason?}`. `201` returns the swap plus `warnings: [{type, entry_id, detail, severity}]` (soft conflicts do not block). `400` same class twice, `403` not your class, `404` entry missing, `409` hard clash (with `conflicts`) or a pending swap already exists |
| GET | `/api/swap-requests` | teacher, hod, admin | teacher: own requests; hod: pending requests of their department; admin: all pending |
| POST | `/api/swap-requests/{id}/approve` | hod (same department as the requester), admin | optional body `{note?}`. `403` not allowed to decide (including your own request), `404`, `409` already decided or a hard clash now |
| POST | `/api/swap-requests/{id}/reject` | hod (same department), admin | body `{note}` (required, not blank). Same errors as approve |
| POST | `/api/swap-requests/{id}/cancel` | teacher (the requester) | `403` not the requester, `409` already decided |

Swap object: `{id, requester_teacher_id, entry_id, target_entry_id, status (pending/approved/rejected/cancelled), reason, decided_by, decided_at, decision_note, created_at}`. An approved swap exchanges the day and times of the two entries; each keeps its own teacher, section and room.

## 4. Makeup classes (`makeup.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/makeup/find-slots` | teacher, hod, admin | body `{section_id, teacher_id, course_id, duration_minutes? (30-240, default 60), preferred_room_id?, days?, term?}`. Returns `[{day_of_week, start_time, end_time, recommended_room, fallback_rooms, soft_warnings}]`; room object `{room_id, room_name, capacity, room_type, building, is_preferred}`. `422` bad day or unknown section/teacher/course |
| POST | `/api/makeup` | teacher, hod, admin | body `{course_id, section_id, teacher_id, room_id, day_of_week, start_time, end_time, class_date?, reason?, term?}`. `201` returns the makeup. `404` something not found, `409` room too small or capacity unknown, wrong room type, or a clash, `422` end before start |
| GET | `/api/makeup` | any user | query: `section_id`, `teacher_id`. Only `scheduled` ones |

Makeup object: `{id, course_id, section_id, teacher_id, room_id, class_date, day_of_week, start_time, end_time, reason, status (scheduled/cancelled)}`.

## 5. Notifications (`notifications.py`)

Every call only sees the logged-in user's own notifications.

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/notifications` | any user | query: `unread_only` (default false), `limit` (1-200, default 50) |
| GET | `/api/notifications/unread-count` | any user | `{count}` |
| PATCH | `/api/notifications/{id}/read` | any user | `404` if missing or not yours |
| POST | `/api/notifications/read-all` | any user | `{updated}` |

Notification object: `{id, type (schedule_changed/swap_requested/swap_approved/swap_rejected/makeup_added), message, related_id, is_read, created_at}`.

## 6. Rooms and reference data (`rooms.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/rooms` | any user | query: `min_capacity`, `building`, `search`, `skip`, `limit` |
| GET | `/api/rooms/empty` | any user | query: `day`, `start`, `end` (all required), `min_capacity`. Rooms with no class overlapping `[start, end)` |
| GET | `/api/rooms/{id}` | any user | 404 if missing |
| POST | `/api/rooms` | hod, admin | body `{name, capacity, building?}`; 409 if name exists |
| PUT | `/api/rooms/{id}` | hod, admin | any subset of the body above |
| GET | `/api/departments` | any user | `[{id, name, code}]` |
| GET | `/api/courses` | any user | query: `department_id`, `search`, `skip`, `limit` |

Room object: `{id, name, capacity, building}`. `capacity` is `null` when unknown (rooms created by a timetable import); the makeup solver never uses such a room. Course object: `{id, code, title, credit_hours, department_id}`.

## 7. Timetable (`timetable.py`)

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

`level: "error"` rows are skipped; `"warning"` rows are kept. `rooms_created` lists rooms invented by the import: their capacity is **unknown (`null`)**, so set it with `PUT /api/rooms/{id}`. Re-importing the same file is safe (identical entries are skipped).

## 8. Exam repository (`exams.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/exam-papers` | teacher, hod, admin | multipart: `course_id`, `title`, `term`, `exam_type` (`quiz/midterm/final/sessional/other`), `year?`, `instructor_name?` (the given name, else the uploader's), `file` (pdf/png/jpg/doc/docx, max 20 MB) |
| GET | `/api/exam-papers` | any user | query: `course_id`, `term`, `exam_type`, `year`, `q`, `skip`, `limit` |
| GET | `/api/exam-papers/{id}` | any user | |
| GET | `/api/exam-papers/{id}/download` | any user | returns the file as an attachment |
| DELETE | `/api/exam-papers/{id}` | uploader, hod, admin | 204 |

Paper object: `{id, course_id, title, term, exam_type, year, instructor_name, original_filename, file_size, uploaded_by, created_at}`.

## 9. Lecture material archive (`materials.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/lecture-materials` | teacher, hod, admin | multipart: `course_id`, `title`, `material_type` (`notes/slides/assignment/other`), `instructor_name?` (the given name if not blank, else the uploader's), `term?`, `description?`, `file` (pdf/ppt/pptx/doc/docx/txt/zip/png/jpg) |
| GET | `/api/lecture-materials` | any user | query: `course_id`, `instructor_name`, `material_type`, `term`, `q`, `skip`, `limit` |
| GET | `/api/lecture-materials/by-course/{course_id}` | any user | `{course_id, course_code, course_title, instructors: [{instructor_name, materials: [...]}]}` |
| GET | `/api/lecture-materials/{id}` | any user | |
| GET | `/api/lecture-materials/{id}/download` | any user | returns the file as an attachment |
| DELETE | `/api/lecture-materials/{id}` | uploader, hod, admin | 204 |

## 10. GPA / CGPA (`gpa.py`)

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

## 11. Software directory (`software.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/software-resources` | any user | query: `course_id`, `category`, `q`, `skip`, `limit` |
| GET | `/api/software-resources/{id}` | any user | |
| POST | `/api/software-resources` | teacher, hod, admin | `{name, category?, description?, download_url (http/https), instructions?, course_id?}` |
| PUT | `/api/software-resources/{id}` | teacher, hod, admin | any subset |
| DELETE | `/api/software-resources/{id}` | teacher, hod, admin | 204 |

## 12. Event calendar (`events.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/events` | any user | query: `month` (`2026-10`), or `from_date`/`to_date`, plus `event_type`, `department_id` (also returns university-wide events) |
| GET | `/api/events/{id}` | any user | |
| POST | `/api/events` | hod, admin | `{title, event_type (deadline/event/holiday/department), description?, start_date, end_date?, location?, department_id?}` |
| PUT | `/api/events/{id}` | hod, admin | any subset |
| DELETE | `/api/events/{id}` | hod, admin | 204 |

An event that starts before the month but runs into it is included in that month.

## Open decisions (this file will change when they are settled)

These come from the backend review. Until each is decided and merged, the code may differ from what is written above.

- **Login key (C5):** the contract says `identifier` (roll number). The `users` model still has `email`.
- **Self-registration (M8):** `POST /api/auth/register` may be removed in favour of `activate` only.
- **HOD accounts (D2):** `POST /api/auth/users` will need `department_id` when `role` is `hod`.
- **Makeup booking (M4):** teachers will only be able to book for their own teacher record.
- **Makeup room type (M3):** whether lecture courses may use labs is still to be decided.
