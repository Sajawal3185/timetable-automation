/* ==========================================================================
   tt-bell.js  -  notification bell in the timetable nav.

   - Asks GET /api/notifications/unread-count every 15 s (TT_CONFIG.POLL_MS).
     That endpoint returns only {count}, so polling stays cheap.
   - Loads the full list (GET /api/notifications) only when the dropdown opens,
     not on every poll.
   - "Mark as read" -> PATCH /api/notifications/{id}/read
     "Mark all read" -> POST /api/notifications/read-all
   - Skips polls while the tab is hidden: nobody sees the badge then, and a
     dozen open tabs would otherwise hit the server every 15 s each.
   Started by TT.init() in tt-layout.js once the user is known.
   ========================================================================== */
(function () {
  "use strict";

  const C = window.TT_CONFIG;
  let timer = null;
  let badge = null;
  let panel = null;
  let button = null;

  function setCount(count) {
    if (!badge) return;
    badge.hidden = !count;
    badge.textContent = count > 99 ? "99+" : String(count);
    button.setAttribute("aria-label", count ? "Notifications, " + count + " unread" : "Notifications");
  }

  async function refreshCount() {
    if (document.hidden) return;
    try {
      const data = await window.TTApi.request("GET", "/api/notifications/unread-count");
      setCount(data.count);
      button.removeAttribute("title");
    } catch (err) {
      // A 401 is already redirecting. Anything else: keep the old badge and
      // say why on hover, instead of popping errors every 15 seconds.
      if (button) button.title = "Could not check notifications: " + err.message;
    }
  }

  function bellIcon() {
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    ["M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9", "M13.7 21a2 2 0 01-3.4 0"].forEach((d) => {
      const path = document.createElementNS(ns, "path");
      path.setAttribute("d", d);
      svg.appendChild(path);
    });
    return svg;
  }

  async function markRead(id) {
    try {
      await window.TTApi.request("PATCH", "/api/notifications/" + encodeURIComponent(id) + "/read");
    } catch (err) {
      renderError(err);
      return;
    }
    await Promise.all([loadList(), refreshCount()]);
  }

  async function markAllRead() {
    try {
      await window.TTApi.request("POST", "/api/notifications/read-all");
    } catch (err) {
      renderError(err);
      return;
    }
    await Promise.all([loadList(), refreshCount()]);
  }

  function renderError(err) {
    const msg = TT.el("p", { className: "form-msg error" });
    TT.showError(msg, err);
    panel.appendChild(msg);
  }

  function renderList(items) {
    TT.clear(panel);
    const allBtn = TT.el("button", { className: "tt-link-btn", text: "Mark all read", attrs: { type: "button" } });
    allBtn.addEventListener("click", markAllRead);
    allBtn.disabled = !items.some((n) => !n.is_read);
    panel.appendChild(TT.el("div", { className: "tt-bell-head" }, [TT.el("strong", { text: "Notifications" }), allBtn]));

    if (!items.length) {
      panel.appendChild(TT.el("p", { className: "empty", text: "No notifications yet." }));
      return;
    }
    items.forEach((n) => {
      const row = TT.el("div", { className: "tt-note" + (n.is_read ? "" : " unread") }, [
        TT.el("p", { text: n.message }),
        TT.el("small", { text: TT.when(n.created_at) })
      ]);
      if (!n.is_read) {
        const btn = TT.el("button", { className: "tt-link-btn", text: "Mark as read", attrs: { type: "button" } });
        btn.addEventListener("click", () => markRead(n.id));
        row.appendChild(btn);
      }
      panel.appendChild(row);
    });
  }

  async function loadList() {
    try {
      renderList(await window.TTApi.request("GET", "/api/notifications", { query: { limit: 20 } }));
    } catch (err) {
      TT.clear(panel);
      renderError(err);
    }
  }

  function toggle(open) {
    panel.hidden = !open;
    button.setAttribute("aria-expanded", String(open));
    if (open) loadList();
  }

  function start(slot) {
    if (!slot || timer) return;
    button = TT.el("button", { className: "tt-bell", attrs: { type: "button", "aria-haspopup": "true", "aria-expanded": "false", "aria-label": "Notifications" } });
    button.appendChild(bellIcon());
    badge = TT.el("span", { className: "tt-badge", attrs: { "aria-hidden": "true" } });
    badge.hidden = true;
    button.appendChild(badge);
    panel = TT.el("div", { className: "tt-bell-panel", attrs: { role: "region", "aria-label": "Notifications" } });
    panel.hidden = true;
    slot.append(button, panel);

    button.addEventListener("click", () => toggle(panel.hidden));
    document.addEventListener("click", (e) => { if (!panel.hidden && !slot.contains(e.target)) toggle(false); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !panel.hidden) toggle(false); });
    document.addEventListener("visibilitychange", () => { if (!document.hidden) refreshCount(); });

    refreshCount();
    timer = window.setInterval(refreshCount, C.POLL_MS);
  }

  function stop() {
    if (timer) window.clearInterval(timer);
    timer = null;
  }

  window.TTBell = { start, stop, refreshCount };
})();
