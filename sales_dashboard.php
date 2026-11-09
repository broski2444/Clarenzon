<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once 'config.php';
require_once 'route_protection.php';
require_once 'notifications_api.php';
require_once __DIR__ . '/product_pricing_lib.php';

// Sales reps and admins may use this dashboard
$__salesRole = normalize_user_role((string)($_SESSION['role'] ?? ''));
if (!in_array($__salesRole, ['sales_representative', 'sales', 'sales_rep', 'sales_marketing', 'admin'], true)) {
    route_require_login(['sales_representative', 'sales_marketing']);
} elseif (empty($_SESSION['user_id'])) {
    route_redirect('login.php');
} else {
    route_reject_inactive_account();
}
unset($__salesRole);

$username = $_SESSION['username'];
$full_name = $_SESSION['full_name'];
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? 'sales_representative');

// Database connection
$conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db']);

if (!$conn) {
    die("Database connection failed.");
}

ensureProductPricingSchema($conn);
$canManagePricing = userCanManageProductPricing($conn, $userId, $role);

$alert = '';
require_once __DIR__ . '/billing_api.php';
if (!empty($billing_alert)) {
    $alert = $billing_alert;
}

// Fetch general products list for the dynamic order form
$dbProducts = [];
$productOptionsHtml = '';
$allocated_stocks = isset($_SESSION['allocated_stocks']) ? explode(',', $_SESSION['allocated_stocks']) : [];

$pQuery = $conn->query("SELECT p.id, p.sku, p.name, c.name AS category_name, p.quantity_in_stock, p.selling_price, p.unit_of_measure FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status != 'Discontinued' ORDER BY p.name");
if ($pQuery) {
    while ($row = $pQuery->fetch_assoc()) {
        $dbProducts[] = $row;
        // Build html options
        $productOptionsHtml .= '<option value="' . (int)$row['id'] . '" data-price="' . (float)$row['selling_price'] . '" data-stock="' . (int)$row['quantity_in_stock'] . '">' . htmlspecialchars($row['name']) . ' (' . htmlspecialchars($row['sku']) . ') - ₱' . number_format($row['selling_price'], 2) . '</option>';
    }
}

// Map database format to template format for inventory views
$all_inventory = [];
foreach ($dbProducts as $row) {
    $all_inventory[] = [
        $row['sku'],
        $row['name'],
        $row['category_name'] ?? 'Uncategorized',
        $row['quantity_in_stock'],
        $row['selling_price']
    ];
}

$my_stocks = array_filter($all_inventory, function($item) use ($allocated_stocks) {
    return in_array($item[0], $allocated_stocks);
});

$my_orders = salesOrderLoadList($conn, $userId);
$sales_credit_agreements = salesOrderLoadAgreements($conn, $userId);
$salesTaxRate = billingGetTaxRate($conn);

