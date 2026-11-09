/* ════════════════════════════════════════════════════════════
   warranty_module.js – Product Warranty Certificates
   Completed sale → generate a certificate per warranty-eligible unit
   → print / reprint (A4) · search records · void / reissue (authorized)
   · product warranty coverage + default terms (authorized).
   Warranty actions never create sales or move inventory.
════════════════════════════════════════════════════════════ */

const WC_API = 'warranty_api.php';
const WC_PENDING_KEY = 'clarWarrantyPendingSale';
let _wcMeta = null;
let _wcSale = null;
let _wcBusy = false;
let _wcLoadTimer = null;
let _wcCoverageTimer = null;

function wcEsc(s) {
  if (s === null || s === undefined) return '';
  const d = document.createElement('div');
  d.textContent = String(s);
  return d.innerHTML;
}
function wcDate(v) {
  if (!v) return '—';
  const s = String(v).replace('T', ' ');
  const d = new Date(s.length <= 10 ? s + 'T00:00:00' : s.replace(' ', 'T'));
  if (isNaN(d.getTime())) return wcEsc(v);
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}
function wcToast(msg, type) {
  if (typeof pToast === 'function') pToast(msg, type || 'success');
  else if (typeof soToast === 'function') soToast(msg, type || 'success');
  else alert(msg);
}
function wcOpen(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (typeof openModal === 'function') openModal(id);
  else { el.classList.add('open'); el.style.display = 'flex'; }
}
function wcClose(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (typeof closeModal === 'function') closeModal(id);
  else { el.classList.remove('open'); el.style.display = 'none'; }
}
function wcStatusBadge(s) {
  const map = { Active: 'badge-green', Expired: 'badge-warning', Voided: 'badge-danger' };
  return `<span class="badge ${map[s] || 'badge-blue'}">${wcEsc(s || '—')}</span>`;
}
function wcPrintUrl(params) {
  return 'print_warranty.php?' + new URLSearchParams(params).toString();
}
function wcOpenPrint(params) {
  window.open(wcPrintUrl(params), '_blank');
}

