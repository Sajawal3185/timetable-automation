/* ==========================================================================
   dashboard.js  -  needs common.js loaded first.
   common.js already sends logged-out users to the login page.
   ========================================================================== */
const user = NUML.getUser();

if (user) {
  const initials = user.name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();

  const firstName = user.name.trim().split(/\s+/)[0];

  const setText = (id, text) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  };

  setText("userName", user.name);
  setText("userId", user.identifier ? "@" + user.identifier : "");
  setText("userAvatar", initials);
  setText("greeting", "HEY, " + firstName.toUpperCase());
}

/* ===== Profile menu (Log out) ===== */
const profileBtn = document.getElementById("profileBtn");
const profileMenu = document.getElementById("profileMenu");

if (profileBtn && profileMenu) {
  profileBtn.addEventListener("click", () => {
    const open = profileMenu.hidden;
    profileMenu.hidden = !open;
    profileBtn.setAttribute("aria-expanded", open);
  });

  document.addEventListener("click", (e) => {
    if (!e.target.closest(".profile-wrap")) {
      profileMenu.hidden = true;
      profileBtn.setAttribute("aria-expanded", false);
    }
  });
}

/* Log out: clear the session AND navigate. */
const logoutEl = document.querySelector(".dash-logout");
if (logoutEl) {
  logoutEl.addEventListener("click", (e) => {
    e.preventDefault();
    NUML.logout();
  });
}