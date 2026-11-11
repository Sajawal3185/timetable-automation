/* ==========================================================================
   admin-dashboard.js  -  needs common.js, then api.js, loaded first.
   common.js already sends logged-out users to the login page.

   Only admins and HODs (same roles as ADMINS in the backend) may stay here;
   everyone else goes to the student dashboard.

   What it does:
     1. Greeting, date and profile button
     2. Loads the files students uploaded: GET /api/uploads (mock: mock/uploads.json)
     3. Status tabs + type filter, Accept / Reject on each pending upload
     4. Profile menu and Log out

   DEMO NOTE: the backend has no review endpoint yet, so decisions are kept in
   this browser (localStorage "numlUploadReviews"). When the endpoint exists,
   send the decision in saveDecision() instead.

   Everything is built with DOM calls and textContent, never innerHTML,
   so a title like "<b>" from a student stays plain text.
   ========================================================================== */

const ADMIN_ROLES = ["admin", "hod"];
const user = NUML.getUser();

if (user && !ADMIN_ROLES.includes(user.role)) {
  window.location.replace(NUML.PAGES.dashboard);
}


/* ---- Small DOM helpers ---- */
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


/* ===== 1. Greeting, date and profile button ===== */
if (user) {
  const name = user.name.trim();
  const initials = name.split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();
  const firstName = name.split(/\s+/)[0];

  setText("greeting", "Hey " + firstName);
  setText("userName", firstName);
  setText("userAvatar", initials);
  setText("menuName", name);
  setText("userId", user.identifier ? "@" + user.identifier : "");
}

setText("today", new Date().toLocaleDateString("en-GB", {
  weekday: "long", day: "numeric", month: "long", year: "numeric"
}));


/* ===== 2. Uploads + decisions saved in this browser ===== */
const REVIEWS_KEY = "numlUploadReviews";
const KIND_LABEL = { lecture: "Lecture slides", paper: "Past paper" };
const STATUS_LABEL = { pending: "Pending", approved: "Accepted", rejected: "Rejected" };

let uploads = [];
let currentStatus = "pending";
let currentKind = "all";

function readReviews() {
  try {
    return JSON.parse(localStorage.getItem(REVIEWS_KEY)) || {};
  } catch (e) {
    return {};
  }
}

function saveDecision(id, status, reason) {
  // TODO(backend): replace with e.g. NUML.api("/api/uploads/" + id, { method: "PATCH", body: { status, reason } })
  const all = readReviews();
  all[id] = { status, reason: reason || "", at: new Date().toISOString() };
  try {
    localStorage.setItem(REVIEWS_KEY, JSON.stringify(all));
  } catch (e) {
    /* storage blocked: the decision still shows until the page reloads */
  }
}

/* Server data with this browser's decisions on top */
function applyReviews(list) {
  const reviews = readReviews();
  return list.map((u) => (reviews[u.id] ? Object.assign({}, u, reviews[u.id]) : u));
}

function formatSize(bytes) {
  if (!bytes) return "";
  if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + " KB";
  return (bytes / (1024 * 1024)).toFixed(1) + " MB";
}

