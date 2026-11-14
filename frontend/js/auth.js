(function () {
  const DASHBOARD_PAGE = "dashboard.html"; 

  const form = document.getElementById("loginForm");
  const msg = document.getElementById("msg");
  const emailInput = document.getElementById("email");
  const passwordInput = document.getElementById("password");

  if (!form) return;

  function showError(text) {
    msg.textContent = text;
    msg.style.color = "#dc2626";
    msg.style.display = "block";
  }

  [emailInput, passwordInput].forEach(function (input) {
    input.addEventListener("input", function () {
      msg.textContent = "";
    });
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();

    const email = emailInput.value.trim().toLowerCase();
    const password = passwordInput.value;

    if (email === "" || password === "") {
      showError("Please enter both email and password.");
      return;
    }

    let users = [];
    try {
      users = JSON.parse(localStorage.getItem("numlUsers") || "[]");
    } catch (err) {
      users = [];
    }

 
    const user = users.find(function (u) {
      return u.email === email && u.password === password;
    });

    if (!user) {
      showError("Incorrect email or password.");
      return;
    }


    sessionStorage.setItem(
      "numlCurrentUser",
      JSON.stringify({
        firstName: user.firstName,
        lastName: user.lastName,
        email: user.email
      })
    );

    window.location.href = DASHBOARD_PAGE;
  });
})();