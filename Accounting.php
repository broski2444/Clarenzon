<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once 'config.php';
require_once 'route_protection.php';
require_once 'billing_api.php';

route_require_login(['accounting']);

$username = $_SESSION['username'];
$full_name = $_SESSION['full_name'];

// Load database connection
if (!isset($conn) || !$conn) {
    $conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db']);
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// 1. Load active inventory items
$inventory_items = [];
$value_chart_labels = [];
$value_chart_values = [];
$total_value = 0;
if ($conn) {
    $invRes = $conn->query("SELECT sku, name, quantity_in_stock, selling_price, cost_price FROM products WHERE status != 'Discontinued' ORDER BY name");
    if ($invRes) {
        while ($row = $invRes->fetch_assoc()) {
            $costPrice = (float)$row['cost_price'];
            $sellingPrice = (float)($row['selling_price'] ?: $row['cost_price']);
            $itemValue = (int)$row['quantity_in_stock'] * $costPrice;
            $inventory_items[] = [$row['sku'], $row['name'], (int)$row['quantity_in_stock'], $costPrice, $sellingPrice];
            $total_value += $itemValue;
            if (count($value_chart_labels) < 7) {
                $value_chart_labels[] = $row['name'];
                $value_chart_values[] = $itemValue;
            }
        }
    }
}

$sales_orders = $conn ? salesOrderLoadList($conn) : [];
$sales_credit_agreements = $conn ? salesOrderLoadAgreements($conn) : [];

// 3. Load all Invoices
$invoices = [];
if ($conn) {
    $invQuery = $conn->query("SELECT * FROM invoices ORDER BY created_at DESC");
    if ($invQuery) {
        while ($row = $invQuery->fetch_assoc()) {
            // Query items for this invoice to pass to modal
            $invoiceId = (int)$row['id'];
            $itemsQuery = $conn->query("SELECT ii.*, p.sku AS product_sku, p.name AS product_name, p.unit_of_measure FROM invoice_items ii LEFT JOIN products p ON ii.product_id = p.id WHERE ii.invoice_id = $invoiceId");
            $items = [];
            if ($itemsQuery) {
                while ($itemRow = $itemsQuery->fetch_assoc()) {
                    $items[] = $itemRow;
                }
            }
            if (empty($row['sales_order_number']) && !empty($row['sales_order_id'])) {
                $soN = $conn->query("SELECT order_number FROM sales_orders WHERE id=" . (int)$row['sales_order_id'] . " LIMIT 1");
                if ($soN && ($sr = $soN->fetch_assoc())) $row['sales_order_number'] = $sr['order_number'];
            }
            $row['items'] = $items;
            $row['items_json'] = json_encode($items);
            $lp = $conn->query("SELECT payment_number FROM invoice_payments WHERE invoice_id=$invoiceId ORDER BY id DESC LIMIT 1");
            $row['latest_payment_number'] = ($lp && ($pr = $lp->fetch_assoc())) ? (string)$pr['payment_number'] : '';
            $invoices[] = $row;
        }
    }
}
if ($conn instanceof mysqli) {
    billingAttachPaymentMetaBatch($conn, $invoices);
}
$pendingCheques = ($conn instanceof mysqli) ? billingLoadPendingCheques($conn) : [];
$reportPeriod = date('M Y');
$generatedDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title>Accounting Dashboard | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-theme.css?v=<?= @filemtime(__DIR__ . '/css/admin-theme.css') ?: time() ?>">
    <link rel="stylesheet" href="css/notifications.css">
    <link rel="stylesheet" href="css/popup-center.css?v=<?= @filemtime(__DIR__ . '/css/popup-center.css') ?: time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:5000; align-items:center; justify-content:center; padding:12px; overflow-y:auto; }
        .modal-overlay.open { display:flex; }
        .modal-overlay .modal { background:#fff; border-radius:16px; width:100%; max-width:620px; max-height:min(92vh, 900px); overflow:auto; margin:auto; }
        .modal-overlay .modal.modal-lg { max-width:min(1100px, 96vw); }
        .modal-overlay .modal-header, .modal-overlay .modal-footer { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #e5e7eb; }
        .modal-overlay .modal-footer { border-bottom:0; border-top:1px solid #e5e7eb; }
        .modal-overlay .modal-body { padding:18px; }
        .modal-close { border:0; background:transparent; font-size:20px; }
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 20px; }
        .stat-label { font-size: 13px; color: #6B7280; font-weight: 500; margin-bottom: 8px; }
        .stat-value { font-size: 32px; font-weight: 700; color: #111827; line-height: 1; }
        .stat-change { font-size: 12px; color: #10B981; margin-top: 8px; }
        .card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-size: 16px; font-weight: 600; color: #111827; }
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
        .btn-primary { background: #4F46E5; color: white; padding: 10px 20px; border-radius: 8px; border: none; font-weight: 500; font-size: 14px; cursor: pointer; }
        .chart-container { position: relative; height: 300px; }
        .logout-btn { width: 32px; height: 32px; border-radius: 6px; border: 1px solid #E5E7EB; background: white; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #EF4444; transition: all 0.2s; }
        .logout-btn:hover { background: #FEE2E2; }
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
            [style*="width: 300px"], [style*="width: 320px"], [style*="width: 180px"], [style*="width: 150px"], [style*="width: 130px"] { width: 100% !important; max-width: 100% !important; }
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
        <a class="nav-item" onclick="showSection('sales-orders', this)">
            <i class="bi bi-receipt"></i><span>Sales Orders</span>
        </a>
        <a class="nav-item" onclick="showSection('sales-credit', this)">
            <i class="bi bi-file-earmark-text"></i><span>Credit Agreements</span>
        </a>
        <a class="nav-item" onclick="showSection('billing-invoices', this)">
            <i class="bi bi-wallet2"></i><span>Account Receivable</span>
        </a>
        <a class="nav-item" onclick="showSection('unpaid-invoices', this)">
            <i class="bi bi-exclamation-circle-fill"></i><span>Accounts Receivable / Pending</span>
        </a>
        <a class="nav-item" onclick="showSection('financial-reports', this)">
            <i class="bi bi-file-earmark-text"></i><span>Financial Reports</span>
        </a>
        <a class="nav-item" onclick="showSection('inventory-valuation', this)">
            <i class="bi bi-calculator"></i><span>Inventory Valuation</span>
        </a>
        <a class="nav-item" onclick="showSection('cost-analysis', this)">
            <i class="bi bi-graph-up"></i><span>Cost Analysis</span>
        </a>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="user-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="user-role">Accounting</div>
            </div>
            <a href="?logout=1" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">Accounting Dashboard</div>
        <div class="d-flex align-items-center gap-3">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" placeholder="Search...">
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
        <?= $billing_alert ?>
        <?php if (!empty($billing_print_invoice) || !empty($billing_print_receipt)): ?>
        <script>
        window.open(<?= json_encode(!empty($billing_print_invoice) ? billingCustomerInvoiceHref($billing_print_invoice) : billingPaymentReceiptHref($billing_print_receipt)) ?>, '_blank');
        </script>
        <?php endif; ?>
        
        <div id="section-dashboard" class="content-section active">
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Inventory Value</div>
                    <div class="stat-value">₱<?= number_format($total_value, 2) ?></div>
                    <div class="stat-change"><i class="bi bi-arrow-up"></i> Current valuation</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Active Products</div>
                    <div class="stat-value"><?= count($inventory_items) ?></div>
                    <div class="stat-change"><i class="bi bi-box"></i> Items in stock</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Outstanding Invoices Balance</div>
                    <div class="stat-value">
                        ₱<?php 
                        $totalUnpaid = 0.00;
                        foreach($invoices as $i) $totalUnpaid += $i['remaining_balance'];
                        echo number_format($totalUnpaid, 2);
                        ?>
                    </div>
                    <div class="stat-change" style="color:#EF4444;"><i class="bi bi-exclamation-circle"></i> Awaiting payment</div>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Monthly Inventory Value Trend</div>
                        </div>
                        <div class="chart-container">
                            <canvas id="valueChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-title">Quick Stats</div>
                        <div class="stat-value mb-3">₱<?= number_format($total_value, 2) ?></div>
                        <div style="font-size: 13px; color: #6B7280; margin-bottom: 24px;">Current Assets Valuation</div>
                        <div class="stat-value mb-3" style="font-size: 24px;">98.5%</div>
                        <div style="font-size: 13px; color: #6B7280;">Accounting Accuracy</div>
                    </div>
                </div>
            </div>
        </div>

        <div id="section-sales-orders" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Sales Orders</div>
                    <div style="width: min(100%, 520px); display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                        <?= billingSaleCategoryTabsHtml('orders') ?>
                        <input type="hidden" id="soTypeFilter" value="">
                        <input type="text" id="soSearchInput" class="form-control form-control-sm" placeholder="Search orders..." onkeyup="filterSalesOrders()">
                        <select id="soStatusFilter" class="form-select form-select-sm" onchange="filterSalesOrders()">
                            <option value="active">Active</option>
                            <option value="" selected>All Statuses</option>
                            <option value="Pending">Pending</option>
                            <option value="Approved">Approved</option>
                            <option value="Open Credit">Open Credit</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
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
                                <th>Created By</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php salesOrderRenderListRows($sales_orders, ['show_creator' => true, 'can_manage' => true]); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php salesOrderRenderCreditSection($sales_credit_agreements ?? []); ?>

        <div id="section-billing-invoices" class="content-section">
            <div class="page-header mb-3">
                <h1>Account Receivable</h1>
            </div>
            <?php billingRenderPendingChequesCard($pendingCheques, billingCanUpdateChequeStatus()); ?>
            <div class="card">
                <div class="card-header flex-wrap gap-2">
                    <div class="card-title">Account Receivable</div>
                    <div style="width: min(100%, 560px); display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                        <?= billingSaleCategoryTabsHtml('invoices') ?>
                        <input type="hidden" id="invoiceTypeFilter" value="">
                        <input type="text" id="invoiceSearchInput" class="form-control form-control-sm" placeholder="Search invoices..." onkeyup="filterInvoices()">
                        <?= billingPaymentStatusFilterHtml() ?>
                    </div>
                </div>
                
                <div class="table-container system-table-scroll">
                    <table class="modern-table" id="invoicesTable">
                        <thead>
                            <tr>
                                <th>Invoice Number</th>
                                <th>SO Number</th>
                                <th>Customer PO</th>
                                <th>Customer Name</th>
                                <th>Type</th>
                                <th>Payment Method</th>
                                <th>Grand Total</th>
                                <th>Remaining Balance</th>
                                <th>Payment Status</th>
                                <th>Due Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($invoices) > 0): ?>
                            <?php foreach ($invoices as $inv): ?>
                                <tr <?= billingInvoiceRowAttrs($inv) ?>>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($inv['invoice_number']) ?></span></td>
                                    <td><span class="badge badge-purple"><?= htmlspecialchars($inv['sales_order_number']) ?></span></td>
                                    <td><?= htmlspecialchars($inv['customer_po_number'] ?? '—') ?></td>
                                    <td><strong><?= htmlspecialchars($inv['customer_name']) ?></strong></td>
                                    <td><?= htmlspecialchars(billingSaleTypeLabel($inv['sale_type'] ?? 'Cash')) ?></td>
                                    <td><?= htmlspecialchars($inv['payment_method_label'] ?? billingPaymentMethodLabel($inv)) ?></td>
                                    <td>₱<?= number_format($inv['grand_total'], 2) ?></td>
                                    <td><strong class="<?= $inv['remaining_balance'] > 0 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($inv['remaining_balance'], 2) ?></strong></td>
                                    <td><?= billingPaymentStatusBadgeHtml($inv) ?></td>
                                    <td><?= creditDueDateHtml($inv) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-outline-primary btn-sm js-view-invoice" data-invoice-id="<?= (int)$inv['id'] ?>"><i class="bi bi-eye"></i> View</button>
                                        <?php if ($inv['remaining_balance'] > 0): ?>
                                            <button class="btn btn-success btn-sm" onclick="openPaymentModal(<?= $inv['id'] ?>,'<?= $inv['invoice_number'] ?>',<?= $inv['grand_total'] ?>,<?= $inv['remaining_balance'] ?>)"><i class="bi bi-cash"></i> Pay</button>
                                        <?php endif; ?>
                                        <a href="print_invoice.php?invoice_number=<?= urlencode($inv['invoice_number']) ?>&with_receipt=1" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Invoice</a>
                                        <a href="print_payment_receipt.php?invoice_number=<?= urlencode($inv['invoice_number']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-receipt"></i> Receipt</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center">No generated invoices found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ═══════════ ACCOUNTS RECEIVABLE / PENDING ═══════════ -->
        <div id="section-unpaid-invoices" class="content-section">
            <div class="page-header mb-3">
                <h1>Accounts Receivable / Pending</h1>
            </div>
            <?php billingRenderPendingChequesCard($pendingCheques, billingCanUpdateChequeStatus()); ?>
            <?php creditRenderOverdueSection(); ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Outstanding Credit / Utang</div>
                </div>
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
                                <th>Due Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $unpaidCount = 0;
                        $canManageCheque = billingCanUpdateChequeStatus();
                        foreach ($invoices as $inv): 
                            if (!billingIsOutstandingInvoice($inv)) continue;
                            $unpaidCount++;
                            $txDate = !empty($inv['created_at']) ? date('M j, Y', strtotime((string)$inv['created_at'])) : '—';
                        ?>
                            <tr <?= billingInvoiceRowAttrs($inv) ?>>
                                <td><span class="badge badge-blue"><?= htmlspecialchars($inv['invoice_number']) ?></span></td>
                                <td><strong><?= htmlspecialchars($inv['customer_name']) ?></strong></td>
                                <td><strong class="text-danger" style="font-size:15px;">₱<?= number_format($inv['remaining_balance'], 2) ?></strong></td>
                                <td><?= htmlspecialchars($inv['payment_method_label'] ?? billingPaymentMethodLabel($inv)) ?></td>
                                <td><?= htmlspecialchars($txDate) ?></td>
                                <td><?= htmlspecialchars(billingSaleTypeLabel($inv['sale_type'] ?? 'Cash')) ?></td>
                                <td><?= billingPaymentStatusBadgeHtml($inv) ?></td>
                                <td><?= creditDueDateHtml($inv) ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline-primary btn-sm js-view-invoice" data-invoice-id="<?= (int)$inv['id'] ?>"><i class="bi bi-eye"></i> View</button>
                                    <?= creditArActionButtons($inv) ?>
                                    <?php if (!empty($inv['has_pending_cheque']) && $canManageCheque && !empty($inv['pending_cheque_id'])): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="payment_id" value="<?= (int)$inv['pending_cheque_id'] ?>">
                                        <button type="submit" name="mark_cheque_paid" class="btn btn-success btn-sm"><i class="bi bi-check-circle"></i> Mark as Paid</button>
                                    </form>
                                    <?php else: ?>
                                    <button class="btn btn-success btn-sm" onclick="openPaymentModal(<?= $inv['id'] ?>,'<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?>',<?= $inv['grand_total'] ?>,<?= $inv['remaining_balance'] ?>)"><i class="bi bi-cash"></i> Pay</button>
                                    <?php endif; ?>
                                    <a href="print_invoice.php?invoice_number=<?= urlencode($inv['invoice_number']) ?>&with_receipt=1" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Invoice</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($unpaidCount === 0): ?>
                            <tr>
                                <td colspan="9" class="text-center">No outstanding credit or pending balances. All invoices are fully paid.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

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
                                <td colspan="6" class="text-center py-4 text-muted">Click Unpaid Invoices or notifications to trigger loading history logs.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="section-financial-reports" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Financial Reports</div>
                    <button class="btn-primary"><i class="bi bi-download"></i> Export All</button>
                </div>
                
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Report Name</th>
                                <th>Period</th>
                                <th>Status</th>
                                <th>Generated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Inventory Valuation Report</td>
                                <td><?= htmlspecialchars($reportPeriod) ?></td>
                                <td><span class="badge badge-green">Complete</span></td>
                                <td><?= htmlspecialchars($generatedDate) ?></td>
                                <td><button class="btn btn-sm btn-primary" onclick="showSection('inventory-valuation')">View</button></td>
                            </tr>
                            <tr>
                                <td>Stock Movement Report</td>
                                <td><?= htmlspecialchars($reportPeriod) ?></td>
                                <td><span class="badge badge-green">Complete</span></td>
                                <td><?= htmlspecialchars($generatedDate) ?></td>
                                <td><button class="btn btn-sm btn-primary" onclick="showSection('notifications')">View</button></td>
                            </tr>
                            <tr>
                                <td>Cost of Goods Sold</td>
                                <td><?= htmlspecialchars($reportPeriod) ?></td>
                                <td><span class="badge badge-green">Complete</span></td>
                                <td><?= htmlspecialchars($generatedDate) ?></td>
                                <td><button class="btn btn-sm btn-primary" onclick="showSection('cost-analysis')">View</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="section-inventory-valuation" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Inventory Valuation Breakdown</div>
                    <div>Total Valuation: <strong>₱<?= number_format($total_value, 2) ?></strong></div>
                </div>
                
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($inventory_items as $item): ?>
                            <tr>
                                <td><span class="badge badge-blue"><?= htmlspecialchars($item[0]) ?></span></td>
                                <td><?= htmlspecialchars($item[1]) ?></td>
                                <td><?= $item[2] ?></td>
                                <td>₱<?= number_format($item[3], 2) ?></td>
                                <td><strong>₱<?= number_format($item[2] * $item[3], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #F9FAFB; font-weight: 700;">
                                <td colspan="4" style="text-align: right; padding: 16px;">TOTAL INVENTORY VALUE:</td>
                                <td style="padding: 16px; color: #4F46E5;">₱<?= number_format($total_value, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="section-cost-analysis" class="content-section">
            <div class="card">
                <div class="card-title">Cost Analysis</div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>Margin</th>
                                <th>Stock Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(count($inventory_items) > 0): ?>
                            <?php foreach($inventory_items as $item): ?>
                                <?php
                                    $margin = $item[4] - $item[3];
                                    $marginPercent = $item[4] > 0 ? ($margin / $item[4]) * 100 : 0;
                                ?>
                                <tr>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($item[0]) ?></span></td>
                                    <td><?= htmlspecialchars($item[1]) ?></td>
                                    <td>â‚±<?= number_format($item[3], 2) ?></td>
                                    <td>â‚±<?= number_format($item[4], 2) ?></td>
                                    <td><strong><?= number_format($marginPercent, 1) ?>%</strong></td>
                                    <td>â‚±<?= number_format($item[2] * $item[3], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">No product cost data found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- ═══════════ MODALS ═══════════ -->

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="recordPaymentForm" target="_blank">
                    <input type="hidden" name="record_payment" value="1">
                    <input type="hidden" name="invoice_id" id="pay_invoice_id">
                    <div class="mb-3">
                        <span class="text-muted">Invoice Number:</span>
                        <h6 id="pay_invoice_number" class="text-primary font-weight-bold"></h6>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <span class="text-muted">Grand Total:</span>
                            <h6 id="pay_grand_total"></h6>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Remaining Balance:</span>
                            <h6 id="pay_remaining_balance" class="text-danger font-weight-bold"></h6>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount to Pay (₱) <span class="text-danger">*</span></label>
                        <input type="number" name="amount_paid" id="pay_amount_paid" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select name="payment_method" id="pay_method" class="form-select" required onchange="toggleChequePayFields()">
                            <option value="Cash">Cash</option>
                            <option value="Credit">Credit (pay later — do not use to record a payment)</option>
                            <option value="Cheque">Cheque</option>
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div id="chequePayFields" class="mb-3" style="display:none">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Bank name <span class="text-danger">*</span></label>
                                <?php billingBankNameField('pay_bank_name', 'bank_name'); ?>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cheque number <span class="text-danger">*</span></label>
                                <input type="text" name="cheque_number" id="pay_cheque_number" class="form-control" placeholder="Cheque #">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cheque Date <span class="text-danger">*</span></label>
                                <input type="date" name="cheque_date" id="pay_cheque_date" class="form-control" oninput="if(typeof clearChequeDateError==='function')clearChequeDateError()" onchange="if(typeof clearChequeDateError==='function')clearChequeDateError()">
                                <div class="invalid-feedback" id="pay_cheque_date_error">Cheque Date is required.</div>
                            </div>
                        </div>
                        <div class="form-text">Cheque waits for bank clearance. If there is no money, mark it Bounced.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Notes</label>
                        <textarea name="payment_notes" class="form-control" placeholder="Optional notes"></textarea>
                    </div>
                    <button type="submit" name="record_payment" id="recordPaymentSubmitBtn" class="btn btn-primary w-100">Submit Payment</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Details Modal -->
<div class="modal fade" id="invoiceDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invoice Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <span class="text-muted d-block">Invoice Number:</span>
                        <h5 id="det_inv_number" class="text-primary font-weight-bold"></h5>
                        <span class="text-muted d-block mt-2">Customer:</span>
                        <strong id="det_customer" class="text-dark"></strong>
                        <div id="det_invoice_refs" class="small text-muted mt-1"></div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <span class="text-muted d-block">Status:</span>
                        <span id="det_status" class="badge"></span>
                        <span class="text-muted d-block mt-2">Date Generated:</span>
                        <strong id="det_date"></strong>
                    </div>
                </div>

                <h6>Itemized List</h6>
                <div class="table-container system-table-scroll mb-4">
                    <table class="table table-sm align-middle" style="font-size: 13px;">
                        <thead>
                            <tr class="table-light">
                                <th>SKU</th>
                                <th>Description</th>
                                <th>UOM</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Tax Included</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody id="det_items_tbody">
                            <!-- Injected JS -->
                        </tbody>
                    </table>
                </div>

                <div class="row">
                    <div class="col-md-6"></div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Subtotal:</span>
                            <span id="det_subtotal">₱0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Discount:</span>
                            <span id="det_discount" class="text-danger">-₱0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Tax Included:</span>
                            <span id="det_tax">₱0.00</span>
                        </div>
                        <hr class="my-1">
                        <div class="d-flex justify-content-between mb-1">
                            <strong>Grand Total:</strong>
                            <strong id="det_grand_total" class="text-primary">₱0.00</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <strong class="text-danger">Balance Due:</strong>
                            <strong id="det_balance" class="text-danger">₱0.00</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" id="btnPrintReceipt" class="btn btn-outline-success"><i class="bi bi-receipt"></i> Print Receipt</button>
                <button type="button" id="btnPrintInvoice" class="btn btn-primary"><i class="bi bi-printer"></i> Print Invoice</button>
            </div>
        </div>
    </div>
</div>

<?php salesOrderRenderModals(); ?>

<script>window.INVOICE_DETAILS = <?= json_encode(billingInvoiceViewMap($invoices ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script type="application/json" id="accounting-js-data">
<?= json_encode(['totalValue' => $total_value, 'valueChartLabels' => $value_chart_labels ?: ['No inventory'], 'valueChartValues' => $value_chart_values ?: [0]], JSON_UNESCAPED_UNICODE) ?>
</script>
<script src="java/app_dialog.js?v=<?= time() ?>"></script>
<script src="java/notifications.js"></script>
<script src="java/sidebar_notification_badges.js?v=<?= time() ?>"></script>
<script src="java/accounting.js"></script>
<script src="java/sales_order_module.js?v=<?= time() ?>"></script>

</body>
</html>
