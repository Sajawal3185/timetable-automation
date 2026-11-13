/* ==========================================================================
   tt-login.js  -  timetable-login.html only.

   Login is by "NUML email or ID" (backend field `identifier`): a student's
   numl-f24-26789 or full NUML email, a staff member's NUML email, or an
   older roll number / staff ID (Login & Signup Plan, 12 Nov). The browser
   never decides whether a password
   is right: it sends both to POST /api/auth/login and believes the answer.
   The 8-character check below is only a hint so nobody waits for a server
   round trip on an obviously short password.
   ========================================================================== */
(function () {
  "use strict";

  const C = window.TT_CONFIG;
  const form = document.getElementById("ttLoginForm");
  const idInput = document.getElementById("identifier");
  const pwInput = document.getElementById("password");
  const btn = document.getElementById("ttLoginBtn");
  const msg = document.getElementById("ttLoginMsg");

  function show(text, isError) {
    msg.textContent = text;
    msg.className = isError ? "msg error" : "msg";
  }

  function goToStart(role) {
    const page = C.START_PAGE[role];
    if (!page) {
      show("Your account role (" + role + ") has no timetable page.", true);
      return;
    }
    window.location.replace(page);
  }

  // Tell the tester which mode is running, so a mock demo is never mistaken
  // for the real backend.
  if (window.TTApi.isMock) {
    document.getElementById("ttModeNote").textContent =
      "Demo mode: sample data, no real backend. See frontend/README.md for the demo accounts.";
  }

  /* Already logged in? Ask the server, not localStorage. redirectOn401:false
     because we are already on the login page; a stale token is just removed. */
  if (window.TTApi.getToken()) {
    window.TTApi.request("GET", "/api/auth/me", { redirectOn401: false })
      .then((me) => goToStart(me.role))
      .catch((err) => {
        if (err.status === 401) window.TTApi.clearToken();
      });
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const identifier = idInput.value.trim();
    const password = pwInput.value;

    if (!identifier) {
      show("Enter your NUML email or ID.", true);
      idInput.focus();
      return;
    }
    if (password.length < C.MIN_PASSWORD) {
      show("Password must be at least " + C.MIN_PASSWORD + " characters.", true);
      pwInput.focus();
      return;
    }

    btn.disabled = true;
    show("Logging in…", false);
    try {
      // redirectOn401:false - a wrong password is a 401 and must show a message here.
      const data = await window.TTApi.request("POST", "/api/auth/login", {
        body: { identifier, password },
        redirectOn401: false
      });
      window.TTApi.setToken(data.token);   // the token only, never the user object
      goToStart(data.user.role);
    } catch (err) {
      // Same message for every wrong login, so nobody can find out which
      // accounts exist. The one exception is a staff account that is still
      // waiting for approval (only after the right password).
      if (err.status === 401) show("Invalid email/ID or password", true);
      else if (err.status === 403) show("Your account is waiting for approval", true);
      else if (err.status === 429) show("Too many attempts. Try again later.", true);
      else show(err.message, true);
      pwInput.value = "";
      pwInput.focus();
    } finally {
      btn.disabled = false;
    }
  });
})();
