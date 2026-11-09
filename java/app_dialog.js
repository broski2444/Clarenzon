(function () {
  var root = null;
  var titleEl = null;
  var messageEl = null;
  var inputEl = null;
  var okBtn = null;
  var cancelBtn = null;
  var resolver = null;

  function finish(result) {
    if (!root) return;
    root.hidden = true;
    document.body.style.overflow = '';
    var done = resolver;
    resolver = null;
    if (done) done(result);
  }

  function ensure() {
    if (root) return;
    root = document.createElement('div');
    root.id = 'clarenzonAppDialog';
    root.hidden = true;
    root.innerHTML =
      '<div class="clar-dialog-backdrop" data-clar-cancel="1"></div>' +
      '<div class="clar-dialog-card" role="dialog" aria-modal="true" aria-labelledby="clarDialogTitle">' +
        '<h2 id="clarDialogTitle" class="clar-dialog-title">Clarenzon Medical</h2>' +
        '<p class="clar-dialog-message"></p>' +
        '<input type="text" class="clar-dialog-input" autocomplete="off">' +
        '<div class="clar-dialog-actions">' +
          '<button type="button" class="clar-dialog-cancel">Cancel</button>' +
          '<button type="button" class="clar-dialog-ok">OK</button>' +
        '</div>' +
      '</div>';
    var style = document.createElement('style');
    style.textContent =
      '#clarenzonAppDialog{position:fixed;inset:0;z-index:30000;display:flex;align-items:center;justify-content:center;padding:16px}' +
      '#clarenzonAppDialog[hidden]{display:none}' +
      '#clarenzonAppDialog .clar-dialog-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.45)}' +
      '#clarenzonAppDialog .clar-dialog-card{position:relative;width:min(100%,420px);background:#fff;color:#0f172a;border-radius:16px;box-shadow:0 20px 50px rgba(15,23,42,.28);padding:22px 22px 18px;font-family:Inter,Segoe UI,sans-serif}' +
      '#clarenzonAppDialog .clar-dialog-title{margin:0 0 8px;font-size:18px;font-weight:700}' +
      '#clarenzonAppDialog .clar-dialog-message{margin:0 0 16px;font-size:14px;line-height:1.5;color:#334155;white-space:pre-wrap}' +
      '#clarenzonAppDialog .clar-dialog-input{display:none;width:100%;box-sizing:border-box;margin:0 0 16px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}' +
      '#clarenzonAppDialog .clar-dialog-input.is-on{display:block}' +
      '#clarenzonAppDialog .clar-dialog-actions{display:flex;justify-content:flex-end;gap:8px}' +
      '#clarenzonAppDialog .clar-dialog-actions button{border:0;border-radius:10px;padding:8px 16px;font:inherit;font-weight:600;cursor:pointer}' +
      '#clarenzonAppDialog .clar-dialog-cancel{background:#e2e8f0;color:#0f172a}' +
      '#clarenzonAppDialog .clar-dialog-ok{background:#16a34a;color:#fff}';
    document.head.appendChild(style);
    document.body.appendChild(root);
    titleEl = root.querySelector('.clar-dialog-title');
    messageEl = root.querySelector('.clar-dialog-message');
    inputEl = root.querySelector('.clar-dialog-input');
    okBtn = root.querySelector('.clar-dialog-ok');
    cancelBtn = root.querySelector('.clar-dialog-cancel');
    okBtn.addEventListener('click', function () {
      finish({ ok: true, value: inputEl.classList.contains('is-on') ? inputEl.value : '' });
    });
    cancelBtn.addEventListener('click', function () { finish({ ok: false, value: null }); });
    root.addEventListener('click', function (event) {
      if (event.target && event.target.getAttribute('data-clar-cancel') === '1') finish({ ok: false, value: null });
    });
    document.addEventListener('keydown', function (event) {
      if (!root || root.hidden) return;
      if (event.key === 'Escape') finish({ ok: false, value: null });
      if (event.key === 'Enter' && document.activeElement === inputEl) {
        event.preventDefault();
        finish({ ok: true, value: inputEl.value });
      }
    });
  }

  function openDialog(options) {
    ensure();
    if (resolver) finish({ ok: false, value: null });
    titleEl.textContent = options.title || 'Clarenzon Medical';
    messageEl.textContent = options.message || '';
    var showInput = !!options.input;
    inputEl.classList.toggle('is-on', showInput);
    inputEl.value = showInput ? (options.value || '') : '';
    cancelBtn.hidden = options.mode === 'alert';
    okBtn.textContent = options.okText || 'OK';
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    if (showInput) inputEl.focus();
    else okBtn.focus();
    return new Promise(function (resolve) { resolver = resolve; });
  }

  window.appAlert = function (message) {
    return openDialog({ mode: 'alert', title: 'Clarenzon Medical', message: String(message == null ? '' : message) });
  };
  window.appConfirm = function (message) {
    return openDialog({ mode: 'confirm', title: 'Clarenzon Medical', message: String(message == null ? '' : message) }).then(function (result) {
      return !!result.ok;
    });
  };
  window.appPrompt = function (message, value) {
    return openDialog({
      mode: 'prompt',
      title: 'Clarenzon Medical',
      message: String(message == null ? '' : message),
      input: true,
      value: value == null ? '' : String(value)
    }).then(function (result) {
      return result.ok ? result.value : null;
    });
  };
  window.appConfirmSubmit = function (event, message) {
    if (event && event.preventDefault) event.preventDefault();
    var node = event && (event.currentTarget || event.target);
    var form = node && node.tagName === 'FORM' ? node : (node && node.closest ? node.closest('form') : null);
    var submitter = (event && event.submitter) || (node && node.closest ? node.closest('button,input[type="submit"]') : null);
    window.appConfirm(message).then(function (ok) {
      if (!ok || !form) return;
      if (submitter && submitter.name) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = submitter.name;
        hidden.value = submitter.value || '1';
        form.appendChild(hidden);
      }
      form.submit();
    });
    return false;
  };
  window.alert = function (message) { window.appAlert(message); };
})();
