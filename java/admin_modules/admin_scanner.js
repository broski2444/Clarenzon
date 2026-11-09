/* Barcode scanner and stock movement admin dashboard actions */
window.adminScanner = window.adminScanner || null;
window.adminScannerActive = window.adminScannerActive || false;
window.adminLastScannedCode = window.adminLastScannedCode || '';
window.dashScannerInstance = window.dashScannerInstance || null;
window.dashScannerIsActive = window.dashScannerIsActive || false;
window.dashCurrentProduct = window.dashCurrentProduct || null;
window.dashLastScannedCode = window.dashLastScannedCode || '';
window.dashScanCooldown = window.dashScanCooldown || false;
window.dashSelectedAction = window.dashSelectedAction || 'stock_in';
window.dashScanCount = window.dashScanCount || 0;
window.dashScanBusy = window.dashScanBusy || false;
window.dashScanSessionId = window.dashScanSessionId || 0;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function exportToCSV() {
    const table = document.querySelector('#inventoryReportTable');
    if (!table) return;
    let csv = [];
    for (let row of table.querySelectorAll('tr')) {
        let rowData = [];
        for (let col of row.querySelectorAll('td,th')) rowData.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
        csv.push(rowData.join(','));
    }
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'inventory_report_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function clarBarcodeKey(code) {
    return String(code || '').trim().toUpperCase();
}

function applyAdminScannedCodeToForms(code) {
    const cleanCode = clarBarcodeKey(code);
    if (!cleanCode) return false;
    const selectEl = document.getElementById('adminStockMovementProduct');
    if (!selectEl) return false;
    const opts = Array.from(selectEl.options);
    const pending = opts.find((opt) => String(opt.value).indexOf('recv:') === 0 && clarBarcodeKey(opt.getAttribute('data-barcode')) === cleanCode);
    const byBarcode = opts.find((opt) => clarBarcodeKey(opt.getAttribute('data-barcode')) === cleanCode);
    const bySku = opts.find((opt) => clarBarcodeKey(opt.getAttribute('data-sku')) === cleanCode || clarBarcodeKey(opt.value) === cleanCode);
    const match = pending || byBarcode || bySku;
    if (!match) return false;
    selectEl.value = match.value;
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
}

function applyAdminBarcodeToStockMovement() {
    const input = document.getElementById('adminStockMovementBarcode');
    if (!input) return;
    const code = (input.value || '').trim();
    if (!code) return;
    const matched = applyAdminScannedCodeToForms(code);
    const resultEl = document.getElementById('admin-scan-result');
    if (resultEl) {
        resultEl.innerHTML = matched
            ? `<span class="badge bg-success">Applied</span> ${code}`
            : `<span class="badge bg-warning text-dark">Not found</span> ${code}`;
    }
}

function openAdminScannerFromMovement() {
    if (typeof clarSectionIsDenied === 'function' && clarSectionIsDenied('data-entry')) return;
    showSection('data-entry');
    setTimeout(() => startAdminScanner(), 50);
}

function handleAdminScan(decodedText) {
    adminLastScannedCode = (decodedText || '').trim();
    const resultEl = document.getElementById('admin-scan-result');
    const matched = applyAdminScannedCodeToForms(adminLastScannedCode);
    resultEl.innerHTML = matched
        ? `<span class="badge bg-success">Scanned: ${adminLastScannedCode}</span> Product selected in stock/delivery forms.`
        : `<span class="badge bg-warning text-dark">Scanned: ${adminLastScannedCode}</span> No exact SKU match found.`;
    const auto = document.getElementById('admin-scan-auto');
    if (auto && auto.checked) processAdminScan();
}

function processAdminScan() {
    const resultEl = document.getElementById('admin-scan-result');
    if (!adminLastScannedCode) {
        resultEl.innerHTML = '<span class="badge bg-danger">No scan</span> Scan a code first.';
        return;
    }

    const payload = new URLSearchParams();
    payload.append('barcode', adminLastScannedCode);
    payload.append('movement_type', document.getElementById('admin-scan-movement').value);
    payload.append('quantity', document.getElementById('admin-scan-qty').value || '1');
    payload.append('reference', document.getElementById('admin-scan-ref').value || '');
    payload.append('notes', 'Processed via Admin scanner');

    fetch('process_scan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
    })
        .then(r => r.json().catch(() => ({ success: false, message: 'Invalid server response' })))
        .then(data => {
            if (data.success) {
                const sku = data.data && data.data.sku ? data.data.sku : adminLastScannedCode;
                const qty = data.data && data.data.new_stock !== undefined ? data.data.new_stock : '';
                resultEl.innerHTML = `<span class="badge bg-success">Updated</span> ${sku} stock is now ${qty}.`;
            } else {
                resultEl.innerHTML = `<span class="badge bg-danger">Failed</span> ${data.message || 'Unable to process scan.'}`;
            }
        })
        .catch(() => {
            resultEl.innerHTML = '<span class="badge bg-danger">Failed</span> Network or server error.';
        });
}

function startAdminScanner() {
    if (adminScannerActive) return;
    if (typeof Html5Qrcode === 'undefined' || typeof Html5QrcodeSupportedFormats === 'undefined') {
        document.getElementById('admin-scan-result').textContent = 'Scanner library not loaded. Check internet connection or CDN access.';
        return;
    }
    if (!adminScanner) adminScanner = new Html5Qrcode('admin-scanner-reader');
    adminScanner.start(
        { facingMode: 'environment' },
        {
            fps: 10,
            qrbox: { width: 260, height: 260 },
            formatsToSupport: [
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E
            ]
        },
        (decodedText) => handleAdminScan(decodedText)
    ).then(() => {
        adminScannerActive = true;
    }).catch((err) => {
        document.getElementById('admin-scan-result').textContent = 'Unable to start camera scanner: ' + err;
    });
}

function stopAdminScanner() {
    if (!adminScanner || !adminScannerActive) return;
    adminScanner.stop().then(() => {
        adminScannerActive = false;
    }).catch(() => { });
}

function dashShowCameraError(kind) {
    const box = document.getElementById('dashCameraError');
    const title = document.getElementById('dashCameraErrorTitle');
    const text = document.getElementById('dashCameraErrorText');
    const placeholder = document.getElementById('dashScannerPlaceholder');
    if (placeholder) placeholder.style.display = 'none';
    if (!box) return;
    box.style.display = 'block';
    if (kind === 'permission') {
        if (title) title.textContent = 'Camera Access Required';
        if (text) text.textContent = 'Please allow camera access in your browser settings to use the barcode scanner.';
    } else {
        if (title) title.textContent = 'Camera Unavailable';
        if (text) text.textContent = 'Please check your browser permissions or use manual barcode entry.';
    }
}

