/* Inventory-related admin dashboard actions */
function applyProductFilters() {
    const searchInput = document.getElementById('productSearchInput');
    const expiryFilter = document.getElementById('expiryFilter');
    const stockFilter = document.getElementById('stockFilter');
    const statusFilter = document.getElementById('catalogStatusFilter');
    const categoryFilter = document.getElementById('categoryFilter');
    const table = document.getElementById('productsTable');
    if (!table) return;

    const search = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const expiry = expiryFilter ? expiryFilter.value : '';
    const stock = stockFilter ? stockFilter.value : '';
    const status = statusFilter ? statusFilter.value : '';
    const category = categoryFilter ? categoryFilter.value : '';
    const tbody = table.getElementsByTagName('tbody')[0];
    if (!tbody) return;
    const rows = tbody.getElementsByTagName('tr');

    for (const row of rows) {
        const matchesSearch = !search || row.textContent.toLowerCase().includes(search) || (row.dataset.barcodes || '').toLowerCase().includes(search);
        const matchesExpiry = !expiry || row.dataset.expiry === expiry;
        const matchesStock = !stock || row.dataset.stock === stock;
        const matchesStatus = !status || (row.dataset.status || '') === status;
        const matchesCategory = !category || row.dataset.category === category || row.dataset.parent === category;
        row.style.display = matchesSearch && matchesExpiry && matchesStock && matchesStatus && matchesCategory ? '' : 'none';
    }
}

function applyInventoryStockFilters() {
    const searchInput = document.getElementById('inventoryStockSearch');
    const expiryFilter = document.getElementById('inventoryExpiryFilter');
    const stockFilter = document.getElementById('inventoryStockFilter');
    const categoryFilter = document.getElementById('inventoryCategoryFilter');
    const table = document.getElementById('inventoryStockTable');
    if (!table) return;
    const search = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const expiry = expiryFilter ? expiryFilter.value : '';
    const stock = stockFilter ? stockFilter.value : '';
    const category = categoryFilter ? categoryFilter.value : '';
    const tbody = table.getElementsByTagName('tbody')[0];
    if (!tbody) return;
    for (const row of tbody.getElementsByTagName('tr')) {
        const hay = (row.textContent || '') + ' ' + (row.dataset.barcodes || '') + ' ' + (row.dataset.batch || '') + ' ' + (row.dataset.sku || '');
        const matchesSearch = !search || hay.toLowerCase().includes(search);
        const matchesExpiry = !expiry || row.dataset.expiry === expiry;
        const matchesStock = !stock || row.dataset.stock === stock;
        const matchesCategory = !category || row.dataset.category === category || row.dataset.parent === category;
        row.style.display = matchesSearch && matchesExpiry && matchesStock && matchesCategory ? '' : 'none';
    }
}

function viewCatalogProduct(data) {
    const body = document.getElementById('viewProductModalBody');
    const modalEl = document.getElementById('viewProductModal');
    if (!body || !modalEl) return;
    const p = data || {};
    const row = (label, value) => `<div class="col-md-6 mb-2"><div class="text-muted" style="font-size:11px;text-transform:uppercase">${label}</div><div>${value || '—'}</div></div>`;
    body.innerHTML = `<div class="row">
      ${row('SKU', p.sku ? `<span class="badge badge-blue">${escHtml(p.sku)}</span>` : '—')}
      ${row('Status', escHtml(p.status || 'Active'))}
      ${row('Product Name', escHtml(p.name))}
      ${row('Brand', escHtml(p.brand))}
      ${row('Item Description', escHtml(p.description))}
      ${row('Packaging', escHtml(p.packaging))}
      ${row('Unit', escHtml(p.uom))}
      ${row('Supplier', escHtml(p.supplier_name))}
      ${row('Category', escHtml(p.parent_category || p.category))}
      ${row('Sub-Category', escHtml(p.parent_category ? p.category : '—'))}
      ${row('Temperature', escHtml(p.storage_temperature || '—'))}
      ${row('Barcode', escHtml(p.barcode))}
    </div>`;
    new bootstrap.Modal(modalEl).show();
}

