/* ==========================================================================
   dashboard.js  -  needs common.js, then api.js, loaded first.
   common.js already sends logged-out users to the login page.

   What it fills in:
     1. Greeting and profile button
     2. Today's date
     3. Profile menu and Log out

   Names go in with textContent, never innerHTML, so a name like "<b>"
   stays plain text.
   ========================================================================== */

function setText(id, text) {
  const node = document.getElementById(id);
  if (node) node.textContent = text;
}


/* ===== 1. Greeting and profile button ===== */
const user = NUML.getUser();

if (user) {
  const name = user.name.trim();
  const initials = name
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
  const firstName = name.split(/\s+/)[0];
  const handle = user.identifier ? "@" + user.identifier : "";

  setText("greeting", "Hey " + firstName);
  setText("userName", firstName);
  setText("userAvatar", initials);
  setText("menuName", name);
  setText("userId", handle);
}


/* ===== 2. Today's date ("Wednesday, 11 November 2026") ===== */
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
}

/* Log out: clear the session AND navigate. Without preventDefault the link
   just opens login.html, which sees the saved session and bounces back here. */
document.querySelectorAll(".dash-logout").forEach((link) => {
  link.addEventListener("click", (e) => {
    e.preventDefault();
    NUML.logout();
  });
});
