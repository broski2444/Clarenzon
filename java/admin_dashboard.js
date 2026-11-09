let adminScanner = null;
let adminScannerActive = false;
let adminLastScannedCode = '';

function routeToSection(section, clickedEl) {
    const targetSection = String(section || '').trim();
    if (!targetSection) return;
    if (typeof showSection === 'function') {
        showSection(targetSection, clickedEl || null);
    }
    if (window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        url.hash = targetSection;
        window.history.replaceState({}, '', url);
    }
}

function applySectionFromHash() {
    const hash = (window.location.hash || '').replace(/^#/, '').trim();
    if (!hash) {
        if (document.getElementById('section-dashboard') && typeof showSection === 'function') {
            setTimeout(() => showSection('dashboard'), 80);
        }
        return;
    }
    if (typeof clarSectionIsDenied === 'function' && clarSectionIsDenied(hash)) {
        setTimeout(() => showSection('dashboard'), 80);
        return;
    }
    setTimeout(() => routeToSection(hash), 120);
}

function switchPRInvoiceTab(tab) {
    const valid = ['requests', 'approval', 'invoices'];
    if (!valid.includes(tab)) tab = 'requests';

    valid.forEach(t => {
        const btn = document.getElementById('pr-tab-btn-' + t);
        const pane = document.getElementById('pr-tab-pane-' + t);
        if (btn) btn.classList.remove('active');
        if (pane) pane.classList.remove('show', 'active');
    });

    const activeBtn = document.getElementById('pr-tab-btn-' + tab);
    const activePane = document.getElementById('pr-tab-pane-' + tab);
    if (activeBtn) activeBtn.classList.add('active');
    if (activePane) activePane.classList.add('show', 'active');

    if (tab === 'requests' && typeof loadPurchaseRequests === 'function') setTimeout(loadPurchaseRequests, 50);
    if (tab === 'approval' && typeof loadPRApprovalQueue === 'function') setTimeout(loadPRApprovalQueue, 50);
    if (tab === 'invoices' && typeof loadSupplierInvoices === 'function') setTimeout(loadSupplierInvoices, 50);
}

function showSection(section, clickedEl) {
    if (typeof clarSectionIsDenied === 'function' && clarSectionIsDenied(section)) {
        section = 'dashboard';
        clickedEl = null;
    }
    if (section !== 'data-entry' && typeof stopAdminScanner === 'function') {
        try { stopAdminScanner(); } catch (err) { console.warn('Unable to stop admin scanner:', err); }
    }
    if (section !== 'barcode-scanner' && typeof dashStopScanner === 'function') {
        try { dashStopScanner(); } catch (err) { console.warn('Unable to stop barcode scanner:', err); }
    }
    if (section === 'barcode-scanner' && typeof dashLoadRecentScans === 'function') {
        try { dashLoadRecentScans(); } catch (err) { console.warn('Unable to load recent scans:', err); }
    }
    
    // Hide all sections
    document.querySelectorAll('.content-section').forEach(s => {
        s.classList.remove('active');
        s.style.display = '';
    });
    
    // Deactivate all navigation items
    document.querySelectorAll('.sidebar-nav .nav-item, .sidebar-nav .nav-subitem, .sidebar-nav .nav-group-toggle, .nav-subitem').forEach(el => {
        el.classList.remove('active');
        el.classList.remove('active-group');
    });
    
    const prThreeMap = {
        'purchase-requests': 'requests',
        'pr-approval': 'approval',
        'supplier-invoices': 'invoices'
    };

    let normalizedSection = section === 'inv-mgmt' ? 'inventory' : section;
    let prTab = prThreeMap[section] || prThreeMap[normalizedSection];

    if (prTab) {
        normalizedSection = 'purchase-requests';
    }

    // Show target section (never fall back to Dashboard / first section)
    let target = document.getElementById('section-' + normalizedSection);
    if (target) {
        target.classList.add('active');
        target.style.display = 'block';
        if (normalizedSection === 'inventory' && typeof loadReturnReplacementHistory === 'function') {
            loadReturnReplacementHistory();
        }
    } else if (typeof console !== 'undefined') {
        console.warn('Section not found:', normalizedSection);
    }

    if (prTab) {
        switchPRInvoiceTab(prTab);
    }
    
    // Find nav item to highlight if not explicitly passed
    let nav = clickedEl;
    if (!nav) {
        nav = document.querySelector(`.sidebar-nav button[onclick*="'${section}'"]`) ||
              document.querySelector(`.sidebar-nav button[onclick*="'${normalizedSection}'"]`);
    }
    if (typeof incomingGrSidebarNav === 'function') {
        const incomingNav = incomingGrSidebarNav(normalizedSection, clickedEl);
        if (incomingNav) nav = incomingNav;
    }
    if (typeof syncIncomingGrTabs === 'function') syncIncomingGrTabs(normalizedSection);

    if (nav) {
        nav.classList.add('active');
        // If it's a subitem, highlight parent group toggle
        const parentGroup = nav.closest('.nav-group');
        if (parentGroup) {
            parentGroup.classList.add('open');
            parentGroup.querySelectorAll('.nav-group-toggle').forEach(t => t.classList.add('active-group'));
        }
    }
    
    // Update breadcrumb/titles
    const titles = { 
        dashboard: 'Dashboard', 
        inventory: 'Product Catalog', 
        'stock-inventory': 'Inventory',
        'inv-mgmt': 'Product Catalog', 
        'data-entry': 'Stock Movement', 
        users: 'Users', 
        'audit-log': 'Activity Logs', 
        'technician-job-orders': 'Technician Job Orders',
        'warehouse-audit-log': 'Audit Log',
        reports: 'Inventory Reports',
        'transaction-history': 'Transaction History',
        'sales-reports': 'Sales Reports',
        account: 'Settings', 
        procurement: 'Procurement Overview', 
        'purchase-requests': 'Purchase Requests',
        'pr-approval': 'PR Queue',
        'purchase-orders': 'PO Creation',
        'po-creation': 'PO Creation',
        'po-approvals': 'PO Approvals',
        'po-tracker': 'PO Tracker',
        'incoming-deliveries': 'Incoming Deliveries',
        'purchase-history': 'Purchase History',
        'purchase-receipts': 'Goods Received',
        'markup-queue': 'Pricing Queue',
        'stock-in-queue': 'Stock-In Queue',
        'damage-returns': 'Replacement / Returns',
        'delivery-schedule': 'Delivery Schedule',
        'supplier-invoices': 'Supplier Invoices',
        'purchaser-audit-log': 'Audit Log',
        'barcode-scanner': 'Barcode Scanner',
        'serials-view': 'Serial Numbers',
        'stock-barcodes': 'Stock Barcodes',
        'low-stock-view': 'Low Stock Alerts',
        'expiry-view': 'Near Expiry Alerts',
        'location-stock-view': 'Location Stock',
        'transfers-view': 'Stock Transfers',
        'suppliers-view': 'Supplier Directory',
        'customers': 'Customer Management',
        'sales-orders': 'Orders',
        'product-pricing': 'Price',
        'billing-invoices': 'Account Receivable',
        'unpaid-invoices': 'Accounts Receivable / Pending',
        notifications: 'Alerts & History'
    };
    const currentTitle = titles[section] || titles[normalizedSection] || 'Dashboard';
    const pageTitle = document.querySelector('.page-title') || document.getElementById('breadcrumbCurrent');
    if (pageTitle) pageTitle.textContent = currentTitle;
    document.title = currentTitle + ' | Clarenzon Medical';
    
    // Auto-load data for non-PR-three sections
    if (normalizedSection === 'inventory' || normalizedSection === 'procurement') {
        setTimeout(() => { if (typeof loadDashboard === 'function') loadDashboard(); }, 50);
    }
    if (normalizedSection === 'incoming-deliveries') {
        setTimeout(() => { if (typeof loadIncomingDeliveries === 'function') loadIncomingDeliveries(); }, 50);
    }
    if (normalizedSection === 'purchase-orders' || normalizedSection === 'po-creation') {
        setTimeout(() => { if (typeof loadPurchaseOrders === 'function') loadPurchaseOrders(); }, 50);
    }
    if (normalizedSection === 'po-approvals') {
        setTimeout(() => { if (typeof loadPoApprovals === 'function') loadPoApprovals(); }, 50);
    }
    if (normalizedSection === 'po-tracker') {
        setTimeout(() => { if (typeof loadPoTracker === 'function') loadPoTracker(); }, 50);
    }
    if (normalizedSection === 'stock-inventory') {
        setTimeout(() => {
            if (typeof loadInventoryOverview === 'function') loadInventoryOverview();
            if (typeof loadReturnReplacementHistory === 'function') loadReturnReplacementHistory();
            if (typeof loadStockConditionHistory === 'function') loadStockConditionHistory();
        }, 50);
    }
    if (normalizedSection === 'purchase-receipts') {
        setTimeout(() => { if (typeof loadPurchaseReceipts === 'function') loadPurchaseReceipts(); }, 50);
    }
    if (normalizedSection === 'markup-queue') {
        setTimeout(() => { if (typeof loadPurchaserMarkupQueue === 'function') loadPurchaserMarkupQueue(); }, 50);
    }
    if (normalizedSection === 'stock-in-queue') {
        setTimeout(() => { if (typeof loadStockInQueue === 'function') loadStockInQueue(); }, 50);
    }
    if (normalizedSection === 'damage-returns') {
        setTimeout(() => { if (typeof loadDamageReturns === 'function') loadDamageReturns(); }, 50);
    }
    if (normalizedSection === 'product-pricing') {
        setTimeout(() => { if (typeof loadProductPricingTable === 'function') loadProductPricingTable(); }, 50);
    }
    if (normalizedSection === 'transaction-history') {
        setTimeout(() => { if (typeof loadTransactionHistory === 'function') loadTransactionHistory(); }, 50);
    }
    if (normalizedSection === 'suppliers-view') {
        setTimeout(() => { if (typeof loadSuppliers === 'function') loadSuppliers(); }, 50);
    }
    if (normalizedSection === 'customers') {
        setTimeout(() => { if (typeof loadCustomers === 'function') loadCustomers(); }, 50);
    }
    if (normalizedSection === 'serials-view') {
        setTimeout(() => { if (typeof loadSerials === 'function') loadSerials(); }, 50);
    }
    if (normalizedSection === 'stock-barcodes') {
        setTimeout(() => { if (typeof loadStockBarcodes === 'function') loadStockBarcodes(); }, 50);
    }
    if (normalizedSection === 'location-stock-view') {
        setTimeout(() => { if (typeof loadLocationStock === 'function') loadLocationStock(); }, 50);
    }
    if (normalizedSection === 'transfers-view') {
        setTimeout(() => { if (typeof loadTransfers === 'function') loadTransfers(); }, 50);
    }
    if (normalizedSection === 'purchase-orders') {
        setTimeout(() => {
            if (typeof loadDropdowns === 'function') loadDropdowns();
            if (typeof loadPurchaseOrders === 'function') loadPurchaseOrders();
        }, 50);
    }
    
    if (typeof syncSalesOrderPosForSection === 'function') {
        syncSalesOrderPosForSection(normalizedSection);
    }

    // Close mobile sidebar
    if (window.innerWidth <= 991 && typeof closeMobileSidebar === 'function') {
        closeMobileSidebar();
    }
    
    // Initialize charts for Analytics and Dashboard
    if (normalizedSection === 'analytics' && typeof initAnalyticsCharts === 'function') {
        initAnalyticsCharts();
    }
    if (normalizedSection === 'dashboard' && typeof initDashboardCharts === 'function') {
        initDashboardCharts();
    }
}

document.addEventListener('DOMContentLoaded', applySectionFromHash);

function invSwitchTab(tab, btn) {
    document.querySelectorAll('.inv-tab-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.inv-tab-btn').forEach(b => b.style.borderBottomColor = 'transparent');
    const panel = document.getElementById('inv-tab-' + tab);
    if (panel) panel.style.display = 'block';
    if (btn) btn.style.borderBottomColor = '#2563eb';
    if (tab === 'catalog') typeof loadCatalog === 'function' && loadCatalog();
    if (tab === 'movements') typeof loadMovements === 'function' && loadMovements();
    if (tab === 'batches') typeof loadBatches === 'function' && loadBatches();
    if (tab === 'alerts') typeof loadAlerts === 'function' && loadAlerts();
    if (tab === 'serials') typeof loadSerials === 'function' && loadSerials();
}

function procSwitchTab(tab, btn) {
    document.querySelectorAll('.proc-tab-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.proc-tab-btn').forEach(b => b.style.borderBottomColor = 'transparent');
    const panel = document.getElementById('proc-tab-' + tab);
    if (panel) panel.style.display = 'block';
    if (btn) btn.style.borderBottomColor = '#2563eb';
    if (tab === 'suppliers') typeof loadSuppliers === 'function' && loadSuppliers();
    if (tab === 'purchase-orders') typeof loadPurchaseOrders === 'function' && loadPurchaseOrders();
    if (tab === 'approval') typeof loadApprovalQueue === 'function' && loadApprovalQueue();
    if (tab === 'performance') typeof loadPerformance === 'function' && loadPerformance();
    if (tab === 'dashboard') typeof loadDashboard === 'function' && loadDashboard();
}

function filterTable(input, tableId) {
    const filter = input.value.toLowerCase();
    const rows = document.getElementById(tableId).getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (let row of rows) row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('open');
    }
}

