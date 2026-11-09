/* Transaction History — read-only view of existing stock_movements */

let _txnHistoryRows = [];
let _txnHistTimer = null;

function txnHistEsc(value) {
  if (typeof esc === 'function') return esc(value);
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function txnHistDash(value) {
  const s = String(value == null ? '' : value).trim();
  return s === '' ? '—' : s;
}

function txnHistFormatDate(value) {
  if (!value) return '—';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  });
}

function txnHistTypeBadge(type) {
  const t = String(type || '').toLowerCase();
  let cls = 'badge-orange';
  if (t.indexOf('in') !== -1 || t.indexOf('receive') !== -1 || t.indexOf('return') !== -1) cls = 'badge-green';
  else if (t.indexOf('out') !== -1 || t.indexOf('issue') !== -1 || t.indexOf('damage') !== -1 || t.indexOf('expir') !== -1) cls = 'badge-red';
  return `<span class="badge ${cls}">${txnHistEsc(type || 'N/A')}</span>`;
}

function txnHistSalesText(row) {
  if (!row || row.sales === null || row.sales === undefined || row.sales === '') return '—';
  const v = Number(row.sales);
  if (!Number.isFinite(v)) return '—';
  return '₱' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function txnHistScheduleLoad() {
  clearTimeout(_txnHistTimer);
  _txnHistTimer = setTimeout(function () {
    loadTransactionHistory();
  }, 280);
}

function resetTransactionHistoryFilters() {
  const search = document.getElementById('txnHistSearch');
  const type = document.getElementById('txnHistType');
  const from = document.getElementById('txnHistFrom');
  const to = document.getElementById('txnHistTo');
  if (search) search.value = '';
  if (type) type.value = 'all';
  if (from) from.value = '';
  if (to) to.value = '';
  loadTransactionHistory();
}

function loadTransactionHistory() {
  const tbody = document.getElementById('txnHistoryBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="11" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading transactions...</td></tr>';
  const url = new URL('inventory_api.php', window.location.href);
  url.searchParams.set('action', 'get_transaction_history');
  const search = document.getElementById('txnHistSearch');
  const type = document.getElementById('txnHistType');
  const from = document.getElementById('txnHistFrom');
  const to = document.getElementById('txnHistTo');
  const q = search ? String(search.value || '').trim() : '';
  const typeVal = type ? String(type.value || 'all') : 'all';
  let fromVal = from ? String(from.value || '').trim() : '';
  let toVal = to ? String(to.value || '').trim() : '';
  if (fromVal && toVal && fromVal > toVal) {
    const swap = fromVal;
    fromVal = toVal;
    toVal = swap;
    if (from) from.value = fromVal;
    if (to) to.value = toVal;
  }
  if (q) url.searchParams.set('q', q);
  if (typeVal && typeVal !== 'all') url.searchParams.set('type', typeVal);
  if (fromVal) url.searchParams.set('from', fromVal);
  if (toVal) url.searchParams.set('to', toVal);
  fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res || !res.success) {
        tbody.innerHTML = `<tr><td colspan="11" class="text-center py-4 text-danger">${txnHistEsc(res && res.message ? res.message : 'Unable to load transactions.')}</td></tr>`;
        return;
      }
      _txnHistoryRows = res.data || [];
      if (!_txnHistoryRows.length) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 text-muted">No transactions found.</td></tr>';
        return;
      }
      tbody.innerHTML = _txnHistoryRows.map(function (row) {
        const id = Number(row.id) || 0;
        return `<tr>
          <td><strong>${txnHistEsc(row.transaction_no)}</strong></td>
          <td>${txnHistEsc(txnHistFormatDate(row.transaction_datetime))}</td>
          <td>${txnHistTypeBadge(row.transaction_type)}</td>
          <td>${txnHistEsc(txnHistDash(row.reference_no))}</td>
          <td>${txnHistEsc(txnHistDash(row.customer))}</td>
          <td><strong>${txnHistEsc(row.product)}</strong>${row.sku ? `<br><span class="text-muted small">${txnHistEsc(row.sku)}</span>` : ''}</td>
          <td>${txnHistEsc(row.quantity)}</td>
          <td>${txnHistEsc(txnHistSalesText(row))}</td>
          <td>${txnHistEsc(txnHistDash(row.processed_by))}</td>
          <td><span class="badge badge-green">${txnHistEsc(row.status)}</span></td>
          <td><button type="button" class="btn-icon primary" title="View Details" onclick="viewTransactionHistory(${id})"><i class="bi bi-eye"></i></button></td>
        </tr>`;
      }).join('');
    })
    .catch(function () {
      tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 text-danger">Network error.</td></tr>';
    });
}

function viewTransactionHistory(id) {
  const row = _txnHistoryRows.find(function (r) { return Number(r.id) === Number(id); });
  const body = document.getElementById('txnHistoryDetailBody');
  if (!body) return;
  if (!row) {
    body.innerHTML = '<p class="text-danger mb-0">Transaction not found.</p>';
    if (typeof openModal === 'function') openModal('txnHistoryDetailModal');
    return;
  }
  body.innerHTML = `
    <div class="row g-3">
      <div class="col-md-6"><div class="text-muted small">Transaction No.</div><div class="fw-semibold">${txnHistEsc(row.transaction_no)}</div></div>
      <div class="col-md-6"><div class="text-muted small">Date &amp; Time</div><div>${txnHistEsc(txnHistFormatDate(row.transaction_datetime))}</div></div>
      <div class="col-md-6"><div class="text-muted small">Transaction Type</div><div>${txnHistTypeBadge(row.transaction_type)}</div></div>
      <div class="col-md-6"><div class="text-muted small">Status</div><div><span class="badge badge-green">${txnHistEsc(row.status)}</span></div></div>
      <div class="col-md-6"><div class="text-muted small">Reference No.</div><div>${txnHistEsc(txnHistDash(row.reference_no))}</div></div>
      <div class="col-md-6"><div class="text-muted small">Customer</div><div>${txnHistEsc(txnHistDash(row.customer))}</div></div>
      <div class="col-md-6"><div class="text-muted small">Processed By</div><div>${txnHistEsc(txnHistDash(row.processed_by))}${row.processed_by_role ? ` <span class="text-muted small">(${txnHistEsc(row.processed_by_role)})</span>` : ''}</div></div>
      <div class="col-md-6"><div class="text-muted small">Product</div><div>${txnHistEsc(row.product)}</div></div>
      <div class="col-md-6"><div class="text-muted small">SKU</div><div>${txnHistEsc(txnHistDash(row.sku))}</div></div>
      <div class="col-md-6"><div class="text-muted small">Quantity</div><div>${txnHistEsc(row.quantity)}${row.quantity_unit ? ' ' + txnHistEsc(row.quantity_unit) : ''}</div></div>
      <div class="col-md-6"><div class="text-muted small">Sales</div><div>${txnHistEsc(txnHistSalesText(row))}</div></div>
      <div class="col-12"><div class="text-muted small">Notes</div><div>${txnHistEsc(txnHistDash(row.notes))}</div></div>
    </div>`;
  if (typeof openModal === 'function') openModal('txnHistoryDetailModal');
}

window.loadTransactionHistory = loadTransactionHistory;
window.viewTransactionHistory = viewTransactionHistory;
window.resetTransactionHistoryFilters = resetTransactionHistoryFilters;
window.txnHistScheduleLoad = txnHistScheduleLoad;