function dashHideCameraError() {
    const box = document.getElementById('dashCameraError');
    if (box) box.style.display = 'none';
}

function dashToggleCamera() {
    if (dashScannerIsActive) dashStopScanner();
    else dashStartScanner();
}

function dashNewRequestId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return 'scan-' + Date.now() + '-' + Math.random().toString(16).slice(2);
}

function dashFriendlyFetchError(err, fallback) {
    const msg = String((err && err.message) || err || '');
    if (/failed to fetch|networkerror|load failed|network/i.test(msg)) {
        return 'Connection Error. Unable to connect to the server. Please try again.';
    }
    return fallback || 'Unable to update inventory. Please try again.';
}

function dashUpdateScanSessionUi() {
    const wrap = document.getElementById('dashScanSession');
    const expectedInput = document.getElementById('dashExpectedQty');
    const expectedLabel = document.getElementById('dashExpectedLabel');
    const receivedLabel = document.getElementById('dashReceivedLabel');
    const qtyInput = document.getElementById('dashQuantityInput');
    const expected = expectedInput ? parseInt(expectedInput.value, 10) || 0 : 0;
    if (wrap) wrap.style.display = dashCurrentProduct ? 'block' : 'none';
    if (expectedLabel) expectedLabel.textContent = expected > 0 ? String(expected) : '—';
    if (receivedLabel) receivedLabel.textContent = String(dashScanCount || 0);
    if (qtyInput && dashScanCount > 0) qtyInput.value = String(dashScanCount);
}

function dashFillBatches(product) {
    const wrap = document.getElementById('dashBatchWrap');
    const select = document.getElementById('dashBatchSelect');
    const batches = Array.isArray(product && product.batches) ? product.batches : [];
    if (!wrap || !select) return;
    if (!batches.length) {
        wrap.style.display = 'none';
        select.innerHTML = '';
        return;
    }
    wrap.style.display = 'block';
    const active = batches.filter((b) => (parseInt(b.quantity_remaining, 10) || 0) > 0);
    const list = active.length ? active : batches;
    select.innerHTML = list.map((b) => {
        const exp = b.expiry_date ? (' · exp ' + escapeHtml(b.expiry_date)) : '';
        const qty = (b.quantity_remaining != null ? b.quantity_remaining : b.quantity) || 0;
        return `<option value="${escapeHtml(String(b.id))}" data-batch-number="${escapeHtml(b.batch_number || '')}" data-expiry="${escapeHtml(b.expiry_date || '')}">${escapeHtml(b.batch_number || ('Lot #' + b.id))} — ${qty}${exp}</option>`;
    }).join('');
}

function dashFocusStockQty() {
    const qty = document.getElementById('dashQuantityInput');
    if (qty) { qty.focus(); qty.select(); }
    const card = document.querySelector('.scanner-actions-card');
    if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function dashStartScanner() {
    if (dashScannerIsActive) return;
    dashHideCameraError();
    const insecureHost = typeof location !== 'undefined'
        && location.protocol === 'http:'
        && location.hostname !== 'localhost'
        && location.hostname !== '127.0.0.1';
    if (insecureHost || (typeof window.isSecureContext !== 'undefined' && !window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1')) {
        dashShowCameraError('unavailable');
        return;
    }
    if (typeof Html5Qrcode === 'undefined') {
        dashShowCameraError('unavailable');
        return;
    }
    const placeholder = document.getElementById('dashScannerPlaceholder');
    const viewfinder = document.getElementById('dashScannerViewfinder');
    const badge = document.getElementById('scannerStatusBadge');
    const startBtn = document.getElementById('dashStartScannerBtn');
    const stopBtn = document.getElementById('dashStopScannerBtn');

    if (placeholder) placeholder.style.display = 'none';
    if (viewfinder) viewfinder.classList.add('scanning');
    if (badge) { badge.textContent = 'Scanning…'; badge.className = 'badge badge-green'; }
    if (startBtn) startBtn.style.display = 'none';
    if (stopBtn) stopBtn.style.display = 'inline-block';

    if (!dashScannerInstance) dashScannerInstance = new Html5Qrcode('dashScannerViewport');

    const viewport = document.getElementById('dashScannerViewport');
    const boxW = Math.min(320, Math.max(180, Math.floor(((viewport && viewport.clientWidth) || 280) * 0.8)));
    const boxH = Math.min(220, Math.max(120, Math.floor(boxW * 0.55)));
    const scanCfg = { fps: 12, qrbox: { width: boxW, height: boxH } };
    if (typeof Html5QrcodeSupportedFormats !== 'undefined') {
        scanCfg.formatsToSupport = [
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.CODE_39,
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.EAN_8,
            Html5QrcodeSupportedFormats.UPC_A,
            Html5QrcodeSupportedFormats.UPC_E,
            Html5QrcodeSupportedFormats.QR_CODE
        ];
    }

    dashScannerInstance.start(
        { facingMode: 'environment' },
        scanCfg,
        (decodedText) => {
            if (dashScanCooldown) return;
            dashScanCooldown = true;
            setTimeout(() => { dashScanCooldown = false; }, 500);
            dashLastScannedCode = decodedText.trim();
            dashLookupBarcode(dashLastScannedCode, 'camera');
        },
        () => { }
    ).then(() => {
        dashScannerIsActive = true;
        dashHideCameraError();
    }).catch((err) => {
        const raw = String((err && (err.name || err.message)) || err || '');
        dashShowCameraError(/notallowed|permission|denied|notreadable/i.test(raw) ? 'permission' : 'unavailable');
        if (placeholder) placeholder.style.display = 'flex';
        if (viewfinder) viewfinder.classList.remove('scanning');
        if (badge) { badge.textContent = 'Camera Error'; badge.className = 'badge badge-red'; }
        if (startBtn) startBtn.style.display = 'inline-block';
        if (stopBtn) stopBtn.style.display = 'none';
        dashScannerIsActive = false;
    });
}

function dashStopScanner() {
    if (!dashScannerInstance || !dashScannerIsActive) return;
    dashScannerInstance.stop().then(() => {
        dashScannerIsActive = false;
        const placeholder = document.getElementById('dashScannerPlaceholder');
        const viewfinder = document.getElementById('dashScannerViewfinder');
        const badge = document.getElementById('scannerStatusBadge');
        const startBtn = document.getElementById('dashStartScannerBtn');
        const stopBtn = document.getElementById('dashStopScannerBtn');

        if (placeholder) placeholder.style.display = 'flex';
        if (viewfinder) viewfinder.classList.remove('scanning');
        if (badge) { badge.textContent = 'Idle'; badge.className = 'badge badge-blue'; }
        if (startBtn) startBtn.style.display = 'inline-block';
        if (stopBtn) stopBtn.style.display = 'none';
    }).catch(() => { });
}

async function dashLookupBarcode(code, deviceUsed = 'camera') {
    if (!code) return;
    const infoContainer = document.getElementById('dashProductInfo');
    if (infoContainer) {
        infoContainer.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Looking up item…</div>';
    }

    try {
        const response = await fetch(`scanner_api.php?action=product&code=${encodeURIComponent(code)}`);
        if (response.status === 401) {
            window.location.href = 'login.php';
            return;
        }
        const data = await response.json().catch(() => ({}));
        if (response.status === 403 || data.code === 'access_denied') {
            if (infoContainer) {
                infoContainer.innerHTML = '<div class="alert alert-danger my-2"><strong>Access Denied</strong><br>You do not have permission to use the mobile barcode scanner.</div>';
            }
            dashCurrentProduct = null;
            return;
        }
        if (data.success && Array.isArray(data.results) && data.results.length) {
            dashShowProductMatches(data.results, deviceUsed, code);
        } else if (data.success && data.data) {
            dashSetProductDetails(data.data, { scannedCode: code });
            dashLogScan(code, 'lookup', data.data.id, deviceUsed);
        } else {
            if (infoContainer) {
                infoContainer.innerHTML = `
                    <div class="alert alert-warning my-3" style="font-size:14px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Barcode Not Found</strong><br>No product is associated with this barcode.
                    </div>`;
            }
            dashCurrentProduct = null;
            dashScanCount = 0;
            dashUpdateScanSessionUi();
            dashLogScan(code, 'not_found', null, deviceUsed);
        }
    } catch (err) {
        if (infoContainer) {
            infoContainer.innerHTML = '<div class="alert alert-danger my-3">' + escapeHtml(dashFriendlyFetchError(err, 'Connection Error. Unable to connect to the server. Please try again.')) + '</div>';
        }
    }
    dashLoadRecentScans();
}

function dashShowProductMatches(results, deviceUsed, code) {
    const infoContainer = document.getElementById('dashProductInfo');
    if (!infoContainer) return;
    dashCurrentProduct = null;
    const items = (results || []).map((p, i) => {
        const stock = parseInt(p.quantity_in_stock, 10) || 0;
        const sellable = parseInt(p.sellable_qty != null ? p.sellable_qty : stock, 10) || 0;
        return `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-start dash-product-match" data-match-index="${i}">
            <div>
                <div class="fw-semibold">${escapeHtml(p.name || '')}</div>
                <div class="small text-muted">${escapeHtml(p.sku || '')}${p.barcode ? ' · ' + escapeHtml(p.barcode) : ''}${p.brand ? ' · ' + escapeHtml(p.brand) : ''}</div>
            </div>
            <span class="badge ${sellable > 0 ? 'bg-success' : 'bg-danger'}">${sellable} sellable</span>
        </button>`;
    }).join('');
    infoContainer.innerHTML = `
        <div class="alert alert-info py-2 mb-2" style="font-size:13px;">Several products matched. Click one to open it.</div>
        <div class="list-group">${items}</div>`;
    infoContainer.querySelectorAll('.dash-product-match').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.getAttribute('data-match-index'), 10);
            const product = results[idx];
            if (!product) return;
            dashSetProductDetails(product, { scannedCode: code });
            dashLogScan(product.barcode || product.sku || code, 'lookup', product.id, deviceUsed);
        });
    });
}

