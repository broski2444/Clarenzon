/* Per-tab sessions: each browser tab keeps its own sign-in.
 * The tab id lives in sessionStorage (one per tab) and reaches the server as the
 * X-Clarenzon-Tab header on fetch/XHR and as the CLARTAB cookie, which this tab
 * sets whenever it is used or is about to navigate. Injected by session_bootstrap.php. */
(function (w, d) {
  var KEY = 'clarTabId';
  var RELOAD_KEY = 'clarTabReloadAt';
  var COOKIE = 'CLARTAB';
  var HEADER = 'X-Clarenzon-Tab';

  var meta = d.querySelector('meta[name="clar-tab"]');
  var served = meta ? String(meta.getAttribute('content') || '') : '';
  var path = (meta && meta.getAttribute('data-path')) || '/';
  var isLoginPage = !!meta && meta.getAttribute('data-login') === '1';

  function read(key) {
    try { return w.sessionStorage.getItem(key) || ''; } catch (e) { return ''; }
  }
  function write(key, value) {
    try { w.sessionStorage.setItem(key, value); return true; } catch (e) { return false; }
  }
  function newId() {
    var bytes = new Uint8Array(6);
    (w.crypto || w.msCrypto).getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  var tabId = read(KEY);
  var mismatch = false;
  if (!tabId) {
    // A new tab opened from another tab (link, print, typed URL) continues that
    // tab's sign-in; a new tab on the login page starts its own.
    tabId = served && !isLoginPage ? served : newId();
    mismatch = tabId !== served;
    if (!write(KEY, tabId)) return; // no sessionStorage: stay on the shared session
  } else {
    mismatch = tabId !== served;
  }

  function mark() {
    d.cookie = COOKIE + '=' + tabId + '; path=' + path + '; SameSite=Lax' +
      (w.location.protocol === 'https:' ? '; Secure' : '');
  }
  mark();

  if (mismatch) {
    // The page was rendered with another tab's session; reload under this tab's id.
    var last = parseInt(read(RELOAD_KEY), 10) || 0;
    if (Date.now() - last > 10000 && write(RELOAD_KEY, String(Date.now()))) {
      w.location.replace(w.location.href);
      return;
    }
  }

  ['pointerdown', 'keydown', 'focus', 'submit'].forEach(function (type) {
    w.addEventListener(type, mark, true);
  });
  w.addEventListener('beforeunload', mark);
  w.addEventListener('pageshow', mark);
  d.addEventListener('visibilitychange', function () {
    if (d.visibilityState === 'visible') mark();
  });

  function sameOrigin(url) {
    try { return new URL(url, w.location.href).origin === w.location.origin; } catch (e) { return false; }
  }

  if (w.fetch) {
    var originalFetch = w.fetch;
    w.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || String(input);
      if (sameOrigin(url)) {
        init = Object.assign({}, init || {});
        var headers = new Headers(init.headers || (typeof input !== 'string' && input && input.headers) || {});
        headers.set(HEADER, tabId);
        init.headers = headers;
      }
      return originalFetch.call(this, input, init);
    };
  }

  if (w.XMLHttpRequest) {
    var proto = w.XMLHttpRequest.prototype;
    var open = proto.open;
    var send = proto.send;
    proto.open = function (method, url) {
      this.__clarTabSameOrigin = sameOrigin(url);
      return open.apply(this, arguments);
    };
    proto.send = function () {
      if (this.__clarTabSameOrigin) {
        try { this.setRequestHeader(HEADER, tabId); } catch (e) {}
      }
      return send.apply(this, arguments);
    };
  }

  w.clarTabId = tabId;
  /** For requests that cannot carry headers, such as EventSource. */
  w.clarTabUrl = function (url) {
    var raw = String(url || '');
    if (!raw || raw.indexOf('_tab=') !== -1) return raw;
    return raw + (raw.indexOf('?') === -1 ? '?' : '&') + '_tab=' + encodeURIComponent(tabId);
  };
})(window, document);
