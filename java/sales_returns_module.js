/* ════════════════════════════════════════════════════════════
   sales_returns_module.js – Customer Returns (Return Policy)
   Record a return against a completed sale → review (approve / reject)
   → stock, refund or replacement are applied on approval.
════════════════════════════════════════════════════════════ */

const SR_API = 'sales_returns_api.php';
let _srMeta = null;
let _srSale = null;
let _srSubmitBusy = false;
let _srReviewBusy = false;
let _srLoadTimer = null;
let _srToken = '';

function srEsc(s) {
  if (s === null || s === undefined) return '';
  const d = document.createElement('div');
  d.textContent = String(s);
  return d.innerHTML;
}
function srToday() {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function srMoney(v) {
  return '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function srDate(v) {
  if (!v) return '—';
  const s = String(v).replace('T', ' ');
  const d = new Date(s.length <= 10 ? s + 'T00:00:00' : s.replace(' ', 'T'));
  if (isNaN(d.getTime())) return srEsc(v);
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    + (s.length > 10 ? ', ' + d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '');
}
function srToast(msg, type) {
  if (typeof pToast === 'function') pToast(msg, type || 'success');
  else alert(msg);
}
function srOpen(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (typeof openModal === 'function') openModal(id);
  else { el.classList.add('open'); el.style.display = 'flex'; }
}
function srClose(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (typeof closeModal === 'function') closeModal(id);
  else { el.classList.remove('open'); el.style.display = 'none'; }
}
function srStatusBadge(s) {
  const map = { 'Pending Review': 'badge-yellow', 'Completed': 'badge-green', 'Rejected': 'badge-red' };
  return `<span class="badge ${map[s] || 'badge-gray'}">${srEsc(s || '—')}</span>`;
}
function srConditionBadge(c) {
  const map = { Sellable: 'badge-green', Damaged: 'badge-orange', Missing: 'badge-red' };
  return `<span class="badge ${map[c] || 'badge-gray'}">${srEsc(c)}</span>`;
}

function srApi(params, cb) {
  const parse = text => { try { return JSON.parse(text); } catch (e) { return null; } };
  if (params.method === 'POST') {
    fetch(SR_API, { method: 'POST', body: new URLSearchParams(params.body), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.text())
      .then(t => cb(parse(t) || { success: false, message: 'Server error occurred' }))
      .catch(() => cb({ success: false, message: 'Network error' }));
    return;
  }
  const url = new URL(SR_API, window.location.href);
  Object.entries(params.query || {}).forEach(([k, v]) => url.searchParams.set(k, v));
  fetch(url, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.text())
    .then(t => cb(parse(t) || { success: false, message: 'Server error occurred' }))
    .catch(() => cb({ success: false, message: 'Network error' }));
}

function srLoadMeta(cb) {
  if (_srMeta) { if (cb) cb(_srMeta); return; }
  srApi({ query: { action: 'get_policy' } }, r => {
    if (!r.success) { srToast(r.message || 'Could not load the return policy', 'danger'); return; }
    _srMeta = r;
    srRenderPolicySummary();
    if (cb) cb(_srMeta);
  });
}

function srRenderPolicySummary() {
  const el = document.getElementById('srPolicySummary');
  if (!el || !_srMeta) return;
  const p = _srMeta.policy;
  const refundBits = [];
  if (p.return_refund_sellable) refundBits.push('sellable');
  if (p.return_refund_damaged) refundBits.push('damaged');
  el.textContent = `Returns accepted within ${p.return_window_days} day(s) of sale · Refunds: ${refundBits.length ? refundBits.join(' & ') + ' items' : 'not allowed'} · Replacement: ${p.return_replacement_allowed ? 'allowed' : 'not allowed'} · Missing items are never refunded.`;
}

/* ── List ─────────────────────────────────────────────────── */
function srScheduleLoad() {
  clearTimeout(_srLoadTimer);
  _srLoadTimer = setTimeout(srLoadReturns, 300);
}

function srLoadReturns() {
  srLoadMeta();
  const tbody = document.getElementById('srReturnsBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="10" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading returns…</td></tr>';
  const status = document.getElementById('srStatusFilter')?.value || 'all';
  const search = document.getElementById('srSearch')?.value || '';
  srApi({ query: { action: 'list_returns', status, search } }, r => {
    if (!r.success) {
      tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">${srEsc(r.message || 'Could not load returns')}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    const pending = rows.filter(x => x.status === 'Pending Review').length;
    const badge = document.getElementById('srPendingBadge');
    if (badge) badge.textContent = pending > 0 && status === 'all' ? `${pending} pending review` : '';
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted">No returns recorded yet.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(x => `<tr>
      <td><strong style="font-family:monospace" class="text-primary">${srEsc(x.return_number)}</strong></td>
      <td>${srDate(x.return_date)}</td>
      <td><div>${srEsc(x.order_number || '—')}</div><div class="text-muted small">${srEsc(x.invoice_number || '')}${x.receipt_reference && x.receipt_reference !== x.invoice_number ? ' · ' + srEsc(x.receipt_reference) : ''}</div></td>
      <td>${srEsc(x.customer_name || '—')}</td>
      <td>${Number(x.total_qty || 0)} <span class="text-muted small">(${Number(x.line_count || 0)} line${Number(x.line_count) === 1 ? '' : 's'})</span></td>
      <td>${srEsc(x.outcome)}</td>
      <td>${x.outcome === 'Refund' ? srMoney(x.refund_amount) : '—'}</td>
      <td>${srStatusBadge(x.status)}</td>
      <td><div>${srEsc(x.created_by_name || '—')}</div>${x.reviewed_by_name ? `<div class="text-muted small">Reviewed: ${srEsc(x.reviewed_by_name)}</div>` : ''}</td>
      <td><button type="button" class="btn btn-sm ${x.status === 'Pending Review' && _srMeta && _srMeta.can_review ? 'btn-success' : 'btn-outline-primary'}" onclick="srViewReturn(${Number(x.id)})"><i class="bi bi-eye me-1"></i>${x.status === 'Pending Review' && _srMeta && _srMeta.can_review ? 'Review' : 'View'}</button></td>
    </tr>`).join('');
  });
}

/* ── Modals (created once) ───────────────────────────────── */
function srEnsureModals() {
  if (document.getElementById('srNewReturnModal')) return;
  const wrap = document.createElement('div');
  wrap.innerHTML = `
  <div class="modal-overlay" id="srNewReturnModal">
    <div class="modal modal-lg">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-arrow-return-left text-primary me-1"></i> Record Customer Return</span>
        <button class="modal-close" type="button" onclick="srClose('srNewReturnModal')"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="srReference">Sales Order, Invoice, or Official Receipt No. <span style="color:var(--accent-rose,#e11d48)">*</span></label>
        <div class="d-flex gap-2 mb-3">
          <input type="text" class="form-control" id="srReference" placeholder="e.g. SO-2026-…, INV-2026-…, PAY-2026-…" onkeydown="if(event.key==='Enter'){event.preventDefault();srLookupSale();}">
          <button type="button" class="btn btn-primary" onclick="srLookupSale()"><i class="bi bi-search me-1"></i>Find Sale</button>
        </div>
        <div id="srSalePanel"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" onclick="srClose('srNewReturnModal')">Cancel</button>
        <button class="btn btn-primary" type="button" id="srSubmitBtn" onclick="srSubmitReturn()" disabled><i class="bi bi-send me-1"></i>Submit for Review</button>
      </div>
    </div>
  </div>
  <div class="modal-overlay" id="srDetailModal">
    <div class="modal modal-lg">
      <div class="modal-header">
        <span class="modal-title" id="srDetailTitle"><i class="bi bi-arrow-return-left"></i> Return</span>
        <button class="modal-close" type="button" onclick="srClose('srDetailModal')"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body" id="srDetailBody"></div>
      <div class="modal-footer" id="srDetailFooter"></div>
    </div>
  </div>
  <div class="modal-overlay" id="srPolicyModal">
    <div class="modal">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-shield-check text-primary me-1"></i> Return Policy</span>
        <button class="modal-close" type="button" onclick="srClose('srPolicyModal')"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body" id="srPolicyBody"></div>
      <div class="modal-footer" id="srPolicyFooter"></div>
    </div>
  </div>`;
  while (wrap.firstElementChild) document.body.appendChild(wrap.firstElementChild);
}

/* ── Record a return ─────────────────────────────────────── */
function srOpenNewReturn() {
  srEnsureModals();
  srLoadMeta(() => {
    _srSale = null;
    _srToken = 'sr' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    document.getElementById('srReference').value = '';
    document.getElementById('srSalePanel').innerHTML = '<div class="text-muted small">Find the original sale to see which items can still be returned.</div>';
    document.getElementById('srSubmitBtn').disabled = true;
    srOpen('srNewReturnModal');
    setTimeout(() => document.getElementById('srReference')?.focus(), 60);
  });
}

function srLookupSale() {
  const ref = String(document.getElementById('srReference')?.value || '').trim();
  const panel = document.getElementById('srSalePanel');
  const submit = document.getElementById('srSubmitBtn');
  if (!ref) { srToast('Enter a sales order, invoice, or receipt number.', 'warn'); return; }
  panel.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Looking up sale…</div>';
  submit.disabled = true;
  srApi({ query: { action: 'lookup_sale', reference: ref } }, r => {
    if (!r.success) {
      _srSale = null;
      panel.innerHTML = `<div class="alert alert-danger py-2 mb-0">${srEsc(r.message || 'Sale not found.')}</div>`;
      return;
    }
    _srSale = r;
    srRenderSale();
  });
}

function srRenderSale() {
  const panel = document.getElementById('srSalePanel');
  const submit = document.getElementById('srSubmitBtn');
  const r = _srSale;
  const s = r.sale;
  const reasons = (_srMeta && _srMeta.reasons) || [];
  const conds = (_srMeta && _srMeta.conditions) || ['Sellable', 'Damaged', 'Missing'];
  const policy = r.policy || (_srMeta && _srMeta.policy) || {};
  const windowNote = s.days_left >= 0
    ? `${s.days_left} day(s) left in the ${s.window_days}-day return window`
    : `outside the ${s.window_days}-day return window`;
  let html = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:13px">
      <div><div class="text-muted small text-uppercase fw-semibold">Sale</div><strong>${srEsc(s.order_number)}</strong></div>
      <div><div class="text-muted small text-uppercase fw-semibold">Invoice</div>${srEsc(s.invoice_number || '—')}</div>
      <div><div class="text-muted small text-uppercase fw-semibold">Receipt(s)</div>${srEsc((s.receipt_numbers || []).join(', ') || '—')}</div>
      <div><div class="text-muted small text-uppercase fw-semibold">Customer</div>${srEsc(s.customer_name)}</div>
      <div><div class="text-muted small text-uppercase fw-semibold">Sale Date</div>${srDate(s.sale_date)}<div class="small ${s.days_left >= 0 ? 'text-muted' : 'text-danger'}">${srEsc(windowNote)}</div></div>
      <div><div class="text-muted small text-uppercase fw-semibold">Payment</div>${srEsc(s.sale_type)} · ${srEsc(s.payment_status || '—')}${Number(s.remaining_balance) > 0 ? `<div class="small text-muted">Balance ${srMoney(s.remaining_balance)}</div>` : ''}</div>
    </div>`;
  if (!r.eligible) {
    panel.innerHTML = html + `<div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>${srEsc(r.blocked_reason)}</div>`;
    submit.disabled = true;
    return;
  }
  html += `
    <div class="table-container system-table-scroll" style="margin-bottom:12px">
      <table class="modern-table">
        <thead><tr><th>Item</th><th>Sold</th><th>Returned / Pending</th><th style="width:96px">Return Qty</th><th style="width:130px">Condition</th><th style="width:190px">Reason</th><th>Value</th></tr></thead>
        <tbody>${r.lines.map(l => `
          <tr data-line="${l.sales_order_item_id}" data-unit-value="${Number(l.unit_value)}">
            <td><strong>${srEsc(l.product_name)}</strong><div class="text-muted small">${srEsc(l.sku)}${l.batch_number ? ' · Lot ' + srEsc(l.batch_number) : ''} · ${srMoney(l.unit_value)}/unit</div></td>
            <td>${l.sold_qty}</td>
            <td>${l.returned_qty}${l.pending_qty ? ` <span class="badge badge-yellow">${l.pending_qty} pending</span>` : ''}</td>
            <td><input type="number" class="form-control form-control-sm sr-qty" min="0" max="${l.returnable_qty}" value="0" ${l.returnable_qty > 0 ? '' : 'disabled'} oninput="srRecalc()" aria-label="Return quantity for ${srEsc(l.product_name)}"><div class="text-muted small">max ${l.returnable_qty}</div></td>
            <td><select class="form-control form-control-sm sr-cond" onchange="srRecalc()" ${l.returnable_qty > 0 ? '' : 'disabled'}>${conds.map(c => `<option value="${srEsc(c)}">${srEsc(c)}</option>`).join('')}</select></td>
            <td><select class="form-control form-control-sm sr-reason" ${l.returnable_qty > 0 ? '' : 'disabled'}><option value="">-- Select reason --</option>${reasons.map(x => `<option value="${srEsc(x)}">${srEsc(x)}</option>`).join('')}</select></td>
            <td class="sr-line-value fw-semibold">${srMoney(0)}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
      <div>
        <label class="form-label">Return Outcome <span style="color:var(--accent-rose,#e11d48)">*</span></label>
        <select class="form-control" id="srOutcome" onchange="srRecalc()">
          <option value="Refund">Refund</option>
          ${policy.return_replacement_allowed ? '<option value="Replacement">Replacement (same product)</option>' : ''}
        </select>
      </div>
      <div>
        <label class="form-label" for="srReturnDate">Return Date <span style="color:var(--accent-rose,#e11d48)">*</span></label>
        <input type="date" class="form-control" id="srReturnDate" value="${srToday()}" max="${srToday()}" min="${String(s.sale_date || '').slice(0, 10)}">
      </div>
      <div>
        <label class="form-label" for="srReceiptRef">Receipt / Transaction Reference</label>
        <input type="text" class="form-control" id="srReceiptRef" maxlength="100" value="${srEsc((s.receipt_numbers || [])[0] || s.invoice_number || '')}">
      </div>
    </div>
    <div class="mt-2">
      <label class="form-label" for="srNotes">Notes</label>
      <textarea class="form-control" id="srNotes" rows="2" maxlength="1000" placeholder="Condition details, packaging, customer remarks…"></textarea>
    </div>
    <div id="srEstimate" class="mt-2" style="font-size:13.5px"></div>
    <div class="text-muted small mt-1">Inventory and billing are updated only after an Admin or Warehouse Head approves this return. Missing items are recorded but never restocked or refunded.</div>`;
  panel.innerHTML = html;
  submit.disabled = false;
  srRecalc();
}

function srCollectLines() {
  const lines = [];
  document.querySelectorAll('#srSalePanel tr[data-line]').forEach(row => {
    const qty = parseInt(row.querySelector('.sr-qty')?.value || 0, 10) || 0;
    lines.push({
      row,
      sales_order_item_id: row.dataset.line,
      quantity: qty,
      stock_condition: row.querySelector('.sr-cond')?.value || 'Sellable',
      reason: row.querySelector('.sr-reason')?.value || '',
      unit_value: parseFloat(row.dataset.unitValue || 0) || 0
    });
  });
  return lines;
}

function srRecalc() {
  if (!_srSale) return;
  const policy = _srSale.policy || {};
  const outcome = document.getElementById('srOutcome')?.value || 'Refund';
  let refund = 0;
  let value = 0;
  let blocked = '';
  srCollectLines().forEach(l => {
    const lineValue = l.quantity * l.unit_value;
    const cell = l.row.querySelector('.sr-line-value');
    if (cell) cell.textContent = srMoney(lineValue);
    if (l.quantity <= 0) return;
    value += lineValue;
    const refundable = l.stock_condition === 'Missing' ? false
      : (l.stock_condition === 'Damaged' ? !!policy.return_refund_damaged : !!policy.return_refund_sellable);
    if (outcome === 'Refund' && l.stock_condition !== 'Missing' && !refundable) {
      blocked = `Refunds are not allowed for ${l.stock_condition.toLowerCase()} items under the current policy. Choose Replacement.`;
    }
    if (outcome === 'Refund' && refundable) refund += lineValue;
  });
  const est = document.getElementById('srEstimate');
  if (!est) return;
  if (blocked) {
    est.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>${srEsc(blocked)}</span>`;
    return;
  }
  if (outcome === 'Refund') {
    const bal = Number(_srSale.sale.remaining_balance || 0);
    const credit = Math.min(refund, bal);
    est.innerHTML = `Returned value: <strong>${srMoney(value)}</strong> · Refund due: <strong class="text-success">${srMoney(refund)}</strong>`
      + (credit > 0 ? ` <span class="text-muted">(${srMoney(credit)} will be credited to the unpaid invoice balance, ${srMoney(refund - credit)} paid back)</span>` : '');
  } else {
    est.innerHTML = `Returned value: <strong>${srMoney(value)}</strong> · The same products will be issued from sellable stock as replacement on approval (Missing items excluded).`;
  }
}

function srSubmitReturn() {
  if (!_srSale || !_srSale.eligible || _srSubmitBusy) return;
  const items = [];
  let problem = '';
  srCollectLines().forEach(l => {
    if (l.quantity <= 0) return;
    const max = parseInt(l.row.querySelector('.sr-qty')?.max || 0, 10) || 0;
    if (l.quantity > max) problem = `Return quantity cannot exceed ${max} for one of the items.`;
    if (!l.reason) problem = 'Select a reason for every item you are returning.';
    items.push({ sales_order_item_id: l.sales_order_item_id, quantity: l.quantity, stock_condition: l.stock_condition, reason: l.reason });
  });
  if (!problem && !items.length) problem = 'Enter a return quantity for at least one item.';
  if (problem) { srToast(problem, 'warn'); return; }

  _srSubmitBusy = true;
  const btn = document.getElementById('srSubmitBtn');
  btn.disabled = true;
  srApi({
    method: 'POST',
    body: {
      action: 'create_return',
      client_token: _srToken,
      sales_order_id: _srSale.sale.sales_order_id,
      outcome: document.getElementById('srOutcome')?.value || 'Refund',
      return_date: document.getElementById('srReturnDate')?.value || '',
      receipt_reference: document.getElementById('srReceiptRef')?.value || '',
      notes: document.getElementById('srNotes')?.value || '',
      items: JSON.stringify(items)
    }
  }, r => {
    _srSubmitBusy = false;
    btn.disabled = false;
    if (!r.success) {
      srToast(r.message || 'Could not record the return', r.duplicate ? 'warn' : 'danger');
      if (r.duplicate) { srClose('srNewReturnModal'); srLoadReturns(); }
      return;
    }
    srToast(`Return ${r.return_number} recorded and sent for review.`);
    srClose('srNewReturnModal');
    srLoadReturns();
  });
}

/* ── View / Review ───────────────────────────────────────── */
function srViewReturn(id) {
  srEnsureModals();
  const body = document.getElementById('srDetailBody');
  const footer = document.getElementById('srDetailFooter');
  body.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading return…</div>';
  footer.innerHTML = '<button class="btn btn-outline-secondary" type="button" onclick="srClose(\'srDetailModal\')">Close</button>';
  srOpen('srDetailModal');
  srLoadMeta(() => {
    srApi({ query: { action: 'get_return', id } }, r => {
      if (!r.success) { body.innerHTML = `<div class="alert alert-danger">${srEsc(r.message || 'Return not found')}</div>`; return; }
      srRenderReturn(r.data);
    });
  });
}

function srRenderReturn(d) {
  const body = document.getElementById('srDetailBody');
  const footer = document.getElementById('srDetailFooter');
  const canReview = !!d.can_review;
  const conds = (_srMeta && _srMeta.conditions) || ['Sellable', 'Damaged', 'Missing'];
  document.getElementById('srDetailTitle').innerHTML = `<i class="bi bi-arrow-return-left text-primary me-1"></i> ${srEsc(d.return_number)} ${srStatusBadge(d.status)}`;
  const field = (label, value) => `<div><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase">${label}</div><div style="font-weight:600">${value}</div></div>`;
  let html = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:16px">
      ${field('Status', srStatusBadge(d.status))}
      ${field('Sale / Invoice', srEsc(d.order_number) + '<div class="text-muted small">' + srEsc(d.invoice_number || '') + '</div>')}
      ${field('Receipt Reference', srEsc(d.receipt_reference || '—'))}
      ${field('Customer', srEsc(d.customer_name || '—'))}
      ${field('Sale Date', srDate(d.sale_date))}
      ${field('Return Date', srDate(d.return_date))}
      ${field('Outcome', srEsc(d.outcome))}
      ${field('Refund', d.outcome === 'Refund' ? srMoney(d.refund_amount) + (Number(d.balance_credit) > 0 ? `<div class="text-muted small">${srMoney(d.balance_credit)} credited to balance · ${srMoney(d.cash_refund)} paid back</div>` : '') : '—')}
      ${field('Recorded By', srEsc(d.created_by_name || '—') + `<div class="text-muted small">${srDate(d.created_at)}</div>`)}
      ${field('Reviewed By', d.reviewed_by_name ? srEsc(d.reviewed_by_name) + `<div class="text-muted small">${srDate(d.reviewed_at)}</div>` : '—')}
      ${d.notes ? `<div style="grid-column:1/-1">${field('Notes', '<span style="font-weight:400">' + srEsc(d.notes) + '</span>')}</div>` : ''}
      ${d.review_remarks ? `<div style="grid-column:1/-1">${field('Review Remarks', '<span style="font-weight:400">' + srEsc(d.review_remarks) + '</span>')}</div>` : ''}
    </div>
    <h6 class="fw-bold mb-2">Returned Items (${(d.items || []).length})</h6>
    <div class="table-container system-table-scroll" style="margin-bottom:12px">
      <table class="modern-table">
        <thead><tr><th>Item</th><th>Sold</th><th>Returned</th><th>Condition</th><th>Reason</th><th>Value</th><th>Refundable</th></tr></thead>
        <tbody>${(d.items || []).map(it => `<tr>
          <td><strong>${srEsc(it.product_name)}</strong><div class="text-muted small">${srEsc(it.sku || '')}</div></td>
          <td>${Number(it.sold_qty || 0)}</td>
          <td>${Number(it.quantity)}</td>
          <td>${canReview
            ? `<select class="form-control form-control-sm sr-review-cond" data-item="${Number(it.id)}" aria-label="Condition for ${srEsc(it.product_name)}">${conds.map(c => `<option value="${srEsc(c)}" ${c === it.stock_condition ? 'selected' : ''}>${srEsc(c)}</option>`).join('')}</select>`
            : srConditionBadge(it.stock_condition)}</td>
          <td>${srEsc(it.reason)}</td>
          <td>${srMoney(it.line_value)}</td>
          <td>${Number(it.refundable) ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-gray">No</span>'}</td>
        </tr>`).join('')}</tbody>
      </table>
    </div>`;
  if (canReview) {
    html += `
      <div style="border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:12px;background:#fff">
        <div class="fw-semibold mb-1"><i class="bi bi-clipboard-check me-1"></i> Review</div>
        <div class="text-muted small mb-2">Inspect the returned goods and confirm each item's condition. Approving restocks sellable items, quarantines damaged items, and applies the ${srEsc(d.outcome.toLowerCase())}. Rejecting makes no stock or billing changes.</div>
        <label class="form-label" for="srReviewRemarks">Review Remarks <span class="text-muted small">(required to reject)</span></label>
        <textarea class="form-control" id="srReviewRemarks" rows="2" maxlength="1000" placeholder="Inspection notes, refund method, or reason for rejection"></textarea>
      </div>`;
  }
  const events = d.events || [];
  if (events.length) {
    html += `<details ${canReview ? '' : 'open'} style="border:1px solid #e2e8f0;border-radius:10px;background:#fff">
      <summary style="padding:10px 14px;font-size:13px;font-weight:600;cursor:pointer"><i class="bi bi-clock-history me-1"></i> History &amp; Audit Trail (${events.length})</summary>
      <div style="padding:0 14px 10px">${events.map(e => {
        const det = e.details || {};
        const bits = [].concat(det.items || [], det.condition_changes && det.condition_changes.length ? ['Condition changed: ' + det.condition_changes.join('; ')] : [], det.outcome ? [String(det.outcome)] : []);
        return `<div style="padding:8px 0;border-top:1px solid #f1f5f9;font-size:12.5px">
          <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><strong>${srEsc(e.action)}</strong><span class="text-muted">${srDate(e.created_at)}</span></div>
          <div class="text-muted">by ${srEsc(e.user_name || 'System')}${e.user_role ? ' (' + srEsc(e.user_role) + ')' : ''}${e.status ? ' · ' + srEsc(e.status) : ''}</div>
          ${e.remarks ? `<div>Remarks: ${srEsc(e.remarks)}</div>` : ''}
          ${bits.length ? `<ul style="margin:4px 0 0 18px;padding:0">${bits.map(b => `<li>${srEsc(b)}</li>`).join('')}</ul>` : ''}
        </div>`;
      }).join('')}</div>
    </details>`;
  }
  body.innerHTML = html;
  let btns = '<button class="btn btn-outline-secondary" type="button" onclick="srClose(\'srDetailModal\')">Close</button>';
  if (canReview) {
    btns += ` <button class="btn btn-outline-danger" type="button" onclick="srReview(${Number(d.id)}, 'reject')"><i class="bi bi-x-circle me-1"></i>Reject</button>`;
    btns += ` <button class="btn btn-success" type="button" onclick="srReview(${Number(d.id)}, 'approve')"><i class="bi bi-check2-circle me-1"></i>Approve &amp; Process</button>`;
  } else if (d.status === 'Pending Review' && _srMeta && _srMeta.can_review) {
    btns += ' <span class="badge badge-yellow" style="align-self:center">Another reviewer must approve a return you recorded</span>';
  } else if (d.status === 'Pending Review') {
    btns += ' <span class="badge badge-yellow" style="align-self:center">Awaiting Admin / Warehouse Head review</span>';
  }
  footer.innerHTML = btns;
}

function srReview(id, decision) {
  if (_srReviewBusy) return;
  const remarks = String(document.getElementById('srReviewRemarks')?.value || '').trim();
  if (decision === 'reject' && !remarks) {
    srToast('Enter the reason for rejecting this return.', 'warn');
    document.getElementById('srReviewRemarks')?.focus();
    return;
  }
  const conditions = {};
  document.querySelectorAll('#srDetailBody .sr-review-cond').forEach(sel => { conditions[sel.dataset.item] = sel.value; });
  const run = () => {
    _srReviewBusy = true;
    srApi({ method: 'POST', body: { action: 'review_return', id, decision, remarks, conditions: JSON.stringify(conditions) } }, r => {
      _srReviewBusy = false;
      if (!r.success) { srToast(r.message || 'Could not complete the review', 'danger'); return; }
      srToast(r.message || 'Return updated');
      srLoadReturns();
      srViewReturn(id);
    });
  };
  const question = decision === 'approve'
    ? 'Approve and process this return? Stock and billing will be updated.'
    : 'Reject this return? No stock or billing changes will be made.';
  if (typeof appConfirm === 'function') appConfirm(question).then(ok => { if (ok) run(); });
  else if (window.confirm(question)) run();
}

/* ── Policy ──────────────────────────────────────────────── */
function srOpenPolicy() {
  srEnsureModals();
  srLoadMeta(meta => {
    const p = meta.policy;
    const editable = !!meta.can_edit_policy;
    const dis = editable ? '' : 'disabled';
    document.getElementById('srPolicyBody').innerHTML = `
      <div class="mb-3">
        <label class="form-label" for="srPolWindow">Return window (days after sale)</label>
        <input type="number" class="form-control" id="srPolWindow" min="0" max="365" value="${Number(p.return_window_days)}" ${dis}>
      </div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="srPolRefundSellable" ${p.return_refund_sellable ? 'checked' : ''} ${dis}><label class="form-check-label" for="srPolRefundSellable">Allow refunds for items returned in sellable condition</label></div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="srPolRefundDamaged" ${p.return_refund_damaged ? 'checked' : ''} ${dis}><label class="form-check-label" for="srPolRefundDamaged">Allow refunds for damaged / defective items</label></div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="srPolReplacement" ${p.return_replacement_allowed ? 'checked' : ''} ${dis}><label class="form-check-label" for="srPolReplacement">Allow replacement (exchange for the same product)</label></div>
      <ul class="text-muted small mb-0" style="padding-left:18px">
        <li>Returned quantities can never exceed what was sold minus earlier or pending returns.</li>
        <li>Sellable items go back to sellable stock; damaged items are quarantined (not sellable); missing items are recorded only.</li>
        <li>Refunds first settle any unpaid invoice balance; the rest is paid back to the customer.</li>
        ${editable ? '' : '<li>Only an Administrator can change this policy.</li>'}
      </ul>`;
    document.getElementById('srPolicyFooter').innerHTML = '<button class="btn btn-outline-secondary" type="button" onclick="srClose(\'srPolicyModal\')">Close</button>'
      + (editable ? ' <button class="btn btn-primary" type="button" onclick="srSavePolicy()"><i class="bi bi-save me-1"></i>Save Policy</button>' : '');
    srOpen('srPolicyModal');
  });
}

function srSavePolicy() {
  const body = {
    action: 'save_policy',
    return_window_days: document.getElementById('srPolWindow')?.value || '0',
    return_refund_sellable: document.getElementById('srPolRefundSellable')?.checked ? '1' : '',
    return_refund_damaged: document.getElementById('srPolRefundDamaged')?.checked ? '1' : '',
    return_replacement_allowed: document.getElementById('srPolReplacement')?.checked ? '1' : ''
  };
  srApi({ method: 'POST', body }, r => {
    if (!r.success) { srToast(r.message || 'Could not save the policy', 'danger'); return; }
    srToast('Return policy saved.');
    _srMeta = null;
    srLoadMeta();
    srClose('srPolicyModal');
  });
}
