<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'staff') {
    header("Location: admin_dashboard.php");
    exit();
}

$username = $_SESSION['username'];
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_name_lib.php';
$full_name = userDisplayName([
    'first_name' => (string)($_SESSION['first_name'] ?? ''),
    'last_name' => (string)($_SESSION['last_name'] ?? ''),
    'full_name' => (string)($_SESSION['full_name'] ?? ''),
], (string)$username);
$first_name = userFirstName([
    'first_name' => (string)($_SESSION['first_name'] ?? ''),
    'last_name' => (string)($_SESSION['last_name'] ?? ''),
    'full_name' => $full_name,
], $full_name);
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'];

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$fallback_product_catalog = [
    ['SKU-001', 'Industrial Bearing A200',    'Mechanical Parts',  245,  45.99, 'In Stock'],
    ['SKU-002', 'Hydraulic Pump HP-500',       'Hydraulics',         15, 289.50, 'Low Stock'],
    ['SKU-003', 'Steel Cable 10mm',            'Cables & Wires',   1200,  2.75, 'In Stock'],
    ['SKU-004', 'Electric Motor 3HP',          'Motors',             42, 425.00, 'In Stock'],
    ['SKU-005', 'Rubber Gasket Set',           'Seals & Gaskets',   325,  18.75, 'In Stock'],
    ['SKU-006', 'Valve Assembly V-100',        'Valves',             18, 156.25, 'Low Stock'],
    ['SKU-007', 'Lubricant Oil 5L',            'Lubricants',        580,  32.50, 'In Stock'],
    ['SKU-008', 'Safety Gloves Heavy Duty',    'Safety Equipment',   89,  12.99, 'In Stock'],
    ['SKU-009', 'Filter Cartridge F-200',      'Filters',           156,  24.50, 'In Stock'],
    ['SKU-010', 'Bolt & Nut Kit M12',          'Fasteners',          28,  15.75, 'In Stock'],
];

$fallback_order_history = [
    ['ORD-2026-001', '2026-01-15', 'Industrial Bearing A200', 10, 459.90, 'Delivered'],
    ['ORD-2026-002', '2026-01-20', 'Steel Cable 10mm',       50, 137.50, 'Delivered'],
    ['ORD-2026-003', '2026-01-25', 'Safety Gloves',          20, 259.80, 'Delivered'],
    ['ORD-2026-004', '2026-02-01', 'Filter Cartridge F-200',  5, 122.50, 'Processing'],
    ['ORD-2026-005', '2026-02-03', 'Lubricant Oil 5L',       10, 325.00, 'Processing'],
];

$product_catalog = [];
$order_history = [];
$conn = getDBConnection();
if ($conn) {
    $products = $conn->query(
        "SELECT p.sku, p.name, COALESCE(c.name, 'Uncategorized') AS category_name,
                p.quantity_in_stock, COALESCE(NULLIF(p.selling_price, 0), p.unit_price) AS display_price,
                p.reorder_level
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.status != 'Discontinued'
         ORDER BY p.name"
    );
    if ($products) {
        while ($row = $products->fetch_assoc()) {
            $status = ((int)$row['quantity_in_stock'] <= (int)$row['reorder_level']) ? 'Low Stock' : 'In Stock';
            $product_catalog[] = [
                $row['sku'],
                $row['name'],
                $row['category_name'],
                (int)$row['quantity_in_stock'],
                (float)$row['display_price'],
                $status,
            ];
        }
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $orders = $conn->prepare(
        "SELECT so.order_number, so.created_at, COALESCE(MIN(p.name), 'Sales Order') AS first_product,
                COALESCE(SUM(soi.quantity), 0) AS total_quantity, so.grand_total, so.status
         FROM sales_orders so
         LEFT JOIN sales_order_items soi ON so.id = soi.sales_order_id
         LEFT JOIN products p ON soi.product_id = p.id
         WHERE so.created_by = ?
         GROUP BY so.id
         ORDER BY so.created_at DESC"
    );
    if ($orders) {
        $orders->bind_param("i", $userId);
        $orders->execute();
        $res = $orders->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $order_history[] = [
                    $row['order_number'],
                    date('Y-m-d', strtotime($row['created_at'])),
                    $row['first_product'],
                    (int)$row['total_quantity'],
                    (float)$row['grand_total'],
                    $row['status'],
                ];
            }
        }
        $orders->close();
    }
    $conn->close();
}

