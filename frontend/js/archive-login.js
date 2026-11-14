/* archive-login.js - needs common.js, then api.js. Real login only: the backend
   (POST /api/auth/login) checks the password and returns the role. The Student /
   Admin tabs only choose where you land; they never grant any permission. */
(function () {
  const form = document.getElementById("loginForm");
  const idInput = document.getElementById("identifier");
  const pwInput = document.getElementById("password");
  const msg = document.getElementById("msg");
  const btn = document.getElementById("loginBtn");
  const tabStudent = document.getElementById("tabStudent");
  const tabAdmin = document.getElementById("tabAdmin");
  const signupLine = document.getElementById("signupLine");
  let mode = "student";

  function setMode(next) {
    mode = next;
    tabStudent.setAttribute("aria-selected", String(next === "student"));
    tabAdmin.setAttribute("aria-selected", String(next === "admin"));
    signupLine.hidden = next === "admin";
    msg.textContent = "";
  }
  tabStudent.addEventListener("click", () => setMode("student"));
  tabAdmin.addEventListener("click", () => setMode("admin"));

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
    if (!NUML.isLive) return fail("The backend is not connected, so login is unavailable.");

    btn.disabled = true;
    btn.textContent = "Logging in…";
    try {
      const data = await NUML.api("/api/auth/login", { method: "POST", body: { identifier, password } });
      const u = data.user;
      if (mode === "admin" && !NUML.isAdmin(u)) return fail("This account does not have admin access.");
      if (u.role === "teacher") return fail("Staff accounts use the Timetable login.");

      NUML.setToken(data.token);
      NUML.setUser({ id: u.id, name: u.name, role: u.role, identifier: u.identifier });
      window.location.replace(mode === "admin" ? NUML.PAGES.reviewUploads : NUML.PAGES.dashboard);
    } catch (err) {
      fail(err.status === 401 ? "Incorrect email address or password." : "Could not reach the server. Try again in a minute.");
    } finally {
      btn.disabled = false;
      btn.textContent = "Log in";
    }
  });
})();