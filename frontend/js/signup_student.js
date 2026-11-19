
document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("signupForm");
  const message = document.getElementById("signupMessage");

  const firstName = document.getElementById("firstName");
  const lastName = document.getElementById("lastName");
  const email = document.getElementById("email");
  const password = document.getElementById("password");
  const confirmPassword = document.getElementById("confirmPassword");

  const fields = [
    firstName,
    lastName,
    email,
    password,
    confirmPassword
  ];

  // Only valid NUML student email format
  // Example: numl-f24-systemid@numls.edu.pk
  const emailPattern = /^numl-[fs]\d{2}-\d{5}@numls\.edu\.pk$/;

  function showMessage(text, type) {
    message.textContent = text;
    message.style.color =
      type === "success" ? "#16a34a" : "#dc2626";
    message.style.display = "block";
  }

  function markInvalid(field) {
    field.style.borderColor = "#dc2626";
    field.focus();
  }

  function clearMarks() {
    fields.forEach(function (field) {
      field.style.borderColor = "";
    });
  }

  // Clear error when user edits a field
  fields.forEach(function (field) {
    field.addEventListener("input", function () {
      field.style.borderColor = "";
      message.textContent = "";
    });
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    clearMarks();

    const data = {
      firstName: firstName.value.trim(),
      lastName: lastName.value.trim(),
      email: email.value.trim(),
      password: password.value,
      confirmPassword: confirmPassword.value
    };

    // 1. Empty fields validation
    const emptyFields = fields.filter(function (field) {
      return field.value.trim() === "";
    });

    if (emptyFields.length > 0) {
      emptyFields.forEach(function (field) {
        field.style.borderColor = "#dc2626";
      });

      emptyFields[0].focus();
      showMessage("Please fill in all the fields.", "error");
      return;
    }

    // 2. Name validation
    const namePattern = /^[A-Za-z\s.'-]+$/;

    if (!namePattern.test(data.firstName)) {
      markInvalid(firstName);
      showMessage(
        "First name should contain only letters.",
        "error"
      );
      return;
    }

    if (!namePattern.test(data.lastName)) {
      markInvalid(lastName);
      showMessage(
        "Last name should contain only letters.",
        "error"
      );
      return;
    }

    // 3. NUML student email validation
    if (!emailPattern.test(data.email)) {
      markInvalid(email);
      showMessage(
        "Only valid NUML student emails are allowed (e.g. numl-f24-systemid@numls.edu.pk).",
        "error"
      );
      return;
    }
data.email = data.email.toLowerCase();
    // 4. Password length validation
    if (data.password.length < 8) {
      markInvalid(password);
      showMessage(
        "Password must be at least 8 characters long.",
        "error"
      );
      return;
    }

    // 5. Confirm password validation
    if (data.password !== data.confirmPassword) {
      markInvalid(confirmPassword);
      showMessage(
        "Password and Confirm Password do not match.",
        "error"
      );
      return;
    }

    // 6. Check existing account
    try {
      const users = JSON.parse(
        localStorage.getItem("numlUsers") || "[]"
      );

      const alreadyExists = users.some(function (user) {
        return user.email === data.email;
      });

      if (alreadyExists) {
        markInvalid(email);
        showMessage(
          "An account with this email already exists.",
          "error"
        );
        return;
      }

      // 7. Create new account
      users.push({
        firstName: data.firstName,
        lastName: data.lastName,
        email: data.email,
        password: data.password
      });

      localStorage.setItem(
        "numlUsers",
        JSON.stringify(users)
      );

      form.reset();

      showMessage(
        "Your account has been created successfully!",
        "success"
      );

    } catch (err) {
      showMessage(
        "Something went wrong. Please try again.",
        "error"
      );

      console.error(err);
    }
  });
});
