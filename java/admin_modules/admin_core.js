/* Core admin dashboard navigation module */
function clarSectionIsDenied(section) {
    const denied = window.CLAR_DENIED_SECTIONS;
    if (!Array.isArray(denied) || !denied.length) return false;
    let n = String(section || '').trim().toLowerCase().replace(/[_\s]+/g, '-');
    if (n === 'sales-credit') n = 'sales-orders';
    if (n === 'inv-mgmt' || n === 'product-catalog' || n === 'stock-inventory') n = 'inventory';
    if (n === 'po-creation' || n === 'po-approvals' || n === 'po-tracker') n = 'purchase-orders';
    if (n === 'pr-queue') n = 'pr-approval';
    if (n === 'price') n = 'product-pricing';
    return denied.indexOf(n) !== -1;
}

function syncIncomingGrTabs(section) {
    const key = String(section || '');
    document.querySelectorAll('[data-incoming-gr-tab]').forEach(btn => {
        const on = btn.getAttribute('data-incoming-gr-tab') === key;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
        btn.tabIndex = on ? 0 : -1;
        if (on) btn.setAttribute('aria-current', 'page');
        else btn.removeAttribute('aria-current');
    });
}

function incomingGrSidebarNav(normalized, clickedEl) {
    if (String(normalized || '') !== 'purchase-receipts') return null;
    const fromSidebar = !!(clickedEl && clickedEl.closest && clickedEl.closest('.sidebar-nav'));
    if (fromSidebar) return null;
    return document.querySelector('.sidebar-nav button[onclick*="\'incoming-deliveries\'"]');
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

    document.querySelectorAll('.content-section').forEach(s => {
        s.classList.remove('active');
        s.style.display = '';
    });
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
    if (normalizedSection === 'product-catalog') normalizedSection = 'inventory';
    if (normalizedSection === 'po-creation') normalizedSection = 'purchase-orders';
    if (normalizedSection === 'pr-queue') normalizedSection = 'pr-approval';
    if (normalizedSection === 'price') normalizedSection = 'product-pricing';
    let prTab = prThreeMap[section] || prThreeMap[normalizedSection];

    if (prTab) normalizedSection = 'purchase-requests';

    let target = document.getElementById('section-' + normalizedSection);
    if (!target) {
        normalizedSection = 'dashboard';
        target = document.getElementById('section-dashboard');
        prTab = null;
    }
    // Do NOT fall back to the first .content-section (Dashboard) — that hides
    // missing-module bugs and makes sidebar clicks look like they open Overview.
    if (target) {
        target.classList.add('active');
        target.style.display = 'block';
        if (normalizedSection === 'inventory' && typeof loadReturnReplacementHistory === 'function') {
            loadReturnReplacementHistory();
        }
    } else if (typeof console !== 'undefined') {
        console.warn('Section not found:', normalizedSection);
    }

    if (prTab) switchPRInvoiceTab(prTab);

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
        const parentGroup = nav.closest('.nav-group');
        if (parentGroup) {
            parentGroup.classList.add('open');
            parentGroup.querySelectorAll('.nav-group-toggle').forEach(t => t.classList.add('active-group'));
        }
    }

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
        notifications: 'Alerts & History',
        procurement: 'Procurement Overview',
        'purchase-requests': 'Purchase Requests',
        'pr-approval': 'PR Approval Queue',
        'purchase-orders': 'PO Creation',
        'po-creation': 'PO Creation',
        'po-approvals': 'PO Approvals',
        'po-tracker': 'PO Tracker',
        'pr-queue': 'PR Queue',
        price: 'Price',
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
        'sales-credit': 'Credit Agreements',
        'product-pricing': 'Price',
        'billing-invoices': 'Account Receivable',
        'unpaid-invoices': 'Accounts Receivable / Pending'
    };

    const currentTitle = titles[section] || titles[normalizedSection] || 'Dashboard';
    const pageTitle = document.querySelector('.page-title') || document.getElementById('breadcrumbCurrent');
    if (pageTitle) pageTitle.textContent = currentTitle;
    document.title = currentTitle + ' | Clarenzon Medical';

    if (typeof syncSalesOrderPosForSection === 'function') syncSalesOrderPosForSection(normalizedSection);
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
    if (normalizedSection === 'data-entry') {
        setTimeout(() => { if (typeof loadPendingAdminStockIn === 'function') loadPendingAdminStockIn(); }, 50);
    }
    if (normalizedSection === 'product-pricing') {
        setTimeout(() => { if (typeof loadProductPricingTable === 'function') loadProductPricingTable(); }, 50);
    }
    if (normalizedSection === 'transaction-history') {
        setTimeout(() => { if (typeof loadTransactionHistory === 'function') loadTransactionHistory(); }, 50);
    }
    if (normalizedSection === 'damage-returns') {
        setTimeout(() => { if (typeof loadDamageReturns === 'function') loadDamageReturns(); }, 50);
    }
    if (normalizedSection === 'suppliers-view') {
        setTimeout(() => { if (typeof loadSuppliers === 'function') loadSuppliers(); }, 50);
    }
    if (normalizedSection === 'customers') {
        setTimeout(() => { if (typeof loadCustomers === 'function') loadCustomers(); }, 50);
    }
    if (normalizedSection === 'notifications') {
        setTimeout(() => {
            if (typeof loadExpiryAlertSettings === 'function') loadExpiryAlertSettings();
            if (typeof loadNotificationsHistory === 'function') loadNotificationsHistory();
        }, 50);
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

    if (window.innerWidth <= 991 && typeof closeMobileSidebar === 'function') {
        closeMobileSidebar();
    }

    if (normalizedSection === 'analytics' && typeof initAnalyticsCharts === 'function') {
        initAnalyticsCharts();
    }
    if (normalizedSection === 'dashboard' && typeof initDashboardCharts === 'function') {
        initDashboardCharts();
    }
}

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

document.addEventListener('keydown', function (e) {
    const btn = e.target && e.target.closest ? e.target.closest('[data-incoming-gr-tab]') : null;
    if (!btn) return;
    const list = btn.closest('.incoming-gr-tabs');
    if (!list) return;
    const tabs = Array.from(list.querySelectorAll('[data-incoming-gr-tab]'));
    if (!tabs.length) return;
    const idx = tabs.indexOf(btn);
    let next = -1;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (idx + 1) % tabs.length;
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (idx - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = tabs.length - 1;
    if (next < 0) return;
    e.preventDefault();
    tabs[next].focus();
    tabs[next].click();
});

document.addEventListener('click', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('modal-overlay')) {
        const id = e.target.id;
        if (id) closeModal(id);
    }
});
