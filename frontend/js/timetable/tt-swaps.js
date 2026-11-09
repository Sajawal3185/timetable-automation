/* ==========================================================================
   tt-swaps.js  -  swaps.html (teacher only).

   Why "swap with another class" and not "pick any new day/time/room":
   the backend's swap is an EXCHANGE. POST /api/swap-requests takes
   {entry_id, target_entry_id, reason}; on approval the two entries trade
   day/start/end and each keeps its own room (swap_service.approve_swap).
   A free-form day/time/room form would send data the API cannot accept.

   Why HODs cannot use this page: create_swap is require_roles("teacher"),
   so an HOD's request would always get 403. HODs decide requests on
   swap-approvals.html instead.
   ========================================================================== */
(async function () {
  "use strict";

  const me = await TT.init({ allowed: ["teacher"] });
  if (!me) return;

  const form = document.getElementById("swapForm");
  const entrySel = document.getElementById("swapEntry");
  const targetSel = document.getElementById("swapTarget");
  const reasonInput = document.getElementById("swapReason");
  const preview = document.getElementById("swapPreview");
  const submitBtn = document.getElementById("swapSubmit");
  const formMsg = document.getElementById("swapMsg");
  const listMsg = document.getElementById("listMsg");
  const list = document.getElementById("swapList");
  const empty = document.getElementById("swapEmpty");

  if (me.teacher_id == null) {
    TT.showMsg(formMsg, "Your account is not linked to a teacher record yet, so you have no classes to swap.", "error");
    submitBtn.disabled = true;
    return;
  }

  let names;
  let entryById = new Map();

  function option(value, text) {
    return TT.el("option", { text, attrs: { value: String(value) } });
  }

  /* Candidate targets: classes in the teacher's department when /me says
     which one it is, otherwise every class. The server re-checks clashes
     either way, so a wide list is safe, just longer. */
  async function loadEntries() {
    const query = { limit: 2000 };
    if (me.department_id != null) query.department_id = me.department_id;
    const [all, mine] = await Promise.all([
      window.TTApi.request("GET", "/api/timetable", { query }),
      window.TTApi.request("GET", "/api/timetable", { query: { teacher_id: me.teacher_id, limit: 2000 } })
    ]);
    entryById = new Map(all.concat(mine).map((e) => [e.id, e]));
    return { all: all.slice().sort(TT.byDayTime), mine: mine.slice().sort(TT.byDayTime) };
  }

  function fillTargets(all) {
    const chosen = Number(entrySel.value);
    TT.clear(targetSel);
    targetSel.appendChild(option("", "Choose the class to trade times with"));
    all.filter((e) => e.id !== chosen).forEach((e) => {
      targetSel.appendChild(option(e.id, TT.entryLabel(e, names) + (e.teacher_id === me.teacher_id ? " (yours)" : "")));
    });
  }

  function updatePreview() {
    const a = entryById.get(Number(entrySel.value));
    const b = entryById.get(Number(targetSel.value));
    if (!a || !b) { preview.textContent = ""; return; }
    preview.textContent = "After approval: " + names.courseCode(a.course_id) + " moves to " +
      b.day_of_week + " " + TT.hhmm(b.start_time) + "–" + TT.hhmm(b.end_time) +
      " (room stays " + names.roomLabel(a.room_id) + "), and " + names.courseCode(b.course_id) +
      " moves to " + a.day_of_week + " " + TT.hhmm(a.start_time) + "–" + TT.hhmm(a.end_time) + ".";
  }

  function describeEntry(id) {
    const e = entryById.get(id);
    return e ? TT.entryLabel(e, names) : "Class #" + id + " (not in your department list)";
  }

  async function cancelRequest(swap, card) {
    const msg = card.querySelector(".form-msg");
    try {
      const updated = await window.TTApi.request("POST", "/api/swap-requests/" + swap.id + "/cancel");
      const fresh = swapCard(updated);
      card.replaceWith(fresh);
      TT.showMsg(fresh.querySelector(".form-msg"), "Request cancelled.", "ok");
    } catch (err) {
      TT.showError(msg, err);
    }
  }

  function swapCard(s) {
    const card = TT.el("article", { className: "paper-card" }, [
      TT.el("span", { className: "tt-status " + s.status, text: s.status }),
      TT.el("h3", { text: "Request #" + s.id }),
      TT.el("p", { text: "From: " + describeEntry(s.entry_id) }),
      TT.el("p", { text: "To: " + describeEntry(s.target_entry_id) }),
      s.reason ? TT.el("p", { text: "Reason: " + s.reason }) : null,
      s.decision_note ? TT.el("p", { text: "HOD note: " + s.decision_note }) : null,
      TT.el("p", { text: "Sent " + TT.when(s.created_at) + (s.decided_at ? " · decided " + TT.when(s.decided_at) : "") }),
      TT.el("p", { className: "form-msg", attrs: { role: "alert" } })
    ]);
    if (s.status === "pending") {
      const btn = TT.el("button", { className: "btn dark", text: "Cancel request", attrs: { type: "button" } });
      btn.addEventListener("click", () => { btn.disabled = true; cancelRequest(s, card); });
      card.appendChild(TT.el("div", { className: "actions" }, [btn]));
    }
    return card;
  }

  async function loadRequests() {
    try {
      const swaps = await window.TTApi.request("GET", "/api/swap-requests");
      TT.clear(list);
      swaps.forEach((s) => list.appendChild(swapCard(s)));
      empty.hidden = swaps.length > 0;
      TT.showMsg(listMsg, "");
    } catch (err) {
      TT.showError(listMsg, err);
    }
  }

  let entries;
  try {
    names = await TT.loadNames();
    entries = await loadEntries();
  } catch (err) {
    TT.showError(formMsg, err);
    submitBtn.disabled = true;
    return;
  }

  entrySel.appendChild(option("", entries.mine.length ? "Choose one of your classes" : "You have no classes this term"));
  entries.mine.forEach((e) => entrySel.appendChild(option(e.id, TT.entryLabel(e, names))));
  fillTargets(entries.all);

  entrySel.addEventListener("change", () => { fillTargets(entries.all); updatePreview(); });
  targetSel.addEventListener("change", updatePreview);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const entryId = Number(entrySel.value);
    const targetId = Number(targetSel.value);
    if (!entryId || !targetId) {
      TT.showMsg(formMsg, "Choose your class and the class to swap with.", "error");
      return;
    }
    submitBtn.disabled = true;
    TT.showMsg(formMsg, "Checking for clashes…");
    try {
      const created = await window.TTApi.request("POST", "/api/swap-requests", {
        body: { entry_id: entryId, target_entry_id: targetId, reason: reasonInput.value.trim() || null }
      });
      const warnings = (created.warnings || []).map((w) => "Warning: " + w.detail);
      TT.showMsg(formMsg, "Request #" + created.id + " sent. Status: " + created.status + ".", "ok", warnings);
      form.reset();
      fillTargets(entries.all);
      updatePreview();
      await loadRequests();
    } catch (err) {
      // 409 carries {message, conflicts}; showError lists each conflict.
      TT.showError(formMsg, err);
    } finally {
      submitBtn.disabled = false;
    }
  });

  await loadRequests();
})();
