/* ==========================================================================
   auth.js  -  LOGIN and FORGOT-PASSWORD pages only.
   Signup now lives ONLY in signup.js. Having signup/checkEmail/EMAIL_PATTERN
   in both files was a landmine: load both on one page and the duplicate
   `const EMAIL_PATTERN` throws a SyntaxError and nothing works.
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

// ---------- Forgot password ----------
function forgotPassword(event) {
  event.preventDefault();

  const email = document.getElementById("email").value.trim();
  const msg = document.getElementById("msg");

  if (!email.toLowerCase().endsWith("@numls.edu.pk")) {
    msg.textContent = "Please enter your NUML email";
    msg.className = "msg error";
    return;
  }

  // Same wording whether or not the account exists (no account enumeration).
  msg.textContent = "If this email is registered, a reset link has been sent.";
  msg.className = "msg";
}
