/* ==========================================================================
   common.js  -  shared session + navigation for every page of the portal.

   LOAD ORDER (every page): common.js FIRST (no defer), then api.js,
   then the page's own script.
   Everything is wrapped in an IIFE and exposed as window.NUML.
   ========================================================================== */
(function () {
  "use strict";

  /* ---- 1. Page map: the ONLY place filenames live. ---- */
  const PAGES = {
    home: "index.html",
    login: "login.html",
    forgot: "forgot-password.html",
    dashboard: "dashboard.html",
    adminDashboard: "admin-dashboard.html",
    papers: "pastpapers.html",
    lectures: "lecture.html",
    resources: "tools-resources.html",
    gpa: "gpacal.html"
  };

  /* Pages that work WITHOUT being logged in. Everything else is protected. */
  const PUBLIC_PAGES = [PAGES.home, PAGES.login, PAGES.forgot];

  /* Links shown in the nav bar / dashboard quick-links, in this order. */
  const NAV_ITEMS = [
    { href: PAGES.dashboard, label: "Dashboard", desc: "Your home page" },
    { href: PAGES.papers, label: "Past Papers", desc: "Search and upload midterm / final papers" },
    { href: PAGES.lectures, label: "Lecture Slides", desc: "Browse slides by subject and year" },
    { href: PAGES.resources, label: "Software Resources", desc: "Download tools and setup steps" },
    { href: PAGES.gpa, label: "GPA Calculator", desc: "Semester GPA and cumulative CGPA" }
  ];

  const USER_KEY = "numlUser";
  const TOKEN_KEY = "numlToken";
  const TT_TOKEN_KEY = "ttAuthToken";
  const OLD_PROFILES_KEY = "numlProfiles"; // removed feature; only cleaned up on logout
  const MIN_PASSWORD = 8;

  /* f = fall, s = spring, 2-digit year, numeric system id */
  const EMAIL_PATTERN = /^numl-([fs])(\d{2})-(\d+)@numls\.edu\.pk$/i;

  /* ---- 2. Small helpers ---- */
  function currentPage() {
    const file = window.location.pathname.split("/").pop().toLowerCase();
    return file || "index.html";
  }

  function readJSON(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) {
      return fallback;
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

  /* Admins and HODs. This only picks which page to show; the backend must
     still check the role on every admin endpoint. */
  const ADMIN_ROLES = ["admin", "hod"];

  function isAdmin(user) {
    return !!user && ADMIN_ROLES.includes(user.role);
  }

  function homeFor(user) {
    return isAdmin(user) ? PAGES.adminDashboard : PAGES.dashboard;
  }

  /* Log out: clear the user AND both tokens (and old saved profiles). */
  function logout() {
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(TT_TOKEN_KEY);
    localStorage.removeItem(OLD_PROFILES_KEY);
    window.location.href = PAGES.login;
  }

  /* Protected page + no session -> login. replace() so Back does not loop. */
  function requireLogin() {
    if (!getUser() || !localStorage.getItem(TOKEN_KEY)) {
      window.location.replace(PAGES.login);
      return false;
    }
    return true;
  }

  /* Ask the server "who am I". A fake numlUser/numlToken fails here:
     api.js clears the token and goes to login on a 401.
     Runs only ONE request per page, however many scripts call it. */
  let sessionPromise = null;

  async function checkSession() {
    if (!window.NUML || typeof window.NUML.api !== "function") {
      console.error("verifySession: api.js is not loaded on this page");
      return null;
    }
    try {
      const res = await window.NUML.api("/api/auth/me");
      const user = res && res.user ? res.user : res; // real backend: user object; mock may wrap it
      if (user && user.name) setUser(user);
      return user;
    } catch (err) {
      console.error("Session check failed:", err);
      return null;
    }
  }

  function verifySession() {
    if (!sessionPromise) sessionPromise = checkSession();
    return sessionPromise;
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
    if (document.querySelector(".numl-nav")) return;
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
      who.textContent = user.name;
      nav.appendChild(who);
    }

    const out = document.createElement("button");
    out.type = "button";
    out.textContent = "Log out";
    out.addEventListener("click", logout);
    nav.appendChild(out);

    document.body.insertBefore(nav, document.body.firstChild);
  }

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
    if (PUBLIC_PAGES.includes(here)) return;       // login/forgot: nothing to do
    if (!requireLogin()) return;                   // bounce to login, stop here
    if (here !== PAGES.dashboard && here !== PAGES.adminDashboard) renderNav();
    if (window.NUML && typeof window.NUML.api === "function") verifySession();
  }

  /* Export FIRST, so window.NUML exists before init() can run. */
  window.NUML = {
    PAGES, MIN_PASSWORD, EMAIL_PATTERN,
    parseEmail, getUser, setUser,
    logout, requireLogin, verifySession, renderQuickLinks, isAdmin, homeFor
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();