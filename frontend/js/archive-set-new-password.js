/* archive-set-new-password.js - needs common.js, then auth-service.js.
   FRONTEND-ONLY DEMO (see auth-service.js): the new password is checked
   here for length and for matching, but it is NOT saved anywhere.

   Flow: archive-forgot-password.html -> archive-verify-code.html -> this page
         -> success message -> archive-login.html
   Opened without a verified code, this page sends the person back. (A
   convenience only: the real check must be done by the backend.) */
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const form = $("newPasswordForm");
  const msg = $("npMsg");
  const btn = $("npBtn");
  const pw = $("npPassword");
  const pw2 = $("npPassword2");

  if (!AuthService.canSetNewPassword()) {
    /* Code not entered yet -> the code page. Nothing started -> the start. */
    const pending = AuthService.getPendingFlow();
    window.location.replace(pending && pending.purpose === "reset" ? NUML.PAGES.verify : NUML.PAGES.forgot);
    return;
  }

  $("npEmail").textContent = AuthService.getPendingFlow().email;

  const demo = $("npDemo");
  demo.hidden = false;
  demo.textContent = AuthService.demoNotes.setPassword;

  function show(text, isError) {
    msg.textContent = text || "";
    msg.className = isError ? "msg error" : "msg";
  }

  [pw, pw2].forEach(function (field) {
    field.addEventListener("input", function () {
      field.classList.remove("invalid");
      show("");
    });
  });

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    pw.classList.remove("invalid");
    pw2.classList.remove("invalid");

    /* Quick checks first, so the first problem is shown right away */
    const problem = AuthService.checkNewPassword(pw.value, pw2.value);
    if (problem) {
      const field = problem.indexOf("match") !== -1 || problem.indexOf("Confirm") === 0 ? pw2 : pw;
      field.classList.add("invalid");
      field.focus();
      show(problem, true);
      return;
    }

    btn.disabled = true;
    show("Changing…");
    try {
      await AuthService.setNewPassword(pw.value, pw2.value);
      form.hidden = true;
      $("npLinks").hidden = true;
      $("npDone").hidden = false;
      setTimeout(function () { window.location.replace(NUML.PAGES.login); }, 2500);
    } catch (err) {
      show(err.message, true);
      btn.disabled = false;
    }
  });
})();
