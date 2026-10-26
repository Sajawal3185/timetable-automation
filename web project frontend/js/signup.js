// Accepts: numl-f24-12345@numls.edu.pk  (f = fall, s = spring, 2-digit year, numeric system ID)
const EMAIL_PATTERN = /^numl-([fs])(\d{2})-(\d+)@numls\.edu\.pk$/i;

const emailInput = document.getElementById("email");
const emailHint = document.getElementById("emailHint");
const msg = document.getElementById("msg");

function checkEmail() {
  const value = emailInput.value.trim();
  const match = value.match(EMAIL_PATTERN);

  if (!value) {
    emailHint.textContent = "Format: numl-f24-systemid@numls.edu.pk";
    emailHint.className = "hint";
    emailInput.classList.remove("invalid");
    return null;
  }

  if (!match) {
    emailHint.textContent = "Use your NUML email: numl-f26-12345@numls.edu.pk";
    emailHint.className = "hint error";
    emailInput.classList.add("invalid");
    return null;
  }

  const term = match[1].toLowerCase() === "f" ? "Fall" : "Spring";
  emailHint.textContent = `Valid: ${term} 20${match[2]}, ID ${match[3]}`;
  emailHint.className = "hint ok";
  emailInput.classList.remove("invalid");
  return { term, year: "20" + match[2], systemId: match[3] };
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
  if (password.length < 8) return showMsg("Password must be at least 8 characters.", true);
  if (password !== confirm) return showMsg("Passwords do not match.", true);

  const user = {
    name,
    email: emailInput.value.trim().toLowerCase(),
    department,
    systemId: info.systemId,
    batch: `${info.term} ${info.year}`,
    password
  };

  // TODO: send `user` to the backend (POST /auth/signup). Never store plain passwords in the browser.
  console.log("Signup data:", user);

  showMsg("Account created. Redirecting to login...", false);
  setTimeout(() => (window.location.href = "login.html"), 1500);
}