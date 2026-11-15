/* ==========================================================================
   dashboard.js  -  needs common.js and api.js loaded first.
   Used by dashboard.html and admin-dashboard.html.
   Names go in with textContent, never innerHTML.
   ========================================================================== */

const isAdminPage = document.body.classList.contains("admin-page");

/* Admin page only: hides it from other roles. The backend checks the role
   on every admin endpoint. */
if (isAdminPage && !NUML.isAdmin(NUML.getUser())) {
  window.location.replace(NUML.PAGES.dashboard);
}

function setText(id, text) {
  const node = document.getElementById(id);
  if (node) node.textContent = text;
}


/* ===== 1. Greeting and profile button ===== */
function renderUser(user) {
  if (!user) return;

  const name = String(user.name || "").trim() || String(user.identifier || "").trim();
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
  const firstName = name.split(/\s+/)[0];
  const handle = user.identifier ? "@" + user.identifier : "";

  if (firstName) setText("greeting", "Hey " + firstName);
  setText("userName", firstName);
  setText("userAvatar", initials);
  setText("menuName", name);
  setText("userId", handle);
}

/* Show the saved user at once, then replace it with the server's answer.
   A fake saved user gets a 401, and api.js sends it to the login page. */
renderUser(NUML.getUser());

if (typeof NUML.verifySession === "function") {
  NUML.verifySession().then((u) => {
    renderUser(u);
    if (isAdminPage && u && !NUML.isAdmin(u)) {
      window.location.replace(NUML.PAGES.dashboard);
    }
  });
}


/* ===== 2. Today's date ===== */
setText("today", new Date().toLocaleDateString("en-GB", {
  weekday: "long", day: "numeric", month: "long", year: "numeric"
}));


/* ===== 3. Profile menu and Log out ===== */
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

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !profileMenu.hidden) {
      profileMenu.hidden = true;
      profileBtn.setAttribute("aria-expanded", false);
      profileBtn.focus();
    }
  });
}

/* Log out: clear the session AND navigate. querySelectorAll, so BOTH links
   work (profile menu and sidebar). */
document.querySelectorAll(".dash-logout").forEach((link) => {
  link.addEventListener("click", (e) => {
    e.preventDefault();
    NUML.logout();
  });
});