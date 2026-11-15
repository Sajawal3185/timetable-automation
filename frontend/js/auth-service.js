/* ==========================================================================
   auth-service.js  -  needs common.js loaded first.

   *** FRONTEND-ONLY DEMO. NOTHING HERE IS REAL AUTHENTICATION OR SECURITY. ***
   No request is sent to any server. No email is sent. No password is
   checked or saved anywhere. Everything lives in this one file so it can
   be replaced in one go when the backend is connected.

   WHY THIS FILE EXISTS
   The login, signup, email-verification, forgot-password and
   set-new-password pages never "know" how things are checked. They only
   call the functions of window.AuthService below and show the result.
   Later, rewrite the function bodies in this file to call the backend
   (fetch). The pages do not need to change as long as each function
   keeps returning the same kind of result (a Promise) and throws an Error
   with a readable message (and .status, like an HTTP code, if you want).

   THE DEMO RULES (delete them when the backend is connected)
     - Verification code: always 123456 (no email is really sent).
     - Login: any valid email + any password works.
         email starts with "review-demo"  -> role "admin"   (Review Team)
         any other email                  -> role "student"
       The real list of the 5 Review Team emails will live in the backend.
       It is NOT written in the frontend.
     - The "token" is a fake text. It only lets the pages' own
       "are you logged in?" check (common.js) pass.

   WHAT TO CALL LATER (the Timetable pages already in this project use
   these endpoint names; confirm them with the backend before relying on them)
     login             POST /api/auth/login            { identifier, password } -> { token, user }
     startSignup       POST /api/auth/signup           { email, name, password }
     startPasswordReset POST /api/auth/forgot-password { identifier }
     resendCode        POST /api/auth/resend-code      { email }
     verifyCode        POST /api/auth/verify-email     { email, code }   (signup)
     setNewPassword    POST /api/auth/reset-password   { identifier, code, new_password }
   NOTE for reset: the Timetable's reset-password sends the code TOGETHER
   with the new password. Here the code is checked on its own page first,
   so the demo keeps the verified code in the flow (see verifyCode) and
   setNewPassword is the place to send it. If the backend gets a separate
   "verify reset code" endpoint, use it in verifyCode instead.
   ========================================================================== */
