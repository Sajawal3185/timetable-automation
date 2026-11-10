/* ==========================================================================
   tt-api.js  -  TEMPORARY request helper for the Timetable Automation pages.

   TEMPORARY: the release plan (F-3) gives the shared helper to the owner of
   frontend/js/api.js as NUML.api(). That file does not exist on main yet, so
   this one stands in. When NUML.api lands, replace TTApi.request() calls with
   it and delete this file. Keep the same rules when you do:

     1. The JWT is the only thing stored (localStorage[TT_CONFIG.TOKEN_KEY]).
     2. It is sent as "Authorization: Bearer <token>".
     3. A 401 from ANY call except login clears the token and goes to the
        timetable login. Login is excluded because a wrong password is also a
        401 and must show a message, not reload the page.
     4. Every non-2xx status becomes an ApiError with a readable message.

   Load order: tt-config.js, tt-mock.js, tt-api.js, then the page scripts.
   ========================================================================== */
(function () {
  "use strict";

  const C = window.TT_CONFIG;

  /* An Error that also carries the HTTP status and the raw FastAPI "detail",
     so a page can special-case 409 (show the conflicts list) without parsing
     the message string. status 0 = the request never got an answer. */
  class ApiError extends Error {
    constructor(status, detail, message) {
      super(message);
      this.name = "ApiError";
      this.status = status;
      this.detail = detail;
    }
  }

  /* ---- Token storage -------------------------------------------------- */
  /* try/catch because storage can throw (private mode, blocked site data).
     Failing closed (no token) just means "log in again". */
  function getToken() {
    try { return localStorage.getItem(C.TOKEN_KEY) || ""; } catch (e) { return ""; }
  }
  function setToken(token) {
    try { localStorage.setItem(C.TOKEN_KEY, token); } catch (e) { /* login will fail visibly on next page */ }
  }
  function clearToken() {
    try { localStorage.removeItem(C.TOKEN_KEY); } catch (e) { /* nothing stored then */ }
  }

  /* replace() instead of href= so the Back button does not return to a page
     that will just bounce again. */
  function goToLogin() {
    clearToken();
    window.location.replace(C.PAGES.login);
  }

  /* ---- Turning FastAPI errors into sentences ----------------------------
     FastAPI sends {"detail": ...} where detail is
       - a string                     (HTTPException("..."))
       - a list of {loc, msg}         (422 validation errors)
       - an object {message, ...}     (the swap 409 with its conflicts list) */
  function detailText(detail) {
    if (detail == null) return "";
    if (typeof detail === "string") return detail;
    if (Array.isArray(detail)) {
      return detail.map((d) => {
        const where = Array.isArray(d.loc) ? d.loc.filter((p) => p !== "body").join(".") : "";
        return where ? where + ": " + d.msg : String(d.msg || "");
      }).join("; ");
    }
    if (typeof detail === "object" && detail.message) return String(detail.message);
    return "";
  }

  function describe(status, detail) {
    const text = detailText(detail);
    if (status === 0) {
      return "Cannot reach the server. Check your connection or the API_BASE setting.";
    }
    if (status === 400) return text || "The request was not valid.";
    if (status === 401) return text || "Please log in again.";
    if (status === 403) return "Not allowed: " + (text || "your role cannot do this.");
    if (status === 404) return "Not found: " + (text || "the item does not exist.");
    if (status === 409) return text || "This clashes with something that already exists.";
    if (status === 422) return "Please check the form: " + (text || "some values are invalid.");
    if (status >= 500) return "Server error (" + status + "). Please try again, and tell the backend team if it repeats.";
    return text || "Request failed (status " + status + ").";
  }

  function queryString(query) {
    if (!query) return "";
    const params = new URLSearchParams();
    Object.keys(query).forEach((k) => {
      const v = query[k];
      if (v !== undefined && v !== null && v !== "") params.append(k, String(v));
    });
    const s = params.toString();
    return s ? "?" + s : "";
  }

  /* ---- The one function every page uses ---------------------------------
     options.query          object -> ?a=1&b=2 (empty values dropped)
     options.body           sent as JSON
     options.redirectOn401  false only for the login call and the login
                            page's "already logged in?" check */
  async function request(method, path, options) {
    const opts = options || {};
    const token = getToken();
    let status;
    let data = null;

    try {
      if (C.API_BASE) {
        const headers = { Accept: "application/json" };
        if (opts.body !== undefined) headers["Content-Type"] = "application/json";
        if (token) headers.Authorization = "Bearer " + token;

        const res = await fetch(C.API_BASE.replace(/\/+$/, "") + path + queryString(opts.query), {
          method,
          headers,
          body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined
        });
        status = res.status;
        const raw = await res.text();
        if (raw) {
          try { data = JSON.parse(raw); } catch (e) { data = { detail: raw.slice(0, 200) }; }
        }
      } else {
        // Mock mode: same inputs, same {status, data} outputs as the network path,
        // so nothing above or below this branch knows which one ran.
        const res = await window.TTMock.handle({
          method, path, query: opts.query || {}, body: opts.body, token
        });
        status = res.status;
        data = res.data;
      }
    } catch (e) {
      throw new ApiError(0, null, describe(0, null));
    }

    if (status >= 200 && status < 300) return data;

    const detail = data ? data.detail : null;
    if (status === 401 && opts.redirectOn401 !== false) {
      goToLogin();
    }
    throw new ApiError(status, detail, describe(status, detail));
  }

  window.TTApi = {
    ApiError, request, describe, detailText,
    getToken, setToken, clearToken, goToLogin,
    isMock: !C.API_BASE
  };
})();
