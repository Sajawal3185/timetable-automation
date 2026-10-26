/* ==========================================================================
   signup.js  -  SIGNUP page only. Requires common.js loaded first.
   Changes: uses the shared EMAIL_PATTERN / password rule, saves a profile
   (name + department, NO password) so login can greet the user by name, and
   no longer console.logs the plain-text password.
   ========================================================================== */
const emailInput = document.getElementById("email");
const emailHint = document.getElementById("emailHint");
const msg = document.getElementById("msg");

function checkEmail() {
  const value = emailInput.value.trim();

  if (!value) {
    emailHint.textContent = "Format: numl-f24-systemid@numls.edu.pk";
    emailHint.className = "hint";
    emailInput.classList.remove("invalid");
    return null;
  }

  const info = NUML.parseEmail(value);

  if (!info) {
    emailHint.textContent = "Use your NUML email: numl-f26-12345@numls.edu.pk";
    emailHint.className = "hint error";
    emailInput.classList.add("invalid");
    return null;
  }

  emailHint.textContent = `Valid: ${info.term} ${info.year}, ID ${info.systemId}`;
  emailHint.className = "hint ok";
  emailInput.classList.remove("invalid");
  return info;
}

emailInput.addEventListener("input", checkEmail);

function showMsg(text, isError) {
  msg.textContent = text;
  msg.className = isError ? "msg error" : "msg";
}

function signup(event) {
  event.preventDefault();

  const name = document.getElementById("name").value.trim();
  const department = document.getElementById("department").value;
  const password = document.getElementById("password").value;
  const confirm = document.getElementById("confirm").value;
  const info = checkEmail();

  if (name.length < 3) return showMsg("Enter your full name.", true);
  if (!info) return showMsg("Only NUML student emails are allowed.", true);
  if (!department) return showMsg("Select your department.", true);
  if (password.length < NUML.MIN_PASSWORD) {
    return showMsg(`Password must be at least ${NUML.MIN_PASSWORD} characters.`, true);
  }
  if (password !== confirm) return showMsg("Passwords do not match.", true);

  const email = emailInput.value.trim().toLowerCase();

  // Profile only: the password never touches the browser.
  NUML.saveProfile({
    name,
    email,
    department,
    systemId: info.systemId,
    batch: `${info.term} ${info.year}`
  });

  // TODO (backend): POST { name, email, department, password } to /auth/signup over HTTPS.
  showMsg("Account created. Redirecting to login...", false);
  setTimeout(() => (window.location.href = NUML.PAGES.login), 1500);
}
