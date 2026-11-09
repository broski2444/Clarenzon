/* ═══════════════════════════════════════════════════════════════
   CLARENZON MEDICAL SUPPLIES — DASHBOARD MAIN JS
   ═══════════════════════════════════════════════════════════════ */

/* ── Data from PHP ─────────────────────────────────────────── */
let categoryStats = {};
let inventoryItems = [];
const adminDataEl = document.getElementById('admin-js-data');
if (adminDataEl) {
    try {
        const parsed = JSON.parse(adminDataEl.textContent || '{}');
        categoryStats = parsed.categoryStats || {};
        inventoryItems = parsed.inventoryItems || [];
    } catch (_) {}
}

/* ── Section Titles Map ────────────────────────────────────── */
const sectionTitles = {
    'dashboard': 'Dashboard',
    'analytics': 'Analytics',
    'users': 'User Management',
    'audit-log': 'Activity Logs',
    'technician-job-orders': 'Technician Job Orders',
    'warehouse-audit-log': 'Audit Log',
    'inventory': 'Product Catalog',
    'stock-barcodes': 'Stock Barcodes',
    'data-entry': 'Stock Movement',
    'low-stock-view': 'Low Stock Alerts',
    'expiry-view': 'Near Expiry Alerts',
    'location-stock-view': 'Location Stock',
    'transfers-view': 'Stock Transfers',
    'suppliers-view': 'Supplier Directory',
    'customers': 'Customer Management',
    'purchase-history': 'Purchase History',
    'markup-queue': 'Pricing Queue',
    'delivery-schedule': 'Delivery Schedule',
    'purchaser-audit-log': 'Audit Log',
    'reports': 'Inventory Reports',
    'sales-reports': 'Sales Reports',
    'sales-orders': 'Orders',
    'sales-credit': 'Credit Agreements',
    'product-pricing': 'Product Selling Prices',
    'billing-invoices': 'Account Receivable',
    'unpaid-invoices': 'Accounts Receivable / Pending',
    'account': 'Settings'
};

/* ── Sidebar Toggle ────────────────────────────────────────── */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
    }
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
}

/* ── Nav Group Toggle ──────────────────────────────────────── */
function toggleNavGroup(btn) {
    const group = btn.closest('.nav-group');
    if (group) {
        group.classList.toggle('open');
    }
}

/* ── Section Navigation ────────────────────────────────────── */
function showSection(sectionId, clickedEl) {
    if (typeof clarSectionIsDenied === 'function' && clarSectionIsDenied(sectionId)) {
        sectionId = 'dashboard';
        clickedEl = null;
    }
    // Hide all sections
    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));

    // Show target section
    const target = document.getElementById('section-' + sectionId);
    if (target) target.classList.add('active');

    // Update breadcrumb
    const breadcrumb = document.getElementById('breadcrumbCurrent');
    const currentTitle = sectionTitles[sectionId] || 'Dashboard';
    if (breadcrumb) breadcrumb.textContent = currentTitle;
    document.title = currentTitle + ' | Clarenzon Medical';

    // Update sidebar active states
    document.querySelectorAll('.sidebar-nav .nav-item, .sidebar-nav .nav-subitem').forEach(el => {
        el.classList.remove('active');
    });
    if (clickedEl) {
        clickedEl.classList.add('active');
        // Also mark parent group toggle as active
        const parentGroup = clickedEl.closest('.nav-group');
        if (parentGroup) {
            parentGroup.querySelectorAll('.nav-group-toggle').forEach(t => t.classList.add('active-group'));
        }
    }

    // Close mobile sidebar
    if (window.innerWidth <= 991) closeMobileSidebar();

    // Initialize charts when analytics section is shown
    if (sectionId === 'analytics') initAnalyticsCharts();
    if (sectionId === 'dashboard') initDashboardCharts();
}

/* ── Theme Toggle ──────────────────────────────────────────── */
function toggleTheme() {
    const html = document.documentElement;
    const current = html.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('clarenzon-theme', next);

    // Update toggle icon
    const icon = document.querySelector('#themeToggle i');
    if (icon) {
        icon.className = next === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }

    // Re-render charts with new theme
    destroyAllCharts();
    initDashboardCharts();
}

