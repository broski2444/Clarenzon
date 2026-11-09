/* ════════════════════════════════════════════════════════════
   inventory_module.js  –  Module 2: Inventory Management
   AJAX-driven tabs for Product Catalog, Movements, Batches,
   Alerts, and Serial Numbers.
════════════════════════════════════════════════════════════ */

const API = 'inventory_api.php';

// ── Global state ────────────────────────────────────────────
let _categories = [], _locations = [], _suppliers = [], _productsList = [];
let _catalogData = [], _movementsData = [], _batchesData = [], _serialsData = [];
let _invLiveTimer = null;
let _alertsSig = '';
let _mvtSig = '';
let _catalogSig = '';
let _locStockSig = '';
let _smHistSig = '';
const INV_LIVE_REFRESH_MS = 8000;

// ── Helpers ─────────────────────────────────────────────────
function api(params, cb) {
  const url = new URL(API, window.location.href);
  if (params.method === 'POST') {
    const body = params.body instanceof FormData ? params.body : new URLSearchParams(params.body);
    fetch(API, { method: 'POST', body })
      .then(r => r.json()).then(cb).catch(() => cb({ success: false, message: 'Network error' }));
  } else {
    Object.entries(params.query || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    fetch(url).then(r => r.json()).then(cb).catch(() => cb({ success: false, message: 'Network error' }));
  }
}

function toast(msg, type = 'success') {
  const el = document.createElement('div');
  const colors = { success: '#10b981', danger: '#ef4444', warn: '#f59e0b', info: '#2563eb' };
  el.className = 'clar-float-toast';
  el.style.cssText = `position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:30000;background:${colors[type]||colors.success};color:#fff;
    padding:12px 20px;border-radius:10px;font-size:13.5px;font-weight:500;font-family:'Inter',sans-serif;
    box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:min(320px, calc(100vw - 32px));text-align:center`;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, 3000);
}

function openModal(id) { 
  const el = document.getElementById(id); 
  if (el) { 
    el.classList.add('open'); 
    if (el.classList.contains('modal-overlay')) el.style.display = 'flex';
  } 
}
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

function productImagePath(product) {
  const raw = String(product?.product_image || product?.image || product?.image_path || product?.photo || product?.photo_path || product?.picture || product?.picture_path || '').trim();
  if (!raw) return '';
  if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:image/') || raw.startsWith('/')) return raw;
  const normalized = raw.replace(/\\/g, '/');
  if (normalized.includes('/')) return normalized;
  return `uploads/${normalized}`;
}

function openProductImageModal(name, imagePath) {
  const title = document.getElementById('productImageModalTitle');
  const body = document.getElementById('productImageModalBody');
  if (!title || !body) return;

  title.innerHTML = `<i class="bi bi-image"></i> ${esc(name || 'Product Image')}`;
  body.innerHTML = '';

  if (!imagePath) {
    body.innerHTML = '<div class="product-image-empty">No image available.</div>';
  } else {
    const img = document.createElement('img');
    img.className = 'product-image-preview';
    img.alt = name || 'Product Image';
    img.src = imagePath;
    img.onerror = () => {
      body.innerHTML = '<div class="product-image-empty">No image available.</div>';
    };
    body.appendChild(img);
  }

  openModal('productImageModal');
}

function renderProductUploadPreview(previewId, imagePath, emptyText = 'No image selected.') {
  const preview = document.getElementById(previewId);
  if (!preview) return;
  preview.innerHTML = '';
  if (!imagePath) {
    preview.innerHTML = `<span>${esc(emptyText)}</span>`;
    return;
  }
  const img = document.createElement('img');
  img.src = imagePath;
  img.alt = 'Product image preview';
  img.onerror = () => { preview.innerHTML = '<span>No image available.</span>'; };
  preview.appendChild(img);
}

function bindProductImageInputs() {
  document.querySelectorAll('.product-image-input').forEach(input => {
    if (input.dataset.boundPreview === '1') return;
    input.dataset.boundPreview = '1';
    input.addEventListener('change', () => {
      const previewId = input.dataset.preview;
      const message = document.getElementById(input.dataset.message || '');
      const file = input.files && input.files[0] ? input.files[0] : null;
      if (message) message.textContent = '';
      if (!file) {
        renderProductUploadPreview(previewId);
        return;
      }
      const allowed = ['image/jpeg', 'image/png', 'image/webp'];
      const validExtension = /\.(jpe?g|png|webp)$/i.test(file.name);
      if (!allowed.includes(file.type) || !validExtension) {
        input.value = '';
        if (message) message.textContent = 'Only JPG, JPEG, PNG, and WEBP images are allowed.';
        renderProductUploadPreview(previewId);
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        input.value = '';
        if (message) message.textContent = 'Product image must be 5 MB or smaller.';
        renderProductUploadPreview(previewId);
        return;
      }
      const reader = new FileReader();
      reader.onload = e => renderProductUploadPreview(previewId, e.target.result);
      reader.readAsDataURL(file);
    });
  });
}

// Close on overlay click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});

function switchTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const panel = document.getElementById('tab-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  // Lazy-load tab data
  if (name === 'catalog') {
    loadCatalog();
    if (typeof loadReturnReplacementHistory === 'function') loadReturnReplacementHistory();
  }
  if (name === 'movements')  loadMovements();
  if (name === 'batches')    loadBatches();
  if (name === 'alerts')     loadAlerts();
  if (name === 'serials')    loadSerials();
}

function stockBadge(qty, reorder, sellable) {
  const onHand = Number(qty || 0);
  const avail = sellable == null ? onHand : Number(sellable);
  if (avail <= 0 && onHand > 0) return '<span class="badge badge-red">Not Sellable</span>';
  if (avail <= 0)           return '<span class="badge badge-red">Out of Stock</span>';
  if (avail <= reorder)     return `<span class="badge badge-yellow">Low (${avail})</span>`;
  return `<span class="badge badge-green">${avail}</span>`;
}

function statusBadge(s) {
  const map = {
    'Active':        'badge-green',
    'Low Stock':     'badge-yellow',
    'Out of Stock':  'badge-red',
    'Expired - Not Sellable': 'badge-red',
    'Discontinued':  'badge-gray',
    'Completed':     'badge-green',
    'Open':          'badge-blue',
    'Submitted':     'badge-yellow',
    'Approved':      'badge-green',
    'Cancelled':     'badge-gray',
    'Draft':         'badge-gray',
  };
  return `<span class="badge ${map[s]||'badge-gray'}">${s}</span>`;
}

function mvtBadge(t) {
  if (t.includes('In') || t === 'Return')  return `<span class="badge badge-green">${t}</span>`;
  if (t.includes('Out') || t === 'Damage' || t === 'Expiry') return `<span class="badge badge-red">${t}</span>`;
  if (t === 'Transfer') return `<span class="badge badge-blue">${t}</span>`;
  return `<span class="badge badge-gray">${t}</span>`;
}

