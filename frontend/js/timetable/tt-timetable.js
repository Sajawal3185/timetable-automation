/* ==========================================================================
   tt-timetable.js  -  timetable.html (student, teacher, hod).

   One view for every role; only the filter differs:
     student      -> GET /api/timetable?section_id=<their section>
     teacher/hod  -> GET /api/timetable?teacher_id=<their teacher row>
   plus GET /api/makeup with the same filter, so makeup classes appear in the
   same week.

   Where the section/teacher id comes from: /api/auth/me. The real UserOut
   does not return it yet (it only has id, name, role). Rather than guess,
   the page says so and shows nothing - showing a wrong section's week would
   be worse than showing none. See README "Backend gaps".
   ========================================================================== */
(async function () {
  "use strict";

  const me = await TT.init({ allowed: ["student", "teacher", "hod"] });
  if (!me) return;

  const summary = document.getElementById("ttSummary");
  const msg = document.getElementById("ttMsg");
  const week = document.getElementById("ttWeek");

  const isStudent = me.role === "student";
  const filterKey = isStudent ? "section_id" : "teacher_id";
  const filterValue = me[filterKey];

  if (filterValue == null) {
    summary.textContent = "No timetable to show.";
    TT.showMsg(msg, isStudent
      ? "Your account is not linked to a section yet. Ask your department admin to set it."
      : "Your account is not linked to a teacher record yet. Ask your department admin to link it.", "error");
    return;
  }

  let entries, makeups, names;
  try {
    [entries, makeups, names] = await Promise.all([
      window.TTApi.request("GET", "/api/timetable", { query: { [filterKey]: filterValue, limit: 2000 } }),
      window.TTApi.request("GET", "/api/makeup", { query: { [filterKey]: filterValue } }),
      TT.loadNames()
    ]);
  } catch (err) {
    summary.textContent = "Your timetable could not be loaded.";
    TT.showError(msg, err);
    return;
  }

  /* Build one card per class. Students see the room (and which teacher is
     not known by name yet); teachers see the section they teach. */
  function classCard(item, isMakeup) {
    const lines = [
      TT.el("span", { className: "tt-time", text: TT.hhmm(item.start_time) + " – " + TT.hhmm(item.end_time) }),
      TT.el("strong", { text: names.courseLabel(item.course_id) }),
      TT.el("span", { text: "Room: " + names.roomLabel(item.room_id) }),
      TT.el("span", { text: isStudent ? names.teacherLabel(item.teacher_id) : names.sectionLabel(item.section_id) })
    ];
    if (isMakeup) {
      lines.unshift(TT.el("span", { className: "tt-tag", text: "Makeup" }));
      if (item.class_date) lines.push(TT.el("small", { text: "On " + item.class_date }));
      if (item.reason) lines.push(TT.el("small", { text: item.reason }));
    }
    return TT.el("article", { className: "tt-class" + (isMakeup ? " makeup" : "") }, lines);
  }

  const all = entries.map((e) => ({ item: e, makeup: false }))
    .concat(makeups.map((m) => ({ item: m, makeup: true })))
    .sort((a, b) => TT.byDayTime(a.item, b.item));

  TT.clear(week);
  TT.DAYS.forEach((day) => {
    const today = all.filter((x) => x.item.day_of_week === day);
    const col = TT.el("section", { className: "tt-day", attrs: { "aria-label": day } }, [
      TT.el("h2", { text: day })
    ]);
    if (!today.length) col.appendChild(TT.el("p", { className: "tt-free", text: "No classes" }));
    today.forEach((x) => col.appendChild(classCard(x.item, x.makeup)));
    week.appendChild(col);
  });

  const who = isStudent ? names.sectionLabel(filterValue) : me.name;
  summary.textContent = who + ": " + entries.length + " weekly class" + (entries.length === 1 ? "" : "es") +
    (makeups.length ? " and " + makeups.length + " makeup class" + (makeups.length === 1 ? "" : "es") : "") + ".";
})();