function wcApi(params, cb) {
  const parse = text => { try { return JSON.parse(text); } catch (e) { return null; } };
  if (params.method === 'POST') {
    fetch(WC_API, { method: 'POST', body: new URLSearchParams(params.body), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.text())
      .then(t => cb(parse(t) || { success: false, message: 'Server error occurred' }))
      .catch(() => cb({ success: false, message: 'Network error' }));
    return;
  }
  const url = new URL(WC_API, window.location.href);
  Object.entries(params.query || {}).forEach(([k, v]) => url.searchParams.set(k, v));
  fetch(url, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.text())
    .then(t => cb(parse(t) || { success: false, message: 'Server error occurred' }))
    .catch(() => cb({ success: false, message: 'Network error' }));
}

function wcLoadMeta(cb) {
  if (_wcMeta) { if (cb) cb(_wcMeta); return; }
  wcApi({ query: { action: 'get_meta' } }, r => {
    if (!r.success) { wcToast(r.message || 'Could not load warranty settings', 'danger'); return; }
    _wcMeta = r;
    wcApplyPermissions();
    if (cb) cb(_wcMeta);
  });
}

/* ── Modals (created once) ───────────────────────────────── */
function wcEnsureModals() {
  if (document.getElementById('wcSaleModal')) return;
  const wrap = document.createElement('div');
  wrap.innerHTML = `
  <div class="modal-overlay" id="wcSaleModal">
    <div class="modal modal-lg">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-shield-check text-primary me-1"></i> Warranty Certificates <span id="wcSaleTitle" class="text-muted small"></span></span>
        <button class="modal-close" type="button" onclick="wcClose('wcSaleModal')"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body" id="wcSaleBody"></div>
      <div class="modal-footer" id="wcSaleFooter"></div>
    </div>
  </div>
  <div class="modal-overlay" id="wcDetailModal">
    <div class="modal modal-lg">
      <div class="modal-header">
        <span class="modal-title" id="wcDetailTitle"><i class="bi bi-shield-check"></i> Warranty Certificate</span>
        <button class="modal-close" type="button" onclick="wcClose('wcDetailModal')"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body" id="wcDetailBody"></div>
      <div class="modal-footer" id="wcDetailFooter"></div>
    </div>
  </div>
  <div class="modal-overlay" id="wcActionModal">
    <div class="modal">
      <div class="modal-header">
        <span class="modal-title" id="wcActionTitle"></span>
        <button class="modal-close" type="button" onclick="wcClose('wcActionModal')"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body" id="wcActionBody"></div>
      <div class="modal-footer" id="wcActionFooter"></div>
    </div>
  </div>`;
  while (wrap.firstElementChild) document.body.appendChild(wrap.firstElementChild);
}

/* ── Sale → certificates ─────────────────────────────────── */
function wcOpenSaleWarranty(soId) {
  if (!soId) return;
  wcEnsureModals();
  if (document.getElementById('viewSalesOrderModal')?.classList.contains('open')) wcClose('viewSalesOrderModal');
  document.getElementById('wcSaleTitle').textContent = '';
  document.getElementById('wcSaleBody').innerHTML = '<p class="text-muted mb-0"><span class="spinner-border spinner-border-sm me-2"></span>Loading sale…</p>';
  document.getElementById('wcSaleFooter').innerHTML = '<button class="btn btn-outline-secondary" type="button" onclick="wcClose(\'wcSaleModal\')">Close</button>';
  wcOpen('wcSaleModal');
  wcLoadMeta(() => wcRefreshSale(soId));
}

function wcRefreshSale(soId) {
  wcApi({ query: { action: 'get_sale_warranty', so_id: soId } }, r => {
    const body = document.getElementById('wcSaleBody');
    if (!body) return;
    if (!r.success) {
      _wcSale = null;
      body.innerHTML = `<div class="alert alert-danger mb-0">${wcEsc(r.message || 'Could not load the sale.')}</div>`;
      return;
    }
    _wcSale = r;
    wcRenderSale();
  });
}

function wcRenderSale() {
  const r = _wcSale;
  const body = document.getElementById('wcSaleBody');
  const foot = document.getElementById('wcSaleFooter');
  if (!r || !body) return;
  const s = r.sale;
  const canVoid = !!r.can_void;
  document.getElementById('wcSaleTitle').textContent = '· ' + (s.order_number || '');
  const eligible = (r.lines || []).filter(l => l.warranty_eligible);
  const notCovered = (r.lines || []).filter(l => !l.warranty_eligible);
  let html = `<div class="row g-2 mb-3">
    <div class="col-md-4"><div class="text-muted small">Customer</div><strong>${wcEsc(s.customer_name)}</strong></div>
    <div class="col-md-4"><div class="text-muted small">Sales Invoice / Order</div><strong>${wcEsc(s.invoice_number || '—')}</strong><div class="small text-muted">${wcEsc(s.order_number)}</div></div>
    <div class="col-md-4"><div class="text-muted small">Purchase Date</div><strong>${wcDate(s.purchase_date)}</strong></div>
  </div>`;
  if (r.blocked_reason) {
    html += `<div class="alert alert-warning py-2 small">${wcEsc(r.blocked_reason)}</div>`;
  }
  if (!eligible.length) {
    html += `<div class="alert alert-info py-2 small mb-0"><i class="bi bi-info-circle me-1"></i>No item on this sale is configured for warranty coverage, so no certificate is issued. Consumables and products without warranty coverage do not receive certificates.</div>`;
    body.innerHTML = html;
    foot.innerHTML = '<button class="btn btn-outline-secondary" type="button" onclick="wcClose(\'wcSaleModal\')">Close</button>';
    return;
  }
  const canGenerate = !r.blocked_reason;
  if (canGenerate) {
    html += `<div class="row g-2 mb-3 align-items-end">
      <div class="col-md-6">
        <label class="form-label small mb-1" for="wcContact">Customer Contact Number <span class="text-muted">(optional)</span></label>
        <input type="text" id="wcContact" class="form-control form-control-sm" maxlength="60" value="${wcEsc(s.customer_contact || '')}" placeholder="e.g. 0917 123 4567">
      </div>
      <div class="col-md-6 small text-muted">Enter the serial number printed on each unit when available. Each sold unit gets its own certificate.</div>
    </div>`;
  }
  eligible.forEach(l => {
    const sub = [l.sku, l.brand, l.model_number ? 'Model ' + l.model_number : ''].filter(Boolean).map(wcEsc).join(' · ');
    html += `<div class="border rounded p-2 mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
        <div><strong>${wcEsc(l.product_name)}</strong><div class="small text-muted">${sub}</div></div>
        <div class="text-end small"><span class="badge badge-blue">${wcEsc(l.duration_label)} warranty</span><div class="text-muted mt-1">Expires ${wcDate(l.expiry_preview)}</div></div>
      </div>
      ${l.refunded_qty > 0 ? `<div class="small text-muted mb-2">${l.refunded_qty} unit(s) refunded through a customer return are not covered.</div>` : ''}
      <div class="table-container system-table-scroll"><table class="modern-table"><thead><tr><th>Unit</th><th>Serial / Unit ID</th><th>Certificate</th><th>Actions</th></tr></thead><tbody>`;
    (l.units || []).forEach(u => {
      const a = u.active;
      const key = l.sales_order_item_id + '-' + u.unit_seq;
      let serialCell;
      let certCell;
      let actions = '';
      if (!u.covered) {
        serialCell = '<span class="text-muted small">—</span>';
        certCell = a ? `<strong>${wcEsc(a.certificate_number)}</strong> ${wcStatusBadge(a.display_status)}` : '<span class="text-muted small">Refunded — not covered</span>';
        if (a) actions = `<button class="btn btn-sm btn-outline-primary" type="button" onclick="wcOpenPrint({id:${Number(a.id)}})"><i class="bi bi-printer me-1"></i>Reprint</button>`;
        if (a && canVoid) actions += ` <button class="btn btn-sm btn-outline-danger" type="button" onclick="wcStartVoid(${Number(a.id)}, '${wcEsc(a.certificate_number)}', true)">Void</button>`;
      } else if (a) {
        serialCell = a.serial_number ? `<code>${wcEsc(a.serial_number)}</code>` : '<span class="text-muted small">Not recorded</span>';
        certCell = `<strong>${wcEsc(a.certificate_number)}</strong> ${wcStatusBadge(a.display_status)}<div class="small text-muted">Issued ${wcDate(a.issued_at)}${a.issued_by_name ? ' by ' + wcEsc(a.issued_by_name) : ''}${Number(a.print_count) > 0 ? ' · printed ' + Number(a.print_count) + '×' : ''}</div>`;
        actions = `<button class="btn btn-sm btn-primary" type="button" onclick="wcOpenPrint({id:${Number(a.id)}})"><i class="bi bi-printer me-1"></i>${Number(a.print_count) > 0 ? 'Reprint' : 'Print'}</button>`;
        if (canVoid) {
          actions += ` <button class="btn btn-sm btn-outline-secondary" type="button" onclick="wcStartReissue(${l.sales_order_item_id}, ${u.unit_seq}, '${wcEsc(a.certificate_number)}', '${wcEsc(a.serial_number || '')}')">Reissue</button>`;
          actions += ` <button class="btn btn-sm btn-outline-danger" type="button" onclick="wcStartVoid(${Number(a.id)}, '${wcEsc(a.certificate_number)}', true)">Void</button>`;
        }
      } else if ((u.history || []).length) {
        const last = u.history[u.history.length - 1];
        serialCell = last.serial_number ? `<code>${wcEsc(last.serial_number)}</code>` : '<span class="text-muted small">—</span>';
        certCell = `<span class="text-muted small">${wcEsc(last.certificate_number)} voided${last.void_reason ? ': ' + wcEsc(last.void_reason) : ''}</span>`;
        actions = canVoid && canGenerate
          ? `<button class="btn btn-sm btn-outline-primary" type="button" onclick="wcStartReissue(${l.sales_order_item_id}, ${u.unit_seq}, '${wcEsc(last.certificate_number)}', '${wcEsc(last.serial_number || '')}')">Reissue</button>`
          : '<span class="small text-muted">Reissue requires an authorized user</span>';
      } else {
        serialCell = canGenerate
          ? `<input type="text" class="form-control form-control-sm wc-serial" data-key="${key}" maxlength="100" placeholder="Serial number (optional)">`
          : '<span class="text-muted small">—</span>';
        certCell = '<span class="text-muted small">Not generated</span>';
        actions = canGenerate
          ? `<button class="btn btn-sm btn-success" type="button" onclick="wcGenerateUnit(${l.sales_order_item_id}, ${u.unit_seq})"><i class="bi bi-shield-plus me-1"></i>Generate Warranty</button>`
          : '';
      }
      html += `<tr><td>${u.unit_seq} of ${l.quantity}</td><td style="min-width:180px">${serialCell}</td><td>${certCell}</td><td class="text-nowrap">${actions}</td></tr>`;
    });
    html += '</tbody></table></div></div>';
  });
  if (notCovered.length) {
    html += `<div class="small text-muted"><i class="bi bi-dash-circle me-1"></i>Not warranty-covered: ${notCovered.map(l => wcEsc(l.product_name)).join(', ')}</div>`;
  }
  body.innerHTML = html;

  const pending = eligible.reduce((n, l) => n + (l.units || []).filter(u => u.covered && !u.active && !(u.history || []).length).length, 0);
  let footHtml = '<button class="btn btn-outline-secondary" type="button" onclick="wcClose(\'wcSaleModal\')">Close</button>';
  if (r.issued_units > 0) {
    footHtml += ` <button class="btn btn-outline-primary" type="button" onclick="wcOpenPrint({so_id:${Number(s.id)}})"><i class="bi bi-printer me-1"></i>Print All Certificates</button>`;
  }
  if (canGenerate && pending > 1) {
    footHtml += ` <button class="btn btn-success" type="button" id="wcGenAllBtn" onclick="wcGenerateAll()"><i class="bi bi-shield-plus me-1"></i>Generate All Remaining (${pending})</button>`;
  }
  foot.innerHTML = footHtml;
}

function wcContactValue() {
  return (document.getElementById('wcContact')?.value || '').trim();
}

function wcIssueRequest(body, cb) {
  wcApi({ method: 'POST', body: Object.assign({ action: 'issue_warranty' }, body) }, cb);
}

function wcGenerateUnit(soiId, unitSeq) {
  if (_wcBusy || !_wcSale) return;
  const serial = (document.querySelector(`.wc-serial[data-key="${soiId}-${unitSeq}"]`)?.value || '').trim();
  _wcBusy = true;
  wcIssueRequest({
    sales_order_id: _wcSale.sale.id,
    sales_order_item_id: soiId,
    unit_seq: unitSeq,
    serial_number: serial,
    customer_contact: wcContactValue()
  }, r => {
    _wcBusy = false;
    if (!r.success) { wcToast(r.message || 'Could not generate the warranty', 'danger'); return; }
    wcToast('Warranty certificate ' + r.certificate_number + ' generated');
    wcRefreshSale(_wcSale.sale.id);
    wcOpenPrint({ id: r.id });
    if (typeof wcLoadRecords === 'function' && document.getElementById('wcRecordsBody')) wcLoadRecords();
  });
}

function wcGenerateAll() {
  if (_wcBusy || !_wcSale) return;
  const queue = [];
  (_wcSale.lines || []).filter(l => l.warranty_eligible).forEach(l => {
    (l.units || []).forEach(u => {
      if (u.covered && !u.active && !(u.history || []).length) {
        queue.push({ soi: l.sales_order_item_id, seq: u.unit_seq, serial: (document.querySelector(`.wc-serial[data-key="${l.sales_order_item_id}-${u.unit_seq}"]`)?.value || '').trim() });
      }
    });
  });
  if (!queue.length) return;
  _wcBusy = true;
  const btn = document.getElementById('wcGenAllBtn');
  if (btn) btn.disabled = true;
  const contact = wcContactValue();
  const soId = _wcSale.sale.id;
  let made = 0;
  const errors = [];
  const next = () => {
    const item = queue.shift();
    if (!item) {
      _wcBusy = false;
      if (made) wcToast(made + ' warranty certificate(s) generated');
      if (errors.length) wcToast(errors[0], 'danger');
      wcRefreshSale(soId);
      if (made) wcOpenPrint({ so_id: soId });
      if (document.getElementById('wcRecordsBody')) wcLoadRecords();
      return;
    }
    wcIssueRequest({ sales_order_id: soId, sales_order_item_id: item.soi, unit_seq: item.seq, serial_number: item.serial, customer_contact: contact }, r => {
      if (r.success) made++;
      else errors.push(r.message || 'Could not generate a certificate');
      next();
    });
  };
  next();
}

/* ── Void / reissue (authorized) ─────────────────────────── */
function wcStartVoid(certId, certNo, fromSale) {
  wcEnsureModals();
  document.getElementById('wcActionTitle').innerHTML = '<i class="bi bi-x-octagon text-danger me-1"></i> Void Certificate ' + wcEsc(certNo);
  document.getElementById('wcActionBody').innerHTML = `
    <p class="small text-muted">Voiding marks this certificate as no longer valid. The record stays in the system for audit, and a voided copy prints with a VOID mark.</p>
    <label class="form-label" for="wcActionReason">Reason <span class="text-danger">*</span></label>
    <textarea id="wcActionReason" class="form-control" rows="3" maxlength="255" placeholder="e.g. Unit returned and refunded"></textarea>`;
  document.getElementById('wcActionFooter').innerHTML = `
    <button class="btn btn-outline-secondary" type="button" onclick="wcClose('wcActionModal')">Cancel</button>
    <button class="btn btn-danger" type="button" id="wcActionBtn" onclick="wcSubmitVoid(${Number(certId)}, ${fromSale ? 'true' : 'false'})">Void Certificate</button>`;
  wcOpen('wcActionModal');
  setTimeout(() => document.getElementById('wcActionReason')?.focus(), 60);
}

function wcSubmitVoid(certId, fromSale) {
  if (_wcBusy) return;
  const reason = (document.getElementById('wcActionReason')?.value || '').trim();
  if (!reason) { wcToast('Enter the reason for voiding this certificate.', 'warn'); return; }
  _wcBusy = true;
  const btn = document.getElementById('wcActionBtn');
  if (btn) btn.disabled = true;
  wcApi({ method: 'POST', body: { action: 'void_warranty', id: certId, reason } }, r => {
    _wcBusy = false;
    if (btn) btn.disabled = false;
    if (!r.success) { wcToast(r.message || 'Could not void the certificate', 'danger'); return; }
    wcToast('Certificate voided');
    wcClose('wcActionModal');
    if (fromSale && _wcSale) wcRefreshSale(_wcSale.sale.id);
    if (document.getElementById('wcDetailModal')?.classList.contains('open')) wcViewCertificate(certId);
    if (document.getElementById('wcRecordsBody')) wcLoadRecords();
  });
}

function wcStartReissue(soiId, unitSeq, certNo, serial) {
  wcEnsureModals();
  document.getElementById('wcActionTitle').innerHTML = '<i class="bi bi-arrow-repeat text-primary me-1"></i> Reissue Certificate';
  document.getElementById('wcActionBody').innerHTML = `
    <p class="small text-muted">Reissuing creates a new certificate number for this unit${certNo ? ' and voids <strong>' + wcEsc(certNo) + '</strong>' : ''}. Coverage dates stay tied to the original purchase date. To print another copy of the same certificate, use Reprint instead.</p>
    <label class="form-label" for="wcActionSerial">Serial / Unit ID</label>
    <input type="text" id="wcActionSerial" class="form-control mb-2" maxlength="100" value="${wcEsc(serial || '')}">
    <label class="form-label" for="wcActionReason">Reason <span class="text-danger">*</span></label>
    <textarea id="wcActionReason" class="form-control" rows="3" maxlength="255" placeholder="e.g. Serial number corrected"></textarea>`;
  document.getElementById('wcActionFooter').innerHTML = `
    <button class="btn btn-outline-secondary" type="button" onclick="wcClose('wcActionModal')">Cancel</button>
    <button class="btn btn-primary" type="button" id="wcActionBtn" onclick="wcSubmitReissue(${Number(soiId)}, ${Number(unitSeq)})">Reissue Certificate</button>`;
  wcOpen('wcActionModal');
  setTimeout(() => document.getElementById('wcActionReason')?.focus(), 60);
}

function wcSubmitReissue(soiId, unitSeq) {
  if (_wcBusy || !_wcSale) return;
  const reason = (document.getElementById('wcActionReason')?.value || '').trim();
  if (!reason) { wcToast('Enter the reason for reissuing this certificate.', 'warn'); return; }
  _wcBusy = true;
  const btn = document.getElementById('wcActionBtn');
  if (btn) btn.disabled = true;
  wcIssueRequest({
    sales_order_id: _wcSale.sale.id,
    sales_order_item_id: soiId,
    unit_seq: unitSeq,
    serial_number: (document.getElementById('wcActionSerial')?.value || '').trim(),
    customer_contact: wcContactValue(),
    reissue: 1,
    reason
  }, r => {
    _wcBusy = false;
    if (btn) btn.disabled = false;
    if (!r.success) { wcToast(r.message || 'Could not reissue the certificate', 'danger'); return; }
    wcToast('Certificate reissued as ' + r.certificate_number);
    wcClose('wcActionModal');
    wcRefreshSale(_wcSale.sale.id);
    wcOpenPrint({ id: r.id });
    if (document.getElementById('wcRecordsBody')) wcLoadRecords();
  });
}

/* ── Sales order view hook ───────────────────────────────── */
/** Called by openSalesOrderView after it renders; adds warranty status + button for eligible sales. */
function wcDecorateSalesOrderView(order) {
  if (!order || !order.id) return;
  const soId = Number(order.id);
  wcApi({ query: { action: 'get_sale_warranty', so_id: soId } }, r => {
    if (!r.success) return;
    const eligibleLines = (r.lines || []).filter(l => l.warranty_eligible);
    if (!eligibleLines.length) return;
    const body = document.getElementById('soViewBody');
    const foot = document.getElementById('soViewFooter');
    const numEl = document.getElementById('soViewNumber');
    if (!body || !foot || (numEl && numEl.textContent !== String(order.order_number || ''))) return;
    const names = eligibleLines.map(l => wcEsc(l.product_name)).join(', ');
    let note;
    if (r.blocked_reason) {
      note = `<div class="alert alert-secondary py-2 small mt-3 mb-0"><i class="bi bi-shield me-1"></i>Warranty-eligible: ${names}. ${wcEsc(r.blocked_reason)}</div>`;
    } else {
      note = `<div class="alert alert-info py-2 small mt-3 mb-0"><i class="bi bi-shield-check me-1"></i>Warranty-eligible: ${names}. Certificates generated for <strong>${Number(r.issued_units)} of ${Number(r.eligible_units)}</strong> unit(s).</div>`;
      const label = r.issued_units >= r.eligible_units && r.eligible_units > 0 ? 'Print Warranty Certificate' : 'Generate Warranty';
      foot.insertAdjacentHTML('beforeend', ` <button class="btn btn-primary" type="button" onclick="wcOpenSaleWarranty(${soId})"><i class="bi bi-shield-check me-1"></i>${label}</button>`);
    }
    body.insertAdjacentHTML('beforeend', note);
  });
}

/** POS hook: remember the sale so the warranty prompt can appear after the page reloads. */
function wcRememberSale(soId) {
  try { if (soId) sessionStorage.setItem(WC_PENDING_KEY, String(soId)); } catch (e) {}
}

function wcCheckPendingSale() {
  let soId = '';
  try {
    soId = sessionStorage.getItem(WC_PENDING_KEY) || '';
    sessionStorage.removeItem(WC_PENDING_KEY);
  } catch (e) { return; }
  if (!soId) return;
  wcApi({ query: { action: 'get_sale_warranty', so_id: soId } }, r => {
    if (!r.success || r.blocked_reason) return;
    const eligible = (r.lines || []).filter(l => l.warranty_eligible);
    if (!eligible.length || r.issued_units >= r.eligible_units) return;
    wcEnsureModals();
    document.getElementById('wcActionTitle').innerHTML = '<i class="bi bi-shield-check text-primary me-1"></i> Warranty Available';
    document.getElementById('wcActionBody').innerHTML = `
      <p class="mb-2">Sale <strong>${wcEsc(r.sale.order_number)}</strong> for <strong>${wcEsc(r.sale.customer_name)}</strong> includes warranty-eligible item(s):</p>
      <ul class="small mb-2">${eligible.map(l => `<li>${wcEsc(l.product_name)} × ${Number(l.quantity)} — ${wcEsc(l.duration_label)}</li>`).join('')}</ul>
      <p class="small text-muted mb-0">Generate and print the warranty certificate to give to the customer. You can also do this later from the sales order.</p>`;
    document.getElementById('wcActionFooter').innerHTML = `
      <button class="btn btn-outline-secondary" type="button" onclick="wcClose('wcActionModal')">Later</button>
      <button class="btn btn-primary" type="button" onclick="wcClose('wcActionModal'); wcOpenSaleWarranty(${Number(r.sale.id)})"><i class="bi bi-shield-plus me-1"></i>Generate Warranty</button>`;
    wcOpen('wcActionModal');
  });
}

/* ── Warranty records section ────────────────────────────── */
function wcApplyPermissions() {
  if (!_wcMeta) return;
  document.querySelectorAll('[data-wc-manage]').forEach(el => { el.style.display = _wcMeta.can_manage ? '' : 'none'; });
}

function wcShowTab(tab) {
  document.querySelectorAll('#section-warranties [data-wc-tab]').forEach(b => b.classList.toggle('active', b.getAttribute('data-wc-tab') === tab));
  document.querySelectorAll('#section-warranties [data-wc-pane]').forEach(p => { p.style.display = p.getAttribute('data-wc-pane') === tab ? '' : 'none'; });
  if (tab === 'records') wcLoadRecords();
  if (tab === 'coverage') wcLoadCoverage();
  if (tab === 'terms') wcLoadTermsEditor();
}

function wcInitSection() {
  wcEnsureModals();
  wcLoadMeta(() => wcShowTab(document.querySelector('#section-warranties [data-wc-tab].active')?.getAttribute('data-wc-tab') || 'records'));
}

function wcScheduleLoad() {
  clearTimeout(_wcLoadTimer);
  _wcLoadTimer = setTimeout(wcLoadRecords, 300);
}

function wcLoadRecords() {
  const tbody = document.getElementById('wcRecordsBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading warranty records…</td></tr>';
  const status = document.getElementById('wcStatusFilter')?.value || 'all';
  const search = document.getElementById('wcSearch')?.value || '';
  wcApi({ query: { action: 'list_warranties', status, search } }, r => {
    if (!r.success) {
      tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-danger">${wcEsc(r.message || 'Could not load warranty records')}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-muted">${search || status !== 'all' ? 'No warranty records match your search.' : 'No warranty certificates issued yet. Generate one from a completed sales order.'}</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(x => `<tr>
      <td><strong style="font-family:monospace" class="text-primary">${wcEsc(x.certificate_number)}</strong></td>
      <td>${wcEsc(x.customer_name)}${x.customer_contact ? `<div class="text-muted small">${wcEsc(x.customer_contact)}</div>` : ''}</td>
      <td>${wcEsc(x.product_name)}<div class="text-muted small">${[x.brand, x.model_number].filter(Boolean).map(wcEsc).join(' · ')}</div></td>
      <td>${x.serial_number ? `<code>${wcEsc(x.serial_number)}</code>` : '<span class="text-muted small">Unit ' + Number(x.unit_seq) + '</span>'}</td>
      <td>${wcEsc(x.invoice_number || '—')}<div class="text-muted small">${wcEsc(x.order_number || '')}</div></td>
      <td>${wcDate(x.purchase_date)}<div class="text-muted small">${wcEsc(x.duration_label)}</div></td>
      <td>${wcDate(x.expiry_date)}</td>
      <td>${wcStatusBadge(x.display_status)}</td>
      <td class="text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="wcViewCertificate(${Number(x.id)})"><i class="bi bi-eye me-1"></i>View</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="wcOpenPrint({id:${Number(x.id)}})"><i class="bi bi-printer me-1"></i>${Number(x.print_count) > 0 ? 'Reprint' : 'Print'}</button>
      </td>
    </tr>`).join('');
  });
}

function wcViewCertificate(id) {
  wcEnsureModals();
  const body = document.getElementById('wcDetailBody');
  body.innerHTML = '<p class="text-muted mb-0">Loading…</p>';
  document.getElementById('wcDetailFooter').innerHTML = '<button class="btn btn-outline-secondary" type="button" onclick="wcClose(\'wcDetailModal\')">Close</button>';
  wcOpen('wcDetailModal');
  wcApi({ query: { action: 'get_warranty', id } }, r => {
    if (!r.success) { body.innerHTML = `<div class="alert alert-danger mb-0">${wcEsc(r.message || 'Certificate not found')}</div>`; return; }
    const c = r.data;
    document.getElementById('wcDetailTitle').innerHTML = '<i class="bi bi-shield-check text-primary me-1"></i> ' + wcEsc(c.certificate_number) + ' ' + wcStatusBadge(c.display_status);
    const cell = (k, v) => `<div class="col-md-4"><div class="text-muted small">${k}</div><strong>${v}</strong></div>`;
    let html = `<div class="row g-3 mb-3">
      ${cell('Customer', wcEsc(c.customer_name))}
      ${cell('Contact Number', wcEsc(c.customer_contact || '—'))}
      ${cell('Sales Invoice / Order', wcEsc(c.invoice_number || '—') + '<div class="small text-muted fw-normal">' + wcEsc(c.order_number || '') + '</div>')}
      ${cell('Product', wcEsc(c.product_name) + '<div class="small text-muted fw-normal">' + wcEsc(c.sku || '') + '</div>')}
      ${cell('Brand / Model', wcEsc([c.brand, c.model_number].filter(Boolean).join(' / ') || '—'))}
      ${cell('Serial / Unit ID', c.serial_number ? wcEsc(c.serial_number) : 'Unit ' + Number(c.unit_seq))}
      ${cell('Purchase Date', wcDate(c.purchase_date))}
      ${cell('Warranty Duration', wcEsc(c.duration_label))}
      ${cell('Coverage', wcDate(c.start_date) + ' – ' + wcDate(c.expiry_date))}
      ${cell('Issued By', wcEsc(c.issued_by_name || '—') + '<div class="small text-muted fw-normal">' + wcDate(c.issued_at) + '</div>')}
      ${cell('Printed', Number(c.print_count) + ' time(s)')}
      ${c.reissued_from_number ? cell('Replaces', wcEsc(c.reissued_from_number)) : ''}
    </div>`;
    if (c.status === 'Voided') {
      html += `<div class="alert alert-danger py-2 small">Voided ${wcDate(c.voided_at)}${c.voided_by_name ? ' by ' + wcEsc(c.voided_by_name) : ''}${c.void_reason ? ' — ' + wcEsc(c.void_reason) : ''}</div>`;
    }
    html += '<h6 class="mt-2">History</h6><ul class="small mb-0">';
    (c.events || []).forEach(e => {
      html += `<li><strong>${wcEsc(e.action)}</strong> · ${wcDate(e.created_at)} ${wcEsc(String(e.created_at || '').slice(11, 16))}${e.user_name ? ' · ' + wcEsc(e.user_name) : ''}${e.remarks ? ' — ' + wcEsc(e.remarks) : ''}</li>`;
    });
    html += '</ul>';
    body.innerHTML = html;
    let foot = '<button class="btn btn-outline-secondary" type="button" onclick="wcClose(\'wcDetailModal\')">Close</button>';
    foot += ` <button class="btn btn-outline-primary" type="button" onclick="wcOpenSaleWarranty(${Number(c.sales_order_id)})">Open Sale</button>`;
    if (r.can_void && c.status !== 'Voided') {
      foot += ` <button class="btn btn-outline-danger" type="button" onclick="wcStartVoid(${Number(c.id)}, '${wcEsc(c.certificate_number)}', false)">Void</button>`;
    }
    foot += ` <button class="btn btn-primary" type="button" onclick="wcOpenPrint({id:${Number(c.id)}})"><i class="bi bi-printer me-1"></i>${Number(c.print_count) > 0 ? 'Reprint' : 'Print'} Certificate</button>`;
    document.getElementById('wcDetailFooter').innerHTML = foot;
  });
}

function wcFindSaleByReference() {
  const ref = (document.getElementById('wcSaleReference')?.value || '').trim();
  if (!ref) { wcToast('Enter a sales order, invoice, or official receipt number.', 'warn'); return; }
  wcApi({ query: { action: 'get_sale_warranty', reference: ref } }, r => {
    if (!r.success) { wcToast(r.message || 'Sale not found', 'danger'); return; }
    wcOpenSaleWarranty(r.sale.id);
  });
}

/* ── Product coverage (Product Management settings) ──────── */
function wcScheduleCoverage() {
  clearTimeout(_wcCoverageTimer);
  _wcCoverageTimer = setTimeout(wcLoadCoverage, 300);
}

function wcUnitOptions(selected) {
  const units = (_wcMeta && _wcMeta.units) || { days: 'Day(s)', months: 'Month(s)', years: 'Year(s)' };
  return Object.entries(units).map(([k, v]) => `<option value="${k}"${k === selected ? ' selected' : ''}>${wcEsc(v)}</option>`).join('');
}

function wcLoadCoverage() {
  const tbody = document.getElementById('wcCoverageBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="6" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading products…</td></tr>';
  const search = document.getElementById('wcCoverageSearch')?.value || '';
  const eligibleOnly = document.getElementById('wcCoverageEligibleOnly')?.checked ? 1 : 0;
  wcApi({ query: { action: 'list_product_warranty', search, eligible_only: eligibleOnly } }, r => {
    if (!r.success) { tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${wcEsc(r.message || 'Could not load products')}</td></tr>`; return; }
    const rows = r.data || [];
    const canManage = !!r.can_manage;
    if (!rows.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No products found.</td></tr>'; return; }
    tbody.innerHTML = rows.map(p => {
      const pid = Number(p.id);
      if (!canManage) {
        return `<tr><td><strong>${wcEsc(p.name)}</strong><div class="small text-muted">${wcEsc(p.sku)}</div></td><td>${wcEsc(p.category_name || '—')}</td><td>${wcEsc(p.brand || '—')}</td><td>${wcEsc(p.model_number || '—')}</td>
          <td>${p.warranty_enabled ? '<span class="badge badge-green">Covered</span>' : '<span class="badge badge-blue">No warranty</span>'}</td><td>${p.warranty_enabled ? wcEsc(p.duration_label) : '—'}</td></tr>`;
      }
      return `<tr data-wc-pid="${pid}">
        <td><strong>${wcEsc(p.name)}</strong><div class="small text-muted">${wcEsc(p.sku)}</div></td>
        <td>${wcEsc(p.category_name || '—')}</td>
        <td>${wcEsc(p.brand || '—')}</td>
        <td><input type="text" class="form-control form-control-sm wc-cov-model" maxlength="120" value="${wcEsc(p.model_number || '')}" placeholder="Model"></td>
        <td><div class="form-check form-switch mb-0"><input class="form-check-input wc-cov-enabled" type="checkbox" ${p.warranty_enabled ? 'checked' : ''} aria-label="Warranty coverage"></div></td>
        <td class="text-nowrap"><div class="d-flex gap-1">
          <input type="number" class="form-control form-control-sm wc-cov-value" min="1" step="1" style="width:76px" value="${p.duration_value > 0 ? Number(p.duration_value) : ''}" placeholder="—">
          <select class="form-select form-select-sm wc-cov-unit" style="width:110px">${wcUnitOptions(p.duration_unit || 'months')}</select>
          <button type="button" class="btn btn-sm btn-primary" onclick="wcSaveCoverageRow(${pid}, this)">Save</button>
        </div></td>
      </tr>`;
    }).join('');
  });
}

function wcSaveCoverageRow(pid, btn) {
  const tr = document.querySelector(`tr[data-wc-pid="${pid}"]`);
  if (!tr) return;
  const enabled = tr.querySelector('.wc-cov-enabled')?.checked;
  const value = (tr.querySelector('.wc-cov-value')?.value || '').trim();
  if (enabled && !value) { wcToast('Enter the warranty duration for this product.', 'warn'); return; }
  if (btn) btn.disabled = true;
  wcApi({ method: 'POST', body: {
    action: 'save_product_warranty',
    product_id: pid,
    warranty_enabled: enabled ? 1 : 0,
    warranty_duration_value: value,
    warranty_duration_unit: tr.querySelector('.wc-cov-unit')?.value || 'months',
    warranty_model_number: (tr.querySelector('.wc-cov-model')?.value || '').trim()
  } }, r => {
    if (btn) btn.disabled = false;
    if (!r.success) { wcToast(r.message || 'Could not save warranty settings', 'danger'); return; }
    wcToast(r.changes && r.changes.length ? 'Warranty settings saved' : 'No changes to save');
  });
}

/* ── Terms editor ────────────────────────────────────────── */
function wcLoadTermsEditor() {
  const ta = document.getElementById('wcTermsText');
  if (!ta) return;
  _wcMeta = null;
  wcLoadMeta(m => {
    ta.value = m.terms || '';
    ta.readOnly = !m.can_manage;
  });
}

function wcResetTerms() {
  const ta = document.getElementById('wcTermsText');
  if (ta && _wcMeta) ta.value = _wcMeta.default_terms || '';
}

function wcSaveTerms() {
  const ta = document.getElementById('wcTermsText');
  if (!ta || _wcBusy) return;
  _wcBusy = true;
  wcApi({ method: 'POST', body: { action: 'save_terms', terms: ta.value } }, r => {
    _wcBusy = false;
    if (!r.success) { wcToast(r.message || 'Could not save the warranty terms', 'danger'); return; }
    if (_wcMeta) _wcMeta.terms = r.terms;
    ta.value = r.terms;
    wcToast('Warranty terms saved. New certificates will use these terms.');
  });
}

/* ── Product edit modal hook (Product Management) ───────── */
function wcLoadProductEditFields(sku) {
  const box = document.getElementById('editWarrantyFields');
  if (!box || !sku) return;
  const enabledEl = document.getElementById('edit_warranty_enabled');
  const valueEl = document.getElementById('edit_warranty_duration_value');
  const unitEl = document.getElementById('edit_warranty_duration_unit');
  const modelEl = document.getElementById('edit_warranty_model_number');
  const flagEl = document.getElementById('edit_warranty_loaded');
  if (flagEl) flagEl.value = '0';
  if (enabledEl) enabledEl.value = '0';
  if (valueEl) valueEl.value = '';
  if (unitEl) unitEl.value = 'months';
  if (modelEl) modelEl.value = '';
  wcSyncProductEditFields();
  wcApi({ query: { action: 'get_product_warranty', sku } }, r => {
    if (!r.success || !r.data) return;
    if (document.getElementById('edit_sku')?.value !== sku) return;
    const d = r.data;
    if (enabledEl) enabledEl.value = d.warranty_enabled ? '1' : '0';
    if (valueEl) valueEl.value = d.duration_value > 0 ? d.duration_value : '';
    if (unitEl) unitEl.value = d.duration_unit || 'months';
    if (modelEl) modelEl.value = d.model_number || '';
    if (flagEl) flagEl.value = '1';
    wcSyncProductEditFields();
  });
}

function wcSyncProductEditFields() {
  const on = document.getElementById('edit_warranty_enabled')?.value === '1';
  const valueEl = document.getElementById('edit_warranty_duration_value');
  if (valueEl) valueEl.required = on;
  document.querySelectorAll('#editWarrantyFields .wc-when-enabled').forEach(el => { el.style.opacity = on ? '1' : '.55'; });
}

(function wcInstallHooks() {
  const wrapEdit = () => {
    if (typeof window.editProduct !== 'function' || window.editProduct._wcWrapped) return;
    const original = window.editProduct;
    const wrapped = function (sku) {
      const out = original.apply(this, arguments);
      try { wcLoadProductEditFields(sku); } catch (e) {}
      return out;
    };
    wrapped._wcWrapped = true;
    window.editProduct = wrapped;
  };
  document.addEventListener('DOMContentLoaded', () => {
    wrapEdit();
    setTimeout(wrapEdit, 0);
    wcCheckPendingSale();
  });
})();
