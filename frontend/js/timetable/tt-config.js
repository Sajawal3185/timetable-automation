
/* ==========================================================================
   tt-config.js - The ONE place that decides mock data vs the real backend.

   API_BASE
     ""                      -> MOCK MODE. Requests are answered by tt-mock.js
                                from the JSON files in frontend/mock/db/.
     "http://localhost:8000" -> REAL MODE. Every request goes to the FastAPI
                                backend with "Authorization: Bearer <token>".

   ========================================================================== */

window.TT_CONFIG = Object.freeze({
    API_BASE: "",

    // Separate from the Archive's "numlUser" key so the two sessions never mix.
    // Only the token is stored. The profile is re-read from /api/auth/me on
    // every page load, so the browser never holds a role it could edit.
    TOKEN_KEY: "ttAuthToken",

    MIN_PASSWORD: 8,
    POLL_MS: 15000,
    TERM: "Fall 2026",

    // File names of the timetable pages.
    PAGES: Object.freeze({
        archive: "../index.html",
        login: "timetable-login.html",
        timetable: "timetable.html",
        swaps: "swaps.html",
        approvals: "swap-approvals.html",
        makeup: "makeup.html",
        admin: "admin.html"
    }),

    // Where each role lands after login.
    START_PAGE: Object.freeze({
        student: "timetable.html",
        teacher: "timetable.html",
        hod: "swap-approvals.html",
        admin: "admin.html"
    })
});
