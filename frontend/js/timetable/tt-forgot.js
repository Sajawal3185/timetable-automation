/* ==========================================================================
   tt-forgot.js  -  forgot-password.html (Login & Signup Plan, 12 Nov).

   Everyone resets their own password with a code sent to the email they
   signed up with (this replaces "contact your department admin"):
     1. The person types their NUML email or ID.
        POST /api/auth/forgot-password always gives the same answer, so this
        page can't be used to check who is registered.
     2. They type the 6-digit code and a new password twice.
        POST /api/auth/reset-password changes it; the code can't be used again.

   Every check here is only a hint; the backend rechecks all of them.
   ========================================================================== */
(function () {
  "use strict";

  const C = window.TT_CONFIG;
  const A = C.AUTH;
  const $ = (id) => document.getElementById(id);

  const steps = { id: $("fpIdStep"), reset: $("fpResetStep"), done: $("fpDone") };
  let identifier = "";
  let resendTimer = null;

  function show(node, text, isError) {
    node.textContent = text || "";
    node.className = isError ? "msg error" : "msg";
  }

  function goTo(step) {
    Object.keys(steps).forEach((k) => { steps[k].hidden = k !== step; });
    $("fpLinks").hidden = step === "done";
    const first = steps[step].querySelector("input, a");
    if (first) first.focus();
  }

  if (window.TTApi.isMock) {
    const note = $("fpDemo");
    note.hidden = false;
    note.textContent = "Demo mode: no email is really sent. The code is always " + A.MOCK_CODE +
      ". Only accounts made on the Create account page have an email; the sample accounts don't.";
  }

  function startResendWait() {
    const btn = $("fpResend");
    let left = A.RESEND_SECONDS;
    clearInterval(resendTimer);
    btn.disabled = true;
    btn.textContent = "Resend code (" + left + "s)";
    resendTimer = setInterval(() => {
      left -= 1;
      if (left <= 0) {
        clearInterval(resendTimer);
        btn.disabled = false;
        btn.textContent = "Resend code";
      } else {
        btn.textContent = "Resend code (" + left + "s)";
      }
    }, 1000);
  }

  /* ---- Step 1: email or ID ---------------------------------------------- */
  steps.id.addEventListener("submit", async (event) => {
    event.preventDefault();
    const msg = $("fpIdMsg");
    const typed = $("fpId").value.trim().toLowerCase();
    if (!typed) { show(msg, "Enter your NUML email or ID.", true); return; }

    const btn = $("fpIdBtn");
    btn.disabled = true;
    show(msg, "Sending…");
    try {
      await window.TTApi.request("POST", "/api/auth/forgot-password", { body: { identifier: typed }, redirectOn401: false });
      identifier = typed;
      show(msg, "");
      $("fpIdShown").textContent = identifier;
      ["fpCode", "fpPassword", "fpPassword2"].forEach((id) => { $(id).value = ""; });
      show($("fpResetMsg"), "");
      startResendWait();
      goTo("reset");
    } catch (err) {
      show(msg, err.status === 429 ? "Too many attempts. Try again later." : err.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  /* ---- Step 2: code + new password -------------------------------------- */
  $("fpBack").addEventListener("click", () => { clearInterval(resendTimer); goTo("id"); });

  $("fpResend").addEventListener("click", async () => {
    const msg = $("fpResetMsg");
    try {
      // Same endpoint as step 1: it sends at most one code a minute.
      await window.TTApi.request("POST", "/api/auth/forgot-password", { body: { identifier }, redirectOn401: false });
      show(msg, "If that account exists, a new code is on its way.");
      startResendWait();
    } catch (err) {
      show(msg, err.status === 429 ? "Please wait a minute before asking for a new code." : err.message, true);
    }
  });

  steps.reset.addEventListener("submit", async (event) => {
    event.preventDefault();
    const msg = $("fpResetMsg");
    const code = $("fpCode").value.trim();
    const pw = $("fpPassword").value;
    const pw2 = $("fpPassword2").value;

    if (!new RegExp("^\\d{" + A.CODE_LENGTH + "}$").test(code)) {
      show(msg, "Enter the " + A.CODE_LENGTH + "-digit code from the email.", true);
      $("fpCode").focus();
      return;
    }
    if (pw.length < C.MIN_PASSWORD || new TextEncoder().encode(pw).length > C.MAX_PASSWORD) {
      show(msg, "Password must be " + C.MIN_PASSWORD + " to " + C.MAX_PASSWORD + " characters.", true);
      $("fpPassword").focus();
      return;
    }
    if (pw !== pw2) { show(msg, "The two passwords don't match.", true); $("fpPassword2").focus(); return; }

    const btn = $("fpResetBtn");
    btn.disabled = true;
    show(msg, "Changing…");
    try {
      await window.TTApi.request("POST", "/api/auth/reset-password", {
        body: { identifier, code, new_password: pw },
        redirectOn401: false
      });
      clearInterval(resendTimer);
      window.TTApi.clearToken();   // any old session ends with the old password
      goTo("done");
    } catch (err) {
      show(msg, err.status === 429 ? "Too many attempts. Try again later." : err.message, true);
      $("fpCode").select();
    } finally {
      btn.disabled = false;
    }
  });
})();