document.addEventListener('click', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('modal-overlay')) {
        const id = e.target.id;
        if (id) closeModal(id);
    }
});

function applyProductFilters() {
    const searchInput = document.getElementById('productSearchInput');
    const expiryFilter = document.getElementById('expiryFilter');
    const stockFilter = document.getElementById('stockFilter');
    const categoryFilter = document.getElementById('categoryFilter');
    const table = document.getElementById('productsTable');
    if (!table) return;

    const search = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const expiry = expiryFilter ? expiryFilter.value : '';
    const stock = stockFilter ? stockFilter.value : '';
    const category = categoryFilter ? categoryFilter.value : '';
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (const row of rows) {
        const matchesSearch = !search || row.textContent.toLowerCase().includes(search) || (row.dataset.barcodes || '').toLowerCase().includes(search);
        const matchesExpiry = !expiry || row.dataset.expiry === expiry;
        const matchesStock = !stock || row.dataset.stock === stock;
        const matchesCategory = !category || row.dataset.category === category || row.dataset.parent === category;
        row.style.display = matchesSearch && matchesExpiry && matchesStock && matchesCategory ? '' : 'none';
    }
}
function filterSupplierHistory() {
    const select = document.getElementById('supplierHistoryFilter');
    const table = document.getElementById('supplierHistoryTable');
    if (!select || !table) return;
    const supplierId = select.value;
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (const row of rows) {
        if (!row.dataset.supplierId) continue;
        row.style.display = !supplierId || row.dataset.supplierId === supplierId ? '' : 'none';
    }
}
function editProduct(sku, name, category, qty, price, location, cost, uom, supplierId, barcode, expiryDate) {
    document.getElementById('edit_sku').value = sku;
    document.getElementById('edit_sku_display').value = sku;
    document.getElementById('edit_name').value = name;
    const barcodeEl = document.getElementById('edit_barcode');
    if (barcodeEl) barcodeEl.value = barcode || '';
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_quantity').value = qty;
    document.getElementById('edit_expiry_date').value = expiryDate || '';
    document.getElementById('edit_price').value = price;
    document.getElementById('edit_cost').value = cost;
    document.getElementById('edit_unit_of_measure').value = uom || 'pcs';
    document.getElementById('edit_supplier_id').value = supplierId || 0;
    document.getElementById('edit_location').value = location;
    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}