function batchStatusBadge(s, days) {
  if (s === 'Expired' || days <= 0)   return '<span class="badge badge-red">Expired</span>';
  if (s === 'Depleted')                return '<span class="badge badge-gray">Depleted</span>';
  if (s === 'Recalled')                return '<span class="badge badge-orange">Recalled</span>';
  if (days !== null && days <= 30)     return '<span class="badge badge-red">Critical</span>';
  if (days !== null && days <= 90)     return '<span class="badge badge-yellow">Near Expiry</span>';
  return '<span class="badge badge-green">Active</span>';
}

function serialStatusBadge(s) {
  const map = { Active:'badge-green','In Use':'badge-blue',Returned:'badge-cyan',Retired:'badge-gray',Lost:'badge-red',Damaged:'badge-orange' };
  return `<span class="badge ${map[s]||'badge-gray'}">${s}</span>`;
}

function fmtDate(d) { if (!d) return '—'; return new Date(d).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' }); }
function fmtMoney(v) { return '₱' + parseFloat(v||0).toLocaleString('en-PH', { minimumFractionDigits:2 }); }
function esc(s) { if (!s) return ''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// ── Lookup loaders ───────────────────────────────────────────
function loadLookups(cb) {
  let done = 0, total = 3;
  function next() { if (++done === total && cb) cb(); }
  api({ query: { action: 'get_categories' } }, r => { _categories = r.data || []; populateCatSelects(); next(); });
  api({ query: { action: 'get_locations' } },  r => { _locations  = r.data || []; populateLocSelects(); next(); });
  api({ query: { action: 'get_suppliers_list' } }, r => { _suppliers = r.data || []; populateSupSelects(); next(); });
}

function loadProductsList() {
  api({ query: { action: 'get_products_list' } }, r => {
    _productsList = r.data || [];
    populateProdSelects();
  });
}

function populateCatSelects() {
  const opts = '<option value="">-- Select --</option>' + _categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  ['prodCatSelect', 'editProdCat'].forEach(id => { const el = document.getElementById(id); if(el) el.innerHTML = opts; });
  const cf = document.getElementById('catalogCatFilter');
  if (cf) cf.innerHTML = '<option value="">All Categories</option>' + _categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
}

function populateLocSelects() {
  const opts = '<option value="">-- None --</option>' + _locations.map(l => `<option value="${l.id}">${esc(l.storage_area)} – ${esc(l.shelf_rack||l.section||'')}</option>`).join('');
  ['prodLocationSelect','editProdLocation','mvtFromLoc','mvtToLoc','serialLocationSelect','returnFromLoc','returnToLoc'].forEach(id => { const el = document.getElementById(id); if(el) el.innerHTML = opts; });
  fillWmsLocationSelects();
}

function populateSupSelects() {
  const opts = '<option value="">-- Select --</option>' + _suppliers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  ['prodSupplierSelect','editProdSupplier','batchSupplierSelect'].forEach(id => { const el = document.getElementById(id); if(el) el.innerHTML = opts; });
  const spf = document.getElementById('serialProductFilter');
  // Serial product filter – will be populated after products load
}

function isEquipmentProduct(p) {
  if (!p) return false;
  const name = (p.name || '').toLowerCase();
  const cat  = (p.category || p.category_name || '').toLowerCase();
  const uom  = (p.unit_of_measure || '').toLowerCase();
  return cat.includes('equipment') || cat.includes('computer') || cat.includes('peripheral') || uom === 'unit' || name.includes('ups') || name.includes('desktop') || name.includes('analyzer') || name.includes('machine') || name.includes('monitor') || name.includes('set');
}

function populateProdSelects() {
  const allOpts = '<option value="">-- Select Product --</option>' +
    _productsList.map(p => `<option value="${p.id}" data-cost="${p.cost_price}">[${esc(p.sku)}] ${esc(p.name)}</option>`).join('');
  ['mvtProductSelect','batchProductSelect','returnProductSelect'].forEach(id => { const el = document.getElementById(id); if(el) el.innerHTML = allOpts; });
  fillReplacementProductSelects();

  // Filter equipment-only items for Serial Numbers
  const equipProducts = _productsList.filter(isEquipmentProduct);
  const equipList = equipProducts.length ? equipProducts : _productsList;

  const equipOpts = '<option value="">-- Select Equipment --</option>' +
    equipList.map(p => `<option value="${p.id}">[${esc(p.sku)}] ${esc(p.name)}</option>`).join('');

  const sps = document.getElementById('serialProductSelect');
  if (sps) sps.innerHTML = equipOpts;

  const spf = document.getElementById('serialProductFilter');
  if (spf) {
    spf.innerHTML = '<option value="">All Equipment Products</option>' +
      equipList.map(p => `<option value="${p.id}">[${esc(p.sku)}] ${esc(p.name)}</option>`).join('');
  }
}

// ── CATALOG ─────────────────────────────────────────────────
function loadCatalog(silent) {
  const tbody = document.getElementById('catalogBody');
  if (!tbody) return;
  if (!silent) tbody.innerHTML = '<tr class="loading-row"><td colspan="12"><span class="loader"></span> Loading products…</td></tr>';
  const params = { action: 'get_products' };
  const catF = document.getElementById('catalogCatFilter')?.value;
  const stF  = document.getElementById('catalogStatusFilter')?.value;
  const srch = document.getElementById('catalogSearch')?.value;
  if (catF) params.category_id = catF;
  if (stF)  params.status = stF;
  if (srch) params.search = srch;
  api({ query: params }, r => {
    if (!r.success) {
      if (!silent) tbody.innerHTML = `<tr><td colspan="12" style="color:red;padding:20px">${esc(r.message)}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    const sig = (rows || []).map(p => [p.id, p.quantity_in_stock, p.status, p.cost_price].join(':')).join('|') + '|' + (srch || '') + '|' + (catF || '') + '|' + (stF || '');
    if (silent && sig === _catalogSig) return;
    _catalogSig = sig;
    _catalogData = rows;
    renderCatalog(_catalogData);
  });
}

function renderCatalog(data) {
  const tbody = document.getElementById('catalogBody');
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="12" class="empty-state" style="padding:32px;text-align:center;color:#64748b"><i class="bi bi-inbox" style="display:block;font-size:36px;opacity:.3;margin-bottom:8px"></i>No products found</td></tr>'; return; }
  tbody.innerHTML = data.map(p => {
    const loc = p.storage_area ? `${esc(p.storage_area)} ${p.shelf_rack ? '– ' + esc(p.shelf_rack) : ''}` : '—';
    const expDate = p.expiry_date ? new Date(p.expiry_date) : null;
    const expired = !!(expDate && expDate < new Date(new Date().toDateString()));
    const near = !!(expDate && !expired && ((expDate - new Date()) / 86400000) <= 30);
    const expiry = p.expiry_date
      ? `<span class="badge ${expired ? 'badge-red' : (near ? 'badge-orange' : 'badge-green')}">${fmtDate(p.expiry_date)}${expired ? ' · Expired' : (near ? ' · Soon' : '')}</span>`
      : '—';
    const displayName = esc(p.name).replace(/_/g, ' ');
    const imagePath = productImagePath(p);
    const sellable = Number(p.sellable_qty != null ? p.sellable_qty : p.quantity_in_stock);
    const extraCodes = Array.isArray(p.barcode_values) ? p.barcode_values.join(' ') : '';
    return `<tr data-barcodes="${esc((p.barcode || '') + ' ' + extraCodes)}">
      <td data-label="SKU"><strong style="font-family:monospace;font-size:12px">${esc(p.sku)}</strong></td>
      <td data-label="Product"><button type="button" class="product-image-link" onclick="openProductImageModal(decodeURIComponent('${encodeURIComponent(p.name || '')}'), decodeURIComponent('${encodeURIComponent(imagePath)}'))">${displayName}</button></td>
      <td data-label="Brand"><span class="badge badge-blue">${esc(p.brand||'—')}</span></td>
      <td data-label="Category">${esc(p.category_name||'—')}</td>
      <td data-label="Stock">${Number(p.quantity_in_stock || 0)}</td>
      <td data-label="Sellable">${stockBadge(p.quantity_in_stock, p.reorder_level, sellable)}</td>
      <td data-label="Cost">${fmtMoney(p.cost_price)}</td>
      <td data-label="Sell">${fmtMoney(p.selling_price)}</td>
      <td data-label="Expiry">${expiry}</td>
      <td data-label="Location" style="font-size:12px;color:#64748b">${loc}</td>
      <td data-label="Status">${statusBadge(expired && sellable <= 0 ? 'Expired - Not Sellable' : p.status)}</td>
      <td data-label="Actions">
        <button class="btn-icon primary" title="Edit" onclick="openEditProduct(${JSON.stringify(p).replace(/"/g,'&quot;')})"><i class="bi bi-pencil"></i></button>
        <button class="btn-icon" title="Replace" onclick="openProductReplaceModal(${p.id},'${esc(p.sku)}','${esc(p.name)}')"><i class="bi bi-arrow-repeat"></i></button>
        <button class="btn-icon" title="Return" onclick="openStockReturnModal(${p.id})"><i class="bi bi-arrow-counterclockwise"></i></button>
        <button class="btn-icon danger" title="Discontinue" onclick="deleteProduct(${p.id},'${esc(p.name)}')"><i class="bi bi-trash"></i></button>
      </td>
    </tr>`;
  }).join('');

}

function filterCatalog() { loadCatalog(); }

function applyGlobalSearch() {
  const q = document.getElementById('globalSearch')?.value.toLowerCase();
  if (!q) return;
  document.getElementById('catalogSearch').value = q;
  switchTab('catalog', document.querySelector('[data-tab=catalog]'));
}

// ── ADD / EDIT PRODUCT ───────────────────────────────────────
function submitAddProduct() {
  const form = document.getElementById('addProductForm');
  const fd = new FormData(form);
  fd.set('action', 'add_product');
  if (form.querySelector('[name=batch_tracking]')?.checked) fd.set('batch_tracking', '1');
  if (form.querySelector('[name=expiry_tracking]')?.checked) fd.set('expiry_tracking', '1');
  api({ method:'POST', body: fd }, r => {
    if (r.success) {
      toast('Product added successfully!');
      closeModal('addProductModal');
      form.reset();
      renderProductUploadPreview('addProductImagePreview');
      const msg = document.getElementById('addProductImageMessage');
      if (msg) msg.textContent = '';
      loadCatalog();
      loadAlerts();
    }
    else toast(r.message || 'Failed to add product', 'danger');
  });
}

function openEditProduct(p) {
  document.getElementById('editProdId').value = p.id;
  document.getElementById('editProdName').value   = p.name;
  document.getElementById('editProdBrand').value  = p.brand;
  document.getElementById('editProdSize').value   = p.size;
  document.getElementById('editProdPkg').value    = p.packaging;
  document.getElementById('editProdDesc').value   = p.description;
  document.getElementById('editProdCost').value   = p.cost_price;
  document.getElementById('editProdSell').value   = p.selling_price;
  document.getElementById('editProdQty').value    = p.quantity_in_stock;
  document.getElementById('editProdRL').value     = p.reorder_level;
  document.getElementById('editProdRQ').value     = p.reorder_quantity;
  document.getElementById('editProdUOM').value    = p.unit_of_measure;
  document.getElementById('editProdExpiry').value = p.expiry_date ? p.expiry_date.substring(0,10) : '';
  document.getElementById('editProdStatus').value = p.status;
  document.getElementById('editProdBT').checked   = !!+p.batch_tracking;
  document.getElementById('editProdET').checked   = !!+p.expiry_tracking;
  const imageInput = document.getElementById('editProdImage');
  if (imageInput) imageInput.value = '';
  const imageMessage = document.getElementById('editProductImageMessage');
  if (imageMessage) imageMessage.textContent = 'Current image is kept unless a replacement is selected.';
  renderProductUploadPreview('editProductImagePreview', productImagePath(p), 'No image available.');
  // Set selects after lookups are loaded
  setTimeout(() => {
    const ec = document.getElementById('editProdCat');     if(ec) ec.value = p.category_id;
    const es = document.getElementById('editProdSupplier');if(es) es.value = p.supplier_id;
    const el = document.getElementById('editProdLocation');if(el) el.value = p.location_id;
  }, 50);
  openModal('editProductModal');
}

function submitEditProduct() {
  const form = document.getElementById('editProductForm');
  const fd   = new FormData(form);
  fd.set('action', 'update_product');
  if (document.getElementById('editProdBT')?.checked) fd.set('batch_tracking','1');
  if (document.getElementById('editProdET')?.checked) fd.set('expiry_tracking','1');
  api({ method:'POST', body: fd }, r => {
    if (r.success) { toast('Product updated!'); closeModal('editProductModal'); loadCatalog(); }
    else toast(r.message||'Update failed','danger');
  });
}

function deleteProduct(id, name) {
  if (window.Swal) {
    Swal.fire({
      title: 'Discontinue Product?',
      text: `Discontinue "${name}"? It will no longer appear in active inventory catalog.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#64748b',
      confirmButtonText: 'Yes, Discontinue',
      cancelButtonText: 'Cancel',
      reverseButtons: true
    }).then(result => {
      if (result.isConfirmed) {
        api({ method:'POST', body:{ action:'delete_product', id } }, r => {
          if (r.success) { toast('Product discontinued'); loadCatalog(); }
          else toast(r.message||'Failed','danger');
        });
      }
    });
  } else {
    api({ method:'POST', body:{ action:'delete_product', id } }, r => {
      if (r.success) { toast('Product discontinued'); loadCatalog(); }
      else toast(r.message||'Failed','danger');
    });
  }
}

