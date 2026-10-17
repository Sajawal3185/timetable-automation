# Wajiha's Backend Work: Timetable Automation & Student Portal

Source: *V-4 Timetable Automation Proposal and Team Guide*, Part A section 1.4 ("Backend 3: Wajiha"), plus the shared-pieces table (1.1) and the folder-ownership table (11.1).
Everything in this package is **only your share**. Nothing here is Sajawal's, Taha's, Amna's or Eisha's work.

---

## 1. What the document says is yours, and where it is now

| Your task in the document | File(s) | Status |
| --- | --- | --- |
| **Timetable import**: read department sheets into one common dataset; start with SE, then check AI, CS, IT | `services/timetable_import.py`, `routers/timetable.py` | Built and tested on invented sheets. **You must check it against the real sheets** (section 3, item 2) |
| **Rooms and reference data**: rooms, capacities, departments, courses endpoints (needed by the empty-room dashboard and the makeup solver) | `routers/rooms.py` | Done and tested |
| **Exam repository**: upload, search, filter by course and term, download | `routers/exams.py`, `models/exam_paper.py`, `utils/file_storage.py` | Done and tested |
| **Lecture material archive**: notes/slides from different instructors for the same course | `routers/materials.py`, `models/lecture_material.py` | Done and tested |
| **GPA / CGPA module**: term GPA and CGPA from grade inputs, with tests for the formulas | `services/gpa_calculator.py`, `routers/gpa.py`, `models/grade_record.py`, `tests/test_gpa_calculator.py` | Done and tested. **Grade scale needs your confirmation** (section 3, item 1) |
| **Software resource directory** | `routers/software.py`, `models/software_resource.py` | Done and tested |
| **Event calendar** | `routers/events.py`, `models/event.py` | Done and tested |
| **Your tables**: exam_paper, lecture_material, grade_record, software_resource, event | `models/*.py` | Done |
| **Postman requests and tests for your own endpoints** (shared-pieces table) | `docs/postman/wajiha.postman_collection.json`, `tests/` | Done: 33 requests, 111 automated tests |
| **API contract before building** (section 1.7) | `docs/api-contract-wajiha.md` | Ready to paste into `docs/api-contract.md` |

Supporting files I added that are also yours: `schemas/portal.py` (request/response shapes), `routers/portal_deps.py` (a small role-check adapter), `tests/conftest.py`, `tests/helpers.py`, and `data/sample_timetables/` (invented sheets).

The document's advice for you: *"most are simple, so your work is large in volume and medium in difficulty"*, and *"the timetable engine is the critical path: start the rules, checker and import in the first days, not after the portal is done."* So do the **import and rooms first** (see the order in section 6).

## 2. Folder map (matches section 11 of the document)

```
backend/
├── requirements-wajiha.txt        <- merge these lines into requirements.txt
├── app/
│   ├── models/    exam_paper.py  lecture_material.py  grade_record.py  software_resource.py  event.py
│   ├── schemas/   portal.py
│   ├── routers/   timetable.py  rooms.py  exams.py  materials.py  gpa.py  software.py  events.py
│   │              portal_deps.py   (temporary adapter, see 3.4)
│   ├── services/  timetable_import.py  gpa_calculator.py
│   └── utils/     file_storage.py
├── data/sample_timetables/        sample_SE_flat.xlsx  sample_AI_grid.xlsx  (invented data)
└── tests/         conftest.py helpers.py test_gpa_calculator.py test_timetable_import.py
                   test_rooms_api.py test_timetable_api.py test_exams_api.py test_materials_api.py
                   test_gpa_api.py test_software_api.py test_events_api.py test_openapi.py
docs/
├── api-contract-wajiha.md
└── postman/wajiha.postman_collection.json
LOCAL_TESTING_STUBS/               temporary stand-ins for Sajawal's files. NEVER commit these.
```

## 3. What I had to assume (please check each one)

I could not see Sajawal's models, Taha's permissions, or the real timetable sheets, so these are assumptions. They are in one place each, so each is a small fix.

