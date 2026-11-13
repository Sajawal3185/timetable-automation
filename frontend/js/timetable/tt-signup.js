/* ==========================================================================
   tt-signup.js  -  signup.html (Login & Signup Plan, 12 Nov).

   There is no list of students or teachers from the university, so the
   official NUML email is the proof of identity:
     1. The person types their email. Its format picks the form:
          numl-<letter><yy>-<digits>@numls.edu.pk -> student form
          any other @numls.edu.pk address        -> staff form
          any other domain                        -> refused here
     2. They fill in the form. POST /api/auth/signup sends a 6-digit code to
        that email. Nothing is a real account yet.
     3. They type the code. POST /api/auth/verify-email:
          student -> active, gets a token, goes to their start page
          staff   -> a teacher account waiting for an admin or HOD's approval

   The role is never sent: the backend decides it from the email. Every check
   here is only a hint; the backend rechecks all of them.
   The password is only sent to the server, never stored in the browser.
   ========================================================================== */
(function () {
  "use strict";

  const C = window.TT_CONFIG;
  const A = C.AUTH;
  const $ = (id) => document.getElementById(id);

  const steps = { email: $("suEmailStep"), details: $("suDetailsStep"), code: $("suCodeStep"), pending: $("suPending") };
  let email = "";
  let isStudent = false;
  let resendTimer = null;

  function show(node, text, isError) {
    node.textContent = text || "";
    node.className = isError ? "msg error" : "msg";
  }

  function goTo(step) {
    Object.keys(steps).forEach((k) => { steps[k].hidden = k !== step; });
    // "Already have an account?" only makes sense before the account exists
    $("suLinks").hidden = step === "pending";
    const first = steps[step].querySelector("input, a");
    if (first) first.focus();
  }

  if (window.TTApi.isMock) {
    const note = $("suDemo");
    note.hidden = false;
    note.textContent = "Demo mode: no email is really sent. The code is always " + A.MOCK_CODE +
      ". Accounts you create last until you close this tab.";
  }

  /* ---- Step 1: email ---------------------------------------------------- */
  steps.email.addEventListener("submit", (event) => {
    event.preventDefault();
    const msg = $("suEmailMsg");
    const typed = $("suEmail").value.trim().toLowerCase();
    const at = typed.indexOf("@");

    if (!typed) { show(msg, "Enter your NUML email.", true); return; }
    if (at < 1 || at !== typed.lastIndexOf("@") || !typed.endsWith(A.EMAIL_DOMAIN)) {
      show(msg, "Use your NUML email address (ending in " + A.EMAIL_DOMAIN + ").", true);
      return;
    }

    email = typed;
    isStudent = A.STUDENT_EMAIL.test(email);
    $("suEmailShown").textContent = email;
    $("suKind").textContent = isStudent ? "Student account" : "Staff account";
    $("suStudentFields").hidden = !isStudent;
    $("suStaffNote").hidden = isStudent;
    show(msg, "");
    goTo("details");
  });

  /* ---- Step 2: details -------------------------------------------------- */
  $("suChangeEmail").addEventListener("click", () => goTo("email"));

  steps.details.addEventListener("submit", async (event) => {
    event.preventDefault();
    const msg = $("suDetailsMsg");
    const name = $("suName").value.trim();
    const pw = $("suPassword").value;
    const pw2 = $("suPassword2").value;
    const roll = $("suRoll").value.trim().toLowerCase();

    // Quick checks, in the order of the form, so the first problem is shown
    if (!name) { show(msg, "Enter your full name.", true); $("suName").focus(); return; }
    if (isStudent && !A.ROLL_NUMBER.test(roll)) {
      show(msg, "Roll number should look like se-9244330.", true);
      $("suRoll").focus();
      return;
    }
    if (pw.length < C.MIN_PASSWORD || new TextEncoder().encode(pw).length > C.MAX_PASSWORD) {
      show(msg, "Password must be " + C.MIN_PASSWORD + " to " + C.MAX_PASSWORD + " characters.", true);
      $("suPassword").focus();
      return;
    }
    if (pw !== pw2) { show(msg, "The two passwords don't match.", true); $("suPassword2").focus(); return; }

    const body = { email, name, password: pw };
    if (isStudent) body.roll_number = roll;

    const btn = $("suDetailsBtn");
    btn.disabled = true;
    show(msg, "Sending the code…");
    try {
      await window.TTApi.request("POST", "/api/auth/signup", { body, redirectOn401: false });
      show(msg, "");
      $("suCodeEmail").textContent = email;
      $("suCode").value = "";
      show($("suCodeMsg"), "");
      startResendWait();
      goTo("code");
    } catch (err) {
      if (err.status === 409) show(msg, "This email already has an account. Log in instead.", true);
      else if (err.status === 429) show(msg, "Too many attempts. Try again later.", true);
      else show(msg, err.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  /* ---- Step 3: code ----------------------------------------------------- */
  function startResendWait() {
    const btn = $("suResend");
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

  $("suResend").addEventListener("click", async () => {
    const msg = $("suCodeMsg");
    try {
      await window.TTApi.request("POST", "/api/auth/resend-code", { body: { email }, redirectOn401: false });
      show(msg, "A new code is on its way.");
      startResendWait();
    } catch (err) {
      show(msg, err.status === 429 ? "Please wait a minute before asking for a new code." : err.message, true);
    }
  });

  $("suBack").addEventListener("click", () => goTo("details"));

  steps.code.addEventListener("submit", async (event) => {
    event.preventDefault();
    const msg = $("suCodeMsg");
    const code = $("suCode").value.trim();
    if (!new RegExp("^\\d{" + A.CODE_LENGTH + "}$").test(code)) {
      show(msg, "Enter the " + A.CODE_LENGTH + "-digit code from the email.", true);
      return;
    }

    const btn = $("suCodeBtn");
    btn.disabled = true;
    show(msg, "Checking…");
    try {
      const res = await window.TTApi.request("POST", "/api/auth/verify-email", { body: { email, code }, redirectOn401: false });
      clearInterval(resendTimer);
      if (res.status === "active" && res.token) {
        // Students are active straight away: save the token only and go in.
        window.TTApi.setToken(res.token);
        window.location.replace(C.START_PAGE[res.user.role] || C.PAGES.login);
        return;
      }
      goTo("pending");   // staff: no token until an admin or HOD approves
    } catch (err) {
      if (err.status === 429) show(msg, "Too many attempts. Try again later.", true);
      else show(msg, err.message, true);
      $("suCode").select();
    } finally {
      btn.disabled = false;
    }
  });
})();