if (!$product_catalog) $product_catalog = $fallback_product_catalog;
if (!$order_history) $order_history = $fallback_order_history;

// Handle inquiry submission
$alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inquiry'])) {
    $alert = '<div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> Your inquiry has been submitted! We will contact you soon.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}

// Handle quote request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_quote'])) {
    $alert = '<div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> Quote request submitted! Check your email for confirmation.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Dashboard | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <script src="java/app_dialog.js?v=<?= time() ?>"></script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/admin-theme.css">
    <link rel="stylesheet" href="css/popup-center.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f4f8; overflow-x: hidden; }
        
        /* Sidebar */
        .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: linear-gradient(180deg, #2c5f6f 0%, #1e4450 100%); padding: 30px 0; z-index: 1000; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .user-profile { text-align: center; padding: 0 20px 30px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 30px; }
        .user-avatar { width: 80px; height: 80px; border-radius: 50%; background: #fff; margin: 0 auto 15px; display: flex; align-items: center; justify-content: center; font-size: 36px; color: #2c5f6f; font-weight: bold; border: 3px solid rgba(255,255,255,0.2); }
        .user-name { color: #fff; font-size: 16px; font-weight: 600; margin-bottom: 5px; }
        .user-email { color: rgba(255,255,255,0.7); font-size: 12px; }
        .nav-menu { list-style: none; padding: 0; }
        .nav-link { display: flex; align-items: center; padding: 15px 25px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; cursor: pointer; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff; border-left-color: #fff; }
        .nav-link.active { background: rgba(255,255,255,0.15); color: #fff; border-left-color: #fff; }
        .nav-link i { font-size: 18px; margin-right: 15px; width: 20px; }
        .logout-link { position: absolute; bottom: 20px; width: 100%; }
        .logout-link .nav-link { color: #ff6b6b; }
        
        /* Main Content */
        .main-content { margin-left: 260px; min-height: 100vh; }
        .top-bar { background: #fff; padding: 20px 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .welcome-text { font-size: 24px; font-weight: 600; color: #2c3e50; margin: 0; }
        .search-box { position: relative; }
        .search-box input { width: 300px; padding: 8px 15px 8px 40px; border: 1px solid #e0e0e0; border-radius: 20px; font-size: 14px; }
        .search-box i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #999; }
        
        .content-area { padding: 0 30px 30px; }
        .page-title { font-size: 20px; font-weight: 600; color: #2c3e50; margin-bottom: 20px; }
        
        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 15px; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .stat-icon { width: 56px; height: 56px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .stat-icon.blue { background: #e3f2fd; color: #1976d2; }
        .stat-icon.green { background: #e8f5e9; color: #388e3c; }
        .stat-icon.orange { background: #fff3e0; color: #f57c00; }
        .stat-value { font-size: 28px; font-weight: 700; color: #2c3e50; line-height: 1; margin-bottom: 5px; }
        .stat-label { font-size: 13px; color: #7f8c8d; }
        
        /* Cards */
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
        .card-title { font-size: 16px; font-weight: 600; color: #2c3e50; margin-bottom: 20px; }
        
        /* Tables */
        .table { font-size: 14px; }
        .table thead { background: #f8f9fa; }
        .table thead th { border: none; padding: 12px; font-size: 12px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; }
        .table tbody td { padding: 12px; vertical-align: middle; border-color: #f0f0f0; }
        .table tbody tr:hover { background: #f8f9fa; }
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 500; }
        
        /* Product Cards */
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .product-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: all 0.3s; }
        .product-card:hover { transform: translateY(-4px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .product-image { width: 100%; height: 150px; background: linear-gradient(135deg, #e0f2f1, #b2dfdb); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 48px; margin-bottom: 15px; }
        .product-name { font-size: 16px; font-weight: 600; color: #2c3e50; margin-bottom: 8px; }
        .product-category { font-size: 12px; color: #7f8c8d; margin-bottom: 10px; }
        .product-price { font-size: 24px; font-weight: 700; color: #2c5f6f; margin-bottom: 10px; }
        .product-stock { font-size: 13px; margin-bottom: 15px; }
        
        /* Content Sections */
        .content-section { display: none; }
        .content-section.active { display: block; }
        
        /* Info Box */
        .info-box { background: linear-gradient(135deg, #e3f2fd, #bbdefb); padding: 20px; border-radius: 12px; margin-bottom: 20px; }
        .info-box h5 { color: #1976d2; font-weight: 600; margin-bottom: 10px; }
        .info-box p { color: #0d47a1; margin: 0; }
        
        @media (max-width: 992px) {
            .sidebar { width: 72px; padding: 18px 0; transform: none; }
            .sidebar .user-name, .sidebar .user-role, .sidebar .nav-link span { display: none; }
            .sidebar .user-avatar { width: 44px; height: 44px; font-size: 20px; }
            .nav-link { justify-content: center; padding: 12px 8px; }
            .nav-link i { margin-right: 0; }
            .logout-link { bottom: 12px; }
            .main-content { margin-left: 72px; }
        }
        @media (max-width: 767px) {
            .main-content { margin-left: 72px !important; }
            .top-bar { padding: 14px 16px; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }
            .search-box input { width: 100%; max-width: 100%; }
            .content-area, .container-fluid { padding-left: 14px !important; padding-right: 14px !important; }
            .stats-grid, .products-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)) !important; gap: 12px; }
            .card, .product-card, .stat-card { max-width: 100%; min-width: 0; }
            p, h1, h2, h3, h4, h5, h6, td, th, .card-title, .product-name { overflow-wrap: anywhere; }
            input, select, textarea, button { max-width: 100%; }
        }
        @media (max-width: 480px) {
            .stats-grid, .products-grid { grid-template-columns: 1fr !important; }
            .product-image { height: 120px; }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="user-profile">
        <div class="user-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
        <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
        <div class="user-email"><?= htmlspecialchars($email) ?></div>
    </div>
    
    <ul class="nav-menu">
        <li class="nav-item">
            <a class="nav-link active" onclick="showSection('dashboard')">
                <i class="bi bi-grid-3x3-gap"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="showSection('catalog')">
                <i class="bi bi-grid"></i> Product Catalog
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="showSection('orders')">
                <i class="bi bi-bag-check"></i> My Orders
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="showSection('quote')">
                <i class="bi bi-file-earmark-text"></i> Request Quote
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="showSection('inquiry')">
                <i class="bi bi-envelope"></i> Contact Us
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="showSection('profile')">
                <i class="bi bi-person"></i> My Profile
            </a>
        </li>
    </ul>
    
    <div class="logout-link">
        <a href="?logout=1" class="nav-link">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    
    <!-- Top Bar -->
    <div class="top-bar d-flex justify-content-between align-items-center">
        <h1 class="welcome-text">Welcome <?= htmlspecialchars($full_name) ?> !</h1>
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search products..." class="form-control">
        </div>
    </div>
    
    <!-- Content Area -->
    <div class="content-area">
        
        <?php if ($alert): echo $alert; endif; ?>
        
        <!-- DASHBOARD SECTION -->
        <div id="section-dashboard" class="content-section active">
            <div class="page-title">Overview</div>
            
            <div class="info-box">
                <h5><i class="bi bi-info-circle"></i> Welcome to Clarenzon Medical Supplies!</h5>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-box-seam"></i></div>
                    <div>
                        <div class="stat-value"><?= count($product_catalog) ?></div>
                        <div class="stat-label">Products Available</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-bag-check"></i></div>
                    <div>
                        <div class="stat-value"><?= count($order_history) ?></div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <div class="stat-value">2</div>
                        <div class="stat-label">Pending Orders</div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Orders -->
            <div class="card">
                <h5 class="card-title">Recent Orders</h5>
                <div class="table-responsive system-table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach(array_slice($order_history, 0, 3) as $order): ?>
                            <tr>
                                <td><span class="badge bg-primary"><?= $order[0] ?></span></td>
                                <td><?= $order[1] ?></td>
                                <td><?= $order[2] ?></td>
                                <td><?= $order[3] ?></td>
                                <td>$<?= number_format($order[4], 2) ?></td>
                                <td>
                                    <?php if ($order[5] === 'Delivered'): ?>
                                        <span class="badge bg-success"><?= $order[5] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><?= $order[5] ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- PRODUCT CATALOG SECTION -->
        <div id="section-catalog" class="content-section">
            <div class="page-title">Product Catalog</div>
            
            <div class="mb-3">
                <input type="text" class="form-control" placeholder="🔍 Search products..." onkeyup="filterProducts(this.value)">
            </div>
            
            <div class="product-grid" id="productGrid">
                <?php foreach($product_catalog as $product): ?>
                    <div class="product-card" data-name="<?= strtolower($product[1]) ?>" data-category="<?= strtolower($product[2]) ?>">
                        <div class="product-image">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="product-name"><?= $product[1] ?></div>
                        <div class="product-category"><i class="bi bi-tag"></i> <?= $product[2] ?></div>
                        <div class="product-price">$<?= number_format($product[4], 2) ?></div>
                        <div class="product-stock">
                            <?php if ($product[5] === 'In Stock'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> <?= $product[5] ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning"><i class="bi bi-exclamation-triangle"></i> <?= $product[5] ?></span>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-sm btn-outline-primary w-100" onclick="alert('Please contact us for orders: <?= htmlspecialchars($email) ?>')">
                            <i class="bi bi-envelope"></i> Inquire
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- MY ORDERS SECTION -->
        <div id="section-orders" class="content-section">
            <div class="page-title">My Orders</div>
            
            <div class="card">
                <h5 class="card-title">Order History</h5>
                <div class="table-responsive system-table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($order_history as $order): ?>
                            <tr>
                                <td><span class="badge bg-primary"><?= $order[0] ?></span></td>
                                <td><?= $order[1] ?></td>
                                <td><?= $order[2] ?></td>
                                <td><?= $order[3] ?></td>
                                <td class="fw-bold">$<?= number_format($order[4], 2) ?></td>
                                <td>
                                    <?php if ($order[5] === 'Delivered'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> <?= $order[5] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><i class="bi bi-clock"></i> <?= $order[5] ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- REQUEST QUOTE SECTION -->
        <div id="section-quote" class="content-section">
            <div class="page-title">Request Quote</div>
            
            <div class="card">
                <h5 class="card-title">Submit Quote Request</h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Product <span class="text-danger">*</span></label>
                        <select name="product" class="form-select" required>
                            <option value="">Select a product</option>
                            <?php foreach($product_catalog as $p): ?>
                                <option value="<?= $p[0] ?>"><?= $p[1] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" placeholder="Enter quantity" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Expected Delivery Date</label>
                            <input type="date" name="delivery_date" class="form-control">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Additional Requirements</label>
                        <textarea name="requirements" class="form-control" rows="4" placeholder="Please specify any additional requirements..."></textarea>
                    </div>
                    
                    <button type="submit" name="request_quote" class="btn btn-primary">
                        <i class="bi bi-file-earmark-text"></i> Submit Quote Request
                    </button>
                </form>
            </div>
        </div>
        
        <!-- CONTACT US SECTION -->
        <div id="section-inquiry" class="content-section">
            <div class="page-title">Contact Us</div>
            
            <div class="card">
                <h5 class="card-title">Send us a message</h5>
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Your Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($full_name) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Your Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter subject" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea name="message" class="form-control" rows="5" placeholder="Your message..." required></textarea>
                    </div>
                    
                    <button type="submit" name="submit_inquiry" class="btn btn-primary">
                        <i class="bi bi-send"></i> Send Message
                    </button>
                </form>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-telephone" style="font-size: 32px; color: #2c5f6f;"></i>
                            <h6 class="mt-3">Phone</h6>
                            <p class="text-muted">+1 (555) 123-4567</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-envelope" style="font-size: 32px; color: #2c5f6f;"></i>
                            <h6 class="mt-3">Email</h6>
                            <p class="text-muted">support@clarenzon.com</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-geo-alt" style="font-size: 32px; color: #2c5f6f;"></i>
                            <h6 class="mt-3">Address</h6>
                            <p class="text-muted">123 Medical St, City</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- PROFILE SECTION -->
        <div id="section-profile" class="content-section">
            <div class="page-title">My Profile</div>
            
            <div class="card">
                <h5 class="card-title">Account Information</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">First Name</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($first_name) ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Last Name</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars((string)($_SESSION['last_name'] ?? '')) ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Username</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($username) ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Email</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($email) ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Account Type</label>
                        <p class="form-control-plaintext"><span class="badge bg-info">Guest User</span></p>
                    </div>
                </div>
                
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle"></i> <strong>Note:</strong> To update your profile information or upgrade your account, please contact our support team.
                </div>
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="java/guest.js"></script>

</body>
</html>