function deleteProduct(sku, name) {
    document.getElementById('delete_sku').value = sku;
    document.getElementById('delete_product_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteProductModal')).show();
}
function editCategory(id, name, description) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    document.getElementById('edit_category_description').value = description;
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}
function deleteCategory(id, name) {
    document.getElementById('delete_category_id').value = id;
    document.getElementById('delete_category_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteCategoryModal')).show();
}
function togglePassVisibility(btn) {
    const input = btn.previousElementSibling || btn.parentElement.querySelector('input');
    if (input) {
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
        } else {
            input.type = 'password';
            btn.innerHTML = '<i class="bi bi-eye"></i>';
        }
    }
}

function splitPersonName(user) {
    const first = String(user && user.first_name || '').trim();
    const last = String(user && user.last_name || '').trim();
    if (first || last) return { first, last };
    const full = String(user && user.full_name || '').trim().replace(/\s+/g, ' ');
    if (!full) return { first: '', last: '' };
    const i = full.indexOf(' ');
    if (i < 0) return { first: full, last: '' };
    return { first: full.slice(0, i), last: full.slice(i + 1) };
}

function openEditUserPermissionsModal(user) {
    if (!user) return;
    document.getElementById('edit_perm_user_id').value = user.id || '';
    const parts = splitPersonName(user);
    const firstEl = document.getElementById('edit_perm_first_name');
    const lastEl = document.getElementById('edit_perm_last_name');
    const fullEl = document.getElementById('edit_perm_full_name');
    if (firstEl) firstEl.value = parts.first;
    if (lastEl) lastEl.value = parts.last;
    if (fullEl) fullEl.value = user.full_name || [parts.first, parts.last].filter(Boolean).join(' ');
    document.getElementById('edit_perm_email').value = user.email || '';
    document.getElementById('edit_perm_role').value = user.role || 'Staff';
    document.getElementById('edit_perm_status').value = user.status || 'Active';
    
    const allowed = (user.allowed_sections || '').split(',').map(s => s.trim());
    document.querySelectorAll('.perm-sec-cb').forEach(cb => {
        cb.checked = allowed.includes(cb.value);
    });
    
    document.getElementById('perm_can_manage').checked = !!user.can_manage;
    document.getElementById('perm_can_delete').checked = !!user.can_delete;
    document.getElementById('perm_can_print').checked = !!user.can_print;
    document.getElementById('perm_can_approve').checked = !!user.can_approve;
    const pricingCb = document.getElementById('perm_can_manage_pricing');
    if (pricingCb) pricingCb.checked = !!user.can_manage_pricing;
    
    new bootstrap.Modal(document.getElementById('editUserPermissionsModal')).show();
}

