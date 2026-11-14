/* ==========================================================================
   tt-approvals.js  -  swap-approvals.html (hod, admin).

   GET /api/swap-requests already scopes the list on the server:
     hod   -> pending requests whose requester is in the HOD's department
     admin -> all pending requests
   The page does not filter again; it trusts the server's list and the server
   re-checks permission (and clashes) on every approve/reject anyway.

   Reject needs a note (SwapReject.note is required and non-blank), so the
   Reject button asks for one before calling the API. Approve's note is
   optional.

   A decided card stays on screen with its new status, so the HOD sees what
   just happened; "Reload list" shows the remaining pending ones.
   ========================================================================== */
(async function () {
  "use strict";

  const me = await TT.init({ allowed: ["hod", "admin"] });
  if (!me) return;

  const summary = document.getElementById("apprSummary");
  const pageMsg = document.getElementById("apprMsg");
  const list = document.getElementById("apprList");
  const empty = document.getElementById("apprEmpty");

  let names;
  let entryById = new Map();
  let pendingCount = 0;

  function describeEntry(id) {
    const e = entryById.get(id);
    return e ? TT.entryLabel(e, names) : "Class #" + id;
  }

  function renderSummary() {
    summary.textContent = pendingCount + " pending request" + (pendingCount === 1 ? "" : "s") +
      (me.role === "hod" ? " in your department." : " across all departments.");
  }

  async function decide(swap, card, approve) {
    const noteInput = card.querySelector("input");
    const msg = card.querySelector(".form-msg");
    const note = noteInput.value.trim();
    if (!approve && !note) {
      TT.showMsg(msg, "Write a note explaining the rejection first.", "error");
      noteInput.focus();
      return;
    }
    card.querySelectorAll("button").forEach((b) => { b.disabled = true; });
    try {
      const updated = await window.TTApi.request("POST", "/api/swap-requests/" + swap.id + (approve ? "/approve" : "/reject"), {
        body: { note: note || null }
      });
      const fresh = requestCard(updated);
      card.replaceWith(fresh);
      TT.showMsg(fresh.querySelector(".form-msg"), approve
        ? "Approved. Both classes now have their new times."
        : "Rejected. The teacher has been notified.", "ok");
      pendingCount = Math.max(0, pendingCount - 1);
      renderSummary();
    } catch (err) {
      TT.showError(msg, err);   // e.g. 409 conflicts appeared since the request was made
      card.querySelectorAll("button").forEach((b) => { b.disabled = false; });
    }
  }

  function requestCard(s) {
    const card = TT.el("article", { className: "paper-card" }, [
      TT.el("span", { className: "tt-status " + s.status, text: s.status }),
      TT.el("h3", { text: "Request #" + s.id }),
      TT.el("p", { text: "Requested by: " + names.teacherLabel(s.requester_teacher_id) }),
      TT.el("p", { text: "Class: " + describeEntry(s.entry_id) }),
      TT.el("p", { text: "Swap with: " + describeEntry(s.target_entry_id) }),
      s.reason ? TT.el("p", { text: "Reason: " + s.reason }) : null,
      s.decision_note ? TT.el("p", { text: "Decision note: " + s.decision_note }) : null,
      TT.el("p", { text: "Sent " + TT.when(s.created_at) }),
      TT.el("p", { className: "form-msg", attrs: { role: "alert" } })
    ]);
    if (s.status === "pending") {
      const noteId = "note-" + s.id;
      const input = TT.el("input", { attrs: { type: "text", id: noteId, maxlength: "500", placeholder: "Note (required to reject)" } });
      const approveBtn = TT.el("button", { className: "btn solid", text: "Approve", attrs: { type: "button" } });
      const rejectBtn = TT.el("button", { className: "btn dark", text: "Reject", attrs: { type: "button" } });
      approveBtn.addEventListener("click", () => decide(s, card, true));
      rejectBtn.addEventListener("click", () => decide(s, card, false));
      card.appendChild(TT.el("label", { className: "tt-note-field", text: "Note", attrs: { for: noteId } }));
      card.appendChild(input);
      card.appendChild(TT.el("div", { className: "actions" }, [approveBtn, rejectBtn]));
    }
    return card;
  }

  async function load() {
    summary.textContent = "Loading pending requests…";
    try {
      const [swaps, entries] = await Promise.all([
        window.TTApi.request("GET", "/api/swap-requests"),
        window.TTApi.request("GET", "/api/timetable", { query: { limit: 2000 } })
      ]);
      entryById = new Map(entries.map((e) => [e.id, e]));
      TT.clear(list);
      swaps.forEach((s) => list.appendChild(requestCard(s)));
      empty.hidden = swaps.length > 0;
      pendingCount = swaps.length;
      renderSummary();
      TT.showMsg(pageMsg, "");
    } catch (err) {
      summary.textContent = "The list could not be loaded.";
      TT.showError(pageMsg, err);
    }
  }

  try {
    names = await TT.loadNames();
  } catch (err) {
    TT.showError(pageMsg, err);
    return;
  }
  document.getElementById("apprReload").addEventListener("click", load);
  await load();
})();