function dashManualLookup() {
    const input = document.getElementById('dashBarcodeInput');
    if (!input) return;
    const code = input.value.trim();
    if (code) {
        dashLookupBarcode(code, 'manual_entry');
        input.value = '';
    }
}

function dashSetProductDetails(product, opts) {
    opts = opts || {};
    const infoContainer = document.getElementById('dashProductInfo');
    if (!infoContainer) return;

    if (!product) {
        dashCurrentProduct = null;
        dashScanCount = 0;
        dashUpdateScanSessionUi();
        infoContainer.innerHTML = `
            <div class="text-center py-4" style="color:var(--text-tertiary);">
                <i class="bi bi-box-seam" style="font-size:40px;opacity:0.4;"></i>
                <p class="mt-2 mb-0">No item scanned yet.<br>Scan a barcode to see product details.</p>
            </div>`;
        return;
    }

    const sameProduct = dashCurrentProduct && Number(dashCurrentProduct.id) === Number(product.id);
    dashCurrentProduct = product;
    if (opts.resetCount) {
        dashScanCount = 0;
    } else if (sameProduct) {
        dashScanCount = (dashScanCount || 0) + 1;
    } else {
        dashScanCount = 1;
        const confirmEl = document.getElementById('dashStockConfirm');
        if (confirmEl) confirmEl.style.display = 'none';
    }
    dashUpdateScanSessionUi();
    dashFillBatches(product);

    const unit = product.unit || product.unit_of_measure || 'pcs';
    const unitEl = document.getElementById('dashQtyUnit');
    if (unitEl) unitEl.textContent = unit;

    const stock = parseInt(product.quantity_in_stock, 10) || 0;
    const sellable = parseInt(product.available_stock != null ? product.available_stock : (product.sellable_qty != null ? product.sellable_qty : stock), 10) || 0;
    let stockBadge = '<span class="badge bg-success">Available Stock</span>';
    if (stock <= 0) stockBadge = '<span class="badge bg-danger">Out of Stock</span>';
    else if (stock <= (parseInt(product.reorder_level, 10) || 30)) stockBadge = '<span class="badge bg-warning text-dark">Low Stock</span>';

    const img = product.image
        ? `<img src="${escapeHtml(product.image)}" alt="" class="scanner-product-thumb">`
        : `<div class="scanner-product-thumb scanner-product-thumb-empty"><i class="bi bi-box-seam"></i></div>`;
    const barcodes = Array.isArray(product.barcode_values) && product.barcode_values.length
        ? product.barcode_values.join(', ')
        : (product.barcode || '—');
    const batch = product.batch_number || (product.batches && product.batches[0] && product.batches[0].batch_number) || '—';
    const expiry = product.batch_expiry || product.expiry_date || '—';
    const extraBarcodes = Array.isArray(product.barcode_values) && product.barcode_values.length > 1
        ? `<div class="col-12"><strong>Registered barcodes:</strong> ${escapeHtml(barcodes)}</div>`
        : '';

    infoContainer.innerHTML = `
        <div class="scanner-product-card">
            <div class="scanner-product-head">
                ${img}
                <div>
                    <h5 class="fw-bold m-0" style="font-size:16px;">${escapeHtml(product.name || '')}</h5>
                    <div class="small text-muted">${escapeHtml(product.sku || '')}</div>
                </div>
            </div>
            <div class="scanner-stock-banner">
                <i class="bi bi-check-circle-fill me-1"></i>${stockBadge}
                <strong class="ms-1">${sellable} ${escapeHtml(unit)}</strong>
            </div>
            <div class="row g-2 scanner-product-meta" style="font-size:13px;">
                <div class="col-6"><strong>SKU:</strong> ${escapeHtml(product.sku || '—')}</div>
                <div class="col-6"><strong>Barcode:</strong> ${escapeHtml(opts.scannedCode || product.scanned_barcode || product.barcode || '—')}</div>
                <div class="col-6"><strong>Brand:</strong> ${escapeHtml(product.brand || '—')}</div>
                <div class="col-6"><strong>Category:</strong> ${escapeHtml(product.category_name || '—')}</div>
                <div class="col-6"><strong>Sub-Category:</strong> ${escapeHtml(product.subcategory_name || '—')}</div>
                <div class="col-6"><strong>Supplier:</strong> ${escapeHtml(product.supplier_name || '—')}</div>
                <div class="col-6"><strong>Current Quantity:</strong> ${stock} ${escapeHtml(unit)}</div>
                <div class="col-6"><strong>Unit:</strong> ${escapeHtml(unit)}</div>
                <div class="col-6"><strong>Location:</strong> ${escapeHtml(product.location || 'Not assigned')}</div>
                <div class="col-6"><strong>Batch / Lot:</strong> ${escapeHtml(batch)}</div>
                <div class="col-6"><strong>Expiry Date:</strong> ${escapeHtml(expiry)}</div>
                <div class="col-6"><strong>Product Status:</strong> ${escapeHtml(product.status || '—')}</div>
                <div class="col-6"><strong>Selling Price:</strong> ₱${Number(product.selling_price || 0).toFixed(2)}</div>
                <div class="col-6"><strong>Cost:</strong> ₱${Number(product.cost_price || product.cost || 0).toFixed(2)}</div>
                <div class="col-12"><strong>Available Stock:</strong> ${sellable} ${escapeHtml(unit)}</div>
                ${extraBarcodes}
            </div>
            <div class="d-flex gap-2 mt-3 scanner-result-actions">
                <button type="button" class="btn btn-primary flex-fill" onclick="dashSelectAction('stock_in'); dashFocusStockQty();"><i class="bi bi-box-arrow-in-down me-1"></i>Add to Stock In</button>
                <button type="button" class="btn btn-outline-primary flex-fill" onclick="dashEditProduct();">View Details</button>
            </div>
        </div>`;
}

