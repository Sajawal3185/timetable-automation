/* archive-verify-code.js - needs common.js, then auth-service.js.
   FRONTEND-ONLY DEMO (see auth-service.js): no email is sent, the code is
   always 123456.

   One page for BOTH flows:
     signup:  signup_student.html        -> this page -> archive-login.html
     reset:   archive-forgot-password.html -> this page -> archive-set-new-password.html
   Which one it is comes from AuthService.getPendingFlow() (set by the page
   before this one). Opened directly with nothing pending, it goes to login. */
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const form = $("verifyForm");
  const msg = $("vMsg");
  const btn = $("vBtn");
  const resendBtn = $("vResend");
  let resendTimer = null;

  const flow = AuthService.getPendingFlow();
  if (!flow) {
    window.location.replace(NUML.PAGES.login);
    return;
  }

  const isSignup = flow.purpose === "signup";
  $("vEmail").textContent = flow.email;
  $("vBack").textContent = isSignup ? "← Change details" : "← Different email";

  const demo = $("vDemo");
  demo.hidden = false;
  demo.textContent = AuthService.demoNotes.verify;

  function show(text, isError) {
    msg.textContent = text || "";
    msg.className = isError ? "msg error" : "msg";
  }

  /* ---- Resend code (wait before it can be used again) ---- */
  function startResendWait(seconds) {
    let left = seconds;
    clearInterval(resendTimer);
    if (left <= 0) {
      resendBtn.disabled = false;
      resendBtn.textContent = "Resend code";
      return;
    }
    resendBtn.disabled = true;
    resendBtn.textContent = "Resend code (" + left + "s)";
    resendTimer = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(resendTimer);
        resendBtn.disabled = false;
        resendBtn.textContent = "Resend code";
      } else {
        resendBtn.textContent = "Resend code (" + left + "s)";
      }
    }, 1000);
  }
  startResendWait(AuthService.resendSecondsLeft());

  resendBtn.addEventListener("click", async function () {
    resendBtn.disabled = true;
    try {
      await AuthService.resendCode();
      show("A new code is on its way.");
      startResendWait(AuthService.RESEND_SECONDS);
    } catch (err) {
      show(err.message, true);
      startResendWait(AuthService.resendSecondsLeft());
    }
  });

  /* ---- Go back to the previous step ---- */
  $("vBack").addEventListener("click", function () {
    clearInterval(resendTimer);
    window.location.href = isSignup ? NUML.PAGES.signup : NUML.PAGES.forgot;
  });

  /* ---- Check the code ---- */
  $("vCode").addEventListener("input", function () { show(""); });

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    const code = $("vCode").value.trim();

    if (!new RegExp("^\\d{" + AuthService.CODE_LENGTH + "}$").test(code)) {
      show("Enter the " + AuthService.CODE_LENGTH + "-digit code from the email.", true);
      $("vCode").focus();
      return;
    }

    btn.disabled = true;
    show("Checking…");
    try {
      const result = await AuthService.verifyCode(code);
      clearInterval(resendTimer);

      if (result.purpose === "reset") {
        show("Code verified. Opening the next page…");
        window.location.replace(NUML.PAGES.setPassword);
        return;
      }

      /* signup: account is ready, show the message, then go to login */
      form.hidden = true;
      $("vLinks").hidden = true;
      $("vIntro").hidden = true;
      $("vDone").hidden = false;
      setTimeout(function () { window.location.replace(NUML.PAGES.login); }, 2500);
    } catch (err) {
      show(err.message, true);
      $("vCode").select();
      btn.disabled = false;
    }
  });
})();
