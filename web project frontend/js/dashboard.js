/* ===== Logged-in user =====
   The login / signup page saves the student like this:
   localStorage.setItem("numlUser", JSON.stringify({ name: studentName, id: rollNo }));
   (studentName and rollNo come from your NUML login / signup data)
*/
let user = null;
try {
  user = JSON.parse(localStorage.getItem("numlUser"));
} catch (e) {}

/* Not logged in -> go to the login page */
if (!user || !user.name) {
  window.location.replace("login.html");
} else {
  const initials = user.name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();

  const firstName = user.name.trim().split(/\s+/)[0];

  document.getElementById("userName").textContent = user.name;
  document.getElementById("userId").textContent = user.id ? "@" + user.id : "";
  document.getElementById("userAvatar").textContent = initials;
  document.getElementById("greeting").textContent = "HEY, " + firstName.toUpperCase();
}

/* ===== Profile menu (Log out) ===== */
const profileBtn = document.getElementById("profileBtn");
const profileMenu = document.getElementById("profileMenu");

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

/* Log out: clear the saved user, then the link goes to login.html */
document.querySelector(".logout").addEventListener("click", () => {
  localStorage.removeItem("numlUser");
});