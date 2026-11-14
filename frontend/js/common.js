
/* ==========================================================================
   common.js - shared session + navigation for NUML Archive.

   Handles:
   - Page filenames
   - Student, Admin and HOD dashboards
   - Login session
   - Logout
   - Protected pages
   - Navigation and quick links

   LOAD ORDER:
   common.js first, then api.js, then page-specific JS.
   ========================================================================== */

(function () {
  "use strict";

  /* ---- 1. Page map ---- */

  const PAGES = {
    home: "index.html",
    login: "login_student.html",
    signup: "signup_student.html",
    forgot: "forgot-password.html",

    dashboard: "dashboard.html",
    adminDashboard: "admin-dashboard.html",

    papers: "pastpapers.html",
    lectures: "lecture.html",
    resources: "tools-resources.html",
    gpa: "gpacal.html"
  };

  /* Pages accessible without login. */
  const PUBLIC_PAGES = [
    PAGES.home,
    PAGES.login,
    PAGES.signup,
    PAGES.forgot
  ];

  /* Role-based starting pages. */
  const START_PAGE = {
    student: PAGES.dashboard,
    admin: PAGES.adminDashboard,
    hod: PAGES.adminDashboard
  };

  /* Student navigation links. */
  const NAV_ITEMS = [
    {
      href: PAGES.dashboard,
      label: "Dashboard",
      desc: "Your home page"
    },
    {
      href: PAGES.papers,
      label: "Past Papers",
      desc: "Search and upload midterm / final papers"
    },
    {
      href: PAGES.lectures,
      label: "Lecture Slides",
      desc: "Browse slides by subject and year"
    },
    {
      href: PAGES.resources,
      label: "Software Resources",
      desc: "Download tools and setup steps"
    },
    {
      href: PAGES.gpa,
      label: "GPA Calculator",
      desc: "Semester GPA and cumulative CGPA"
    }
  ];

  const USER_KEY = "numlUser";
  const TOKEN_KEY = "numlToken";
  const PROFILES_KEY = "numlProfiles";

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


  /* ---- 3. Session management ---- */

  function getUser() {
    const user = readJSON(USER_KEY, null);
    return user && user.name ? user : null;
  }

  function setUser(user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }

  function getToken() {
    return localStorage.getItem(TOKEN_KEY);
  }

  function setToken(token) {
    localStorage.setItem(TOKEN_KEY, token);
  }

  function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
  }

  function findProfile(email) {
    return readJSON(PROFILES_KEY, {})[String(email).toLowerCase()] || null;
  }

  function saveProfile(profile) {
    const all = readJSON(PROFILES_KEY, {});
    all[profile.email.toLowerCase()] = profile;
    localStorage.setItem(PROFILES_KEY, JSON.stringify(all));
  }


  /* ---- 4. Role-based dashboard ---- */

  function homeFor(role) {
    return START_PAGE[role] || null;
  }

  function redirectByRole(role) {
    const destination = homeFor(role);

    if (!destination) {
      clearSession();
      window.location.replace(PAGES.login);
      return false;
    }

    window.location.replace(destination);
    return true;
  }


  /* ---- 5. Logout and session clearing ---- */

  function clearSession() {
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(TOKEN_KEY);
  }

  function logout() {
    clearSession();
    window.location.replace(PAGES.login);
  }


  /* ---- 6. Protected pages and role checking ---- */

  function requireLogin() {
    const user = getUser();
    const token = getToken();

    if (!user || !token) {
      window.location.replace(PAGES.login);
      return false;
    }

    const here = currentPage();

    /* Admin and HOD dashboards are only for admin and HOD users. */
    if (here === PAGES.adminDashboard) {
      if (user.role !== "admin" && user.role !== "hod") {
        redirectByRole(user.role);
        return false;
      }

      return true;
    }

    /* All other protected Archive pages are for students. */
    if (user.role !== "student") {
      redirectByRole(user.role);
      return false;
    }

    return true;
  }


  /* ---- 7. Navigation UI ---- */

  function injectStyles() {
    if (document.getElementById("numl-nav-style")) return;

    const style = document.createElement("style");
    style.id = "numl-nav-style";

    style.textContent = `
      .numl-nav {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
        padding: 10px 16px;
        background: #fff;
        border-bottom: 1px solid #ddd;
        font: 500 14px system-ui, sans-serif;
      }

      .numl-nav a,
      .numl-nav button {
        padding: 8px 12px;
        border-radius: 8px;
        color: #222;
        text-decoration: none;
        background: none;
        border: 0;
        font: inherit;
        cursor: pointer;
      }

      .numl-nav a:hover,
      .numl-nav button:hover {
        background: #f0f0f0;
      }

      .numl-nav a.active {
        background: #222;
        color: #fff;
      }

      .numl-nav .spacer {
        flex: 1;
      }

      .numl-nav .who {
        color: #666;
        padding: 0 8px;
      }

      .numl-quick {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
        margin: 20px 0;
      }

      .numl-quick a {
        display: block;
        padding: 16px;
        border: 1px solid #ddd;
        border-radius: 12px;
        color: inherit;
        text-decoration: none;
        background: #fff;
      }

      .numl-quick a:hover {
        border-color: #222;
      }

      .numl-quick strong {
        display: block;
        margin-bottom: 4px;
      }

      .numl-quick span {
        font-size: 13px;
        color: #666;
      }
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


  /* ---- 8. Dashboard quick links ---- */

  function renderQuickLinks() {
    if (document.querySelector(".numl-quick")) return;

    injectStyles();

    const grid = document.createElement("div");
    grid.className = "numl-quick";

    NAV_ITEMS
      .filter((item) => item.href !== PAGES.dashboard)
      .forEach((item) => {
        const a = document.createElement("a");
        a.href = item.href;

        const title = document.createElement("strong");
        title.textContent = item.label;

        const description = document.createElement("span");
        description.textContent = item.desc;

        a.append(title, description);
        grid.appendChild(a);
      });

    const host =
      document.getElementById("quickLinks") ||
      document.querySelector("main") ||
      document.body;

    host.appendChild(grid);
  }


  /* ---- 9. Automatic page setup ---- */

  function init() {
    const here = currentPage();

    /* Public pages do not require a login. */
    if (PUBLIC_PAGES.includes(here)) return;

    /* Check session and user role on protected pages. */
    if (!requireLogin()) return;

    /* Admin and HOD dashboards have their own navigation. */
    if (here === PAGES.adminDashboard) return;

    /* Student dashboard has its own header and quick links. */
    if (here !== PAGES.dashboard) {
      renderNav();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }


  /* ---- 10. Public API ---- */

  window.NUML = {
    PAGES,
    START_PAGE,
    MIN_PASSWORD,
    EMAIL_PATTERN,

    parseEmail,

    getUser,
    setUser,

    getToken,
    setToken,
    clearToken,

    findProfile,
    saveProfile,

    homeFor,
    redirectByRole,

    clearSession,
    logout,
    requireLogin,

    renderQuickLinks
  };

})();