function escHtml(v) {
    return String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function openStockConditionModal(productId, batchId, name, batchLot, type, sellable) {
    const title = document.getElementById('stockConditionTitle');
    const err = document.getElementById('stockConditionError');
    if (title) title.textContent = type === 'Missing' ? 'Record Missing Stock' : 'Record Damaged Stock';
    if (err) { err.classList.add('d-none'); err.textContent = ''; }
    const pid = document.getElementById('stockConditionProductId');
    const bid = document.getElementById('stockConditionBatchId');
    const typ = document.getElementById('stockConditionType');
    const pname = document.getElementById('stockConditionProductName');
    const blot = document.getElementById('stockConditionBatch');
    const qty = document.getElementById('stockConditionQty');
    const sell = document.getElementById('stockConditionSellable');
    const reason = document.getElementById('stockConditionReason');
    if (pid) pid.value = String(productId || '');
    if (bid) bid.value = String(batchId || '');
    if (typ) typ.value = type === 'Missing' ? 'Missing' : 'Damage';
    if (pname) pname.value = name || '';
    if (blot) blot.value = batchLot || 'N/A';
    if (qty) { qty.value = ''; qty.max = String(Math.max(1, parseInt(sellable, 10) || 1)); }
    if (sell) sell.textContent = String(parseInt(sellable, 10) || 0);
    if (reason) reason.value = '';
    if (typeof openModal === 'function') openModal('stockConditionModal');
    else document.getElementById('stockConditionModal')?.classList.add('open');
}

function submitStockCondition(ev) {
    if (ev) ev.preventDefault();
    const err = document.getElementById('stockConditionError');
    const showErr = (msg) => {
        if (!err) { alert(msg); return; }
        err.textContent = msg;
        err.classList.remove('d-none');
    };
    const productId = parseInt(document.getElementById('stockConditionProductId')?.value || '0', 10);
    const batchId = parseInt(document.getElementById('stockConditionBatchId')?.value || '0', 10);
    const type = document.getElementById('stockConditionType')?.value || 'Damage';
    const qty = parseInt(document.getElementById('stockConditionQty')?.value || '0', 10);
    const reason = (document.getElementById('stockConditionReason')?.value || '').trim();
    const sellable = parseInt(document.getElementById('stockConditionSellable')?.textContent || '0', 10);
    if (!productId || qty <= 0) {
        showErr('Enter a valid quantity.');
        return false;
    }
    if (qty > sellable) {
        showErr('Quantity cannot exceed sellable stock. Damage + Missing cannot exceed QTY.');
        return false;
    }
    const body = new URLSearchParams();
    body.set('action', 'record_stock_condition');
    body.set('product_id', String(productId));
    if (batchId > 0) body.set('batch_id', String(batchId));
    body.set('type', type);
    body.set('quantity', String(qty));
    body.set('reason', reason);
    body.set('remarks', reason);
    fetch('inventory_api.php', { method: 'POST', body })
        .then(r => r.json())
        .then((res) => {
            if (!res || !res.success) {
                showErr((res && res.message) || 'Could not save the adjustment.');
                return;
            }
            if (typeof closeModal === 'function') closeModal('stockConditionModal');
            if (typeof toast === 'function') toast(res.message || 'Stock adjustment saved.', 'success');
            else alert(res.message || 'Stock adjustment saved.');
            window.location.reload();
        })
        .catch(() => showErr('Could not save the adjustment. Please try again.'));
    return false;
}

function loadStockConditionHistory() {
    const tbody = document.getElementById('stockConditionHistoryBody');
    if (!tbody) return;
    fetch('inventory_api.php?action=get_stock_condition_history')
        .then(r => r.json())
        .then((res) => {
            const rows = (res && res.success && Array.isArray(res.data)) ? res.data : [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No damage or missing adjustments yet.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map((r) => {
                const when = r.created_at ? String(r.created_at).replace('T', ' ').slice(0, 16) : '—';
                const user = r.user_name || r.username || '—';
                const note = [r.reason, r.remarks].filter(Boolean).join(' — ') || '—';
                const typeClass = r.adjustment_type === 'Missing' ? 'badge-red' : 'badge-orange';
                return `<tr>
                  <td>${escHtml(r.product_name || '')}<div class="text-muted" style="font-size:11px">${escHtml(r.sku || '')}</div></td>
                  <td>${escHtml(r.batch_number || 'N/A')}</td>
                  <td>${parseInt(r.quantity, 10) || 0}</td>
                  <td><span class="badge ${typeClass}">${escHtml(r.adjustment_type || '')}</span></td>
                  <td>${escHtml(note)}</td>
                  <td style="font-size:12px">${escHtml(when)}</td>
                  <td>${escHtml(user)}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Could not load adjustment history.</td></tr>';
        });
}

function showProductImageModal(name, imagePath) {
    const modalEl = document.getElementById('productImageModal');
    const titleEl = document.getElementById('productImageModalTitle');
    const bodyEl = document.getElementById('productImageModalBody');
    if (!modalEl || !titleEl || !bodyEl) return;

    titleEl.textContent = name || 'Product Image';
    bodyEl.innerHTML = '';

    const image = String(imagePath || '').trim();
    if (!image) {
        bodyEl.innerHTML = '<div class="product-image-empty">No image available.</div>';
    } else {
        const img = document.createElement('img');
        img.className = 'product-image-preview';
        img.alt = name || 'Product Image';
        img.src = image;
        img.onerror = function () {
            bodyEl.innerHTML = '<div class="product-image-empty">No image available.</div>';
        };
        bodyEl.appendChild(img);
    }

    new bootstrap.Modal(modalEl).show();
}

function renderProductUploadPreview(previewId, imagePath, emptyText) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    preview.innerHTML = '';
    const image = String(imagePath || '').trim();
    if (!image) {
        preview.innerHTML = `<span>${emptyText || 'No image selected.'}</span>`;
        return;
    }
    const img = document.createElement('img');
    img.src = image;
    img.alt = 'Product image preview';
    img.onerror = function () {
        preview.innerHTML = '<span>No image available.</span>';
    };
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
                renderProductUploadPreview(previewId, '', 'No image selected.');
                return;
            }
            const allowed = ['image/jpeg', 'image/png', 'image/webp'];
            const validExtension = /\.(jpe?g|png|webp)$/i.test(file.name);
            if (!allowed.includes(file.type) || !validExtension) {
                input.value = '';
                if (message) message.textContent = 'Only JPG, JPEG, PNG, and WEBP images are allowed.';
                renderProductUploadPreview(previewId, '', 'No image selected.');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                input.value = '';
                if (message) message.textContent = 'Product image must be 5 MB or smaller.';
                renderProductUploadPreview(previewId, '', 'No image selected.');
                return;
            }
            const reader = new FileReader();
            reader.onload = e => renderProductUploadPreview(previewId, e.target.result, 'No image selected.');
            reader.readAsDataURL(file);
        });
    });
}

document.addEventListener('DOMContentLoaded', bindProductImageInputs);

function editProduct(sku, name, category, qty, price, location, cost, uom, supplierId, barcode, expiryDate, brand, size, packaging, reorderLevel, imagePath, expiryTracking, categoryId, parentCategoryId, storageTemp, description, status) {
    document.getElementById('edit_sku').value = sku;
    document.getElementById('edit_sku_display').value = sku;
    document.getElementById('edit_name').value = name;
    const descEl = document.getElementById('edit_description');
    if (descEl) descEl.value = description || '';
    const statusEl = document.getElementById('edit_status');
    if (statusEl) {
        const st = status || 'Active';
        if (![...statusEl.options].some(o => o.value === st)) {
            const extra = document.createElement('option');
            extra.value = st;
            extra.textContent = st;
            statusEl.appendChild(extra);
        }
        statusEl.value = st;
    }
    const barcodeEl = document.getElementById('edit_barcode');
    if (barcodeEl) barcodeEl.value = barcode || '';
    const brandEl = document.getElementById('edit_brand');
    if (brandEl) brandEl.value = brand || '';
    const sizeEl = document.getElementById('edit_size');
    if (sizeEl) sizeEl.value = size || '';
    const packEl = document.getElementById('edit_packaging');
    if (packEl) packEl.value = packaging || '';
    const catText = document.getElementById('edit_category');
    if (catText) catText.value = category || '';
    document.getElementById('edit_quantity').value = qty;
    const reorderEl = document.getElementById('edit_reorder_level');
    if (reorderEl) reorderEl.value = reorderLevel != null && reorderLevel !== '' ? reorderLevel : 50;
    const trackEl = document.getElementById('edit_expiry_tracking');
    if (trackEl) trackEl.value = String(parseInt(expiryTracking, 10) === 1 ? 1 : 0);
    document.getElementById('edit_expiry_date').value = expiryDate || '';
    document.getElementById('edit_price').value = price;
    document.getElementById('edit_cost').value = cost;
    const uomEl = document.getElementById('edit_unit_of_measure');
    if (uomEl) {
        const want = uom || 'pcs';
        if (![...uomEl.options].some(o => o.value === want)) {
            const extra = document.createElement('option');
            extra.value = want;
            extra.textContent = want;
            uomEl.appendChild(extra);
        }
        uomEl.value = want;
    }
    document.getElementById('edit_supplier_id').value = supplierId || 0;
    document.getElementById('edit_location').value = location;
    const parentEl = document.getElementById('edit_parent_category_id');
    if (parentEl) {
        parentEl.value = parentCategoryId ? String(parentCategoryId) : '';
        if (typeof onCatalogParentChange === 'function') onCatalogParentChange('edit', categoryId, false);
    }
    if (typeof setStorageTempFields === 'function') setStorageTempFields('edit', storageTemp || '');
    const imageInput = document.getElementById('edit_product_image');
    if (imageInput) imageInput.value = '';
    const imageMessage = document.getElementById('editProductImageMessage');
    if (imageMessage) imageMessage.textContent = 'Current image is kept unless a replacement is selected.';
    renderProductUploadPreview('editProductImagePreview', imagePath, 'No image available.');
    if (typeof syncExpiryTrackingFields === 'function') syncExpiryTrackingFields();
    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}

function deleteProduct(sku, name) {
    document.getElementById('delete_sku').value = sku;
    document.getElementById('delete_product_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteProductModal')).show();
}

function editCategory(id, name, description, defaultExpiryTracking, parentId) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    document.getElementById('edit_category_description').value = description;
    const defEl = document.getElementById('edit_category_default_expiry_tracking');
    if (defEl) {
        if (defaultExpiryTracking === 1 || defaultExpiryTracking === '1') defEl.value = '1';
        else if (defaultExpiryTracking === 0 || defaultExpiryTracking === '0') defEl.value = '0';
        else defEl.value = '';
    }
    const parentEl = document.getElementById('edit_category_parent_id');
    if (parentEl) {
        parentEl.value = (parentId === 1 || parentId === 0 || parentId) ? String(parentId || '') : '';
        if (parentId === null || parentId === undefined || parentId === 'null') parentEl.value = '';
        else parentEl.value = String(parentId);
    }
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function syncExpiryTrackingFields() {
    const editDate = document.getElementById('edit_expiry_date');
    const newDate = document.getElementById('new_expiry_date');
    const editTracked = document.getElementById('edit_expiry_tracking')?.value === '1';
    const newTracked = document.getElementById('new_expiry_tracking')?.value === '1';
    if (editDate) {
        editDate.disabled = document.getElementById('edit_expiry_tracking') ? !editTracked : false;
        if (!editTracked) editDate.removeAttribute('required');
        const wrap = editDate.closest('.form-group');
        if (wrap) wrap.classList.toggle('d-none', !!document.getElementById('edit_expiry_tracking') && !editTracked);
    }
    if (newDate) {
        newDate.disabled = document.getElementById('new_expiry_tracking') ? !newTracked : false;
        if (!newTracked) newDate.removeAttribute('required');
        const wrap = newDate.closest('.form-group') || newDate.closest('.col-md-4');
        if (wrap && document.getElementById('new_expiry_tracking')) wrap.classList.toggle('d-none', !newTracked);
    }
}

function applyCategoryExpiryDefault(sel) {
    const track = document.getElementById(sel && sel.id === 'edit_product_subcategory_id' ? 'edit_expiry_tracking' : 'new_expiry_tracking');
    if (!sel || !track) return;
    const opt = sel.options[sel.selectedIndex];
    const def = opt ? opt.getAttribute('data-default-expiry') : '';
    if (def === '0' || def === '1') track.value = def;
    syncExpiryTrackingFields();
    const hidden = document.getElementById('edit_category');
    if (hidden && sel.id === 'edit_product_subcategory_id') hidden.value = opt && opt.dataset.name ? opt.dataset.name : (opt ? opt.textContent : '');
}

function onCatalogParentChange(which, selectedSubId, applyDefault) {
    const parentEl = document.getElementById(which === 'edit' ? 'edit_parent_category_id' : 'new_parent_category_id');
    const subEl = document.getElementById(which === 'edit' ? 'edit_product_subcategory_id' : 'new_category_id');
    if (!parentEl || !subEl) return;
    const tree = window.CLZ_CATEGORY_TREE || {};
    const items = tree[parentEl.value] || tree[String(parentEl.value)] || [];
    const keep = selectedSubId != null && selectedSubId !== '' ? String(selectedSubId) : subEl.value;
    subEl.innerHTML = '<option value="">Select Subcategory</option>';
    items.forEach((it) => {
        const opt = document.createElement('option');
        opt.value = String(it.id);
        opt.textContent = it.name;
        opt.dataset.name = it.name;
        opt.setAttribute('data-default-expiry', it.default_expiry_tracking == null || it.default_expiry_tracking === '' ? '' : String(it.default_expiry_tracking));
        if (keep && String(it.id) === String(keep)) opt.selected = true;
        subEl.appendChild(opt);
    });
    if (applyDefault !== false && typeof applyCategoryExpiryDefault === 'function') applyCategoryExpiryDefault(subEl);
    else {
        const hidden = document.getElementById('edit_category');
        const opt = subEl.options[subEl.selectedIndex];
        if (hidden && opt) hidden.value = opt.dataset.name || opt.textContent || hidden.value;
    }
    if (which === 'new' && typeof generateProductName === 'function') generateProductName();
}

function syncStorageTempOther(which) {
    const sel = document.getElementById(which === 'edit' ? 'edit_storage_temp' : 'new_storage_temp');
    const other = document.getElementById(which === 'edit' ? 'edit_storage_temp_other' : 'new_storage_temp_other');
    if (!sel || !other) return;
    const isOther = String(sel.value).toLowerCase() === 'other';
    other.classList.toggle('d-none', !isOther);
    if (!isOther) other.value = other.dataset.custom || other.value;
}

function setStorageTempFields(which, value) {
    const sel = document.getElementById(which === 'edit' ? 'edit_storage_temp' : 'new_storage_temp');
    const other = document.getElementById(which === 'edit' ? 'edit_storage_temp_other' : 'new_storage_temp_other');
    if (!sel) return;
    const val = String(value || '');
    const hasPreset = [...sel.options].some(o => o.value === val);
    if (hasPreset && val !== '') {
        sel.value = val;
        if (other) { other.value = ''; other.classList.add('d-none'); }
    } else if (val !== '') {
        sel.value = 'Other';
        if (other) { other.value = val; other.dataset.custom = val; other.classList.remove('d-none'); }
    } else {
        sel.value = '';
        if (other) { other.value = ''; other.classList.add('d-none'); }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof syncExpiryTrackingFields === 'function') syncExpiryTrackingFields();
});

function deleteCategory(id, name) {
    document.getElementById('delete_category_id').value = id;
    document.getElementById('delete_category_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteCategoryModal')).show();
}

function confirmDeleteAllProducts() {
    if (window.Swal) {
        Swal.fire({
            title: 'DELETE ALL PRODUCTS?',
            text: 'WARNING: This action is permanent, cannot be undone, and will completely empty your inventory catalog. Type "DELETE ALL" to confirm:',
            icon: 'warning',
            input: 'text',
            inputPlaceholder: 'Type DELETE ALL here...',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Delete All Products',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            inputValidator: (value) => {
                if (value !== 'DELETE ALL') {
                    return 'You must type "DELETE ALL" exactly to confirm!';
                }
            }
        }).then(result => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_all_products';
                input.value = '1';
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        });
    } else {
        appConfirm("WARNING: You are about to DELETE ALL PRODUCTS from the inventory! This action is permanent, cannot be undone, and will empty your catalog. Are you sure you want to proceed?").then(ok => {
            if (!ok) return;
            appPrompt("Please type 'DELETE ALL' to confirm this high-risk database deletion:").then(check => {
            if (check === 'DELETE ALL') {
                const form = document.createElement('form');
                form.method = 'POST';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_all_products';
                input.value = '1';
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            } else if (check !== null) {
                alert("Verification failed. Deletion aborted.");
            }
            });
        });
    }
}

let _inventoryOverviewRows = [];
let _inventoryOverviewPage = 1;
const INVENTORY_OVERVIEW_PAGE_SIZE = 10;

let _inventoryOverviewTimer = null;

function loadInventoryOverview(fromFilter) {
    const tbody = document.getElementById('inventoryOverviewBody');
    if (!tbody || typeof api !== 'function') return;
    if (!fromFilter) {
        tbody.innerHTML = '<tr class="loading-row"><td colspan="10" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading inventory...</td></tr>';
    }
    const params = { action: 'get_inventory_overview' };
    const search = document.getElementById('inventoryOverviewSearch')?.value || '';
    const category = document.getElementById('inventoryOverviewCategory')?.value || '';
    const status = document.getElementById('inventoryOverviewStatus')?.value || '';
    if (search) params.search = search;
    if (category) params.category = category;
    if (status) params.status = status;
    api({ query: params }, function (r) {
        if (!r || !r.success) {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">${escHtml(r?.message || 'Unable to load inventory.')}</td></tr>`;
            return;
        }
        _inventoryOverviewRows = r.data || [];
        if (!fromFilter) {
            renderInventoryOverviewSummary(r.summary || {});
            fillInventoryOverviewCategories(r.categories || []);
        }
        applyInventoryOverviewFilters();
    });
}

function renderInventoryOverviewSummary(s) {
    const set = (id, n) => {
        const el = document.getElementById(id);
        if (el) el.textContent = Number(n || 0).toLocaleString();
    };
    set('invStatProducts', s.total_products);
    set('invStatStock', s.total_stock);
    set('invStatSold', s.total_sold);
    set('invStatLow', s.low_stock);
    set('invStatOut', s.out_of_stock);
}

function fillInventoryOverviewCategories(cats) {
    const sel = document.getElementById('inventoryOverviewCategory');
    if (!sel) return;
    const current = sel.value;
    sel.innerHTML = '<option value="">All Categories</option>' + (cats || []).map(function (c) {
        return `<option value="${escHtml(c)}">${escHtml(c)}</option>`;
    }).join('');
    if (current && Array.from(sel.options).some(o => o.value === current)) sel.value = current;
}

function filterInventoryOverviewStatus(status) {
    const sel = document.getElementById('inventoryOverviewStatus');
    if (sel) sel.value = sel.value === status ? '' : status;
    inventoryOverviewFilterChanged();
}

function inventoryOverviewFilterChanged() {
    _inventoryOverviewPage = 1;
    clearTimeout(_inventoryOverviewTimer);
    _inventoryOverviewTimer = setTimeout(function () { loadInventoryOverview(true); }, 250);
}

function applyInventoryOverviewFilters() {
    const tbody = document.getElementById('inventoryOverviewBody');
    if (!tbody) return;
    const sort = document.getElementById('inventoryOverviewSort')?.value || 'name';
    let rows = (_inventoryOverviewRows || []).slice();
    rows.sort(function (a, b) {
        if (sort === 'stock-desc') return (b.current_stock || 0) - (a.current_stock || 0);
        if (sort === 'stock-asc') return (a.current_stock || 0) - (b.current_stock || 0);
        if (sort === 'sold-desc') return (b.total_sold || 0) - (a.total_sold || 0);
        if (sort === 'sold-asc') return (a.total_sold || 0) - (b.total_sold || 0);
        return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
    });
    const pages = Math.max(1, Math.ceil(rows.length / INVENTORY_OVERVIEW_PAGE_SIZE));
    if (_inventoryOverviewPage > pages || _inventoryOverviewPage < 1) _inventoryOverviewPage = 1;
    const start = (_inventoryOverviewPage - 1) * INVENTORY_OVERVIEW_PAGE_SIZE;
    const pageRows = rows.slice(start, start + INVENTORY_OVERVIEW_PAGE_SIZE);
    renderInventoryOverviewTable(pageRows, rows.length);
    renderInventoryOverviewPager(rows.length, pages);
}

function inventoryOverviewStatusBadge(status) {
    if (status === 'Out of Stock') return '<span class="badge badge-red">Out of Stock</span>';
    if (status === 'Low Stock') return '<span class="badge badge-orange">Low Stock</span>';
    return '<span class="badge badge-green">In Stock</span>';
}

function renderInventoryOverviewTable(rows, total) {
    const tbody = document.getElementById('inventoryOverviewBody');
    if (!tbody) return;
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-muted">${total === 0 && !_inventoryOverviewRows.length ? 'No products in inventory.' : 'No matching products.'}</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map(function (p) {
        const cur = Number(p.current_stock || 0);
        const avail = Number(p.available_stock || 0);
        const curBadge = p.stock_status === 'Out of Stock' ? 'badge-red' : (p.stock_status === 'Low Stock' ? 'badge-orange' : 'badge-green');
        return `<tr>
            <td><span class="badge badge-blue">${escHtml(p.sku)}</span></td>
            <td><strong>${escHtml(p.name)}</strong></td>
            <td>${escHtml(p.category || '—')}</td>
            <td><span class="badge ${curBadge}">${cur.toLocaleString()}</span></td>
            <td>${Number(p.total_sold || 0).toLocaleString()}</td>
            <td>${avail.toLocaleString()}</td>
            <td>${Number(p.stock_in || 0).toLocaleString()}</td>
            <td>${Number(p.stock_out || 0).toLocaleString()}</td>
            <td>${inventoryOverviewStatusBadge(p.stock_status)}</td>
            <td><button type="button" class="btn-icon primary" title="View Product Details" onclick="viewInventoryOverviewProduct(${Number(p.id)})"><i class="bi bi-eye"></i></button></td>
        </tr>`;
    }).join('');
}

function renderInventoryOverviewPager(total, pages) {
    const el = document.getElementById('inventoryOverviewPager');
    if (!el) return;
    const page = _inventoryOverviewPage;
    const from = total === 0 ? 0 : ((page - 1) * INVENTORY_OVERVIEW_PAGE_SIZE) + 1;
    const to = Math.min(total, page * INVENTORY_OVERVIEW_PAGE_SIZE);
    el.innerHTML = `
      <span class="text-muted small">Showing ${from}–${to} of ${total}</span>
      <div class="d-flex gap-2 align-items-center">
        <label class="text-muted small mb-0 d-flex align-items-center gap-1">Go to page:
          <input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm" style="width:64px" aria-label="Go to page" onkeydown="submitInventoryGoToPage(event, ${pages})">
        </label>
        <button type="button" class="btn btn-outline-secondary btn-sm" ${page <= 1 ? 'disabled' : ''} onclick="goInventoryOverviewPage(${page - 1})">Previous</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" ${page >= pages ? 'disabled' : ''} onclick="goInventoryOverviewPage(${page + 1})">Next</button>
      </div>`;
}

function goInventoryOverviewPage(page) {
    _inventoryOverviewPage = Math.max(1, page);
    applyInventoryOverviewFilters();
}

function submitInventoryGoToPage(event, pages) {
    if (!event || event.key !== 'Enter') return;
    event.preventDefault();
    const raw = String(event.target && event.target.value || '').trim();
    if (!/^[1-9]\d*$/.test(raw)) return;
    const n = parseInt(raw, 10);
    const last = Math.max(1, parseInt(pages, 10) || 1);
    if (n < 1 || n > last) return;
    _inventoryOverviewPage = n;
    applyInventoryOverviewFilters();
}

function inventoryLotLabel(lot) {
    const mfr = String((lot && lot.manufacturer_lot) || '').trim();
    return mfr || '—';
}

function inventoryLotExpiry(value) {
    const raw = String(value || '').trim();
    if (!raw || raw === '0000-00-00') return 'No Expiry';
    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return 'No Expiry';
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const mon = months[(parseInt(m[2], 10) || 1) - 1] || m[2];
    return mon + ' ' + (parseInt(m[3], 10) || m[3]) + ', ' + m[1];
}

function inventoryOverviewLotsHtml(lots) {
    const rows = Array.isArray(lots) ? lots : [];
    const body = rows.length
        ? rows.map(function (lot) {
            return `<tr>
                <td>${escHtml(inventoryLotLabel(lot))}</td>
                <td>${Number(lot.quantity || 0).toLocaleString()}</td>
                <td>${escHtml(inventoryLotExpiry(lot.expiry_date))}</td>
            </tr>`;
        }).join('')
        : '<tr><td colspan="3" class="text-muted">No lots recorded for this product.</td></tr>';
    return `<div class="mt-3">
      <div class="text-muted mb-1" style="font-size:11px;text-transform:uppercase">Stock Lots</div>
      <div class="table-container">
        <table class="modern-table">
          <thead><tr><th>Lot Number</th><th>Quantity</th><th>Expiry Date</th></tr></thead>
          <tbody>${body}</tbody>
        </table>
      </div>
    </div>`;
}

function viewInventoryOverviewProduct(id) {
    const p = (_inventoryOverviewRows || []).find(r => Number(r.id) === Number(id));
    if (!p) return;
    const body = document.getElementById('viewProductModalBody');
    const modalEl = document.getElementById('viewProductModal');
    if (!body || !modalEl) return;
    const row = (label, value) => `<div class="col-md-6 mb-2"><div class="text-muted" style="font-size:11px;text-transform:uppercase">${label}</div><div>${value}</div></div>`;
    body.innerHTML = `<div class="row">
      ${row('SKU', `<span class="badge badge-blue">${escHtml(p.sku)}</span>`)}
      ${row('Status', inventoryOverviewStatusBadge(p.stock_status))}
      ${row('Product Name', escHtml(p.name))}
      ${row('Category', escHtml(p.category || '—'))}
      ${row('Current Stock', String(Number(p.current_stock || 0).toLocaleString()))}
      ${row('Available Stock', String(Number(p.available_stock || 0).toLocaleString()))}
      ${row('Total Sold', String(Number(p.total_sold || 0).toLocaleString()))}
      ${row('Stock-In', String(Number(p.stock_in || 0).toLocaleString()))}
      ${row('Stock-Out', String(Number(p.stock_out || 0).toLocaleString()))}
      ${row('Reorder Level', String(Number(p.reorder_level || 0).toLocaleString()))}
      ${row('Unit', escHtml(p.uom || '—'))}
      ${row('Brand', escHtml(p.brand || '—'))}
    </div>
    ${inventoryOverviewLotsHtml(p.lots)}`;
    if (window.bootstrap && bootstrap.Modal) new bootstrap.Modal(modalEl).show();
    else modalEl.classList.add('show');
}

