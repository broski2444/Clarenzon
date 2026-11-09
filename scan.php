<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'warehouse_permissions.php';

clarenzon_session_start_any_authenticated();
route_require_login();

if (isset($_GET['logout'])) {
    clarenzon_logout_current();
    header('Location: login.php');
    exit();
}

$role = (string)($_SESSION['role'] ?? '');
$roleKey = function_exists('normalize_user_role') ? normalize_user_role($role) : scannerRoleKey($role);
$full_name = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
$canScan = canUseMobileBarcodeScanner($role);
$isSystemAdmin = ($roleKey === 'admin');
$isWarehouseHead = ($roleKey === 'warehouse_head');
$canManageRecords = $isSystemAdmin;
$scannerStandalone = true;
$scannerHome = route_home_for_role($role);
$assetBase = '';

$navItems = [];
if ($canScan) {
    $navItems[] = ['label' => 'Dashboard', 'href' => $scannerHome, 'icon' => 'bi-grid-1x2'];
    $navItems[] = ['label' => 'Barcode Scanner', 'href' => 'scan.php', 'icon' => 'bi-upc-scan', 'current' => true];
    $navItems[] = ['label' => 'Inventory', 'href' => $scannerHome . '#stock-inventory', 'icon' => 'bi-boxes'];
    $navItems[] = ['label' => 'Incoming Deliveries', 'href' => $scannerHome . '#incoming-deliveries', 'icon' => 'bi-truck'];
    $navItems[] = ['label' => 'Stock-In Queue', 'href' => $scannerHome . '#stock-in-queue', 'icon' => 'bi-box-arrow-in-down'];
    if ($isSystemAdmin) {
        $navItems[] = ['label' => 'Reports', 'href' => $scannerHome . '#reports', 'icon' => 'bi-graph-up'];
        $navItems[] = ['label' => 'Users', 'href' => $scannerHome . '#users', 'icon' => 'bi-people'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title><?= $canScan ? 'Barcode Scanner' : 'Access Denied' ?> | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/admin-theme.css">
    <link rel="stylesheet" href="css/popup-center.css">
</head>
<body>
<?php if (!$canScan): ?>
<div class="content-area" style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div class="card" style="max-width:420px;width:100%;">
        <div class="card-body text-center p-4">
            <img src="images/clarenzon-removebg.png" alt="Clarenzon" style="width:72px;height:auto;margin-bottom:12px;">
            <h1 class="h4 mb-2">Access Denied</h1>
            <p class="text-muted mb-3">You do not have permission to use the mobile barcode scanner.</p>
            <a class="btn btn-primary" href="<?= htmlspecialchars($scannerHome) ?>">Back to Dashboard</a>
            <a class="btn btn-outline-secondary ms-1" href="scan.php?logout=1">Logout</a>
        </div>
    </div>
</div>
</body>
</html>
<?php exit(); endif; ?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <div class="sidebar-logo-icon"><i class="bi bi-heart-pulse"></i></div>
            <div class="sidebar-logo-text">
                <div class="sidebar-logo-title">Clarenzon Medical</div>
                <div class="sidebar-logo-subtitle">Inventory System</div>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($navItems as $item): ?>
        <a class="nav-item<?= !empty($item['current']) ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
            <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
            <span class="nav-text"><?= htmlspecialchars($item['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= strtoupper(substr($full_name !== '' ? $full_name : 'U', 0, 1)) ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="sidebar-user-role"><?= htmlspecialchars($isWarehouseHead ? 'Warehouse Head' : 'Admin') ?></div>
            </div>
            <a href="scan.php?logout=1" class="sidebar-logout" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<div class="main-content scanner-standalone-main">
    <header class="topbar">
        <button class="topbar-toggle" id="sidebarToggle" type="button" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>
        <div class="topbar-title"><i class="bi bi-upc-scan me-2"></i>Barcode Scanner</div>
        <a class="topbar-icon-btn" href="<?= htmlspecialchars($scannerHome) ?>" title="Dashboard"><i class="bi bi-person-circle"></i></a>
    </header>
    <div class="content-area">
        <?php require __DIR__ . '/includes/ui_barcode_scanner.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
window.CLAR_AUTH_CONTEXT = <?= json_encode($roleKey) ?>;
window.currentRoleKey = <?= json_encode($roleKey) ?>;
window.isWarehouseHeadRole = <?= $isWarehouseHead ? 'true' : 'false' ?>;
window.dashboardHomeUrl = <?= json_encode($scannerHome) ?>;
window.CLAR_DENIED_SECTIONS = <?= json_encode($isSystemAdmin ? [] : ['users', 'reports']) ?>;
</script>
<script src="java/app_dialog.js?v=<?= time() ?>"></script>
<script src="java/auth_context.js"></script>
<script src="java/dashboard_main.js"></script>
<script src="java/admin_modules/admin_scanner.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const section = document.getElementById('section-barcode-scanner');
    if (section) {
        section.classList.add('active');
        section.style.display = 'block';
    }
    if (typeof dashLoadRecentScans === 'function') dashLoadRecentScans();
});
</script>
</body>
</html>
