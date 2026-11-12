/* ==========================================================================
   review-uploads.js  -  review-uploads.html (admin only).

   Students upload past papers and lectures -> status "pending".
   An admin opens one, previews the file, then votes Approve or Reject.
   It becomes FINAL when 2 different admins vote the same way:
     2 approvals -> appears on pastpapers.html / lecture.html
     2 rejections -> never shown on the website
   One vote only counts from each admin; until then it stays pending.
   Data comes from UploadStore (js/uploads-store.js).
   ========================================================================== */
(async function () {
  "use strict";

  const me = await TT.init({ allowed: ["admin"] });
  if (!me) return;

  const S = window.UploadStore;
  const $ = (id) => document.getElementById(id);
  const el = TT.el;

  // Who is voting. id keeps two admins with the same name apart.
  const voter = { id: me.id != null ? me.id : me.name, name: me.name };

  const state = {
    tab: "pending",
    query: "",
    selected: new Set(),
    openId: null,        // upload shown in the preview window
    urls: []             // blob URLs to free when the window closes
  };

  /* ---------- small helpers ---------- */
  function shortDate(iso) {
    const d = new Date(iso);
    return isNaN(d) ? "" : d.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
  }

  function size(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + " MB";
    return Math.max(1, Math.round(bytes / 1024)) + " KB";
  }

  function pill(type) {
    const cls = type === "Midterm" ? "mid" : type === "Final" ? "fin" : "lec";
    return el("span", { className: "ru-pill " + cls, text: type });
  }

  function say(node, text, kind) {
    node.textContent = text || "";
    node.className = "form-msg" + (kind ? " " + kind : "");
  }

  function myVote(u) {
    return (u.votes || []).find((v) => v.id === voter.id) || null;
  }

  /* "Admin Office: approve · Admin Two: reject" for an upload still pending. */
  function voteText(u) {
    const votes = u.votes || [];
    if (u.status !== "pending" || !votes.length) return "";
    return votes.map((v) => (v.id === voter.id ? "You" : v.name) + ": " +
      (v.decision === "approved" ? "approve" : "reject")).join(" · ") +
      " — needs " + S.VOTES_NEEDED + " matching votes";
  }

  function visibleItems() {
    const q = state.query.trim().toLowerCase();
    return S.list({ status: state.tab }).filter((u) => {
      if (!q) return true;
      return [u.title, u.subject, u.dept, u.uploadedBy].join(" ").toLowerCase().includes(q);
    });
  }

  /* ---------- list ---------- */
  function renderRow(u) {
    const box = el("input", { attrs: { type: "checkbox", "aria-label": "Select " + u.title } });
    box.checked = state.selected.has(u.id);
    box.addEventListener("change", () => {
      if (box.checked) state.selected.add(u.id); else state.selected.delete(u.id);
      updateBulk();
    });

    const review = el("button", { className: "ru-review", text: "Review", attrs: { type: "button" } });
    review.addEventListener("click", () => openReview(u.id));

    const tr = el("tr", {}, [
      el("td", { className: "ru-check" }, [box]),
      el("td", {}, [
        el("div", { className: "ru-title", text: u.title }),
        el("div", { className: "ru-sub", text: u.dept + " · " + u.subject + " · " + u.year }),
        voteText(u) ? el("div", { className: "ru-votes", text: voteText(u) }) : null,
        u.status !== "pending" && u.reviewedBy
          ? el("div", { className: "ru-votes", text: "Decided by " + u.reviewedBy }) : null
      ]),
      el("td", {}, [pill(u.type)]),
      el("td", { text: u.uploadedBy }),
      el("td", { className: "ru-date", text: shortDate(u.uploadedAt) }),
      el("td", { className: "ru-act" }, [review])
    ]);

    tr.addEventListener("click", (e) => {
      if (e.target.closest("input, button")) return;
      openReview(u.id);
    });
    return tr;
  }

  function render() {
    const items = visibleItems();

    // Keep only selections that are still on screen.
    const ids = new Set(items.map((u) => u.id));
    state.selected.forEach((id) => { if (!ids.has(id)) state.selected.delete(id); });

    const body = $("ruRows");
    TT.clear(body);
    items.forEach((u) => body.appendChild(renderRow(u)));

    const empty = $("ruEmpty");
    empty.hidden = items.length > 0;
    if (!items.length) {
      empty.textContent = state.query.trim()
        ? "No uploads match your search."
        : state.tab === "pending" ? "Nothing waiting for review. 🎉"
        : "No " + state.tab + " uploads yet.";
    }

    $("tabPending").textContent = "Pending (" + S.list({ status: "pending" }).length + ")";
    updateBulk();
  }

  function updateBulk() {
    const items = visibleItems();
    const n = state.selected.size;
    const total = items.length;

    $("ruSelected").textContent = n + " selected";
    $("bulkApprove").disabled = n === 0;
    $("bulkReject").disabled = n === 0;
    // Approved tab can only be taken down; Rejected tab can only be restored.
    $("bulkApprove").hidden = state.tab === "approved";
    $("bulkReject").hidden = state.tab === "rejected";

    const all = $("ruAll");
    all.checked = total > 0 && n === total;
    all.indeterminate = n > 0 && n < total;

    document.querySelectorAll("#ruRows input[type=checkbox]").forEach((box, i) => {
      box.checked = !!items[i] && state.selected.has(items[i].id);
    });
  }

  /* ---------- decisions ---------- */
  function describe(res, status) {
    const verb = status === "approved" ? "approve" : "reject";
    const word = status === "approved" ? "approved" : "rejected";
    const parts = [];
    if (res.finalized) parts.push(res.finalized + " final: " + word + " (2 admins agreed).");
    if (res.voted) parts.push("Your " + verb + " vote saved for " + res.voted +
      ". Waiting for a second admin.");
    if (res.skipped) parts.push(res.skipped + " skipped (you already voted this way, or already decided).");
    return parts.join(" ");
  }

  function decideAndReport(ids, status) {
    const res = S.decide(ids, status, voter, state.tab);
    say($("ruMsg"), describe(res, status), res.finalized + res.voted ? "ok" : "error");
    return res;
  }

  function bulk(status) {
    const ids = Array.from(state.selected);
    if (!ids.length) return;
    const verb = status === "approved" ? "Approve" : "Reject";
    if (!window.confirm(verb + " " + ids.length + (ids.length === 1 ? " upload?" : " uploads?"))) return;
    decideAndReport(ids, status);
    state.selected.clear();
    render();
  }

  /* ---------- preview window ---------- */
  const dlg = $("ruModal");

  function freeUrls() {
    state.urls.forEach((u) => URL.revokeObjectURL(u));
    state.urls = [];
    TT.clear($("mPreview"));
    TT.clear($("mFiles"));
  }

  function showFile(file, url) {
    const box = $("mPreview");
    TT.clear(box);

    if (file.mime === "application/pdf") {
      box.appendChild(el("iframe", { attrs: { src: url, title: file.name } }));
    } else if (file.mime.indexOf("image/") === 0) {
      box.appendChild(el("img", { attrs: { src: url, alt: file.name } }));
    } else {
      box.appendChild(el("div", { className: "ru-nopreview" }, [
        el("p", { text: "This file type cannot be previewed in the browser." }),
        el("a", { className: "btn solid", text: "Download to check it", attrs: { href: url, download: file.name } })
      ]));
    }
  }

  async function openReview(id) {
    const u = S.get(id);
    if (!u) return;

    state.openId = id;
    $("mTitle").textContent = u.title;
    $("mMeta").textContent = [u.dept, u.subject, u.type, u.year].join(" · ") +
      "  —  uploaded by " + u.uploadedBy + " on " + shortDate(u.uploadedAt);
    say($("mMsg"), "");
    const mine = myVote(u);
    $("mVotes").textContent = u.status === "pending"
      ? (voteText(u) || "No votes yet — needs " + S.VOTES_NEEDED + " admins to agree.")
      : "Decided by " + (u.reviewedBy || "admins") +
        ". Changing it needs " + S.VOTES_NEEDED + " admins again.";
    // Hide the button for what is already decided / what you already voted.
    $("mApprove").hidden = u.status === "approved" || (!!mine && mine.decision === "approved");
    $("mReject").hidden = u.status === "rejected" || (!!mine && mine.decision === "rejected");
    $("mApprove").textContent = u.status === "rejected" ? "Restore & approve" : "Approve";
    $("mReject").textContent = u.status === "approved" ? "Take down" : "Reject";

    freeUrls();
    $("mPreview").appendChild(el("p", { className: "ru-loading", text: "Loading preview…" }));
    if (!dlg.open) dlg.showModal();

    let files;
    try {
      files = await S.getFiles(u);
    } catch (e) {
      if (state.openId === id) {
        TT.clear($("mPreview"));
        say($("mMsg"), "Could not load the file: " + e.message, "error");
      }
      return;
    }
    if (state.openId !== id) return;          // window was closed or changed meanwhile

    if (!files.length) {
      TT.clear($("mPreview"));
      say($("mMsg"), "The file for this upload is missing from this browser's storage.", "error");
      return;
    }

    const urls = files.map((f) => URL.createObjectURL(f.blob));
    state.urls = urls;

    const tabs = $("mFiles");
    files.forEach((f, i) => {
      const b = el("button", {
        className: "ru-file" + (i === 0 ? " active" : ""),
        text: f.name + " (" + size(f.size) + ")",
        attrs: { type: "button" }
      });
      b.addEventListener("click", () => {
        tabs.querySelectorAll(".ru-file").forEach((x) => x.classList.remove("active"));
        b.classList.add("active");
        showFile(f, urls[i]);
      });
      tabs.appendChild(b);
    });
    showFile(files[0], urls[0]);
  }

  function closeReview() {
    state.openId = null;
    if (dlg.open) dlg.close();
    freeUrls();
  }

  function decideFromWindow(status) {
    const id = state.openId;
    const u = S.get(id);
    if (!u) return;

    // Remember where this row was so we can jump to the next one.
    const before = visibleItems().map((x) => x.id);
    const pos = before.indexOf(id);

    const res = S.decide([id], status, voter, u.status);
    if (!res.finalized && !res.voted) {
      say($("mMsg"), "Nothing changed: you already voted this way, or it was already decided.", "error");
      render();
      return;
    }

    say($("ruMsg"), u.title + " — " + describe(res, status), "ok");
    state.selected.delete(id);
    render();

    // Jump to the next upload you have not voted on yet.
    const after = visibleItems();
    const stillListed = after.some((x) => x.id === id);
    const next = after.slice(stillListed ? pos + 1 : pos).find((x) => !myVote(x));
    if (next) openReview(next.id); else closeReview();
  }

  /* ---------- events ---------- */
  document.querySelectorAll(".ru-tab").forEach((tab) => {
    tab.addEventListener("click", () => {
      state.tab = tab.dataset.tab;
      state.selected.clear();
      document.querySelectorAll(".ru-tab").forEach((t) => {
        const on = t === tab;
        t.classList.toggle("active", on);
        t.setAttribute("aria-selected", on ? "true" : "false");
      });
      say($("ruMsg"), "");
      render();
    });
  });

  $("ruSearch").addEventListener("input", (e) => {
    state.query = e.target.value;
    render();
  });

  $("ruAll").addEventListener("change", (e) => {
    state.selected.clear();
    if (e.target.checked) visibleItems().forEach((u) => state.selected.add(u.id));
    updateBulk();
  });

  $("bulkApprove").addEventListener("click", () => bulk("approved"));
  $("bulkReject").addEventListener("click", () => bulk("rejected"));

  $("mApprove").addEventListener("click", () => decideFromWindow("approved"));
  $("mReject").addEventListener("click", () => decideFromWindow("rejected"));
  $("mClose").addEventListener("click", closeReview);
  $("mCancel").addEventListener("click", closeReview);
  dlg.addEventListener("close", () => { state.openId = null; freeUrls(); });
  dlg.addEventListener("click", (e) => { if (e.target === dlg) closeReview(); });   // click on the dark backdrop

  // Another admin (another tab) decided or a student uploaded: refresh the list.
  window.addEventListener("storage", (e) => {
    if (e.key === S.META_KEY) render();
  });

  render();
})();
