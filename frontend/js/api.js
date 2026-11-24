// frontend/js/api.js
// Owner: Taha. Others ask Taha for changes.
(function () {
  const API_BASE = "https://numl-archive.onrender.com";  // "" = use mock files
  const MOCK_DIR = "../mock/";
  const TOKEN_KEY = "numlToken";
  const LOGIN_PAGE = "login.html";
  const LOGIN_PATH = "/api/auth/login";

  window.NUML = window.NUML || {};
  const NUML = window.NUML;

  NUML.getToken = () => localStorage.getItem(TOKEN_KEY);
  NUML.setToken = (t) => localStorage.setItem(TOKEN_KEY, t);
  NUML.clearToken = () => localStorage.removeItem(TOKEN_KEY);

  function mockUrl(path) {
    const clean = path.split("?")[0].replace(/^\/api\//, "").replace(/\//g, "-");
    return MOCK_DIR + clean + ".json";
  }

  NUML.api = async function (path, options = {}) {
    if (!API_BASE) {
      const res = await fetch(mockUrl(path));
      if (!res.ok) throw new Error("Mock file missing: " + mockUrl(path));
      return res.json();
    }

    const headers = Object.assign({}, options.headers);
    const token = NUML.getToken();
    if (token) headers["Authorization"] = "Bearer " + token;

    let body = options.body;
    if (body && !(body instanceof FormData) && typeof body === "object") {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(body);
    }

    const res = await fetch(API_BASE + path, Object.assign({}, options, { headers, body }));

    if (res.status === 401 && path !== LOGIN_PATH) {
      NUML.clearToken();
      if (!location.pathname.endsWith(LOGIN_PAGE)) location.href = LOGIN_PAGE;
      throw new Error("Unauthorized");
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.detail || "Request failed");
      err.status = res.status;
      throw err;
    }
    return data;
  };

  // Upload: NUML.api already sends FormData without a Content-Type, so reuse it.
  NUML.upload = function (path, formData) {
    return NUML.api(path, { method: "POST", body: formData });
  };

  // Download: a plain <a href> has no token, so fetch it, make a blob, click a temp link.
  NUML.download = async function (path, filename) {
    if (!API_BASE) throw new Error("Download needs the live backend (API_BASE is empty)");

    const headers = {};
    const token = NUML.getToken();
    if (token) headers["Authorization"] = "Bearer " + token;

    const res = await fetch(API_BASE + path, { headers });

    if (res.status === 401) {
      NUML.clearToken();
      if (!location.pathname.endsWith(LOGIN_PAGE)) location.href = LOGIN_PAGE;
      throw new Error("Unauthorized");
    }
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      const err = new Error(data.detail || "Download failed");
      err.status = res.status;
      throw err;
    }

    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename || "download";
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };
})();