async function dashLogScan(code, action, productId = null, deviceUsed = 'camera') {
    try {
        const formData = new FormData();
        formData.append('barcode', code);
        formData.append('action_performed', action);
        formData.append('device_used', deviceUsed);
        if (productId) formData.append('product_id', productId);
        await fetch('scanner_api.php?action=scan', { method: 'POST', body: formData });
    } catch (_) { }
}

async function dashLoadRecentScans() {
    const listEl = document.getElementById('dashRecentScans');
    if (!listEl) return;
    try {
        const res = await fetch('scanner_api.php?action=recent-scans');
        const data = await res.json();
        listEl.innerHTML = '';
        if (data.success && data.data && data.data.length > 0) {
            data.data.forEach(item => {
                const li = document.createElement('li');
                li.className = 'py-2 border-bottom d-flex justify-content-between align-items-center';
                li.style.fontSize = '13px';
                li.innerHTML = `
                    <div>
                        <strong>${escapeHtml(item.scanned_code)}</strong>
                        <div class="text-muted" style="font-size:11px;">${escapeHtml(item.product_name || item.name || item.action_performed)}</div>
                    </div>
                    <span class="text-muted" style="font-size:11px;">${escapeHtml(item.created_at || '')}</span>`;
                listEl.appendChild(li);
            });
        } else {
            listEl.innerHTML = '<li class="text-muted py-2 text-center" style="font-size:13px;">No scan history yet.</li>';
        }
    } catch (e) {
        console.error('Failed to load recent scans:', e);
    }
}

function dashSelectAction(actionType, btn) {
    window.dashSelectedAction = actionType === 'stock_out' ? 'stock_out' : 'stock_in';
    document.querySelectorAll('.dash-action-btn').forEach(el => el.classList.remove('active'));
    const match = btn || document.querySelector('.dash-action-btn[data-dash-action="' + window.dashSelectedAction + '"]');
    if (match) match.classList.add('active');
    const applyBtn = document.getElementById('dashApplyActionBtn');
    if (applyBtn) {
        applyBtn.innerHTML = window.dashSelectedAction === 'stock_out'
            ? '<i class="bi bi-check-lg me-1"></i>Confirm Stock Out'
            : '<i class="bi bi-check-lg me-1"></i>Confirm';
    }
}