// ── MOVEMENTS ───────────────────────────────────────────────
function loadMovements(silent) {
  const tbody = document.getElementById('movementsBody');
  if (!tbody) return;
  if (!silent) tbody.innerHTML = '<tr class="loading-row"><td colspan="10"><span class="loader"></span> Loading…</td></tr>';
  const params = {
    action:        'get_movements',
    movement_type: document.getElementById('mvtTypeFilter')?.value || 'all',
    date_from:     document.getElementById('mvtDateFrom')?.value || '',
    date_to:       document.getElementById('mvtDateTo')?.value   || '',
  };
  api({ query: params }, r => {
    if (!r.success) {
      if (!silent) tbody.innerHTML = `<tr><td colspan="10" style="color:red;padding:20px">${esc(r.message)}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    const sig = rows.map(m => [m.id, m.movement_type, m.quantity, m.reference_number].join(':')).join('|');
    if (silent && sig === _mvtSig) return;
    _mvtSig = sig;
    _movementsData = rows;
    renderMovements(_movementsData);
  });
}

function renderMovements(data) {
  const tbody = document.getElementById('movementsBody');
  if (!data.length) { tbody.innerHTML = '<tr><td colspan="14" style="text-align:center;padding:32px;color:#64748b">No movements found</td></tr>'; return; }
  tbody.innerHTML = data.map(m => {
    const isDrRecord = (m.dr_number && m.dr_number.trim() !== '') || m.source === 'scanner';
    const rowClass = isDrRecord ? 'class="mvt-row-dr" title="This record is a Delivery Receipt entry and cannot be modified."' : '';
    const drBadge = isDrRecord ? ' <span class="badge-dr"><i class="bi bi-truck"></i> DR</span>' : '';
    
    // Format SLF docs
    let slfHtml = '—';
    if (m.return_slf || m.charge_slf) {
      const parts = [];
      if (m.return_slf) parts.push(`Ret: ${esc(m.return_slf)}`);
      if (m.charge_slf) parts.push(`Chg: ${esc(m.charge_slf)}`);
      slfHtml = parts.join('<br>');
    }

    return `<tr ${rowClass}>
      <td><strong style="font-family:monospace;font-size:12px">${esc(m.reference_number||'—')}</strong>${drBadge}</td>
      <td><strong>${esc(m.product_name)}</strong><br><span style="font-size:11px;color:#64748b">${esc(m.sku)}</span></td>
      <td>${mvtBadge(m.movement_type)}</td>
      <td style="font-weight:600">
        ${m.quantity > 0 ? '+' : ''}${m.quantity}
        <br><span style="font-size:10px;color:#64748b;font-weight:normal;">${esc(m.quantity_unit || 'piece')}</span>
      </td>
      <td>${fmtMoney(m.unit_price)}</td>
      <td style="font-weight:600">${fmtMoney(m.total_value)}</td>
      <td>${esc(m.po_number || '—')}</td>
      <td>${esc(m.si_number || '—')}</td>
      <td><strong>${esc(m.dr_number || '—')}</strong></td>
      <td style="font-size:11px;line-height:1.2;">${slfHtml}</td>
      <td>${esc(m.from_location||'—')}</td>
      <td>${esc(m.to_location||'—')}</td>
      <td>${esc(m.user_name||'System')}</td>
      <td style="font-size:12px;color:#64748b">${fmtDate(m.transaction_date)}</td>
    </tr>`;
  }).join('');
}

function submitAddMovement() {
  const form = document.getElementById('addMovementForm');
  const fd   = new FormData(form);
  fd.set('action','add_movement');
  const body = {};
  fd.forEach((v,k) => body[k]=v);
  api({ method:'POST', body }, r => {
    if (r.success) { toast(`Movement logged (${r.reference})`); closeModal('addMovementModal'); form.reset(); loadMovements(); loadCatalog(); loadAlerts(); updateAlertBadge(); }
    else toast(r.message||'Failed','danger');
  });
}

// ── ALERTS ──────────────────────────────────────────────────
function loadAlerts(silent) {
  const lowEl = document.getElementById('lowStockList');
  const expEl = document.getElementById('nearExpiryList');
  if (!silent) {
    if (lowEl) lowEl.innerHTML = '<span class="loader"></span> Loading…';
    if (expEl) expEl.innerHTML = '<span class="loader"></span> Loading…';
    if (document.getElementById('overviewLowStock')) document.getElementById('overviewLowStock').innerHTML = '<span class="loader"></span> Loading…';
    if (document.getElementById('overviewExpiry')) document.getElementById('overviewExpiry').innerHTML = '<span class="loader"></span> Loading…';
  }

  api({ query:{ action:'get_alerts' } }, r => {
    if (!r.success) return;
    const low = r.low_stock  || [];
    const exp = r.near_expiry || [];
    const sig = low.map(p => [p.id, p.quantity_in_stock, p.reorder_level].join(':')).join('|') + '#' + exp.map(p => [p.id, p.days_left, p.quantity_in_stock].join(':')).join('|');
    updateAlertBadge(r.low_count + r.expiry_count);
    const lsc = document.getElementById('lowStockCount');  if(lsc) lsc.textContent = low.length;
    const ecc = document.getElementById('expiryCount');    if(ecc) ecc.textContent = exp.length;
    if (silent && sig === _alertsSig) return;
    _alertsSig = sig;
    renderLowStockViewTable(low);

    renderLowStockList(low, 'lowStockList');
    renderExpiryList(exp, 'nearExpiryList');
    renderLowStockList(low.slice(0,5), 'overviewLowStock', true);
    renderExpiryList(exp.slice(0,5), 'overviewExpiry', true);
  });
}

function canCreatePurchaseRequestFromAlerts() {
  return typeof canCreatePurchaseRequest === 'function' && canCreatePurchaseRequest();
}

function lowStockCreatePrButton(p) {
  if (!canCreatePurchaseRequestFromAlerts() || typeof openCreatePRFromSuggestion !== 'function') return '';
  const id = parseInt(p.id, 10) || 0;
  if (!id) return '';
  const qty = Math.max(1, parseInt(p.suggested_quantity || p.reorder_quantity || p.shortage, 10) || 1);
  const price = parseFloat(p.cost_price || p.cost || 0) || 0;
  const sid = parseInt(p.supplier_id, 10) || 0;
  const nameArg = JSON.stringify(String(p.name || ''));
  return `<button type="button" class="btn btn-sm btn-primary" onclick='openCreatePRFromSuggestion(${id}, ${nameArg}, ${sid}, ${qty}, ${price})'><i class="bi bi-file-earmark-plus me-1"></i>Create PR</button>`;
}

function renderLowStockViewTable(data) {
  const tbody = document.getElementById('lowStockViewBody');
  if (!tbody) return;
  const canPr = canCreatePurchaseRequestFromAlerts();
  const cols = canPr ? 8 : 7;
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="${cols}" style="text-align:center;color:var(--text-tertiary);padding:40px;"><i class="bi bi-check-circle-fill" style="font-size:24px;color:var(--accent-emerald);"></i><br>All products are sufficiently stocked.</td></tr>`;
    return;
  }
  tbody.innerHTML = data.map(p => {
    const qty = parseInt(p.quantity_in_stock, 10) || 0;
    const reorder = parseInt(p.reorder_level, 10) || 0;
    const shortage = Math.max(0, parseInt(p.shortage, 10) || (reorder - qty));
    return `<tr data-product-id="${p.id}">
      <td><span class="badge badge-blue">${esc(p.sku || '')}</span></td>
      <td>${esc(p.name || '')}</td>
      <td>${esc(p.brand || '')}</td>
      <td><span class="badge badge-red">${qty}</span></td>
      <td>${reorder}</td>
      <td><span class="badge badge-orange">${shortage}</span></td>
      <td><span class="badge badge-red">Needs Reorder</span></td>
      ${canPr ? `<td>${lowStockCreatePrButton(p)}</td>` : ''}
    </tr>`;
  }).join('');
}

function renderLowStockList(data, elId, compact = false) {
  const el = document.getElementById(elId);
  if (!el) return;
  if (!data.length) { el.innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;font-size:13px"><i class="bi bi-check-circle-fill" style="color:#10b981;font-size:24px;display:block;margin-bottom:8px"></i>All items are sufficiently stocked!</div>'; return; }
  el.innerHTML = data.map(p => `
    <div class="alert-item low-stock">
      <div class="alert-icon warn"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <div class="alert-info" style="flex:1;min-width:0">
        <h4>${esc(p.name)}</h4>
        <p>Stock: <strong>${p.quantity_in_stock}</strong> / Reorder at: <strong>${p.reorder_level}</strong> · Suggest: ${p.reorder_quantity} units · ${esc(p.supplier_name||'No supplier')}</p>
      </div>
      ${!compact && canCreatePurchaseRequestFromAlerts() ? `<div class="alert-action">${lowStockCreatePrButton(p)}</div>` : ''}
    </div>`).join('');
}

function renderExpiryList(data, elId) {
  const el = document.getElementById(elId);
  if (!el) return;
  if (!data.length) { el.innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;font-size:13px"><i class="bi bi-check-circle-fill" style="color:#10b981;font-size:24px;display:block;margin-bottom:8px"></i>No products expiring within 90 days.</div>'; return; }
  el.innerHTML = data.map(p => {
    const d = parseInt(p.days_left);
    const urgency = d <= 14 ? 'danger' : d <= 30 ? 'warn' : 'info';
    return `
    <div class="alert-item near-expiry">
      <div class="alert-icon ${urgency}"><i class="bi bi-calendar-x-fill"></i></div>
      <div class="alert-info" style="flex:1;min-width:0">
        <h4>${esc(p.name)}</h4>
        <p>Expires: <strong>${fmtDate(p.expiry_date)}</strong> · <strong style="color:${urgency==='danger'?'#ef4444':urgency==='warn'?'#f59e0b':'#06b6d4'}">${d} days</strong> · Qty: ${p.quantity_in_stock}</p>
      </div>
    </div>`;
  }).join('');
}

function updateAlertBadge(count) {
  const b = document.getElementById('alertBadge');
  if (!b) return;
  if (count && count > 0) { b.textContent = count; b.style.display = 'inline'; }
  else b.style.display = 'none';
}

// ── STOCK BARCODES (received / scanned into inventory) ──────
function loadStockBarcodes() {
  const tbody = document.getElementById('stockBarcodesBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted"><span class="loader"></span> Loading barcodes…</td></tr>';
  const q = document.getElementById('stockBarcodeSearch')?.value || '';
  const source = document.getElementById('stockBarcodeSource')?.value || '';
  const status = document.getElementById('stockBarcodeStatus')?.value || 'active';
  api({ query: { action: 'get_stock_barcodes', q, source, status } }, r => {
    if (!r.success) {
      tbody.innerHTML = `<tr><td colspan="9" class="text-danger text-center py-4">${esc(r.message || 'Could not load barcodes')}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No barcodes registered in stock yet. Codes are added when items are scanned at receiving.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(b => {
      const src = String(b.source || '');
      const srcLabel = src === 'scanner' ? 'Barcode Scanner' : (src === 'split' ? 'Split Barcode Entry' : (src === 'manual' ? 'Manual' : (src === 'received' ? 'Received' : (src ? src.charAt(0).toUpperCase() + src.slice(1) : '—'))));
      const rec = [b.receipt_number, b.po_number].filter(Boolean).join(' · ') || '—';
      const when = b.registered_at ? String(b.registered_at).replace('T', ' ').slice(0, 16) : '—';
      const st = String(b.status || 'active');
      const stBadge = st.toLowerCase() === 'active' ? 'badge-green' : 'badge-gray';
      const qty = Number(b.quantity_in_stock || 0);
      const sell = Number(b.sellable_qty != null ? b.sellable_qty : qty);
      return `<tr>
        <td><strong style="font-family:monospace">${esc(b.barcode_value)}</strong>${b.is_primary ? ' <span class="badge badge-blue">Primary</span>' : ''}</td>
        <td>${esc(b.product_name || '—')}</td>
        <td><span class="badge badge-blue">${esc(b.sku || '—')}</span></td>
        <td>${qty}</td>
        <td>${sell}</td>
        <td>${esc(srcLabel)}</td>
        <td class="small text-muted">${esc(rec)}</td>
        <td class="small">${esc(when)}${b.registered_by_name ? `<div class="text-muted">${esc(b.registered_by_name)}</div>` : ''}</td>
        <td><span class="badge ${stBadge}">${esc(st)}</span></td>
      </tr>`;
    }).join('');
  });
}

// ── SERIAL NUMBERS ──────────────────────────────────────────
function loadSerials() {
  const tbody = document.getElementById('serialsBody');
  tbody.innerHTML = '<tr class="loading-row"><td colspan="9"><span class="loader"></span> Loading…</td></tr>';
  const params = { action:'get_serials' };
  const pf = document.getElementById('serialProductFilter')?.value;
  if (pf) params.product_id = pf;
  api({ query:params }, r => {
    if (!r.success) { tbody.innerHTML = `<tr><td colspan="9" style="color:red;padding:20px">${esc(r.message)}</td></tr>`; return; }
    _serialsData = r.data || [];
    renderSerials(_serialsData);
  });
}

function renderSerials(data) {
  const sf   = document.getElementById('serialStatusFilter')?.value || '';
  const display = sf ? data.filter(s => s.status === sf) : data;
  const tbody = document.getElementById('serialsBody');
  if (!display.length) { tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:#64748b">No serial numbers found</td></tr>'; return; }
  tbody.innerHTML = display.map(s => `<tr>
    <td><strong style="font-family:monospace">${esc(s.serial_number)}</strong></td>
    <td>${esc(s.product_name)}</td>
    <td><span class="badge badge-blue">${esc(s.sku)}</span></td>
    <td>${serialStatusBadge(s.status)}</td>
    <td>${fmtDate(s.purchase_date)}</td>
    <td style="color:${s.warranty_expiry&&new Date(s.warranty_expiry)<new Date()?'#ef4444':'inherit'}">${fmtDate(s.warranty_expiry)}</td>
    <td>${esc(s.storage_area||'—')}</td>
    <td style="font-size:12px;color:#64748b">${esc(s.notes||'—')}</td>
    <td>
      <select class="form-control" style="width:120px;padding:4px 8px;font-size:12px" onchange="updateSerialStatus(${s.id},this.value)">
        ${['Active','In Use','Returned','Retired','Lost','Damaged'].map(st => `<option value="${st}"${s.status===st?' selected':''}>${st}</option>`).join('')}
      </select>
    </td>
  </tr>`).join('');
}

function updateSerialStatus(id, status) {
  api({ method:'POST', body:{ action:'update_serial_status', id, status } }, r => {
    if (r.success) toast('Status updated');
    else toast(r.message||'Failed','danger');
  });
}

function submitAddSerial() {
  const form = document.getElementById('addSerialForm');
  const fd   = new FormData(form);
  fd.set('action','add_serial');
  const body = {};
  fd.forEach((v,k) => body[k]=v);
  api({ method:'POST', body }, r => {
    if (r.success) { toast('Serial number added!'); closeModal('addSerialModal'); form.reset(); loadSerials(); }
    else toast(r.message||'Failed','danger');
  });
}

function openAddBatchModal() {
  openModal('addBatchModal');
  populateProdSelects();
  populateSupSelects();
}

function openAddSerialModal() {
  openModal('addSerialModal');
  populateProdSelects();
  populateLocSelects();
}

// ── STOCK RETURN HANDLERS ────────────────────────────────────
function openStockReturnModal(productId = null) {
  openModal('stockReturnModal');
  populateProdSelects();
  populateLocSelects();
  const sel = document.getElementById('returnProductSelect');
  if (sel && productId) {
    sel.value = productId;
    onReturnProductChange();
  }
  syncReturnReplacementVisibility();
}

function sellableProductOptions(excludeId = null) {
  return (_productsList || [])
    .filter(p => String(p.id) !== String(excludeId || ''))
    .filter(p => Number(p.sellable_qty != null ? p.sellable_qty : p.quantity_in_stock) > 0)
    .map(p => {
      const sell = Number(p.sellable_qty != null ? p.sellable_qty : p.quantity_in_stock);
      const bc = p.barcode ? ` · ${esc(p.barcode)}` : '';
      return `<option value="${p.id}">[${esc(p.sku)}] ${esc(p.name)} — sellable ${sell}${bc}</option>`;
    })
    .join('');
}

function fillReplacementProductSelects(excludeId = null) {
  const opts = '<option value="">-- Select a valid sellable product --</option>' + sellableProductOptions(excludeId);
  ['returnReplacementSelect', 'replaceProductSelect'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const keep = el.value;
    el.innerHTML = opts;
    if (keep && [...el.options].some(o => o.value === keep)) el.value = keep;
  });
}

function syncReturnReplacementVisibility() {
  const type = document.getElementById('returnTypeSelect')?.value || '';
  const group = document.getElementById('returnReplacementGroup');
  const show = type.toLowerCase().includes('replacement');
  if (group) group.style.display = show ? '' : 'none';
  const orig = document.getElementById('returnProductSelect')?.value;
  fillReplacementProductSelects(orig);
}

function onReturnProductChange() {
  const pid = document.getElementById('returnProductSelect')?.value;
  const batchSel = document.getElementById('returnBatchSelect');
  const priceInput = document.getElementById('returnUnitPrice');

  if (!pid) {
    if (batchSel) batchSel.innerHTML = '<option value="">-- Optional / Select Batch --</option>';
    if (priceInput) priceInput.value = '';
    fillReplacementProductSelects(null);
    return;
  }

  const prod = _productsList.find(p => String(p.id) === String(pid));
  if (prod && priceInput) {
    priceInput.value = prod.selling_price || prod.cost_price || '0.00';
  }
  fillReplacementProductSelects(pid);

  if (batchSel) {
    batchSel.innerHTML = '<option value="">Loading batches...</option>';
    api({ query: { action: 'get_product_batches', product_id: pid } }, r => {
      if (r.success && r.data && r.data.length > 0) {
        batchSel.innerHTML = '<option value="">-- Optional / Select Batch --</option>' +
          r.data.map(b => `<option value="${b.id}">Batch #${esc(b.batch_number)} (${b.quantity_remaining} left${b.expiry_date ? ', Exp: ' + fmtDate(b.expiry_date) : ''})</option>`).join('');
      } else {
        batchSel.innerHTML = '<option value="">No batches found for this product</option>';
      }
    });
  }
}

function submitStockReturn() {
  const form = document.getElementById('stockReturnForm');
  if (!form) return;
  const pid = document.getElementById('returnProductSelect')?.value;
  const qty = form.querySelector('[name="quantity"]')?.value;
  const retType = document.getElementById('returnTypeSelect')?.value || '';
  if (!pid || !qty || parseInt(qty) <= 0) {
    toast('Please select a product and valid return quantity.', 'warn');
    return;
  }
  if (retType.toLowerCase().includes('replacement')) {
    const repl = document.getElementById('returnReplacementSelect')?.value;
    if (!repl) {
      toast('Select a replacement product for this return.', 'warn');
      return;
    }
    if (String(repl) === String(pid)) {
      toast('Replacement product must be different from the original.', 'warn');
      return;
    }
  }

  const fd = new FormData(form);
  fd.set('action', 'process_stock_return');
  const body = {};
  fd.forEach((v, k) => body[k] = v);

  api({ method: 'POST', body }, r => {
    if (r.success) {
      toast(r.message || 'Stock return recorded successfully!');
      closeModal('stockReturnModal');
      form.reset();
      syncReturnReplacementVisibility();
      if (typeof loadCatalog === 'function') loadCatalog();
      if (typeof loadMovements === 'function') loadMovements();
      if (typeof loadAlerts === 'function') loadAlerts();
      if (typeof loadProductsList === 'function') loadProductsList();
      if (typeof loadReturnReplacementHistory === 'function') loadReturnReplacementHistory();
      if (document.getElementById('productsTable')) setTimeout(() => window.location.reload(), 600);
    } else {
      toast(r.message || 'Failed to process stock return', 'danger');
    }
  });
}

function openProductReplaceModal(productId, sku, name) {
  if (!_productsList || !_productsList.length) loadProductsList();
  const idEl = document.getElementById('replaceOriginalId');
  const label = document.getElementById('replaceOriginalLabel');
  if (idEl) idEl.value = productId || '';
  if (label) label.textContent = sku ? `[${sku}] ${name || ''}` : (name || '—');
  fillReplacementProductSelects(productId);
  const qty = document.getElementById('replaceQty');
  if (qty) qty.value = '1';
  openModal('productReplaceModal');
}

function submitProductReplacement() {
  const form = document.getElementById('productReplaceForm');
  if (!form) return;
  const orig = document.getElementById('replaceOriginalId')?.value;
  const repl = document.getElementById('replaceProductSelect')?.value;
  const qty = form.querySelector('[name="quantity"]')?.value;
  if (!orig || !repl || !qty || parseInt(qty) <= 0) {
    toast('Original product, replacement product, and quantity are required.', 'warn');
    return;
  }
  if (String(orig) === String(repl)) {
    toast('Replacement product must be different from the original.', 'warn');
    return;
  }
  const fd = new FormData(form);
  fd.set('action', 'process_product_replacement');
  const body = {};
  fd.forEach((v, k) => body[k] = v);
  api({ method: 'POST', body }, r => {
    if (r.success) {
      toast(r.message || 'Replacement recorded.');
      closeModal('productReplaceModal');
      form.reset();
      if (typeof loadCatalog === 'function') loadCatalog();
      if (typeof loadProductsList === 'function') loadProductsList();
      if (typeof loadReturnReplacementHistory === 'function') loadReturnReplacementHistory();
      if (document.getElementById('productsTable')) setTimeout(() => window.location.reload(), 600);
    } else {
      toast(r.message || 'Failed to record replacement', 'danger');
    }
  });
}

function loadReturnReplacementHistory() {
  const retBody = document.getElementById('returnHistoryBody');
  const replBody = document.getElementById('replacementHistoryBody');
  if (retBody) {
    api({ query: { action: 'list_product_returns' } }, r => {
      if (!retBody) return;
      const rows = r.data || [];
      if (!r.success || !rows.length) {
        retBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No returns recorded yet.</td></tr>';
        return;
      }
      retBody.innerHTML = rows.map(row => `<tr>
        <td><code>${esc(row.return_number || row.reference_number || '—')}</code></td>
        <td><span class="badge ${row.party === 'Supplier' ? 'badge-orange' : 'badge-blue'}">${esc(row.party || 'Customer')}</span></td>
        <td>${esc(row.sku || '')} ${esc(row.product_name || '')}</td>
        <td>${esc(String(row.quantity || 0))}</td>
        <td>${esc(row.condition_status || row.return_type || '—')}</td>
        <td>${row.replacement_sku ? esc(row.replacement_sku + ' ' + (row.replacement_name || '')) : '—'}</td>
        <td>${esc(row.reason || '—')}</td>
        <td>${fmtDate(row.created_at)}</td>
      </tr>`).join('');
    });
  }
  if (replBody) {
    api({ query: { action: 'list_product_replacements' } }, r => {
      if (!replBody) return;
      const rows = r.data || [];
      if (!r.success || !rows.length) {
        replBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No replacements recorded yet.</td></tr>';
        return;
      }
      replBody.innerHTML = rows.map(row => `<tr>
        <td><code>${esc(row.replacement_number || '—')}</code></td>
        <td>${esc((row.original_sku || '') + ' ' + (row.original_name || ''))}</td>
        <td>${esc((row.replacement_sku || '') + ' ' + (row.replacement_name || ''))}</td>
        <td>${esc(String(row.quantity || 0))}</td>
        <td>${esc(row.reason || '—')}</td>
        <td>${esc(row.source || '—')}</td>
        <td>${fmtDate(row.created_at)}</td>
      </tr>`).join('');
    });
  }
}

// ── WMS: LOCATION STOCK / TRANSFERS ──────────────────────────
function locLabel(l) {
  if (!l) return '';
  const code = l.location_code || '';
  const area = l.storage_area || '';
  const shelf = l.shelf_rack || l.section || '';
  return [code, area, shelf].filter(Boolean).join(' · ') || ('#' + l.id);
}

function fillWmsLocationSelects() {
  const optsAll = '<option value="">All Locations</option>' +
    _locations.map(l => `<option value="${l.id}">${esc(locLabel(l))}</option>`).join('');
  const optsReq = '<option value="">-- Select --</option>' +
    _locations.map(l => `<option value="${l.id}">${esc(locLabel(l))}</option>`).join('');
  const filt = document.getElementById('locStockLocationFilter');
  if (filt) {
    const keep = filt.value;
    filt.innerHTML = optsAll;
    if (keep) filt.value = keep;
  }
  ['trfFrom', 'trfTo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      const keep = el.value;
      el.innerHTML = optsReq;
      if (keep) el.value = keep;
    }
  });
}

function loadLocationStock(silent) {
  const body = document.getElementById('locStockBody');
  if (!body) return;
  if (!silent) body.innerHTML = '<tr class="loading-row"><td colspan="7" class="text-center py-4 text-muted">Loading…</td></tr>';
  const q = {
    action: 'get_location_stock',
    search: document.getElementById('locStockSearch')?.value || '',
    location_id: document.getElementById('locStockLocationFilter')?.value || ''
  };
  if (!silent) fillWmsLocationSelects();
  api({ query: q }, r => {
    if (!r.success) {
      if (!silent) body.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">${esc(r.message || 'Failed to load')}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    const sig = rows.map(row => [row.product_id || row.sku, row.location_id || row.location_code, row.quantity, row.total_stock].join(':')).join('|') + '|' + (q.search || '') + '|' + (q.location_id || '');
    if (silent && sig === _locStockSig) return;
    _locStockSig = sig;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No location stock rows yet.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(row => {
      const loc = [row.location_code, row.storage_area, row.shelf_rack].filter(Boolean).join(' · ');
      return `<tr>
        <td data-label="SKU">${esc(row.sku)}</td>
        <td data-label="Product">${esc(row.name)}</td>
        <td data-label="Brand">${esc(row.brand || '—')}</td>
        <td data-label="Location">${esc(loc)}</td>
        <td data-label="Qty Here"><strong>${parseInt(row.quantity, 10) || 0}</strong></td>
        <td data-label="Total Stock">${parseInt(row.total_stock, 10) || 0}</td>
        <td data-label="Unit">${esc(row.unit_of_measure || '—')}</td>
      </tr>`;
    }).join('');
  });
}

function loadTransfers() {
  const body = document.getElementById('transfersBody');
  if (!body) return;
  body.innerHTML = '<tr class="loading-row"><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr>';
  api({ query: { action: 'get_transfers' } }, r => {
    if (!r.success) {
      body.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">${esc(r.message || 'Failed to load')}</td></tr>`;
      return;
    }
    const rows = r.data || [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No transfers yet.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(t => {
      const from = [t.from_code, t.from_area].filter(Boolean).join(' · ') || '—';
      const to = [t.to_code, t.to_area].filter(Boolean).join(' · ') || '—';
      return `<tr>
        <td data-label="Transfer #">${esc(t.transfer_number)}</td>
        <td data-label="From">${esc(from)}</td>
        <td data-label="To">${esc(to)}</td>
        <td data-label="Items">${parseInt(t.item_count, 10) || 0}</td>
        <td data-label="Qty">${parseInt(t.total_qty, 10) || 0}</td>
        <td data-label="Status">${statusBadge(t.status)}</td>
        <td data-label="By">${esc(t.transferred_by_name || '—')}</td>
        <td data-label="Date">${fmtDate(t.completed_at || t.created_at)}</td>
      </tr>`;
    }).join('');
  });
}

function openTransferModal() {
  fillWmsLocationSelects();
  const prodSel = document.getElementById('trfProduct');
  if (prodSel) {
    const opts = '<option value="">-- Select product --</option>' +
      (_productsList || []).map(p => `<option value="${p.id}">${esc(p.sku)} — ${esc(p.name)} (stock: ${p.quantity_in_stock ?? 0})</option>`).join('');
    prodSel.innerHTML = opts;
  }
  const qty = document.getElementById('trfQty');
  if (qty) qty.value = '1';
  const notes = document.getElementById('trfNotes');
  if (notes) notes.value = '';
  if (!_productsList || !_productsList.length) loadProductsList();
  openModal('transferStockModal');
}

function submitTransfer() {
  const product_id = document.getElementById('trfProduct')?.value;
  const from_location_id = document.getElementById('trfFrom')?.value;
  const to_location_id = document.getElementById('trfTo')?.value;
  const quantity = document.getElementById('trfQty')?.value;
  const notes = document.getElementById('trfNotes')?.value || '';
  if (!product_id || !from_location_id || !to_location_id || !quantity || parseInt(quantity, 10) <= 0) {
    toast('Product, locations, and quantity are required.', 'warn');
    return;
  }
  if (from_location_id === to_location_id) {
    toast('From and To locations must be different.', 'warn');
    return;
  }
  api({
    method: 'POST',
    body: { action: 'transfer_stock', product_id, from_location_id, to_location_id, quantity, notes }
  }, r => {
    if (r.success) {
      toast(r.message || `Transfer ${r.transfer_number || ''} completed`);
      closeModal('transferStockModal');
      loadTransfers();
      loadLocationStock();
      if (typeof loadProductsList === 'function') loadProductsList();
    } else {
      toast(r.message || 'Transfer failed', 'danger');
    }
  });
}

function invModalOpen(ids) {
  return (ids || []).some(id => {
    const el = document.getElementById(id);
    return !!(el && (el.classList.contains('open') || el.classList.contains('show') || el.style.display === 'flex'));
  });
}

function invFormBusy() {
  if (window.adminScannerActive || window.dashScannerIsActive) return true;
  if (invModalOpen(['addProductModal', 'editProductModal', 'addMovementModal', 'addSerialModal', 'addBatchModal', 'stockReturnModal', 'productReplaceModal', 'transferStockModal', 'productImageModal'])) return true;
  const form = document.getElementById('adminStockMovementForm');
  const ae = document.activeElement;
  if (form && ae && form.contains(ae)) return true;
  return false;
}

function patchProductsTableQuantities(products) {
  const table = document.getElementById('productsTable');
  if (!table || !products || !products.length) return;
  const bySku = {};
  products.forEach(p => { if (p.sku) bySku[String(p.sku)] = p; });
  table.querySelectorAll('tbody tr').forEach(tr => {
    const sku = (tr.querySelector('td')?.textContent || '').trim();
    const p = bySku[sku];
    if (!p) return;
    const cells = tr.querySelectorAll('td');
    if (cells.length < 8) return;
    const qty = parseInt(p.quantity_in_stock, 10) || 0;
    const sellable = parseInt(p.sellable_qty != null ? p.sellable_qty : qty, 10) || 0;
    const reorder = parseInt(p.reorder_level, 10) || 0;
    const qtyClass = qty <= reorder ? 'badge badge-red' : 'badge badge-green';
    const sellClass = sellable <= 0 ? 'badge badge-red' : (sellable <= reorder ? 'badge badge-orange' : 'badge badge-green');
    cells[5].innerHTML = `<span class="${qtyClass}">${qty}</span>`;
    cells[6].innerHTML = `<span class="${sellClass}">${sellable}</span>`;
    tr.dataset.stock = qty <= 0 ? 'out' : (qty <= reorder ? 'low' : 'in');
  });
  if (typeof applyProductFilters === 'function') applyProductFilters();
}

function refreshStockMovementHistory(silent) {
  const bodies = document.querySelectorAll('.js-stock-movement-history-body');
  if (!bodies.length) return;
  api({ query: { action: 'get_movements' } }, r => {
    if (!r.success) return;
    const rows = r.data || [];
    const sig = rows.map(m => [m.id, m.movement_type, m.quantity, m.reference_number].join(':')).join('|');
    if (silent && sig === _smHistSig) return;
    _smHistSig = sig;
    const html = rows.length ? rows.map(m => {
      const type = String(m.movement_type || '');
      const lower = type.toLowerCase();
      let badge = 'badge-orange';
      if (lower.includes('in') || lower.includes('receipt') || lower.includes('return')) badge = 'badge-green';
      else if (lower.includes('out') || lower.includes('issue') || lower.includes('damage') || lower.includes('expiry')) badge = 'badge-red';
      const rawDate = m.transaction_date || m.movement_date || '';
      let when = 'N/A';
      if (rawDate) {
        const dt = new Date(String(rawDate).replace(/-/g, '/'));
        when = Number.isNaN(dt.getTime()) ? String(rawDate) : dt.toLocaleString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
      }
      return `<tr data-movement-id="${m.id || ''}">
        <td>${esc(when)}</td>
        <td>${esc(m.product_name || 'N/A')}</td>
        <td><span class="badge ${badge}">${esc(type || 'N/A')}</span></td>
        <td>${m.quantity != null ? m.quantity : 'N/A'}</td>
        <td>${esc(m.reference_number || 'N/A')}</td>
        <td>${esc(m.user_name || 'N/A')}</td>
        <td>${esc(m.remarks || m.notes || 'N/A')}</td>
      </tr>`;
    }).join('') : '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted);">No stock movement records available.</td></tr>';
    bodies.forEach(tbody => { tbody.innerHTML = html; });
  });
}

function inventoryLiveRefreshTick() {
  if (document.hidden) return;
  try {
    const section = document.querySelector('.content-section.active')?.id || '';
    if (section === 'section-low-stock-view' || section === 'section-expiry-view' || section === 'section-dashboard' || document.getElementById('lowStockList')) {
      loadAlerts(true);
    }
    if (section === 'section-inventory') {
      if (document.getElementById('catalogBody') && !invFormBusy()) loadCatalog(true);
      if (!invFormBusy()) {
        api({ query: { action: 'get_products_list' } }, r => {
          if (r.success) patchProductsTableQuantities(r.data || []);
        });
      }
    }
    if (section === 'section-barcode-scanner' && typeof dashLoadRecentScans === 'function') {
      dashLoadRecentScans();
    }
    if (section === 'section-data-entry') {
      if (document.getElementById('movementsBody') && !invModalOpen(['addMovementModal'])) loadMovements(true);
      refreshStockMovementHistory(true);
    }
    if (section === 'section-location-stock-view' && !invFormBusy()) loadLocationStock(true);
    const mvtPanel = document.getElementById('inv-tab-movements');
    if (mvtPanel && mvtPanel.style.display !== 'none' && document.getElementById('movementsBody') && !invFormBusy()) {
      loadMovements(true);
    }
  } catch (err) {
    console.error('Inventory live refresh skipped', err);
  }
}

function startInventoryLiveRefresh() {
  if (_invLiveTimer) return;
  inventoryLiveRefreshTick();
  _invLiveTimer = setInterval(inventoryLiveRefreshTick, INV_LIVE_REFRESH_MS);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) inventoryLiveRefreshTick();
  });
}

// ── INIT ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  bindProductImageInputs();
  loadLookups(() => {
    fillWmsLocationSelects();
    loadProductsList();
    loadAlerts();   // loads overview + alerts tab
    if (typeof loadReturnReplacementHistory === 'function') loadReturnReplacementHistory();
  });
  startInventoryLiveRefresh();
});
