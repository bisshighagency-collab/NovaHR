/**
 * Shared API client for the NovaHR frontend.
 *
 * By default this assumes the PHP backend is served at /api on the SAME
 * origin as these static files (e.g. copy `frontend/` contents into
 * `backend/` document root, or use an Apache alias — see app/README.md).
 * Change API_BASE below if you host the backend elsewhere.
 */
const API_BASE = (window.NOVAHR_API_BASE || '/api');

const Auth = {
  getToken() { return localStorage.getItem('novahr_token'); },
  getUser() { try { return JSON.parse(localStorage.getItem('novahr_user') || 'null'); } catch { return null; } },
  getRole() { return localStorage.getItem('novahr_role'); },
  setSession(token, user, role) {
    localStorage.setItem('novahr_token', token);
    localStorage.setItem('novahr_user', JSON.stringify(user));
    localStorage.setItem('novahr_role', role);
  },
  clear() {
    localStorage.removeItem('novahr_token');
    localStorage.removeItem('novahr_user');
    localStorage.removeItem('novahr_role');
  },
  requireRole(role, redirectTo) {
    if (Auth.getRole() !== role || !Auth.getToken()) {
      window.location.href = redirectTo;
    }
  },
};

async function api(method, path, body) {
  const headers = { 'Content-Type': 'application/json' };
  const token = Auth.getToken();
  if (token) headers['Authorization'] = 'Bearer ' + token;

  let res;
  try {
    res = await fetch(API_BASE + path, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new Error('Could not reach the NovaHR API. Is the PHP backend running?');
  }

  let data = null;
  try { data = await res.json(); } catch { /* empty body */ }

  if (res.status === 401) {
    Auth.clear();
  }
  if (!res.ok) {
    const message = (data && data.error) ? data.error : `Request failed (${res.status})`;
    throw new Error(message);
  }
  return data;
}

/** For endpoints that take a file upload (multipart/form-data) alongside other fields. */
async function apiForm(method, path, formData) {
  const headers = {};
  const token = Auth.getToken();
  if (token) headers['Authorization'] = 'Bearer ' + token;

  let res;
  try {
    res = await fetch(API_BASE + path, { method, headers, body: formData });
  } catch (e) {
    throw new Error('Could not reach the NovaHR API. Is the PHP backend running?');
  }

  let data = null;
  try { data = await res.json(); } catch { /* empty body */ }

  if (res.status === 401) Auth.clear();
  if (!res.ok) {
    const message = (data && data.error) ? data.error : `Request failed (${res.status})`;
    throw new Error(message);
  }
  return data;
}

const Api = {
  get: (path) => api('GET', path),
  post: (path, body) => api('POST', path, body),
  put: (path, body) => api('PUT', path, body),
  patch: (path, body) => api('PATCH', path, body),
  del: (path) => api('DELETE', path),
  postForm: (path, formData) => apiForm('POST', path, formData),
};

/**
 * URL for a protected file, viewable as a plain link (target="_blank").
 * A real <a href> avoids the popup-blocker problems a JS-triggered
 * window.open() runs into (especially after an intervening `await`, which
 * loses the click's "user gesture"); the JWT rides as a query param since a
 * plain link/new-tab navigation can't carry a custom Authorization header —
 * the backend accepts either (see bearer_token() in middleware/auth.php).
 */
function attachmentUrl(path) {
  const token = Auth.getToken();
  return API_BASE + path + (token ? `?token=${encodeURIComponent(token)}` : '');
}

/** Escapes text before it is interpolated into an innerHTML template string —
 *  every field below comes from user input (names, job titles, AI knowledge
 *  base content, etc.) and must never be inserted unescaped. */
function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function showAlert(container, message, type = 'error') {
  container.innerHTML = `<div class="alert ${type}">${esc(message)}</div>`;
}

function fmtMoney(n) {
  return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' FCFA';
}

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d.replace(' ', 'T')).toLocaleString();
}

function badge(text) {
  const cls = String(text || '').toLowerCase().replace(/[^a-z0-9_]/g, '_');
  return `<span class="badge ${cls}">${esc(text)}</span>`;
}
