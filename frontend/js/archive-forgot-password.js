/* archive-forgot-password.js - calls POST /api/auth/forgot-password. The backend does not
   have this endpoint yet, so until it does the page says so instead of pretending. */
(function () {
  const form = document.getElementById("forgotPasswordForm");
  const input = document.getElementById("email");
  const msg = document.getElementById("resetMessage");
  const btn = document.getElementById("sendBtn");

  function show(text, isError) {
    msg.textContent = text;
    msg.className = "msg" + (isError ? " error" : "");
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    const identifier = input.value.trim();
    if (!identifier) return show("Enter your email or ID.", true);
    if (!NUML.isLive) return show("The backend is not connected, so reset is unavailable.", true);

    btn.disabled = true;
    try {
      await NUML.api("/api/auth/forgot-password", { method: "POST", body: { identifier } });
      show("If that account exists, a reset link has been sent. Check your email.", false);
    } catch (err) {
      if (err.status === 404 || err.status === 405) {
        show("Password reset is not available yet. Ask a group admin to reset your password.", true);
      } else {
        show("Could not send the reset link. Try again in a minute.", true);
      }
    } finally {
      btn.disabled = false;
    }
  });
})();
