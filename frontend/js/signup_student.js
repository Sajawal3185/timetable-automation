document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("signupForm");
  const message = document.getElementById("signupMessage");

  const firstName = document.getElementById("firstName");
  const lastName = document.getElementById("lastName");
  const email = document.getElementById("email");
  const password = document.getElementById("password");
  const confirmPassword = document.getElementById("confirmPassword");

  const fields = [firstName, lastName, email, password, confirmPassword];

  // Email format: something@something.domain
  const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function showMessage(text, type) {
    message.textContent = text;
    message.style.color = type === "success" ? "#16a34a" : "#dc2626";
    message.style.display = "block";
  }

  function markInvalid(field) {
    field.style.borderColor = "#dc2626";
    field.focus();
  }

  function clearMarks() {
    fields.forEach(function (f) {
      f.style.borderColor = "";
    });
  }

    
  fields.forEach(function (f) {
    f.addEventListener("input", function () {
      f.style.borderColor = "";
      message.textContent = "";
    });
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    clearMarks();

    const data = {
      firstName: firstName.value.trim(),
      lastName: lastName.value.trim(),
      email: email.value.trim().toLowerCase(),
      password: password.value,
      confirmPassword: confirmPassword.value
    };

    // 1. Empty fields check
    const emptyFields = fields.filter(function (f) {
      return f.value.trim() === "";
    });

    if (emptyFields.length > 0) {
      emptyFields.forEach(function (f) {
        f.style.borderColor = "#dc2626";
      });
      emptyFields[0].focus();
      showMessage("Please fill in all the fields.", "error");
      return;
    }

    // 2. Name validation (only letters, spaces, hyphens, apostrophes)
    const namePattern = /^[A-Za-z\s.'-]+$/;
    if (!namePattern.test(data.firstName)) {
      markInvalid(firstName);
      showMessage("First name should contain only letters.", "error");
      return;
    }
    if (!namePattern.test(data.lastName)) {
      markInvalid(lastName);
      showMessage("Last name should contain only letters.", "error");
      return;
    }

    // 3. Email format
    if (!emailPattern.test(data.email)) {
      markInvalid(email);
      showMessage("Please enter a valid email address (e.g. name@example.com).", "error");
      return;
    }

    // 4. Password length
    if (data.password.length < 8) {
      markInvalid(password);
      showMessage("Password must be at least 8 characters long.", "error");
      return;
    }

    // 5. Password match
    if (data.password !== data.confirmPassword) {
      markInvalid(confirmPassword);
      showMessage("Password and Confirm Password do not match.", "error");
      return;
    }


    // 6. Next step: email verification.
    // FRONTEND-ONLY DEMO: AuthService.startSignup() sends no email and does not
    // store the password (see auth-service.js). The verify page asks for the code.
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    showMessage("Sending your verification code…", "success");

    AuthService.startSignup(data)
      .then(function () {
        window.location.href = NUML.PAGES.verify;
      })
      .catch(function (err) {
        submitBtn.disabled = false;
        showMessage(err.message || "Something went wrong. Please try again.", "error");
        console.error(err);
      });
  });
});