1. **Grade scale (most important).** `GRADE_POINTS` at the top of `services/gpa_calculator.py` uses a common 4.0 scale: A 4.00, A- 3.67, B+ 3.33, B 3.00, B- 2.67, C+ 2.33, C 2.00, C- 1.67, D+ 1.33, D 1.00, F 0.00. I searched for NUML's official grading table and did **not** find it, and universities differ a lot. The document says "using the university's grading rules", so get the real table from your department or the NUML student handbook and edit **only that dictionary**. If NUML grades by marks (for example 85+ = A), tell me and I will add a marks-to-grade step. Also decide the **repeated-course rule** (`all`, `latest`, `best`) and ask your teacher which one NUML uses.
2. **Real timetable sheets.** Nobody has shared the actual SE/AI/CS/IT files. The importer recognises two common layouts (one row per class, or a day-by-time grid with "Course / Teacher / Room" in each cell) and reports clearly when a sheet is not understood. Run it with `dry_run=true` on each real file. If something is not recognised, the usual fixes are: add a header spelling to `HEADER_ALIASES`, add a sheet name to `skip_sheets` in `DEPARTMENT_CONFIG`, or tweak `parse_cell()`. Start with SE (the known consistent format), then AI, CS, IT, as the document says.
3. **Sajawal's tables.** I assumed these columns (from his list in the document): `Room(id, name, capacity, building)`, `Course(id, code, title, credit_hours, department_id)`, `Department(id, name, code)`, `Section(id, name, department_id)`, `Teacher(id, name, department_id)`, `User(id, name, role)`, and `TimetableEntry(id, section_id, course_id, teacher_id, room_id, day_of_week, start_time, end_time)`, with `day_of_week` stored as `"Monday"`. If his names differ, change them in `rooms.py`, `timetable.py` and `timetable_import.load_rows()` (that last function is the only place that creates rows in his tables). **Ask him to confirm the `timetable_entry` column names early.** Your import and Taha's conflict checker both depend on them.
4. **Roles and auth.** `routers/portal_deps.py` re-exports `get_current_user` from `core/security.py` and defines `require_roles(...)`. When Taha merges `core/permissions.py`, swap `require_roles` for his version. Roles used: `student`, `teacher`, `hod`, `admin`. Rules I chose: teachers/HOD/admin upload papers, materials and software; only HOD/admin manage events, rooms and timetable imports; students can read everything and manage only their own saved grades.
5. **New rooms from an import.** If a sheet names a room that is not in the database, the importer creates it with a placeholder capacity of 40 (`DEFAULT_NEW_ROOM_CAPACITY`) and lists it in `rooms_created`. Fix those capacities with `PUT /api/rooms/{id}`, because Sajawal's makeup solver relies on capacities.
6. **Class clashes are not checked here.** Teacher/room/section double-booking is Taha's `conflict_checker.py`. Your import only checks that each row makes sense on its own. After importing, ask Taha's checker to scan the data.

## 4. Run it and test it

You can run all 111 tests **today**, without MySQL and before Sajawal merges his files:

```bash
cd backend
python -m venv venv
venv\Scripts\activate                       # Windows
pip install -r requirements-wajiha.txt
```

Copy the contents of `LOCAL_TESTING_STUBS/backend/app/` into `backend/app/` **only if his real files are not in the repo yet** (do not overwrite his real files, and do not commit the stubs). Then:

```bash
python -m pytest tests -q          # expect: 111 passed
```

Once his real models are merged, delete the stubs and run again. If a test fails because his models need extra required columns (for example a password hash on `User`), fix it in the `seed` fixture in `tests/conftest.py`.

**Registering your pieces.** In `app/main.py` (a shared "conflict hotspot": announce it in the group chat and keep edits tiny):

```python
from app.routers import timetable, rooms, exams, materials, gpa, software, events
app.include_router(timetable.router)
app.include_router(rooms.router)
app.include_router(exams.router)
app.include_router(materials.router)
app.include_router(gpa.router)
app.include_router(software.router)
app.include_router(events.router)
```

Also make sure your five model modules are imported wherever tables are created (for example in `models/__init__.py`), otherwise `create_all` will not see them.

**Config lines to add:**
- `.gitignore`: `uploads/`
- `.env.example`: `UPLOAD_DIR=uploads` and `MAX_UPLOAD_MB=20`
- `requirements.txt`: the lines from `requirements-wajiha.txt` (merge, do not replace: it is a conflict hotspot)

**Manual check in the browser:** run `uvicorn app.main:app --reload` and open `http://127.0.0.1:8000/docs`. Your endpoints are grouped under Timetable, Rooms & Reference Data, Exam Repository, Lecture Material Archive, GPA / CGPA, Software Directory and Event Calendar. The team rule says backend PRs must show the endpoint tested in Postman or `/docs`, so take a screenshot per PR. Import `docs/postman/wajiha.postman_collection.json` into Postman, then set `base_url` and `token`. Do not save a real token inside the file.

## 5. How each module works (so you can explain it in the viva or demo)

