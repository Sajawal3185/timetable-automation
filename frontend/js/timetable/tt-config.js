/* ==========================================================================
   tt-config.js  -  the ONE place that decides mock data vs the real backend.

   API_BASE
     ""                      -> MOCK MODE. Requests are answered by tt-mock.js
                                from the JSON files in frontend/mock/db/.
                                Mock mode is a STAND-IN for the demo, not a
                                security boundary (see frontend/README.md).
     "http://localhost:8000" -> REAL MODE. Every request goes to the FastAPI
                                backend with "Authorization: Bearer <token>".

   Why a frozen object on window instead of a module: the frontend has no
   build step, so plain <script> tags share globals. Freezing it means no page
   script can flip API_BASE at runtime by accident.
   ========================================================================== */
window.TT_CONFIG = Object.freeze({
  API_BASE: "https://numl-archive.onrender.com",

  // Separate from the Archive's "numlUser" key so the two sessions never mix.
  // Only the token is stored. The profile is re-read from /api/auth/me on
  // every page load, so the browser never holds a role it could edit.
  TOKEN_KEY: "ttAuthToken",

  MIN_PASSWORD: 8,        // same floor as the backend's register/activate schemas
  POLL_MS: 15000,         // notification bell: unread-count every 15 seconds
  TERM: "Fall 2026",      // default term the makeup endpoints expect
  MAX_PASSWORD: 72,       // bcrypt's limit, which the backend enforces

  // Signup and forgot password (Login & Signup Plan, 12 Nov). These checks
  // only pick the right form and catch typos early; the backend rechecks all.
  //   STUDENT_EMAIL  numl-<term letter><yy>-<sys-id>@numls.edu.pk, e.g.
  //                  numl-f24-26789@numls.edu.pk. Any single term letter is
  //                  accepted (f = fall is confirmed, s = spring is assumed),
  //                  so a student is never pushed onto the staff form.
  //   ROLL_NUMBER    same shape as ROLL_NUMBER_PATTERN in routers/auth.py
  AUTH: Object.freeze({
    EMAIL_DOMAIN: "@numls.edu.pk",
    STUDENT_EMAIL: /^numl-[a-z]\d{2}-\d+@numls\.edu\.pk$/,
    ROLL_NUMBER: /^[a-z]{2,4}-\d{6,8}$/,
    CODE_LENGTH: 6,
    RESEND_SECONDS: 60,
    MOCK_CODE: "123456"   // mock mode only: stands in for the emailed code
  }),

  // File names of the timetable pages. Every link in the timetable UI is built
  // from this map, so a rename happens here and nowhere else.
  PAGES: Object.freeze({
    archive: "../index.html",
    login: "timetable-login.html",
    signup: "signup.html",
    forgot: "forgot-password.html",
    timetable: "timetable.html",
    swaps: "swaps.html",
    approvals: "swap-approvals.html",
    makeup: "makeup.html",
    admin: "admin.html"
  }),

  // Where each role lands after login. A role never lands on a page it may
  // not open; every page also re-checks the role itself (tt-layout.js).
  START_PAGE: Object.freeze({
    student: "timetable.html",
    teacher: "timetable.html",
    hod: "swap-approvals.html",
    admin: "admin.html"
  })
});
