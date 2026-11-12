/* ==========================================================================
   uploads-store.js  -  shared review queue for student uploads.

   WHY: pastpapers.html, lecture.html and the admin page review-uploads.html
   are different pages, so they need one shared place for uploads.
   - Details (title, status, who, when) -> localStorage["numlUploads"]
   - The files themselves              -> IndexedDB (localStorage is too small)
   Same browser + same origin (localhost:8000) = all pages see the same data.

   When the real backend exists, replace the inside of these functions with
   NUML.api() calls. The pages only call UploadStore.*, so they won't change.

   Status flow (2 admins must agree):
     pending --(2 admins approve)--> approved  (shown on the website)
     pending --(2 admins reject)---> rejected  (never shown)
   Each admin has ONE vote per upload (they may change it while pending).
   ========================================================================== */
(function () {
  "use strict";

  const META_KEY = "numlUploads";
  const DB_NAME = "numlArchiveFiles";
  const STORE = "files";
  const VOTES_NEEDED = 2;      // matching votes from different admins to finalize

  /* ---- details (localStorage) ---- */
  function readMeta() {
    try {
      const raw = localStorage.getItem(META_KEY);
      const list = raw ? JSON.parse(raw) : [];
      return Array.isArray(list) ? list : [];
    } catch (e) {
      return [];
    }
  }

  function writeMeta(list) {
    localStorage.setItem(META_KEY, JSON.stringify(list));
  }

  /* ---- files (IndexedDB) ---- */
  function openDb() {
    return new Promise((resolve, reject) => {
      if (!window.indexedDB) {
        reject(new Error("This browser cannot store uploads."));
        return;
      }
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => req.result.createObjectStore(STORE);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error || new Error("Could not open file storage."));
    });
  }

  function putFiles(id, files) {
    return openDb().then((db) => new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, "readwrite");
      files.forEach((file, i) => tx.objectStore(STORE).put(file, id + ":" + i));
      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onerror = tx.onabort = () => {
        db.close();
        reject(tx.error || new Error("Could not save the file. Is the disk or browser storage full?"));
      };
    }));
  }

  function deleteFiles(id, count) {
    return openDb().then((db) => new Promise((resolve) => {
      const tx = db.transaction(STORE, "readwrite");
      for (let i = 0; i < count; i++) tx.objectStore(STORE).delete(id + ":" + i);
      tx.oncomplete = tx.onerror = tx.onabort = () => { db.close(); resolve(); };
    })).catch(() => {});
  }

  function getFile(id, i) {
    return openDb().then((db) => new Promise((resolve, reject) => {
      const req = db.transaction(STORE, "readonly").objectStore(STORE).get(id + ":" + i);
      req.onsuccess = () => { db.close(); resolve(req.result || null); };
      req.onerror = () => { db.close(); reject(req.error); };
    }));
  }

  /* Do not trust File.type: pick the type from the extension so a PDF is
     always shown as a PDF and nothing else can be rendered as a web page. */
  function mimeFor(name) {
    const ext = String(name).split(".").pop().toLowerCase();
    return ({
      pdf: "application/pdf",
      jpg: "image/jpeg", jpeg: "image/jpeg", png: "image/png", webp: "image/webp",
      ppt: "application/vnd.ms-powerpoint",
      pptx: "application/vnd.openxmlformats-officedocument.presentationml.presentation"
    })[ext] || "application/octet-stream";
  }

  /* ---- public API ---- */

  /* list({kind, status}) -> newest first */
  function list(filter) {
    const f = filter || {};
    return readMeta()
      .filter((u) => (!f.kind || u.kind === f.kind) && (!f.status || u.status === f.status))
      .sort((a, b) => String(b.uploadedAt).localeCompare(String(a.uploadedAt)));
  }

  function get(id) {
    return readMeta().find((u) => u.id === id) || null;
  }

  /* add({kind, dept, subject, year, type, title, uploadedBy}, [File, ...])
     Files are saved first, then the details, so a pending item never has
     missing files. Resolves with the saved item. */
  function add(info, files) {
    const id = "u" + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    const item = {
      id,
      kind: info.kind,                 // "paper" | "lecture"
      dept: info.dept,
      subject: info.subject,
      year: info.year,
      type: info.type,                 // "Midterm" | "Final" | "Lecture"
      title: info.title,
      uploadedBy: info.uploadedBy || "Student",
      uploadedAt: new Date().toISOString(),
      status: "pending",
      votes: [],                       // [{id, name, decision, at}]
      reviewedBy: null,
      reviewedAt: null,
      files: files.map((f) => ({ name: f.name, size: f.size }))
    };

    return putFiles(id, files).then(() => {
      try {
        const all = readMeta();
        all.push(item);
        writeMeta(all);
      } catch (e) {
        deleteFiles(id, files.length);
        throw new Error("Could not save the upload. Browser storage is full.");
      }
      return item;
    });
  }

  /* decide(ids, "approved"|"rejected", {id, name}, fromStatus)
     Records this admin's vote. An upload is FINAL only when VOTES_NEEDED
     different admins vote the same way; until then it stays "pending".
     - Same admin voting the same way twice does nothing (skipped).
     - Same admin voting the opposite way replaces their earlier vote.
     - Items no longer in fromStatus (another admin finished them) are skipped.
     - Reversing a finished upload (fromStatus approved/rejected) reopens it:
       old votes are cleared, your vote counts as the first of the new round.
     Returns {finalized, voted, skipped}. */
  function decide(ids, decision, voter, fromStatus) {
    const wanted = new Set(ids);
    const all = readMeta();
    let finalized = 0;
    let voted = 0;
    let skipped = 0;
    let touched = false;

    all.forEach((u) => {
      if (!wanted.has(u.id)) return;
      if (u.status !== fromStatus) { skipped++; return; }

      const now = new Date().toISOString();

      if (u.status !== "pending") {          // reopen a finished decision
        u.status = "pending";
        u.votes = [];
        u.reviewedBy = null;
        u.reviewedAt = null;
      }
      u.votes = u.votes || [];

      const mine = u.votes.find((v) => v.id === voter.id);
      if (mine && mine.decision === decision) { skipped++; return; }

      if (mine) { mine.decision = decision; mine.at = now; }
      else u.votes.push({ id: voter.id, name: voter.name, decision, at: now });
      touched = true;

      const same = u.votes.filter((v) => v.decision === decision);
      if (same.length >= VOTES_NEEDED) {
        u.status = decision;
        u.reviewedBy = same.map((v) => v.name).join(", ");
        u.reviewedAt = now;
        finalized++;
      } else {
        voted++;
      }
    });

    if (touched) writeMeta(all);
    return { finalized, voted, skipped };
  }

  /* Files of one upload -> [{name, size, mime, blob}] */
  async function getFiles(item) {
    const out = [];
    for (let i = 0; i < item.files.length; i++) {
      const raw = await getFile(item.id, i);
      if (!raw) continue;
      const mime = mimeFor(item.files[i].name);
      out.push({
        name: item.files[i].name,
        size: item.files[i].size,
        mime,
        blob: new Blob([raw], { type: mime })
      });
    }
    return out;
  }

  window.UploadStore = { META_KEY, VOTES_NEEDED, list, get, add, decide, getFiles };
})();
