/* ════════════════════════════════════════════════════════════
   procurement_module.js – Comprehensive Procurement Workflow
   Purchase Requests (PR) -> Admin Approval -> Purchase Orders (PO)
   -> Goods Received Receipts -> Supplier Invoices
════════════════════════════════════════════════════════════ */

const PROC_API = 'procurement_api.php';

// ── Global State ────────────────────────────────────────────
let _suppliersData = [], _posData = [], _prsData = [], _receiptsData = [], _invoicesData = [], _perfData = [];
let _poProducts = [], _poSuppliers = [];
let _lowStockProducts = [];
let _customerOrdersForPR = [];
let _prPartyNames = [];
let _prCustomerNameFilter = '';
let _prCustomerOrdersAvailable = null;
let _prSelectedCustomerOrderId = '';
let _prCustomerOrderViewSig = '';
let _prCatalogViewSig = '';
let _editingSupplierId = null;
let _prItemCount = 0, _poItemCount = 0;
let _prLiveSyncTimer = null;
let _prLowStockViewSig = '';
let _incomingPollTimer = null;
let _incomingSig = '';
let _liveRefreshTimer = null;
let _prListSig = '';
let _prQueueSig = '';
let _poListSig = '';
let _receiptListSig = '';
let _stockInSig = '';
let _dashPoSig = '';
let _syncingPRSelect = false;
let _prPurposeConfirmOpen = false;
let _liveCheckBusy = false;
let _liveChangeStamp = '';
let _liveChangeParts = { pr_pending: '', pr: '', po: '', receipt: '' };
let _liveReviewWasOpen = false;
let _prRejectBusy = false;
let _prApproveBusy = false;
let _prPreferredSupplierId = '';
let _prSupplierComboBound = false;
let _prSupplierComboFilter = '';
let _prRowSupplierMenuAnchor = null;
let _prRowSupplierMenuRow = null;
let _prStashedItems = [];
let _prPurposeLastSupplier = {};
let _prPurposeBuckets = null;
let _prRestoringPurposeItems = false;
let _prSwitchingPurpose = false;
let _prActivePurposeView = '';
let _prCustomerOrderFilter = '';
let _prCoCustomers = [];
let _prCoCustomersLoaded = false;
const PR_PURPOSE_KEYS = ['Low Stock Replenishment', 'Customer Order', 'Others'];
const LIVE_REFRESH_MS = 4000;

function liveRowsSignature(rows, fields) {
  return (rows || []).map(row => fields.map(f => String(row[f] ?? '')).join(':')).join('|');
}

function liveScannerActive() {
  return !!(window.adminScannerActive || window.dashScannerIsActive);
}

function liveSectionId() {
  return document.querySelector('.content-section.active')?.id || '';
}

function livePaneActive(id) {
  const el = document.getElementById(id);
  if (!el) return false;
  return el.classList.contains('active') || el.classList.contains('show');
}

function applyPRApprovalCount(n) {
  n = parseInt(n, 10) || 0;
  const badgeText = n > 0 ? String(n) : '';
  const approvalBadge = document.getElementById('approvalBadge');
  const tabBadge = document.getElementById('tabApprovalBadge');
  const prAppBadge = document.getElementById('prAppBadge');
  const pending = document.getElementById('kpiPendingApproval');
  if (approvalBadge) approvalBadge.textContent = badgeText;
  if (tabBadge) tabBadge.textContent = badgeText;
  if (prAppBadge) prAppBadge.textContent = badgeText;
  if (pending) pending.textContent = n;
  // Admin dashboard overview card
  const dashCount = document.getElementById('dashPendingPRCount');
  const dashHint = document.getElementById('dashPendingPRHint');
  if (dashCount) dashCount.textContent = n;
  if (dashHint) {
    const pendingLabel = dashHint.dataset.pendingLabel || 'Needs Action';
    const noneLabel = dashHint.dataset.noneLabel || 'All PRs Processed';
    dashHint.className = 'stat-card-change ' + (n > 0 ? 'down' : 'up');
    dashHint.innerHTML = n > 0
      ? `<i class="bi bi-clock-history"></i> ${n} ${esc(pendingLabel)}`
      : `<i class="bi bi-check-circle"></i> ${esc(noneLabel)}`;
  }
  if (typeof window.repaintSidebarNotificationBadges === 'function') {
    window.repaintSidebarNotificationBadges();
  }
}

// ── Helpers ─────────────────────────────────────────────────
function pApi(params, cb) {
  const parseJsonSafe = text => {
    let cleanText = (text || '').trim();
    try { return JSON.parse(cleanText); } catch(e) {}
    const firstBrace = cleanText.indexOf('{');
    const lastBrace  = cleanText.lastIndexOf('}');
    if (firstBrace !== -1 && lastBrace > firstBrace) {
      try { return JSON.parse(cleanText.substring(firstBrace, lastBrace + 1)); } catch(e) {}
    }
    return null;
  };

  if (params.method === 'POST') {
    let bodyData;
    if (params.isFormData) {
      bodyData = params.body;
    } else {
      bodyData = new URLSearchParams(params.body);
    }
    
    const options = { method: 'POST', body: bodyData };
    fetch(PROC_API, options)
      .then(r => r.text())
      .then(text => {
        const data = parseJsonSafe(text);
        if (data) cb(data);
        else { console.error("Procurement API error:", text); cb({ success:false, message: text.length < 120 ? text : 'Server error occurred' }); }
      })
      .catch(err => { console.error("Procurement network error:", err); cb({ success:false, message:'Network error' }); });
  } else {
    const url = new URL(PROC_API, window.location.href);
    Object.entries(params.query || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    fetch(url, { cache: 'no-store' })
      .then(r => r.text())
      .then(text => {
        const data = parseJsonSafe(text);
        if (data) cb(data);
        else { console.error("Procurement API error:", text); cb({ success:false, message: text.length < 120 ? text : 'Server error occurred' }); }
      })
      .catch(err => { console.error("Procurement network error:", err); cb({ success:false, message:'Network error' }); });
  }
}

function phMobileError(value) {
  if (typeof window.clarenzonPhMobileError === 'function') return window.clarenzonPhMobileError(value);
  const phone = String(value || '').trim();
  if (!phone) return '';
  if (!/^09\d{9}$/.test(phone)) return 'Enter an 11-digit Philippine mobile number starting with 09, or leave the phone blank.';
  return '';
}

function pToast(msg, type='success') {
  const colors = { success:'#10b981', danger:'#ef4444', warn:'#f59e0b', info:'#7c3aed' };
  const el = document.createElement('div');
  el.className = 'clar-float-toast';
  el.style.cssText = `position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:30000;background:${colors[type]||colors.success};color:#fff;
    padding:12px 20px;border-radius:10px;font-size:13.5px;font-weight:500;font-family:'Inter',sans-serif;
    box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:min(340px, calc(100vw - 32px));text-align:center`;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3500);
}

function openModal(id)  { 
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('open');
  if (id === 'createPRModal' && el.getAttribute('data-pr-pos') === 'inline') {
    el.style.removeProperty('display');
    return;
  }
  if (el.classList.contains('modal-overlay')) {
    el.style.display = 'flex';
    if (id === 'prAddCustomerModal' || id === 'prAddCompanyModal' || id === 'prAddSupplierModal' || id === 'prAddCatalogModal') {
      el.style.zIndex = '7000';
    }
  }
  if (el.classList.contains('modal') && !el.classList.contains('modal-overlay')) {
    try {
      const bsModal = bootstrap.Modal.getOrCreateInstance(el);
      bsModal.show();
    } catch(e) {}
  }
}

function closeModal(id) { 
  const el = document.getElementById(id);
  if (id === 'createPRModal') {
    stopPRSelectLiveSync();
    if (typeof closeAllPRSupplierMenus === 'function') closeAllPRSupplierMenus();
    if (el && el.getAttribute('data-pr-pos') === 'inline') {
      if (!window._resettingInlinePR && typeof openCreatePR === 'function') {
        window._resettingInlinePR = true;
        try { openCreatePR(true); }
        finally { window._resettingInlinePR = false; }
      }
      return;
    }
  }
  if (id === 'createReceiptModal' && typeof stopReceiptBarcodeScanner === 'function') stopReceiptBarcodeScanner();
  if (id === 'createReceiptModal' && typeof clearActiveScanningTarget === 'function') clearActiveScanningTarget();
  if (id === 'createReceiptModal' && typeof setReceiptReplacementMode === 'function') setReceiptReplacementMode(false);
  if (el) {
    el.classList.remove('open');
    if (el.classList.contains('modal-overlay')) {
      el.style.display = 'none';
    }
    if (el.classList.contains('modal') && !el.classList.contains('modal-overlay')) {
      try {
        const bsModal = bootstrap.Modal.getInstance(el);
        if (bsModal) bsModal.hide();
      } catch(e) {}
    }
  }
}

document.addEventListener('click', e => { 
  if(e.target.classList.contains('modal-overlay')) {
    if (e.target.id === 'createPRModal' && e.target.getAttribute('data-pr-pos') === 'inline') return;
    if (e.target.id === 'createPRModal') stopPRSelectLiveSync();
    if (e.target.id === 'createReceiptModal' && typeof stopReceiptBarcodeScanner === 'function') stopReceiptBarcodeScanner();
    e.target.classList.remove('open');
  }
});

function esc(s) { if(!s) return ''; const d=document.createElement('div');d.textContent=s;return d.innerHTML; }
function fmtDate(d) { if(!d || d === '0000-00-00') return '—'; return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}); }
function fmtDateTime(d) {
  if (!d || d === '0000-00-00' || d === '0000-00-00 00:00:00') return '—';
  const s = String(d).trim().replace('T', ' ');
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/);
  if (!m) return fmtDate(d);
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const mon = months[(parseInt(m[2], 10) || 1) - 1] || m[2];
  let out = mon + ' ' + (parseInt(m[3], 10) || m[3]) + ', ' + m[1];
  if (m[4] != null && m[4] !== '') {
    let h = parseInt(m[4], 10);
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    out += ', ' + h + ':' + m[5] + ' ' + ap;
  }
  return out;
}
function fmtMoney(v) { return '₱'+parseFloat(v||0).toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2}); }

function currentProcurementRole() {
  return String(window.currentRoleKey || window.CLAR_AUTH_CONTEXT || '')
    .toLowerCase()
    .trim()
    .replace(/[\s\-\/]+/g, '_');
}
function isProcurementAdmin() {
  const r = currentProcurementRole();
  return r === 'admin' || r === 'administrator' || r === 'system_administrator';
}
function isProcurementWarehouseHead() {
  const r = currentProcurementRole();
  return window.isWarehouseHeadRole === true
    || r === 'warehouse_head';
}
function isProcurementPurchaser() {
  return currentProcurementRole() === 'purchaser';
}
function canApprovePurchaseRequests() {
  // Admin only — Warehouse Head must never approve PR/PO
  return isProcurementAdmin() && !isProcurementWarehouseHead() && window.canApprovePOs !== false;
}
function canReceivePurchaseOrders() {
  const r = currentProcurementRole();
  // Warehouse Head receives deliveries; Admin may override. Purchaser does not receive.
  return ['admin', 'administrator', 'system_administrator', 'warehouse_head'].includes(r);
}
function canSetReceiptMarkup() {
  if (!isProcurementPurchaser()) return false;
  if (typeof window.CLAR_CAN_MANAGE_RECEIPT_PRICING === 'boolean') {
    return window.CLAR_CAN_MANAGE_RECEIPT_PRICING;
  }
  if (typeof window.CLAR_CAN_MANAGE_PRICING === 'boolean') {
    return window.CLAR_CAN_MANAGE_PRICING;
  }
  return false;
}
function canGrantStockIn() {
  return false;
}
function canExecuteStockIn() {
  return isProcurementWarehouseHead();
}
function canUpdatePurchaseOrders() {
  // Purchaser/Admin status updates only — not Warehouse Head
  return (isProcurementAdmin() || isProcurementPurchaser()) && !isProcurementWarehouseHead();
}
function canPrintPurchaseOrders() {
  return isProcurementPurchaser();
}
function canCreatePurchaseRequest() {
  return (isProcurementPurchaser() || isProcurementAdmin()) && !isProcurementWarehouseHead();
}
function printDocumentFromHint() {
  const section = document.querySelector('.content-section.active')?.id || '';
  if (section === 'section-purchase-requests') {
    if (document.getElementById('pr-tab-btn-approval')?.classList.contains('active')) return 'pr-approval';
    if (document.getElementById('pr-tab-btn-invoices')?.classList.contains('active')) return 'supplier-invoices';
    return 'purchase-requests';
  }
  if (section === 'section-purchase-orders') return 'purchase-orders';
  if (section === 'section-incoming-deliveries') return 'incoming-deliveries';
  if (typeof isProcurementAdmin === 'function' && isProcurementAdmin()) return 'pr-approval';
  return 'purchase-orders';
}
function printSupplierReceiptUrl(receiptId) {
  const id = parseInt(receiptId, 10) || 0;
  return id > 0 ? ('print_supplier_receipt.php?receipt_id=' + id) : '';
}

function printPoOrReceivingUrl(p, from) {
  const receiptId = parseInt(p?.receiving_receipt_id ?? p?.latest_receipt_id ?? 0, 10) || 0;
  if (receiptId > 0) return printSupplierReceiptUrl(receiptId);
  return printPoUrl(p?.id ?? p, from);
}

function printPoUrl(poId, from) {
  const params = new URLSearchParams();
  params.set('po_id', String(parseInt(poId, 10) || 0));
  const src = String(from || printDocumentFromHint() || '').trim();
  if (src) params.set('from', src);
  const ctx = String(window.currentRoleKey || window.CLAR_AUTH_CONTEXT || '').trim();
  if (ctx) params.set('auth_ctx', ctx);
  return 'print_po.php?' + params.toString();
}
function purchaserCanPrintPO() {
  return isProcurementPurchaser();
}

function isPendingPurchaserMarkup(status) {
  return ['Received - Pending Markup', 'Pending Markup', 'Pending Stock-In', 'Pending for Stock-In'].includes(String(status || ''));
}

function isReadyForWarehouseStockIn(status) {
  return ['Approved - Pending Stock-In', 'Granted - Pending Stock-In', 'Markup Ready - Pending Admin Approval', 'Ready for Stock-In'].includes(String(status || ''));
}

function prStatusBadge(s) {
  const map = {
    'Draft':            'badge-gray',
    'Requested':        'badge-gray',
    'Pending Approval': 'badge-yellow',
    'Approved':         'badge-green',
    'Rejected':         'badge-red',
    'PO Created':       'badge-blue',
    'Ordered':          'badge-cyan',
    'Received':         'badge-orange',
    'Stocked In':       'badge-green'
  };
  return `<span class="badge ${map[s]||'badge-gray'}">${esc(s||'')}</span>`;
}

function receivingStatusBadge(s, p) {
  if (p && isPartialReceivedDisplay(p)) {
    return partialReceivedStatusBadge(p);
  }
  const labels = {
    'Expected to Arrive': 'For Receiving',
    'Received': 'Received',
    'Pending for Stock-In': 'Pending Purchaser Markup',
    'Pending Markup': 'Pending Purchaser Markup',
    'Pending Stock-In': 'Pending Purchaser Markup',
    'Received - Pending Markup': 'Waiting for Pricing',
    'Waiting for Pricing': 'Waiting for Pricing',
    'Ready for Stock-In': 'Ready for Stock In',
    'Pending Admin Approval': 'Ready for Stock In',
    'Markup Ready - Pending Admin Approval': 'Ready for Stock In',
    'Approved - Pending Stock-In': 'Ready for Stock In',
    'Granted - Pending Stock-In': 'Ready for Stock In',
    'Pending - Incomplete Delivery': 'Pending - Incomplete Delivery',
    'Pending – Incomplete Delivery': 'Pending - Incomplete Delivery',
    'Stocked In': 'Stocked'
  };
  const map = {
    'Expected to Arrive': 'badge-orange',
    'Received': 'badge-blue',
    'Pending for Stock-In': 'badge-yellow',
    'Pending Markup': 'badge-yellow',
    'Pending Stock-In': 'badge-yellow',
    'Received - Pending Markup': 'badge-yellow',
    'Ready for Stock-In': 'badge-cyan',
    'Pending Admin Approval': 'badge-cyan',
    'Markup Ready - Pending Admin Approval': 'badge-cyan',
    'Approved - Pending Stock-In': 'badge-violet',
    'Granted - Pending Stock-In': 'badge-violet',
    'Pending - Incomplete Delivery': 'badge-yellow',
    'Pending – Incomplete Delivery': 'badge-yellow',
    'Stocked In': 'badge-green'
  };
  const label = labels[s] || s || 'For Receiving';
  return `<span class="badge ${map[s]||'badge-gray'}">${esc(label)}</span>`;
}

function deliveryStatusBadge(s, p) {
  if (p && isPartialReceivedDisplay(p)) {
    return partialReceivedStatusBadge(p);
  }
  const map = {
    'Approved': 'badge-blue',
    'In Transit': 'badge-cyan',
    'Expected': 'badge-orange',
    'Partially Received': 'badge-yellow',
    'Pending - Incomplete Delivery': 'badge-yellow',
    'Pending – Incomplete Delivery': 'badge-yellow',
    'Received': 'badge-blue',
    'For Inspection': 'badge-violet',
    'Completed': 'badge-green'
  };
  return `<span class="badge ${map[s]||'badge-gray'}">${esc(s || 'Expected')}</span>`;
}

function poStatusBadge(s, p) {
  if (p && isPartialReceivedDisplay(p)) {
    return partialReceivedStatusBadge(p);
  }
  const map = {
    'Draft':               'badge-gray',
    'Submitted':           'badge-yellow',
    'Pending Approval':    'badge-yellow',
    'For Approval':        'badge-yellow',
    'Approved':            'badge-blue',
    'Sent':                'badge-cyan',
    'Sent to Supplier':    'badge-cyan',
    'Supplier Confirmed':  'badge-violet',
    'Waiting for Delivery':'badge-orange',
    'Partially Received':  'badge-orange',
    'Receiving':           'badge-orange',
    'Received':            'badge-green',
    'Completed':           'badge-green',
    'Cancelled':           'badge-red',
    'Rejected':            'badge-red',
  };
  const label = (p && p.lifecycle_status) ? p.lifecycle_status : s;
  return `<span class="badge ${map[label]||map[s]||'badge-gray'}">${esc(label || s || '—')}</span>`;
}

function invoiceStatusBadge(s) {
  const map = { 'Unpaid':'badge-red', 'Partially Paid':'badge-yellow', 'Paid':'badge-green' };
  return `<span class="badge ${map[s]||'badge-gray'}">${s}</span>`;
}

function supplierStatusBadge(s) {
  const map = { Active:'badge-green', Inactive:'badge-gray', Blacklisted:'badge-red' };
  return `<span class="badge ${map[s]||'badge-gray'}">${s}</span>`;
}

function ratingStars(r) {
  const v = parseFloat(r||0);
  const full = Math.floor(v);
  const half = v - full >= 0.5 ? 1 : 0;
  const empty = 5 - full - half;
  return '<span class="stars">' +
    '<i class="bi bi-star-fill"></i>'.repeat(full) +
    (half ? '<i class="bi bi-star-half"></i>' : '') +
    '<i class="bi bi-star"></i>'.repeat(empty) +
    `</span> <span style="font-size:12px;color:#64748b">${v.toFixed(2)}</span>`;
}

// ── Load Dropdowns ───────────────────────────────────────────
function loadDropdowns(cb) {
  let pending = 2;
  const done = () => {
    pending -= 1;
    if (pending <= 0 && typeof cb === 'function') cb();
  };
  pApi({ query:{ action:'get_suppliers_dropdown' } }, r => {
    _poSuppliers = r.data || [];
    const selects = ['prSupplierSelect', 'prReviewSupplierSelect', 'poSupplierSelect', 'supplierModalSelect', 'perfSupplierSelect', 'invSupplierSelect'];
    selects.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      if (id === 'prSupplierSelect' && el.tagName === 'INPUT') {
        const keep = String(_prPreferredSupplierId || el.value || '');
        bindPRSupplierCombo();
        bindPRCustomerOrderCombo();
        if (keep) setPRPreferredSupplier(keep, { silent: true, preserveWarn: true });
        else syncPRSupplierComboDisplay();
        return;
      }
      const prev = (id === 'prSupplierSelect' || id === 'prReviewSupplierSelect') ? String(el.value || '') : '';
      el.innerHTML = '<option value="">-- Select Supplier (Optional) --</option>' +
        _poSuppliers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
      if (prev && [...el.options].some(o => String(o.value) === prev)) el.value = prev;
    });
    done();
  });
  pApi({ query:{ action:'get_products_for_po' } }, r => {
    _poProducts = r.data || [];
    done();
  });
}

// ── DASHBOARD ───────────────────────────────────────────────
function loadDashboard() {
  loadDashRecentPOs();
  loadDashSuggestions();
  loadDashKPIs();
  loadIncomingDeliveries(true);
}

function loadDashKPIs() {
  pApi({ query:{ action:'get_purchase_requests', status:'Pending Approval' } }, r => {
    applyPRApprovalCount((r.data || []).length);
  });

  pApi({ query:{ action:'get_purchase_orders', status:'all' } }, r => {
    const pos = r.data || [];
    const openStatuses = ['Draft', 'Pending Approval', 'Approved', 'Sent', 'Supplier Confirmed', 'Waiting for Delivery', 'Partially Received', 'Submitted'];
    const open = pos.filter(p => openStatuses.includes(p.status)).length;
    const received = pos.filter(p => p.status === 'Received').length;
    const openEl = document.getElementById('kpiOpenPOs');
    const receivedEl = document.getElementById('kpiReceivedPOs');
    if (openEl) openEl.textContent = open;
    if (receivedEl) receivedEl.textContent = received;
    const incomingCount = pos.filter(p => p.is_incoming).length;
    if (typeof setIncomingDeliveryCount === 'function') setIncomingDeliveryCount(incomingCount);
  });

  pApi({ query:{ action:'get_suppliers' } }, r => {
    const active = (r.data || []).filter(s => s.status === 'Active').length;
    const suppliersEl = document.getElementById('kpiSuppliersCount');
    if (suppliersEl) suppliersEl.textContent = active;
  });
}

function loadDashRecentPOs(silent) {
  const el = document.getElementById('dashRecentPOs');
  if (!el) return;
  if (!silent) {
    el.innerHTML = '<tr class="loading-row"><td colspan="5" class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading recent POs...</td></tr>';
  }
  pApi({ query:{ action:'get_purchase_orders', status:'all' } }, r => {
    if (!r.success) return;
    const data = (r.data || []).slice(0, 6);
    const sig = liveRowsSignature(data, ['id', 'status', 'receiving_status', 'total_amount', 'po_number']);
    if (silent && sig === _dashPoSig) return;
    _dashPoSig = sig;
    if (!data.length) { el.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No purchase orders found</td></tr>'; return; }
    el.innerHTML = data.map(p => `<tr>
      <td><strong style="font-family:monospace" class="text-primary">${esc(p.po_number)}</strong></td>
      <td>${esc(p.supplier_name||'—')}</td>
      <td class="fw-bold">${fmtMoney(p.total_amount)}</td>
      <td>${poStatusBadge(p.status, p)}</td>
      <td><button class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:12px;" onclick="viewPO(${p.id})">Details</button></td>
    </tr>`).join('');
  });
}

function loadDashSuggestions() {
  const el = document.getElementById('dashSuggestions');
  const sc = document.getElementById('suggestCount');
  if (!el) return;
  el.innerHTML = '<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Checking reorder suggestions...</div>';
  pApi({ query:{ action:'get_reorder_suggestions' } }, r => {
    const data = r.data || [];
    if (sc) sc.textContent = data.length;
    if (!data.length) { el.innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;font-size:13px"><i class="bi bi-check-circle-fill" style="color:#10b981;font-size:24px;display:block;margin-bottom:8px"></i>No reorder needed right now!</div>'; return; }
    el.innerHTML = data.slice(0,5).map(p => `
      <div class="suggest-card p-2 mb-2 border rounded d-flex justify-content-between align-items-center" style="background:#f8fafc">
        <div>
          <h6 class="mb-1 fw-bold" style="font-size:13px">${esc(p.name)} <span class="badge badge-orange">Low Stock</span></h6>
          <p class="mb-0 text-muted" style="font-size:11px">Stock: <strong class="text-danger">${p.quantity_in_stock}</strong> / Reorder: <strong>${p.reorder_level}</strong> · Unit: ${esc(p.unit_of_measure||'—')} · Cost: ${fmtMoney(p.cost_price)}${p.supplier_name ? '' : ' · <span style="color:#b45309">No supplier</span>'}</p>
        </div>
        <button class="btn btn-sm btn-primary py-1 px-2" style="font-size:11px" onclick="openCreatePRFromSuggestion(${p.id}, '${esc(p.name)}', ${p.supplier_id||0}, ${p.suggested_quantity||p.reorder_quantity}, ${p.cost_price})">
          <i class="bi bi-file-earmark-plus me-1"></i> Request PR
        </button>
      </div>`).join('');
  });
}

function setIncomingDeliveryCount(n) {
  n = parseInt(n, 10) || 0;
  document.querySelectorAll('#incomingDeliveriesBadge, .incoming-deliveries-badge').forEach(badge => {
    badge.textContent = n > 0 ? String(n) : '';
  });
  document.querySelectorAll('#kpiIncomingDeliveries, .kpi-incoming-deliveries').forEach(kpi => {
    kpi.textContent = String(n);
  });
  document.querySelectorAll('#incomingDeliveriesCountLabel, .incoming-deliveries-count-label').forEach(label => {
    label.textContent = 'Incoming Deliveries: ' + n;
  });
  const hint = document.getElementById('kpiIncomingDeliveriesHint');
  if (hint) {
    hint.innerHTML = n > 0
      ? `<i class="bi bi-clock-history"></i> ${n} waiting for delivery`
      : '<i class="bi bi-check-circle"></i> None pending';
    hint.classList.toggle('down', n > 0);
    hint.classList.toggle('up', n <= 0);
  }
}

function incomingDeliveriesSignature(rows) {
  return (rows || []).map(p => {
    const lines = (p.ordered_item_lines || []).map(l => [l.po_item_id || '', l.product_name || '', l.quantity_ordered || '', l.quantity_pending_replacement || '', l.note_to_supplier || ''].join('~')).join(',');
    return [p.id, p.incoming_status || incomingDeliveryStatusLabel(p), p.has_pending_replacement || 0, p.has_damaged_receiving || 0, p.note_to_supplier || '', p.delivery_status, p.status, p.receiving_status, p.receipt_workflow_status, p.qty_received, p.qty_ordered, p.qty_remaining, p.qty_damage_missing, p.expected_delivery_date, lines].join(':');
  }).join('|');
}

function incomingPOIsIncomplete(p) {
  return poRemainingQty(p) > 0 && incomingDeliveryWasInspected(p);
}

function incomingDeliveryWasInspected(p) {
  if ((parseInt(p?.latest_receipt_id, 10) || 0) > 0) return true;
  if (String(p?.receipt_workflow_status || '').trim() !== '') return true;
  const received = parseInt(p?.qty_processed ?? p?.qty_received ?? p?.received_boxes, 10) || 0;
  if (received > 0) return true;
  const poStatus = String(p?.status || '').trim();
  return poStatus === 'Received' || poStatus === 'Partially Received';
}

function incomingPoHasIssue(p) {
  if ((parseInt(p?.qty_damage_missing, 10) || 0) > 0) return true;
  const flag = p?.has_receiving_issue;
  return flag === true || flag === 1 || flag === '1';
}

function incomingDeliveryNeedsReplacement(p) {
  if ((parseInt(p?.has_pending_replacement, 10) || 0) > 0) return true;
  if (!incomingDeliveryWasInspected(p)) return false;
  if ((parseInt(p?.qty_damage_missing, 10) || 0) > 0) return true;
  if ((parseInt(p?.has_damaged_receiving, 10) || 0) > 0) return true;
  if (incomingPoHasIssue(p)) return true;
  return (p?.ordered_item_lines || []).some(l => (parseInt(l.quantity_damage_missing, 10) || 0) > 0);
}

function incomingDamageMissingRemain(p) {
  if ((parseInt(p?.qty_damage_missing, 10) || 0) > 0) return true;
  if ((parseInt(p?.has_pending_replacement, 10) || 0) > 0) return true;
  if ((parseInt(p?.has_damaged_receiving, 10) || 0) > 0) return true;
  return (p?.ordered_item_lines || []).some(l =>
    (parseInt(l.quantity_damage_missing, 10) || 0) > 0 ||
    (parseInt(l.quantity_pending_replacement, 10) || 0) > 0
  );
}

function incomingAwaitingToReceived(p) {
  if ((parseInt(p?.has_pending_replacement, 10) || 0) > 0) return true;
  if ((parseInt(p?.pending_replacement_qty, 10) || 0) > 0) return true;
  return (p?.ordered_item_lines || []).some(l => (parseInt(l.quantity_pending_replacement, 10) || 0) > 0);
}

function incomingDeliveryStatusLabel(p) {
  if (incomingAwaitingToReceived(p) && incomingDeliveryWasInspected(p)) return 'To Received';
  if (incomingDamageMissingRemain(p)) return 'Partially Received';
  const good = parseInt(p?.qty_good, 10);
  const received = Number.isFinite(good)
    ? good
    : (parseInt(p?.qty_processed ?? p?.qty_received ?? p?.received_boxes, 10) || 0);
  if (received <= 0) return 'Pending';
  const incomingStatus = String(p?.incoming_status || '').trim();
  if (incomingStatus && incomingStatus !== 'Pending' && incomingStatus !== 'For Replacement' && incomingStatus !== 'To Received') return incomingStatus;
  return 'Received';
}

function incomingDeliveryStatusBadge(p) {
  const label = incomingDeliveryStatusLabel(p);
  const map = {
    'Pending': 'badge-yellow',
    'For Replacement': 'badge-orange',
    'To Received': 'badge-orange',
    'Received': 'badge-green',
    'Partially Received': 'badge-yellow',
    'Received with Issue': 'badge-orange',
    'Completed': 'badge-green'
  };
  return `<span class="badge ${map[label] || 'badge-gray'} incoming-status-badge">${esc(label)}</span>`;
}

function poRemainingQty(p) {
  const remaining = parseInt(p?.qty_remaining, 10);
  if (Number.isFinite(remaining)) return Math.max(0, remaining);
  const boxes = parseInt(p?.remaining_boxes, 10);
  if (Number.isFinite(boxes)) return Math.max(0, boxes);
  const ordered = parseInt(p?.qty_ordered ?? p?.ordered_boxes, 10) || 0;
  const processed = parseInt(p?.qty_processed ?? p?.qty_received ?? p?.received_boxes, 10) || 0;
  return Math.max(0, ordered - processed);
}

function isPartialReceivedDisplay(p) {
  if (!p) return false;
  return poRemainingQty(p) > 0 && incomingDeliveryWasInspected(p);
}

function partialReceivedStatusText(p) {
  const pending = poRemainingQty(p);
  const stocked = parseInt(p && p.qty_stocked, 10) || 0;
  const text = 'Partially Received – ' + pending + ' Pending';
  return stocked > 0 ? text + ' | ' + stocked + ' Stocked' : text;
}

function partialReceivedStatusBadge(p) {
  return `<span class="badge badge-orange">${esc(partialReceivedStatusText(p))}</span>`;
}

function incomingPOCanReceive(p) {
  if (!(typeof canReceivePurchaseOrders === 'function' && canReceivePurchaseOrders())) return false;
  if (poRemainingQty(p) <= 0) return false;
  if (['Stocked In'].includes(p.receiving_status || '') && poRemainingQty(p) <= 0) return false;
  if (incomingDeliveryWasInspected(p)) return true;
  return ['Submitted','Approved','Sent','Supplier Confirmed','Waiting for Delivery','Partially Received'].includes(p.status);
}

function incomingCatalogUnit(raw) {
  const unit = String(raw || '').trim();
  return unit || 'pcs';
}

function poViewQtyWithUnit(qty, unit) {
  if (qty === '' || qty == null) return '—';
  const n = parseInt(qty, 10);
  if (!Number.isFinite(n)) return '—';
  return `<strong>${n}</strong> <span class="po-qty-unit">${esc(incomingCatalogUnit(unit))}</span>`;
}

function incomingOrderedItemLines(p) {
  if (Array.isArray(p?.ordered_item_lines) && p.ordered_item_lines.length) {
    return p.ordered_item_lines;
  }
  const names = String(p?.ordered_items || '').split(',').map(s => s.trim()).filter(Boolean);
  if (names.length) {
    return names.map(name => ({
      product_name: name,
      brand: '',
      sku: '',
      quantity_ordered: '',
      unit_of_measure: ''
    }));
  }
  return [];
}

function incomingOrderedItemsHtml(p) {
  const lines = incomingOrderedItemLines(p);
  if (!lines.length) {
    return `<div class="incoming-item-block"><div class="incoming-item-name">${esc((p.item_count || 0) + ' item(s)')}</div></div>`;
  }
  return lines.map(line => {
    const brand = String(line.brand || '').trim() || '—';
    const sku = String(line.sku || '').trim() || '—';
    const note = String(line.note_to_supplier || '').trim();
    return `<div class="incoming-item-block">
      <div class="incoming-item-name">${esc(line.product_name || '—')}</div>
      <div class="incoming-item-brand">${esc(brand)}</div>
      <div class="incoming-item-sku">SKU: ${esc(sku)}</div>
      ${(parseInt(line.quantity_pending_replacement, 10) || 0) > 0 ? `<div class="incoming-item-note">To Received: ${parseInt(line.quantity_pending_replacement, 10)}</div>` : ''}
      ${note ? `<div class="incoming-item-note">Note to Supplier: ${esc(note)}</div>` : ''}
    </div>`;
  }).join('');
}

function incomingOrderedQtyHtml(p) {
  const lines = incomingOrderedItemLines(p);
  if (lines.length) {
    return lines.map(line => {
      const qty = parseInt(line.quantity_ordered, 10);
      const qtyLabel = Number.isFinite(qty) ? String(qty) : '—';
      return `<div class="incoming-item-block incoming-qty-block">
        <div class="incoming-qty-line"><span class="incoming-qty-value">${esc(qtyLabel)}</span> <span class="incoming-qty-unit">${esc(incomingCatalogUnit(line.unit_of_measure))}</span></div>
      </div>`;
    }).join('');
  }
  const boxes = parseInt(p.ordered_boxes != null ? p.ordered_boxes : p.qty_ordered, 10) || 0;
  return `<div class="incoming-item-block incoming-qty-block"><div class="incoming-qty-line"><span class="incoming-qty-value">${boxes}</span> <span class="incoming-qty-unit">pcs</span></div></div>`;
}

function incomingAlignItemQtyBlocks(scope) {
  const roots = scope ? [scope] : [
    document.getElementById('incomingDeliveriesBody'),
    document.getElementById('dashIncomingDeliveries')
  ].filter(Boolean);
  roots.forEach(root => {
    root.querySelectorAll('tr').forEach(tr => {
      const items = [...tr.querySelectorAll('.incoming-items-cell .incoming-item-block')];
      const qtys = [...tr.querySelectorAll('.incoming-qty-cell .incoming-item-block')];
      const n = Math.min(items.length, qtys.length);
      for (let i = 0; i < n; i++) {
        items[i].style.minHeight = '';
        qtys[i].style.minHeight = '';
      }
      for (let i = 0; i < n; i++) {
        const h = Math.max(items[i].offsetHeight, qtys[i].offsetHeight);
        items[i].style.minHeight = h + 'px';
        qtys[i].style.minHeight = h + 'px';
      }
    });
  });
}

function bindIncomingAlignResize() {
  if (window._incomingAlignBound) return;
  window._incomingAlignBound = true;
  window.addEventListener('resize', () => incomingAlignItemQtyBlocks());
}

function incomingPORowHtml(p, { compact = false } = {}) {
  const canAct = typeof canReceivePurchaseOrders === 'function' && canReceivePurchaseOrders();
  const receiveBtn = (canAct && !incomingDamageMissingRemain(p) && incomingPOCanReceive(p))
    ? `<button class="btn btn-sm btn-success" onclick="event.stopPropagation();openCreateReceiptModal(${p.id})"><i class="bi bi-box-arrow-in-down me-1"></i> Inspect & Receive</button>`
    : '';
  const replBtn = (canAct && incomingDamageMissingRemain(p))
    ? `<button class="btn btn-sm btn-success" onclick="event.stopPropagation();openReceiveReplacementModal(${p.id})">Receive</button>`
    : '';
  const hasDamaged = (parseInt(p.has_damaged_receiving, 10) || 0) > 0
    || (parseInt(p.has_pending_replacement, 10) || 0) > 0
    || (p.ordered_item_lines || []).some(l => (parseInt(l.quantity_damage_missing, 10) || 0) > 0 || String(l.note_to_supplier || '').trim() !== '');
  const noteBtn = (!compact && typeof canReceivePurchaseOrders === 'function' && canReceivePurchaseOrders() && hasDamaged)
    ? `<button class="btn-icon" title="Note to Supplier" onclick="event.stopPropagation();openNoteToSupplierModal(${p.id})"><i class="bi bi-chat-left-text"></i></button>`
    : '';
  return `<tr role="button" style="cursor:pointer" onclick="viewPO(${p.id})" title="Open PO details">
    <td class="incoming-po-cell"><strong class="incoming-po-number">${esc(p.po_number)}</strong>${p.pr_number ? `<div class="incoming-item-sku">PR: ${esc(p.pr_number)}</div>` : ''}</td>
    <td class="incoming-supplier-cell">${esc(p.supplier_name||'—')}</td>
    <td class="incoming-items-cell">${incomingOrderedItemsHtml(p)}</td>
    <td class="incoming-qty-cell">${incomingOrderedQtyHtml(p)}</td>
    <td class="incoming-date-cell">${fmtDate(p.expected_delivery_date)}</td>
    <td class="incoming-date-cell">${fmtDate(p.order_date || p.created_at)}</td>
    <td class="incoming-status-cell">${incomingDeliveryStatusBadge(p)}</td>
    ${compact ? '' : `<td class="incoming-actions-cell" onclick="event.stopPropagation()"><button class="btn-icon primary" title="View" onclick="viewPO(${p.id})"><i class="bi bi-eye"></i></button> ${noteBtn} ${receiveBtn} ${replBtn}</td>`}
  </tr>`;
}

function openNoteToSupplierModal(poId) {
  poId = parseInt(poId, 10) || 0;
  if (!poId) return;
  if (typeof canReceivePurchaseOrders === 'function' && !canReceivePurchaseOrders()) {
    pToast('Only Warehouse Head can add a Note to Supplier.', 'warn');
    return;
  }
  const body = document.getElementById('noteToSupplierBody');
  const saveBtn = document.getElementById('noteToSupplierSaveBtn');
  if (!body || !document.getElementById('noteToSupplierModal')) {
    pToast('Note to Supplier form is not available on this page.', 'danger');
    return;
  }
  body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm me-2"></div> Loading...</div>';
  if (saveBtn) saveBtn.disabled = true;
  openModal('noteToSupplierModal');
  pApi({ query: { action: 'get_note_to_supplier', po_id: poId } }, res => {
    if (!res.success || !res.data) {
      body.innerHTML = `<div class="text-danger">${esc(res.message || 'Unable to load damaged item.')}</div>`;
      return;
    }
    const d = res.data;
    const items = Array.isArray(d.items) ? d.items : [];
    if (!items.length) {
      body.innerHTML = '<div class="text-muted">No damaged item was found on this incoming delivery.</div>';
      return;
    }
    body.innerHTML = items.map((it, idx) => {
      const qty = parseInt(it.damaged_qty, 10) || 0;
      return `<div class="nts-item" data-receipt-item-id="${parseInt(it.receipt_item_id, 10) || 0}">
        ${idx > 0 ? '<hr class="my-3">' : ''}
        <div class="nts-meta">
          <div><span>Product</span><strong>${esc(it.product_name || '—')}</strong></div>
          <div><span>PO Number</span><strong>${esc(d.po_number || it.po_number || '—')}</strong></div>
          <div><span>Supplier</span><strong>${esc(d.supplier_name || it.supplier_name || '—')}</strong></div>
          <div><span>Damaged Quantity</span><strong>${qty}</strong></div>
        </div>
        <label class="form-label mt-3 mb-1" for="ntsReason${idx}">Reason / Note</label>
        <textarea class="form-control nts-reason" id="ntsReason${idx}" rows="3" maxlength="500" placeholder="e.g. For Replacement">${esc(it.note_to_supplier || '')}</textarea>
      </div>`;
    }).join('');
    if (saveBtn) saveBtn.disabled = false;
  });
}

function saveNoteToSupplier() {
  const body = document.getElementById('noteToSupplierBody');
  const saveBtn = document.getElementById('noteToSupplierSaveBtn');
  if (!body) return;
  const blocks = [...body.querySelectorAll('.nts-item')];
  const notes = [];
  for (const block of blocks) {
    const receiptItemId = parseInt(block.getAttribute('data-receipt-item-id'), 10) || 0;
    const note = String(block.querySelector('.nts-reason')?.value || '').trim();
    if (receiptItemId <= 0) continue;
    if (!note) {
      pToast('Enter a reason / note for the supplier.', 'warn');
      block.querySelector('.nts-reason')?.focus();
      return;
    }
    notes.push({ receipt_item_id: receiptItemId, note });
  }
  if (!notes.length) {
    pToast('No damaged receiving record to update.', 'warn');
    return;
  }
  if (saveBtn) saveBtn.disabled = true;
  pApi({ method: 'POST', body: { action: 'save_note_to_supplier', notes: JSON.stringify(notes) } }, res => {
    if (saveBtn) saveBtn.disabled = false;
    if (!res.success) {
      pToast(res.message || 'Unable to save the note.', 'danger');
      return;
    }
    pToast(res.message || 'Note to Supplier saved.');
    closeModal('noteToSupplierModal');
    if (typeof loadIncomingDeliveries === 'function') loadIncomingDeliveries();
  });
}

function incomingPODateValue(p) {
  const raw = p && (p.order_date || p.created_at) ? String(p.order_date || p.created_at) : '';
  const t = Date.parse(raw);
  return Number.isNaN(t) ? 0 : t;
}

function renderIncomingDeliveries(rows) {
  const listBody = document.getElementById('incomingDeliveriesBody');
  const dashBody = document.getElementById('dashIncomingDeliveries');
  rows = (rows || []).slice().sort((a, b) => incomingPODateValue(b) - incomingPODateValue(a));
  const dates = incomingDeliveriesDateFilter();
  const emptyMsg = (dates.from || dates.to)
    ? 'No incoming deliveries match the selected Expected Delivery Date range.'
    : 'No incoming purchase orders waiting for delivery.';
  const emptyList = `<tr><td colspan="8" style="text-align:center;padding:32px;color:#64748b">${emptyMsg}</td></tr>`;
  const emptyDash = '<tr><td colspan="7" class="text-center py-4 text-muted">No incoming deliveries right now.</td></tr>';
  if (listBody) {
    listBody.innerHTML = rows.length ? rows.map(p => incomingPORowHtml(p)).join('') : emptyList;
  }
  if (dashBody) {
    const preview = rows.slice(0, 8);
    dashBody.innerHTML = preview.length ? preview.map(p => incomingPORowHtml(p, { compact: true })).join('') : emptyDash;
  }
  bindIncomingAlignResize();
  incomingAlignItemQtyBlocks();
}

function incomingDeliveriesDateFilter() {
  return {
    from: (document.getElementById('incomingDateFrom')?.value || '').trim(),
    to: (document.getElementById('incomingDateTo')?.value || '').trim()
  };
}

function syncIncomingDeliveriesDateBounds() {
  const fromEl = document.getElementById('incomingDateFrom');
  const toEl = document.getElementById('incomingDateTo');
  if (!fromEl || !toEl) return;
  if (fromEl.value) toEl.min = fromEl.value;
  else toEl.removeAttribute('min');
  if (toEl.value) fromEl.max = toEl.value;
  else fromEl.removeAttribute('max');
}

function incomingDeliveriesDateFilterValid(showToast) {
  const { from, to } = incomingDeliveriesDateFilter();
  if (from && to && to < from) {
    if (showToast) pToast('To Date cannot be earlier than From Date.', 'warn');
    return false;
  }
  return true;
}

function applyIncomingDeliveriesFilter() {
  if (!incomingDeliveriesDateFilterValid(true)) return;
  loadIncomingDeliveries();
}

function clearIncomingDeliveriesFilter() {
  const fromEl = document.getElementById('incomingDateFrom');
  const toEl = document.getElementById('incomingDateTo');
  if (fromEl) fromEl.value = '';
  if (toEl) toEl.value = '';
  syncIncomingDeliveriesDateBounds();
  loadIncomingDeliveries();
}

function loadIncomingDeliveries(silent) {
  const listBody = document.getElementById('incomingDeliveriesBody');
  const dashBody = document.getElementById('dashIncomingDeliveries');
  if (!listBody && !dashBody && !document.getElementById('kpiIncomingDeliveries') && !document.getElementById('incomingDeliveriesBadge')) {
    return;
  }
  const incomingActive = (typeof liveSectionId === 'function' ? liveSectionId() : '') === 'section-incoming-deliveries';
  if (incomingActive && !incomingDeliveriesDateFilterValid(!silent)) return;
  if (!silent && listBody && !listBody.querySelector('tr:not(.loading-row)')) {
    listBody.innerHTML = '<tr class="loading-row"><td colspan="8" style="text-align:center;padding:32px;color:#64748b"><span class="loader"></span> Loading incoming deliveries…</td></tr>';
  }
  const dates = incomingActive ? incomingDeliveriesDateFilter() : { from: '', to: '' };
  const query = { action: 'get_incoming_deliveries' };
  if (dates.from) query.date_from = dates.from;
  if (dates.to) query.date_to = dates.to;
  pApi({ query }, r => {
    if (!r.success) {
      if (!silent && listBody) listBody.innerHTML = `<tr><td colspan="8" style="color:red;padding:20px">${esc(r.message||'Failed to load incoming deliveries')}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    const sig = incomingDeliveriesSignature(rows) + '|' + (dates.from || '') + '|' + (dates.to || '');
    const totalCount = typeof r.count === 'number' ? r.count : rows.length;
    setIncomingDeliveryCount(totalCount);
    if (listBody && (dates.from || dates.to)) {
      const shown = typeof r.filtered_count === 'number' ? r.filtered_count : rows.length;
      document.querySelectorAll('#section-incoming-deliveries .incoming-deliveries-count-label').forEach(label => {
        label.textContent = 'Incoming Deliveries: ' + shown;
      });
    }
    const hasIncomingTable = (listBody && listBody.querySelector('tr:not(.loading-row)'))
      || (dashBody && dashBody.querySelector('tr'));
    if (silent && sig === _incomingSig && hasIncomingTable) {
      return;
    }
    _incomingSig = sig;
    renderIncomingDeliveries(rows);
  });
}

function liveExtractStamps(r) {
  return {
    pr_pending: String(r.pr_pending || ''),
    pr: String(r.pr || ''),
    po: String(r.po || ''),
    receipt: String(r.receipt || ''),
    inspect: String(r.inspect || ''),
    scan: String(r.scan || '')
  };
}

function liveStampsFingerprint(parts) {
  return ['pr_pending', 'pr', 'po', 'receipt', 'inspect', 'scan'].map(k => k + '=' + (parts[k] || '')).join('|');
}

function liveStampChanged(prev, next, key) {
  return (prev[key] || '') !== (next[key] || '');
}

function liveApplyChangedSections(prev, next) {
  const changed = (key) => liveStampChanged(prev, next, key);
  const section = liveSectionId();
  if (changed('po') || changed('receipt') || changed('inspect') || changed('scan')) {
    loadIncomingDeliveries(true);
  }
  if (changed('pr_pending') && typeof canApprovePurchaseRequests === 'function' && canApprovePurchaseRequests()
      && (document.getElementById('approvalBody') || document.getElementById('prAppBadge') || document.getElementById('kpiPendingApproval') || document.getElementById('approvalBadge') || document.getElementById('dashPendingPRCount'))) {
    loadPRApprovalQueue(true);
  }
  if (changed('pr') && !changed('pr_pending') && document.getElementById('dashPendingPRCount')) {
    // Purchaser dashboards do not get the admin-only pending stamp; recount on any PR change.
    pApi({ query: { action: 'get_purchase_requests', status: 'Pending Approval' } }, r => {
      if (r && r.success !== false) applyPRApprovalCount((r.data || []).length);
    });
  }
  const onPrSection = !section || section === 'section-purchase-requests';
  if (changed('pr') && onPrSection && document.getElementById('prBody') && (!document.getElementById('pr-tab-pane-requests') || livePaneActive('pr-tab-pane-requests')) && !liveModalOpen(['createPRModal', 'prDetailModal'])) {
    loadPurchaseRequests(true);
  }
  if ((changed('receipt') || changed('inspect') || changed('po')) && section === 'section-purchase-orders' && !liveModalOpen(['createPOModal', 'poDetailModal'])) {
    loadPurchaseOrders(true);
  }
  if ((changed('receipt') || changed('inspect')) && section === 'section-purchase-receipts' && !liveModalOpen(['createReceiptModal', 'receiptDetailModal'])) {
    loadPurchaseReceipts(true);
  }
  if ((changed('receipt') || changed('po')) && section === 'section-stock-in-queue' && !liveModalOpen(['stockInDetailModal'])) {
    loadStockInQueue(true);
  }
  if ((changed('receipt') || changed('po')) && section === 'section-markup-queue' && typeof loadPurchaserMarkupQueue === 'function') {
    loadPurchaserMarkupQueue(true);
  }
  if ((changed('pr_pending') || changed('po') || changed('inspect') || changed('receipt')) && (section === 'section-dashboard' || section === 'section-procurement')) {
    loadDashKPIs();
    if (changed('po') || changed('inspect') || changed('receipt')) loadDashRecentPOs(true);
  }
  if (changed('scan') && typeof dashLoadRecentScans === 'function') {
    dashLoadRecentScans();
  }
}

function liveRefreshTick() {
  if (document.hidden || liveScannerActive()) return;
  if (_liveCheckBusy) return;
  const reviewOpen = typeof isPRReviewModalOpen === 'function' && isPRReviewModalOpen();
  const reviewJustClosed = _liveReviewWasOpen && !reviewOpen;
  _liveReviewWasOpen = reviewOpen;
  _liveCheckBusy = true;
  pApi({ query: { action: 'get_live_changes' } }, r => {
    try {
      if (!r || r.success !== true) return;
      const next = liveExtractStamps(r);
      const fp = liveStampsFingerprint(next);
      if (_liveChangeStamp !== fp) {
        liveApplyChangedSections(_liveChangeParts, next);
        _liveChangeParts = next;
        _liveChangeStamp = fp;
      }
      if (reviewJustClosed && typeof canApprovePurchaseRequests === 'function' && canApprovePurchaseRequests()) {
        loadPRApprovalQueue(true);
      }
    } catch (err) {
      console.error('Live refresh skipped', err);
    } finally {
      _liveCheckBusy = false;
    }
  });
}

function liveModalOpen(ids) {
  return (ids || []).some(id => {
    const el = document.getElementById(id);
    if (!el) return false;
    if (id === 'createPRModal' && el.getAttribute('data-pr-pos') === 'inline') return false;
    return !!(el.classList.contains('open') || el.classList.contains('show') || el.style.display === 'flex');
  });
}

function startLiveDataRefresh() {
  if (_liveRefreshTimer) return;
  liveRefreshTick();
  _liveRefreshTimer = setInterval(liveRefreshTick, LIVE_REFRESH_MS);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) liveRefreshTick();
  });
}

function startIncomingDeliveriesPoll() {
  startLiveDataRefresh();
}

function openCreatePRFromSuggestion(productId, productName, supplierId, qty, price) {
  if (isCreatePRInline() && typeof showSection === 'function') {
    const sec = document.getElementById('section-purchase-requests');
    if (!sec || !sec.classList.contains('active')) {
      showSection('purchase-requests');
    } else if (typeof switchPRInvoiceTab === 'function') {
      const pane = document.getElementById('pr-tab-pane-requests');
      if (pane && !pane.classList.contains('active') && !pane.classList.contains('show')) {
        switchPRInvoiceTab('requests');
      }
    }
  }
  openCreatePR(false);
  const purposeSel = document.getElementById('prPurpose');
  if (purposeSel && !purposeSel.value) {
    purposeSel.value = 'Low Stock Replenishment';
    onPRPurposeChange();
  }
  const tryAdd = (attemptsLeft) => {
    if (findProductById(productId) || (_lowStockProducts || []).some(p => String(p.id) === String(productId))) {
      addPRItemFromProduct(productId);
      return;
    }
    if (attemptsLeft <= 0) {
      pToast('Could not load suggested product. Try again.', 'warn');
      return;
    }
    setTimeout(() => tryAdd(attemptsLeft - 1), 150);
  };
  setTimeout(() => tryAdd(25), 200);
}

// ── STEP 1: PURCHASE REQUESTS (PR) ─────────────────────────
function shouldUsePurposeListPOV() {
  return isCreatePRInline() && !!document.getElementById('prListTitle');
}

function getPRListPOV() {
  if (!shouldUsePurposeListPOV()) return 'all';
  const purpose = getCreatePRPurpose();
  if (purpose === 'Low Stock Replenishment') return 'low_stock';
  if (purpose === 'Customer Order') return 'customer_order';
  if (purpose === 'Others') return 'others';
  return 'all';
}

function prListColCount(pov) {
  const view = pov || getPRListPOV();
  if (view === 'customer_order') return 8;
  if (view === 'others') return 7;
  return 9;
}

function prListSearchQuery() {
  return String(document.getElementById('prSearch')?.value || '').trim().toLowerCase();
}

function prPovStockStatus(available, ordered) {
  const av = parseInt(available, 10) || 0;
  if (ordered == null || ordered === '') {
    return av > 0
      ? { label: 'Available', cls: 'badge-green' }
      : { label: 'Out of Stock', cls: 'badge-orange' };
  }
  const ord = parseInt(ordered, 10) || 0;
  if (av <= 0) return { label: 'Out of Stock', cls: 'badge-orange' };
  if (av < ord) return { label: 'Insufficient Stock', cls: 'badge-orange' };
  return { label: 'Available', cls: 'badge-green' };
}

function getPRListCard() {
  return document.getElementById('prListTitle')?.closest('.card') || null;
}

function syncPRListCardVisibility(pov) {
  const card = getPRListCard();
  if (!card) return;
  card.style.display = '';
}

function syncPRListChrome(pov) {
  const view = pov || getPRListPOV();
  syncPRListCardVisibility(view);
  const title = document.getElementById('prListTitle');
  const head = document.getElementById('prListHead');
  const status = document.getElementById('prStatusFilter');
  const search = document.getElementById('prSearch');
  if (!title && !head) return;
  if (title) {
    title.textContent = view === 'low_stock'
      ? 'Low Stock Purchase Requests'
      : view === 'customer_order'
        ? 'Customer Orders'
        : view === 'others'
          ? 'All Products'
          : 'All Purchase Requests';
  }
  if (status) status.style.display = (view === 'customer_order' || view === 'others') ? 'none' : '';
  if (search) {
    search.placeholder = view === 'customer_order'
      ? 'Search customer, PO, product...'
      : view === 'others'
        ? 'Search product, SKU, category...'
        : 'Search PR...';
  }
  if (head) {
    if (view === 'customer_order') {
      head.innerHTML = '<tr><th>Customer PO</th><th>Customer Name</th><th>Product</th><th>Ordered Qty</th><th>Available Qty</th><th>Unit</th><th>Required to Purchase</th><th>Status</th></tr>';
    } else if (view === 'others') {
      head.innerHTML = '<tr><th>Product</th><th>SKU</th><th>Current Stock</th><th>Unit</th><th>Category</th><th>Status</th><th></th></tr>';
    } else {
      head.innerHTML = '<tr><th>PR Number</th><th>Supplier</th><th>Purpose</th><th>Items</th><th>Total Amount</th><th>Status</th><th>Requested By</th><th>Created Date</th><th>Actions</th></tr>';
    }
  }
}

function prListEmptyRow(cols, message) {
  return `<tr><td colspan="${cols}" style="text-align:center;padding:32px;color:#64748b">${esc(message)}</td></tr>`;
}

function flattenPRListCustomerOrderRows() {
  const q = prListSearchQuery();
  const rows = [];
  (_customerOrdersForPR || []).forEach(order => {
    const po = String(order.customer_po_number || '').trim() || String(order.order_number || '').trim() || '—';
    const name = String(order.customer_name || '').trim() || '—';
    const items = (order.items || []).length ? order.items : [{ product_name: '—', ordered_qty: '', available_qty: '', unit: '', required_qty: '' }];
    items.forEach(item => {
      const product = String(item.product_name || '—');
      const hay = `${po} ${name} ${product} ${item.sku || ''} ${order.order_number || ''}`.toLowerCase();
      if (q && !hay.includes(q)) return;
      const ordered = parseInt(item.ordered_qty, 10) || 0;
      const available = parseInt(item.available_qty, 10) || 0;
      const required = Math.max(0, ordered - available);
      rows.push({ po, name, product, ordered, available, unit: item.unit || '—', required, stockStatus: prPovStockStatus(available, ordered) });
    });
  });
  return rows;
}

function renderPRListCustomerOrderRows() {
  const tbody = document.getElementById('prBody');
  if (!tbody) return;
  if (_prCustomerOrdersAvailable === false) {
    tbody.innerHTML = prListEmptyRow(8, 'Customer order / customer PO records were not found in this system.');
    return;
  }
  const rows = flattenPRListCustomerOrderRows();
  if (!rows.length) {
    tbody.innerHTML = prListEmptyRow(8, (_customerOrdersForPR || []).length ? 'No matching customer orders.' : 'No customer orders or customer POs are on file.');
    return;
  }
  tbody.innerHTML = rows.map(r => `<tr>
    <td><strong style="font-family:monospace">${esc(r.po)}</strong></td>
    <td>${esc(r.name)}</td>
    <td>${esc(r.product)}</td>
    <td>${r.ordered}</td>
    <td><strong style="color:${r.required > 0 ? '#dc2626' : '#0f172a'}">${r.available}</strong></td>
    <td>${esc(r.unit)}</td>
    <td>${r.required}</td>
    <td><span class="badge ${r.stockStatus.cls}">${esc(r.stockStatus.label)}</span></td>
  </tr>`).join('');
}

function renderPRListCatalogRows() {
  const tbody = document.getElementById('prBody');
  if (!tbody) return;
  const q = prListSearchQuery();
  const list = visiblePRCatalogProducts().slice().sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
  const rows = list.filter(p => {
    if (!q) return true;
    const hay = `${p.name || ''} ${p.sku || ''} ${p.category || ''} ${p.brand || ''}`.toLowerCase();
    return hay.includes(q);
  });
  if (!rows.length) {
    tbody.innerHTML = prListEmptyRow(7, list.length ? 'No matching products.' : 'No active Product Catalog items were found.');
    return;
  }
  const taken = new Set(getPRSelectedProductIds());
  tbody.innerHTML = rows.map(p => {
    const stock = prCatalogCurrentStock(p);
    const st = prPovStockStatus(stock);
    const already = taken.has(String(p.id));
    const action = already
      ? '<span class="badge badge-gray">Added</span>'
      : `<button type="button" class="btn btn-sm btn-outline-primary" style="font-size:11px;white-space:nowrap" onclick="addPRItemFromCatalog(${p.id})"><i class="bi bi-plus-lg me-1"></i>Add</button>`;
    return `<tr>
      <td>${esc(p.name)}</td>
      <td style="font-family:monospace;font-size:12px">${esc(p.sku || '—')}</td>
      <td>${stock}</td>
      <td>${esc(p.unit_of_measure || '—')}</td>
      <td>${esc(p.category || '—')}</td>
      <td><span class="badge ${st.cls}">${esc(st.label)}</span></td>
      <td>${action}</td>
    </tr>`;
  }).join('');
}

function loadPRListCustomerOrders(silent) {
  const tbody = document.getElementById('prBody');
  if (!tbody) return;
  const cols = 8;
  const hasCache = Array.isArray(_customerOrdersForPR) && (_customerOrdersForPR.length > 0 || _prCustomerOrdersAvailable === false || document.getElementById('prCustomerOrderPanel')?.dataset?.loaded === '1');
  if (hasCache) renderPRListCustomerOrderRows();
  else if (!silent) tbody.innerHTML = `<tr class="loading-row"><td colspan="${cols}"><span class="loader"></span> Loading customer orders…</td></tr>`;
  pApi({ query: { action: 'get_customer_orders_for_pr' } }, r => {
    if (r && r.success) {
      _prCustomerOrdersAvailable = r.source_available !== false;
      _customerOrdersForPR = mergePRCustomerOrderLists(r.data || []);
    }
    if (getPRListPOV() !== 'customer_order') return;
    renderPRListCustomerOrderRows();
  });
}

function loadPRListCatalog(silent) {
  const tbody = document.getElementById('prBody');
  if (!tbody) return;
  const cols = 7;
  if ((_poProducts || []).length) renderPRListCatalogRows();
  else if (!silent) tbody.innerHTML = `<tr class="loading-row"><td colspan="${cols}"><span class="loader"></span> Loading products…</td></tr>`;
  pApi({ query: { action: 'get_products_for_po' } }, r => {
    if (r && r.success) _poProducts = r.data || [];
    if (getPRListPOV() !== 'others') return;
    renderPRListCatalogRows();
  });
}

function syncPRListPOV() {
  if (!shouldUsePurposeListPOV()) return;
  const pov = getPRListPOV();
  const title = document.getElementById('prListTitle');
  const body = document.getElementById('prBody');
  if (pov === 'all' && title && title.textContent.trim() === 'All Purchase Requests'
      && body && body.querySelector('tr') && !body.querySelector('.loading-row')) {
    return;
  }
  loadPurchaseRequests();
}

function loadPurchaseRequests(silent) {
  const tbody = document.getElementById('prBody');
  if (!tbody) return;
  const pov = getPRListPOV();
  if (shouldUsePurposeListPOV()) {
    syncPRListChrome(pov);
    if (pov === 'others') {
      loadPRListCatalog(silent);
      return;
    }
    if (pov === 'customer_order') {
      loadPRListCustomerOrders(silent);
      return;
    }
  }
  const cols = prListColCount(pov);
  if (!silent) {
    tbody.innerHTML = `<tr class="loading-row"><td colspan="${cols}"><span class="loader"></span> Loading Purchase Requests…</td></tr>`;
  }
  const params = { action:'get_purchase_requests' };
  const sf = document.getElementById('prStatusFilter')?.value;
  const sq = document.getElementById('prSearch')?.value;
  if (sf && sf !== 'all') params.status = sf;
  if (sq) params.search = sq;
  pApi({ query:params }, r => {
    if (!r.success) {
      if (!silent) tbody.innerHTML = `<tr><td colspan="${cols}" style="color:red;padding:20px">${esc(r.message)}</td></tr>`;
      return;
    }
    let rows = r.data || [];
    if (shouldUsePurposeListPOV() && getPRListPOV() === 'low_stock') {
      rows = rows.filter(p => String(p.purpose || '') === 'Low Stock Replenishment');
    }
    const sig = liveRowsSignature(rows, ['id', 'status', 'pipeline_status', 'item_count', 'total_estimated_amount', 'generated_po_numbers', 'supplier_name', 'purpose', 'customer_order_reference', 'remarks']) + '|' + (sf || '') + '|' + (sq || '') + '|' + pov;
    if (silent && sig === _prListSig) return;
    _prListSig = sig;
    _prsData = r.data || [];
    renderPRs(rows);
  });
}

function filterPRs() { loadPurchaseRequests(); }

function formatPRPurpose(p) {
  const v = String((p && (p.purpose_label || p.purpose)) || '').trim();
  return v || 'Not Specified';
}

function formatPRCustomerOrderRef(p) {
  return String((p && p.customer_order_reference) || '').trim();
}

function prPurposeRemarksLabel(p) {
  const purpose = String((p && p.purpose) || '').trim();
  if (purpose === 'Customer Order') return 'Order Details';
  if (purpose === 'Others') return 'Purpose Details';
  return 'Remarks';
}

function prPurposeListExtra(p) {
  const purpose = String((p && p.purpose) || '').trim();
  const ref = formatPRCustomerOrderRef(p);
  const remarks = String((p && p.remarks) || '').trim();
  let extra = '';
  if (purpose === 'Customer Order' && ref) {
    extra += `<div style="font-size:11px;color:#64748b;margin-top:2px">${esc(ref)}</div>`;
  }
  if ((purpose === 'Customer Order' || purpose === 'Others') && remarks) {
    extra += `<div style="font-size:11px;color:#64748b;margin-top:2px">${esc(remarks)}</div>`;
  }
  return extra;
}

function prClearEl(id) {
  const el = document.getElementById(id);
  if (el) el.value = '';
}

function prSetWrapActive(wrap, active) {
  if (!wrap) return;
  wrap.style.display = active ? '' : 'none';
  wrap.querySelectorAll('input, textarea, select').forEach(el => {
    el.disabled = !active;
  });
}

function shouldShowPRLowStockPanel() {
  if (isEditingExistingPR()) return false;
  return String(document.getElementById('prPurpose')?.value || '').trim() === 'Low Stock Replenishment';
}

function getCreatePRPurpose() {
  return String(document.getElementById('prPurpose')?.value || '').trim();
}

function purposeDetailsAreRequired(purpose) {
  return String(purpose || '').trim() === 'Others';
}

function syncPRPurposeDetailsRequired(purpose) {
  const pdEl = document.getElementById('prPurposeDetails');
  if (!pdEl) return;
  if (purposeDetailsAreRequired(purpose)) {
    pdEl.required = true;
    pdEl.setAttribute('required', 'required');
  } else {
    pdEl.required = false;
    pdEl.removeAttribute('required');
    pdEl.setCustomValidity('');
  }
}

function clearPRCoExtraFields() {
  _prSelectedCustomerOrderId = '';
  _prCustomerOrderFilter = '';
  const hidden = document.getElementById('prCustomerOrderSelect');
  const search = document.getElementById('prCustomerOrderSearch');
  if (hidden) hidden.value = '';
  if (search) {
    search.value = '';
    delete search.dataset.customAccepted;
  }
  closePRCustomerOrderMenu();
  const nameHidden = document.getElementById('prCustomerNameValue');
  const nameSel = document.getElementById('prCustomerNameSelect');
  const nameSearch = document.getElementById('prCustomerNameSearch');
  if (nameHidden) nameHidden.value = '';
  if (nameSel) nameSel.value = '';
  if (nameSearch) {
    nameSearch.value = '';
    delete nameSearch.dataset.customAccepted;
  }
  _prCustomerNameFilter = '';
  closePRCustomerNameMenu();
}

function buildCustomerOrderRemarksForSubmit() {
  return String(document.getElementById('prOrderDetails')?.value || '').trim();
}

function composePRCustomerName() {
  const first = String(document.getElementById('prNewCustomerFirstName')?.value || '').trim();
  const middle = String(document.getElementById('prNewCustomerMiddleName')?.value || '').trim();
  const last = String(document.getElementById('prNewCustomerLastName')?.value || '').trim();
  const suffix = String(document.getElementById('prNewCustomerSuffix')?.value || '').trim();
  return [first, middle, last, suffix].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
}

function openPRAddSupplierModal() {
  if (typeof closePRSupplierMenu === 'function') closePRSupplierMenu();
  ['prNewSupplierCode', 'prNewSupplierName', 'prNewSupplierContact', 'prNewSupplierPhone', 'prNewSupplierEmail', 'prNewSupplierCity'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  openModal('prAddSupplierModal');
  setTimeout(() => document.getElementById('prNewSupplierCode')?.focus(), 50);
}

function applyAddedSupplierToPR(s) {
  const id = String(s?.id || '');
  if (!id) return;
  const entry = {
    id,
    supplier_code: String(s.supplier_code || ''),
    name: String(s.name || ''),
    contact_person: String(s.contact_person || ''),
    email: String(s.email || ''),
    phone: String(s.phone || ''),
    status: String(s.status || 'Active')
  };
  const list = _poSuppliers || [];
  const idx = list.findIndex(x => String(x.id) === id);
  if (idx >= 0) _poSuppliers[idx] = { ...list[idx], ...entry };
  else _poSuppliers = [...list, entry].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
  setPRPreferredSupplier(id);
  if (typeof loadDropdowns === 'function') loadDropdowns();
}

function submitPRAddSupplier() {
  const code = String(document.getElementById('prNewSupplierCode')?.value || '').trim();
  const name = String(document.getElementById('prNewSupplierName')?.value || '').trim();
  const contact = String(document.getElementById('prNewSupplierContact')?.value || '').trim();
  const phone = String(document.getElementById('prNewSupplierPhone')?.value || '').trim();
  const email = String(document.getElementById('prNewSupplierEmail')?.value || '').trim();
  const city = String(document.getElementById('prNewSupplierCity')?.value || '').trim();
  if (!code) {
    pToast('Supplier Code is required.', 'warn');
    document.getElementById('prNewSupplierCode')?.focus();
    return;
  }
  if (!name) {
    pToast('Supplier Name is required.', 'warn');
    document.getElementById('prNewSupplierName')?.focus();
    return;
  }
  const supplierPhoneError = phMobileError(phone);
  if (supplierPhoneError) {
    pToast(supplierPhoneError, 'warn');
    document.getElementById('prNewSupplierPhone')?.focus();
    return;
  }
  const btn = document.getElementById('prAddSupplierSaveBtn');
  if (btn) btn.disabled = true;
  pApi({
    method: 'POST',
    body: {
      action: 'add_supplier',
      supplier_code: code,
      name,
      contact_person: contact,
      phone,
      email,
      city,
      status: 'Active'
    }
  }, res => {
    if (btn) btn.disabled = false;
    if (!res.success || !res.id) {
      pToast(res.message || 'Unable to save supplier.', 'danger');
      return;
    }
    closeModal('prAddSupplierModal');
    applyAddedSupplierToPR({
      id: res.id,
      supplier_code: code,
      name,
      contact_person: contact,
      phone,
      email,
      status: 'Active'
    });
    pToast('Supplier added.');
  });
}

function openPRAddCustomerModal() {
  ['prNewCustomerFirstName', 'prNewCustomerMiddleName', 'prNewCustomerLastName', 'prNewCustomerSuffix', 'prNewCustomerName'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const poEl = document.getElementById('prNewCustomerPoRef');
  const phoneEl = document.getElementById('prNewCustomerPhone');
  const emailEl = document.getElementById('prNewCustomerEmail');
  if (poEl) poEl.value = '';
  if (phoneEl) phoneEl.value = '';
  if (emailEl) emailEl.value = '';
  openModal('prAddCustomerModal');
  setTimeout(() => document.getElementById('prNewCustomerFirstName')?.focus(), 50);
}

function restampPRCustomerOrderRows() {
  const ref = String(document.getElementById('prCustomerOrderRef')?.value || '').trim();
  const remarks = String(document.getElementById('prOrderDetails')?.value || '').trim();
  createPRItemsTable()?.querySelectorAll('tr').forEach(row => {
    const purpose = String(row.dataset.purpose || getCreatePRPurpose() || '').trim();
    if (purpose !== 'Customer Order') return;
    row.dataset.purpose = 'Customer Order';
    if (ref) row.dataset.customerOrderRef = ref;
    if (remarks) row.dataset.remarks = remarks;
  });
  commitCurrentPRPurposeItems();
}

function mergePRCustomerOrderLists(serverRows) {
  const incoming = Array.isArray(serverRows) ? serverRows : [];
  const kept = (_customerOrdersForPR || []).filter(local => {
    const id = String(local?.id || '');
    const po = String(local?.customer_po_number || local?.order_number || '').trim().toLowerCase();
    const name = String(local?.customer_name || '').trim().toLowerCase();
    return !incoming.some(row => {
      if (id && String(row?.id || '') === id) return true;
      const rowPo = String(row?.customer_po_number || row?.order_number || '').trim().toLowerCase();
      const rowName = String(row?.customer_name || '').trim().toLowerCase();
      return po !== '' && rowPo === po && rowName === name;
    });
  });
  return incoming.concat(kept);
}

function applyAddedCustomerToPROrderCombo(c) {
  const po = String(c?.po_reference || '').trim();
  const name = String(c?.name || '').trim();
  const id = 'cust-' + String(c?.id || '');
  const entry = {
    id,
    customer_id: c?.id,
    order_number: po,
    customer_po_number: po,
    customer_name: name,
    status: 'Active',
    items: [],
    _from_customer: true
  };
  const list = _customerOrdersForPR || [];
  const idx = list.findIndex(o =>
    String(o.id) === id
    || (String(o.customer_po_number || o.order_number || '').toLowerCase() === po.toLowerCase()
      && String(o.customer_name || '').toLowerCase() === name.toLowerCase())
  );
  if (idx >= 0) _customerOrdersForPR[idx] = { ...list[idx], ...entry };
  else _customerOrdersForPR = [entry, ...list];
  _prCustomerOrdersAvailable = true;
  rememberPRPartyName({
    id,
    name,
    kind: 'customer',
    po_reference: po
  });
  populatePRCustomerOrderSelect();
  setPRSelectedCustomerOrder(id);
  if (name) acceptTypedPRCustomerName(name, { silent: true });
  const bucket = ensurePRPurposeBucket('Customer Order');
  bucket.customer_id = String(c?.id || '');
  bucket.customer_name = name;
  bucket.customer_order_id = id;
  bucket.customer_order_reference = po;
  restampPRCustomerOrderRows();
}

function submitPRAddCustomer() {
  const first = String(document.getElementById('prNewCustomerFirstName')?.value || '').trim();
  const last = String(document.getElementById('prNewCustomerLastName')?.value || '').trim();
  const name = composePRCustomerName();
  const hiddenName = document.getElementById('prNewCustomerName');
  if (hiddenName) hiddenName.value = name;
  const poRef = String(document.getElementById('prNewCustomerPoRef')?.value || '').trim();
  const phone = String(document.getElementById('prNewCustomerPhone')?.value || '').trim();
  const email = String(document.getElementById('prNewCustomerEmail')?.value || '').trim();
  if (!first) {
    pToast('First Name is required.', 'warn');
    document.getElementById('prNewCustomerFirstName')?.focus();
    return;
  }
  if (!last) {
    pToast('Last Name is required.', 'warn');
    document.getElementById('prNewCustomerLastName')?.focus();
    return;
  }
  if (!poRef) {
    pToast('Customer PO / Reference No. is required.', 'warn');
    document.getElementById('prNewCustomerPoRef')?.focus();
    return;
  }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    pToast('Enter a valid email address.', 'warn');
    document.getElementById('prNewCustomerEmail')?.focus();
    return;
  }
  const customerPhoneError = phMobileError(phone);
  if (customerPhoneError) {
    pToast(customerPhoneError, 'warn');
    document.getElementById('prNewCustomerPhone')?.focus();
    return;
  }
  const middle = String(document.getElementById('prNewCustomerMiddleName')?.value || '').trim();
  const suffix = String(document.getElementById('prNewCustomerSuffix')?.value || '').trim();
  const btn = document.getElementById('prAddCustomerSaveBtn');
  if (btn) btn.disabled = true;
  pApi({
    method: 'POST',
    body: {
      action: 'create_customer',
      name,
      first_name: first,
      middle_name: middle,
      last_name: last,
      suffix,
      phone,
      email,
      po_reference: poRef
    }
  }, res => {
    if (btn) btn.disabled = false;
    if (!res.success || !res.data) {
      pToast(res.message || 'Unable to save customer.', 'danger');
      return;
    }
    const c = { ...res.data, po_reference: res.data.po_reference || poRef };
    const existsIdx = (_prCoCustomers || []).findIndex(x => String(x.id) === String(c.id));
    if (existsIdx >= 0) _prCoCustomers[existsIdx] = c;
    else _prCoCustomers = [c, ...(_prCoCustomers || [])];
    _prCoCustomersLoaded = true;
    closeModal('prAddCustomerModal');
    applyAddedCustomerToPROrderCombo(c);
    pToast(res.existing ? 'Customer already existed and was selected.' : 'Customer added.');
  });
}

function openPRAddCompanyModal() {
  ['prNewCompanyName', 'prNewCompanyAddress', 'prNewCompanyContact', 'prNewCompanyPhone', 'prNewCompanyEmail'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  openModal('prAddCompanyModal');
  setTimeout(() => document.getElementById('prNewCompanyName')?.focus(), 50);
}

function rememberPRPartyName(rec) {
  const name = String(rec?.name || '').trim();
  if (!name) return null;
  const entry = {
    id: String(rec.id || ''),
    name,
    kind: String(rec.kind || 'company'),
    po_reference: String(rec.po_reference || '').trim()
  };
  const list = _prPartyNames || [];
  const idx = list.findIndex(p => String(p.id || '') === entry.id || String(p.name || '').trim().toLowerCase() === name.toLowerCase());
  if (idx >= 0) _prPartyNames[idx] = { ...list[idx], ...entry };
  else _prPartyNames = [...list, entry];
  return entry;
}

function applyPRPartyNamesFromApi(rows) {
  const incoming = Array.isArray(rows) ? rows : [];
  const kept = (_prPartyNames || []).filter(p => {
    const name = String(p?.name || '').trim().toLowerCase();
    if (!name) return false;
    return !incoming.some(x => String(x?.name || '').trim().toLowerCase() === name);
  });
  _prPartyNames = incoming.concat(kept);
}

function submitPRAddCompany() {
  const name = String(document.getElementById('prNewCompanyName')?.value || '').trim();
  const address = String(document.getElementById('prNewCompanyAddress')?.value || '').trim();
  const contact = String(document.getElementById('prNewCompanyContact')?.value || '').trim();
  const phone = String(document.getElementById('prNewCompanyPhone')?.value || '').trim();
  const email = String(document.getElementById('prNewCompanyEmail')?.value || '').trim();
  if (!name) {
    pToast('Company Name is required.', 'warn');
    document.getElementById('prNewCompanyName')?.focus();
    return;
  }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    pToast('Enter a valid email address.', 'warn');
    document.getElementById('prNewCompanyEmail')?.focus();
    return;
  }
  const companyPhoneError = phMobileError(phone);
  if (companyPhoneError) {
    pToast(companyPhoneError, 'warn');
    document.getElementById('prNewCompanyPhone')?.focus();
    return;
  }
  const btn = document.getElementById('prAddCompanySaveBtn');
  if (btn) btn.disabled = true;
  pApi({
    method: 'POST',
    body: {
      action: 'create_company',
      name,
      address,
      contact_person: contact,
      phone,
      email
    }
  }, res => {
    if (btn) btn.disabled = false;
    if (!res.success || !res.data) {
      pToast(res.message || 'Unable to save company.', 'danger');
      return;
    }
    const company = res.data;
    rememberPRPartyName({
      id: company.id ? ('co-' + company.id) : '',
      name: company.name || name,
      kind: 'company'
    });
    closeModal('prAddCompanyModal');
    acceptTypedPRCustomerName(company.name || name);
    const bucket = ensurePRPurposeBucket('Customer Order');
    bucket.customer_name = company.name || name;
    bucket.customer_id = company.id ? String(company.id) : '';
    restampPRCustomerOrderRows();
    pToast(res.existing ? 'Company already existed and was selected.' : 'Company added.');
  });
}

function fillPRCatalogCategorySelect(rows) {
  const sel = document.getElementById('prNewCatalogCategory');
  if (!sel) return;
  const list = rows || [];
  const parents = list.filter(c => !parseInt(c.parent_id, 10));
  const byParent = {};
  list.forEach(c => {
    const pid = parseInt(c.parent_id, 10) || 0;
    if (!pid) return;
    if (!byParent[pid]) byParent[pid] = [];
    byParent[pid].push(c);
  });
  let html = '<option value="">-- Select Category --</option>';
  const used = {};
  parents.forEach(p => {
    const kids = byParent[parseInt(p.id, 10)] || [];
    if (!kids.length) {
      html += `<option value="${esc(p.id)}">${esc(p.name)}</option>`;
      used[String(p.id)] = true;
      return;
    }
    html += `<optgroup label="${esc(p.name)}">`;
    kids.forEach(k => {
      html += `<option value="${esc(k.id)}">${esc(k.name)}</option>`;
      used[String(k.id)] = true;
    });
    html += '</optgroup>';
    used[String(p.id)] = true;
  });
  list.forEach(c => {
    if (used[String(c.id)]) return;
    html += `<option value="${esc(c.id)}">${esc(c.name)}</option>`;
  });
  sel.innerHTML = html;
}

function openPRAddCatalogModal() {
  ['prNewCatalogName', 'prNewCatalogSku', 'prNewCatalogBarcode', 'prNewCatalogBrand', 'prNewCatalogReorder', 'prNewCatalogDescription'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const unit = document.getElementById('prNewCatalogUnit');
  if (unit) unit.value = '';
  const cat = document.getElementById('prNewCatalogCategory');
  if (cat) cat.value = '';
  openModal('prAddCatalogModal');
  pApi({ query: { action: 'get_catalog_categories' } }, r => {
    if (!r || !r.success) return;
    const keep = document.getElementById('prNewCatalogCategory')?.value || '';
    fillPRCatalogCategorySelect(r.data || []);
    const sel = document.getElementById('prNewCatalogCategory');
    if (sel && keep) sel.value = keep;
  });
  setTimeout(() => document.getElementById('prNewCatalogName')?.focus(), 50);
}

function refreshPRCatalogSelects() {
  document.querySelectorAll('#prItemsBody select[name*="[product_id]"], #prReviewItemsBody select[name*="[product_id]"]').forEach(sel => {
    const keep = String(sel.value || '');
    sel.innerHTML = buildPRProductOptions(keep);
    if (keep && String(sel.value) !== keep) sel.value = keep;
  });
  if (typeof shouldShowPRCatalogPanel === 'function' && shouldShowPRCatalogPanel() && typeof renderPRCatalogPanel === 'function') {
    _prCatalogViewSig = '';
    renderPRCatalogPanel();
  }
}

function submitPRAddCatalogItem() {
  const name = String(document.getElementById('prNewCatalogName')?.value || '').trim();
  const sku = String(document.getElementById('prNewCatalogSku')?.value || '').trim();
  const barcode = String(document.getElementById('prNewCatalogBarcode')?.value || '').trim();
  const categoryId = String(document.getElementById('prNewCatalogCategory')?.value || '').trim();
  const brand = String(document.getElementById('prNewCatalogBrand')?.value || '').trim();
  const unit = String(document.getElementById('prNewCatalogUnit')?.value || '').trim();
  const reorder = String(document.getElementById('prNewCatalogReorder')?.value || '').trim();
  const description = String(document.getElementById('prNewCatalogDescription')?.value || '').trim();
  if (!name) {
    pToast('Item Name is required.', 'warn');
    document.getElementById('prNewCatalogName')?.focus();
    return;
  }
  if (!sku) {
    pToast('SKU / Item Code is required.', 'warn');
    document.getElementById('prNewCatalogSku')?.focus();
    return;
  }
  if (!categoryId) {
    pToast('Category is required.', 'warn');
    document.getElementById('prNewCatalogCategory')?.focus();
    return;
  }
  if (!unit) {
    pToast('Unit is required.', 'warn');
    document.getElementById('prNewCatalogUnit')?.focus();
    return;
  }
  if (reorder !== '' && !/^\d+$/.test(reorder)) {
    pToast('Reorder Level must be a whole number of 0 or greater.', 'warn');
    document.getElementById('prNewCatalogReorder')?.focus();
    return;
  }
  const btn = document.getElementById('prAddCatalogSaveBtn');
  if (btn) btn.disabled = true;
  pApi({
    method: 'POST',
    body: {
      action: 'add_catalog_item',
      name,
      sku,
      barcode,
      category_id: categoryId,
      brand,
      unit_of_measure: unit,
      reorder_level: reorder,
      description
    }
  }, res => {
    if (btn) btn.disabled = false;
    if (!res.success || !res.id) {
      pToast(res.message || 'Unable to save catalog item.', 'danger');
      return;
    }
    closeModal('prAddCatalogModal');
    pApi({ query: { action: 'get_products_for_po' } }, r => {
      if (r && r.success) _poProducts = r.data || [];
      refreshPRCatalogSelects();
      pToast('Catalog item added.');
    });
  });
}

function emptyPRPurposeBucket() {
  return {
    items: [],
    supplier_id: '',
    customer_order_id: '',
    customer_order_reference: '',
    order_details: '',
    purpose_details: '',
    customer_id: '',
    customer_name: '',
    customer_items: []
  };
}

function emptyPRPurposeBuckets() {
  const out = {};
  PR_PURPOSE_KEYS.forEach(key => { out[key] = emptyPRPurposeBucket(); });
  return out;
}

function ensurePRPurposeBucket(purpose) {
  if (!_prPurposeBuckets) _prPurposeBuckets = emptyPRPurposeBuckets();
  const key = String(purpose || '').trim();
  if (!key) return emptyPRPurposeBucket();
  if (!_prPurposeBuckets[key]) _prPurposeBuckets[key] = emptyPRPurposeBucket();
  return _prPurposeBuckets[key];
}

function clonePRPreparedItem(item) {
  return {
    product_id: String(item?.product_id || ''),
    product_name: String(item?.product_name || ''),
    quantity_requested: item?.quantity_requested,
    estimated_unit_price: item?.estimated_unit_price,
    unit_of_measure: String(item?.unit_of_measure || ''),
    supplier_id: String(item?.supplier_id || ''),
    purpose: String(item?.purpose || ''),
    customer_order_reference: String(item?.customer_order_reference || ''),
    remarks: String(item?.remarks || ''),
    seq: item?.seq
  };
}

function clonePRPurposeBucket(src) {
  const b = emptyPRPurposeBucket();
  if (!src || typeof src !== 'object') return b;
  b.items = (src.items || []).map(clonePRPreparedItem).filter(i => i.product_id || i.product_name);
  b.supplier_id = String(src.supplier_id || '');
  b.customer_order_id = String(src.customer_order_id || '');
  b.customer_order_reference = String(src.customer_order_reference || '');
  b.order_details = String(src.order_details || '');
  b.purpose_details = String(src.purpose_details || '');
  b.customer_id = String(src.customer_id || '');
  b.customer_name = String(src.customer_name || '');
  b.customer_items = Array.isArray(src.customer_items)
    ? src.customer_items.map(ci => ({
        item_name: String(ci?.item_name || ci?.product_name || ''),
        quantity: ci?.quantity ?? ci?.quantity_requested ?? 1,
        unit: String(ci?.unit || ci?.unit_of_measure || '')
      })).filter(ci => ci.item_name)
    : [];
  return b;
}

function purposeOfVisibleCreatePRRows() {
  const rows = [...(createPRItemsTable()?.querySelectorAll('tr') || [])];
  for (const row of rows) {
    const p = String(row.dataset.purpose || '').trim();
    if (PR_PURPOSE_KEYS.includes(p)) return p;
  }
  return String(_prActivePurposeView || '').trim();
}

function itemsFromDraftPR(draft) {
  const purpose = String(draft?.purpose || '').trim();
  return (draft?.items || []).map(item => clonePRPreparedItem({
    product_id: item.product_id,
    product_name: item.product_name,
    quantity_requested: item.quantity_requested,
    estimated_unit_price: item.estimated_unit_price ?? item.unit_price,
    unit_of_measure: item.unit_of_measure,
    supplier_id: item.supplier_id || draft.supplier_id,
    purpose,
    customer_order_reference: purpose === 'Customer Order' ? String(draft.customer_order_reference || item.customer_order_reference || '') : '',
    remarks: purpose === 'Low Stock Replenishment' ? '' : String(draft.remarks || item.remarks || '')
  })).filter(i => i.product_id || i.product_name);
}

function mergeDraftIntoPurposeBuckets(draft, bundle) {
  if (bundle && bundle.buckets && typeof bundle.buckets === 'object') {
    _prPurposeBuckets = emptyPRPurposeBuckets();
    PR_PURPOSE_KEYS.forEach(key => {
      _prPurposeBuckets[key] = clonePRPurposeBucket(bundle.buckets[key]);
    });
  }
  const purpose = String(draft?.purpose || '').trim();
  if (!PR_PURPOSE_KEYS.includes(purpose)) return;
  const b = ensurePRPurposeBucket(purpose);
  b.items = itemsFromDraftPR(draft);
  b.supplier_id = String(draft.supplier_id || b.supplier_id || '');
  if (purpose === 'Others') {
    b.purpose_details = String(draft.remarks || b.purpose_details || '');
  }
  if (purpose === 'Customer Order') {
    b.customer_order_id = String(b.customer_order_id || '');
    b.customer_order_reference = String(draft.customer_order_reference || b.customer_order_reference || '');
    b.customer_name = String(draft.customer_name || b.customer_name || '');
    b.order_details = String(draft.remarks || b.order_details || '');
  }
  if (b.supplier_id) _prPurposeLastSupplier[purpose] = b.supplier_id;
  PR_PURPOSE_KEYS.forEach(key => {
    const sid = String(ensurePRPurposeBucket(key).supplier_id || '');
    if (sid) _prPurposeLastSupplier[key] = sid;
  });
}

function draftBundleForPR(id) {
  const bundle = readPRPurposeDraftBundle();
  if (!bundle || !Array.isArray(bundle.prIds)) return null;
  return bundle.prIds.map(String).includes(String(id)) ? bundle : null;
}

function prDraftBundleStorageKey() {
  return 'clarenzon_pr_purpose_draft_bundle';
}

function readPRPurposeDraftBundle() {
  try {
    const raw = localStorage.getItem(prDraftBundleStorageKey());
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (!data || typeof data !== 'object') return null;
    return data;
  } catch (e) {
    return null;
  }
}

function writePRPurposeDraftBundle(prIds) {
  try {
    const current = getCreatePRPurpose();
    if (current) commitPRPurposeBucket(current);
    const buckets = {};
    PR_PURPOSE_KEYS.forEach(key => {
      buckets[key] = clonePRPurposeBucket(ensurePRPurposeBucket(key));
    });
    localStorage.setItem(prDraftBundleStorageKey(), JSON.stringify({
      prIds: (prIds || []).map(id => String(id)).filter(Boolean),
      buckets,
      savedAt: Date.now()
    }));
  } catch (e) { /* ignore quota / private mode */ }
}

function clearPRPurposeDraftBundle() {
  try { localStorage.removeItem(prDraftBundleStorageKey()); } catch (e) { /* ignore */ }
}

function resetCreatePRPreparedState() {
  _prStashedItems = [];
  _prPurposeLastSupplier = {};
  _prPurposeBuckets = emptyPRPurposeBuckets();
  _prActivePurposeView = '';
}

function createPRItemsTable() {
  return document.getElementById('prItemsBody');
}

function hydratePreparedPRItem(item) {
  const out = { ...(item || {}) };
  const purpose = String(out.purpose || '').trim();
  const bucket = PR_PURPOSE_KEYS.includes(purpose) ? ensurePRPurposeBucket(purpose) : null;
  if (purpose === 'Low Stock Replenishment') {
    out.customer_order_reference = '';
    out.remarks = '';
    return out;
  }
  if (purpose === 'Customer Order') {
    if (!String(out.customer_order_reference || '').trim()) {
      out.customer_order_reference = String(bucket?.customer_order_reference || document.getElementById('prCustomerOrderRef')?.value || '').trim();
    }
    if (!String(out.remarks || '').trim()) {
      out.remarks = String(bucket?.order_details || document.getElementById('prOrderDetails')?.value || '').trim();
    }
  }
  if (purpose === 'Others') {
    out.customer_order_reference = '';
    if (!String(out.remarks || '').trim()) {
      out.remarks = String(bucket?.purpose_details || document.getElementById('prPurposeDetails')?.value || '').trim();
    }
  }
  return out;
}

function stampCreatePRRowGroup(row, item) {
  if (!row || isPRReviewModalOpen()) return;
  const purpose = String((item && item.purpose) || row.dataset.purpose || getCreatePRPurpose() || '').trim();
  row.dataset.purpose = purpose;
  if (item && Object.prototype.hasOwnProperty.call(item, 'customer_order_reference')) {
    row.dataset.customerOrderRef = String(item.customer_order_reference || '').trim();
    row.dataset.remarks = String(item.remarks || '').trim();
    return;
  }
  if (purpose === 'Customer Order') {
    if (!row.dataset.customerOrderRef) {
      row.dataset.customerOrderRef = String(document.getElementById('prCustomerOrderRef')?.value || '').trim();
    }
    if (!row.dataset.remarks) {
      row.dataset.remarks = String(document.getElementById('prOrderDetails')?.value || '').trim();
    }
  } else if (purpose === 'Others') {
    row.dataset.customerOrderRef = '';
    if (!row.dataset.remarks) {
      row.dataset.remarks = String(document.getElementById('prPurposeDetails')?.value || '').trim();
    }
  } else {
    row.dataset.customerOrderRef = '';
    row.dataset.remarks = '';
  }
}

function lastCreatePRItemRow() {
  const tbody = createPRItemsTable();
  if (!tbody) return null;
  return tbody.querySelector(`tr[data-idx="${_prItemCount - 1}"]`) || tbody.lastElementChild;
}

function restampEmptyCreatePRRowsForPurpose(purpose) {
  const key = String(purpose || '').trim();
  if (!PR_PURPOSE_KEYS.includes(key)) return;
  createPRItemsTable()?.querySelectorAll('tr').forEach(row => {
    const idx = row.dataset.idx;
    const pid = String(row.querySelector(`[name="items[${idx}][product_id]"]`)?.value || '').trim();
    const pname = String(row.querySelector(`[name="items[${idx}][product_name]"]`)?.value || '').trim();
    if (pid || pname) return;
    row.dataset.purpose = key;
    stampCreatePRRowGroup(row);
  });
}

function parseCreatePRRow(row, purposeFallback) {
  if (!row) return { hasProduct: false, complete: false, item: null };
  const idx = row.dataset.idx;
  const pid = String(row.querySelector(`[name="items[${idx}][product_id]"]`)?.value || '').trim();
  const pname = String(row.querySelector(`[name="items[${idx}][product_name]"]`)?.value || '').trim();
  if (!pid && !pname) return { hasProduct: false, complete: false, item: null };
  const qty = parseInt(row.querySelector(`[name="items[${idx}][quantity_requested]"]`)?.value || 0, 10);
  const upRaw = row.querySelector(`[name="items[${idx}][estimated_unit_price]"]`)?.value;
  const up = parseFloat(upRaw);
  const unit = String(row.querySelector(`[name="items[${idx}][unit_of_measure]"]`)?.value || '').trim();
  const sid = getPRRowSupplierId(row);
  const purpose = String(row.dataset.purpose || purposeFallback || '').trim();
  const item = hydratePreparedPRItem({
    product_id: pid,
    product_name: pname,
    quantity_requested: qty,
    estimated_unit_price: Number.isNaN(up) ? upRaw : up,
    unit_of_measure: unit,
    supplier_id: sid,
    purpose,
    customer_order_reference: String(row.dataset.customerOrderRef || '').trim(),
    remarks: String(row.dataset.remarks || '').trim()
  });
  const complete = !!(item.product_id && item.product_name && item.quantity_requested > 0
    && item.unit_of_measure && item.supplier_id && item.purpose
    && upRaw !== '' && !Number.isNaN(up) && up >= 0);
  return { hasProduct: true, complete, item };
}

function readCreatePRRequestedItems(purposeOverride) {
  const purpose = String(purposeOverride || getCreatePRPurpose() || '').trim();
  const items = [];
  createPRItemsTable()?.querySelectorAll('tr').forEach(row => {
    const rowPurpose = String(row.dataset.purpose || '').trim();
    if (purposeOverride && rowPurpose && rowPurpose !== purposeOverride) return;
    if (!rowPurpose && purpose) stampCreatePRRowGroup(row);
    const parsed = parseCreatePRRow(row, row.dataset.purpose || purpose);
    if (parsed.hasProduct) {
      items.push({
        ...parsed.item,
        purpose: parsed.item.purpose || row.dataset.purpose || purpose,
        seq: parseInt(row.dataset.idx, 10)
      });
    }
  });
  return items;
}

function decoratePRItemForPurpose(item, key) {
  const bucket = ensurePRPurposeBucket(key);
  if (key === 'Customer Order') {
    return clonePRPreparedItem({
      ...item,
      purpose: key,
      customer_order_reference: item.customer_order_reference
        || bucket.customer_order_reference
        || String(document.getElementById('prCustomerOrderRef')?.value || '').trim(),
      remarks: item.remarks
        || bucket.order_details
        || String(document.getElementById('prOrderDetails')?.value || '').trim()
    });
  }
  if (key === 'Others') {
    return clonePRPreparedItem({
      ...item,
      purpose: key,
      customer_order_reference: '',
      remarks: item.remarks
        || bucket.purpose_details
        || String(document.getElementById('prPurposeDetails')?.value || '').trim()
    });
  }
  return clonePRPreparedItem({ ...item, purpose: key, customer_order_reference: '', remarks: '' });
}

function syncCreatePRItemsFromTable() {
  if (_prRestoringPurposeItems) return;
  if (isPRReviewModalOpen()) return;
  const byPurpose = {};
  PR_PURPOSE_KEYS.forEach(key => { byPurpose[key] = []; });
  createPRItemsTable()?.querySelectorAll('tr').forEach(row => {
    if (!String(row.dataset.purpose || '').trim()) stampCreatePRRowGroup(row);
    const purpose = String(row.dataset.purpose || '').trim();
    if (!PR_PURPOSE_KEYS.includes(purpose)) return;
    const parsed = parseCreatePRRow(row, purpose);
    if (!parsed.hasProduct) return;
    byPurpose[purpose].push(decoratePRItemForPurpose({
      ...parsed.item,
      seq: parseInt(row.dataset.idx, 10)
    }, purpose));
  });
  PR_PURPOSE_KEYS.forEach(key => {
    ensurePRPurposeBucket(key).items = byPurpose[key];
  });
}

function commitPRPurposeHeaderFields(purpose) {
  const key = String(purpose || '').trim();
  if (!PR_PURPOSE_KEYS.includes(key)) return;
  const bucket = ensurePRPurposeBucket(key);
  const sid = snapshotPreferredSupplier();
  if (sid && !_prSwitchingPurpose) {
    bucket.supplier_id = sid;
    _prPurposeLastSupplier[key] = sid;
  }
  if (key === 'Customer Order') {
    const idVal = String(_prSelectedCustomerOrderId || document.getElementById('prCustomerOrderSelect')?.value || '');
    if (idVal) bucket.customer_order_id = idVal;
    const refVal = String(document.getElementById('prCustomerOrderRef')?.value || '').trim();
    if (refVal) bucket.customer_order_reference = refVal;
    const nameVal = String(document.getElementById('prCustomerNameValue')?.value || document.getElementById('prCustomerNameSearch')?.value || '').trim();
    if (nameVal) bucket.customer_name = nameVal;
    if (!_prSwitchingPurpose) {
      bucket.order_details = String(document.getElementById('prOrderDetails')?.value || '').trim();
    }
  }
  if (key === 'Others' && !_prSwitchingPurpose) {
    bucket.purpose_details = String(document.getElementById('prPurposeDetails')?.value || '').trim();
  }
}

function commitPRPurposeBucket(purpose) {
  if (_prRestoringPurposeItems) return;
  if (isPRReviewModalOpen()) return;
  syncCreatePRItemsFromTable();
  commitPRPurposeHeaderFields(purpose);
}

function commitCurrentPRPurposeItems() {
  if (_prRestoringPurposeItems || _prSwitchingPurpose) return;
  syncCreatePRItemsFromTable();
  const key = getCreatePRPurpose() || _prActivePurposeView;
  if (key) commitPRPurposeHeaderFields(key);
}

function collectAllBucketItemsSorted() {
  const all = [];
  PR_PURPOSE_KEYS.forEach(purpose => {
    (ensurePRPurposeBucket(purpose).items || []).forEach(item => {
      all.push(clonePRPreparedItem({ ...item, purpose }));
    });
  });
  all.sort((a, b) => (parseInt(a.seq, 10) || 0) - (parseInt(b.seq, 10) || 0));
  return all;
}

function stashCreatePRItemsForPurpose(purpose) {
  commitPRPurposeBucket(purpose);
}

function rebuildCreatePRRequestedItems(items) {
  const tbody = createPRItemsTable();
  if (!tbody) return;
  if (!_poProducts.length) {
    loadDropdowns(() => rebuildCreatePRRequestedItems(items));
    return;
  }
  const restoring = _prRestoringPurposeItems;
  _prRestoringPurposeItems = true;
  try {
    tbody.innerHTML = '';
    _prItemCount = 0;
    const filled = (items || []).filter(i => i && (i.product_id || i.product_name));
    filled.forEach(item => {
      addPRItemRow(item.product_id, item.product_name, item.quantity_requested, item.estimated_unit_price, item.remarks, item.supplier_id);
      const row = lastCreatePRItemRow();
      if (!row) return;
      stampCreatePRRowGroup(row, item);
      const idx = row.dataset.idx;
      const unitInput = row.querySelector(`[name="items[${idx}][unit_of_measure]"]`);
      if (unitInput && item.unit_of_measure) unitInput.value = item.unit_of_measure;
      const qtyInput = row.querySelector(`[name="items[${idx}][quantity_requested]"]`);
      if (qtyInput && item.quantity_requested != null) qtyInput.value = item.quantity_requested;
      const priceInput = row.querySelector(`[name="items[${idx}][estimated_unit_price]"]`);
      if (priceInput && item.estimated_unit_price != null && item.estimated_unit_price !== '') {
        const n = parseFloat(item.estimated_unit_price);
        if (!Number.isNaN(n)) priceInput.value = n.toFixed(2);
      }
    });
  } finally {
    _prRestoringPurposeItems = restoring;
  }
  recalcPRTotal();
  if (!_prRestoringPurposeItems) syncPRLowStockAddButtons();
}

function restoreCreatePRItemsForPurpose(purpose) {
  applyPRPurposeBucketToForm(purpose, { rebuildItems: false });
}

function restorePRPurposeHeaderFields(purpose) {
  const key = String(purpose || '').trim();
  if (!PR_PURPOSE_KEYS.includes(key)) return;
  const bucket = ensurePRPurposeBucket(key);
  if (key === 'Customer Order') {
    _prSelectedCustomerOrderId = String(bucket.customer_order_id || '');
    const ref = document.getElementById('prCustomerOrderRef');
    if (ref) ref.value = bucket.customer_order_reference || '';
    acceptTypedPRCustomerName(bucket.customer_name || '', { silent: true });
    const od = document.getElementById('prOrderDetails');
    if (od) od.value = bucket.order_details || '';
    const coSel = document.getElementById('prCustomerOrderSelect');
    if (coSel) coSel.value = _prSelectedCustomerOrderId || '';
    if (typeof matchPRCustomerOrderFromRef === 'function') matchPRCustomerOrderFromRef();
    syncPRCustomerOrderComboDisplay();
  }
  if (key === 'Others') {
    const pd = document.getElementById('prPurposeDetails');
    if (pd) pd.value = bucket.purpose_details || '';
  }
  const sid = String(bucket.supplier_id || _prPurposeLastSupplier[key] || '');
  if (sid && typeof setPRPreferredSupplier === 'function') {
    _prPurposeLastSupplier[key] = sid;
    setPRPreferredSupplier(sid, { silent: true, skipRemember: true });
  }
}

function applyPRPurposeBucketToForm(purpose, opts = {}) {
  const rebuildItems = opts.rebuildItems === true;
  const restoring = _prRestoringPurposeItems;
  _prRestoringPurposeItems = true;
  try {
    restorePRPurposeHeaderFields(purpose);
    if (rebuildItems) {
      rebuildCreatePRRequestedItems(collectAllBucketItemsSorted());
    }
  } finally {
    _prRestoringPurposeItems = restoring;
  }
  recalcPRTotal();
  if (rebuildItems) syncPRLowStockAddButtons();
}

function collectAllPreparedPRItems() {
  syncCreatePRItemsFromTable();
  const fromTable = [];
  createPRItemsTable()?.querySelectorAll('tr').forEach(row => {
    const parsed = parseCreatePRRow(row, row.dataset.purpose || getCreatePRPurpose());
    if (parsed.hasProduct) fromTable.push(hydratePreparedPRItem(clonePRPreparedItem(parsed.item)));
  });
  if (fromTable.length) return fromTable;
  return collectAllBucketItemsSorted().map(item => hydratePreparedPRItem(item));
}

function preparedPRItemIsComplete(item) {
  if (!item) return false;
  const up = parseFloat(item.estimated_unit_price);
  const hasProduct = !!(item.product_id || (item.purpose === 'Customer Order' && item.product_name));
  return !!(hasProduct && item.product_name && item.purpose && item.supplier_id
    && parseInt(item.quantity_requested, 10) > 0
    && String(item.unit_of_measure || '').trim()
    && item.estimated_unit_price !== '' && item.estimated_unit_price != null
    && !Number.isNaN(up) && up >= 0);
}

function duplicatePreparedPRProduct(items) {
  const seen = {};
  for (const item of items || []) {
    const pid = String(item.product_id || '');
    if (!pid) continue;
    const key = `${item.purpose}\0${item.supplier_id}`;
    seen[key] = seen[key] || {};
    if (seen[key][pid]) return item;
    seen[key][pid] = true;
  }
  return null;
}

function validatePreparedPRItems(items) {
  for (const item of items || []) {
    if (!item.purpose) {
      pToast('Each requested item must have a Purpose.', 'warn');
      return false;
    }
    if (!item.supplier_id) {
      showPRSupplierWarn(true);
      pToast(prNeedItemSupplierMessage(), 'warn');
      return false;
    }
    if (!(item.product_id || (item.purpose === 'Customer Order' && item.product_name)) || !item.product_name || !(parseInt(item.quantity_requested, 10) > 0)) {
      pToast('Each requested item must have a product, supplier, and quantity.', 'warn');
      return false;
    }
    if (item.purpose === 'Customer Order' && !String(item.customer_order_reference || '').trim()) {
      pToast('Customer Order Reference is required for Customer Order items.', 'warn');
      if (prCustomerOrdersHaveData()) document.getElementById('prCustomerOrderSearch')?.focus();
      else document.getElementById('prCustomerOrderRef')?.focus();
      return false;
    }
  }
  if (purposeDetailsAreRequired(getCreatePRPurpose())) {
    const details = String(document.getElementById('prPurposeDetails')?.value || '').trim();
    const itemRemarks = (items || []).some(i => purposeDetailsAreRequired(i.purpose) && String(i.remarks || '').trim());
    if (!details && !itemRemarks) {
      pToast('Purpose Details are required.', 'warn');
      document.getElementById('prPurposeDetails')?.focus();
      return false;
    }
  }
  return true;
}

function rememberPRPurposeSupplier(purpose, sid) {
  const key = String(purpose || getCreatePRPurpose() || '').trim();
  const supplierId = String(sid || '').trim();
  if (!key || !supplierId) return;
  _prPurposeLastSupplier[key] = supplierId;
}

function isPRHeaderSupplierMode() {
  if (isPRReviewModalOpen()) return false;
  return true;
}

function isPRPerItemSupplierMode() {
  return false;
}

function syncPRSupplierFieldMode() {
  closeAllPRSupplierMenus();
  const headerMode = isPRHeaderSupplierMode();
  const group = document.getElementById('prPreferredSupplierGroup');
  if (group) group.style.display = headerMode ? '' : 'none';
  document.getElementById('createPRModal')?.classList.toggle('pr-per-item-supplier', isPRPerItemSupplierMode());
  if (!headerMode) showPRSupplierWarn(false);
  const tbody = isPRReviewModalOpen()
    ? document.getElementById('prReviewItemsBody')
    : document.getElementById('prItemsBody');
  tbody?.querySelectorAll('tr').forEach(syncPRRowSupplierUI);
}

function syncPRRowSupplierUI(row) {
  if (!row) return;
  const perItem = isPRPerItemSupplierMode();
  const nameEl = row.querySelector('.pr-row-supplier-name');
  const combo = row.querySelector('.pr-row-supplier-combo');
  if (nameEl) nameEl.hidden = perItem;
  if (combo) {
    combo.hidden = !perItem;
    if (perItem) bindPRRowSupplierCombo(row);
  }
  setPRRowSupplier(row, getPRRowSupplierId(row));
}

function shouldShowPRCustomerOrderPanel() {
  if (isEditingExistingPR()) return false;
  return getCreatePRPurpose() === 'Customer Order';
}

function shouldShowPRCatalogPanel() {
  if (isEditingExistingPR()) return false;
  if (shouldUsePurposeListPOV()) return false;
  return getCreatePRPurpose() === 'Others';
}

function syncPRPurposeSourcePanels() {
  const low = document.getElementById('prLowStockPanel');
  const co = document.getElementById('prCustomerOrderPanel');
  const cat = document.getElementById('prCatalogPanel');
  if (low) low.style.display = shouldShowPRLowStockPanel() ? '' : 'none';
  if (co) co.style.display = shouldShowPRCustomerOrderPanel() ? '' : 'none';
  if (cat) cat.style.display = shouldShowPRCatalogPanel() ? '' : 'none';
}

function syncPRLowStockPanelVisibility() {
  syncPRPurposeSourcePanels();
}

function refreshPRPurposeSourcePanels() {
  syncPRPurposeSourcePanels();
  const purpose = getCreatePRPurpose();
  if (isEditingExistingPR()) {
    if (purpose === 'Customer Order') loadPRCustomerOrders();
    return;
  }
  if (purpose === 'Low Stock Replenishment') renderPRLowStockPanel();
  else if (purpose === 'Customer Order') loadPRCustomerOrders();
  else if (purpose === 'Others') renderPRCatalogPanel();
}

function getCreatePREnteredItemRows() {
  const tbody = document.getElementById('prItemsBody');
  if (!tbody) return [];
  return [...tbody.querySelectorAll('tr')].filter(row => {
    const idx = row.dataset.idx;
    const pid = String(row.querySelector(`[name="items[${idx}][product_id]"]`)?.value || '').trim();
    const pname = String(row.querySelector(`[name="items[${idx}][product_name]"]`)?.value || '').trim();
    return !!(pid || pname);
  });
}

function clearCreatePRRequestedItemsKeepTable() {
  const tbody = document.getElementById('prItemsBody');
  if (tbody) tbody.innerHTML = '';
  _prItemCount = 0;
  const itemsWrap = document.getElementById('prRequestedItemsSection');
  if (itemsWrap) itemsWrap.style.display = '';
  addPRItemRow();
  const gt = document.getElementById('prGrandTotal');
  if (gt) gt.textContent = '0.00';
  syncPRLowStockAddButtons();
}

function detachUnsavedCreatePRFromSavedDraft() {
  const form = document.getElementById('createPRForm');
  if (!form?.dataset?.editPrId) return;
  if (String(form.dataset.editPrStatus || '') === 'Rejected') return;
  delete form.dataset.editPrId;
  delete form.dataset.editPrStatus;
  const title = document.querySelector('#createPRModal .modal-title');
  if (title) {
    title.innerHTML = '<i class="bi bi-file-earmark-plus text-primary"></i> Create Purchase Request';
  }
}

function resetUnsavedCreatePRForPurposeChange() {
  detachUnsavedCreatePRFromSavedDraft();
  prClearEl('prCustomerOrderRef');
  prClearEl('prOrderDetails');
  prClearEl('prPurposeDetails');
  clearPRCoExtraFields();
  _prSelectedCustomerOrderId = '';
  const coSel = document.getElementById('prCustomerOrderSelect');
  if (coSel) coSel.value = '';
  if (typeof setPRPreferredSupplier === 'function') {
    setPRPreferredSupplier('', { silent: true });
  } else {
    _prPreferredSupplierId = '';
    const hidden = document.getElementById('prSupplierSelect');
    if (hidden) hidden.value = '';
    const search = document.getElementById('prSupplierSearch');
    if (search) search.value = '';
  }
  _prLowStockViewSig = '';
  _prCustomerOrderViewSig = '';
  _prCatalogViewSig = '';
  _prPurposeConfirmOpen = false;
  const lowPanel = document.getElementById('prLowStockPanel');
  if (lowPanel) delete lowPanel.dataset.lowStockRendered;
  const coPanel = document.getElementById('prCustomerOrderPanel');
  if (coPanel) {
    delete coPanel.dataset.loaded;
    delete coPanel.dataset.coRendered;
  }
  const catPanel = document.getElementById('prCatalogPanel');
  if (catPanel) delete catPanel.dataset.catalogRendered;
  clearCreatePRRequestedItemsKeepTable();
}

function applyPRPurposeUI(prev, purpose) {
  const sel = document.getElementById('prPurpose');
  if (sel) sel.dataset.lastPurpose = purpose;

  const coWrap = document.getElementById('prCustomerOrderFieldsWrap');
  const pdWrap = document.getElementById('prPurposeDetailsWrap');
  prSetWrapActive(coWrap, purpose === 'Customer Order');
  prSetWrapActive(pdWrap, purpose === 'Others');
  syncPRPurposeDetailsRequired(purpose);
  syncPRCustomerOrderRefRequired();

  const itemsWrap = document.getElementById('prRequestedItemsSection');
  if (itemsWrap) itemsWrap.style.display = '';

  if (purpose === 'Customer Order') {
    bindPRCustomerOrderCombo();
  } else {
    closePRCustomerOrderMenu();
  }

  syncPRSupplierFieldMode();
  refreshPRPurposeSourcePanels();
  syncPRListPOV();
}

function onPRPurposeChange() {
  const sel = document.getElementById('prPurpose');
  const purpose = String(sel?.value || '').trim();
  const prev = String(_prActivePurposeView || sel?.dataset?.lastPurpose || purposeOfVisibleCreatePRRows() || '');
  _prPurposeConfirmOpen = false;
  if (prev === purpose) {
    applyPRPurposeUI(prev, purpose);
    _prActivePurposeView = purpose;
    return;
  }

  syncCreatePRItemsFromTable();
  if (PR_PURPOSE_KEYS.includes(prev)) {
    commitPRPurposeHeaderFields(prev);
    rememberPRPurposeSupplier(prev, snapshotPreferredSupplier());
  }

  _prSwitchingPurpose = true;
  try {
    applyPRPurposeUI(prev, purpose);
    _prActivePurposeView = purpose;
    if (sel) sel.dataset.lastPurpose = purpose;
    if (PR_PURPOSE_KEYS.includes(purpose)) {
      restorePRPurposeHeaderFields(purpose);
    }
    restampEmptyCreatePRRowsForPurpose(purpose);
  } finally {
    _prSwitchingPurpose = false;
  }
  recalcPRTotal();
}

function flushPRCustomerOrderInputs() {
  if (getCreatePRPurpose() !== 'Customer Order') return;
  const nameSearch = document.getElementById('prCustomerNameSearch');
  if (nameSearch && document.activeElement === nameSearch) {
    acceptTypedPRCustomerName(String(nameSearch.value || '').trim(), { silent: true });
  }
  const poSearch = document.getElementById('prCustomerOrderSearch');
  if (!poSearch || document.activeElement !== poSearch) return;
  const typed = String(poSearch.value || '').trim();
  const selected = getSelectedPRCustomerOrder();
  const selectedPo = selected ? prCustomerOrderRefValue(selected) : '';
  if (typed && typed !== selectedPo) acceptTypedPRCustomerOrderRef(typed);
  else if (!typed) {
    _prSelectedCustomerOrderId = '';
    const hidden = document.getElementById('prCustomerOrderSelect');
    if (hidden) hidden.value = '';
    const refEl = document.getElementById('prCustomerOrderRef');
    if (refEl) refEl.value = '';
    delete poSearch.dataset.customAccepted;
  }
}

function validatePRPurposeFields() {
  flushPRCustomerOrderInputs();
  const purpose = String(document.getElementById('prPurpose')?.value || '').trim();
  const ref = String(document.getElementById('prCustomerOrderRef')?.value || '').trim();
  const orderDetails = String(document.getElementById('prOrderDetails')?.value || '').trim();
  const purposeDetails = String(document.getElementById('prPurposeDetails')?.value || '').trim();
  if (!purpose) {
    pToast('Please select a Purpose', 'warn');
    document.getElementById('prPurpose')?.focus();
    return null;
  }
  if (purpose === 'Customer Order' && !ref) {
    pToast('Please select a Customer Order / PO Reference', 'warn');
    document.getElementById('prCustomerOrderSearch')?.focus();
    return null;
  }
  if (purposeDetailsAreRequired(purpose) && !purposeDetails) {
    pToast('Purpose Details are required.', 'warn');
    document.getElementById('prPurposeDetails')?.focus();
    return null;
  }
  if (ref.length > 120) {
    pToast('Customer Order Reference must be 120 characters or fewer', 'warn');
    document.getElementById('prCustomerOrderRef')?.focus();
    return null;
  }
  const customerName = String(document.getElementById('prCustomerNameValue')?.value || document.getElementById('prCustomerNameSearch')?.value || '').trim();
  return {
    purpose,
    customer_order_reference: purpose === 'Customer Order' ? ref : '',
    customer_name: purpose === 'Customer Order' ? customerName : '',
    remarks: purpose === 'Customer Order' ? orderDetails : (purpose === 'Others' ? purposeDetails : '')
  };
}

function formatPRSuppliers(p) {
  return esc(p.supplier_name || 'Unspecified');
}

function prLinkedPOList(p) {
  const ids = String(p.generated_po_ids || p.generated_po_id || '')
    .split(',').map(s => s.trim()).filter(Boolean);
  const nums = String(p.generated_po_numbers || p.generated_po_number || '')
    .split(',').map(s => s.trim()).filter(Boolean);
  return ids.map((id, i) => ({ id: parseInt(id, 10) || 0, number: nums[i] || ('PO #' + id) })).filter(x => x.id > 0);
}

function purchaserCanReviseRejectedPR(p) {
  return isProcurementPurchaser() && String(p?.status || '') === 'Rejected';
}

function isRevisingRejectedPR() {
  return String(document.getElementById('createPRForm')?.dataset?.editPrStatus || '') === 'Rejected';
}

function prViewEditButtons(id) {
  return `<button class="btn-icon primary" title="View Details" onclick="viewPR(${id})"><i class="bi bi-eye"></i></button>`;
}

function renderPRs(data) {
  const tbody = document.getElementById('prBody');
  if (!tbody) return;
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:#64748b">No Purchase Requests found</td></tr>'; return; }
  tbody.innerHTML = data.map(p => {
    let actions = `${prViewEditButtons(p.id)} `;
    actions += `<button class="btn-icon" title="Print PR" onclick="window.open('print_pr.php?pr_id=${p.id}','_blank')" style="border-color:#7c3aed;color:#7c3aed"><i class="bi bi-printer"></i></button> `;
    if (p.status === 'Draft' && (isProcurementPurchaser() || isProcurementAdmin())) {
      actions += `<button class="btn btn-sm btn-outline-primary" title="Continue Draft" onclick="event.stopPropagation();continueDraftPR(${p.id})"><i class="bi bi-pencil-square me-1"></i> Continue</button> `;
    }
    if (purchaserCanReviseRejectedPR(p)) {
      actions += `<button class="btn btn-sm btn-outline-primary" title="Revise rejected PR" onclick="event.stopPropagation();reviseRejectedPR(${p.id})"><i class="bi bi-pencil-square me-1"></i> Revise</button> `;
    }
    if (p.status === 'Approved') {
      const linked = prLinkedPOList(p);
      if (linked.length) {
        linked.forEach(po => {
          actions += `<button class="btn btn-sm btn-outline-info" title="View Generated PO" onclick="viewPO(${po.id})"><i class="bi bi-file-earmark-text me-1"></i>${esc(po.number)}</button> `;
          if (purchaserCanPrintPO()) {
            actions += `<button class="btn btn-sm btn-primary" title="Print PO" onclick="window.open(printPoUrl(${po.id}),'_blank')"><i class="bi bi-printer"></i></button> `;
          }
        });
      }
    }
    return `<tr>
      <td><strong style="font-family:monospace" class="text-primary">${esc(p.pr_number)}</strong></td>
      <td>${formatPRSuppliers(p)}</td>
      <td style="font-size:12.5px">${esc(formatPRPurpose(p))}${prPurposeListExtra(p)}</td>
      <td><span class="badge badge-gray">${p.item_count} item(s)</span></td>
      <td style="font-weight:700">${fmtMoney(p.total_estimated_amount)}</td>
      <td>${prStatusBadge(p.pipeline_status || p.status)}${p.status === 'Rejected' && p.admin_remarks ? `<div style="font-size:11px;color:#b91c1c;margin-top:4px;max-width:220px;white-space:normal">${esc(p.admin_remarks)}</div>` : ''}</td>
      <td>${esc(p.requested_by_name||'—')}</td>
      <td style="font-size:12px;color:#64748b">${fmtDate(p.created_at)}</td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
}

function findProductById(productId) {
  const id = String(productId || '');
  if (!id) return null;
  return (_poProducts || []).find(p => String(p.id) === id)
      || (_lowStockProducts || []).find(p => String(p.id) === id)
      || null;
}

function prNeedItemSupplierMessage() {
  return 'Please select a supplier before adding a product. It applies only to the next item you add.';
}

function snapshotPreferredSupplier() {
  const v = String(_prPreferredSupplierId || document.getElementById('prSupplierSelect')?.value || '');
  if (v) _prPreferredSupplierId = v;
  return v;
}

function findSupplierById(id) {
  const sid = String(id || '');
  if (!sid) return null;
  return (_poSuppliers || []).find(s => String(s.id) === sid) || null;
}

function supplierComboMeta(s) {
  if (!s) return '';
  const bits = [];
  if (s.status) bits.push(s.status);
  if (s.contact_person) bits.push(s.contact_person);
  if (s.phone) bits.push(s.phone);
  else if (s.email) bits.push(s.email);
  return bits.join(' · ');
}

function showPRSupplierWarn(show) {
  const warn = document.getElementById('prSupplierWarn');
  if (!warn) return;
  warn.hidden = !show;
}

function closePRSupplierMenu() {
  const combo = document.getElementById('prSupplierCombo');
  const menu = document.getElementById('prSupplierMenu');
  const search = document.getElementById('prSupplierSearch');
  if (combo) combo.classList.remove('open');
  if (menu) menu.hidden = true;
  if (search) search.setAttribute('aria-expanded', 'false');
}

function renderSupplierOptionsHtml(selectedId, filter) {
  const q = String(filter || '').trim().toLowerCase();
  const selected = String(selectedId || '');
  const list = (_poSuppliers || []).filter(s => {
    if (!q) return true;
    const hay = [s.name, s.supplier_code, s.contact_person, s.email, s.phone, s.status]
      .map(x => String(x || '').toLowerCase()).join(' ');
    return hay.includes(q);
  });
  if (!list.length) {
    return '<div class="pr-supplier-empty">No matching active suppliers.</div>';
  }
  return list.map(s => {
    const meta = supplierComboMeta(s);
    const isSel = String(s.id) === selected;
    return `<button type="button" class="pr-supplier-opt${isSel ? ' selected' : ''}" role="option" data-supplier-id="${s.id}" aria-selected="${isSel ? 'true' : 'false'}">
      <span class="pr-supplier-opt-name">${esc(s.name)}</span>
      ${meta ? `<span class="pr-supplier-opt-meta">${esc(meta)}</span>` : ''}
    </button>`;
  }).join('');
}

function renderPRSupplierMenu(filter) {
  const menu = document.getElementById('prSupplierMenu');
  if (!menu) return;
  menu.innerHTML = renderSupplierOptionsHtml(_prPreferredSupplierId, filter);
}

function openPRSupplierMenu() {
  closePRCustomerOrderMenu();
  const combo = document.getElementById('prSupplierCombo');
  const menu = document.getElementById('prSupplierMenu');
  const search = document.getElementById('prSupplierSearch');
  if (!combo || !menu) return;
  if (search) search.placeholder = 'Search or select supplier…';
  renderPRSupplierMenu(_prSupplierComboFilter);
  menu.hidden = false;
  combo.classList.add('open');
  if (search) search.setAttribute('aria-expanded', 'true');
}

function syncPRSupplierComboDisplay() {
  const search = document.getElementById('prSupplierSearch');
  const hidden = document.getElementById('prSupplierSelect');
  const sid = String(_prPreferredSupplierId || hidden?.value || '');
  const s = findSupplierById(sid);
  if (hidden && hidden.tagName === 'INPUT') hidden.value = sid;
  if (!search) return;
  if (document.activeElement === search && comboMenuIsOpen()) return;
  search.value = s ? String(s.name || '') : '';
}

function comboMenuIsOpen() {
  const menu = document.getElementById('prSupplierMenu');
  return !!(menu && !menu.hidden);
}

function setPRPreferredSupplier(id, opts = {}) {
  const sid = String(id || '');
  _prPreferredSupplierId = sid;
  const hidden = document.getElementById('prSupplierSelect');
  if (hidden) hidden.value = sid;
  _prSupplierComboFilter = '';
  syncPRSupplierComboDisplay();
  if (!opts.preserveWarn) showPRSupplierWarn(!sid && !!opts.showWarn);
  else if (sid) showPRSupplierWarn(false);
  if (!opts.skipRemember) {
    rememberPRPurposeSupplier(getCreatePRPurpose(), sid);
    const key = getCreatePRPurpose();
    if (PR_PURPOSE_KEYS.includes(key) && sid && !_prRestoringPurposeItems) {
      ensurePRPurposeBucket(key).supplier_id = sid;
    }
  }
  if (!opts.silent) onPRPreferredSupplierChange();
}

function getPRRowSupplierId(row) {
  const idx = row?.dataset?.idx;
  return String(row?.dataset?.supplierId || row?.querySelector(`[name="items[${idx}][supplier_id]"]`)?.value || '');
}

function setPRRowSupplier(row, supplierId) {
  if (!row) return;
  const sid = String(supplierId || '');
  row.dataset.supplierId = sid;
  const idx = row.dataset.idx;
  const hid = row.querySelector(`[name="items[${idx}][supplier_id]"]`);
  if (hid) hid.value = sid;
  const s = findSupplierById(sid);
  const label = s ? String(s.name || '') : (sid ? '—' : '—');
  const nameEl = row.querySelector('.pr-row-supplier-name');
  if (nameEl) nameEl.textContent = label;
  const search = row.querySelector('.pr-row-supplier-combo .pr-supplier-search');
  if (search && document.activeElement !== search) search.value = s ? String(s.name || '') : '';
}

function applyHeaderSupplierToPRRows() {
  if (!isPRReviewModalOpen()) return;
  const sid = getSelectedPRSupplierId();
  document.getElementById('prReviewItemsBody')?.querySelectorAll('tr').forEach(row => setPRRowSupplier(row, sid));
}

function stampPRRowSupplier(row, supplierId) {
  const sid = supplierId !== undefined && supplierId !== null
    ? String(supplierId)
    : getPRRowSupplierId(row);
  setPRRowSupplier(row, sid);
}

function setPRRowSupplierEnabled(row) {
  stampPRRowSupplier(row);
}

function requirePRSupplierForAdd() {
  if (isPRReviewModalOpen()) {
    return getSelectedPRSupplierId();
  }
  if (isPRPerItemSupplierMode()) return '';
  const sid = snapshotPreferredSupplier();
  if (sid) {
    showPRSupplierWarn(false);
    return sid;
  }
  showPRSupplierWarn(true);
  document.getElementById('prSupplierSearch')?.focus();
  pToast(prNeedItemSupplierMessage(), 'warn');
  return '';
}

function closePRRowSupplierMenu(combo) {
  const closingCurrent = !combo || combo === _prRowSupplierMenuAnchor;
  const target = combo || _prRowSupplierMenuAnchor;
  if (target) {
    target.classList.remove('open');
    const search = target.querySelector('.pr-supplier-search');
    if (search) search.setAttribute('aria-expanded', 'false');
  }
  if (!closingCurrent) return;
  const portal = document.getElementById('prRowSupplierMenuPortal');
  if (portal) {
    portal.hidden = true;
    portal.innerHTML = '';
    portal.classList.remove('open-up');
  }
  _prRowSupplierMenuAnchor = null;
  _prRowSupplierMenuRow = null;
}

function closeAllPRSupplierMenus(exceptCombo) {
  closePRSupplierMenu();
  document.querySelectorAll('.pr-row-supplier-combo').forEach(combo => {
    if (combo !== exceptCombo) closePRRowSupplierMenu(combo);
  });
  if (!exceptCombo) closePRRowSupplierMenu();
}

function getPRRowSupplierMenuPortal() {
  let el = document.getElementById('prRowSupplierMenuPortal');
  if (!el) {
    el = document.createElement('div');
    el.id = 'prRowSupplierMenuPortal';
    el.className = 'pr-supplier-menu pr-supplier-menu-portal';
    el.hidden = true;
    el.setAttribute('role', 'listbox');
    document.body.appendChild(el);
    el.addEventListener('mousedown', (e) => {
      const opt = e.target.closest('.pr-supplier-opt');
      if (!opt) {
        e.preventDefault();
        return;
      }
      e.preventDefault();
      if (_prRowSupplierMenuRow) setPRRowSupplier(_prRowSupplierMenuRow, opt.dataset.supplierId);
      closePRRowSupplierMenu(_prRowSupplierMenuAnchor);
    });
  }
  return el;
}

function positionPRRowSupplierMenu(combo, menu) {
  if (!combo || !menu) return;
  const anchor = combo.querySelector('.pr-supplier-search') || combo;
  const rect = anchor.getBoundingClientRect();
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const gap = 6;
  const pad = 8;
  const width = Math.min(Math.max(rect.width, 240), Math.min(360, vw - pad * 2));
  let left = rect.left;
  if (left + width > vw - pad) left = Math.max(pad, vw - width - pad);
  if (left < pad) left = pad;

  const maxMenuH = Math.min(280, Math.round(vh * 0.45));
  const spaceBelow = vh - rect.bottom - gap - pad;
  const spaceAbove = rect.top - gap - pad;
  const openUp = spaceBelow < 160 && spaceAbove > spaceBelow;
  const avail = Math.max(120, openUp ? spaceAbove : spaceBelow);
  const height = Math.min(maxMenuH, avail);

  menu.style.position = 'fixed';
  menu.style.zIndex = '20000';
  menu.style.width = `${width}px`;
  menu.style.maxHeight = `${height}px`;
  menu.style.left = `${left}px`;
  menu.style.right = 'auto';
  menu.style.overflowY = 'auto';
  menu.style.overflowX = 'hidden';
  if (openUp) {
    menu.style.top = 'auto';
    menu.style.bottom = `${vh - rect.top + gap}px`;
    menu.classList.add('open-up');
  } else {
    menu.style.bottom = 'auto';
    menu.style.top = `${rect.bottom + gap}px`;
    menu.classList.remove('open-up');
  }
}

function onPRRowSupplierMenuReposition() {
  if (!_prRowSupplierMenuAnchor) return;
  const portal = document.getElementById('prRowSupplierMenuPortal');
  if (!portal || portal.hidden) return;
  positionPRRowSupplierMenu(_prRowSupplierMenuAnchor, portal);
}

function bindPRRowSupplierMenuReposition() {
  if (window._prRowSupplierReposBound) return;
  window._prRowSupplierReposBound = true;
  window.addEventListener('resize', onPRRowSupplierMenuReposition);
  window.addEventListener('scroll', onPRRowSupplierMenuReposition, true);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', onPRRowSupplierMenuReposition);
    window.visualViewport.addEventListener('scroll', onPRRowSupplierMenuReposition);
  }
}

function isPRRowSupplierMenuEvent(e) {
  const portal = document.getElementById('prRowSupplierMenuPortal');
  return !!(portal && e.target && portal.contains(e.target));
}

function openPRRowSupplierMenu(row, combo) {
  const search = combo?.querySelector('.pr-supplier-search');
  if (!combo || combo.hidden || !row) return;
  closeAllPRSupplierMenus(combo);
  const portal = getPRRowSupplierMenuPortal();
  bindPRRowSupplierMenuReposition();
  const selectedName = String(findSupplierById(getPRRowSupplierId(row))?.name || '');
  const typed = String(search?.value || '');
  const filter = typed && typed !== selectedName ? typed : '';
  portal.innerHTML = renderSupplierOptionsHtml(getPRRowSupplierId(row), filter);
  portal.hidden = false;
  combo.classList.add('open');
  if (search) search.setAttribute('aria-expanded', 'true');
  _prRowSupplierMenuAnchor = combo;
  _prRowSupplierMenuRow = row;
  positionPRRowSupplierMenu(combo, portal);
  const selected = portal.querySelector('.pr-supplier-opt.selected');
  if (selected) selected.scrollIntoView({ block: 'nearest' });
}

function bindPRRowSupplierCombo(row) {
  const combo = row?.querySelector('.pr-row-supplier-combo');
  if (!combo || combo.dataset.bound === '1') return;
  const search = combo.querySelector('.pr-supplier-search');
  const toggle = combo.querySelector('.pr-supplier-combo-caret');
  if (!search) return;
  combo.dataset.bound = '1';

  search.addEventListener('focus', () => {
    openPRRowSupplierMenu(row, combo);
    search.select();
  });
  search.addEventListener('input', () => openPRRowSupplierMenu(row, combo));
  search.addEventListener('keydown', (e) => {
    const portal = document.getElementById('prRowSupplierMenuPortal');
    if (e.key === 'Escape') {
      closePRRowSupplierMenu(combo);
      setPRRowSupplier(row, getPRRowSupplierId(row));
      search.blur();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const first = portal?.querySelector('.pr-supplier-opt');
      if (first) setPRRowSupplier(row, first.dataset.supplierId);
      closePRRowSupplierMenu(combo);
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      openPRRowSupplierMenu(row, combo);
      portal?.querySelector('.pr-supplier-opt')?.focus();
    }
  });
  search.addEventListener('blur', () => {
    setTimeout(() => {
      const portal = document.getElementById('prRowSupplierMenuPortal');
      if (combo.contains(document.activeElement) || (portal && portal.contains(document.activeElement))) return;
      closePRRowSupplierMenu(combo);
      setPRRowSupplier(row, getPRRowSupplierId(row));
    }, 140);
  });
  toggle?.addEventListener('mousedown', (e) => {
    e.preventDefault();
    if (!combo.hidden && combo.classList.contains('open') && _prRowSupplierMenuAnchor === combo) {
      closePRRowSupplierMenu(combo);
    } else {
      search.focus();
      openPRRowSupplierMenu(row, combo);
    }
  });
}

function bindPRSupplierCombo() {
  const combo = document.getElementById('prSupplierCombo');
  const search = document.getElementById('prSupplierSearch');
  const toggle = document.getElementById('prSupplierToggle');
  const menu = document.getElementById('prSupplierMenu');
  const hidden = document.getElementById('prSupplierSelect');
  if (!combo || !search || !menu || !hidden) return;
  if (combo.dataset.bound === '1') {
    syncPRSupplierComboDisplay();
    return;
  }
  combo.dataset.bound = '1';
  _prSupplierComboBound = true;
  search.placeholder = 'Search or select supplier…';

  search.addEventListener('focus', () => {
    _prSupplierComboFilter = '';
    openPRSupplierMenu();
    search.select();
  });
  search.addEventListener('input', () => {
    _prSupplierComboFilter = search.value;
    openPRSupplierMenu();
  });
  search.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closePRSupplierMenu();
      syncPRSupplierComboDisplay();
      search.blur();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const first = menu.querySelector('.pr-supplier-opt');
      if (first) setPRPreferredSupplier(first.dataset.supplierId, { silent: false });
      closePRSupplierMenu();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      openPRSupplierMenu();
      menu.querySelector('.pr-supplier-opt')?.focus();
    }
  });
  search.addEventListener('blur', () => {
    setTimeout(() => {
      if (combo.contains(document.activeElement)) return;
      closePRSupplierMenu();
      syncPRSupplierComboDisplay();
      if (isPRHeaderSupplierMode() && !String(_prPreferredSupplierId || hidden.value || '')) showPRSupplierWarn(true);
    }, 140);
  });
  toggle.addEventListener('mousedown', (e) => {
    e.preventDefault();
    if (comboMenuIsOpen()) closePRSupplierMenu();
    else {
      search.focus();
      openPRSupplierMenu();
    }
  });
  menu.addEventListener('mousedown', (e) => {
    const opt = e.target.closest('.pr-supplier-opt');
    if (!opt) return;
    e.preventDefault();
    setPRPreferredSupplier(opt.dataset.supplierId);
    closePRSupplierMenu();
  });
  document.addEventListener('mousedown', (e) => {
    if (isPRRowSupplierMenuEvent(e)) return;
    if (e.target.closest('#prCustomerOrderCombo')) return;
    if (!combo.contains(e.target)) closePRSupplierMenu();
    document.querySelectorAll('.pr-row-supplier-combo.open').forEach(rowCombo => {
      if (!rowCombo.contains(e.target)) closePRRowSupplierMenu(rowCombo);
    });
  });
}

function buildPRProductOptions(selectedId) {
  const sel = String(selectedId || '');
  const taken = new Set(getPRSelectedProductIds());
  const available = (_poProducts || []).filter(p => {
    const id = String(p.id);
    if (id === sel) return true;
    if (taken.has(id)) return false;
    return true;
  });
  const low = available.filter(p => Number(p.is_low_stock) === 1);
  const ok  = available.filter(p => Number(p.is_low_stock) !== 1);
  const renderOpt = (p) => {
    const lowFlag = Number(p.is_low_stock) === 1;
    const label = lowFlag
      ? `[${p.sku}] ${p.name} — Low Stock`
      : `[${p.sku}] ${p.name}`;
    return `<option value="${p.id}"
      data-price="${p.cost_price || 0}"
      data-name="${esc(p.name)}"
      data-brand="${esc(p.brand || '')}"
      data-unit="${esc(p.unit_of_measure || '')}"
      data-stock="${p.quantity_in_stock ?? 0}"
      data-reorder="${p.reorder_level ?? 0}"
      data-suggested="${p.suggested_quantity ?? p.reorder_quantity ?? 1}"
      data-supplier-id="${p.supplier_id || ''}"
      data-supplier-name="${esc(p.supplier_name || '')}"
      data-low-stock="${lowFlag ? 1 : 0}"
      ${String(p.id) === sel ? ' selected' : ''}>${esc(label)}</option>`;
  };
  let html = '<option value="">-- Select Catalog Item --</option>';
  if (low.length) {
    html += `<optgroup label="Low Stock (needs PR)">${low.map(renderOpt).join('')}</optgroup>`;
  }
  if (ok.length) {
    html += `<optgroup label="Sufficient Stock">${ok.map(renderOpt).join('')}</optgroup>`;
  }
  return html;
}

function buildPRSupplierOptions(selectedId) {
  const sel = String(selectedId || '');
  return '<option value="">-- Select Supplier --</option>' +
    (_poSuppliers || []).map(s =>
      `<option value="${s.id}"${String(s.id) === sel ? ' selected' : ''}>${esc(s.name)}</option>`
    ).join('');
}

function applyPRSupplierFromProduct() {
  // Never copy a product catalog supplier onto the row or the PR.
}

function updatePRItemMeta(row, product) {
  if (!row) return;
  let meta = row.querySelector('.pr-item-meta');
  if (!meta) {
    const firstTd = row.querySelector('td');
    if (!firstTd) return;
    meta = document.createElement('div');
    meta.className = 'pr-item-meta';
    meta.style.cssText = 'margin-top:6px;font-size:11px;color:#64748b;line-height:1.35';
    firstTd.appendChild(meta);
  }
  if (!product) {
    meta.innerHTML = '';
    return;
  }
  const low = Number(product.is_low_stock) === 1;
  meta.innerHTML = `
    ${low ? '<span class="badge badge-orange" style="margin-right:6px">Low Stock</span>' : ''}
    Brand: <strong>${esc(product.brand || '—')}</strong> ·
    Stock: <strong style="color:${low ? '#dc2626' : '#0f172a'}">${product.quantity_in_stock ?? 0}</strong>
    / Min: <strong>${product.reorder_level ?? 0}</strong> ·
    Unit: <strong>${esc(product.unit_of_measure || '—')}</strong>
  `;
}

function isEditingExistingPR() {
  if (isPRReviewModalOpen()) return true;
  return !!(document.getElementById('createPRForm')?.dataset?.editPrId);
}

function isPRReviewModalOpen() {
  const el = document.getElementById('prDetailModal');
  return !!(el && el.classList.contains('open') && document.getElementById('prReviewForm'));
}

function getPRItemsTbody() {
  if (isPRReviewModalOpen()) return document.getElementById('prReviewItemsBody');
  return document.getElementById('prItemsBody');
}

function getPRSupplierSelect() {
  if (isPRReviewModalOpen()) return document.getElementById('prReviewSupplierSelect');
  return document.getElementById('prSupplierSelect');
}

function getPRRemarksEl() {
  if (isPRReviewModalOpen()) return document.getElementById('prReviewRemarks');
  return document.getElementById('prRemarks');
}

function getPRReasonEl() {
  if (isPRReviewModalOpen()) return document.getElementById('prReviewReason');
  return document.getElementById('prReason');
}

function getPRGrandTotalEl() {
  if (isPRReviewModalOpen()) return document.getElementById('prReviewGrandTotal');
  return document.getElementById('prGrandTotal');
}

function getPREditId() {
  if (isPRReviewModalOpen()) return document.getElementById('prReviewForm')?.dataset?.editPrId || '';
  return document.getElementById('createPRForm')?.dataset?.editPrId || '';
}

function canAdminEditPR(p) {
  return isProcurementAdmin() && ['Draft', 'Pending Approval'].includes(p.status);
}

function onPRReviewSupplierChange() {
  applyHeaderSupplierToPRRows();
}

function getSelectedPRSupplierId() {
  if (isPRReviewModalOpen()) {
    return String(document.getElementById('prReviewSupplierSelect')?.value || '');
  }
  return String(_prPreferredSupplierId || document.getElementById('prSupplierSelect')?.value || '');
}

function availablePRLowStockProducts(data) {
  const seen = new Set();
  return (data || []).filter(p => {
    const id = String(p.id || '');
    if (!id || seen.has(id)) return false;
    seen.add(id);
    return true;
  });
}

function visiblePRLowStockProducts() {
  const taken = new Set(getPRSelectedProductIds());
  return (_lowStockProducts || []).filter(p => !taken.has(String(p.id)));
}

function prLowStockViewSignature(visible) {
  return (visible || []).map(p => String(p.id)).join(',');
}

function bindPRSupplierLowStockFilter() {
  // Low-stock list is not filtered by catalog or preferred supplier.
}

function onPRPreferredSupplierChange() {
  if (!isEditingExistingPR()) {
    renderPRLowStockPanel();
    if (shouldShowPRCatalogPanel()) renderPRCatalogPanel();
    if (shouldShowPRCustomerOrderPanel()) renderPRCustomerOrderPanel();
  }
  syncPRLowStockAddButtons();
}

function renderPRLowStockPanel(data) {
  const panel = document.getElementById('prLowStockPanel');
  if (!panel) return;
  if (isEditingExistingPR()) return;
  if (arguments.length > 0) {
    _lowStockProducts = availablePRLowStockProducts(data || []);
  }
  syncPRLowStockPanelVisibility();
  if (!shouldShowPRLowStockPanel()) return;
  const visible = visiblePRLowStockProducts();
  const sig = prLowStockViewSignature(visible);
  if (arguments.length === 0 && sig === _prLowStockViewSig && panel.dataset.lowStockRendered === '1') return;
  _prLowStockViewSig = sig;
  panel.dataset.lowStockRendered = '1';

  if (!visible.length) {
    const total = (_lowStockProducts || []).length;
    const emptyMsg = total > 0
      ? 'All auto-detected low-stock products are already in this request.'
      : 'No low-stock products right now.';
    panel.innerHTML = `
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px;margin-bottom:12px">
      <div style="font-size:13px;font-weight:700;color:#9a3412;margin-bottom:6px">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>Low Stock — Auto-detected (<span id="prLowStockVisibleCount">0</span>)
      </div>
      <div style="font-size:12.5px;color:#9a3412">${emptyMsg}</div>
    </div>`;
    return;
  }

  panel.innerHTML = `
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px;margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
        <div style="font-size:13px;font-weight:700;color:#9a3412">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>Low Stock — Auto-detected (<span id="prLowStockVisibleCount">${visible.length}</span>)
        </div>
        <button type="button" class="btn btn-sm btn-warning" onclick="addAllLowStockToPR()" style="font-size:11px">
          <i class="bi bi-plus-circle me-1"></i>Add All Low Stock
        </button>
      </div>
      <div class="pr-lowstock-list system-table-scroll">
        <table class="modern-table" style="margin-bottom:0">
          <thead>
            <tr>
              <th>Product</th>
              <th>Current Stock</th>
              <th>Minimum Stock</th>
              <th>Unit</th>
              <th>Suggested Qty</th>
              <th style="width:80px"></th>
            </tr>
          </thead>
          <tbody>
            ${visible.map(p => `
              <tr data-lowstock-id="${p.id}">
                <td>
                  <div style="font-size:12.5px;font-weight:650;color:#0f172a">
                    ${esc(p.name)} <span class="badge badge-orange">Low Stock</span>
                  </div>
                </td>
                <td><strong style="color:#dc2626">${p.quantity_in_stock}</strong></td>
                <td>${p.reorder_level}</td>
                <td>${esc(p.unit_of_measure || '—')}</td>
                <td>${p.suggested_quantity || p.reorder_quantity || 1}</td>
                <td>
                  <button type="button" class="btn btn-sm btn-outline-primary pr-lowstock-add" data-product-id="${p.id}" style="font-size:11px;white-space:nowrap" onclick="addPRItemFromProduct(${p.id})">
                    <i class="bi bi-plus-lg me-1"></i>Add
                  </button>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
}

function loadPRLowStockPanel(cb) {
  const panel = document.getElementById('prLowStockPanel');
  syncPRLowStockPanelVisibility();
  if (panel && shouldShowPRLowStockPanel()) {
    panel.innerHTML = `<div style="text-align:center;padding:10px;color:#64748b;font-size:12px;margin-bottom:12px">
      <div class="spinner-border spinner-border-sm me-2"></div> Detecting low-stock products…
    </div>`;
  }
  pApi({ query:{ action:'get_reorder_suggestions' } }, r => {
    renderPRLowStockPanel(r.success ? (r.data || []) : []);
    syncPRLowStockAddButtons();
    if (typeof cb === 'function') cb(_lowStockProducts);
  });
}

function prCustomerOrderLabel(o) {
  const po = String(o.customer_po_number || '').trim();
  const num = String(o.order_number || '').trim();
  const name = String(o.customer_name || '').trim();
  const ref = po || num || ('Order #' + o.id);
  return name ? `${ref} — ${name}` : ref;
}

function prCustomerOrderRefValue(o) {
  if (!o) return '';
  return String(o.customer_po_number || o.order_number || '').trim();
}

function prCustomerOrdersHaveData() {
  return _prCustomerOrdersAvailable && (_customerOrdersForPR || []).length > 0;
}

function prCustomerOrderMatchesFilter(o, filter) {
  const q = String(filter || '').trim().toLowerCase();
  if (!q) return true;
  const po = String(o.customer_po_number || '').toLowerCase();
  const num = String(o.order_number || '').toLowerCase();
  const name = String(o.customer_name || '').toLowerCase();
  const label = prCustomerOrderLabel(o).toLowerCase();
  return name.includes(q) || po.includes(q) || num.includes(q) || label.includes(q);
}

function bindPRCustomerOrderRefInput() {
  const refEl = document.getElementById('prCustomerOrderRef');
  if (!refEl || refEl.dataset.bound === '1') return;
  refEl.dataset.bound = '1';
  refEl.addEventListener('input', () => {
    const typed = String(refEl.value || '').trim().toLowerCase();
    const current = getSelectedPRCustomerOrder();
    const currentRef = current ? String(prCustomerOrderRefValue(current) || '').trim().toLowerCase() : '';
    if (current && typed === currentRef) return;
    const match = typed ? (_customerOrdersForPR || []).find(o => {
      const po = String(o.customer_po_number || '').trim().toLowerCase();
      const num = String(o.order_number || '').trim().toLowerCase();
      return (po && po === typed) || (num && num === typed);
    }) : null;
    const hidden = document.getElementById('prCustomerOrderSelect');
    if (match) {
      if (String(_prSelectedCustomerOrderId) === String(match.id)) return;
      _prSelectedCustomerOrderId = String(match.id);
      if (hidden) hidden.value = String(match.id);
    } else if (_prSelectedCustomerOrderId) {
      _prSelectedCustomerOrderId = '';
      if (hidden) hidden.value = '';
    } else {
      return;
    }
    _prCustomerOrderViewSig = '';
    renderPRCustomerOrderPanel();
  });
}

function syncPRCustomerOrderRefRequired() {
  const purpose = getCreatePRPurpose();
  const isCO = purpose === 'Customer Order';
  const refEl = document.getElementById('prCustomerOrderRef');
  if (refEl) refEl.required = isCO && !String(_prSelectedCustomerOrderId || document.getElementById('prCustomerOrderSelect')?.value || '').trim();
  const combo = document.getElementById('prCustomerOrderCombo');
  const selLabel = document.getElementById('prCustomerOrderSelectLabel');
  const refLabel = document.getElementById('prCustomerOrderRefLabel');
  const nameLabel = document.getElementById('prCustomerNameLabel');
  const addBtn = document.getElementById('prCoAddCustomerBtn');
  const companyBtn = document.getElementById('prCoAddCompanyBtn');
  if (combo) combo.style.display = isCO ? '' : 'none';
  if (selLabel) selLabel.style.display = isCO ? '' : 'none';
  if (nameLabel) nameLabel.style.display = isCO ? '' : 'none';
  if (addBtn) addBtn.style.display = isCO ? '' : 'none';
  if (companyBtn) companyBtn.style.display = isCO ? '' : 'none';
  if (refEl) refEl.style.display = 'none';
  if (refLabel) refLabel.style.display = isCO ? '' : 'none';
  const selectRow = document.getElementById('prCustomerOrderSelectRow');
  if (selectRow) selectRow.style.display = isCO ? '' : 'none';
  if (isCO) bindPRCustomerNameCombo();
  if (!isCO) {
    closePRCustomerOrderMenu();
    closePRCustomerNameMenu();
  }
}

function getSelectedPRCustomerOrder() {
  const id = String(_prSelectedCustomerOrderId || document.getElementById('prCustomerOrderSelect')?.value || '');
  if (!id) return null;
  return (_customerOrdersForPR || []).find(o => String(o.id) === id) || null;
}

function findPRCustomerOrderProduct(productId) {
  const id = String(productId || '');
  if (!id) return null;
  const order = getSelectedPRCustomerOrder();
  const item = (order?.items || []).find(i => String(i.product_id) === id);
  if (!item) return null;
  return {
    id: item.product_id,
    name: item.product_name,
    sku: item.sku,
    cost_price: item.cost_price,
    unit_of_measure: item.unit,
    suggested_quantity: Math.max(1, parseInt(item.required_qty, 10) || 1),
    quantity_in_stock: item.available_qty,
    sellable_qty: item.available_qty
  };
}

function matchPRCustomerOrderFromRef() {
  const ref = String(document.getElementById('prCustomerOrderRef')?.value || '').trim().toLowerCase();
  const hidden = document.getElementById('prCustomerOrderSelect');
  if (!hidden || !ref) return;
  const match = (_customerOrdersForPR || []).find(o => {
    const po = String(o.customer_po_number || '').trim().toLowerCase();
    const num = String(o.order_number || '').trim().toLowerCase();
    return po === ref || num === ref;
  });
  if (!match) return;
  _prSelectedCustomerOrderId = String(match.id);
  hidden.value = String(match.id);
  syncPRCustomerOrderComboDisplay();
}

function closePRCustomerOrderMenu() {
  const combo = document.getElementById('prCustomerOrderCombo');
  const menu = document.getElementById('prCustomerOrderMenu');
  const search = document.getElementById('prCustomerOrderSearch');
  if (combo) combo.classList.remove('open');
  if (menu) menu.hidden = true;
  if (search) search.setAttribute('aria-expanded', 'false');
}

function syncPRCustomerOrderComboDisplay() {
  const search = document.getElementById('prCustomerOrderSearch');
  const hidden = document.getElementById('prCustomerOrderSelect');
  if (!search) return;
  const id = String(_prSelectedCustomerOrderId || hidden?.value || '');
  if (hidden) hidden.value = id;
  if (document.activeElement === search && document.getElementById('prCustomerOrderCombo')?.classList.contains('open')) return;
  const order = id ? (_customerOrdersForPR || []).find(o => String(o.id) === id) : null;
  if (order) {
    delete search.dataset.customAccepted;
    search.value = prCustomerOrderRefValue(order) || prCustomerOrderLabel(order);
    return;
  }
  const accepted = String(search.dataset.customAccepted || '').trim();
  if (accepted) {
    search.value = accepted;
    const refEl = document.getElementById('prCustomerOrderRef');
    if (refEl) refEl.value = accepted;
    return;
  }
  search.value = '';
}

function openPRCustomerOrderMenu() {
  closePRSupplierMenu();
  closePRCustomerNameMenu();
  const combo = document.getElementById('prCustomerOrderCombo');
  const menu = document.getElementById('prCustomerOrderMenu');
  const search = document.getElementById('prCustomerOrderSearch');
  if (!combo || !menu || !search || combo.style.display === 'none') return;
  const filter = String(_prCustomerOrderFilter != null ? _prCustomerOrderFilter : '').trim();
  const selectedId = String(_prSelectedCustomerOrderId || document.getElementById('prCustomerOrderSelect')?.value || '');
  const customerName = String(document.getElementById('prCustomerNameValue')?.value || '').trim().toLowerCase();
  const scoped = customerName
    ? (_customerOrdersForPR || []).filter(o => String(o.customer_name || '').trim().toLowerCase() === customerName)
    : (_customerOrdersForPR || []);
  const list = scoped.filter(o => prCustomerOrderMatchesFilter(o, filter));
  if (!list.length) {
    menu.innerHTML = `<div class="pr-supplier-opt" style="cursor:default;color:#64748b">${filter || customerName ? 'No matching customer orders' : 'No customer orders on file'}</div>`;
  } else {
    menu.innerHTML = list.map(o => {
      const sel = String(o.id) === selectedId ? ' selected' : '';
      const po = prCustomerOrderRefValue(o) || ('Order #' + o.id);
      return `<button type="button" class="pr-supplier-opt${sel}" role="option" data-customer-order-id="${esc(String(o.id))}">
        <div class="pr-supplier-opt-name">${esc(po)}</div>
      </button>`;
    }).join('');
  }
  menu.hidden = false;
  combo.classList.add('open');
  search.setAttribute('aria-expanded', 'true');
}

function setPRSelectedCustomerOrder(id, opts = {}) {
  const oid = String(id || '');
  _prSelectedCustomerOrderId = oid;
  const hidden = document.getElementById('prCustomerOrderSelect');
  if (hidden) hidden.value = oid;
  _prCustomerOrderFilter = '';
  const order = getSelectedPRCustomerOrder();
  const refEl = document.getElementById('prCustomerOrderRef');
  if (refEl) refEl.value = prCustomerOrderRefValue(order);
  const partyName = String(order?.customer_name || '').trim();
  const acceptedName = String(document.getElementById('prCustomerNameValue')?.value || document.getElementById('prCustomerNameSearch')?.dataset?.customAccepted || '').trim();
  if (partyName && !acceptedName) acceptTypedPRCustomerName(partyName, { silent: true });
  closePRCustomerOrderMenu();
  syncPRCustomerOrderComboDisplay();
  const poSearch = document.getElementById('prCustomerOrderSearch');
  const keptRef = String(document.getElementById('prCustomerOrderRef')?.value || '').trim();
  if (poSearch && keptRef) poSearch.dataset.customAccepted = keptRef;
  _prCustomerOrderViewSig = '';
  if (!opts.skipRender) renderPRCustomerOrderPanel();
  if (!opts.silent) commitCurrentPRPurposeItems();
}

function populatePRCustomerOrderSelect() {
  const keep = String(_prSelectedCustomerOrderId || document.getElementById('prCustomerOrderSelect')?.value || '');
  const orders = _customerOrdersForPR || [];
  if (keep && orders.some(o => String(o.id) === keep)) {
    _prSelectedCustomerOrderId = keep;
    const hidden = document.getElementById('prCustomerOrderSelect');
    if (hidden) hidden.value = keep;
  } else if (!keep) {
    matchPRCustomerOrderFromRef();
  } else {
    _prSelectedCustomerOrderId = '';
    const hidden = document.getElementById('prCustomerOrderSelect');
    if (hidden) hidden.value = '';
  }
  syncPRCustomerOrderRefRequired();
  syncPRCustomerOrderComboDisplay();
  bindPRCustomerOrderCombo();
}

function prRefsForCustomerName(name) {
  const key = String(name || '').trim().toLowerCase();
  const refs = [];
  const seen = new Set();
  const add = (ref) => {
    const value = String(ref || '').trim();
    if (!value || seen.has(value.toLowerCase())) return;
    seen.add(value.toLowerCase());
    refs.push(value);
  };
  if (!key) return refs;
  (_customerOrdersForPR || []).forEach(o => {
    if (String(o.customer_name || '').trim().toLowerCase() === key) add(prCustomerOrderRefValue(o));
  });
  (_prPartyNames || []).forEach(p => {
    if (String(p.name || '').trim().toLowerCase() === key) add(p.po_reference);
  });
  return refs;
}

function applyPRCustomerOrderRefValue(value) {
  const next = String(value || '').trim();
  _prSelectedCustomerOrderId = '';
  _prCustomerOrderFilter = '';
  const hidden = document.getElementById('prCustomerOrderSelect');
  if (hidden) hidden.value = '';
  const refEl = document.getElementById('prCustomerOrderRef');
  if (refEl) refEl.value = next;
  const search = document.getElementById('prCustomerOrderSearch');
  if (search) {
    search.value = next;
    if (next) search.dataset.customAccepted = next;
    else delete search.dataset.customAccepted;
  }
}

function refreshPRCustomerOrderRefForName(name) {
  const key = String(name || '').trim().toLowerCase();
  const refs = prRefsForCustomerName(name);
  const refEl = document.getElementById('prCustomerOrderRef');
  const search = document.getElementById('prCustomerOrderSearch');
  const current = String(refEl?.value || search?.dataset?.customAccepted || '').trim();
  const belongs = !!current && refs.some(ref => ref.toLowerCase() === current.toLowerCase());
  const orderFor = (ref) => (_customerOrdersForPR || []).find(o =>
    String(o.customer_name || '').trim().toLowerCase() === key
    && prCustomerOrderRefValue(o).toLowerCase() === String(ref || '').trim().toLowerCase()
  );
  if (refs.length === 1 && !belongs) {
    const order = orderFor(refs[0]);
    if (order) setPRSelectedCustomerOrder(order.id, { silent: true });
    else applyPRCustomerOrderRefValue(refs[0]);
    return;
  }
  if (belongs) {
    const order = orderFor(current);
    if (order && String(_prSelectedCustomerOrderId) !== String(order.id)) {
      setPRSelectedCustomerOrder(order.id, { silent: true });
    }
    return;
  }
  applyPRCustomerOrderRefValue('');
}

function acceptTypedPRCustomerOrderRef(typed) {
  const value = String(typed || '').trim();
  if (!value) return;
  _prSelectedCustomerOrderId = '';
  _prCustomerOrderFilter = '';
  const hidden = document.getElementById('prCustomerOrderSelect');
  if (hidden) hidden.value = '';
  const refEl = document.getElementById('prCustomerOrderRef');
  if (refEl) refEl.value = value;
  const search = document.getElementById('prCustomerOrderSearch');
  if (search) {
    search.dataset.customAccepted = value;
    search.value = value;
  }
  closePRCustomerOrderMenu();
  _prCustomerOrderViewSig = '';
  renderPRCustomerOrderPanel();
  commitCurrentPRPurposeItems();
}

function onPRCustomerOrderSelectChange() {
  const id = String(document.getElementById('prCustomerOrderSelect')?.value || '');
  setPRSelectedCustomerOrder(id);
}

function bindPRCustomerOrderCombo() {
  const combo = document.getElementById('prCustomerOrderCombo');
  const search = document.getElementById('prCustomerOrderSearch');
  const toggle = document.getElementById('prCustomerOrderToggle');
  const menu = document.getElementById('prCustomerOrderMenu');
  const hidden = document.getElementById('prCustomerOrderSelect');
  if (!combo || !search || !menu || !hidden) return;
  if (combo.dataset.bound === '1') {
    syncPRCustomerOrderComboDisplay();
    return;
  }
  combo.dataset.bound = '1';

  search.addEventListener('focus', () => {
    _prCustomerOrderFilter = '';
    openPRCustomerOrderMenu();
    search.select();
  });
  search.addEventListener('input', () => {
    _prCustomerOrderFilter = search.value;
    if (hidden.value) {
      const sel = (_customerOrdersForPR || []).find(o => String(o.id) === String(hidden.value));
      const shown = String(search.value || '').trim();
      const label = sel ? prCustomerOrderLabel(sel) : '';
      const po = sel ? prCustomerOrderRefValue(sel) : '';
      if (!sel || (shown !== label && shown !== po)) {
        hidden.value = '';
        _prSelectedCustomerOrderId = '';
        const refEl = document.getElementById('prCustomerOrderRef');
        if (refEl && prCustomerOrdersHaveData()) refEl.value = '';
        _prCustomerOrderViewSig = '';
      }
    }
    openPRCustomerOrderMenu();
    renderPRCustomerOrderPanel();
  });
  search.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closePRCustomerOrderMenu();
      syncPRCustomerOrderComboDisplay();
      search.blur();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const typed = String(search.value || '').trim();
      const exact = [...menu.querySelectorAll('.pr-supplier-opt[data-customer-order-id]')].find((btn) => {
        const order = (_customerOrdersForPR || []).find(o => String(o.id) === String(btn.dataset.customerOrderId));
        if (!order || !typed) return false;
        return typed === prCustomerOrderRefValue(order) || typed === prCustomerOrderLabel(order);
      });
      if (exact) {
        setPRSelectedCustomerOrder(exact.dataset.customerOrderId);
        closePRCustomerOrderMenu();
      } else if (typed) {
        acceptTypedPRCustomerOrderRef(typed);
      }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      openPRCustomerOrderMenu();
      menu.querySelector('.pr-supplier-opt[data-customer-order-id]')?.focus();
    }
  });
  search.addEventListener('blur', () => {
    setTimeout(() => {
      if (combo.contains(document.activeElement)) return;
      closePRCustomerOrderMenu();
      syncPRCustomerOrderComboDisplay();
    }, 140);
  });
  toggle?.addEventListener('mousedown', (e) => {
    e.preventDefault();
    if (combo.classList.contains('open')) closePRCustomerOrderMenu();
    else {
      _prCustomerOrderFilter = '';
      search.focus();
      openPRCustomerOrderMenu();
    }
  });
  menu.addEventListener('mousedown', (e) => {
    const opt = e.target.closest('.pr-supplier-opt[data-customer-order-id]');
    if (!opt) return;
    e.preventDefault();
    setPRSelectedCustomerOrder(opt.dataset.customerOrderId);
    closePRCustomerOrderMenu();
  });
  document.addEventListener('mousedown', (e) => {
    if (!combo.contains(e.target)) closePRCustomerOrderMenu();
  });
}

function prPartyNameRecords() {
  const map = new Map();
  const add = (rec) => {
    const name = String(rec?.name || rec?.customer_name || '').trim();
    if (!name) return;
    const key = name.toLowerCase();
    const po = String(rec?.po_reference || rec?.customer_po_number || rec?.order_number || '').trim();
    if (map.has(key)) {
      const prev = map.get(key);
      if (!prev.po_reference && po) prev.po_reference = po;
      return;
    }
    map.set(key, {
      id: String(rec?.id || ('name-' + key)),
      name,
      kind: String(rec?.kind || 'customer'),
      po_reference: po
    });
  };
  (_prPartyNames || []).forEach(add);
  (_customerOrdersForPR || []).forEach(o => add({
    id: 'order-name-' + o.id,
    name: o.customer_name,
    kind: 'customer',
    po_reference: o.customer_po_number || o.order_number || ''
  }));
  return [...map.values()].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
}

function closePRCustomerNameMenu() {
  const combo = document.getElementById('prCustomerNameCombo');
  const menu = document.getElementById('prCustomerNameMenu');
  const search = document.getElementById('prCustomerNameSearch');
  if (combo) combo.classList.remove('open');
  if (menu) menu.hidden = true;
  if (search) search.setAttribute('aria-expanded', 'false');
}

function acceptTypedPRCustomerName(typed, opts = {}) {
  const value = String(typed || '').trim();
  const hidden = document.getElementById('prCustomerNameValue');
  const sel = document.getElementById('prCustomerNameSelect');
  const search = document.getElementById('prCustomerNameSearch');
  if (!value) {
    if (hidden) hidden.value = '';
    if (sel) sel.value = '';
    if (search) {
      search.value = '';
      delete search.dataset.customAccepted;
    }
    closePRCustomerNameMenu();
    renderPRCustomerOrderPanel();
    return;
  }
  if (hidden) hidden.value = value;
  const match = prPartyNameRecords().find(p => p.name.toLowerCase() === value.toLowerCase());
  if (sel) sel.value = match ? String(match.id) : '';
  if (search) {
    search.dataset.customAccepted = value;
    search.value = value;
  }
  _prCustomerNameFilter = '';
  closePRCustomerNameMenu();
  if (!opts.silent) refreshPRCustomerOrderRefForName(value);
  if (opts.silent) {
    renderPRCustomerOrderPanel();
    return;
  }
  commitCurrentPRPurposeItems();
  renderPRCustomerOrderPanel();
}

function syncPRCustomerNameComboDisplay() {
  const search = document.getElementById('prCustomerNameSearch');
  if (!search) return;
  if (document.activeElement === search && document.getElementById('prCustomerNameCombo')?.classList.contains('open')) return;
  const accepted = String(document.getElementById('prCustomerNameValue')?.value || search.dataset.customAccepted || '').trim();
  search.value = accepted;
  if (accepted) search.dataset.customAccepted = accepted;
}

function openPRCustomerNameMenu() {
  closePRSupplierMenu();
  closePRCustomerOrderMenu();
  const combo = document.getElementById('prCustomerNameCombo');
  const menu = document.getElementById('prCustomerNameMenu');
  const search = document.getElementById('prCustomerNameSearch');
  if (!combo || !menu || !search || combo.style.display === 'none') return;
  const filter = String(_prCustomerNameFilter || '').trim().toLowerCase();
  const selectedId = String(document.getElementById('prCustomerNameSelect')?.value || '');
  const rows = prPartyNameRecords().filter(p => !filter || p.name.toLowerCase().includes(filter));
  if (!rows.length) {
    menu.innerHTML = `<div class="pr-supplier-opt" style="cursor:default;color:#64748b">No matching customers or companies</div>`;
  } else {
    menu.innerHTML = rows.map(p => {
      const sel = selectedId && selectedId === String(p.id) ? ' selected' : '';
      const meta = p.kind === 'company' ? 'Company' : 'Customer';
      return `<button type="button" class="pr-supplier-opt${sel}" role="option" data-party-id="${esc(String(p.id))}">
        <div class="pr-supplier-opt-name">${esc(p.name)}</div>
        <div class="pr-supplier-opt-meta">${esc(meta)}</div>
      </button>`;
    }).join('');
  }
  menu.hidden = false;
  combo.classList.add('open');
  search.setAttribute('aria-expanded', 'true');
}

function bindPRCustomerNameCombo() {
  const combo = document.getElementById('prCustomerNameCombo');
  const search = document.getElementById('prCustomerNameSearch');
  const toggle = document.getElementById('prCustomerNameToggle');
  const menu = document.getElementById('prCustomerNameMenu');
  const hidden = document.getElementById('prCustomerNameValue');
  if (!combo || !search || !menu || !hidden) return;
  if (combo.dataset.bound === '1') {
    syncPRCustomerNameComboDisplay();
    return;
  }
  combo.dataset.bound = '1';

  search.addEventListener('focus', () => {
    _prCustomerNameFilter = '';
    openPRCustomerNameMenu();
    search.select();
  });
  search.addEventListener('input', () => {
    _prCustomerNameFilter = search.value;
    const shown = String(search.value || '').trim();
    const accepted = String(hidden.value || '').trim();
    const sel = document.getElementById('prCustomerNameSelect');
    if (sel && accepted && shown.toLowerCase() !== accepted.toLowerCase()) sel.value = '';
    openPRCustomerNameMenu();
    renderPRCustomerOrderPanel();
  });
  search.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closePRCustomerNameMenu();
      syncPRCustomerNameComboDisplay();
      search.blur();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const typed = String(search.value || '').trim();
      const exact = [...menu.querySelectorAll('.pr-supplier-opt[data-party-id]')].find((btn) => {
        const rec = prPartyNameRecords().find(p => String(p.id) === String(btn.dataset.partyId));
        return rec && typed && rec.name.toLowerCase() === typed.toLowerCase();
      });
      if (exact) {
        const rec = prPartyNameRecords().find(p => String(p.id) === String(exact.dataset.partyId));
        acceptTypedPRCustomerName(rec ? rec.name : typed);
      } else if (typed) {
        acceptTypedPRCustomerName(typed);
      }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      openPRCustomerNameMenu();
      menu.querySelector('.pr-supplier-opt[data-party-id]')?.focus();
    }
  });
  search.addEventListener('blur', () => {
    setTimeout(() => {
      if (combo.contains(document.activeElement)) return;
      closePRCustomerNameMenu();
      syncPRCustomerNameComboDisplay();
    }, 140);
  });
  toggle?.addEventListener('mousedown', (e) => {
    e.preventDefault();
    if (combo.classList.contains('open')) closePRCustomerNameMenu();
    else {
      _prCustomerNameFilter = '';
      search.focus();
      openPRCustomerNameMenu();
    }
  });
  menu.addEventListener('mousedown', (e) => {
    const opt = e.target.closest('.pr-supplier-opt[data-party-id]');
    if (!opt) return;
    e.preventDefault();
    const rec = prPartyNameRecords().find(p => String(p.id) === String(opt.dataset.partyId));
    if (rec) acceptTypedPRCustomerName(rec.name);
  });
  document.addEventListener('mousedown', (e) => {
    if (!combo.contains(e.target)) closePRCustomerNameMenu();
  });
}

function prCustomerOrderSummaryFields() {
  const nameEl = document.getElementById('prCustomerNameSearch');
  const poEl = document.getElementById('prCustomerOrderSearch');
  const name = nameEl
    ? String(nameEl.value || '').trim()
    : String(document.getElementById('prCustomerNameValue')?.value || '').trim();
  const po = poEl
    ? String(poEl.value || '').trim()
    : String(document.getElementById('prCustomerOrderRef')?.value || '').trim();
  return { name: name || '—', po: po || '—' };
}

function prCustomerOrderViewSignature(order) {
  const taken = getPRSelectedProductIds().join(',');
  const items = order
    ? (order.items || []).map(i => `${i.product_id}:${i.ordered_qty}:${i.available_qty}:${i.required_qty}`).join('|')
    : '';
  const summary = prCustomerOrderSummaryFields();
  return `${order ? order.id : ''}:${items}:${taken}:${summary.name}:${summary.po}`;
}

function loadPRCustomerOrders(cb) {
  const panel = document.getElementById('prCustomerOrderPanel');
  syncPRPurposeSourcePanels();
  if (getCreatePRPurpose() !== 'Customer Order') {
    if (typeof cb === 'function') cb(_customerOrdersForPR);
    return;
  }
  if (shouldShowPRCustomerOrderPanel() && panel && panel.dataset.loaded !== '1') {
    panel.dataset.coRendered = 'loading';
    panel.innerHTML = `<div style="text-align:center;padding:10px;color:#64748b;font-size:12px;margin-bottom:12px">
      <div class="spinner-border spinner-border-sm me-2"></div> Loading customer orders…
    </div>`;
  }
  pApi({ query:{ action:'get_customer_orders_for_pr' } }, r => {
    if (r && r.success) {
      _prCustomerOrdersAvailable = r.source_available !== false;
      _customerOrdersForPR = mergePRCustomerOrderLists(r.data || []);
      applyPRPartyNamesFromApi(Array.isArray(r.party_names) ? r.party_names : []);
    } else if (_prCustomerOrdersAvailable == null) {
      _prCustomerOrdersAvailable = false;
      _customerOrdersForPR = [];
    }
    populatePRCustomerOrderSelect();
    if (panel) {
      panel.dataset.loaded = '1';
      delete panel.dataset.coRendered;
    }
    _prCustomerOrderViewSig = '';
    renderPRCustomerOrderPanel();
    if (typeof cb === 'function') cb(_customerOrdersForPR);
  });
}

function renderPRCustomerOrderPanel() {
  const panel = document.getElementById('prCustomerOrderPanel');
  if (!panel) return;
  if (isEditingExistingPR()) return;
  syncPRPurposeSourcePanels();
  if (!shouldShowPRCustomerOrderPanel()) return;

  if (panel.dataset.loaded !== '1') {
    if (panel.dataset.coRendered === 'loading') return;
    panel.dataset.coRendered = 'loading';
    panel.innerHTML = `<div style="text-align:center;padding:10px;color:#64748b;font-size:12px;margin-bottom:12px">
      <div class="spinner-border spinner-border-sm me-2"></div> Loading customer orders…
    </div>`;
    return;
  }

  const summary = prCustomerOrderSummaryFields();

  if (_prCustomerOrdersAvailable === false) {
    const msg = 'Customer order / customer PO records were not found in this system. Enter a Customer Order Reference manually. Sales orders were not created.';
    const missingSig = `missing|${summary.name}|${summary.po}`;
    if (panel.dataset.coRendered === missingSig) return;
    panel.dataset.coRendered = missingSig;
    panel.innerHTML = `
      <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
        <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0">
          <div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:8px">Customer Order</div>
          <div style="font-size:12.5px;color:#334155">Customer: <strong>${esc(summary.name)}</strong></div>
          <div style="font-size:12.5px;color:#334155">Customer PO: <strong>${esc(summary.po)}</strong></div>
        </div>
        <div style="padding:12px 16px;font-size:12.5px;color:#64748b">${msg}</div>
      </div>`;
    return;
  }

  if (!(_customerOrdersForPR || []).length) {
    const emptySig = `empty|${summary.name}|${summary.po}`;
    if (panel.dataset.coRendered === emptySig) return;
    panel.dataset.coRendered = emptySig;
    panel.innerHTML = `
      <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
        <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0">
          <div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:8px">Customer Order</div>
          <div style="font-size:12.5px;color:#334155">Customer: <strong>${esc(summary.name)}</strong></div>
          <div style="font-size:12.5px;color:#334155">Customer PO: <strong>${esc(summary.po)}</strong></div>
        </div>
        <div style="padding:12px 16px;font-size:12.5px;color:#64748b">No customer orders or customer POs are on file. Enter a Customer Order Reference manually. This page does not create customer orders.</div>
      </div>`;
    return;
  }

  const order = getSelectedPRCustomerOrder();
  const sig = prCustomerOrderViewSignature(order);
  if (sig === _prCustomerOrderViewSig && panel.dataset.coRendered === '1') return;
  _prCustomerOrderViewSig = sig;
  panel.dataset.coRendered = '1';

  if (!order) {
    panel.innerHTML = `
      <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
        <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0">
          <div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:8px">Customer Order</div>
          <div style="font-size:12.5px;color:#334155">Customer: <strong>${esc(summary.name)}</strong></div>
          <div style="font-size:12.5px;color:#334155">Customer PO: <strong>${esc(summary.po)}</strong></div>
        </div>
        <div style="padding:12px 16px;font-size:12.5px;color:#64748b">Select a Customer Order / PO Reference to see the customer, products, ordered quantity, and available inventory.</div>
      </div>`;
    return;
  }

  const taken = new Set(getPRSelectedProductIds());
  const items = order.items || [];
  const soNum = String(order.order_number || '').trim();
  panel.innerHTML = `
    <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
      <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0">
        <div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:8px">Customer Order</div>
        <div style="font-size:12.5px;color:#334155">Customer: <strong>${esc(summary.name)}</strong></div>
        <div style="font-size:12.5px;color:#334155">Customer PO: <strong>${esc(summary.po)}</strong></div>
        ${soNum && soNum !== summary.po ? `<div style="font-size:12px;color:#64748b">Sales Order: ${esc(soNum)}</div>` : ''}
      </div>
      <div class="system-table-scroll" style="padding:0">
        <table class="modern-table" style="margin-bottom:0">
          <thead>
            <tr>
              <th>Product</th>
              <th>Ordered Qty</th>
              <th>Available Qty</th>
              <th>Unit</th>
              <th>Required to Purchase</th>
              <th style="width:80px"></th>
            </tr>
          </thead>
          <tbody>
            ${items.length ? items.map(i => {
              const required = Math.max(0, parseInt(i.required_qty, 10) || 0);
              const already = taken.has(String(i.product_id));
              const enough = required <= 0;
              let action = '';
              if (already) {
                action = '<span class="badge badge-gray">Added</span>';
              } else if (enough) {
                action = '<span class="badge badge-gray">Sufficient</span>';
              } else {
                action = `<button type="button" class="btn btn-sm btn-outline-primary" style="font-size:11px;white-space:nowrap" onclick="addPRItemFromCustomerOrder(${parseInt(i.product_id, 10) || 0}, ${required})"><i class="bi bi-plus-lg me-1"></i>Add</button>`;
              }
              return `<tr>
                <td>${esc(i.product_name || '—')}</td>
                <td>${i.ordered_qty}</td>
                <td><strong style="color:${enough ? '#0f172a' : '#dc2626'}">${i.available_qty}</strong></td>
                <td>${esc(i.unit || '—')}</td>
                <td>${enough ? '0' : required}</td>
                <td>${action}</td>
              </tr>`;
            }).join('') : `<tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b">This customer order has no products.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
}

function addPRItemFromCustomerOrder(productId, requiredQty) {
  const qty = Math.max(0, parseInt(requiredQty, 10) || 0);
  if (qty <= 0) {
    pToast('Available inventory already covers this ordered quantity.', 'info');
    return false;
  }
  return addPRItemFromProduct(productId, { qty });
}

function prCatalogCurrentStock(p) {
  if (!p) return 0;
  if (p.sellable_qty != null && p.sellable_qty !== '') return parseInt(p.sellable_qty, 10) || 0;
  return parseInt(p.quantity_in_stock, 10) || 0;
}

function visiblePRCatalogProducts() {
  return (_poProducts || []).filter(p => String(p.status || '').toLowerCase() !== 'discontinued');
}

function prCatalogViewSignature(list) {
  const taken = getPRSelectedProductIds().join(',');
  return (list || []).map(p => String(p.id)).join(',') + ':' + taken;
}

function renderPRCatalogPanel() {
  const panel = document.getElementById('prCatalogPanel');
  if (!panel) return;
  if (isEditingExistingPR()) return;
  syncPRPurposeSourcePanels();
  if (!shouldShowPRCatalogPanel()) return;
  const list = visiblePRCatalogProducts();
  const sig = prCatalogViewSignature(list);
  if (sig === _prCatalogViewSig && panel.dataset.catalogRendered === '1') return;
  _prCatalogViewSig = sig;
  panel.dataset.catalogRendered = '1';
  const taken = new Set(getPRSelectedProductIds());

  if (!list.length) {
    panel.innerHTML = `
      <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
        <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:600;color:#0f172a">Available Products</div>
        <div style="padding:12px 16px;font-size:12.5px;color:#64748b">No active Product Catalog items were found.</div>
      </div>`;
    return;
  }

  panel.innerHTML = `
    <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
      <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:600;color:#0f172a">
        Available Products (${list.length})
      </div>
      <div class="system-table-scroll">
        <table class="modern-table" style="margin-bottom:0">
          <thead>
            <tr>
              <th>Product</th>
              <th>SKU</th>
              <th>Current Stock</th>
              <th>Unit</th>
              <th style="width:80px"></th>
            </tr>
          </thead>
          <tbody>
            ${list.map(p => {
              const already = taken.has(String(p.id));
              const action = already
                ? '<span class="badge badge-gray">Added</span>'
                : `<button type="button" class="btn btn-sm btn-outline-primary" style="font-size:11px;white-space:nowrap" onclick="addPRItemFromCatalog(${p.id})"><i class="bi bi-plus-lg me-1"></i>Add</button>`;
              return `<tr>
                <td>${esc(p.name)}</td>
                <td style="font-family:monospace;font-size:12px">${esc(p.sku || '—')}</td>
                <td>${prCatalogCurrentStock(p)}</td>
                <td>${esc(p.unit_of_measure || '—')}</td>
                <td>${action}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
}

function addPRItemFromCatalog(productId) {
  const added = addPRItemFromProduct(productId, { qty: 1 });
  if (shouldUsePurposeListPOV() && getPRListPOV() === 'others') renderPRListCatalogRows();
  return added;
}

function getPRSelectedProductIds() {
  return [...(getPRItemsTbody()?.querySelectorAll('select[name*="[product_id]"]') || [])]
    .map(sel => String(sel.value || ''))
    .filter(Boolean);
}

function clearEmptyPRItemRows() {
  getPRItemsTbody()?.querySelectorAll('tr').forEach(row => {
    const idx = row.dataset.idx;
    const pid = row.querySelector(`[name="items[${idx}][product_id]"]`)?.value;
    const pname = row.querySelector(`[name="items[${idx}][product_name]"]`)?.value?.trim();
    if (!pid && !pname) row.remove();
  });
}

function refreshPRItemDropdowns() {
  getPRItemsTbody()?.querySelectorAll('tr').forEach(row => {
    const idx = row.dataset.idx;
    const sel = row.querySelector(`[name="items[${idx}][product_id]"]`);
    if (!sel) return;
    const current = String(sel.value || '');
    sel.innerHTML = buildPRProductOptions(current);
    if (current) sel.value = current;
  });
}

function isCreatePRInline() {
  const el = document.getElementById('createPRModal');
  return !!(el && el.getAttribute('data-pr-pos') === 'inline');
}

function isCreatePRModalOpen() {
  const el = document.getElementById('createPRModal');
  if (!el) return false;
  if (isCreatePRInline()) {
    const section = document.getElementById('section-purchase-requests');
    const pane = document.getElementById('pr-tab-pane-requests');
    const sectionOn = !section || section.classList.contains('active');
    const paneOn = !pane || pane.classList.contains('active') || pane.classList.contains('show');
    return sectionOn && paneOn;
  }
  return el.classList.contains('open');
}

function ensureInlineCreatePRReady() {
  if (!isCreatePRInline()) return;
  const form = document.getElementById('createPRForm');
  if (!form) return;
  if (form.dataset.inlineReady === '1') {
    startPRSelectLiveSync();
    return;
  }
  form.dataset.inlineReady = '1';
  openCreatePR(true);
}

function stopPRSelectLiveSync() {
  if (_prLiveSyncTimer) {
    clearInterval(_prLiveSyncTimer);
    _prLiveSyncTimer = null;
  }
}

function startPRSelectLiveSync() {
  stopPRSelectLiveSync();
  _prLiveSyncTimer = setInterval(() => {
    if (!isCreatePRModalOpen()) {
      stopPRSelectLiveSync();
      return;
    }
    pApi({ query: { action: 'get_reorder_suggestions' } }, r => {
      if (!isCreatePRModalOpen() || !r.success) return;
      if (isEditingExistingPR()) return;
      _lowStockProducts = availablePRLowStockProducts(r.data || []);
      renderPRLowStockPanel();
      syncPRLowStockAddButtons();
    });
    pApi({ query: { action: 'get_products_for_po' } }, r => {
      if (!isCreatePRModalOpen() || !r.success) return;
      _poProducts = r.data || [];
      refreshPRItemDropdowns();
      if (!isEditingExistingPR() && shouldShowPRCatalogPanel()) renderPRCatalogPanel();
      if (shouldUsePurposeListPOV() && getPRListPOV() === 'others') renderPRListCatalogRows();
    });
    if (!isEditingExistingPR() && shouldShowPRCustomerOrderPanel()) {
      pApi({ query: { action: 'get_customer_orders_for_pr' } }, r => {
        if (!isCreatePRModalOpen() || !r.success) return;
        _prCustomerOrdersAvailable = r.source_available !== false;
        _customerOrdersForPR = mergePRCustomerOrderLists(r.data || []);
        if (Array.isArray(r.party_names)) applyPRPartyNamesFromApi(r.party_names);
        populatePRCustomerOrderSelect();
        renderPRCustomerOrderPanel();
        if (shouldUsePurposeListPOV() && getPRListPOV() === 'customer_order') renderPRListCustomerOrderRows();
      });
    }
  }, 2500);
}

function syncPRLowStockAddButtons() {
  if (_syncingPRSelect) return;
  _syncingPRSelect = true;
  try {
    refreshPRItemDropdowns();
    if (!isEditingExistingPR()) {
      renderPRLowStockPanel();
      if (shouldShowPRCatalogPanel()) renderPRCatalogPanel();
      if (shouldShowPRCustomerOrderPanel()) renderPRCustomerOrderPanel();
      if (shouldUsePurposeListPOV() && getPRListPOV() === 'others') renderPRListCatalogRows();
    }
  } finally {
    _syncingPRSelect = false;
  }
}

function openCreatePR(addInitialRow = true, draft = null) {
  const formEl = document.getElementById('createPRForm');
  if (formEl && isCreatePRInline()) formEl.dataset.inlineReady = '1';
  resetCreatePRPreparedState();
  if (draft) {
    mergeDraftIntoPurposeBuckets(draft, draftBundleForPR(draft.id));
  }
  const purposeSelEarly = document.getElementById('prPurpose');
  if (purposeSelEarly) delete purposeSelEarly.dataset.lastPurpose;
  _prItemCount = 0;
  if (!draft) {
    _prPreferredSupplierId = '';
    _prSupplierComboFilter = '';
  } else {
    _prPreferredSupplierId = String(draft.supplier_id || '');
  }
  const form = document.getElementById('createPRForm');
  if (form) {
    form.reset();
    if (draft) {
      form.dataset.editPrId = String(draft.id);
      form.dataset.editPrStatus = String(draft.status || '');
    } else {
      delete form.dataset.editPrId;
      delete form.dataset.editPrStatus;
    }
  }
  const revisingRejected = !!(draft && String(draft.status) === 'Rejected');
  const title = document.querySelector('#createPRModal .modal-title');
  if (title) {
    if (revisingRejected) {
      title.innerHTML = `<i class="bi bi-pencil-square text-primary"></i> Revise Purchase Request · ${esc(draft.pr_number || '')}`;
    } else if (draft) {
      title.innerHTML = `<i class="bi bi-file-earmark-plus text-primary"></i> Continue Draft · ${esc(draft.pr_number || '')}`;
    } else {
      title.innerHTML = `<i class="bi bi-file-earmark-plus text-primary"></i> Create Purchase Request`;
    }
  }
  const footer = document.querySelector('#createPRModal .modal-footer');
  if (footer) {
    footer.innerHTML = revisingRejected
      ? `
      <button class="btn btn-outline-secondary" onclick="closeModal('createPRModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitCreatePR(true)"><i class="bi bi-send me-1"></i> Resubmit PR</button>`
      : `
      <button class="btn btn-outline-secondary" onclick="closeModal('createPRModal')">Cancel</button>
      <button class="btn btn-outline-primary" onclick="submitCreatePR(false)"><i class="bi bi-save me-1"></i> Save as Draft</button>
      <button class="btn btn-primary" onclick="submitCreatePR(true)"><i class="bi bi-send me-1"></i> Submit for Admin Approval</button>`;
  }
  syncPRRevisionBanner(draft);
  const tbody = document.getElementById('prItemsBody');
  if (tbody) tbody.innerHTML = '';
  document.getElementById('prGrandTotal').textContent = '0.00';
  const reasonEl = document.getElementById('prReason');
  const purposeSel = document.getElementById('prPurpose');
  if (purposeSel) delete purposeSel.dataset.lastPurpose;
  _prPurposeConfirmOpen = false;
  _prLowStockViewSig = '';
  _prCustomerOrderViewSig = '';
  _prCatalogViewSig = '';
  _prSelectedCustomerOrderId = '';
  _customerOrdersForPR = [];
  _prPartyNames = [];
  _prCustomerNameFilter = '';
  _prCustomerOrdersAvailable = null;
  clearPRCoExtraFields();
  if (draft) {
    _prSelectedCustomerOrderId = String(ensurePRPurposeBucket('Customer Order').customer_order_id || '');
  }
  const lowPanel = document.getElementById('prLowStockPanel');
  if (lowPanel) {
    delete lowPanel.dataset.lowStockRendered;
    lowPanel.style.display = 'none';
  }
  const coPanel = document.getElementById('prCustomerOrderPanel');
  if (coPanel) {
    coPanel.style.display = 'none';
    coPanel.innerHTML = '';
    delete coPanel.dataset.loaded;
    delete coPanel.dataset.coRendered;
  }
  const catPanel = document.getElementById('prCatalogPanel');
  if (catPanel) {
    catPanel.style.display = 'none';
    catPanel.innerHTML = '';
    delete catPanel.dataset.catalogRendered;
  }
  const itemsWrap = document.getElementById('prRequestedItemsSection');
  if (itemsWrap) itemsWrap.style.display = '';
  showPRSupplierWarn(false);
  closePRSupplierMenu();
  openModal('createPRModal');
  startPRSelectLiveSync();
  loadDropdowns(() => {
    if (draft) {
      setPRPreferredSupplier(String(draft.supplier_id || ''), { silent: true, skipRemember: true });
      fillCreatePRDraftFields(draft);
    } else {
      setPRPreferredSupplier('', { silent: true });
      if (reasonEl) reasonEl.value = 'stock in';
      onPRPurposeChange();
    }
    loadPRLowStockPanel(() => {
      if (draft) {
        const purpose = String(draft.purpose || getCreatePRPurpose() || '');
        applyPRPurposeBucketToForm(purpose, { rebuildItems: true });
        const restored = collectAllBucketItemsSorted().length;
        if (!restored && addInitialRow) addPRItemRow();
      } else if (addInitialRow) {
        addPRItemRow();
      }
      syncPRLowStockAddButtons();
    });
  });
}

function fillCreatePRDraftFields(p) {
  const reasonEl = document.getElementById('prReason');
  if (reasonEl) reasonEl.value = p.reason || 'stock in';
  const purpose = String(p.purpose || '').trim();
  const sel = document.getElementById('prPurpose');
  if (sel) {
    sel.value = purpose;
    sel.dataset.lastPurpose = purpose;
  }
  _prActivePurposeView = purpose;
  applyPRPurposeUI('', purpose);
  if (purpose === 'Customer Order') {
    const remarks = String(p.remarks || '');
    const details = remarks.replace(/(?:\n)?Customer:\s*.+\s*$/m, '').trim();
    const ref = document.getElementById('prCustomerOrderRef');
    if (ref) ref.value = p.customer_order_reference || '';
    if (p.customer_name) acceptTypedPRCustomerName(p.customer_name, { silent: true });
    const od = document.getElementById('prOrderDetails');
    if (od) od.value = details;
    matchPRCustomerOrderFromRef();
    syncPRCustomerOrderComboDisplay();
    renderPRCustomerOrderPanel();
  } else if (purpose === 'Others') {
    const pd = document.getElementById('prPurposeDetails');
    if (pd) pd.value = p.remarks || '';
  }
}

function continueDraftPR(id) {
  pApi({ query: { action: 'get_pr_detail', id } }, r => {
    if (!r.success || !r.data) {
      pToast(r.message || 'Could not load draft', 'danger');
      return;
    }
    if (String(r.data.status) !== 'Draft') {
      pToast('Only Draft purchase requests can be continued here', 'info');
      viewPR(id);
      return;
    }
    openCreatePR(false, r.data);
  });
}

function reviseRejectedPR(id) {
  pApi({ query: { action: 'get_pr_detail', id } }, r => {
    if (!r.success || !r.data) {
      pToast(r.message || 'Could not load purchase request', 'danger');
      return;
    }
    if (String(r.data.status) !== 'Rejected') {
      pToast('Only rejected purchase requests can be revised here', 'info');
      viewPR(id);
      return;
    }
    if (typeof switchPRInvoiceTab === 'function') {
      try { switchPRInvoiceTab('requests'); } catch (e) { /* tab may not exist */ }
    }
    openCreatePR(false, r.data);
    if (isCreatePRInline()) {
      document.getElementById('createPRModal')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
}

function syncPRRevisionBanner(pr) {
  const form = document.getElementById('createPRForm');
  if (!form) return;
  let banner = document.getElementById('prRevisionBanner');
  const rejected = !!(pr && String(pr.status) === 'Rejected');
  if (!rejected) {
    if (banner) banner.remove();
    return;
  }
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'prRevisionBanner';
    const anchor = document.getElementById('prLowStockPanel');
    if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(banner, anchor);
    else form.insertBefore(banner, form.firstChild);
  }
  const reason = String(pr.admin_remarks || '').trim();
  banner.style.cssText = 'background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;margin-bottom:16px';
  banner.innerHTML = `
    <div style="font-size:11px;color:#b91c1c;font-weight:700;text-transform:uppercase">Returned for revision</div>
    <div style="font-size:13px;color:#0f172a;margin-top:4px"><strong>${esc(pr.pr_number || '')}</strong> · ${esc(pr.supplier_name || 'Unspecified')}</div>
    <div style="font-size:11px;color:#b91c1c;font-weight:700;text-transform:uppercase;margin-top:8px">Rejected by Admin</div>
    <div style="font-size:13px;color:#991b1b">${reason ? esc(reason) : 'No rejection reason provided.'}</div>
    <div style="font-size:12px;color:#64748b;margin-top:6px">Remove unavailable items, keep the remaining items, then resubmit this same Purchase Request.</div>`;
}

function addPRItemFromProduct(productId, opts = {}) {
  const keepSupplier = snapshotPreferredSupplier();
  const assignSid = String(opts.supplierId || requirePRSupplierForAdd() || '');
  if (!assignSid) return false;
  const pid = String(productId || '');
  if (!pid) return false;

  const already = getPRSelectedProductIds().includes(pid);
  if (already) {
    const named = findProductById(pid) || (_lowStockProducts || []).find(p => String(p.id) === pid) || findPRCustomerOrderProduct(pid);
    if (!opts.silent) pToast(`${named?.name || 'This product'} is already in this PR`, 'info');
    syncPRLowStockAddButtons();
    return false;
  }

  const candidate = findProductById(productId) || (_lowStockProducts || []).find(p => String(p.id) === pid) || findPRCustomerOrderProduct(pid);
  if (!candidate) {
    if (!opts.silent) pToast('Product not found in catalog', 'warn');
    return false;
  }

  clearEmptyPRItemRows();

  const defaultQty = candidate.suggested_quantity || candidate.reorder_quantity || 1;
  const qty = (opts.qty != null && opts.qty !== '') ? Math.max(1, parseInt(opts.qty, 10) || 1) : defaultQty;

  const product = findProductById(productId);
  if (!product) {
    addPRItemRow(candidate.id, candidate.name, qty, candidate.cost_price, '', assignSid);
    const row = getPRItemsTbody()?.querySelector(`tr[data-idx="${_prItemCount - 1}"]`);
    updatePRItemMeta(row, { ...candidate, is_low_stock: 1 });
    setPRRowSupplier(row, assignSid);
    if (!opts.silent) recalcPRTotal();
    syncPRLowStockAddButtons();
    if (keepSupplier) {
      _prPreferredSupplierId = keepSupplier;
      const hidden = document.getElementById('prSupplierSelect');
      if (hidden) hidden.value = keepSupplier;
    }
    if (!opts.silent) pToast(`Added ${candidate.name}.`, 'success');
    return true;
  }

  addPRItemRow(product.id, product.name, qty, product.cost_price, '', assignSid);
  const row = getPRItemsTbody()?.querySelector(`tr[data-idx="${_prItemCount - 1}"]`);
  const sel = row?.querySelector('select[name*="[product_id]"]');
  if (sel) onPRProductChange(sel, row.dataset.idx, { keepSupplier: assignSid, keepQty: qty });
  setPRRowSupplier(row, assignSid);
  if (keepSupplier) {
    _prPreferredSupplierId = keepSupplier;
    const hidden = document.getElementById('prSupplierSelect');
    if (hidden) hidden.value = keepSupplier;
  }
  syncPRLowStockAddButtons();
  if (!opts.silent) pToast(`Added ${product.name}.`, 'success');
  return true;
}

function addAllLowStockToPR() {
  const keepSupplier = snapshotPreferredSupplier();
  const assignSid = requirePRSupplierForAdd();
  if (!assignSid) return;
  const addable = visiblePRLowStockProducts();
  if (!addable.length) {
    pToast('No more low-stock products available.', 'info');
    return;
  }
  clearEmptyPRItemRows();
  let added = 0;
  addable.forEach(p => {
    if (addPRItemFromProduct(p.id, { silent: true, supplierId: assignSid })) added += 1;
  });
  if (keepSupplier) {
    _prPreferredSupplierId = keepSupplier;
    const hidden = document.getElementById('prSupplierSelect');
    if (hidden) hidden.value = keepSupplier;
  }
  if (added) pToast(`Added ${added} low-stock product(s).`, 'success');
  else pToast('No low-stock products were added', 'info');
  recalcPRTotal();
  syncPRLowStockAddButtons();
}

function addPRItemRow(productId, productName, qty, price, remarks, supplierId) {
  const keepSupplier = snapshotPreferredSupplier();
  const tbody = getPRItemsTbody();
  if (!tbody) return;
  if (!_poProducts.length) {
    loadDropdowns(() => addPRItemRow(productId, productName, qty, price, remarks, supplierId));
    return;
  }
  const idx = _prItemCount++;
  const prodOpts = buildPRProductOptions(productId);
  const product = productId ? findProductById(productId) : null;
  const unitVal = product?.unit_of_measure || '';
  const priceVal = (price != null && price !== '' ? parseFloat(price) : parseFloat(product?.cost_price || 0)) || 0;
  const priceReadonly = isProcurementAdmin() ? '' : 'readonly';
  let itemSupplierId = String(supplierId || '');
  if (productId && !itemSupplierId && isPRReviewModalOpen()) {
    itemSupplierId = getSelectedPRSupplierId();
  }
  const supplierName = findSupplierById(itemSupplierId)?.name || (itemSupplierId ? '—' : '—');

  const row = document.createElement('tr');
  row.dataset.idx = idx;
  row.dataset.supplierId = itemSupplierId;
  row.innerHTML = `
    <td><select class="form-control" name="items[${idx}][product_id]" style="font-size:12px" onchange="onPRProductChange(this,${idx})">${prodOpts}</select></td>
    <td><input name="items[${idx}][product_name]" class="form-control" value="${esc(productName||'')}" placeholder="Item Description / Specification" style="font-size:12px" required></td>
    <td class="pr-row-supplier-cell">
      <input type="hidden" name="items[${idx}][supplier_id]" value="${esc(itemSupplierId)}">
      <span class="pr-row-supplier-name" style="font-size:12.5px;font-weight:600;color:#0f172a;line-height:1.35">${esc(supplierName)}</span>
      <div class="pr-supplier-combo pr-row-supplier-combo" hidden>
        <input type="text" class="form-control pr-supplier-search" placeholder="Select a supplier." autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-autocomplete="list">
        <button type="button" class="pr-supplier-combo-caret" tabindex="-1" aria-label="Show suppliers"><i class="bi bi-chevron-down"></i></button>
        <div class="pr-supplier-menu" hidden role="listbox"></div>
      </div>
    </td>
    <td><input name="items[${idx}][quantity_requested]" type="number" min="1" value="${qty||1}" class="form-control pr-qty-input" oninput="recalcPRTotal()" aria-label="Requested quantity"></td>
    <td><input name="items[${idx}][unit_of_measure]" class="form-control" value="${esc(unitVal)}" placeholder="—" readonly style="font-size:12px"></td>
    <td><input name="items[${idx}][estimated_unit_price]" type="number" step="0.01" min="0" value="${priceVal.toFixed(2)}" class="form-control" style="font-size:12px" ${priceReadonly} oninput="recalcPRTotal()"></td>
    <td class="pr-row-total" style="font-weight:600;color:#0f172a">₱0.00</td>
    <td><button type="button" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px" onclick="removePRItemRow(this)"><i class="bi bi-trash"></i></button></td>`;
  tbody.appendChild(row);

  if (productId) {
    if (product) updatePRItemMeta(row, product);
  }
  setPRRowSupplier(row, itemSupplierId);
  syncPRRowSupplierUI(row);
  stampCreatePRRowGroup(row);
  if (keepSupplier) {
    _prPreferredSupplierId = keepSupplier;
    const hidden = document.getElementById('prSupplierSelect');
    if (hidden) hidden.value = keepSupplier;
  }
  recalcPRTotal();
  syncPRLowStockAddButtons();
  commitCurrentPRPurposeItems();
}

function onPRProductChange(sel, idx, opts = {}) {
  const opt = sel.options[sel.selectedIndex];
  const tbody = getPRItemsTbody();
  const row = tbody?.querySelector(`tr[data-idx="${idx}"]`);
  if (!row) return;

  const productId = sel.value;
  if (productId) {
    const dup = [...(tbody.querySelectorAll('select[name*="[product_id]"]') || [])]
      .some(other => other !== sel && String(other.value) === String(productId));
    if (dup) {
      sel.value = '';
      updatePRItemMeta(row, null);
      syncPRLowStockAddButtons();
      pToast('This product is already in Requested Items', 'info');
      return;
    }
  }
  if (!productId) {
    updatePRItemMeta(row, null);
    syncPRLowStockAddButtons();
    return;
  }

  const product = findProductById(productId) || {
    id: productId,
    name: opt?.dataset?.name || '',
    brand: opt?.dataset?.brand || '',
    cost_price: parseFloat(opt?.dataset?.price || 0),
    quantity_in_stock: parseInt(opt?.dataset?.stock || 0, 10),
    reorder_level: parseInt(opt?.dataset?.reorder || 0, 10),
    suggested_quantity: parseInt(opt?.dataset?.suggested || 1, 10),
    unit_of_measure: opt?.dataset?.unit || '',
    supplier_id: opt?.dataset?.supplierId || '',
    supplier_name: opt?.dataset?.supplierName || '',
    is_low_stock: parseInt(opt?.dataset?.lowStock || 0, 10)
  };

  const nameInput  = row.querySelector(`[name="items[${idx}][product_name]"]`);
  const qtyInput   = row.querySelector(`[name="items[${idx}][quantity_requested]"]`);
  const priceInput = row.querySelector(`[name="items[${idx}][estimated_unit_price]"]`);
  const unitInput  = row.querySelector(`[name="items[${idx}][unit_of_measure]"]`);

  const displayName = product.brand ? `${product.name} (${product.brand})` : (product.name || '');
  if (nameInput) nameInput.value = displayName;
  if (priceInput) priceInput.value = parseFloat(product.cost_price || 0).toFixed(2);
  if (unitInput) unitInput.value = product.unit_of_measure || '';
  if (qtyInput) {
    if (opts.keepQty != null && opts.keepQty !== '') {
      qtyInput.value = Math.max(1, parseInt(opts.keepQty, 10) || 1);
    } else {
      const suggested = Math.max(1, parseInt(product.suggested_quantity || product.reorder_quantity || 1, 10));
      qtyInput.value = suggested;
    }
  }

  updatePRItemMeta(row, product);
  const existingSid = getPRRowSupplierId(row);
  if (existingSid) {
    setPRRowSupplier(row, existingSid);
  } else if (isPRPerItemSupplierMode()) {
    setPRRowSupplier(row, '');
  } else {
    const assignSid = opts.keepSupplier || (isPRReviewModalOpen() ? getSelectedPRSupplierId() : requirePRSupplierForAdd());
    if (!assignSid) {
      sel.value = '';
      updatePRItemMeta(row, null);
      syncPRLowStockAddButtons();
      return;
    }
    setPRRowSupplier(row, assignSid);
  }
  stampCreatePRRowGroup(row);
  recalcPRTotal();
  syncPRLowStockAddButtons();
  commitCurrentPRPurposeItems();
}

function removePRItemRow(btn) {
  const row = btn.closest('tr');
  if (row) row.remove();
  recalcPRTotal();
  syncPRLowStockAddButtons();
  commitCurrentPRPurposeItems();
}

function recalcPRTotal() {
  let total = 0;
  getPRItemsTbody()?.querySelectorAll('tr').forEach(row => {
    const idx = row.dataset.idx;
    const qty = parseFloat(row.querySelector(`[name="items[${idx}][quantity_requested]"]`)?.value||0);
    const up  = parseFloat(row.querySelector(`[name="items[${idx}][estimated_unit_price]"]`)?.value||0);
    const rt  = qty * up;
    const rtCell = row.querySelector('.pr-row-total');
    if (rtCell) rtCell.textContent = '₱'+rt.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
    total += rt;
  });
  const gt = getPRGrandTotalEl();
  if (gt) gt.textContent = total.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
  commitCurrentPRPurposeItems();
}

function openCreatePO() {
  pToast('Purchase Orders are created from approved Purchase Requests.', 'info');
  if (typeof showSection === 'function') showSection('purchase-requests');
  return;
  _poItemCount = 0;
  document.getElementById('createPOForm')?.reset();
  const tbody = document.getElementById('poItemsBody');
  if (tbody) tbody.innerHTML = '';
  const gt = document.getElementById('poGrandTotal');
  if (gt) gt.textContent = '0.00';
  loadDropdowns(() => addPOItemRow());
  openModal('createPOModal');
}

function addPOItemRow(productId, productName, qty, price, notes) {
  const tbody = document.getElementById('poItemsBody');
  if (!tbody) return;
  if (!_poProducts.length) {
    loadDropdowns(() => addPOItemRow(productId, productName, qty, price, notes));
    return;
  }

  const supplierId = document.getElementById('poSupplierSelect')?.value || '';
  const idx = _poItemCount++;
  const productOptions = '<option value="">-- Select Catalog Item (Optional) --</option>' +
    _poProducts
      .filter(p => !supplierId || !p.supplier_id || String(p.supplier_id) === String(supplierId))
      .map(p => `<option value="${p.id}" data-price="${p.cost_price}" data-name="${esc(p.name)}"${p.id==productId?' selected':''}>${esc('['+p.sku+'] '+p.name)}</option>`)
      .join('');

  const row = document.createElement('tr');
  row.dataset.idx = idx;
  row.innerHTML = `
    <td><select class="form-control" name="items[${idx}][product_id]" style="font-size:12px" onchange="onPOProductChange(this,${idx})">${productOptions}</select></td>
    <td><input name="items[${idx}][product_name]" class="form-control" value="${esc(productName||'')}" placeholder="Item Description / Specification" style="font-size:12px" required></td>
    <td><input name="items[${idx}][quantity_ordered]" type="number" min="1" value="${qty||1}" class="form-control" style="font-size:12px" oninput="recalcPOTotal()"></td>
    <td><input name="items[${idx}][unit_price]" type="number" step="0.01" min="0" value="${price||'0.00'}" class="form-control" style="font-size:12px" oninput="recalcPOTotal()"></td>
    <td class="po-row-total" style="font-weight:600;color:#0f172a">0.00</td>
    <td><button type="button" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px" onclick="removePOItemRow(this)"><i class="bi bi-trash"></i></button></td>`;
  tbody.appendChild(row);
  const selected = row.querySelector('select');
  if (selected && productId) onPOProductChange(selected, idx);
  recalcPOTotal();
}

function filterPOProductsBySupplier() {
  const rows = document.querySelectorAll('#poItemsBody tr');
  if (!rows.length) return;
  const currentRows = Array.from(rows).map(row => {
    const idx = row.dataset.idx;
    return {
      product_id: row.querySelector(`[name="items[${idx}][product_id]"]`)?.value || '',
      product_name: row.querySelector(`[name="items[${idx}][product_name]"]`)?.value || '',
      quantity_ordered: row.querySelector(`[name="items[${idx}][quantity_ordered]"]`)?.value || 1,
      unit_price: row.querySelector(`[name="items[${idx}][unit_price]"]`)?.value || '0.00'
    };
  });
  document.getElementById('poItemsBody').innerHTML = '';
  _poItemCount = 0;
  currentRows.forEach(item => addPOItemRow(item.product_id, item.product_name, item.quantity_ordered, item.unit_price));
}

function onPOProductChange(sel, idx) {
  const opt = sel.options[sel.selectedIndex];
  const price = parseFloat(opt.dataset.price||0);
  const name  = opt.dataset.name || '';
  const row   = document.querySelector(`#poItemsBody tr[data-idx="${idx}"]`);
  if (!row) return;
  const nameInput  = row.querySelector(`[name="items[${idx}][product_name]"]`);
  const priceInput = row.querySelector(`[name="items[${idx}][unit_price]"]`);
  if (nameInput && name) nameInput.value = name;
  if (priceInput && price) priceInput.value = price.toFixed(2);
  recalcPOTotal();
}

function removePOItemRow(btn) {
  const row = btn.closest('tr');
  if (row) row.remove();
  recalcPOTotal();
}

function recalcPOTotal() {
  let total = 0;
  document.querySelectorAll('#poItemsBody tr').forEach(row => {
    const idx = row.dataset.idx;
    const qty = parseFloat(row.querySelector(`[name="items[${idx}][quantity_ordered]"]`)?.value||0);
    const up  = parseFloat(row.querySelector(`[name="items[${idx}][unit_price]"]`)?.value||0);
    const rt  = qty * up;
    const rtCell = row.querySelector('.po-row-total');
    if (rtCell) rtCell.textContent = rt.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
    total += rt;
  });
  const gt = document.getElementById('poGrandTotal');
  if (gt) gt.textContent = total.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
}

function submitCreatePO(submitForApproval) {
  const form = document.getElementById('createPOForm');
  const tbody = document.getElementById('poItemsBody');
  if (!form || !tbody) return;

  const rows = tbody.querySelectorAll('tr');
  if (!rows.length) { pToast('Add at least one item to the purchase order','warn'); return; }

  const items = [];
  rows.forEach(row => {
    const idx = row.dataset.idx;
    const pid   = row.querySelector(`[name="items[${idx}][product_id]"]`)?.value;
    const pname = row.querySelector(`[name="items[${idx}][product_name]"]`)?.value;
    const qty   = parseInt(row.querySelector(`[name="items[${idx}][quantity_ordered]"]`)?.value||0);
    const up    = parseFloat(row.querySelector(`[name="items[${idx}][unit_price]"]`)?.value||0);
    if (pname && qty > 0) {
      items.push({ product_id: pid||null, product_name: pname, quantity_ordered: qty, unit_price: up });
    }
  });

  if (!items.length) { pToast('Please enter valid product names and quantities','warn'); return; }

  const body = {
    action: 'create_po',
    supplier_id: document.getElementById('poSupplierSelect')?.value || '',
    expected_delivery_date: document.getElementById('poExpDelivery')?.value || '',
    notes: document.getElementById('poNotes')?.value || '',
    items: JSON.stringify(items),
    submit_for_approval: submitForApproval ? '1' : '0'
  };

  pApi({ method:'POST', body }, r => {
    if (r.success) {
      pToast(`Purchase Order ${r.po_number} created!`);
      closeModal('createPOModal');
      loadPurchaseOrders();
      loadDashboard();
    } else {
      pToast(r.message || 'Failed to create Purchase Order', 'danger');
    }
  });
}

function collectCreatePRItems() {
  let items = collectAllPreparedPRItems();
  const incomplete = items.some(item => !preparedPRItemIsComplete(item));
  return { items: items.filter(preparedPRItemIsComplete), incomplete, all: items };
}

function submitCreatePR(submitForApproval) {
  const collected = collectCreatePRItems();
  const allItems = collected.all || collected.items;
  if (collected.incomplete) {
    const missingSupplier = allItems.some(i => (i.product_id || i.product_name) && !i.supplier_id);
    if (missingSupplier) {
      showPRSupplierWarn(true);
      pToast(prNeedItemSupplierMessage(), 'warn');
      return;
    }
    pToast('Each requested item must have a product, supplier, valid quantity, unit, and price.', 'warn');
    return;
  }
  const items = collected.items;
  if (!items.length) { pToast('Add at least one complete requested item','warn'); return; }
  if (items.some(i => !i.supplier_id)) {
    showPRSupplierWarn(true);
    pToast(prNeedItemSupplierMessage(), 'warn');
    return;
  }
  showPRSupplierWarn(false);

  const dup = duplicatePreparedPRProduct(items);
  if (dup) {
    pToast('The same product cannot be added twice in one Purchase Request', 'warn');
    return;
  }
  if (!validatePreparedPRItems(items)) return;

  const reason      = document.getElementById('prReason')?.value;
  const currentPurpose = getCreatePRPurpose();
  syncPRPurposeDetailsRequired(currentPurpose);
  flushPRCustomerOrderInputs();
  const purposeFields = {
    purpose: currentPurpose,
    customer_order_reference: currentPurpose === 'Customer Order'
      ? String(document.getElementById('prCustomerOrderRef')?.value || '').trim()
      : '',
    customer_name: currentPurpose === 'Customer Order'
      ? String(document.getElementById('prCustomerNameValue')?.value || document.getElementById('prCustomerNameSearch')?.value || '').trim()
      : '',
    remarks: currentPurpose === 'Customer Order'
      ? buildCustomerOrderRemarksForSubmit()
      : (currentPurpose === 'Others' ? String(document.getElementById('prPurposeDetails')?.value || '').trim() : '')
  };

  const editId = document.getElementById('createPRForm')?.dataset?.editPrId || '';
  const revisingRejected = isRevisingRejectedPR();
  if (revisingRejected && !submitForApproval) {
    pToast('Resubmit this rejected Purchase Request for Admin approval', 'warn');
    return;
  }
  const headerSid = snapshotPreferredSupplier() || items[0].supplier_id;
  const body = {
    action:               editId ? 'update_pr' : 'create_pr',
    supplier_id:          headerSid,
    reason:               (reason && reason.trim()) ? reason.trim() : 'stock in',
    remarks:              purposeFields.remarks,
    purpose:              purposeFields.purpose,
    customer_order_reference: purposeFields.customer_order_reference,
    customer_name: purposeFields.customer_name,
    items:                JSON.stringify(items),
    submit_for_approval:  submitForApproval ? '1' : '0'
  };
  if (editId) body.id = editId;

  pApi({ method:'POST', body }, r => {
    if (r.success) {
      const nums = Array.isArray(r.pr_numbers) && r.pr_numbers.length
        ? r.pr_numbers
        : String(r.pr_number || '').split(',').map(s => s.trim()).filter(Boolean);
      const label = nums.join(', ') || r.pr_number || '';
      const count = parseInt(r.pr_count, 10) || nums.length || 1;
      if (count > 1) {
        pToast(`${count} Purchase Requests ${editId ? 'saved' : 'created'} (${label}) as ${r.status} — one PR per purpose and supplier`, 'success');
      } else if (revisingRejected) {
        pToast(`Purchase Request ${label} resubmitted as ${r.status}`);
      } else {
        pToast(`Purchase Request ${label} ${editId ? 'updated' : 'created'} as ${r.status}!`);
      }
      if (!submitForApproval) {
        writePRPurposeDraftBundle(Array.isArray(r.pr_ids) && r.pr_ids.length ? r.pr_ids : (r.pr_id ? [r.pr_id] : []));
      } else {
        clearPRPurposeDraftBundle();
      }
      closeModal('createPRModal');
      loadPurchaseRequests();
      if (typeof loadPRApprovalQueue === 'function') loadPRApprovalQueue();
      loadDashboard();
    } else {
      if (/select a supplier/i.test(String(r.message || ''))) {
        showPRSupplierWarn(true);
      }
      pToast(r.message || 'Failed to create Purchase Request', 'danger');
    }
  });
}

function viewPR(id, reviewMode) {
  const body   = document.getElementById('prDetailBody');
  const footer = document.getElementById('prDetailFooter');
  if (!body) return;
  body.innerHTML = '<div style="text-align:center;padding:40px"><div class="spinner-border spinner-border-sm me-2"></div> Loading Purchase Request details…</div>';
  openModal('prDetailModal');

  pApi({ query:{ action:'get_pr_detail', id } }, r => {
    if (!r.success) { body.innerHTML = `<p style="color:red">${esc(r.message)}</p>`; return; }
    const p = r.data;
    window._lastViewedPR = p;
    document.getElementById('prDetailTitle').innerHTML = `<i class="bi bi-file-earmark-text text-primary me-2"></i> ${esc(p.pr_number)}`;
    if (reviewMode && canApprovePurchaseRequests() && p.status === 'Pending Approval') {
      loadDropdowns(() => renderPRReviewEdit(p));
      return;
    }
    renderPRReadOnly(p);
  });
}

function renderPRReadOnly(p) {
  const body   = document.getElementById('prDetailBody');
  const footer = document.getElementById('prDetailFooter');
  if (!body) return;
  const items = p.items || [];
  body.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;background:#f8fafc;padding:16px;border-radius:10px;border:1px solid #e2e8f0">
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">PR Number</div>
          <div style="font-weight:700;font-family:monospace;color:#2563eb">${esc(p.pr_number)}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Request Date</div>
          <div style="font-weight:600">${fmtDate(p.created_at)}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Purchaser</div>
          <div style="font-weight:600;color:#0f172a">${esc(p.requested_by_name||'—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">PR Status</div>
          ${prStatusBadge(p.pipeline_status || p.status)}
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Supplier</div>
          <div style="font-weight:600">${formatPRSuppliers(p)}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Purpose</div>
          <div style="font-weight:600">${esc(formatPRPurpose(p))}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Approved By</div>
          <div>${esc(p.approved_by_name||'—')}${p.approved_at ? ` <span style="font-size:11px;color:#64748b">· ${fmtDateTime(p.approved_at)}</span>` : ''}</div>
        </div>
        ${String(p.purpose || '') === 'Customer Order' || formatPRCustomerOrderRef(p) ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Customer Order Reference</div><div style="font-size:13px">${esc(formatPRCustomerOrderRef(p) || '—')}</div></div>` : ''}
        ${p.reason ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Reason for Purchase</div><div style="font-size:13px">${esc(p.reason)}</div></div>` : ''}
        ${p.remarks && String(p.purpose || '') !== 'Low Stock Replenishment' ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">${esc(prPurposeRemarksLabel(p))}</div><div style="font-size:13px">${esc(p.remarks)}</div></div>` : ''}
        ${p.admin_remarks ? `<div style="grid-column:1/-1;background:${p.status==='Rejected'?'#fef2f2':'#fff7ed'};border:1px solid ${p.status==='Rejected'?'#fecaca':'#fed7aa'};border-radius:8px;padding:10px 12px"><div style="font-size:11px;color:#b91c1c;font-weight:700;text-transform:uppercase">${p.status==='Rejected' ? 'Rejection Reason' : 'Admin Remarks'}</div><div style="font-size:13px;color:#991b1b">${esc(p.admin_remarks)}</div></div>` : ''}
        ${prLinkedPOList(p).length ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Linked Purchase Order</div><div style="font-size:13px"><strong>${esc(p.generated_po_numbers || p.generated_po_number)}</strong></div></div>` : ''}
      </div>

      <h6 class="fw-bold mb-2">Requested Items (${items.length})</h6>
      <div class="table-responsive system-table-scroll">
      <table class="po-items-table" style="margin-bottom:16px">
        <thead><tr>
          <th>Product Name</th><th>Supplier</th><th>Brand</th><th>SKU</th><th>Qty</th><th>Unit</th>
          <th>Current Stock</th><th>Unit Price</th><th>Total</th>
        </tr></thead>
        <tbody>${items.length ? items.map(i=>`<tr>
          <td><strong>${esc(i.product_name)}</strong></td>
          <td>${esc(i.item_supplier_name || '—')}</td>
          <td>${esc(i.brand||'—')}</td>
          <td><span class="badge badge-gray">${esc(i.sku||'—')}</span></td>
          <td style="font-weight:800;font-size:15px">${i.quantity_requested}</td>
          <td>${esc(i.unit_of_measure||'—')}</td>
          <td><strong style="color:${(i.quantity_in_stock != null && i.reorder_level != null && Number(i.quantity_in_stock) <= Number(i.reorder_level)) ? '#dc2626' : '#0f172a'}">${i.quantity_in_stock != null ? i.quantity_in_stock : '—'}</strong>${i.reorder_level != null ? ` <span style="font-size:11px;color:#64748b">/ min ${i.reorder_level}</span>` : ''}</td>
          <td>${fmtMoney(i.actual_unit_price ?? i.unit_price ?? i.estimated_unit_price)}</td>
          <td style="font-weight:700">${fmtMoney(i.total_price)}</td>
        </tr>`).join('') : '<tr><td colspan="9" style="text-align:center;color:#64748b">No items</td></tr>'}</tbody>
      </table>
      </div>
      <div style="text-align:right;font-size:16px;font-weight:800;color:#0f172a">Grand Total: ${fmtMoney(p.total_estimated_amount)}</div>
      ${prHistoryHtml(p)}`;

  let btnHtml = `<button class="btn btn-outline-secondary" onclick="closeModal('prDetailModal')">Close</button>`;
  btnHtml += ` <button class="btn btn-outline-primary" onclick="window.open('print_pr.php?pr_id=${p.id}','_blank')"><i class="bi bi-printer me-1"></i> Print PR</button>`;
  if (p.can_add_items) {
    btnHtml += ` <button class="btn btn-outline-success" onclick="openAddPRItemsModal(${p.id})"><i class="bi bi-plus-lg me-1"></i> Add Items</button>`;
  }
  if (p.status === 'Draft' && (isProcurementPurchaser() || isProcurementAdmin())) {
    btnHtml += ` <button class="btn btn-primary" onclick="closeModal('prDetailModal');continueDraftPR(${p.id})"><i class="bi bi-pencil-square me-1"></i> Continue Draft</button>`;
  }
  if (purchaserCanReviseRejectedPR(p)) {
    btnHtml += ` <button class="btn btn-primary" onclick="closeModal('prDetailModal');reviseRejectedPR(${p.id})"><i class="bi bi-pencil-square me-1"></i> Revise PR</button>`;
  }
  if (p.status === 'Approved') {
    const linked = prLinkedPOList(p);
    if (linked.length) {
      linked.forEach(po => {
        btnHtml += ` <button class="btn btn-info" onclick="closeModal('prDetailModal');viewPO(${po.id})"><i class="bi bi-file-earmark-text me-1"></i> View PO (${esc(po.number)})</button>`;
        if (purchaserCanPrintPO()) {
          btnHtml += ` <button class="btn btn-primary" onclick="window.open(printPoUrl(${po.id}),'_blank')"><i class="bi bi-printer me-1"></i> Print PO</button>`;
        }
      });
    } else if (canApprovePurchaseRequests()) {
      btnHtml += ` <button class="btn btn-primary" onclick="approveAndGeneratePO(${p.id},'${esc(p.pr_number)}')"><i class="bi bi-cart-plus me-1"></i> Generate Purchase Order</button>`;
    } else if (isProcurementPurchaser()) {
      btnHtml += ` <span class="badge badge-yellow" style="align-self:center">PO not generated yet — wait for Admin</span>`;
    }
  }
  if (p.status === 'Pending Approval' && isProcurementPurchaser()) {
    btnHtml += ` <span class="badge badge-yellow" style="align-self:center">Awaiting Admin approval — PO printing unavailable</span>`;
  }
  if (footer) footer.innerHTML = btnHtml;
}

function renderPRReviewEdit(p) {
  const body   = document.getElementById('prDetailBody');
  const footer = document.getElementById('prDetailFooter');
  if (!body) return;
  const canApprove = canApprovePurchaseRequests() && p.status === 'Pending Approval';
  body.innerHTML = `
    <form id="prReviewForm" data-edit-pr-id="${p.id}" onsubmit="return false">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;background:#f8fafc;padding:16px;border-radius:10px;border:1px solid #e2e8f0">
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">PR Number</div>
          <div style="font-weight:700;font-family:monospace;color:#2563eb">${esc(p.pr_number)}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Request Date</div>
          <div style="font-weight:600">${fmtDate(p.created_at)}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Purchaser</div>
          <div style="font-weight:600;color:#0f172a">${esc(p.requested_by_name||'—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">PR Status</div>
          ${prStatusBadge(p.pipeline_status || p.status)}
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Approved By</div>
          <div>${esc(p.approved_by_name||'—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Purpose</div>
          <div style="font-weight:600">${esc(formatPRPurpose(p))}</div>
        </div>
        <div>
          <label class="form-label" style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Supplier</label>
          <select class="form-control" id="prReviewSupplierSelect" onchange="onPRReviewSupplierChange()">${buildPRSupplierOptions(p.supplier_id)}</select>
        </div>
        ${String(p.purpose || '') === 'Customer Order' || formatPRCustomerOrderRef(p) ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Customer Order Reference</div><div style="font-size:13px">${esc(formatPRCustomerOrderRef(p) || '—')}</div></div>` : ''}
        ${String(p.purpose || '') !== 'Low Stock Replenishment' ? `<div style="grid-column:1/-1">
          <label class="form-label" style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">${esc(prPurposeRemarksLabel(p))}</label>
          <textarea class="form-control" id="prReviewRemarks" rows="2">${esc(p.remarks || '')}</textarea>
        </div>` : ''}
      </div>

      <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
        <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:13px;font-weight:600;color:#0f172a"><i class="bi bi-list-check"></i> Requested Items</span>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPRItemRow()"><i class="bi bi-plus-lg me-1"></i> Add Item</button>
        </div>
        <div style="padding:14px 16px">
          <div class="pr-items-scroll system-table-scroll">
          <table class="modern-table" style="margin-bottom:8px">
            <thead><tr><th>Product Catalog</th><th>Item Description</th><th style="width:170px">Supplier</th><th style="width:110px">Qty</th><th style="width:90px">Unit</th><th style="width:130px">Unit Price (₱)</th><th style="width:110px">Total (₱)</th><th style="width:36px"></th></tr></thead>
            <tbody id="prReviewItemsBody"></tbody>
          </table>
          </div>
          <div style="text-align:right;font-size:14px;font-weight:700;color:#0f172a;padding:8px 0">
            Grand Total: ₱<span id="prReviewGrandTotal">0.00</span>
          </div>
        </div>
      </div>
      ${canApprove ? `<div style="margin-bottom:8px">
        <label class="form-label" for="prReviewAdminRemarks" style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Admin Review Remarks</label>
        <textarea class="form-control" id="prReviewAdminRemarks" rows="2" maxlength="1000" placeholder="Optional for approval; used as the reason if you reject.">${esc(p.admin_remarks || '')}</textarea>
        <div style="font-size:11.5px;color:#64748b;margin-top:4px">Saved with your decision and shown to the Purchaser. Item, quantity, and price edits are sent to the Purchaser as a change notice.</div>
      </div>` : ''}
      ${prHistoryHtml(p)}
    </form>`;

  _prItemCount = 0;
  const lockedSid = String(p.supplier_id || '');
  (p.items || []).forEach(item => {
    addPRItemRow(item.product_id, item.product_name, item.quantity_requested, item.estimated_unit_price, '', item.supplier_id || lockedSid);
  });
  if (!(p.items || []).length) addPRItemRow('', '', 1, 0, '', lockedSid);
  onPRReviewSupplierChange();
  recalcPRTotal();

  let btnHtml = `<button class="btn btn-outline-secondary" onclick="closeModal('prDetailModal')">Close</button>`;
  if (canApprove) {
    btnHtml += ` <button class="btn btn-outline-primary" onclick="savePRReviewChanges(${p.id})"><i class="bi bi-save me-1"></i> Save Changes</button>`;
    btnHtml += ` <button class="btn btn-outline-danger" onclick="rejectPR(${p.id}, '${esc(p.pr_number)}')"><i class="bi bi-x-circle me-1"></i> Reject PR</button>`;
    btnHtml += ` <button class="btn btn-success" onclick="saveThenApproveAndGeneratePO(${p.id}, '${esc(p.pr_number)}')"><i class="bi bi-check2-circle me-1"></i> Approve &amp; Generate PO</button>`;
  }
  if (footer) footer.innerHTML = btnHtml;
}

function prReviewAdminRemarks() {
  return String(document.getElementById('prReviewAdminRemarks')?.value || '').trim();
}

/** Admin saves review edits without deciding yet; the Purchaser is notified of the changes. */
function savePRReviewChanges(id) {
  if (!canApprovePurchaseRequests()) {
    pToast('Only Admin can edit a Purchase Request under review.', 'warn');
    return;
  }
  submitEditPR(r => {
    const changes = Array.isArray(r.changes) ? r.changes : [];
    pToast(changes.length ? `Saved ${changes.length} change(s). The Purchaser has been notified.` : 'No changes to save.', changes.length ? 'success' : 'info');
    loadPurchaseRequests();
    loadPRApprovalQueue();
    viewPR(id, true);
  });
}

/** Audit trail of who changed, approved, or rejected this PR and when. */
function prHistoryHtml(p) {
  const rows = Array.isArray(p && p.history) ? p.history : [];
  if (!rows.length) return '';
  return `<details style="margin-top:16px;border:1px solid #e2e8f0;border-radius:10px;background:#fff">
    <summary style="padding:10px 14px;font-size:13px;font-weight:600;color:#0f172a;cursor:pointer"><i class="bi bi-clock-history me-1"></i> Review &amp; Change History (${rows.length})</summary>
    <div style="padding:0 14px 10px">${rows.map(h => `
      <div style="padding:8px 0;border-top:1px solid #f1f5f9;font-size:12.5px">
        <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
          <strong style="color:#0f172a">${esc(h.label || h.action || '')}</strong>
          <span style="color:#64748b">${fmtDateTime(h.created_at)}</span>
        </div>
        <div style="color:#475569">by ${esc(h.user_name || 'System')}${h.user_role ? ` (${esc(h.user_role)})` : ''}${h.status ? ` · Status: ${esc(h.status)}` : ''}</div>
        ${(h.details || []).length ? `<ul style="margin:4px 0 0 18px;padding:0;color:#334155">${h.details.map(d => `<li>${esc(d)}</li>`).join('')}</ul>` : ''}
      </div>`).join('')}
    </div>
  </details>`;
}

// ── Add items to an existing PR (Draft / Pending Approval) ──
let _prAddItemsCtx = null;
let _prAddItemsBusy = false;

function ensureAddPRItemsModal() {
  let el = document.getElementById('prAddItemsModal');
  if (el) return el;
  el = document.createElement('div');
  el.className = 'modal-overlay';
  el.id = 'prAddItemsModal';
  el.innerHTML = `
    <div class="modal modal-lg">
      <div class="modal-header">
        <span class="modal-title" id="prAddItemsTitle"><i class="bi bi-plus-square text-success me-1"></i> Add Items to Purchase Request</span>
        <button class="modal-close" type="button" onclick="closeModal('prAddItemsModal')"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body">
        <div id="prAddItemsInfo" style="font-size:13px;color:#475569;margin-bottom:12px"></div>
        <div class="pr-items-scroll system-table-scroll">
          <table class="modern-table" style="margin-bottom:8px">
            <thead><tr><th>Product</th><th style="width:100px">Qty</th><th style="width:100px">Unit</th><th style="width:130px">Unit Price (₱)</th><th style="width:110px">Total (₱)</th><th style="width:36px"></th></tr></thead>
            <tbody id="prAddItemsBody"></tbody>
          </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPRExtraItemRow()"><i class="bi bi-plus-lg me-1"></i> Another Item</button>
        <div style="text-align:right;font-size:14px;font-weight:700;color:#0f172a;padding:8px 0">Added Total: ₱<span id="prAddItemsTotal">0.00</span></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" onclick="closeModal('prAddItemsModal')">Cancel</button>
        <button class="btn btn-success" type="button" id="prAddItemsSubmit" onclick="submitAddPRItems()"><i class="bi bi-check2-circle me-1"></i> Add to PR</button>
      </div>
    </div>`;
  document.body.appendChild(el);
  return el;
}

function openAddPRItemsModal(prId) {
  const p = window._lastViewedPR;
  if (!p || String(p.id) !== String(prId)) {
    pToast('Open the Purchase Request first.', 'warn');
    return;
  }
  if (!p.can_add_items) {
    pToast('Items can only be added to Draft or Pending Approval Purchase Requests.', 'warn');
    return;
  }
  ensureAddPRItemsModal();
  _prAddItemsCtx = {
    id: p.id,
    prNumber: p.pr_number,
    supplierId: String(p.supplier_id || ''),
    existing: new Set((p.items || []).map(i => String(i.product_id || '')).filter(Boolean))
  };
  document.getElementById('prAddItemsTitle').innerHTML = `<i class="bi bi-plus-square text-success me-1"></i> Add Items to ${esc(p.pr_number)}`;
  document.getElementById('prAddItemsInfo').innerHTML =
    `Supplier: <strong>${esc(p.supplier_name || formatPRSuppliers(p) || '—')}</strong> · Status: ${prStatusBadge(p.status)}<br>`
    + `<span style="font-size:12px">New items are added to this PR's existing list for the same supplier.${p.status === 'Pending Approval' ? ' The Admin will be notified and the PR stays in the approval queue.' : ''}</span>`;
  document.getElementById('prAddItemsBody').innerHTML = '';
  const start = () => { addPRExtraItemRow(); openModal('prAddItemsModal'); };
  if (_poProducts && _poProducts.length) start();
  else loadDropdowns(start);
}

function prExtraProductOptions() {
  const skip = _prAddItemsCtx ? _prAddItemsCtx.existing : new Set();
  return '<option value="">-- Select product --</option>' + (_poProducts || [])
    .filter(pr => !skip.has(String(pr.id)))
    .map(pr => `<option value="${pr.id}">${esc(pr.name)}${pr.brand ? ' (' + esc(pr.brand) + ')' : ''} · ${esc(pr.sku || '')} · Stock ${Number(pr.sellable_qty ?? pr.quantity_in_stock ?? 0)}</option>`)
    .join('');
}

function addPRExtraItemRow() {
  const tbody = document.getElementById('prAddItemsBody');
  if (!tbody) return;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><select class="form-control form-control-sm pr-x-product" onchange="onPRExtraProductChange(this)">${prExtraProductOptions()}</select></td>
    <td><input type="number" class="form-control form-control-sm pr-x-qty" min="1" step="1" value="1" oninput="recalcPRExtraTotal()"></td>
    <td><input type="text" class="form-control form-control-sm pr-x-unit" placeholder="Unit"></td>
    <td><input type="number" class="form-control form-control-sm pr-x-price" min="0" step="0.01" value="" oninput="recalcPRExtraTotal()"></td>
    <td class="pr-x-total" style="font-weight:600">0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" title="Remove" onclick="this.closest('tr').remove();recalcPRExtraTotal()"><i class="bi bi-trash"></i></button></td>`;
  tbody.appendChild(tr);
}

function onPRExtraProductChange(sel) {
  const row = sel.closest('tr');
  const prod = (_poProducts || []).find(x => String(x.id) === String(sel.value));
  if (row && prod) {
    const unit = row.querySelector('.pr-x-unit');
    const price = row.querySelector('.pr-x-price');
    const qty = row.querySelector('.pr-x-qty');
    if (unit) unit.value = prod.unit_of_measure || unit.value || 'piece';
    if (price && price.value === '') price.value = Number(prod.cost_price || 0).toFixed(2);
    if (qty && (!qty.value || qty.value === '1') && Number(prod.suggested_quantity) > 0 && Number(prod.is_low_stock)) qty.value = prod.suggested_quantity;
  }
  recalcPRExtraTotal();
}

function recalcPRExtraTotal() {
  let sum = 0;
  document.querySelectorAll('#prAddItemsBody tr').forEach(row => {
    const qty = parseInt(row.querySelector('.pr-x-qty')?.value || 0, 10) || 0;
    const price = parseFloat(row.querySelector('.pr-x-price')?.value || 0) || 0;
    const line = qty * price;
    sum += line;
    const cell = row.querySelector('.pr-x-total');
    if (cell) cell.textContent = line.toFixed(2);
  });
  const total = document.getElementById('prAddItemsTotal');
  if (total) total.textContent = sum.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function submitAddPRItems() {
  if (!_prAddItemsCtx || _prAddItemsBusy) return;
  const items = [];
  const seen = new Set();
  let problem = '';
  document.querySelectorAll('#prAddItemsBody tr').forEach(row => {
    const pid = String(row.querySelector('.pr-x-product')?.value || '');
    if (!pid) return;
    const prod = (_poProducts || []).find(x => String(x.id) === pid);
    const qty = parseInt(row.querySelector('.pr-x-qty')?.value || 0, 10);
    const unit = String(row.querySelector('.pr-x-unit')?.value || '').trim();
    const priceRaw = row.querySelector('.pr-x-price')?.value;
    const price = parseFloat(priceRaw);
    if (seen.has(pid)) { problem = 'The same product is listed twice.'; return; }
    seen.add(pid);
    if (!qty || qty <= 0) { problem = 'Each item needs a quantity of at least 1.'; return; }
    if (!unit) { problem = 'Each item needs a unit.'; return; }
    if (priceRaw === '' || Number.isNaN(price) || price < 0) { problem = 'Each item needs a valid unit price.'; return; }
    items.push({
      product_id: pid,
      product_name: prod ? (prod.brand ? `${prod.name} (${prod.brand})` : prod.name) : '',
      quantity_requested: qty,
      estimated_unit_price: price,
      unit_of_measure: unit,
      supplier_id: _prAddItemsCtx.supplierId
    });
  });
  if (problem) { pToast(problem, 'warn'); return; }
  if (!items.length) { pToast('Select at least one product to add.', 'warn'); return; }

  _prAddItemsBusy = true;
  const btn = document.getElementById('prAddItemsSubmit');
  if (btn) btn.disabled = true;
  const prId = _prAddItemsCtx.id;
  pApi({ method: 'POST', body: { action: 'add_pr_items', id: prId, items: JSON.stringify(items) } }, r => {
    _prAddItemsBusy = false;
    if (btn) btn.disabled = false;
    if (!r.success) {
      pToast(r.message || 'Failed to add items', 'danger');
      return;
    }
    pToast(r.message || 'Items added');
    closeModal('prAddItemsModal');
    loadPurchaseRequests();
    if (typeof loadPRApprovalQueue === 'function' && canApprovePurchaseRequests()) loadPRApprovalQueue();
    viewPR(prId);
  });
}

// ── Admin Edit PR (updates existing record) ─────────────────
function openEditPR(id) {
  viewPR(id);
}

function closeEditPRModal() {
  const form = document.getElementById('createPRForm');
  if (form) {
    delete form.dataset.editPrId;
    delete form.dataset.editPrStatus;
  }
  syncPRRevisionBanner(null);
  const title = document.querySelector('#createPRModal .modal-title');
  if (title) title.innerHTML = `<i class="bi bi-file-earmark-plus text-primary"></i> Create Purchase Request`;
  const footer = document.querySelector('#createPRModal .modal-footer');
  if (footer) {
    footer.innerHTML = `
      <button class="btn btn-outline-secondary" onclick="closeModal('createPRModal')">Cancel</button>
      <button class="btn btn-outline-primary" onclick="submitCreatePR(false)"><i class="bi bi-save me-1"></i> Save as Draft</button>
      <button class="btn btn-primary" onclick="submitCreatePR(true)"><i class="bi bi-send me-1"></i> Submit for Admin Approval</button>`;
  }
  closeModal('createPRModal');
}

function submitEditPR(afterSave) {
  const id = getPREditId();
  if (!id) { pToast('No PR selected for editing', 'warn'); return; }

  const tbody = getPRItemsTbody();
  const rows  = tbody?.querySelectorAll('tr') || [];
  if (!rows.length) { pToast('Add at least one item', 'warn'); return; }

  const headerSid = String(getSelectedPRSupplierId() || '');
  if (!headerSid) {
    pToast(prNeedItemSupplierMessage(), 'warn');
    return;
  }
  const items = [];
  let incomplete = false;
  rows.forEach(row => {
    const idx = row.dataset.idx;
    const pid   = row.querySelector(`[name="items[${idx}][product_id]"]`)?.value;
    const pname = String(row.querySelector(`[name="items[${idx}][product_name]"]`)?.value || '').trim();
    const qty   = parseInt(row.querySelector(`[name="items[${idx}][quantity_requested]"]`)?.value||0, 10);
    const upRaw = row.querySelector(`[name="items[${idx}][estimated_unit_price]"]`)?.value;
    const up    = parseFloat(upRaw);
    const sid   = getPRRowSupplierId(row) || headerSid;
    const unit  = String(row.querySelector(`[name="items[${idx}][unit_of_measure]"]`)?.value || '').trim();
    if (!pid && !pname) return;
    if (!pname || qty <= 0 || !sid || !unit || upRaw === '' || Number.isNaN(up) || up < 0) {
      incomplete = true;
      return;
    }
    items.push({ product_id: pid||null, product_name: pname, quantity_requested: qty, estimated_unit_price: up, supplier_id: sid, unit_of_measure: unit });
  });
  if (incomplete) {
    pToast('Each requested item must have a product, valid quantity, unit, and price.', 'warn');
    return;
  }
  if (!items.length) { pToast('Please enter valid product names and quantities', 'warn'); return; }
  const pids = items.map(i => String(i.product_id || '')).filter(id => id && id !== 'null' && id !== 'undefined');
  if (pids.find((id, i) => pids.indexOf(id) !== i)) {
    pToast('The same product cannot be added twice in one Purchase Request', 'warn');
    return;
  }
  const saveSid = headerSid;

  if (isPRReviewModalOpen() && window._lastViewedPR) {
    const existingPurpose = String(window._lastViewedPR.purpose || '').trim();
    const remarksNow = String(getPRRemarksEl()?.value || '').trim();
    if (purposeDetailsAreRequired(existingPurpose) && !remarksNow) {
      pToast('Purpose Details are required.', 'warn');
      getPRRemarksEl()?.focus();
      return;
    }
    if (existingPurpose === 'Customer Order') {
      const existingRef = String(window._lastViewedPR.customer_order_reference || '').trim();
      if (!existingRef) {
        pToast('Customer Order Reference is required', 'warn');
        return;
      }
    }
  }

  const body = {
    action: 'update_pr',
    id,
    supplier_id: saveSid,
    remarks: getPRRemarksEl()?.value || '',
    items: JSON.stringify(items)
  };
  const reasonEl = getPRReasonEl();
  if (reasonEl) body.reason = reasonEl.value || '';
  if (isPRReviewModalOpen() && window._lastViewedPR) {
    body.purpose = String(window._lastViewedPR.purpose || '').trim();
    body.customer_order_reference = String(window._lastViewedPR.customer_order_reference || '').trim();
  } else if (document.getElementById('prPurpose') && isCreatePRModalOpen()) {
    const purposeFields = validatePRPurposeFields();
    if (!purposeFields) return;
    body.purpose = purposeFields.purpose;
    body.customer_order_reference = purposeFields.customer_order_reference;
    body.customer_name = purposeFields.customer_name || '';
    body.remarks = purposeFields.remarks;
  }

  pApi({ method:'POST', body }, r => {
    if (r.success) {
      if (typeof afterSave === 'function') {
        afterSave(r);
        return;
      }
      pToast(`PR ${r.pr_number || ''} updated successfully`);
      loadPurchaseRequests();
      loadPRApprovalQueue();
      loadDashboard();
      if (!isPRReviewModalOpen()) closeEditPRModal();
    } else {
      pToast(r.message || 'Failed to update PR', 'danger');
    }
  });
}

// ── STEP 2: ADMIN PR APPROVAL QUEUE ─────────────────────────
function loadPRApprovalQueue(silent) {
  const tbody = document.getElementById('approvalBody');
  if (!silent && tbody) {
    tbody.innerHTML = '<tr class="loading-row"><td colspan="8"><span class="loader"></span> Loading Pending Approvals…</td></tr>';
  }
  pApi({ query:{ action:'get_purchase_requests', status:'Pending Approval' } }, r => {
    if (r && r.success === false) return;
    const data = r.data || [];
    applyPRApprovalCount(data.length);
    if (!tbody) return;
    const sig = liveRowsSignature(data, ['id', 'status', 'item_count', 'total_estimated_amount', 'supplier_name', 'pr_number', 'purpose', 'customer_order_reference', 'remarks']);
    if (silent && sig === _prQueueSig) return;
    if (silent && typeof isPRReviewModalOpen === 'function' && isPRReviewModalOpen()) return;
    _prQueueSig = sig;

    if (!data.length) {
      tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:48px;color:#64748b"><i class="bi bi-check-circle-fill" style="color:#10b981;font-size:40px;display:block;margin-bottom:12px"></i>No pending PR approvals</td></tr>';
      return;
    }
    if (!canApprovePurchaseRequests()) {
      tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:48px;color:#64748b">PR approval is Admin-only. Warehouse Head cannot approve PRs or POs.</td></tr>';
      return;
    }
    tbody.innerHTML = data.map(p => `<tr data-pr-id="${p.id}">
      <td><strong style="font-family:monospace" class="text-primary">${esc(p.pr_number)}</strong></td>
      <td>${formatPRSuppliers(p)}</td>
      <td style="font-size:12.5px">${esc(formatPRPurpose(p))}${prPurposeListExtra(p)}</td>
      <td><span class="badge badge-gray">${p.item_count} item(s)</span></td>
      <td style="font-weight:700">${fmtMoney(p.total_estimated_amount)}</td>
      <td>${esc(p.requested_by_name||'—')}</td>
      <td style="font-size:12px;color:#64748b">${fmtDate(p.created_at)}</td>
      <td>
        <button type="button" class="btn btn-sm btn-success" title="Review & Approve" onclick="viewPR(${p.id}, true)"><i class="bi bi-eye me-1"></i> Review &amp; Approve</button>
      </td>
    </tr>`).join('');
  });
}

function showApproveGeneratePOSuccessPopup(prNumber, poNumber, poId) {
  const prLabel = String(prNumber || '').trim() || 'This Purchase Request';
  const poLabel = String(poNumber || '').trim() || '—';
  const hasPo = (parseInt(poId, 10) || 0) > 0;

  if (window.Swal) {
    Swal.fire({
      icon: 'success',
      iconColor: '#10b981',
      title: 'Purchase Order Generated',
      html: `
        <div style="text-align:center;padding:2px 4px 0">
          <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#475569">
            <strong style="color:#0f172a">${esc(prLabel)}</strong> has been approved successfully.
          </p>
          <div style="display:inline-block;min-width:220px;max-width:100%;padding:14px 18px;border:1px solid #d1fae5;border-radius:12px;background:#ecfdf5;margin-bottom:14px">
            <div style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#047857;margin-bottom:4px">Purchase Order</div>
            <div style="font-size:18px;font-weight:700;color:#065f46;letter-spacing:.01em">${esc(poLabel)}</div>
          </div>
          <p style="margin:0;font-size:13px;line-height:1.45;color:#64748b">
            The purchase order is now ready for review.
          </p>
        </div>
      `,
      confirmButtonText: hasPo
        ? '<i class="bi bi-file-earmark-text me-1"></i> View Purchase Order'
        : 'OK',
      confirmButtonColor: '#10b981',
      showCloseButton: true,
      allowOutsideClick: true,
      allowEscapeKey: true,
      width: 420,
      customClass: {
        popup: 'pr-approve-success-popup',
        title: 'pr-approve-success-title',
        confirmButton: 'pr-approve-success-btn'
      }
    }).then(res => {
      if (res.isConfirmed && hasPo) {
        window.open(printPoUrl(parseInt(poId, 10), 'pr-approval'), '_blank');
      }
    });
    return;
  }

  pToast(`${prLabel} approved. PO ${poLabel} ready.`, 'success');
  if (hasPo) {
    appConfirm('View Purchase Order?').then(ok => {
      if (ok) window.open(printPoUrl(parseInt(poId, 10), 'pr-approval'), '_blank');
    });
  }
}

function saveThenApproveAndGeneratePO(id, prNumber) {
  if (!canApprovePurchaseRequests()) {
    pToast('Only Admin can approve PRs and generate POs. Warehouse Head cannot approve.', 'warn');
    return;
  }
  const adminRemarks = prReviewAdminRemarks();
  submitEditPR(function() {
    approveAndGeneratePO(id, prNumber, true, adminRemarks);
  });
}

function approveAndGeneratePO(id, prNumber, skipConfirm, adminRemarks) {
  if (!canApprovePurchaseRequests()) {
    pToast('Only Admin can approve PRs and generate POs. Warehouse Head cannot approve.', 'warn');
    return;
  }
  const run = () => {
    if (_prApproveBusy) return;
    _prApproveBusy = true;
    pApi({ method:'POST', body:{ action:'approve_and_generate_po', id, pr_id: id, admin_remarks: String(adminRemarks || '') } }, r => {
      _prApproveBusy = false;
      if (r.success) {
        const generatedPoId = parseInt(r.po_id, 10) || 0;
        const generatedPoNumber = String(r.po_number || '').trim();
        const approvedPrNumber = String(r.pr_number || prNumber || '').trim();
        closeModal('prDetailModal');
        if (document.getElementById('createPRForm')?.dataset?.editPrId) closeEditPRModal();
        loadPRApprovalQueue();
        loadPurchaseRequests();
        loadPurchaseOrders();
        if (typeof loadSupplierInvoices === 'function') loadSupplierInvoices();
        loadDashboard();
        showApproveGeneratePOSuccessPopup(approvedPrNumber, generatedPoNumber, generatedPoId);
      } else {
        if (window.Swal) Swal.fire('Cannot Approve', r.message || 'Failed', 'error');
        else pToast(r.message || 'Failed', 'danger');
      }
    });
  };

  if (skipConfirm) { run(); return; }
  run();
}

function approvePR(id, prNumber) {
  // Backward-compatible alias used by older buttons
  approveAndGeneratePO(id, prNumber);
}

function rejectPR(id, prNumber) {
  if (!canApprovePurchaseRequests()) {
    pToast('Only Admin can reject purchase requests. Warehouse Head cannot approve/reject PRs.', 'warn');
    return;
  }
  document.getElementById('rejectPRId').value = id;
  document.getElementById('rejectPRNumber').textContent = prNumber || '';
  const remarksEl = document.getElementById('rejectAdminRemarks');
  if (remarksEl) remarksEl.value = (typeof prReviewAdminRemarks === 'function' && isPRReviewModalOpen()) ? prReviewAdminRemarks() : '';
  const title = document.querySelector('#rejectPRModal .modal-title');
  if (title) title.innerHTML = '<i class="bi bi-x-circle me-1"></i> Reject Purchase Request';
  openModal('rejectPRModal');
}

function confirmRejectPR() {
  if (!canApprovePurchaseRequests()) {
    pToast('Only Admin can reject purchase requests. Warehouse Head cannot approve/reject PRs.', 'warn');
    return;
  }
  if (_prRejectBusy) return;
  const id      = document.getElementById('rejectPRId')?.value;
  const remarks = (document.getElementById('rejectAdminRemarks')?.value || '').trim();
  const prNumber = (document.getElementById('rejectPRNumber')?.textContent || '').trim();
  if (!id) {
    pToast('No Purchase Request selected', 'warn');
    return;
  }
  if (!remarks) {
    pToast('A rejection reason is required', 'warn');
    document.getElementById('rejectAdminRemarks')?.focus();
    return;
  }

  const postReject = () => {
    if (_prRejectBusy) return;
    _prRejectBusy = true;
    pApi({
      method: 'POST',
      body: {
        action: 'update_pr_status',
        id,
        status: 'Rejected',
        admin_remarks: remarks
      }
    }, r => {
      _prRejectBusy = false;
      if (r.success) {
        pToast(r.already_rejected ? 'This Purchase Request is already rejected' : 'Purchase Request rejected');
        closeModal('rejectPRModal');
        closeModal('prDetailModal');
        loadPRApprovalQueue();
        loadPurchaseRequests();
        loadDashboard();
      } else {
        pToast(r.message || 'Failed to reject PR', 'danger');
      }
    });
  };

  if (window.Swal) {
    Swal.fire({
      title: 'Confirm Rejection?',
      html: `Reject Purchase Request <strong>${esc(prNumber || ('#' + id))}</strong>?<br><span style="font-size:12px;color:#64748b">The purchaser will see this reason. A rejected PR cannot generate a PO.</span>`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#64748b',
      confirmButtonText: 'Confirm Rejection',
      cancelButtonText: 'Cancel'
    }).then(result => { if (result.isConfirmed) postReject(); });
  } else {
    appConfirm(`Reject Purchase Request ${prNumber || ('#' + id)}? This cannot generate a PO.`).then(ok => {
      if (ok) postReject();
    });
  }
}

// ── STEP 3: PURCHASE ORDER (PO) GENERATION & MANAGEMENT ──
function generatePOFromPR(prId, prNumber) {
  // Prefer atomic admin path; fallback keeps idempotent generate_po_from_pr
  if (isProcurementWarehouseHead()) {
    pToast('Warehouse Head cannot generate POs. Only Admin can approve and generate POs.', 'warn');
    return;
  }
  if (isProcurementAdmin()) {
    approveAndGeneratePO(prId, prNumber);
    return;
  }
  pApi({ method:'POST', body:{ action:'generate_po_from_pr', pr_id: prId } }, r => {
    if (r.success) {
      if (window.Swal) {
        Swal.fire({
          title: r.already_existed ? 'PO Already Exists' : 'Purchase Order Generated!',
          text: `PO ${r.po_number} linked to PR ${prNumber}.`,
          icon: 'success',
          timer: 2000,
          showConfirmButton: false
        });
      } else {
        pToast(`PO ${r.po_number} ready`);
      }
      loadPurchaseRequests();
      loadPurchaseOrders();
      loadDashboard();
      if (r.po_id) viewPO(r.po_id);
    } else {
      pToast(r.message || 'Failed to generate PO', 'danger');
    }
  });
}

function poPricingDisplay(p) {
  const lines = p.price_lines || [];
  if (!lines.length) {
    return { items: '', purchase: fmtMoney(0), markup: `${fmtMoney(0)} <span style="font-size:10px;color:#64748b">(0.00%)</span>`, selling: `<strong>${fmtMoney(0)}</strong>` };
  }
  const stack = (html) => lines.length > 1 ? `<div class="po-stack-line">${html}</div>` : html;
  return {
    items: lines.map(it => `<div class="po-stack-line">${esc(it.product_name || 'Item')}</div>`).join(''),
    purchase: lines.map(it => stack(fmtMoney(it.purchase_price || 0))).join(''),
    markup: lines.map(it => {
      const purchase = Number(it.purchase_price || 0);
      const sell = Number(it.selling_price || 0);
      const amt = it.markup_amount != null ? Number(it.markup_amount) : (sell - purchase);
      const pct = it.markup_percent != null
        ? Number(it.markup_percent)
        : (purchase > 0 ? (amt / purchase) * 100 : 0);
      return stack(`${fmtMoney(amt)} <span style="font-size:10px;color:#64748b">(${Number(pct).toFixed(2)}%)</span>`);
    }).join(''),
    selling: lines.map(it => stack(`<strong>${fmtMoney(it.selling_price || 0)}</strong>`)).join('')
  };
}

function loadPurchaseOrders(silent) {
  const tbody = document.getElementById('poBody');
  if (!tbody) return;
  if (!silent) {
    tbody.innerHTML = '<tr class="loading-row"><td colspan="13"><span class="loader"></span> Loading Purchase Orders…</td></tr>';
  }
  const params = { action:'get_purchase_orders' };
  const sf = document.getElementById('poStatusFilter')?.value;
  const sq = document.getElementById('poSearch')?.value;
  if (sf && sf !== 'all') params.status = sf;
  if (sq) params.search = sq;
  pApi({ query:params }, r => {
    if (!r.success) {
      if (!silent) tbody.innerHTML = `<tr><td colspan="13" style="color:red;padding:20px">${esc(r.message)}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    const sig = liveRowsSignature(rows, ['id', 'status', 'receiving_status', 'receipt_workflow_status', 'qty_received', 'qty_stocked', 'total_amount', 'po_number']) + '|' + (sf || '') + '|' + (sq || '');
    if (silent && sig === _poListSig) return;
    _poListSig = sig;
    _posData = rows;
    filterPOs();
  });
}

function filterPOs() {
  const sq = (document.getElementById('poSearch')?.value||'').toLowerCase();
  const data = sq ? _posData.filter(p=>(p.po_number+(p.supplier_name||'')+(p.pr_number||'')+(p.receiving_status||'')).toLowerCase().includes(sq)) : _posData;
  renderPOs(data, 'poBody');
}

function renderPOs(data, tbodyId) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:32px;color:#64748b">No purchase orders found</td></tr>'; return; }
  tbody.innerHTML = data.map(p => {
    let actions = `<button class="btn-icon primary" title="View" onclick="viewPO(${p.id})"><i class="bi bi-eye"></i></button> `;
    if (purchaserCanPrintPO()) {
      actions += `<button class="btn-icon" title="Print PO" onclick="window.open(printPoUrl(${p.id}),'_blank')" style="border-color:#7c3aed;color:#7c3aed"><i class="bi bi-printer"></i></button> `;
    }
    if (canUpdatePurchaseOrders() && p.status === 'Draft') actions += `<button class="btn btn-sm btn-primary" title="Send PO to Supplier" onclick="updatePOStatus(${p.id}, 'Submitted', '${esc(p.po_number)}')"><i class="bi bi-send me-1"></i> Send PO</button> `;
    if (canUpdatePurchaseOrders() && p.status === 'Sent') actions += `<button class="btn btn-sm btn-info" title="Supplier Confirmed" onclick="updatePOStatus(${p.id}, 'Supplier Confirmed', '${esc(p.po_number)}')"><i class="bi bi-check2-square me-1"></i> Confirm</button> `;
    const receiptWf = p.receipt_workflow_status || '';
    const receiptId = parseInt(p.latest_receipt_id, 10) || 0;
    if (receiptId > 0 && isReadyForWarehouseStockIn(receiptWf) && canExecuteStockIn()) {
      actions += ` <button class="btn btn-sm btn-success" title="Stock In to inventory" onclick="openStockInDetail(${receiptId})"><i class="bi bi-box-seam me-1"></i> Stock In</button>`;
    }
    const qtyOrdered = parseInt(p.qty_ordered, 10) || 0;
    const qtyReceived = parseInt(p.qty_processed != null ? p.qty_processed : p.qty_received, 10) || 0;
    const qtyRemaining = poRemainingQty(p);
    const prices = poPricingDisplay(p);
    return `<tr>
      <td><strong style="font-family:monospace" class="text-primary">${esc(p.po_number)}</strong>${p.pr_number ? `<br><span style="font-size:10px;color:#64748b">PR: ${esc(p.pr_number)}</span>` : ''}</td>
      <td>${esc(p.supplier_name||'—')}</td>
      <td><span class="badge badge-gray">${p.item_count} item(s)</span>${prices.items ? `<div style="font-size:12px;margin-top:2px">${prices.items}</div>` : ''}<span style="font-size:10px;color:#64748b">${qtyOrdered} ordered · ${qtyReceived} rcv · ${qtyRemaining} rem</span></td>
      <td style="font-weight:700">${fmtMoney(p.total_amount)}</td>
      <td>${prices.purchase}</td>
      <td>${prices.markup}</td>
      <td>${prices.selling}</td>
      <td>${poStatusBadge(p.status, p)}</td>
      <td>${isPartialReceivedDisplay(p) ? '—' : receivingStatusBadge(p.receiving_status || 'Expected to Arrive', p)}</td>
      <td>${fmtDate(p.expected_delivery_date)}${p.order_date ? `<br><span style="font-size:10px;color:#64748b">Ordered: ${fmtDate(p.order_date)}</span>` : ''}</td>
      <td>${esc(p.requested_by_name||'—')}</td>
      <td style="font-size:12px;color:#64748b">${fmtDate(p.created_at)}</td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
}

function updatePOStatus(id, status, poNumber) {
  if (!canUpdatePurchaseOrders()) {
    pToast('Warehouse Head cannot approve or change PO status. Use Mark as Received / Stock-In for deliveries.', 'warn');
    return;
  }
  pApi({ method:'POST', body:{ action:'update_po_status', id, status } }, r => {
    if (r.success) {
      pToast(`PO ${poNumber} updated to ${status}!`);
      loadPurchaseOrders(); loadDashboard();
    } else {
      pToast(r.message || 'Failed to update PO status', 'danger');
    }
  });
}

function viewPO(id) {
  const modal = document.querySelector('#poDetailModal');
  const body = modal?.querySelector('#poDetailBody') || document.getElementById('poDetailBody');
  const footer = modal?.querySelector('#poDetailFooter') || document.getElementById('poDetailFooter');
  if (!body) return;
  body.innerHTML = '<div style="text-align:center;padding:40px"><span class="loader"></span> Loading Purchase Order…</div>';
  openModal('poDetailModal');
  pApi({ query:{ action:'get_po_detail', id } }, r => {
    if (!r.success) { body.innerHTML = `<p style="color:red">${esc(r.message)}</p>`; return; }
    const p = r.data;
    const titleEl = modal?.querySelector('#poDetailTitle') || document.getElementById('poDetailTitle');
    if (titleEl) titleEl.innerHTML = `<i class="bi bi-file-earmark-text text-primary me-2"></i> ${esc(p.po_number)} Clarenzon Marketing.`;
    const items = p.items || [];
    const itemCount = items.length;
    if (!items.length) {
      items.push({
        product_name: 'No purchase order line items recorded for this PO.',
        sku: '',
        quantity_ordered: '',
        quantity_received: '',
        unit_price: 0,
        total_price: 0
      });
    }
    body.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;background:#f8fafc;padding:16px;border-radius:10px;border:1px solid #e2e8f0">
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Supplier</div>
          <div style="font-weight:600">${esc(p.supplier_name||'—')}</div>
          <div style="font-size:12px;color:#64748b">${esc(p.supplier_email||'')} ${esc(p.supplier_phone||'')}</div>
          ${p.supplier_address ? `<div style="font-size:12px;color:#64748b">${esc(p.supplier_address)}</div>` : ''}
          <div style="font-size:12px;color:#64748b">Payment Terms: <strong>${esc(p.supplier_payment_terms||'Net 30')}</strong></div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">PO & Delivery</div>
          <div style="font-size:12px;color:#64748b">PO Number: <strong>${esc(p.po_number)}</strong></div>
          <div style="margin-top:4px">${incomingDeliveryStatusBadge(p)}</div>
          <div style="font-size:12px;color:#64748b;margin-top:4px">Order Date: <strong>${fmtDate(p.order_date || p.created_at)}</strong></div>
          <div style="font-size:12px;color:#64748b">Expected Delivery: <strong>${fmtDate(p.expected_delivery_date)}</strong></div>
          ${p.pr_number ? `<div style="font-size:12px;color:#64748b">Linked PR: <strong>${esc(p.pr_number)}</strong></div>` : ''}
        </div>
        ${p.notes ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">PO Notes</div><div style="font-size:13px">${esc(p.notes)}</div></div>` : ''}
      </div>
      ${poRemainingQty(p) > 0 ? `<div style="margin-bottom:12px">${receivingStatusBadge(p.receiving_status || 'Expected to Arrive', p)}</div>` : ''}
      <h6 class="fw-bold mb-2">Order Items (${itemCount})</h6>
      <div class="table-responsive system-table-scroll po-view-table-wrap">
      <table class="po-items-table po-view-items-table">
        <thead><tr>
          <th>Product Name</th>
          <th>Brand</th>
          <th>SKU</th>
          <th class="po-qty-col">Ordered Quantity</th>
          <th class="po-qty-col">Received Quantity</th>
          <th class="po-qty-col">Replacement</th>
          <th class="po-qty-col">Remaining Quantity</th>
          <th class="po-note-col">Reason / Note</th>
          <th class="po-money-col">Unit Price</th>
          <th class="po-money-col">Total Amount</th>
        </tr></thead>
        <tbody>${items.map(i=>{
          const ordered = parseInt(i.quantity_ordered, 10) || 0;
          const processed = parseInt(i.quantity_processed ?? i.quantity_received, 10) || 0;
          const remaining = i.quantity_remaining != null && i.quantity_remaining !== ''
            ? Math.max(0, parseInt(i.quantity_remaining, 10) || 0)
            : Math.max(0, ordered - processed);
          const damagedMissing = Math.max(0, parseInt(i.quantity_damage_missing, 10) || 0);
          const note = String(i.note_to_supplier || '').trim();
          const received = processed;
          const purchase = parseFloat(i.purchase_price ?? i.unit_price ?? 0) || 0;
          const unit = i.unit_of_measure;
          const hasQty = i.quantity_ordered !== '' && i.quantity_ordered != null;
          return `<tr>
          <td><strong>${esc(i.product_name)}</strong></td>
          <td>${esc(i.brand||'—')}</td>
          <td><span class="badge badge-gray">${esc(i.sku||'—')}</span></td>
          <td class="po-qty-col">${hasQty ? poViewQtyWithUnit(ordered, unit) : '—'}</td>
          <td class="po-qty-col"><span style="color:${received >= ordered && ordered > 0 ? '#10b981' : '#f59e0b'}">${hasQty ? poViewQtyWithUnit(received, unit) : '—'}</span></td>
          <td class="po-qty-col"><span style="color:${damagedMissing > 0 ? '#dc2626' : '#64748b'}">${hasQty ? poViewQtyWithUnit(damagedMissing, unit) : '—'}</span></td>
          <td class="po-qty-col"><span style="color:${remaining === 0 && ordered > 0 ? '#10b981' : '#64748b'}">${hasQty ? poViewQtyWithUnit(remaining, unit) : '—'}</span></td>
          <td class="po-note-col" style="font-size:12.5px;color:#334155">${note ? esc(note) : ''}</td>
          <td class="po-money-col">${fmtMoney(purchase)}</td>
          <td class="po-money-col" style="font-weight:700">${fmtMoney(i.total_price)}</td>
        </tr>`;
        }).join('')}</tbody>
      </table>
      </div>
      <div style="text-align:right;font-size:16px;font-weight:800;color:#0f172a">Grand Total: ${fmtMoney(p.total_amount)}</div>`;

    if (footer) {
      const receiptPrintId = parseInt(p.receiving_receipt_id || p.latest_receipt_id, 10) || 0;
      const printHref = receiptPrintId > 0
        ? printSupplierReceiptUrl(receiptPrintId)
        : printPoUrl(p.id);
      footer.innerHTML = `<button class="btn btn-outline-secondary" onclick="closeModal('poDetailModal')">Close</button>
        <button class="btn btn-outline-primary" onclick="window.open('${printHref}','_blank')"><i class="bi bi-printer me-1"></i> Print PO</button>`;
    }
  });
}

// ── STEP 4: PURCHASE RECEIPT (GOODS RECEIVED) ───────────────
function loadPurchaseReceipts(silent) {
  const tbody = document.getElementById('receiptBody');
  if (!tbody) return;
  if (!silent) {
    tbody.innerHTML = '<tr class="loading-row"><td colspan="8"><span class="loader"></span> Loading Goods Receipts…</td></tr>';
  }
  const params = { action:'get_purchase_receipts' };
  const sq = document.getElementById('receiptSearch')?.value;
  const status = document.getElementById('receiptStatusFilter')?.value;
  if (sq) params.search = sq;
  if (status && status !== 'all') params.status = status;
  pApi({ query:params }, r => {
    if (!r.success) {
      if (!silent) tbody.innerHTML = `<tr><td colspan="8" style="color:red;padding:20px">${esc(r.message)}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    const sig = liveRowsSignature(rows, ['id', 'status', 'workflow_status', 'total_received_qty', 'receipt_number']) + '|' + (sq || '') + '|' + (status || '');
    if (silent && sig === _receiptListSig) return;
    _receiptListSig = sig;
    _receiptsData = rows;
    renderReceipts(_receiptsData);
  });
}

function filterReceipts() { loadPurchaseReceipts(); }

function renderReceipts(data) {
  const tbody = document.getElementById('receiptBody');
  if (!tbody) return;
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:#64748b">No Purchase Receipts found</td></tr>'; return; }
  tbody.innerHTML = data.map(r => `<tr>
    <td><strong style="font-family:monospace" class="text-success">${esc(r.receipt_number)}</strong></td>
    <td><strong style="font-family:monospace">${esc(r.po_number)}</strong></td>
    <td>${esc(r.supplier_name||'—')}</td>
    <td><span class="badge badge-green">${r.total_received_qty} pcs (${r.item_count} items)</span></td>
    <td><span class="badge ${(r.display_status || r.status) === 'Received' || r.status === 'Fully Received' ? 'badge-green' : 'badge-yellow'}">${esc(r.display_status || r.status)}</span><br><span class="small">${esc(Number(r.is_replacement) === 1 ? 'Replacement order' : (r.workflow_status||''))}</span></td>
    <td>${fmtDate(r.date_received)}</td>
    <td>${esc(r.received_by_name||'Warehouse Staff')}</td>
    <td>
      <button class="btn-icon primary" title="View" onclick="viewReceipt(${r.id})"><i class="bi bi-eye"></i></button>
      <button class="btn-icon" title="Edit" onclick="editReceipt(${r.id})"><i class="bi bi-pencil"></i></button>
      <button class="btn-icon" title="Receipt / Print" onclick="window.open('print_supplier_receipt.php?receipt_id=${r.id}','_blank')" style="border-color:#10b981;color:#10b981"><i class="bi bi-printer"></i></button>
    </td>
  </tr>`).join('');
}

let _receiptPoNumber = '';
let _receiptSubmitBusy = false;

function ensureReceiptContinueField() {
  let hid = document.getElementById('receiptContinueId');
  if (hid) return hid;
  const poEl = document.getElementById('receiptPoId');
  hid = document.createElement('input');
  hid.type = 'hidden';
  hid.id = 'receiptContinueId';
  hid.value = '';
  if (poEl && poEl.parentNode) poEl.parentNode.insertBefore(hid, poEl.nextSibling);
  else document.body.appendChild(hid);
  return hid;
}

function receiptReplacementFlagEl() {
  let el = document.getElementById('receiptReceiveReplacement');
  if (el) return el;
  el = document.createElement('input');
  el.type = 'hidden';
  el.id = 'receiptReceiveReplacement';
  el.value = '0';
  const poEl = document.getElementById('receiptPoId');
  if (poEl && poEl.parentNode) poEl.parentNode.insertBefore(el, poEl.nextSibling);
  else document.body.appendChild(el);
  return el;
}

function setReceiptReplacementMode(on) {
  const flag = receiptReplacementFlagEl();
  flag.value = on ? '1' : '0';
  const title = document.querySelector('#createReceiptModal .modal-title');
  const btn = document.querySelector('#createReceiptModal .modal-footer .btn-success');
  const alertBox = document.querySelector('#createReceiptModal .alert');
  if (!window._receiptModalDefaults) {
    window._receiptModalDefaults = {
      title: title ? title.innerHTML : '',
      btn: btn ? btn.innerHTML : '',
      alert: alertBox ? alertBox.innerHTML : ''
    };
  }
  if (on) {
    if (title) title.innerHTML = '<i class="bi bi-box-arrow-in-down text-success"></i> Receive';
    if (btn) btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Receive & Inspect';
    if (alertBox) {
      alertBox.innerHTML = 'To Received quantity is shown as Ordered. Scan or enter a unique barcode for each accepted unit. Lot Number and Expiry use the existing fields. Quantity not accepted as Good stays To Received. Stock is not added until Stock-In.';
    }
  } else if (window._receiptModalDefaults) {
    if (title && window._receiptModalDefaults.title) title.innerHTML = window._receiptModalDefaults.title;
    if (btn && window._receiptModalDefaults.btn) btn.innerHTML = window._receiptModalDefaults.btn;
    if (alertBox && window._receiptModalDefaults.alert) alertBox.innerHTML = window._receiptModalDefaults.alert;
  }
}

function openReceiveReplacementModal(poId) {
  if (!poId) {
    pToast('Please select a Purchase Order first', 'warn');
    return;
  }
  openCreateReceiptModal(poId, 0, true);
}

function openCreateReceiptModal(poId, rtsId, replacement) {
  clearActiveScanningTarget();
  if (!poId) {
    pToast('Please select a Purchase Order first', 'warn');
    return;
  }
  document.getElementById('receiptPoId').value = poId;
  const rtsEl = document.getElementById('receiptRtsId');
  if (rtsEl) rtsEl.value = rtsId ? String(rtsId) : '';
  setReceiptReplacementMode(!!replacement);
  const contEl = ensureReceiptContinueField();
  if (contEl) contEl.value = '';
  _receiptPoNumber = '';
  const tbody = document.getElementById('receiptItemsBody');
  if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center py-3"><div class="spinner-border spinner-border-sm me-2"></div> Loading PO Items...</td></tr>';
  openModal('createReceiptModal');
  bindReceiptQtyListeners();

  const afterLoad = (r, extraTitle) => {
    if (!r.success || !r.data) { pToast(r.message || 'Could not load PO details', 'danger'); closeModal('createReceiptModal'); return; }
    const po = r.data;
    _receiptPoNumber = String(po.po_number || '');
    const inc = po.incomplete_receipt || null;
    const contId = parseInt(po.incomplete_receipt_id, 10) || (inc ? parseInt(inc.id, 10) : 0) || 0;
    if (contEl) contEl.value = replacement ? '' : (contId ? String(contId) : '');
    const storedReceiptNo = (!replacement && inc) ? String(inc.receipt_number || '').trim() : '';
    let headerLabel = extraTitle || (contId && !replacement ? ('Receive Remaining · ' + (po.po_number || '')) : po.po_number);
    if (storedReceiptNo && String(headerLabel || '').indexOf(storedReceiptNo) === -1) headerLabel = headerLabel + ' · ' + storedReceiptNo;
    document.getElementById('receiptPoNumberHeader').textContent = headerLabel;
    document.getElementById('receiptSupplierHeader').textContent = po.supplier_name || '—';
    document.getElementById('receiptDate').value = new Date().toISOString().split('T')[0];

    const itemsAll = po.items || [];
    if (!itemsAll.length) { tbody.innerHTML = '<tr><td colspan="9" class="text-center py-3 text-muted">No items found in PO</td></tr>'; return; }
    const replacementMode = !!replacement || !!po.receive_replacement || receiptReplacementFlagEl().value === '1';
    const remainingMode = !replacementMode && (!!contId
      || ((parseInt(po.qty_remaining, 10) || 0) > 0 && (parseInt(po.latest_receipt_id, 10) || 0) > 0));
    const items = remainingMode
      ? itemsAll.filter(i => (parseInt(i.quantity_remaining, 10) || 0) > 0)
      : itemsAll;
    if (remainingMode && !items.length) {
      pToast('No remaining quantity to receive. Replacement is not remaining.', 'warn');
      closeModal('createReceiptModal');
      return;
    }

    const driverEl = document.getElementById('receiptDriverName');
    const drEl = document.getElementById('receiptDrNumber');
    const siEl = document.getElementById('receiptSupplierSi');
    const remEl = document.getElementById('receiptRemarks');
    if (driverEl) driverEl.value = replacementMode ? '' : (inc ? String(inc.driver_name || '') : '');
    if (drEl) drEl.value = replacementMode ? '' : (inc ? String(inc.dr_number || '') : '');
    if (siEl) siEl.value = replacementMode ? '' : (inc ? String(inc.supplier_si_number || '') : '');
    if (remEl) remEl.value = extraTitle ? String(extraTitle) : (inc && !replacementMode ? String(inc.remarks || '') : '');
    if (!replacementMode && inc && inc.date_received) {
      const d = String(inc.date_received).slice(0, 10);
      if (d) document.getElementById('receiptDate').value = d;
    }

    tbody.innerHTML = items.map((i, idx) => receiptInspectRowHtml(i, idx, { continueReceiving: remainingMode, poNumber: po.po_number })).join('');
    tbody.querySelectorAll('tr[data-idx]').forEach(row => {
      const item = items[parseInt(row.dataset.idx, 10)] || {};
      receiptPrefillInspectRow(row, item);
      recalcReceiptInspect(row);
      receiptLotModeSync(row);
      if (replacementMode) {
        const savedLot = String(item.replacement_lot_number || '').trim();
        const originalLot = String(item.original_lot_number || '').trim();
        const prefillLot = savedLot || originalLot;
        const lotInput = row.querySelector('.rcpt-lot-number');
        if (lotInput && prefillLot && !String(lotInput.value || '').trim()) {
          lotInput.value = prefillLot;
        }
      }
    });
    bindReceiptBarcodeModeMenus(tbody);
  };

  if (replacement) {
    pApi({ query:{ action:'get_damage_replacement_inspect', po_id: poId } }, r => afterLoad(r, 'Receive Replacement · ' + ((r.data && r.data.po_number) || '')));
    return;
  }
  if (rtsId) {
    pApi({ query:{ action:'get_rts_replacement_inspect', rts_id: rtsId } }, r => afterLoad(r, (r.data && (r.data.rts_number + ' replacement')) || ''));
    return;
  }
  pApi({ query:{ action:'get_po_detail', id: poId } }, r => afterLoad(r));
}

function receiptUniqueBarcodes(list) {
  const seen = new Set();
  const out = [];
  (list || []).forEach(v => {
    const raw = String(v || '').trim();
    const key = clarBarcodeKey(raw);
    if (!key || seen.has(key)) return;
    seen.add(key);
    out.push(raw);
  });
  return out;
}

function receiptCatalogBarcodeValues(row) {
  try {
    const parsed = JSON.parse(row?.getAttribute('data-catalog-barcodes') || '[]');
    return receiptUniqueBarcodes(Array.isArray(parsed) ? parsed : []);
  } catch (err) {
    const one = String(row?.getAttribute('data-catalog-barcode') || '').trim();
    return one ? [one] : [];
  }
}

function receiptInspectedMetaHtml(inspectedCount, ordered, sku, brand) {
  return `<span class="rcpt-product-meta">
    <span class="rcpt-known-bar">
      <span class="rcpt-known-line">Inspected Items: <strong class="rcpt-inspected-count">${inspectedCount}</strong> of <strong class="rcpt-inspected-ordered">${ordered}</strong></span>
      <button type="button" class="btn btn-outline-secondary btn-sm rcpt-view-barcodes">View Barcodes</button>
    </span>
    <span class="rcpt-sku-line">SKU: ${esc(sku || '—')} · ${esc(brand || '')}</span>
  </span>`;
}

function receiptNormalizeInspectedList(list) {
  const seen = new Map();
  const out = [];
  (list || []).forEach(v => {
    let rec = v;
    if (typeof v === 'string' || typeof v === 'number') {
      rec = { barcode: String(v), status: 'Good', input_method: 'Manual', recorded_at: '', quantity: 1 };
    }
    if (!rec || typeof rec !== 'object') return;
    const raw = String(rec.barcode || rec.barcode_value || rec.value || '').trim().replace(/#\d+-\d+$/, '');
    const key = clarBarcodeKey(raw);
    if (!key) return;
    const st = String(rec.status || 'Good');
    const qty = Math.max(1, parseInt(rec.quantity, 10) || 1);
    const normalized = {
      barcode: raw,
      status: st === 'Replacement' ? 'Replacement' : ((st === 'Damaged' || st === 'Rejected' || st === 'Damaged/Rejected' || st === 'Damaged / Rejected') ? 'Damaged/Rejected' : 'Good'),
      input_method: (function () {
        const method = String(rec.input_method || rec.method || 'Manual');
        if (method === 'Camera' || method === 'Barcode Scanner') return 'Camera';
        if (method === 'Split' || method === 'Split Barcode Entry') return 'Split';
        return 'Manual';
      })(),
      lot_number: String(rec.lot_number || ''),
      expiry_date: String(rec.expiry_date || ''),
      recorded_at: String(rec.recorded_at || ''),
      quantity: qty
    };
    if (seen.has(key)) {
      const existing = seen.get(key);
      existing.quantity = (parseInt(existing.quantity, 10) || 1) + qty;
      return;
    }
    seen.set(key, normalized);
    out.push(normalized);
  });
  return out;
}

function receiptInspectedRecords(row) {
  try {
    return receiptNormalizeInspectedList(JSON.parse(row?.getAttribute('data-inspected') || '[]'));
  } catch (err) {
    return [];
  }
}

function receiptSellableInspectedCount(row) {
  return receiptInspectedRecords(row)
    .filter(r => r.status === 'Good' || r.status === 'Replacement')
    .reduce((sum, r) => sum + (parseInt(r.quantity, 10) || 1), 0);
}

function receiptInspectRowHtml(i, idx, opts = {}) {
  const ordered = parseInt(i.quantity_ordered, 10) || 0;
  const remainingQty = Math.max(0, parseInt(i.quantity_remaining, 10) || 0);
  const orderedUnit = String(i.unit_of_measure || '').trim();
  const cost = parseFloat(i.purchase_price || i.unit_price || 0) || 0;
  const continueReceiving = !!(opts.continueReceiving || i.continue_receiving);
  const remainingOnly = continueReceiving && remainingQty > 0;
  const inspectCap = remainingOnly ? remainingQty : ordered;
  const inspected = remainingOnly ? [] : receiptNormalizeInspectedList(i.inspected_barcodes || i.scanned_barcodes || []);
  const goodStart = remainingOnly
    ? 0
    : (continueReceiving
      ? Math.max(0, parseInt(i.quantity_good, 10) || inspected.filter(r => r.status === 'Good' || r.status === 'Replacement').length || 0)
      : 0);
  const expirable = Number(i.expiry_tracking) === 1;
  const poNumber = opts.poNumber || _receiptPoNumber || '';
  const discStart = Math.max(0, inspectCap - goodStart);
  const receivedStart = Math.min(inspectCap, Math.max(0, goodStart));
  const remainStart = Math.max(0, inspectCap - receivedStart);
  return `<tr class="rcpt-item-row rcpt-lot-single" data-idx="${idx}" data-product-id="${esc(i.product_id||'')}" data-catalog-barcode="${esc(i.barcode || '')}" data-scanned-barcodes="${esc(JSON.stringify(inspected.map(r => r.barcode)))}" data-inspected="${esc(JSON.stringify(inspected))}" data-scan-count="${goodStart}" data-lot-count="1" data-expirable="${expirable ? '1' : '0'}" data-continue="${continueReceiving ? '1' : '0'}" data-remaining-only="${remainingOnly ? '1' : '0'}" data-po-number="${esc(poNumber)}" data-receipt-item-id="${esc(i.receipt_item_id || '')}">
    <td class="rcpt-product-cell">
      <input type="hidden" name="items[${idx}][po_item_id]" value="${i.id}">
      <input type="hidden" name="items[${idx}][product_id]" value="${i.product_id||''}">
      <input type="hidden" name="items[${idx}][product_name]" value="${esc(i.product_name)}">
      <input type="hidden" name="items[${idx}][quantity_ordered]" value="${inspectCap}">
      <input type="hidden" name="items[${idx}][quantity_received]" value="${receivedStart}">
      <input type="hidden" name="items[${idx}][unit_price]" value="${cost}">
      <strong>${esc(i.product_name)}</strong><br>
      ${receiptInspectedMetaHtml(inspected.length, inspectCap, i.sku, i.brand)}
    </td>
    <td class="rcpt-ordered">${inspectCap}${orderedUnit ? ' ' + esc(orderedUnit) : ''}</td>
    <td class="rcpt-received">${receivedStart}</td>
    <td>
      <input type="number" min="0" step="1" value="${goodStart}" name="items[${idx}][quantity_good]" class="form-control form-control-sm rcpt-good" style="width:80px" readonly>
      <div class="rcpt-remain">Remaining to Inspect: ${remainStart}</div>
    </td>
    <td class="rcpt-disc-cell">
      <input type="number" min="0" step="1" value="${discStart}" name="items[${idx}][quantity_discrepancy]" class="form-control form-control-sm rcpt-disc" title="Quantity still To Received for this delivery">
      <input type="hidden" name="items[${idx}][quantity_damaged]" class="rcpt-dmg" value="0">
      <input type="hidden" name="items[${idx}][quantity_missing]" class="rcpt-miss" value="${discStart}">
    </td>
    <td><input type="number" step="0.01" min="0" value="${cost.toFixed(2)}" name="items[${idx}][cost_price]" class="form-control form-control-sm rcpt-cost" style="width:100px" readonly></td>
    <td class="rcpt-barcode-cell">
      <div class="rcpt-barcode-wrap" data-mode="manual">
        <div class="input-group input-group-sm rcpt-barcode-group">
          <input type="text" inputmode="text" autocomplete="off" spellcheck="false" placeholder="Scan or type barcode" name="items[${idx}][barcode]" class="form-control form-control-sm rcpt-barcode" value="">
          <button class="btn btn-outline-secondary dropdown-toggle rcpt-barcode-mode-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Manual">
            <i class="bi bi-keyboard"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item rcpt-barcode-mode active" type="button" data-mode="manual"><i class="bi bi-keyboard me-1"></i> Manual</button></li>
            <li><button class="dropdown-item rcpt-barcode-mode" type="button" data-mode="scanner"><i class="bi bi-upc-scan me-1"></i> Barcode Scanner</button></li>
            <li><button class="dropdown-item rcpt-barcode-mode" type="button" data-mode="split"><i class="bi bi-layout-split me-1"></i> Split Barcode Entry</button></li>
          </ul>
        </div>
        <div class="rcpt-barcode-split d-none">
          <div class="small text-muted">First Barcode</div>
          <input type="text" inputmode="text" autocomplete="off" spellcheck="false" class="form-control form-control-sm rcpt-barcode-first" placeholder="First Barcode" aria-label="First Barcode">
          <div class="small text-muted">Last Digits</div>
          <input type="text" inputmode="text" autocomplete="off" spellcheck="false" class="form-control form-control-sm rcpt-barcode-last" placeholder="Last Digits" aria-label="Last Digits">
        </div>
        <div class="rcpt-barcode-msg" aria-live="polite"></div>
        <div class="rcpt-scan-count small text-muted"></div>
        <div class="rcpt-scanned-list small"></div>
      </div>
    </td>
    <td class="rcpt-lots-cell" colspan="2">
      <div class="rcpt-lots-wrap">${receiptLotEntryHtml(idx, 0, expirable, false)}</div>
      <button type="button" class="btn btn-sm btn-outline-primary rcpt-add-lot">+ Add Another Lot</button>
    </td>
  </tr>`;
}

function receiptPrefillInspectRow(row, item) {
  if (!row || !item) return;
  if (row.getAttribute('data-remaining-only') === '1') return;
  const inspected = receiptNormalizeInspectedList(item.inspected_barcodes || item.scanned_barcodes || []);
  row.setAttribute('data-inspected', JSON.stringify(inspected));
  row.setAttribute('data-scanned-barcodes', JSON.stringify(inspected.map(r => r.barcode)));
  receiptRenderScannedList(row);
  const lots = Array.isArray(item.lots) ? item.lots.filter(l => (parseInt(l.quantity, 10) || 0) > 0 || String(l.lot_number || '').trim()) : [];
  const wrap = row.querySelector('.rcpt-lots-wrap');
  if (wrap && lots.length) {
    const idx = row.dataset.idx;
    const expirable = row.dataset.expirable === '1';
    wrap.innerHTML = lots.map((lot, i) => receiptLotEntryHtml(idx, i, expirable, i > 0)).join('');
    row.setAttribute('data-lot-count', String(lots.length));
    lots.forEach((lot, i) => {
      const el = wrap.querySelectorAll('.rcpt-lot-entry')[i];
      if (!el) return;
      const num = el.querySelector('.rcpt-lot-number');
      const qty = el.querySelector('.rcpt-lot-qty');
      const exp = el.querySelector('.rcpt-expiry');
      const na = el.querySelector('.rcpt-lot-na');
      const ne = el.querySelector('.rcpt-lot-noexp');
      if (num) num.value = lot.lot_na ? 'N/A' : String(lot.lot_number || '');
      if (qty) qty.value = String(parseInt(lot.quantity, 10) || '');
      if (na) na.checked = !!lot.lot_na || String(lot.lot_number || '').toUpperCase() === 'N/A';
      if (ne) {
        ne.checked = !!lot.no_expiry || !lot.expiry_date;
        if (!expirable) { ne.checked = true; ne.disabled = true; }
      }
      if (exp && exp.type === 'date') exp.value = lot.no_expiry ? '' : String(lot.expiry_date || '').slice(0, 10);
      syncReceiptLotNa(el);
      syncReceiptLotNoExp(el);
    });
  }
  receiptSyncInspectedCount(row);
}

function receiptLotEntryHtml(idx, lotIdx, expirable, canRemove) {
  const naId = `rcptLotNa_${idx}_${lotIdx}`;
  const neId = `rcptLotNe_${idx}_${lotIdx}`;
  return `<div class="rcpt-lot-entry" data-lot-idx="${lotIdx}">
    <div class="rcpt-lot-fields">
      <div class="rcpt-lot-col">
        <input type="text" class="form-control form-control-sm rcpt-lot-number" maxlength="80" placeholder="Lot number" autocomplete="off">
        <label class="rcpt-lot-check" for="${naId}"><input type="checkbox" id="${naId}" class="rcpt-lot-na" value="1"> Not Applicable</label>
        <input type="number" min="0" step="1" class="form-control form-control-sm rcpt-lot-qty" placeholder="Qty" title="Lot quantity">
      </div>
      <div class="rcpt-lot-col">
        ${expirable
          ? `<input type="date" class="form-control form-control-sm rcpt-expiry">`
          : `<span class="text-muted small">N/A</span><input type="hidden" class="rcpt-expiry" value="">`}
        <label class="rcpt-lot-check" for="${neId}"><input type="checkbox" id="${neId}" class="rcpt-lot-noexp" value="1" ${expirable ? '' : 'checked disabled'}> No Expiry</label>
        ${canRemove ? `<button type="button" class="btn btn-sm btn-link text-danger p-0 rcpt-remove-lot" title="Remove lot">&times;</button>` : ''}
      </div>
    </div>
  </div>`;
}

function receiptCollectLots(row) {
  return [...(row?.querySelectorAll('.rcpt-lot-entry') || [])].map(el => {
    const na = !!el.querySelector('.rcpt-lot-na')?.checked;
    const noExp = !!el.querySelector('.rcpt-lot-noexp')?.checked;
    let lot = String(el.querySelector('.rcpt-lot-number')?.value || '').trim();
    if (na) lot = 'N/A';
    return {
      lot_number: lot,
      lot_na: na ? 1 : 0,
      quantity: receiptQtyValue(el.querySelector('.rcpt-lot-qty')),
      expiry_date: noExp ? '' : String(el.querySelector('.rcpt-expiry')?.value || ''),
      no_expiry: noExp ? 1 : 0
    };
  });
}

function addReceiptLotRow(row) {
  if (!row) return;
  const wrap = row.querySelector('.rcpt-lots-wrap');
  if (!wrap) return;
  const idx = row.dataset.idx;
  const next = (parseInt(row.getAttribute('data-lot-count') || '1', 10) || 1);
  row.setAttribute('data-lot-count', String(next + 1));
  wrap.insertAdjacentHTML('beforeend', receiptLotEntryHtml(idx, next, row.dataset.expirable === '1', true));
  receiptLotModeSync(row);
}

function receiptLotModeSync(row) {
  if (!row) return;
  const entries = row.querySelectorAll('.rcpt-lot-entry');
  const single = entries.length <= 1;
  row.classList.toggle('rcpt-lot-single', single);
  const good = receiptSellableInspectedCount(row);
  entries.forEach(el => {
    const qty = el.querySelector('.rcpt-lot-qty');
    if (!qty) return;
    qty.readOnly = single;
    if (single) qty.value = good ? String(good) : '';
  });
}

function syncReceiptLotQtyCap(row) {
  if (!row) return;
  const good = recalcReceiptInspect(row).good;
  let used = 0;
  row.querySelectorAll('.rcpt-lot-entry').forEach(el => {
    const input = el.querySelector('.rcpt-lot-qty');
    let q = receiptQtyValue(input);
    if (used + q > good) {
      q = Math.max(0, good - used);
      if (input) input.value = q ? String(q) : '';
    }
    used += q;
  });
}

function removeReceiptLotRow(entry) {
  const row = entry?.closest('tr');
  if (!entry || !row) return;
  if (row.querySelectorAll('.rcpt-lot-entry').length <= 1) return;
  entry.remove();
  receiptLotModeSync(row);
}

function syncReceiptLotNa(entry) {
  const na = !!entry?.querySelector('.rcpt-lot-na')?.checked;
  const input = entry?.querySelector('.rcpt-lot-number');
  if (!input) return;
  input.disabled = na;
  if (na) input.value = 'N/A';
  else if (clarBarcodeKey(input.value) === clarBarcodeKey('N/A') || String(input.value).toLowerCase() === 'n/a') input.value = '';
}

function syncReceiptLotNoExp(entry) {
  const noExp = !!entry?.querySelector('.rcpt-lot-noexp')?.checked;
  const input = entry?.querySelector('input.rcpt-expiry[type="date"]');
  if (!input) return;
  input.disabled = noExp;
  if (noExp) input.value = '';
}

function receiptDisplayLotNumber(raw) {
  const s = String(raw || '').trim();
  if (!s) return '—';
  if (s.toUpperCase() === 'N/A') return 'N/A';
  return s;
}

function receiptAttachLotsForBarcodeView(records, lots) {
  const out = (records || []).map(r => Object.assign({}, r));
  const pool = [];
  (lots || []).forEach(lot => {
    const qty = parseInt(lot.quantity, 10) || 0;
    const num = String(lot.lot_number || '').trim();
    if (qty <= 0 && !num) return;
    pool.push({
      lot_number: num,
      _left: qty > 0 ? qty : 0
    });
  });
  out.forEach((row, i) => {
    if (String(row.lot_number || '').trim() !== '') return;
    if (row.status !== 'Good' && row.status !== 'Replacement') return;
    for (let j = 0; j < pool.length; j++) {
      if (pool[j]._left <= 0) continue;
      out[i].lot_number = pool[j].lot_number;
      pool[j]._left--;
      return;
    }
  });
  const named = pool.filter(l => String(l.lot_number || '').trim());
  if (named.length === 1) {
    out.forEach((row, i) => {
      if (String(row.lot_number || '').trim() !== '') return;
      if (row.status !== 'Good' && row.status !== 'Replacement') return;
      out[i].lot_number = named[0].lot_number;
    });
  }
  return out;
}

function openReceiptKnownBarcodesView(row) {
  const records = receiptAttachLotsForBarcodeView(
    receiptNormalizeInspectedList(receiptInspectedRecords(row)),
    receiptCollectLots(row)
  );
  let modal = document.getElementById('receiptKnownBarcodesModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'receiptKnownBarcodesModal';
    modal.className = 'modal-overlay';
    modal.style.zIndex = '5100';
    modal.innerHTML = `<div class="modal" style="max-width:min(720px, 96vw)">
      <div class="modal-header">
        <span class="modal-title">Inspected Barcodes</span>
        <button class="modal-close" type="button" data-kb-close><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body">
        <div class="fw-semibold mb-1" id="receiptKnownBarcodesProduct"></div>
        <div class="small text-muted mb-1" id="receiptKnownBarcodesPo"></div>
        <div class="small text-muted mb-2" id="receiptKnownBarcodesTitle"></div>
        <input type="search" id="receiptKnownBarcodesSearch" class="form-control form-control-sm mb-2" placeholder="Search barcodes" autocomplete="off">
        <div id="receiptKnownBarcodesList" class="rcpt-known-modal-list"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-kb-close>Close</button>
      </div>
    </div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', (e) => {
      if (e.target === modal || e.target.closest('[data-kb-close]')) {
        modal.classList.remove('open', 'show');
        modal.style.display = 'none';
      }
    });
    modal.addEventListener('input', (e) => {
      if (e.target && e.target.id === 'receiptKnownBarcodesSearch') {
        renderReceiptKnownBarcodesList();
      }
    });
  }
  modal._records = records;
  const nameEl = document.getElementById('receiptKnownBarcodesProduct');
  const poEl = document.getElementById('receiptKnownBarcodesPo');
  const title = document.getElementById('receiptKnownBarcodesTitle');
  const search = document.getElementById('receiptKnownBarcodesSearch');
  if (nameEl) nameEl.textContent = receiptRowProductName(row);
  if (poEl) poEl.textContent = 'PO ' + (row.getAttribute('data-po-number') || _receiptPoNumber || '—');
  if (title) title.textContent = records.length + ' inspected barcode' + (records.length === 1 ? '' : 's');
  if (search) search.value = '';
  renderReceiptKnownBarcodesList();
  modal.classList.add('open', 'show');
  modal.style.display = 'flex';
}

function receiptFormatInspectedAt(raw) {
  const s = String(raw || '').replace('T', ' ').slice(0, 19);
  return s || '—';
}

function renderReceiptKnownBarcodesList() {
  const modal = document.getElementById('receiptKnownBarcodesModal');
  const list = document.getElementById('receiptKnownBarcodesList');
  if (!modal || !list) return;
  const q = clarBarcodeKey(document.getElementById('receiptKnownBarcodesSearch')?.value || '');
  const records = receiptNormalizeInspectedList(modal._records || []);
  const filtered = q ? records.filter(r => clarBarcodeKey(r.barcode).includes(q) || clarBarcodeKey(r.lot_number).includes(q) || clarBarcodeKey(r.status).includes(q) || clarBarcodeKey(r.input_method).includes(q)) : records;
  list.innerHTML = filtered.length
    ? `<div class="rcpt-known-modal-head"><span>Barcode</span><span>Qty</span><span>Lot Number</span><span>Status</span><span>Input</span><span>Recorded</span></div>`
      + filtered.map(r => `<div class="rcpt-known-modal-item rcpt-known-modal-row">
        <code>${esc(r.barcode)}</code>
        <span>${esc(String(parseInt(r.quantity, 10) || 1))}</span>
        <span>${esc(receiptDisplayLotNumber(r.lot_number))}</span>
        <span>${esc(r.status)}</span>
        <span>${esc(r.input_method)}</span>
        <span>${esc(receiptFormatInspectedAt(r.recorded_at))}</span>
      </div>`).join('')
    : '<div class="text-muted small py-2">No matching barcodes for this delivery.</div>';
}

function receiptQtyValue(input) {
  if (!input) return 0;
  const raw = String(input.value ?? '').trim();
  if (raw === '') return 0;
  const n = parseInt(raw, 10);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

function sanitizeReceiptWholeQty(input) {
  if (!input) return 0;
  const raw = String(input.value ?? '').trim();
  if (raw === '') return 0;
  if (!/^\d+$/.test(raw)) {
    const n = parseInt(raw, 10);
    if (!Number.isFinite(n) || n < 0) {
      input.value = '';
      return 0;
    }
    input.value = String(n);
    return n;
  }
  return parseInt(raw, 10);
}

function recalcReceiptInspect(row) {
  if (!row) return { ordered: 0, received: 0, damaged: 0, missing: 0, disc: 0, good: 0, remaining: 0, invalid: false };
  const idx = row.dataset.idx;
  const ordered = parseInt(row.querySelector(`[name="items[${idx}][quantity_ordered]"]`)?.value || '0', 10) || 0;
  const goodInput = row.querySelector('.rcpt-good');
  let good = receiptQtyValue(goodInput);
  if (good > ordered) {
    good = ordered;
    if (goodInput) goodInput.value = String(good);
  }
  const discInput = row.querySelector('.rcpt-disc');
  const disc = Math.max(0, ordered - good);
  let received = good;
  if (received > ordered) received = ordered;
  if (received < 0) received = 0;
  const remaining = Math.max(0, ordered - received);
  const receivedHidden = row.querySelector(`[name="items[${idx}][quantity_received]"]`);
  if (receivedHidden) receivedHidden.value = String(received);
  const receivedEl = row.querySelector('.rcpt-received');
  if (receivedEl) receivedEl.textContent = String(received);
  if (discInput) discInput.value = String(disc);
  const dmgHidden = row.querySelector('.rcpt-dmg');
  if (dmgHidden) dmgHidden.value = String(disc);
  const missHidden = row.querySelector('.rcpt-miss');
  if (missHidden) missHidden.value = '0';
  const remainEl = row.querySelector('.rcpt-remain');
  if (remainEl) {
    remainEl.textContent = remaining === 0 ? 'Inspection complete' : ('Remaining to Inspect: ' + remaining);
    remainEl.classList.toggle('is-done', remaining === 0);
  }
  receiptSyncInspectedCount(row);
  receiptLotModeSync(row);
  const countEl = row.querySelector('.rcpt-scan-count');
  if (countEl) countEl.textContent = good > 0 ? ('Quantity: ' + good) : '';
  row.setAttribute('data-scan-count', String(good));
  return {
    ordered,
    received,
    damaged: disc,
    missing: 0,
    disc,
    good,
    remaining,
    invalid: good > ordered || good < 0 || disc < 0 || received > ordered
  };
}

function recalcReceiptGood(row) {
  return recalcReceiptInspect(row);
}

function receiptSyncInspectedCount(row) {
  if (!row) return;
  const n = receiptInspectedRecords(row).length;
  const countEl = row.querySelector('.rcpt-inspected-count');
  if (countEl) countEl.textContent = String(n);
  const orderedEl = row.querySelector('.rcpt-inspected-ordered');
  const idx = row.dataset.idx;
  const ordered = parseInt(row.querySelector(`[name="items[${idx}][quantity_ordered]"]`)?.value || '0', 10) || 0;
  if (orderedEl) orderedEl.textContent = String(ordered);
}

function receiptSyncGoodFromInspected(row) {
  if (!row) return recalcReceiptInspect(row);
  const goodInput = row.querySelector('.rcpt-good');
  const n = receiptSellableInspectedCount(row);
  if (goodInput) goodInput.value = String(n);
  receiptRenderScannedList(row);
  return recalcReceiptInspect(row);
}

function receiptCurrentLotSnapshot(row) {
  const entries = [...(row?.querySelectorAll('.rcpt-lot-entry') || [])];
  const last = entries[entries.length - 1];
  if (!last) return { lot_number: '', expiry_date: '', no_expiry: 1 };
  const na = !!last.querySelector('.rcpt-lot-na')?.checked;
  const noExp = !!last.querySelector('.rcpt-lot-noexp')?.checked;
  let lot = String(last.querySelector('.rcpt-lot-number')?.value || '').trim();
  if (na) lot = 'N/A';
  return {
    lot_number: lot,
    expiry_date: noExp ? '' : String(last.querySelector('.rcpt-expiry')?.value || ''),
    no_expiry: noExp ? 1 : 0
  };
}

function receiptBarcodeAlreadyOnRow(row, key) {
  return receiptInspectedRecords(row).some(r => clarBarcodeKey(r.barcode) === key)
    || receiptScannedBarcodes(row).some(b => clarBarcodeKey(b) === key);
}

function receiptBarcodeOnOtherRow(row, key) {
  return Array.from(document.querySelectorAll('#receiptItemsBody tr[data-idx]'))
    .some(other => other !== row && receiptBarcodeAlreadyOnRow(other, key));
}

function receiptBarcodeAlreadyInTransaction(key) {
  if (!key) return false;
  return Array.from(document.querySelectorAll('#receiptItemsBody tr[data-idx]'))
    .some(row => receiptBarcodeAlreadyOnRow(row, key));
}

function rejectDuplicateReceiptBarcode(input, toast, scannedKey) {
  if (input) {
    setReceiptBarcodeState(input, false, 'Barcode Already Scanned');
    const current = clarBarcodeKey(input.value);
    if (!scannedKey || !current || current === scannedKey) input.value = '';
  }
  if (toast) pToast('Barcode Already Scanned. This barcode has already been recorded.', 'warn');
}

function receiptScanIsBurst(input, key) {
  const now = Date.now();
  if (input && input._scanLockKey === key && (now - (input._scanLockAt || 0)) < 150) {
    return true;
  }
  if (input) {
    input._scanLockKey = key;
    input._scanLockAt = now;
  }
  return false;
}

function receiptAddInspectedRecord(row, code, method) {
  const value = String(code || '').trim().replace(/#\d+-\d+$/, '');
  const key = clarBarcodeKey(value);
  if (!row || !key) return false;
  const idx = row.dataset.idx;
  const ordered = parseInt(row.querySelector(`[name="items[${idx}][quantity_ordered]"]`)?.value || '0', 10) || 0;
  if (receiptSellableInspectedCount(row) >= ordered) return false;
  const records = receiptInspectedRecords(row);
  const lot = receiptCurrentLotSnapshot(row);
  const existing = records.find(r => clarBarcodeKey(r.barcode) === key);
  if (existing) return false;
  const status = row.getAttribute('data-continue') === '1' ? 'Replacement' : 'Good';
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  const recorded = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
    + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
  records.push({
    barcode: value,
    status,
    input_method: method === 'Camera' ? 'Camera' : (method === 'Split' ? 'Split' : 'Manual'),
    lot_number: lot.lot_number || '',
    expiry_date: lot.expiry_date || '',
    recorded_at: recorded,
    quantity: 1
  });
  row.setAttribute('data-inspected', JSON.stringify(records));
  receiptAddScannedBarcode(row, value);
  receiptSyncGoodFromInspected(row);
  return true;
}

function receiptInputMethod(input, scanned) {
  if (receiptBarcodeMode(input) === 'split') return 'Split';
  if (scanned || receiptBarcodeMode(input) === 'scanner') return 'Camera';
  return 'Manual';
}

function receiptNoteInspected(row, input, code, { toast = false, advance = false, countUnit = false } = {}) {
  if (code) receiptAddScannedBarcode(row, code);
  if (countUnit) {
    if (!receiptRecordInspectedUnit(row)) {
      setReceiptBarcodeState(input, false, 'Goods cannot exceed received quantity.');
      if (toast) pToast('Goods cannot exceed received quantity for ' + receiptRowProductName(row) + '.', 'warn');
      return false;
    }
    const left = recalcReceiptInspect(row).remaining;
    setReceiptBarcodeState(input, true, left === 0 ? 'Fully inspected.' : ('Good +1 · remaining ' + left));
  }
  if (advance) holdReceiptBarcodeFocus(row?.querySelector?.('.rcpt-barcode'));
  return true;
}

function onReceiptQtyEvent(e) {
  const t = e.target;
  if (!t || !t.classList || (!t.classList.contains('rcpt-dmg') && !t.classList.contains('rcpt-miss') && !t.classList.contains('rcpt-disc'))) return;
  sanitizeReceiptWholeQty(t);
  recalcReceiptInspect(t.closest('tr'));
}

function receiptRowProductName(row) {
  const idx = row?.dataset?.idx;
  return row?.querySelector(`[name="items[${idx}][product_name]"]`)?.value || 'this product';
}

function receiptExpectedBarcode(row) {
  return clarBarcodeKey(row?.getAttribute('data-catalog-barcode') || '');
}

function receiptKnownBarcodes(row) {
  try {
    const parsed = JSON.parse(row?.getAttribute('data-catalog-barcodes') || '[]');
    return Array.isArray(parsed) ? parsed.map(v => clarBarcodeKey(v)).filter(Boolean) : [];
  } catch (err) {
    const one = receiptExpectedBarcode(row);
    return one ? [one] : [];
  }
}

function receiptScannedBarcodes(row) {
  try {
    const parsed = JSON.parse(row?.getAttribute('data-scanned-barcodes') || '[]');
    return Array.isArray(parsed) ? parsed : [];
  } catch (err) {
    return [];
  }
}

function receiptAddScannedBarcode(row, code) {
  const value = String(code || '').trim();
  if (!row || !value) return receiptScannedBarcodes(row);
  const list = receiptScannedBarcodes(row);
  const key = clarBarcodeKey(value);
  if (!list.some(b => clarBarcodeKey(b) === key)) list.push(value);
  row.setAttribute('data-scanned-barcodes', JSON.stringify(list));
  receiptRenderScannedList(row);
  return list;
}

function receiptRenderScannedList(row) {
  const listEl = row?.querySelector('.rcpt-scanned-list');
  if (!listEl) return;
  const unique = receiptUniqueBarcodes(receiptScannedBarcodes(row));
  listEl.textContent = unique.length ? (unique.length + ' unique barcode' + (unique.length === 1 ? '' : 's')) : '';
}

function receiptBarcodeCellHtml(item) {
  const vals = receiptUniqueBarcodes(
    Array.isArray(item?.barcode_values) && item.barcode_values.length
      ? item.barcode_values
      : (item?.barcode ? [item.barcode] : [])
  );
  if (!vals.length) return '—';
  if (vals.length === 1) return `<code>${esc(vals[0])}</code>`;
  return `<code>${esc(vals[vals.length - 1])}</code><div class="small text-muted">${vals.length} unique</div>`;
}

function receiptLotsSummaryHtml(item) {
  const lots = Array.isArray(item?.lots) && item.lots.length
    ? item.lots
    : [{
        lot_number: item?.manufacturer_lot || '',
        quantity: item?.quantity_good,
        expiry_date: item?.expiry_date,
        no_expiry: item?.expiry_date ? 0 : 1
      }];
  const lines = lots.filter(l => (parseInt(l.quantity, 10) || 0) > 0 || String(l.lot_number || '').trim()).map(l => {
    const lot = esc(l.lot_number || 'N/A');
    const qty = parseInt(l.quantity, 10) || 0;
    const exp = l.no_expiry || !l.expiry_date ? 'No Expiry' : fmtDate(l.expiry_date);
    return `<div class="small">Lot ${lot}${qty ? ' × ' + qty : ''} · ${exp}</div>`;
  });
  return lines.length ? lines.join('') : '—';
}

function receiptRememberKnownBarcode(row, code, allValues) {
  if (!row) return;
  const value = String(code || '').trim();
  let known = [];
  try {
    known = JSON.parse(row.getAttribute('data-catalog-barcodes') || '[]');
  } catch (err) {
    known = [];
  }
  if (!Array.isArray(known)) known = [];
  if (Array.isArray(allValues) && allValues.length) {
    known = allValues.slice();
  } else if (value && !known.some(b => clarBarcodeKey(b) === clarBarcodeKey(value))) {
    known.push(value);
  }
  known = receiptUniqueBarcodes(known);
  row.setAttribute('data-catalog-barcodes', JSON.stringify(known));
  if (known.length && !row.getAttribute('data-catalog-barcode')) {
    row.setAttribute('data-catalog-barcode', known[0]);
  }
  const unique = known;
  const countEl = row.querySelector('.rcpt-known-count');
  if (countEl) countEl.textContent = String(unique.length);
  const previewEl = row.querySelector('.rcpt-known-preview');
  if (previewEl) previewEl.remove();
}

function registerReceiptBarcodeNow(row, input, code, { toast = false, advance = false, countUnit = false } = {}) {
  const productId = receiptRowProductId(row);
  const poId = document.getElementById('receiptPoId')?.value || '';
  pApi({
    method: 'POST',
    body: {
      action: 'register_receive_barcode',
      barcode: code,
      product_id: String(productId),
      po_id: String(poId)
    }
  }, r => {
    if (clarBarcodeKey(input.value) !== clarBarcodeKey(code)) return;
    if (!r || !r.success) {
      if (r && r.status === 'conflict') {
        setReceiptBarcodeState(input, false, 'Linked to ' + (r.conflict?.product_name || 'another product') + '.');
        if (toast) pToast(r.message || 'Barcode belongs to a different product.', 'danger');
        openBarcodeConflictModal(row, input, r);
        return;
      }
      setReceiptBarcodeState(input, false, r?.message || 'Could not save barcode.');
      if (toast) pToast(r?.message || 'Could not save barcode to this product.', 'danger');
      return;
    }
    receiptAddScannedBarcode(row, code);
    receiptRememberKnownBarcode(row, code, r.barcode_values);
    if (countUnit) {
      receiptNoteInspected(row, input, '', { toast: false, advance, countUnit: true });
    } else {
      setReceiptBarcodeState(input, true, 'Saved in system for this product.');
      if (advance) holdReceiptBarcodeFocus(row?.querySelector?.('.rcpt-barcode'));
    }
    if (toast) pToast('Barcode ' + code + ' is now registered to ' + receiptRowProductName(row) + '.', 'success');
    if (typeof loadCatalog === 'function') loadCatalog(true);
  });
}

function receiptRowProductId(row) {
  return parseInt(row?.getAttribute('data-product-id') || row?.querySelector('[name$="[product_id]"]')?.value || '0', 10) || 0;
}

function findReceiptRowByProductId(productId) {
  const pid = parseInt(productId, 10) || 0;
  if (!pid) return null;
  return Array.from(document.querySelectorAll('#receiptItemsBody tr[data-idx]'))
    .find(row => receiptRowProductId(row) === pid) || null;
}

function findReceiptRowByKnownBarcode(code) {
  const key = clarBarcodeKey(code);
  if (!key) return null;
  return Array.from(document.querySelectorAll('#receiptItemsBody tr[data-idx]'))
    .find(row => receiptKnownBarcodes(row).includes(key) || receiptScannedBarcodes(row).some(b => clarBarcodeKey(b) === key)) || null;
}

function receiptBarcodeMode(el) {
  const mode = el?.closest?.('.rcpt-barcode-wrap')?.getAttribute('data-mode') || 'manual';
  if (mode === 'scanner' || mode === 'split') return mode;
  return 'manual';
}

let _receiptQr = null;
let _receiptQrActive = false;
let _receiptScanInput = null;
let _activeScanningInput = null;
let _receiptPointerInput = null;
let _receiptPointerAt = 0;
let _receiptLastNavKey = '';
let _receiptScanFinishAt = 0;
let _receiptCamLastKey = '';
let _receiptCamLastHitAt = 0;
let _receiptCamAcceptedKey = '';
let _receiptCamAcceptedAt = 0;
let _receiptCamInView = false;

function bindReceiptBarcodeModeMenus(tbody) {
  if (!tbody || typeof bootstrap === 'undefined' || !bootstrap.Dropdown) return;
  tbody.querySelectorAll('.rcpt-barcode-mode-btn').forEach(btn => {
    const existing = bootstrap.Dropdown.getInstance(btn);
    if (existing) existing.dispose();
    new bootstrap.Dropdown(btn, { popperConfig: { strategy: 'fixed' } });
  });
}

function setReceiptBarcodeMode(wrap, mode, { startCam = true } = {}) {
  if (!wrap) return;
  if (mode !== 'scanner' && mode !== 'split') mode = 'manual';
  wrap.setAttribute('data-mode', mode);
  const input = wrap.querySelector('.rcpt-barcode');
  const split = wrap.querySelector('.rcpt-barcode-split');
  const btn = wrap.querySelector('.rcpt-barcode-mode-btn');
  const icon = btn?.querySelector('i');
  if (icon) icon.className = mode === 'scanner' ? 'bi bi-upc-scan' : (mode === 'split' ? 'bi bi-layout-split' : 'bi bi-keyboard');
  if (btn) btn.title = mode === 'scanner' ? 'Barcode Scanner' : (mode === 'split' ? 'Split Barcode Entry' : 'Manual');
  wrap.querySelectorAll('.rcpt-barcode-mode').forEach(item => {
    item.classList.toggle('active', item.getAttribute('data-mode') === mode);
  });
  if (input) input.classList.toggle('d-none', mode === 'split');
  if (split) split.classList.toggle('d-none', mode !== 'split');
  if (!input) return;
  if (mode !== 'split') input.placeholder = mode === 'scanner' ? 'Scan barcode…' : 'Type barcode';
  if (mode === 'scanner') {
    setActiveScanningTarget(input, true);
    try { input.focus({ preventScroll: true }); input.select(); } catch (err) {}
    if (startCam) startReceiptBarcodeScanner(input);
  } else if (_receiptScanInput === input) {
    stopReceiptBarcodeScanner();
  }
  if (mode === 'split') {
    clearActiveScanningTarget();
    const first = wrap.querySelector('.rcpt-barcode-first');
    try { first?.focus({ preventScroll: true }); } catch (err) {}
  }
}

function startReceiptBarcodeScanner(input) {
  const panel = document.getElementById('receiptBarcodeScannerPanel');
  const hint = document.getElementById('receiptBarcodeScannerHint');
  if (panel) panel.classList.remove('d-none');
  _receiptScanInput = input || _receiptScanInput;
  _receiptCamLastKey = '';
  _receiptCamLastHitAt = 0;
  _receiptCamAcceptedKey = '';
  _receiptCamAcceptedAt = 0;
  _receiptCamInView = false;
  if (_receiptQrActive) {
    if (hint) hint.textContent = 'Point the camera at the barcode, or use a handheld scanner in the Barcode ID field.';
    return;
  }
  if (typeof Html5Qrcode === 'undefined') {
    if (hint) hint.textContent = 'Camera not loaded. Use a handheld scanner in the Barcode ID field.';
    return;
  }
  try {
    if (!_receiptQr) _receiptQr = new Html5Qrcode('receiptBarcodeScannerViewport');
  } catch (err) {
    if (hint) hint.textContent = 'Camera not available. Use a handheld scanner in the Barcode ID field.';
    return;
  }
  const cfg = { fps: 12, qrbox: { width: 220, height: 140 } };
  if (typeof Html5QrcodeSupportedFormats !== 'undefined') {
    cfg.formatsToSupport = [
      Html5QrcodeSupportedFormats.CODE_128,
      Html5QrcodeSupportedFormats.CODE_39,
      Html5QrcodeSupportedFormats.EAN_13,
      Html5QrcodeSupportedFormats.EAN_8,
      Html5QrcodeSupportedFormats.UPC_A,
      Html5QrcodeSupportedFormats.UPC_E,
      Html5QrcodeSupportedFormats.QR_CODE
    ];
  }
  _receiptQr.start(
    { facingMode: 'environment' },
    cfg,
    (decodedText) => applyReceiptScannedBarcode(decodedText),
    () => {
      if (Date.now() - _receiptCamLastHitAt > 400) {
        _receiptCamLastKey = '';
        _receiptCamInView = false;
      }
    }
  ).then(() => {
    _receiptQrActive = true;
    if (hint) hint.textContent = 'Point the camera at the barcode, or use a handheld scanner in the Barcode ID field.';
  }).catch(() => {
    _receiptQrActive = false;
    if (hint) hint.textContent = 'Camera not available. Use a handheld scanner in the Barcode ID field.';
  });
}

function receiptCameraAllowsCode(key) {
  if (!key) return false;
  const now = Date.now();
  const stillInView = key === _receiptCamLastKey && (now - _receiptCamLastHitAt) < 800;
  _receiptCamLastKey = key;
  _receiptCamLastHitAt = now;
  if (key === _receiptCamAcceptedKey && (_receiptCamInView || stillInView)) return false;
  _receiptCamAcceptedKey = key;
  _receiptCamAcceptedAt = now;
  _receiptCamInView = true;
  return true;
}

function applyReceiptScannedBarcode(text) {
  const code = String(text || '').trim();
  const key = clarBarcodeKey(code);
  if (!key) return;
  if (!receiptCameraAllowsCode(key)) return;
  let input = activeScanningInput()
    || _receiptScanInput
    || document.querySelector('#receiptItemsBody .rcpt-barcode-wrap[data-mode="scanner"] .rcpt-barcode');
  if (!input) return;
  input.value = code;
  validateReceiptBarcodeInput(input, { toast: true, advance: false, scanned: true });
}

function stopReceiptBarcodeScanner() {
  const panel = document.getElementById('receiptBarcodeScannerPanel');
  if (panel) panel.classList.add('d-none');
  _receiptScanInput = null;
  _receiptCamLastKey = '';
  _receiptCamLastHitAt = 0;
  _receiptCamAcceptedKey = '';
  _receiptCamAcceptedAt = 0;
  _receiptCamInView = false;
  if (_receiptQr && _receiptQrActive) {
    _receiptQr.stop().then(() => { _receiptQrActive = false; }).catch(() => { _receiptQrActive = false; });
  } else {
    _receiptQrActive = false;
  }
}

function onReceiptBarcodeModeClick(e) {
  const item = e.target.closest('.rcpt-barcode-mode');
  if (!item) return;
  e.preventDefault();
  const wrap = item.closest('.rcpt-barcode-wrap');
  const picked = wrap?.querySelector('.rcpt-barcode');
  const pickedMode = item.getAttribute('data-mode');
  if (picked && pickedMode !== 'split') setActiveScanningTarget(picked, true);
  setReceiptBarcodeMode(wrap, pickedMode);
  const btn = wrap?.querySelector('.rcpt-barcode-mode-btn');
  try {
    const dd = typeof bootstrap !== 'undefined' && btn ? bootstrap.Dropdown.getInstance(btn) : null;
    if (dd) dd.hide();
  } catch (err) {}
}

function setReceiptBarcodeState(input, ok, message) {
  if (!input) return;
  const msg = input.closest('.rcpt-barcode-wrap')?.querySelector('.rcpt-barcode-msg');
  input.classList.toggle('is-ok', !!ok);
  input.classList.toggle('is-bad', !ok && !!message);
  if (msg) {
    msg.textContent = message || '';
    msg.classList.toggle('is-ok', !!ok);
    msg.classList.toggle('is-bad', !ok && !!message);
  }
}

function clearReceiptBarcodeState(input) {
  if (!input) return;
  input.classList.remove('is-ok', 'is-bad');
  const msg = input.closest('.rcpt-barcode-wrap')?.querySelector('.rcpt-barcode-msg');
  if (msg) {
    msg.textContent = '';
    msg.classList.remove('is-ok', 'is-bad');
  }
}

function submitReceiptSplitBarcode(fromInput) {
  const wrap = fromInput?.closest?.('.rcpt-barcode-wrap');
  if (!wrap || wrap.getAttribute('data-mode') !== 'split') return false;
  const main = wrap.querySelector('.rcpt-barcode');
  const first = String(wrap.querySelector('.rcpt-barcode-first')?.value || '').trim();
  const last = String(wrap.querySelector('.rcpt-barcode-last')?.value || '').trim();
  if (!main) return false;
  if (!first || !last) {
    setReceiptBarcodeState(main, false, 'Enter First Barcode and Last Digits.');
    return false;
  }
  main.value = first + last;
  return validateReceiptBarcodeInput(main, { toast: true, advance: false, scanned: false, split: true });
}

function clearReceiptSplitBarcodeFields(input) {
  const wrap = input?.closest?.('.rcpt-barcode-wrap');
  if (!wrap) return;
  const last = wrap.querySelector('.rcpt-barcode-last');
  if (last) {
    last.value = '';
    try { last.focus(); } catch (err) {}
  }
}

function validateReceiptBarcodeInput(input, { toast = false, advance = false, scanned = false, split = false } = {}) {
  if (!input) return false;
  const row = input.closest('tr');
  if (!row) return false;
  const value = String(input.value || '').trim();
  const key = clarBarcodeKey(value);
  const name = receiptRowProductName(row);
  const linePid = receiptRowProductId(row);
  const method = receiptInputMethod(input, scanned);
  row.dataset.barcodeOverride = '';
  row.dataset.barcodeOverrideReason = '';

  if (!key) {
    clearReceiptBarcodeState(input);
    return true;
  }

  if (receiptScanIsBurst(input, key)) {
    return false;
  }
  if (input.dataset.lookupPending === '1' && input.dataset.lookupKey === key) {
    return false;
  }
  if (receiptBarcodeAlreadyInTransaction(key)) {
    rejectDuplicateReceiptBarcode(input, toast);
    return false;
  }

  const calc = recalcReceiptInspect(row);
  if (calc.good >= calc.ordered) {
    setReceiptBarcodeState(input, false, 'Good quantity has reached the ordered quantity.');
    if (toast) pToast('Cannot accept more barcodes. Good already equals Ordered for ' + name + '.', 'warn');
    input.value = '';
    return false;
  }

  input.dataset.lookupPending = '1';
  input.dataset.lookupKey = key;
  input._scanSeq = (input._scanSeq || 0) + 1;
  const scanSeq = input._scanSeq;
  input.value = '';
  const query = { action: 'lookup_receive_barcode', barcode: value, product_id: String(linePid) };
  if (split) query.exact = '1';
  pApi({ query }, r => {
    if (input.dataset.lookupKey === key) input.dataset.lookupPending = '0';
    if (!r || !r.success) {
      setReceiptBarcodeState(input, false, r?.message || 'Unable to process barcode. Please try scanning again.');
      return;
    }
    if (r.status === 'empty') {
      clearReceiptBarcodeState(input);
      return;
    }
    if (r.status === 'conflict') {
      setReceiptBarcodeState(input, false, 'This barcode belongs to another product.');
      if (toast) pToast('This barcode belongs to another product' + (r.product_name || r.conflict?.product_name ? ' (' + (r.conflict?.product_name || r.product_name) + ')' : '') + '.', 'danger');
      return;
    }
    if (r.status === 'already_used' || r.status === 'matched') {
      setReceiptBarcodeState(input, false, r.message || 'This barcode is already used.');
      if (toast) pToast(r.message || 'This barcode is already used.', 'warn');
      return;
    }
    if (r.status !== 'not_found' && r.status !== 'catalog') {
      setReceiptBarcodeState(input, false, r.message || 'Unable to process barcode. Please try scanning again.');
      if (toast) pToast(r.message || 'Unable to process barcode. Please try scanning again.', 'warn');
      return;
    }
    const resolved = String(r.barcode || value).trim();
    const recordCode = (!split && clarBarcodeKey(resolved) !== key) ? resolved : value;
    const recordKey = clarBarcodeKey(recordCode);
    if (receiptBarcodeAlreadyInTransaction(recordKey)) {
      rejectDuplicateReceiptBarcode(input, toast, recordKey);
      return;
    }
    if (!receiptAddInspectedRecord(row, recordCode, method)) {
      setReceiptBarcodeState(input, false, 'Unable to process barcode. Please try scanning again.');
      return;
    }
    const left = recalcReceiptInspect(row).remaining;
    setReceiptBarcodeState(input, true, left === 0 ? 'Fully inspected.' : ('Accepted · remaining ' + left));
    if (split) clearReceiptSplitBarcodeFields(input);
    if (toast) pToast('Barcode accepted for ' + name + '.', 'success');
    if (input._scanSeq === scanSeq && !clarBarcodeKey(input.value)) input.value = '';
    if (receiptLineAutoState(row) === 'ready') maybeCommitCompletedReceiptRow(row);
    else if (!split) holdReceiptBarcodeFocus(input);
  });
  setReceiptBarcodeState(input, true, 'Checking barcode…');
  return true;
}

function openBarcodeConflictModal(row, input, lookup) {
  let modal = document.getElementById('barcodeConflictModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'barcodeConflictModal';
    modal.className = 'modal-overlay';
    modal.innerHTML = `<div class="modal" style="max-width:520px">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-exclamation-triangle text-warning"></i> Barcode conflict</span>
        <button class="modal-close" type="button" data-bc-close><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body">
        <p id="barcodeConflictText" class="mb-2"></p>
        <label class="form-label small fw-bold">Override reason</label>
        <textarea id="barcodeConflictReason" class="form-control" rows="2" placeholder="Why should this barcode move to the product on this PO line?"></textarea>
        <p class="small text-muted mt-2 mb-0">This is logged. The barcode is not moved unless you confirm.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bc-close>Keep existing link</button>
        <button type="button" class="btn btn-warning" id="barcodeConflictOverride">Override and link here</button>
      </div>
    </div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', (e) => {
      if (e.target === modal || e.target.closest('[data-bc-close]')) closeBarcodeConflictModal();
    });
    document.getElementById('barcodeConflictOverride').addEventListener('click', confirmBarcodeConflictOverride);
  }
  modal._row = row;
  modal._input = input;
  modal._lookup = lookup;
  const name = receiptRowProductName(row);
  const other = lookup.conflict?.product_name || lookup.product_name || 'another product';
  const sku = lookup.conflict?.sku || lookup.sku || '';
  const text = document.getElementById('barcodeConflictText');
  if (text) {
    text.textContent = `Barcode ${lookup.barcode || input.value} is already registered to ${other}${sku ? ' (' + sku + ')' : ''}. Receiving "${name}" will not silently reassign it.`;
  }
  const reason = document.getElementById('barcodeConflictReason');
  if (reason) reason.value = '';
  modal.classList.add('show');
  modal.style.display = 'flex';
}

function closeBarcodeConflictModal() {
  const modal = document.getElementById('barcodeConflictModal');
  if (!modal) return;
  modal.classList.remove('show');
  modal.style.display = 'none';
}

function confirmBarcodeConflictOverride() {
  const modal = document.getElementById('barcodeConflictModal');
  if (!modal) return;
  const reason = String(document.getElementById('barcodeConflictReason')?.value || '').trim();
  if (!reason) {
    if (typeof pToast === 'function') pToast('Enter a reason to override this barcode conflict.', 'warn');
    return;
  }
  const row = modal._row;
  const input = modal._input;
  if (row && input) {
    row.dataset.barcodeOverride = '1';
    row.dataset.barcodeOverrideReason = reason;
    receiptAddScannedBarcode(row, input.value);
    receiptNoteInspected(row, input, input.value, { toast: true, countUnit: true });
    setReceiptBarcodeState(input, true, 'Override recorded — will reassign on receive.');
  }
  closeBarcodeConflictModal();
}

function clearActiveScanningTarget() {
  _activeScanningInput = null;
  _receiptPointerInput = null;
  _receiptPointerAt = 0;
}

function activeScanningInput() {
  if (_activeScanningInput && _activeScanningInput.isConnected) return _activeScanningInput;
  _activeScanningInput = null;
  return null;
}

function setActiveScanningTarget(input, userIntent) {
  if (!input || !input.isConnected || !input.classList || !input.classList.contains('rcpt-barcode')) return;
  if (!userIntent && activeScanningInput() && activeScanningInput() !== input) return;
  _activeScanningInput = input;
  _receiptScanInput = input;
}

function receiptScanDestination(fallback) {
  return activeScanningInput()
    || (fallback && fallback.classList && fallback.classList.contains('rcpt-barcode') ? fallback : null);
}

function finishActiveReceiptBarcodeScan(fallback) {
  const now = Date.now();
  if (now - _receiptScanFinishAt < 60) return;
  const input = receiptScanDestination(fallback);
  if (!input) return;
  _receiptScanFinishAt = now;
  if (fallback && fallback !== input && fallback.classList && fallback.classList.contains('rcpt-barcode')) {
    const stray = String(fallback.value || '').trim();
    if (stray && !String(input.value || '').trim()) input.value = stray;
    fallback.value = '';
  }
  clearTimeout(input._scanTimer);
  input._scanRapid = 0;
  validateReceiptBarcodeInput(input, { toast: true, advance: false, scanned: true });
}

function holdReceiptBarcodeFocus(input) {
  if (!input || !input.isConnected || !input.classList || !input.classList.contains('rcpt-barcode')) return;
  setActiveScanningTarget(input, true);
  const place = () => {
    if (!input.isConnected) return;
    setActiveScanningTarget(input, true);
    if (document.activeElement === input) return;
    try { input.focus({ preventScroll: true }); } catch (err) {}
  };
  place();
  setTimeout(place, 0);
}

function receiptLineAutoState(row) {
  if (!row || row.dataset.lineSaved === '1') return 'saved';
  const calc = recalcReceiptInspect(row);
  if (calc.invalid || calc.good <= 0 || calc.remaining > 0 || calc.good < calc.ordered) return 'pending';
  const inspected = receiptInspectedRecords(row);
  const uniqueGood = inspected.filter(r => r.status === 'Good' || r.status === 'Replacement').length;
  if (uniqueGood === 0) return 'pending';
  if (uniqueGood > 1 && uniqueGood !== calc.good) return 'pending';
  if (uniqueGood > calc.good) return 'pending';
  receiptLotModeSync(row);
  const lots = typeof receiptCollectLots === 'function' ? receiptCollectLots(row) : [];
  const lotEntries = lots.filter(l => (parseInt(l.quantity, 10) || 0) > 0 || String(l.lot_number || '').trim());
  const usable = lotEntries.length ? lotEntries : lots.filter(l => (parseInt(l.quantity, 10) || 0) > 0 || l.lot_number);
  if (!usable.length || usable.some(lot => !String(lot.lot_number || '').trim() || (!lot.no_expiry && !lot.expiry_date))) {
    return 'need-lot';
  }
  return 'ready';
}

function maybeCommitCompletedReceiptRow(row) {
  if (!row || _receiptSubmitBusy || row.dataset.lineSaving === '1' || row.dataset.lineSaved === '1') return;
  const state = receiptLineAutoState(row);
  if (state === 'need-lot') {
    if (row.dataset.lotHint !== '1') {
      row.dataset.lotHint = '1';
      pToast('Enter the lot and expiry for ' + receiptRowProductName(row) + ' to finish this product.', 'warn');
    }
    return;
  }
  if (state !== 'ready') return;
  row.dataset.lotHint = '';
  submitPurchaseReceipt(row, { auto: true });
}

function onReceiptBarcodeKeydown(e) {
  const input = e.target;
  if (input && input.classList && (input.classList.contains('rcpt-barcode-first') || input.classList.contains('rcpt-barcode-last'))) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    e.stopPropagation();
    if (input.classList.contains('rcpt-barcode-first')) {
      const last = input.closest('.rcpt-barcode-wrap')?.querySelector('.rcpt-barcode-last');
      const lastVal = String(last?.value || '').trim();
      const firstVal = String(input.value || '').trim();
      if (firstVal && !lastVal && last) {
        try { last.focus(); } catch (err) {}
        return;
      }
    }
    submitReceiptSplitBarcode(input);
    return;
  }
  if (!input || !input.classList || !input.classList.contains('rcpt-barcode')) return;
  if (e.key === 'Tab') _receiptLastNavKey = 'Tab';
  if (e.key === 'Enter') {
    e.preventDefault();
    e.stopPropagation();
    finishActiveReceiptBarcodeScan(input);
    return;
  }
  if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) return;
  if (receiptBarcodeMode(input) !== 'scanner') return;
  const now = Date.now();
  const gap = now - (input._scanLast || 0);
  input._scanLast = now;
  input._scanRapid = gap > 0 && gap < 50 ? (input._scanRapid || 0) + 1 : 0;
  clearTimeout(input._scanTimer);
  input._scanTimer = setTimeout(() => {
    const target = receiptScanDestination(input);
    if ((input._scanRapid || 0) >= 3 && target && clarBarcodeKey(target.value)) {
      finishActiveReceiptBarcodeScan(input);
    }
    input._scanRapid = 0;
  }, 80);
}

function onReceiptBarcodeFocus(e) {
  const input = e.target;
  if (!input || !input.classList || !input.classList.contains('rcpt-barcode')) return;
  const userPick = (_receiptPointerInput === input && (Date.now() - _receiptPointerAt) < 1000)
    || _receiptLastNavKey === 'Tab';
  _receiptLastNavKey = '';
  setActiveScanningTarget(input, userPick);
  if (receiptBarcodeMode(input) === 'scanner' && userPick) {
    try { input.select(); } catch (err) {}
  }
}

function routeReceiptScannerKey(e) {
  const modal = document.getElementById('createReceiptModal');
  if (!modal || modal.style.display === 'none' || !modal.classList.contains('open')) return;
  if (e.key === 'Tab') {
    _receiptLastNavKey = 'Tab';
    return;
  }
  const active = activeScanningInput();
  if (!active) return;
  const target = e.target;
  const onBarcode = !!(target && target.classList && target.classList.contains('rcpt-barcode'));
  const onSplit = !!(target && target.classList && (target.classList.contains('rcpt-barcode-first') || target.classList.contains('rcpt-barcode-last')));
  if (onSplit) return;
  if (e.key === 'Enter') {
    if (!onBarcode) return;
    e.preventDefault();
    e.stopPropagation();
    finishActiveReceiptBarcodeScan(target);
    return;
  }
  if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) return;
  if (!onBarcode || target === active) return;
  e.preventDefault();
  e.stopPropagation();
  active.value = String(active.value || '') + e.key;
  if (target !== active) target.value = '';
}

function onReceiptBarcodeFocusOut(e) {
  const input = e.target;
  if (input && input.classList && input.classList.contains('rcpt-barcode-last')) {
    const wrap = input.closest('.rcpt-barcode-wrap');
    const first = String(wrap?.querySelector('.rcpt-barcode-first')?.value || '').trim();
    const last = String(input.value || '').trim();
    if (wrap && wrap.getAttribute('data-mode') === 'split' && first && last) submitReceiptSplitBarcode(input);
    return;
  }
  if (!input || !input.classList || !input.classList.contains('rcpt-barcode')) return;
  if (!clarBarcodeKey(input.value)) {
    clearReceiptBarcodeState(input);
    return;
  }
  const row = input.closest('tr');
  const key = clarBarcodeKey(input.value);
  if (row && receiptBarcodeAlreadyOnRow(row, key)) {
    setReceiptBarcodeState(input, true, 'Recorded.');
    return;
  }
  validateReceiptBarcodeInput(input, { toast: false, advance: false, scanned: false });
}

function onReceiptItemsInput(e) {
  onReceiptQtyEvent(e);
  if (e.target && e.target.classList && e.target.classList.contains('rcpt-lot-qty')) {
    sanitizeReceiptWholeQty(e.target);
    syncReceiptLotQtyCap(e.target.closest('tr'));
  }
  if (!e.target || !e.target.classList || !e.target.classList.contains('rcpt-barcode')) return;
  clearReceiptBarcodeState(e.target);
  if (e.inputType === 'insertFromPaste' && clarBarcodeKey(e.target.value)) {
    validateReceiptBarcodeInput(e.target, { toast: true, advance: false, scanned: false });
  }
}

function onReceiptItemsClick(e) {
  const viewBtn = e.target.closest('.rcpt-view-barcodes');
  if (viewBtn) {
    e.preventDefault();
    openReceiptKnownBarcodesView(viewBtn.closest('tr'));
    return;
  }
  const addBtn = e.target.closest('.rcpt-add-lot');
  if (addBtn) {
    e.preventDefault();
    addReceiptLotRow(addBtn.closest('tr'));
    return;
  }
  const removeBtn = e.target.closest('.rcpt-remove-lot');
  if (removeBtn) {
    e.preventDefault();
    removeReceiptLotRow(removeBtn.closest('.rcpt-lot-entry'));
    return;
  }
}

function onReceiptItemsChange(e) {
  onReceiptQtyEvent(e);
  const t = e.target;
  if (!t || !t.classList) return;
  if (t.classList.contains('rcpt-lot-na')) syncReceiptLotNa(t.closest('.rcpt-lot-entry'));
  if (t.classList.contains('rcpt-lot-noexp')) syncReceiptLotNoExp(t.closest('.rcpt-lot-entry'));
  const lotRow = t.closest ? t.closest('tr.rcpt-item-row') : null;
  if (lotRow && (t.classList.contains('rcpt-lot-number') || t.classList.contains('rcpt-expiry') || t.classList.contains('rcpt-lot-na') || t.classList.contains('rcpt-lot-noexp') || t.classList.contains('rcpt-lot-qty'))) {
    maybeCommitCompletedReceiptRow(lotRow);
  }
}

function rememberReceiptBarcodePointer(e) {
  const input = e.target && e.target.closest ? e.target.closest('#receiptItemsBody .rcpt-barcode') : null;
  if (!input) return;
  _receiptPointerInput = input;
  _receiptPointerAt = Date.now();
  setActiveScanningTarget(input, true);
}

function bindReceiptQtyListeners() {
  const tbody = document.getElementById('receiptItemsBody');
  if (!tbody || tbody.dataset.qtyBound === '1') return;
  tbody.dataset.qtyBound = '1';
  tbody.addEventListener('input', onReceiptItemsInput);
  tbody.addEventListener('change', onReceiptItemsChange);
  tbody.addEventListener('keydown', onReceiptBarcodeKeydown);
  tbody.addEventListener('focusin', onReceiptBarcodeFocus);
  tbody.addEventListener('focusout', onReceiptBarcodeFocusOut);
  tbody.addEventListener('pointerdown', rememberReceiptBarcodePointer);
  tbody.addEventListener('click', (e) => {
    onReceiptBarcodeModeClick(e);
    onReceiptItemsClick(e);
  });
  if (!window._receiptScannerRouteBound) {
    window._receiptScannerRouteBound = true;
    document.addEventListener('keydown', routeReceiptScannerKey, true);
  }
}
function bindBarcodeReuseInputs() {
  const stockInInput = document.getElementById('stockInQueueBarcode');
  if (stockInInput && stockInInput.dataset.barcodeBound !== '1') {
    stockInInput.dataset.barcodeBound = '1';
    stockInInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitStockInQueueBarcode();
      }
    });
    stockInInput.addEventListener('input', () => {
      if (_stockInScanPinned && !clarBarcodeKey(stockInInput.value)) {
        _stockInScanPinned = false;
        renderStockInQueueTable();
      }
    });
  }
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bindReceiptQtyListeners);
  document.addEventListener('DOMContentLoaded', bindBarcodeReuseInputs);
} else {
  bindReceiptQtyListeners();
  bindBarcodeReuseInputs();
}

function recalcReceiptMissing(el, remaining) {
  const row = el?.closest?.('tr');
  if (row) recalcReceiptGood(row);
}

function submitPurchaseReceipt(onlyRow, opts) {
  opts = opts || {};
  if (_receiptSubmitBusy) return;
  const poId = document.getElementById('receiptPoId')?.value;
  const date_received = document.getElementById('receiptDate')?.value;
  const remarks = document.getElementById('receiptRemarks')?.value;
  const driver_name = document.getElementById('receiptDriverName')?.value || '';
  const dr_number = document.getElementById('receiptDrNumber')?.value || '';
  const supplier_si_number = document.getElementById('receiptSupplierSi')?.value || '';

  const allRows = [...document.querySelectorAll('#receiptItemsBody tr.rcpt-item-row, #receiptItemsBody tr[data-idx]')].filter(row => row.dataset.lineSaved !== '1');
  let rows = onlyRow ? [onlyRow] : allRows.filter(row => {
    const inspected = receiptInspectedRecords(row);
    const calc = receiptSyncGoodFromInspected(row);
    return calc.good > 0 || inspected.length > 0;
  });
  const leftUnstarted = !onlyRow && rows.length < allRows.length;
  if (!rows.length) { pToast('No items to receive', 'warn'); return; }

  const items = [];
  let invalid = false;
  let invalidFocused = null;
  rows.forEach(row => {
    const idx = row.dataset.idx;
    if (idx === undefined) return;
    receiptLotModeSync(row);
    const calc = receiptSyncGoodFromInspected(row);
    const po_item_id   = row.querySelector(`[name="items[${idx}][po_item_id]"]`)?.value;
    const product_id   = row.querySelector(`[name="items[${idx}][product_id]"]`)?.value;
    const product_name = row.querySelector(`[name="items[${idx}][product_name]"]`)?.value;
    const barcodeInput = row.querySelector('.rcpt-barcode');
    const typedBarcode = String(barcodeInput?.value || '').trim();
    const inspected = receiptInspectedRecords(row);
    if (typedBarcode && !inspected.some(r => clarBarcodeKey(r.barcode) === clarBarcodeKey(typedBarcode))) {
      invalid = true;
      pToast(`Press Enter to accept barcode ${typedBarcode} for ${product_name} before submitting.`, 'warn');
      if (!invalidFocused) invalidFocused = barcodeInput;
      return;
    }
    const barcodes     = inspected.filter(r => r.status === 'Good' || r.status === 'Replacement').map(r => r.barcode);
    const barcode      = String(barcodes[barcodes.length - 1] || '').trim();
    const barcodeOverride = 0;
    const barcodeOverrideReason = '';
    const lots = receiptCollectLots(row);
    const lotEntries = lots.filter(l => (parseInt(l.quantity, 10) || 0) > 0 || String(l.lot_number || '').trim());
    const lotQtySum = lotEntries.reduce((s, l) => s + (parseInt(l.quantity, 10) || 0), 0);
    const firstLot = lotEntries[0] || lots[0] || {};
    const expiry_date  = firstLot.no_expiry ? '' : (firstLot.expiry_date || '');
    const lot_number = firstLot.lot_number || '';
    const unit_price   = parseFloat(row.querySelector(`[name="items[${idx}][unit_price]"]`)?.value||0);

    if (calc.good > calc.ordered || calc.invalid) {
      invalid = true;
      pToast(`Goods cannot exceed ordered quantity for ${product_name}.`, 'warn');
      return;
    }
    const uniqueGood = inspected.filter(r => r.status === 'Good' || r.status === 'Replacement').length;
    if (calc.good > 0 && uniqueGood === 0) {
      invalid = true;
      pToast(`Scan a barcode for ${product_name} before completing inspection.`, 'warn');
      return;
    }
    if (uniqueGood > 1 && uniqueGood !== calc.good) {
      invalid = true;
      pToast(`Unique serial barcodes must match the good quantity for ${product_name}.`, 'warn');
      return;
    }
    if (uniqueGood > calc.good) {
      invalid = true;
      pToast(`Accepted barcodes cannot exceed the good quantity for ${product_name}.`, 'warn');
      return;
    }
    if (lotEntries.length > 1 && lotQtySum !== calc.good) {
      invalid = true;
      pToast(`Combined lot quantities must equal Good quantity (${calc.good}) for ${product_name}.`, 'warn');
      return;
    }
    if (lotQtySum > calc.good) {
      invalid = true;
      pToast(`Lot quantities cannot exceed Good quantity for ${product_name}.`, 'warn');
      return;
    }
    if (calc.good > 0) {
      const usableLots = lotEntries.length ? lotEntries : lots.filter(l => (parseInt(l.quantity, 10) || 0) > 0 || l.lot_number);
      if (!usableLots.length) {
        invalid = true;
        pToast(`Enter a Lot Number (or Not Applicable) for ${product_name}.`, 'warn');
        return;
      }
      for (const lot of usableLots) {
        if (!String(lot.lot_number || '').trim()) {
          invalid = true;
          pToast(`Each lot for ${product_name} needs a Lot Number or Not Applicable.`, 'warn');
          return;
        }
        if (!lot.no_expiry && !lot.expiry_date) {
          invalid = true;
          pToast(`Each lot for ${product_name} needs an Expiry Date or No Expiry.`, 'warn');
          return;
        }
      }
    }
    items.push({
      po_item_id, product_id, product_name,
      quantity_ordered: calc.ordered,
      quantity_good: calc.good,
      quantity_damaged: calc.damaged,
      quantity_missing: calc.missing,
      quantity_received: calc.received,
      manufacturer_lot: lot_number,
      lot_number,
      lots: lotEntries.length ? lotEntries : lots,
      barcode,
      barcodes,
      inspected_barcodes: inspected,
      barcode_override: barcodeOverride,
      barcode_override_reason: barcodeOverrideReason,
      expiry_date,
      no_expiry: firstLot.no_expiry ? 1 : 0,
      unit_price
    });
  });

  if (invalid) {
    if (invalidFocused) {
      try { invalidFocused.focus(); } catch (err) {}
    }
    return;
  }
  if (!items.length) { pToast('No items to receive', 'warn'); return; }

  const body = {
    action: 'create_purchase_receipt',
    po_id: poId,
    date_received: date_received || '',
    remarks: remarks || '',
    driver_name,
    dr_number,
    supplier_si_number,
    items: JSON.stringify(items)
  };
  const rtsId = parseInt(document.getElementById('receiptRtsId')?.value || '0', 10) || 0;
  if (rtsId > 0) body.rts_id = String(rtsId);
  if ((document.getElementById('receiptReceiveReplacement')?.value || '0') === '1') {
    body.receive_replacement = '1';
  }
  const continueId = parseInt(document.getElementById('receiptContinueId')?.value || '0', 10) || 0;
  if (continueId > 0) body.receipt_id = String(continueId);

  const keepOpen = !!onlyRow || leftUnstarted;
  if (onlyRow) onlyRow.dataset.lineSaving = '1';
  _receiptSubmitBusy = true;
  pApi({ method:'POST', body }, r => {
    _receiptSubmitBusy = false;
    if (onlyRow) onlyRow.dataset.lineSaving = '0';
    if (r.success) {
      if (r.receipt_id) {
        const hid = document.getElementById('receiptContinueId');
        if (hid) hid.value = String(r.receipt_id);
      }
      if (r.receipt_number) {
        const header = document.getElementById('receiptPoNumberHeader');
        const savedNo = String(r.receipt_number);
        if (header && String(header.textContent || '').indexOf(savedNo) === -1) {
          const current = String(header.textContent || '').trim();
          header.textContent = current && current !== '—' ? (current + ' · ' + savedNo) : savedNo;
        }
      }
      if (keepOpen) {
        const finishedName = onlyRow ? receiptRowProductName(onlyRow) : '';
        rows.forEach(row => {
          row.dataset.lineSaved = '1';
          row.remove();
        });
        pToast(finishedName ? (finishedName + ' is complete and was removed from Incoming Deliveries.') : (r.message || 'Completed products were removed from Incoming Deliveries.'));
        if (typeof loadIncomingDeliveries === 'function') loadIncomingDeliveries(true);
        const left = document.querySelectorAll('#receiptItemsBody tr.rcpt-item-row');
        if (!left.length) {
          closeModal('createReceiptModal');
          loadPurchaseReceipts();
          loadPurchaseOrders();
          loadStockInQueue();
          loadDamageReturns();
          loadDashboard();
        } else {
          const input = left[0]?.querySelector('.rcpt-barcode');
          if (input) {
            try { input.focus(); } catch (err) {}
            if (typeof setActiveScanningTarget === 'function') setActiveScanningTarget(input, true);
          }
        }
        return;
      }
      pToast(r.message || `Receipt ${r.receipt_number} recorded.`);
      closeModal('createReceiptModal');
      loadPurchaseReceipts();
      loadPurchaseOrders();
      loadStockInQueue();
      loadDamageReturns();
      loadDashboard();
      if (typeof loadIncomingDeliveries === 'function') loadIncomingDeliveries(true);
      if (typeof loadPurchaserMarkupQueue === 'function') loadPurchaserMarkupQueue();
      if (typeof loadPendingAdminStockIn === 'function') loadPendingAdminStockIn();
      if (r.inventory_updated && typeof loadCatalog === 'function') loadCatalog();
      if (r.inventory_updated && typeof loadLocationStock === 'function') loadLocationStock();
      if (r.receipt_id && r.print_receipt !== false) {
        window.open('print_supplier_receipt.php?receipt_id=' + r.receipt_id, '_blank');
      } else if (r.print_receipt !== false && poId) {
        window.open(printPoUrl(poId), '_blank');
      }
    } else {
      pToast(r.message || 'Failed to mark as received', 'danger');
    }
  });
}

function goodsReceivedIsStocked(rec) {
  const wf = String(rec?.workflow_status || '');
  if (wf === 'Stocked In' || String(rec?.stock_in_number || '').trim() !== '') return true;
  return (rec?.items || []).some(it => (parseInt(it.stocked_in_qty, 10) || 0) > 0 || String(it.line_stock_status || '') === 'Stocked In');
}

function receiptDisplayQtys(item) {
  const ordered = Math.max(0, parseInt(item?.quantity_ordered, 10) || 0);
  let good = Math.max(0, parseInt(item?.quantity_good, 10) || 0);
  if (ordered > 0) good = Math.min(ordered, good);
  const gap = Math.max(0, ordered - good);
  const disc = gap;
  const received = good;
  const remaining = gap;
  return { good, disc, received, remaining };
}

function receiptDiscrepancyQty(item) {
  return receiptDisplayQtys(item).disc;
}

function receiptDiscBreakdownHtml(item) {
  return `<strong>${receiptDiscrepancyQty(item)}</strong>`;
}

function goodsReceivedReplacementTable(items) {
  const rows = items || [];
  return `
      <h6 class="fw-bold mb-2">Replacement Items Received (${rows.length})</h6>
      <div class="table-container system-table-scroll" style="margin-bottom:16px">
      <table class="po-items-table" style="margin-bottom:0">
        <thead><tr><th>Product</th><th>Brand</th><th>Replacement Qty</th><th>Unit</th><th>Actual Received</th><th>Status</th></tr></thead>
        <tbody>${rows.map(i => {
          const ordered = parseInt(i.quantity_ordered, 10) || 0;
          const good = parseInt(i.quantity_good, 10) || 0;
          const unit = i.unit_of_measure || 'pcs';
          const status = i.display_status || (ordered > 0 && good >= ordered ? 'Received' : 'To Received');
          return `<tr>
          <td><strong>${esc(i.product_name)}</strong></td>
          <td>${esc(i.brand || '—')}</td>
          <td>${ordered}</td>
          <td>${esc(unit)}</td>
          <td><strong class="text-success">${good}</strong></td>
          <td><span class="badge ${status === 'Received' ? 'badge-green' : 'badge-orange'}">${esc(status)}</span></td>
        </tr>`;
        }).join('') || '<tr><td colspan="6" class="text-center text-muted">No replacement items received.</td></tr>'}</tbody>
      </table>
      </div>`;
}

function goodsReceivedItemsTable(items) {
  const rows = items || [];
  return `
      <h6 class="fw-bold mb-2">Delivered Items (${rows.length})</h6>
      <div class="table-container system-table-scroll" style="margin-bottom:16px">
      <table class="po-items-table" style="margin-bottom:0">
        <thead><tr><th>Product</th><th>SKU</th><th>Ordered</th><th>Received</th><th>Good Qty</th><th>Replacement</th><th>Remaining</th><th>Purchase Price</th><th>Barcode ID</th><th>Lot Number / Expiry</th><th>Batch</th><th>Status</th></tr></thead>
        <tbody>${rows.map(i => {
          const ordered = parseInt(i.quantity_ordered, 10) || 0;
          const shown = receiptDisplayQtys(i);
          const received = shown.received;
          const good = shown.good;
          const cost = i.cost_price || i.unit_price;
          const batch = i.system_batch_number || i.batch_number || '';
          return `<tr>
          <td><strong>${esc(i.product_name)}</strong></td>
          <td><span class="badge badge-gray">${esc(i.sku||'—')}</span></td>
          <td>${ordered}</td>
          <td>${received}</td>
          <td><strong class="text-success">${good}</strong></td>
          <td><strong>${shown.disc}</strong></td>
          <td>${shown.remaining}</td>
          <td>${fmtMoney(cost)}</td>
          <td>${receiptBarcodeCellHtml(i)}</td>
          <td>${receiptLotsSummaryHtml(i)}</td>
          <td>${esc(batch || '—')}</td>
          <td>${esc(i.line_stock_status || '—')}</td>
        </tr>`;
        }).join('')}</tbody>
      </table>
      </div>`;
}

function viewReceipt(id) {
  const body   = document.getElementById('receiptDetailBody');
  const footer = document.getElementById('receiptDetailFooter');
  if (!body) return;
  body.innerHTML = '<div style="text-align:center;padding:40px"><div class="spinner-border spinner-border-sm me-2"></div> Loading Goods Receipt details…</div>';
  openModal('receiptDetailModal');

  pApi({ query:{ action:'get_receipt_detail', id, goods_received: 1 } }, r => {
    if (!r.success || !r.data) { body.innerHTML = `<p style="color:red">${esc(r.message)}</p>`; return; }
    const rec = r.data;
    document.getElementById('receiptDetailTitle').innerHTML = `<i class="bi bi-box-arrow-in-down text-success me-2"></i> ${esc(rec.receipt_number)}`;

    const items = rec.items || [];
    const receivedWhen = rec.received_at ? `${fmtDate(rec.date_received)} ${String(rec.received_at).length > 10 ? String(rec.received_at).slice(11, 16) : ''}`.trim() : fmtDate(rec.date_received);
    body.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;background:#f8fafc;padding:16px;border-radius:10px;border:1px solid #e2e8f0">
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">${Number(rec.is_replacement) === 1 ? 'Replacement Order Number' : 'Receipt # / Receiving Reference'}</div>
          <div style="font-weight:800;font-family:monospace;color:#059669">${esc(rec.replacement_order_number || rec.receipt_number || '—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">${Number(rec.is_replacement) === 1 ? 'Original PO Reference' : 'PO Reference'}</div>
          <div style="font-weight:700;font-family:monospace;color:#0f172a">${esc(rec.original_po_number || rec.po_number || '—')}</div>
        </div>
        ${Number(rec.is_replacement) === 1 ? `<div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Status</div>
          <div style="font-weight:700">${esc(rec.display_status || 'Received')}</div>
          ${rec.original_receipt_number ? `<div style="font-size:12px;color:#64748b">Original receipt ${esc(rec.original_receipt_number)} is unchanged</div>` : ''}
        </div>` : ''}
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Supplier</div>
          <div style="font-weight:600">${esc(rec.supplier_name||'—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Supplier DR #</div>
          <div style="font-weight:600">${esc(rec.dr_number || '—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Supplier Invoice #</div>
          <div style="font-weight:600">${esc(rec.supplier_si_number || '—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Driver / Deliverer</div>
          <div style="font-weight:600">${esc(rec.driver_name || '—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Date Received</div>
          <div style="font-weight:600">${esc(receivedWhen || '—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Received By</div>
          <div style="font-weight:600">${esc(rec.received_by_name||'Warehouse Staff')}</div>
          <div style="font-size:12px;color:#64748b">Workflow: <strong>${esc(rec.workflow_status||'—')}</strong></div>
        </div>
        ${rec.remarks ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Remarks</div><div style="font-size:13px">${esc(rec.remarks)}</div></div>` : ''}
      </div>
      ${Number(rec.is_replacement) === 1 ? goodsReceivedReplacementTable(items) : goodsReceivedItemsTable(items)}`;

    let btnHtml = `<button class="btn btn-outline-secondary" onclick="closeModal('receiptDetailModal')">Close</button>`;
    btnHtml += ` <button class="btn btn-outline-primary" onclick="closeModal('receiptDetailModal');editReceipt(${rec.id})"><i class="bi bi-pencil me-1"></i> Edit</button>`;
    btnHtml += ` <button class="btn btn-success" onclick="window.open('print_supplier_receipt.php?receipt_id=${rec.id}','_blank')"><i class="bi bi-printer me-1"></i> Receipt / Print</button>`;
    if (footer) footer.innerHTML = btnHtml;
  });
}

function editReceipt(id) {
  const body   = document.getElementById('receiptDetailBody');
  const footer = document.getElementById('receiptDetailFooter');
  if (!body) return;
  body.innerHTML = '<div style="text-align:center;padding:40px"><div class="spinner-border spinner-border-sm me-2"></div> Loading Goods Receipt…</div>';
  openModal('receiptDetailModal');

  pApi({ query:{ action:'get_receipt_detail', id, goods_received: 1 } }, r => {
    if (!r.success || !r.data) { body.innerHTML = `<p style="color:red">${esc(r.message)}</p>`; return; }
    const rec = r.data;
    const stocked = goodsReceivedIsStocked(rec);
    document.getElementById('receiptDetailTitle').innerHTML = `<i class="bi bi-pencil-square text-primary me-2"></i> Edit ${esc(rec.receipt_number)}`;
    body.innerHTML = `
      <input type="hidden" id="grEditReceiptId" value="${parseInt(rec.id, 10) || 0}">
      <input type="hidden" id="grEditOriginalNumber" value="${esc(rec.receipt_number || '')}">
      <div class="alert alert-info py-2 small mb-3">Receipt # and PO # are permanent. Editing does not create a new receiving record or change stock.</div>
      <div class="row g-2 mb-3">
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">Receipt # / Receiving Reference</label>
          <input type="text" class="form-control form-control-sm" id="grEditReceiptNumber" value="${esc(rec.receipt_number || '')}" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">PO #</label>
          <input type="text" class="form-control form-control-sm" id="grEditPoNumber" value="${esc(rec.po_number || '')}" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">Supplier</label>
          <input type="text" class="form-control form-control-sm" value="${esc(rec.supplier_name || '')}" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">Received By</label>
          <input type="text" class="form-control form-control-sm" value="${esc(rec.received_by_name || '')}" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">Supplier DR #</label>
          <input type="text" class="form-control form-control-sm" id="grEditDrNumber" value="${esc(rec.dr_number || '')}" maxlength="80">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">Supplier Invoice #</label>
          <input type="text" class="form-control form-control-sm" id="grEditSupplierSi" value="${esc(rec.supplier_si_number || '')}" maxlength="80">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">Driver / Deliverer</label>
          <input type="text" class="form-control form-control-sm" id="grEditDriverName" value="${esc(rec.driver_name || '')}" maxlength="150">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold mb-1">Date Received</label>
          <input type="text" class="form-control form-control-sm" value="${esc(fmtDate(rec.date_received))}" readonly>
        </div>
        <div class="col-12">
          <label class="form-label small fw-bold mb-1">Remarks</label>
          <textarea class="form-control form-control-sm" id="grEditRemarks" rows="2">${esc(rec.remarks || '')}</textarea>
        </div>
      </div>
      <p class="small text-muted">${stocked
        ? 'This receipt is already Stocked In. Quantity, product, barcode, expiry, and purchase price cannot be changed here.'
        : 'Inspection quantities, barcode, expiry, and purchase price stay as recorded during Receive & Inspect. Only document fields above can be corrected here.'}</p>
      ${goodsReceivedItemsTable(rec.items || [])}`;

    let btnHtml = `<button class="btn btn-outline-secondary" onclick="closeModal('receiptDetailModal')">Cancel</button>`;
    btnHtml += ` <button class="btn btn-primary" type="button" onclick="saveGoodsReceivedEdit()"><i class="bi bi-check2 me-1"></i> Save</button>`;
    if (footer) footer.innerHTML = btnHtml;
  });
}

function saveGoodsReceivedEdit() {
  const id = parseInt(document.getElementById('grEditReceiptId')?.value || '0', 10);
  const original = document.getElementById('grEditOriginalNumber')?.value || '';
  const shown = document.getElementById('grEditReceiptNumber')?.value || '';
  if (id <= 0) {
    pToast('Goods Received record not found.', 'danger');
    return;
  }
  if (shown && original && shown !== original) {
    pToast('Receipt # cannot be changed.', 'warn');
    return;
  }
  pApi({
    method: 'POST',
    body: {
      action: 'update_purchase_receipt',
      receipt_id: id,
      driver_name: document.getElementById('grEditDriverName')?.value || '',
      dr_number: document.getElementById('grEditDrNumber')?.value || '',
      supplier_si_number: document.getElementById('grEditSupplierSi')?.value || '',
      remarks: document.getElementById('grEditRemarks')?.value || ''
    }
  }, r => {
    if (!r.success) {
      pToast(r.message || 'Failed to save Goods Received', 'danger');
      return;
    }
    pToast(r.message || 'Goods Received updated. Receipt # unchanged.');
    closeModal('receiptDetailModal');
    loadPurchaseReceipts();
  });
}

// ── STEP 5: SUPPLIER INVOICES ───────────────────────────────
function loadSupplierInvoices() {
  const tbody = document.getElementById('invoiceBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="9"><span class="loader"></span> Loading Supplier Invoices…</td></tr>';
  const params = { action:'get_supplier_invoices' };
  const sf = document.getElementById('supplierInvoiceStatusFilter')?.value;
  const sq = document.getElementById('invoiceSearch')?.value;
  if (sf && sf !== 'all') params.status = sf;
  if (sq) params.search = sq;
  pApi({ query:params }, r => {
    if (!r.success) { tbody.innerHTML = `<tr><td colspan="9" style="color:red;padding:20px">${esc(r.message)}</td></tr>`; return; }
    _invoicesData = r.data || [];
    renderInvoices(_invoicesData);
  });
}

function filterInvoices() { loadSupplierInvoices(); }

function renderInvoices(data) {
  const tbody = document.getElementById('invoiceBody');
  if (!tbody) return;
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:#64748b">No Supplier Invoices found</td></tr>'; return; }
  tbody.innerHTML = data.map(inv => `<tr>
    <td><strong style="font-family:monospace" class="text-primary">${esc(inv.invoice_number)}</strong></td>
    <td><strong style="font-family:monospace">${esc(inv.po_number)}</strong></td>
    <td>${esc(inv.receipt_number||'—')}</td>
    <td>${esc(inv.supplier_name||'—')}</td>
    <td style="font-weight:700">${fmtMoney(inv.total_amount)}</td>
    <td>${invoiceStatusBadge(inv.payment_status)}</td>
    <td>${fmtDate(inv.invoice_date)}</td>
    <td>${fmtDate(inv.due_date)}</td>
    <td>
      <button class="btn-icon primary" title="View Invoice" onclick="viewInvoice(${inv.id})"><i class="bi bi-eye"></i></button>
      <button class="btn-icon" title="Print Invoice" onclick="window.open('print_supplier_invoice.php?invoice_id=${inv.id}','_blank')" style="border-color:#2563eb;color:#2563eb"><i class="bi bi-printer"></i></button>
    </td>
  </tr>`).join('');
}

function openCreateInvoiceModal() {
  document.getElementById('createInvoiceForm')?.reset();
  const poSel = document.getElementById('invPoSelect');
  if (poSel) {
    poSel.innerHTML = '<option value="">Loading purchase orders...</option>';
    const fillPOs = () => {
      poSel.innerHTML = '<option value="">-- Select Purchase Order --</option>' +
        _posData.map(p => `<option value="${p.id}" data-total="${p.total_amount}" data-supplier="${p.supplier_name||''}">${esc(p.po_number + ' - ' + (p.supplier_name||'') + ' (' + fmtMoney(p.total_amount) + ')')}</option>`).join('');
    };

    if (_posData.length) {
      fillPOs();
    } else {
      pApi({ query:{ action:'get_purchase_orders', status:'all' } }, r => {
        _posData = r.data || [];
        fillPOs();
      });
    }
  }
  openModal('createInvoiceModal');
}

function onInvoicePOChange(sel) {
  const opt = sel.options[sel.selectedIndex];
  const total = parseFloat(opt.dataset.total||0);
  const subtotalInput = document.getElementById('invSubtotal');
  const totalInput    = document.getElementById('invTotal');
  if (subtotalInput) subtotalInput.value = total.toFixed(2);
  if (totalInput) totalInput.value = total.toFixed(2);
}

function recalcInvoiceTotal() {
  const sub = parseFloat(document.getElementById('invSubtotal')?.value||0);
  const tax = parseFloat(document.getElementById('invTax')?.value||0);
  const disc = parseFloat(document.getElementById('invDiscount')?.value||0);
  const total = sub + tax - disc;
  const totalInput = document.getElementById('invTotal');
  if (totalInput) totalInput.value = Math.max(0, total).toFixed(2);
}

function submitSupplierInvoice() {
  const form = document.getElementById('createInvoiceForm');
  if (!form) return;
  const fd   = new FormData(form);
  fd.set('action', 'create_supplier_invoice');

  pApi({ method:'POST', isFormData:true, body: fd }, r => {
    if (r.success) {
      pToast(`Invoice ${r.invoice_number} recorded!`);
      closeModal('createInvoiceModal');
      loadSupplierInvoices();
      loadDashboard();
    } else {
      pToast(r.message || 'Failed to record invoice', 'danger');
    }
  });
}

function viewInvoice(id) {
  const body   = document.getElementById('invoiceDetailBody');
  const footer = document.getElementById('invoiceDetailFooter');
  if (!body) return;
  body.innerHTML = '<div style="text-align:center;padding:40px"><div class="spinner-border spinner-border-sm me-2"></div> Loading Supplier Invoice…</div>';
  openModal('invoiceDetailModal');

  pApi({ query:{ action:'get_invoice_detail', id } }, r => {
    if (!r.success || !r.data) { body.innerHTML = `<p style="color:red">${esc(r.message)}</p>`; return; }
    const inv = r.data;
    document.getElementById('invoiceDetailTitle').innerHTML = `<i class="bi bi-file-earmark-text text-primary me-2"></i> Invoice ${esc(inv.invoice_number)}`;

    body.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;background:#f8fafc;padding:16px;border-radius:10px;border:1px solid #e2e8f0">
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Supplier</div>
          <div style="font-weight:700;color:#0f172a">${esc(inv.supplier_name||'—')}</div>
          <div style="font-size:12px;color:#64748b">Code: ${esc(inv.supplier_code||'—')}</div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Payment Status</div>
          <div class="d-flex align-items-center gap-2">
            ${invoiceStatusBadge(inv.payment_status)}
            <select class="form-select form-select-sm" style="width:130px" onchange="updateInvoicePaymentStatus(${inv.id}, this.value)">
              <option value="Unpaid"${inv.payment_status==='Unpaid'?' selected':''}>Unpaid</option>
              <option value="Partially Paid"${inv.payment_status==='Partially Paid'?' selected':''}>Partially Paid</option>
              <option value="Paid"${inv.payment_status==='Paid'?' selected':''}>Paid</option>
            </select>
          </div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Invoice & Due Date</div>
          <div style="font-size:13px">Invoice: <strong>${fmtDate(inv.invoice_date)}</strong></div>
          <div style="font-size:13px">Due Date: <strong>${fmtDate(inv.due_date)}</strong></div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Linked Documents</div>
          <div style="font-size:13px">PO #: <strong>${esc(inv.po_number)}</strong></div>
          ${inv.receipt_number ? `<div style="font-size:13px">Receipt #: <strong>${esc(inv.receipt_number)}</strong></div>` : ''}
        </div>
        ${inv.scanned_invoice_url ? `<div style="grid-column:1/-1"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">Scanned Document</div><div><a href="${esc(inv.scanned_invoice_url)}" target="_blank" class="btn btn-sm btn-outline-primary mt-1"><i class="bi bi-file-earmark-pdf me-1"></i> View Scanned Invoice PDF/Image</a></div></div>` : ''}
      </div>

      <div class="p-3 mb-3 border rounded" style="background:#fff">
        <div class="d-flex justify-content-between mb-1"><span>Subtotal:</span><strong>${fmtMoney(inv.subtotal)}</strong></div>
        <div class="d-flex justify-content-between mb-1"><span>Tax:</span><strong>${fmtMoney(inv.tax_amount)}</strong></div>
        <div class="d-flex justify-content-between mb-1"><span>Discount:</span><strong class="text-danger">-${fmtMoney(inv.discount_amount)}</strong></div>
        <hr class="my-2">
        <div class="d-flex justify-content-between h5 fw-bold mb-0"><span>Total Amount:</span><span class="text-primary">${fmtMoney(inv.total_amount)}</span></div>
      </div>`;

    let btnHtml = `<button class="btn btn-outline-secondary" onclick="closeModal('invoiceDetailModal')">Close</button>`;
    btnHtml += ` <button class="btn btn-primary" onclick="window.open('print_supplier_invoice.php?invoice_id=${inv.id}','_blank')"><i class="bi bi-printer me-1"></i> Print Invoice</button>`;
    if (footer) footer.innerHTML = btnHtml;
  });
}

function updateInvoicePaymentStatus(id, newStatus) {
  pApi({ method:'POST', body:{ action:'update_invoice_payment_status', id, payment_status: newStatus } }, r => {
    if (r.success) {
      pToast(`Payment status updated to ${newStatus}`);
      loadSupplierInvoices();
      loadDashboard();
    } else {
      pToast(r.message || 'Failed to update payment status', 'danger');
    }
  });
}

// ── SUPPLIERS DIRECTORY & PERFORMANCE ──────────────────────
function confirmReceivePO() {
  const poId = parseInt(document.getElementById('receivePOId')?.value || 0);
  const receivedDate = document.getElementById('receivedDate')?.value || new Date().toISOString().split('T')[0];
  if (!poId) {
    pToast('Please select a Purchase Order first', 'warn');
    return;
  }

  pApi({ query:{ action:'get_po_detail', id: poId } }, r => {
    if (!r.success || !r.data) {
      pToast(r.message || 'Could not load Purchase Order details', 'danger');
      return;
    }
    const items = (r.data.items || []).map(i => {
      const remaining = i.quantity_remaining != null
        ? Math.max(0, parseInt(i.quantity_remaining, 10) || 0)
        : Math.max(0, (parseInt(i.quantity_ordered, 10) || 0) - (parseInt(i.quantity_processed != null ? i.quantity_processed : i.quantity_received, 10) || 0));
      return {
        po_item_id: i.id,
        product_id: i.product_id || null,
        product_name: i.product_name,
        quantity_ordered: i.quantity_ordered,
        quantity_received: remaining,
        unit_price: i.unit_price
      };
    }).filter(i => i.quantity_received > 0);

    if (!items.length) {
      pToast('No remaining PO items to receive', 'warn');
      return;
    }

    pApi({ method:'POST', body:{
      action: 'create_purchase_receipt',
      po_id: poId,
      date_received: receivedDate,
      remarks: 'Received through PO quick receipt confirmation',
      items: JSON.stringify(items)
    } }, resp => {
      if (resp.success) {
        pToast(`Purchase Receipt ${resp.receipt_number} created!`);
        closeModal('receivePOModal');
        loadPurchaseOrders();
        loadPurchaseReceipts();
        loadDashboard();
      } else {
        pToast(resp.message || 'Failed to confirm receipt', 'danger');
      }
    });
  });
}

function submitPerformance() {
  const form = document.getElementById('addPerfForm');
  if (!form) return;
  const fd = new FormData(form);
  fd.set('action', 'add_performance');
  const body = {};
  fd.forEach((v, k) => body[k] = v);

  if (!body.supplier_id) {
    pToast('Please select a supplier', 'warn');
    return;
  }

  pApi({ method:'POST', body }, r => {
    if (r.success) {
      pToast('Delivery performance recorded!');
      closeModal('addPerfModal');
      form.reset();
      loadPerformance();
      loadSuppliers();
    } else {
      pToast(r.message || 'Failed to record delivery performance', 'danger');
    }
  });
}

function loadSuppliers() {
  const tbody = document.getElementById('suppliersBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="8"><span class="loader"></span> Loading…</td></tr>';
  const params = { action:'get_suppliers' };
  const sf = document.getElementById('supplierStatusFilter')?.value;
  const sq = document.getElementById('supplierSearch')?.value;
  if (sf && sf !== 'all') params.status = sf;
  if (sq) params.search = sq;
  pApi({ query:params }, r => {
    if (!r.success) { tbody.innerHTML = `<tr><td colspan="8" style="color:red;padding:20px">${esc(r.message)}</td></tr>`; return; }
    _suppliersData = r.data || [];
    renderSuppliers(_suppliersData);
  });
}

function filterSuppliers() { loadSuppliers(); }

function renderSuppliers(data) {
  const tbody = document.getElementById('suppliersBody');
  if (!tbody) return;
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:#64748b">No suppliers found</td></tr>'; return; }
  tbody.innerHTML = data.map(s => `<tr>
    <td><span class="badge badge-violet">${esc(s.supplier_code)}</span></td>
    <td><strong>${esc(s.name)}</strong><br><span style="font-size:11px;color:#64748b">${esc(s.contact_person||'')}</span></td>
    <td>${esc(s.email||'—')}</td>
    <td>${esc(s.phone||'—')}</td>
    <td><span class="badge badge-gray">${esc(s.payment_terms||'—')}</span></td>
    <td><span class="badge badge-blue">${s.lead_time_days||'?'} days</span></td>
    <td>${supplierStatusBadge(s.status)}</td>
    <td>
      <button class="btn-icon primary" title="Edit" onclick="openEditSupplier(${JSON.stringify(s).replace(/"/g,'&quot;')})"><i class="bi bi-pencil"></i></button>
    </td>
  </tr>`).join('');
}

function openAddSupplier() {
  _editingSupplierId = null;
  document.getElementById('supplierModalTitle').innerHTML = '<i class="bi bi-building"></i> Add New Supplier';
  document.getElementById('supplierForm')?.reset();
  document.getElementById('supplierEditId').value = '';
  const codeEl = document.getElementById('supCode');
  if (codeEl) { codeEl.readOnly = false; codeEl.value = ''; }
  openModal('addSupplierModal');
}

function openEditSupplier(s) {
  _editingSupplierId = s.id;
  document.getElementById('supplierModalTitle').innerHTML = '<i class="bi bi-pencil-square"></i> Edit Supplier';
  document.getElementById('supplierEditId').value = s.id;
  document.getElementById('supCode').value         = s.supplier_code;
  document.getElementById('supCode').readOnly      = true;
  document.getElementById('supName').value         = s.name;
  document.getElementById('supContact').value      = s.contact_person;
  document.getElementById('supEmail').value        = s.email;
  document.getElementById('supPhone').value        = s.phone;
  document.getElementById('supCity').value         = s.city;
  document.getElementById('supCountry').value      = s.country;
  document.getElementById('supPayTerms').value     = s.payment_terms;
  document.getElementById('supLeadTime').value     = s.lead_time_days;
  document.getElementById('supStatus').value       = s.status;
  document.getElementById('supAddress').value      = s.address;
  document.getElementById('supProductLines').value = s.product_lines;
  document.getElementById('supNotes').value        = s.notes;
  openModal('addSupplierModal');
}

function submitSupplier() {
  const form = document.getElementById('supplierForm');
  const fd   = new FormData(form);
  const id   = document.getElementById('supplierEditId')?.value;
  fd.set('action', id ? 'update_supplier' : 'add_supplier');
  if (id) fd.set('id', id);
  const body = {};
  fd.forEach((v,k) => body[k]=v);
  pApi({ method:'POST', body }, r => {
    if (r.success) { pToast(id ? 'Supplier updated!' : 'Supplier added!'); closeModal('addSupplierModal'); loadSuppliers(); loadDropdowns(); loadDashboard(); }
    else pToast(r.message||'Failed','danger');
  });
}

function loadPerformance() {
  const tbody = document.getElementById('perfBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="8"><span class="loader"></span> Loading…</td></tr>';
  pApi({ query:{ action:'get_performance' } }, r => {
    if (!r.success) { tbody.innerHTML = `<tr><td colspan="8" style="color:red;padding:20px">${esc(r.message)}</td></tr>`; return; }
    _perfData = r.data || [];
    if (!_perfData.length) { tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:#64748b">No performance data yet</td></tr>'; return; }
    tbody.innerHTML = _perfData.map(s => {
      const rate = parseFloat(s.on_time_rate||0);
      const barColor = rate >= 90 ? '#10b981' : rate >= 70 ? '#f59e0b' : '#ef4444';
      return `<tr>
        <td><strong>${esc(s.supplier_name)}</strong><br><span class="badge badge-gray" style="font-size:10px">${esc(s.supplier_code)}</span></td>
        <td>${s.total_deliveries}</td>
        <td>${s.on_time_count}</td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div class="progress-bar" style="width:80px"><div class="progress-fill" style="width:${rate}%;background:${barColor}"></div></div>
            <span style="font-size:12px;font-weight:600;color:${barColor}">${rate||0}%</span>
          </div>
        </td>
        <td>${s.quality_issues > 0 ? `<span class="badge badge-red">${s.quality_issues}</span>` : '<span class="badge badge-green">0</span>'}</td>
        <td><span class="badge badge-blue">${s.lead_time_days||'?'} days</span></td>
        <td>${ratingStars(s.rating)}</td>
        <td>${s.received_pos}</td>
      </tr>`;
    }).join('');
  });
}

// ── STOCK-IN QUEUE / MARKUP / DAMAGE / SALES ─────────────────
let _siCaps = { can_set_markup: false, can_grant_stock_in: false, can_execute_stock_in: false, can_receive: false };

function stockInQueueMarkupLabel(it) {
  const saved = Number(it && it.markup_percent != null ? it.markup_percent : 0);
  return (Number.isFinite(saved) ? saved : 0).toFixed(2) + '%';
}

function stockInQueueMarkupStack(rec) {
  const items = (rec && rec.items) || [];
  if (!items.length) return stockInQueueMarkupLabel(rec);
  if (items.length === 1) return stockInQueueMarkupLabel(items[0]);
  return `<div class="small">${items.map(it => stockInQueueMarkupLabel(it)).join('<br>')}</div>`;
}

function siQueuePriceStack(rec, field, format) {
  const items = rec.items || [];
  if (!items.length) {
    return format(parseFloat(rec[field] || 0));
  }
  if (items.length === 1) {
    return format(parseFloat(items[0][field] || 0));
  }
  const vals = items.map(it => format(parseFloat(it[field] || 0)));
  return `<div class="small">${vals.join('<br>')}</div>`;
}

function clarBarcodeKey(code) {
  return String(code || '').trim().toUpperCase();
}

function siQueueItemSummary(rec) {
  const items = rec.items || [];
  if (!items.length) return '—';
  if (items.length === 1) {
    const bc = items[0].barcode ? ` <span class="small text-muted">(${esc(items[0].barcode)})</span>` : '';
    return esc(items[0].product_name || 'Item') + bc;
  }
  const names = items.map(it => esc(it.product_name || 'Item'));
  return `<div class="small">${items.length} items<br>${names.slice(0, 2).join('<br>')}${items.length > 2 ? '<br>…' : ''}</div>`;
}

let _stockInQueueRows = [];
window._pendingStockInScan = window._pendingStockInScan || '';

function stockInQueueStatusFilter() {
  const el = document.getElementById('stockInStatusFilter');
  const v = el ? String(el.value || 'all') : 'all';
  if (v === 'ready' || v === 'stocked') return v;
  return 'all';
}

let _stockInCustomFrom = '';
let _stockInCustomTo = '';
let _stockInActiveDateMode = 'all';

function localYmd(date) {
  const d = date || new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return y + '-' + m + '-' + day;
}

function receivedYmd(stamp) {
  const m = String(stamp || '').trim().match(/^(\d{4}-\d{2}-\d{2})/);
  return m ? m[1] : '';
}

function stockInDateBounds() {
  const mode = _stockInActiveDateMode || 'all';
  const today = new Date();
  const todayYmd = localYmd(today);
  if (mode === 'today') return { from: todayYmd, to: todayYmd };
  if (mode === 'week') {
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const dow = start.getDay();
    start.setDate(start.getDate() - (dow === 0 ? 6 : dow - 1));
    const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
    return { from: localYmd(start), to: localYmd(end) };
  }
  if (mode === 'month') {
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    const end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    return { from: localYmd(start), to: localYmd(end) };
  }
  if (mode === 'custom' && /^\d{4}-\d{2}-\d{2}$/.test(_stockInCustomFrom) && /^\d{4}-\d{2}-\d{2}$/.test(_stockInCustomTo) && _stockInCustomFrom <= _stockInCustomTo) {
    return { from: _stockInCustomFrom, to: _stockInCustomTo };
  }
  return null;
}

function stockInDateFilterChanged() {
  const mode = document.getElementById('stockInDateFilter')?.value || 'all';
  const box = document.getElementById('stockInDateCustom');
  if (box) {
    const show = mode === 'custom';
    box.classList.toggle('d-none', !show);
    box.classList.toggle('d-flex', show);
  }
  if (mode !== 'custom') {
    _stockInActiveDateMode = mode;
    renderStockInQueueTable();
  }
}
window.stockInDateFilterChanged = stockInDateFilterChanged;

function applyStockInCustomDate() {
  const from = document.getElementById('stockInDateFrom')?.value || '';
  const to = document.getElementById('stockInDateTo')?.value || '';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to) || from > to) return;
  _stockInCustomFrom = from;
  _stockInCustomTo = to;
  _stockInActiveDateMode = 'custom';
  renderStockInQueueTable();
}
window.applyStockInCustomDate = applyStockInCustomDate;

function stockInSearchText(rec) {
  const it = (rec.items && rec.items[0]) || {};
  const extras = it.barcode_values || (Array.isArray(it.barcodes) ? it.barcodes.map(b => b.barcode_value || b) : []);
  const lots = Array.isArray(it.lots) ? it.lots.map(l => [l.lot_number, l.expiry_date].join(' ')) : [];
  return [
    it.product_name, it.sku, it.barcode, it.manufacturer_lot, it.expiry_date, rec.po_number, rec.receipt_number, rec.supplier_name, it.scanned_barcodes,
    rec.queue_status, rec.received_by_name, rec.received_at, fmtDateTime(rec.received_at), rec.ready_since, fmtDateTime(rec.ready_since)
  ].concat(extras, lots).join(' ').toLowerCase();
}

function stockInItemQueueStatus(it) {
  const s = String((it && it.queue_status) || '');
  if (s === 'Stocked' || s === 'Ready for Stock-In') return s;
  const line = String((it && it.line_stock_status) || '');
  const good = parseInt(it && it.quantity_good, 10) || 0;
  const stocked = parseInt(it && it.stocked_in_qty, 10) || 0;
  if (line === 'Stocked In' || (good > 0 && stocked >= good)) return 'Stocked';
  return 'Ready for Stock-In';
}

function stockInExactSingleLot(it) {
  const lots = Array.isArray(it && it.lots) ? it.lots : [];
  const names = [];
  lots.forEach(l => {
    const num = String(l.lot_number || '').trim();
    if (names.indexOf(num) === -1) names.push(num);
  });
  if (names.length > 1) return null;
  if (names.length === 1) return names[0];
  return String((it && it.manufacturer_lot) || '').trim();
}

function accumulateExactStockInLots(lots) {
  const order = [];
  const map = new Map();
  (lots || []).forEach(l => {
    const num = String(l.lot_number || '').trim();
    if (!map.has(num)) {
      map.set(num, Object.assign({}, l, { lot_number: num, quantity: parseInt(l.quantity, 10) || 0 }));
      order.push(num);
      return;
    }
    const dest = map.get(num);
    dest.quantity = (parseInt(dest.quantity, 10) || 0) + (parseInt(l.quantity, 10) || 0);
  });
  return order.map(key => map.get(key));
}

function stockInProductLotKey(rec) {
  const it = (rec.items && rec.items[0]) || {};
  const lot = stockInExactSingleLot(it);
  if (lot === null) return '';
  const productId = parseInt(it.product_id, 10) || 0;
  const productKey = productId > 0 ? ('id:' + productId) : ('name:' + String(it.product_name || '').trim());
  return [
    String(rec.po_number || '').trim(),
    productKey,
    lot,
    String(rec.queue_status || '')
  ].join('\n');
}

function stockInRowReceiptIds(rec) {
  const ids = [];
  const add = (value) => {
    const id = parseInt(value, 10) || 0;
    if (id > 0 && ids.indexOf(id) === -1) ids.push(id);
  };
  if (Array.isArray(rec && rec._receipt_ids)) rec._receipt_ids.forEach(add);
  add(rec && rec.id);
  return ids;
}

function mergeStockInQueueSameProductLot(rows) {
  const out = [];
  const index = new Map();
  (rows || []).forEach(rec => {
    const src = (rec.items && rec.items[0]) ? rec.items[0] : {};
    const it = Object.assign({}, src);
    if (Array.isArray(it.lots) && it.lots.length) it.lots = accumulateExactStockInLots(it.lots);
    const row = Object.assign({}, rec, {
      quantity_good: parseInt(it.quantity_good, 10) || 0,
      items: [it],
      _receipt_ids: stockInRowReceiptIds(rec)
    });
    const key = stockInProductLotKey(row);
    if (!key || !index.has(key)) {
      out.push(row);
      if (key) index.set(key, row);
      return;
    }
    const dest = index.get(key);
    const dIt = dest.items[0];
    const addGood = parseInt(it.quantity_good, 10) || 0;
    dIt.quantity_good = (parseInt(dIt.quantity_good, 10) || 0) + addGood;
    dest.quantity_good = dIt.quantity_good;
    const addOrdered = parseInt(it.quantity_ordered, 10) || 0;
    if (addOrdered > (parseInt(dIt.quantity_ordered, 10) || 0)) dIt.quantity_ordered = addOrdered;
    const mergedOrdered = parseInt(dIt.quantity_ordered, 10) || 0;
    const mergedGood = parseInt(dIt.quantity_good, 10) || 0;
    dIt.quantity_damaged = 0;
    dIt.quantity_missing = Math.max(0, mergedOrdered - (mergedOrdered > 0 ? Math.min(mergedOrdered, mergedGood) : mergedGood));
    stockInRowReceiptIds(row).forEach(id => {
      if (dest._receipt_ids.indexOf(id) === -1) dest._receipt_ids.push(id);
    });
    dIt.barcode_values = stockInUnionCodes(dIt.barcode_values, it.barcode_values);
    dIt.scanned_barcodes = stockInUnionCodes(dIt.scanned_barcodes, it.scanned_barcodes);
    const addLot = (it.lots && it.lots[0]) ? (parseInt(it.lots[0].quantity, 10) || 0) : addGood;
    if (dIt.lots && dIt.lots[0]) {
      dIt.lots[0].quantity = (parseInt(dIt.lots[0].quantity, 10) || 0) + addLot;
    }
  });
  return out;
}

function flattenStockInQueueRows(receipts) {
  const out = [];
  (receipts || []).forEach(rec => {
    (rec.items || []).forEach(it => {
      const status = stockInItemQueueStatus(it);
      if (status !== 'Ready for Stock-In' && status !== 'Stocked') return;
      out.push({
        id: parseInt(rec.id, 10) || 0,
        receipt_item_id: parseInt(it.receipt_item_id, 10) || 0,
        queue_status: status,
        received_at: it.received_at || rec.received_at || '',
        ready_since: it.pricing_confirmed_at || '',
        quantity_good: parseInt(it.quantity_good, 10) || 0,
        po_number: rec.po_number,
        receipt_number: rec.receipt_number,
        supplier_name: rec.supplier_name,
        received_by_name: rec.received_by_name,
        workflow_status: status === 'Stocked' ? 'Stocked In' : 'Approved - Pending Stock-In',
        items: [it]
      });
    });
  });
  return mergeStockInQueueSameProductLot(out);
}

let _stockInScanPinned = false;
let _stockInKeepScan = false;

function stockInUnionCodes(left, right) {
  const out = [];
  const seen = {};
  [left, right].forEach(list => {
    (Array.isArray(list) ? list : []).forEach(code => {
      const text = String(code || '').trim();
      const key = clarBarcodeKey(text);
      if (!key || seen[key]) return;
      seen[key] = true;
      out.push(text);
    });
  });
  return out;
}

function stockInPinnedScan() {
  if (!_stockInScanPinned) return '';
  return clarBarcodeKey(document.getElementById('stockInQueueBarcode')?.value || '');
}

function filterStockInQueueRows(rows) {
  let list = rows || [];
  const scan = stockInPinnedScan();
  if (scan) return list.filter(r => stockInQueueMatchesBarcode(r, scan));
  const mode = stockInQueueStatusFilter();
  if (mode === 'ready') list = list.filter(r => r.queue_status === 'Ready for Stock-In');
  else if (mode === 'stocked') list = list.filter(r => r.queue_status === 'Stocked');
  const bounds = stockInDateBounds();
  if (bounds) {
    list = list.filter(r => {
      const ymd = receivedYmd(r.received_at);
      return ymd !== '' && ymd >= bounds.from && ymd <= bounds.to;
    });
  }
  const q = String(document.getElementById('stockInQueueSearch')?.value || '').trim().toLowerCase();
  if (q) list = list.filter(r => stockInSearchText(r).indexOf(q) !== -1);
  return list;
}

function stockInQueueStatusBadge(status) {
  if (status === 'Stocked') return '<span class="badge badge-green">Stocked</span>';
  return '<span class="badge badge-violet">Ready for Stock-In</span>';
}

function stockInQueueMatchesBarcode(rec, code) {
  const key = clarBarcodeKey(code);
  if (!key) return false;
  return (rec.items || []).some(it => {
    if (clarBarcodeKey(it.barcode) === key) return true;
    const extras = stockInUnionCodes(it.barcode_values, it.scanned_barcodes)
      .concat(Array.isArray(it.barcodes) ? it.barcodes.map(b => b.barcode_value || b) : []);
    return extras.some(v => clarBarcodeKey(v) === key);
  });
}

function highlightStockInQueueRow(receiptItemId, receiptId) {
  document.querySelectorAll('#stockInQueueBody tr').forEach(tr => {
    const match = receiptItemId
      ? String(tr.getAttribute('data-receipt-item-id')) === String(receiptItemId)
      : String(tr.getAttribute('data-receipt-id')) === String(receiptId);
    tr.classList.toggle('table-active', !!match);
    tr.style.outline = match ? '2px solid #2563eb' : '';
    if (match) tr.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });
}

function applyBarcodeToStockInQueue(code) {
  const key = clarBarcodeKey(code);
  const input = document.getElementById('stockInQueueBarcode');
  if (input && key) input.value = String(code || '').trim();
  if (!key) return false;
  const rec = (_stockInQueueRows || []).find(r => stockInQueueMatchesBarcode(r, key));
  _stockInScanPinned = true;
  _stockInKeepScan = true;
  renderStockInQueueTable();
  if (!rec) {
    if (typeof pToast === 'function') pToast('No Stock-In item for barcode ' + key + '.', 'warn');
    return false;
  }
  const shown = document.querySelector('#stockInQueueBody tr[data-receipt-item-id="' + rec.receipt_item_id + '"]');
  if (shown && typeof window.clarenzonRevealTableRow === 'function') window.clarenzonRevealTableRow(shown);
  highlightStockInQueueRow(rec.receipt_item_id, rec.id);
  if (input) {
    try { input.focus(); input.select(); } catch (err) {}
  }
  return true;
}

function submitStockInQueueBarcode() {
  const input = document.getElementById('stockInQueueBarcode');
  const code = input ? input.value : '';
  if (!clarBarcodeKey(code)) return;
  if (_stockInQueueRows.length) {
    applyBarcodeToStockInQueue(code);
    return;
  }
  window._pendingStockInScan = code;
  loadStockInQueue();
}

function pricingQueueLotIdentity(it) {
  const lots = Array.isArray(it && it.lots) && it.lots.length
    ? it.lots
    : [{ lot_number: (it && it.manufacturer_lot) || '' }];
  const names = [];
  lots.forEach(l => {
    const num = String(l.lot_number || '').trim();
    if (names.indexOf(num) === -1) names.push(num);
  });
  if (names.length !== 1) return null;
  return names[0];
}

function accumulatePricingQueueLots(lots) {
  const order = [];
  const map = new Map();
  (lots || []).forEach(l => {
    const num = String(l.lot_number || '').trim();
    if (!map.has(num)) {
      map.set(num, Object.assign({}, l, { lot_number: num, quantity: parseInt(l.quantity, 10) || 0 }));
      order.push(num);
      return;
    }
    const dest = map.get(num);
    dest.quantity = (parseInt(dest.quantity, 10) || 0) + (parseInt(l.quantity, 10) || 0);
  });
  return order.map(key => map.get(key));
}

function groupPricingQueueRows(rows) {
  const out = [];
  const index = new Map();
  (rows || []).forEach(src => {
    const lot = pricingQueueLotIdentity(src);
    const productId = parseInt(src.product_id, 10) || 0;
    const productKey = productId > 0 ? ('id:' + productId) : ('name:' + String(src.product_name || '').trim());
    const key = lot === null ? '' : [String(src.po_number || '').trim(), productKey, lot].join('\n');
    const copy = Object.assign({}, src, {
      lots: accumulatePricingQueueLots(Array.isArray(src.lots) ? src.lots.map(l => Object.assign({}, l)) : []),
      _grouped_ids: [parseInt(src.receipt_item_id, 10) || 0].filter(Boolean)
    });
    if (!copy.lots.length) {
      copy.lots = [{
        lot_number: String(copy.manufacturer_lot || '').trim(),
        quantity: parseInt(copy.quantity_good, 10) || 0,
        expiry_date: copy.expiry_date || '',
        no_expiry: copy.expiry_date ? 0 : 1
      }];
    }
    if (!key || !index.has(key)) {
      out.push(copy);
      if (key) index.set(key, copy);
      return;
    }
    const dest = index.get(key);
    dest.quantity_good = (parseInt(dest.quantity_good, 10) || 0) + (parseInt(copy.quantity_good, 10) || 0);
    copy._grouped_ids.forEach(id => {
      if (dest._grouped_ids.indexOf(id) === -1) dest._grouped_ids.push(id);
    });
    copy.lots.forEach(l => {
      const num = String(l.lot_number || '').trim();
      const match = dest.lots.find(d => String(d.lot_number || '').trim() === num);
      if (match) match.quantity = (parseInt(match.quantity, 10) || 0) + (parseInt(l.quantity, 10) || 0);
      else dest.lots.push(Object.assign({}, l));
    });
  });
  return out;
}

function loadPurchaserMarkupQueue(silent) {
  const tbody = document.getElementById('markupQueueBody');
  if (!tbody) return;
  if (!silent) {
    tbody.innerHTML = '<tr class="loading-row"><td colspan="10" class="text-center py-3 text-muted">Loading…</td></tr>';
  }
  pApi({ query: { action: 'get_purchaser_markup_queue' } }, r => {
    if (!r.success) {
      if (!silent) tbody.innerHTML = `<tr><td colspan="10" class="text-danger text-center py-3">${esc(r.message || 'Failed')}</td></tr>`;
      return;
    }
    if (typeof r.can_manage_pricing === 'boolean') {
      window.CLAR_CAN_MANAGE_RECEIPT_PRICING = r.can_manage_pricing;
    }
    const canManage = canSetReceiptMarkup();
    const rows = groupPricingQueueRows(r.data || []);
    if (typeof window.refreshSidebarNotificationBadges === 'function') {
      window.refreshSidebarNotificationBadges();
    }
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted">No complete deliveries waiting for pricing confirmation.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(it => {
      const id = parseInt(it.receipt_item_id, 10) || 0;
      const groupedIds = (it._grouped_ids || [id]).filter(Boolean).join(',');
      const cost = parseFloat(it.purchase_price || 0) || 0;
      const status = it.pricing_status || 'Waiting for Pricing';
      const dis = canManage ? '' : 'disabled';
      return `<tr data-receipt-item-id="${id}" data-grouped-ids="${groupedIds}" data-cost="${cost}">
        <td><strong>${esc(it.product_name || '—')}</strong>${receiptLotsSummaryHtml(it)}</td>
        <td style="font-family:monospace">${esc(it.sku || '—')}</td>
        <td>${esc(it.unit_of_measure || 'pcs')}</td>
        <td><strong class="text-primary" style="font-family:monospace">${esc(it.po_number || '—')}</strong></td>
        <td>${esc(it.supplier_name || '—')}</td>
        <td class="mq-cost">${fmtMoney(cost)}</td>
        <td class="mq-pct-cell"><input type="number" step="0.01" min="0" class="form-control form-control-sm mq-markup" value="" placeholder="" ${dis} inputmode="decimal" oninput="syncMarkupQueueRow(this.closest('tr'), 'markup')"></td>
        <td class="mq-sell-cell"><input type="number" step="0.01" min="0" class="form-control form-control-sm mq-sell" value="" placeholder="" ${dis} inputmode="decimal" oninput="syncMarkupQueueRow(this.closest('tr'), 'sell')"></td>
        <td><span class="badge badge-yellow">${esc(status)}</span></td>
        <td>${canManage
          ? `<button type="button" class="btn btn-sm btn-success" onclick="confirmPurchaserMarkup(${id}, this)">Confirm Pricing</button>`
          : `<span class="small text-muted">View only</span>`}</td>
      </tr>`;
    }).join('');
    tbody.querySelectorAll('tr[data-receipt-item-id]').forEach(row => syncMarkupQueueRow(row));
    bindPurchaserMarkupEditors();
  });
}

function referenceSellingPrice(cost, applyMarkup, markupPct, applyTax, taxPct) {
  const c = parseFloat(cost || 0) || 0;
  const mk = applyMarkup ? (parseFloat(markupPct || 0) || 0) : 0;
  const after = applyMarkup ? (c + (c * (mk / 100))) : c;
  const tx = applyTax ? (parseFloat(taxPct || 0) || 0) : 0;
  const taxAmt = applyTax ? (after * (tx / 100)) : 0;
  return (after + taxAmt).toFixed(2);
}

function sellingPriceFromMarkupAmount(cost, markupAmount) {
  const c = parseFloat(cost || 0) || 0;
  const m = parseFloat(markupAmount || 0) || 0;
  return c + m;
}

function sellingPriceFromMarkupPercent(cost, markupPct) {
  const c = parseFloat(cost || 0) || 0;
  const p = parseFloat(markupPct || 0) || 0;
  return Math.round(c * (1 + p / 100) * 100) / 100;
}

const MARKUP_QUEUE_MAX_PERCENT = 999.99;

function parseMarkupPriceInput(raw) {
  const s = String(raw ?? '').trim();
  if (s === '') {
    return { blank: true, ok: true, amount: 0 };
  }
  if (/[eE]/.test(s) || !Number.isFinite(parseFloat(s)) || Number.isNaN(Number(s))) {
    return { blank: false, ok: false, amount: 0 };
  }
  const amount = parseFloat(s);
  if (!Number.isFinite(amount) || amount < 0 || amount > 9999999.99) {
    return { blank: false, ok: false, amount: 0 };
  }
  return { blank: false, ok: true, amount: amount };
}

function syncMarkupQueueRow(row, source) {
  if (!row || !row.querySelector) {
    row = row && row.closest ? row.closest('tr') : null;
  }
  if (!row) return;
  const mkEl = row.querySelector('.mq-markup');
  const sell = row.querySelector('.mq-sell');
  const canManage = canSetReceiptMarkup();
  if (mkEl) {
    mkEl.hidden = false;
    mkEl.style.display = 'block';
    mkEl.disabled = !canManage;
  }
  if (sell) {
    sell.hidden = false;
    sell.style.display = 'block';
    sell.disabled = !canManage;
    sell.readOnly = false;
    sell.removeAttribute('readonly');
    sell.style.background = '';
  }
  const cost = parseFloat(row.dataset.cost || '0') || 0;
  const fromSell = source === 'sell';
  if (fromSell) {
    const parsedSell = parseMarkupPriceInput(sell ? sell.value : '');
    if (!mkEl) return;
    if (parsedSell.blank) {
      mkEl.value = '';
      return;
    }
    if (!parsedSell.ok || cost <= 0) return;
    const markupPct = ((parsedSell.amount - cost) / cost) * 100;
    if (markupPct < 0 || markupPct > MARKUP_QUEUE_MAX_PERCENT) return;
    mkEl.value = markupPct.toFixed(2);
    return;
  }
  const rawMk = mkEl ? String(mkEl.value).trim() : '';
  const parsed = parseMarkupPriceInput(rawMk);
  if (sell) {
    if (parsed.blank || !parsed.ok || parsed.amount > MARKUP_QUEUE_MAX_PERCENT) {
      sell.value = '';
    } else {
      sell.value = sellingPriceFromMarkupPercent(cost, parsed.amount).toFixed(2);
    }
  }
}
window.syncMarkupQueueRow = syncMarkupQueueRow;

function bindPurchaserMarkupEditors() {
  const tbody = document.getElementById('markupQueueBody');
  if (!tbody) return;
  if (tbody.dataset.mqBound === '1') return;
  tbody.dataset.mqBound = '1';
  const syncFromEvent = function (e) {
    const el = e.target;
    if (!el || !el.closest) return;
    if (!el.closest('.mq-markup, .mq-sell')) return;
    const row = el.closest('tr[data-receipt-item-id]') || el.closest('tr');
    if (row) syncMarkupQueueRow(row, el.classList.contains('mq-sell') ? 'sell' : 'markup');
  };
  tbody.addEventListener('input', syncFromEvent);
  tbody.addEventListener('change', syncFromEvent);
}

function confirmPurchaserMarkup(receiptItemId, btn) {
  if (!canSetReceiptMarkup()) {
    pToast('You do not have permission to confirm pricing.', 'warn');
    return;
  }
  if (btn && btn.dataset.busy === '1') return;
  const row = btn ? btn.closest('tr') : document.querySelector(`#markupQueueBody tr[data-receipt-item-id="${receiptItemId}"]`);
  if (!row) return;
  const mkEl = row.querySelector('.mq-markup');
  const cost = parseFloat(row.dataset.cost || '0') || 0;
  const raw = mkEl ? String(mkEl.value).trim() : '';
  const parsed = parseMarkupPriceInput(raw);
  if (parsed.blank) {
    pToast('Markup is required before this product can continue.', 'warn');
    mkEl?.focus();
    return;
  }
  if (!parsed.ok || parsed.amount > MARKUP_QUEUE_MAX_PERCENT) {
    pToast(`Enter a valid Markup % between 0 and ${MARKUP_QUEUE_MAX_PERCENT}. Decimals are allowed.`, 'warn');
    mkEl?.focus();
    return;
  }
  const selling = sellingPriceFromMarkupPercent(cost, parsed.amount);
  const groupedIds = String(row.dataset.groupedIds || receiptItemId)
    .split(',')
    .map(s => parseInt(s, 10) || 0)
    .filter((gid, i, arr) => gid > 0 && arr.indexOf(gid) === i);
  const ids = groupedIds.length ? groupedIds : [parseInt(receiptItemId, 10) || 0].filter(Boolean);
  if (btn) {
    btn.dataset.busy = '1';
    btn.disabled = true;
  }
  const confirmNext = (index) => {
    if (index >= ids.length) {
      pToast('Pricing confirmed. Inventory was not increased.');
      loadPurchaserMarkupQueue();
      if (typeof loadStockInQueue === 'function') loadStockInQueue();
      if (typeof loadPendingAdminStockIn === 'function') loadPendingAdminStockIn();
      return;
    }
    pApi({
      method: 'POST',
      body: {
        action: 'confirm_purchaser_markup',
        receipt_item_id: ids[index],
        markup_percent: raw,
        selling_price: selling,
        reference_selling_price: selling
      }
    }, r => {
      if (!r.success) {
        if (btn) {
          btn.dataset.busy = '0';
          btn.disabled = false;
        }
        pToast(r.message || 'Failed to confirm pricing', 'danger');
        return;
      }
      confirmNext(index + 1);
    });
  };
  confirmNext(0);
}

function stockInPoNumber(rec) {
  return String((rec && rec.po_number) || '').trim() || '—';
}

function stockInPoKey(rec) {
  return stockInPoNumber(rec) + '||' + String((rec && rec.queue_status) || '').trim();
}

function stockInPoStatusKey(rec) {
  return stockInPoKey(rec);
}

function groupStockInQueueByPo(rows) {
  const groups = [];
  const index = {};
  (rows || []).forEach(rec => {
    const key = stockInPoKey(rec);
    if (index[key] == null) {
      index[key] = groups.length;
      groups.push([]);
    }
    groups[index[key]].push(rec);
  });
  const out = [];
  groups.forEach(list => list.forEach(rec => out.push(rec)));
  return out;
}

function stockInScannedBarcodeHtml(it) {
  const codes = stockInUnionCodes(it && it.scanned_barcodes, []);
  const shown = codes.length ? codes : stockInUnionCodes(it && it.barcode ? [it.barcode] : [], []);
  if (!shown.length) return '';
  const visible = shown.slice(0, 8).map(code => `<div>${esc(code)}</div>`).join('');
  const more = shown.length > 8 ? `<div>+ ${shown.length - 8} more</div>` : '';
  return `<div class="small text-muted">${visible}${more}</div>`;
}

function stockInGroupedItemHtml(rec) {
  const it = (rec && rec.items && rec.items[0]) || {};
  const product = esc(it.product_name || 'Item');
  const bc = stockInScannedBarcodeHtml(it);
  const lots = Array.isArray(it.lots) && it.lots.length
    ? it.lots
    : [{
        lot_number: it.manufacturer_lot || '',
        quantity: it.quantity_good,
        expiry_date: it.expiry_date,
        no_expiry: it.expiry_date ? 0 : 1
      }];
  const usable = lots.filter(l => (parseInt(l.quantity, 10) || 0) > 0 || String(l.lot_number || '').trim());
  const list = usable.length ? usable : lots;
    return list.map((l, i) => {
    const lot = esc(String(l.lot_number || '').trim() || 'N/A');
    const qty = parseInt(l.quantity, 10) || 0;
    const exp = l.no_expiry || !l.expiry_date ? 'No Expiry' : fmtDate(l.expiry_date);
    return `<div class="small"${i ? ' style="margin-top:6px"' : ''}>
      <div><strong>${product}</strong></div>
      ${i === 0 ? bc : ''}
      <div>Lot ${lot}</div>
      <div>${esc(exp)}</div>
      <div>Qty ${qty}</div>
    </div>`;
  }).join('');
}

function stockInPoReadyReceiptIds(group) {
  const seen = {};
  const ids = [];
  (group || []).forEach(rec => {
    if (rec.queue_status !== 'Ready for Stock-In') return;
    const id = parseInt(rec.id, 10) || 0;
    if (id <= 0 || seen[id]) return;
    seen[id] = true;
    ids.push(id);
  });
  return ids;
}

function placeStockInPoCheckboxes() {
  const tbody = document.getElementById('stockInQueueBody');
  if (!tbody || tbody.dataset.siqPlacing === '1') return;
  const groups = {};
  tbody.querySelectorAll('tr[data-po-key]').forEach(tr => {
    const key = tr.getAttribute('data-po-key');
    if (!groups[key]) groups[key] = [];
    groups[key].push(tr);
  });
  tbody.dataset.siqPlacing = '1';
  Object.keys(groups).forEach(key => {
    const rows = groups[key];
    let input = null;
    rows.forEach(tr => {
      const found = tr.querySelector('.siq-check');
      if (found) input = found;
    });
    if (!input) return;
    const visible = rows.find(tr => tr.style.display !== 'none' && tr.getAttribute('data-queue-status') === 'Ready for Stock-In');
    const target = visible && visible.querySelector('.siq-check-cell');
    if (target && input.parentElement !== target) target.appendChild(input);
  });
  tbody.dataset.siqPlacing = '0';
}

function bindStockInCheckboxPlacement() {
  const tbody = document.getElementById('stockInQueueBody');
  if (!tbody || tbody.dataset.siqCheckObserver === '1') return;
  tbody.dataset.siqCheckObserver = '1';
  new MutationObserver(() => placeStockInPoCheckboxes()).observe(tbody, {
    subtree: true,
    attributes: true,
    attributeFilter: ['style']
  });
}

function renderStockInQueueTable() {
  const tbody = document.getElementById('stockInQueueBody');
  if (!tbody) return;
  if (!_stockInKeepScan) _stockInScanPinned = false;
  _stockInKeepScan = false;
  const scan = stockInPinnedScan();
  const rows = groupStockInQueueByPo(filterStockInQueueRows(_stockInQueueRows));
  if (!rows.length) {
    const empty = scan
      ? ('No Stock-In item for barcode ' + scan + '.')
      : ((_stockInQueueRows || []).length
      ? 'No items match the current filters.'
      : 'No items waiting for Stock-In.');
    tbody.innerHTML = `<tr><td colspan="13" class="text-center py-4 text-muted">${empty}</td></tr>`;
    return;
  }
  const groups = [];
  rows.forEach(rec => {
    const key = stockInPoKey(rec);
    if (!groups.length || stockInPoKey(groups[groups.length - 1][0]) !== key) groups.push([]);
    groups[groups.length - 1].push(rec);
  });
  const readyByPo = {};
  rows.forEach(rec => {
    if (rec.queue_status !== 'Ready for Stock-In') return;
    const po = stockInPoNumber(rec);
    stockInRowReceiptIds(rec).forEach(id => {
      if (!readyByPo[po]) readyByPo[po] = [];
      if (readyByPo[po].indexOf(id) === -1) readyByPo[po].push(id);
    });
  });
  const html = [];
  const poCheckboxPlaced = {};
  groups.forEach((group, groupIndex) => {
    group.forEach((rec, index) => {
      const rid = parseInt(rec.id, 10) || 0;
      const itemId = parseInt(rec.receipt_item_id, 10) || 0;
      const poNum = stockInPoNumber(rec);
      const actionHtml = rec.queue_status === 'Stocked'
        ? `<button type="button" class="btn btn-sm btn-outline-secondary js-view-stock-in" data-receipt-id="${rid}">View</button>`
        : `<button type="button" class="btn btn-sm btn-outline-primary js-view-stock-in" data-receipt-id="${rid}">View</button>`;
      const items = rec.items || [];
      const good = items.reduce((s, it) => s + (parseInt(it.quantity_good, 10) || 0), 0);
      const ordered = items.reduce((m, it) => Math.max(m, parseInt(it.quantity_ordered, 10) || 0), 0);
      const cappedGood = ordered > 0 ? Math.min(ordered, good) : good;
      const shortage = Math.max(0, ordered - cappedGood);
      const readySince = fmtDateTime(rec.ready_since);
      const searchBlob = stockInSearchText(rec);
      const poStart = groupIndex > 0 && index === 0;
      const poStatusKey = stockInPoStatusKey(rec);
      const readyIds = rec.queue_status === 'Ready for Stock-In' ? (readyByPo[poNum] || []) : [];
      const showCheck = !poCheckboxPlaced[poStatusKey] && rec.queue_status === 'Ready for Stock-In' && readyIds.length > 0;
      if (showCheck) poCheckboxPlaced[poStatusKey] = true;
      const checkCell = showCheck
        ? `<input type="checkbox" class="siq-check" value="${readyIds.join(',')}" aria-label="Select ${esc(poNum)}">`
        : '';
      html.push(`<tr data-po-key="${esc(poStatusKey)}" data-receipt-id="${rid}" data-receipt-item-id="${itemId}" data-queue-status="${esc(rec.queue_status)}" data-search="${esc(searchBlob)}"${poStart ? ' class="siq-po-start"' : ''}>
      <td class="siq-check-cell">${checkCell}</td>
      <td><strong class="text-primary" style="font-family:monospace">${esc(rec.po_number || rec.receipt_number || '—')}</strong></td>
      <td><span class="small text-muted">${esc(rec.receipt_number || '—')}</span></td>
      <td>${esc(rec.supplier_name||'—')}</td>
      <td>${stockInGroupedItemHtml(rec)}</td>
      <td class="siq-qty">${good}</td>
      <td class="siq-qty">${shortage}</td>
      <td class="siq-money">${siQueuePriceStack(rec, 'purchase_price', fmtMoney)}</td>
      <td class="siq-money">${stockInQueueMarkupStack(rec)}</td>
      <td class="siq-money"><strong>${siQueuePriceStack(rec, 'selling_price', fmtMoney)}</strong></td>
      <td class="siq-workflow">${stockInQueueStatusBadge(rec.queue_status)}</td>
      <td class="siq-ready">${readySince}<div class="small text-muted">${esc(rec.received_by_name||'')}</div></td>
      <td class="siq-actions">${actionHtml}</td>
    </tr>`);
    });
  });
  tbody.innerHTML = html.join('');
  bindStockInCheckboxPlacement();
  placeStockInPoCheckboxes();
}
window.renderStockInQueueTable = renderStockInQueueTable;

function loadStockInQueue(silent) {
  const tbody = document.getElementById('stockInQueueBody');
  if (!tbody) return;
  if (!silent) {
    tbody.innerHTML = '<tr class="loading-row"><td colspan="13" class="text-center py-3 text-muted">Loading…</td></tr>';
  }
  pApi({ query: { action: 'get_stock_in_queue' } }, r => {
    if (!r.success) {
      tbody.innerHTML = `<tr><td colspan="13" class="text-danger text-center py-3">${esc(r.message||'Failed')}</td></tr>`;
      return;
    }
    _siCaps = {
      can_set_markup: !!r.can_set_markup || canSetReceiptMarkup(),
      can_grant_stock_in: !!r.can_grant_stock_in || canGrantStockIn(),
      can_execute_stock_in: !!r.can_execute_stock_in || canExecuteStockIn(),
      can_receive: !!r.can_receive || canReceivePurchaseOrders()
    };
    const pendingScan = clarBarcodeKey(window._pendingStockInScan);
    const flat = flattenStockInQueueRows(r.data || []);
    const sig = liveRowsSignature(flat, ['id', 'receipt_item_id', 'queue_status', 'received_at', 'ready_since', 'quantity_good']);
    _stockInQueueRows = flat;
    if (silent && sig === _stockInSig && !pendingScan) return;
    _stockInSig = sig;
    renderStockInQueueTable();
    if (pendingScan) {
      window._pendingStockInScan = '';
      applyBarcodeToStockInQueue(pendingScan);
    }
  });
}

function pendingStockInSelling(cost, markup) {
  const c = parseFloat(cost || 0) || 0;
  const m = parseFloat(markup || 0) || 0;
  return (c + (c * (m / 100))).toFixed(2);
}

function loadPendingAdminStockIn() {
  const tbody = document.getElementById('pendingStockInBody');
  if (tbody) {
    tbody.innerHTML = '<tr class="loading-row"><td colspan="13" style="text-align:center;padding:32px;color:var(--text-tertiary);">Loading pending Stock In items…</td></tr>';
  }
  pApi({ query: { action: 'get_pending_stock_in_items' } }, r => {
    const rows = (r && r.success) ? (r.data || []) : [];
    if (typeof syncApprovedStockInProductOptions === 'function') syncApprovedStockInProductOptions(rows);
    if (!tbody) return;
    if (!r.success) {
      tbody.innerHTML = `<tr><td colspan="13" style="text-align:center;padding:32px;color:#ef4444;">${esc(r.message || 'Failed to load pending Stock In items')}</td></tr>`;
      return;
    }
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:40px;color:var(--text-tertiary);"><i class="bi bi-box-seam" style="font-size:22px;display:block;margin-bottom:8px;"></i>No received items waiting for Stock In.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(it => {
      const id = parseInt(it.receipt_item_id, 10) || 0;
      const cost = parseFloat(it.purchase_price || 0) || 0;
      const markup = parseFloat(it.markup_percent || 0) || 0;
      const taxPct = parseFloat(it.tax_percent || 0) || 0;
      const sell = parseFloat(it.selling_price || it.reference_selling_price || 0) || parseFloat(pendingStockInSelling(cost, markup));
      const expirable = !!it.expirable;
      const lots = Array.isArray(it.lots) ? it.lots : [];
      const firstLot = lots.find(l => !l.no_expiry && l.expiry_date) || lots[0] || {};
      const expVal = firstLot.expiry_date || it.expiry_date || '';
      const noExpiry = !!(firstLot.no_expiry || (!expirable && !expVal));
      const sku = esc(it.sku || '—');
      const details = esc(it.product_details || '');
      return `<tr data-receipt-item-id="${id}" data-cost="${cost}" data-expirable="${expirable ? '1' : '0'}" data-no-expiry="${noExpiry ? '1' : '0'}" data-date-received="${esc(it.date_received || '')}" data-sku="${esc(it.sku || '')}" data-barcode="${esc(it.barcode || '')}">
        <td class="psi-product">
          <strong>${esc(it.product_name || '—')}</strong>
          <div class="small text-muted">${sku}${it.barcode ? ' · ' + esc(it.barcode) : ''}${details ? ' · ' + details : ''}</div>
        </td>
        <td>
          <strong class="text-primary" style="font-family:monospace">${esc(it.po_number || '—')}</strong>
          <div class="small text-muted">${esc(it.supplier || '—')}</div>
          <div class="small text-muted">${esc(it.reference_number || '')}</div>
        </td>
        <td>${fmtMoney(cost)}</td>
        <td><strong>${parseInt(it.quantity_good, 10) || 0}</strong></td>
        <td>${receiptDiscBreakdownHtml(it)}</td>
        <td>${esc(it.batch_number || '—')}</td>
        <td>${fmtDate(it.date_received)}</td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm psi-markup" value="${markup}" readonly></td>
        <td>${Number(taxPct).toFixed(2)}%</td>
        <td><input type="text" class="form-control form-control-sm psi-sell" value="${fmtMoney(sell)}" readonly style="background:var(--bg-secondary,#f8fafc)"></td>
        <td>${receiptLotsSummaryHtml(it)}<input type="hidden" class="psi-expiry" value="${esc(expVal)}"></td>
        <td><span class="badge badge-yellow">Pending</span></td>
        <td>${canExecuteStockIn()
          ? `<button type="button" class="btn btn-sm btn-primary" onclick="confirmPendingStockIn(${id}, this)">Stock In</button>`
          : `<button type="button" class="btn btn-sm btn-outline-primary js-view-stock-in" data-receipt-id="${parseInt(it.receipt_id, 10) || 0}">View</button>`}</td>
      </tr>`;
    }).join('');
    if (typeof syncApprovedStockInProductOptions === 'function') syncApprovedStockInProductOptions(rows);
  });
}

function bindPendingStockInEditors() {
  const tbody = document.getElementById('pendingStockInBody');
  if (!tbody || tbody.dataset.psiBound === '1') return;
  tbody.dataset.psiBound = '1';
  tbody.addEventListener('input', function (e) {
    const mk = e.target.closest('.psi-markup');
    if (!mk) return;
    const row = mk.closest('tr');
    if (!row) return;
    let m = parseFloat(mk.value || '0');
    if (Number.isNaN(m) || m < 0) {
      m = 0;
      mk.value = '0';
    }
    const cost = parseFloat(row.dataset.cost || '0') || 0;
    const sell = row.querySelector('.psi-sell');
    if (sell) sell.value = fmtMoney(pendingStockInSelling(cost, m));
  });
}

function confirmPendingStockIn(receiptItemId, btn) {
  if (!canExecuteStockIn()) {
    pToast('Only Warehouse Head can complete Stock-In after Purchaser markup.', 'warn');
    return;
  }
  const row = btn ? btn.closest('tr') : document.querySelector(`tr[data-receipt-item-id="${receiptItemId}"]`);
  if (!row) return;
  const mkEl = row.querySelector('.psi-markup');
  const expEl = row.querySelector('.psi-expiry');
  const sellEl = row.querySelector('.psi-sell');
  let markup = parseFloat(mkEl ? mkEl.value : '0');
  if (Number.isNaN(markup) || markup < 0) {
    pToast('Markup cannot be negative.', 'warn');
    if (mkEl) mkEl.focus();
    return;
  }
  const cost = parseFloat(row.dataset.cost || '0') || 0;
  const sellRaw = sellEl ? String(sellEl.value).replace(/[^0-9.\-]/g, '') : '';
  const selling = parseFloat(sellRaw);
  const expirable = row.dataset.expirable === '1';
  const expiry = (expEl && expEl.value) ? expEl.value : '';
  const dateReceived = row.dataset.dateReceived || '';
  if (expirable && !expiry && row.dataset.noExpiry !== '1') {
    pToast('Expiry Date is required for this expirable product.', 'warn');
    return;
  }
  if (expiry && dateReceived && expiry < dateReceived) {
    pToast('Expiry Date cannot be earlier than Date Received.', 'warn');
    if (expEl) expEl.focus();
    return;
  }
  if (Number.isFinite(selling) && selling < 0) {
    pToast('Reference Selling Price cannot be negative.', 'warn');
    return;
  }
  if (btn && btn.dataset.busy === '1') return;
  if (btn) {
    btn.dataset.busy = '1';
    btn.disabled = true;
  }
  const body = {
    action: 'confirm_stock_in_item',
    receipt_item_id: receiptItemId,
    markup_percent: markup,
    expiry_date: expiry
  };
  if (Number.isFinite(selling)) body.selling_price = selling;
  pApi({
    method: 'POST',
    body
  }, r => {
    if (!r.success) {
      if (btn) {
        btn.dataset.busy = '0';
        btn.disabled = false;
      }
      pToast(r.message || 'Stock In failed', 'danger');
      return;
    }
    pToast(r.message || 'Stocked in');
    if (sellEl) sellEl.value = fmtMoney(r.selling_price != null ? r.selling_price : selling);
    loadPendingAdminStockIn();
    if (typeof loadStockInQueue === 'function') loadStockInQueue();
  });
}

document.addEventListener('DOMContentLoaded', function () {
  bindPendingStockInEditors();
  bindPurchaserMarkupEditors();
  if (document.getElementById('pendingStockInBody')) loadPendingAdminStockIn();
  if (document.getElementById('markupQueueBody')) loadPurchaserMarkupQueue();
});

function openStockInDetail(id) {
  id = parseInt(id, 10) || 0;
  const body = document.getElementById('stockInDetailBody');
  const footer = document.getElementById('stockInDetailFooter');
  const title = document.getElementById('stockInDetailTitle');
  const modal = document.getElementById('stockInDetailModal');
  if (!modal || !body) {
    if (typeof pToast === 'function') pToast('Stock-in detail window is missing. Refresh the page.', 'danger');
    return;
  }
  body.innerHTML = '<div class="text-muted py-3">Loading…</div>';
  if (footer) footer.innerHTML = '<button type="button" class="btn btn-outline-secondary" onclick="closeModal(\'stockInDetailModal\')">Close</button>';
  modal.style.display = 'flex';
  modal.classList.add('open');
  if (typeof openModal === 'function') openModal('stockInDetailModal');
  if (id <= 0) {
    body.innerHTML = '<div class="text-danger">This row has no receipt id. Refresh Stock-In Queue and try again.</div>';
    return;
  }
  pApi({ query: { action: 'get_receipt_detail', id } }, r => {
    if (!r.success || !r.data) {
      body.innerHTML = `<div class="text-danger">${esc(r.message||'Not found')}</div>`;
      return;
    }
    const rec = r.data;
    const canMarkup = !!(r.data.can_set_markup ?? _siCaps.can_set_markup ?? canSetReceiptMarkup());
    const canGrant = !!(r.data.can_grant_stock_in ?? _siCaps.can_grant_stock_in ?? canGrantStockIn());
    const canExec = !!(r.data.can_execute_stock_in ?? _siCaps.can_execute_stock_in ?? canExecuteStockIn());
    if (title) title.innerHTML = `<i class="bi bi-box-seam"></i> ${esc(rec.receipt_number)} — ${esc(rec.workflow_status||rec.status)}`;
    const items = rec.items || [];
    const pendingMarkup = isPendingPurchaserMarkup(rec.workflow_status);
    const editable = false;
    body.innerHTML = `
      <div class="mb-2 small">
        <div>PO <strong>${esc(rec.po_number)}</strong> · Supplier <strong>${esc(rec.supplier_name||'—')}</strong></div>
        <div class="text-muted">Received by ${esc(rec.received_by_name||'—')} on ${fmtDateTime(rec.received_at || rec.date_received)}${rec.markup_by_name ? ` · Markup by ${esc(rec.markup_by_name)}` : ''}${rec.approved_by_name ? ` · Approved by ${esc(rec.approved_by_name)}` : ''}</div>
        ${rec.stock_in_number ? `<div class="text-success">Stock-In #: <strong>${esc(rec.stock_in_number)}</strong></div>` : ''}
      </div>
      <div class="table-container system-table-scroll">
        <table class="modern-table">
          <thead><tr><th>Product</th><th>Ordered</th><th>Good</th><th>Replacement</th><th>Purchase Price</th><th>Markup %</th><th>Reference Selling Price</th><th>Line Status</th></tr></thead>
          <tbody>
            ${items.map(it => {
              const cost = parseFloat(it.cost_price || it.unit_price || 0);
              const sell = parseFloat(it.selling_price || it.reference_selling_price || 0);
              const markupLabel = Number(it.apply_markup) === 1 ? stockInQueueMarkupLabel(it) : 'Not Applied';
              const ordered = parseInt(it.quantity_ordered,10)||0;
              const damaged = parseInt(it.quantity_damaged,10)||0;
              const good = parseInt(it.quantity_good,10)||0;
              const maxGood = good;
              return `<tr data-item-id="${it.id}" data-ordered="${ordered}" data-damaged="${damaged}">
                <td>${esc(it.product_name)}<br><span class="small text-muted">${esc(it.sku||'')}</span>${stockInScannedBarcodeHtml(it)}${receiptLotsSummaryHtml(it)}</td>
                <td class="si-ordered">${ordered}</td>
                <td>${editable
                  ? `<input type="number" step="1" min="0" max="${maxGood}" class="form-control form-control-sm si-good" value="${good}" style="width:90px" readonly>`
                  : good}</td>
                <td class="si-damaged">${receiptDiscBreakdownHtml(it)}</td>
                <td>${editable
                  ? `<input type="number" step="0.01" min="0" class="form-control form-control-sm si-cost" value="${cost.toFixed(2)}" style="width:110px" oninput="recalcSiFromCost(this)">`
                  : fmtMoney(cost)}</td>
                <td>${esc(markupLabel)}</td>
                <td>${fmtMoney(sell)}</td>
                <td>${esc(it.line_stock_status||'—')}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
      <p class="small text-muted mt-2 mb-0">Only scanned Good quantity can be Stocked In. Replacement never enters inventory.</p>`;
    let ft = '<button class="btn btn-outline-secondary" onclick="closeModal(\'stockInDetailModal\')">Close</button>';
    if (pendingMarkup) {
      ft += ` <span class="text-warning small align-self-center fw-semibold">Waiting for Pricing</span>`;
    } else if (isReadyForWarehouseStockIn(rec.workflow_status) && canExec) {
      ft += ` <button type="button" class="btn btn-success" onclick="executeWarehouseStockIn(${id})"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In to Inventory</button>`;
    } else if (isReadyForWarehouseStockIn(rec.workflow_status) && !canExec) {
      ft += ` <span class="text-muted small align-self-center">Waiting for Warehouse Head Stock-In</span>`;
    }
    if (footer) footer.innerHTML = ft;
  });
}

if (!window._stockInViewClickBound) {
  window._stockInViewClickBound = true;
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-view-stock-in');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    openStockInDetail(btn.getAttribute('data-receipt-id'));
  });
}

function recalcSiFromCost(costInput) {
  const row = costInput.closest('tr');
  const cost = parseFloat(costInput.value || 0);
  const markup = row?.querySelector('.si-markup');
  if (markup) recalcSiSell(markup, cost);
}

function recalcSiMissing(goodInput) {
  const row = goodInput.closest('tr');
  if (!row) return;
  const ordered = parseInt(row.dataset.ordered || '0', 10);
  const damaged = parseInt(row.dataset.damaged || '0', 10);
  let good = parseInt(goodInput.value || '0', 10);
  if (Number.isNaN(good) || good < 0) good = 0;
  const maxGood = Math.max(0, ordered - damaged);
  if (good > maxGood) {
    good = maxGood;
    goodInput.value = String(maxGood);
  }
  const missingEl = row.querySelector('.si-missing');
  if (missingEl) missingEl.textContent = String(Math.max(0, ordered - damaged - good));
}

function recalcSiSell(markupInput, cost) {
  const row = markupInput.closest('tr');
  const sell = row?.querySelector('.si-sell');
  const costEl = row?.querySelector('.si-cost');
  const useCost = costEl ? parseFloat(costEl.value || 0) : cost;
  if (!sell) return;
  const m = parseFloat(markupInput.value || 0);
  sell.value = (useCost + (useCost * (m / 100))).toFixed(2);
}

function recalcSiMarkup(sellInput, cost) {
  const row = sellInput.closest('tr');
  const markup = row?.querySelector('.si-markup');
  const costEl = row?.querySelector('.si-cost');
  const useCost = costEl ? parseFloat(costEl.value || 0) : cost;
  if (!markup) return;
  const sell = parseFloat(sellInput.value || 0);
  markup.value = (useCost > 0 ? ((sell - useCost) / useCost) * 100 : 0).toFixed(2);
}

function saveReceiptMarkup(receiptId, submitForApproval) {
  const items = Array.from(document.querySelectorAll('#stockInDetailBody tr[data-item-id]')).map(tr => ({
    id: parseInt(tr.dataset.itemId, 10),
    quantity_good: tr.querySelector('.si-good')?.value ?? '',
    cost_price: tr.querySelector('.si-cost')?.value || '',
    markup_percent: tr.querySelector('.si-markup')?.value || 0,
    selling_price: tr.querySelector('.si-sell')?.value || 0
  }));
  const body = {
    action: 'set_receipt_markup',
    receipt_id: receiptId,
    items: JSON.stringify(items)
  };
  if (submitForApproval) body.submit_for_approval = 1;
  pApi({ method: 'POST', body }, r => {
    if (r.success) {
      pToast(r.message || 'Markup saved');
      closeModal('stockInDetailModal');
      loadStockInQueue();
      loadPurchaseOrders();
      loadDashboard();
    } else pToast(r.message || 'Failed', 'danger');
  });
}

function markReadyForStockIn(receiptId) {
  saveReceiptMarkup(receiptId, true);
}

function finalizeStockIn(receiptId) {
  pToast('Admin grant is no longer required. Warehouse Head stocks in after Purchaser markup.', 'info');
}

function executeWarehouseStockIn(receiptId) {
  if (!canExecuteStockIn() && !_siCaps.can_execute_stock_in) {
    pToast('Only Warehouse Head can complete Stock-In after Purchaser markup.', 'warn');
    return;
  }
  const run = () => {
    pApi({ method: 'POST', body: { action: 'execute_stock_in', receipt_id: receiptId } }, r => {
      if (r.success) {
        pToast(r.message || `Stocked in ${r.stocked_qty || 0} good unit(s)` + (r.stock_in_number ? ` (${r.stock_in_number})` : ''));
        closeModal('stockInDetailModal');
        loadStockInQueue();
        loadPurchaseOrders();
        if (typeof loadPendingAdminStockIn === 'function') loadPendingAdminStockIn();
        if (typeof loadCatalog === 'function') loadCatalog();
        if (typeof loadLocationStock === 'function') loadLocationStock();
      } else pToast(r.message || 'Stock-in failed', 'danger');
    });
  };
  if (window.Swal) {
    Swal.fire({
      title: 'Stock In to Inventory?',
      text: 'Only good quantities will be added. Replacement units stay excluded.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Stock In',
      didOpen: () => {
        const c = Swal.getContainer();
        if (c) c.style.zIndex = '20000';
      }
    }).then(res => { if (res.isConfirmed) run(); });
  } else {
    appConfirm('Stock good quantities into available inventory?').then(ok => { if (ok) run(); });
  }
}

function toggleStockInSelectAll(master) {
  document.querySelectorAll('#stockInQueueBody .siq-check').forEach(cb => {
    const tr = cb.closest('tr');
    if (tr && tr.style.display === 'none') return;
    if (cb.disabled) return;
    cb.checked = !!(master && master.checked);
  });
}

function selectedStockInReceiptIds() {
  const seen = {};
  const ids = [];
  document.querySelectorAll('#stockInQueueBody .siq-check:checked').forEach(cb => {
    String(cb.value || '').split(',').forEach(part => {
      const id = parseInt(part, 10) || 0;
      if (id <= 0 || seen[id]) return;
      seen[id] = true;
      ids.push(id);
    });
  });
  return ids;
}

function confirmSelectedStockIn() {
  if (!canExecuteStockIn() && !_siCaps.can_execute_stock_in) {
    pToast('Only Warehouse Head can complete Stock-In after Purchaser markup.', 'warn');
    return;
  }
  const ids = selectedStockInReceiptIds();
  if (!ids.length) {
    pToast('Select one or more ready receipts, then Confirm Stock-In.', 'warn');
    return;
  }
  const run = () => {
    pApi({ method: 'POST', body: { action: 'execute_stock_in_batch', receipt_ids: JSON.stringify(ids) } }, r => {
      if (r.success) {
        pToast(r.message || 'Stock-In completed');
        const master = document.getElementById('stockInSelectAll');
        if (master) master.checked = false;
        loadStockInQueue();
        loadPurchaseOrders();
        if (typeof loadPendingAdminStockIn === 'function') loadPendingAdminStockIn();
        if (typeof loadCatalog === 'function') loadCatalog();
        if (typeof loadLocationStock === 'function') loadLocationStock();
      } else pToast(r.message || 'Stock-in failed', 'danger');
    });
  };
  if (window.Swal) {
    Swal.fire({
      title: 'Confirm Stock-In?',
      text: ids.length + ' selected receipt(s) will be stocked in automatically. Only good quantities are added.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Confirm Stock-In'
    }).then(res => { if (res.isConfirmed) run(); });
  } else {
    appConfirm('Stock in ' + ids.length + ' selected receipt(s)?').then(ok => { if (ok) run(); });
  }
}

function canProcessRts() {
  return !!(isProcurementAdmin() || isProcurementWarehouseHead());
}

function loadDamageReturns() {
  const tbody = document.getElementById('damageReturnsBody');
  const rtsBody = document.getElementById('supplierRtsBody');
  if (tbody) tbody.innerHTML = '<tr class="loading-row"><td colspan="9" class="text-center py-3 text-muted">Loading…</td></tr>';
  if (rtsBody) rtsBody.innerHTML = '<tr class="loading-row"><td colspan="10" class="text-center py-3 text-muted">Loading…</td></tr>';
  if (tbody) {
    pApi({ query: { action: 'get_damage_returns' } }, r => {
      if (!r.success) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-danger text-center py-3">${esc(r.message||'Failed')}</td></tr>`;
        return;
      }
      const rows = r.data || [];
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No damaged receiving records. Damaged qty from Receive &amp; Inspect appears here.</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(d => {
        const eligible = parseInt(d.eligible_return_qty, 10) || 0;
        const damaged = parseInt(d.quantity_damaged, 10) || 0;
        const missing = parseInt(d.quantity_missing, 10) || parseInt(d.line_quantity_missing, 10) || 0;
        const replacement = damaged + missing;
        const returned = parseInt(d.quantity_returned_rts, 10) || 0;
        const canReturn = canProcessRts() && eligible > 0;
        const actions = [];
        if (canReturn) actions.push(`<button type="button" class="btn btn-sm btn-warning" onclick="openRtsReturnModal(${d.id})">Return Item</button>`);
        if (!actions.length) actions.push('<span class="text-muted">—</span>');
        return `<tr>
          <td><div class="fw-semibold">${esc(d.receipt_number||'—')}</div><div class="small text-muted">${esc(d.po_number||'')}</div></td>
          <td>${esc(d.product_name||'—')}<br><span class="small text-muted">${esc(d.sku||'')} ${d.barcode ? '· ' + esc(d.barcode) : ''}</span></td>
          <td>${esc(d.supplier_name||'—')}</td>
          <td>${d.quantity_good||0}</td>
          <td><strong>${replacement}</strong></td>
          <td>${returned}</td>
          <td>${eligible}</td>
          <td><span class="badge badge-yellow">${esc(d.status)}</span></td>
          <td>${actions.join(' ')}</td>
        </tr>`;
      }).join('');
    });
  }
  if (rtsBody) {
    pApi({ query: { action: 'get_supplier_rts' } }, r => {
      if (!r.success) {
        rtsBody.innerHTML = `<tr><td colspan="10" class="text-danger text-center py-3">${esc(r.message||'Failed')}</td></tr>`;
        return;
      }
      const rows = r.data || [];
      if (!rows.length) {
        rtsBody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted">No RTS documents yet. Use Return Item on a damaged receiving line.</td></tr>';
        return;
      }
      rtsBody.innerHTML = rows.map(d => {
        const items = [];
        items.push(`<button type="button" class="dropdown-item" onclick="viewRts(${d.id})">View Return</button>`);
        items.push(`<button type="button" class="dropdown-item" onclick="printRts(${d.id})">Print Return Receipt</button>`);
        if (canProcessRts() && !d.replacement_status) {
          items.push(`<button type="button" class="dropdown-item" onclick="openRtsReplaceModal(${d.id})">Request Replacement</button>`);
        }
        if (canProcessRts() && (d.replacement_status === 'Requested' || d.replacement_status === 'Pending' || d.replacement_status === 'Approved') && !d.replacement_receipt_number) {
          items.push(`<button type="button" class="dropdown-item" onclick="openRtsReplacementInspect(${d.id}, ${parseInt(d.po_id,10)||0})">Receive Replacement</button>`);
        }
        const menu = `<div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Manage</button>
            <ul class="dropdown-menu dropdown-menu-end">${items.map(i => `<li>${i}</li>`).join('')}</ul>
          </div>`;
        const repl = d.replacement_status
          ? `${esc(d.replacement_status)}${d.replacement_qty ? ' · ' + d.replacement_qty : ''}${d.replacement_receipt_number ? '<br><span class="small text-muted">' + esc(d.replacement_receipt_number) + '</span>' : ''}`
          : '—';
        return `<tr>
          <td><strong style="font-family:monospace">${esc(d.rts_number)}</strong></td>
          <td>${fmtDate(d.return_date)}</td>
          <td>${esc(d.po_number||'—')}<br><span class="small text-muted">${esc(d.receipt_number||'')}</span></td>
          <td>${esc(d.supplier_name||'—')}</td>
          <td>${esc(d.product_name||'—')}<br><span class="small text-muted">${esc(d.sku||'')}</span></td>
          <td>${d.quantity_returned||0}</td>
          <td>${esc(d.return_reason||'—')}</td>
          <td>${repl}</td>
          <td><span class="badge badge-yellow">${esc(d.status)}</span></td>
          <td>${menu}</td>
        </tr>`;
      }).join('');
    });
  }
}

function openRtsReturnModal(id) {
  window._rtsReturnId = id;
  const body = document.getElementById('rtsReturnBody');
  if (body) body.innerHTML = '<div class="text-center py-4 text-muted">Loading original receiving record…</div>';
  openModal('rtsReturnModal');
  pApi({ query: { action: 'get_rts_returnable', id } }, r => {
    if (!r.success || !r.data) {
      if (body) body.innerHTML = `<p class="text-danger">${esc(r.message||'Not found')}</p>`;
      return;
    }
    const d = r.data;
    const eligible = parseInt(d.eligible_return_qty, 10) || 0;
    if (eligible <= 0) {
      if (body) body.innerHTML = '<p class="text-warning">No remaining damaged quantity is eligible to return. Missing quantity cannot generate an RTS.</p>';
      return;
    }
    const uom = d.unit_of_measure || 'unit';
    const barcodes = Array.isArray(d.barcode_values) && d.barcode_values.length ? d.barcode_values.join(', ') : (d.barcode || '—');
    if (body) body.innerHTML = `
      <input type="hidden" id="rtsDamageId" value="${d.id}">
      <div class="row g-2 mb-3 small">
        <div class="col-md-6"><div class="text-muted">Product</div><div class="fw-semibold">${esc(d.product_name||'—')}</div></div>
        <div class="col-md-3"><div class="text-muted">SKU</div><div>${esc(d.sku||'—')}</div></div>
        <div class="col-md-3"><div class="text-muted">Unit</div><div>${esc(uom)}</div></div>
        <div class="col-md-6"><div class="text-muted">Barcode</div><div>${esc(barcodes)}</div></div>
        <div class="col-md-6"><div class="text-muted">Supplier</div><div>${esc(d.supplier_name||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">PO #</div><div>${esc(d.po_number||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">PREC #</div><div>${esc(d.receipt_number||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">Date Received</div><div>${fmtDate(d.date_received)}</div></div>
        <div class="col-md-4"><div class="text-muted">Supplier DR #</div><div>${esc(d.dr_number||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">Supplier Invoice #</div><div>${esc(d.supplier_si_number||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">Batch / Lot</div><div>${esc(d.batch_display||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">Expiry</div><div>${d.expiry_date ? fmtDate(d.expiry_date) : '—'}</div></div>
        <div class="col-md-4"><div class="text-muted">Damaged / Eligible</div><div><strong>${d.quantity_damaged||0}</strong> / ${eligible} ${esc(uom)}</div></div>
        <div class="col-md-4"><div class="text-muted">Missing (not returnable)</div><div>${parseInt(d.quantity_missing,10)||0} ${esc(uom)}</div></div>
      </div>
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label">Quantity to Return <span class="text-danger">*</span></label>
          <input type="number" min="1" max="${eligible}" value="${eligible}" id="rtsQty" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Return Reason <span class="text-danger">*</span></label>
          <select id="rtsReason" class="form-control">
            <option value="Damaged">Damaged</option>
            <option value="Defective">Defective</option>
            <option value="Wrong Item">Wrong Item</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Return Date</label>
          <input type="date" id="rtsDate" class="form-control" value="${new Date().toISOString().slice(0,10)}">
        </div>
        <div class="col-12">
          <label class="form-label">Damage / Return Notes</label>
          <textarea id="rtsNotes" class="form-control" rows="2" placeholder="Condition on inspection…"></textarea>
        </div>
      </div>`;
  });
}

function submitRtsReturn() {
  const id = parseInt(document.getElementById('rtsDamageId')?.value || window._rtsReturnId || '0', 10);
  const qty = parseInt(document.getElementById('rtsQty')?.value || '0', 10);
  const reason = document.getElementById('rtsReason')?.value || 'Damaged';
  const notes = (document.getElementById('rtsNotes')?.value || '').trim();
  const returnDate = document.getElementById('rtsDate')?.value || '';
  if (!id || qty <= 0) {
    pToast('Enter a valid return quantity.', 'warn');
    return;
  }
  pApi({ method: 'POST', body: {
    action: 'create_supplier_rts',
    damage_return_id: id,
    quantity_returned: qty,
    return_reason: reason,
    notes,
    return_date: returnDate
  } }, r => {
    if (!r.success) {
      pToast(r.message || 'Could not create RTS', 'danger');
      return;
    }
    pToast(r.message || ('RTS ' + r.rts_number));
    closeModal('rtsReturnModal');
    loadDamageReturns();
    const newId = r.data && r.data.id;
    if (newId) printRts(newId);
  });
}

function printRts(id) {
  if (!id) return;
  pApi({ method: 'POST', body: { action: 'log_rts_print', id } }, () => {});
  window.open('print_rts.php?id=' + encodeURIComponent(id), '_blank');
}

function viewRts(id) {
  window._rtsViewId = id;
  const body = document.getElementById('rtsViewBody');
  const title = document.getElementById('rtsViewTitle');
  if (body) body.innerHTML = '<div class="text-center py-4 text-muted">Loading…</div>';
  openModal('rtsViewModal');
  pApi({ query: { action: 'get_rts_detail', id } }, r => {
    if (!r.success || !r.data) {
      if (body) body.innerHTML = `<p class="text-danger">${esc(r.message||'Not found')}</p>`;
      return;
    }
    const d = r.data;
    if (title) title.innerHTML = `<i class="bi bi-file-earmark-text me-1"></i> ${esc(d.rts_number)}`;
    const uom = d.unit_of_measure || 'unit';
    const repl = (d.replacements || []).map(rr =>
      `<div class="small">${esc(rr.replacement_number)} · ${rr.quantity_requested} · ${esc(rr.status)}${rr.replacement_receipt_number ? ' · ' + esc(rr.replacement_receipt_number) : ''}</div>`
    ).join('') || '<div class="text-muted">None</div>';
    if (body) body.innerHTML = `
      <div class="row g-2 small">
        <div class="col-md-4"><div class="text-muted">RTS #</div><div class="fw-bold">${esc(d.rts_number)}</div></div>
        <div class="col-md-4"><div class="text-muted">Status</div><div>${esc(d.status)}</div></div>
        <div class="col-md-4"><div class="text-muted">Return Date</div><div>${fmtDate(d.return_date)}</div></div>
        <div class="col-md-4"><div class="text-muted">PO #</div><div>${esc(d.po_number||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">PREC #</div><div>${esc(d.receipt_number||'—')}</div></div>
        <div class="col-md-4"><div class="text-muted">Supplier</div><div>${esc(d.supplier_name||'—')}</div></div>
        <div class="col-md-6"><div class="text-muted">Product</div><div>${esc(d.product_name||'—')} (${esc(d.sku||'')})</div></div>
        <div class="col-md-3"><div class="text-muted">Returned</div><div>${d.quantity_returned||0} ${esc(uom)}</div></div>
        <div class="col-md-3"><div class="text-muted">Reason</div><div>${esc(d.return_reason||'—')}</div></div>
        <div class="col-12"><div class="text-muted">Notes</div><div>${esc(d.notes||'—')}</div></div>
        <div class="col-12"><div class="text-muted">Replacement requests</div>${repl}</div>
      </div>`;
  });
}

function printRtsFromView() {
  if (window._rtsViewId) printRts(window._rtsViewId);
}

function openRtsReplaceModal(id) {
  window._rtsReplaceId = id;
  const body = document.getElementById('rtsReplaceBody');
  if (body) body.innerHTML = '<div class="text-center py-3 text-muted">Loading RTS…</div>';
  openModal('rtsReplaceModal');
  pApi({ query: { action: 'get_rts_detail', id } }, r => {
    if (!r.success || !r.data) {
      if (body) body.innerHTML = `<p class="text-danger">${esc(r.message||'Not found')}</p>`;
      return;
    }
    const d = r.data;
    const requested = (d.replacements || []).filter(x => String(x.status||'') !== 'Rejected').reduce((n, x) => n + (parseInt(x.quantity_requested,10)||0), 0);
    const max = Math.max(0, (parseInt(d.quantity_returned,10)||0) - requested);
    if (max <= 0) {
      if (body) body.innerHTML = '<p class="text-warning">No remaining quantity on this RTS can be requested as a replacement.</p>';
      return;
    }
    if (body) body.innerHTML = `
      <p class="small text-muted mb-2">Replacement for <strong>${esc(d.rts_number)}</strong> · ${esc(d.product_name||'')} · PO ${esc(d.po_number||'')}. This does not add inventory. The supplier shipment must still go through Receive &amp; Inspect.</p>
      <input type="hidden" id="rtsReplaceRtsId" value="${d.id}">
      <div class="form-group mb-2">
        <label class="form-label">Replacement Quantity (max ${max})</label>
        <input type="number" min="1" max="${max}" value="${max}" id="rtsReplaceQty" class="form-control">
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Request Date</label>
        <input type="date" id="rtsReplaceDate" class="form-control" value="${new Date().toISOString().slice(0,10)}">
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea id="rtsReplaceNotes" class="form-control" rows="2"></textarea>
      </div>`;
  });
}

function submitRtsReplacement() {
  const id = parseInt(document.getElementById('rtsReplaceRtsId')?.value || window._rtsReplaceId || '0', 10);
  const qty = parseInt(document.getElementById('rtsReplaceQty')?.value || '0', 10);
  const notes = (document.getElementById('rtsReplaceNotes')?.value || '').trim();
  const requestDate = document.getElementById('rtsReplaceDate')?.value || '';
  if (!id || qty <= 0) {
    pToast('Enter a valid replacement quantity.', 'warn');
    return;
  }
  pApi({ method: 'POST', body: {
    action: 'request_rts_replacement',
    rts_id: id,
    quantity: qty,
    notes,
    request_date: requestDate
  } }, r => {
    if (!r.success) {
      pToast(r.message || 'Could not request replacement', 'danger');
      return;
    }
    pToast(r.message || 'Replacement requested');
    closeModal('rtsReplaceModal');
    loadDamageReturns();
  });
}

function openRtsReplacementInspect(rtsId, poId) {
  if (!rtsId || !poId) {
    pToast('RTS is not linked to a purchase order.', 'warn');
    return;
  }
  openCreateReceiptModal(poId, rtsId);
}

function updateDamageStatus(id, status) {
  const needsNote = status === 'Returned to Supplier' || status === 'Awaiting Replacement';
  const send = (remarks) => {
    pApi({ method: 'POST', body: { action: 'update_damage_return_status', id, status, remarks } }, r => {
      if (r.success) {
        pToast('Status updated to ' + status);
        loadDamageReturns();
      } else pToast(r.message || 'Failed', 'danger');
    });
  };
  if (!needsNote) {
    send('');
    return;
  }
  appPrompt('Return / replacement note (required):', status === 'Returned to Supplier' ? 'Damaged Item' : '').then(note => {
    const remarks = String(note || '').trim();
    if (note === null || !remarks) {
      if (note !== null) pToast('A note is required (e.g. Damaged Item).', 'warn');
      return;
    }
    send(remarks);
  });
}

function printDamageReturnReceipt(receiptId) {
  if (!receiptId) {
    pToast('Open the related Goods Received receipt to print.', 'info');
    return;
  }
  window.open('print_supplier_receipt.php?receipt_id=' + encodeURIComponent(receiptId), '_blank');
}

function recordDamageReplacement(id, maxQty) {
  pToast('Use Request Replacement on the RTS document, then Receive Replacement to inspect the shipment.', 'info');
}

function stockInDamageReplacement(id) {
  pToast('Replacement stock-in uses the normal Stock-In Queue after Receive & Inspect.', 'info');
}

// Sales Order create/scan/tax lives in java/sales_order_module.js
window._pendingSoBarcode = window._pendingSoBarcode || '';

function soSellableQty(p) {
  if (!p) return 0;
  if (p.sellable_qty != null && p.sellable_qty !== '') return parseInt(p.sellable_qty, 10) || 0;
  return parseInt(p.quantity_in_stock, 10) || 0;
}

function soFindByBarcode(code) {
  const key = clarBarcodeKey(code);
  if (!key) return null;
  return _soProductsCache.find(p => {
    if (clarBarcodeKey(p.barcode) === key) return true;
    const extras = p.barcode_values || (Array.isArray(p.barcodes) ? p.barcodes.map(b => b.barcode_value || b) : []);
    return extras.some(v => clarBarcodeKey(v) === key);
  }) || null;
}

function applySalesOrderBarcode(code) {
  const key = clarBarcodeKey(code);
  if (!key) return false;
  if (!_soProductsCache.length) {
    window._pendingSoBarcode = key;
    return false;
  }
  const product = soFindByBarcode(key);
  if (!product) {
    pToast('Scanned item is not in the Product Catalog for this sale.', 'warn');
    return false;
  }
  const stock = typeof soSellableQty === 'function' ? soSellableQty(product) : (parseInt(product.sellable_qty ?? product.quantity_in_stock, 10) || 0);
  if (stock <= 0 || !(parseFloat(product.selling_price ?? product.unit_price ?? 0) > 0)) {
    pToast('This item is unavailable or not sellable.', 'warn');
    return false;
  }
  const body = document.getElementById('soLinesBody');
  if (!body) return false;
  let combo = Array.from(body.querySelectorAll('.so-product-combo')).find(c => {
    const sel = c.querySelector('.so-product');
    return sel && !sel.value;
  });
  if (!combo) {
    addSalesOrderLine();
    const rows = body.querySelectorAll('tr');
    combo = rows[rows.length - 1] ? rows[rows.length - 1].querySelector('.so-product-combo') : null;
  }
  if (!combo) return false;
  soApplyProductChoice(combo, product);
  const scanInput = document.getElementById('soBarcodeScan');
  if (scanInput) scanInput.value = '';
  return true;
}

function submitSalesOrderBarcode() {
  const input = document.getElementById('soBarcodeScan');
  applySalesOrderBarcode(input ? input.value : '');
}

function openCreateSalesOrderModal() {
  const due = document.getElementById('soDueDate');
  if (due) due.value = new Date(Date.now() + 7*86400000).toISOString().split('T')[0];
  ['soCustomerFirstName', 'soCustomerMiddleName', 'soCustomerLastName', 'soCustomerSuffix', 'soCustomerName'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  if (typeof soClearCustomerNameError === 'function') {
    soClearCustomerNameError('soCustomerFirstName');
    soClearCustomerNameError('soCustomerLastName');
  }
  const po = document.getElementById('soCustomerPo');
  if (po) po.value = '';
  const st = document.getElementById('soSaleType');
  if (st) st.value = 'Cash';
  const chequeNo = document.getElementById('soChequeNumber');
  const chequeBank = document.getElementById('soBankName');
  const chequeDate = document.getElementById('soChequeDate');
  if (chequeNo) chequeNo.value = '';
  if (chequeBank) chequeBank.value = '';
  if (chequeDate) chequeDate.value = '';
  if (typeof toggleSoChequeFields === 'function') toggleSoChequeFields();
  if (typeof soSyncPayDetailFields === 'function') soSyncPayDetailFields();
  const body = document.getElementById('soLinesBody');
  if (body) body.innerHTML = '';
  openModal('createSalesOrderModal');
  const apiUrl = (typeof clarAuthUrl === 'function')
    ? clarAuthUrl('inventory_api.php?action=get_products_list')
    : 'inventory_api.php?action=get_products_list';
  fetch(apiUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(r => {
      if (!r.success) {
        _soProductsCache = [];
        pToast(r.message || 'Could not load products for sale', 'danger');
        addSalesOrderLine();
        return;
      }
      _soProductsCache = (r.data || []).filter(p => {
        const stock = soSellableQty(p);
        const sell = parseFloat(p.selling_price ?? p.unit_price ?? 0) || 0;
        return stock > 0 && sell > 0;
      });
      if (!_soProductsCache.length) {
        pToast('No sellable products yet. Need stocked-in qty and selling price > 0.', 'warn');
      }
      addSalesOrderLine();
      const pending = clarBarcodeKey(window._pendingSoBarcode);
      if (pending) {
        window._pendingSoBarcode = '';
        applySalesOrderBarcode(pending);
      }
    })
    .catch(() => {
      _soProductsCache = [];
      pToast('Failed to load products', 'danger');
      addSalesOrderLine();
    });
}

function soProductLabel(p) {
  const price = parseFloat(p.selling_price ?? p.unit_price ?? 0) || 0;
  const stock = soSellableQty(p);
  return `${p.sku || ''} — ${p.name || ''} (₱${price.toFixed(2)}, stock ${stock})`;
}

function soFindCachedProduct(id) {
  const n = parseInt(id, 10);
  if (!n) return null;
  return _soProductsCache.find(p => parseInt(p.id, 10) === n) || null;
}

function soRankedProducts(query) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return _soProductsCache.slice();
  const starts = [];
  const contains = [];
  _soProductsCache.forEach(p => {
    const sku = String(p.sku || '').toLowerCase();
    const name = String(p.name || '').toLowerCase();
    const barcode = String(p.barcode || '').toLowerCase();
    const extra = (p.barcode_values || []).join(' ').toLowerCase();
    const nameSpaced = name.replace(/[_]+/g, ' ');
    const skuTail = sku.replace(/^sku-/, '');
    const exactBarcode = barcode && barcode === q;
    const nameStart = name.startsWith(q) || nameSpaced.startsWith(q);
    const skuStart = sku.startsWith(q) || skuTail.startsWith(q);
    const barcodeStart = barcode && barcode.startsWith(q);
    if (exactBarcode) starts.unshift(p);
    else if (nameStart || skuStart || barcodeStart) starts.push(p);
    else if (name.includes(q) || nameSpaced.includes(q) || sku.includes(q) || skuTail.includes(q) || barcode.includes(q)) contains.push(p);
  });
  return starts.concat(contains);
}

function soCloseProductCombo(combo) {
  if (!combo) return;
  const menu = combo.querySelector('.so-product-menu');
  if (menu) {
    menu.hidden = true;
    menu.innerHTML = '';
  }
}

function soCloseAllProductCombos(except) {
  document.querySelectorAll('#soLinesBody .so-product-combo').forEach(combo => {
    if (combo !== except) soCloseProductCombo(combo);
  });
}

function soBindProductComboDocClose() {
  if (window._soComboDocBound) return;
  window._soComboDocBound = true;
  document.addEventListener('mousedown', (e) => {
    document.querySelectorAll('#soLinesBody .so-product-combo').forEach(combo => {
      if (!combo.contains(e.target)) soCloseProductCombo(combo);
    });
  });
}

function soRenderProductMenu(combo, query, highlightFirst) {
  const menu = combo.querySelector('.so-product-menu');
  if (!menu) return;
  const matches = soRankedProducts(query);
  if (!matches.length) {
    menu.innerHTML = '<div class="so-product-empty">No products found</div>';
    menu.hidden = false;
    return;
  }
  menu.innerHTML = matches.map((p, i) => {
    const active = highlightFirst && i === 0 ? ' active' : '';
    return `<button type="button" class="so-product-opt${active}" role="option" data-id="${p.id}">${esc(soProductLabel(p))}</button>`;
  }).join('');
  menu.hidden = false;
}

function soMoveProductHighlight(combo, dir) {
  const menu = combo.querySelector('.so-product-menu');
  if (!menu || menu.hidden) return;
  const opts = [...menu.querySelectorAll('.so-product-opt')];
  if (!opts.length) return;
  let idx = opts.findIndex(o => o.classList.contains('active'));
  idx = idx < 0 ? (dir > 0 ? 0 : opts.length - 1) : (idx + dir + opts.length) % opts.length;
  opts.forEach(o => o.classList.remove('active'));
  opts[idx].classList.add('active');
  opts[idx].scrollIntoView({ block: 'nearest' });
}

function soApplyProductChoice(combo, product) {
  const sel = combo.querySelector('.so-product');
  const input = combo.querySelector('.so-product-search');
  if (!sel) return;
  if (!product) {
    sel.value = '';
    if (input) input.value = '';
  } else {
    sel.value = String(product.id);
    if (input) input.value = soProductLabel(product);
  }
  sel.dispatchEvent(new Event('change', { bubbles: true }));
  soCloseProductCombo(combo);
}

function soPickHighlightedProduct(combo) {
  const active = combo.querySelector('.so-product-opt.active') || combo.querySelector('.so-product-opt');
  if (!active) return;
  soApplyProductChoice(combo, soFindCachedProduct(active.getAttribute('data-id')));
}

function soBindProductCombo(combo) {
  if (!combo || combo.dataset.bound === '1') return;
  combo.dataset.bound = '1';
  soBindProductComboDocClose();
  const input = combo.querySelector('.so-product-search');
  const menu = combo.querySelector('.so-product-menu');
  const sel = combo.querySelector('.so-product');
  if (!input || !menu || !sel) return;

  const openFiltered = (highlightFirst) => {
    soCloseAllProductCombos(combo);
    soRenderProductMenu(combo, input.value, highlightFirst);
  };

  input.addEventListener('focus', () => openFiltered(!!String(input.value || '').trim()));
  input.addEventListener('input', () => {
    const chosen = soFindCachedProduct(sel.value);
    if (chosen && input.value !== soProductLabel(chosen)) {
      sel.value = '';
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    openFiltered(true);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (menu.hidden) openFiltered(true);
      else soMoveProductHighlight(combo, 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (menu.hidden) openFiltered(false);
      else soMoveProductHighlight(combo, -1);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (menu.hidden) openFiltered(true);
      soPickHighlightedProduct(combo);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      soCloseProductCombo(combo);
    }
  });
  menu.addEventListener('mousedown', (e) => {
    const opt = e.target.closest('.so-product-opt');
    if (!opt) return;
    e.preventDefault();
    soApplyProductChoice(combo, soFindCachedProduct(opt.getAttribute('data-id')));
  });
}

function addSalesOrderLine() {
  const body = document.getElementById('soLinesBody');
  if (!body) return;
  const opts = '<option value="">-- Select product --</option>' +
    _soProductsCache.map(p => {
      const price = parseFloat(p.selling_price ?? p.unit_price ?? 0) || 0;
      const stock = soSellableQty(p);
      return `<option value="${p.id}" data-stock="${stock}" data-price="${price}">${esc(p.sku)} — ${esc(p.name)} (₱${price.toFixed(2)}, stock ${stock})</option>`;
    }).join('');
  const tr = document.createElement('tr');
  tr.innerHTML = `<td>
      <div class="so-product-combo">
        <select class="form-control form-control-sm so-product so-product-native" tabindex="-1" aria-hidden="true" onchange="onSoProductChange(this)">${opts}</select>
        <input type="text" class="form-control form-control-sm so-product-search" placeholder="-- Select product --" autocomplete="off" spellcheck="false">
        <div class="so-product-menu" hidden role="listbox"></div>
      </div>
    </td>
    <td class="so-stock">—</td>
    <td class="so-price">—</td>
    <td class="so-batch small text-muted">FEFO: —</td>
    <td><input type="number" min="1" value="1" class="form-control form-control-sm so-qty" style="width:90px"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>`;
  body.appendChild(tr);
  soBindProductCombo(tr.querySelector('.so-product-combo'));
}

function onSoProductChange(sel) {
  const opt = sel.selectedOptions[0];
  const tr = sel.closest('tr');
  if (!tr || !opt) return;
  const baseStock = parseInt(opt.getAttribute('data-stock') || opt.dataset.stock || '0', 10) || 0;
  const lineQty = parseInt(tr.querySelector('.so-qty')?.value || '0', 10) || 0;
  if (baseStock > 0 && !tr.dataset.baseStock) tr.dataset.baseStock = String(baseStock);
  const original = parseInt(tr.dataset.baseStock || '0', 10) || baseStock;
  tr.querySelector('.so-stock').textContent = original > 0 ? String(Math.max(0, original - lineQty)) : '—';
  tr.querySelector('.so-price').textContent = opt.dataset.price ? ('₱' + parseFloat(opt.dataset.price).toFixed(2)) : '—';
  const qty = tr.querySelector('.so-qty');
  if (qty && opt.dataset.stock) qty.max = opt.dataset.stock;
  const batchCell = tr.querySelector('.so-batch');
  if (batchCell) batchCell.textContent = 'FEFO: loading…';
  const pid = sel.value;
  if (!pid) {
    if (batchCell) batchCell.textContent = 'FEFO: —';
    return;
  }
  const apiUrl = (typeof clarAuthUrl === 'function')
    ? clarAuthUrl('inventory_api.php?action=get_fifo_batches&product_id=' + encodeURIComponent(pid))
    : 'inventory_api.php?action=get_fifo_batches&product_id=' + encodeURIComponent(pid);
  fetch(apiUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(r => {
      if (!batchCell) return;
      const batches = r.data || [];
      if (!batches.length) {
        batchCell.textContent = 'FEFO: no lots (stocked qty only)';
        return;
      }
      const first = batches[0];
      const lot = String(first.manufacturer_lot || '').trim();
      const exp = String(first.expiry_date || '').trim();
      batchCell.innerHTML = `FEFO: <strong>${esc(lot || '—')}</strong>`
        + (exp && exp !== '0000-00-00' ? ` · exp ${esc(exp)}` : '')
        + ` (${first.quantity_remaining} avail)`;
    })
    .catch(() => { if (batchCell) batchCell.textContent = 'FEFO: —'; });
}

function toggleSoChequeFields() {
  const st = document.getElementById('soSaleType')?.value || 'Cash';
  const box = document.getElementById('soChequeFields');
  const credit = document.getElementById('soCreditFields');
  const isCheque = st === 'Cheque' || st === 'Check';
  if (box) box.style.display = isCheque ? '' : 'none';
  if (credit) credit.style.display = (st === 'Credit') ? '' : 'none';
  ['soChequeNumber', 'soBankName', 'soChequeDate'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.required = isCheque;
  });
  if (typeof soSyncPayDetailFields === 'function') soSyncPayDetailFields();
}

let _poApprovalTab = 'For Approval';

function switchPoApprovalTab(tab, btn) {
  _poApprovalTab = tab || 'For Approval';
  document.querySelectorAll('#poApprovalTabs .nav-link').forEach((el) => el.classList.remove('active'));
  if (btn) btn.classList.add('active');
  loadPoApprovals();
}

function renderPoLifecycleRows(data, tbodyId, mode) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">No purchase orders found.</td></tr>`;
    return;
  }
  const canApprove = typeof window.canApprovePOs !== 'undefined' ? !!window.canApprovePOs : false;
  tbody.innerHTML = data.map((p) => {
    const poDate = fmtDate(p.order_date || p.created_at);
    const statusHtml = poStatusBadge(p.status, p);
    let actions = `<button class="btn btn-sm btn-outline-primary" type="button" onclick="viewPO(${p.id})"><i class="bi bi-eye me-1"></i>Review</button>`;
    if (mode === 'approval' && canApprove && ['Submitted', 'Pending Approval'].includes(p.status)) {
      const num = esc(p.po_number || '');
      actions += ` <button class="btn btn-sm btn-success" type="button" onclick="approvePoRecord(${p.id}, '${num}')"><i class="bi bi-check2 me-1"></i>Approve</button>`;
      actions += ` <button class="btn btn-sm btn-outline-danger" type="button" onclick="rejectPoRecord(${p.id}, '${num}')"><i class="bi bi-x-circle me-1"></i>Reject</button>`;
    }
    return `<tr>
      <td><strong style="font-family:monospace" class="text-primary">${esc(p.po_number)}</strong></td>
      <td>${poDate}</td>
      <td>${esc(p.supplier_name || '—')}</td>
      <td style="font-weight:700">${fmtMoney(p.total_amount)}</td>
      <td>${statusHtml}</td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
}

function loadPoApprovals() {
  const tbody = document.getElementById('poApprovalsBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="6" class="text-center py-4 text-muted"><span class="loader"></span> Loading…</td></tr>';
  const tab = _poApprovalTab || 'For Approval';
  pApi({ query: { action: 'get_purchase_orders', lifecycle: tab } }, (r) => {
    if (!r.success) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${esc(r.message || 'Could not load purchase orders.')}</td></tr>`;
      return;
    }
    renderPoLifecycleRows(r.data || [], 'poApprovalsBody', 'approval');
  });
}

function loadPoTracker() {
  const tbody = document.getElementById('poTrackerBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="6" class="text-center py-4 text-muted"><span class="loader"></span> Loading…</td></tr>';
  const params = { action: 'get_purchase_orders' };
  const status = document.getElementById('poTrackerStatus')?.value || 'all';
  const poNum = document.getElementById('poTrackerNumber')?.value || '';
  const supplier = document.getElementById('poTrackerSupplier')?.value || '';
  if (status && status !== 'all') params.lifecycle = status;
  if (poNum.trim()) params.po_number = poNum.trim();
  if (supplier.trim()) params.supplier = supplier.trim();
  pApi({ query: params }, (r) => {
    if (!r.success) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${esc(r.message || 'Could not load purchase orders.')}</td></tr>`;
      return;
    }
    renderPoLifecycleRows(r.data || [], 'poTrackerBody', 'tracker');
  });
}

function approvePoRecord(id, poNumber) {
  if (!window.canApprovePOs) {
    pToast('You do not have permission to approve purchase orders.', 'warn');
    return;
  }
  appConfirm('Approve ' + poNumber + '? This updates the same PO record and keeps the PO number.').then(ok => {
  if (!ok) return;
  pApi({ method: 'POST', body: { action: 'update_po_status', id, status: 'Approved' } }, (r) => {
    if (r.success) {
      pToast('PO ' + poNumber + ' approved. Same PO number kept.');
      loadPoApprovals();
      if (document.getElementById('poTrackerBody')) loadPoTracker();
      if (typeof loadPurchaseOrders === 'function') loadPurchaseOrders();
    } else {
      pToast(r.message || 'Could not approve this purchase order.', 'danger');
    }
  });
  });
}

function rejectPoRecord(id, poNumber) {
  if (!window.canApprovePOs) {
    pToast('You do not have permission to reject purchase orders.', 'warn');
    return;
  }
  appPrompt('Reject ' + poNumber + '? The PO will be kept with status Rejected.\nEnter rejection reason:').then(reason => {
  if (reason === null) return;
  if (!String(reason).trim()) {
    pToast('A rejection reason is required. The PO was not deleted.', 'warn');
    return;
  }
  pApi({ method: 'POST', body: { action: 'update_po_status', id, status: 'Rejected', rejection_reason: String(reason).trim() } }, (r) => {
    if (r.success) {
      pToast('PO ' + poNumber + ' rejected. Same record retained.');
      loadPoApprovals();
      if (document.getElementById('poTrackerBody')) loadPoTracker();
      if (typeof loadPurchaseOrders === 'function') loadPurchaseOrders();
    } else {
      pToast(r.message || 'Could not reject this purchase order.', 'danger');
    }
  });
  });
}

function submitCreateSalesOrder() {
  if (typeof soValidateCustomerNameFields === 'function' && !soValidateCustomerNameFields()) {
    pToast('First Name and Last Name are required', 'warn');
    return;
  }
  let customer_name = (typeof soComposeCustomerName === 'function')
    ? soComposeCustomerName()
    : (document.getElementById('soCustomerName')?.value?.trim() || '');
  if (!customer_name) {
    customer_name = ['soCustomerFirstName', 'soCustomerMiddleName', 'soCustomerLastName', 'soCustomerSuffix']
      .map((id) => (document.getElementById(id)?.value || '').trim())
      .filter(Boolean)
      .join(' ')
      .replace(/\s+/g, ' ')
      .trim();
    const hidden = document.getElementById('soCustomerName');
    if (hidden) hidden.value = customer_name;
  }
  const customer_po_number = document.getElementById('soCustomerPo')?.value?.trim() || '';
  const sale_type = document.getElementById('soSaleType')?.value || 'Cash';
  const due_date = document.getElementById('soDueDate')?.value;
  const cheque_number = document.getElementById('soChequeNumber')?.value?.trim() || '';
  const bank_name = document.getElementById('soBankName')?.value?.trim() || '';
  const cheque_date = document.getElementById('soChequeDate')?.value || '';
  const delivery_fee = parseFloat(document.getElementById('soDeliveryFee')?.value || '0') || 0;
  if (!customer_name || !due_date) { pToast('Customer and due date required', 'warn'); return; }
  if (sale_type === 'Cheque' || sale_type === 'Check') {
    if (!cheque_number || !bank_name || !cheque_date) {
      pToast('Cheque Number, Bank Name, and Cheque Date are required', 'warn');
      return;
    }
  }
  if (typeof soValidatePaymentDetailFields === 'function' && !soValidatePaymentDetailFields()) {
    pToast('Complete the required payment details', 'warn');
    return;
  }
  const product_ids = [];
  const quantities = [];
  let stockError = false;
  document.querySelectorAll('#soLinesBody tr').forEach(tr => {
    const pid = parseInt(tr.querySelector('.so-product')?.value || 0, 10);
    const qty = parseInt(tr.querySelector('.so-qty')?.value || 0, 10);
    const stock = parseInt(tr.querySelector('.so-product')?.selectedOptions[0]?.dataset.stock || 0, 10);
    if (pid > 0 && qty > 0) {
      if (qty > stock) stockError = true;
      product_ids.push(pid);
      quantities.push(qty);
    }
  });
  if (stockError) { pToast('Qty exceeds available stock', 'warn'); return; }
  if (!product_ids.length) { pToast('Add at least one product line', 'warn'); return; }

  const fd = new FormData();
  fd.append('create_sales_order', '1');
  fd.append('customer_name', customer_name);
  fd.append('customer_first_name', (document.getElementById('soCustomerFirstName')?.value || '').trim());
  fd.append('customer_middle_name', (document.getElementById('soCustomerMiddleName')?.value || '').trim());
  fd.append('customer_last_name', (document.getElementById('soCustomerLastName')?.value || '').trim());
  fd.append('customer_suffix', (document.getElementById('soCustomerSuffix')?.value || '').trim());
  fd.append('customer_po_number', customer_po_number);
  fd.append('sale_type', sale_type);
  fd.append('due_date', due_date);
  fd.append('discount_percent', '0');
  fd.append('delivery_fee', String(Math.max(0, delivery_fee)));
  if (sale_type === 'Cheque' || sale_type === 'Check') {
    fd.append('cheque_number', cheque_number);
    fd.append('bank_name', bank_name);
    fd.append('cheque_date', cheque_date);
  }
  if (typeof soAppendPayDetailFields === 'function') soAppendPayDetailFields(fd);
  product_ids.forEach(id => fd.append('product_ids[]', id));
  quantities.forEach(q => fd.append('quantities[]', q));

  fetch('billing_api.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected response' })))
    .then(r => {
      if (r.success) {
        pToast(r.message || 'Sale invoiced');
        closeModal('createSalesOrderModal');
        if (r.invoice_number) {
          window.open('print_invoice.php?invoice_number=' + encodeURIComponent(r.invoice_number) + '&with_receipt=1', '_blank');
        } else if (r.payment_number) {
          window.open('print_payment_receipt.php?payment_number=' + encodeURIComponent(r.payment_number), '_blank');
        }
        setTimeout(() => location.reload(), 800);
      } else pToast(r.message || 'Failed to create sale', 'danger');
    })
    .catch(() => pToast('Network error creating sale', 'danger'));
}

// ── INIT ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Cashier accounts have no procurement access; skip PR/PO loading and polling.
  if (currentProcurementRole() === 'cashier') return;
  loadDropdowns();
  loadDashboard();
  startIncomingDeliveriesPoll();
  ['poTrackerNumber', 'poTrackerSupplier'].forEach((id) => {
    document.getElementById(id)?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        loadPoTracker();
      }
    });
  });
});