function openUserPasswordModal(userId, username, plainPassword) {
    document.getElementById('target_user_id').value = userId;
    document.getElementById('target_username').value = username;
    document.getElementById('target_username_display').value = username;
    const oldPassDisplay = document.getElementById('target_old_password_display');
    if (oldPassDisplay) oldPassDisplay.value = plainPassword || '';
    
    const newPass = document.getElementById('admin_new_password');
    if (newPass) newPass.value = '';
    const confirmPass = document.getElementById('admin_confirm_password');
    if (confirmPass) confirmPass.value = '';
    
    const forceCheckbox = document.getElementById('force_password_change');
    if (forceCheckbox) forceCheckbox.checked = true;
    new bootstrap.Modal(document.getElementById('userPasswordModal')).show();
}
function editAuditLog(id, currentDetails) {
    appPrompt('Edit audit details:', currentDetails || '').then(nextDetails => {
    if (nextDetails === null) return;
    const trimmed = String(nextDetails).trim();
    if (!trimmed) {
        alert('Details cannot be empty.');
        return;
    }
    const idInput = document.getElementById('audit_log_edit_id');
    const detailsInput = document.getElementById('audit_log_edit_details');
    const form = document.getElementById('auditLogEditForm');
    if (!idInput || !detailsInput || !form) return;
    idInput.value = id;
    detailsInput.value = trimmed;
    form.submit();
    });
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

function applyAdminScannedCodeToForms(code) {
    const cleanCode = (code || '').trim().toUpperCase();
    if (!cleanCode) return false;
    let matched = false;
    document.querySelectorAll('select[name="product"], select[name="del_product"]').forEach((selectEl) => {
        const option = Array.from(selectEl.options).find((opt) => opt.value.trim().toUpperCase() === cleanCode);
        if (option) {
            selectEl.value = option.value;
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            matched = true;
        }
    });
    return matched;
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
        document.getElementById('admin-scan-result').textContent = "Scanner library not loaded. Check internet connection or CDN access.";
        return;
    }
    if (!adminScanner) adminScanner = new Html5Qrcode("admin-scanner-reader");
    adminScanner.start(
        { facingMode: "environment" },
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
        document.getElementById('admin-scan-result').textContent = "Unable to start camera scanner: " + err;
    });
}