$my_invoices = [];
$pendingCheques = [];
if ($conn instanceof mysqli) {
    $invStmt = $conn->prepare("SELECT i.* FROM invoices i INNER JOIN sales_orders so ON so.id = i.sales_order_id WHERE so.created_by = ? ORDER BY i.created_at DESC");
    if ($invStmt) {
        $invStmt->bind_param('i', $userId);
        $invStmt->execute();
        $invRes = $invStmt->get_result();
        while ($invRes && ($row = $invRes->fetch_assoc())) {
            $my_invoices[] = $row;
        }
        $invStmt->close();
    }
    billingAttachPaymentMetaBatch($conn, $my_invoices);
    $allPending = billingLoadPendingCheques($conn);
    $myInvoiceIds = [];
    foreach ($my_invoices as $inv) {
        $myInvoiceIds[(int)$inv['id']] = true;
    }
    foreach ($allPending as $ch) {
        if (!empty($myInvoiceIds[(int)($ch['invoice_id'] ?? 0)])) {
            $pendingCheques[] = $ch;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title>Sales Orders | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-theme.css?v=<?= @filemtime(__DIR__ . '/css/admin-theme.css') ?: time() ?>">
    <link rel="stylesheet" href="css/notifications.css">
    <link rel="stylesheet" href="css/popup-center.css?v=<?= @filemtime(__DIR__ . '/css/popup-center.css') ?: time() ?>">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #F7F8FA; color: #2D3748; }
        .sidebar { position: fixed; left: 0; top: 0; width: 240px; height: 100vh; background: white; border-right: 1px solid #E5E7EB; z-index: 1000; display: flex; flex-direction: column; }
        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid #E5E7EB; }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-icon { width: 36px; height: 36px; background: #4F46E5; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 16px; }
        .logo-text { font-size: 15px; font-weight: 700; color: #111827; line-height: 1.2; }
        .logo-subtitle { font-size: 11px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .sidebar-nav { flex: 1; padding: 20px 12px; overflow-y: auto; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; margin-bottom: 4px; border-radius: 8px; color: #6B7280; text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; cursor: pointer; }
        .nav-item:hover { background: #F3F4F6; color: #111827; }
        .nav-item.active { background: #EEF2FF; color: #4F46E5; }
        .nav-item i { font-size: 18px; width: 20px; text-align: center; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #E5E7EB; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px; }
        .user-info { flex: 1; }
        .user-name { font-size: 13px; font-weight: 600; color: #111827; line-height: 1.2; }
        .user-role { font-size: 11px; color: #6B7280; }
        .main-content { margin-left: 240px; min-height: 100vh; }
        .top-bar { background: white; border-bottom: 1px solid #E5E7EB; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 20px; font-weight: 700; color: #111827; }
        .search-box { position: relative; width: 320px; }
        .search-box input { width: 100%; padding: 8px 16px 8px 40px; border: 1px solid #E5E7EB; border-radius: 8px; font-size: 14px; background: #F9FAFB; }
        .search-box input:focus { outline: none; border-color: #4F46E5; background: white; }
        .search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9CA3AF; font-size: 16px; }
        .content-area { padding: 32px; }
        .content-section { display: none; }
        .content-section.active { display: block; }
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 20px; }
        .stat-label { font-size: 13px; color: #6B7280; font-weight: 500; margin-bottom: 8px; }
        .stat-value { font-size: 32px; font-weight: 700; color: #111827; line-height: 1; }
        .stat-change { font-size: 12px; color: #10B981; margin-top: 8px; }
        .card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .card-title { font-size: 16px; font-weight: 600; color: #111827; margin-bottom: 20px; }
        .table-container { overflow-x: auto; }
        .modern-table { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; }
        .modern-table thead th { background: #F9FAFB; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #E5E7EB; }
        .modern-table tbody td { padding: 16px; border-bottom: 1px solid #F3F4F6; font-size: 14px; color: #374151; }
        .modern-table tbody tr:hover { background: #F9FAFB; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; }
        .badge-blue { background: #EEF2FF; color: #4F46E5; }
        .badge-green { background: #D1FAE5; color: #059669; }
        .badge-purple { background: #F3E8FF; color: #7C3AED; }
        .badge-warning { background: #FFFBEB; color: #D97706; }
        .badge-danger { background: #FEE2E2; color: #EF4444; }
        .logout-btn { width: 32px; height: 32px; border-radius: 6px; border: 1px solid #E5E7EB; background: white; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #EF4444; transition: all 0.2s; }
        .logout-btn:hover { background: #FEE2E2; }
        .alert-info { background: #DBEAFE; border: 1px solid #BFDBFE; color: #1E40AF; padding: 16px; border-radius: 8px; margin-bottom: 24px; }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:5000; align-items:center; justify-content:center; padding:12px; overflow-y:auto; }
        .modal-overlay.open { display:flex; }
        .modal-overlay .modal { background:#fff; border-radius:16px; width:100%; max-width:620px; max-height:min(92vh, 900px); overflow:auto; display:flex; flex-direction:column; box-shadow:0 20px 40px rgba(0,0,0,0.2); margin:auto; padding:0; }
        .modal-overlay .modal.modal-lg { max-width:min(1100px, 96vw); }
        .modal-overlay .modal-header, .modal-overlay .modal-footer { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #e5e7eb; }
        .modal-overlay .modal-footer { border-bottom:0; border-top:1px solid #e5e7eb; }
        .modal-overlay .modal-body { padding:18px; }
        .modal-close { border:0; background:transparent; font-size:20px; }
        @media (max-width: 991px) {
            body { overflow-x: hidden; }
            .sidebar { width: 72px; }
            .sidebar .logo-text, .sidebar .logo-subtitle, .sidebar .user-info, .sidebar .nav-item span { display: none; }
            .sidebar-header, .sidebar-footer { padding: 14px; }
            .nav-item { justify-content: center; padding: 11px 8px; }
            .main-content { margin-left: 72px !important; }
            .top-bar, .card-header, .page-header, .d-flex { flex-wrap: wrap; gap: 10px; }
            .content-area { padding: 18px; }
            .search-box { width: 100%; max-width: 100%; }
            .stats-row { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
            .card { padding: 18px; }
            .table-container { max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .modern-table { width: max-content; min-width: 100%; }
            .row > [class*="col-"], [class*="col-md-"], [class*="col-lg-"] { width: 100% !important; max-width: 100% !important; }
            [style*="width: 180px"], [style*="width: 150px"], [style*="width: 130px"] { width: 100% !important; max-width: 100% !important; }
            input, select, textarea, button { max-width: 100%; }
        }
        @media (max-width: 520px) {
            .top-bar { padding: 12px 14px; align-items: flex-start; }
            .page-title { font-size: 17px; }
            .content-area { padding: 14px; }
            .card { padding: 14px; }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon"><i class="bi bi-box-seam"></i></div>
            <div>
                <div class="logo-text">INVENTORY</div>
                <div class="logo-subtitle">MANAGEMENT</div>
            </div>
        </div>
    </div>
    
    <div class="sidebar-nav">
        <a class="nav-item active" onclick="showSection('dashboard', this)">
            <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
        </a>
        <a class="nav-item" onclick="showSection('my-stocks', this)">
            <i class="bi bi-star"></i><span>My Allocated Stocks</span>
        </a>
        <a class="nav-item" onclick="showSection('all-stocks', this)">
            <i class="bi bi-box"></i><span>All Stock Levels</span>
        </a>
        <a class="nav-item" onclick="showSection('sales-orders', this)">
            <i class="bi bi-receipt"></i><span>Sales Orders</span>
        </a>
        <a class="nav-item" onclick="showSection('sales-credit', this)">
            <i class="bi bi-file-earmark-text"></i><span>Credit Agreements</span>
        </a>
        <a class="nav-item" onclick="showSection('unpaid-invoices', this)">
            <i class="bi bi-wallet2"></i><span>Accounts Receivable / Pending</span>
        </a>
        <a class="nav-item" onclick="showSection('warranties', this); if (typeof wcInitSection==='function') wcInitSection();">
            <i class="bi bi-shield-check"></i><span>Warranty Certificates</span>
        </a>
        <a class="nav-item" onclick="showSection('product-pricing', this); if (typeof loadProductPricingTable==='function') loadProductPricingTable(); if (typeof loadPendingReceiptMarkup==='function') loadPendingReceiptMarkup();">
            <i class="bi bi-tags"></i><span>Product Pricing</span>
        </a>
        <a class="nav-item" onclick="showSection('sales', this)">
            <i class="bi bi-graph-up"></i><span>Sales Activity</span>
        </a>
        <a class="nav-item" onclick="showSection('notifications', this); loadNotificationsHistory();">
            <i class="bi bi-bell-fill"></i><span>Alerts & History</span>
        </a>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="user-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="user-role">Sales Rep</div>
            </div>
            <a href="?logout=1" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">Sales Representative Dashboard</div>
        <div class="d-flex align-items-center gap-3">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" placeholder="Search..." id="searchInput" onkeyup="searchTable()">
            </div>
            <div style="position:relative;">
                <button class="topbar-icon-btn" onclick="toggleNotifications()" title="Notifications">
                    <i class="bi bi-bell-fill"></i>
                    <span class="topbar-badge" id="notifBadge" style="display:none;">0</span>
                </button>
                <div class="notification-dropdown" id="notifDropdown">
                    <div class="notification-dropdown-header">
                        <h4><i class="bi bi-bell-fill me-2"></i>Notifications</h4>
                        <button onclick="markAllNotificationsAsRead()">Mark all as read</button>
                    </div>
                    <ul class="notification-list-menu" id="notifDropdownList">
                        <li style="padding: 12px; text-align:center; color:#9CA3AF;">Loading...</li>
                    </ul>
                    <div style="border-top:1px solid #E5E7EB; padding-top:10px; margin-top:10px; text-align:center;">
                        <button class="btn btn-sm btn-primary w-100" onclick="showSection('notifications')" style="font-size:11px; padding: 4px 8px;">View All History</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?= $alert ?>
        
        <div id="section-dashboard" class="content-section active">
            <div class="alert-info">
                <i class="bi bi-star-fill"></i> <strong>Your Allocated Stocks:</strong> <?= empty($allocated_stocks) ? 'None' : implode(', ', $allocated_stocks) ?>
            </div>
            
            <div class="stats-row">
                <div class="stat-card" style="cursor:pointer;" onclick="showSection('my-stocks')">
                    <div class="stat-label">My Allocated Products</div>
                    <div class="stat-value"><?= count($my_stocks) ?></div>
                    <div class="stat-change"><i class="bi bi-star"></i> Assigned to me</div>
                </div>
                <div class="stat-card" style="cursor:pointer;" onclick="showSection('all-stocks')">
                    <div class="stat-label">Total Products</div>
                    <div class="stat-value"><?= count($all_inventory) ?></div>
                    <div class="stat-change"><i class="bi bi-box"></i> In catalog</div>
                </div>
                <div class="stat-card" style="cursor:pointer;" onclick="showSection('sales-orders')">
                    <div class="stat-label">My Pending Orders</div>
                    <div class="stat-value">
                        <?php 
                        $pendingCount = 0;
                        foreach($my_orders as $o) if($o['status'] === 'Pending') $pendingCount++;
                        echo $pendingCount;
                        ?>
                    </div>
                    <div class="stat-change" style="color:#D97706;"><i class="bi bi-clock-history"></i> Awaiting approval</div>
                </div>
            </div>
        </div>
        
        <div id="section-my-stocks" class="content-section">
            <div class="card">
                <div class="card-title">My Allocated Stock - Priority View</div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Available Stock</th>
                                <th>Unit Price</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(count($my_stocks) > 0): ?>
                            <?php foreach($my_stocks as $item): ?>
                                <tr style="background: #F0FDF4;">
                                    <td><span class="badge badge-purple"><?= htmlspecialchars($item[0]) ?></span></td>
                                    <td><strong><?= htmlspecialchars($item[1]) ?></strong></td>
                                    <td><?= htmlspecialchars($item[2]) ?></td>
                                    <td><span class="badge badge-green"><?= $item[3] ?> units</span></td>
                                    <td>₱<?= number_format($item[4], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">No stocks currently allocated to you</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="section-all-stocks" class="content-section">
            <div class="card">
                <div class="card-title">All Stock Levels - Full Inventory View</div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table" id="allStocksTable">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Stock on Hand</th>
                                <th>Unit Price</th>
                                <th>Allocation</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($all_inventory as $item): ?>
                            <?php $is_allocated = in_array($item[0], $allocated_stocks); ?>
                            <tr <?= $is_allocated ? 'style="background: #F0FDF4;"' : '' ?>>
                                <td><span class="badge <?= $is_allocated ? 'badge-purple' : 'badge-blue' ?>"><?= htmlspecialchars($item[0]) ?></span></td>
                                <td><?= htmlspecialchars($item[1]) ?></td>
                                <td><?= htmlspecialchars($item[2]) ?></td>
                                <td><?= $item[3] ?> units</td>
                                <td>₱<?= number_format($item[4], 2) ?></td>
                                <td>
                                    <?php if($is_allocated): ?>
                                        <span class="badge badge-purple"><i class="bi bi-star-fill"></i> My Stock</span>
                                    <?php else: ?>
                                        <span class="badge badge-blue">General</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="section-product-pricing" class="content-section">
            <div class="card mb-3">
                <div class="card-title d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-hourglass-split me-2"></i>Received — Pending Markup</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadPendingReceiptMarkup()"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>PO #</th>
                                <th>Supplier</th>
                                <th>Good</th>
                                <th>Damaged</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="pendingReceiptMarkupBody">
                            <tr><td colspan="7" class="text-center text-muted">Open this section to load…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-title d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-tags me-2"></i>Product Selling Prices</span>
                    <div class="d-flex gap-2" style="width: min(100%, 320px);">
                        <input type="text" id="pricingSearchInput" class="form-control form-control-sm" placeholder="Search product / SKU..." onkeyup="filterProductPricingTable()">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadProductPricingTable()"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table" id="productPricingTable">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>SKU</th>
                                <th>Current Stock</th>
                                <th>Cost Price</th>
                                <th>Markup %</th>
                                <th>Selling Price</th>
                                <th>Last Updated</th>
                                <th>Updated By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productPricingTbody">
                            <tr><td colspan="9" class="text-center text-muted">Loading pricing…</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-2 mb-0">Selling Price = Cost Price + (Cost Price × Markup %). <?= !empty($canManagePricing) ? 'You can set or update selling prices.' : 'View only — you do not have permission to edit prices.' ?></p>
            </div>
        </div>

        <div id="section-sales-orders" class="content-section">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-title"><i class="bi bi-plus-circle me-2 text-primary-color"></i>Sales Order</div>
                        <form method="POST" id="salesOrderForm" onsubmit="return validateSdSalesOrder()">
                            <div class="mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" name="customer_name" class="form-control" placeholder="e.g. Metro Medical Center" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Payment Due Date <span class="text-danger">*</span></label>
                                <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                            </div>

                            <h6 class="mt-3 mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span>1. Product</span>
                                <span class="d-flex gap-2">
                                    <input type="text" id="sdBarcodeScan" class="form-control form-control-sm" placeholder="Scan barcode — each scan +1 qty" autocomplete="off" style="min-width:200px;">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="submitSalesOrderBarcode()"><i class="bi bi-upc-scan"></i></button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addOrderRow()"><i class="bi bi-plus"></i> Add Item</button>
                                </span>
                            </h6>
                            <div class="table-container system-table-scroll mb-3" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm align-middle" style="font-size: 13px;">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th style="width: 80px;">Qty</th>
                                            <th style="width: 110px;">Price</th>
                                            <th style="width: 110px;">Total</th>
                                            <th style="width: 40px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="orderItemsTbody"></tbody>
                                </table>
                            </div>
                            <p class="form-text">Scan a barcode to add the product. Scanning the same item again increases quantity by 1. Qty cannot exceed available stock.</p>

                            <div class="mb-3">
                                <label class="form-label">2. Payment Method</label>
                                <select name="sale_type" id="sdSaleType" class="form-select" onchange="toggleSdChequeFields()">
                                    <option value="Cash">Cash</option>
                                    <option value="GCash">GCash</option>
                                    <option value="Maya">Maya</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Credit">Credit / Accounts Receivable</option>
                                </select>
                            </div>
                            <div class="row g-2 mb-3" id="sdCashFields">
                                <div class="col-md-6">
                                    <label class="form-label" for="sdCashReceived">Cash Received</label>
                                    <input type="number" name="cash_tendered" id="sdCashReceived" class="form-control" min="0" step="0.01" inputmode="decimal" oninput="soUpdateCashChange()">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="sdCashChange">Change</label>
                                    <input type="text" id="sdCashChange" class="form-control" value="₱0.00" readonly tabindex="-1">
                                </div>
                            </div>
                            <div id="sdChequeFields" class="mb-3" style="display:none">
                                <div class="alert alert-warning py-2 small">Cheque sales stay <strong>Pending</strong> until the cheque is marked Paid. Inventory is not deducted until then.</div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Cheque Number <span class="text-danger">*</span></label>
                                        <input type="text" name="cheque_number" id="sdChequeNumber" class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                                        <?php billingBankNameField('sdBankName', 'bank_name'); ?>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Cheque Date <span class="text-danger">*</span></label>
                                        <input type="date" name="cheque_date" id="sdChequeDate" class="form-control">
                                    </div>
                                </div>
                            </div>
                            <div id="sdGcashFields" class="mb-3" style="display:none">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdGcashAccountName">GCash Account Name <span class="text-danger">*</span></label>
                                        <input type="text" name="gcash_account_name" id="sdGcashAccountName" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdGcashAccountName')">
                                        <div class="invalid-feedback" id="sdGcashAccountNameError">GCash Account Name is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdGcashAccountNumber">GCash Account Number <span class="text-danger">*</span></label>
                                        <input type="text" name="gcash_account_number" id="sdGcashAccountNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdGcashAccountNumber')">
                                        <div class="invalid-feedback" id="sdGcashAccountNumberError">GCash Account Number is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdGcashReferenceNumber">GCash Reference Number <span class="text-danger">*</span></label>
                                        <input type="text" name="gcash_reference_number" id="sdGcashReferenceNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdGcashReferenceNumber')">
                                        <div class="invalid-feedback" id="sdGcashReferenceNumberError">GCash Reference Number is required.</div>
                                    </div>
                                </div>
                            </div>
                            <div id="sdMayaFields" class="mb-3" style="display:none">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdMayaAccountName">Maya Account Name <span class="text-danger">*</span></label>
                                        <input type="text" name="maya_account_name" id="sdMayaAccountName" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdMayaAccountName')">
                                        <div class="invalid-feedback" id="sdMayaAccountNameError">Maya Account Name is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdMayaAccountNumber">Maya Account Number <span class="text-danger">*</span></label>
                                        <input type="text" name="maya_account_number" id="sdMayaAccountNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdMayaAccountNumber')">
                                        <div class="invalid-feedback" id="sdMayaAccountNumberError">Maya Account Number is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdMayaReferenceNumber">Maya Reference Number <span class="text-danger">*</span></label>
                                        <input type="text" name="maya_reference_number" id="sdMayaReferenceNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdMayaReferenceNumber')">
                                        <div class="invalid-feedback" id="sdMayaReferenceNumberError">Maya Reference Number is required.</div>
                                    </div>
                                </div>
                            </div>
                            <div id="sdBankTransferFields" class="mb-3" style="display:none">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdBtBankName">Bank Name <span class="text-danger">*</span></label>
                                        <?php billingBankNameField('sdBtBankName', 'bt_bank_name', 'autocomplete="off" oninput="if(typeof soClearPayFieldError===\'function\')soClearPayFieldError(\'sdBtBankName\')" onchange="if(typeof soClearPayFieldError===\'function\')soClearPayFieldError(\'sdBtBankName\')"'); ?>
                                        <div class="invalid-feedback" id="sdBtBankNameError">Bank Name is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdBtSenderName">Sender / Account Name <span class="text-danger">*</span></label>
                                        <input type="text" name="bt_sender_name" id="sdBtSenderName" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdBtSenderName')">
                                        <div class="invalid-feedback" id="sdBtSenderNameError">Sender / Account Name is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdBtSenderAccount">Sender Account Number</label>
                                        <input type="text" name="bt_sender_account" id="sdBtSenderAccount" class="form-control" autocomplete="off" placeholder="Optional">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdBtReference">Reference / Transaction Number <span class="text-danger">*</span></label>
                                        <input type="text" name="bt_reference" id="sdBtReference" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdBtReference')">
                                        <div class="invalid-feedback" id="sdBtReferenceError">Reference / Transaction Number is required.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="sdBtDate">Transfer Date <span class="text-danger">*</span></label>
                                        <input type="date" name="bt_transfer_date" id="sdBtDate" class="form-control" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdBtDate')" onchange="if(typeof soClearPayFieldError==='function')soClearPayFieldError('sdBtDate')">
                                        <div class="invalid-feedback" id="sdBtDateError">Transfer Date is required.</div>
                                    </div>
                                </div>
                            </div>
                            <div id="sdCreditFields" class="mb-3" style="display:none">
                                <div class="alert alert-info py-2 small">A credit agreement/contract is generated. Outstanding balance stays on this sales order until settled.</div>
                                <label class="form-label">Credit terms <span class="text-danger">*</span></label>
                                <textarea name="credit_terms" id="soCreditTerms" class="form-control" rows="3"></textarea>
                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="sdPrintCreditAgreementBtn" onclick="printPosCreditAgreementPreview()">Preview / Print Credit Agreement</button>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="credit_ack" id="soCreditAck" value="1">
                                    <label class="form-check-label" for="soCreditAck">Generate credit agreement/contract</label>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Discount (%)</label>
                                    <input type="number" id="discountPercent" name="discount_percent" class="form-control" min="0" max="100" value="0" oninput="calculateTotals()">
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <p class="form-text mb-2">Total Amount = Subtotal − Discount + Tax. Tax updates when products, qty, price, or discount change.</p>
                                </div>
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted">Subtotal:</span>
                                <strong id="lblSubtotal">₱0.00</strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted">Discount:</span>
                                <strong id="lblDiscount" class="text-danger">₱0.00</strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted">Tax:</span>
                                <strong id="lblTax">₱0.00</strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted">Delivery Fee:</span>
                                <strong id="lblDeliveryFee">₱0.00</strong>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1">Delivery Fee</label>
                                <input type="number" id="deliveryFee" name="delivery_fee" class="form-control form-control-sm" min="0" step="0.01" value="0" oninput="calculateTotals()">
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-dark font-weight-bold">Total Amount:</span>
                                <h5 id="lblGrandTotal" class="text-primary font-weight-bold mb-0">₱0.00</h5>
                            </div>

                            <button type="submit" name="create_sales_order" class="btn btn-primary w-100">Submit Sale</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-title d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span>My Sales Orders</span>
                            <?= billingSaleCategoryTabsHtml('orders') ?>
                            <input type="hidden" id="soTypeFilter" value="">
                        </div>
                        <div class="table-container system-table-scroll">
                            <table class="modern-table" id="salesOrdersTable">
                                <thead>
                                    <tr>
                                        <th>SO Number</th>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th>Total Amount</th>
                                        <th>Payment</th>
                                        <th>Outstanding</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php salesOrderRenderListRows($my_orders, ['show_creator' => false, 'can_manage' => true]); ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php salesOrderRenderCreditSection($sales_credit_agreements ?? []); ?>

        <div id="section-unpaid-invoices" class="content-section">
            <div class="card mb-3">
                <div class="card-title">Accounts Receivable / Pending</div>
            </div>
            <?php billingRenderPendingChequesCard($pendingCheques ?? [], false, false); ?>
            <?php creditRenderOverdueSection(array_map(function ($inv) { return (int)$inv['id']; }, $my_invoices)); ?>
            <div class="card">
                <div class="card-title">Outstanding Credit / Utang</div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Invoice Number</th>
                                <th>Customer</th>
                                <th>Amount Owed</th>
                                <th>Payment Method</th>
                                <th>Transaction Date</th>
                                <th>Type</th>
                                <th>Payment Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $unpaidCount = 0;
                        foreach ($my_invoices as $inv):
                            if (!billingIsOutstandingInvoice($inv)) continue;
                            $unpaidCount++;
                            $txDate = !empty($inv['created_at']) ? date('M j, Y', strtotime((string)$inv['created_at'])) : '—';
                        ?>
                            <tr <?= billingInvoiceRowAttrs($inv) ?>>
                                <td><span class="badge badge-blue"><?= htmlspecialchars($inv['invoice_number']) ?></span></td>
                                <td><strong><?= htmlspecialchars($inv['customer_name']) ?></strong></td>
                                <td><strong class="text-danger">₱<?= number_format((float)$inv['remaining_balance'], 2) ?></strong></td>
                                <td><?= htmlspecialchars($inv['payment_method_label'] ?? billingPaymentMethodLabel($inv)) ?></td>
                                <td><?= htmlspecialchars($txDate) ?></td>
                                <td><?= htmlspecialchars(billingSaleTypeLabel($inv['sale_type'] ?? 'Cash')) ?></td>
                                <td><?= billingPaymentStatusBadgeHtml($inv) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($unpaidCount === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center">No outstanding credit or pending balances.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php require __DIR__ . '/includes/ui_warranty_section.php'; ?>

        <!-- ═══════════ NOTIFICATIONS SECTION ═══════════ -->
        <div id="section-notifications" class="content-section">
            <div class="card">
                <div class="card-header" style="flex-wrap: wrap; gap: 16px;">
                    <div class="card-title">System Audit Alerts Log</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <select id="histTypeFilter" class="form-select form-select-sm" style="width: 150px;" onchange="loadNotificationsHistory()">
                            <option value="">All Types</option>
                            <option value="low_stock">Low Stock</option>
                            <option value="near_expiry">Near Expiry</option>
                            <option value="order_status">Order Status</option>
                            <option value="pending_approval">Pending Approval</option>
                        </select>
                        <input type="date" id="histDateFrom" class="form-control form-control-sm" style="width: 130px;" onchange="loadNotificationsHistory()">
                        <input type="date" id="histDateTo" class="form-control form-control-sm" style="width: 130px;" onchange="loadNotificationsHistory()">
                        <input type="text" id="histSearchInput" class="form-control form-control-sm" style="width: 180px;" placeholder="Search log message..." onkeyup="loadNotificationsHistory()">
                        <button class="btn btn-sm btn-outline-secondary" onclick="markAllNotificationsAsRead()"><i class="bi bi-check-all"></i> Mark All Read</button>
                    </div>
                </div>

                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Alert Type</th>
                                <th>Notification Detail</th>
                                <th>Priority</th>
                                <th>Timestamp</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="notifHistoryTbody">
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Click notifications to trigger loading history logs.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="section-sales" class="content-section">
            <div class="card">
                <div class="card-title">Sales Activity</div>
                <div class="stats-row" style="margin-bottom: 20px;">
                    <div class="stat-card">
                        <div class="stat-label">Total Orders</div>
                        <div class="stat-value"><?= count($my_orders) ?></div>
                        <div class="stat-change"><i class="bi bi-receipt"></i> Created by you</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Approved Orders</div>
                        <div class="stat-value"><?= count(array_filter($my_orders, fn($o) => $o['status'] === 'Approved')) ?></div>
                        <div class="stat-change" style="color:#059669;"><i class="bi bi-check-circle"></i> Ready for billing</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Pending Orders</div>
                        <div class="stat-value"><?= count(array_filter($my_orders, fn($o) => $o['status'] === 'Pending')) ?></div>
                        <div class="stat-change" style="color:#D97706;"><i class="bi bi-hourglass-split"></i> Awaiting approval</div>
                    </div>
                </div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>SO Number</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(count($my_orders) > 0): ?>
                            <?php foreach(array_slice($my_orders, 0, 10) as $ord): ?>
                                <tr>
                                    <td><span class="badge badge-purple"><?= htmlspecialchars($ord['order_number']) ?></span></td>
                                    <td><strong><?= htmlspecialchars($ord['customer_name']) ?></strong></td>
                                    <td>â‚±<?= number_format($ord['grand_total'], 2) ?></td>
                                    <td>
                                        <?php if($ord['status'] === 'Approved'): ?>
                                            <span class="badge badge-green"><i class="bi bi-check-circle"></i> Approved</span>
                                        <?php elseif($ord['status'] === 'Cancelled'): ?>
                                            <span class="badge badge-danger"><i class="bi bi-x-circle"></i> Cancelled</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><i class="bi bi-hourglass-split"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(date('M d, Y', strtotime($ord['created_at'] ?? 'now'))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">No sales activity found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- Product Pricing Modal -->
<div class="modal fade" id="productPricingModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-tag text-primary me-2"></i>Set Selling Price</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="pp_product_id" value="">
        <div class="mb-2"><span class="text-muted">Product:</span> <strong id="pp_product_name">—</strong></div>
        <div class="mb-3"><span class="text-muted">SKU:</span> <span id="pp_product_sku" class="badge badge-blue">—</span></div>
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label">Current Stock</label>
            <input type="text" id="pp_stock" class="form-control" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Cost Price (₱)</label>
            <input type="number" id="pp_cost" class="form-control" step="0.01" readonly>
          </div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label">Markup %</label>
            <input type="number" id="pp_markup" class="form-control" step="0.01" min="0" value="0" oninput="ppRecalcFromMarkup()">
          </div>
          <div class="col-md-6">
            <label class="form-label">Selling Price (₱)</label>
            <input type="number" id="pp_selling" class="form-control" step="0.01" min="0" value="0" oninput="ppRecalcFromSelling()">
          </div>
        </div>
        <p class="small text-muted mb-0">Selling Price = Cost + (Cost × Markup %)</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="pp_save_btn" onclick="saveProductPricing()"><i class="bi bi-save me-1"></i> Save Selling Price</button>
      </div>
    </div>
  </div>
</div>

<?php salesOrderRenderModals(); ?>

<script>
window.CLAR_CAN_MANAGE_PRICING = <?= !empty($canManagePricing) ? 'true' : 'false' ?>;
window.CLAR_SALES_TAX_RATE = <?= json_encode((float)($salesTaxRate ?? 12)) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="java/app_dialog.js?v=<?= time() ?>"></script>
<script src="java/auth_context.js"></script>
<script src="java/notifications.js"></script>
<script src="java/sidebar_notification_badges.js?v=<?= time() ?>"></script>
<script src="java/sales_dashboard.js?v=<?= time() ?>"></script>
<script src="java/sales_order_module.js?v=<?= time() ?>"></script>
<script src="java/warranty_module.js?v=<?= time() ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof setProductOptions === 'function') {
        setProductOptions(<?= json_encode($productOptionsHtml) ?>);
    }
    if (typeof addOrderRow === 'function') addOrderRow();
});
</script>
</body>
</html>
