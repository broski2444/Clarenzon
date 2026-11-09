/* Shared auth-context helpers for multi-role concurrent sessions */
(function (global) {
  function clarAuthContext() {
    return String(global.CLAR_AUTH_CONTEXT || global.currentRoleKey || 'admin').toLowerCase().trim() || 'admin';
  }

  function clarAuthHeaders(extra) {
    const headers = Object.assign({}, extra || {});
    headers['X-Clarenzon-Auth-Context'] = clarAuthContext();
    return headers;
  }

  function clarAuthUrl(url) {
    const raw = String(url || '');
    if (!raw || raw.indexOf('auth_ctx=') !== -1 || raw.indexOf('://') === 0) return raw;
    const sep = raw.indexOf('?') === -1 ? '?' : '&';
    return raw + sep + 'auth_ctx=' + encodeURIComponent(clarAuthContext());
  }

  /** Wrap fetch so root APIs open the correct role session cookie bag. */
  const originalFetch = global.fetch.bind(global);
  global.fetch = function (input, init) {
    init = init || {};
    const headers = new Headers(init.headers || (typeof input !== 'string' && input && input.headers) || {});
    if (!headers.has('X-Clarenzon-Auth-Context')) {
      headers.set('X-Clarenzon-Auth-Context', clarAuthContext());
    }
    const csrfMeta = global.document && global.document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? String(csrfMeta.getAttribute('content') || '') : '';
    if (csrf && !headers.has('X-CSRF-TOKEN')) {
      headers.set('X-CSRF-TOKEN', csrf);
    }
    init.headers = headers;

    if (typeof input === 'string') {
      input = clarAuthUrl(input);
    }
    return originalFetch(input, init);
  };

  global.clarAuthContext = clarAuthContext;
  global.clarAuthHeaders = clarAuthHeaders;
  global.clarAuthUrl = clarAuthUrl;
})(window);
