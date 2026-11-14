/* ==========================================================================
   tt-layout.js  -  login guard, role guard and nav for every timetable page.

   Every page calls:   const me = await TT.init({ allowed: ["teacher", "hod"] });
   and stops if `me` is null. init() does, in this order:
     1. No token          -> go to the timetable login (nothing else runs).
     2. GET /api/auth/me  -> the SERVER says who we are. A 401 makes tt-api.js
                             clear the token and go to the login, so a
                             hand-made localStorage value never gets further.
     3. Draw the nav for that role (only links that role may open).
     4. Role not allowed  -> show a refusal card and return null. The page's
                             <main> stays hidden and its data is never loaded.
     5. Otherwise         -> show <main>, start the bell, return the profile.

   The role check here is only about what to SHOW. The backend checks the role
   again on every request (403), which is the check that actually protects data.
   ========================================================================== */
(function () {
  "use strict";

  const C = window.TT_CONFIG;
  const P = C.PAGES;

  /* Which pages each role may open. The nav, the guard and the README all
     follow this one list. */
  const NAV_ITEMS = [
    { href: P.timetable, label: "My Timetable", roles: ["teacher", "hod"] },
    { href: P.swaps, label: "Swap Requests", roles: ["teacher"] },
    { href: P.approvals, label: "Swap Approvals", roles: ["hod", "admin"] },
    { href: P.makeup, label: "Makeup Classes", roles: ["teacher", "hod"] },
    { href: P.admin, label: "Admin", roles: ["admin"] }
  ];

  const ROLE_LABEL = { student: "Student", teacher: "Teacher", hod: "HOD", admin: "Admin" };

  /* ---- DOM helpers ------------------------------------------------------
     el() only ever sets textContent, never innerHTML, so a course title or a
     notification message like "<img onerror=...>" is shown as plain text. */
  function el(tag, props, children) {
    const node = document.createElement(tag);
    const p = props || {};
    if (p.className) node.className = p.className;
    if (p.text !== undefined && p.text !== null) node.textContent = String(p.text);
    if (p.attrs) Object.keys(p.attrs).forEach((k) => node.setAttribute(k, p.attrs[k]));
    (children || []).forEach((c) => { if (c) node.appendChild(c); });
    return node;
  }

  function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  /* Backend times are "08:30:00"; the UI always shows "08:30". */
  const hhmm = (t) => (t ? String(t).slice(0, 5) : "");

  /* "2026-11-07T10:15:00" -> "2026-11-07 10:15" (no timezone guessing). */
  const when = (iso) => (iso ? String(iso).replace("T", " ").slice(0, 16) : "");

  /* Show a message in a .form-msg element. For a swap 409 the API sends a
     list of conflicts; show each one so the teacher knows what to change. */
  function showMsg(node, text, kind, list) {
    clear(node);
    node.className = "form-msg" + (kind ? " " + kind : "");
    node.appendChild(document.createTextNode(text || ""));
    if (list && list.length) {
      node.appendChild(el("ul", {}, list.map((item) => el("li", { text: item }))));
    }
  }

  /* Error -> message, including the conflicts list of a swap 409. */
  function showError(node, err) {
    const conflicts = err && err.detail && Array.isArray(err.detail.conflicts)
      ? err.detail.conflicts.map((c) => c.detail)
      : null;
    showMsg(node, (err && err.message) || "Something went wrong.", "error", conflicts);
  }

  /* ---- Nav --------------------------------------------------------------- */
  function currentFile() {
    return window.location.pathname.split("/").pop().toLowerCase();
  }

  function renderNav(me) {
    let nav = document.getElementById("ttNav");
    if (!nav) {
      nav = el("nav", { className: "tt-nav", attrs: { id: "ttNav" } });
      document.body.insertBefore(nav, document.body.firstChild);
    }
    clear(nav);
    nav.setAttribute("aria-label", "Timetable navigation");

    const here = currentFile();
    NAV_ITEMS.filter((item) => item.roles.includes(me.role)).forEach((item) => {
      const a = el("a", { text: item.label, attrs: { href: item.href } });
      if (item.href === here) {
        a.classList.add("active");
        a.setAttribute("aria-current", "page");
      }
      nav.appendChild(a);
    });

    nav.appendChild(el("span", { className: "spacer" }));

    // The bell lives here; tt-bell.js fills it. If that script is not on the
    // page the nav still works.
    const bellSlot = el("div", { className: "tt-bell-wrap", attrs: { id: "ttBellSlot" } });
    nav.appendChild(bellSlot);

    nav.appendChild(el("span", { className: "who", text: me.name + " · " + (ROLE_LABEL[me.role] || me.role) }));

    const out = el("button", { text: "Log out", attrs: { type: "button" } });
    out.addEventListener("click", logout);
    nav.appendChild(out);

    return bellSlot;
  }

  /* Logout clears the token only. There is no profile in storage to clear,
     because none is ever saved. */
  function logout() {
    if (window.TTBell) window.TTBell.stop();
    window.TTApi.clearToken();
    window.location.replace(P.login);
  }

  function renderRefusal(gate, me) {
    clear(gate);
    const start = C.START_PAGE[me.role];
    gate.appendChild(el("section", { className: "tt-card", attrs: { role: "alert" } }, [
      el("h2", { text: "This page is not available for your role" }),
      el("p", { text: "You are logged in as " + (ROLE_LABEL[me.role] || me.role) + ". This page is only for: " +
        (gate.dataset.allowed || "other roles") + "." }),
      start ? el("a", { className: "btn solid", text: "Go to your start page", attrs: { href: start } }) : null
    ]));
  }

  function renderGateError(gate, err) {
    clear(gate);
    const msg = el("p", { className: "form-msg error" });
    showError(msg, err);
    gate.appendChild(el("section", { className: "tt-card", attrs: { role: "alert" } }, [
      el("h2", { text: "Could not load your account" }), msg
    ]));
  }

  /* ---- The guard every page calls ---------------------------------------- */
  async function init(options) {
    const allowed = options.allowed;
    const gate = document.getElementById("ttGate");
    const main = document.getElementById("ttMain");

    if (!window.TTApi.getToken()) {
      window.location.replace(P.login);
      return null;
    }

    let me;
    try {
      me = await window.TTApi.request("GET", "/api/auth/me");
    } catch (err) {
      if (err.status !== 401) renderGateError(gate, err);   // 401 is already redirecting
      return null;
    }

    const bellSlot = renderNav(me);

    if (!allowed.includes(me.role)) {
      gate.dataset.allowed = allowed.map((r) => ROLE_LABEL[r] || r).join(", ");
      renderRefusal(gate, me);
      return null;
    }

    gate.hidden = true;
    main.hidden = false;
    if (window.TTBell) window.TTBell.start(bellSlot);
    return me;
  }

  /* Small shared lookups. The timetable API returns ids only, so every page
     resolves course and room names from /api/courses and /api/rooms. There is
     no /api/sections or /api/teachers endpoint yet, so those stay as "#id". */
  async function loadNames() {
    const [courses, rooms] = await Promise.all([
      window.TTApi.request("GET", "/api/courses", { query: { limit: 500 } }),
      window.TTApi.request("GET", "/api/rooms", { query: { limit: 500 } })
    ]);
    const courseById = new Map(courses.map((c) => [c.id, c]));
    const roomById = new Map(rooms.map((r) => [r.id, r]));
    return {
      course: (id) => courseById.get(id) || null,
      courseLabel: (id) => {
        const c = courseById.get(id);
        return c ? c.code + " " + c.title : "Course #" + id;
      },
      courseCode: (id) => { const c = courseById.get(id); return c ? c.code : "Course #" + id; },
      roomLabel: (id) => {
        if (id == null) return "No room";
        const r = roomById.get(id);
        return r ? r.name : "Room #" + id;
      },
      sectionLabel: (id) => "Section #" + id,
      teacherLabel: (id) => (id == null ? "No teacher" : "Teacher #" + id)
    };
  }

  const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
  const dayIndex = (d) => { const i = DAYS.indexOf(d); return i === -1 ? 99 : i; };

  /* Sort by real weekday order (the API sorts day names alphabetically). */
  function byDayTime(a, b) {
    return (dayIndex(a.day_of_week) - dayIndex(b.day_of_week)) || String(a.start_time).localeCompare(String(b.start_time));
  }

  /* One-line description of a timetable entry, used in dropdowns and cards. */
  function entryLabel(e, names) {
    return e.day_of_week.slice(0, 3) + " " + hhmm(e.start_time) + "–" + hhmm(e.end_time) + " · " +
      names.courseCode(e.course_id) + " · " + names.sectionLabel(e.section_id) + " · " + names.roomLabel(e.room_id);
  }

  window.TT = {
    init, el, clear, hhmm, when, showMsg, showError, loadNames,
    DAYS, byDayTime, entryLabel, ROLE_LABEL, logout
  };
})();
