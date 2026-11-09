/* ==========================================================================
   tt-makeup.js  -  makeup.html (teacher, hod).

   1. "Section and course" comes from the teacher's own timetable, because
      POST /api/makeup/find-slots needs section_id + course_id + teacher_id
      and there is no /api/sections endpoint to list sections by name.
   2. find-slots returns clash-free (day, start, end) slots, each with a
      recommended room and fallback rooms. The room list per slot is a
      <select> so the teacher can take a fallback.
   3. Book -> POST /api/makeup. The slot list is a snapshot; someone may
      book the same time first. The server re-checks and answers 409 with
      the reason, which is shown on that slot. The list is not refreshed
      automatically after a booking, so the teacher sees which row they
      booked; "Find free slots" again gives a fresh list.
   ========================================================================== */
(async function () {
  "use strict";

  const me = await TT.init({ allowed: ["teacher", "hod"] });
  if (!me) return;

  const C = window.TT_CONFIG;
  const form = document.getElementById("mkForm");
  const classSel = document.getElementById("mkClass");
  const durationSel = document.getElementById("mkDuration");
  const daySel = document.getElementById("mkDay");
  const reasonInput = document.getElementById("mkReason");
  const findBtn = document.getElementById("mkFind");
  const msg = document.getElementById("mkMsg");
  const results = document.getElementById("mkResults");
  const booked = document.getElementById("mkBooked");
  const bookedMsg = document.getElementById("mkBookedMsg");
  const bookedEmpty = document.getElementById("mkBookedEmpty");

  if (me.teacher_id == null) {
    TT.showMsg(msg, "Your account is not linked to a teacher record yet, so makeup classes cannot be searched.", "error");
    findBtn.disabled = true;
    return;
  }

  let names;
  let mine;
  try {
    [names, mine] = await Promise.all([
      TT.loadNames(),
      window.TTApi.request("GET", "/api/timetable", { query: { teacher_id: me.teacher_id, limit: 2000 } })
    ]);
  } catch (err) {
    TT.showError(msg, err);
    findBtn.disabled = true;
    return;
  }

  /* Unique (section, course) pairs the teacher teaches. Value "3:6" keeps
     both ids in one <option> without a lookup table. */
  const pairs = new Map();
  mine.forEach((e) => pairs.set(e.section_id + ":" + e.course_id, e));
  classSel.appendChild(TT.el("option", { text: pairs.size ? "Choose a section and course" : "You have no classes this term", attrs: { value: "" } }));
  Array.from(pairs.values())
    .sort((a, b) => a.section_id - b.section_id || a.course_id - b.course_id)
    .forEach((e) => classSel.appendChild(TT.el("option", {
      text: names.sectionLabel(e.section_id) + " · " + names.courseLabel(e.course_id),
      attrs: { value: e.section_id + ":" + e.course_id }
    })));

  function roomText(r) {
    return r.room_name + " (" + r.capacity + " seats, " + r.room_type + (r.building ? ", " + r.building : "") + ")";
  }

  async function book(slot, row, roomSel, ids) {
    const rowMsg = row.querySelector(".form-msg");
    const btn = row.querySelector("button");
    btn.disabled = true;
    try {
      const made = await window.TTApi.request("POST", "/api/makeup", {
        body: {
          course_id: ids.courseId, section_id: ids.sectionId, teacher_id: me.teacher_id,
          room_id: Number(roomSel.value), day_of_week: slot.day_of_week,
          start_time: slot.start_time, end_time: slot.end_time,
          reason: reasonInput.value.trim() || null, term: C.TERM
        }
      });
      TT.showMsg(rowMsg, "Booked (makeup #" + made.id + ").", "ok");
      roomSel.disabled = true;
      btn.textContent = "Booked";
      await loadBooked();
    } catch (err) {
      // 409: the slot or room was taken since the search, or the room does not fit.
      TT.showError(rowMsg, err);
      btn.disabled = false;
    }
  }

  function slotRow(slot, ids) {
    const rooms = [slot.recommended_room].concat(slot.fallback_rooms || []);
    const roomSel = TT.el("select", { attrs: { "aria-label": "Room for " + slot.day_of_week + " " + TT.hhmm(slot.start_time) } },
      rooms.map((r, i) => TT.el("option", { text: (i === 0 ? "Recommended: " : "") + roomText(r), attrs: { value: String(r.room_id) } })));
    const btn = TT.el("button", { className: "btn solid", text: "Book", attrs: { type: "button" } });
    const row = TT.el("div", { className: "tt-slot" }, [
      TT.el("strong", { className: "tt-time", text: TT.hhmm(slot.start_time) + " – " + TT.hhmm(slot.end_time) }),
      roomSel,
      btn,
      TT.el("p", { className: "form-msg", attrs: { role: "alert" } })
    ]);
    (slot.soft_warnings || []).forEach((w) => row.appendChild(TT.el("p", { className: "tt-soft", text: "Note: " + w })));
    btn.addEventListener("click", () => book(slot, row, roomSel, ids));
    return row;
  }

  function renderSlots(slots, ids) {
    TT.clear(results);
    if (!slots.length) {
      results.appendChild(TT.el("p", { className: "empty", text: "No free slot found. Try another day or a shorter duration." }));
      return;
    }
    TT.DAYS.forEach((day) => {
      const today = slots.filter((s) => s.day_of_week === day);
      if (!today.length) return;
      const box = TT.el("section", { className: "tt-card tt-slots" }, [
        TT.el("h2", { text: day + " (" + today.length + " free slot" + (today.length === 1 ? "" : "s") + ")" })
      ]);
      today.forEach((s) => box.appendChild(slotRow(s, ids)));
      results.appendChild(box);
    });
  }

  async function loadBooked() {
    try {
      const rows = await window.TTApi.request("GET", "/api/makeup", { query: { teacher_id: me.teacher_id } });
      TT.clear(booked);
      rows.slice().sort(TT.byDayTime).forEach((m) => booked.appendChild(TT.el("article", { className: "paper-card" }, [
        TT.el("h3", { text: names.courseLabel(m.course_id) }),
        TT.el("p", { text: m.day_of_week + " " + TT.hhmm(m.start_time) + "–" + TT.hhmm(m.end_time) + (m.class_date ? " · " + m.class_date : "") }),
        TT.el("p", { text: names.sectionLabel(m.section_id) + " · Room " + names.roomLabel(m.room_id) }),
        m.reason ? TT.el("p", { text: m.reason }) : null
      ])));
      bookedEmpty.hidden = rows.length > 0;
      TT.showMsg(bookedMsg, "");
    } catch (err) {
      TT.showError(bookedMsg, err);
    }
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!classSel.value) {
      TT.showMsg(msg, "Choose a section and course first.", "error");
      return;
    }
    const [sectionId, courseId] = classSel.value.split(":").map(Number);
    findBtn.disabled = true;
    TT.showMsg(msg, "Searching…");
    TT.clear(results);
    try {
      const slots = await window.TTApi.request("POST", "/api/makeup/find-slots", {
        body: {
          section_id: sectionId, teacher_id: me.teacher_id, course_id: courseId,
          duration_minutes: Number(durationSel.value),
          days: daySel.value ? [daySel.value] : null,
          term: C.TERM
        }
      });
      TT.showMsg(msg, slots.length + " free slot" + (slots.length === 1 ? "" : "s") + " found.", "ok");
      renderSlots(slots, { sectionId, courseId });
    } catch (err) {
      TT.showError(msg, err);
    } finally {
      findBtn.disabled = false;
    }
  });

  await loadBooked();
})();
