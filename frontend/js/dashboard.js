/* ==========================================================================
   dashboard.js  -  needs common.js, then api.js, loaded first.
   common.js already sends logged-out users to the login page.

   What it fills in:
     1. Profile box and greeting (name, roll number, initials)
     2. Today's date
     3. Quick access cards (one per part of the archive)
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

const SVG_NS = "http://www.w3.org/2000/svg";

/* Line icon from one or more path strings (same style as the sidebar icons) */
function icon(paths) {
  const svg = document.createElementNS(SVG_NS, "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("aria-hidden", "true");
  paths.forEach((d) => {
    const p = document.createElementNS(SVG_NS, "path");
    p.setAttribute("d", d);
    svg.appendChild(p);
  });
  return svg;
}


/* ===== 1. Profile box and greeting ===== */
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
    const node = document.getElementById(id);
    if (node) node.textContent = text;
  };

  setText("userName", user.name);
  setText("userId", user.identifier ? "@" + user.identifier : "");
  setText("userAvatar", initials);
  setText("greeting", "HEY, " + firstName.toUpperCase());
}


/* ===== 2. Today's date ("Wednesday, 11 November 2026") ===== */
const todayEl = document.getElementById("today");
if (todayEl) {
  todayEl.textContent = new Date().toLocaleDateString("en-GB", {
    weekday: "long", day: "numeric", month: "long", year: "numeric"
  });
}


/* ===== 3. Quick access cards =====
   Same pages and order as the sidebar. `tone` picks the icon tile color. */
const QUICK_CARDS = [
  {
    href: "lecture.html", label: "Uni Lecs", tone: "blue",
    desc: "Lecture slides by department, subject and year.",
    icon: ["M3 7l9-4 9 4-9 4-9-4z", "M7 10v5c0 1.5 2.2 3 5 3s5-1.5 5-3v-5"]
  },
  {
    href: "pastpapers.html", label: "Past Papers", tone: "orange",
    desc: "Midterm and final papers to practise with.",
    icon: ["M7 3h7l5 5v13H7z", "M14 3v5h5M10 13h6M10 17h6"]
  },
  {
    href: "tools-resources.html", label: "Resources", tone: "green",
    desc: "Software for your courses, with setup steps.",
    icon: ["M4 5h6a3 3 0 013 3v12a2 2 0 00-2-2H4z", "M20 5h-6a3 3 0 00-3 3v12a2 2 0 012-2h7z"]
  },
  {
    href: "gpacal.html", label: "GPA Calculator", tone: "purple",
    desc: "Work out your semester GPA and CGPA.",
    icon: ["M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z", "M8 7h8M8 12h2M12 12h2M8 16h2M12 16h2"]
  },
  {
    href: "timetable/timetable-login.html", label: "Timetable", tone: "navy",
    desc: "Your weekly classes, swaps and makeup classes. Separate login.",
    icon: ["M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z", "M3 10h18M8 3v4M16 3v4"]
  }
];

const quickCards = document.getElementById("quickCards");
if (quickCards) {
  QUICK_CARDS.forEach((card) => {
    const a = el("a", "dash-card");
    a.href = card.href;

    const tile = el("span", "dash-card-icon tone-" + card.tone);
    tile.appendChild(icon(card.icon));

    const text = el("span", "dash-card-text");
    text.append(el("strong", "", card.label), el("span", "", card.desc));

    const arrow = el("span", "dash-card-arrow", "→");
    arrow.setAttribute("aria-hidden", "true");

    a.append(tile, text, arrow);
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
   menu and sidebar). With querySelector only the first one cleared the
   session; the sidebar link just opened login.html, which saw the saved
   session and bounced straight back here. */
document.querySelectorAll(".dash-logout").forEach((link) => {
  link.addEventListener("click", (e) => {
    e.preventDefault();
    NUML.logout();
  });
});
