// ---------- Login ----------
function login(event) {
  event.preventDefault();
  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;

  if (!email.endsWith("@numls.edu.pk") || password.length < 6) {
    alert("Invalid email or password");
    return;
  }
  window.location.href = "dashboard.html";
}

// ---------- Signup ----------
function signup(event) {
  event.preventDefault();
  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;
  const confirm = document.getElementById("confirm").value;

  if (!email.endsWith("@numl.edu.pk")) {
    alert("Please use your NUML email (example: you@numl.edu.pk)");
    return;
  }
  if (password.length < 6) {
    alert("Password must be at least 6 characters");
    return;
  }
  if (password !== confirm) {
    alert("Passwords do not match");
    return;
  }
  alert("Account created! Please log in.");
  window.location.href = "login.html";
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