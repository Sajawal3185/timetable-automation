# Frontend: NUML Archive + Timetable Automation

Plain HTML, CSS and JavaScript. No build step.

```bash
cd frontend
python -m http.server 8000
```

Open <http://localhost:8000/html/index.html>. Use `localhost`, not a LAN IP:
mock login hashes passwords with `crypto.subtle`, which browsers only allow on
`https://` or `localhost`.

## Two systems, two sessions

| | NUML Archive | Timetable Automation |
|---|---|---|
| Pages | `html/*.html` | `html/timetable/*.html` |
| Scripts | `js/common.js` + page scripts | `js/timetable/*.js` (never loads `common.js`) |
| Login | email (demo only) | roll number / staff ID (`identifier`) + password |
| Browser storage | `numlUser` | `ttAuthToken` (the JWT only, no profile) |

The landing page (`index.html`) and the Archive dashboard each have one link to
`html/timetable/timetable-login.html`.

## Timetable pages and who may open them

| Page | student | teacher | hod | admin |
|---|:-:|:-:|:-:|:-:|
| `timetable-login.html` | public | public | public | public |
| `timetable.html` (week, Mon to Sun) | start | start | yes | no |
| `swaps.html` (request a swap) | no | yes | no (API is teacher-only) | no |
| `swap-approvals.html` (approve/reject) | no | no | start | yes |
| `makeup.html` (find slots, book) | no | yes | yes | no |
| `admin.html` (link to backend `/docs`) | no | no | no | start |

Every page calls `TT.init({ allowed: [...] })` (`js/timetable/tt-layout.js`):
no token goes to the login, `GET /api/auth/me` decides the role, and a role that
is not allowed gets a refusal card without the page's data ever being loaded.
This only decides what is shown. The backend's 403 is what protects data.

## Mock mode vs real backend

One switch: `API_BASE` in `js/timetable/tt-config.js`.

- `""` (default): **mock mode**. `js/timetable/tt-mock.js` answers requests
  from `mock/db/*.json`.
- `"http://localhost:8000"` (or the deployed URL): every request goes to the
  FastAPI backend with `Authorization: Bearer <token>`. CORS must allow the
  frontend's origin.

### Mock mode is a stand-in, not security

It runs in the browser, so anyone can change it. It exists so the UI can be
built and demoed before the backend runs. It still behaves like the API:

- Passwords are checked against `mock/db/users.json` (SHA-256 of
  `identifier:password`). A wrong password returns **401** and shows an error.
- Tokens are random and only valid in the mock's session table. A value typed
  into `localStorage` by hand gets a 401 and the user is sent to the login.
- Role checks, status codes and error texts are copied from
  `backend/app/routers/`. Responses are cut down to the Pydantic schema fields.
- Changes (swaps, approvals, bookings, read notifications) are kept in
  `sessionStorage` for the current tab. Close the tab to reset the demo.

### Demo accounts (mock mode only)

| Role | Roll number / Staff ID | Password | Notes |
|---|---|---|---|
| student | `Se-9244330` | `student123` | section #1 (BSSE-5A) |
| student | `cs-9255101` | `student123` | section #3, CS department |
| teacher | `tr-100201` | `teacher123` | SE department, teacher #1 |
| teacher | `tr-200401` | `teacher123` | CS department, teacher #4 |
| hod | `hod-100301` | `hodpass123` | SE department, also teacher #2 |
| admin | `adm-100001` | `adminpass1` | |

Try: as `tr-100201`, swap *Mon 08:30 SE-311* with *Mon 10:00 SE-312* (409:
teacher clash), then *Wed 10:00 SE-311* with *Wed 08:30 SE-315* (pending). Log
in as `hod-100301` and approve it; the teacher's week changes and the bell
shows "approved".

## Backend gaps the frontend works around

Found while reading `backend/app/` (8 Nov 2026). The frontend follows the code,
not the docs, and these need backend changes before real mode works:

1. **`/api/auth/me` returns only `{id, name, role}`.** The timetable page needs
   the student's `section_id` and the teacher's `teacher_id` (and swaps use
   `department_id`). Mock mode adds these three fields; in real mode the pages
   show "your account is not linked" until `UserOut` includes them.
2. **The backend cannot start yet:** `app/main.py` is empty, `app/config.py`,
   `app/dependencies.py` and the top-level `database` module do not exist,
   and `get_current_user` is not defined anywhere.
3. `User` has `email` but no `identifier` column, while `routers/auth.py`
   queries `User.identifier`.
4. `swap_service.py` and `conflict_checker.py` use `entry.day`; the model
   column is `day_of_week`. `conflict_checker` reads
   `scheduler_rules.HARD_CONFLICT_TYPES`, which does not exist.
5. No `/api/sections` or `/api/teachers` endpoint, so sections and teachers are
   shown as `Section #id` / `Teacher #id`.
6. `POST /api/swap-requests` is teacher-only, so an HOD cannot request a swap.
7. A swap exchanges the times of two existing classes; there is no "move to a
   new day/time/room" request.
8. Swap notifications go to both teachers and both sections' students, not to
   the HOD who must approve. Booking a makeup sends no notification
   (`recipients_for_makeup` is never called).
9. The makeup solver sorts rooms by capacity then name, so a lab can be the
   "recommended" room for a lecture course.