async function dashApplyAction(actionType) {
    actionType = actionType || window.dashSelectedAction || 'stock_in';
    if (dashScanBusy) return;
    if (!dashCurrentProduct) {
        alert('Please scan or look up a product first.');
        return;
    }
    const barcode = String(dashCurrentProduct.scanned_barcode || dashCurrentProduct.barcode || dashCurrentProduct.sku || '').trim();
    if (!barcode) {
        alert('This product has no barcode.');
        return;
    }

    const qtyInput = document.getElementById('dashQuantityInput');
    const quantity = Math.max(1, parseInt(qtyInput && qtyInput.value, 10) || dashScanCount || 1);
    const expectedInput = document.getElementById('dashExpectedQty');
    const expected = expectedInput ? parseInt(expectedInput.value, 10) || 0 : 0;
    if (expected > 0 && quantity > expected) {
        alert('Scanned quantity cannot exceed the expected quantity.');
        return;
    }

    const applyBtn = document.getElementById('dashApplyActionBtn');
    dashScanBusy = true;
    if (applyBtn) {
        applyBtn.disabled = true;
        applyBtn.classList.add('disabled');
    }

    const route = actionType === 'stock_out' ? 'stock-out' : 'stock-in';
    const formData = new FormData();
    formData.append('barcode', barcode);
    formData.append('quantity', String(quantity));
    formData.append('device_used', 'camera');
    formData.append('request_id', dashNewRequestId());
    formData.append('quantity_unit', dashCurrentProduct.unit || dashCurrentProduct.unit_of_measure || '');
    const batchSelect = document.getElementById('dashBatchSelect');
    if (batchSelect && batchSelect.value) {
        formData.append('batch_id', batchSelect.value);
        const opt = batchSelect.options[batchSelect.selectedIndex];
        if (opt) {
            formData.append('batch_number', opt.getAttribute('data-batch-number') || '');
            formData.append('expiry_date', opt.getAttribute('data-expiry') || '');
        }
    }

    try {
        const res = await fetch('scanner_api.php?action=' + route, { method: 'POST', body: formData });
        if (res.status === 401) {
            window.location.href = 'login.php';
            return;
        }
        const data = await res.json().catch(() => ({}));
        if (res.status === 403 || data.code === 'access_denied') {
            alert(data.message || 'You do not have permission to use the mobile barcode scanner.');
            return;
        }
        if (!data.success) {
            alert(data.message || 'Unable to update inventory. Please try again.');
            return;
        }
        const unit = (data.data && data.data.unit) || dashCurrentProduct.unit || 'pcs';
        const newStock = data.data ? data.data.new_stock : '';
        const name = (data.data && data.data.product && data.data.product.name) || dashCurrentProduct.name || '';
        const banner = document.getElementById('dashStockConfirm');
        if (banner) {
            banner.style.display = 'block';
            banner.innerHTML = `<strong>Stock Updated Successfully</strong><br>Product: ${escapeHtml(name)}<br>Quantity ${route === 'stock-in' ? 'Added' : 'Removed'}: ${quantity} ${escapeHtml(unit)}<br>New Stock: ${escapeHtml(String(newStock))} ${escapeHtml(unit)}`;
        }
        if (data.data && dashCurrentProduct) {
            dashCurrentProduct.quantity_in_stock = newStock;
            dashCurrentProduct.sellable_qty = newStock;
            dashCurrentProduct.available_stock = newStock;
            dashSetProductDetails(dashCurrentProduct, { scannedCode: barcode, resetCount: true });
            if (qtyInput) qtyInput.value = '1';
            dashScanCount = 0;
            dashUpdateScanSessionUi();
            if (banner) banner.style.display = 'block';
        }
        dashLoadRecentScans();
    } catch (err) {
        alert(dashFriendlyFetchError(err, 'Unable to update inventory. Please try again.'));
    } finally {
        dashScanBusy = false;
        if (applyBtn) {
            applyBtn.disabled = false;
            applyBtn.classList.remove('disabled');
        }
    }
}

