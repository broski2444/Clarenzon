/* Customer Management — uses procurement_api customers_* actions */

let _customersPage = 1;
let _customersViewId = 0;
let _customersEditId = 0;
let _customersLoaded = false;
let _customersSearchTimer = null;
let _customersClicksBound = false;

function customersCanManage() {
  const v = window.CLAR_CAN_MANAGE_CUSTOMERS;
  return v === true || v === 1 || v === '1' || v === 'true';
}

function customerIdOf(value) {
  const n = parseInt(value, 10);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

function showCustomerOverlay(id) {
  const el = document.getElementById(id);
  if (!el) {
    if (typeof pToast === 'function') pToast('Customer window is missing. Refresh the page.', 'danger');
    return false;
  }
  el.classList.add('open');
  el.style.display = 'flex';
  const inner = el.querySelector(':scope > .modal');
  if (inner) {
    inner.classList.add('show');
    inner.style.display = 'flex';
    inner.style.position = 'relative';
    inner.style.height = 'auto';
    inner.style.width = 'auto';
  }
  if (typeof openModal === 'function') {
    try { openModal(id); } catch (e) {}
  }
  return true;
}

function hideCustomerOverlay(id) {
  if (typeof closeModal === 'function') {
    try { closeModal(id); } catch (e) {}
  }
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('open');
  el.style.display = 'none';
  const inner = el.querySelector(':scope > .modal');
  if (inner) {
    inner.classList.remove('show');
    inner.style.display = '';
    inner.style.position = '';
    inner.style.height = '';
    inner.style.width = '';
  }
}

function customersPost(body, cb) {
  const payload = body || {};
  const url = new URL('procurement_api.php', window.location.href);
  Object.keys(payload).forEach(function (k) {
    if (payload[k] !== undefined && payload[k] !== null) {
      url.searchParams.set(k, String(payload[k]));
    }
  });
  fetch(url.toString(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
    body: new URLSearchParams(payload)
  })
    .then(function (r) { return r.text(); })
    .then(function (text) {
      let data = null;
      try { data = JSON.parse((text || '').trim()); } catch (e) {
        const a = (text || '').indexOf('{');
        const b = (text || '').lastIndexOf('}');
        if (a !== -1 && b > a) {
          try { data = JSON.parse(text.substring(a, b + 1)); } catch (e2) {}
        }
      }
      cb(data || { success: false, message: (text && text.length < 120) ? text : 'Server error occurred' });
    })
    .catch(function () { cb({ success: false, message: 'Network error' }); });
}

function scheduleLoadCustomers() {
  clearTimeout(_customersSearchTimer);
  _customersSearchTimer = setTimeout(function () { loadCustomers(1); }, 300);
}

function loadCustomers(page) {
  bindCustomerTableClicks();
  const addBtn = document.getElementById('customerAddBtn');
  if (addBtn) addBtn.style.display = customersCanManage() ? '' : 'none';
  const tbody = document.getElementById('customersBody');
  if (!tbody || typeof pApi !== 'function') return;
  if (page) _customersPage = page;
  const search = document.getElementById('customerSearch')?.value || '';
  const status = document.getElementById('customerStatusFilter')?.value || 'all';
  tbody.innerHTML = '<tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading customers...</td></tr>';
  pApi({
    query: {
      action: 'customers_list',
      search: search,
      status: status,
      page: String(_customersPage),
      per_page: '10'
    }
  }, function (r) {
    if (!r || !r.success) {
      tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-danger">${esc(r?.message || 'Unable to load customers.')}</td></tr>`;
      renderCustomersPager(0, 1, 10, 1);
      return;
    }
    _customersLoaded = true;
    renderCustomers(r.data || []);
    renderCustomersPager(r.total || 0, r.page || 1, r.per_page || 10, r.pages || 1);
  });
}

function renderCustomers(rows) {
  const tbody = document.getElementById('customersBody');
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No customers found.</td></tr>';
    return;
  }
  const can = customersCanManage();
  tbody.innerHTML = rows.map(function (c) {
    const statusCls = String(c.status || 'Active') === 'Active' ? 'badge-green' : 'badge-gray';
    const last = formatCustomerDate(c.last_purchase);
    const addr = String(c.address || '').trim();
    const id = customerIdOf(c.id);
    const actions = `
      <div class="d-inline-flex align-items-center gap-1">
        <button type="button" class="btn-icon primary" title="View" data-customer-action="view" data-customer-id="${id}"><i class="bi bi-eye"></i></button>
        ${can ? `<button type="button" class="btn-icon" title="Edit" data-customer-action="edit" data-customer-id="${id}"><i class="bi bi-pencil"></i></button>` : ''}
        ${can ? `<button type="button" class="btn-icon" title="Delete" data-customer-action="delete" data-customer-id="${id}"><i class="bi bi-trash"></i></button>` : ''}
      </div>
    `;
    return `<tr>
      <td><span class="badge badge-violet">${esc(c.customer_code || customersCode(id))}</span></td>
      <td><strong>${esc(c.name)}</strong></td>
      <td>${esc(c.phone || '—')}</td>
      <td>${esc(c.email || '—')}</td>
      <td>${esc(addr || '—')}</td>
      <td>${Number(c.total_purchases || 0)}</td>
      <td>${esc(last)}</td>
      <td><span class="badge ${statusCls}">${esc(c.status || 'Active')}</span></td>
      <td style="white-space:nowrap">${actions}</td>
    </tr>`;
  }).join('');
}

