/* archive-login.js - needs common.js, then auth-service.js.

   ONE login page for everyone (students and the Review Team).
   There are no Student / Admin tabs any more: the ROLE decides where you go.
     student               -> dashboard.html
     admin (Review Team)   -> archive-review-uploads.html
   Where each role goes is the START_PAGE table in common.js
   (NUML.redirectByRole), so it is not repeated here.

   FRONTEND-ONLY DEMO: AuthService.login() in auth-service.js is a stand-in.
   It sends nothing to a server and checks no real password. When the
   backend is connected, only that function changes; this page stays. */
(function () {
  "use strict";

  const form = document.getElementById("loginForm");
  const idInput = document.getElementById("identifier");
  const pwInput = document.getElementById("password");
  const msg = document.getElementById("msg");
  const btn = document.getElementById("loginBtn");

  /* Coming back to the login page cancels any half-finished signup/reset. */
  AuthService.clearPendingFlow();

  const demo = document.getElementById("loginDemo");
  demo.hidden = false;
  demo.textContent = AuthService.demoNotes.login;

  const toggle = document.getElementById("togglePw");
  toggle.addEventListener("click", function () {
    const show = pwInput.type === "password";
    pwInput.type = show ? "text" : "password";
    toggle.textContent = show ? "Hide" : "Show";
    toggle.setAttribute("aria-pressed", String(show));
    toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
  });

  function fail(text) { msg.textContent = text; }
  [idInput, pwInput].forEach((i) => i.addEventListener("input", () => { msg.textContent = ""; }));

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    const identifier = idInput.value.trim();
    const password = pwInput.value;
    if (!identifier || !password) return fail("Enter your email address and your password.");

    btn.disabled = true;
    btn.textContent = "Logging in…";
    try {
      const data = await AuthService.login(identifier, password);
      NUML.setToken(data.token);
      NUML.setUser(data.user);
      NUML.redirectByRole(data.user.role);
    } catch (err) {
      fail(err.message || "Could not log in. Try again in a minute.");
      btn.disabled = false;
      btn.textContent = "Log in";
    }
  });
})();
