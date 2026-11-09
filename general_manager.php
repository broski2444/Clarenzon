<?php
session_start();
require_once 'config.php';
require_once 'route_protection.php';

route_require_login(['general_manager']);

$username = $_SESSION['username'];
$full_name = $_SESSION['full_name'];
$role = $_SESSION['role'];

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$inventory_items = [];
$overview_chart_labels = [];
$overview_chart_values = [];
$total_value = 0;
$total_items = 0;
$low_stock_count = 0;
$analytics = [
    'sales_orders' => 0,
    'pending_sales' => 0,
    'approved_sales' => 0,
    'purchase_orders' => 0,
    'pending_purchase_orders' => 0,
    'stock_movement_value' => 0,
    'purchase_order_value' => 0,
];

$conn = getDBConnection();
if ($conn) {
    $products = $conn->query(
        "SELECT p.sku, p.name, COALESCE(c.name, 'Uncategorized') AS category_name,
                p.quantity_in_stock, p.cost_price, p.reorder_level
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.status != 'Discontinued'
         ORDER BY p.name"
    );
    if ($products) {
        while ($row = $products->fetch_assoc()) {
            $qty = (int)$row['quantity_in_stock'];
            $cost = (float)$row['cost_price'];
            $inventory_items[] = [$row['sku'], $row['name'], $row['category_name'], $qty, $cost, (int)$row['reorder_level']];
            $total_value += $qty * $cost;
            $total_items += $qty;
            if ($qty <= (int)$row['reorder_level']) {
                $low_stock_count++;
            }
            if (count($overview_chart_labels) < 6) {
                $overview_chart_labels[] = $row['name'];
                $overview_chart_values[] = $qty * $cost;
            }
        }
    }

    $sales = $conn->query("SELECT status, COUNT(*) AS total FROM sales_orders GROUP BY status");
    if ($sales) {
        while ($row = $sales->fetch_assoc()) {
            $analytics['sales_orders'] += (int)$row['total'];
            if ($row['status'] === 'Pending') $analytics['pending_sales'] += (int)$row['total'];
            if ($row['status'] === 'Approved') $analytics['approved_sales'] += (int)$row['total'];
        }
    }

    $purchaseOrders = $conn->query("SELECT status, COUNT(*) AS total FROM purchase_orders GROUP BY status");
    if ($purchaseOrders) {
        while ($row = $purchaseOrders->fetch_assoc()) {
            $analytics['purchase_orders'] += (int)$row['total'];
            if ($row['status'] === 'Pending Approval') $analytics['pending_purchase_orders'] += (int)$row['total'];
        }
    }

    $movementValue = $conn->query("SELECT COALESCE(SUM(total_value), 0) AS total FROM stock_movements");
    if ($movementValue && ($row = $movementValue->fetch_assoc())) {
        $analytics['stock_movement_value'] = (float)$row['total'];
    }

    $poValue = $conn->query("SELECT COALESCE(SUM(total_amount), 0) AS total FROM purchase_orders");
    if ($poValue && ($row = $poValue->fetch_assoc())) {
        $analytics['purchase_order_value'] = (float)$row['total'];
    }

    $conn->close();
}
$report_period = date('M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Manager Dashboard | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-theme.css">
    <link rel="stylesheet" href="css/popup-center.css">
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
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 20px; }
        .stat-label { font-size: 13px; color: #6B7280; font-weight: 500; margin-bottom: 8px; }
        .stat-value { font-size: 32px; font-weight: 700; color: #111827; line-height: 1; }
        .stat-change { font-size: 12px; color: #10B981; margin-top: 8px; }
        .card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-size: 16px; font-weight: 600; color: #111827; }
        .table-container { overflow-x: auto; }
        .modern-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .modern-table thead th { background: #F9FAFB; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #E5E7EB; }
        .modern-table tbody td { padding: 16px; border-bottom: 1px solid #F3F4F6; font-size: 14px; color: #374151; }
        .modern-table tbody tr:hover { background: #F9FAFB; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; }
        .badge-blue { background: #EEF2FF; color: #4F46E5; }
        .badge-green { background: #D1FAE5; color: #059669; }
        .badge-orange { background: #FEF3C7; color: #D97706; }
        .btn-icon { width: 32px; height: 32px; border-radius: 6px; border: 1px solid #E5E7EB; background: white; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: #6B7280; transition: all 0.2s; }
        .btn-icon:hover { background: #F3F4F6; color: #111827; }
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
            .top-bar, .card-header, .page-header { flex-wrap: wrap; gap: 10px; }
            .content-area { padding: 18px; }
            .search-box { width: 100%; max-width: 100%; }
            .stats-row { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
            .card { padding: 18px; }
            .table-container { max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .modern-table { width: max-content; min-width: 100%; }
            .row > [class*="col-"], [class*="col-md-"], [class*="col-lg-"] { width: 100% !important; max-width: 100% !important; }
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
        <a class="nav-item active" onclick="showSection('dashboard')">
            <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
        </a>
        <a class="nav-item" onclick="showSection('reports')">
            <i class="bi bi-file-earmark-text"></i><span>Reports</span>
        </a>
        <a class="nav-item" onclick="showSection('analytics')">
            <i class="bi bi-graph-up"></i><span>Analytics</span>
        </a>
        <a class="nav-item" onclick="showSection('overview')">
            <i class="bi bi-eye"></i><span>Overview</span>
        </a>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="user-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="user-role">General Manager</div>
            </div>
            <a href="?logout=1" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">General Manager Dashboard</div>
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search reports...">
        </div>
    </div>
    
    <div class="content-area">
        
        <div id="section-dashboard" class="content-section active">
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Products</div>
                    <div class="stat-value"><?= count($inventory_items) ?></div>
                    <div class="stat-change"><i class="bi bi-arrow-up"></i> In catalog</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Stock Value</div>
                    <div class="stat-value">₱<?= number_format($total_value, 0) ?></div>
                    <div class="stat-change"><i class="bi bi-arrow-up"></i> Current worth</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Units</div>
                    <div class="stat-value"><?= number_format($total_items) ?></div>
                    <div class="stat-change"><i class="bi bi-box"></i> In stock</div>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Inventory Overview</div>
                        </div>
                        <div class="chart-container">
                            <canvas id="overviewChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-title">Quick Stats</div>
                        <div class="stat-value mb-3"><?= $analytics['sales_orders'] + $analytics['purchase_orders'] ?></div>
                        <div style="font-size: 13px; color: #6B7280; margin-bottom: 24px;">Total Orders</div>
                        <div class="stat-value mb-3" style="font-size: 24px;"><?= $analytics['approved_sales'] + max(0, $analytics['purchase_orders'] - $analytics['pending_purchase_orders']) ?></div>
                        <div style="font-size: 13px; color: #6B7280;">Completed Orders</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="section-reports" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Financial Reports</div>
                    <button class="btn-primary"><i class="bi bi-download"></i> Export PDF</button>
                </div>
                
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Report Type</th>
                                <th>Period</th>
                                <th>Total Value</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Inventory Valuation</td>
                                <td><?= htmlspecialchars($report_period) ?></td>
                                <td>₱<?= number_format($total_value, 2) ?></td>
                                <td><span class="badge badge-green">Complete</span></td>
                                <td><button class="btn-icon"><i class="bi bi-eye"></i></button></td>
                            </tr>
                            <tr>
                                <td>Stock Movement</td>
                                <td><?= htmlspecialchars($report_period) ?></td>
                                <td>₱<?= number_format($analytics['stock_movement_value'], 2) ?></td>
                                <td><span class="badge badge-green">Complete</span></td>
                                <td><button class="btn-icon"><i class="bi bi-eye"></i></button></td>
                            </tr>
                            <tr>
                                <td>Purchase Orders</td>
                                <td><?= htmlspecialchars($report_period) ?></td>
                                <td>₱<?= number_format($analytics['purchase_order_value'], 2) ?></td>
                                <td><span class="badge badge-orange">Pending</span></td>
                                <td><button class="btn-icon"><i class="bi bi-eye"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="section-analytics" class="content-section">
            <div class="card">
                <div class="card-title">Analytics Dashboard</div>
                <div class="stats-row" style="margin-bottom: 20px;">
                    <div class="stat-card">
                        <div class="stat-label">Inventory Value</div>
                        <div class="stat-value">₱<?= number_format($total_value, 2) ?></div>
                        <div class="stat-change"><i class="bi bi-box-seam"></i> Current stock cost</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Low Stock Items</div>
                        <div class="stat-value"><?= $low_stock_count ?></div>
                        <div class="stat-change" style="color:#EF4444;"><i class="bi bi-exclamation-triangle"></i> Needs review</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Pending Sales</div>
                        <div class="stat-value"><?= $analytics['pending_sales'] ?></div>
                        <div class="stat-change" style="color:#D97706;"><i class="bi bi-hourglass-split"></i> Awaiting action</div>
                    </div>
                </div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Area</th>
                                <th>Total Records</th>
                                <th>Pending</th>
                                <th>Completed / Approved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Sales Orders</strong></td>
                                <td><?= $analytics['sales_orders'] ?></td>
                                <td><span class="badge badge-orange"><?= $analytics['pending_sales'] ?></span></td>
                                <td><span class="badge badge-green"><?= $analytics['approved_sales'] ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>Purchase Orders</strong></td>
                                <td><?= $analytics['purchase_orders'] ?></td>
                                <td><span class="badge badge-orange"><?= $analytics['pending_purchase_orders'] ?></span></td>
                                <td><span class="badge badge-green"><?= max(0, $analytics['purchase_orders'] - $analytics['pending_purchase_orders']) ?></span></td>
                            </tr>
                            <tr>
                                <td><strong>Inventory</strong></td>
                                <td><?= count($inventory_items) ?> products</td>
                                <td><span class="badge badge-orange"><?= $low_stock_count ?> low stock</span></td>
                                <td><span class="badge badge-green"><?= max(0, count($inventory_items) - $low_stock_count) ?> stocked</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="section-overview" class="content-section">
            <div class="card">
                <div class="card-title">System Overview</div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($inventory_items as $item): ?>
                            <tr>
                                <td><span class="badge badge-blue"><?= $item[0] ?></span></td>
                                <td><?= $item[1] ?></td>
                                <td><?= $item[2] ?></td>
                                <td><?= $item[3] ?></td>
                                <td>₱<?= number_format($item[4], 2) ?></td>
                                <td>₱<?= number_format($item[3] * $item[4], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.gmOverviewChartData = {
    labels: <?= json_encode($overview_chart_labels ?: ['No inventory']) ?>,
    values: <?= json_encode($overview_chart_values ?: [0]) ?>
};
</script>
<script src="java/general_manager.js"></script>

</body>
</html>

