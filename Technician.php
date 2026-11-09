<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once 'config.php';
require_once 'route_protection.php';
require_once 'technician_job_orders.php';

route_require_login(['technician']);

$username = $_SESSION['username'] ?? '';
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
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'technician');
$roleKey = normalize_user_role($role);
$welcomeRoleLabels = [
    'admin' => 'Admin',
    'purchaser' => 'Purchaser',
    'warehouse_head' => 'Warehouse Head',
    'technician' => 'Technician',
];
$welcomeRoleLabel = $welcomeRoleLabels[$roleKey] ?? 'Technician';

if (isset($_GET['logout'])) {
    clarenzon_logout_current('technician');
    header("Location: login.php");
    exit();
}

$inventory_items = [];
$job_orders = [];
$issued_supplies = [];
$audit_logs = [];
$alert = '';
$conn = getDBConnection();
if ($conn) {
    ensureTechnicianJobOrderSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_job_status'])) {
            $jobId = (int)($_POST['job_order_id'] ?? 0);
            $status = trim((string)($_POST['work_status'] ?? ''));
            $res = updateTechnicianJobStatus($conn, $jobId, $user_id, $status);
            if ($res['success']) {
                technicianAddAuditLog($conn, $user_id, $username, $role, 'technician_job_status', 'Updated job order status to ' . $status . '.');
                $_SESSION['flash_alert'] = '<div class="alert alert-success">Job order status updated.</div>';
            } else {
                $_SESSION['flash_alert'] = '<div class="alert alert-danger">' . htmlspecialchars($res['message']) . '</div>';
            }
            header('Location: Technician.php');
            exit();
        }

        if (isset($_POST['issue_supply'])) {
            $jobId = (int)($_POST['issue_job_order_id'] ?? 0);
            $productId = (int)($_POST['issue_product_id'] ?? 0);
            $quantity = (int)($_POST['issue_quantity'] ?? 0);
            $remarks = trim((string)($_POST['issue_remarks'] ?? ''));
            $res = issueSupplyForJobOrder($conn, $jobId, $productId, $quantity, $user_id, $remarks);
            if ($res['success']) {
                technicianAddAuditLog($conn, $user_id, $username, $role, 'technician_issue_supply', 'Issued supplies for job order. Reference: ' . $res['reference']);
                $_SESSION['flash_alert'] = '<div class="alert alert-success">Issued supply recorded. Reference: ' . htmlspecialchars($res['reference']) . '</div>';
            } else {
                $_SESSION['flash_alert'] = '<div class="alert alert-danger">' . htmlspecialchars($res['message']) . '</div>';
            }
            header('Location: Technician.php');
            exit();
        }
    }

    if (isset($_SESSION['flash_alert'])) {
        $alert = $_SESSION['flash_alert'];
        unset($_SESSION['flash_alert']);
    }

    $products = $conn->query(
        "SELECT p.id, p.sku, p.name, COALESCE(c.name, 'Uncategorized') AS category_name,
                p.quantity_in_stock, p.selling_price, p.reorder_level
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.status != 'Discontinued'
         ORDER BY p.name"
    );
    if ($products) {
        while ($row = $products->fetch_assoc()) {
            $status = ((int)$row['quantity_in_stock'] <= (int)$row['reorder_level']) ? 'Low Stock' : 'In Stock';
            if ((int)$row['quantity_in_stock'] <= 0) {
                $status = 'Low Stock';
            }
            $inventory_items[] = [
                $row['sku'],
                $row['name'],
                $row['category_name'],
                (int)$row['quantity_in_stock'],
                (float)$row['selling_price'],
                $status,
                (int)$row['reorder_level'],
                (int)$row['id'],
            ];
        }
    }

    $job_orders = getTechnicianJobOrders($conn, $user_id);
    $issued_supplies = getTechnicianIssuedSupplies($conn, $user_id);

    $auditStmt = $conn->prepare("SELECT action_key, details, created_at FROM audit_logs WHERE user_id = ? AND (action_key LIKE 'technician_%' OR action_key = 'login') ORDER BY id DESC LIMIT 100");
    if ($auditStmt) {
        $auditStmt->bind_param("i", $user_id);
        $auditStmt->execute();
        $auditRes = $auditStmt->get_result();
        while ($row = $auditRes->fetch_assoc()) $audit_logs[] = $row;
        $auditStmt->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title>Technician Dashboard | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/dashboard.css?v=<?= @filemtime(__DIR__ . '/css/dashboard.css') ?: time() ?>">
    <link rel="stylesheet" href="css/popup-center.css?v=<?= @filemtime(__DIR__ . '/css/popup-center.css') ?: time() ?>">
    <style>
        .content-section { display: none; }
        .content-section.active { display: block; }
        .technician-empty { text-align: center; color: var(--text-tertiary); padding: 28px 16px !important; }
        .technician-inline-form { display: flex; gap: 8px; align-items: center; flex-wrap: nowrap; }
        .technician-inline-form .form-select { min-width: 130px; }
        .technician-card-note { color: var(--text-tertiary); font-size: 13px; margin: 0; }
        @media (max-width: 767px) {
            .technician-inline-form { flex-wrap: wrap; }
            .technician-inline-form .form-select,
            .technician-inline-form .btn { width: 100%; }
        }
    </style>
</head>
<body>

<div class="dashboard-layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="sidebar-logo-icon"><i class="bi bi-tools"></i></div>
                <div class="sidebar-logo-text">
                    <div class="sidebar-logo-title">Clarenzon Medical</div>
                    <div class="sidebar-logo-subtitle">Technician</div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Main</div>
            <div class="nav-group open">
                <button class="nav-group-toggle active-group" type="button" onclick="toggleNavGroup(this)">
                    <i class="bi bi-grid-1x2-fill nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                    <i class="bi bi-chevron-right nav-chevron"></i>
                </button>
                <div class="nav-group-items">
                    <button class="nav-subitem active" type="button" onclick="showSection('dashboard', this)">Overview</button>
                </div>
            </div>

            <div class="nav-section-label">Technician Work</div>
            <div class="nav-group">
                <button class="nav-group-toggle" type="button" onclick="toggleNavGroup(this)">
                    <i class="bi bi-clipboard-check nav-icon"></i>
                    <span class="nav-text">Job Orders</span>
                    <i class="bi bi-chevron-right nav-chevron"></i>
                </button>
                <div class="nav-group-items">
                    <button class="nav-subitem" type="button" onclick="showSection('job-orders', this)">My Job Orders</button>
                    <button class="nav-subitem" type="button" onclick="showSection('issued-supplies', this)">Issued Supplies</button>
                    <button class="nav-subitem" type="button" onclick="showSection('work-status', this)">Work Status / Report</button>
                </div>
            </div>

            <div class="nav-section-label">User Record / Attendance</div>
            <div class="nav-group">
                <button class="nav-group-toggle" type="button" onclick="toggleNavGroup(this)">
                    <i class="bi bi-clock-history nav-icon"></i>
                    <span class="nav-text">User Record</span>
                    <i class="bi bi-chevron-right nav-chevron"></i>
                </button>
                <div class="nav-group-items">
                    <button class="nav-subitem" type="button" onclick="showSection('audit-log', this)">Audit Log</button>
                </div>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= htmlspecialchars($full_name) ?></div>
                    <div class="sidebar-user-role">Technician</div>
                </div>
                <a href="?logout=1" class="sidebar-logout" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
            </div>
        </div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

    <div class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="topbar-toggle" id="sidebarToggle" type="button" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                <div class="topbar-breadcrumb">
                    <span class="topbar-breadcrumb-item">Technician</span>
                    <i class="bi bi-chevron-right topbar-breadcrumb-sep"></i>
                    <span class="topbar-breadcrumb-current" id="breadcrumbCurrent">Overview</span>
                </div>
            </div>

            <div class="topbar-center">
                <div class="topbar-search">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search products..." id="globalSearch" onkeyup="searchTable()">
                </div>
            </div>

            <div class="topbar-right">
                <button class="theme-toggle" id="themeToggle" type="button" onclick="toggleTheme()" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <div class="topbar-divider"></div>
                <div class="topbar-user">
                    <div class="topbar-user-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
                    <div class="topbar-user-info">
                        <div class="topbar-user-name"><?= htmlspecialchars($full_name) ?></div>
                        <div class="topbar-user-role">Technician</div>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-area">
            <?php if ($alert): echo $alert; endif; ?>

            <div id="section-dashboard" class="content-section active">
                <div class="page-header">
                    <h1>Welcome <?= htmlspecialchars($welcomeRoleLabel) ?></h1>
                    <p>Welcome back, <?= htmlspecialchars($first_name ?: $full_name) ?>. Review product availability and your assigned technician work.</p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card blue">
                        <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-box-seam"></i></div></div>
                        <div class="stat-card-label">Total Products</div>
                        <div class="stat-card-value"><?= count($inventory_items) ?></div>
                        <div class="stat-card-change neutral"><i class="bi bi-boxes"></i> Available catalog items</div>
                    </div>
                    <div class="stat-card emerald">
                        <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-check-circle"></i></div></div>
                        <div class="stat-card-label">In Stock</div>
                        <div class="stat-card-value"><?= count(array_filter($inventory_items, fn($i) => $i[5] === 'In Stock')) ?></div>
                        <div class="stat-card-change up"><i class="bi bi-check"></i> Sufficient</div>
                    </div>
                    <div class="stat-card amber">
                        <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-exclamation-triangle"></i></div></div>
                        <div class="stat-card-label">Low Stock</div>
                        <div class="stat-card-value"><?= count(array_filter($inventory_items, fn($i) => $i[5] === 'Low Stock')) ?></div>
                        <div class="stat-card-change down"><i class="bi bi-arrow-down"></i> Need ordering</div>
                    </div>
                    <div class="stat-card violet">
                        <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-clipboard-check"></i></div></div>
                        <div class="stat-card-label">Assigned Jobs</div>
                        <div class="stat-card-value"><?= count($job_orders) ?></div>
                        <div class="stat-card-change neutral"><i class="bi bi-tools"></i> Technician work queue</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title"><i class="bi bi-box-seam text-primary-color"></i> All Products - Stock Availability</div>
                            <p class="technician-card-note">View product availability from the shared inventory catalog.</p>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-container system-table-scroll">
                            <table class="modern-table" id="productsTable">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th>Available Quantity</th>
                                        <th>Unit Price</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($inventory_items as $item): ?>
                                    <tr>
                                        <td><span class="badge badge-blue"><?= htmlspecialchars($item[0]) ?></span></td>
                                        <td><?= htmlspecialchars($item[1]) ?></td>
                                        <td><?= htmlspecialchars($item[2]) ?></td>
                                        <td><?= (int)$item[3] ?></td>
                                        <td>&#8369;<?= number_format($item[4], 2) ?></td>
                                        <td>
                                            <?php if ($item[5] === 'In Stock'): ?>
                                                <span class="badge badge-green"><?= htmlspecialchars($item[5]) ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-yellow"><?= htmlspecialchars($item[5]) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="section-job-orders" class="content-section">
                <div class="page-header">
                    <h1>My Job Orders</h1>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-clipboard-check text-primary-color"></i> Assigned Job Orders</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-container system-table-scroll">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Job Order</th>
                                        <th>Equipment / Item</th>
                                        <th>Task Description</th>
                                        <th>Assigned Date</th>
                                        <th>Instructions / Remarks</th>
                                        <th>Status</th>
                                        <th>Update</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($job_orders)): ?>
                                <?php foreach($job_orders as $job): ?>
                                    <tr>
                                        <td><span class="badge badge-blue"><?= htmlspecialchars($job['job_order_no']) ?></span></td>
                                        <td><?= htmlspecialchars($job['equipment_item'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($job['task_description']) ?></td>
                                        <td><?= htmlspecialchars($job['assigned_date']) ?></td>
                                        <td><?= htmlspecialchars($job['instructions'] ?: 'N/A') ?></td>
                                        <td><span class="badge <?= $job['status'] === 'Completed' ? 'badge-green' : ($job['status'] === 'Ongoing' ? 'badge-yellow' : 'badge-blue') ?>"><?= htmlspecialchars($job['status']) ?></span></td>
                                        <td>
                                            <form method="POST" class="technician-inline-form">
                                                <input type="hidden" name="job_order_id" value="<?= (int)$job['id'] ?>">
                                                <select name="work_status" class="form-select form-select-sm">
                                                    <option value="Pending" <?= $job['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="Ongoing" <?= $job['status'] === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                                    <option value="Completed" <?= $job['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                                </select>
                                                <button type="submit" name="update_job_status" class="btn btn-sm btn-primary">Save</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="technician-empty"><i class="bi bi-inbox d-block mb-2" style="font-size:24px;"></i>No assigned job orders found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="section-issued-supplies" class="content-section">
                <div class="page-header">
                    <h1>Issued Supplies</h1>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-box-arrow-up text-primary-color"></i> Record Issued Supplies</div>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Job Order</label>
                                <select name="issue_job_order_id" class="form-select" required>
                                    <option value="">Select Job Order</option>
                                    <?php foreach($job_orders as $job): ?>
                                        <option value="<?= (int)$job['id'] ?>"><?= htmlspecialchars($job['job_order_no']) ?> - <?= htmlspecialchars($job['status']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Product / Supply</label>
                                <select name="issue_product_id" class="form-select" required>
                                    <option value="">Select Product</option>
                                    <?php foreach($inventory_items as $item): ?>
                                        <option value="<?= (int)$item[7] ?>"><?= htmlspecialchars($item[1]) ?> (<?= htmlspecialchars($item[0]) ?>) - Stock: <?= (int)$item[3] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="issue_quantity" class="form-control" min="1" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="issue_remarks" class="form-control" placeholder="Optional remarks">
                            </div>
                            <div class="col-12">
                                <button type="submit" name="issue_supply" class="btn btn-primary"><i class="bi bi-save me-1"></i> Record Issued Supply</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-journal-text text-primary-color"></i> My Issued Supplies</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-container system-table-scroll">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Date / Time</th>
                                        <th>Job Order</th>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Reference</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($issued_supplies)): ?>
                                <?php foreach($issued_supplies as $issued): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($issued['issued_at']) ?></td>
                                        <td><span class="badge badge-blue"><?= htmlspecialchars($issued['job_order_no']) ?></span></td>
                                        <td><?= htmlspecialchars($issued['product_name']) ?> (<?= htmlspecialchars($issued['sku']) ?>)</td>
                                        <td><?= (int)$issued['quantity'] ?></td>
                                        <td><?= htmlspecialchars($issued['reference_number']) ?></td>
                                        <td><?= htmlspecialchars($issued['remarks'] ?: 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="technician-empty"><i class="bi bi-inbox d-block mb-2" style="font-size:24px;"></i>No issued supplies recorded.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="section-work-status" class="content-section">
                <div class="page-header">
                    <h1>Work Status / Report</h1>
                </div>

                <div class="stats-grid">
                    <div class="stat-card amber">
                        <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-hourglass-split"></i></div></div>
                        <div class="stat-card-label">Pending</div>
                        <div class="stat-card-value"><?= count(array_filter($job_orders, fn($j) => $j['status'] === 'Pending')) ?></div>
                    </div>
                    <div class="stat-card blue">
                        <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-arrow-repeat"></i></div></div>
                        <div class="stat-card-label">Ongoing</div>
                        <div class="stat-card-value"><?= count(array_filter($job_orders, fn($j) => $j['status'] === 'Ongoing')) ?></div>
                    </div>
                    <div class="stat-card emerald">
                        <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-check2-circle"></i></div></div>
                        <div class="stat-card-label">Completed</div>
                        <div class="stat-card-value"><?= count(array_filter($job_orders, fn($j) => $j['status'] === 'Completed')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-bar-chart text-primary-color"></i> Job Status Summary</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-container system-table-scroll">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Job Order</th>
                                        <th>Equipment / Item</th>
                                        <th>Assigned Date</th>
                                        <th>Status</th>
                                        <th>Completed At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if(!empty($job_orders)): ?>
                                    <?php foreach($job_orders as $job): ?>
                                        <tr>
                                            <td><span class="badge badge-blue"><?= htmlspecialchars($job['job_order_no']) ?></span></td>
                                            <td><?= htmlspecialchars($job['equipment_item'] ?: 'N/A') ?></td>
                                            <td><?= htmlspecialchars($job['assigned_date']) ?></td>
                                            <td><span class="badge <?= $job['status'] === 'Completed' ? 'badge-green' : ($job['status'] === 'Ongoing' ? 'badge-yellow' : 'badge-blue') ?>"><?= htmlspecialchars($job['status']) ?></span></td>
                                            <td><?= htmlspecialchars($job['completed_at'] ?: 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="technician-empty"><i class="bi bi-inbox d-block mb-2" style="font-size:24px;"></i>No job orders found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="section-audit-log" class="content-section">
                <div class="page-header">
                    <h1>Audit Log</h1>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-clock-history text-primary-color"></i> Technician Activity Logs</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-container system-table-scroll">
                            <table class="modern-table">
                                <thead><tr><th>Date / Time</th><th>Action</th><th>Details</th></tr></thead>
                                <tbody>
                                <?php if(!empty($audit_logs)): ?>
                                    <?php foreach($audit_logs as $log): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($log['created_at']) ?></td>
                                            <td><span class="badge badge-yellow"><?= htmlspecialchars($log['action_key']) ?></span></td>
                                            <td><?= htmlspecialchars($log['details'] ?: 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="technician-empty"><i class="bi bi-inbox d-block mb-2" style="font-size:24px;"></i>No Technician audit records found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="java/technician.js"></script>

</body>
</html>
