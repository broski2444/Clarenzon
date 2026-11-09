<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once 'config.php';
require_once 'route_protection.php';
require_once 'warehouse_permissions.php';

route_require_login(['warehouse_head', 'warehouse_assistant']);

$username  = $_SESSION['username'] ?? '';
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
$role      = $_SESSION['role'] ?? '';
$isWarehouseHead = isWarehouseHeadRole((string)$role);
$isWarehouseAssistant = isWarehouseAssistantRole((string)$role);

if (!$isWarehouseHead && !$isWarehouseAssistant) {
    header("Location: admin_dashboard.php");
    exit();
}

$permConn = clarenzon_db_connect_first(['inventory_db', 'clarenzon_db']);
if (!$permConn) {
    die("Connection failed: database is unavailable.");
}

$assistantPermissions = getWarehouseAssistantPermissions($permConn);
$assistantCanManageRecords = !empty($assistantPermissions['can_manage_records']);
$assistantCanPrintReceipts = !empty($assistantPermissions['can_print_receipts']);
$canManageRecords = $isWarehouseHead || $isWarehouseAssistant;
$canPrintRecords = $isWarehouseHead || ($isWarehouseAssistant && $assistantCanPrintReceipts);

if (isset($_GET['logout'])) {
    session_destroy();
    $permConn->close();
    header("Location: login.php");
    exit();
}

function whFindOrCreateCategory(mysqli $conn, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $stmt->close();
        return (int)$row['id'];
    }
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO categories (name, status) VALUES (?, 'Active')");
    if (!$stmt) return null;
    $stmt->bind_param("s", $name);
    $ok = $stmt->execute();
    $id = $ok ? (int)$conn->insert_id : null;
    $stmt->close();
    return $id;
}

function whFindOrCreateLocation(mysqli $conn, string $storageArea): ?int {
    $storageArea = trim($storageArea);
    if ($storageArea === '') return null;
    $stmt = $conn->prepare("SELECT id FROM storage_locations WHERE storage_area = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $storageArea);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $stmt->close();
        return (int)$row['id'];
    }
    $stmt->close();
    $locationCode = 'LOC-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $stmt = $conn->prepare("INSERT INTO storage_locations (location_code, storage_area, status) VALUES (?, ?, 'Active')");
    if (!$stmt) return null;
    $stmt->bind_param("ss", $locationCode, $storageArea);
    $ok = $stmt->execute();
    $id = $ok ? (int)$conn->insert_id : null;
    $stmt->close();
    return $id;
}

$products = [];
$warehouse_locations = [];
$stock_chart_labels = [];
$stock_chart_in = [];
$stock_chart_out = [];

$alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assistant_permissions'])) {
    if (!$isWarehouseHead) {
        $_SESSION['flash_alert'] = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Warehouse Head can update assistant permissions.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $canManageRecords = isset($_POST['assistant_can_manage_records']);
    $canPrintReceipts = isset($_POST['assistant_can_print_receipts']);
    $updatedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $ok = setWarehouseAssistantPermissions($permConn, $canManageRecords, $canPrintReceipts, $updatedBy);
    $_SESSION['flash_alert'] = $ok
        ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Warehouse Assistant permissions updated.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
        : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update Warehouse Assistant permissions.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    if (!$canManageRecords) {
        $_SESSION['flash_alert'] = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> You have view-only access.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $sku = trim($_POST['edit_sku'] ?? '');
    if ($sku !== '') {
        $name = trim($_POST['edit_name'] ?? '');
        $categoryId = whFindOrCreateCategory($permConn, trim($_POST['edit_cat'] ?? ''));
        $locationId = whFindOrCreateLocation($permConn, trim($_POST['edit_loc'] ?? ''));
        $qty = max(0, (int)($_POST['edit_qty'] ?? 0));
        $price = max(0, (float)($_POST['edit_price'] ?? 0));
        $status = $qty <= 0 ? 'Out of Stock' : 'Active';
        $stmt = $permConn->prepare(
            "UPDATE products
             SET name = ?, category_id = ?, quantity_in_stock = ?, selling_price = ?, unit_price = ?, location_id = ?, status = ?
             WHERE sku = ?"
        );
        if ($stmt) {
            $stmt->bind_param("siiddiss", $name, $categoryId, $qty, $price, $price, $locationId, $status, $sku);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['flash_alert'] = $ok
                ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Product updated successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update product.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $_SESSION['flash_alert'] = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to prepare product update.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_adjustment'])) {
    if (!$canManageRecords) {
        $_SESSION['flash_alert'] = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> You have view-only access.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $sku = trim($_POST['product'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $qty = max(0, (int)($_POST['quantity'] ?? 0));
    $reference = trim($_POST['reference'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    if ($sku === '' || $type === '' || $qty <= 0 || $reference === '') {
        $_SESSION['flash_alert'] = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Product, type, quantity, and reference are required.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $stmt = $permConn->prepare("SELECT id, quantity_in_stock, cost_price, selling_price FROM products WHERE sku = ? LIMIT 1");
    if (!$stmt) {
        $_SESSION['flash_alert'] = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to prepare adjustment.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $stmt->bind_param("s", $sku);
    $stmt->execute();
    $res = $stmt->get_result();
    $product = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$product) {
        $_SESSION['flash_alert'] = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Selected product was not found.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $productId = (int)$product['id'];
    $beforeQty = (int)$product['quantity_in_stock'];
    $adjustmentType = in_array($type, ['Increase'], true) ? 'Increase' : 'Decrease';
    $movementType = 'Adjustment';
    $afterQty = $adjustmentType === 'Increase' ? $beforeQty + $qty : max(0, $beforeQty - $qty);
    $unitPrice = (float)($product['cost_price'] ?: $product['selling_price']);
    $totalValue = $qty * $unitPrice;
    $status = $afterQty <= 0 ? 'Out of Stock' : 'Active';
    $adjustmentNumber = 'ADJ-' . date('YmdHis') . '-' . random_int(10, 99);

    $permConn->begin_transaction();
    try {
        $stmt = $permConn->prepare("UPDATE products SET quantity_in_stock = ?, status = ? WHERE id = ?");
        if (!$stmt) throw new Exception($permConn->error);
        $stmt->bind_param("isi", $afterQty, $status, $productId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();

        $stmt = $permConn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception($permConn->error);
        $stmt->bind_param("isiddsis", $productId, $movementType, $qty, $unitPrice, $totalValue, $reference, $userId, $reason);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();

        $allowedReasons = ['Damage', 'Expiry', 'Loss', 'Found', 'Count Correction', 'Return', 'Other'];
        $adjustmentReason = in_array($type, $allowedReasons, true) ? $type : 'Other';
        $adjustmentNotes = trim($reason !== '' ? $reason : $type);
        $stmt = $permConn->prepare("INSERT INTO stock_adjustments (adjustment_number, product_id, adjustment_type, quantity_before, quantity_adjusted, quantity_after, reason, reference_number, adjusted_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception($permConn->error);
        $stmt->bind_param("sisiiissis", $adjustmentNumber, $productId, $adjustmentType, $beforeQty, $qty, $afterQty, $adjustmentReason, $reference, $userId, $adjustmentNotes);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();

        $permConn->commit();
        $_SESSION['flash_alert'] = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Stock adjustment recorded successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } catch (Throwable $e) {
        $permConn->rollback();
        $_SESSION['flash_alert'] = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to record stock adjustment.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_SESSION['flash_alert'])) {
    $alert = $_SESSION['flash_alert'];
    unset($_SESSION['flash_alert']);
}

$assistantPermissions = getWarehouseAssistantPermissions($permConn);

$productResult = $permConn->query(
    "SELECT p.sku, p.name, COALESCE(c.name, 'Uncategorized') AS category_name,
            p.quantity_in_stock, COALESCE(NULLIF(p.selling_price, 0), p.unit_price, p.cost_price) AS display_price,
            COALESCE(CONCAT_WS(' - ', NULLIF(sl.storage_area, ''), NULLIF(sl.shelf_rack, '')), 'Unassigned') AS location_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     LEFT JOIN storage_locations sl ON p.location_id = sl.id
     WHERE p.status != 'Discontinued'
     ORDER BY p.name"
);
if ($productResult) {
    while ($row = $productResult->fetch_assoc()) {
        $products[] = [
            $row['sku'],
            $row['name'],
            $row['category_name'],
            (int)$row['quantity_in_stock'],
            (float)$row['display_price'],
            $row['location_name'] ?: 'Unassigned',
        ];
    }
}

$locationResult = $permConn->query(
    "SELECT sl.storage_area,
            COALESCE(NULLIF(CONCAT_WS(' ', NULLIF(sl.shelf_rack, ''), NULLIF(sl.section, '')), ''), sl.location_code) AS location_name,
            COALESCE(c.name, 'Uncategorized') AS category_name,
            COALESCE(SUM(CASE WHEN p.status != 'Discontinued' THEN p.quantity_in_stock ELSE 0 END), 0) AS current_items,
            sl.capacity,
            sl.status
     FROM storage_locations sl
     LEFT JOIN categories c ON sl.category_id = c.id
     LEFT JOIN products p ON p.location_id = sl.id
     GROUP BY sl.id, sl.storage_area, sl.shelf_rack, sl.section, sl.location_code, c.name, sl.capacity, sl.status
     ORDER BY sl.storage_area, sl.shelf_rack, sl.section"
);
if ($locationResult) {
    while ($row = $locationResult->fetch_assoc()) {
        $warehouse_locations[] = $row;
    }
}

$movementResult = $permConn->query(
    "SELECT DATE(created_at) AS movement_date,
            SUM(CASE WHEN movement_type IN ('Stock In', 'Purchase', 'Return') THEN quantity ELSE 0 END) AS stock_in,
            SUM(CASE WHEN movement_type IN ('Stock Out', 'Sale', 'Transfer') THEN quantity ELSE 0 END) AS stock_out
     FROM stock_movements
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 27 DAY)
     GROUP BY DATE(created_at)
     ORDER BY movement_date"
);
if ($movementResult) {
    while ($row = $movementResult->fetch_assoc()) {
        $stock_chart_labels[] = date('M j', strtotime($row['movement_date']));
        $stock_chart_in[] = (int)$row['stock_in'];
        $stock_chart_out[] = (int)$row['stock_out'];
    }
}
if (!$stock_chart_labels) {
    $stock_chart_labels = ['No movement'];
    $stock_chart_in = [0];
    $stock_chart_out = [0];
}

$total_value = 0;
$low_stock   = 0;
$total_items = 0;
foreach ($products as $p) {
    $total_value += $p[3] * $p[4];
    $total_items += $p[3];
    if ($p[3] <= 20) $low_stock++;
}
$permConn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title>Warehouse Head | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-theme.css?v=<?= @filemtime(__DIR__ . '/css/admin-theme.css') ?: time() ?>">
    <link rel="stylesheet" href="css/popup-center.css?v=<?= @filemtime(__DIR__ . '/css/popup-center.css') ?: time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }

        /* ── Sidebar (dark — matches admin) ── */
        .sidebar { position: fixed; left: 0; top: 0; width: 240px; height: 100vh; background: #1e293b; border-right: 1px solid #cbd5e1; z-index: 1000; display: flex; flex-direction: column; }
        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid #475569; }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-icon { width: 36px; height: 36px; background: #2563eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 16px; }
        .logo-text { font-size: 15px; font-weight: 700; color: #f1f5f9; line-height: 1.2; }
        .logo-subtitle { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .sidebar-nav { flex: 1; padding: 20px 12px; overflow-y: auto; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; margin-bottom: 4px; border-radius: 8px; color: #94a3b8; text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; cursor: pointer; }
        .nav-item:hover { background: #334155; color: #f1f5f9; }
        .nav-item.active { background: #3b82f6; color: white; }
        .nav-item i { font-size: 18px; width: 20px; text-align: center; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #475569; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px; }
        .user-info { flex: 1; }
        .user-name { font-size: 13px; font-weight: 600; color: #f1f5f9; line-height: 1.2; }
        .user-role { font-size: 11px; color: #94a3b8; }

        /* ── Main layout ── */
        .main-content { margin-left: 240px; min-height: 100vh; }
        .top-bar { background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 20px; font-weight: 700; color: #1e293b; }
        .content-area { padding: 32px; }
        .content-section { display: none; }
        .content-section.active { display: block; }

        /* ── Stats ── */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; }
        .stat-label { font-size: 13px; color: #64748b; font-weight: 500; margin-bottom: 8px; }
        .stat-value { font-size: 32px; font-weight: 700; color: #1e293b; line-height: 1; }
        .stat-change { font-size: 12px; color: #10b981; margin-top: 8px; }

        /* ── Cards ── */
        .card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 0; }

        /* ── Tables ── */
        .table-container { overflow-x: auto !important; max-width: 100%; min-width: 0; }
        .modern-table { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; }
        .modern-table thead th { background: #f8fafc; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; }
        .modern-table tbody td { padding: 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #374151; }
        .modern-table tbody tr:hover { background: #f8fafc; }

        /* ── Badges ── */
        .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; }
        .badge-blue   { background: #dbeafe; color: #2563eb; }
        .badge-green  { background: #d1fae5; color: #059669; }
        .badge-orange { background: #fed7aa; color: #ea580c; }
        .badge-red    { background: #fecaca; color: #dc2626; }

        /* ── Buttons ── */
        .btn-icon { width: 32px; height: 32px; border-radius: 6px; border: 1px solid #cbd5e1; background: white; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; transition: all 0.2s; }
        .btn-icon:hover { background: #f1f5f9; color: #1e293b; }
        .btn-icon.delete:hover { background: #fecaca; color: #dc2626; }
        .btn-primary { background: #2563eb; color: white; padding: 10px 20px; border-radius: 8px; border: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #1d4ed8; }
        .logout-btn { width: 32px; height: 32px; border-radius: 6px; border: 1px solid #cbd5e1; background: white; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #ef4444; transition: all 0.2s; }
        .logout-btn:hover { background: #fecaca; }

        /* ── Forms ── */
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .form-control:focus { outline: none; border-color: #2563eb; }
        .form-select { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .form-select:focus { outline: none; border-color: #2563eb; }

        /* ── Search box ── */
        .search-box { position: relative; width: 320px; }
        .search-box input { width: 100%; padding: 8px 16px 8px 40px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #f8fafc; }
        .search-box input:focus { outline: none; border-color: #2563eb; background: white; }
        .search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 16px; }

        /* ── Charts ── */
        .chart-container { position: relative; height: 300px; }

        /* ── Nav pills ── */
        .nav-pills .nav-link { color: #64748b; border-radius: 8px; padding: 10px 20px; transition: all 0.3s; font-size: 14px; }
        .nav-pills .nav-link.active { background: #2563eb; color: white; }
        .nav-pills .nav-link:hover:not(.active) { background: #f1f5f9; }

        /* ── Modals ── */
        .modal-content { border-radius: 12px; border: none; }
        .modal-header { border-bottom: 1px solid #e2e8f0; }

        /* ── Report extras ── */
        .export-btn { background: #10b981; color: white; padding: 10px 20px; border-radius: 8px; border: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .export-btn:hover { background: #059669; }
        .print-btn { background: white; color: #2563eb; border: 1px solid #2563eb; padding: 10px 20px; border-radius: 8px; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .print-btn:hover { background: #eff6ff; }

        @media print {
            .sidebar, .top-bar, .btn-primary, .print-btn, .export-btn, .nav-pills { display: none !important; }
            .main-content { margin-left: 0; }
        }
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
            .nav-pills { flex-wrap: nowrap; overflow-x: auto; }
            .nav-pills .nav-link { white-space: nowrap; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════ -->
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
        <a class="nav-item" onclick="showSection('products')">
            <i class="bi bi-box"></i><span>Products</span>
        </a>
        <a class="nav-item" onclick="showSection('locations')">
            <i class="bi bi-geo-alt"></i><span>Locations</span>
        </a>
        <a class="nav-item" onclick="showSection('adjustments')">
            <i class="bi bi-sliders"></i><span>Adjustments</span>
        </a>
        <a class="nav-item" onclick="showSection('reports')">
            <i class="bi bi-file-earmark-text"></i><span>Reports</span>
        </a>
        <a class="nav-item" onclick="showSection('scanner')">
            <i class="bi bi-qr-code-scan"></i><span>Scan Code</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="user-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="user-role"><?= htmlspecialchars($role) ?></div>
            </div>
            <a href="?logout=1" class="logout-btn"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MAIN CONTENT
════════════════════════════════════════════ -->
<div class="main-content">
    <div class="top-bar">
        <div class="page-title">Dashboard</div>
    </div>

    <div class="content-area">
        <?php if ($alert): echo $alert; endif; ?>
        <?php if (!$canManageRecords): ?>
            <div class="alert alert-info">
                <i class="bi bi-lock-fill"></i> View-only mode is active. Printing requires Warehouse Head permission.
            </div>
        <?php endif; ?>

        <!-- ── DASHBOARD ── -->
        <div id="section-dashboard" class="content-section active">
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Products</div>
                    <div class="stat-value"><?= count($products) ?></div>
                    <div class="stat-change"><i class="bi bi-arrow-up"></i> Active items</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Value</div>
                    <div class="stat-value">₱<?= number_format($total_value, 0) ?></div>
                    <div class="stat-change"><i class="bi bi-arrow-up"></i> Total worth</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Units</div>
                    <div class="stat-value"><?= number_format($total_items) ?></div>
                    <div class="stat-change"><i class="bi bi-box"></i> In stock</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Low Stock</div>
                    <div class="stat-value"><?= $low_stock ?></div>
                    <div class="stat-change" style="color:#ef4444;"><i class="bi bi-exclamation-triangle"></i> Needs reorder</div>
                </div>
            </div>

            <?php if ($isWarehouseHead): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Warehouse Assistant Permissions</div>
                </div>
                <form method="POST">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="assistant_can_manage_records" name="assistant_can_manage_records" <?= !empty($assistantPermissions['can_manage_records']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="assistant_can_manage_records">
                            Allow add, edit, and delete warehouse records
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="assistant_can_print_receipts" name="assistant_can_print_receipts" <?= !empty($assistantPermissions['can_print_receipts']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="assistant_can_print_receipts">
                            Allow receipt printing
                        </label>
                    </div>
                    <button type="submit" name="update_assistant_permissions" class="btn-primary">
                        <i class="bi bi-shield-lock me-2"></i>Save Permissions
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Stock Movement Trend</div>
                            <div class="d-flex gap-2"><button class="btn-icon"><i class="bi bi-three-dots"></i></button></div>
                        </div>
                        <div class="chart-container"><canvas id="stockChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><div class="card-title">Summary</div></div>
                        <div class="stat-value mb-3"><?= count($products) ?></div>
                        <div style="font-size:13px;color:#64748b;margin-bottom:24px;">Total SKUs</div>
                        <hr style="margin:20px 0;border-color:#e2e8f0;">
                        <div class="stat-value mb-3" style="font-size:24px;"><?= number_format($total_items) ?></div>
                        <div style="font-size:13px;color:#64748b;">Total Units</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── PRODUCTS ── -->
        <div id="section-products" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Product Management</div>
                </div>
                <div class="mb-3">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Search products..." onkeyup="filterTable(this, 'productsTable')">
                    </div>
                </div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table" id="productsTable">
                        <thead>
                            <tr>
                                <th>SKU</th><th>Product Name</th><th>Category</th>
                                <th>Quantity</th><th>Price</th><th>Location</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($products as $p): ?>
                            <tr data-sku="<?= htmlspecialchars($p[0]) ?>">
                                <td><span class="badge badge-blue"><?= htmlspecialchars($p[0]) ?></span></td>
                                <td><?= htmlspecialchars($p[1]) ?></td>
                                <td><?= htmlspecialchars($p[2]) ?></td>
                                <td>
                                    <?php if ($p[3] <= 20): ?>
                                        <span class="badge badge-red"><?= $p[3] ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-green"><?= $p[3] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>₱<?= number_format($p[4], 2) ?></td>
                                <td><?= htmlspecialchars($p[5]) ?></td>
                                <td>
                                    <?php if ($canManageRecords): ?>
                                        <button class="btn-icon" title="Edit"
                                            onclick="editProduct('<?= addslashes($p[0]) ?>','<?= addslashes($p[1]) ?>','<?= addslashes($p[2]) ?>',<?= $p[3] ?>,<?= $p[4] ?>,'<?= addslashes($p[5]) ?>')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">View only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── LOCATIONS ── -->
        <div id="section-locations" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Warehouse Locations</div>
                </div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table">
                        <thead>
                            <tr><th>Warehouse</th><th>Location</th><th>Category</th><th>Capacity</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($warehouse_locations): ?>
                                <?php foreach ($warehouse_locations as $loc): ?>
                                    <?php
                                        $capacityText = number_format((int)$loc['current_items']) . ' items';
                                        if (!empty($loc['capacity'])) {
                                            $capacityText .= ' / ' . number_format((int)$loc['capacity']);
                                        }
                                        $statusClass = $loc['status'] === 'Active' ? 'badge-green' : ($loc['status'] === 'Full' ? 'badge-orange' : 'badge-red');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($loc['storage_area']) ?></td>
                                        <td><?= htmlspecialchars($loc['location_name']) ?></td>
                                        <td><?= htmlspecialchars($loc['category_name']) ?></td>
                                        <td><span class="badge badge-blue"><?= htmlspecialchars($capacityText) ?></span></td>
                                        <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($loc['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-muted">No warehouse locations found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── ADJUSTMENTS ── -->
        <div id="section-adjustments" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Stock Adjustments</div>
                </div>
                <form method="POST">
                    <fieldset <?= $canManageRecords ? '' : 'disabled' ?>>
                    <div class="form-group">
                        <label class="form-label">Product <span style="color:#ef4444;">*</span></label>
                        <select name="product" class="form-select" required>
                            <option value="">Select a product</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?= htmlspecialchars($p[0]) ?>"><?= htmlspecialchars($p[1]) ?> (<?= htmlspecialchars($p[0]) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Adjustment Type <span style="color:#ef4444;">*</span></label>
                                <select name="type" class="form-select" required>
                                    <option value="">Select type</option>
                                    <option>Increase</option>
                                    <option>Decrease</option>
                                    <option>Damage</option>
                                    <option>Loss</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Quantity <span style="color:#ef4444;">*</span></label>
                                <input type="number" name="quantity" class="form-control" placeholder="Enter quantity" min="1" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="reference" class="form-control" placeholder="e.g., ADJ-2026-001" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Reason for adjustment"></textarea>
                    </div>
                    <button type="submit" name="submit_adjustment" class="btn-primary">
                        <i class="bi bi-save me-2"></i>Submit Adjustment
                    </button>
                    </fieldset>
                </form>
            </div>
        </div>

        <!-- ── REPORTS ── -->
        <div id="section-reports" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Warehouse Reports</div>
                    <div class="d-flex gap-2">
                        <?php if ($canPrintRecords): ?>
                            <button class="print-btn" onclick="window.print()"><i class="bi bi-printer"></i> Print Report</button>
                        <?php endif; ?>
                        <button class="export-btn" onclick="exportToCSV()"><i class="bi bi-download"></i> Export CSV</button>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-8">
                        <div class="card" style="margin:0;">
                            <div class="card-header"><div class="card-title">Stock Movement Trend</div></div>
                            <div class="chart-container"><canvas id="reportStockChart"></canvas></div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card" style="margin:0;">
                            <div class="card-header"><div class="card-title">Summary</div></div>
                            <div class="stat-value mb-3"><?= count($products) ?></div>
                            <div style="font-size:13px;color:#64748b;margin-bottom:24px;">Total SKUs</div>
                            <hr style="margin:20px 0;border-color:#e2e8f0;">
                            <div class="stat-value mb-3" style="font-size:24px;"><?= number_format($total_items) ?></div>
                            <div style="font-size:13px;color:#64748b;">Total Units</div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin:0;">
                    <div class="card-header">
                        <div class="card-title">Inventory Valuation Report</div>
                    </div>
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="valuationTable">
                            <thead>
                                <tr><th>SKU</th><th>Product</th><th>Quantity</th><th>Unit Price</th><th>Total Value</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach($products as $p): ?>
                                <tr>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($p[0]) ?></span></td>
                                    <td><?= htmlspecialchars($p[1]) ?></td>
                                    <td><?= $p[3] ?></td>
                                    <td>₱<?= number_format($p[4], 2) ?></td>
                                    <td><strong>₱<?= number_format($p[3] * $p[4], 2) ?></strong></td>
                                    <td>
                                        <?php if ($p[3] <= 20): ?>
                                            <span class="badge badge-red">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge badge-green">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc;font-weight:700;">
                                    <td colspan="4" style="text-align:right;padding:16px;color:#64748b;">TOTAL:</td>
                                    <td style="padding:16px;color:#2563eb;font-size:15px;">₱<?= number_format($total_value, 2) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="section-scanner" class="content-section">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">QR/Barcode Scanner</div>
                </div>
                <p>Scan a product code to auto-select it in stock forms.</p>
                <div id="wh-scanner-reader" style="max-width:420px;"></div>
                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-primary" onclick="startWarehouseScanner()"><i class="bi bi-camera-video me-1"></i>Start Scan</button>
                    <button type="button" class="btn btn-outline-primary" onclick="stopWarehouseScanner()"><i class="bi bi-stop-circle me-1"></i>Stop</button>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-md-3">
                        <label class="form-label">Movement</label>
                        <select id="wh-scan-movement" class="form-select">
                            <option value="stock_in">Stock In</option>
                            <option value="stock_out">Stock Out</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Qty</label>
                        <input type="number" id="wh-scan-qty" class="form-control" value="1" min="1">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Reference</label>
                        <input type="text" id="wh-scan-ref" class="form-control" placeholder="Auto if blank">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100" onclick="processWarehouseScan()">
                            <i class="bi bi-check-circle me-1"></i>Process Scan
                        </button>
                    </div>
                </div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="wh-scan-auto" checked>
                    <label class="form-check-label" for="wh-scan-auto">Auto process after scan</label>
                </div>
                <div id="wh-scan-result" class="mt-3" style="font-size:14px;color:#1e293b;"></div>
            </div>
        </div>

    </div><!-- /content-area -->
</div><!-- /main-content -->

<!-- ═══════════════════════════════════════════
     EDIT PRODUCT MODAL
════════════════════════════════════════════ -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="edit_sku" id="edit_sku">
                    <div class="form-group">
                        <label class="form-label">SKU</label>
                        <input type="text" id="edit_sku_display" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="edit_name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Category</label>
                                <input type="text" name="edit_cat" id="edit_cat" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="edit_qty" id="edit_qty" class="form-control" min="0" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Price</label>
                                <input type="number" step="0.01" name="edit_price" id="edit_price" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Location</label>
                                <input type="text" name="edit_loc" id="edit_loc" class="form-control">
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="update_product" class="btn-primary w-100">
                        <i class="bi bi-check-circle me-2"></i>Update Product
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
window.whChartData = {
    labels: <?= json_encode($stock_chart_labels) ?>,
    stockIn: <?= json_encode($stock_chart_in) ?>,
    stockOut: <?= json_encode($stock_chart_out) ?>
};
</script>
<script src="java/warehouse_head.js"></script>
<script src="java/realtime_audit.js"></script>
</body>
</html>