function stopAdminScanner() {
    if (!adminScanner || !adminScannerActive) return;
    adminScanner.stop().then(() => {
        adminScannerActive = false;
    }).catch(() => { });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert.alert-success').forEach(function (el) {
        setTimeout(function () {
            try { bootstrap.Alert.getOrCreateInstance(el).close(); }
            catch (e) { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }
        }, 3000);
    });

    const lineCtx = document.getElementById('lineChart');
    if (lineCtx) new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: ['1', '2', '3', '4', '5', '6', '7'], datasets: [
                { label: 'Sales', data: [15, 22, 18, 32, 28, 38, 24], borderColor: '#4F46E5', backgroundColor: 'rgba(79,70,229,0.1)', fill: true, tension: 0.4, borderWidth: 2 },
                { label: 'Purchase', data: [10, 15, 12, 25, 20, 30, 18], borderColor: '#D1D5DB', backgroundColor: 'transparent', fill: false, tension: 0.4, borderWidth: 2 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end' } }, scales: { y: { beginAtZero: true, grid: { color: '#F3F4F6' } }, x: { grid: { display: false } } } }
    });

    let categoryStats = {};
    let inventoryItems = [];
    const adminDataEl = document.getElementById('admin-js-data');
    if (adminDataEl) {
        try {
            const parsed = JSON.parse(adminDataEl.textContent || '{}');
            categoryStats = parsed.categoryStats || {};
            inventoryItems = parsed.inventoryItems || [];
        } catch (_) { }
    }
    const reportCharts = {};

    function initReportCharts(tabId) {
        if (tabId === 'report-valuation' && !reportCharts.valuation) {
            reportCharts.valuation = true;
            const pieCtx = document.getElementById('valuePieChart');
            if (pieCtx) new Chart(pieCtx, {
                type: 'doughnut',
                data: { labels: Object.keys(categoryStats), datasets: [{ data: Object.values(categoryStats).map(s => s.value), backgroundColor: ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#EC4899', '#14B8A6', '#F97316', '#6366F1'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
            });
            const topCtx = document.getElementById('topProductsChart');
            if (topCtx) {
                const sorted = [...inventoryItems].sort((a, b) => (b[3] * b[4]) - (a[3] * a[4])).slice(0, 5);
                new Chart(topCtx, {
                    type: 'bar',
                    data: { labels: sorted.map(i => i[1].length > 20 ? i[1].substring(0, 20) + '…' : i[1]), datasets: [{ label: 'Value (₱)', data: sorted.map(i => i[3] * i[4]), backgroundColor: '#4F46E5', borderRadius: 6 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#F3F4F6' } }, y: { grid: { display: false } } } }
                });
            }
        }
        if (tabId === 'report-movement' && !reportCharts.movement) {
            reportCharts.movement = true;
            const mvCtx = document.getElementById('movementTimelineChart');
            if (mvCtx) new Chart(mvCtx, {
                type: 'line',
                data: {
                    labels: ['Jan 28', 'Jan 29', 'Jan 30', 'Jan 31', 'Feb 1'], datasets: [
                        { label: 'Stock In', data: [50, 200, 100, 0, 56], borderColor: '#10B981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.4 },
                        { label: 'Stock Out', data: [0, 5, 0, 3, 0], borderColor: '#EF4444', backgroundColor: 'rgba(239,68,68,0.1)', fill: true, tension: 0.4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end' } }, scales: { y: { beginAtZero: true, grid: { color: '#F3F4F6' } }, x: { grid: { display: false } } } }
            });
        }
        if (tabId === 'report-category' && !reportCharts.category) {
            reportCharts.category = true;
            const catCtx = document.getElementById('categoryBarChart');
            if (catCtx) new Chart(catCtx, {
                type: 'bar',
                data: { labels: Object.keys(categoryStats), datasets: [{ label: 'Total Quantity', data: Object.values(categoryStats).map(s => s.quantity), backgroundColor: '#4F46E5', borderRadius: 6 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#F3F4F6' } }, x: { grid: { display: false } } } }
            });
        }
    }

    document.querySelectorAll('[data-bs-toggle="pill"]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function (e) {
            const target = e.target.getAttribute('data-bs-target');
            if (target) initReportCharts(target.replace('#', ''));
        });
    });
});


/* ════════════════════════════════════════════════════════════
   Embedded Barcode Scanner Logic for admin_dashboard.php
   ════════════════════════════════════════════════════════════ */
let dashScannerInstance = null;
let dashScannerIsActive = false;
let dashCurrentProduct = null;
let dashLastScannedCode = '';
let dashScanCooldown = false;

function dashStartScanner() {
    if (dashScannerIsActive) return;
    if (typeof Html5Qrcode === 'undefined') {
        alert('Scanner library not loaded. Please check network connection.');
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

    if (!dashScannerInstance) {
        dashScannerInstance = new Html5Qrcode("dashScannerViewport");
    }

    dashScannerInstance.start(
        { facingMode: "environment" },
        {
            fps: 15,
            qrbox: { width: 500, height: 350 }
        },
        (decodedText) => {
            if (dashScanCooldown) return;
            dashScanCooldown = true;
            setTimeout(() => { dashScanCooldown = false; }, 2000);
            dashLastScannedCode = decodedText.trim();
            dashLookupBarcode(dashLastScannedCode, 'camera');
        },
        (errorMessage) => { }
    ).then(() => {
        dashScannerIsActive = true;
    }).catch((err) => {
        console.error('Camera scanner start error:', err);
        if (placeholder) placeholder.style.display = 'flex';
        if (viewfinder) viewfinder.classList.remove('scanning');
        if (badge) { badge.textContent = 'Camera Error'; badge.className = 'badge badge-red'; }
        if (startBtn) startBtn.style.display = 'inline-block';
        if (stopBtn) stopBtn.style.display = 'none';
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
        const data = await response.json();
        if (data.success && data.data) {
            dashSetProductDetails(data.data);
            dashLogScan(code, 'lookup', data.data.id, deviceUsed);
        } else {
            if (infoContainer) {
                infoContainer.innerHTML = `
                    <div class="alert alert-warning my-3" style="font-size:14px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Barcode not found in Product Catalog.</strong>
                    </div>`;
            }
            dashCurrentProduct = null;
            dashLogScan(code, 'not_found', null, deviceUsed);
        }
    } catch (err) {
        if (infoContainer) {
            infoContainer.innerHTML = `<div class="alert alert-danger my-3">Error fetching product details.</div>`;
        }
    }
    dashLoadRecentScans();
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

function dashSetProductDetails(product) {
    dashCurrentProduct = product;
    const infoContainer = document.getElementById('dashProductInfo');
    if (!infoContainer) return;

    if (!product) {
        infoContainer.innerHTML = `
            <div class="text-center py-4" style="color:var(--text-tertiary);">
                <i class="bi bi-box-seam" style="font-size:40px;opacity:0.4;"></i>
                <p class="mt-2 mb-0">No item scanned yet.<br>Scan a barcode to see product details.</p>
            </div>`;
        return;
    }

    const stock = parseInt(product.quantity_in_stock) || 0;
    let stockBadge = '<span class="badge bg-success">In Stock</span>';
    if (stock <= 0) stockBadge = '<span class="badge bg-danger">Out of Stock</span>';
    else if (stock <= 30) stockBadge = '<span class="badge bg-warning text-dark">Low Stock</span>';

    infoContainer.innerHTML = `
        <div class="p-3" style="background:var(--bg-tertiary,#f8fafc);border-radius:12px;border:1px solid var(--border-color,#e2e8f0);">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="fw-bold m-0" style="font-size:16px;">${escapeHtml(product.name || '')}</h5>
                ${stockBadge}
            </div>
            <div class="row g-2" style="font-size:13px;">
                <div class="col-6"><strong>SKU:</strong> ${escapeHtml(product.sku || '')}</div>
                <div class="col-6"><strong>Barcode:</strong> ${escapeHtml(product.barcode || '—')}</div>
                <div class="col-6"><strong>Current Stock:</strong> ${stock}</div>
                <div class="col-6"><strong>Unit Price:</strong> ₱${Number(product.unit_price || 0).toFixed(2)}</div>
                <div class="col-12"><strong>Location:</strong> ${escapeHtml(product.location || 'Not assigned')}</div>
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
                        <div class="text-muted" style="font-size:11px;">${escapeHtml(item.name || item.action_performed)}</div>
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

async function dashApplyAction(actionType = 'stock_in') {
    actionType = actionType || window.dashSelectedAction || 'stock_in';
    if (!dashCurrentProduct) {
        alert('Please scan or look up a product first.');
        return;
    }
    const barcode = String(dashCurrentProduct.barcode || dashCurrentProduct.sku || '').trim();
    if (!barcode) {
        alert('This product has no barcode.');
        return;
    }
    if (actionType === 'stock_in') {
        window._pendingStockInScan = barcode;
        if (typeof showSection === 'function') showSection('stock-in-queue');
        else if (typeof loadStockInQueue === 'function') loadStockInQueue();
        return;
    }
    window._pendingSoBarcode = barcode;
    if (typeof clarSectionIsDenied === 'function' && clarSectionIsDenied('sales-orders')) {
        return;
    }
    if (typeof showSection === 'function') showSection('sales-orders');
    if (typeof isSalesOrderPosInline === 'function' && isSalesOrderPosInline()) {
        return;
    }
    if (typeof openCreateSalesOrderModal === 'function') {
        setTimeout(function () { openCreateSalesOrderModal(); }, 80);
        return;
    }
    if (typeof showSection === 'function') showSection('data-entry');
    const smInput = document.getElementById('adminStockMovementBarcode');
    if (smInput) {
        smInput.value = barcode;
        if (typeof applyAdminBarcodeToStockMovement === 'function') applyAdminBarcodeToStockMovement();
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

    const modalEl = document.getElementById('dashEditProductModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

async function dashSaveProduct() {
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
    }
});

// ─── BILLING & INVOICING HANDLERS ───

function toggleChequePayFields() {
    const method = document.getElementById('pay_method')?.value || '';
    const box = document.getElementById('chequePayFields');
    const isCheque = method === 'Cheque' || method === 'Check';
    if (box) box.style.display = isCheque ? '' : 'none';
    const bank = document.getElementById('pay_bank_name');
    const chq = document.getElementById('pay_cheque_number');
    const cdt = document.getElementById('pay_cheque_date');
    if (bank) bank.required = isCheque;
    if (chq) chq.required = isCheque;
    if (cdt) cdt.required = false;
    if (!isCheque && typeof clearChequeDateError === 'function') clearChequeDateError();
}

function openPaymentModal(invoiceId, invoiceNumber, grandTotal, remainingBalance) {
    const modalEl = document.getElementById('recordPaymentModal');
    if (!modalEl) return;
    
    document.getElementById('pay_invoice_id').value = invoiceId;
    document.getElementById('pay_invoice_number').textContent = invoiceNumber;
    document.getElementById('pay_grand_total').textContent = '₱' + Number(grandTotal).toFixed(2);
    document.getElementById('pay_remaining_balance').textContent = '₱' + Number(remainingBalance).toFixed(2);
    
    const amountInput = document.getElementById('pay_amount_paid');
    amountInput.value = Number(remainingBalance).toFixed(2);
    amountInput.max = remainingBalance;
    const method = document.getElementById('pay_method');
    if (method) method.value = 'Cash';
    const bank = document.getElementById('pay_bank_name');
    const chq = document.getElementById('pay_cheque_number');
    const cdt = document.getElementById('pay_cheque_date');
    if (bank) bank.value = '';
    if (chq) chq.value = '';
    if (cdt) cdt.value = '';
    if (typeof toggleChequePayFields === 'function') toggleChequePayFields();
    
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function resolveInvoiceDetails(source, itemsJsonStr) {
    if (source && source.nodeType === 1) {
        const id = String(source.getAttribute('data-invoice-id') || '');
        if (id && window.INVOICE_DETAILS && window.INVOICE_DETAILS[id]) {
            return window.INVOICE_DETAILS[id];
        }
        const raw = source.getAttribute('data-invoice') || (source.dataset && source.dataset.invoice) || '';
        if (raw) {
            try { return JSON.parse(raw); } catch (_) {}
        }
        return {};
    }
    let items = [];
    try { items = JSON.parse(itemsJsonStr || '[]'); } catch (_) { items = []; }
    return {
        invoice_number: source || '',
        items: items
    };
}

function openInvoiceDetailsModal(source, customer, subtotal, discount, tax, grandTotal, balance, date, status, itemsJsonStr) {
    const modalEl = document.getElementById('invoiceDetailsModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const data = resolveInvoiceDetails(source, itemsJsonStr);
    const invoiceNumber = data.invoice_number || (typeof source === 'string' ? source : '');
    customer = data.customer_name != null ? data.customer_name : (customer || '');
    subtotal = data.subtotal != null ? data.subtotal : subtotal;
    discount = data.discount_amount != null ? data.discount_amount : discount;
    tax = data.tax_amount != null ? data.tax_amount : tax;
    grandTotal = data.grand_total != null ? data.grand_total : grandTotal;
    balance = data.remaining_balance != null ? data.remaining_balance : balance;
    date = data.created_at || date || '';
    status = data.display_status || data.payment_status || status || 'Unpaid';
    const items = Array.isArray(data.items) ? data.items : [];

    const extra = [];
    if (data.sales_order_number) extra.push('SO ' + data.sales_order_number);
    if (data.customer_po_number) extra.push('Customer PO ' + data.customer_po_number);
    if (data.sale_type_label || data.sale_type) extra.push(data.sale_type_label || data.sale_type);
    if (data.payment_method_label) extra.push(data.payment_method_label);
    const extraEl = document.getElementById('det_invoice_refs');
    if (extraEl) extraEl.textContent = extra.join(' · ');

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };
    setText('det_inv_number', invoiceNumber);
    setText('det_customer', customer);
    setText('det_date', date);
    setText('det_subtotal', '₱' + Number(subtotal || 0).toFixed(2));
    setText('det_discount', '₱' + Number(discount || 0).toFixed(2));
    setText('det_tax', '₱' + Number(tax || 0).toFixed(2));
    setText('det_grand_total', '₱' + Number(grandTotal || 0).toFixed(2));
    setText('det_balance', '₱' + Number(balance || 0).toFixed(2));

    let statusClass = 'badge-warning';
    if (status === 'Paid') statusClass = 'badge-green';
    else if (status === 'Pending') statusClass = 'badge-warning';
    else if (status === 'Unpaid' || status === 'Unpaid / Outstanding' || !status) statusClass = 'badge-danger';

    const statusContainer = document.getElementById('det_status');
    if (statusContainer) {
        statusContainer.className = 'badge ' + statusClass;
        statusContainer.textContent = status || 'Unpaid';
    }

    const tbody = document.getElementById('det_items_tbody');
    if (tbody) {
        tbody.innerHTML = '';
        items.forEach(item => {
            const qty = Number(item.quantity || 0);
            const price = Number(item.unit_price || 0);
            const amount = qty * price;
            const rate = Number(item.tax_rate || data.tax_rate || 12);
            const taxInc = item.tax_included != null && item.tax_included !== ''
                ? Number(item.tax_included)
                : (rate > 0 ? amount * rate / (100 + rate) : 0);
            const uom = item.unit_of_measure || item.uom || '—';
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${item.product_sku || 'N/A'}</td>
                <td><strong>${item.product_name || 'N/A'}</strong></td>
                <td>${uom}</td>
                <td>${qty}</td>
                <td>₱${price.toFixed(2)}</td>
                <td>₱${taxInc.toFixed(2)}</td>
                <td><strong>₱${amount.toFixed(2)}</strong></td>
            `;
            tbody.appendChild(row);
        });
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No line items found for this invoice.</td></tr>';
        }
    }

    const printBtn = document.getElementById('btnPrintInvoice');
    if (printBtn) {
        printBtn.onclick = () => {
            window.open('print_invoice.php?invoice_number=' + encodeURIComponent(invoiceNumber) + '&with_receipt=1', '_blank');
        };
    }
    const receiptBtn = document.getElementById('btnPrintReceipt');
    if (receiptBtn) {
        receiptBtn.onclick = () => {
            const payNo = data.latest_payment_number || '';
            const url = payNo
                ? 'print_payment_receipt.php?payment_number=' + encodeURIComponent(payNo)
                : 'print_payment_receipt.php?invoice_number=' + encodeURIComponent(invoiceNumber);
            window.open(url, '_blank');
        };
    }

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

if (!window._invoiceViewClickBound) {
    window._invoiceViewClickBound = true;
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-view-invoice');
        if (!btn) return;
        e.preventDefault();
        openInvoiceDetailsModal(btn);
    });
}

function setSalesCategoryTab(btn) {
    if (!btn) return;
    const target = btn.dataset.target || 'invoices';
    const type = btn.dataset.saleType || '';
    document.querySelectorAll('.js-sales-type-tab[data-target="' + target + '"]').forEach(b => {
        b.classList.remove('btn-primary');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-primary');
    if (target === 'orders') {
        const el = document.getElementById('soTypeFilter');
        if (el) el.value = type;
        if (type === '') {
            const statusEl = document.getElementById('soStatusFilter');
            if (statusEl) statusEl.value = '';
        }
        if (typeof filterSalesOrders === 'function') filterSalesOrders();
        return;
    }
    const el = document.getElementById('invoiceTypeFilter');
    if (el) el.value = type;
    if (typeof filterInvoices === 'function') filterInvoices();
}

function billingStatusFilterMatches(row, filterStatus) {
    if (!filterStatus) return true;
    const display = (row.dataset.displayStatus || row.dataset.status || '').toLowerCase();
    const raw = (row.dataset.status || '').toLowerCase();
    const f = String(filterStatus).toLowerCase();
    if (f === 'unpaid / outstanding' || f === 'unpaid') {
        return display.indexOf('unpaid') !== -1 || raw === 'unpaid' || raw === 'partially paid';
    }
    return display === f || raw === f;
}

function filterInvoices() {
    const searchEl = document.getElementById('invoiceSearchInput');
    const statusEl = document.getElementById('invoiceStatusFilter');
    if (!searchEl || !statusEl) return;

    const filterText = searchEl.value.toLowerCase();
    const filterStatus = statusEl.value.toLowerCase();
    const filterType = (document.getElementById('invoiceTypeFilter')?.value || '').toLowerCase();
    const rows = document.querySelectorAll('#invoicesTable tbody tr');
    
    rows.forEach(row => {
        const textMatch = row.textContent.toLowerCase().includes(filterText);
        const statusMatch = billingStatusFilterMatches(row, filterStatus);
        const typeMatch = !filterType || (row.dataset.saleType || '').toLowerCase() === filterType;
        row.style.display = (textMatch && statusMatch && typeMatch) ? '' : 'none';
    });
}

function filterSalesOrders() {
    const searchEl = document.getElementById('soSearchInput');
    if (!searchEl && !document.getElementById('soTypeFilter')) return;

    const filterText = (searchEl?.value || '').toLowerCase();
    const filterStatus = (document.getElementById('soStatusFilter')?.value || '').toLowerCase();
    const filterType = (document.getElementById('soTypeFilter')?.value || '').toLowerCase();
    const tbody = document.querySelector('#salesOrdersTable tbody');
    const rows = tbody ? tbody.querySelectorAll(':scope > tr') : [];

    rows.forEach(row => {
        if (row.children.length === 1 && row.children[0].hasAttribute('colspan')) return;
        const textMatch = row.textContent.toLowerCase().includes(filterText);
        const rowStatus = (row.dataset.status || '').toLowerCase();
        const displayStatus = (row.dataset.displayStatus || '').toLowerCase();
        const statusMatch = filterStatus === ''
            || (filterStatus === 'active' ? rowStatus !== 'approved' : (
                filterStatus === 'open credit' ? displayStatus === 'open credit' : rowStatus === filterStatus
            ));
        const typeMatch = !filterType || (row.dataset.saleType || '').toLowerCase() === filterType;
        const visible = textMatch && statusMatch && typeMatch;
        if (visible) row.removeAttribute('data-clar-hide');
        else row.setAttribute('data-clar-hide', '1');
    });
    if (tbody && typeof window.clarenzonRefreshListPager === 'function') {
        window.clarenzonRefreshListPager(tbody, true);
        return;
    }
    rows.forEach(row => {
        if (row.children.length === 1 && row.children[0].hasAttribute('colspan')) return;
        row.style.display = row.getAttribute('data-clar-hide') === '1' ? 'none' : '';
    });
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