function dashEditProduct() {
    if (!dashCurrentProduct) {
        alert('Scan a product to edit.');
        return;
    }
    document.getElementById('dashEditProductId').value = dashCurrentProduct.id;
    document.getElementById('dashEditName').value = dashCurrentProduct.name || '';
    document.getElementById('dashEditSku').value = dashCurrentProduct.sku || '';
    document.getElementById('dashEditBarcode').value = dashCurrentProduct.barcode || '';
    document.getElementById('dashEditLocation').value = dashCurrentProduct.location || '';
    document.getElementById('dashEditPrice').value = dashCurrentProduct.unit_price || 0;

    const readOnly = !!window.isWarehouseHeadRole;
    ['dashEditName', 'dashEditSku', 'dashEditBarcode', 'dashEditLocation', 'dashEditPrice'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.readOnly = readOnly;
    });

    const modalEl = document.getElementById('dashEditProductModal');
    if (modalEl) {
        const title = modalEl.querySelector('.modal-title');
        if (title) title.textContent = readOnly ? 'Product Details' : 'Edit Product';
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

async function dashSaveProduct() {
    if (window.isWarehouseHeadRole) return;
    const id = document.getElementById('dashEditProductId').value;
    const name = document.getElementById('dashEditName').value;
    const sku = document.getElementById('dashEditSku').value;
    const barcode = document.getElementById('dashEditBarcode').value;
    const location = document.getElementById('dashEditLocation').value;
    const price = document.getElementById('dashEditPrice').value;

    const formData = new FormData();
    formData.append('product_id', id);
    formData.append('name', name);
    formData.append('sku', sku);
    formData.append('barcode', barcode);
    formData.append('location', location);
    formData.append('unit_price', price);

    try {
        const res = await fetch('scanner_api.php?action=update-product', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            alert('Product updated successfully!');
            const modalEl = document.getElementById('dashEditProductModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
            dashLookupBarcode(barcode || sku, 'camera');
        } else {
            alert(data.message || 'Update failed.');
        }
    } catch (e) {
        alert('Failed to update product.');
    }
}

async function dashDeleteProduct() {
    if (!dashCurrentProduct) return;

    const executeDelete = async () => {
        const formData = new FormData();
        formData.append('product_id', dashCurrentProduct.id);

        try {
            const res = await fetch('scanner_api.php?action=delete-product', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                if (window.Swal) Swal.fire('Deleted!', 'Product has been deleted.', 'success');
                else alert('Product deleted!');
                dashSetProductDetails(null);
                dashLoadRecentScans();
            } else {
                if (window.Swal) Swal.fire('Failed', data.message || 'Delete failed.', 'error');
                else alert(data.message || 'Delete failed.');
            }
        } catch (e) {
            if (window.Swal) Swal.fire('Error', 'Failed to delete product.', 'error');
            else alert('Failed to delete product.');
        }
    };

    if (window.Swal) {
        Swal.fire({
            title: 'Delete Product?',
            text: `Are you sure you want to delete "${dashCurrentProduct.name}"?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(result => {
            if (result.isConfirmed) executeDelete();
        });
    } else {
        appConfirm(`Are you sure you want to delete "${dashCurrentProduct.name}"?`).then(ok => {
            if (ok) executeDelete();
        });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('dashBarcodeInput');
    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                dashManualLookup();
            }
        });
        input.addEventListener('paste', function () {
            setTimeout(function () {
                if (input.value && input.value.trim()) dashManualLookup();
            }, 0);
        });
    }
    const smInput = document.getElementById('adminStockMovementBarcode');
    if (smInput) {
        smInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyAdminBarcodeToStockMovement();
            }
        });
    }
    const editForm = document.getElementById('dashEditProductForm');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (typeof dashSaveProduct === 'function') dashSaveProduct();
        });
    }
    const expected = document.getElementById('dashExpectedQty');
    if (expected) {
        expected.addEventListener('input', dashUpdateScanSessionUi);
    }
    const hint = document.getElementById('dashReceivingHint');
    if (hint) {
        const denied = typeof clarSectionIsDenied === 'function' && clarSectionIsDenied('incoming-deliveries');
        hint.style.display = denied ? 'none' : 'block';
    }
    initAdminStockMovementAutofill();
});

function stockMovementApiUrl(path) {
    if (typeof clarAuthUrl === 'function') return clarAuthUrl(path);
    if (window.location.pathname.indexOf('/warehouse_head/') !== -1) return '../' + path;
    return path;
}

function clearAdminStockMovementAutofill() {
    const details = document.getElementById('adminStockMovementDetails');
    const detailsText = document.getElementById('adminStockMovementDetailsText');
    const available = document.getElementById('adminStockMovementAvailable');
    const qty = document.getElementById('adminStockMovementQty');
    const reference = document.getElementById('adminStockMovementReference');
    const refWrap = document.getElementById('adminStockMovementRefSelectWrap');
    const refSelect = document.getElementById('adminStockMovementRefSelect');
    const batchWrap = document.getElementById('adminStockMovementBatchWrap');
    const batchSelect = document.getElementById('adminStockMovementBatch');
    const receiptItemId = document.getElementById('adminStockMovementReceiptItemId');
    const purchasePrice = document.getElementById('adminStockMovementPurchasePrice');
    const expiry = document.getElementById('adminStockMovementExpiry');

    if (details) details.style.display = 'none';
    if (detailsText) detailsText.textContent = '';
    if (available) available.value = '';
    if (qty) {
        qty.value = '';
        qty.readOnly = false;
        qty.removeAttribute('max');
    }
    if (reference) reference.value = '';
    if (refWrap) refWrap.style.display = 'none';
    if (refSelect) {
        refSelect.innerHTML = '<option value="">Select a related reference</option>';
        refSelect.onchange = null;
    }
    if (batchWrap) batchWrap.style.display = 'none';
    if (batchSelect) {
        batchSelect.innerHTML = '<option value="">Use FEFO / no specific batch</option>';
    }
    if (receiptItemId) receiptItemId.value = '';
    if (purchasePrice) purchasePrice.value = '';
    if (expiry) {
        expiry.value = '';
        expiry.removeAttribute('min');
        expiry.removeAttribute('required');
    }
    const typeEl = document.getElementById('adminStockMovementType');
    setStockInPricingVisible(!!(typeEl && typeEl.value === 'Stock In'));
}

function isReceivedStockInValue(value) {
    return String(value || '').indexOf('recv:') === 0;
}

function stockInSellingPrice(cost, markup) {
    const c = parseFloat(cost || 0) || 0;
    const m = parseFloat(markup || 0) || 0;
    return (c + (c * (m / 100))).toFixed(2);
}

function setStockInPricingVisible(show) {
    const wrap = document.getElementById('adminStockInPricingWrap');
    const markup = document.getElementById('adminStockMovementMarkup');
    if (wrap) wrap.style.display = show ? '' : 'none';
    if (markup) {
        if (show) markup.setAttribute('required', 'required');
        else markup.removeAttribute('required');
    }
}

function recalcAdminStockInSellingPrice() {
    const purchase = document.getElementById('adminStockMovementPurchasePrice');
    const markup = document.getElementById('adminStockMovementMarkup');
    const selling = document.getElementById('adminStockMovementSelling');
    if (!selling) return;
    const cost = purchase ? parseFloat(purchase.value || '0') || 0 : 0;
    let m = markup ? parseFloat(markup.value || '0') : 0;
    if (Number.isNaN(m) || m < 0) {
        m = 0;
        if (markup) markup.value = '0';
    }
    selling.value = stockInSellingPrice(cost, m);
}

function applyReceivedItemToStockInForm(option) {
    if (!option) return;
    const receiptItemId = option.getAttribute('data-receipt-item-id') || String(option.value || '').replace(/^recv:/, '');
    const sku = option.getAttribute('data-sku') || '';
    const name = option.getAttribute('data-name') || option.textContent || '';
    const goodQty = parseInt(option.getAttribute('data-good-qty') || '0', 10) || 0;
    const purchase = option.getAttribute('data-purchase-price') || '0';
    const poNumber = option.getAttribute('data-po-number') || '';
    const referenceNo = option.getAttribute('data-reference') || poNumber;
    const supplier = option.getAttribute('data-supplier') || '';
    const dateReceived = option.getAttribute('data-date-received') || '';
    const batchNumber = option.getAttribute('data-batch-number') || '';
    const detailsExtra = option.getAttribute('data-product-details') || '';
    const markupVal = option.getAttribute('data-markup') || '';
    const expiryVal = option.getAttribute('data-expiry') || '';
    const expirable = option.getAttribute('data-expirable') === '1';

    const receiptEl = document.getElementById('adminStockMovementReceiptItemId');
    const purchaseEl = document.getElementById('adminStockMovementPurchasePrice');
    const typeEl = document.getElementById('adminStockMovementType');
    const qty = document.getElementById('adminStockMovementQty');
    const reference = document.getElementById('adminStockMovementReference');
    const available = document.getElementById('adminStockMovementAvailable');
    const details = document.getElementById('adminStockMovementDetails');
    const detailsText = document.getElementById('adminStockMovementDetailsText');
    const markup = document.getElementById('adminStockMovementMarkup');
    const expiry = document.getElementById('adminStockMovementExpiry');
    const batchWrap = document.getElementById('adminStockMovementBatchWrap');
    const batchSelect = document.getElementById('adminStockMovementBatch');
    const refWrap = document.getElementById('adminStockMovementRefSelectWrap');

    if (receiptEl) receiptEl.value = String(receiptItemId || '');
    if (purchaseEl) purchaseEl.value = String(purchase);
    if (typeEl) typeEl.value = 'Stock In';
    if (qty) {
        qty.value = goodQty > 0 ? String(goodQty) : '';
        qty.readOnly = true;
        qty.min = String(goodQty);
        qty.max = String(goodQty);
    }
    if (reference) reference.value = referenceNo;
    if (available) available.value = goodQty + ' good (replacement excluded)';
    if (details && detailsText) {
        const parts = [
            name ? ('Product: ' + name) : '',
            sku ? ('SKU: ' + sku) : '',
            detailsExtra,
            supplier ? ('Supplier: ' + supplier) : '',
            poNumber ? ('PO: ' + poNumber) : '',
            dateReceived ? ('Date Received: ' + dateReceived) : '',
            purchase ? ('Purchase Price: ' + Number(purchase).toFixed(2)) : '',
            batchNumber && batchNumber !== '—' ? ('Batch: ' + batchNumber) : '',
            'Movement Type: Stock In',
        ].filter(Boolean);
        detailsText.textContent = parts.join('  ·  ');
        details.style.display = '';
    }
    setStockInPricingVisible(true);
    if (markup) markup.value = markupVal !== '' ? markupVal : '0';
    recalcAdminStockInSellingPrice();
    if (expiry) {
        if (dateReceived) expiry.min = dateReceived;
        else expiry.removeAttribute('min');
        if (expiryVal) expiry.value = expiryVal;
        if (expirable) expiry.setAttribute('required', 'required');
        else expiry.removeAttribute('required');
    }
    if (refWrap) refWrap.style.display = 'none';
    if (batchWrap && batchSelect && batchNumber && batchNumber !== '—') {
        batchSelect.innerHTML = '<option value="">' + batchNumber + '</option>';
        batchWrap.style.display = '';
        batchSelect.disabled = true;
    } else if (batchWrap) {
        batchWrap.style.display = 'none';
        if (batchSelect) batchSelect.disabled = false;
    }
    filterStockMovementProductOptions();
}

function syncApprovedStockInProductOptions(rows) {
    const select = document.getElementById('adminStockMovementProduct');
    if (!select) return;
    let group = document.getElementById('adminStockInPendingGroup');
    if (!group) {
        group = document.createElement('optgroup');
        group.id = 'adminStockInPendingGroup';
        group.label = 'Approved to Stock In';
        const first = select.querySelector('option');
        if (first && first.parentNode === select) {
            select.insertBefore(group, first.nextSibling);
        } else {
            select.insertBefore(group, select.firstChild);
        }
    }
    const current = select.value;
    group.innerHTML = '';
    const items = Array.isArray(rows) ? rows : [];
    if (!items.length) {
        const emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.disabled = true;
        emptyOpt.textContent = 'No approved received items waiting for Stock In';
        group.appendChild(emptyOpt);
    } else {
        items.forEach((it) => {
            const id = parseInt(it.receipt_item_id, 10) || 0;
            if (id <= 0) return;
            const opt = document.createElement('option');
            opt.value = 'recv:' + id;
            opt.textContent = (it.product_name || 'Product') + ' (' + (it.sku || '') + ') — ' + (it.po_number || '') + ' — ' + (parseInt(it.quantity_good, 10) || 0) + ' good';
            opt.setAttribute('data-receipt-item-id', String(id));
            opt.setAttribute('data-sku', it.sku || '');
            opt.setAttribute('data-barcode', it.barcode || '');
            opt.setAttribute('data-name', it.product_name || '');
            opt.setAttribute('data-good-qty', String(parseInt(it.quantity_good, 10) || 0));
            opt.setAttribute('data-purchase-price', String(it.purchase_price || 0));
            opt.setAttribute('data-po-number', it.po_number || '');
            opt.setAttribute('data-reference', it.reference_number || it.po_number || '');
            opt.setAttribute('data-supplier', it.supplier || '');
            opt.setAttribute('data-date-received', it.date_received || '');
            opt.setAttribute('data-batch-number', it.batch_number || '');
            opt.setAttribute('data-markup', String(it.markup_percent || 0));
            opt.setAttribute('data-selling', String(it.selling_price || 0));
            opt.setAttribute('data-expiry', it.expiry_date || '');
            opt.setAttribute('data-expirable', it.expirable ? '1' : '0');
            opt.setAttribute('data-product-details', it.product_details || '');
            group.appendChild(opt);
        });
    }
    if (current && !Array.from(select.options).some((o) => o.value === current)) {
        select.value = '';
        clearAdminStockMovementAutofill();
    } else if (current) {
        select.value = current;
    }
    filterStockMovementProductOptions();
}

function filterStockMovementProductOptions() {
    const typeEl = document.getElementById('adminStockMovementType');
    const type = typeEl ? typeEl.value : '';
    const pendingGroup = document.getElementById('adminStockInPendingGroup');
    const catalogGroup = document.getElementById('adminStockInCatalogGroup');
    if (pendingGroup) pendingGroup.hidden = (type !== '' && type !== 'Stock In');
    if (catalogGroup) catalogGroup.hidden = false;
    setStockInPricingVisible(type === 'Stock In');
}

function applyStockMovementLookupData(data) {
    if (!data) return;
    const product = data.product || {};
    const details = document.getElementById('adminStockMovementDetails');
    const detailsText = document.getElementById('adminStockMovementDetailsText');
    const available = document.getElementById('adminStockMovementAvailable');
    const qty = document.getElementById('adminStockMovementQty');
    const reference = document.getElementById('adminStockMovementReference');
    const typeEl = document.getElementById('adminStockMovementType');
    const refWrap = document.getElementById('adminStockMovementRefSelectWrap');
    const refSelect = document.getElementById('adminStockMovementRefSelect');
    const batchWrap = document.getElementById('adminStockMovementBatchWrap');
    const batchSelect = document.getElementById('adminStockMovementBatch');

    const detailParts = [
        product.brand ? ('Brand: ' + product.brand) : '',
        product.category ? ('Category: ' + product.category) : '',
        product.unit ? ('Unit: ' + product.unit) : '',
        product.packaging ? ('Packaging: ' + product.packaging) : '',
        product.size ? ('Size: ' + product.size) : '',
        product.supplier ? ('Supplier: ' + product.supplier) : '',
        product.sku ? ('SKU: ' + product.sku) : '',
    ].filter(Boolean);

    if (details && detailsText) {
        detailsText.textContent = detailParts.join('  ·  ') || 'No additional product details available.';
        details.style.display = '';
    }
    if (available) {
        available.value = data.available_display || String(data.available_quantity ?? '');
    }

    const movementType = (typeEl && typeEl.value) ? typeEl.value : '';
    const isOutbound = ['Stock Out', 'Damage', 'Expiry', 'Transfer'].includes(movementType);
    const isInbound = ['Stock In', 'Return'].includes(movementType);
    if (qty) {
        if (data.suggested_quantity && Number(data.suggested_quantity) > 0) {
            qty.value = data.suggested_quantity;
        }
        if (isOutbound && data.available_quantity != null) {
            qty.max = data.available_quantity;
        } else {
            qty.removeAttribute('max');
        }
        if (isInbound) {
            qty.removeAttribute('max');
        }
    }

    const refs = Array.isArray(data.references) ? data.references : [];
    if (refSelect && refWrap) {
        refSelect.innerHTML = '<option value="">Select a related reference</option>';
        refs.forEach((ref) => {
            const opt = document.createElement('option');
            opt.value = ref.value;
            opt.textContent = ref.label + (ref.extra ? (' — ' + ref.extra) : '');
            opt.dataset.quantity = ref.quantity != null ? String(ref.quantity) : '';
            refSelect.appendChild(opt);
        });
        refWrap.style.display = refs.length > 1 ? '' : 'none';
        refSelect.onchange = function () {
            if (!refSelect.value || !reference) return;
            reference.value = refSelect.value;
            const selected = refSelect.options[refSelect.selectedIndex];
            const linkedQty = selected && selected.dataset.quantity ? parseInt(selected.dataset.quantity, 10) : 0;
            if (qty && linkedQty > 0 && (!qty.value || isInbound)) {
                qty.value = linkedQty;
            }
        };
    }

    if (reference) {
        if (data.suggested_reference) {
            reference.value = data.suggested_reference;
            if (refSelect && refs.length) {
                refSelect.value = data.suggested_reference;
            }
        } else if (!reference.value) {
            reference.value = '';
        }
    }

    const batches = Array.isArray(data.batches) ? data.batches : [];
    if (batchSelect && batchWrap) {
        batchSelect.innerHTML = '<option value="">Use FEFO / no specific batch</option>';
        batches.forEach((batch) => {
            const opt = document.createElement('option');
            opt.value = String(batch.id);
            opt.textContent = batch.label || batch.batch_number;
            batchSelect.appendChild(opt);
        });
        batchWrap.style.display = batches.length ? '' : 'none';
        batchSelect.onchange = function () {
            if (!batchSelect.value || !qty) return;
            const selected = batches.find((b) => String(b.id) === batchSelect.value);
            if (selected && selected.quantity_remaining > 0 && isOutbound) {
                qty.value = selected.quantity_remaining;
                qty.max = selected.quantity_remaining;
            }
        };
    }
}

function loadAdminStockMovementProduct(sku) {
    if (!sku) {
        clearAdminStockMovementAutofill();
        return;
    }
    const productSelect = document.getElementById('adminStockMovementProduct');
    const selected = productSelect ? productSelect.options[productSelect.selectedIndex] : null;
    if (isReceivedStockInValue(sku) || (selected && selected.getAttribute('data-receipt-item-id'))) {
        applyReceivedItemToStockInForm(selected);
        return;
    }

    const receiptItemId = document.getElementById('adminStockMovementReceiptItemId');
    if (receiptItemId) receiptItemId.value = '';
    const batchSelect = document.getElementById('adminStockMovementBatch');
    if (batchSelect) batchSelect.disabled = false;
    const typeEl = document.getElementById('adminStockMovementType');
    setStockInPricingVisible(!!(typeEl && typeEl.value === 'Stock In'));

    const available = document.getElementById('adminStockMovementAvailable');
    if (available) available.value = 'Loading...';

    if (selected) {
        const fallbackQty = selected.getAttribute('data-qty');
        const fallbackUom = selected.getAttribute('data-uom') || 'unit';
        const fallbackBrand = selected.getAttribute('data-brand') || '';
        const fallbackCategory = selected.getAttribute('data-category') || '';
        const fallbackPackaging = selected.getAttribute('data-packaging') || '';
        const details = document.getElementById('adminStockMovementDetails');
        const detailsText = document.getElementById('adminStockMovementDetailsText');
        if (details && detailsText) {
            const parts = [
                fallbackBrand ? ('Brand: ' + fallbackBrand) : '',
                fallbackCategory ? ('Category: ' + fallbackCategory) : '',
                fallbackUom ? ('Unit: ' + fallbackUom) : '',
                fallbackPackaging ? ('Packaging: ' + fallbackPackaging) : '',
                'SKU: ' + sku,
            ].filter(Boolean);
            detailsText.textContent = parts.join('  ·  ');
            details.style.display = '';
        }
        if (available && fallbackQty != null) {
            available.value = Number(fallbackQty).toLocaleString() + ' ' + fallbackUom;
        }
    }

    fetch(stockMovementApiUrl('inventory_api.php?action=get_stock_movement_lookup&sku=' + encodeURIComponent(sku)))
        .then((r) => r.json().catch(() => ({ success: false, message: 'Invalid server response' })))
        .then((payload) => {
            if (!payload || !payload.success || !payload.data) {
                if (available && !available.value) {
                    available.value = 'Unable to load live stock';
                }
                return;
            }
            applyStockMovementLookupData(payload.data);
        })
        .catch(() => {
            if (available && available.value === 'Loading...') {
                available.value = 'Unable to load live stock';
            }
        });
}

function initAdminStockMovementAutofill() {
    const productSelect = document.getElementById('adminStockMovementProduct');
    if (!productSelect || productSelect.dataset.autofillBound === '1') return;
    productSelect.dataset.autofillBound = '1';

    productSelect.addEventListener('change', function () {
        loadAdminStockMovementProduct(productSelect.value);
    });

    const typeEl = document.getElementById('adminStockMovementType');
    if (typeEl) {
        typeEl.addEventListener('change', function () {
            filterStockMovementProductOptions();
            if (isReceivedStockInValue(productSelect.value) && typeEl.value && typeEl.value !== 'Stock In') {
                productSelect.value = '';
                clearAdminStockMovementAutofill();
                setStockInPricingVisible(typeEl.value === 'Stock In');
                return;
            }
            if (productSelect.value) loadAdminStockMovementProduct(productSelect.value);
        });
    }

    const markup = document.getElementById('adminStockMovementMarkup');
    if (markup) {
        markup.addEventListener('input', recalcAdminStockInSellingPrice);
        markup.addEventListener('change', recalcAdminStockInSellingPrice);
    }

    const form = document.getElementById('adminStockMovementForm');
    if (form && form.dataset.stockInBound !== '1') {
        form.dataset.stockInBound = '1';
        form.addEventListener('submit', function (e) {
            const typeEl = document.getElementById('adminStockMovementType');
            if (!typeEl || typeEl.value !== 'Stock In') return;
            const markup = document.getElementById('adminStockMovementMarkup');
            const m = markup ? parseFloat(markup.value) : NaN;
            if (!markup || markup.value === '' || Number.isNaN(m) || m < 0) {
                e.preventDefault();
                alert('Markup (%) must be 0 or greater.');
                return;
            }
            const expiry = document.getElementById('adminStockMovementExpiry');
            const selected = productSelect.options[productSelect.selectedIndex];
            const dateReceived = selected ? (selected.getAttribute('data-date-received') || '') : '';
            if (expiry && expiry.value && dateReceived && expiry.value < dateReceived) {
                e.preventDefault();
                alert('Expiry Date cannot be earlier than Date Received.');
            }
        });
    }

    filterStockMovementProductOptions();
}
