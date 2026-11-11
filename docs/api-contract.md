# API contract

The single source of truth for every endpoint. If code and this file disagree, that is a bug: fix one of them in the same PR.
FastAPI's live docs at `/docs` can be used to check it.

**Pending tags.** Something marked *(pending BE-xx)* or *(lands with BE-xx)* is decided but not in `main` yet, so the code differs from this file until that PR merges. The PR that delivers it removes the tag.

## Rules for everyone

- **Auth:** every endpoint except `POST /api/auth/login`, `POST /api/auth/activate` and `GET /api/health` needs `Authorization: Bearer <JWT>`. Roles (lowercase): `student`, `teacher`, `hod`, `admin`. The role always comes from the database, never from the request. Tokens expire after 60 minutes; there is no refresh; on any `401` the frontend sends the user to the login page. All URLs are `{API_BASE}/api/...`.
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

There is **no public sign-up**. A student claims a roster account with `activate`; staff accounts are created by an admin with `POST /api/auth/users`.

| Method | URL | Who | Body | Success | Errors |
| --- | --- | --- | --- | --- | --- |
| POST | `/api/auth/login` | anyone | `{identifier, password}` | `200 {token, user}` (user object below) | `401 Invalid credentials` (same message for unknown user, wrong password, not activated, disabled) |
| POST | `/api/auth/activate` | anyone | `{identifier, new_password}` (8+ chars, max 72 bytes) | `200` same as login | `400 This account cannot be activated` (not on the roster, not a student, already has a password) |
| GET | `/api/auth/me` | any user | | `200` user object | `401` |
| POST | `/api/auth/users` | admin | `{name, identifier, password, role, department_id}`: `role` is `teacher`, `hod` or `admin`; `department_id` is **required when `role` is `hod`** *(lands with BE-07)* | `201` user object | `403`, `400` bad role, `400` `hod` without `department_id` or unknown department *(lands with BE-07)*, `409` identifier exists |

User object (login, activate, `me` and create-user all return it): `{id, name, role, identifier, department_id, section_id, teacher_id}`.

- `section_id`: the student's section; `null` for everyone else.
- `department_id`: the HOD's department (an HOD who has none cannot approve swaps); `null` if the user has none.
- `teacher_id`: the user's teacher record, for `teacher` and `hod`; `null` for students and admins. *(pending BE-04: `identifier`, `department_id` and `section_id` are already returned; `teacher_id` is not yet.)* The frontend needs it for `find-slots` and `POST /api/makeup` (section 4), because the teacher id is **not** the same as the user id.

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
| POST | `/api/makeup/find-slots` | teacher, hod, admin | body `{section_id, teacher_id, course_id, duration_minutes? (30-240, default 60), preferred_room_id?, days?, term?}`. Returns `[{day_of_week, start_time, end_time, recommended_room, fallback_rooms, soft_warnings}]`; room object `{room_id, room_name, capacity, room_type, building, is_preferred}`. `403` `teacher_id` is not yours to book for *(lands with BE-08)*, `422` bad day or unknown section/teacher/course |
| POST | `/api/makeup` | teacher, hod, admin | body `{course_id, section_id, teacher_id, room_id, day_of_week, start_time, end_time, class_date?, reason?, term?}`. `201` returns the makeup. `403` `teacher_id` is not yours to book for *(lands with BE-08)*, `404` something not found, `409` room too small or capacity unknown, wrong room type, or a clash, `422` end before start |
| GET | `/api/makeup` | any user | query: `section_id`, `teacher_id`. Only `scheduled` ones |

Makeup object: `{id, course_id, section_id, teacher_id, room_id, class_date, day_of_week, start_time, end_time, reason, status (scheduled/cancelled)}`.

Rules *(land with BE-08)*:

- **Who may book:** a teacher only for their own teacher record; an HOD for teachers of their own department; an admin for anyone. Otherwise `403 You can only book makeup classes for yourself`. This applies to `find-slots` and to `POST /api/makeup`.
- **Room type:** a lab course needs a lab room, and a lecture course never gets a lab room (labs are scarce). A wrong type is a `409` on booking, and `find-slots` never offers one.
- **No double booking:** if two requests try the same room at the same day and start time, the second gets `409 This room was just booked for that time. Search again for a free slot.`

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
| GET | `/api/rooms/empty` | any user | query: `day`, `start`, `end` (all required), `min_capacity`. Rooms with no class overlapping `[start, end)`, smallest first, rooms with unknown capacity last *(lands with BE-18)* |
| GET | `/api/rooms/{id}` | any user | 404 if missing |
| POST | `/api/rooms` | hod, admin | body `{name, capacity, building?}`; 409 if name exists |
| PUT | `/api/rooms/{id}` | hod, admin | any subset of the body above |
| GET | `/api/departments` | any user | `[{id, name, code}]` |
| GET | `/api/courses` | any user | query: `department_id`, `search`, `skip`, `limit` |
| GET | `/api/sections` | any user | query: `department_id`. Returns `[{id, name, department_id, student_count}]`. Lets the frontend offer "pick a section" *(pending BE-04)* |

Room object: `{id, name, capacity, building}`. `capacity` is `null` when unknown (rooms created by a timetable import) *(lands with BE-18)*; the makeup solver never uses such a room. Course object: `{id, code, title, credit_hours, department_id}`.

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

`level: "error"` rows are skipped; `"warning"` rows are kept. `rooms_created` lists rooms invented by the import: their capacity is **unknown (`null`)** *(lands with BE-18; until then the import writes 40)*, so set it with `PUT /api/rooms/{id}`. Re-importing the same file is safe (identical entries are skipped).

## 8. Exam repository (`exams.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/exam-papers` | teacher, hod, admin | multipart: `course_id`, `title`, `term`, `exam_type` (`quiz/midterm/final/sessional/other`), `year?`, `instructor_name?` (the given name, else the uploader's *(lands with BE-03)*), `file` (pdf/png/jpg/doc/docx, max 20 MB) |
| GET | `/api/exam-papers` | any user | query: `course_id`, `term`, `exam_type`, `year`, `q`, `skip`, `limit` |
| GET | `/api/exam-papers/{id}` | any user | |
| GET | `/api/exam-papers/{id}/download` | any user | returns the file as an attachment |
| DELETE | `/api/exam-papers/{id}` | uploader, hod, admin | 204 |

Paper object: `{id, course_id, title, term, exam_type, year, instructor_name, original_filename, file_size, uploaded_by, created_at}`.

Uploads (here and in section 9): the extension must be allowed (`400`), the file must not be empty (`400`) or over the size limit (`413`), and the file's real content must match its extension, so a text file renamed `.pdf` is refused with `400 File content does not match its .pdf extension` *(lands with BE-14)*.

## 9. Lecture material archive (`materials.py`)

| Method | URL | Who | Notes |
| --- | --- | --- | --- |
| POST | `/api/lecture-materials` | teacher, hod, admin | multipart: `course_id`, `title`, `material_type` (`notes/slides/assignment/other`), `instructor_name?` (the given name if not blank, else the uploader's *(lands with BE-03)*), `term?`, `description?`, `file` (pdf/ppt/pptx/doc/docx/txt/zip/png/jpg) |
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
| PUT | `/api/events/{id}` | hod, admin | any subset *(lands with BE-03; the code still says `PATCH` until then)* |
| DELETE | `/api/events/{id}` | hod, admin | 204 |

An event that starts before the month but runs into it is included in that month.