// Load saved theme
(function() {
    const saved = localStorage.getItem('clarenzon-theme');
    if (saved) {
        document.documentElement.setAttribute('data-theme', saved);
        const icon = document.querySelector('#themeToggle i');
        if (icon) icon.className = saved === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }
})();

/* ── Notifications ─────────────────────────────────────────── */
function toggleNotifications() {
    const dropdown = document.getElementById('notifDropdown');
    if (dropdown) dropdown.classList.toggle('open');
}

// Close notifications when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('notifDropdown');
    if (dropdown && !e.target.closest('.topbar-icon-btn') && !e.target.closest('.notification-dropdown')) {
        dropdown.classList.remove('open');
    }
});

/* ── Global Search ─────────────────────────────────────────── */
function handleGlobalSearch(query) {
    if (!query || query.length < 2) return;
    // Switch to inventory section and filter
    showSection('inventory');
    const searchInput = document.getElementById('productSearchInput');
    if (searchInput) {
        searchInput.value = query;
        applyProductFilters();
    }
}

/* ── Table Filter ──────────────────────────────────────────── */
function filterTable(input, tableId) {
    const filter = input.value.toLowerCase();
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (let row of rows) row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
}

/* ── Product Filters ───────────────────────────────────────── */
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

/* ── Generate Product Name ─────────────────────────────────── */
function generateProductName() {
    const catSelect = document.getElementById('new_category_id');
    const brand = document.getElementById('new_brand');
    const size = document.getElementById('new_size');
    const packaging = document.getElementById('new_packaging');
    const nameField = document.getElementById('new_name');
    if (!catSelect || !brand || !size || !packaging || !nameField) return;

    const catOption = catSelect.options[catSelect.selectedIndex];
    const catName = catOption && catOption.dataset.name ? catOption.dataset.name : '';
    const parts = [catName, brand.value, size.value, packaging.value].filter(p => p.trim() !== '');
    nameField.value = parts.join('_');
}

function generateEditProductName() {
    const category = document.getElementById('edit_category');
    const brand = document.getElementById('edit_brand');
    const size = document.getElementById('edit_size');
    const packaging = document.getElementById('edit_packaging');
    const nameField = document.getElementById('edit_name');
    if (!category || !brand || !size || !packaging || !nameField) return;

    const parts = [category.value, brand.value, size.value, packaging.value].filter(p => p.trim() !== '');
    if (parts.length > 0) nameField.value = parts.join('_');
}

/* ── Modal Functions ───────────────────────────────────────── */
function editProduct(sku, name, category, qty, price, location, cost, uom, supplierId, barcode, expiryDate, brand, size, packaging, reorderLevel) {
    document.getElementById('edit_sku').value = sku;
    document.getElementById('edit_sku_display').value = sku;
    document.getElementById('edit_name').value = name;
    const barcodeEl = document.getElementById('edit_barcode');
    if (barcodeEl) barcodeEl.value = barcode || '';
    document.getElementById('edit_brand').value = brand || '';
    document.getElementById('edit_size').value = size || '';
    document.getElementById('edit_packaging').value = packaging || '';
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_quantity').value = qty;
    document.getElementById('edit_reorder_level').value = reorderLevel || 50;
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

function editSupplier(id, code, name, contact, email, phone, city) {
    document.getElementById('edit_supplier_id_field').value = id;
    document.getElementById('edit_sup_code').value = code;
    document.getElementById('edit_sup_name').value = name;
    document.getElementById('edit_sup_contact').value = contact;
    document.getElementById('edit_sup_email').value = email;
    document.getElementById('edit_sup_phone').value = phone;
    document.getElementById('edit_sup_city').value = city;
    new bootstrap.Modal(document.getElementById('editSupplierModal')).show();
}

function deleteSupplier(id, name) {
    document.getElementById('delete_sup_id').value = id;
    document.getElementById('delete_sup_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteSupplierModal')).show();
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
    if (!trimmed) { alert('Details cannot be empty.'); return; }
    document.getElementById('audit_log_edit_id').value = id;
    document.getElementById('audit_log_edit_details').value = trimmed;
    document.getElementById('auditLogEditForm').submit();
    });
}

