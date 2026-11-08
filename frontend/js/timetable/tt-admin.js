/* ==========================================================================
   tt-admin.js  -  admin.html (admin only).

   Deliberately no custom admin screens: FastAPI's /docs page already offers
   a form for every admin endpoint, and building our own would duplicate it
   before the deadline. In mock mode there is no backend, so /docs does not
   exist; the page says so instead of showing a link that would 404.
   ========================================================================== */
(async function () {
  "use strict";

  const me = await TT.init({ allowed: ["admin"] });
  if (!me) return;

  const slot = document.getElementById("adminDocs");
  const base = window.TT_CONFIG.API_BASE;

  if (base) {
    const url = base.replace(/\/+$/, "") + "/docs";
    slot.appendChild(TT.el("a", { className: "btn solid", text: "Open " + url, attrs: { href: url, target: "_blank", rel: "noopener" } }));
  } else {
    slot.className = "warn";
    slot.textContent = "Demo mode: no backend is connected, so /docs is not available. " +
      "Set API_BASE in js/timetable/tt-config.js to the backend address to get the link.";
  }
})();
