/* ==========================================================================
   tt-mock.js  -  MOCK BACKEND used only when TT_CONFIG.API_BASE is "".

   >>> THIS IS A STAND-IN, NOT SECURITY. <<<
   Everything here runs in the visitor's browser, so a determined user can
   change it. It exists so the frontend can be built and demoed before the
   backend runs. It still behaves like the real API on purpose, so the pages
   are written against real behaviour and not against shortcuts:

   - Login checks the password against frontend/mock/db/users.json (SHA-256
     of "identifier:password") and answers 401 "Invalid credentials" when it
     is wrong. There is no "any password works" path.
   - Tokens are random strings known only to this mock's session table. A
     value typed into localStorage by hand is not in that table, so /me
     answers 401 and the page goes to the login.
   - Role checks, status codes and error texts are copied from the routers in
     backend/app/routers/ (auth, timetable, swaps, makeup, notifications,
     rooms). Each handler names the router function it mirrors.
   - Responses are projected through the field lists of the Pydantic schemas
     (SHAPES below), the same way FastAPI's response_model drops extra columns.

   ONE DELIBERATE DIFFERENCE (also listed in the README and the PR):
   /api/auth/me also returns section_id, teacher_id and department_id. The
   real UserOut only has {id, name, role}, which is not enough to know whose
   timetable to show. The pages read these fields if present and show a clear
   "not linked" message if they are missing, so real mode fails visibly
   instead of silently showing someone else's week.

   SIGNUP AND FORGOT PASSWORD (Login & Signup Plan, 12 Nov) are not in the
   backend yet. Their endpoints are marked pending in docs/api-contract.md and
   are mocked here so the pages can be built now. No email is sent: the code
   is always TT_CONFIG.AUTH.MOCK_CODE (123456), and the pages say so.

   State lives in sessionStorage ("ttMockDb", "ttMockSessions"): it survives
   page loads in one tab (so a teacher's request can be approved by the HOD
   after switching accounts) and disappears when the tab closes.
   ========================================================================== */
