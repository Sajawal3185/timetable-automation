/* ==========================================================================
   auth.js  -  LOGIN page only.
   Signup now lives ONLY in signup.js. Having signup/checkEmail/EMAIL_PATTERN
   in both files was a landmine: load both on one page and the duplicate
   `const EMAIL_PATTERN` throws a SyntaxError and nothing works.
   Forgot password has no code: that page tells users to contact their
   department admin.
   Requires common.js to be loaded before this file.
   ========================================================================== */

// Already logged in? Skip the login form.
if (document.getElementById("loginForm") && NUML.getUser()) {
  window.location.replace(NUML.PAGES.dashboard);
}

// ---------- Login ----------
function login(event) {
  event.preventDefault();

  const email = document.getElementById("email").value.trim().toLowerCase();
  const password = document.getElementById("password").value;

  if (!email.endsWith("@numls.edu.pk") || password.length < NUML.MIN_PASSWORD) {
    alert(`Invalid email or password. Password must be at least ${NUML.MIN_PASSWORD} characters.`);
    return;
  }

  // Name/department were saved at signup (without the password).
  const profile = NUML.findProfile(email);
  const info = NUML.parseEmail(email);
  const fallbackId = info ? info.systemId : email.split("@")[0];

  /* THE FIX for the login<->dashboard loop: dashboard.js refuses to open
     without this saved user, and the old login never saved one. */
  NUML.setUser({
    name: profile ? profile.name : "Student " + fallbackId,
    id: fallbackId,
    email,
    department: profile ? profile.department : ""
  });

  window.location.href = NUML.PAGES.dashboard;
}