function formatDate(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return "";
  return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

/* Icon per type (same line icons as the side menu) */
const ICONS = {
  lecture: ["M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z", "M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"],
  paper: ["M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z", "M14 2v6h6M16 13H8M16 17H8"]
};

function icon(kind) {
  const ns = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(ns, "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("aria-hidden", "true");
  (ICONS[kind] || ICONS.paper).forEach((d) => {
    const path = document.createElementNS(ns, "path");
    path.setAttribute("d", d);
    svg.appendChild(path);
  });
  return svg;
}


/* ===== 3. Render the list ===== */
function renderCounts() {
  const ofKind = uploads.filter((u) => currentKind === "all" || u.kind === currentKind);
  const count = (s) => ofKind.filter((u) => u.status === s).length;
  setText("countPending", count("pending"));
  setText("countApproved", count("approved"));
  setText("countRejected", count("rejected"));
  setText("countAll", ofKind.length);
}

function renderUpload(u) {
  const li = el("li", "admin-item status-" + u.status);

  const ic = el("span", "admin-item-ic kind-" + u.kind);
  ic.appendChild(icon(u.kind));

  // Title, then "Past paper · Midterm · Fall 2025 · Data Structures"
  const body = el("div", "admin-item-body");
  body.appendChild(el("strong", "admin-item-title", u.title));
  body.appendChild(el("span", "admin-item-meta",
    [KIND_LABEL[u.kind] || "Upload", u.category, u.term, u.course].filter(Boolean).join(" · ")));
  // "by Ali Hassan (se-9244330) · 10 Nov 2026 · dsa-mid.pdf, 1.8 MB"
  const who = u.uploaded_by || {};
  const file = [u.original_filename, formatSize(u.file_size)].filter(Boolean).join(", ");
  body.appendChild(el("span", "admin-item-meta",
    ["by " + (who.name || "Unknown") + (who.identifier ? " (" + who.identifier + ")" : ""), formatDate(u.created_at), file]
      .filter(Boolean).join(" · ")));
  if (u.status === "rejected" && u.reason) {
    body.appendChild(el("span", "admin-item-reason", "Reason: " + u.reason));
  }

  const side = el("div", "admin-item-actions");
  if (u.status === "pending") {
    const accept = el("button", "admin-btn accept", "Accept");
    accept.type = "button";
    accept.addEventListener("click", () => decide(u.id, "approved"));

    const reject = el("button", "admin-btn reject", "Reject");
    reject.type = "button";
    reject.addEventListener("click", () => openRejectForm(li, u.id));

    side.append(accept, reject);
  } else {
    side.appendChild(el("span", "admin-badge " + u.status, STATUS_LABEL[u.status] || u.status));
    const undo = el("button", "admin-link", "Undo");
    undo.type = "button";
    undo.addEventListener("click", () => decide(u.id, "pending"));
    side.appendChild(undo);
  }

  li.append(ic, body, side);
  return li;
}

/* Reject asks for an optional reason, shown to the admin later */
function openRejectForm(li, id) {
  if (li.querySelector(".admin-reject-form")) return;

  const form = el("form", "admin-reject-form");
  const input = el("input");
  input.type = "text";
  input.maxLength = 200;
  input.placeholder = "Reason (optional), e.g. wrong subject, blurry scan";
  input.setAttribute("aria-label", "Reason for rejecting");

  const confirm = el("button", "admin-btn reject", "Reject");
  confirm.type = "submit";
  const cancel = el("button", "admin-link", "Cancel");
  cancel.type = "button";
  cancel.addEventListener("click", () => form.remove());

  form.append(input, confirm, cancel);
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    decide(id, "rejected", input.value.trim());
  });

  li.appendChild(form);
  input.focus();
}

function decide(id, status, reason) {
  saveDecision(id, status, reason);
  uploads = uploads.map((u) => (u.id === id ? Object.assign({}, u, { status, reason: reason || "" }) : u));
  render();
}

function render() {
  const list = document.getElementById("uploadList");
  if (!list) return;
  renderCounts();

  const shown = uploads
    .filter((u) => currentStatus === "all" || u.status === currentStatus)
    .filter((u) => currentKind === "all" || u.kind === currentKind);

  if (!shown.length) {
    const text = currentStatus === "pending" ? "All caught up. Nothing is waiting for review." : "Nothing here.";
    list.replaceChildren(el("li", "admin-msg", text));
    return;
  }
  list.replaceChildren(...shown.map(renderUpload));
}

async function loadUploads() {
  const list = document.getElementById("uploadList");
  try {
    const data = await NUML.api("/api/uploads");
    // Newest first
    uploads = applyReviews(Array.isArray(data) ? data : [])
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    render();
  } catch (err) {
    // A 401 is handled inside NUML.api (it goes to the login page).
    console.error("Could not load uploads:", err);
    if (list) list.replaceChildren(el("li", "admin-msg", "Could not load uploads. Try again later."));
  }
}

/* Tabs and type filter */
document.querySelectorAll(".admin-tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    currentStatus = tab.dataset.status;
    document.querySelectorAll(".admin-tab").forEach((t) => t.setAttribute("aria-selected", t === tab));
    render();
  });
});

const kindFilter = document.getElementById("kindFilter");
if (kindFilter) {
  kindFilter.addEventListener("change", () => {
    currentKind = kindFilter.value;
    render();
  });
}

if (user && ADMIN_ROLES.includes(user.role)) loadUploads();


/* ===== 4. Profile menu and Log out ===== */
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