/* ── CSV Export ─────────────────────────────────────────────── */
function exportToCSV() {
    const table = document.querySelector('#inventoryReportTable');
    if (!table) return;
    let csv = [];
    for (let row of table.querySelectorAll('tr')) {
        let rowData = [];
        for (let col of row.querySelectorAll('td,th'))
            rowData.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
        csv.push(rowData.join(','));
    }
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'inventory_report_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

/* ═══════════════════════════════════════════════════════════════
   CHARTS
   ═══════════════════════════════════════════════════════════════ */
const chartInstances = {};

function getChartColors() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    return {
        text: isDark ? '#CBD5E1' : '#475569',
        grid: isDark ? '#334155' : '#F1F5F9',
        palette: ['#0EA5E9', '#10B981', '#F59E0B', '#F43F5E', '#8B5CF6', '#06B6D4', '#EC4899', '#14B8A6', '#F97316', '#6366F1']
    };
}

function destroyAllCharts() {
    Object.keys(chartInstances).forEach(key => {
        if (chartInstances[key]) {
            chartInstances[key].destroy();
            chartInstances[key] = null;
        }
    });
}

function initDashboardCharts() {
    const colors = getChartColors();

    // Category Bar Chart
    const catCtx = document.getElementById('categoryBarChart');
    if (catCtx && !chartInstances.categoryBar) {
        chartInstances.categoryBar = new Chart(catCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(categoryStats),
                datasets: [{
                    label: 'Total Quantity',
                    data: Object.values(categoryStats).map(s => s.quantity),
                    backgroundColor: colors.palette.slice(0, Object.keys(categoryStats).length),
                    borderRadius: 8,
                    borderSkipped: false,
                    barThickness: 32
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.9)',
                        titleColor: '#F1F5F9',
                        bodyColor: '#CBD5E1',
                        cornerRadius: 8,
                        padding: 12
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.grid },
                        ticks: { color: colors.text, font: { size: 11 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: colors.text,
                            font: { size: 11 },
                            maxRotation: 45,
                            minRotation: 0,
                            callback: function(value) {
                                const label = this.getLabelForValue(value);
                                return label.length > 12 ? label.substring(0, 12) + '…' : label;
                            }
                        }
                    }
                }
            }
        });
    }

    // Stock Distribution Doughnut
    const stockCtx = document.getElementById('stockDoughnutChart');
    if (stockCtx && !chartInstances.stockDoughnut) {
        let inStock = 0, lowStock = 0, outOfStock = 0;
        inventoryItems.forEach(item => {
            if (item.qty <= 0) outOfStock++;
            else if (item.qty <= (item.reorder_level || 50)) lowStock++;
            else inStock++;
        });

        chartInstances.stockDoughnut = new Chart(stockCtx, {
            type: 'doughnut',
            data: {
                labels: ['In Stock', 'Low Stock', 'Out of Stock'],
                datasets: [{
                    data: [inStock, lowStock, outOfStock],
                    backgroundColor: ['#10B981', '#F59E0B', '#F43F5E'],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: colors.text, padding: 16, usePointStyle: true, pointStyleWidth: 10 }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.9)',
                        cornerRadius: 8,
                        padding: 12
                    }
                }
            }
        });
    }
}

