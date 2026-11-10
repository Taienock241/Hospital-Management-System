(function () {
  const scriptUrl = document.currentScript && document.currentScript.src;
  const apiBase = new URL('../../backend/api/', scriptUrl || window.location.href);
  let csrfToken = null;

  async function readResponse(response) {
    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('The server returned an invalid response. Please try again later.');
    }

    if (!response.ok || !payload.success) {
      const requestError = new Error(payload.message || 'The request could not be completed.');
      requestError.status = response.status;
      requestError.errors = payload.errors || {};
      throw requestError;
    }
    return payload;
  }

  async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const response = await fetch(new URL('auth/csrf.php', apiBase), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const payload = await readResponse(response);
    csrfToken = payload.data.csrfToken;
    return csrfToken;
  }

  async function request(path, options) {
    const settings = options || {};
    const method = (settings.method || 'GET').toUpperCase();
    const headers = { Accept: 'application/json', ...(settings.headers || {}) };
    const init = { method, credentials: 'same-origin', headers };

    if (settings.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(settings.body);
    }
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      headers['X-CSRF-Token'] = await getCsrfToken();
    }

    const response = await fetch(new URL(path, apiBase), init);
    const payload = await readResponse(response);
    if (payload.data && payload.data.csrfToken) {
      csrfToken = payload.data.csrfToken;
    }
    return payload.data;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, character => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[character]);
  }

  function formatDate(value, options) {
    const parts = String(value).split('-').map(Number);
    if (parts.length !== 3 || parts.some(Number.isNaN)) return String(value);
    return new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString('en-US', options || {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  function localDateString(date) {
    const value = date || new Date();
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');
    return `${value.getFullYear()}-${month}-${day}`;
  }

  window.HopewellAPI = { request, escapeHtml, formatDate, localDateString };
})();
