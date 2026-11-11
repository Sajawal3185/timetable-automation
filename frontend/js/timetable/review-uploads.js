/* review-uploads.js - Review Uploads page (tabs, search, bulk select).
   Uses sample data for now. Later: replace UPLOADS with an API call. */
(function () {
  "use strict";

  /* ---- Sample data (replace with API later) ---- */
  const UPLOADS = [
    { id: 1, title: "SE-314 Database Systems",    type: "Midterm", by: "Ali Khan",      date: "10 Nov", status: "pending" },
    { id: 2, title: "SE-311 Week 4: Design Patterns", type: "Lecture", by: "Dr. Ahmed Raza", date: "10 Nov", status: "pending" },
    { id: 3, title: "CS-201 Data Structures",     type: "Final",   by: "Sara Malik",    date: "9 Nov",  status: "pending" },
    { id: 4, title: "SE-315 Requirements",        type: "Lecture", by: "Dr. Hina Shah", date: "9 Nov",  status: "pending" },
    { id: 5, title: "SE-312 Quality Engineering", type: "Midterm", by: "Usman Tariq",   date: "8 Nov",  status: "pending" },
    { id: 6, title: "CS-101 Programming Basics",  type: "Final",   by: "Hamza Ali",     date: "6 Nov",  status: "approved" },
    { id: 7, title: "SE-301 Software Process",    type: "Lecture", by: "Dr. Ahmed Raza", date: "5 Nov", status: "rejected" }
  ];

  const tbody = document.getElementById("ruRows");
  const empty = document.getElementById("ruEmpty");
  const bulk = document.getElementById("ruBulk");
  const count = document.getElementById("ruCount");
  const all = document.getElementById("ruAll");
  const search = document.getElementById("ruSearch");
  const tabs = document.querySelectorAll(".ru-tab");

  let status = "pending";
  const selected = new Set();

  /* Small helper: build an element with plain text only (no innerHTML). */
  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function visible() {
    const q = search.value.trim().toLowerCase();
    return UPLOADS.filter((u) => u.status === status && u.title.toLowerCase().includes(q));
  }

  function render() {
    const rows = visible();
    tbody.textContent = "";

    rows.forEach((u) => {
      const tr = el("tr", selected.has(u.id) ? "selected" : "");

      const cb = el("input");
      cb.type = "checkbox";
      cb.checked = selected.has(u.id);
      cb.setAttribute("aria-label", "Select " + u.title);
      cb.addEventListener("change", () => {
        cb.checked ? selected.add(u.id) : selected.delete(u.id);
        render();
      });
      const tdCheck = el("td", "c-check");
      tdCheck.appendChild(cb);

      const tdType = el("td");
      tdType.appendChild(el("span", "ru-badge " + u.type.toLowerCase(), u.type));

      const review = el("button", "ru-review", "Review");
      review.type = "button";
      review.addEventListener("click", () => alert("Open review for: " + u.title)); // TODO: real review view
      const tdAct = el("td");
      tdAct.style.textAlign = "right";
      tdAct.appendChild(review);

      tr.append(tdCheck, el("td", "title", u.title), tdType, el("td", "", u.by), el("td", "muted", u.date), tdAct);
      tbody.appendChild(tr);
    });

    empty.hidden = rows.length > 0;

    // tab counts
    tabs.forEach((t) => {
      const label = t.dataset.status[0].toUpperCase() + t.dataset.status.slice(1);
      const n = UPLOADS.filter((u) => u.status === t.dataset.status).length;
      t.textContent = t.dataset.status === "pending" ? label + " (" + n + ")" : label;
    });

    // bulk bar + select-all state
    const n = selected.size;
    bulk.hidden = n === 0;
    count.textContent = n + " selected";
    all.checked = rows.length > 0 && rows.every((u) => selected.has(u.id));
  }

  function setStatus(ids, newStatus) {
    UPLOADS.forEach((u) => { if (ids.has(u.id)) u.status = newStatus; });
    selected.clear();
    render();
  }

  /* ---- Events ---- */
  tabs.forEach((t) => t.addEventListener("click", () => {
    status = t.dataset.status;
    selected.clear();
    tabs.forEach((x) => {
      x.classList.toggle("active", x === t);
      x.setAttribute("aria-selected", x === t ? "true" : "false");
    });
    render();
  }));

  search.addEventListener("input", render);

  all.addEventListener("change", () => {
    visible().forEach((u) => (all.checked ? selected.add(u.id) : selected.delete(u.id)));
    render();
  });

  document.getElementById("ruApprove").addEventListener("click", () => setStatus(new Set(selected), "approved"));
  document.getElementById("ruReject").addEventListener("click", () => setStatus(new Set(selected), "rejected"));

  /* Log out: clear the token and go to the timetable login (same as tt-layout.js). */
  document.getElementById("ruLogout").addEventListener("click", () => {
    localStorage.removeItem("ttAuthToken");
    window.location.replace("timetable-login.html");
  });

  render();
})();