(function () {
  const cfg = window.CLAR_MOBILE || {};
  const page = document.body && document.body.getAttribute('data-page');

  function toast(msg, kind) {
    const el = document.getElementById('mToast');
    if (!el) { window.alert(msg); return; }
    el.hidden = false;
    el.className = 'm-toast' + (kind === 'ok' ? ' is-ok' : kind === 'bad' ? ' is-bad' : '');
    el.textContent = msg;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { el.hidden = true; }, 3200);
  }

  const Sync = {
    busy: false,
    timer: null,
    clock: null,
    lastOk: 0,
    lastRev: '',
    lastData: null,
    connectedOnce: false,
    wasOffline: false
  };
  let alertTab = 'all';
  const deliveryState = { rows: [], filter: 'all', q: '', paint: null };

  function paintSync(live) {
    const wrap = document.getElementById('mSync');
    const text = document.getElementById('mSyncText');
    if (!wrap || !text) return;
    wrap.classList.toggle('is-live', !!live);
    wrap.classList.toggle('is-off', !live);
    if (!live) {
      text.textContent = 'Offline';
      return;
    }
    const ago = Sync.lastOk ? Math.max(0, Math.round((Date.now() - Sync.lastOk) / 1000)) : 0;
    text.textContent = ago <= 8 ? 'Live' : ('Last synced: ' + ago + ' second' + (ago === 1 ? '' : 's') + ' ago');
  }

  function setOffline(on) {
    const el = document.getElementById('mOffline');
    if (el) {
      el.textContent = 'Connection lost. Reconnecting...';
      el.hidden = !on;
    }
    paintSync(!on && Sync.lastOk > 0);
  }

  window.addEventListener('online', () => setOffline(false));
  window.addEventListener('offline', () => setOffline(true));
  setOffline(!navigator.onLine);

  function uuid() {
    if (crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'r' + Date.now() + '-' + Math.random().toString(16).slice(2);
  }

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  function money(n) {
    const v = Number(n || 0);
    return '₱' + v.toFixed(2);
  }

  function chip(status) {
    const s = String(status || '');
    let cls = 'm-chip';
    if (/ready|approved|completed|good|safe|received/i.test(s)) cls += ' m-chip-good';
    else if (/pending|near|incoming|inspect|await/i.test(s)) cls += ' m-chip-warn';
    else if (/expired|denied|damage/i.test(s)) cls += ' m-chip-bad';
    else if (/stock-in|receiving/i.test(s)) cls += ' m-chip-ready';
    return '<span class="' + cls + '">' + esc(s) + '</span>';
  }

  async function api(action, data, method) {
    const isPost = (method || (data && Object.keys(data).length ? 'POST' : 'GET')) === 'POST';
    const headers = {
      'X-Clarenzon-Auth-Context': cfg.role || 'admin',
      'X-CSRF-TOKEN': cfg.csrf || '',
      'Accept': 'application/json'
    };
    let url = cfg.api + (cfg.api.indexOf('?') >= 0 ? '&' : '?') + 'action=' + encodeURIComponent(action) + '&auth_ctx=' + encodeURIComponent(cfg.role || 'admin');
    let body;
    if (isPost) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
      const params = new URLSearchParams();
      params.set('action', action);
      params.set('csrf_token', cfg.csrf || '');
      Object.keys(data || {}).forEach((k) => {
        if (data[k] !== undefined && data[k] !== null) params.set(k, String(data[k]));
      });
      body = params.toString();
    } else if (data) {
      Object.keys(data).forEach((k) => {
        if (data[k] !== undefined && data[k] !== null && data[k] !== '') {
          url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(data[k]));
        }
      });
    }
    let res;
    try {
      res = await fetch(url, { method: isPost ? 'POST' : 'GET', headers, body, credentials: 'same-origin' });
    } catch (err) {
      setOffline(true);
      const e = new Error('Connection lost. Reconnecting...');
      e.submitted = false;
      throw e;
    }
    setOffline(false);
    let json = null;
    try { json = await res.json(); } catch (e) { json = null; }
    if (!json) {
      const e = new Error('Unable to connect to the server.');
      e.submitted = false;
      throw e;
    }
    if (!json.success) {
      const e = new Error(json.message || 'Unable to complete this action.');
      e.payload = json;
      e.submitted = json.submitted === true;
      throw e;
    }
    return json;
  }

  function bindScanner(ids, handler) {
    const start = document.getElementById(ids.start);
    const stop = document.getElementById(ids.stop);
    const perm = document.getElementById(ids.perm);
    const hint = document.getElementById(ids.hint);
    if (!start || typeof ClarenzonScanner === 'undefined') return null;
    const native = typeof ClarenzonScannerIsNative === 'function' && ClarenzonScannerIsNative();
    if (native) {
      start.textContent = 'Scan barcode';
      if (stop) stop.hidden = true;
      if (hint) hint.textContent = 'Tap Scan barcode, then point the phone camera at the box.';
    }
    const scanner = new ClarenzonScanner({
      viewportId: ids.viewport,
      onCode: handler,
      onStatus: (st) => {
        if (native) {
          if (hint) hint.textContent = st === 'opening'
            ? 'Opening the phone camera…'
            : 'Tap Scan barcode, then point the phone camera at the box.';
          return;
        }
        if (hint) hint.textContent = st === 'live' ? 'Scanning… hold a barcode in the frame.' : 'Camera idle.';
        start.hidden = st === 'live';
        if (stop) stop.hidden = st !== 'live';
      },
      onError: (msg) => {
        if (perm) { perm.hidden = false; perm.textContent = msg; }
        toast(msg, 'bad');
      }
    });
    start.addEventListener('click', () => {
      if (perm) perm.hidden = true;
      scanner.start();
    });
    if (stop) stop.addEventListener('click', () => scanner.stop());
    return scanner;
  }

  function applyBell(n) {
    const el = document.getElementById('mBellCount');
    if (!el) return;
    const v = parseInt(n, 10) || 0;
    if (v > 0) {
      el.hidden = false;
      el.textContent = v > 99 ? '99+' : String(v);
    } else {
      el.hidden = true;
      el.textContent = '';
    }
  }

  function applyCounts(d) {
    document.querySelectorAll('[data-key]').forEach((card) => {
      const key = card.getAttribute('data-key');
      let n = d[key];
      if ((n === undefined || n === null) && key === 'incoming') n = d.incoming_po;
      const el = card.querySelector('[data-count]');
      if (el && n !== undefined && n !== null) el.textContent = String(n);
    });
    applyBell(d.alerts_count);
  }

  function applyActivities(d) {
    const box = document.getElementById('dashMoves');
    if (!box) return;
    const acts = d.activities && d.activities.length ? d.activities : (d.movements || []).map((m) => ({
      title: m.product_name || m.sku || 'Item',
      subtitle: (m.movement_type || '') + ' · Qty ' + (m.quantity || 0),
      when: m.moved_at || '',
      href: 'inventory.php'
    }));
    const html = !acts.length
      ? '<p class="m-empty">No recent warehouse activity.</p>'
      : acts.map((a) => (
        '<a class="m-activity" href="' + esc(a.href || '#') + '">' +
        '<div class="m-activity-ico">' + (a.type === 'stock_in' ? 'SI' : 'PO') + '</div>' +
        '<div><strong>' + esc(a.title) + '</strong><p>' + esc(a.subtitle || '') + '</p></div>' +
        '<em>' + esc(relTime(a.when)) + '</em></a>'
      )).join('');
    if (box.getAttribute('data-sig') === html) return;
    box.setAttribute('data-sig', html);
    box.innerHTML = html;
  }

  function applyAlertsView(d) {
    const list = document.getElementById('alertList');
    const summary = document.getElementById('alertSummary');
    if (!list && !summary) return;
    if (summary) {
      summary.innerHTML =
        '<a class="m-alert-card" href="alerts.php?tab=low"><div class="m-alert-ico low">!</div><div><strong>Low Stock</strong><p>' + esc(d.low_stock || 0) + ' items</p></div></a>' +
        '<a class="m-alert-card" href="alerts.php?tab=near"><div class="m-alert-ico near">⏱</div><div><strong>Near Expiry</strong><p>' + esc(d.near_expiry || 0) + ' items</p></div></a>' +
        '<a class="m-alert-card" href="alerts.php?tab=expired"><div class="m-alert-ico exp">i</div><div><strong>Expired</strong><p>' + esc(d.expired || 0) + ' items</p></div></a>';
    }
    if (!list) return;
    const rows = [];
    (d.alerts || []).forEach((row) => {
      const kind = row.kind || '';
      if (alertTab === 'low' && kind !== 'low') return;
      if (alertTab === 'near' && kind !== 'near') return;
      if (alertTab === 'expired' && kind !== 'exp') return;
      rows.push(row);
    });
    if (!rows.length) {
      list.innerHTML = '<p class="m-empty">No alerts in this view.</p>';
      return;
    }
    list.innerHTML = rows.map((row) => (
      '<a class="m-alert-card" href="' + esc(row.href) + '">' +
      '<div class="m-alert-ico ' + esc(row.kind || '') + '">!</div>' +
      '<div><strong>' + esc(row.title) + '</strong><p>' + esc(row.sub) + '</p></div></a>'
    )).join('');
  }

  function applyDeliveries(rows) {
    deliveryState.rows = Array.isArray(rows) ? rows : [];
    if (typeof deliveryState.paint === 'function') deliveryState.paint();
  }

  async function fetchDashboardData(opts) {
    const force = !!(opts && opts.force);
    if (Sync.busy) return;
    if (document.hidden && !force) return;
    if (page === 'scan') return;
    Sync.busy = true;
    try {
      const r = await api('dashboard');
      const d = r.data || {};
      Sync.lastOk = Date.now();
      if (Sync.wasOffline) {
        toast('Connected', 'ok');
      }
      Sync.wasOffline = false;
      Sync.connectedOnce = true;
      setOffline(false);
      if (!force && Sync.lastRev && d.revision === Sync.lastRev) {
        paintSync(true);
        return;
      }
      Sync.lastRev = d.revision || '';
      Sync.lastData = d;
      applyCounts(d);
      applyActivities(d);
      applyAlertsView(d);
      applyDeliveries(d.deliveries || []);
      paintSync(true);
    } catch (e) {
      Sync.wasOffline = true;
      setOffline(true);
      if (!Sync.connectedOnce) toast(e.message, 'bad');
    } finally {
      Sync.busy = false;
    }
  }

  function startRealtimeSync() {
    if (page === 'scan') return;
    fetchDashboardData({ force: true });
    if (Sync.timer) return;
    Sync.timer = setInterval(() => {
      fetchDashboardData();
      if (page === 'receiving' && typeof window.__mobileRecvReload === 'function') {
        window.__mobileRecvReload();
      }
      if (page === 'stock_in' && typeof window.__mobileSiReload === 'function') {
        window.__mobileSiReload();
      }
      if (page === 'inventory' && typeof window.__mobileInvReload === 'function') {
        window.__mobileInvReload();
      }
    }, 5000);
    if (!Sync.clock) {
      Sync.clock = setInterval(() => paintSync(!Sync.wasOffline && Sync.lastOk > 0), 1000);
    }
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) fetchDashboardData({ force: true });
    });
    window.addEventListener('focus', () => fetchDashboardData({ force: true }));
    window.addEventListener('pageshow', () => fetchDashboardData({ force: true }));
    window.addEventListener('online', () => fetchDashboardData({ force: true }));
  }

  function initDashboard() {
  }

  function relTime(v) {
    if (!v) return '';
    const s = String(v);
    if (s.length <= 10) return s;
    return s.slice(0, 16).replace('T', ' ');
  }

  function renderProduct(p) {
    if (!p) return '<p class="m-empty">Product information could not be found.</p>';
    const po = p.incoming_po || null;
    const img = p.image
      ? '<img class="m-prod-img" src="' + esc(p.image) + '" alt="">'
      : '<div class="m-prod-img"></div>';
    const poQty = po ? po.po_qty : (p.quantity_in_stock ?? '—');
    const rec = po ? po.received : (p.sellable_qty ?? '—');
    const rem = po ? po.remaining : '—';
    const dmg = po ? po.damage_missing : (p.damage_missing ?? 0);
    const recvBtns = po
      ? '<div class="m-actions"><a class="m-btn m-btn-ghost" href="scan.php">Scan Another</a>' +
        '<a class="m-btn m-btn-primary" href="receiving.php?po_id=' + encodeURIComponent(po.po_id) + '">Confirm Receiving</a></div>'
      : '<div class="m-actions"><a class="m-btn m-btn-ghost" href="scan.php">Scan Another</a></div>';
    const expStatus = (p.batches && p.batches[0] && p.batches[0].status) ? p.batches[0].status : (p.expiry_date ? 'Good' : 'N/A');
    return '<article class="m-panel">' +
      '<div class="m-prod-head">' + img +
      '<div><h2>' + esc(p.name) + '</h2><p class="m-muted">' + esc(p.brand || '') + '</p></div></div>' +
      '<dl class="m-kv">' +
      '<dt>Packaging</dt><dd>' + esc(p.packaging || '—') + '</dd>' +
      '<dt>Unit</dt><dd>' + esc(p.unit || '—') + '</dd>' +
      '<dt>Category</dt><dd>' + esc(p.category || '—') + '</dd>' +
      '<dt>Sub-Category</dt><dd>' + esc(p.subcategory || '—') + '</dd>' +
      '<dt>Supplier</dt><dd>' + esc(p.supplier || '—') + '</dd>' +
      '<dt>Brand</dt><dd>' + esc(p.brand || '—') + '</dd>' +
      '<dt>Batch / Lot</dt><dd>' + esc((p.batches && p.batches[0] && p.batches[0].batch_number) || '—') + '</dd>' +
      '<dt>Expiry</dt><dd>' + esc(p.expiry_date || '—') + ' ' + chip(expStatus) + '</dd>' +
      '<dt>Location</dt><dd>' + esc(p.location || '—') + '</dd>' +
      '</dl>' +
      '<div class="m-stock-box"><h3>Stock Information</h3>' +
      '<div class="m-stat-row"><span>PO Quantity</span><b>' + esc(poQty) + '</b></div>' +
      '<div class="m-stat-row"><span>Received</span><b>' + esc(rec) + '</b></div>' +
      '<div class="m-stat-row"><span>Remaining</span><b>' + esc(rem) + '</b></div>' +
      '<div class="m-stat-row"><span>Qty Sellable</span><b>' + esc(p.sellable_qty) + '</b></div>' +
      '<div class="m-counter m-counter-good"><span>Goods (scanned)</span><b>' + esc(po ? po.received : 0) + '</b></div>' +
      '<div class="m-counter m-counter-bad"><span>Replacement</span><b>' + esc(dmg) + '</b></div>' +
      '</div>' + recvBtns + '</article>';
  }

  async function lookupCode(code) {
    const r = await api('lookup_barcode', { barcode: code }, 'GET');
    return r;
  }

  function initScan() {
    const out = document.getElementById('scanResult');
    const wrap = document.querySelector('.m-scanner-wrap');
    async function handle(code, method) {
      try {
        const r = await api('scan_record', { barcode: code, input_method: method || 'Camera', request_id: uuid() }, 'POST');
        const data = r.data || r.product;
        wrap.hidden = true;
        out.hidden = false;
        if (!data && r.results && r.results.length) {
          out.innerHTML = r.results.map((p) => (
            '<a class="m-card" href="product.php?id=' + encodeURIComponent(p.id) + '">' +
            '<strong>' + esc(p.name) + '</strong>' +
            '<p class="m-muted">' + esc(p.brand || p.sku || '') + '</p></a>'
          )).join('');
          toast(r.message || 'Select a product', 'ok');
          return;
        }
        out.innerHTML = renderProduct(data);
        toast(r.message || (r.recorded ? 'Scan recorded on the website.' : 'Item scanned successfully.'), r.recorded ? 'ok' : 'ok');
      } catch (e) {
        out.hidden = false;
        out.innerHTML = '<p class="m-empty">' + esc(e.message) + '</p>';
        toast(e.message, 'bad');
      }
    }
    const scanner = bindScanner({ start: 'scanStart', stop: 'scanStop', perm: 'scanPerm', hint: 'scanHint', viewport: 'scanViewport' }, handle);
    const toggle = document.getElementById('scanManualToggle');
    const form = document.getElementById('scanManual');
    if (toggle && form) {
      toggle.addEventListener('click', () => { form.hidden = !form.hidden; });
    }
    if (form) {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const code = (document.getElementById('scanCode').value || '').trim();
        if (!code) {
          out.hidden = false;
          out.innerHTML = '<p class="m-empty">Please enter a barcode.</p>';
          toast('Please enter a barcode.', 'bad');
          return;
        }
        handle(code, 'Manual');
      });
    }
    if (scanner) {
      setTimeout(() => scanner.start(), 400);
    }
  }

  async function initDeliveries() {
    const box = document.getElementById('delList');
    deliveryState.filter = new URLSearchParams(location.search).get('filter') || 'all';
    deliveryState.paint = function paint() {
      const qq = String(deliveryState.q || '').toLowerCase();
      const filter = deliveryState.filter;
      const list = (deliveryState.rows || []).filter((row) => {
        const hay = ((row.po_number || '') + ' ' + (row.supplier_name || '')).toLowerCase();
        const bucket = row.filter_bucket || 'pending';
        let okF = true;
        if (filter === 'for_receiving') okF = bucket === 'pending' || bucket === 'receiving';
        else if (filter !== 'all') okF = bucket === filter;
        return okF && (!qq || hay.indexOf(qq) >= 0);
      });
      if (!list.length) {
        box.innerHTML = '<p class="m-empty">No incoming purchase orders.</p>';
        return;
      }
      box.innerHTML = list.map((row) => {
        const bucket = row.filter_bucket || 'pending';
        const label = bucket === 'received' ? 'Received' : (bucket === 'receiving' ? 'Receiving' : 'Pending');
        const date = String(row.expected_delivery_date || row.order_date || '').slice(0, 10);
        return '<a class="m-card" href="receiving.php?po_id=' + encodeURIComponent(row.id) + '">' +
          '<div class="m-po-row"><div><strong>' + esc(row.po_number) + '</strong>' +
          '<p>' + esc(row.supplier_name || 'Supplier') + '</p></div>' +
          '<div class="m-po-date">' + esc(date) + '<div class="m-meta">' + chip(label) + '</div></div></div></a>';
      }).join('');
    };
    document.querySelectorAll('#delFilter button').forEach((b) => {
      b.classList.toggle('is-active', b.getAttribute('data-filter') === deliveryState.filter);
    });
    const search = document.getElementById('delSearch');
    if (search) search.addEventListener('input', () => { deliveryState.q = search.value; deliveryState.paint(); });
    const filt = document.getElementById('delFilter');
    if (filt) {
      filt.addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        if (!btn) return;
        deliveryState.filter = btn.getAttribute('data-filter') || 'all';
        document.querySelectorAll('#delFilter button').forEach((b) => b.classList.toggle('is-active', b === btn));
        deliveryState.paint();
      });
    }
  }

  function initReceiving() {
    const app = document.getElementById('recvApp');
    if (!app) return;
    const poId = Number(app.getAttribute('data-po-id') || 0);
    if (!poId) return;
    let mode = 'good';
    let selectedItem = 0;
    let busy = false;
    const camWrap = document.getElementById('recvCamWrap');

    document.getElementById('recvMode').addEventListener('click', (ev) => {
      const btn = ev.target.closest('button');
      if (!btn) return;
      mode = btn.getAttribute('data-mode');
      document.querySelectorAll('#recvMode button').forEach((b) => b.classList.toggle('is-active', b === btn));
    });

    async function load() {
      const r = await api('po_detail', { id: poId }, 'GET');
      const po = r.data || {};
      const items = r.items || [];
      document.getElementById('recvHeader').innerHTML =
        '<h2 style="margin:0 0 4px">' + esc(po.po_number) + '</h2>' +
        '<p class="m-muted">' + esc(po.supplier_name || '') + '</p>' +
        '<div class="m-stat-row"><span>Expected Items</span><b>' + esc(po.qty_ordered || 0) + '</b></div>' +
        '<div class="m-stat-row"><span>Received</span><b>' + esc(po.qty_good || 0) + '</b></div>' +
        '<div class="m-stat-row"><span>Replacement</span><b>' + esc(po.qty_damage_missing || 0) + '</b></div>' +
        '<div class="m-stat-row"><span>Remaining</span><b>' + esc(po.qty_remaining || 0) + '</b></div>';
      if (po.driver_name && !document.getElementById('recvDriver').value) {
        document.getElementById('recvDriver').value = po.driver_name;
      }
      document.getElementById('recvLines').innerHTML = items.map((it) => (
        '<button type="button" class="m-card" data-item="' + esc(it.id) + '">' +
        '<strong>' + esc(it.product_name) + '</strong>' +
        '<p class="m-muted">' + esc(it.quantity_good || 0) + ' / ' + esc(it.quantity_ordered) + '</p></button>'
      )).join('') || '<p class="m-empty">No PO lines.</p>';
      document.querySelectorAll('#recvLines [data-item]').forEach((btn) => {
        btn.addEventListener('click', () => {
          selectedItem = Number(btn.getAttribute('data-item'));
          document.querySelectorAll('#recvLines [data-item]').forEach((b) => b.classList.remove('m-line-on'));
          btn.classList.add('m-line-on');
        });
        if (selectedItem && Number(btn.getAttribute('data-item')) === selectedItem) {
          btn.classList.add('m-line-on');
        }
      });
      if (!selectedItem && items[0]) {
        selectedItem = Number(items[0].id);
        const first = document.querySelector('#recvLines [data-item="' + selectedItem + '"]');
        if (first) first.classList.add('m-line-on');
      }
    }

    window.__mobileRecvReload = function () {
      if (busy || document.hidden) return;
      load().catch(() => {});
    };

    async function receive(code, method) {
      if (busy) return;
      busy = true;
      try {
        const r = await api('receive_scan', {
          po_id: poId,
          po_item_id: selectedItem || '',
          barcode: code,
          status: mode === 'damage' ? 'damage' : 'good',
          input_method: method || 'Camera',
          lot_number: document.getElementById('recvLot').value,
          expiry_date: document.getElementById('recvExp').value,
          mfg_date: document.getElementById('recvMfg').value,
          delivered_by: document.getElementById('recvDriver').value,
          remarks: document.getElementById('recvNotes').value,
          request_id: uuid()
        }, 'POST');
        toast(r.message || 'Item scanned successfully.', 'ok');
        if (r.po_item_id) selectedItem = Number(r.po_item_id);
        if (r.batch_number && !document.getElementById('recvLot').value) {
          document.getElementById('recvLot').value = r.batch_number;
        }
        await load();
      } catch (e) {
        toast(e.message, 'bad');
      } finally {
        busy = false;
      }
    }

    const startBtn = document.getElementById('recvStart');
    if (startBtn && camWrap) {
      startBtn.addEventListener('click', () => { camWrap.hidden = false; }, true);
    }
    bindScanner({ start: 'recvStart', stop: 'recvStop', perm: 'recvPerm', hint: 'recvHint', viewport: 'recvViewport' }, receive);
    document.getElementById('recvManual').addEventListener('submit', (ev) => {
      ev.preventDefault();
      const code = (document.getElementById('recvCode').value || '').trim();
      if (code) receive(code, 'Manual');
    });
    const done = document.getElementById('recvComplete');
    if (done) {
      done.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        try {
          const r = await api('receive_complete', {
            po_id: poId,
            remarks: document.getElementById('recvNotes').value,
            delivered_by: document.getElementById('recvDriver').value
          }, 'POST');
          toast(r.message, 'ok');
          window.location.href = 'deliveries.php';
        } catch (e) {
          toast(e.message, 'bad');
          busy = false;
        }
      });
    }
    load().catch((e) => toast(e.message, 'bad'));
  }

  async function initStockIn() {
    const tabs = document.getElementById('siTabs');
    const bucket = (tabs && tabs.getAttribute('data-tab')) || 'pending';
    const box = document.getElementById('siList');
    async function load() {
      const r = await api('stock_in_queue', { bucket: bucket }, 'GET');
      const rows = r.data || [];
      if (!rows.length) {
        box.innerHTML = '<p class="m-empty">Nothing in this queue.</p>';
        return;
      }
      box.innerHTML = rows.map((row) => {
        const actions = [];
        if (cfg.isAdmin) {
          actions.push('<button class="m-btn m-btn-ghost" data-approve="' + row.id + '">Approve</button>');
          actions.push('<button class="m-btn m-btn-ghost" data-reject="' + row.id + '">Request correction</button>');
        }
        if (row.can_stock_in) {
          actions.push('<button class="m-btn m-btn-primary" data-stock="' + row.id + '">Stock-In</button>');
        }
        const items = (row.items || []).map((it) => (
          '<p class="m-muted">' + esc(it.product_name) + ' · Good ' + esc(it.quantity_good) +
          ' · Replacement ' + esc(it.quantity_damage_missing || 0) +
          ' · ' + esc(it.batch_number || it.system_batch_number || '') + '</p>'
        )).join('');
        return '<div class="m-card"><strong>' + esc(row.po_number) + '</strong>' +
          '<p>' + esc(row.supplier_name || '') + ' · ' + esc(row.receipt_number || '') + '</p>' +
          '<div class="m-meta">' + chip(row.workflow_status) +
          (row.waiting_pricing ? chip('Waiting for Pricing') : '') +
          '<span class="m-chip">Good ' + esc(row.total_good || 0) + '</span></div>' +
          items + '<div class="m-si-actions">' + actions.join('') + '</div></div>';
      }).join('');
    }
    box.addEventListener('click', async (ev) => {
      const approve = ev.target.closest('[data-approve]');
      const reject = ev.target.closest('[data-reject]');
      const stock = ev.target.closest('[data-stock]');
      try {
        if (approve) {
          const r = await api('approve_stock_in', { receipt_id: approve.getAttribute('data-approve') }, 'POST');
          toast(r.message, 'ok');
          await load();
        } else if (reject) {
          const note = await window.appPrompt('Correction note');
          if (!note) return;
          const r = await api('reject_stock_in', { receipt_id: reject.getAttribute('data-reject'), remarks: note }, 'POST');
          toast(r.message, 'ok');
          await load();
        } else if (stock) {
          if (!await window.appConfirm('Stock in good quantity? Replacement will not become sellable stock.')) return;
          const r = await api('execute_stock_in', { receipt_id: stock.getAttribute('data-stock'), request_id: uuid() }, 'POST');
          toast(r.message, 'ok');
          await load();
        }
      } catch (e) {
        toast(e.message, 'bad');
      }
    });
    load().catch((e) => { box.innerHTML = '<p class="m-empty">' + esc(e.message) + '</p>'; });
    window.__mobileSiReload = function () {
      if (document.hidden) return;
      load().catch(() => {});
    };
  }

  async function initInventory() {
    const box = document.getElementById('invList');
    const input = document.getElementById('invSearch');
    const cat = document.getElementById('invCat');
    const sup = document.getElementById('invSup');
    const exp = document.getElementById('invExp');
    try {
      const f = await api('inventory_filters');
      if (cat) {
        (f.categories || []).forEach((c) => {
          const o = document.createElement('option');
          o.value = c.id;
          o.textContent = c.name;
          cat.appendChild(o);
        });
      }
      if (sup) {
        (f.suppliers || []).forEach((s) => {
          const o = document.createElement('option');
          o.value = s.id;
          o.textContent = s.name;
          sup.appendChild(o);
        });
      }
    } catch (e) { /* filters optional */ }
    async function run() {
      try {
        const r = await api('inventory', {
          q: input.value || '',
          category_id: (cat && cat.value) || '',
          supplier_id: (sup && sup.value) || '',
          expiry: (exp && exp.value) || ''
        }, 'GET');
        const rows = r.data || [];
        if (!rows.length) {
          box.innerHTML = '<p class="m-empty">No matching stock.</p>';
          return;
        }
        box.innerHTML = rows.map((row) => (
          '<a class="m-card" href="product.php?id=' + encodeURIComponent(row.id) + '">' +
          '<div class="m-stock-row"><div><strong>' + esc(row.name) + '</strong>' +
          '<small>' + esc(row.brand || row.supplier_name || '') + '</small></div>' +
          '<div>' + esc(row.sellable) + '</div>' +
          '<div>' + esc((row.expiry_date || '').toString().slice(0, 10) || '—') + '</div></div></a>'
        )).join('');
      } catch (e) {
        box.innerHTML = '<p class="m-empty">' + esc(e.message) + '</p>';
      }
    }
    document.getElementById('invSearchForm').addEventListener('submit', (ev) => { ev.preventDefault(); run(); });
    input.addEventListener('change', run);
    if (cat) cat.addEventListener('change', run);
    if (sup) sup.addEventListener('change', run);
    if (exp) exp.addEventListener('change', run);
    window.__mobileInvReload = function () {
      if (document.hidden) return;
      run();
    };
    run();
  }

  async function initProduct() {
    const app = document.getElementById('prodApp');
    const id = Number(app && app.getAttribute('data-id') || 0);
    if (!id) return;
    try {
      const r = await api('product', { id: id }, 'GET');
      document.getElementById('prodBody').innerHTML = renderProduct(r.data);
    } catch (e) {
      document.getElementById('prodBody').innerHTML = '<p class="m-empty">' + esc(e.message) + '</p>';
    }
  }

  async function initLow() {
    const box = document.getElementById('lowList');
    try {
      const r = await api('low_stock');
      const rows = r.data || [];
      if (!rows.length) {
        box.innerHTML = '<p class="m-empty">No low-stock items.</p>';
        return;
      }
      box.innerHTML = rows.map((row) => (
        '<a class="m-card" href="product.php?id=' + encodeURIComponent(row.id) + '">' +
        '<strong>' + esc(row.name) + '</strong>' +
        '<div class="m-qty"><div><b>' + esc(row.sellable_qty) + '</b>Now</div>' +
        '<div><b>' + esc(row.reorder_level) + '</b>Reorder</div>' +
        '<div><b>' + esc(row.shortage) + '</b>Short</div></div>' +
        '<p class="m-muted">' + esc(row.supplier_name || 'No supplier') + '</p></a>'
      )).join('');
    } catch (e) {
      box.innerHTML = '<p class="m-empty">' + esc(e.message) + '</p>';
    }
  }

  async function initExpiry() {
    const box = document.getElementById('expList');
    let rows = [];
    function paint(status) {
      const list = rows.filter((row) => !status || status === 'all' || row.expiry_status === status);
      if (!list.length) {
        box.innerHTML = '<p class="m-empty">No matching lots.</p>';
        return;
      }
      box.innerHTML = list.map((row) => (
        '<a class="m-card" href="product.php?id=' + encodeURIComponent(row.id) + '">' +
        '<strong>' + esc(row.name) + '</strong>' +
        '<p class="m-muted">' + esc(row.batch_lot) + ' · ' + esc(row.location) + '</p>' +
        '<div class="m-meta">' + chip(row.expiry_status) +
        '<span class="m-chip">' + esc(row.expiry_display) + '</span>' +
        '<span class="m-chip">Qty ' + esc(row.qty) + '</span></div></a>'
      )).join('');
    }
    try {
      const r = await api('expiry');
      rows = r.data || [];
      const wanted = new URLSearchParams(location.search).get('status');
      const map = { expired: 'Expired', 'near-expiry': 'Near Expiry' };
      paint(map[(wanted || '').toLowerCase()] || 'all');
      document.getElementById('expFilter').addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        if (!btn) return;
        document.querySelectorAll('#expFilter button').forEach((b) => b.classList.toggle('is-active', b === btn));
        paint(btn.getAttribute('data-status'));
      });
    } catch (e) {
      box.innerHTML = '<p class="m-empty">' + esc(e.message) + '</p>';
    }
  }

  async function initReturns() {
    const box = document.getElementById('retList');
    const wrap = document.getElementById('retFormWrap');
    try {
      const r = await api('returns');
      const rows = r.data || [];
      if (!rows.length) {
        box.innerHTML = '<p class="m-empty">No replacement records.</p>';
        return;
      }
      box.innerHTML = rows.map((row) => (
        '<button type="button" class="m-card" data-ret="' + esc(row.id) + '"' +
        ' data-name="' + esc(row.product_name || '') + '"' +
        ' data-sup="' + esc(row.supplier_name || '') + '"' +
        ' data-qty="' + esc((row.quantity_damaged || 0) + (row.quantity_missing || 0) - (row.quantity_returned || 0)) + '"' +
        ' data-lot="' + esc(row.batch_display || '') + '">' +
        '<strong>' + esc(row.product_name || 'Item') + '</strong>' +
        '<p>' + esc(row.supplier_name || '') + ' · ' + esc(row.po_number || '') + '</p>' +
        '<div class="m-meta">' + chip(row.status) +
        '<span class="m-chip">Open qty ' + esc((row.quantity_damaged || 0) + (row.quantity_missing || 0) - (row.quantity_returned || 0)) + '</span></div></button>'
      )).join('');
      box.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-ret]');
        if (!btn) return;
        wrap.hidden = false;
        document.getElementById('retDamageId').value = btn.getAttribute('data-ret');
        document.getElementById('retQty').value = btn.getAttribute('data-qty') || '1';
        document.getElementById('retDate').value = new Date().toISOString().slice(0, 10);
        document.getElementById('retSummary').textContent =
          (btn.getAttribute('data-name') || '') + ' · ' + (btn.getAttribute('data-sup') || '') +
          ' · Lot ' + (btn.getAttribute('data-lot') || '—');
        wrap.scrollIntoView({ behavior: 'smooth' });
      });
      document.getElementById('retForm').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        try {
          const res = await api('create_return', {
            damage_return_id: document.getElementById('retDamageId').value,
            quantity: document.getElementById('retQty').value,
            reason: document.getElementById('retReason').value,
            remarks: document.getElementById('retNotes').value,
            return_date: document.getElementById('retDate').value
          }, 'POST');
          toast(res.message, 'ok');
          location.reload();
        } catch (e) {
          toast(e.message, 'bad');
        }
      });
    } catch (e) {
      box.innerHTML = '<p class="m-empty">' + esc(e.message) + '</p>';
    }
  }

  function initAlerts() {
    alertTab = new URLSearchParams(location.search).get('tab') || 'all';
    document.querySelectorAll('#alertTabs button').forEach((b) => {
      b.classList.toggle('is-active', b.getAttribute('data-tab') === alertTab);
    });
    const tabs = document.getElementById('alertTabs');
    if (tabs) {
      tabs.addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        if (!btn) return;
        alertTab = btn.getAttribute('data-tab') || 'all';
        document.querySelectorAll('#alertTabs button').forEach((b) => b.classList.toggle('is-active', b === btn));
        if (Sync.lastData) applyAlertsView(Sync.lastData);
      });
    }
  }

  function initReports() {
  }

  const nativeSettings = document.getElementById('nativeSettings');
  if (nativeSettings) {
    try {
      if (window.ClarenzonNativeApp && window.ClarenzonNativeApp.openServerSettings) {
        nativeSettings.hidden = false;
        nativeSettings.addEventListener('click', (ev) => {
          ev.preventDefault();
          window.ClarenzonNativeApp.openServerSettings();
        });
      }
    } catch (e) { /* browser */ }
  }

  const boot = {
    dashboard: initDashboard,
    scan: initScan,
    deliveries: initDeliveries,
    receiving: initReceiving,
    stock_in: initStockIn,
    inventory: initInventory,
    product: initProduct,
    low_stock: initLow,
    expiry: initExpiry,
    returns: initReturns,
    alerts: initAlerts,
    reports: initReports
  };
  if (boot[page]) boot[page]();
  startRealtimeSync();
})();
