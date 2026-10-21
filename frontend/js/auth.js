
 // Accepts: numl-f24-12345@numls.edu.pk or numl-s26-12345@numls.edu.pk
const EMAIL_PATTERN = /^numl-([fs])(\d{2})-(\d+)@numls\.edu\.pk$/i;

// ---------- Login ----------
function login(event) {
  event.preventDefault();

  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;

  if (!email.endsWith("@numls.edu.pk") || password.length < 6) {
    alert("Invalid email or password. Password must be at least 6 characters.");
    return;
  }

  window.location.href = "dashboard.html";
}

// ---------- Signup ----------
function checkEmail() {
  const emailInput = document.getElementById("email");
  const emailHint = document.getElementById("emailHint");
  const value = emailInput.value.trim();
  const match = value.match(EMAIL_PATTERN);

  if (!value) {
    emailHint.textContent = "Format: numl-f24-systemid@numls.edu.pk";
    emailHint.className = "hint";
    emailInput.classList.remove("invalid");
    return null;
  }

  if (!match) {
    emailHint.textContent = "Use your NUML email: numl-f24-systemid@numls.edu.pk";
    emailHint.className = "hint error";
    emailInput.classList.add("invalid");
    return null;
  }

  const term = match[1].toLowerCase() === "f" ? "Fall" : "Spring";

  emailHint.textContent = `Valid: ${term} 20${match[2]}, ID ${match[3]}`;
  emailHint.className = "hint ok";
  emailInput.classList.remove("invalid");

  return {
    term,
    year: "20" + match[2],
    systemId: match[3]
  };
}

function showMsg(text, isError) {
  const msg = document.getElementById("msg");
  msg.textContent = text;
  msg.className = isError ? "msg error" : "msg";
}

function signup(event) {
  event.preventDefault();

  const name = document.getElementById("name").value.trim();
  const email = document.getElementById("email").value.trim().toLowerCase();
  const department = document.getElementById("department").value;
  const password = document.getElementById("password").value;
  const confirm = document.getElementById("confirm").value;
  const info = checkEmail();

  if (name.length < 3) {
    return showMsg("Enter your full name.", true);
  }

  if (!info) {
    return showMsg("Only NUML student emails are allowed.", true);
  }

  if (!department) {
    return showMsg("Select your department.", true);
  }

  if (password.length < 6) {
    return showMsg("Password must be at least 6 characters.", true);
  }

  if (password !== confirm) {
    return showMsg("Passwords do not match.", true);
  }

  const user = {
    name,
    email,
    department,
    systemId: info.systemId,
    batch: `${info.term} ${info.year}`,
    password
  };

  // TODO: Send user to the backend (POST /auth/signup).
  // Never store plain passwords in the browser.
  console.log("Signup data:", user);

  showMsg("Account created. Redirecting to login...", false);

  setTimeout(() => {
    window.location.href = "login.html";
  }, 1500);
}

// Live email check, only on the signup page
if (document.getElementById("signupForm")) {
  document.getElementById("email").addEventListener("input", checkEmail);
}

// ---------- Forgot password ----------
function forgotPassword(event) {
  event.preventDefault();

  const email = document.getElementById("email").value.trim();
  const msg = document.getElementById("msg");

  if (!email.endsWith("@numls.edu.pk")) {
    msg.textContent = "Please enter your NUML email";
    msg.className = "msg error";
    return;
  }

  msg.textContent = "If this email is registered, a reset link has been sent.";
  msg.className = "msg";
}