(function () {
  "use strict";

  /* ---- Demo settings --------------------------------------------------- */
  const MODE = "frontend-demo";
  const DEMO_CODE = "123456";
  const CODE_LENGTH = 6;
  const RESEND_SECONDS = 60;
  const DEMO_REVIEW_PREFIX = "review-demo";
  const DEMO_TOKEN = "frontend-demo-token-not-real";
  const FAKE_DELAY_MS = 300;            // so "Logging in…" is visible

  const MIN_PASSWORD = 8;
  const MAX_PASSWORD = 72;              // same limits as the Timetable pages
  const EMAIL_FORMAT = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  /* Where the half-finished signup / reset is remembered (this tab only). */
  const FLOW_KEY = "numlAuthFlow";


  /* ---- Small helpers --------------------------------------------------- */
  function wait(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function fail(message, status) {
    const err = new Error(message);
    err.status = status || 400;
    return err;
  }

  function cleanEmail(value) {
    return String(value || "").trim().toLowerCase();
  }

  function isEmail(value) {
    return EMAIL_FORMAT.test(value);
  }

  function utf8Length(text) {
    return new TextEncoder().encode(text).length;
  }

  /* DEMO ONLY: decides the role from the email. The backend will do this. */
  function demoRoleFor(email) {
    return email.split("@")[0].indexOf(DEMO_REVIEW_PREFIX) === 0 ? "admin" : "student";
  }


  /* ---- The half-finished signup / reset (sessionStorage) ---------------- */
  /* Shape: { purpose: "signup" | "reset", email, name?, verified, code?, sentAt } */
  function getPendingFlow() {
    try {
      const raw = sessionStorage.getItem(FLOW_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function savePendingFlow(flow) {
    try {
      sessionStorage.setItem(FLOW_KEY, JSON.stringify(flow));
    } catch (e) {
      throw fail("Your browser is blocking storage, so this demo cannot continue.", 500);
    }
  }

  function clearPendingFlow() {
    try { sessionStorage.removeItem(FLOW_KEY); } catch (e) { /* nothing to clear */ }
  }

  /* Seconds left before "Resend code" may be used again (0 = allowed now). */
  function resendSecondsLeft() {
    const flow = getPendingFlow();
    if (!flow || !flow.sentAt) return 0;
    const passed = Math.floor((Date.now() - flow.sentAt) / 1000);
    return Math.max(0, RESEND_SECONDS - passed);
  }


  /* ---- Password check (used by the page for hints, and again below) ----- */
  /* Returns "" when fine, otherwise the message to show. */
  function checkNewPassword(password, confirm) {
    if (!password) return "Enter a new password.";
    if (password.length < MIN_PASSWORD || utf8Length(password) > MAX_PASSWORD) {
      return "Password must be " + MIN_PASSWORD + " to " + MAX_PASSWORD + " characters.";
    }
    if (!confirm) return "Confirm your new password.";
    if (password !== confirm) return "The two passwords don't match.";
    return "";
  }


  /* ======================================================================
     LOGIN
     Returns { token, user: { id, name, role, identifier } }.
     LATER: POST /api/auth/login and return what the backend answers.
     ====================================================================== */
  async function login(emailInput, password) {
    const email = cleanEmail(emailInput);
    if (!email || !password) throw fail("Enter your email address and your password.");
    if (!isEmail(email)) throw fail("Enter a valid email address.");

    await wait(FAKE_DELAY_MS);

    const role = demoRoleFor(email);                      // DEMO ONLY
    const profile = window.NUML && NUML.findProfile ? NUML.findProfile(email) : null;
    const fallbackName = role === "admin" ? "Review Team" : "Student";

    return {
      token: DEMO_TOKEN,                                  // DEMO ONLY: not a real token
      user: {
        id: "demo-" + email,
        name: (profile && profile.name) || fallbackName,
        role: role,
        identifier: email.split("@")[0]
      }
    };
  }


  /* ======================================================================
     SIGNUP  (signup page -> verify page -> login page)
     The password is passed in so the real call can send it later.
     The demo never stores it.
     LATER: POST /api/auth/signup, then the code is sent by email.
     ====================================================================== */
  async function startSignup(details) {
    const email = cleanEmail(details && details.email);
    if (!isEmail(email)) throw fail("Enter a valid email address.");

    await wait(FAKE_DELAY_MS);

    const name = [details.firstName, details.lastName]
      .map(function (part) { return String(part || "").trim(); })
      .filter(Boolean)
      .join(" ");

    savePendingFlow({ purpose: "signup", email: email, name: name, verified: false, sentAt: Date.now() });
    return { email: email };
  }


  /* ======================================================================
     FORGOT PASSWORD  (forgot page -> verify page -> set new password page)
     LATER: POST /api/auth/forgot-password. Keep the answer the same whether
     or not the email has an account, so the page cannot be used to find
     out who is registered.
     ====================================================================== */
  async function startPasswordReset(emailInput) {
    const email = cleanEmail(emailInput);
    if (!email) throw fail("Enter your email address.");
    if (!isEmail(email)) throw fail("Enter a valid email address.");

    await wait(FAKE_DELAY_MS);

    savePendingFlow({ purpose: "reset", email: email, verified: false, sentAt: Date.now() });
    return { email: email };
  }


  /* ======================================================================
     RESEND CODE (verify page)
     LATER: POST /api/auth/resend-code
     ====================================================================== */
  async function resendCode() {
    const flow = getPendingFlow();
    if (!flow) throw fail("Your session expired. Please start again.");
    if (resendSecondsLeft() > 0) throw fail("Please wait a minute before asking for a new code.", 429);

    await wait(FAKE_DELAY_MS);

    flow.sentAt = Date.now();
    savePendingFlow(flow);
    return { email: flow.email };
  }


  /* ======================================================================
     VERIFY THE EMAIL CODE (verify page)
     Returns { purpose }. The page decides where to go next:
       "signup" -> login page      "reset" -> set-new-password page
     LATER: signup  -> POST /api/auth/verify-email { email, code }
            reset   -> keep the code for setNewPassword (see the note above)
     ====================================================================== */
  async function verifyCode(codeInput) {
    const flow = getPendingFlow();
    if (!flow) throw fail("Your session expired. Please start again.");

    const code = String(codeInput || "").trim();
    if (!new RegExp("^\\d{" + CODE_LENGTH + "}$").test(code)) {
      throw fail("Enter the " + CODE_LENGTH + "-digit code from the email.");
    }

    await wait(FAKE_DELAY_MS);

    if (code !== DEMO_CODE) throw fail("That code is not correct. Try again.");   // DEMO ONLY

    if (flow.purpose === "signup") {
      /* Remember only the name (never the password) so the dashboard can greet them. */
      if (window.NUML && NUML.saveProfile) NUML.saveProfile({ email: flow.email, name: flow.name });
      clearPendingFlow();
      return { purpose: "signup" };
    }

    flow.verified = true;
    flow.code = code;
    savePendingFlow(flow);
    return { purpose: "reset" };
  }


  /* ======================================================================
     SET NEW PASSWORD (set-new-password page)
     The page also calls canSetNewPassword() when it opens, to send people
     back if they skipped the code step. That is only a convenience: the
     backend must check the code again.
     LATER: POST /api/auth/reset-password { identifier: flow.email, code: flow.code, new_password }
     The demo does NOT save the new password anywhere.
     ====================================================================== */
  function canSetNewPassword() {
    const flow = getPendingFlow();
    return !!flow && flow.purpose === "reset" && flow.verified === true;
  }

  async function setNewPassword(password, confirm) {
    if (!canSetNewPassword()) throw fail("Please verify your email first.", 403);

    const problem = checkNewPassword(password, confirm);
    if (problem) throw fail(problem);

    await wait(FAKE_DELAY_MS);

    clearPendingFlow();
    return { ok: true };
  }


  /* ---- Text for the yellow "demo" notes on the pages -------------------- */
  const demoNotes = {
    login: "Demo mode (frontend only): no server checks anything yet, so any valid email and password " +
      "will log in. An email starting with \"" + DEMO_REVIEW_PREFIX + "\" opens Review Uploads; any other " +
      "email opens the Student Dashboard.",
    verify: "Demo mode (frontend only): no email is really sent. The code is always " + DEMO_CODE + ".",
    setPassword: "Demo mode (frontend only): the new password is not saved anywhere. " +
      "The real reset will be connected to the backend later."
  };


  /* ---- Public API ------------------------------------------------------- */
  window.AuthService = {
    MODE: MODE,
    isDemo: true,
    CODE_LENGTH: CODE_LENGTH,
    RESEND_SECONDS: RESEND_SECONDS,
    DEMO_CODE: DEMO_CODE,
    demoNotes: demoNotes,

    login: login,
    startSignup: startSignup,
    startPasswordReset: startPasswordReset,
    resendCode: resendCode,
    verifyCode: verifyCode,
    setNewPassword: setNewPassword,

    getPendingFlow: getPendingFlow,
    clearPendingFlow: clearPendingFlow,
    resendSecondsLeft: resendSecondsLeft,
    canSetNewPassword: canSetNewPassword,
    checkNewPassword: checkNewPassword
  };
})();
