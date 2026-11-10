/* ==========================================================================
   dashboard.js  -  needs common.js, then api.js, loaded first.
   common.js already sends logged-out users to the login page.

   What it fills in:
     1. Greeting, profile button and "Your account" panel
     2. Today's date
     3. Quick access cards (same look as the landing page feature cards)
     4. Upcoming events from GET /api/events (mock: mock/events.json)
     5. Profile menu and Log out

   Everything is built with DOM calls and textContent, never innerHTML,
   so a title like "<b>" from the server stays plain text.
   ========================================================================== */

/* ---- Small DOM helper: el("p", "class", "text") ---- */
function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function setText(id, text) {
  const node = document.getElementById(id);
  if (node) node.textContent = text;
}


/* ===== 1. Greeting, profile button and "Your account" panel ===== */
const ROLE_LABEL = { student: "Student", teacher: "Teacher", hod: "Head of Department", admin: "Admin" };
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

  setText("meAvatar", initials);
  setText("meName", name);
  setText("meId", handle);
  setText("meRole", ROLE_LABEL[user.role] || user.role || "-");
}


/* ===== 2. Today's date ("Wednesday, 11 November 2026") ===== */
setText("today", new Date().toLocaleDateString("en-GB", {
  weekday: "long", day: "numeric", month: "long", year: "numeric"
}));


/* ===== 3. Quick access cards =====
   Same pages as the top bar, same emoji as the landing page features. */
const QUICK_CARDS = [
  { href: "lecture.html", emoji: "🎓", label: "Lectures", desc: "Slides by department, subject and year." },
  { href: "pastpapers.html", emoji: "📄", label: "Past Papers", desc: "Midterm and final papers to practise with." },
  { href: "tools-resources.html", emoji: "💾", label: "Resources", desc: "Software links for your courses, with setup steps." },
  { href: "gpacal.html", emoji: "🧮", label: "GPA & CGPA", desc: "Work out your results in seconds." },
  { href: "timetable/timetable-login.html", emoji: "🗓️", label: "Timetable", desc: "Your week, swaps and makeup classes." }
];

const quickCards = document.getElementById("quickCards");
if (quickCards) {
  QUICK_CARDS.forEach((card) => {
    const a = el("a", "dash-card");
    a.href = card.href;

    const tile = el("span", "ic", card.emoji);
    tile.setAttribute("aria-hidden", "true");

    a.append(tile, el("h3", "", card.label), el("p", "", card.desc), el("span", "dash-card-go", "Open →"));
    quickCards.appendChild(a);
  });
}


/* ===== 4. Upcoming events ===== */
const MAX_EVENTS = 5;
const TYPE_LABEL = { deadline: "Deadline", event: "Event", holiday: "Holiday", department: "Department" };
const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

/* "2026-11-20" -> local Date at midnight. new Date("2026-11-20") would be UTC
   midnight, which shows the day before in time zones west of UTC. */
function parseDay(iso) {
  const [y, m, d] = String(iso).split("-").map(Number);
  return new Date(y, m - 1, d);
}

function startOfToday() {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

/* "Today", "Tomorrow", "In 5 days", "Now on" (started earlier, not over yet) */
function whenLabel(start, today) {
  const days = Math.round((start - today) / 86400000);
  if (days < 0) return "Now on";
  if (days === 0) return "Today";
  if (days === 1) return "Tomorrow";
  return "In " + days + " days";
}

function shortDate(d) {
  return d.getDate() + " " + MONTHS[d.getMonth()];
}

function renderEvent(ev, today) {
  const start = parseDay(ev.start_date);
  const end = ev.end_date ? parseDay(ev.end_date) : start;
  const type = TYPE_LABEL[ev.event_type] ? ev.event_type : "event";

  const li = el("li", "dash-event");

  // Date badge: "20 / NOV"
  const badge = el("span", "dash-event-date type-" + type);
  badge.append(el("strong", "", String(start.getDate())), el("small", "", MONTHS[start.getMonth()].toUpperCase()));

  // Title + one meta line: "Deadline · Main hall · 20 Nov – 25 Nov"
  const body = el("div", "dash-event-body");
  body.appendChild(el("strong", "dash-event-title", ev.title));
  const meta = [TYPE_LABEL[type]];
  if (ev.location) meta.push(ev.location);
  if (ev.end_date && ev.end_date !== ev.start_date) meta.push(shortDate(start) + " – " + shortDate(end));
  body.appendChild(el("span", "dash-event-meta", meta.join(" · ")));

  li.append(badge, body, el("span", "dash-event-when", whenLabel(start, today)));
  return li;
}

function showEventsMessage(list, text) {
  list.replaceChildren(el("li", "dash-events-msg", text));
}

async function loadEvents() {
  const list = document.getElementById("eventsList");
  const hint = document.getElementById("eventsHint");
  if (!list || typeof NUML.api !== "function") return;

  try {
    const events = await NUML.api("/api/events");
    const today = startOfToday();

    // Keep what has not ended yet, soonest first.
    const upcoming = (Array.isArray(events) ? events : [])
      .filter((ev) => ev && ev.start_date && parseDay(ev.end_date || ev.start_date) >= today)
      .sort((a, b) => parseDay(a.start_date) - parseDay(b.start_date));

    if (!upcoming.length) {
      showEventsMessage(list, "Nothing coming up on the calendar.");
      if (hint) hint.textContent = "";
      return;
    }

    list.replaceChildren(...upcoming.slice(0, MAX_EVENTS).map((ev) => renderEvent(ev, today)));
    if (hint) {
      hint.textContent = upcoming.length > MAX_EVENTS
        ? "Next " + MAX_EVENTS + " of " + upcoming.length
        : upcoming.length + " coming up";
    }
  } catch (err) {
    // A 401 is handled inside NUML.api (it goes to the login page).
    console.error("Could not load events:", err);
    showEventsMessage(list, "Could not load the calendar. Try again later.");
    if (hint) hint.textContent = "";
  }
}

loadEvents();


/* ===== 5. Profile menu and Log out ===== */
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

/* Log out: clear the session AND navigate.
   querySelectorAll, not querySelector: there are TWO logout links (profile
   menu and the "Your account" panel). With querySelector only the first one
   cleared the session; the other just opened login.html, which saw the saved
   session and bounced straight back here. */
document.querySelectorAll(".dash-logout").forEach((link) => {
  link.addEventListener("click", (e) => {
    e.preventDefault();
    NUML.logout();
  });
});
