# Release Plan: Automated Timetable & Student Portal
Written: Saturday 7 November 2026 · Hand-in: Sunday 15 November 2026 · Plan owner: Sajawal


**How to read this**
- **Everyone:** read Parts 1 to 6 (about 15 minutes), then **your own page in Part 7**. Your page alone tells you what to do each morning.
- **Sajawal:** also read the Appendix (the technical version). The team part does not depend on it.

---

## Part 1. The short version

1. **Can we finish by 15 November? Yes, but only if we build less than the proposal promised.**
2. We have about **91 hours of real working time** as a team. The full original plan needs about **130 hours**, so we are **39 hours short**. About 30% of the remaining work has to be dropped.
3. **What we keep:** the timetable system working on the internet. That means login for students, teachers, HODs and admins, each person's timetable, class swaps with an HOD approval step, finding and booking makeup classes, and notifications.
4. **What waits:** the student-portal screens (past papers, lecture slides, GPA, software list, events). Their backend already works and can be shown through the API's built-in test page.
5. **Biggest risk:** nobody has written code for 14 days. Two members have almost no code in the project yet. **Second biggest:** the code has never run on the real database type (MySQL), only on a test one.

### What this plan assumes
- Each person works **3 hours a day**, from 8 to 15 November. All five members are available. (You told me both of these.)
- **15 November is for handing in only.** Its hours are kept spare, not planned.
- About **0.4 hours per person per day** goes to check-ins, reviewing others' work and fixing merge issues.
- The frontend stays as **plain HTML and JavaScript**. React is not required (you told me).
- I made the time estimates myself, by reading the code. They are not padded in secret. Every estimate is listed in the Appendix.
- I could not check whether the free hosting websites have changed their free plans. That gets checked on 9 November.

---

## Part 2. Glossary (words used in this plan)

| Term | What it means | Why it matters to us |
|---|---|---|
| **Repo (repository)** | The project folder on GitHub that holds all our code and its history. | It is our single source of truth. Code sent on WhatsApp doesn't count. |
| **Branch** | Your own copy of the code, where you work without disturbing others. | You never work directly on `main`. |
| **main** | The official branch. Only reviewed work goes into it. | What is on `main` is what gets put online. |
| **PR (pull request)** | A request to add your branch's changes to `main`. | It is the only way code reaches `main`. |
| **Review / approval** | A teammate reads your PR, runs it, and clicks Approve. | `main` is locked until one other person approves. |
| **Squash merge** | Your PR is added to `main` as one single change. | It keeps history clean. Delete your branch afterwards and start a new one. |
| **Merge conflict** | Two people changed the same lines and Git can't choose. | This is why some files have one owner (see Rule 4). |
| **Backend** | The server code (Python, FastAPI) that stores data and enforces the rules. | It is what Sajawal, Wajiha and Taha build. |
| **Frontend** | The web pages people click on (HTML, CSS, JavaScript). | It is what Amna and Eisha build. |
| **API** | The set of web addresses the frontend calls to get or send data. | It connects frontend and backend. |
| **Endpoint** | One API address, for example `POST /api/auth/login`. | Each screen uses a few of these. |
| **API contract** | One document listing every endpoint: its address, what you send, and what you get back. | Frontend and backend build to the same document, so they fit together. |
| **Mock data** | Fake sample data in files, shaped exactly like the real API's answers. | Frontend work can start before the backend is ready. |
| **FastAPI** | The Python tool our backend is built with. | It also gives us `/docs` for free. |
| **/docs** | A web page that FastAPI generates automatically, where you can try every endpoint. | You can test without the frontend, and use it to show portal features in the demo. |
| **Postman** | An app for sending test requests to the API. | It is our saved set of example requests. |
| **JWT (token)** | A signed "ticket" the server gives you when you log in. The browser sends it with every request. | It is how the server knows who you are. Without it, anyone could pretend to be logged in. |
| **Role** | What kind of user you are: student, teacher, HOD or admin. | It decides what each person is allowed to do. |
| **HOD** | Head of Department. | Approves swap requests for their own department. |
| **Swap** | A teacher asks to move a class to another time or room. | It is a core feature, and HOD approval is required. |
| **Makeup class** | An extra class booked to replace a missed one. | It is a core feature. The system finds free times and rooms. |
| **Conflict check** | Code that checks whether a teacher, a section or a room is already busy at that time. | It stops double-bookings. |
| **Row lock** (`with_for_update` in code) | While one person is saving a booking, the database makes others wait. | It stops two people booking the same room at the same moment. |
| **Database** | Where all data is stored. | We use **MySQL** online. |
| **SQLite** | A small test database that lives in one file or in memory. | Our tests use it. It behaves differently from MySQL, so passing tests here does not prove the code works online. |
| **Migration / Alembic** | Alembic is a tool that creates and updates database tables from our code, step by step. | Everyone, and the server, gets exactly the same tables. |
| **Seed data** | Realistic sample data loaded into the database (rooms, teachers, a timetable, test accounts). | Without it, every screen is empty and the demo is impossible. |
| **Test / pytest** | Code that checks other code automatically. `pytest` runs all our tests. | It catches bugs before they reach `main`. |
| **CI (continuous integration)** | GitHub runs all tests automatically on every PR. | A PR with red (failing) tests can't be merged. This would have caught the bugs that are on `main` now. |
| **PR template** | A checklist GitHub adds to every new PR automatically. | Nobody forgets "tests pass, no secrets". |
| **Deploy / deployment** | Putting our app on a public server so anyone can open it. | "Works on my laptop" is not enough to hand in. |
| **Staging** | The online copy of the app where we test before handing in. | We find "only breaks online" bugs early. |
| **Render** | Free hosting website for our backend. | Runs our Python server. |
| **Aiven** | Free hosting website for our MySQL database. | Stores our data online. |
| **TiDB** | Another free database host that behaves like MySQL. | Our backup if Aiven fails. |
| **Netlify** | Free hosting website for our frontend pages. | Serves our HTML/JS pages. |
| **Environment variable / `.env`** | A setting (like the database password) given to the app from outside the code. | Passwords never go into the repo. They are set on the host website. |
| **Secret** | Any password or key. | If one is ever committed to Git, it is leaked forever and must be changed. |
| **CORS** | A browser safety rule. The backend must list which websites may call it. | If it is set wrong, the frontend shows errors online even though it works locally. |
| **Cold start** | Free Render servers fall asleep when unused and take 30 to 60 seconds to wake up. | Open the site 2 minutes before the demo. |
| **Temporary disk** | Files saved on a free Render server are deleted when it restarts. | Uploaded papers can vanish, so we don't demo uploads unless they are done live. |
| **Health check** (`/api/health`) | An endpoint that just answers "OK". | It proves the server is up and wakes it before the demo. |
| **Status codes** 401 / 403 / 404 / 409 / 500 | 401 = not logged in, 403 = not allowed, 404 = not found, 409 = clash or duplicate, 500 = server crashed. | The frontend shows a message for each one. A 500 is always a bug. |
| **Smoke test** | A quick run through the main steps to check nothing is badly broken. | We do it after every deploy. |
| **Person-hour (ph)** | One person working for one hour. | All our capacity maths uses this unit. |
| **Capacity** | Total hours the team can really work. | It decides how much we can build. |
| **Buffer** | Spare hours kept for surprises. | Things always take longer than planned. |
| **Estimate (best / likely / worst)** | Three guesses per task: if all goes well, normally, if it goes badly. | We plan on "likely" and watch "worst" for risk. |
| **Task list (WBS)** | The full list of tasks, each 1 to 9 hours. WBS stands for Work Breakdown Structure. | Small tasks are easy to track and to hand over. |
| **Dependency** | Task B can't start until task A is finished. | Waiting on others is where time is lost. |
| **Critical path** | The chain of dependent tasks that decides the finish date. | If any task on it is late, the whole project is late. |
| **MoSCoW** | Sorting features into **M**ust have, **S**hould have, **C**ould have and **W**on't have this time. | It decides what gets dropped first. |
| **Cut line** | The line between what we build and what we drop. | It is drawn so the Musts fit our hours plus a buffer. |
| **Feature freeze** | A date after which no new features are added, only bug fixes. | Ours is **14 November**. |
| **Trigger (cut trigger)** | A rule agreed in advance: "if X is late by this time, we automatically do Y". | No arguing when we're behind. The decision is already made. |
| **Review time limit (SLA)** | A promise: every PR gets reviewed within 4 waking hours. SLA stands for Service Level Agreement. | A slow review blocks the next person. |
| **Risk register** | A list of things that could go wrong, how likely they are, and our plan for each. | We spot trouble early instead of on the last day. |
| **Rebase** | Updating your branch with the newest `main` before merging. | Fewer conflicts. |

