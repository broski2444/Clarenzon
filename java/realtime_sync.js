/* Additive live updates. Does not replace existing loaders or polling. */
(function () {
  if (window.__clarRealtimeStarted) return;
  window.__clarRealtimeStarted = true;

  function basePath() {
    const path = window.location.pathname || '/';
    const nested = path.match(/^(.*\/)(?:warehouse_head|technician|admin|purchaser|mobile)\//);
    if (nested) return nested[1];
    return path.replace(/[^/]*$/, '');
  }

  const base = basePath();
  const badge = document.createElement('div');
  badge.id = 'clarRealtimeStatus';
  badge.setAttribute('aria-live', 'polite');
  badge.style.cssText = 'position:fixed;right:14px;bottom:14px;z-index:30;pointer-events:none;font:600 12px/1.2 system-ui,sans-serif;color:#0f172a;background:rgba(255,255,255,.92);border:1px solid #e2e8f0;border-radius:999px;padding:4px 8px;box-shadow:0 4px 14px rgba(15,23,42,.08);';
  badge.textContent = '○ Reconnecting';
  document.body.appendChild(badge);

  function setLive(on) {
    badge.textContent = on ? '● Live' : '○ Reconnecting';
    badge.style.color = on ? '#047857' : '#b45309';
  }

  function fieldBusy(node) {
    const active = document.activeElement;
    if (!node || !active || !node.contains(active)) return false;
    const tag = active.tagName;
    if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') return false;
    if (active.type === 'hidden' || active.readOnly || active.disabled) return false;
    return true;
  }

  function modalBusy(node) {
    const open = document.querySelector('.swal2-container, .modal-overlay.open, .modal-overlay.show, .modal.show');
    if (!open) return false;
    if (!node) return true;
    return open.contains(node) || node.contains(open);
  }

  function canTouch(selector) {
    const node = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!node) return false;
    if (fieldBusy(node) || modalBusy(node)) return false;
    return true;
  }

  function call(name, args) {
    const fn = window[name];
    if (typeof fn === 'function') {
      try { fn.apply(window, args || []); } catch (e) {}
    }
  }

  async function freshDocument() {
    const res = await fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' });
    if (!res.ok) return null;
    const html = await res.text();
    return new DOMParser().parseFromString(html, 'text/html');
  }

  async function swapHtml(selector) {
    if (!canTouch(selector)) return;
    try {
      const doc = await freshDocument();
      if (!doc) return;
      const next = doc.querySelector(selector);
      const current = document.querySelector(selector);
      if (!next || !current || !canTouch(selector)) return;
      current.innerHTML = next.innerHTML;
    } catch (e) {}
  }

  async function swapMatches(selector) {
    try {
      const doc = await freshDocument();
      if (!doc) return;
      const next = doc.querySelectorAll(selector);
      const current = document.querySelectorAll(selector);
      if (!next.length || next.length !== current.length) return;
      current.forEach(function (node, index) {
        if (!canTouch(node)) return;
        node.innerHTML = next[index].innerHTML;
      });
    } catch (e) {}
  }

  function sectionActive(id) {
    const el = document.getElementById(id);
    return !!(el && el.classList.contains('active'));
  }

  function refresh(channels) {
    const want = new Set(channels || []);
    const has = function (name) { return want.has(name); };
    if (document.hidden) return;

    if (!fieldBusy(document.getElementById('notifBell')) && typeof loadNotificationsDropdown === 'function') {
      loadNotificationsDropdown();
    }

    if ((has('dashboard') || has('purchase-requests') || has('pr-approval') || has('purchase-orders') || has('incoming')) && sectionActive('section-dashboard') && canTouch('#section-dashboard')) {
      call('loadDashKPIs');
      call('loadDashRecentPOs', [true]);
    }
    if (has('purchase-requests') && sectionActive('section-purchase-requests') && canTouch('#section-purchase-requests')) {
      call('loadPurchaseRequests', [true]);
    }
    if (has('pr-approval') && canTouch('#approvalBody')) {
      call('loadPRApprovalQueue', [true]);
    }
    if (has('purchase-orders') && sectionActive('section-purchase-orders') && canTouch('#section-purchase-orders')) {
      call('loadPurchaseOrders', [true]);
    }
    if (has('po-approval') && sectionActive('section-po-approvals') && canTouch('#section-po-approvals')) {
      call('loadPoApprovals');
    }
    if ((has('purchase-orders') || has('incoming')) && sectionActive('section-po-tracker') && canTouch('#section-po-tracker')) {
      call('loadPoTracker');
    }
    if (has('incoming') && sectionActive('section-incoming-deliveries') && canTouch('#section-incoming-deliveries')) {
      call('loadIncomingDeliveries', [true]);
    }
    if (has('receiving') && sectionActive('section-purchase-receipts') && canTouch('#section-purchase-receipts')) {
      call('loadPurchaseReceipts', [true]);
    }
    if (has('receiving') && sectionActive('section-damage-returns') && canTouch('#section-damage-returns')) {
      call('loadDamageReturns');
    }
    if (has('pricing') && sectionActive('section-markup-queue') && canTouch('#section-markup-queue')) {
      call('loadPurchaserMarkupQueue', [true]);
    }
    if (has('stock-in') && sectionActive('section-stock-in-queue') && canTouch('#section-stock-in-queue')) {
      call('loadStockInQueue', [true]);
    }
    if (has('inventory') && !modalBusy(document.body) && typeof inventoryLiveRefreshTick === 'function' && !(typeof invFormBusy === 'function' && invFormBusy())) {
      inventoryLiveRefreshTick();
    }
    if (has('transaction-history') && sectionActive('section-transaction-history') && canTouch('#section-transaction-history')) {
      call('loadTransactionHistory');
    }
    if (has('sales') && canTouch('#salesOrdersTable')) {
      swapHtml('#salesOrdersTable tbody');
    }
    if (has('payments') && canTouch('#invoicesTable')) {
      swapHtml('#invoicesTable tbody');
    }
    if (has('credit') && canTouch('#salesCreditTable')) {
      swapHtml('#salesCreditTable tbody');
    }
    if (has('reports-inventory') && sectionActive('section-reports') && !fieldBusy(document.getElementById('inventoryReportFilterForm'))) {
      if (canTouch('#section-reports .report-summary')) swapMatches('#section-reports .report-summary');
      if (canTouch('#inventoryReportTable')) swapHtml('#inventoryReportTable tbody');
    }
    if (has('reports-sales') && sectionActive('section-sales-reports') && !fieldBusy(document.getElementById('salesReportFilterForm'))) {
      if (canTouch('#section-sales-reports .report-summary')) swapMatches('#section-sales-reports .report-summary');
      if (canTouch('#salesReportTable')) swapHtml('#salesReportTable tbody');
    }
  }

  let source = null;
  function connect() {
    if (source) {
      source.close();
    }
    const streamUrl = base + 'realtime_stream.php';
    source = new EventSource(window.clarTabUrl ? window.clarTabUrl(streamUrl) : streamUrl);
    source.onopen = function () { setLive(true); };
    source.onerror = function () { setLive(false); };
    source.addEventListener('change', function (event) {
      setLive(true);
      let payload = {};
      try { payload = JSON.parse(event.data || '{}'); } catch (e) { payload = {}; }
      refresh(payload.channels || []);
    });
    source.addEventListener('denied', function () {
      if (source) source.close();
      badge.remove();
    });
    source.addEventListener('reconnect', function () { setLive(false); });
  }

  connect();
})();