(function () {
  "use strict";

  const SCRIPT_URL = document.currentScript ? document.currentScript.src : window.location.href;
  // js/timetable/tt-mock.js -> ../../mock/db/ = frontend/mock/db/
  const DATA_URL = new URL("../../mock/db/", SCRIPT_URL);
  const DB_KEY = "ttMockDb";
  const SESSIONS_KEY = "ttMockSessions";
  const TABLES = [
    "users", "departments", "sections", "courses", "rooms", "teachers",
    "timetable_entries", "makeup_classes", "swap_requests", "notifications"
  ];
  // Tables that start empty and only exist in the mock (signup plan):
  //   pending_signups  a signup waiting for its code (not a real account yet)
  //   email_codes      {email, purpose, code_sha256, expires_at, attempts, sent_at}
  const EMPTY_TABLES = ["pending_signups", "email_codes"];

  /* Field lists copied from backend/app/schemas and routers/auth.py. */
  const SHAPES = {
    UserOut: ["id", "name", "role"],                                   // routers/auth.py
    MeOutProposed: ["id", "name", "role", "section_id", "teacher_id", "department_id"],
    DepartmentOut: ["id", "name", "code"],                             // schemas/portal.py
    CourseOut: ["id", "code", "title", "credit_hours", "department_id"],
    RoomOut: ["id", "name", "capacity", "building"],
    TimetableEntryOut: ["id", "section_id", "course_id", "teacher_id", "room_id",
      "day_of_week", "start_time", "end_time"],
    MakeupClassOut: ["id", "course_id", "section_id", "teacher_id", "room_id", "class_date",
      "day_of_week", "start_time", "end_time", "reason", "status"],      // schemas/makeup.py
    SwapRequestOut: ["id", "requester_teacher_id", "entry_id", "target_entry_id", "status",
      "reason", "decided_by", "decided_at", "decision_note", "created_at"], // schemas/swap_request.py
    NotificationOut: ["id", "type", "message", "related_id", "is_read", "created_at"]
  };

  const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
  const WEEKDAYS = DAYS.slice(0, 5);                       // makeup_solver.WEEKDAYS
  const CANDIDATE_STARTS = ["08:00", "08:30", "09:00", "09:30", "10:00", "10:30", "10:45",
    "11:00", "11:30", "11:40", "12:00", "12:30", "13:00", "13:30", "14:00", "14:30", "15:00"];
  const CAMPUS_CLOSE = "16:30:00";
  const STAFF = ["teacher", "hod", "admin"];

  /* An HTTP error a handler can throw; handle() turns it into {status, data}. */
  class HttpError extends Error {
    constructor(status, detail) { super(typeof detail === "string" ? detail : "error"); this.status = status; this.detail = detail; }
  }

  /* ---- Storage -------------------------------------------------------- */
  let dbPromise = null;

  function loadDb() {
    if (dbPromise) return dbPromise;
    dbPromise = (async () => {
      try {
        const saved = sessionStorage.getItem(DB_KEY);
        if (saved) {
          const db = JSON.parse(saved);
          EMPTY_TABLES.forEach((t) => { if (!db[t]) db[t] = []; });
          return db;
        }
      } catch (e) { /* corrupted or blocked: reseed below */ }
      const db = {};
      await Promise.all(TABLES.map(async (t) => {
        const res = await fetch(new URL(t + ".json", DATA_URL));
        if (!res.ok) throw new Error("Mock data file missing: " + t + ".json");
        db[t] = await res.json();
      }));
      EMPTY_TABLES.forEach((t) => { db[t] = []; });
      saveDb(db);
      return db;
    })();
    // A failed load must not be cached forever, or one network blip kills the tab.
    dbPromise.catch(() => { dbPromise = null; });
    return dbPromise;
  }

  function saveDb(db) {
    try { sessionStorage.setItem(DB_KEY, JSON.stringify(db)); } catch (e) { /* state lasts for this page only */ }
  }

  function readSessions() {
    try { return JSON.parse(sessionStorage.getItem(SESSIONS_KEY) || "{}"); } catch (e) { return {}; }
  }
  function writeSessions(s) {
    try { sessionStorage.setItem(SESSIONS_KEY, JSON.stringify(s)); } catch (e) { /* login lasts one page */ }
  }

  /* ---- Small helpers -------------------------------------------------- */
  function pick(row, fields) {
    const out = {};
    fields.forEach((f) => { out[f] = row[f] === undefined ? null : row[f]; });
    return out;
  }
  const byId = (rows, id) => rows.find((r) => r.id === Number(id)) || null;
  const nextId = (rows) => rows.reduce((m, r) => Math.max(m, r.id), 0) + 1;
  const nowIso = () => new Date().toISOString().slice(0, 19);   // naive UTC like swap_service._utcnow
  const overlaps = (s1, e1, s2, e2) => s1 < e2 && e1 > s2;      // "HH:MM:SS" strings sort correctly
  const hhmm = (t) => String(t).slice(0, 5);

  function toInt(value, name, required) {
    if (value === undefined || value === null || value === "") {
      if (required) throw new HttpError(422, [{ loc: ["body", name], msg: "Field required", type: "missing" }]);
      return null;
    }
    const n = Number(value);
    if (!Number.isInteger(n)) {
      throw new HttpError(422, [{ loc: ["body", name], msg: "Input should be a valid integer", type: "int_parsing" }]);
    }
    return n;
  }

  function toTime(value, name) {
    const m = /^(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(value || ""));
    if (!m) throw new HttpError(422, [{ loc: ["body", name], msg: "Input should be in a valid time format", type: "time_parsing" }]);
    return m[1] + ":" + m[2] + ":" + (m[3] || "00");
  }

  function addMinutes(t, minutes) {
    const [h, m] = t.split(":").map(Number);
    const total = h * 60 + m + minutes;
    if (total >= 24 * 60) return null;
    return String(Math.floor(total / 60)).padStart(2, "0") + ":" + String(total % 60).padStart(2, "0") + ":00";
  }

  /* timetable_import.normalize_day: "mon" / "Monday" -> "Monday", else null */
  function normalizeDay(d) {
    const s = String(d || "").trim().toLowerCase();
    return DAYS.find((day) => day.toLowerCase() === s || day.toLowerCase().slice(0, 3) === s) || null;
  }

  async function sha256Hex(text) {
    if (!(window.crypto && crypto.subtle)) {
      // crypto.subtle only exists on https:// or localhost. Refuse rather than skip the check.
      throw new HttpError(500, "Mock login needs a secure context: open the site on http://localhost.");
    }
    const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
    return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, "0")).join("");
  }

  function randomToken() {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    return "mock-" + Array.from(bytes).map((b) => b.toString(16).padStart(2, "0")).join("");
  }

  /* ---- Auth (dependencies.get_current_user + core/permissions) --------- */
  function currentUser(db, token) {
    const userId = token ? readSessions()[token] : null;
    const user = userId ? byId(db.users, userId) : null;
    if (!user || !user.is_active) throw new HttpError(401, "Not authenticated");
    return user;
  }

  function requireRoles(user, roles, detail) {
    if (!roles.includes(user.role)) {
      throw new HttpError(403, detail || "You do not have permission for this action");
    }
  }

  const teacherOfUser = (db, user) => db.teachers.find((t) => t.user_id === user.id) || null;

  /* ---- Rules (services/scheduler_rules.py) ----------------------------- */
  function hardClashes(c, slots) {
    const out = [];
    slots.forEach((s) => {
      if (c.entry_id != null && s.entry_id === c.entry_id && s.entry_type === c.entry_type) return;
      if (s.day_of_week !== c.day_of_week) return;
      if (!overlaps(c.start_time, c.end_time, s.start_time, s.end_time)) return;
      const when = hhmm(s.start_time) + "-" + hhmm(s.end_time);
      if (c.teacher_id != null && c.teacher_id === s.teacher_id) {
        out.push("Teacher #" + c.teacher_id + " is already booked " + when);
      }
      if (c.room_id != null && c.room_id === s.room_id) {
        out.push("Room #" + c.room_id + " is already booked " + when);
      }
      if (c.section_id === s.section_id) {
        const sameElective = !!c.elective_group && c.elective_group === s.elective_group;
        const labGroups = !!c.group_label && !!s.group_label && c.group_label !== s.group_label;
        if (!sameElective && !labGroups) out.push("Section #" + c.section_id + " is already booked " + when);
      }
    });
    return out;
  }

  function roomSuitability(studentCount, room, requiresLab) {
    const out = [];
    if (requiresLab && String(room.room_type).toLowerCase() !== "lab") {
      out.push("Lab course requires a room with room_type='lab'");
    }
    if (studentCount == null || room.capacity == null) {
      out.push("Cannot verify seating fit because section student_count or room capacity is unknown (None)");
    } else if (room.capacity < studentCount) {
      out.push("Room capacity (" + room.capacity + ") is smaller than section size (" + studentCount + ")");
    }
    return out;
  }

  function softGaps(slots) {
    if (slots.length < 2) return [];
    const ordered = slots.slice().sort((a, b) => (a.start_time + a.end_time).localeCompare(b.start_time + b.end_time));
    const out = [];
    let latestEnd = ordered[0].end_time;
    ordered.slice(1).forEach((s) => {
      const toMin = (t) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
      const gap = toMin(s.start_time) - toMin(latestEnd);
      if (gap > 120) {
        out.push("Section #" + s.section_id + " has a " + gap + "-minute gap on " + s.day_of_week +
          " between " + hhmm(latestEnd) + " and " + hhmm(s.start_time));
      }
      if (s.end_time > latestEnd) latestEnd = s.end_time;
    });
    return out;
  }

  /* makeup_solver._load_active_slots */
  function activeSlots(db, term) {
    const slots = db.timetable_entries.filter((e) => e.term === term).map((e) => ({
      day_of_week: e.day_of_week, start_time: e.start_time, end_time: e.end_time,
      section_id: e.section_id, course_id: e.course_id, teacher_id: e.teacher_id, room_id: e.room_id,
      elective_group: e.elective_group, group_label: e.group_label || "", entry_id: e.id, entry_type: "timetable"
    }));
    db.makeup_classes.filter((m) => m.status === "scheduled").forEach((m) => slots.push({
      day_of_week: m.day_of_week, start_time: m.start_time, end_time: m.end_time,
      section_id: m.section_id, course_id: m.course_id, teacher_id: m.teacher_id, room_id: m.room_id,
      elective_group: null, group_label: "", entry_id: m.id, entry_type: "makeup"
    }));
    return slots;
  }

  /* conflict_checker.check_conflicts for a swap of entries a and b. Every
     teacher/section/room clash is "hard" here; the real _severity() reads
     scheduler_rules.HARD_CONFLICT_TYPES, which does not exist yet (README). */
  function swapConflicts(db, a, b) {
    const slots = [
      { day: b.day_of_week, start: b.start_time, end: b.end_time, teacher_id: a.teacher_id, section_id: a.section_id, room_id: a.room_id, entry_id: a.id },
      { day: a.day_of_week, start: a.start_time, end: a.end_time, teacher_id: b.teacher_id, section_id: b.section_id, room_id: b.room_id, entry_id: b.id }
    ];
    // SQL "x = NULL" is never true, so a missing teacher/room never clashes.
    const same = (x, y) => x != null && x === y;
    const out = [];
    slots.forEach((slot) => {
      db.timetable_entries.forEach((e) => {
        if (e.id === a.id || e.id === b.id) return;
        if (e.day_of_week !== slot.day || !overlaps(slot.start, slot.end, e.start_time, e.end_time)) return;
        [["teacher", same(e.teacher_id, slot.teacher_id)], ["section", same(e.section_id, slot.section_id)],
          ["room", same(e.room_id, slot.room_id)]].forEach(([type, hit]) => {
          if (hit) {
            out.push({ type, entry_id: e.id, severity: "hard",
              detail: type + " clash with entry " + e.id + " on " + slot.day + " " + e.start_time + "-" + e.end_time });
          }
        });
      });
    });
    const [p, q] = slots;
    if (p.day === q.day && overlaps(p.start, p.end, q.start, q.end)) {
      [["teacher", same(p.teacher_id, q.teacher_id)], ["section", same(p.section_id, q.section_id)],
        ["room", same(p.room_id, q.room_id)]].forEach(([type, hit]) => {
        if (hit) out.push({ type, entry_id: q.entry_id, severity: "hard", detail: type + " clash between two proposed slots on " + p.day });
      });
    }
    return out;
  }

  /* notification_service.recipients_for_swap + notify_many */
  function notifySwap(db, a, b, type, swapId, message) {
    const ids = new Set();
    db.teachers.filter((t) => t.id === a.teacher_id || t.id === b.teacher_id)
      .forEach((t) => { if (t.user_id != null) ids.add(t.user_id); });
    db.users.filter((u) => u.role === "student" && (u.section_id === a.section_id || u.section_id === b.section_id))
      .forEach((u) => ids.add(u.id));
    Array.from(ids).sort((x, y) => x - y).forEach((uid) => {
      db.notifications.push({ id: nextId(db.notifications), user_id: uid, type, message, related_id: swapId, is_read: false, created_at: nowIso() });
    });
  }

  /* core/permissions.can_decide_swap */
  function canDecideSwap(db, user, swap) {
    const requester = byId(db.teachers, swap.requester_teacher_id);
    if (!requester || requester.user_id === user.id) return false;
    if (user.role === "admin") return true;
    if (user.role === "hod") return user.department_id != null && user.department_id === requester.department_id;
    return false;
  }

  /* ---- Route handlers -------------------------------------------------- */
  const routes = [];
  const route = (method, pattern, fn) => routes.push({ method, pattern, fn });

  /* ---- Signup plan helpers (pending backend; see the header) ---------- */
  const AUTH = window.TT_CONFIG.AUTH;
  const CODE_MINUTES = 10;
  const MAX_ATTEMPTS = 5;

  function findAccount(db, typed) {
    const t = String(typed || "").trim().toLowerCase();
    if (!t) return null;
    return db.users.find((u) => u.identifier === t || (u.email && u.email === t)) || null;
  }

  function cleanEmail(value) {
    const email = String(value || "").trim().toLowerCase();
    const at = email.indexOf("@");
    if (!email.endsWith(AUTH.EMAIL_DOMAIN) || at < 1 || at !== email.lastIndexOf("@")) {
      throw new HttpError(400, "Use your NUML email address (ending in " + AUTH.EMAIL_DOMAIN + ")");
    }
    return email;
  }

  function checkPassword(pw, field) {
    const p = String(pw || "");
    if (p.length < 8 || new TextEncoder().encode(p).length > 72) {
      throw new HttpError(422, [{ loc: ["body", field], msg: "Password must be 8 to 72 characters", type: "value_error" }]);
    }
    return p;
  }

  function requireText(value, field, label) {
    const v = String(value || "").trim();
    if (!v) throw new HttpError(422, [{ loc: ["body", field], msg: label + " is required", type: "missing" }]);
    return v;
  }

  const secondsSince = (iso) => (Date.now() - Date.parse(iso + "Z")) / 1000;

  /* Store a new code (hashed) for this email and purpose, replacing any old
     one. In mock mode "sending" it means nothing: the code is MOCK_CODE. */
  async function issueCode(db, email, purpose) {
    db.email_codes = db.email_codes.filter((c) => !(c.email === email && c.purpose === purpose));
    db.email_codes.push({
      email, purpose,
      code_sha256: await sha256Hex(email + ":" + purpose + ":" + AUTH.MOCK_CODE),
      expires_at: new Date(Date.now() + CODE_MINUTES * 60000).toISOString().slice(0, 19),
      attempts: 0,
      sent_at: nowIso()
    });
  }

  /* Check a typed code. Wrong codes count; after MAX_ATTEMPTS the code is dead. */
  async function useCode(db, email, purpose, code) {
    const row = db.email_codes.find((c) => c.email === email && c.purpose === purpose);
    if (!row || secondsSince(row.expires_at) > 0) throw new HttpError(400, "The code is wrong or has expired. Ask for a new one.");
    if (row.attempts >= MAX_ATTEMPTS) throw new HttpError(429, "Too many attempts. Try again later.");
    const ok = (await sha256Hex(email + ":" + purpose + ":" + String(code || "").trim())) === row.code_sha256;
    if (!ok) {
      row.attempts += 1;
      saveDb(db);   // the attempt counts even though the request fails
      if (row.attempts >= MAX_ATTEMPTS) throw new HttpError(429, "Too many attempts. Try again later.");
      throw new HttpError(400, "The code is wrong or has expired. Ask for a new one.");
    }
    db.email_codes = db.email_codes.filter((c) => c !== row);   // single use
  }

  // PENDING (signup plan): departments and sections for the signup dropdowns.
  // Public, because the person has no account yet. Names only.
  route("GET", /^\/api\/auth\/signup-options$/, (db) => ({
    status: 200,
    data: {
      departments: db.departments.slice().sort((a, b) => a.name.localeCompare(b.name)).map((d) => pick(d, ["id", "name", "code"])),
      sections: db.sections.slice().sort((a, b) => a.name.localeCompare(b.name)).map((x) => pick(x, ["id", "name", "department_id"]))
    }
  }));

  // PENDING (signup plan): start a signup. The email decides student or staff;
  // the role is never read from the request. Nothing is a real account until
  // the code is entered, and a new signup replaces an unfinished one, so
  // nobody can block someone else's email.
  route("POST", /^\/api\/auth\/signup$/, async (db, req) => {
    const b = req.body || {};
    const email = cleanEmail(b.email);
    const name = requireText(b.name, "name", "Full name");
    const dept = byId(db.departments, b.department_id);
    if (!dept) throw new HttpError(422, [{ loc: ["body", "department_id"], msg: "Choose your department", type: "missing" }]);
    const password = checkPassword(b.password, "password");
    if (db.users.some((u) => u.email === email)) {
      throw new HttpError(409, "This email already has an account. Log in instead.");
    }

    const isStudent = AUTH.STUDENT_EMAIL.test(email);
    const local = email.slice(0, email.indexOf("@"));
    const signup = { email, name, department_id: dept.id, kind: isStudent ? "student" : "staff" };
    if (isStudent) {
      const section = byId(db.sections, b.section_id);
      if (!section || section.department_id !== dept.id) {
        throw new HttpError(422, [{ loc: ["body", "section_id"], msg: "Choose a section of your department", type: "value_error" }]);
      }
      const roll = requireText(b.roll_number, "roll_number", "Roll number").toLowerCase();
      if (!AUTH.ROLL_NUMBER.test(roll)) {
        throw new HttpError(422, [{ loc: ["body", "roll_number"], msg: "Roll number should look like se-9244330", type: "value_error" }]);
      }
      const parts = /^numl-([a-z])(\d{2})-(\d+)$/.exec(local);
      Object.assign(signup, {
        identifier: local, section_id: section.id, roll_number: roll,
        sys_id: parts[3], join_term: parts[1] + parts[2]
      });
    } else {
      signup.identifier = email;   // staff log in with their full email
    }
    if (db.users.some((u) => u.identifier === signup.identifier)) {
      throw new HttpError(409, "This email already has an account. Log in instead.");
    }
    signup.password_sha256 = await sha256Hex(signup.identifier + ":" + password);   // never the password itself

    db.pending_signups = db.pending_signups.filter((p) => p.email !== email);
    db.pending_signups.push(Object.assign(signup, { created_at: nowIso() }));
    await issueCode(db, email, "signup");
    return { status: 202, data: { email, kind: signup.kind, expires_in: CODE_MINUTES * 60 } };
  });

  // PENDING (signup plan): enter the signup code. Students become active and
  // are logged in; staff become a pending teacher who cannot log in yet.
  route("POST", /^\/api\/auth\/verify-email$/, async (db, req) => {
    const b = req.body || {};
    const email = cleanEmail(b.email);
    const signup = db.pending_signups.find((p) => p.email === email);
    if (!signup || secondsSince(signup.created_at) > CODE_MINUTES * 60) {
      throw new HttpError(400, "This signup has expired. Start again.");
    }
    await useCode(db, email, "signup", b.code);

    const user = {
      id: nextId(db.users),
      name: signup.name,
      identifier: signup.identifier,
      email,
      role: signup.kind === "student" ? "student" : "teacher",
      department_id: signup.department_id,
      section_id: signup.section_id || null,
      roll_number: signup.roll_number || null,
      sys_id: signup.sys_id || null,
      join_term: signup.join_term || null,
      status: signup.kind === "student" ? "active" : "pending",
      email_verified_at: nowIso(),
      password_sha256: signup.password_sha256,
      is_active: true
    };
    db.users.push(user);
    db.pending_signups = db.pending_signups.filter((p) => p !== signup);

    if (user.status === "pending") return { status: 200, data: { status: "pending" } };
    const token = randomToken();
    const sessions = readSessions();
    sessions[token] = user.id;
    writeSessions(sessions);
    return { status: 200, data: { status: "active", token, user: pick(user, SHAPES.UserOut) } };
  });

  // PENDING (signup plan): send a new signup code, at most once a minute.
  // (Forgot password resends by calling forgot-password again.)
  route("POST", /^\/api\/auth\/resend-code$/, async (db, req) => {
    const b = req.body || {};
    const purpose = "signup";
    const email = cleanEmail(b.email);
    if (!db.pending_signups.some((p) => p.email === email)) throw new HttpError(400, "This signup has expired. Start again.");
    const old = db.email_codes.find((c) => c.email === email && c.purpose === purpose);
    if (old && secondsSince(old.sent_at) < AUTH.RESEND_SECONDS) {
      throw new HttpError(429, "Please wait a minute before asking for a new code.");
    }
    await issueCode(db, email, purpose);
    return { status: 202, data: {} };
  });

  // PENDING (signup plan): forgot password. Always the same answer, so the
  // page cannot be used to check who is registered. The code goes to the
  // email the account signed up with.
  route("POST", /^\/api\/auth\/forgot-password$/, async (db, req) => {
    const b = req.body || {};
    requireText(b.identifier, "identifier", "NUML email or ID");
    const user = findAccount(db, b.identifier);
    if (user && user.email && user.status !== "rejected") {
      const old = db.email_codes.find((c) => c.email === user.email && c.purpose === "reset");
      if (!old || secondsSince(old.sent_at) >= AUTH.RESEND_SECONDS) await issueCode(db, user.email, "reset");
    }
    return { status: 202, data: { detail: "If that account exists, we sent a code to its email." } };
  });

  // PENDING (signup plan): set a new password with the reset code.
  route("POST", /^\/api\/auth\/reset-password$/, async (db, req) => {
    const b = req.body || {};
    const password = checkPassword(b.new_password, "new_password");
    const user = findAccount(db, b.identifier);
    if (!user || !user.email) throw new HttpError(400, "The code is wrong or has expired. Ask for a new one.");
    await useCode(db, user.email, "reset", b.code);
    user.password_sha256 = await sha256Hex(user.identifier + ":" + password);
    // Log the account out everywhere, like a real password change should.
    const sessions = readSessions();
    Object.keys(sessions).forEach((t) => { if (sessions[t] === user.id) delete sessions[t]; });
    writeSessions(sessions);
    return { status: 200, data: { detail: "Password changed. You can log in now." } };
  });

  // routers/auth.py login()
  route("POST", /^\/api\/auth\/login$/, async (db, req) => {
    const body = req.body || {};
    const errors = [];
    if (!body.identifier) errors.push({ loc: ["body", "identifier"], msg: "String should have at least 1 character", type: "string_too_short" });
    if (!body.password) errors.push({ loc: ["body", "password"], msg: "String should have at least 1 character", type: "string_too_short" });
    if (errors.length) throw new HttpError(422, errors);

    // "NUML email or ID": a roll number / staff ID, a student's
    // numl-f24-26789, or the full NUML email (signup plan).
    const typed = String(body.identifier).trim().toLowerCase();
    const user = findAccount(db, typed);
    // Hash even for unknown users, like the backend's DUMMY_HASH, so the code
    // path is the same either way.
    const hash = await sha256Hex((user ? user.identifier : typed) + ":" + String(body.password));
    if (!user || !user.is_active || !user.password_sha256 || hash !== user.password_sha256) {
      throw new HttpError(401, "Invalid credentials");
    }
    // The one exception to "same message for everything" (signup plan): only
    // after the right password, so it does not reveal which accounts exist.
    if (user.status === "pending") throw new HttpError(403, "Your account is waiting for approval");
    if (user.status === "rejected") throw new HttpError(401, "Invalid credentials");
    const token = randomToken();
    const sessions = readSessions();
    sessions[token] = user.id;
    writeSessions(sessions);
    return { status: 200, data: { token, user: pick(user, SHAPES.UserOut) } };
  });

  // routers/auth.py me()  (+ the PROPOSED fields, see header)
  route("GET", /^\/api\/auth\/me$/, (db, req) => {
    const user = currentUser(db, req.token);
    const teacher = teacherOfUser(db, user);
    return { status: 200, data: pick(Object.assign({}, user, { teacher_id: teacher ? teacher.id : null }), SHAPES.MeOutProposed) };
  });

  // routers/timetable.py list_timetable()
  route("GET", /^\/api\/timetable$/, (db, req) => {
    currentUser(db, req.token);
    const q = req.query;
    let rows = db.timetable_entries.slice();
    ["section_id", "teacher_id", "room_id", "course_id"].forEach((k) => {
      if (q[k]) rows = rows.filter((e) => e[k] === Number(q[k]));
    });
    if (q.department_id) {
      const ids = db.sections.filter((s) => s.department_id === Number(q.department_id)).map((s) => s.id);
      rows = rows.filter((e) => ids.includes(e.section_id));
    }
    if (q.day) {
      const day = normalizeDay(q.day);
      if (!day) throw new HttpError(422, "Unrecognised day: '" + q.day + "'");
      rows = rows.filter((e) => e.day_of_week === day);
    }
    // ORDER BY day_of_week (a string, so alphabetical), start_time - same as SQL.
    rows.sort((a, b) => a.day_of_week.localeCompare(b.day_of_week) || a.start_time.localeCompare(b.start_time));
    const skip = Number(q.skip || 0);
    const limit = Number(q.limit || 500);
    return { status: 200, data: rows.slice(skip, skip + limit).map((e) => pick(e, SHAPES.TimetableEntryOut)) };
  });

  // routers/rooms.py list_rooms(), list_departments(), list_courses()
  route("GET", /^\/api\/rooms$/, (db, req) => {
    currentUser(db, req.token);
    const q = req.query;
    let rows = db.rooms.slice().sort((a, b) => a.name.localeCompare(b.name));
    if (q.min_capacity) rows = rows.filter((r) => r.capacity >= Number(q.min_capacity));
    if (q.building) rows = rows.filter((r) => r.building === q.building);
    if (q.search) rows = rows.filter((r) => r.name.toLowerCase().includes(String(q.search).toLowerCase()));
    const skip = Number(q.skip || 0);
    return { status: 200, data: rows.slice(skip, skip + Number(q.limit || 100)).map((r) => pick(r, SHAPES.RoomOut)) };
  });

  route("GET", /^\/api\/departments$/, (db, req) => {
    currentUser(db, req.token);
    return { status: 200, data: db.departments.slice().sort((a, b) => a.name.localeCompare(b.name)).map((d) => pick(d, SHAPES.DepartmentOut)) };
  });

  route("GET", /^\/api\/courses$/, (db, req) => {
    currentUser(db, req.token);
    const q = req.query;
    let rows = db.courses.slice().sort((a, b) => a.code.localeCompare(b.code));
    if (q.department_id) rows = rows.filter((c) => c.department_id === Number(q.department_id));
    if (q.search) {
      const s = String(q.search).toLowerCase();
      rows = rows.filter((c) => c.code.toLowerCase().includes(s) || c.title.toLowerCase().includes(s));
    }
    const skip = Number(q.skip || 0);
    return { status: 200, data: rows.slice(skip, skip + Number(q.limit || 100)).map((c) => pick(c, SHAPES.CourseOut)) };
  });

  // routers/makeup.py list_makeup_classes()
  route("GET", /^\/api\/makeup$/, (db, req) => {
    currentUser(db, req.token);
    const q = req.query;
    let rows = db.makeup_classes.filter((m) => m.status === "scheduled");
    if (q.section_id) rows = rows.filter((m) => m.section_id === Number(q.section_id));
    if (q.teacher_id) rows = rows.filter((m) => m.teacher_id === Number(q.teacher_id));
    rows.sort((a, b) => a.day_of_week.localeCompare(b.day_of_week) || a.start_time.localeCompare(b.start_time));
    return { status: 200, data: rows.map((m) => pick(m, SHAPES.MakeupClassOut)) };
  });

  // routers/makeup.py search_makeup_slots() + services/makeup_solver.find_makeup_slots()
  route("POST", /^\/api\/makeup\/find-slots$/, (db, req) => {
    const user = currentUser(db, req.token);
    requireRoles(user, STAFF, "Staff role required");
    const b = req.body || {};
    const sectionId = toInt(b.section_id, "section_id", true);
    const teacherId = toInt(b.teacher_id, "teacher_id", true);
    const courseId = toInt(b.course_id, "course_id", true);
    const duration = b.duration_minutes == null ? 60 : toInt(b.duration_minutes, "duration_minutes", true);
    if (duration < 30 || duration > 240) {
      throw new HttpError(422, [{ loc: ["body", "duration_minutes"], msg: "Input should be between 30 and 240", type: "range" }]);
    }
    const preferred = toInt(b.preferred_room_id, "preferred_room_id", false);
    const days = Array.isArray(b.days) && b.days.length ? b.days : WEEKDAYS;
    days.forEach((d) => { if (!DAYS.includes(d)) throw new HttpError(422, "Invalid day_of_week: '" + d + "'"); });
    const term = b.term || "Fall 2026";

    const section = byId(db.sections, sectionId);
    const teacher = byId(db.teachers, teacherId);
    const course = byId(db.courses, courseId);
    if (!section) throw new HttpError(422, "Section not found");
    if (!teacher) throw new HttpError(422, "Teacher not found");
    if (!course) throw new HttpError(422, "Course not found");
    if (section.student_count == null) {
      throw new HttpError(422, "Section student_count is unknown (None); set student_count before running the solver");
    }

    const rooms = db.rooms.slice().sort((x, y) => (x.capacity - y.capacity) || x.name.localeCompare(y.name))
      .filter((r) => roomSuitability(section.student_count, r, course.is_lab).length === 0);
    if (!rooms.length) return { status: 200, data: [] };

    const existing = activeSlots(db, term);
    const out = [];
    days.forEach((day) => {
      const daySection = existing.filter((s) => s.day_of_week === day && s.section_id === sectionId);
      CANDIDATE_STARTS.forEach((start) => {
        const startT = start + ":00";
        const endT = addMinutes(startT, duration);
        if (!endT || endT > CAMPUS_CLOSE) return;
        const probe = { day_of_week: day, start_time: startT, end_time: endT, section_id: sectionId, course_id: courseId, teacher_id: teacherId, room_id: null };
        if (hardClashes(probe, existing).length) return;
        const free = rooms.filter((r) => !hardClashes(Object.assign({}, probe, { room_id: r.id }), existing).length)
          .map((r) => ({ room_id: r.id, room_name: r.name, capacity: r.capacity || 0, room_type: r.room_type, building: r.building, is_preferred: r.id === preferred }));
        if (!free.length) return;
        free.sort((x, y) => (Number(!x.is_preferred) - Number(!y.is_preferred)) || (x.capacity - y.capacity) || x.room_name.localeCompare(y.room_name));
        out.push({
          day_of_week: day, start_time: startT, end_time: endT,
          recommended_room: free[0], fallback_rooms: free.slice(1),
          soft_warnings: softGaps(daySection.concat([probe]))
        });
      });
    });
    return { status: 200, data: out };
  });

  // routers/makeup.py book_makeup_class()
  route("POST", /^\/api\/makeup$/, (db, req) => {
    const user = currentUser(db, req.token);
    requireRoles(user, STAFF, "Staff role required");
    const b = req.body || {};
    const courseId = toInt(b.course_id, "course_id", true);
    const sectionId = toInt(b.section_id, "section_id", true);
    const teacherId = toInt(b.teacher_id, "teacher_id", true);
    const roomId = toInt(b.room_id, "room_id", true);
    const start = toTime(b.start_time, "start_time");
    const end = toTime(b.end_time, "end_time");
    if (end <= start) throw new HttpError(422, "end_time must be after start_time");

    const section = byId(db.sections, sectionId);
    const teacher = byId(db.teachers, teacherId);
    const course = byId(db.courses, courseId);
    const room = byId(db.rooms, roomId);
    if (!section || !teacher || !course || !room) throw new HttpError(404, "Section, teacher, course, or room not found");

    const fit = roomSuitability(section.student_count, room, course.is_lab);
    if (fit.length) throw new HttpError(409, fit[0]);

    const candidate = { day_of_week: b.day_of_week, start_time: start, end_time: end, section_id: sectionId, course_id: courseId, teacher_id: teacherId, room_id: roomId };
    const clashes = hardClashes(candidate, activeSlots(db, b.term || "Fall 2026"));
    if (clashes.length) throw new HttpError(409, clashes[0]);

    const row = {
      id: nextId(db.makeup_classes), course_id: courseId, section_id: sectionId, teacher_id: teacherId,
      room_id: roomId, class_date: b.class_date || null, day_of_week: b.day_of_week, start_time: start,
      end_time: end, reason: b.reason || null, status: "scheduled", created_by: user.id
    };
    db.makeup_classes.push(row);
    return { status: 201, data: pick(row, SHAPES.MakeupClassOut) };
  });

  // routers/swaps.py create_swap()
  route("POST", /^\/api\/swap-requests$/, (db, req) => {
    const user = currentUser(db, req.token);
    requireRoles(user, ["teacher"]);
    const b = req.body || {};
    const entryId = toInt(b.entry_id, "entry_id", true);
    const targetId = toInt(b.target_entry_id, "target_entry_id", true);
    if (entryId === targetId) throw new HttpError(400, "A class cannot be swapped with itself");
    const entry = byId(db.timetable_entries, entryId);
    const target = byId(db.timetable_entries, targetId);
    if (!entry || !target) throw new HttpError(404, "Timetable entry not found");
    const owner = byId(db.teachers, entry.teacher_id);
    if (!owner || owner.user_id !== user.id) throw new HttpError(403, "You can only request swaps for your own classes");

    const hard = swapConflicts(db, entry, target).filter((c) => c.severity === "hard");
    if (hard.length) throw new HttpError(409, { message: "Swap would cause scheduling conflicts", conflicts: hard });

    const busy = db.swap_requests.some((s) => s.status === "pending" &&
      [entryId, targetId].some((id) => s.entry_id === id || s.target_entry_id === id));
    if (busy) throw new HttpError(409, "A pending swap request already exists for one of these classes");

    const swap = {
      id: nextId(db.swap_requests), requester_teacher_id: entry.teacher_id, entry_id: entryId,
      target_entry_id: targetId, status: "pending", reason: b.reason || null, decided_by: null,
      decided_at: null, decision_note: null, created_at: nowIso()
    };
    db.swap_requests.push(swap);
    notifySwap(db, entry, target, "swap_requested", swap.id, "Swap request #" + swap.id + " has been submitted for approval.");
    return { status: 201, data: Object.assign(pick(swap, SHAPES.SwapRequestOut), { warnings: [] }) };
  });

  // routers/swaps.py list_swaps() + swap_service.list_swaps_for_user()
  route("GET", /^\/api\/swap-requests$/, (db, req) => {
    const user = currentUser(db, req.token);
    requireRoles(user, STAFF);
    let rows = [];
    if (user.role === "teacher") {
      const t = teacherOfUser(db, user);
      rows = t ? db.swap_requests.filter((s) => s.requester_teacher_id === t.id) : [];
    } else if (user.role === "hod") {
      rows = db.swap_requests.filter((s) => {
        const t = byId(db.teachers, s.requester_teacher_id);
        return s.status === "pending" && t && t.department_id === user.department_id;
      });
    } else {
      rows = db.swap_requests.filter((s) => s.status === "pending");
    }
    rows = rows.slice().sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id - a.id);
    return { status: 200, data: rows.map((s) => pick(s, SHAPES.SwapRequestOut)) };
  });

  // routers/swaps.py approve_swap() / reject_swap() + swap_service
  function decide(db, req, approve) {
    const user = currentUser(db, req.token);
    requireRoles(user, ["hod", "admin"]);
    const swap = byId(db.swap_requests, req.params[1]);
    if (!swap) throw new HttpError(404, "Swap request not found");
    const note = req.body && typeof req.body.note === "string" ? req.body.note.trim() : "";
    if (!approve && !note) {
      throw new HttpError(422, [{ loc: ["body", "note"], msg: "String should have at least 1 character", type: "string_too_short" }]);
    }
    if (swap.status !== "pending") throw new HttpError(409, "Swap request is already " + swap.status);
    const entry = byId(db.timetable_entries, swap.entry_id);
    const target = byId(db.timetable_entries, swap.target_entry_id);
    if (!entry || !target) throw new HttpError(404, "Timetable entry no longer exists");
    if (!canDecideSwap(db, user, swap)) throw new HttpError(403, "You may not decide this swap request");

    if (approve) {
      const hard = swapConflicts(db, entry, target).filter((c) => c.severity === "hard");
      if (hard.length) throw new HttpError(409, { message: "Swap would cause scheduling conflicts", conflicts: hard });
      ["day_of_week", "start_time", "end_time"].forEach((k) => { const t = entry[k]; entry[k] = target[k]; target[k] = t; });
    }
    swap.status = approve ? "approved" : "rejected";
    swap.decided_by = user.id;
    swap.decided_at = nowIso();
    swap.decision_note = note || null;
    notifySwap(db, entry, target, approve ? "swap_approved" : "swap_rejected", swap.id, approve
      ? "Swap request #" + swap.id + " was approved and the timetable has been updated."
      : "Swap request #" + swap.id + " was rejected.");
    return { status: 200, data: pick(swap, SHAPES.SwapRequestOut) };
  }
  route("POST", /^\/api\/swap-requests\/(\d+)\/approve$/, (db, req) => decide(db, req, true));
  route("POST", /^\/api\/swap-requests\/(\d+)\/reject$/, (db, req) => decide(db, req, false));

  // routers/swaps.py cancel_swap()
  route("POST", /^\/api\/swap-requests\/(\d+)\/cancel$/, (db, req) => {
    const user = currentUser(db, req.token);
    requireRoles(user, ["teacher"]);
    const swap = byId(db.swap_requests, req.params[1]);
    if (!swap) throw new HttpError(404, "Swap request not found");
    const requester = byId(db.teachers, swap.requester_teacher_id);
    if (!requester || requester.user_id !== user.id) throw new HttpError(403, "Only the requester can cancel this swap request");
    if (swap.status !== "pending") throw new HttpError(409, "Swap request is already " + swap.status);
    swap.status = "cancelled";
    return { status: 200, data: pick(swap, SHAPES.SwapRequestOut) };
  });

  // routers/notifications.py
  route("GET", /^\/api\/notifications$/, (db, req) => {
    const user = currentUser(db, req.token);
    const unreadOnly = String(req.query.unread_only) === "true";
    const limit = Math.min(Math.max(Number(req.query.limit || 50), 1), 200);
    const rows = db.notifications.filter((n) => n.user_id === user.id && (!unreadOnly || !n.is_read))
      .sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id - a.id)
      .slice(0, limit);
    return { status: 200, data: rows.map((n) => pick(n, SHAPES.NotificationOut)) };
  });

  route("GET", /^\/api\/notifications\/unread-count$/, (db, req) => {
    const user = currentUser(db, req.token);
    return { status: 200, data: { count: db.notifications.filter((n) => n.user_id === user.id && !n.is_read).length } };
  });

  route("PATCH", /^\/api\/notifications\/(\d+)\/read$/, (db, req) => {
    const user = currentUser(db, req.token);
    const n = db.notifications.find((x) => x.id === Number(req.params[1]) && x.user_id === user.id);
    if (!n) throw new HttpError(404, "Notification not found");   // same answer for "not yours"
    n.is_read = true;
    return { status: 200, data: pick(n, SHAPES.NotificationOut) };
  });

  route("POST", /^\/api\/notifications\/read-all$/, (db, req) => {
    const user = currentUser(db, req.token);
    let updated = 0;
    db.notifications.forEach((n) => { if (n.user_id === user.id && !n.is_read) { n.is_read = true; updated += 1; } });
    return { status: 200, data: { updated } };
  });

  /* ---- Entry point used by tt-api.js ----------------------------------- */
  async function handle(req) {
    let db;
    try {
      db = await loadDb();
    } catch (e) {
      return { status: 500, data: { detail: e.message } };
    }
    const match = routes.map((r) => ({ r, m: r.method === req.method ? r.pattern.exec(req.path) : null })).find((x) => x.m);
    if (!match) return { status: 404, data: { detail: "Not Found" } };

    try {
      const res = await match.r.fn(db, Object.assign({}, req, { params: match.m }));
      if (req.method !== "GET") saveDb(db);
      // JSON round trip: the page gets a copy, exactly like a network response,
      // so it can never mutate the mock "database" through a shared reference.
      return { status: res.status, data: JSON.parse(JSON.stringify(res.data)) };
    } catch (e) {
      if (e instanceof HttpError) return { status: e.status, data: { detail: e.detail } };
      return { status: 500, data: { detail: "Internal Server Error" } };
    }
  }

  /* Rule for new handlers: validate everything first, mutate last. `db` is
     changed in place, so a check that throws after a mutation would leave
     half a change behind (the backend avoids the same thing with one
     transaction per request). */

  window.TTMock = { handle };
})();
