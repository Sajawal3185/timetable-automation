/* ==========================================================================
   auth.js  -  LOGIN page only.
   The server decides who may log in. The browser never checks passwords.
   Requires common.js, then api.js, to be loaded before this file.
   ========================================================================== */

// Already logged in? Skip the login form.
if (document.getElementById("loginForm") && NUML.getUser() && NUML.getToken()) {
  window.location.replace(NUML.homeFor(NUML.getUser()));
}

const loginForm = document.getElementById("loginForm");

if (loginForm) {
  loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const identifier = document.getElementById("identifier").value.trim();
    const password = document.getElementById("password").value;
    const msg = document.getElementById("msg");
    msg.textContent = "";
    msg.className = "msg";

    if (!identifier || !password) {
      msg.textContent = "Enter your roll number and password";
      msg.className = "msg error";
      return;
    }

    try {
      const data = await NUML.api("/api/auth/login", {
        method: "POST",
        body: { identifier, password }
      });
NUML.setToken(data.token);
      const me = await NUML.api("/api/auth/me");
      NUML.setUser(me);

      // Admins and HODs start on the review page, everyone else on the dashboard.
      window.location.href = NUML.homeFor(me);
    } catch (err) {
      NUML.clearToken();
      msg.textContent =
        err.status === 401
          ? "Invalid roll number or password"
          : "Something went wrong. Try again.";
      msg.className = "msg error";
    }
  });
}