function bindCustomerTableClicks() {
  if (_customersClicksBound) return;
  _customersClicksBound = true;
  document.addEventListener('click', function (e) {
    const btn = e.target && e.target.closest ? e.target.closest('[data-customer-action]') : null;
    if (!btn) return;
    const action = btn.getAttribute('data-customer-action');
    const id = customerIdOf(btn.getAttribute('data-customer-id'));
    if (action === 'add') {
      e.preventDefault();
      e.stopPropagation();
      openCustomerForm(0);
      return;
    }
    if (!id) return;
    e.preventDefault();
    e.stopPropagation();
    if (action === 'view') viewCustomer(id);
    else if (action === 'edit') openCustomerForm(id);
    else if (action === 'delete') deleteCustomer(id);
  }, true);
  const addBtn = document.getElementById('customerAddBtn');
  if (addBtn) addBtn.setAttribute('data-customer-action', 'add');
}

function customersCode(id) {
  return 'CUS-' + String(id || 0).padStart(5, '0');
}

function formatCustomerDate(value) {
  if (!value) return '—';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return String(value).slice(0, 16);
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatCustomerMoney(n) {
  const v = Number(n || 0);
  return '₱' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function submitCustomersGoToPage(event, pages) {
  if (!event || event.key !== 'Enter') return;
  event.preventDefault();
  const raw = String(event.target && event.target.value || '').trim();
  if (!/^[1-9]\d*$/.test(raw)) return;
  const n = parseInt(raw, 10);
  const last = Math.max(1, parseInt(pages, 10) || 1);
  if (n < 1 || n > last) return;
  loadCustomers(n);
}

function renderCustomersPager(total, page, perPage, pages) {
  const el = document.getElementById('customersPager');
  if (!el) return;
  const from = total === 0 ? 0 : ((page - 1) * perPage) + 1;
  const to = Math.min(total, page * perPage);
  el.innerHTML = `
    <span class="text-muted small">Showing ${from}–${to} of ${total}</span>
    <div class="d-flex gap-2 align-items-center">
      <label class="text-muted small mb-0 d-flex align-items-center gap-1">Go to page:
        <input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm" style="width:64px" aria-label="Go to page" onkeydown="submitCustomersGoToPage(event, ${pages})">
      </label>
      <button type="button" class="btn btn-outline-secondary btn-sm" ${page <= 1 ? 'disabled' : ''} onclick="loadCustomers(${page - 1})">Previous</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" ${page >= pages ? 'disabled' : ''} onclick="loadCustomers(${page + 1})">Next</button>
    </div>
  `;
}

function setCustomerField(id, value) {
  const el = document.getElementById(id);
  if (el) el.value = value;
}

function customerFormType() {
  const v = String(document.getElementById('custType')?.value || 'individual').toLowerCase();
  return v === 'company' ? 'company' : 'individual';
}

function composeCustomerFormName() {
  if (customerFormType() === 'company') {
    return String(document.getElementById('custCompanyName')?.value || '').replace(/\s+/g, ' ').trim();
  }
  const first = String(document.getElementById('custFirstName')?.value || '').trim();
  const middle = String(document.getElementById('custMiddleName')?.value || '').trim();
  const last = String(document.getElementById('custLastName')?.value || '').trim();
  const suffix = String(document.getElementById('custSuffix')?.value || '').trim();
  return [first, middle, last, suffix].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
}

function setCustomerInputRequired(id, required) {
  const el = document.getElementById(id);
  if (!el) return;
  if (required) el.setAttribute('required', 'required');
  else el.removeAttribute('required');
}

function syncCustomerFormType() {
  const isCompany = customerFormType() === 'company';
  const ind = document.getElementById('custIndividualFields');
  const co = document.getElementById('custCompanyFields');
  if (ind) ind.style.display = isCompany ? 'none' : 'contents';
  if (co) co.style.display = isCompany ? 'contents' : 'none';
  setCustomerInputRequired('custFirstName', !isCompany);
  setCustomerInputRequired('custLastName', !isCompany);
  setCustomerInputRequired('custCompanyName', isCompany);
  const hidden = document.getElementById('custName');
  if (hidden) hidden.value = composeCustomerFormName();
}

function parseStoredCustomerName(raw) {
  const name = String(raw || '').replace(/\s+/g, ' ').trim();
  const suffixMarks = ['jr.', 'jr', 'sr.', 'sr', 'ii', 'iii', 'iv', 'v'];
  const parts = name ? name.split(' ') : [];
  let suffix = '';
  if (parts.length) {
    const lastTok = parts[parts.length - 1];
    if (suffixMarks.indexOf(lastTok.toLowerCase()) !== -1) {
      suffix = parts.pop();
    }
  }
  if (parts.length <= 1) {
    return { type: 'company', company: name, first: '', middle: '', last: '', suffix: '' };
  }
  const first = parts.shift() || '';
  const last = parts.pop() || '';
  const middle = parts.join(' ');
  return { type: 'individual', company: '', first: first, middle: middle, last: last, suffix: suffix };
}

function fillCustomerNameFields(storedName) {
  const parsed = parseStoredCustomerName(storedName);
  setCustomerField('custType', parsed.type);
  setCustomerField('custFirstName', parsed.first);
  setCustomerField('custMiddleName', parsed.middle);
  setCustomerField('custLastName', parsed.last);
  setCustomerField('custSuffix', parsed.suffix);
  setCustomerField('custCompanyName', parsed.company);
  setCustomerField('custName', String(storedName || '').trim());
  syncCustomerFormType();
}

function resetCustomerNameFields() {
  setCustomerField('custType', 'individual');
  setCustomerField('custFirstName', '');
  setCustomerField('custMiddleName', '');
  setCustomerField('custLastName', '');
  setCustomerField('custSuffix', '');
  setCustomerField('custCompanyName', '');
  setCustomerField('custName', '');
  syncCustomerFormType();
}

function openCustomerForm(id) {
  if (!customersCanManage()) return;
  const cid = customerIdOf(id);
  _customersEditId = cid;
  const form = document.getElementById('customerForm');
  if (form) form.reset();
  setCustomerField('customerEditId', cid ? String(cid) : '');
  resetCustomerNameFields();
  const title = document.getElementById('customerFormTitle');
  if (title) title.innerHTML = '<i class="bi bi-person-plus"></i> Add Customer';
  if (!cid) {
    showCustomerOverlay('customerFormModal');
    document.getElementById('custFirstName')?.focus();
    return;
  }
  pApi({ query: { action: 'customers_get', id: String(cid) } }, function (r) {
    if (!r?.success || !r.data) {
      if (typeof pToast === 'function') pToast(r?.message || 'Customer not found.', 'danger');
      return;
    }
    const c = r.data;
    _customersEditId = customerIdOf(c.id) || cid;
    setCustomerField('customerEditId', String(_customersEditId));
    fillCustomerNameFields(c.name || '');
    setCustomerField('custPhone', c.phone || '');
    setCustomerField('custEmail', c.email || '');
    setCustomerField('custAddress', c.address || '');
    setCustomerField('custStatus', c.status || 'Active');
    if (title) title.innerHTML = '<i class="bi bi-pencil-square"></i> Edit Customer';
    showCustomerOverlay('customerFormModal');
  });
}

function clarenzonPhMobileError(value) {
  const phone = String(value || '').trim();
  if (!phone) return '';
  if (!/^09\d{9}$/.test(phone)) {
    return 'Enter an 11-digit Philippine mobile number starting with 09, or leave the phone blank.';
  }
  return '';
}
window.clarenzonPhMobileError = clarenzonPhMobileError;

function clarenzonBindPhMobile(root) {
  (root || document).querySelectorAll('.js-ph-mobile').forEach((input) => {
    if (input.dataset.phBound === '1') return;
    input.dataset.phBound = '1';
    input.addEventListener('input', () => {
      const digits = String(input.value || '').replace(/\D/g, '').slice(0, 11);
      if (input.value !== digits) input.value = digits;
    });
  });
}
clarenzonBindPhMobile(document);

function submitCustomerForm(ev) {
  if (ev) ev.preventDefault();
  if (!customersCanManage()) return false;
  syncCustomerFormType();
  const type = customerFormType();
  if (type === 'individual') {
    const first = String(document.getElementById('custFirstName')?.value || '').trim();
    const last = String(document.getElementById('custLastName')?.value || '').trim();
    if (!first || !last) {
      if (typeof pToast === 'function') pToast('First name and last name are required.', 'danger');
      return false;
    }
  } else if (!String(document.getElementById('custCompanyName')?.value || '').trim()) {
    if (typeof pToast === 'function') pToast('Company name is required.', 'danger');
    return false;
  }
  const phoneError = clarenzonPhMobileError(document.getElementById('custPhone')?.value || '');
  if (phoneError) {
    if (typeof pToast === 'function') pToast(phoneError, 'danger');
    document.getElementById('custPhone')?.focus();
    return false;
  }
  const name = composeCustomerFormName();
  const hidden = document.getElementById('custName');
  if (hidden) hidden.value = name;
  if (!name) {
    if (typeof pToast === 'function') pToast('Customer name is required.', 'danger');
    return false;
  }
  const editId = customerIdOf(document.getElementById('customerEditId')?.value) || _customersEditId;
  customersPost({
    action: 'customers_save',
    id: editId ? String(editId) : '',
    name: name,
    phone: document.getElementById('custPhone')?.value || '',
    email: document.getElementById('custEmail')?.value || '',
    address: document.getElementById('custAddress')?.value || '',
    status: document.getElementById('custStatus')?.value || 'Active'
  }, function (r) {
    if (!r?.success) {
      if (typeof pToast === 'function') pToast(r?.message || 'Unable to save customer.', 'danger');
      return;
    }
    if (typeof pToast === 'function') pToast(r.message || 'Customer saved.');
    hideCustomerOverlay('customerFormModal');
    loadCustomers(_customersPage);
  });
  return false;
}

function viewCustomer(id) {
  const cid = customerIdOf(id);
  _customersViewId = cid;
  const body = document.getElementById('customerViewBody');
  if (body) body.innerHTML = '<p class="text-muted mb-0">Loading…</p>';
  showCustomerOverlay('customerViewModal');
  pApi({ query: { action: 'customers_get', id: String(cid) } }, function (r) {
    if (!r?.success || !r.data) {
      if (body) body.innerHTML = `<p class="text-danger mb-0">${esc(r?.message || 'Customer not found.')}</p>`;
      return;
    }
    const c = r.data;
    const orders = Array.isArray(c.orders) ? c.orders : [];
    const orderRows = orders.length
      ? orders.map(function (o) {
          return `<tr>
            <td>${esc(o.source)}</td>
            <td>${esc(o.number)}</td>
            <td>${formatCustomerMoney(o.amount)}</td>
            <td>${esc(o.status)}</td>
            <td>${esc(formatCustomerDate(o.date))}</td>
          </tr>`;
        }).join('')
      : '<tr><td colspan="5" class="text-center text-muted py-3">No sales orders or invoices yet.</td></tr>';
    const editBtn = document.getElementById('customerViewEditBtn');
    if (editBtn) editBtn.style.display = customersCanManage() ? '' : 'none';
    if (body) {
      body.innerHTML = `
        <div class="row g-3 mb-3">
          <div class="col-md-4"><div class="text-muted small">Customer ID</div><div><span class="badge badge-violet">${esc(c.customer_code)}</span></div></div>
          <div class="col-md-8"><div class="text-muted small">Customer Name</div><div class="fw-semibold">${esc(c.name)}</div></div>
          <div class="col-md-4"><div class="text-muted small">Contact Number</div><div>${esc(c.phone || '—')}</div></div>
          <div class="col-md-4"><div class="text-muted small">Email</div><div>${esc(c.email || '—')}</div></div>
          <div class="col-md-4"><div class="text-muted small">Status</div><div><span class="badge ${c.status === 'Active' ? 'badge-green' : 'badge-gray'}">${esc(c.status)}</span></div></div>
          <div class="col-12"><div class="text-muted small">Address</div><div>${esc(c.address || '—')}</div></div>
          <div class="col-md-4"><div class="text-muted small">Total Purchases</div><div>${Number(c.total_purchases || 0)} <span class="text-muted small">(${formatCustomerMoney(c.total_purchase_amount)})</span></div></div>
          <div class="col-md-4"><div class="text-muted small">Last Purchase</div><div>${esc(formatCustomerDate(c.last_purchase))}</div></div>
        </div>
        <div class="card-title mb-2">Recent purchases</div>
        <div class="table-container system-table-scroll">
          <table class="modern-table">
            <thead><tr><th>Source</th><th>Number</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>${orderRows}</tbody>
          </table>
        </div>`;
    }
  });
}

function editCustomerFromView() {
  hideCustomerOverlay('customerViewModal');
  if (_customersViewId) openCustomerForm(_customersViewId);
}

function deleteCustomer(id) {
  if (!customersCanManage()) return;
  const cid = customerIdOf(id);
  if (!cid) {
    if (typeof pToast === 'function') pToast('Invalid customer.', 'danger');
    return;
  }
  appConfirm('Delete this customer? The customer record is removed. Sales history is kept.').then(function (ok) {
  if (!ok) return;
  customersPost({ action: 'customers_delete', id: String(cid) }, function (r) {
    if (!r?.success) {
      if (typeof pToast === 'function') pToast(r?.message || 'Unable to delete customer.', 'danger');
      return;
    }
    if (typeof pToast === 'function') pToast(r.message || 'Customer deleted.');
    loadCustomers(_customersPage);
  });
  });
}

window.loadCustomers = loadCustomers;
window.scheduleLoadCustomers = scheduleLoadCustomers;
window.openCustomerForm = openCustomerForm;
window.submitCustomerForm = submitCustomerForm;
window.syncCustomerFormType = syncCustomerFormType;
window.viewCustomer = viewCustomer;
window.editCustomerFromView = editCustomerFromView;
window.deleteCustomer = deleteCustomer;

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bindCustomerTableClicks);
} else {
  bindCustomerTableClicks();
}
