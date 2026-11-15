/* archive-forgot-password.js - needs common.js, then auth-service.js.

   Step 1 of 3 of "forgot password":
     this page (type your email) -> archive-verify-code.html (type the code)
     -> archive-set-new-password.html (choose a new password) -> login

   FRONTEND-ONLY DEMO: AuthService.startPasswordReset() sends no email and
   asks no server. See auth-service.js. */
(function () {
  "use strict";

  const form = document.getElementById("forgotPasswordForm");
  const input = document.getElementById("email");
  const msg = document.getElementById("resetMessage");
  const btn = document.getElementById("sendBtn");

  function show(text, isError) {
    msg.textContent = text;
    msg.className = "msg" + (isError ? " error" : "");
  }

  input.addEventListener("input", function () { show("", false); });

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    const email = input.value.trim();
    if (!email) return show("Enter your email address.", true);

    btn.disabled = true;
    show("Sending the code…", false);
    try {
      await AuthService.startPasswordReset(email);
      window.location.href = NUML.PAGES.verify;
    } catch (err) {
      show(err.message || "Could not send the code. Try again in a minute.", true);
      btn.disabled = false;
    }
  });
})();
