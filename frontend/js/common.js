/* ==========================================================================
   common.js  -  shared session + navigation for every page of the portal.

   WHY THIS FILE EXISTS
   - Before: each page decided on its own where to go, login never saved the
     user, and no page linked back to the dashboard. Result: login -> dashboard
     -> bounced back to login (dashboard.js found no "numlUser").
   - Now: ONE place knows the page filenames, the session, and the nav bar.

   LOAD ORDER (every page): <script src="common.js" defer></script> FIRST,
   then the page's own script. Everything is wrapped in an IIFE and exposed as
   window.NUML, so it cannot clash with the top-level `const $` / `SUBJECTS`
   that pastpaper.js and lecture.js already declare.
   ========================================================================== */
(function () {
  "use strict";

  /* ---- 1. Page map: the ONLY place filenames live. Rename here if yours differ. ---- */
  const PAGES = {
    login: "login.html",
    signup: "signup.html",
    forgot: "forgot.html",
    dashboard: "dashboard.html",
    papers: "pastpaper.html",
    lectures: "lecture.html",
    resources: "resources.html",
    gpa: "gpacal.html"
  };

  /* Pages that work WITHOUT being logged in. Everything else is protected. */
  const PUBLIC_PAGES = [PAGES.login, PAGES.signup, PAGES.forgot, "forgot-password.html", "index.html"];

  /* Links shown in the nav bar / dashboard quick-links, in this order. */
  const NAV_ITEMS = [
    { href: PAGES.dashboard, label: "Dashboard", desc: "Your home page" },
    { href: PAGES.papers, label: "Past Papers", desc: "Search and upload midterm / final papers" },
    { href: PAGES.lectures, label: "Lecture Slides", desc: "Browse slides by subject and year" },
    { href: PAGES.resources, label: "Software Resources", desc: "Download tools and setup steps" },
    { href: PAGES.gpa, label: "GPA Calculator", desc: "Semester GPA and cumulative CGPA" }
  ];

  const USER_KEY = "numlUser";          // same key dashboard.js already reads
  const PROFILES_KEY = "numlProfiles";  // name/department saved at signup (NO passwords)
  const MIN_PASSWORD = 8;               // login and signup used 6 and 8; one rule now

  /* f = fall, s = spring, 2-digit year, numeric system id */
  const EMAIL_PATTERN = /^numl-([fs])(\d{2})-(\d+)@numls\.edu\.pk$/i;

  /* ---- 2. Small helpers ---- */
  function currentPage() {
    // "/site/Dashboard.html?x=1" -> "dashboard.html"; bare "/" -> "index.html"
    const file = window.location.pathname.split("/").pop().toLowerCase();
    return file || "index.html";
  }

  function readJSON(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) {
      return fallback; // corrupted or blocked storage: behave as "nothing saved"
    }
  }

  function parseEmail(email) {
    const m = String(email || "").trim().match(EMAIL_PATTERN);
    if (!m) return null;
    return {
      term: m[1].toLowerCase() === "f" ? "Fall" : "Spring",
      year: "20" + m[2],
      systemId: m[3]
    };
  }

  /* ---- 3. Session ---- */
  function getUser() {
    const u = readJSON(USER_KEY, null);
    return u && u.name ? u : null;
  }

  function setUser(user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }

  function findProfile(email) {
    return readJSON(PROFILES_KEY, {})[String(email).toLowerCase()] || null;
  }

  function saveProfile(profile) {
    const all = readJSON(PROFILES_KEY, {});
    all[profile.email.toLowerCase()] = profile;
    localStorage.setItem(PROFILES_KEY, JSON.stringify(all));
  }

  function logout() {
    localStorage.removeItem(USER_KEY);
    window.location.href = PAGES.login;
  }

  /* Protected page + no session -> login. replace() so Back does not loop. */
  function requireLogin() {
    if (!getUser()) {
      window.location.replace(PAGES.login);
      return false;
    }
    return true;
  }

  /* ---- 4. Navigation UI (built with DOM calls, never innerHTML) ---- */
  function injectStyles() {
    if (document.getElementById("numl-nav-style")) return;
    const style = document.createElement("style");
    style.id = "numl-nav-style";
    style.textContent = `
      .numl-nav{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:10px 16px;
        background:#fff;border-bottom:1px solid #ddd;font:500 14px system-ui,sans-serif}
      .numl-nav a,.numl-nav button{padding:8px 12px;border-radius:8px;color:#222;text-decoration:none;
        background:none;border:0;font:inherit;cursor:pointer}
      .numl-nav a:hover,.numl-nav button:hover{background:#f0f0f0}
      .numl-nav a.active{background:#222;color:#fff}
      .numl-nav .spacer{flex:1}
      .numl-nav .who{color:#666;padding:0 8px}
      .numl-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:20px 0}
      .numl-quick a{display:block;padding:16px;border:1px solid #ddd;border-radius:12px;
        color:inherit;text-decoration:none;background:#fff}
      .numl-quick a:hover{border-color:#222}
      .numl-quick strong{display:block;margin-bottom:4px}
      .numl-quick span{font-size:13px;color:#666}
    `;
    document.head.appendChild(style);
  }

  function renderNav() {
    if (document.querySelector(".numl-nav")) return; // never add twice
    injectStyles();

    const here = currentPage();
    const nav = document.createElement("nav");
    nav.className = "numl-nav";
    nav.setAttribute("aria-label", "Main navigation");

    NAV_ITEMS.forEach((item) => {
      const a = document.createElement("a");
      a.href = item.href;
      a.textContent = item.label;
      if (item.href === here) {
        a.classList.add("active");
        a.setAttribute("aria-current", "page");
      }
      nav.appendChild(a);
    });

    const spacer = document.createElement("span");
    spacer.className = "spacer";
    nav.appendChild(spacer);

    const user = getUser();
    if (user) {
      const who = document.createElement("span");
      who.className = "who";
      who.textContent = user.name; // textContent: a name like "<b>" stays text
      nav.appendChild(who);
    }

    const out = document.createElement("button");
    out.type = "button";
    out.textContent = "Log out";
    out.addEventListener("click", logout);
    nav.appendChild(out);

    document.body.insertBefore(nav, document.body.firstChild);
  }

  /* Dashboard -> every other page. Goes into <section id="quickLinks"> if you
     add one in dashboard.html, otherwise into <main>, otherwise <body>. */
  function renderQuickLinks() {
    if (document.querySelector(".numl-quick")) return;
    injectStyles();

    const grid = document.createElement("div");
    grid.className = "numl-quick";

    NAV_ITEMS.filter((i) => i.href !== PAGES.dashboard).forEach((item) => {
      const a = document.createElement("a");
      a.href = item.href;
      const t = document.createElement("strong");
      t.textContent = item.label;
      const d = document.createElement("span");
      d.textContent = item.desc;
      a.append(t, d);
      grid.appendChild(a);
    });

    const host = document.getElementById("quickLinks") || document.querySelector("main") || document.body;
    host.appendChild(grid);
  }

  /* ---- 5. Auto-run on every page that loads this file ---- */
  function init() {
    const here = currentPage();
    if (PUBLIC_PAGES.includes(here)) return;       // login/signup/forgot: nothing to do
    if (!requireLogin()) return;                   // bounce to login, stop here
    if (here !== PAGES.dashboard) renderNav();     // dashboard has its own header + quick links
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  window.NUML = {
    PAGES, MIN_PASSWORD, EMAIL_PATTERN,
    parseEmail, getUser, setUser, findProfile, saveProfile,
    logout, requireLogin, renderQuickLinks
  };
})();