function initAnalyticsCharts() {
    const colors = getChartColors();

    // 1. Stock Trend Line Chart
    const trendCtx = document.getElementById('stockTrendChart');
    if (trendCtx && !chartInstances.stockTrend) {
        chartInstances.stockTrend = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
                datasets: [
                    {
                        label: 'Stock In',
                        data: [0, 0, 0, 0, 0, 0, 0],
                        borderColor: '#10B981',
                        backgroundColor: 'rgba(16,185,129,0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2.5,
                        pointRadius: 4,
                        pointBackgroundColor: '#10B981'
                    },
                    {
                        label: 'Stock Out',
                        data: [0, 0, 0, 0, 0, 0, 0],
                        borderColor: '#F43F5E',
                        backgroundColor: 'rgba(244,63,94,0.08)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2.5,
                        pointRadius: 4,
                        pointBackgroundColor: '#F43F5E'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { color: colors.text, usePointStyle: true } },
                    tooltip: { backgroundColor: 'rgba(15,23,42,0.9)', cornerRadius: 8 }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: colors.grid }, ticks: { color: colors.text } },
                    x: { grid: { display: false }, ticks: { color: colors.text } }
                }
            }
        });
    }

    // 3. Movement Bar Chart
    const movCtx = document.getElementById('movementBarChart');
    if (movCtx && !chartInstances.movementBar) {
        chartInstances.movementBar = new Chart(movCtx, {
            type: 'bar',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [
                    {
                        label: 'Stock In',
                        data: [0, 0, 0, 0, 0, 0, 0],
                        backgroundColor: '#10B981',
                        borderRadius: 6,
                        barPercentage: 0.6
                    },
                    {
                        label: 'Stock Out',
                        data: [0, 0, 0, 0, 0, 0, 0],
                        backgroundColor: '#F43F5E',
                        borderRadius: 6,
                        barPercentage: 0.6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { color: colors.text, usePointStyle: true } },
                    tooltip: { backgroundColor: 'rgba(15,23,42,0.9)', cornerRadius: 8 }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: colors.grid }, ticks: { color: colors.text } },
                    x: { grid: { display: false }, ticks: { color: colors.text } }
                }
            }
        });
    }

    // 4. Value Pie Chart
    const pieCtx = document.getElementById('valuePieChart');
    if (pieCtx && !chartInstances.valuePie) {
        chartInstances.valuePie = new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(categoryStats || {}),
                datasets: [{
                    data: Object.values(categoryStats || {}).map(s => s.value || 0),
                    backgroundColor: colors.palette,
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'right', labels: { color: colors.text, padding: 12, usePointStyle: true } },
                    tooltip: { backgroundColor: 'rgba(15,23,42,0.9)', cornerRadius: 8 }
                }
            }
        });
    }

    // Fetch real-time analytics data from MariaDB database
    fetch('inventory_api.php?action=get_analytics_data')
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data) {
                const d = res.data;
                
                // Update Monthly Stock Trend
                if (chartInstances.stockTrend && d.monthly) {
                    chartInstances.stockTrend.data.labels = d.monthly.labels;
                    chartInstances.stockTrend.data.datasets[0].data = d.monthly.in;
                    chartInstances.stockTrend.data.datasets[1].data = d.monthly.out;
                    chartInstances.stockTrend.update();
                }
                
                // Update Weekly Inventory Movement
                if (chartInstances.movementBar && d.daily) {
                    chartInstances.movementBar.data.labels = d.daily.labels;
                    chartInstances.movementBar.data.datasets[0].data = d.daily.in;
                    chartInstances.movementBar.data.datasets[1].data = d.daily.out;
                    chartInstances.movementBar.update();
                }
                
                // Update Category Value Pie Chart
                if (chartInstances.valuePie && d.category) {
                    chartInstances.valuePie.data.labels = d.category.labels;
                    chartInstances.valuePie.data.datasets[0].data = d.category.values;
                    chartInstances.valuePie.update();
                }

            }
        })
        .catch(err => console.error("Failed to load real-time analytics:", err));
}

/* ── Counter Animation ─────────────────────────────────────── */
function animateCounters() {
    document.querySelectorAll('.stat-card-value').forEach(el => {
        const text = el.textContent;
        const match = text.match(/[\d,]+/);
        if (!match) return;
        const target = parseInt(match[0].replace(/,/g, ''), 10);
        if (isNaN(target) || target === 0) return;

        const prefix = text.substring(0, text.indexOf(match[0]));
        const suffix = text.substring(text.indexOf(match[0]) + match[0].length);
        let current = 0;
        const step = Math.max(1, Math.floor(target / 40));
        const timer = setInterval(() => {
            current += step;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            el.textContent = prefix + current.toLocaleString() + suffix;
        }, 25);
    });
}

/* ── Auto-dismiss success alerts ───────────────────────────── */
function autoDismissAlerts() {
    document.querySelectorAll('.alert-success').forEach(el => {
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s ease';
            setTimeout(() => el.remove(), 300);
        }, 4000);
    });
}

/* ── Initialize on DOM Ready ───────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    initDashboardCharts();
    animateCounters();
    autoDismissAlerts();

    // Bootstrap tab pill change handlers
    document.querySelectorAll('[data-bs-toggle="pill"]').forEach(function(btn) {
        btn.addEventListener('shown.bs.tab', function(e) {});
    });
});