---

## Part 3. Task codes (legend)

Codes are short labels. Every code is always written with its name in this plan.

| Code | Name | Code | Name |
|---|---|---|---|
| **B-1** | Make the backend start without errors | **F-1** | Clean up the frontend and bring it into `main` |
| **B-2** | Fix broken import paths in 12 files | **F-2** | One shared menu/layout on every page |
| **B-3** | Fix the 2 failing portal bugs (events update, instructor name) | **F-3** | Real login on the frontend (API helper + login, logout, roles) |
| **B-4** | Fix login and user accounts | **F-4** | Timetable page (weekly view) |
| **B-5** | Write the single API contract | **F-5** | Swap requests page (teacher asks, HOD decides) |
| **B-6** | Fix the swap feature and the conflict checker | **F-6** | Makeup class page (find a free slot and book it) |
| **B-7** | Let each HOD approve swaps for their own department | **F-7** | Notification bell |
| **B-8** | Make makeup booking safe (right rooms, owner check, no double booking) | **X-1** | Postman requests for the demo |
| **B-9** | Test notifications | **X-2** | Swaps also check against booked makeup classes |
| **B-10** | Create database tables with migrations, on real MySQL | **X-3** | Empty rooms page |
| **B-11** | Fill the database with sample data (seed) | **X-4** | Past papers and lecture slides pages using real data |
| **B-12** | Automatic tests on every PR (CI) + PR template | **X-5** | Activate account page |
| **D-1** | First deploy: "hello world" online | **X-6** | Safety fixes: room capacity, file type check, login attempt limit |
| **D-2** | Full deploy with real tables and sample data | **X-7** | Run CI tests on MySQL too |
| **D-3** | Write the README (how to run and deploy) | **X-8** | Check import of the real AI/CS/IT timetable sheets |
| **C-1** | GPA page using real data | **C-4** | Code style tools, folder renames, ownership file |
| **C-2** | Software list and events pages using real data | **C-5** | Password reset, live push, accessibility pass (**Won't do**) |
| **C-3** | Remove duplicate role-check code |  |  |

Letters: **B** = backend, **F** = frontend, **D** = deployment, **X** = Should have, **C** = Could have.
**T1 to T7** are the automatic cut triggers (Part 4, Rule 6). **R1 to R8** are risks (Part 6).

---

## Part 4. The rules, in plain words

### Rule 1. What "done" means (one definition for everyone)
A task is **done** only when all four are true:
1. It is **merged into `main`** through an approved PR.
2. The **automatic tests are green**, and the task has its own test if it is backend work.
3. **It works on the online staging site**, not only on your laptop. (Before staging exists on 9 November, "works locally" is accepted.)
4. If it changed an endpoint, the **API contract is updated**.

"I pushed it", "it works on my machine" and "PR is open" mean **not done**.

### Rule 2. Why feature freeze is 14 November
Every new feature can break something that already worked. The demo must run **twice in a row without errors** before we hand in. If we keep adding features until 15 November, there is no time left to find and fix what they break. So from 14 November we only fix bugs and rehearse. 15 November is kept empty on purpose as a safety day.

### Rule 3. Why a review must happen within 4 waking hours (not 24)
The guide says 24 hours. We have 7 working days. Four key tasks must happen **one after another** (backend starts → login works → database tables → sample data), and each one waits for a review. At 24 hours per review, we could lose up to **4 of our 7 days** just waiting. With 4 hours, a PR opened in the morning is merged the same day.
- Every PR names a **main reviewer** and a **backup reviewer** (see your page).
- If the main reviewer hasn't replied in 4 hours, the backup reviews.
- The reviewer **runs the code**, not only reads it. The bugs on `main` today got in because nobody ran the code.
- Keep PRs small (about 300 changed lines or less) so a review takes 20 minutes, not 2 hours.

### Rule 4. One owner per shared file
These files are touched by everyone, so they cause merge conflicts. Only the owner edits them. Others ask the owner in their PR.
- `backend/app/main.py` and `backend/requirements.txt`: **Sajawal**
- `frontend/js/common.js` and `frontend/js/api.js`: **Eisha**
- Database migration files: **Wajiha**

### Rule 5. Why the cut line is where it is
- After buffer, we have **76 hours** for Must-have work (Part 5). The Musts below need **75 hours**.
- The Musts are the features in the project's title: the automated **timetable**, the swaps, the makeup classes, the notifications, all online. Without them, there is no timetable project.
- The portal features were cut first because their backend **already works and has tests**, so they can still be shown through `/docs`. The timetable screens don't exist at all yet, so they need the hours.
- **Must before Should:** nobody starts a Should task until all of their own Must tasks are done.

### Rule 6. What happens automatically if we fall behind (triggers)
These are decided **now**, so nobody has to argue later. At the 21:00 check-in, Sajawal says which trigger fired, and it happens.

| Trigger | If this is not done by… | …then this happens automatically |
|---|---|---|
| **T1** | B-1 (backend starts) by **8 Nov 23:59** | All Should tasks are cancelled. Eisha's spare time goes to helping Amna with F-4 (timetable page). |
| **T2** | B-6 (swap feature fixed, tests green) by **10 Nov 21:00** | Sajawal takes over swaps. X-2 (swap vs makeup check) is cancelled. Taha works only on D-2 (full deploy) and F-6 (makeup page). |
| **T3** | Frontend using the real API, not mock data, by **12 Nov 21:00** | F-6 (makeup page) shows only "find free slots". Booking is shown through `/docs`. X-3 (empty rooms) and X-4 (papers/slides pages) are cancelled. |
| **T4** | Tables created on online MySQL by **10 Nov 21:00** | Switch the database to TiDB. If that also fails by 11 Nov, switch to Postgres (we'd have to explain this to the instructor). |
| **T5** | Anyone has **no PR for 2 days in a row** | Their whole remaining module goes that night to their named backup (see your page). |
| **T6** | Demo not passing on staging by **13 Nov 21:00** | No more features at all. 14 Nov is spent only on making the demo pass. |
| **T7** | Amna's F-4 (timetable page) not merged by **11 Nov 21:00** | Eisha takes the HOD half of F-5 (approve/reject list). Eisha's X-3 and X-4 are cancelled. See Part 6b. |

### Rule 7. Daily check-in (5 minutes, WhatsApp, 21:00, one message each)
```
[Name] Day N
Done (merged PR numbers): …
Blocked by: … (who or what)
Tomorrow (task codes + names): …
My Must hours: X of Y done · plan said Z · ahead / on track / behind
```
Sajawal then posts one line: "Critical path on track / behind by N hours · Trigger fired: none / T#".

---

## Part 5. Where we are and what we have

### 5.1 Where the project is today (checked by me on 7 Nov)
- **The backend does not start.** The file that starts it (`main.py`) is empty. Three files it needs don't exist. When I ran the tests, **0 tests ran**: everything crashes on loading.
- **Much backend code exists and most of it is good.** There are about 5,200 lines and 53 endpoints. Your backend review found 112 of 116 tests pass once the loading problems are patched. So most backend work is **repair**, not new work.
- **Broken backend features:** login (the code and the user table disagree), swaps (they use a column name that doesn't exist), makeup booking (puts lecture courses into labs, and doesn't stop double bookings).
- **The frontend exists only as portal pages, with fake data.** It is on the `frontend` branch, whose PR was closed without merging on 6 November. Login accepts any password. Signup and the lecture page crash. **No timetable screens exist** (no timetable view, swaps, makeup or empty rooms).
- **No one-file API contract.** Wajiha's file covers 7 of 11 backend areas. Login, swaps, makeup and notifications have none.
- **Missing:** automatic tests (CI), PR template, database setup, sample data, deployment, README.
- **Good:** no passwords or secrets are in the repo.
- **Activity:** no commits by anyone since 24 October. Eisha has no commits yet. Taha's backend modules were written by Sajawal.

### 5.2 The hours (capacity check)
| | Hours |
|---|---|
| 5 people × 3 hours × 8 days (8 to 15 Nov) | 120 |
| minus 15 November (kept spare) | −15 |
| minus check-ins, reviews and merging (0.4 h × 7 days × 5 people) | −14 |
| **Real working hours** | **91** |
| Must-have limit (91 divided by 1.2, which keeps 20% spare) | **76** |
| Hours needed for **everything** in the original plan | about 130 |
| **Shortfall** | **39 hours (about 30% of the work)** |
| Hours needed for the **Must** tasks | **75** → fits, with 1 hour to spare |

### 5.3 What we build and what we cut
- **Must have:** login for 4 roles, timetable page, swap request + HOD approval with conflict check, makeup find + book, notifications, online database with sample data, automatic tests, API contract, README, everything online.
- **Should have** (only after your Musts are done): Postman demo requests (X-1), swaps checking against makeup classes (X-2, the most important Should), empty rooms page (X-3), papers and slides pages (X-4), activate account page (X-5), safety fixes (X-6), MySQL in CI (X-7), real AI/CS/IT sheets (X-8).
- **Could have:** GPA page (C-1), software and events pages (C-2), remove duplicate role checks (C-3), style tools and renames (C-4).
- **Won't have this time:** password reset (the page will say "contact your department admin"), live push notifications (we check every 15 seconds instead, which the proposal allows), more than one term, React.

**Things we must explain to the instructor:**
1. Portal features (past papers, lecture slides, GPA, software, events) have **working, tested backends**, but little or no screen. We show them through `/docs`.
2. Plain HTML/JS instead of React.
3. Timetable import is checked for the **SE sheet format only**.
4. Notifications refresh every 15 seconds instead of arriving instantly.
5. Students can't sign up freely. They activate an account the admin already created (safer, by design).
6. Only if trigger T4 fires: a database other than MySQL.

### 5.4 The critical path (the chain that decides our finish date)
Each step needs the one before it:

1. **B-1** Backend starts (8 Nov) →
2. **B-4** Login and user accounts fixed (9 to 10 Nov) →
3. **B-10** Tables created on online MySQL (10 Nov) →
4. **B-11** Sample data loaded (11 Nov) →
5. **F-4** Timetable page on real data (11 Nov) →
6. **F-5** Swap requests page (12 Nov) →
7. **D-2** Full staging deploy passes the demo (13 Nov) →
8. Feature freeze (14 Nov).

Running alongside it, and also needed: **B-6** swaps fixed (by 10 Nov) and **F-3** real login on the frontend (by 11 Nov).
If **any** of these is a day late, the hand-in is a day late, unless a trigger cuts something. Everything else has some spare time.

---

## Part 6. Risks (what could go wrong)

| # | Risk | How likely | How bad | Early warning sign | What we do |
|---|---|---|---|---|---|
| **R1** | **A member stops delivering.** Eisha has no commits yet. Taha hasn't written his backend modules. | High | High | No branch pushed by 21:00 on their first task day | Every key task has a named backup. Trigger T5 moves whole modules, never half a file. |
| **R2** | **Code breaks on MySQL.** It has only ever run on the SQLite test database. | High | High | Table creation fails online on 10 Nov | Real database on day 3, not day 6. Trigger T4. X-7 (MySQL in CI) if there's time. |
| **R3** | **Merge conflicts** on shared files | Medium | Medium | Two open PRs change the same file | One owner per shared file (Rule 4). |
| **R4** | **Slow reviews** block the next person | High | High | A PR waits more than 4 hours | 4-hour review limit, backup reviewer, small PRs (Rule 3). |
| **R5** | **Real timetable sheets don't match the import code.** The roll-number format is also a guess. | Medium | Medium | Test import of the real SE sheet shows errors | Sample data is made from a hand-built file we control. Real sheets are X-8 (Should). |
| **R6** | **Double booking** if two people book at the same moment (row lock never tested on MySQL) | Medium | Medium | In a two-tab test, both bookings succeed | One manual test on staging on 13 Nov. The database also refuses duplicate room bookings. The demo uses one user at a time. |
| **R7** | **Works locally, breaks online** (CORS, cold start, temporary disk, missing settings) | High | High | Anything that works on a laptop fails on the website | Put it online on 9 Nov and redeploy after every merge. Smoke test. Wake the server before the demo. |
| **R8** | **Polishing portal pages instead of timetable pages** (the portal was built first) | Medium | High | Portal commits while F-4 and F-5 aren't merged | Must before Should (Rule 5). Sajawal flags any Should PR opened too early. |

### 6b. If Amna falls behind: what gets cut first
Amna carries the two frontend tasks on the critical path: F-4 (timetable page) and F-5 (swap requests page). In the first draft she had **17 of her 18.2 hours** planned. One bad day and the swap screens slip. So, in this order:

0. **Done now, before anything slips:** F-2 (shared menu/layout, 2 hours) moves from Amna to **Eisha**. It lives in `common.js`, which Eisha owns anyway (Rule 4), so this also removes a conflict risk. F-4 no longer waits for it: Amna builds the timetable page and the menu plugs in when it lands. **Amna is now at 15 of 18.2 hours and has 13 November free as buffer.** Eisha goes from 11 to 13 hours.
1. **Trigger T7:** if F-4 (timetable page) is not merged by 11 Nov 21:00, **Eisha takes the HOD half of F-5** (the approve/reject list, about 2 to 3 hours). Eisha's Shoulds X-3 (empty rooms) and X-4 (papers/slides) are **cancelled** to pay for it.
2. If the teacher half of F-5 is not merged by **12 Nov 21:00**, the timetable page becomes a **simple table per day** instead of a styled weekly grid. Teachers pick the class to swap from a **dropdown**, not by clicking the grid. Taha helps Amna after F-6 (makeup page).
3. **Never cut:** teacher asks for a swap → HOD approves → timetable changes. That is the heart of the demo. If even more time is needed, trigger T3 cuts makeup *booking* to "find slots only", which frees Taha to help.

**The case against step 0:** Eisha has no commits yet, so giving her more work increases risk R1. That is why only 2 non-critical hours move now, and the bigger move (step 1) happens only if Amna is actually late. If **both** Amna and Eisha slip, the backup is Taha (from 13 Nov) and then Sajawal. Then the portal and empty-room pages are gone for sure.

---

## Part 7. Day-by-day overview and each person's page

### 7.1 Whole team, one table (end-of-day goal = what must exist at 21:00)
| Day | Sajawal | Wajiha | Taha | Amna | Eisha | **Team goal by 21:00** |
|---|---|---|---|---|---|---|
| **Sun 8** | B-1 Backend starts | B-2 Import paths · B-3 Portal bugs | B-6 Swaps fix (start) | F-1 Frontend cleanup | B-12 CI + PR template · F-3 API helper (start) | Backend starts, `/docs` shows all endpoints, CI runs |
| **Mon 9** | B-4 Login fix (start) · D-1 help | **B-5 API contract (final)** | D-1 First deploy · B-6 | F-4 Timetable page (mock data) | F-2 Shared menu · F-3 Login (mock) | **Contract final. Backend and frontend both online.** |
| **Tue 10** | B-4 merged by 13:00 · B-8 Makeup safety | B-10 Tables on MySQL | **B-6 merged, tests green** · B-7 | F-4 · F-5 Swaps page (start) | F-3 on real backend | Login works end to end on a laptop. Tables exist online. |
| **Wed 11** | B-8 merged · reviews | B-11 Sample data | B-7 · B-9 Notification tests | **F-4 on real data, merged** · F-5 | F-3 merged · F-7 Bell | Timetable shows real sample data |
| **Thu 12** | D-2 Full deploy | B-11 finish · X-1 Postman · X-6 | B-9 merged · F-6 Makeup page | F-5 teacher half merged | F-7 merged · X-5 Activate page | **No fake data left in the demo path** |
| **Fri 13** | Demo test online · D-3 README | Test demo as an outsider · X-8 | F-6 merged · deploy help | F-5 HOD half merged · bug fixes (buffer) | X-3 / X-4 if all Musts done | **Staging: full demo passes once online** |
| **Sat 14** | FREEZE · rehearse ×2 · tag `v1.0` | Bug fixes only | Bug fixes only | Bug fixes only | Bug fixes only | Demo under 5 minutes, twice, no errors |
| **Sun 15** | Hand in · record backup demo video | Spare | Spare | Spare | Spare | Submitted |

---

### 7.2 Sajawal: backend lead, plan owner (Must work: 16.5 of 18.2 hours)
**Your work is reviewed by:** Wajiha (main), Taha (backup). **You review:** Taha's swap PRs and Wajiha's PRs (main reviewer). Keep to the 4-hour limit; you are the bottleneck for the critical path.
**You own:** `main.py`, `requirements.txt`. **Your backup if you're stuck:** Wajiha for backend, Taha for deploy.

| Day | Task | What exactly you do | Done when | Needs first |
|---|---|---|---|---|
| Sun 8 | **B-1 Make the backend start** (3 h) | Fill `main.py` (create the app, connect all 11 parts, allow the frontend website, add `/api/health`). Create `config.py` (reads settings from `.env`, no default password). Create `dependencies.py` with the "who is logged in" check, loading the user from the database every time. Add `.env.example` with names only. | `uvicorn app.main:app` starts, `/api/health` says OK, and `/docs` lists every endpoint. PR merged tonight. | Nothing |
| Mon 9 | **B-4 Fix login and user accounts** (2 h) · **D-1 First deploy** (1 h, with Taha) | Add a roll-number column to users, make email optional, allow "no password yet" for un-activated students. Fix login, activate, "me" and admin create-user. Remove open self-signup. Return 409 for duplicates. Write login tests. Help Taha connect Render to Aiven. | (Continues Tue.) Deploy: both websites answer online. | B-1 |
| Tue 10 | **B-4** finish (2 h) · **B-8 Makeup safety** start (1 h) | Merge B-4 **by 13:00**, because Wajiha's B-10 (tables) waits for it. | Login tests green; a wrong password gets 401. | B-1 |
| Wed 11 | **B-8 Make makeup booking safe** (3 h) | Lecture courses only get lecture rooms. Teachers can only book for themselves. Lock the room while saving. The database refuses a duplicate room/date/time. Write tests. | Tests show: lab refused for a lecture course; a second booking of the same slot is refused. | B-1 |
| Thu 12 | **D-2 Full deploy** (3 h) | On Render: create tables on every deploy, load sample data on staging, fix the Python version, turn on the database's secure connection, set the health check, write a smoke-test list. | Staging shows sample data after a fresh deploy. | B-10, B-11, D-1 |
| Fri 13 | Demo test online (1.5 h) · **D-3 README** (1.5 h) | Run Part 8's demo on the public websites. Write the README: run locally, settings, deploy, demo accounts, known limits. | Demo passes once online; a stranger can follow the README. | D-2 |
| Sat 14 | Freeze | Bug fixes only. Two timed rehearsals. Tag the release `v1.0`. | Demo under 5 minutes twice with no error. | n/a |
| Sun 15 | Hand in | Record a backup demo video. Redeploy only for a real bug. Submit. | Submitted. | n/a |
Every night at 21:00: post the critical-path line and any trigger that fired.

---

### 7.3 Wajiha: backend, import and portal (Must work: 13 of 18.2 hours)
**Your work is reviewed by:** Sajawal (main), Taha (backup). **You review:** Sajawal's backend PRs (main reviewer).
**You own:** database migration files. Nobody else creates them. **Your backup if you're stuck:** Sajawal.

| Day | Task | What exactly you do | Done when | Needs first |
|---|---|---|---|---|
| Sun 8 | **B-2 Fix broken import paths** (1.5 h) · **B-3 Fix 2 portal bugs** (1.5 h) | In 12 files, change `from database import` to `from app.database import`. Add `pytest.ini` so tests find the code. Add empty `__init__.py` files. Add uploads, `*.db` and `node_modules` to `.gitignore`. Then: events update uses PUT (matches the contract), and `instructor_name` uses the given name, else the uploader's, with tests. | `pytest` runs and your portal tests pass. Both PRs merged. | B-1 (rebase on it once merged) |
| Mon 9 | **B-5 Write the single API contract** (3 h) | Create `docs/api-contract.md`: your file plus login, swaps, makeup, notifications and health. For each endpoint: address, who may call it, what you send, what comes back, error codes. Fix shared formats: day names `Monday…Sunday`, times `HH:MM`, dates `YYYY-MM-DD`, roles. | Reviewed by Sajawal **and** Eisha, merged **by 21:00**. From now on, changing it needs both again. | B-1 |
| Tue 10 | **B-10 Create tables with migrations on MySQL** (3 h) | Set up Alembic in the morning. After B-4 (login fix) merges at about 13:00, generate the first migration and run it on the online Aiven database. | All tables exist on Aiven. If not by 21:00, trigger T4 fires. | B-4 |
| Wed 11 | **B-11 Fill the database with sample data** (3 h) | `backend/scripts/seed.py`: SE department, ~8 rooms with real capacities (2 labs), ~6 courses, 2 sections, 4 teachers, a full week timetable, one account per role, one un-activated student. Include a known clashing slot and a known free slot for the demo. Running it twice must not create duplicates. | The staging database shows the sample timetable. | B-10 |
| Thu 12 | B-11 finish (1 h) · **X-1 Postman** (1.5 h) · **X-6** start (0.5 h) | Postman requests for each demo step, named by feature, not person. Invented rooms get "unknown" capacity, not 40. | Postman demo runs top to bottom on staging. | B-11 |
| Fri 13 | Outsider demo test (1 h) · **X-8 real sheets** / **X-6 safety fixes** (2 h) | Run the demo as if you'd never seen it; report each bug to its owner. Then check the real AI/CS/IT sheets, check uploaded file types by content, and limit login attempts. | Bug list posted. | D-2 |
| Sat 14 | Freeze | Bug fixes only. Check the sample data loads cleanly twice. | n/a | n/a |
| Sun 15 | Spare | Available for emergencies only. | n/a | n/a |

---

### 7.4 Taha: backend swaps and notifications, plus deploy (Must work: 16 of 18.2 hours)
**Your work is reviewed by:** Sajawal (main), Wajiha (backup) for backend. Amna (main), Eisha (backup) for the makeup page.
**Your backup if you're stuck:** Sajawal (swaps), Amna (makeup page). **Note:** B-6 (swaps fix) is on the critical path. Push your branch every day, even unfinished, so a stall is visible early.

| Day | Task | What exactly you do | Done when | Needs first |
|---|---|---|---|---|
| Sun 8 | **B-6 Fix swaps and the conflict checker** (3 h) | Read `swap_service.py` and `conflict_checker.py` (1 h). Rename every `TimetableEntry.day` to `day_of_week`. Give `TimetableEntry` a link to its teacher. | Branch pushed tonight. | B-1 |
| Mon 9 | **D-1 First deploy** (2 h) · B-6 (1 h) | With Sajawal's accounts: backend on Render from `main`, database on Aiven, frontend on Netlify. Set settings on the host, never in code. Allow the Netlify address (CORS). Check the free plans still exist. | Both public websites answer; `/api/health` says OK online. | B-1 |
| Tue 10 | **B-6** finish (2 h) · **B-7 HOD approval** start (1 h) | Write swap tests and conflict-checker tests. | **B-6 merged with green tests by 21:00.** If not, trigger T2: Sajawal takes swaps. | B-4 |
| Wed 11 | **B-7 Let HODs approve their department's swaps** (1 h) · **B-9 Test notifications** (2 h) | Admin create-user takes a department for HODs. Test: HOD of dept A can approve A's swap and is refused for dept B. Test that a notification is saved when a swap is approved and when a makeup is booked. | Both PRs merged, tests green. | B-6, B-8 |
| Thu 12 | **F-6 Makeup class page** (3 h) | `makeup.html`: pick section and duration → show free slots and rooms → book one. Use `NUML.api()` from Eisha's helper. | (Continues Fri.) | F-3, B-8 |
| Fri 13 | **F-6** finish (2 h) · deploy help (1 h) | Show the 409 message if the slot was taken. Help Sajawal with online bugs. | A teacher books a makeup on staging and it appears on the timetable. | F-3, B-8 |
| Sat 14 | Freeze | Bug fixes only. One two-tab test: two people approving at once must not double-book. | n/a | n/a |
| Sun 15 | Spare | Emergencies only. | n/a | n/a |

---

### 7.5 Amna: frontend, timetable screens (Must work: 15 of 18.2 hours, after F-2 moved to Eisha)
**Your work is reviewed by:** Eisha (main), Taha (backup). **You review:** Eisha's and Taha's frontend PRs (main reviewer).
**Your backup if you're stuck:** Eisha, then Taha. **Read Part 6b**: it says exactly what happens if you fall behind. Tell the team early; that is not failure.

| Day | Task | What exactly you do | Done when | Needs first |
|---|---|---|---|---|
| Sun 8 | **F-1 Clean up the frontend and bring it into `main`** (3 h) | New branch from the latest `main`. Copy in `frontend/`. Delete the `web project frontend/` folder and the 4 unused CSS files. Make file names and the `PAGES` list match. Load `common.js` on every page. Fix `lecture.html`. Delete the signup page (students activate instead). | Every page opens with no red errors in the browser console. PR merged. | Nothing |
| Mon 9 | **F-4 Timetable page** (3 h) | `timetable.html`: a weekly view. Students see their section; teachers see their own classes; makeup classes are shown too. Use mock data shaped like the contract. | Page shows the mock week correctly. | B-5 (contract) |
| Tue 10 | **F-4** (2 h) · **F-5 Swap requests page** start (1 h) | Finish the grid on mocks. Start the teacher form on mocks. | n/a | F-3 helper |
| Wed 11 | **F-4** on real data (1 h) · **F-5** (2 h) | Switch the timetable to the real API on staging. | **F-4 merged by 21:00**, showing real sample data. If not, trigger T7. | B-11, F-3 |
| Thu 12 | **F-5 teacher half** (3 h) | Teacher picks a class and a new slot. On a clash, show the 409 reason; on success, show "pending". | Teacher half merged; a request appears as pending. | B-6 |
| Fri 13 | **F-5 HOD half** + buffer (3 h) | HOD/admin list with approve/reject buttons. Any leftover time is your buffer. | HOD approves on staging and the teacher's timetable changes. | F-5 teacher half |
| Sat 14 | Freeze | Bug fixes only. | n/a | n/a |
| Sun 15 | Spare | Emergencies only. | n/a | n/a |

---

### 7.6 Eisha: frontend foundation, login, notifications (Must work: 13 of 18.2 hours)
**Your work is reviewed by:** Amna (main), Taha (backup). CI and PR template reviewed by Sajawal.
**You own:** `common.js` and `api.js`. Others ask you for changes. **Your backup if you're stuck:** Amna, then Taha.
You have the most spare time. That is on purpose: you are Amna's first backup (Part 6b, trigger T7).

| Day | Task | What exactly you do | Done when | Needs first |
|---|---|---|---|---|
| Sun 8 | **B-12 Automatic tests + PR template** (2 h) · **F-3 API helper** start (1 h) | Add `.github/workflows/backend-tests.yml` (installs and runs `pytest` on every PR) and `.github/pull_request_template.md` (checklist from the guide). Start `js/api.js` with one `API_BASE` setting (empty = use mock files). | CI runs on PRs (red is OK until B-2 merges). Template appears on new PRs. | Nothing |
| Mon 9 | **F-2 Shared menu/layout** (2 h) · **F-3 Real login** (1 h) | Render one sidebar menu from `common.js` on every logged-in page; move its styles into `master.css`. Start the login form on mocks. | Every logged-in page shows the same menu. | F-1 |
| Tue 10 | **F-3 Real login** (3 h) | `NUML.api()` sends the login ticket with every request; on 401, go to login. Login uses **roll number**, not email. Check "who am I" on page load. Logout clears the ticket. Send each role to its start page. Remove the saved profiles in the browser. | Wrong password is refused; a fake browser "logged in" value no longer works. | B-4, B-5 |
| Wed 11 | **F-3** finish (1 h) · **F-7 Notification bell** (2 h) | Merge F-3. Bell asks for the unread count every 15 seconds and shows a dropdown with mark-as-read. | F-3 merged. | B-9 |
| Thu 12 | **F-7** finish (1 h) · **X-5 Activate page** (1.5 h) | Activate page: roll number + new password. | Bell shows "swap approved" after an HOD approves. | F-3 |
| Fri 13 | **X-3 Empty rooms** / **X-4 Papers & slides pages** (3 h), only if all your Musts are merged | Use real endpoints. If T7 fired, do the HOD half of F-5 instead. | n/a | F-3 |
| Sat 14 | Freeze | Bug fixes only. | n/a | n/a |
| Sun 15 | Spare | Emergencies only. | n/a | n/a |

---

## Part 8. The 5-minute demo (everything above is built for this)
*Two minutes before: open `/api/health` to wake the server.*
1. **0:00** Open the public website → login page. Mention `/docs` is live.
2. **0:20 Student** logs in with a roll number → sees their section's weekly timetable.
3. **1:00 Teacher** logs in → own timetable → asks to move a class to a slot that clashes → red message, e.g. "Room R-2 is booked" → picks a free slot → request is **pending**.
4. **2:00 HOD** logs in → swap requests → approves.
5. **2:30 Teacher** → bell shows "Swap approved" → the class is in its new slot.
6. **3:00 Teacher** → makeup page: section + 90 minutes → list of free slots, lecture rooms only → books one.
7. **3:45 Student** → bell shows "Makeup class added" → it's on the timetable.
8. **4:15 Admin** → `/docs`: timetable import (test run) and portal endpoints (exams, GPA) → end.

The sample data guarantees fixed accounts, one known clashing slot, one known free slot, and a section with enough room capacity.

---

## Part 9. What I need from you (Sajawal) in the next 2 hours
1. **Post Part 4 (the rules) and Part 7 (the pages) in the team chat tonight.** Get a written "yes, I own it, by this date" from **Taha (B-6 swaps fix)** and **Eisha (F-3 real login)**. No reply by morning counts as risk R1 happening.
2. **Approve the two process changes:** the 4-hour review limit, and F-2 (shared menu) moving to Eisha. Once B-12 (automatic tests) lands, make the tests a required check on `main`.
3. **Create the Render, Aiven and Netlify accounts** (you own them; add Taha), and confirm the free plans still exist. Without them, D-1 (first deploy) can't happen on Monday.
4. **Start B-1 (make the backend start) tonight or first thing tomorrow.** The whole critical path waits for it.
5. **Send Wajiha the real SE timetable sheet and 3 to 4 real roll numbers,** for the sample data and the roll-number format. Without them, the sample data is invented.

---
---

# Appendix: technical version (for Sajawal)

### A1. Audit evidence
"Registered in main.py" = 0 for all modules (`backend/app/main.py` is 0 bytes).

| Module (owner) | Files (lines) | Endpoints coded | Tests | Status | Evidence |
|---|---|---|---|---|---|
| Core tables (Sajawal) | 13 models (~360) | n/a | indirect | Partial | `User` has `email`, no `identifier`; `password_hash` NOT NULL |
| Login/JWT (Sajawal) | `core/security.py` 53, `routers/auth.py` 269 | 5 | 0 | Broken | imports missing `app.config` and `app.dependencies`; `DUMMY_HASH` undefined |
| App entry/config (Sajawal) | `main.py` 0, `config.py` missing | 0 registered | n/a | Not started | `uvicorn app.main:app` fails |
| Scheduler rules (Sajawal) | `scheduler_rules.py` 173 | n/a | via solver | Working, 1 bug | `:127` lab rule only one way |
| Makeup solver (Sajawal) | `makeup_solver.py` 174, `routers/makeup.py` 114 | 3 | 159 lines | Partial | 1 failing test; booking: no lock, no ownership check |
| Permissions (Taha) | `core/permissions.py` 47 + `portal_deps.py` 23 | n/a | 0 | Partial | 4 role-check copies; HOD `department_id` never set |
| Conflict checker (Taha) | `conflict_checker.py` 108 | n/a | 0 | Broken | `TimetableEntry.day` (column is `day_of_week`); ignores `MakeupClass` |
| Swaps (Taha) | `swap_service.py` 249, `routers/swaps.py` 110 | 5 | 0 | Broken | same `day` bug; no `entry.teacher` relationship → 500 |
| Notifications (Taha) | service 119, router 50 | 4 | 0 | Partial | missing `get_current_user` import |
| Import (Wajiha) | `timetable_import.py` 496, router 130 | 2 | 251 lines | Working (SE) | `from database` path; placeholder capacity 40 |
| Rooms/ref (Wajiha) | `rooms.py` 133 | 7 | 66 lines | Working | path bug |
| Exams/materials/GPA/software/events (Wajiha) | ~670 | 27 | 5 files | Working, 3 bugs | events PATCH vs contract PUT; `instructor_name` ignored |
| Frontend foundation (Eisha) | none | n/a | n/a | Not started | 0 commits |
| Timetable screens (Amna) | none | n/a | n/a | Not started | none on any branch |
| Portal UI | `frontend` branch, 38 files, +7,064 | 0 API calls | 0 | Mock UI | PR [Sajawal3185/timetable-automation#35](https://github.com/Sajawal3185/timetable-automation/pull/35) closed unmerged on 6 Nov |
| Deployment | none | n/a | n/a | Not started | no `.env.example`, no migrations |

- **Test run (mine):** Python 3.13 scratch venv, install OK, `pytest` → `ModuleNotFoundError: app.config` at `conftest.py:19`; 0 tests run. The 112/116 figure comes from your backend review PDF (stubbed run); I didn't reproduce it.
- **PRs:** 0 open. Merged backend PRs: #27, #28, #30, #26, #33, #34. **Branches:** `main` (protected), `frontend` (6 ahead / 6 behind), `amna_245` (stale since 17 Oct). **Commits 24 Oct → 7 Nov:** none. Lifetime on main: Sajawal 21, Wajiha 2, Amna 2, Taha 0, Eisha 0.
- **Not verified:** frontend runtime (taken from your PDF; branch not fetched to stay read-only), ruleset settings, real sheet parsing, MySQL behaviour, free-tier terms.
- **Guide gaps:** no `.github/` (no PR template, no CI); `.gitignore` lacks `backend/uploads/`, `node_modules/` and `*.db`; `backend/test/` vs guide `tests/`; `.docx` in root; person names in file names; no `.env.example` or README. No secrets committed.
- **Contract:** `docs/api-contract.md` missing; `api-contract-wajiha.md` matches code except events PUT/PATCH. 17 endpoints (auth 5, swaps 5, makeup 3, notifications 4) have no contract.

### A2. Mapping to your review PDFs
Backend PDF → this plan:
- B-1 (backend starts) = backend C1 empty main.py, C2 missing config.py, C3 missing auth dependency, and D3 role read from token.
- B-2 (import paths) = C4 wrong import path in 12 files, plus D8 uploads not ignored.
- B-3 (portal bugs) = M1 events uses PATCH, M2 instructor_name ignored.
- B-4 (login fix) = C5 auth vs User model, M8 open self-registration, D6 500 on duplicate user.
- B-5 (API contract) = M9 no single contract.
- B-6 (swaps fix) = C6 wrong column and missing relationship, plus D1 tests for swaps and the conflict checker.
- B-7 (HOD approval) = D2 HOD has no department.
- B-8 (makeup safety) = M3 lecture courses in labs, M4 no lock or ownership check.
- B-9 (notification tests) = D1 tests for notifications.
- B-10 (migrations) = M7 no migrations.
- B-12 (CI) = C7 merged without running.
- X-2 (swap vs makeup) = M5 two rule engines.
- X-6 (safety fixes) = D4 no rate limit, D5 placeholder capacity, D7 extension-only upload check.
- C-3 (duplicate role checks) = M6 four role-check copies, D9 service rollback pattern.

Frontend PDF → this plan:
- F-1 (frontend cleanup) = C1 signup broken, C5 forgot password unreachable, C6 lecture page crash, M1 missing login guard, M2 wrong page map, M3 dead links, M4 duplicate folder, D4 dead CSS.
- F-2 (shared layout) = D2 no shared layout, D3 styles injected from JS.
- F-3 (real login) = C2 any password accepted, C3 client-side auth flag, C4 no backend calls, C7 email vs roll number, D1 profiles in localStorage.
- X-4 (papers/slides pages) = M5 fake uploads, M6 missing lecture files, M8 duplicate subject list.
- Password reset message = D8 forgot password does nothing.

### A3. Full task list with estimates
Expected time = (best + 4 × likely + worst) ÷ 6. Capacity maths uses Likely.

| Task | Owner | Depends on | Best/Likely/Worst h | Priority |
|---|---|---|---|---|
| B-1 Backend starts | Sajawal | none | 2/3/4 | Must |
| B-2 Import paths | Wajiha | none | 1/1.5/2 | Must |
| B-3 Portal bugs | Wajiha | B-2 | 1/1.5/2 | Must |
| B-4 Login fix | Sajawal | B-1 | 3/4/6 | Must |
| B-5 API contract | Wajiha | B-1 | 2/3/4 | Must |
| B-6 Swaps fix | Taha | B-1, B-4 | 3/5/8 | Must |
| B-7 HOD approval | Taha | B-4 | 1/2/3 | Must |
| B-8 Makeup safety | Sajawal | B-1 | 3/4/6 | Must |
| B-9 Notification tests | Taha | B-6, B-8 | 1/2/3 | Must |
| B-10 Migrations on MySQL | Wajiha | B-4 | 2/3/5 | Must |
| B-11 Seed data | Wajiha | B-10 | 3/4/6 | Must |
| B-12 CI + PR template | Eisha | none | 1/2/3 | Must |
| D-1 First deploy | Taha 2 + Sajawal 1 | B-1 | 2/3/5 | Must |
| D-2 Full deploy | Sajawal | B-10, B-11, D-1 | 2/3/5 | Must |
| D-3 README | Sajawal | D-2 | 1/1.5/2 | Must |
| F-1 Frontend cleanup | Amna | none | 2/3/5 | Must |
| F-2 Shared layout | **Eisha** (moved from Amna) | F-1 | 1/2/3 | Must |
| F-3 Real login (frontend) | Eisha | B-5 mock, B-4 real | 4/6/9 | Must |
| F-4 Timetable page | Amna | F-3, B-11 (no longer F-2) | 4/6/9 | Must |
| F-5 Swap requests page | Amna (HOD half → Eisha if T7 fires) | F-4, B-6 | 4/6/9 | Must |
| F-6 Makeup page | Taha | F-3, B-8 | 3/5/7 | Must |
| F-7 Notification bell | Eisha | F-3, B-9 | 2/3/4 | Must |
| X-1 Postman | Wajiha | B-11 | 1/1.5/2 | Should |
| X-2 Swap vs makeup check | Sajawal/Taha | B-6, B-8 | 2/3/5 | Should |
| X-3 Empty rooms page | Eisha | F-3 | 2/3/4 | Should |
| X-4 Papers & slides pages | Eisha | F-3 | 4/5/8 | Should |
| X-5 Activate page | Eisha | F-3 | 1/1.5/2 | Should |
| X-6 Safety fixes | Wajiha | B-2 | 2/3/4 | Should |
| X-7 MySQL in CI | Eisha | B-12, B-10 | 1/2/3 | Should |
| X-8 Real AI/CS/IT sheets | Wajiha | B-10 | 2/3/6 | Should |
| C-1 GPA page | Amna/Eisha | F-3 | 2/2.5/4 | Could |
| C-2 Software + events pages | Eisha | F-3 | 2/3/4 | Could |
| C-3 Remove duplicate role checks | Taha | B-7 | 2/3/5 | Could |
| C-4 Style tools, renames, CODEOWNERS | Wajiha | none | 1/2/3 | Could |
| C-5 Reset, push, a11y, fonts | none | none | none | Won't |

Totals (Likely): Must ≈ 75 · Should 24 · Could 12. Must per person: Sajawal 16.5, Wajiha 13, Taha 16, Amna 15 (was 17), Eisha 13 (was 11). Each person has about 18.2 productive hours (91 ÷ 5).
The critical path (B-1 → B-4 → B-10 → B-11 → F-4 → F-5 → D-2) is about 23 Likely hours, run in sequence over 6 days. It only works with same-day handoffs.

### A4. Interface details pinned on Day 1 (inside B-5, the API contract)
- `{API_BASE}/api/...`, JSON except multipart uploads. `Authorization: Bearer <JWT>`, 60-minute expiry, no refresh token. Errors are `{"detail": "..."}`.
- Enums: role `student|teacher|hod|admin`; `day_of_week` `Monday…Sunday` (matches the DB check constraint); swap status `pending|approved|rejected|cancelled`. Times `HH:MM`, dates `YYYY-MM-DD`.
- Mocks: `frontend/mock/*.json`, copied from the contract examples; `API_BASE = null` switches to mock mode.

**First 5 endpoints:**
1. `POST /api/auth/login`: `auth.py:173`, broken (no `identifier` column). Fixed by B-4.
2. `GET /api/auth/me`: `auth.py:227`, broken (no `get_current_user`). Fixed by B-1 and B-4.
3. `GET /api/timetable`: `timetable.py:31`, import path bug only. Fixed by B-2.
4. `POST/GET /api/swap-requests` and `/{id}/approve`: `swaps.py:32,61,72`, returns 500. Fixed by B-6.
5. `POST /api/makeup/find-slots` and `POST /api/makeup`: `makeup.py:35,61`, lab bug and no lock. Fixed by B-8.

Also needed: `GET /api/notifications/unread-count` (exists) and `GET /api/health` (new, added in B-1).

### A5. Deployment configuration
- **Render:** root `backend/`; build `pip install -r requirements.txt`; start `alembic upgrade head && uvicorn app.main:app --host 0.0.0.0 --port $PORT`; `PYTHON_VERSION=3.12`.
- **Database:** Aiven free MySQL. Fallback: TiDB Serverless (TLS required), then Postgres.
- **Frontend:** Netlify serving `frontend/`, with a per-environment `config.js` that sets `API_BASE`.
- **Host environment variables:** `DATABASE_URL=mysql+pymysql://…?ssl_ca=…`, `JWT_SECRET_KEY`, `CORS_ORIGINS=https://<site>`, `UPLOAD_DIR`, `ACCESS_TOKEN_EXPIRE_MINUTES=60`.

**Failure modes:**
1. CORS origin mismatch (scheme, trailing slash, preview URLs).
2. MySQL TLS not passed to PyMySQL.
3. MySQL-only DDL errors: constraint names over 64 characters from the naming convention, Enum DDL.
4. Render cold start of 30–60 s.
5. Ephemeral disk wipes uploads.
6. Missing env var crashes startup.
7. Python 3.13 vs pinned wheels.

**Why MySQL is a risk:** tests use in-memory SQLite (`conftest.py:44`), where `with_for_update()` is a no-op, so locking has never actually been exercised.