- **Timetable import** has three separate stages: *parse* (file to rows), *validate* (drop impossible rows and duplicates), *load* (write to the database). `dry_run=true` is the default: the admin sees a preview and a list of problems first, and only a second call with `dry_run=false` saves anything. The save runs in **one transaction**: if anything fails halfway, everything rolls back. That is the same idea as proposal section 3.2. Re-importing the same sheet is safe because identical entries are skipped.
- **Class times without am/pm.** `1:00 - 2:30` is read as 13:00 to 14:30, because classes run roughly 08:00 to 19:00, so hours 1 to 7 must be afternoon.
- **Empty rooms** use the standard overlap rule: a room is busy if an existing class *starts before the requested end* **and** *ends after the requested start*. So a class ending at 11:00 does not block a request starting at 11:00 (tested at both edges).
- **File uploads** are stored under random UUID names inside `UPLOAD_DIR`. The database keeps only a relative path, and the original name is only used for the download. This stops two people overwriting each other and stops a file called `../../etc/passwd.pdf` from escaping the folder. Extensions are whitelisted (papers: pdf, images, doc/docx; materials also ppt/pptx/txt/zip), size is capped at 20 MB, and the file is removed if the database save fails.
- **GPA** is `sum(credit_hours x grade_points) / sum(credit_hours)`. **CGPA is not the average of term GPAs**: it weights every credit hour equally. Example: 3 credits of A (4.0) plus 6 credits of C (2.0) gives (12 + 12) / 9 = **2.67**, while averaging 4.0 and 2.0 would wrongly give 3.0. Rounding is half-up (3.125 becomes 3.13) using `Decimal`, because Python's plain `round()` uses banker's rounding. W, I, P, NC and AUD are left out of GPA; F counts as 0 points but still counts its credit hours. The "previous CGPA + credit hours" option lets a student plan: "what if I get an A in my next 3 credits?"
- **Saved grades are private**: you can only list or delete your own. Deleting someone else's record answers 404 (not 403), so ids are not leaked.
- **Events** include anything that overlaps the month, so a holiday running 28 Dec to 5 Jan shows in both December and January. Filtering by department also returns university-wide events (those with no department).

## 6. Suggested order of work and pull requests

The team rules (Part B, section 9): one branch per small task, branch names `type/area-short-description`, PRs ideally under about 300 changed lines, a filled PR template, one reviewer who is **another backend member** (Taha or Sajawal), **Squash and merge**, then delete the branch and start the next one from fresh `main`. Merge `main` into your branch every morning.

| # | Branch | Contents | Needs first |
| --- | --- | --- | --- |
| 1 | `docs/api-contract-wajiha` | Add `api-contract-wajiha.md` to `docs/api-contract.md`. A contract PR needs **one backend and one frontend** reviewer (for example Taha and Eisha) | nothing |
| 2 | `chore/backend-portal-foundation` | 5 models, `schemas/portal.py`, `portal_deps.py`, `tests/conftest.py`, `tests/helpers.py` (mostly declarative; say so in the PR) | Sajawal's `database.py` and core models |
| 3 | `feat/backend-rooms-reference` | `rooms.py`, `test_rooms_api.py` | #2 |
| 4 | `feat/backend-timetable-parser` | `timetable_import.py`, `test_timetable_import.py`, sample sheets | #2 |
| 5 | `feat/backend-timetable-import-api` | `timetable.py`, `test_timetable_api.py` | #3, #4 |
| 6 | `feat/backend-gpa-calculator` | `gpa_calculator.py`, `test_gpa_calculator.py` | nothing (pure Python) |
| 7 | `feat/backend-gpa-api` | `gpa.py`, `test_gpa_api.py` | #2, #6 |
| 8 | `feat/backend-exam-repository` | `file_storage.py`, `exams.py`, `test_exams_api.py` | #2 |
| 9 | `feat/backend-lecture-materials` | `materials.py`, `test_materials_api.py` | #8 |
| 10 | `feat/backend-software-directory` | `software.py`, `test_software_api.py` | #2 |
| 11 | `feat/backend-event-calendar` | `events.py`, `test_events_api.py` | #2 |
| 12 | `test/backend-wajiha-postman` | the Postman collection, `test_openapi.py` | the rest |

Good commit messages are imperative and specific, for example `Add overlap check to empty rooms query`. A ready PR description:

```
## What does this PR do?
Adds the GPA/CGPA calculator with credit-weighted term and cumulative formulas.

## How was it tested?
pytest tests/test_gpa_calculator.py (26 tests); /docs screenshot attached.

## Checklist
- [x] Branch is up to date with main
- [x] No secrets, .env files, or large data files included
- [x] I read my own diff before opening this PR
```

**Week-one rule (section 1.8):** after the first week, tell the group what is finished and what is blocked. If you are clearly ahead or behind, a whole module can move to someone else.

## 7. Things that are NOT yours (do not edit)

Core tables (user, teacher, section, course, room, department, timetable_entry), login/JWT, scheduling rules, the makeup solver, and deployment belong to Sajawal. Permissions, the conflict checker, swaps with locking, and notifications belong to Taha. All React screens belong to Amna and Eisha. If you need a change in a file you do not own, propose it in a PR the owner reviews (section 11.1).

## 8. Honest limits of this package

- Tests run on SQLite so they work anywhere. The queries are plain SQLAlchemy and should behave the same on MySQL, but run the suite and a few Postman calls against your real MySQL once before the demo.
- The importer was tested on sheets I invented, not the real NUML files.
- The grade table is a standard scale, not a verified NUML one.
- Authentication in the tests is faked on purpose; test the real JWT flow in Postman after Sajawal merges login.
- No frontend work is included (that is Amna's and Eisha's part). The API contract tells them exactly what to call.
