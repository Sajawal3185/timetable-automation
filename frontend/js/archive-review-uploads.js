/* archive-review-uploads.js - needs common.js, then api.js.
   The BACKEND decides who may review and when an upload counts as approved
   (two admins must approve). This page only shows the list and sends votes.
   Backend contract this page expects:
     GET  /api/uploads                 -> [{ id, title, kind: "midterm"|"final"|"lecture",
                                             uploaded_by, created_at, status: "pending"|"approved"|"rejected",
                                             approvals, required_approvals, my_vote: null|"approve"|"reject",
                                             file_url }]
     POST /api/uploads/{id}/review     body { decision: "approve" | "reject" }
 */
(function () {
  "use strict";

  const user = NUML.getUser();
  if (!NUML.isAdmin(user)) return;            // common.js is already redirecting

  const $ = (id) => document.getElementById(id);
  const KIND = { midterm: "Midterm", final: "Final", lecture: "Lecture" };

  let items = [];
  let tab = "pending";
  let query = "";
  let year = "all";
  let current = null;                          // upload open in the dialog
  const selected = new Set();

  $("adminName").textContent = user.name;
  $("logoutBtn").addEventListener("click", NUML.logout);

  /* ---------- messages ---------- */
  function banner(text, isError) {
    const b = $("banner");
    b.textContent = text || "";
    b.className = "ru-banner" + (isError ? " error" : "");
    b.hidden = !text;
  }

  /* ---------- load ---------- */
  async function load() {
    try {
      items = await NUML.api("/api/uploads");
      banner("");
    } catch (err) {
      items = [];
      if (err.status === 403) banner("Only admins can review uploads.", true);
      else if (err.status === 404 || err.status === 405) banner("The backend has no review API yet (GET /api/uploads), so there is nothing to show.", true);
      else banner("Could not load uploads. Try again in a minute.", true);
    }
    render();
  }

  /* ---------- render ---------- */
  /* Year of an upload: the paper/lecture year if the backend sends one, else the upload date. */
  function itemYear(u) {
    return Number(u.year) || new Date(u.created_at).getFullYear();
  }

  /* Dropdown runs from 2020 to the current year (or a later year found in the data),
     so a new year appears by itself every January. */
  function buildYears() {
    const sel = $("yearFilter");
    const top = Math.max(new Date().getFullYear(), ...items.map(itemYear));
    sel.replaceChildren(new Option("All years", "all"));
    for (let y = 2020; y <= top; y++) sel.add(new Option(String(y), String(y)));
    sel.value = year;
  }

  function visible() {
    const q = query.trim().toLowerCase();
    return items.filter((u) =>
      u.status === tab &&
      (year === "all" || itemYear(u) === Number(year)) &&
      (!q || u.title.toLowerCase().includes(q)));
  }

  function cell(text, cls) {
    const td = document.createElement("td");
    if (cls) td.className = cls;
    td.textContent = text;
    return td;
  }

  function render() {
    buildYears();
    document.querySelectorAll(".ru-tab").forEach((t) => {
      const s = t.dataset.status;
      const n = items.filter((u) => u.status === s).length;
      t.textContent = s[0].toUpperCase() + s.slice(1) + (s === "pending" ? " (" + n + ")" : "");
      t.setAttribute("aria-selected", String(s === tab));
    });

    const rows = visible();
    const body = $("rows");
    body.replaceChildren();

    rows.forEach((u) => {
      const tr = document.createElement("tr");
      const canVote = u.status === "pending" && !u.my_vote;

      const box = document.createElement("td");
      box.className = "ru-chk";
      if (u.status === "pending") {
        const c = document.createElement("input");
        c.type = "checkbox";
        c.checked = selected.has(u.id);
        c.disabled = !canVote;
        c.setAttribute("aria-label", "Select " + u.title);
        c.addEventListener("change", () => { c.checked ? selected.add(u.id) : selected.delete(u.id); updateBulk(); });
        box.appendChild(c);
      }
      tr.appendChild(box);

      tr.appendChild(cell(u.title, "title"));

      const type = document.createElement("td");
      const pill = document.createElement("span");
      pill.className = "ru-type " + u.kind;
      pill.textContent = KIND[u.kind] || u.kind;
      type.appendChild(pill);
      tr.appendChild(type);

      tr.appendChild(cell(u.uploaded_by, ""));
      tr.appendChild(cell(new Date(u.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "short" }), "muted"));
      tr.appendChild(cell(votesText(u), "muted"));

      const act = document.createElement("td");
      const b = document.createElement("button");
      b.type = "button";
      b.className = "ru-review";
      b.textContent = "Review";
      b.addEventListener("click", () => openDialog(u));
      act.appendChild(b);
      tr.appendChild(act);

      body.appendChild(tr);
    });

    const empty = $("empty");
    empty.hidden = rows.length > 0;
    empty.textContent = query ? "No uploads match your search." : "No " + tab + " uploads.";
    $("selectAll").style.visibility = tab === "pending" ? "visible" : "hidden";
    updateBulk();
  }

  function votesText(u) {
    if (u.status !== "pending") return u.status === "approved" ? "Approved" : "Rejected";
    const mine = u.my_vote ? " (you " + u.my_vote + "d)" : "";
    return u.approvals + " of " + u.required_approvals + mine;
  }

  function updateBulk() {
    const n = selected.size;
    $("bulk").hidden = n === 0;
    $("selCount").textContent = n + " selected";
  }

  /* ---------- voting ---------- */
  async function vote(ids, decision) {
    const failed = [];
    for (const id of ids) {
      try {
        await NUML.api("/api/uploads/" + id + "/review", { method: "POST", body: { decision } });
      } catch (err) {
        failed.push(err.message || "Request failed");
      }
    }
    selected.clear();
    await load();
    if (failed.length) banner(failed.length + " vote(s) failed: " + failed[0], true);
    return failed.length === 0;
  }

  $("bulkApprove").addEventListener("click", () => vote([...selected], "approve"));
  $("bulkReject").addEventListener("click", () => vote([...selected], "reject"));

  /* ---------- dialog ---------- */
  function openDialog(u) {
    current = u;
    $("dTitle").textContent = u.title;
    $("dType").textContent = KIND[u.kind] || u.kind;
    $("dBy").textContent = u.uploaded_by;
    $("dDate").textContent = new Date(u.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });
    $("dVotes").textContent = votesText(u);
    $("dMsg").hidden = true;
    const locked = u.status !== "pending" || !!u.my_vote;
    $("dApprove").disabled = locked;
    $("dReject").disabled = locked;
    $("dialog").showModal();
  }

  async function finish(decision) {
    const ok = await vote([current.id], decision);
    if (ok) $("dialog").close();
  }
  $("dApprove").addEventListener("click", () => finish("approve"));
  $("dReject").addEventListener("click", () => finish("reject"));
  $("dClose").addEventListener("click", () => $("dialog").close());

  $("dOpen").addEventListener("click", async function () {
    const msg = $("dMsg");
    msg.hidden = false;
    msg.className = "ru-banner";
    if (!current.file_url) { msg.textContent = "No file is attached to this upload."; return; }
    try {
      const res = await fetch(NUML.apiBase + current.file_url, { headers: { Authorization: "Bearer " + NUML.getToken() } });
      if (!res.ok) throw new Error();
      window.open(URL.createObjectURL(await res.blob()), "_blank");
      msg.hidden = true;
    } catch (e) {
      msg.className = "ru-banner error";
      msg.textContent = "Could not open the file.";
    }
  });

  /* ---------- tabs, search, select all ---------- */
  document.querySelectorAll(".ru-tab").forEach((t) => t.addEventListener("click", () => {
    tab = t.dataset.status;
    selected.clear();
    render();
  }));
  $("yearFilter").addEventListener("change", (e) => { year = e.target.value; selected.clear(); render(); });
  $("search").addEventListener("input", (e) => { query = e.target.value; render(); });
  $("selectAll").addEventListener("change", (e) => {
    visible().forEach((u) => { if (!u.my_vote) e.target.checked ? selected.add(u.id) : selected.delete(u.id); });
    render();
  });

  load();
})();