<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once 'config.php';
require_once 'route_protection.php';
require_once 'warehouse_permissions.php';
require_once 'technician_job_orders.php';

route_require_login();
route_enforce_dashboard();

$username = $_SESSION['username'];
$user_id = (int)($_SESSION['user_id'] ?? 0);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_name_lib.php';
$full_name = userDisplayName([
    'first_name' => (string)($_SESSION['first_name'] ?? ''),
    'last_name' => (string)($_SESSION['last_name'] ?? ''),
    'full_name' => (string)($_SESSION['full_name'] ?? ''),
], (string)($username ?? ''));
$first_name = userFirstName([
    'first_name' => (string)($_SESSION['first_name'] ?? ''),
    'last_name' => (string)($_SESSION['last_name'] ?? ''),
    'full_name' => $full_name,
], $full_name);
$role = $_SESSION['role'];
$roleKey = normalize_user_role((string)$role);
$welcomeRoleLabels = [
    'admin' => 'Admin',
    'purchaser' => 'Purchaser',
    'warehouse_head' => 'Warehouse Head',
    'technician' => 'Technician',
];
$welcomeRoleLabel = $welcomeRoleLabels[$roleKey] ?? ucwords(str_replace('_', ' ', (string)$roleKey));
$isSystemAdmin = in_array($roleKey, ['admin'], true);
$isWarehouseHead = isWarehouseHeadRole((string)$role);
$isAdminWarehouse = isAdminWarehouseRole((string)$role);
$isWarehouseAssistant = isWarehouseAssistantRole((string)$role);
$isPurchaser = ($roleKey === 'purchaser');
$canManageRecords = $isAdminWarehouse || $isPurchaser || $isSystemAdmin;
$canPrintRecords = $isSystemAdmin;
$canApprovePOs = $isSystemAdmin;
$canDeleteRecords = $isSystemAdmin;
$canWarehouseLimitedProductUpdate = false;
$canManageSerialNumbers = false;
$canManageStockMovements = $canManageRecords;

if (isset($_GET['logout'])) {
    clarenzon_logout_current();
    header("Location: login.php");
    exit();
}

$db_user = 'root';
$db_pass = '';
$db_candidates = ['clarenzon_db', 'clarenzon_inventory', 'inventory_db'];
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first($db_candidates, $db_user, $db_pass, 3);

if (!$conn) {
    // Attempt auto-initialization if MySQL server is up but database is not yet created
    if (function_exists('initializeDatabase')) {
        initializeDatabase();
        $conn = clarenzon_db_connect_first($db_candidates, $db_user, $db_pass, 3);
    }
}

if (!$conn) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Connection Required | Clarenzon Medical</title>
        <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <style>
            body { background: #f8fafc; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
            .card-box { max-width: 540px; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(0,0,0,0.06); padding: 36px; text-align: center; }
            .icon-badge { width: 64px; height: 64px; background: #fee2e2; color: #dc2626; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 20px; }
            .step-list { text-align: left; background: #f1f5f9; padding: 16px 20px; border-radius: 12px; font-size: 14px; color: #334155; margin: 20px 0; }
            .step-list li { margin-bottom: 8px; }
            .step-list li:last-child { margin-bottom: 0; }
        </style>
    </head>
    <body>
        <div class="card-box">
            <div class="icon-badge"><i class="bi bi-database-fill-slash"></i></div>
            <h4 class="fw-bold text-dark mb-2">MySQL Server is Not Running</h4>
            <p class="text-muted fs-6 mb-3">The application cannot reach the MySQL database server on <code>localhost:3306</code>.</p>
            
            <div class="step-list">
                <strong><i class="bi bi-tools me-1"></i> How to resolve in XAMPP:</strong>
                <ol class="mt-2 mb-0 ps-3">
                    <li>Open your <strong>XAMPP Control Panel</strong> window.</li>
                    <li>Click the <strong>Start</strong> button next to <strong>MySQL</strong>.</li>
                    <li>Ensure the <strong>MySQL</strong> module status turns green.</li>
                    <li>If MySQL fails to start, check if another app (like Skype/MariaDB service) is using port 3306.</li>
                </ol>
            </div>

            <button onclick="window.location.reload()" class="btn btn-primary btn-lg w-100 fw-semibold rounded-3"><i class="bi bi-arrow-clockwise me-1"></i> Refresh Page</button>
        </div>
    </body>
    </html>
    <?php
    exit();
}

function ensureUserPermissionsSchema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS user_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        allowed_sections TEXT NULL,
        can_manage_records TINYINT(1) NOT NULL DEFAULT 1,
        can_print_records TINYINT(1) NOT NULL DEFAULT 1,
        can_approve_pos TINYINT(1) NOT NULL DEFAULT 1,
        can_delete_records TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_permissions_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $col = $conn->query("SHOW COLUMNS FROM user_permissions LIKE 'can_manage_pricing'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE user_permissions ADD COLUMN can_manage_pricing TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete_records");
    }
}

ensureUserPermissionsSchema($conn);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_name_lib.php';
if ($conn instanceof mysqli) {
    userNameEnsureSchema($conn);
}
require_once __DIR__ . '/procurement_receiving_lib.php';
if ($conn instanceof mysqli) {
    ensureReceivingWorkflowSchema($conn);
}
require_once 'billing_api.php';
require_once __DIR__ . '/sales_report_lib.php';

// ── FRESH USER ROLE & PERMISSION RESOLUTION ─────────────────────────────────
$userId = (int)($_SESSION['user_id'] ?? 0);
$userAllowedSections = null;

if ($conn && $userId > 0) {
    $uPermQuery = $conn->query("SELECT u.role, u.full_name, u.first_name, u.last_name, u.status, up.allowed_sections, up.can_manage_records, up.can_print_records, up.can_approve_pos, up.can_delete_records, up.can_manage_pricing 
                                FROM users u 
                                LEFT JOIN user_permissions up ON u.id = up.user_id 
                                WHERE u.id = $userId LIMIT 1");
    if ($uPermQuery && $uRow = $uPermQuery->fetch_assoc()) {
        if (!empty($uRow['role'])) {
            $role = $uRow['role'];
            $_SESSION['role'] = $role;
            $roleKey = normalize_user_role((string)$role);
            $welcomeRoleLabel = $welcomeRoleLabels[$roleKey] ?? ucwords(str_replace('_', ' ', (string)$roleKey));
            $isSystemAdmin = ($roleKey === 'admin');
            $isWarehouseHead = isWarehouseHeadRole((string)$role);
            $isAdminWarehouse = isAdminWarehouseRole((string)$role);
            $isWarehouseAssistant = isWarehouseAssistantRole((string)$role);
            $isPurchaser = ($roleKey === 'purchaser');
        }
        if (!empty($uRow['full_name']) || !empty($uRow['first_name']) || !empty($uRow['last_name'])) {
            $names = userNameNormalize(
                (string)($uRow['first_name'] ?? ''),
                (string)($uRow['last_name'] ?? ''),
                (string)($uRow['full_name'] ?? '')
            );
            $full_name = $names['full_name'] !== '' ? $names['full_name'] : $full_name;
            $first_name = $names['first_name'] !== '' ? $names['first_name'] : $first_name;
            $_SESSION['first_name'] = $names['first_name'];
            $_SESSION['last_name'] = $names['last_name'];
            $_SESSION['full_name'] = $full_name;
        }
        if (!empty($uRow['allowed_sections'])) {
            $userAllowedSections = array_map('trim', explode(',', $uRow['allowed_sections']));
        }
        $canManageRecords = $isSystemAdmin || !empty($uRow['can_manage_records']);
        $canPrintRecords = $isSystemAdmin || !empty($uRow['can_print_records']);
        $canApprovePOs = $isSystemAdmin || !empty($uRow['can_approve_pos']);
        $canDeleteRecords = $isSystemAdmin || !empty($uRow['can_delete_records']);
    }
}

// Warehouse Head: receive/stock-in only — never approve PR or PO
if ($isWarehouseHead) {
    $canApprovePOs = false;
}

$canManageReceiptPricing = !empty($isPurchaser) && ($conn instanceof mysqli) && $userId > 0
    && recvUserCanManageReceiptPricing($conn, $userId, true);

$canWarehouseLimitedProductUpdate = $isSystemAdmin || $isWarehouseHead || $canManageRecords;
$canManageSerialNumbers = $isSystemAdmin || $isWarehouseHead || $canManageRecords;
$canManageStockMovements = $canManageRecords && !$isWarehouseHead;

// Keep role flags in $GLOBALS for isSecAllowed() when this file is included via role_page_loader.
$GLOBALS['isSystemAdmin'] = $isSystemAdmin;
$GLOBALS['isPurchaser'] = $isPurchaser;
$GLOBALS['isWarehouseHead'] = $isWarehouseHead;
$GLOBALS['userAllowedSections'] = $userAllowedSections;

function isSecAllowed(string $secKey): bool {
    $aliases = [
        'sales-credit' => 'sales-orders',
        'stock-barcodes' => 'inventory',
        'pricing-queue' => 'markup-queue',
        'stock-inventory' => 'inventory',
        'product-catalog' => 'inventory',
        'po-creation' => 'purchase-orders',
        'po-approvals' => 'purchase-orders',
        'po-tracker' => 'purchase-orders',
        'pr-queue' => 'pr-approval',
        'price' => 'product-pricing',
    ];
    if (isset($aliases[$secKey])) {
        $secKey = $aliases[$secKey];
    }
    $isSystemAdmin = !empty($GLOBALS['isSystemAdmin']);
    $isPurchaser = !empty($GLOBALS['isPurchaser']);
    $isWarehouseHead = !empty($GLOBALS['isWarehouseHead']);
    $userAllowedSections = $GLOBALS['userAllowedSections'] ?? null;
    $procurementSections = ['procurement','purchase-requests','purchase-orders','purchase-receipts','incoming-deliveries','supplier-invoices','suppliers-view'];
    $purchaserOnlySections = ['purchase-history','delivery-schedule','purchaser-audit-log','markup-queue'];
    $adminHiddenSections = ['serials-view', 'sales-orders', 'billing-invoices', 'unpaid-invoices'];
    $warehouseHeadSections = ['dashboard','analytics','inventory','barcode-scanner','low-stock-view','expiry-view','location-stock-view','transfers-view','incoming-deliveries','purchase-orders','purchase-receipts','stock-in-queue','damage-returns','sales-orders','sales-credit','billing-invoices','unpaid-invoices','reports','sales-reports','account','warehouse-audit-log','stock-barcodes','product-pricing','customers'];
    $purchaserDefaultSections = [
        'dashboard','analytics','inventory','serials-view','low-stock-view','expiry-view',
        'procurement','purchase-requests','purchase-orders','purchase-receipts','incoming-deliveries','supplier-invoices','suppliers-view','customers',
        'purchase-history','delivery-schedule','purchaser-audit-log','markup-queue','reports','sales-reports','notifications','account'
    ];

    // Barcode scanner: Admin + Warehouse Head only (backend also enforces this).
    if ($secKey === 'barcode-scanner') {
        return $isSystemAdmin || $isWarehouseHead;
    }

    if ($isSystemAdmin) {
        if (in_array($secKey, $adminHiddenSections, true)) {
            return false;
        }
        return true; // Administrator sees remaining modules
    }

    if ($isPurchaser) {
        if (!in_array($secKey, $purchaserDefaultSections, true)) {
            return false;
        }
        if ($secKey === 'markup-queue' && is_array($userAllowedSections) && count($userAllowedSections) > 0) {
            return in_array('markup-queue', $userAllowedSections, true) || in_array('pricing-queue', $userAllowedSections, true);
        }
        return true;
    }

    if ($isWarehouseHead) {
        if (in_array($secKey, ['pr-approval', 'purchase-requests'], true)) {
            return false;
        }
        return in_array($secKey, $warehouseHeadSections, true);
    }

    if (in_array($secKey, $purchaserOnlySections, true)) {
        return false;
    }

    if (in_array($secKey, $procurementSections, true)) {
        return false;
    }

    if ($secKey === 'pr-approval') {
        return false;
    }

    if ($userAllowedSections === null) {
        return false;
    }
    return in_array($secKey, $userAllowedSections, true);
}

$canViewSalesReports = isSecAllowed('sales-reports');
$canExportSalesReports = $canViewSalesReports && !$isPurchaser && ($isSystemAdmin || $isWarehouseHead);
$salesReportFilters = salesReportReadFilters($_GET);
$salesReport = salesReportEmpty();
if ($canViewSalesReports && $conn instanceof mysqli) {
    $salesReport = salesReportLoad($conn, $salesReportFilters);
    salesReportMaybeExport($salesReport, $canExportSalesReports);
}

// Load all Sales Orders
$sales_orders = salesOrderLoadList($conn);
$sales_credit_agreements = salesOrderLoadAgreements($conn);
$salesTaxRate = billingGetTaxRate($conn);

// Load all Invoices
$invoices = [];
$invQuery = $conn->query("SELECT * FROM invoices ORDER BY created_at DESC");
if ($invQuery) {
    while ($row = $invQuery->fetch_assoc()) {
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

if ($conn instanceof mysqli) {
    billingAttachPaymentMetaBatch($conn, $invoices);
}
$pendingCheques = ($conn instanceof mysqli) ? billingLoadPendingCheques($conn) : [];

$assistantPermissions = getWarehouseAssistantPermissions($conn);
$assistantCanManageRecords = !empty($assistantPermissions['can_manage_records']);
$assistantCanPrintReceipts = !empty($assistantPermissions['can_print_receipts']);
$canManageRecords = ($isAdminWarehouse && !$isWarehouseHead) || ($isWarehouseAssistant && $assistantCanManageRecords) || $isPurchaser || $isSystemAdmin;
$isViewOnly = !$canManageRecords;
$canWarehouseLimitedProductUpdate = $isSystemAdmin || $isWarehouseHead || $canManageRecords;
$canManageSerialNumbers = $isSystemAdmin || $isWarehouseHead || $canManageRecords;
$canManageStockMovements = $canManageRecords && !$isWarehouseHead;
ensureUserPasswordPolicySchema($conn);
ensureTechnicianJobOrderSchema($conn);

function ensureSerialNumbersSchema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS serial_numbers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        serial_number VARCHAR(100) NOT NULL UNIQUE,
        status ENUM('Active','In Use','Returned','Retired','Lost','Damaged') NOT NULL DEFAULT 'Active',
        purchase_date DATE NULL,
        warranty_expiry DATE NULL,
        location_id INT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
        INDEX idx_serial_number (serial_number),
        INDEX idx_product (product_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB");
}
ensureSerialNumbersSchema($conn);

function generateSku(mysqli $conn): string {
    do {
        $sku = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);
    return $sku;
}

function resolveCategoryId(mysqli $conn, string $categoryName): ?int {
    if ($categoryName === '') return null;
    $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmt->bind_param("s", $categoryName);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $stmt->close(); return (int)$row['id']; }
    $stmt->close();
    $insert = $conn->prepare("INSERT INTO categories (name, status) VALUES (?, 'Active')");
    $insert->bind_param("s", $categoryName);
    if (!$insert->execute()) { $insert->close(); return null; }
    $id = (int)$insert->insert_id;
    $insert->close();
    return $id;
}

function resolveLocationId(mysqli $conn, string $locationText): ?int {
    $locationText = trim($locationText);
    if ($locationText === '') return null;
    $stmt = $conn->prepare("SELECT id FROM storage_locations WHERE LOWER(location_code) = LOWER(?) OR LOWER(CONCAT(storage_area, IF(shelf_rack IS NOT NULL AND shelf_rack <> '', CONCAT(' - ', shelf_rack), ''))) = LOWER(?) LIMIT 1");
    $stmt->bind_param("ss", $locationText, $locationText);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $stmt->close(); return (int)$row['id']; }
    $stmt->close();
    $locationCode = 'LOC-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $insert = $conn->prepare("INSERT INTO storage_locations (location_code, storage_area, shelf_rack, status) VALUES (?, ?, '', 'Active')");
    $insert->bind_param("ss", $locationCode, $locationText);
    if (!$insert->execute()) { $insert->close(); return null; }
    $id = (int)$insert->insert_id;
    $insert->close();
    return $id;
}

function ensureProductBarcodeSchema(mysqli $conn): bool {
    $hasBarcode = false;
    $col = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
    if ($col && $col->num_rows > 0) {
        $hasBarcode = true;
    } else {
        $ok = $conn->query("ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL AFTER name");
        if ($ok) {
            $hasBarcode = true;
        }
    }
    if ($hasBarcode) {
        $idx = $conn->query("SHOW INDEX FROM products WHERE Column_name = 'barcode' AND Non_unique = 0");
        if (!($idx && $idx->num_rows > 0)) {
            $conn->query("ALTER TABLE products ADD UNIQUE INDEX uq_products_barcode (barcode)");
        }
    }
    return $hasBarcode;
}

function ensureProductExpirySchema(mysqli $conn): bool {
    require_once __DIR__ . '/product_expiry_lib.php';
    $col = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_date'");
    if ($col && $col->num_rows > 0) {
        productExpiryEnsureSchema($conn);
        return true;
    }
    $ok = $conn->query("ALTER TABLE products ADD COLUMN expiry_date DATE NULL AFTER barcode");
    if ($ok) {
        $conn->query("ALTER TABLE products ADD INDEX idx_products_expiry_date (expiry_date)");
        productExpiryEnsureSchema($conn);
        return true;
    }
    return false;
}

function ensureProductBrandSchema(mysqli $conn): void {
    $cols = ['brand' => "VARCHAR(100) NULL AFTER barcode", 'size' => "VARCHAR(50) NULL AFTER brand", 'packaging' => "VARCHAR(100) NULL AFTER size"];
    foreach ($cols as $colName => $colDef) {
        $check = $conn->query("SHOW COLUMNS FROM products LIKE '{$colName}'");
        if (!($check && $check->num_rows > 0)) {
            $conn->query("ALTER TABLE products ADD COLUMN {$colName} {$colDef}");
        }
    }
    $reorderCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'reorder_level'");
    if (!($reorderCheck && $reorderCheck->num_rows > 0)) {
        $conn->query("ALTER TABLE products ADD COLUMN reorder_level INT DEFAULT 50 AFTER quantity_in_stock");
    }
}

function normalizeExpiryDate(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

function updateProductExpiryDate(mysqli $conn, string $sku, ?string $expiryDate): bool {
    if ($expiryDate === null) {
        $stmt = $conn->prepare("UPDATE products SET expiry_date = NULL WHERE sku = ?");
        if (!$stmt) { return false; }
        $stmt->bind_param("s", $sku);
    } else {
        $stmt = $conn->prepare("UPDATE products SET expiry_date = ? WHERE sku = ?");
        if (!$stmt) { return false; }
        $stmt->bind_param("ss", $expiryDate, $sku);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getProductImageColumn(mysqli $conn): ?string {
    foreach (['product_image', 'image', 'image_path', 'photo', 'photo_path', 'picture', 'picture_path'] as $column) {
        $safeColumn = $conn->real_escape_string($column);
        $check = $conn->query("SHOW COLUMNS FROM products LIKE '{$safeColumn}'");
        if ($check && $check->num_rows > 0) {
            return $column;
        }
    }
    return null;
}

function ensureProductImageSchema(mysqli $conn): ?string {
    $existingColumn = getProductImageColumn($conn);
    if ($existingColumn !== null) {
        return $existingColumn;
    }
    $ok = $conn->query("ALTER TABLE products ADD COLUMN product_image VARCHAR(255) NULL AFTER description");
    return $ok ? 'product_image' : null;
}

function normalizeProductImagePath(?string $imagePath): string {
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '') {
        return '';
    }
    if (preg_match('/^(https?:)?\/\//i', $imagePath) || strpos($imagePath, 'data:image/') === 0 || strpos($imagePath, '/') === 0) {
        return $imagePath;
    }
    $imagePath = str_replace('\\', '/', $imagePath);
    if (strpos($imagePath, '/') !== false) {
        return $imagePath;
    }
    return 'uploads/' . $imagePath;
}

function handleProductImageUpload(string $fieldName, string &$error): ?string {
    $error = '';
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'Product image upload failed. Please choose another image.';
        return null;
    }

    $maxBytes = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        $error = 'Product image must be 5 MB or smaller.';
        return null;
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $error = 'Product image must be JPG, JPEG, PNG, or WEBP.';
        return null;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmpName);
            finfo_close($finfo);
        }
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        $error = 'Product image type is not supported.';
        return null;
    }

    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'product_images';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        $error = 'Product image folder could not be created.';
        return null;
    }

    $safeName = 'product_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($tmpName, $destination)) {
        $error = 'Product image could not be saved.';
        return null;
    }

    return 'uploads/product_images/' . $safeName;
}

function deleteProductImageIfUnused(mysqli $conn, string $imagePath, string $imageColumn, string $excludeSku = ''): void {
    $imagePath = trim($imagePath);
    if ($imagePath === '' || strpos($imagePath, 'uploads/product_images/') !== 0) {
        return;
    }

    $sql = "SELECT COUNT(*) AS used_count FROM products WHERE `$imageColumn` = ?";
    if ($excludeSku !== '') {
        $sql .= " AND sku <> ?";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    if ($excludeSku !== '') {
        $stmt->bind_param('ss', $imagePath, $excludeSku);
    } else {
        $stmt->bind_param('s', $imagePath);
    }
    $stmt->execute();
    $used = (int)($stmt->get_result()->fetch_assoc()['used_count'] ?? 0);
    $stmt->close();

    if ($used > 0) {
        return;
    }
    $filePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath));
    $baseDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'product_images');
    if ($filePath && $baseDir && strpos($filePath, $baseDir) === 0 && is_file($filePath)) {
        @unlink($filePath);
    }
}

function getExpiryStatus(?string $expiryDate, $tracking = 0, int $soonDays = 90): array {
    require_once __DIR__ . '/product_expiry_lib.php';
    return getProductExpiryStatus($expiryDate, $tracking, $soonDays);
}

function getStockLevelMeta(int $quantity, int $reorderLevel = 30, ?int $sellable = null, bool $expiredBlocked = false): array {
    if ($expiredBlocked) {
        return ['label' => 'Expired - Not Sellable', 'class' => 'badge-red', 'filter' => 'not-sellable'];
    }
    $avail = $sellable !== null ? $sellable : $quantity;
    if ($avail <= 0) {
        if ($quantity > 0) {
            return ['label' => 'Not Sellable', 'class' => 'badge-red', 'filter' => 'not-sellable'];
        }
        return ['label' => 'Out of Stock', 'class' => 'badge-red', 'filter' => 'out'];
    }
    $threshold = $reorderLevel > 0 ? $reorderLevel : 30;
    if ($avail <= $threshold) {
        return ['label' => 'Low Stock', 'class' => 'badge-orange', 'filter' => 'low'];
    }
    return ['label' => 'In Stock', 'class' => 'badge-green', 'filter' => 'in'];
}

function tableExists(mysqli $conn, string $tableName): bool {
    $safeName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safeName}'");
    return (bool)($result && $result->num_rows > 0);
}

function columnExists(mysqli $conn, string $tableName, string $columnName): bool {
    $safeTable = $conn->real_escape_string($tableName);
    $safeColumn = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return (bool)($result && $result->num_rows > 0);
}

function ensureAuditLogSchema(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(100) NOT NULL,
            user_role VARCHAR(100) NOT NULL,
            action_key VARCHAR(100) NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
}

function ensureUserPasswordPolicySchema(mysqli $conn): void {
    $plainPasswordCol = $conn->query("SHOW COLUMNS FROM users LIKE 'plain_password'");
    if (!($plainPasswordCol && $plainPasswordCol->num_rows > 0)) {
        $conn->query("ALTER TABLE users ADD COLUMN plain_password VARCHAR(255) NULL AFTER password");
    }
    $mustChangeCol = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
    if (!($mustChangeCol && $mustChangeCol->num_rows > 0)) {
        $conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
    $passwordChangedAtCol = $conn->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'");
    if (!($passwordChangedAtCol && $passwordChangedAtCol->num_rows > 0)) {
        $conn->query("ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL AFTER must_change_password");
    }
}

function addAuditLog(mysqli $conn, ?int $userId, string $username, string $role, string $actionKey, string $details): void {
    ensureAuditLogSchema($conn);
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("issss", $userId, $username, $role, $actionKey, $details);
        $stmt->execute();
        $stmt->close();
    }
}

$hasSupplierId = false;
$hasSellingPrice = false;
$hasBarcode = false;
$hasExpiryDate = false;
$suppliers_list = [];
$supplier_history = [];
$supplier_performance_rows = [];
$purchase_history_rows = [];
$delivery_schedule_rows = [];
$purchaser_audit_logs = [];
$warehouse_audit_logs = [];
$technician_job_orders = [];
$technician_users = [];
$categories_list = [];
$location_options = [];

$colSupplier = $conn->query("SHOW COLUMNS FROM products LIKE 'supplier_id'");
if ($colSupplier && $colSupplier->num_rows > 0) $hasSupplierId = true;

$colSelling = $conn->query("SHOW COLUMNS FROM products LIKE 'selling_price'");
if ($colSelling && $colSelling->num_rows > 0) $hasSellingPrice = true;

$hasBarcode = ensureProductBarcodeSchema($conn);
require_once __DIR__ . '/product_barcodes_lib.php';
productBarcodesEnsureSchema($conn);
$hasExpiryDate = ensureProductExpirySchema($conn);
ensureProductBrandSchema($conn);
require_once __DIR__ . '/product_catalog_fields_lib.php';
productCatalogEnsureSchema($conn);
$productImageColumn = ensureProductImageSchema($conn);

$supplierResult = $conn->query("SELECT id, supplier_code, name, contact_person, email, phone, city, country, status FROM suppliers ORDER BY name");
if ($supplierResult) while ($row = $supplierResult->fetch_assoc()) $suppliers_list[] = $row;

if (tableExists($conn, 'purchase_orders') && tableExists($conn, 'suppliers')) {
    $supplierHistorySql = "SELECT
            po.id,
            po.po_number,
            po.order_date,
            po.expected_delivery_date,
            po.status,
            po.total_amount,
            s.id AS supplier_id,
            s.name AS supplier_name,
            COUNT(poi.id) AS item_count,
            COALESCE(SUM(poi.quantity_ordered), 0) AS units_ordered
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
        GROUP BY po.id, po.po_number, po.order_date, po.expected_delivery_date, po.status, po.total_amount, s.id, s.name
        ORDER BY po.order_date DESC, po.id DESC";
    $supplierHistoryResult = $conn->query($supplierHistorySql);
    if ($supplierHistoryResult) {
        while ($row = $supplierHistoryResult->fetch_assoc()) {
            $supplier_history[] = $row;
        }
    }
    $purchase_history_rows = $supplier_history;

    $deliveryScheduleSql = "SELECT
            po.id,
            po.po_number,
            po.order_date,
            po.expected_delivery_date,
            po.received_date,
            po.status,
            s.name AS supplier_name,
            rec.receipt_number,
            rec.date_received AS actual_received_date,
            rec.status AS receipt_status
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN (
            SELECT pr1.po_id, pr1.receipt_number, pr1.date_received, pr1.status
            FROM purchase_receipts pr1
            INNER JOIN (
                SELECT po_id, MAX(date_received) AS max_date
                FROM purchase_receipts
                GROUP BY po_id
            ) latest ON latest.po_id = pr1.po_id AND latest.max_date = pr1.date_received
        ) rec ON rec.po_id = po.id
        ORDER BY
            CASE WHEN po.expected_delivery_date IS NULL THEN 1 ELSE 0 END,
            po.expected_delivery_date ASC,
            po.id DESC";
    if (tableExists($conn, 'purchase_receipts')) {
        $deliveryScheduleResult = $conn->query($deliveryScheduleSql);
        if ($deliveryScheduleResult) {
            while ($row = $deliveryScheduleResult->fetch_assoc()) {
                $delivery_schedule_rows[] = $row;
            }
        }
    } else {
        foreach ($supplier_history as $row) {
            $delivery_schedule_rows[] = [
                'id' => $row['id'] ?? null,
                'po_number' => $row['po_number'] ?? null,
                'order_date' => $row['order_date'] ?? null,
                'expected_delivery_date' => $row['expected_delivery_date'] ?? null,
                'received_date' => null,
                'status' => $row['status'] ?? null,
                'supplier_name' => $row['supplier_name'] ?? null,
                'receipt_number' => null,
                'actual_received_date' => null,
                'receipt_status' => null,
            ];
        }
    }
}

if (tableExists($conn, 'suppliers')) {
    $supplierJoins = [];
    $productCountTerms = [];
    $lastDeliveryTerms = [];

    if ($hasSupplierId) {
        $supplierJoins[] = "LEFT JOIN (
            SELECT supplier_id, COUNT(DISTINCT id) AS product_count
            FROM products
            WHERE supplier_id IS NOT NULL AND status != 'Discontinued'
            GROUP BY supplier_id
        ) pc ON pc.supplier_id = s.id";
        $productCountTerms[] = "COALESCE(pc.product_count, 0)";
    }

    if (tableExists($conn, 'purchase_orders') && tableExists($conn, 'purchase_order_items')) {
        $poProductExpr = columnExists($conn, 'purchase_order_items', 'product_name')
            ? "COALESCE(CAST(poi.product_id AS CHAR), poi.product_name)"
            : "CAST(poi.product_id AS CHAR)";
        $supplierJoins[] = "LEFT JOIN (
            SELECT po.supplier_id, COUNT(DISTINCT {$poProductExpr}) AS po_product_count
            FROM purchase_orders po
            JOIN purchase_order_items poi ON poi.po_id = po.id
            WHERE po.supplier_id IS NOT NULL
            GROUP BY po.supplier_id
        ) poc ON poc.supplier_id = s.id";
        $productCountTerms[] = "COALESCE(poc.po_product_count, 0)";
    }

    if (tableExists($conn, 'deliveries') && columnExists($conn, 'deliveries', 'delivery_date')) {
        $supplierJoins[] = "LEFT JOIN (
            SELECT supplier_id, MAX(delivery_date) AS last_delivery_date
            FROM deliveries
            WHERE supplier_id IS NOT NULL
            GROUP BY supplier_id
        ) del ON del.supplier_id = s.id";
        $lastDeliveryTerms[] = "COALESCE(del.last_delivery_date, '1000-01-01')";
    }

    if (tableExists($conn, 'purchase_receipts') && columnExists($conn, 'purchase_receipts', 'date_received')) {
        $supplierJoins[] = "LEFT JOIN (
            SELECT supplier_id, MAX(date_received) AS last_receipt_date
            FROM purchase_receipts
            WHERE supplier_id IS NOT NULL
            GROUP BY supplier_id
        ) rec ON rec.supplier_id = s.id";
        $lastDeliveryTerms[] = "COALESCE(rec.last_receipt_date, '1000-01-01')";
    }

    if (tableExists($conn, 'purchase_orders') && columnExists($conn, 'purchase_orders', 'received_date')) {
        $supplierJoins[] = "LEFT JOIN (
            SELECT supplier_id, MAX(received_date) AS last_po_received_date
            FROM purchase_orders
            WHERE supplier_id IS NOT NULL AND received_date IS NOT NULL
            GROUP BY supplier_id
        ) pod ON pod.supplier_id = s.id";
        $lastDeliveryTerms[] = "COALESCE(pod.last_po_received_date, '1000-01-01')";
    }

    $productsSuppliedSql = $productCountTerms
        ? 'GREATEST(' . implode(', ', $productCountTerms) . ')'
        : '0';
    $lastDeliverySql = $lastDeliveryTerms
        ? "NULLIF(GREATEST(" . implode(', ', $lastDeliveryTerms) . "), '1000-01-01')"
        : "NULL";

    $supplierInfoSql = "SELECT
            s.id,
            s.name AS supplier_name,
            s.contact_person,
            s.phone,
            s.status,
            {$productsSuppliedSql} AS products_supplied,
            {$lastDeliverySql} AS last_delivery_date
        FROM suppliers s
        " . implode("\n        ", $supplierJoins) . "
        ORDER BY s.name";
    $supplierInfoResult = $conn->query($supplierInfoSql);
    if ($supplierInfoResult) {
        while ($row = $supplierInfoResult->fetch_assoc()) {
            $supplierStatus = (string)($row['status'] ?? '');
            if (!in_array($supplierStatus, ['Active', 'Inactive'], true)) {
                $supplierStatus = $supplierStatus === 'Active' ? 'Active' : 'Inactive';
            }
            $supplier_performance_rows[] = [
                'supplier_name' => $row['supplier_name'] ?: 'N/A',
                'contact_person' => $row['contact_person'] ?: 'N/A',
                'contact_number' => $row['phone'] ?: 'N/A',
                'products_supplied' => (int)($row['products_supplied'] ?? 0),
                'last_delivery_date' => $row['last_delivery_date'] ?? null,
                'status' => empty($row['last_delivery_date']) ? 'No Delivery Record' : $supplierStatus,
            ];
        }
    }
}

$categoryResult = $conn->query("SELECT id, name, description, status, default_expiry_tracking, parent_id FROM categories ORDER BY name");
if (!$categoryResult) {
    $categoryResult = $conn->query("SELECT id, name, description, status, default_expiry_tracking FROM categories ORDER BY name");
}
if (!$categoryResult) {
    $categoryResult = $conn->query("SELECT id, name, description, status FROM categories ORDER BY name");
}
if ($categoryResult) while ($row = $categoryResult->fetch_assoc()) $categories_list[] = $row;
require_once __DIR__ . '/product_catalog_fields_lib.php';
$catalogSplit = productCatalogSplit($categories_list);
$catalog_parents = $catalogSplit['parents'];
$catalog_children = $catalogSplit['children'];
$catalog_by_parent = $catalogSplit['by_parent'];
$catalogTreeForJs = [];
foreach ($catalog_by_parent as $pid => $rows) {
    foreach ($rows as $row) {
        $catalogTreeForJs[(string)$pid][] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'default_expiry_tracking' => isset($row['default_expiry_tracking']) && $row['default_expiry_tracking'] !== null && $row['default_expiry_tracking'] !== '' ? (int)$row['default_expiry_tracking'] : null,
        ];
    }
}

$locations = [];
$locationResult = $conn->query("SELECT id, location_code, storage_area, shelf_rack, section, status, current_usage FROM storage_locations ORDER BY storage_area, shelf_rack, section");
if ($locationResult) {
    while ($row = $locationResult->fetch_assoc()) {
        $label = trim((string)$row['storage_area']);
        if (!empty($row['shelf_rack'])) $label .= ' - ' . $row['shelf_rack'];
        if (!empty($row['section'])) $label .= ' (' . $row['section'] . ')';
        if (!empty($row['location_code'])) $label .= ' [' . $row['location_code'] . ']';
        $location_options[] = ['id' => (int)$row['id'], 'label' => $label];
        $locations[] = [
            $row['storage_area'] ?: 'N/A',
            $row['shelf_rack'] ?: ($row['location_code'] ?: 'N/A'),
            $row['section'] ?: '-',
            $row['status'] ?: 'Active',
            (int)($row['current_usage'] ?? 0),
        ];
    }
}

$currentUserAccount = null;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId > 0) {
    $accountStmt = $conn->prepare("SELECT id, username, full_name, first_name, last_name, email, role, status, must_change_password, password_changed_at FROM users WHERE id = ? LIMIT 1");
    if ($accountStmt) {
        $accountStmt->bind_param("i", $currentUserId);
        $accountStmt->execute();
        $accountRes = $accountStmt->get_result();
        $currentUserAccount = $accountRes ? $accountRes->fetch_assoc() : null;
        $accountStmt->close();
        if ($currentUserAccount) {
            $acctNames = userNameNormalize(
                (string)($currentUserAccount['first_name'] ?? ''),
                (string)($currentUserAccount['last_name'] ?? ''),
                (string)($currentUserAccount['full_name'] ?? '')
            );
            $currentUserAccount['first_name'] = $acctNames['first_name'];
            $currentUserAccount['last_name'] = $acctNames['last_name'];
            $currentUserAccount['full_name'] = $acctNames['full_name'];
        }
    }
}

$stock_movement_history = [];
if (tableExists($conn, 'stock_movements')) {
    $dateExpr = columnExists($conn, 'stock_movements', 'transaction_date')
        ? 'sm.transaction_date'
        : (columnExists($conn, 'stock_movements', 'created_at') ? 'sm.created_at' : 'NULL');
    $referenceExpr = columnExists($conn, 'stock_movements', 'reference_number') ? 'sm.reference_number' : 'NULL';
    $notesExpr = columnExists($conn, 'stock_movements', 'notes') ? 'sm.notes' : 'NULL';
    $productJoin = tableExists($conn, 'products') ? 'LEFT JOIN products p ON sm.product_id = p.id' : '';
    $userJoin = tableExists($conn, 'users') ? 'LEFT JOIN users u ON sm.user_id = u.id' : '';
    $productNameExpr = tableExists($conn, 'products') ? "COALESCE(NULLIF(p.name, ''), NULLIF(p.sku, ''), 'N/A')" : "'N/A'";
    $userNameExpr = tableExists($conn, 'users') ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))), ''), NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'System')" : "'System'";

    $movementHistorySql = "SELECT
            {$dateExpr} AS movement_date,
            {$productNameExpr} AS product_name,
            sm.movement_type,
            sm.quantity,
            {$referenceExpr} AS reference_number,
            {$userNameExpr} AS user_name,
            {$notesExpr} AS remarks
        FROM stock_movements sm
        {$productJoin}
        {$userJoin}
        ORDER BY {$dateExpr} DESC, sm.id DESC
        LIMIT 300";
    $movementHistoryResult = $conn->query($movementHistorySql);
    if ($movementHistoryResult) {
        while ($row = $movementHistoryResult->fetch_assoc()) {
            $stock_movement_history[] = $row;
        }
    }
}

function renderStockMovementHistoryTable(array $stock_movement_history, string $wrapperClass = 'mt-4'): void {
    ?>
    <div class="<?= htmlspecialchars($wrapperClass) ?>">
        <h6 style="font-weight:700;margin-bottom:16px;"><i class="bi bi-clock-history me-2 text-primary-color"></i>Stock Movement History</h6>
        <div class="table-container system-table-scroll">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Date and Time</th>
                        <th>Product</th>
                        <th>Movement Type</th>
                        <th>Quantity</th>
                        <th>Reference</th>
                        <th>User</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody class="js-stock-movement-history-body">
                <?php if (!empty($stock_movement_history)): ?>
                    <?php foreach ($stock_movement_history as $movementRow): ?>
                        <?php
                            $movementType = (string)($movementRow['movement_type'] ?? '');
                            $movementTypeLower = strtolower($movementType);
                            $movementBadgeClass = 'badge-orange';
                            if (strpos($movementTypeLower, 'in') !== false || strpos($movementTypeLower, 'receipt') !== false || strpos($movementTypeLower, 'return') !== false) {
                                $movementBadgeClass = 'badge-green';
                            } elseif (strpos($movementTypeLower, 'out') !== false || strpos($movementTypeLower, 'issue') !== false || strpos($movementTypeLower, 'damage') !== false || strpos($movementTypeLower, 'expiry') !== false) {
                                $movementBadgeClass = 'badge-red';
                            }
                            $movementDate = !empty($movementRow['movement_date'])
                                ? date('M d, Y h:i A', strtotime((string)$movementRow['movement_date']))
                                : 'N/A';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($movementDate) ?></td>
                            <td><?= htmlspecialchars((string)($movementRow['product_name'] ?: 'N/A')) ?></td>
                            <td><span class="badge <?= $movementBadgeClass ?>"><?= htmlspecialchars($movementType ?: 'N/A') ?></span></td>
                            <td><?= isset($movementRow['quantity']) ? (int)$movementRow['quantity'] : 'N/A' ?></td>
                            <td><?= htmlspecialchars((string)($movementRow['reference_number'] ?: 'N/A')) ?></td>
                            <td><?= htmlspecialchars((string)($movementRow['user_name'] ?: 'N/A')) ?></td>
                            <td><?= htmlspecialchars((string)($movementRow['remarks'] ?: 'N/A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted);">No stock movement records available.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

$movements = [
    ['MV-001','2026-01-28','Industrial Bearing A200','Stock In',50,'PO-2026-001','Routine restock'],
    ['MV-002','2026-01-29','Hydraulic Pump HP-500','Stock Out',5,'SO-2026-012','Customer order'],
    ['MV-003','2026-01-29','Steel Cable 10mm','Stock In',200,'PO-2026-002','Bulk delivery'],
    ['MV-004','2026-01-30','Electric Motor 3HP','Transfer',10,'TR-2026-001','A to B transfer'],
    ['MV-005','2026-01-30','Rubber Gasket Set','Stock In',100,'PO-2026-003','Restock'],
    ['MV-006','2026-01-31','Valve Assembly V-100','Stock Out',3,'SO-2026-018','Maintenance'],
    ['MV-007','2026-01-31','Lubricant Oil 5L','Adjustment',-20,'ADJ-2026-01','Damage write-off'],
    ['MV-008','2026-02-01','Filter Cartridge F-200','Stock In',56,'PO-2026-004','Monthly supply'],
];

$deliveries = [
    ['DEL-001','2026-01-28','Industrial Bearing A200','ABC Industrial',50,'Completed'],
    ['DEL-002','2026-01-29','Steel Cable 10mm','WireCo Ltd',200,'Completed'],
    ['DEL-003','2026-01-30','Rubber Gasket Set','SealMaster Inc',100,'Completed'],
    ['DEL-004','2026-01-31','Filter Cartridge F-200','FilterPro Corp',56,'Completed'],
    ['DEL-005','2026-02-01','Hydraulic Pump HP-500','HydroTech',10,'Pending'],
    ['DEL-006','2026-02-03','Electric Motor 3HP','MotorWorks',20,'Pending'],
];

// ── USERS: only load for Administrator ──────────────────────────────────────
$users = [];
if ($isSystemAdmin) {
    $userResult = $conn->query("SELECT u.id, u.username, u.role, u.email, u.full_name, u.first_name, u.last_name, u.status, u.plain_password, 
                                       up.allowed_sections, up.can_manage_records, up.can_print_records, up.can_approve_pos, up.can_delete_records, up.can_manage_pricing 
                                FROM users u 
                                LEFT JOIN user_permissions up ON u.id = up.user_id 
                                ORDER BY u.id");
    if ($userResult) {
        while ($row = $userResult->fetch_assoc()) {
            $names = userNameNormalize(
                (string)($row['first_name'] ?? ''),
                (string)($row['last_name'] ?? ''),
                (string)($row['full_name'] ?? '')
            );
            $users[] = [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'role' => $row['role'],
                'email' => $row['email'],
                'first_name' => $names['first_name'],
                'last_name' => $names['last_name'],
                'full_name' => $names['full_name'],
                'status' => $row['status'] ?? 'Active',
                'plain_password' => '',
                'allowed_sections' => $row['allowed_sections'] ?? 'dashboard,analytics,inventory,barcode-scanner,data-entry,serials-view,low-stock-view,expiry-view,procurement,suppliers-view,purchase-orders,reports,account',
                'can_manage' => (int)($row['can_manage_records'] ?? 1),
                'can_print' => (int)($row['can_print_records'] ?? 1),
                'can_approve' => (int)($row['can_approve_pos'] ?? 1),
                'can_delete' => (int)($row['can_delete_records'] ?? 0),
                'can_manage_pricing' => (int)($row['can_manage_pricing'] ?? 0)
            ];
        }
    }
}

$alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('clarenzon_csrf_validate_request') || !clarenzon_csrf_validate_request()) {
        $_POST = [];
        $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Your session expired. Please try again.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    $mutationActions = [
        'update_product', 'delete_product', 'add_product', 'import_products', 'add_location',
        'update_product', 'delete_product', 'add_product', 'import_products', 'add_location',
        'delete_all_products', 'add_category', 'update_category', 'delete_category', 'add_supplier', 'update_supplier', 'delete_supplier', 'submit_adjustment', 'submit_movement', 'submit_delivery',
    ];
    $isMutationRequest = false;
    $triggeredAction = '';
    foreach ($mutationActions as $actionKey) {
        if (isset($_POST[$actionKey])) {
            $isMutationRequest = true;
            $triggeredAction = $actionKey;
            break;
        }
    }

    if ($isMutationRequest && !$canManageRecords && !($isWarehouseHead && $triggeredAction === 'update_product')) {
        $_SESSION['flash_alert'] = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Your account currently has view-only access. Contact Warehouse Head for record management permissions.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($isWarehouseHead && in_array($triggeredAction, ['delete_product','delete_all_products','add_product','import_products','submit_adjustment','submit_movement','submit_delivery','add_category','update_category','delete_category','add_supplier','update_supplier','delete_supplier'], true)) {
        $_SESSION['flash_alert'] = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Warehouse Head access is view-only for this action.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if (isset($_POST['create_job_order'])) {
        if (!$isSystemAdmin) {
            $_SESSION['flash_alert'] = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Admin can create technician job orders.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $assignedTo = (int)($_POST['job_assigned_to'] ?? 0);
            $equipment = trim((string)($_POST['job_equipment'] ?? ''));
            $description = trim((string)($_POST['job_description'] ?? ''));
            $instructions = trim((string)($_POST['job_instructions'] ?? ''));
            $res = createTechnicianJobOrder($conn, $assignedTo, $equipment, $description, $instructions, (int)($_SESSION['user_id'] ?? 0));
            if ($res['success']) {
                addAuditLog($conn, (int)($_SESSION['user_id'] ?? 0), (string)$username, (string)$role, 'create_job_order', 'Created technician job order ' . $res['job_order_no']);
                $_SESSION['flash_alert'] = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Job order created: ' . htmlspecialchars($res['job_order_no']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $_SESSION['flash_alert'] = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($res['message']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if (isset($_POST['update_product'])) {
        $sku = trim($_POST['edit_sku'] ?? '');
        if ($sku !== '') {
            if ($isWarehouseHead && !$isSystemAdmin) {
                $location = trim($_POST['edit_location'] ?? '');
                $expiryDate = normalizeExpiryDate($_POST['edit_expiry_date'] ?? '');
                $locationId = resolveLocationId($conn, $location);
                if ($alert === '') {
                    require_once __DIR__ . '/product_expiry_lib.php';
                    $whTracking = productExpiryPostedFlag('new_expiry_tracking', 'edit_expiry_tracking');
                    $writeExpiry = $hasExpiryDate && $whTracking;
                    if ($writeExpiry) {
                        $stmt = $conn->prepare("UPDATE products SET location_id=?, expiry_date=? WHERE sku=?");
                        $stmt->bind_param("iss", $locationId, $expiryDate, $sku);
                    } else {
                        $stmt = $conn->prepare("UPDATE products SET location_id=? WHERE sku=?");
                        $stmt->bind_param("is", $locationId, $sku);
                    }
                    $ok = $stmt && $stmt->execute();
                    if ($stmt) $stmt->close();
                    if ($ok) {
                        productExpirySaveTracking($conn, $sku, $whTracking);
                    }
                    if ($alert === '') {
                        $alert = $ok
                            ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Warehouse product fields updated successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                            : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update warehouse product fields.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    }
                }
            } else {
            $name = trim($_POST['edit_name'] ?? '');
            $category = trim($_POST['edit_category'] ?? '');
            $price = (float)($_POST['edit_price'] ?? 0);
            $cost = (float)($_POST['edit_cost'] ?? 0);
            $unitOfMeasure = trim($_POST['edit_unit_of_measure'] ?? 'pcs');
            $supplierId = (int)($_POST['edit_supplier_id'] ?? 0);
            $location = trim($_POST['edit_location'] ?? '');
            $expiryDate = normalizeExpiryDate($_POST['edit_expiry_date'] ?? '');
            $description = trim((string)($_POST['edit_description'] ?? ''));
            $editStatus = trim((string)($_POST['edit_status'] ?? 'Active'));
            if (!in_array($editStatus, ['Active', 'Inactive', 'Discontinued'], true)) {
                $editStatus = 'Active';
            }
            require_once __DIR__ . '/product_catalog_fields_lib.php';
            $validUnits = productCatalogUnitOptions();
            if (!in_array($unitOfMeasure, $validUnits, true)) $unitOfMeasure = 'pcs';
            $dupStmt = $conn->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND sku <> ? LIMIT 1");
            $dupStmt->bind_param("ss", $name, $sku);
            $dupStmt->execute();
            $duplicateExists = $dupStmt->get_result()->num_rows > 0;
            $dupStmt->close();
            if ($duplicateExists) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Product name already exists.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
            if ($alert === '') {
                $newImagePath = null;
                $oldImagePath = '';
                if ($productImageColumn) {
                    $oldImageStmt = $conn->prepare("SELECT `$productImageColumn` AS product_image FROM products WHERE sku = ? LIMIT 1");
                    if ($oldImageStmt) {
                        $oldImageStmt->bind_param("s", $sku);
                        $oldImageStmt->execute();
                        $oldImagePath = (string)($oldImageStmt->get_result()->fetch_assoc()['product_image'] ?? '');
                        $oldImageStmt->close();
                    }
                    $uploadError = '';
                    $newImagePath = handleProductImageUpload('edit_product_image', $uploadError);
                    if ($uploadError !== '') {
                        $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($uploadError) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    }
                }
            }
            if ($alert === '') {
                $postedSubId = (int)($_POST['edit_product_subcategory_id'] ?? 0);
                $categoryId = $postedSubId > 0 ? $postedSubId : resolveCategoryId($conn, $category);
                $locationId = resolveLocationId($conn, $location);
                if ($hasSupplierId) {
                    $stmt = $conn->prepare("UPDATE products SET name=?,supplier_id=?,category_id=?,unit_price=?,cost_price=?,unit_of_measure=?,location_id=?,description=?,status=? WHERE sku=?");
                    $stmt->bind_param("siiddsisss", $name, $supplierId, $categoryId, $price, $cost, $unitOfMeasure, $locationId, $description, $editStatus, $sku);
                } else {
                    $stmt = $conn->prepare("UPDATE products SET name=?,category_id=?,unit_price=?,cost_price=?,unit_of_measure=?,location_id=?,description=?,status=? WHERE sku=?");
                    $stmt->bind_param("siddsisss", $name, $categoryId, $price, $cost, $unitOfMeasure, $locationId, $description, $editStatus, $sku);
                }
                $ok = $stmt->execute(); $stmt->close();
                if ($ok) {
                    $brand = trim($_POST['edit_brand'] ?? '');
                    $size = trim($_POST['edit_size'] ?? '');
                    $packaging = trim($_POST['edit_packaging'] ?? '');
                    $reorderLevel = (int)($_POST['edit_reorder_level'] ?? 50);
                    if ($reorderLevel < 0) $reorderLevel = 0;
                    $extraStmt = $conn->prepare("UPDATE products SET brand=?, size=?, packaging=?, reorder_level=? WHERE sku=?");
                    if ($extraStmt) {
                        $extraStmt->bind_param("sssis", $brand, $size, $packaging, $reorderLevel, $sku);
                        $extraStmt->execute();
                        $extraStmt->close();
                    }
                    require_once __DIR__ . '/product_catalog_fields_lib.php';
                    productCatalogSaveInfoFields($conn, $sku, $packaging, productCatalogPostedStorageTemp('edit_storage_temp', 'edit_storage_temp_other'));
                    if ($hasSellingPrice) {
                        $spStmt = $conn->prepare("UPDATE products SET selling_price=?, unit_price=? WHERE sku=?");
                        if ($spStmt) {
                            $spStmt->bind_param("dds", $price, $price, $sku);
                            $spStmt->execute();
                            $spStmt->close();
                        }
                    }
                    require_once __DIR__ . '/inventory_wms_lib.php';
                    invWmsEnsureSchema($conn);
                    $pidStmt = $conn->prepare('SELECT id FROM products WHERE sku=? LIMIT 1');
                    $editPid = 0;
                    if ($pidStmt) {
                        $pidStmt->bind_param('s', $sku);
                        $pidStmt->execute();
                        $editPid = (int)($pidStmt->get_result()->fetch_assoc()['id'] ?? 0);
                        $pidStmt->close();
                    }
                    if ($editPid > 0) {
                        productBarcodesSyncPrimary($conn, $editPid);
                    }
                }
                require_once __DIR__ . '/product_expiry_lib.php';
                $expiryTracking = productExpiryPostedFlag('new_expiry_tracking', 'edit_expiry_tracking');
                if ($ok) {
                    productExpirySaveTracking($conn, $sku, $expiryTracking);
                }
                if ($ok && $hasExpiryDate && $expiryTracking) {
                    $ok = updateProductExpiryDate($conn, $sku, $expiryDate);
                }
                if ($ok && $productImageColumn && $newImagePath !== null) {
                    $imageStmt = $conn->prepare("UPDATE products SET `$productImageColumn` = ? WHERE sku = ?");
                    if ($imageStmt) {
                        $imageStmt->bind_param("ss", $newImagePath, $sku);
                        $ok = $imageStmt->execute();
                        $imageStmt->close();
                        if ($ok) {
                            deleteProductImageIfUnused($conn, $oldImagePath, $productImageColumn, $sku);
                        }
                    } else {
                        $ok = false;
                    }
                }
                if (!$ok && isset($newImagePath) && $newImagePath !== null) {
                    deleteProductImageIfUnused($conn, $newImagePath, $productImageColumn ?: 'product_image');
                }
                $alert = $ok
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Product updated successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update product.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
            }
        }
    }

    if (isset($_POST['delete_product'])) {
        $sku = trim($_POST['delete_sku'] ?? '');
        if ($sku !== '') {
            $stmt = $conn->prepare("DELETE FROM products WHERE sku = ?");
            $stmt->bind_param("s", $sku);
            $ok = $stmt->execute(); $stmt->close();
            $alert = $ok
                ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Product deleted successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to delete product.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }

    if (isset($_POST['delete_all_products'])) {
        if ($isSystemAdmin && $canManageRecords) {
            $ok = $conn->query("DELETE FROM products");
            $alert = $ok
                ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> All products have been deleted successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to delete all products.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }

    if (isset($_POST['import_products'])) {
        if (!$isPurchaser || !$canManageRecords) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only a purchaser can import a product file.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $file = $_FILES['product_import'] ?? null;
            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Choose a CSV file to import.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif ($ext !== 'csv' || (int)$file['size'] > 2 * 1024 * 1024) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Import a CSV file up to 2 MB. In Excel, use Save As CSV.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $handle = fopen($file['tmp_name'], 'r');
                $header = $handle ? fgetcsv($handle) : false;
                if (!$header) {
                    if ($handle) fclose($handle);
                    $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> The file has no header row.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                } else {
                    if (isset($header[0])) {
                        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
                    }
                    $map = [];
                    foreach ($header as $index => $label) {
                        $key = strtolower(trim((string)$label));
                        $key = str_replace([' ', '-'], '_', $key);
                        $map[$key] = $index;
                    }
                    $need = ['name', 'supplier', 'category'];
                    $missing = array_values(array_filter($need, function ($key) use ($map) { return !isset($map[$key]); }));
                    if ($missing) {
                        fclose($handle);
                        $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> The CSV must include columns: name, supplier, category.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    } else {
                        require_once __DIR__ . '/product_catalog_fields_lib.php';
                        $units = productCatalogUnitOptions();
                        $imported = 0;
                        $notes = [];
                        $rowNo = 1;
                        $insert = null;
                        if ($hasSupplierId && $hasSellingPrice && $hasBarcode) {
                            $insert = $conn->prepare("INSERT INTO products (sku,supplier_id,category_id,name,brand,size,packaging,barcode,description,unit_price,cost_price,selling_price,unit_of_measure,quantity_in_stock,status,reorder_level) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        } elseif ($hasSupplierId && $hasSellingPrice) {
                            $insert = $conn->prepare("INSERT INTO products (sku,supplier_id,category_id,name,brand,size,packaging,description,unit_price,cost_price,selling_price,unit_of_measure,quantity_in_stock,status,reorder_level) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        }
                        if (!$insert) {
                            fclose($handle);
                            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Product import is not available for this database.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                        } else {
                            $supplierStmt = $conn->prepare("SELECT id FROM suppliers WHERE LOWER(name) = LOWER(?) OR LOWER(supplier_code) = LOWER(?) LIMIT 1");
                            $categoryStmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
                            $nameStmt = $conn->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
                            while (($row = fgetcsv($handle)) !== false && $rowNo <= 500) {
                                $rowNo++;
                                $cell = function ($key) use ($row, $map) {
                                    if (!isset($map[$key])) return '';
                                    return trim((string)($row[$map[$key]] ?? ''));
                                };
                                if (implode('', array_map('trim', $row)) === '') continue;
                                $name = $cell('name');
                                $supplierName = $cell('supplier');
                                $categoryName = $cell('category');
                                if ($name === '' || $supplierName === '' || $categoryName === '') {
                                    $notes[] = 'Row ' . $rowNo . ' is missing name, supplier, or category.';
                                    continue;
                                }
                                $supplierStmt->bind_param('ss', $supplierName, $supplierName);
                                $supplierStmt->execute();
                                $supplierRow = $supplierStmt->get_result()->fetch_assoc();
                                $categoryStmt->bind_param('s', $categoryName);
                                $categoryStmt->execute();
                                $categoryRow = $categoryStmt->get_result()->fetch_assoc();
                                $nameStmt->bind_param('s', $name);
                                $nameStmt->execute();
                                $exists = $nameStmt->get_result()->num_rows > 0;
                                if (!$supplierRow || !$categoryRow) {
                                    $notes[] = 'Row ' . $rowNo . ' supplier or category was not found.';
                                    continue;
                                }
                                if ($exists) {
                                    $notes[] = 'Row ' . $rowNo . ' already exists and was skipped.';
                                    continue;
                                }
                                $brand = $cell('brand');
                                $size = $cell('size');
                                $packaging = $cell('packaging');
                                $description = $cell('description');
                                $barcode = strtoupper($cell('barcode'));
                                if ($barcode === '') $barcode = null;
                                $unit = $cell('unit');
                                if ($unit === '' || !in_array($unit, $units, true)) $unit = 'pcs';
                                $cost = max(0, (float)$cell('cost_price'));
                                $selling = max(0, (float)$cell('selling_price'));
                                $qty = max(0, (int)$cell('quantity'));
                                $reorder = $cell('reorder_level') === '' ? 50 : max(0, (int)$cell('reorder_level'));
                                if ($hasBarcode && $barcode !== null && function_exists('productBarcodesFind') && productBarcodesFind($conn, $barcode)) {
                                    $notes[] = 'Row ' . $rowNo . ' barcode already exists.';
                                    continue;
                                }
                                $sku = generateSku($conn);
                                $supplierId = (int)$supplierRow['id'];
                                $categoryId = (int)$categoryRow['id'];
                                $status = 'Active';
                                if ($hasBarcode) {
                                    $insert->bind_param('siissssssdddsisi', $sku, $supplierId, $categoryId, $name, $brand, $size, $packaging, $barcode, $description, $selling, $cost, $selling, $unit, $qty, $status, $reorder);
                                } else {
                                    $insert->bind_param('siisssssdddsisi', $sku, $supplierId, $categoryId, $name, $brand, $size, $packaging, $description, $selling, $cost, $selling, $unit, $qty, $status, $reorder);
                                }
                                if (!$insert->execute()) {
                                    $notes[] = 'Row ' . $rowNo . ' could not be saved.';
                                    continue;
                                }
                                $newId = (int)$insert->insert_id;
                                if ($hasBarcode && $barcode !== null && $newId > 0 && function_exists('productBarcodesRegister')) {
                                    productBarcodesRegister($conn, $newId, $barcode, ['source' => 'import', 'registered_by' => (int)($_SESSION['user_id'] ?? 0), 'is_primary' => true]);
                                }
                                productCatalogSaveInfoFields($conn, $sku, $packaging, null);
                                if ($qty > 0 && $newId > 0) {
                                    require_once __DIR__ . '/inventory_wms_lib.php';
                                    invWmsEnsureSchema($conn);
                                    invWmsSetProductOnHand($conn, $newId, $qty, null);
                                }
                                $imported++;
                            }
                            fclose($handle);
                            $insert->close();
                            $supplierStmt->close();
                            $categoryStmt->close();
                            $nameStmt->close();
                            if ($imported > 0) {
                                addAuditLog($conn, (int)($_SESSION['user_id'] ?? 0), (string)$username, (string)$role, 'import_products', 'Imported ' . $imported . ' product(s) from CSV');
                            }
                            $detail = $notes ? ' ' . htmlspecialchars(implode(' ', array_slice($notes, 0, 6))) : '';
                            $alert = '<div class="alert alert-' . ($imported > 0 ? 'success' : 'warning') . ' alert-dismissible fade show"><i class="bi bi-file-earmark-arrow-up"></i> Imported ' . $imported . ' product(s).' . $detail . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                        }
                    }
                }
            }
        }
    }

    if (isset($_POST['add_product'])) {
        $supplierId = (int)($_POST['new_supplier_id'] ?? 0);
        $categoryId = (int)($_POST['new_category_id'] ?? 0);
        $name = trim($_POST['new_name'] ?? '');
        $brand = trim($_POST['new_brand'] ?? '');
        $size = trim($_POST['new_size'] ?? '');
        $packaging = trim($_POST['new_packaging'] ?? '');
        $barcode = strtoupper(trim($_POST['new_barcode'] ?? ''));
        if ($barcode === '') $barcode = null;
        $description = trim($_POST['new_description'] ?? '');
        $quantity = (int)($_POST['new_quantity'] ?? 0);
        $selectedLocationId = (int)($_POST['new_location_id'] ?? 0);
        $costPrice = (float)($_POST['new_cost'] ?? 0);
        $unitOfMeasure = trim($_POST['new_unit_of_measure'] ?? 'pcs');
        $sellingPrice = (float)($_POST['new_selling_price'] ?? 0);
        $expiryDate = normalizeExpiryDate($_POST['new_expiry_date'] ?? '');
        
        if ($unitOfMeasure === '') $unitOfMeasure = 'pcs';
        require_once __DIR__ . '/product_catalog_fields_lib.php';
        if (!in_array($unitOfMeasure, productCatalogUnitOptions(), true)) $unitOfMeasure = 'pcs';
        if ($supplierId <= 0 || $categoryId <= 0 || $name === '') {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Supplier, category, and product name are required.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } elseif ($quantity < 0 || $costPrice < 0 || $sellingPrice < 0) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid quantity, cost, or selling price.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $dupStmt = $conn->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $dupStmt->bind_param("s", $name);
            $dupStmt->execute();
            $duplicateExists = $dupStmt->get_result()->num_rows > 0;
            $dupStmt->close();
            if ($duplicateExists) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Product name already exists.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif ($hasBarcode && !empty($barcode)) {
                $linked = productBarcodesFind($conn, (string)$barcode);
                if ($linked) {
                    $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Barcode already exists. Use a unique barcode.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                }
            }
            if ($alert === '') {
                $newImagePath = null;
                if ($productImageColumn) {
                    $uploadError = '';
                    $newImagePath = handleProductImageUpload('new_product_image', $uploadError);
                    if ($uploadError !== '') {
                        $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($uploadError) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    }
                }
            }
            if ($alert === '') {
                $sku = generateSku($conn);
                $status = 'Active';
                $locationId = $selectedLocationId > 0 ? $selectedLocationId : null;
                if ($hasSupplierId && $hasSellingPrice && $hasBarcode) {
                    $stmt = $conn->prepare("INSERT INTO products (sku,supplier_id,category_id,name,brand,size,packaging,barcode,description,unit_price,cost_price,selling_price,unit_of_measure,quantity_in_stock,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("siissssssdddsiis", $sku, $supplierId, $categoryId, $name, $brand, $size, $packaging, $barcode, $description, $sellingPrice, $costPrice, $sellingPrice, $unitOfMeasure, $quantity, $locationId, $status);
                } elseif ($hasSupplierId && $hasSellingPrice) {
                    $stmt = $conn->prepare("INSERT INTO products (sku,supplier_id,category_id,name,brand,size,packaging,description,unit_price,cost_price,selling_price,unit_of_measure,quantity_in_stock,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("siisssssdddsiis", $sku, $supplierId, $categoryId, $name, $brand, $size, $packaging, $description, $sellingPrice, $costPrice, $sellingPrice, $unitOfMeasure, $quantity, $locationId, $status);
                } elseif ($hasSupplierId && $hasBarcode) {
                    $stmt = $conn->prepare("INSERT INTO products (sku,supplier_id,category_id,name,brand,size,packaging,barcode,description,unit_price,cost_price,unit_of_measure,quantity_in_stock,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("siissssssddsiis", $sku, $supplierId, $categoryId, $name, $brand, $size, $packaging, $barcode, $description, $sellingPrice, $costPrice, $unitOfMeasure, $quantity, $locationId, $status);
                } elseif ($hasSupplierId) {
                    $stmt = $conn->prepare("INSERT INTO products (sku,supplier_id,category_id,name,brand,size,packaging,description,unit_price,cost_price,unit_of_measure,quantity_in_stock,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("siisssssddsiis", $sku, $supplierId, $categoryId, $name, $brand, $size, $packaging, $description, $sellingPrice, $costPrice, $unitOfMeasure, $quantity, $locationId, $status);
                } elseif ($hasBarcode) {
                    $stmt = $conn->prepare("INSERT INTO products (sku,category_id,name,brand,size,packaging,barcode,description,unit_price,cost_price,unit_of_measure,quantity_in_stock,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("sissssssddsiis", $sku, $categoryId, $name, $brand, $size, $packaging, $barcode, $description, $sellingPrice, $costPrice, $unitOfMeasure, $quantity, $locationId, $status);
                } else {
                    $stmt = $conn->prepare("INSERT INTO products (sku,category_id,name,brand,size,packaging,description,unit_price,cost_price,unit_of_measure,quantity_in_stock,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("sisssssddsiis", $sku, $categoryId, $name, $brand, $size, $packaging, $description, $sellingPrice, $costPrice, $unitOfMeasure, $quantity, $locationId, $status);
                }
                $ok = $stmt->execute();
                $newProductId = (int)$stmt->insert_id;
                $stmt->close();
                if ($ok && $newProductId > 0 && !empty($barcode)) {
                    productBarcodesRegister($conn, $newProductId, (string)$barcode, [
                        'source' => 'manual',
                        'registered_by' => $user_id,
                        'is_primary' => true,
                    ]);
                }
                require_once __DIR__ . '/product_expiry_lib.php';
                $expiryTracking = productExpiryPostedFlag('new_expiry_tracking', 'edit_expiry_tracking');
                if ($ok) {
                    productExpirySaveTracking($conn, $sku, $expiryTracking);
                }
                if ($ok && $hasExpiryDate && $expiryTracking) {
                    $ok = updateProductExpiryDate($conn, $sku, $expiryDate);
                }
                if ($ok && $productImageColumn && $newImagePath !== null) {
                    $imageStmt = $conn->prepare("UPDATE products SET `$productImageColumn` = ? WHERE sku = ?");
                    if ($imageStmt) {
                        $imageStmt->bind_param("ss", $newImagePath, $sku);
                        $ok = $imageStmt->execute();
                        $imageStmt->close();
                    } else {
                        $ok = false;
                    }
                }
                if (!$ok && isset($newImagePath) && $newImagePath !== null) {
                    deleteProductImageIfUnused($conn, $newImagePath, $productImageColumn ?: 'product_image');
                }
                if ($ok) {
                    $reorderLevel = (int)($_POST['new_reorder_level'] ?? 50);
                    if ($reorderLevel < 0) $reorderLevel = 0;
                    if ($newProductId > 0) {
                        $rlStmt = $conn->prepare("UPDATE products SET reorder_level=? WHERE id=?");
                        if ($rlStmt) {
                            $rlStmt->bind_param("ii", $reorderLevel, $newProductId);
                            $rlStmt->execute();
                            $rlStmt->close();
                        }
                    }
                    require_once __DIR__ . '/product_catalog_fields_lib.php';
                    productCatalogSaveInfoFields($conn, $sku, $packaging, productCatalogPostedStorageTemp('new_storage_temp', 'new_storage_temp_other'));
                    require_once __DIR__ . '/inventory_wms_lib.php';
                    invWmsEnsureSchema($conn);
                    if ($newProductId > 0) {
                        invWmsSetProductOnHand($conn, $newProductId, $quantity, $locationId);
                    } else {
                        invWmsSetProductOnHandBySku($conn, $sku, $quantity, $locationId);
                    }
                }
                $alert = $ok
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Product added successfully! SKU: ' . htmlspecialchars($sku) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to add product.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
    }

    if (isset($_POST['add_location'])) {
        $storageArea = trim($_POST['location_storage_area'] ?? '');
        $shelfRack = trim($_POST['location_shelf_rack'] ?? '');
        $section = trim($_POST['location_section'] ?? '');
        if ($storageArea === '') {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Storage area is required.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            do {
                $locationCode = 'LOC-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                $codeCheck = $conn->prepare("SELECT id FROM storage_locations WHERE location_code = ? LIMIT 1");
                $codeCheck->bind_param("s", $locationCode);
                $codeCheck->execute();
                $exists = $codeCheck->get_result()->num_rows > 0;
                $codeCheck->close();
            } while ($exists);
            $insertLoc = $conn->prepare("INSERT INTO storage_locations (location_code,storage_area,shelf_rack,section,current_usage,status) VALUES (?,?,?,?,0,'Active')");
            $insertLoc->bind_param("ssss", $locationCode, $storageArea, $shelfRack, $section);
            $ok = $insertLoc->execute();
            $newLocationId = (int)$insertLoc->insert_id;
            $insertLoc->close();
            if ($ok) {
                $label = $storageArea;
                if ($shelfRack !== '') $label .= ' - ' . $shelfRack;
                if ($section !== '') $label .= ' (' . $section . ')';
                $label .= ' [' . $locationCode . ']';
                $location_options[] = ['id' => $newLocationId, 'label' => $label];
                $locations[] = [$storageArea, $shelfRack !== '' ? $shelfRack : $locationCode, $section !== '' ? $section : '-', 'Active', 0];
                $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Location added successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to add location.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
    }

    if (isset($_POST['add_category'])) {
        $categoryName = trim($_POST['category_name'] ?? '');
        $categoryDescription = trim($_POST['category_description'] ?? '');
        if ($categoryName === '') {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Category name is required.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $checkStmt->bind_param("s", $categoryName);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();
            if ($exists) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Category name already exists.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $insertStmt = $conn->prepare("INSERT INTO categories (name, description, status) VALUES (?, ?, 'Active')");
                $insertStmt->bind_param("ss", $categoryName, $categoryDescription);
                $ok = $insertStmt->execute();
                $newCatId = (int)$conn->insert_id;
                $insertStmt->close();
                if ($ok) {
                    $catDefaultRaw = trim((string)($_POST['category_default_expiry_tracking'] ?? ''));
                    if ($catDefaultRaw !== '0' && $catDefaultRaw !== '1') {
                        require_once __DIR__ . '/product_expiry_lib.php';
                        $inferred = productExpiryCategoryDefaultFromName($categoryName);
                        if ($inferred !== null) {
                            $catDefaultRaw = (string)$inferred;
                        }
                    }
                    if ($catDefaultRaw === '0' || $catDefaultRaw === '1') {
                        $d = (int)$catDefaultRaw;
                        $conn->query("UPDATE categories SET default_expiry_tracking=$d WHERE id=$newCatId");
                    }
                    $parentId = (int)($_POST['category_parent_id'] ?? 0);
                    if ($parentId > 0 && $parentId !== $newCatId) {
                        $conn->query("UPDATE categories SET parent_id=$parentId WHERE id=$newCatId");
                    }
                    $categories_list[] = ['id' => $newCatId, 'name' => $categoryName, 'description' => $categoryDescription, 'status' => 'Active', 'default_expiry_tracking' => ($catDefaultRaw === '0' || $catDefaultRaw === '1') ? (int)$catDefaultRaw : null, 'parent_id' => $parentId > 0 ? $parentId : null];
                    usort($categories_list, fn($a,$b) => strcasecmp((string)$a['name'], (string)$b['name']));
                    $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Category added successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                } else {
                    $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to add category.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                }
            }
        }
    }

    if (isset($_POST['add_supplier'])) {
        $supplierCode = trim($_POST['supplier_code'] ?? '');
        $supplierName = trim($_POST['supplier_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $city = trim($_POST['city'] ?? '');
        
        if ($supplierName === '' || $supplierCode === '') {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Supplier code and name are required.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $insertStmt = $conn->prepare("INSERT INTO suppliers (supplier_code, name, contact_person, email, phone, city, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
            $insertStmt->bind_param("ssssss", $supplierCode, $supplierName, $contactPerson, $email, $phone, $city);
            $ok = $insertStmt->execute(); $insertStmt->close();
            if ($ok) {
                $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Supplier added successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to add supplier.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
    }

    if (isset($_POST['update_supplier'])) {
        $supplierId = (int)($_POST['edit_supplier_id_field'] ?? 0);
        $supplierCode = trim($_POST['edit_sup_code'] ?? '');
        $supplierName = trim($_POST['edit_sup_name'] ?? '');
        $contactPerson = trim($_POST['edit_sup_contact'] ?? '');
        $email = trim($_POST['edit_sup_email'] ?? '');
        $phone = trim($_POST['edit_sup_phone'] ?? '');
        $city = trim($_POST['edit_sup_city'] ?? '');
        
        if ($supplierId > 0 && $supplierName !== '') {
            $stmt = $conn->prepare("UPDATE suppliers SET supplier_code=?, name=?, contact_person=?, email=?, phone=?, city=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("ssssssi", $supplierCode, $supplierName, $contactPerson, $email, $phone, $city, $supplierId);
            if ($stmt->execute()) {
                $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Supplier updated successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update supplier.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
            $stmt->close();
        }
    }

    if (isset($_POST['delete_supplier'])) {
        $supplierId = (int)($_POST['delete_sup_id'] ?? 0);
        if ($supplierId > 0) {
            $stmt = $conn->prepare("DELETE FROM suppliers WHERE id=?");
            $stmt->bind_param("i", $supplierId);
            if ($stmt->execute()) {
                $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Supplier deleted successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to delete supplier. It may be linked to existing products.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
            $stmt->close();
        }
    }

    if (isset($_POST['update_category'])) {
        $categoryId = (int)($_POST['edit_category_id'] ?? 0);
        $categoryName = trim($_POST['edit_category_name'] ?? '');
        $categoryDescription = trim($_POST['edit_category_description'] ?? '');
        if ($categoryId <= 0 || $categoryName === '') {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid category data.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
            $checkStmt->bind_param("si", $categoryName, $categoryId);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();
            if ($exists) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Category name already exists.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $updateStmt = $conn->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
                $updateStmt->bind_param("ssi", $categoryName, $categoryDescription, $categoryId);
                $ok = $updateStmt->execute(); $updateStmt->close();
                if ($ok) {
                    $catDefaultRaw = trim((string)($_POST['edit_category_default_expiry_tracking'] ?? ''));
                    if ($catDefaultRaw === '') {
                        @$conn->query("UPDATE categories SET default_expiry_tracking=NULL WHERE id=$categoryId");
                    } elseif ($catDefaultRaw === '0' || $catDefaultRaw === '1') {
                        $d = (int)$catDefaultRaw;
                        @$conn->query("UPDATE categories SET default_expiry_tracking=$d WHERE id=$categoryId");
                    }
                    $parentId = (int)($_POST['edit_category_parent_id'] ?? 0);
                    if ($parentId > 0 && $parentId !== $categoryId) {
                        @$conn->query("UPDATE categories SET parent_id=$parentId WHERE id=$categoryId");
                    } else {
                        @$conn->query("UPDATE categories SET parent_id=NULL WHERE id=$categoryId");
                    }
                }
                $alert = $ok
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Category updated successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update category.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
    }

    if (isset($_POST['delete_category'])) {
        $categoryId = (int)($_POST['delete_category_id'] ?? 0);
        if ($categoryId <= 0) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid category selected.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $deleteStmt = $conn->prepare("DELETE FROM categories WHERE id=?");
            $deleteStmt->bind_param("i", $categoryId);
            $ok = $deleteStmt->execute(); $deleteStmt->close();
            $alert = $ok
                ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Category deleted successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Cannot delete category in use by products.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }

    if (isset($_POST['submit_adjustment'])) {
        if ($isPurchaser) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Purchaser access is view-only for stock movements.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $sku = trim($_POST['product'] ?? '');
            $adjustmentType = trim($_POST['type'] ?? '');
            $qty = (int)($_POST['quantity'] ?? 0);
            if ($sku === '' || $qty <= 0 || $adjustmentType === '') {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid adjustment data.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $stockStmt = $conn->prepare("SELECT quantity_in_stock FROM products WHERE sku=? LIMIT 1");
            $stockStmt->bind_param("s", $sku);
            $stockStmt->execute();
            $productRow = $stockStmt->get_result()->fetch_assoc();
            $stockStmt->close();
            if (!$productRow) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Product not found.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $currentStock = (int)$productRow['quantity_in_stock'];
                $newStock = $currentStock;
                if ($adjustmentType === 'Increase') {
                    $newStock = $currentStock + $qty;
                } else {
                    if ($qty > $currentStock) {
                        $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Adjustment cannot reduce stock below zero.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    } else {
                        $newStock = $currentStock - $qty;
                    }
                }
                if ($alert === '') {
                    $status = $newStock <= 30 ? 'Low Stock' : 'Active';
                    $updateStmt = $conn->prepare("UPDATE products SET quantity_in_stock=?, status=? WHERE sku=?");
                    $updateStmt->bind_param("iss", $newStock, $status, $sku);
                    $ok = $updateStmt->execute(); $updateStmt->close();
                    $alert = $ok
                        ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Stock adjustment recorded successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                        : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update stock.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                }
            }
        }
        }
    }

    if (isset($_POST['submit_movement'])) {
        if ($isPurchaser) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Purchaser access is view-only for stock movements.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $sku = trim($_POST['product'] ?? '');
            $movementType = trim($_POST['movement_type'] ?? '');
            $qty = (int)($_POST['quantity'] ?? 0);
            $reference = trim((string)($_POST['reference'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $batchId = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;
            if ($sku === '' || $qty <= 0 || $movementType === '') {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid movement data.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $stockStmt = $conn->prepare("SELECT id, quantity_in_stock, unit_price, cost_price FROM products WHERE sku=? LIMIT 1");
                $stockStmt->bind_param("s", $sku);
                $stockStmt->execute();
                $productRow = $stockStmt->get_result()->fetch_assoc();
                $stockStmt->close();
                if (!$productRow) {
                    $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Product not found.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                } else {
                    $productId = (int)$productRow['id'];
                    $currentStock = (int)$productRow['quantity_in_stock'];
                    $unitPrice = (float)($productRow['unit_price'] ?? $productRow['cost_price'] ?? 0);
                    $typeMap = [
                        'Stock In' => 'Stock In (Receipt)',
                        'Stock In (Receipt)' => 'Stock In (Receipt)',
                        'Stock Out' => 'Stock Out (Issue)',
                        'Stock Out (Issue)' => 'Stock Out (Issue)',
                        'Order' => 'Stock Out (Issue)',
                        'Transfer' => 'Transfer',
                        'Return' => 'Return',
                        'Damage' => 'Damage',
                        'Expiry' => 'Expiry',
                        'Adjustment' => 'Adjustment',
                    ];
                    $dbMovementType = $typeMap[$movementType] ?? '';
                    $newStock = $currentStock;
                    $stockDelta = 0;
                    if (in_array($dbMovementType, ['Stock In (Receipt)', 'Return'], true)) {
                        $stockDelta = $qty;
                        $newStock = $currentStock + $qty;
                    } elseif (in_array($dbMovementType, ['Stock Out (Issue)', 'Expiry'], true)) {
                        if ($qty > $currentStock) {
                            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Insufficient available stock for this movement.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                        } else {
                            $stockDelta = -$qty;
                            $newStock = $currentStock - $qty;
                        }
                    } elseif ($dbMovementType === 'Damage') {
                        require_once __DIR__ . '/inventory_condition_lib.php';
                        $dmgRes = invStockRecordCondition($conn, $productId, $batchId, 'Damage', $qty, $notes !== '' ? $notes : 'Damage', $notes, (int)($_SESSION['user_id'] ?? 0));
                        if (empty($dmgRes['ok'])) {
                            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($dmgRes['message'] ?? 'Could not record damage.') . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                        } else {
                            $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Damage recorded. QTY stayed on record and sellable was reduced automatically.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                        }
                        $stockDelta = 0;
                        $newStock = $currentStock;
                    } elseif ($dbMovementType === 'Transfer' || $dbMovementType === 'Adjustment') {
                        $stockDelta = 0;
                        $newStock = $currentStock;
                    } else {
                        $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Unsupported movement type.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    }

                    if ($alert === '') {
                        if ($reference === '') {
                            $pfx = $stockDelta > 0 ? 'RCV' : ($stockDelta < 0 ? 'ISS' : 'TRF');
                            $reference = $pfx . '-' . date('Y') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                        }
                        $poNumber = preg_match('/^PO[-_]/i', $reference) ? $reference : null;
                        $siNumber = preg_match('/^SI[-_]/i', $reference) ? $reference : null;
                        $drNumber = preg_match('/^(DR|PREC)[-_]/i', $reference) ? $reference : null;
                        $totalValue = $qty * $unitPrice;
                        $userId = (int)($_SESSION['user_id'] ?? 0);
                        $status = $newStock <= 30 ? 'Low Stock' : 'Active';

                        $conn->begin_transaction();
                        try {
                            if ($stockDelta !== 0) {
                                $updateStmt = $conn->prepare("UPDATE products SET quantity_in_stock=?, status=? WHERE id=?");
                                $updateStmt->bind_param("isi", $newStock, $status, $productId);
                                if (!$updateStmt->execute()) {
                                    throw new RuntimeException('Failed to update stock.');
                                }
                                $updateStmt->close();
                            }

                            if ($batchId && $stockDelta !== 0 && tableExists($conn, 'product_batches')) {
                                if ($stockDelta > 0) {
                                    $conn->query("UPDATE product_batches SET quantity_remaining = quantity_remaining + " . (int)$qty . " WHERE id=" . (int)$batchId . " AND product_id=" . (int)$productId);
                                } else {
                                    $conn->query("UPDATE product_batches SET quantity_remaining = GREATEST(0, quantity_remaining - " . (int)$qty . ") WHERE id=" . (int)$batchId . " AND product_id=" . (int)$productId);
                                }
                            }

                            if (tableExists($conn, 'stock_movements')) {
                                $poBind = $poNumber;
                                $siBind = $siNumber;
                                $drBind = $drNumber;
                                if ($batchId) {
                                    $mvStmt = $conn->prepare("INSERT INTO stock_movements
                                        (product_id, batch_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes, po_number, si_number, dr_number, source)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin_stock_movement')");
                                    $mvStmt->bind_param(
                                        'iisiddsissss',
                                        $productId,
                                        $batchId,
                                        $dbMovementType,
                                        $qty,
                                        $unitPrice,
                                        $totalValue,
                                        $reference,
                                        $userId,
                                        $notes,
                                        $poBind,
                                        $siBind,
                                        $drBind
                                    );
                                } else {
                                    $mvStmt = $conn->prepare("INSERT INTO stock_movements
                                        (product_id, batch_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes, po_number, si_number, dr_number, source)
                                        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin_stock_movement')");
                                    $mvStmt->bind_param(
                                        'isiddsissss',
                                        $productId,
                                        $dbMovementType,
                                        $qty,
                                        $unitPrice,
                                        $totalValue,
                                        $reference,
                                        $userId,
                                        $notes,
                                        $poBind,
                                        $siBind,
                                        $drBind
                                    );
                                }
                                if (!$mvStmt->execute()) {
                                    throw new RuntimeException('Failed to record stock movement log.');
                                }
                                $mvStmt->close();
                            }

                            $conn->commit();
                            $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Movement submitted. Stock and audit log updated successfully. Ref: ' . htmlspecialchars($reference) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                        } catch (Throwable $e) {
                            $conn->rollback();
                            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($e->getMessage()) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                        }
                    }
                }
            }
        }
    }

    if (isset($_POST['submit_delivery'])) {
        if ($isPurchaser) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Purchaser access is view-only for stock movements.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $sku = trim($_POST['del_product'] ?? '');
            $qty = (int)($_POST['del_quantity'] ?? 0);
            if ($sku === '' || $qty <= 0) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid delivery data.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $stockStmt = $conn->prepare("SELECT quantity_in_stock FROM products WHERE sku=? LIMIT 1");
            $stockStmt->bind_param("s", $sku);
            $stockStmt->execute();
            $productRow = $stockStmt->get_result()->fetch_assoc();
            $stockStmt->close();
            if (!$productRow) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Product not found.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $newStock = (int)$productRow['quantity_in_stock'] + $qty;
                $status = $newStock <= 30 ? 'Low Stock' : 'Active';
                $updateStmt = $conn->prepare("UPDATE products SET quantity_in_stock=?, status=? WHERE sku=?");
                $updateStmt->bind_param("iss", $newStock, $status, $sku);
                $ok = $updateStmt->execute(); $updateStmt->close();
                $alert = $ok
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Delivery submitted. Stock increased successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update stock.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
        }
    }

    if (isset($_POST['add_user'])) {
        if (!$isSystemAdmin) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Admin can add users.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $firstName = trim((string)($_POST['new_first_name'] ?? ''));
            $lastName = trim((string)($_POST['new_last_name'] ?? ''));
            $names = userNameNormalize($firstName, $lastName, trim((string)($_POST['new_name'] ?? '')));
            $fullName = $names['full_name'];
            $usernameInput = trim($_POST['new_username'] ?? '');
            $email = trim($_POST['new_email'] ?? '');
            $newUserRole = trim($_POST['new_role'] ?? '');
            $allowedRoles = ['Purchaser','Technician'];
            if ($newUserRole === 'Techinician') $newUserRole = 'Technician';
            if ($fullName === '' || $email === '' || $newUserRole === '' || !in_array($newUserRole, $allowedRoles, true)) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid user details.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
            $newUsername = $usernameInput ?: substr(strtolower(preg_replace('/[^a-z0-9]+/i','',($fullName ?: 'user'))),0,20);
            $dupStmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username)=LOWER(?) OR LOWER(email)=LOWER(?) LIMIT 1");
            $dupStmt->bind_param("ss", $newUsername, $email);
            $dupStmt->execute();
            $exists = $dupStmt->get_result()->num_rows > 0;
            $dupStmt->close();
            if ($exists) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Username or email already exists.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $newPassword = (string)($_POST['new_password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_new_password'] ?? '');
                if ($newPassword === '' || $newPassword !== $confirmPassword) {
                    $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Enter and confirm a password.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    $ok = false;
                } else {
                $temporaryPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $userStatus = 'Active';
                $mustChangePassword = 0;
                $emptyPlain = '';
                $insertUser = $conn->prepare("INSERT INTO users (username,password,plain_password,email,full_name,first_name,last_name,role,status,must_change_password) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $insertUser->bind_param("sssssssssi", $newUsername, $temporaryPassword, $emptyPlain, $email, $fullName, $names['first_name'], $names['last_name'], $newUserRole, $userStatus, $mustChangePassword);
                $ok = $insertUser->execute();
                $newUserId = $conn->insert_id;
                $insertUser->close();
                if ($ok && $newUserId) {
                    $defaultSecs = $newUserRole === 'Technician'
                        ? 'dashboard,job-orders,issued-supplies,audit-log,account'
                        : 'dashboard,analytics,inventory,barcode-scanner,serials-view,low-stock-view,expiry-view,procurement,purchase-requests,purchase-orders,supplier-invoices,suppliers-view,purchase-history,delivery-schedule,purchaser-audit-log,markup-queue,reports,notifications,account';
                    $canManagePricingNew = $newUserRole === 'Purchaser' ? 1 : 0;
                    $conn->query("INSERT INTO user_permissions (user_id, allowed_sections, can_manage_records, can_print_records, can_approve_pos, can_delete_records, can_manage_pricing) VALUES ($newUserId, '$defaultSecs', 1, 1, 1, 0, $canManagePricingNew)");
                }
                $alert = $ok
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> User added successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to add user.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                }
            }
        }
        }
    }

    if (isset($_POST['update_user_permissions'])) {
        if (!$isSystemAdmin) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Administrator can edit user permissions.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $editUserId = (int)($_POST['edit_perm_user_id'] ?? 0);
            $sessionUid = (int)($_SESSION['user_id'] ?? 0);
            $editRole = trim($_POST['edit_perm_role'] ?? '');
            $editStatus = trim($_POST['edit_perm_status'] ?? 'Active');
            $editFirst = trim((string)($_POST['edit_perm_first_name'] ?? ''));
            $editLast = trim((string)($_POST['edit_perm_last_name'] ?? ''));
            $editNames = userNameNormalize($editFirst, $editLast, trim((string)($_POST['edit_perm_full_name'] ?? '')));
            $editName = $editNames['full_name'];
            $editEmail = trim($_POST['edit_perm_email'] ?? '');
            if ($editRole === 'Techinician') $editRole = 'Technician';
            
            $selectedSections = $_POST['sections'] ?? [];
            $allowedSectionsStr = implode(',', $selectedSections);
            
            $canManage = isset($_POST['perm_can_manage']) ? 1 : 0;
            $canPrint = isset($_POST['perm_can_print']) ? 1 : 0;
            $canApprove = isset($_POST['perm_can_approve']) ? 1 : 0;
            $canDelete = isset($_POST['perm_can_delete']) ? 1 : 0;
            $canManagePricingPerm = isset($_POST['perm_can_manage_pricing']) ? 1 : 0;
            
            $currentEditUser = null;
            if ($editUserId > 0) {
                $curStmt = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
                if ($curStmt) {
                    $curStmt->bind_param("i", $editUserId);
                    $curStmt->execute();
                    $currentEditUser = $curStmt->get_result()->fetch_assoc();
                    $curStmt->close();
                }
            }
            $currentEditRoleKey = strtolower(str_replace([' ', '-'], '_', trim((string)($currentEditUser['role'] ?? ''))));
            $targetEditableRole = in_array($currentEditRoleKey, ['purchaser', 'technician', 'techinician'], true);
            if ($editUserId > 0 && $sessionUid > 0 && $editUserId === $sessionUid) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Users cannot grant or change their own permissions.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif (!$targetEditableRole || !in_array($editRole, ['Purchaser', 'Technician'], true)) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Admin can only change normal user accounts between Purchaser and Technician. Default Admin and Warehouse Head accounts cannot be reassigned here.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif ($editUserId > 0) {
                $stmtU = $conn->prepare("UPDATE users SET full_name=?, first_name=?, last_name=?, email=?, role=?, status=? WHERE id=?");
                $stmtU->bind_param("ssssssi", $editName, $editNames['first_name'], $editNames['last_name'], $editEmail, $editRole, $editStatus, $editUserId);
                $stmtU->execute();
                $stmtU->close();
                
                $stmtP = $conn->prepare("INSERT INTO user_permissions (user_id, allowed_sections, can_manage_records, can_print_records, can_approve_pos, can_delete_records, can_manage_pricing)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE allowed_sections=?, can_manage_records=?, can_print_records=?, can_approve_pos=?, can_delete_records=?, can_manage_pricing=?");
                $stmtP->bind_param("isiiiiisiiiii", $editUserId, $allowedSectionsStr, $canManage, $canPrint, $canApprove, $canDelete, $canManagePricingPerm,
                                                  $allowedSectionsStr, $canManage, $canPrint, $canApprove, $canDelete, $canManagePricingPerm);
                $ok = $stmtP->execute();
                $stmtP->close();
                
                $alert = $ok
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> User access & permissions updated successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update user permissions.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
    }

    if (isset($_POST['submit_inventory_report_to_admin'])) {
        if (!isSecAllowed('reports')) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> You do not have permission to submit reports.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $summary = ['products' => 0, 'stock_units' => 0, 'low_stock' => 0, 'total_value' => 0.00];
            $reportSql = "SELECT
                    COUNT(*) AS products,
                    COALESCE(SUM(quantity_in_stock),0) AS stock_units,
                    COALESCE(SUM(CASE WHEN quantity_in_stock <= reorder_level THEN 1 ELSE 0 END),0) AS low_stock,
                    COALESCE(SUM(quantity_in_stock * COALESCE(selling_price, unit_price, 0)),0) AS total_value
                FROM products
                WHERE status <> 'Discontinued'";
            $reportRes = $conn->query($reportSql);
            if ($reportRes && ($row = $reportRes->fetch_assoc())) {
                $summary = [
                    'products' => (int)$row['products'],
                    'stock_units' => (int)$row['stock_units'],
                    'low_stock' => (int)$row['low_stock'],
                    'total_value' => (float)$row['total_value'],
                ];
            }
            $reportMessage = sprintf(
                '%s submitted an inventory report. Products: %d, Stock Units: %d, Low Stock: %d, Total Value: %s.',
                $full_name,
                $summary['products'],
                $summary['stock_units'],
                $summary['low_stock'],
                number_format($summary['total_value'], 2)
            );
            if (function_exists('createNotification')) {
                createNotification($conn, 'Inventory Report Submitted', $reportMessage, 'report_submitted', 'medium', 'inventory-report-' . date('YmdHis'), 'reports', 'admin');
            }
            addAuditLog($conn, (int)($_SESSION['user_id'] ?? 0), (string)$username, (string)$role, 'submit_inventory_report', $reportMessage);
            $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Inventory report submitted to Admin.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }

    if (isset($_POST['change_own_password'])) {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_new_password'] ?? '');
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($currentUserId <= 0) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Unable to identify your account.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Please complete all password fields.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } elseif ($newPassword !== $confirmPassword) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> New password and confirmation do not match.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } elseif (strlen($newPassword) < 6) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> New password must be at least 6 characters long.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $passwordStmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $passwordStmt->bind_param("i", $currentUserId);
            $passwordStmt->execute();
            $passwordRow = $passwordStmt->get_result()->fetch_assoc();
            $passwordStmt->close();
            if (!$passwordRow) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Your account record was not found in the database.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif (!password_verify($currentPassword, (string)$passwordRow['password'])) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Current password is incorrect.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif (password_verify($newPassword, (string)$passwordRow['password'])) {
                $alert = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> New password must be different from your current password.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $mustChangePassword = 0;
                $emptyPlain = '';
                $updatePasswordStmt = $conn->prepare("UPDATE users SET password = ?, plain_password = ?, must_change_password = ?, password_changed_at = NOW() WHERE id = ? LIMIT 1");
                $updatePasswordStmt->bind_param("ssii", $newPasswordHash, $emptyPlain, $mustChangePassword, $currentUserId);
                $ok = $updatePasswordStmt->execute();
                $updatePasswordStmt->close();
                if ($ok) {
                    if ($currentUserAccount) {
                        $currentUserAccount['must_change_password'] = 0;
                        $currentUserAccount['password_changed_at'] = date('Y-m-d H:i:s');
                    }
                    $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Password changed successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                } else {
                    $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to change password.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                }
            }
        }
    }


    if (isset($_POST['admin_change_user_password'])) {
        if (!$isSystemAdmin) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Admin can change another user\'s password.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $targetUsername = trim((string)($_POST['target_username'] ?? ''));
            $newUserPassword = (string)($_POST['admin_new_password'] ?? '');
            $confirmUserPassword = (string)($_POST['admin_confirm_password'] ?? '');
            $forcePasswordChange = isset($_POST['force_password_change']) ? 1 : 0;
            if ($targetUserId <= 0 || $targetUsername === '') {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid user selected for password change.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif ($newUserPassword === '' || $confirmUserPassword === '') {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Please enter and confirm the new password.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif ($newUserPassword !== $confirmUserPassword) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> New password and confirmation do not match.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } elseif (strlen($newUserPassword) < 6) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> New password must be at least 6 characters long.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $checkUserStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND username = ? LIMIT 1");
                $checkUserStmt->bind_param("is", $targetUserId, $targetUsername);
                $checkUserStmt->execute();
                $targetUserExists = $checkUserStmt->get_result()->num_rows > 0;
                $checkUserStmt->close();
                if (!$targetUserExists) {
                    $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> User not found.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                } else {
                    $newPasswordHash = password_hash($newUserPassword, PASSWORD_DEFAULT);
                    $emptyPlain = '';
                    $adminPasswordStmt = $conn->prepare("UPDATE users SET password = ?, plain_password = ?, must_change_password = ?, password_changed_at = NOW() WHERE id = ? LIMIT 1");
                    $adminPasswordStmt->bind_param("ssii", $newPasswordHash, $emptyPlain, $forcePasswordChange, $targetUserId);
                    $ok = $adminPasswordStmt->execute();
                    $adminPasswordStmt->close();
                    if ($ok) {
                        $alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Password for ' . htmlspecialchars($targetUsername) . ' updated successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    } else {
                        $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update password.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                    }
                }
            }
        }
    }

    if (isset($_POST['update_audit_log'])) {
        if (!$isSystemAdmin) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Admin can edit audit logs.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $auditId = (int)($_POST['audit_log_id'] ?? 0);
            $auditDetails = trim((string)($_POST['audit_log_details'] ?? ''));
            if ($auditId <= 0 || $auditDetails === '') {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid audit log edit request.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $stmt = $conn->prepare("UPDATE audit_logs SET details = ? WHERE id = ? LIMIT 1");
                $stmt->bind_param("si", $auditDetails, $auditId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                $alert = ($ok && $affected >= 0)
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Audit log updated successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to update audit log.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
    }

    if (isset($_POST['delete_audit_log'])) {
        if (!$isSystemAdmin) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Admin can delete audit logs.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $auditId = (int)($_POST['audit_log_id'] ?? 0);
            if ($auditId <= 0) {
                $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invalid audit log selected.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $stmt = $conn->prepare("DELETE FROM audit_logs WHERE id = ? LIMIT 1");
                $stmt->bind_param("i", $auditId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                $alert = ($ok && $affected > 0)
                    ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Audit log deleted successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to delete audit log.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        }
    }

    if (isset($_POST['delete_all_audit_logs'])) {
        if (!$isSystemAdmin) {
            $alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Only Admin can delete audit logs.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $ok = $conn->query("DELETE FROM audit_logs");
            $alert = $ok
                ? '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> All audit logs deleted successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                : '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to delete all audit logs.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }

    if (isset($_POST['reset_inventory'])) {
        $alert = '<div class="alert alert-info alert-dismissible fade show"><i class="bi bi-info-circle-fill"></i> Reset inventory is not available for database-backed products.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }

    if (
        $isMutationRequest &&
        $triggeredAction !== '' &&
        !in_array($triggeredAction, ['update_audit_log', 'delete_audit_log', 'delete_all_audit_logs'], true)
    ) {
        $auditDetails = trim(preg_replace('/\s+/', ' ', strip_tags((string)$alert)));
        if ($auditDetails === '') {
            $auditDetails = 'Action submitted.';
        }
        addAuditLog(
            $conn,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            (string)$username,
            (string)$role,
            $triggeredAction,
            $auditDetails
        );
    }

    $_SESSION['flash_alert'] = $alert;
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_SESSION['flash_alert'])) {
    $alert = $_SESSION['flash_alert'];
    unset($_SESSION['flash_alert']);
}

if ($currentUserAccount && !empty($currentUserAccount['must_change_password']) && $alert === '') {
    $alert = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-shield-lock-fill"></i> Your account is still using the default password. Please change it in Account Settings.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

$inventory_items = [];
require_once __DIR__ . '/inventory_wms_lib.php';
require_once __DIR__ . '/inventory_condition_lib.php';
require_once __DIR__ . '/product_expiry_lib.php';
invWmsEnsureSchema($conn);
invStockEnsureSchema($conn);
$productImageColumn = getProductImageColumn($conn);
$productImageSelect = $productImageColumn ? ", p.`" . str_replace('`', '``', $productImageColumn) . "` AS product_image" : ", '' AS product_image";
$sql = "SELECT p.id, p.sku, p.name, p.barcode, p.brand, p.size, p.packaging, p.storage_temperature, p.description, p.supplier_id, p.category_id, c.name AS category_name, c.parent_id AS parent_category_id, pc.name AS parent_category_name, s.name AS supplier_name, p.quantity_in_stock, p.reorder_level, p.unit_price, p.cost_price, p.selling_price, p.unit_of_measure, p.status AS product_status, COALESCE(p.expiry_tracking, 0) AS expiry_tracking, COALESCE(p.expiry_alert_days, 90) AS expiry_alert_days, CASE WHEN COALESCE(p.expiry_tracking,0)=1 THEN COALESCE(bx.batch_expiry, p.expiry_date) ELSE NULL END AS expiry_date{$productImageSelect},
    COALESCE(NULLIF(CONCAT(sl.storage_area, IF(sl.shelf_rack IS NOT NULL AND sl.shelf_rack <> '', CONCAT(' - ', sl.shelf_rack), '')), ''), sl.location_code, 'Warehouse') AS location_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN categories pc ON pc.id = c.parent_id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN storage_locations sl ON p.location_id = sl.id
    LEFT JOIN (
        SELECT product_id, MIN(expiry_date) AS batch_expiry
        FROM product_batches
        WHERE expiry_date IS NOT NULL AND quantity_remaining > 0
        GROUP BY product_id
    ) bx ON bx.product_id = p.id
    ORDER BY p.sku";
$result = $conn->query($sql);
if (!$result) {
    $sql = "SELECT p.id, p.sku, p.name, p.barcode, p.brand, p.size, p.packaging, p.description, p.supplier_id, c.name AS category_name, s.name AS supplier_name, p.quantity_in_stock, p.reorder_level, p.unit_price, p.cost_price, p.selling_price, p.unit_of_measure, p.status AS product_status, COALESCE(p.expiry_tracking, 0) AS expiry_tracking, COALESCE(p.expiry_alert_days, 90) AS expiry_alert_days, CASE WHEN COALESCE(p.expiry_tracking,0)=1 THEN p.expiry_date ELSE NULL END AS expiry_date{$productImageSelect},
        COALESCE(NULLIF(CONCAT(sl.storage_area, IF(sl.shelf_rack IS NOT NULL AND sl.shelf_rack <> '', CONCAT(' - ', sl.shelf_rack), '')), ''), sl.location_code, 'Warehouse') AS location_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN storage_locations sl ON p.location_id = sl.id
        ORDER BY p.sku";
    $result = $conn->query($sql);
}
if ($result && $result->num_rows > 0) {
    $sellableMap = invWmsSellableQtyMap($conn);
    while ($row = $result->fetch_assoc()) {
        $pid = (int)($row['id'] ?? 0);
        $onHand = (int)$row['quantity_in_stock'];
        $reorder = (int)($row['reorder_level'] ?? 50);
        $sellable = (int)($sellableMap[$pid] ?? $onHand);
        $expiryMeta = getExpiryStatus($row['expiry_date'] ?? null, (int)($row['expiry_tracking'] ?? 0), (int)($row['expiry_alert_days'] ?? 90));
        $expiredBlocked = ($expiryMeta['label'] === 'Expired' && $sellable <= 0);
        $stockMeta = getStockLevelMeta($onHand, $reorder, $sellable, $expiredBlocked);
        $inventory_items[] = [
            'id' => $pid,
            'sku' => $row['sku'],
            'name' => $row['name'],
            'category' => $row['category_name'] ?? 'Uncategorized',
            'parent_category' => (string)($row['parent_category_name'] ?? ''),
            'category_id' => (int)($row['category_id'] ?? 0),
            'parent_category_id' => (int)($row['parent_category_id'] ?? 0),
            'storage_temperature' => (string)($row['storage_temperature'] ?? ''),
            'qty' => $onHand,
            'sellable' => $sellable,
            'price' => (float)($row['selling_price'] ?? $row['unit_price']),
            'location' => $row['location_name'] ?? 'Warehouse',
            'description' => $row['description'] ?? '',
            'cost' => (float)($row['cost_price'] ?? 0),
            'uom' => $row['unit_of_measure'] ?? 'piece',
            'stock_label' => $stockMeta['label'],
            'supplier_id' => isset($row['supplier_id']) ? (int)$row['supplier_id'] : 0,
            'barcode' => (string)($row['barcode'] ?? ''),
            'expiry_raw' => $expiryMeta['raw'],
            'expiry_display' => $expiryMeta['display'],
            'expiry_label' => $expiryMeta['label'],
            'expiry_class' => $expiryMeta['class'],
            'expiry_tracking' => (int)($row['expiry_tracking'] ?? 0),
            'supplier_name' => (string)($row['supplier_name'] ?? 'Unassigned'),
            'stock_filter' => $stockMeta['filter'],
            'stock_class' => $stockMeta['class'],
            'brand' => (string)($row['brand'] ?? ''),
            'size' => (string)($row['size'] ?? ''),
            'packaging' => (string)($row['packaging'] ?? ''),
            'reorder_level' => $reorder,
            'image' => normalizeProductImagePath($row['product_image'] ?? ''),
            'product_status' => (string)($row['product_status'] ?? 'Active'),
        ];
    }
    $inventory_items = productBarcodesHydrateRows($conn, $inventory_items, 'id');
}

$inventory_lots = [];
if ($conn instanceof mysqli) {
    try {
        $inventory_lots = invStockLoadLotRows($conn, [
            'search' => trim((string)($_GET['inv_search'] ?? '')),
            'category' => trim((string)($_GET['inv_category'] ?? '')),
            'expiry' => trim((string)($_GET['inv_expiry'] ?? '')),
            'stock' => trim((string)($_GET['inv_stock'] ?? '')),
        ]);
        $inventory_lots = productBarcodesHydrateRows($conn, $inventory_lots, 'id');
    } catch (Throwable $e) {
        $inventory_lots = [];
    }
}

$total_value = 0; $low_stock = 0; $total_items = 0; $near_expiry = 0; $expired_count = 0;
foreach ($inventory_items as $item) {
    $total_value += $item['qty'] * $item['price'];
    $total_items += $item['qty'];
    $sellableQty = (int)($item['sellable'] ?? $item['qty']);
    if (invWmsIsLowStock($sellableQty, (int)$item['reorder_level'])) $low_stock++;
    if ($item['expiry_label'] === 'Expiring Soon' || $item['expiry_label'] === 'Near Expiry') $near_expiry++;
    if ($item['expiry_label'] === 'Expired') $expired_count++;
}

$category_stats = [];
foreach ($inventory_items as $item) {
    $cat = $item['category'];
    if (!isset($category_stats[$cat])) $category_stats[$cat] = ['count'=>0,'quantity'=>0,'value'=>0];
    $category_stats[$cat]['count']++;
    $category_stats[$cat]['quantity'] += $item['qty'];
    $category_stats[$cat]['value'] += $item['qty'] * $item['price'];
}
$inventory_count = count($inventory_items);
$category_count = count($category_stats);

$pending_pr_count = 0;
if (tableExists($conn, 'purchase_requests')) {
    $prCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_requests WHERE status = 'Pending Approval'");
    if ($prCountRes && ($prCountRow = $prCountRes->fetch_assoc())) {
        $pending_pr_count = (int)$prCountRow['cnt'];
    }
}

$auditLogs = [];
if ($isSystemAdmin) {
    ensureAuditLogSchema($conn);
    $auditRes = $conn->query("SELECT id, username, user_role, action_key, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 200");
    if ($auditRes) {
        while ($row = $auditRes->fetch_assoc()) {
            $auditLogs[] = $row;
        }
    }
}

if ($isPurchaser && tableExists($conn, 'audit_log')) {
    $stmt = $conn->prepare(
        "SELECT al.id, al.action_type, al.table_name, al.record_id, al.new_values, al.ip_address, al.created_at,
                COALESCE(u.full_name, u.username, ?) AS user_name
         FROM audit_log al
         LEFT JOIN users u ON al.user_id = u.id
         WHERE al.user_id = ?
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT 200"
    );
    if ($stmt) {
        $fallbackName = $full_name ?: $username;
        $stmt->bind_param('si', $fallbackName, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $purchaser_audit_logs[] = $row;
        }
        $stmt->close();
    }
}

if ($isWarehouseHead) {
    ensureAuditLogSchema($conn);
    $warehouseAuditSql = "SELECT id, username, user_role, action_key, details, created_at
                          FROM audit_logs
                          WHERE user_id = ?
                             OR action_key LIKE '%product%'
                             OR action_key LIKE '%movement%'
                             OR action_key LIKE '%stock%'
                             OR action_key LIKE '%inventory%'
                             OR action_key LIKE '%serial%'
                             OR action_key LIKE '%batch%'
                             OR action_key LIKE '%scan%'
                             OR action_key LIKE '%return%'
                          ORDER BY id DESC
                          LIMIT 200";
    $stmt = $conn->prepare($warehouseAuditSql);
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $warehouse_audit_logs[] = $row;
        }
        $stmt->close();
    }
}

if ($isSystemAdmin) {
    $technician_users = getTechnicianUsers($conn);
    $technician_job_orders = getTechnicianJobOrders($conn);
}

// Recent activity for dashboard feed (last 20)
$recentActivity = [];
ensureAuditLogSchema($conn);
$activityRes = $conn->query("SELECT username, user_role, action_key, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 20");
if ($activityRes) {
    while ($row = $activityRes->fetch_assoc()) {
        $recentActivity[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title><?= $isPurchaser ? 'Audit Log | Clarenzon Medical' : 'Dashboard | Clarenzon Medical' ?></title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <meta name="description" content="Hospital Equipment & Medical Supplies Inventory Management System for Clarenzon Medical Supplies">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/dashboard.css?v=<?= @filemtime(__DIR__ . '/css/dashboard.css') ?: time() ?>">
    <link rel="stylesheet" href="css/notifications.css">
    <link rel="stylesheet" href="css/popup-center.css?v=<?= @filemtime(__DIR__ . '/css/popup-center.css') ?: time() ?>">
    <style>
        .stat-card[onclick] {
            cursor: pointer;
            transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.22s ease, border-color 0.22s ease;
        }
        .stat-card[onclick]:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
            border-color: rgba(99, 102, 241, 0.35);
        }
        .stat-card[onclick]:active {
            transform: translateY(-1px);
        }
        /* PO & Procurement Modal Overlay Styles */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:5000; align-items:center; justify-content:center; padding:12px; overflow-y:auto; }
        .modal-overlay.open { display:flex; }
        .modal-overlay .modal { background:var(--surface,#fff); border-radius:16px; width:100%; max-width:620px; max-height:min(92vh, 900px); overflow:hidden; display:flex; flex-direction:column; box-shadow:0 20px 40px rgba(0,0,0,0.2); margin:auto; }
        .modal-overlay .modal.modal-lg { max-width:min(1100px, 96vw); }
        .modal-overlay .modal.modal-xl { max-width:min(1280px, 98vw); }
        #createPRModal .modal { max-height:min(96vh, 980px); }
        #createPRModal .form-grid { overflow: visible; }
        #createPRModal .pr-supplier-combo { z-index: 5; }
        #createPRModal .pr-supplier-combo.open { z-index: 50; }
        #createPRModal .pr-co-order-combo { z-index: 1; }
        #createPRModal .pr-co-order-combo.open { z-index: 20; }
        #createPRModal .pr-lowstock-list {
            height: auto;
            max-height: none;
            overflow: visible;
            padding-right: 4px;
        }
        #createPRModal[data-pr-pos="inline"] {
            display: block !important;
            position: static !important;
            inset: auto !important;
            background: none !important;
            z-index: auto !important;
            padding: 0 !important;
            overflow: visible !important;
            align-items: stretch !important;
            justify-content: flex-start !important;
            margin: 0 0 16px;
            width: 100%;
            height: auto !important;
            min-height: 0;
            transform: none !important;
        }
        #createPRModal[data-pr-pos="inline"] .modal {
            position: relative !important;
            inset: auto !important;
            left: auto !important;
            right: auto !important;
            top: auto !important;
            bottom: auto !important;
            display: flex !important;
            flex-direction: column;
            max-width: 100% !important;
            width: 100%;
            height: auto !important;
            max-height: none !important;
            margin: 0;
            overflow: visible;
            transform: none !important;
        }
        #createPRModal[data-pr-pos="inline"] .modal-body {
            overflow: visible;
            max-height: none;
            flex: none;
        }
        #createPRModal[data-pr-pos="inline"] .pr-items-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        #createSalesOrderModal.modal-overlay .modal { overflow: visible; }
        #createSalesOrderModal[data-so-pos="inline"] { overflow: visible; }
        #createSalesOrderModal[data-so-pos="inline"] .card-body { overflow: visible; }
        #createSalesOrderModal .modal-body { overflow: visible; }
        #createSalesOrderModal .table-container,
        #createSalesOrderModal .system-table-scroll { overflow: visible; }
        #createSalesOrderModal .so-product-combo { position: relative; min-width: 260px; }
        #createSalesOrderModal .so-product-native {
            position: absolute; left: 0; top: 0; width: 1px; height: 1px;
            opacity: 0; pointer-events: none; margin: 0; padding: 0; border: 0;
        }
        #createSalesOrderModal .so-product-search { width: 100%; }
        #createSalesOrderModal .so-product-menu {
            position: absolute; top: calc(100% + 2px); left: 0; right: 0;
            min-width: 320px; max-height: 240px; overflow-y: auto;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
            box-shadow: 0 10px 28px rgba(15,23,42,.14); z-index: 40;
        }
        #createSalesOrderModal .so-product-opt {
            display: block; width: 100%; text-align: left; padding: 7px 10px;
            border: 0; background: #fff; font-size: 12px; line-height: 1.35;
            color: #0f172a; cursor: pointer;
        }
        #createSalesOrderModal .so-product-opt:hover,
        #createSalesOrderModal .so-product-opt.active { background: #eff6ff; }
        #createSalesOrderModal .so-product-empty {
            padding: 10px 12px; font-size: 12px; color: #64748b;
        }
        .modal-overlay .modal-header { padding:16px 20px; border-bottom:1px solid var(--border,#e2e8f0); display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .modal-overlay .modal-title { font-size:16px; font-weight:700; color:var(--text,#0f172a); }
        .modal-overlay .modal-close { width:32px; height:32px; border:none; background:#f1f5f9; border-radius:8px; cursor:pointer; color:#64748b; font-size:16px; display:flex; align-items:center; justify-content:center; transition:.15s; flex-shrink:0; }
        .modal-overlay .modal-close:hover { background:#fee2e2; color:#ef4444; }
        .modal-overlay .modal-body { padding:20px; overflow-x:hidden; overflow-y:auto; flex:1 1 auto; min-height:0; min-width:0; -webkit-overflow-scrolling:touch; }
        .modal-overlay .modal-footer { padding:14px 20px; border-top:1px solid var(--border,#e2e8f0); display:flex; justify-content:flex-end; flex-wrap:wrap; gap:10px; flex-shrink:0; }
        .modal-overlay .pr-items-scroll,
        .modal-overlay .receipt-items-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; margin:0 -4px; padding:0 4px; }
        .modal-overlay .pr-items-scroll .modern-table { min-width:1080px; width:100%; }
        .modal-overlay .receipt-items-scroll .modern-table { min-width:1180px; width:100%; }
        .modal-overlay .pr-items-scroll .modern-table th,
        .modal-overlay .pr-items-scroll .modern-table td,
        .modal-overlay .receipt-items-scroll .modern-table th,
        .modal-overlay .receipt-items-scroll .modern-table td { white-space:nowrap; vertical-align:top; }
        .modal-overlay .pr-items-scroll .modern-table td:first-child,
        .modal-overlay .pr-items-scroll .modern-table td:nth-child(2),
        .modal-overlay .pr-items-scroll .modern-table td:nth-child(3),
        .modal-overlay .receipt-items-scroll .modern-table td:first-child { white-space:normal; min-width:160px; }
        .modal-overlay .receipt-items-scroll .modern-table td.rcpt-barcode-cell,
        .modal-overlay .receipt-items-scroll .modern-table td.rcpt-disc-cell,
        .modal-overlay .receipt-items-scroll .modern-table td.rcpt-lots-cell,
        .modal-overlay .receipt-items-scroll .modern-table td.rcpt-product-cell { white-space:normal; vertical-align:top; }
        .modal-overlay .pr-items-scroll .pr-qty-input { min-width:88px; width:100%; font-size:14px; font-weight:700; text-align:right; }
        .modal-overlay .pr-items-scroll .form-control,
        .modal-overlay .receipt-items-scroll .form-control { min-width:0; max-width:100%; }
        .po-items-table { width:100%; border-collapse:collapse; font-size:13px; }
        .po-items-table th { background:#f8fafc; padding:8px 10px; text-align:left; font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; border-bottom:1px solid var(--border,#e2e8f0); }
        .po-items-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .po-items-table td input, .po-items-table td select { width:100%; padding:6px 8px; border:1px solid var(--border,#e2e8f0); border-radius:6px; font-size:12px; }
        #poDetailModal .po-view-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; margin-bottom:16px; }
        #poDetailModal .po-view-items-table { width:100%; min-width:1080px; table-layout:auto; }
        #poDetailModal .po-view-items-table th,
        #poDetailModal .po-view-items-table td { overflow-wrap:normal; word-break:normal; white-space:nowrap; vertical-align:top; }
        #poDetailModal .po-view-items-table th { white-space:normal; line-height:1.25; }
        #poDetailModal .po-view-items-table td:first-child,
        #poDetailModal .po-view-items-table .po-note-col { white-space:normal; overflow-wrap:break-word; word-break:normal; max-width:220px; }
        #poDetailModal .po-view-items-table .po-qty-col,
        #poDetailModal .po-view-items-table .po-money-col { white-space:nowrap; text-align:right; width:1%; }
        #poDetailModal .po-view-items-table .po-qty-unit { font-size:11px; font-weight:600; color:#64748b; }
        #poDetailModal .modal-footer { flex-wrap:wrap; gap:8px; }
        @media (max-width:768px) {
            #poDetailModal .modal.modal-lg,
            #poDetailModal .modal.modal-xl { width:96%; max-width:96%; }
            #poDetailModal .po-view-items-table { min-width:980px; font-size:12px; }
        }
        .badge-cyan { background:#cffafe; color:#155e75; }
        .badge-yellow { background:#fef3c7; color:#92400e; }
        .badge-gray { background:#f1f5f9; color:#475569; }
        .badge-violet { background:#ede9fe; color:#5b21b6; }
        .incoming-count-badge {
            display:inline-flex;align-items:center;justify-content:center;
            min-width:18px;height:18px;margin-left:6px;padding:0 6px;border-radius:999px;
            background:#ef4444;color:#fff;font-size:10px;font-weight:700;line-height:1;
        }
        .incoming-count-badge:empty { display:none; }
    </style>
</head>
<body>

<div class="dashboard-layout">

<!-- ═══════════ SIDEBAR ═══════════ -->
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
        <?php if ($isPurchaser): ?>
        <div class="nav-section-label">Main</div>
        <div class="nav-group open">
            <button class="nav-group-toggle active-group" onclick="toggleNavGroup(this)">
                <i class="bi bi-grid-1x2-fill nav-icon"></i>
                <span class="nav-text">Dashboard</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem active" onclick="showSection('dashboard', this)">Overview</button>
                <button class="nav-subitem" onclick="showSection('analytics', this)">Analytics</button>
                <button class="nav-subitem" onclick="showSection('procurement', this)">Procurement Overview</button>
            </div>
        </div>

        <div class="nav-section-label">User Record / Attendance</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-clock-history nav-icon"></i>
                <span class="nav-text">User Record</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('purchaser-audit-log', this)">Audit Log</button>
            </div>
        </div>

        <div class="nav-section-label">Operations</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-boxes nav-icon"></i>
                <span class="nav-text">Inventory</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('stock-inventory', this)">Inventory</button>
            </div>
        </div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-box-seam-fill nav-icon"></i>
                <span class="nav-text">Product Management</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('inventory', this)">Product Catalog</button>
            </div>
        </div>

        <div class="nav-section-label">Purchasing</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-people-fill nav-icon"></i>
                <span class="nav-text">Customer & Supplier Management</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('suppliers-view', this)"><i class="bi bi-building me-1"></i>Supplier Directory</button>
                <button class="nav-subitem" onclick="showSection('customers', this)"><i class="bi bi-people me-1"></i>Customers</button>
            </div>
        </div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-truck nav-icon"></i>
                <span class="nav-text">PR / PO Management</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('purchase-requests', this)"><i class="bi bi-file-earmark-plus me-1"></i>Purchase Requests</button>
                <button class="nav-subitem" onclick="showSection('purchase-orders', this)"><i class="bi bi-plus-square me-1"></i>PO Creation</button>
                <button class="nav-subitem" onclick="showSection('po-approvals', this)"><i class="bi bi-clipboard-check me-1"></i>PO Approval</button>
                <button class="nav-subitem" onclick="showSection('po-tracker', this)"><i class="bi bi-signpost-2 me-1"></i>PO Tracker</button>
                <button class="nav-subitem" onclick="showSection('purchase-history', this)"><i class="bi bi-journal-text me-1"></i>Purchase History</button>
                <button class="nav-subitem" onclick="showSection('supplier-invoices', this)"><i class="bi bi-receipt me-1"></i>Supplier Invoices</button>
            </div>
        </div>

        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-box-arrow-in-down nav-icon"></i>
                <span class="nav-text">Delivery Management</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('delivery-schedule', this)"><i class="bi bi-calendar-event me-1"></i>Delivery Schedule</button>
                <button class="nav-subitem" onclick="showSection('incoming-deliveries', this)"><i class="bi bi-truck me-1"></i>Incoming Deliveries <span class="incoming-count-badge" id="incomingDeliveriesBadge"></span></button>
                <?php if (isSecAllowed('markup-queue')): ?><button class="nav-subitem" onclick="showSection('markup-queue', this)"><i class="bi bi-percent me-1"></i>Pricing Queue</button><?php endif; ?>
            </div>
        </div>

        <div class="nav-section-label">Report</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-graph-up-arrow nav-icon"></i>
                <span class="nav-text">Reports</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('reports', this)">Inventory Reports</button>
                <button class="nav-subitem" onclick="showSection('sales-reports', this)">Sales Reports</button>
                <button class="nav-subitem" onclick="showSection('notifications', this); loadNotificationsHistory();">Alerts & History</button>
            </div>
        </div>

        <div class="nav-section-label">Account</div>
        <button class="nav-item" onclick="showSection('account', this)">
            <i class="bi bi-person-gear"></i>
            <span class="nav-text">My Account</span>
        </button>
        <?php elseif ($isWarehouseHead): ?>
        <div class="nav-section-label">Main</div>
        <div class="nav-group open">
            <button class="nav-group-toggle active-group" onclick="toggleNavGroup(this)">
                <i class="bi bi-grid-1x2-fill nav-icon"></i>
                <span class="nav-text">Dashboard</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem active" onclick="showSection('dashboard', this)">Overview</button>
                <button class="nav-subitem" onclick="showSection('analytics', this)">Analytics</button>
            </div>
        </div>

        <div class="nav-section-label">User Record / Attendance</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-clock-history nav-icon"></i>
                <span class="nav-text">User Record</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('warehouse-audit-log', this)">Audit Log</button>
            </div>
        </div>

        <div class="nav-section-label">Warehouse</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-box-seam-fill nav-icon"></i>
                <span class="nav-text">Inventory</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('stock-inventory', this)">Inventory</button>
                <button class="nav-subitem" onclick="showSection('inventory', this)">Product Catalog</button>
                <button class="nav-subitem" onclick="showSection('stock-barcodes', this)"><i class="bi bi-upc me-1"></i>Stock Barcodes</button>
                <button class="nav-subitem" onclick="showSection('barcode-scanner', this)"><i class="bi bi-upc-scan me-1"></i>Barcode Scanner</button>
                <button class="nav-subitem" onclick="showSection('location-stock-view', this)"><i class="bi bi-geo-alt me-1"></i>Location Stock</button>
                <button class="nav-subitem" onclick="showSection('damage-returns', this)"><i class="bi bi-exclamation-octagon me-1"></i>Replacement / Returns</button>
            </div>
        </div>

        <div class="nav-section-label">Receiving</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-truck nav-icon"></i>
                <span class="nav-text">Purchase Receiving</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('incoming-deliveries', this)"><i class="bi bi-truck me-1"></i>Incoming Deliveries <span class="incoming-count-badge" id="incomingDeliveriesBadge"></span></button>
                <button class="nav-subitem" onclick="showSection('purchase-orders', this)"><i class="bi bi-file-earmark-text me-1"></i>Purchase Orders</button>
                <button class="nav-subitem" onclick="showSection('po-tracker', this)"><i class="bi bi-signpost-2 me-1"></i>PO Tracker</button>
                <button class="nav-subitem" onclick="showSection('stock-in-queue', this)"><i class="bi bi-box-seam me-1"></i>Stock-In Queue</button>
            </div>
        </div>

        <?php if (isSecAllowed('customers') || isSecAllowed('suppliers-view')): ?>
        <div class="nav-section-label">Customers & Suppliers</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-people-fill nav-icon"></i>
                <span class="nav-text">Customer & Supplier Management</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('suppliers-view')): ?><button class="nav-subitem" onclick="showSection('suppliers-view', this)"><i class="bi bi-building me-1"></i>Supplier Directory</button><?php endif; ?>
                <?php if (isSecAllowed('customers')): ?><button class="nav-subitem" onclick="showSection('customers', this)"><i class="bi bi-people me-1"></i>Customers</button><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="nav-section-label">Orders</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-cart-check-fill nav-icon"></i>
                <span class="nav-text">Orders</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('sales-orders', this)">All Orders</button>
                <button class="nav-subitem" onclick="showSection('sales-credit', this)">Credit Agreements</button>
            </div>
        </div>

        <div class="nav-section-label">Billing</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-wallet2 nav-icon"></i>
                <span class="nav-text">Billing</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('billing-invoices', this)">Account Receivable</button>
                <button class="nav-subitem" onclick="showSection('unpaid-invoices', this)">Accounts Receivable / Pending</button>
            </div>
        </div>

        <div class="nav-section-label">Reports</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-graph-up-arrow nav-icon"></i>
                <span class="nav-text">Reports</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('reports', this)">Inventory Reports</button>
                <button class="nav-subitem" onclick="showSection('sales-reports', this)">Sales Reports</button>
            </div>
        </div>

        <div class="nav-section-label">Account</div>
        <button class="nav-item" onclick="showSection('account', this)">
            <i class="bi bi-person-gear"></i>
            <span class="nav-text">My Account</span>
        </button>
        <?php else: ?>
        <!-- Dashboard -->
        <?php if (isSecAllowed('dashboard') || isSecAllowed('analytics')): ?>
        <div class="nav-section-label">Main</div>
        <div class="nav-group open">
            <button class="nav-group-toggle active-group" onclick="toggleNavGroup(this)">
                <i class="bi bi-grid-1x2-fill nav-icon"></i>
                <span class="nav-text">Dashboard</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('dashboard')): ?><button class="nav-subitem active" onclick="showSection('dashboard', this)">Overview</button><?php endif; ?>
                <?php if (isSecAllowed('analytics')): ?><button class="nav-subitem" onclick="showSection('analytics', this)">Analytics</button><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Administration -->
        <?php if (isSecAllowed('users') || isSecAllowed('audit-log')): ?>
        <div class="nav-section-label">Administration</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-people-fill nav-icon"></i>
                <span class="nav-text">User Management</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('users')): ?><button class="nav-subitem" onclick="showSection('users', this)">Users</button><?php endif; ?>
                <?php if (isSecAllowed('audit-log')): ?><button class="nav-subitem" onclick="showSection('audit-log', this)">Activity Logs</button><?php endif; ?>
                <?php if ($isSystemAdmin): ?><button class="nav-subitem" onclick="showSection('technician-job-orders', this)">Technician Job Orders</button><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Operations / Inventory -->
        <?php if (isSecAllowed('inventory') || isSecAllowed('barcode-scanner') || isSecAllowed('data-entry') || isSecAllowed('serials-view') || isSecAllowed('low-stock-view') || isSecAllowed('expiry-view')): ?>
        <div class="nav-section-label">Operations</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-box-seam-fill nav-icon"></i>
                <span class="nav-text">Inventory</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('inventory')): ?><button class="nav-subitem" onclick="showSection('stock-inventory', this)">Inventory</button><?php endif; ?>
                <?php if (isSecAllowed('inventory')): ?><button class="nav-subitem" onclick="showSection('inventory', this)">Product Catalog</button><?php endif; ?>
                <?php if (isSecAllowed('stock-barcodes')): ?><button class="nav-subitem" onclick="showSection('stock-barcodes', this)"><i class="bi bi-upc me-1"></i>Stock Barcodes</button><?php endif; ?>
                <?php if (isSecAllowed('barcode-scanner')): ?><button class="nav-subitem" onclick="showSection('barcode-scanner', this)"><i class="bi bi-upc-scan me-1"></i>Barcode Scanner</button><?php endif; ?>
                <?php if (isSecAllowed('data-entry')): ?><button class="nav-subitem" onclick="showSection('data-entry', this)">Stock Movement</button><?php endif; ?>
                <?php if (isSecAllowed('serials-view')): ?><button class="nav-subitem" onclick="showSection('serials-view', this)"><i class="bi bi-qr-code me-1"></i>Serial Numbers</button><?php endif; ?>
                <?php if (isSecAllowed('inventory') || isSecAllowed('low-stock-view')): ?>
                <button class="nav-subitem" onclick="showSection('location-stock-view', this)"><i class="bi bi-geo-alt me-1"></i>Location Stock</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('suppliers-view') || isSecAllowed('customers')): ?>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-people-fill nav-icon"></i>
                <span class="nav-text">Customer & Supplier Management</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('suppliers-view')): ?><button class="nav-subitem" onclick="showSection('suppliers-view', this)"><i class="bi bi-building me-1"></i>Supplier Directory</button><?php endif; ?>
                <?php if (isSecAllowed('customers')): ?><button class="nav-subitem" onclick="showSection('customers', this)"><i class="bi bi-people me-1"></i>Customers</button><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Procurement -->
        <?php if (isSecAllowed('procurement') || isSecAllowed('purchase-requests') || isSecAllowed('pr-approval') || isSecAllowed('purchase-orders') || isSecAllowed('purchase-receipts') || isSecAllowed('supplier-invoices')): ?>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-truck nav-icon"></i>
                <span class="nav-text">Procurement</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('purchase-requests') || isSecAllowed('pr-approval') || isSecAllowed('supplier-invoices')): ?><button class="nav-subitem" onclick="showSection('purchase-requests', this)"><i class="bi bi-file-earmark-plus me-1"></i>Purchase Requests</button><?php endif; ?>
                <?php if (isSecAllowed('purchase-orders')): ?><button class="nav-subitem" onclick="showSection('purchase-orders', this)"><i class="bi bi-file-earmark-text me-1"></i>Purchase Orders</button><?php endif; ?>
                <?php if (isSecAllowed('incoming-deliveries')): ?><button class="nav-subitem" onclick="showSection('incoming-deliveries', this)"><i class="bi bi-truck me-1"></i>Incoming Deliveries <span class="incoming-count-badge" id="incomingDeliveriesBadge"></span></button><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('sales-orders')): ?>
        <div class="nav-section-label">Orders</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-cart-check-fill nav-icon"></i>
                <span class="nav-text">Orders</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <button class="nav-subitem" onclick="showSection('sales-orders', this)">All Orders</button>
                <button class="nav-subitem" onclick="showSection('sales-credit', this)">Credit Agreements</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('billing-invoices') || isSecAllowed('unpaid-invoices')): ?>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-wallet2 nav-icon"></i>
                <span class="nav-text">Billing</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('billing-invoices')): ?><button class="nav-subitem" onclick="showSection('billing-invoices', this)">Account Receivable</button><?php endif; ?>
                <?php if (isSecAllowed('unpaid-invoices')): ?><button class="nav-subitem" onclick="showSection('unpaid-invoices', this)">Accounts Receivable / Pending</button><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Insights / Reports -->
        <?php if (isSecAllowed('reports') || isSecAllowed('notifications')): ?>
        <div class="nav-section-label">Insights</div>
        <div class="nav-group">
            <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                <i class="bi bi-graph-up-arrow nav-icon"></i>
                <span class="nav-text">Reports</span>
                <i class="bi bi-chevron-right nav-chevron"></i>
            </button>
            <div class="nav-group-items">
                <?php if (isSecAllowed('reports')): ?><button class="nav-subitem" onclick="showSection('reports', this)">Inventory Reports</button><?php endif; ?>
                <?php if (isSecAllowed('sales-reports')): ?><button class="nav-subitem" onclick="showSection('sales-reports', this)">Sales Reports</button><?php endif; ?>
                <?php if (isSecAllowed('notifications')): ?><button class="nav-subitem" onclick="showSection('notifications', this); loadNotificationsHistory();">Alerts & History</button><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- System / Account -->
        <?php if (isSecAllowed('account')): ?>
        <?php if ($isSystemAdmin): ?>
        <div class="nav-section-label">System</div>
        <button class="nav-item" onclick="showSection('account', this)">
            <i class="bi bi-gear-fill"></i>
            <span class="nav-text">Settings</span>
        </button>
        <?php else: ?>
        <div class="nav-section-label">Account</div>
        <button class="nav-item" onclick="showSection('account', this)">
            <i class="bi bi-person-gear"></i>
            <span class="nav-text">My Account</span>
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="sidebar-user-role"><?= htmlspecialchars($role) ?></div>
            </div>
            <a href="?logout=1" class="sidebar-logout" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<!-- ═══════════ MAIN CONTENT ═══════════ -->
<div class="main-content">

    <!-- Top Bar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="topbar-toggle" id="sidebarToggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div class="topbar-breadcrumb">
                <span class="topbar-breadcrumb-item">Dashboard</span>
                <i class="bi bi-chevron-right topbar-breadcrumb-sep"></i>
                <span class="topbar-breadcrumb-current" id="breadcrumbCurrent"><?= $isPurchaser ? 'Audit Log' : 'Overview' ?></span>
            </div>
        </div>

        <div class="topbar-center">
            <div class="topbar-search">
                <i class="bi bi-search"></i>
                <input type="text" id="globalSearch" placeholder="Search products, suppliers, users..." onkeyup="handleGlobalSearch(this.value)">
            </div>
        </div>

        <div class="topbar-right">
            <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Toggle Dark Mode">
                <i class="bi bi-moon-stars-fill"></i>
            </button>

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

            <div class="topbar-divider"></div>

            <div class="topbar-user" onclick="showSection('account')">
                <div class="topbar-user-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
                <div class="topbar-user-info">
                    <div class="topbar-user-name"><?= htmlspecialchars($full_name) ?></div>
                    <div class="topbar-user-role"><?= htmlspecialchars($role) ?></div>
                </div>
            </div>
        </div>
    </header>

    <!-- Content Area -->
    <div class="content-area">
        <?php if ($alert): echo $alert; endif; ?>
        <?php if ($billing_alert): echo $billing_alert; endif; ?>
        <?php if (!empty($billing_print_invoice) || !empty($billing_print_receipt)): ?>
        <script>
        window.open(<?= json_encode(!empty($billing_print_invoice) ? billingCustomerInvoiceHref($billing_print_invoice) : billingPaymentReceiptHref($billing_print_receipt)) ?>, '_blank');
        </script>
        <?php endif; ?>
        <?php if ($isViewOnly): ?>
            <div class="alert alert-info">
                <i class="bi bi-lock-fill"></i> View-only mode: add, edit, delete, and print actions are disabled for your role.
            </div>
        <?php endif; ?>

        <!-- ═══════════ DASHBOARD OVERVIEW ═══════════ -->
        <div id="section-dashboard" class="content-section active">
            <div class="page-header">
                <h1>Welcome <?= htmlspecialchars($welcomeRoleLabel) ?></h1>
                <p>Welcome back, <?= htmlspecialchars($first_name ?: $full_name) ?>. Here's your inventory summary for <?= date('F j, Y') ?>.</p>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card blue" onclick="showSection('inventory')">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-box-seam"></i></div>
                    </div>
                    <div class="stat-card-label">Total Products</div>
                    <div class="stat-card-value"><?= count($inventory_items) ?></div>
                    <div class="stat-card-change neutral"><i class="bi bi-boxes"></i> <?= $category_count ?> categories</div>
                </div>
                <div class="stat-card emerald" onclick="showSection('reports')">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-currency-exchange"></i></div>
                    </div>
                    <div class="stat-card-label">Inventory Value</div>
                    <div class="stat-card-value">₱<?= number_format($total_value, 0) ?></div>
                    <div class="stat-card-change up"><i class="bi bi-graph-up-arrow"></i> Total valuation</div>
                </div>
                <div class="stat-card rose" onclick="showSection('low-stock-view')">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-exclamation-triangle"></i></div>
                    </div>
                    <div class="stat-card-label">Low Stock Items</div>
                    <div class="stat-card-value"><?= $low_stock ?></div>
                    <div class="stat-card-change <?= $low_stock > 0 ? 'down' : 'up' ?>"><i class="bi bi-<?= $low_stock > 0 ? 'arrow-down' : 'check' ?>"></i> <?= $low_stock > 0 ? 'Needs reorder' : 'All stocked' ?></div>
                </div>
                <div class="stat-card amber" onclick="showSection('expiry-view')">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-clock-history"></i></div>
                    </div>
                    <div class="stat-card-label">Near Expiry</div>
                    <div class="stat-card-value"><?= $near_expiry ?></div>
                    <div class="stat-card-change <?= $near_expiry > 0 ? 'down' : 'up' ?>"><i class="bi bi-<?= $near_expiry > 0 ? 'exclamation' : 'check' ?>-circle"></i> Within 3 months</div>
                </div>
                <div class="stat-card violet" onclick="showSection('inventory')">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-archive"></i></div>
                    </div>
                    <div class="stat-card-label">Total Stock Units</div>
                    <div class="stat-card-value"><?= number_format($total_items) ?></div>
                    <div class="stat-card-change neutral"><i class="bi bi-box-seam"></i> Items in warehouse</div>
                </div>
                <button type="button" class="stat-card cyan" onclick="routeToSection('suppliers-view', this)">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-building"></i></div>
                    </div>
                    <div class="stat-card-label">Active Suppliers</div>
                    <div class="stat-card-value"><?= count($suppliers_list) ?></div>
                    <div class="stat-card-change neutral"><i class="bi bi-truck"></i> Verified suppliers</div>
                </button>
                <?php if ($isWarehouseHead): ?>
                <button type="button" class="stat-card cyan" onclick="showSection('incoming-deliveries', this)">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-truck"></i></div>
                    </div>
                    <div class="stat-card-label">Incoming Deliveries</div>
                    <div class="stat-card-value kpi-incoming-deliveries" id="kpiIncomingDeliveries">0</div>
                    <div class="stat-card-change" id="kpiIncomingDeliveriesHint"><i class="bi bi-clock-history"></i> Waiting for delivery</div>
                </button>
                <?php else: ?>
                <button type="button" class="stat-card violet" onclick="routeToSection('purchase-requests', this)">
                    <div class="stat-card-header">
                        <div class="stat-card-icon"><i class="bi bi-file-earmark-check"></i></div>
                    </div>
                    <div class="stat-card-label">Purchase Requests</div>
                    <div class="stat-card-value" id="dashPendingPRCount"><?= $pending_pr_count ?></div>
                    <div class="stat-card-change <?= $pending_pr_count > 0 ? 'down' : 'up' ?>" id="dashPendingPRHint" data-pending-label="Pending Approval" data-none-label="No pending PRs"><i class="bi bi-<?= $pending_pr_count > 0 ? 'clock-history' : 'check-circle' ?>"></i> <?= $pending_pr_count > 0 ? $pending_pr_count . ' Pending Approval' : 'No pending PRs' ?></div>
                </button>
                <?php endif; ?>
            </div>

            <!-- Charts Row -->
            <div class="charts-grid">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-bar-chart-fill text-primary-color"></i> Inventory by Category</div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="categoryBarChart"></canvas></div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-pie-chart-fill text-primary-color"></i> Stock Distribution</div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="stockDoughnutChart"></canvas></div>
                    </div>
                </div>
            </div>

            <?php if ($isWarehouseHead): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="card-title mb-0"><i class="bi bi-truck text-primary-color me-2"></i>Incoming Deliveries / Incoming PO</div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge badge-orange incoming-deliveries-count-label">Incoming Deliveries: 0</span>
                        <button class="btn btn-outline-primary btn-sm" onclick="showSection('incoming-deliveries')">View All →</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table incoming-deliveries-table align-middle">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Ordered Items</th>
                                    <th>Ordered Quantity</th>
                                    <th>Expected Delivery Date</th>
                                    <th>PO Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="dashIncomingDeliveries">
                                <tr class="loading-row"><td colspan="7" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading incoming deliveries...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bottom Row: Activity + Top Products -->
            <div class="row g-4">
                <?php if ($isSystemAdmin): ?>
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title"><i class="bi bi-activity text-primary-color"></i> Recent Activity</div>
                        </div>
                        <div class="card-body" style="max-height:380px;overflow-y:auto;">
                            <ul class="activity-feed">
                                <?php if (!empty($recentActivity)): ?>
                                    <?php foreach(array_slice($recentActivity, 0, 10) as $act):
                                        $actionKey = $act['action_key'] ?? '';
                                        $iconClass = 'product';
                                        if (stripos($actionKey, 'login') !== false) $iconClass = 'login';
                                        elseif (stripos($actionKey, 'stock') !== false || stripos($actionKey, 'movement') !== false) $iconClass = 'stock-in';
                                        elseif (stripos($actionKey, 'delete') !== false) $iconClass = 'stock-out';
                                        elseif (stripos($actionKey, 'user') !== false) $iconClass = 'user';
                                        elseif (stripos($actionKey, 'adjust') !== false) $iconClass = 'adjustment';
                                    ?>
                                    <li class="activity-item">
                                        <div class="activity-icon <?= $iconClass ?>">
                                            <i class="bi bi-<?php
                                                if ($iconClass === 'login') echo 'box-arrow-in-right';
                                                elseif ($iconClass === 'stock-in') echo 'arrow-down-circle';
                                                elseif ($iconClass === 'stock-out') echo 'arrow-up-circle';
                                                elseif ($iconClass === 'user') echo 'person';
                                                elseif ($iconClass === 'adjustment') echo 'sliders';
                                                else echo 'box';
                                            ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-text"><strong><?= htmlspecialchars($act['username']) ?></strong> — <?= htmlspecialchars(substr($act['details'] ?? $actionKey, 0, 80)) ?></div>
                                            <div class="activity-time"><?= htmlspecialchars($act['created_at'] ?? '') ?></div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="activity-item">
                                        <div class="activity-icon product"><i class="bi bi-info-circle"></i></div>
                                        <div class="activity-content">
                                            <div class="activity-text">No recent activity recorded.</div>
                                            <div class="activity-time">System</div>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                <?php else: ?>
                <div class="col-lg-12">
                <?php endif; ?>
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title"><i class="bi bi-trophy-fill text-primary-color"></i> Product Overview</div>
                        </div>
                        <div class="card-body">
                            <div class="table-container system-table-scroll" style="max-height:380px;overflow-y:auto;">
                                <table class="modern-table">
                                    <thead>
                                        <tr>
                                            <th>SKU</th>
                                            <th>Product Name</th>
                                            <th>Brand</th>
                                            <th>Category</th>
                                            <th>Qty</th>
                                            <th>Price</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach(array_slice($inventory_items, 0, 10) as $item): ?>
                                        <tr>
                                            <td><span class="badge badge-blue"><?= htmlspecialchars($item['sku']) ?></span></td>
                                            <td><?= htmlspecialchars($item['name']) ?></td>
                                            <td><?= htmlspecialchars($item['brand']) ?></td>
                                            <td><?= htmlspecialchars($item['category']) ?></td>
                                            <td>
                                                <?php if($item['qty'] <= $item['reorder_level']): ?>
                                                    <span class="badge badge-red"><?= $item['qty'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-green"><?= $item['qty'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>₱<?= number_format($item['price'], 2) ?></td>
                                            <td><span class="badge <?= $item['stock_class'] ?>"><?= htmlspecialchars($item['stock_label']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($inventory_items)): ?>
                                        <tr><td colspan="7" style="text-align:center;color:var(--text-tertiary);padding:32px;">No products found.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ ANALYTICS SECTION ═══════════ -->
        <div id="section-analytics" class="content-section">
            <div class="page-header">
                <h1>Analytics & Insights</h1>

            </div>
            <div class="charts-grid">
                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-graph-up text-primary-color"></i> Stock Level Trends</div></div>
                    <div class="card-body"><div class="chart-container"><canvas id="stockTrendChart"></canvas></div></div>
                </div>
                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-diagram-3-fill text-primary-color"></i> Supplier Performance</div></div>
                    <div class="card-body">
                        <div class="table-container system-table-scroll">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Supplier Name</th>
                                        <th>Contact Person</th>
                                        <th>Contact Number</th>
                                        <th>Products Supplied</th>
                                        <th>Last Delivery Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($supplier_performance_rows)): ?>
                                    <?php foreach ($supplier_performance_rows as $supplierRow): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($supplierRow['supplier_name']) ?></td>
                                            <td><?= htmlspecialchars($supplierRow['contact_person']) ?></td>
                                            <td><?= htmlspecialchars($supplierRow['contact_number']) ?></td>
                                            <td><?= $supplierRow['products_supplied'] > 0 ? (int)$supplierRow['products_supplied'] : 'N/A' ?></td>
                                            <td><?= !empty($supplierRow['last_delivery_date']) ? htmlspecialchars(date('M d, Y', strtotime($supplierRow['last_delivery_date']))) : 'N/A' ?></td>
                                            <td>
                                                <?php if ($supplierRow['status'] === 'No Delivery Record'): ?>
                                                    <span class="badge badge-gray">No Delivery Record</span>
                                                <?php elseif ($supplierRow['status'] === 'Active'): ?>
                                                    <span class="badge badge-green">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-gray">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:32px;">No supplier records available.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="charts-grid">
                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-bar-chart-steps text-primary-color"></i> Inventory Movement</div></div>
                    <div class="card-body"><div class="chart-container"><canvas id="movementBarChart"></canvas></div></div>
                </div>
                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-pie-chart text-primary-color"></i> Value by Category</div></div>
                    <div class="card-body"><div class="chart-container"><canvas id="valuePieChart"></canvas></div></div>
                </div>
            </div>
        </div>

        <!-- ═══════════ PRODUCT CATALOG ═══════════ -->
        <div id="section-inventory" class="content-section">
            <div class="page-header"><h1>Product Catalog</h1></div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">All Products</div>
                    <div class="catalog-toolbar">
                        <?php if ($canManageRecords && !$isWarehouseHead && isSecAllowed('data-entry')): ?>
                            <button class="btn btn-primary btn-sm" onclick="showSection('data-entry')"><i class="bi bi-plus-circle me-1"></i>Add Product</button>
                        <?php endif; ?>
                        <?php if ($isPurchaser && $canManageRecords && isSecAllowed('data-entry')): ?>
                            <form method="POST" enctype="multipart/form-data" style="display:inline;">
                                <?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>
                                <input type="hidden" name="import_products" value="1">
                                <input type="file" name="product_import" accept=".csv,text/csv" hidden onchange="if(!this.files.length)return;var input=this;appConfirm('Import products from '+input.files[0].name+'? Existing products stay. Rows with the same name are skipped.').then(function(ok){if(ok)input.form.submit();else input.value='';});">
                                <button class="btn btn-outline-primary btn-sm" type="button" onclick="this.previousElementSibling.click()"><i class="bi bi-upload me-1"></i>Import File</button>
                            </form>
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="downloadProductImportTemplate()"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV Template</button>
                        <?php endif; ?>
                        <?php if ($isSystemAdmin && $canManageRecords): ?>
                            <button class="btn btn-danger btn-sm" onclick="confirmDeleteAllProducts()"><i class="bi bi-trash-fill me-1"></i>Delete All</button>
                        <?php endif; ?>
                        <div class="search-box catalog-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="productSearchInput" placeholder="Search product name, SKU, or barcode..." onkeyup="applyProductFilters()">
                        </div>
                        <select id="catalogStatusFilter" class="form-select catalog-filter-select" onchange="applyProductFilters()">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <select id="categoryFilter" class="form-select catalog-category-filter" onchange="applyProductFilters()">
                            <?= productCatalogFilterSelectHtml($catalog_parents, $catalog_by_parent) ?>
                        </select>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="productsTable">
                            <thead>
                                <tr>
                                    <th>SKU</th><th>Product Name</th><th>Item Description</th><th>Packaging</th><th>Unit</th>
                                    <th>Supplier</th><th>Brand</th><th>Category</th><th>Sub-Category</th>
                                    <th>Temperature</th><th>Status</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach($inventory_items as $item): ?>
                                <?php
                                    $pStatus = strtolower(trim((string)($item['product_status'] ?? 'Active')));
                                    $statusFilter = in_array($pStatus, ['inactive', 'discontinued'], true) ? 'inactive' : 'active';
                                    $statusClass = $statusFilter === 'active' ? 'badge-green' : 'badge-gray';
                                    $statusLabel = $statusFilter === 'active' ? 'Active' : (($item['product_status'] ?? '') !== '' ? $item['product_status'] : 'Inactive');
                                    $viewPayload = htmlspecialchars(json_encode([
                                        'sku' => $item['sku'],
                                        'name' => $item['name'],
                                        'description' => $item['description'] ?? '',
                                        'packaging' => $item['packaging'] ?? '',
                                        'uom' => $item['uom'] ?? '',
                                        'supplier_name' => $item['supplier_name'] ?? '',
                                        'brand' => $item['brand'] ?? '',
                                        'parent_category' => $item['parent_category'] ?? '',
                                        'category' => $item['category'] ?? '',
                                        'storage_temperature' => $item['storage_temperature'] ?? '',
                                        'status' => $statusLabel,
                                        'barcode' => $item['barcode'] ?? '',
                                        'image' => $item['image'] ?? '',
                                    ]), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr data-category="<?= htmlspecialchars(strtolower($item['category'])) ?>" data-parent="<?= htmlspecialchars(strtolower($item['parent_category'] ?? '')) ?>" data-status="<?= htmlspecialchars($statusFilter) ?>" data-barcodes="<?= htmlspecialchars(trim(implode(' ', array_filter(array_merge($item['barcode_values'] ?? [], [ (string)($item['barcode'] ?? '') ]))))) ?>">
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($item['sku']) ?></span></td>
                                    <td><button type="button" class="product-image-link" onclick="showProductImageModal(<?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($item['image']), ENT_QUOTES, 'UTF-8') ?>)"><?= htmlspecialchars($item['name']) ?></button></td>
                                    <td><?= htmlspecialchars($item['description'] !== '' ? $item['description'] : '—') ?></td>
                                    <td><?= htmlspecialchars($item['packaging'] !== '' ? $item['packaging'] : '—') ?></td>
                                    <td><?= htmlspecialchars($item['uom']) ?></td>
                                    <td><?= htmlspecialchars($item['supplier_name']) ?></td>
                                    <td><?= htmlspecialchars($item['brand'] !== '' ? $item['brand'] : '—') ?></td>
                                    <td><?= htmlspecialchars(($item['parent_category'] ?? '') !== '' ? $item['parent_category'] : $item['category']) ?></td>
                                    <td><?= htmlspecialchars(($item['parent_category'] ?? '') !== '' ? $item['category'] : '—') ?></td>
                                    <td><?= htmlspecialchars(trim((string)($item['storage_temperature'] ?? '')) !== '' ? $item['storage_temperature'] : '—') ?></td>
                                    <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                    <td>
                                        <button class="btn-icon" title="View" onclick="viewCatalogProduct(<?= $viewPayload ?>)"><i class="bi bi-eye"></i></button>
                                        <?php if ($canWarehouseLimitedProductUpdate): ?>
                                            <button class="btn-icon" onclick="editProduct('<?= $item['sku'] ?>','<?= addslashes($item['name']) ?>','<?= addslashes($item['category']) ?>',<?= $item['qty'] ?>,<?= $item['price'] ?>,'<?= addslashes($item['location']) ?>',<?= $item['cost'] ?>,'<?= addslashes($item['uom']) ?>',<?= $item['supplier_id'] ?>,'<?= addslashes($item['barcode']) ?>','<?= addslashes($item['expiry_raw']) ?>','<?= addslashes($item['brand']) ?>','<?= addslashes($item['size']) ?>','<?= addslashes($item['packaging']) ?>',<?= $item['reorder_level'] ?>,'<?= addslashes($item['image']) ?>',<?= (int)($item['expiry_tracking'] ?? 0) ?>,<?= (int)($item['category_id'] ?? 0) ?>,<?= (int)($item['parent_category_id'] ?? 0) ?>,'<?= addslashes($item['storage_temperature'] ?? '') ?>','<?= addslashes($item['description'] ?? '') ?>','<?= addslashes($item['product_status'] ?? 'Active') ?>')" title="Edit"><i class="bi bi-pencil"></i></button>
                                            <?php if ($canDeleteRecords && !$isWarehouseHead): ?>
                                            <button class="btn-icon delete" onclick="deleteProduct('<?= $item['sku'] ?>','<?= addslashes($item['name']) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                                            <?php endif; ?>
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

        <?php require __DIR__ . '/includes/ui_inventory_stock_section.php'; ?>

        <!-- ═══════════ STOCK MOVEMENT / DATA ENTRY ═══════════ -->
        <?php if (isSecAllowed('data-entry')): ?>
        <div id="section-data-entry" class="content-section">
            <div class="page-header"><h1>Stock Movement & Deliveries</h1></div>
            <div class="card">
                <div class="card-body">
                <?php if ($isPurchaser || $isWarehouseHead): ?>
                    <?php renderStockMovementHistoryTable($stock_movement_history, ''); ?>
                <?php else: ?>
                <?php if ($canManageRecords): ?>
                <ul class="nav nav-pills mb-4" id="dataEntryTabs">
                    <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="pill" data-bs-target="#add-product-entry-tab"><i class="bi bi-plus-circle me-1"></i>Add Product</button></li>
                    <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="pill" data-bs-target="#category-entry-tab"><i class="bi bi-tags me-1"></i>Category</button></li>
                </ul>
                <?php endif; ?>
                <div class="tab-content">
                    <div class="tab-pane fade<?= $canManageRecords ? '' : ' show active' ?>" id="stock-movement">
                        <form method="POST" enctype="multipart/form-data" id="adminStockMovementForm"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                            <fieldset <?= $canManageRecords ? '' : 'disabled' ?>>
                            <div class="form-group">
                                <label class="form-label">Product <span style="color:var(--accent-rose);">*</span></label>
                                <select name="product" id="adminStockMovementProduct" class="form-select" required>
                                <option value="">Select a product</option>
                                    <?php foreach($inventory_items as $i): ?>
                                        <option value="<?= $i['sku'] ?>"
                                            data-name="<?= htmlspecialchars($i['name']) ?>"
                                            data-qty="<?= (int)$i['qty'] ?>"
                                            data-uom="<?= htmlspecialchars($i['uom'] ?? 'piece') ?>"
                                            data-brand="<?= htmlspecialchars($i['brand'] ?? '') ?>"
                                            data-category="<?= htmlspecialchars($i['category'] ?? '') ?>"
                                            data-packaging="<?= htmlspecialchars($i['packaging'] ?? '') ?>"
                                        ><?= htmlspecialchars($i['name']) ?> (<?= $i['sku'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="adminStockMovementDetails" class="form-group" style="display:none;">
                                <label class="form-label">Product Details</label>
                                <div id="adminStockMovementDetailsText" class="form-control" style="min-height:auto;height:auto;background:var(--bg-secondary, #f8fafc);font-size:0.9rem;"></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Available Quantity</label>
                                <input type="text" id="adminStockMovementAvailable" class="form-control" placeholder="Select a product to load available stock" readonly>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Movement Type <span style="color:var(--accent-rose);">*</span></label>
                                        <select name="movement_type" id="adminStockMovementType" class="form-select" required>
                                            <option value="">Select type</option>
                                            <option>Stock In</option>
                                            <option>Stock Out</option>
                                            <option>Transfer</option>
                                            <option>Return</option>
                                            <option>Damage</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Quantity <span style="color:var(--accent-rose);">*</span></label>
                                        <input type="number" name="quantity" id="adminStockMovementQty" class="form-control" placeholder="Enter quantity" min="1" required>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group" id="adminStockMovementBatchWrap" style="display:none;">
                                <label class="form-label">Batch / Lot</label>
                                <select name="batch_id" id="adminStockMovementBatch" class="form-select">
                                    <option value="">Use FEFO / no specific batch</option>
                                </select>
                            </div>
                            <div class="form-group" id="adminStockMovementRefSelectWrap" style="display:none;">
                                <label class="form-label">Related References</label>
                                <select id="adminStockMovementRefSelect" class="form-select">
                                    <option value="">Select a related reference</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reference Number <span style="color:var(--accent-rose);">*</span></label>
                                <input type="text" name="reference" id="adminStockMovementReference" class="form-control" placeholder="e.g., PO-2026-001" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" id="adminStockMovementNotes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                            </div>
                            <button type="submit" name="submit_movement" class="btn-primary w-100">
                                <i class="bi bi-check-circle"></i> Submit Stock Movement
                            </button>
                            </fieldset>
                        </form>
                        <?php renderStockMovementHistoryTable($stock_movement_history); ?>
                    </div>
                    <div class="tab-pane fade" id="deliveries">
                        <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                            <fieldset <?= $canManageRecords ? '' : 'disabled' ?>>
                            <div class="form-group">
                                <label class="form-label">Product <span style="color:var(--accent-rose);">*</span></label>
                                <select name="del_product" class="form-select" required>
                                    <option value="">Select a product</option>
                                    <?php foreach($inventory_items as $i): ?>
                                        <option value="<?= $i['sku'] ?>"><?= htmlspecialchars($i['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Quantity <span style="color:var(--accent-rose);">*</span></label>
                                        <input type="number" name="del_quantity" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Delivery Date <span style="color:var(--accent-rose);">*</span></label>
                                        <input type="date" name="del_date" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Supplier <span style="color:var(--accent-rose);">*</span></label>
                                <input type="text" name="del_supplier" class="form-control" placeholder="Supplier name" required>
                            </div>
                            <button type="submit" name="submit_delivery" class="btn-primary w-100">
                                <i class="bi bi-truck"></i> Submit Delivery
                            </button>
                            </fieldset>
                        </form>
                    </div>
                    <?php if ($canManageRecords): ?>
                    <div class="tab-pane fade show active" id="add-product-entry-tab">
                        <h6 style="font-weight:700;margin-bottom:20px;"><i class="bi bi-plus-circle me-2 text-primary-color"></i>Add New Product</h6>
                        <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                            <div class="row">
                                <div class="col-md-6"><div class="form-group"><label class="form-label">Supplier <span style="color:var(--accent-rose);">*</span></label><select name="new_supplier_id" class="form-select" required><option value="">Select Supplier</option><?php foreach($suppliers_list as $s): ?><option value="<?= (int)$s['id'] ?>"><?= (int)$s['id'] ?> - <?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select></div></div>
                                <div class="col-md-6"><div class="form-group"><label class="form-label">Category <span style="color:var(--accent-rose);">*</span></label><select name="new_parent_category_id" id="new_parent_category_id" class="form-select" required onchange="if(typeof onCatalogParentChange==='function')onCatalogParentChange('new')"><option value="">Select Category</option><?php foreach($catalog_parents as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?></select></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6"><div class="form-group"><label class="form-label">Subcategory <span style="color:var(--accent-rose);">*</span></label><select name="new_category_id" id="new_category_id" class="form-select" required onchange="if(typeof applyCategoryExpiryDefault==='function')applyCategoryExpiryDefault(this); if(typeof generateProductName==='function')generateProductName();"><option value="">Select Subcategory</option></select></div></div>
                                <div class="col-md-6"><div class="form-group"><label class="form-label">Packaging / Specification <span style="color:var(--accent-rose);">*</span></label><input type="text" name="new_packaging" id="new_packaging" class="form-control" placeholder="e.g. 100 pcs/box, 21G" required></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Brand <span style="color:var(--accent-rose);">*</span></label><input type="text" name="new_brand" id="new_brand" class="form-control" placeholder="e.g. Indoplas" required></div></div>
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Size <span style="color:var(--accent-rose);">*</span></label><input type="text" name="new_size" id="new_size" class="form-control" placeholder="e.g. Large" required></div></div>
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Unit <span style="color:var(--accent-rose);">*</span></label><select name="new_unit_of_measure" class="form-select" required><?= productCatalogRenderUnitOptions('pcs') ?></select></div></div>
                            </div>
                            <div class="form-group"><label class="form-label">Product Name <span style="color:var(--accent-rose);">*</span></label><input type="text" name="new_name" id="new_name" class="form-control" placeholder="Enter product name (auto-fills from details)" required style="font-weight:600;"></div>
                            <div class="form-group"><label class="form-label">Barcode</label><input type="text" name="new_barcode" class="form-control" placeholder="Optional — can be registered when receiving"><div class="form-text">Leave blank if boxes will be scanned at Receive &amp; Inspect.</div></div>
                            <div class="form-group"><label class="form-label">Product Image</label><input type="file" name="new_product_image" id="new_product_image" class="form-control product-image-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-preview="newProductImagePreview" data-message="newProductImageMessage"><div id="newProductImageMessage" class="product-image-message"></div><div id="newProductImagePreview" class="product-upload-preview"><span>No image selected.</span></div></div>
                            <div class="form-group"><label class="form-label">Description</label><textarea name="new_description" class="form-control" rows="2" placeholder="Enter product description"></textarea></div>
                            <div class="row">
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Initial Quantity</label><input type="number" min="0" name="new_quantity" class="form-control" placeholder="0" value="0"><div class="form-text">Optional. Leave 0 and receive stock through Inventory / Goods Received.</div></div></div>
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Reorder Level</label><input type="number" min="0" name="new_reorder_level" class="form-control" placeholder="50" value="50"></div></div>
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Expiry Tracking</label><select name="new_expiry_tracking" id="new_expiry_tracking" class="form-select" onchange="if(typeof syncExpiryTrackingFields==='function')syncExpiryTrackingFields()"><option value="0">No</option><option value="1">Yes</option></select></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Expiry Date</label><input type="date" name="new_expiry_date" id="new_expiry_date" class="form-control"></div></div>
                            </div>
                            <div class="form-group"><label class="form-label">Location</label><div class="d-flex gap-2"><select name="new_location_id" class="form-select"><option value="">Select Location</option><?php foreach($location_options as $loc): ?><option value="<?= (int)$loc['id'] ?>"><?= htmlspecialchars($loc['label']) ?></option><?php endforeach; ?></select><button type="button" class="btn-secondary" data-bs-toggle="modal" data-bs-target="#addLocationModal">Add</button></div></div>
                            <div class="row">
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Cost Price <span style="color:var(--accent-rose);">*</span></label><input type="number" step="0.01" min="0" name="new_cost" class="form-control" placeholder="0.00" required></div></div>
                                <div class="col-md-4"><div class="form-group"><label class="form-label">Selling Price <span style="color:var(--accent-rose);">*</span></label><input type="number" step="0.01" min="0" name="new_selling_price" class="form-control" placeholder="0.00" required></div></div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Storage Temperature</label>
                                        <select name="new_storage_temp" id="new_storage_temp" class="form-select" onchange="if(typeof syncStorageTempOther==='function')syncStorageTempOther('new')">
                                            <option value="">Select...</option>
                                            <?php foreach (productCatalogStorageTempOptions() as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="new_storage_temp_other" id="new_storage_temp_other" class="form-control mt-2 d-none" placeholder="Enter storage temperature">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_product" class="btn-primary w-100"><i class="bi bi-plus-circle"></i> Add Product</button>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="category-entry-tab">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h6 style="font-weight:700;margin:0;"><i class="bi bi-tags me-2 text-primary-color"></i>Category Management</h6>
                            <button class="btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal"><i class="bi bi-plus-circle"></i> Add Category</button>
                        </div>
                        <div class="table-container system-table-scroll">
                            <table class="modern-table">
                                <thead><tr><th>ID</th><th>Name</th><th>Parent Category</th><th>Description</th><th>Default Expiry Tracking</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php
                                $catNameById = [];
                                foreach ($categories_list as $cx) { $catNameById[(int)$cx['id']] = (string)$cx['name']; }
                                foreach($categories_list as $c):
                                    $pid = isset($c['parent_id']) && $c['parent_id'] !== null && $c['parent_id'] !== '' ? (int)$c['parent_id'] : 0;
                                ?>
                                    <tr>
                                        <td><?= (int)$c['id'] ?></td>
                                        <td><?= htmlspecialchars($c['name']) ?></td>
                                        <td><?= $pid > 0 ? htmlspecialchars($catNameById[$pid] ?? '—') : '—' ?></td>
                                        <td><?= htmlspecialchars((string)($c['description']??'')) ?></td>
                                        <td><?= isset($c['default_expiry_tracking']) && $c['default_expiry_tracking'] !== null && $c['default_expiry_tracking'] !== '' ? ((int)$c['default_expiry_tracking'] === 1 ? 'Yes' : 'No') : '—' ?></td>
                                        <td>
                                            <button class="btn-icon" title="Edit" onclick="editCategory(<?= (int)$c['id'] ?>,'<?= addslashes($c['name']) ?>','<?= addslashes((string)($c['description']??'')) ?>',<?= isset($c['default_expiry_tracking']) && $c['default_expiry_tracking'] !== null && $c['default_expiry_tracking'] !== '' ? (int)$c['default_expiry_tracking'] : 'null' ?>,<?= $pid > 0 ? $pid : 'null' ?>)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn-icon delete" title="Delete" onclick="deleteCategory(<?= (int)$c['id'] ?>,'<?= addslashes($c['name']) ?>')"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════ LOW STOCK VIEW ═══════════ -->
        <div id="section-low-stock-view" class="content-section">
            <div class="page-header"><h1>Low Stock Alerts</h1></div>
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <?php $canCreatePrFromLowStock = ($isSystemAdmin || $isPurchaser) && !$isWarehouseHead; ?>
                            <thead><tr><th>SKU</th><th>Product Name</th><th>Brand</th><th>Current Qty</th><th>Reorder Level</th><th>Shortage</th><th>Status</th><?php if ($canCreatePrFromLowStock): ?><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody id="lowStockViewBody">
                            <?php $hasLow = false; ?>
                            <?php foreach($inventory_items as $item): $sellableQty = (int)($item['sellable'] ?? $item['qty']); if(invWmsIsLowStock($sellableQty, (int)$item['reorder_level'])): $hasLow = true; $shortageQty = max(0, (int)$item['reorder_level'] - $sellableQty); ?>
                                <tr>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($item['sku']) ?></span></td>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= htmlspecialchars($item['brand']) ?></td>
                                    <td><span class="badge badge-red"><?= $item['qty'] ?></span></td>
                                    <td><?= $item['reorder_level'] ?></td>
                                    <td><span class="badge badge-orange"><?= $shortageQty ?></span></td>
                                    <td><span class="badge badge-red">Needs Reorder</span></td>
                                    <?php if ($canCreatePrFromLowStock): ?>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openCreatePRFromSuggestion(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode((string)$item['name']), ENT_QUOTES, 'UTF-8') ?>, <?= (int)($item['supplier_id'] ?? 0) ?>, <?= max(1, $shortageQty) ?>, <?= (float)($item['cost'] ?? 0) ?>)"><i class="bi bi-file-earmark-plus me-1"></i>Create PR</button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endif; endforeach; ?>
                            <?php if (!$hasLow): ?>
                                <tr><td colspan="<?= $canCreatePrFromLowStock ? 8 : 7 ?>" style="text-align:center;color:var(--text-tertiary);padding:40px;"><i class="bi bi-check-circle-fill" style="font-size:24px;color:var(--accent-emerald);"></i><br>All products are sufficiently stocked.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ EXPIRY VIEW ═══════════ -->
        <div id="section-expiry-view" class="content-section">
            <div class="page-header"><h1>Near Expiry Alerts</h1></div>
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead><tr><th>SKU</th><th>Product Name</th><th>Brand</th><th>Expiry Date</th><th>Qty in Stock</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php $hasExpiryAlerts = false; ?>
                            <?php foreach($inventory_items as $item): ?>
                                <?php if (in_array($item['expiry_label'], ['Expired', 'Expiring Soon', 'Near Expiry'], true)): $hasExpiryAlerts = true; ?>
                                    <tr>
                                        <td><span class="badge badge-blue"><?= htmlspecialchars($item['sku']) ?></span></td>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td><?= htmlspecialchars($item['brand']) ?></td>
                                        <td><?= htmlspecialchars($item['expiry_display']) ?></td>
                                        <td><?= $item['qty'] ?></td>
                                        <td><span class="badge <?= htmlspecialchars($item['expiry_class']) ?>"><?= htmlspecialchars($item['expiry_label']) ?></span></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!$hasExpiryAlerts): ?>
                                <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:40px;"><i class="bi bi-shield-check" style="font-size:24px;color:var(--accent-emerald);"></i><br>No expired or near-expiry items found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ LOCATION STOCK ═══════════ -->
        <div id="section-location-stock-view" class="content-section">
            <div class="page-header"><h1>Location Stock</h1></div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                    <div class="card-title mb-0">Stock by Location</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box" style="width:min(100%,220px)">
                            <i class="bi bi-search"></i>
                            <input type="text" id="locStockSearch" placeholder="Search…" oninput="loadLocationStock()">
                        </div>
                        <select id="locStockLocationFilter" class="form-select form-select-sm" style="width:min(100%,180px)" onchange="loadLocationStock()">
                            <option value="">All Locations</option>
                        </select>
                        <button class="btn btn-sm btn-outline-primary" onclick="loadLocationStock()"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead><tr><th>SKU</th><th>Product</th><th>Brand</th><th>Location</th><th>Qty Here</th><th>Total Stock</th><th>Unit</th></tr></thead>
                            <tbody id="locStockBody"><tr class="loading-row"><td colspan="7" class="text-center py-4 text-muted">Open this section to load…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ TRANSFERS ═══════════ -->
        <div id="section-transfers-view" class="content-section">
            <div class="page-header"><h1>Stock Transfers</h1></div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <div class="card-title mb-0">Transfer History</div>
                    <?php if ($isSystemAdmin || $isWarehouseHead): ?>
                    <button class="btn btn-primary btn-sm" onclick="openTransferModal()"><i class="bi bi-plus-lg me-1"></i>New Transfer</button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead><tr><th>Transfer #</th><th>From</th><th>To</th><th>Items</th><th>Qty</th><th>Status</th><th>By</th><th>Date</th></tr></thead>
                            <tbody id="transfersBody"><tr class="loading-row"><td colspan="8" class="text-center py-4 text-muted">Open this section to load…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ INCOMING DELIVERIES ═══════════ -->
        <?php if (isSecAllowed('incoming-deliveries')): ?>
        <div id="section-incoming-deliveries" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Incoming Deliveries</h1>

                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <span class="badge badge-orange incoming-deliveries-count-label">Incoming Deliveries: 0</span>
                    <button class="btn btn-outline-secondary" onclick="loadIncomingDeliveries()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
                    <input type="date" id="incomingDateFrom" class="form-control form-control-sm" style="width:140px;max-width:100%;" title="From Date" aria-label="From Date" onchange="syncIncomingDeliveriesDateBounds()" onkeydown="if(event.key==='Enter'){event.preventDefault();applyIncomingDeliveriesFilter();}">
                    <input type="date" id="incomingDateTo" class="form-control form-control-sm" style="width:140px;max-width:100%;" title="To Date" aria-label="To Date" onchange="syncIncomingDeliveriesDateBounds()" onkeydown="if(event.key==='Enter'){event.preventDefault();applyIncomingDeliveriesFilter();}">
                    <button type="button" class="btn btn-outline-secondary" onclick="applyIncomingDeliveriesFilter()"><i class="bi bi-funnel me-1"></i> Apply Filter</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearIncomingDeliveriesFilter()" title="Clear date filter"><i class="bi bi-x-circle me-1"></i> Clear</button>
                </div>
            </div>
            <?php $incomingGrActiveTab = 'incoming-deliveries'; require __DIR__ . '/includes/ui_incoming_gr_tabs.php'; ?>
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table incoming-deliveries-table">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Ordered Items</th>
                                    <th>Ordered Quantity</th>
                                    <th>Expected Delivery Date</th>
                                    <th>PO Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="incomingDeliveriesBody">
                                <tr class="loading-row"><td colspan="8" style="text-align:center;padding:32px;color:var(--text-tertiary);"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading incoming deliveries...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════ PURCHASE ORDERS VIEW ═══════════ -->
        <?php if (isSecAllowed('purchase-orders')): ?>
        <div id="section-purchase-orders" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Purchase Orders</h1>

                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" onclick="loadPurchaseOrders()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
                    <?php if (!$isViewOnly && ($isSystemAdmin || $isPurchaser)): ?>
                    <button class="btn btn-primary" onclick="routeToSection('purchase-requests', this)"><i class="bi bi-plus-lg me-1"></i> Create from Approved PR</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter & Toolbar -->
            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-5">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="poSearch" class="form-control" placeholder="Search PO # or supplier..." onkeyup="filterPOs()">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select id="poStatusFilter" class="form-select" onchange="loadPurchaseOrders()">
                                <option value="all">All Statuses</option>
                                <option value="Draft">Draft</option>
                                <option value="Submitted">Submitted</option>
                                <option value="Pending Approval">Pending Approval</option>
                                <option value="Approved">Approved</option>
                                <option value="Sent">Sent</option>
                                <option value="Partially Received">Partially Received</option>
                                <option value="Received">Received</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Items</th>
                                    <th>Total Amount</th>
                                    <th>Purchase Price</th>
                                    <th>Markup Price</th>
                                    <th>Selling Price</th>
                                    <th>Status</th>
                                    <th>Receiving</th>
                                    <th>Expected Date</th>
                                    <th>Requested By</th>
                                    <th>Created Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="poBody">
                                <tr class="loading-row"><td colspan="13" style="text-align:center;padding:32px;color:var(--text-tertiary);"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading Purchase Orders...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php require __DIR__ . '/includes/ui_po_lifecycle_sections.php'; ?>

        <!-- ═══════════ PURCHASE RECEIPTS VIEW ═══════════ -->
        <!-- PURCHASE HISTORY VIEW -->
        <?php if ($isPurchaser && isSecAllowed('purchase-history')): ?>
        <div id="section-purchase-history" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Purchase History</h1>

                </div>
                <button class="btn btn-outline-secondary" onclick="showSection('purchase-orders')"><i class="bi bi-file-earmark-text me-1"></i> View Purchase Orders</button>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Purchase Order History</div>
                    <div class="search-box" style="width:min(100%,280px); max-width:100%;">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Search purchase history..." onkeyup="filterTable(this, 'purchaseHistoryTable')">
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="purchaseHistoryTable">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Order Date</th>
                                    <th>Expected Delivery</th>
                                    <th>Items</th>
                                    <th>Units Ordered</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($purchase_history_rows)): ?>
                                    <?php foreach ($purchase_history_rows as $historyRow): ?>
                                        <tr>
                                            <td><strong style="font-family:monospace" class="text-primary"><?= htmlspecialchars((string)($historyRow['po_number'] ?? 'N/A')) ?></strong></td>
                                            <td><?= htmlspecialchars((string)($historyRow['supplier_name'] ?? 'N/A')) ?></td>
                                            <td><?= !empty($historyRow['order_date']) ? htmlspecialchars(date('M d, Y', strtotime((string)$historyRow['order_date']))) : 'N/A' ?></td>
                                            <td><?= !empty($historyRow['expected_delivery_date']) ? htmlspecialchars(date('M d, Y', strtotime((string)$historyRow['expected_delivery_date']))) : 'N/A' ?></td>
                                            <td><span class="badge badge-gray"><?= (int)($historyRow['item_count'] ?? 0) ?> item(s)</span></td>
                                            <td><?= number_format((int)($historyRow['units_ordered'] ?? 0)) ?></td>
                                            <td>PHP <?= number_format((float)($historyRow['total_amount'] ?? 0), 2) ?></td>
                                            <td><span class="badge badge-blue"><?= htmlspecialchars((string)($historyRow['status'] ?? 'N/A')) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" style="text-align:center;color:var(--text-tertiary);padding:24px;">No purchase history records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('purchase-receipts')): ?>
        <div id="section-purchase-receipts" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Goods Received</h1>

                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" onclick="loadPurchaseReceipts()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
                    <button class="btn btn-primary" onclick="showSection('purchase-orders')"><i class="bi bi-file-earmark-text me-1"></i> View Purchase Orders</button>
                </div>
            </div>
            <?php $incomingGrActiveTab = 'purchase-receipts'; require __DIR__ . '/includes/ui_incoming_gr_tabs.php'; ?>

            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-5">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="receiptSearch" class="form-control" placeholder="Search Receipt #, PO # or supplier..." onkeyup="filterReceipts()">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select id="receiptStatusFilter" class="form-select" onchange="loadPurchaseReceipts()">
                                <option value="all">All Statuses</option>
                                <option value="Partially Received">Partially Received</option>
                                <option value="Fully Received">Fully Received</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>PO #</th>
                                    <th>Supplier</th>
                                    <th>Items Received</th>
                                    <th>Status</th>
                                    <th>Date Received</th>
                                    <th>Received By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="receiptBody">
                                <tr class="loading-row"><td colspan="8" style="text-align:center;padding:32px;color:var(--text-tertiary);"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading Goods Receipts...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isPurchaser && isSecAllowed('markup-queue')): ?>
        <div id="section-markup-queue" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Pricing Queue</h1>

                </div>
                <button class="btn btn-outline-secondary" onclick="loadPurchaserMarkupQueue()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
            </div>
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll pricing-queue-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>UNIT</th>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Purchase Price</th>
                                    <th>Markup (%)</th>
                                    <th>Selling Price (₱)</th>
                                    <th>Pricing Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="markupQueueBody">
                                <tr class="loading-row"><td colspan="10" class="text-center py-4 text-muted">Open this section to load…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('stock-in-queue')): ?>
        <div id="section-stock-in-queue" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Stock-In Queue</h1>

                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <select id="stockInStatusFilter" class="form-select" style="min-width:190px" onchange="renderStockInQueueTable()">
                        <option value="ready" selected>Ready for Stock-In</option>
                        <option value="stocked">Stocked</option>
                        <option value="all">All</option>
                    </select>
                    <select id="stockInDateFilter" class="form-select" style="min-width:170px" onchange="stockInDateFilterChanged()">
                        <option value="all">All Dates</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                    <span id="stockInDateCustom" class="d-none align-items-center gap-2">
                        <input type="date" id="stockInDateFrom" class="form-control" aria-label="From Date" style="width:150px">
                        <input type="date" id="stockInDateTo" class="form-control" aria-label="To Date" style="width:150px">
                        <button type="button" class="btn btn-outline-primary" onclick="applyStockInCustomDate()">Apply</button>
                    </span>
                    <input type="text" id="stockInQueueSearch" class="form-control" placeholder="Search..." autocomplete="off" style="min-width:160px" oninput="renderStockInQueueTable()">
                    <input type="text" id="stockInQueueBarcode" class="form-control" placeholder="Scan or enter barcode…" autocomplete="off" spellcheck="false" style="min-width:220px;">
                    <button type="button" class="btn btn-outline-primary" onclick="submitStockInQueueBarcode()"><i class="bi bi-upc-scan me-1"></i>Find</button>
                    <button type="button" class="btn btn-success" onclick="confirmSelectedStockIn()"><i class="bi bi-box-arrow-in-down me-1"></i> Confirm Stock-In</button>
                    <button class="btn btn-outline-secondary" onclick="loadStockInQueue()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <colgroup>
                                <col class="siq-col-check">
                                <col class="siq-col-po">
                                <col class="siq-col-receipt">
                                <col class="siq-col-supplier">
                                <col class="siq-col-items">
                                <col class="siq-col-qty">
                                <col class="siq-col-dmg">
                                <col class="siq-col-price">
                                <col class="siq-col-markup">
                                <col class="siq-col-sell">
                                <col class="siq-col-workflow">
                                <col class="siq-col-ready">
                                <col class="siq-col-actions">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="siq-check-cell"><input type="checkbox" id="stockInSelectAll" title="Select all ready items" onclick="toggleStockInSelectAll(this)"></th>
                                    <th>PO #</th>
                                    <th>Receipt #</th>
                                    <th>Supplier</th>
                                    <th>Items</th>
                                    <th class="siq-qty">Good Qty</th>
                                    <th class="siq-qty">Replacement</th>
                                    <th class="siq-money">Purchase Price</th>
                                    <th class="siq-money">Markup %</th>
                                    <th class="siq-money">Reference Selling Price</th>
                                    <th class="siq-workflow">Workflow</th>
                                    <th class="siq-ready">Ready Since</th>
                                    <th class="siq-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="stockInQueueBody">
                                <tr class="loading-row"><td colspan="13" class="text-center py-4 text-muted">Open this section to load…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('damage-returns')): ?>
        <div id="section-damage-returns" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Replacement / Return to Supplier</h1>

                </div>
                <button class="btn btn-outline-secondary" onclick="loadDamageReturns()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
            </div>
            <div class="card mb-3">
                <div class="card-header"><div class="card-title">Returnable damaged receiving</div></div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>PREC / PO</th>
                                    <th>Product</th>
                                    <th>Supplier</th>
                                    <th>Good</th>
                                    <th>Replacement</th>
                                    <th>Returned</th>
                                    <th>Eligible</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="damageReturnsBody">
                                <tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted">Open this section to load…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title">Return to Supplier (RTS)</div></div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>RTS #</th>
                                    <th>Date</th>
                                    <th>PO / PREC</th>
                                    <th>Supplier</th>
                                    <th>Product</th>
                                    <th>Returned</th>
                                    <th>Reason</th>
                                    <th>Replacement</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="supplierRtsBody">
                                <tr class="loading-row"><td colspan="10" class="text-center py-4 text-muted">Open this section to load…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════ USERS SECTION ═══════════ -->
        <!-- DELIVERY SCHEDULE VIEW -->
        <?php if ($isPurchaser && isSecAllowed('delivery-schedule')): ?>
        <div id="section-delivery-schedule" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>Delivery Schedule</h1>

                </div>
                <button class="btn btn-outline-secondary" onclick="showSection('purchase-receipts')"><i class="bi bi-box-arrow-in-down me-1"></i> View Goods Received</button>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Scheduled Deliveries</div>
                    <div class="search-box" style="width:min(100%,280px); max-width:100%;">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Search delivery schedule..." onkeyup="filterTable(this, 'deliveryScheduleTable')">
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="deliveryScheduleTable">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Expected Delivery Date</th>
                                    <th>Delivery / Receiving Status</th>
                                    <th>Actual Received Date</th>
                                    <th>Receipt #</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($delivery_schedule_rows)): ?>
                                    <?php foreach ($delivery_schedule_rows as $deliveryRow): ?>
                                        <?php
                                            $deliveryStatus = $deliveryRow['receipt_status'] ?: ($deliveryRow['status'] ?? 'N/A');
                                            $actualReceivedDate = $deliveryRow['actual_received_date'] ?: ($deliveryRow['received_date'] ?? null);
                                        ?>
                                        <tr>
                                            <td><strong style="font-family:monospace" class="text-primary"><?= htmlspecialchars((string)($deliveryRow['po_number'] ?? 'N/A')) ?></strong></td>
                                            <td><?= htmlspecialchars((string)($deliveryRow['supplier_name'] ?? 'N/A')) ?></td>
                                            <td><?= !empty($deliveryRow['expected_delivery_date']) ? htmlspecialchars(date('M d, Y', strtotime((string)$deliveryRow['expected_delivery_date']))) : 'N/A' ?></td>
                                            <td><span class="badge badge-blue"><?= htmlspecialchars((string)$deliveryStatus) ?></span></td>
                                            <td><?= !empty($actualReceivedDate) ? htmlspecialchars(date('M d, Y', strtotime((string)$actualReceivedDate))) : 'N/A' ?></td>
                                            <td><?= htmlspecialchars((string)($deliveryRow['receipt_number'] ?? 'N/A')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:24px;">No delivery schedule records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isSystemAdmin): ?>
        <div id="section-technician-job-orders" class="content-section">
            <div class="page-header"><h1>Technician Job Orders</h1></div>
            <div class="card">
                <div class="card-header"><div class="card-title">Create Job Order</div></div>
                <div class="card-body">
                    <form method="POST" class="row g-3"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                        <div class="col-md-4">
                            <label class="form-label">Assign Technician</label>
                            <select name="job_assigned_to" class="form-select" required>
                                <option value="">Select Technician</option>
                                <?php foreach ($technician_users as $tech): ?>
                                    <option value="<?= (int)$tech['id'] ?>"><?= htmlspecialchars($tech['full_name'] ?: $tech['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Equipment / Item</label>
                            <input type="text" name="job_equipment" class="form-control" placeholder="Equipment or item involved">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Instructions / Remarks</label>
                            <input type="text" name="job_instructions" class="form-control" placeholder="Optional instructions">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Task Description</label>
                            <textarea name="job_description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" name="create_job_order" class="btn-primary">Create Job Order</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title">All Technician Job Orders</div></div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead><tr><th>Job Order</th><th>Technician</th><th>Equipment / Item</th><th>Task</th><th>Assigned Date</th><th>Status</th><th>Completed At</th></tr></thead>
                            <tbody>
                            <?php if (!empty($technician_job_orders)): ?>
                                <?php foreach ($technician_job_orders as $job): ?>
                                    <tr>
                                        <td><span class="badge badge-blue"><?= htmlspecialchars($job['job_order_no']) ?></span></td>
                                        <td><?= htmlspecialchars($job['technician_name'] ?: $job['technician_username']) ?></td>
                                        <td><?= htmlspecialchars($job['equipment_item'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($job['task_description']) ?></td>
                                        <td><?= htmlspecialchars($job['assigned_date']) ?></td>
                                        <td><span class="badge <?= $job['status'] === 'Completed' ? 'badge-green' : ($job['status'] === 'Ongoing' ? 'badge-orange' : 'badge-blue') ?>"><?= htmlspecialchars($job['status']) ?></span></td>
                                        <td><?= htmlspecialchars($job['completed_at'] ?: 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align:center;color:var(--text-tertiary);padding:24px;">No technician job orders found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="section-users" class="content-section">
            <div class="page-header"><h1>User Management</h1></div>
            <div class="card">
                <div class="card-body">
                    <ul class="nav nav-pills mb-4">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#users-list">User List</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#users-add">Add User</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="users-list">
                            <div class="mb-3"><div class="search-box"><i class="bi bi-search"></i><input type="text" placeholder="Search users..." onkeyup="filterTable(this, 'usersTable')"></div></div>
                            <div class="table-container system-table-scroll">
                                <table class="modern-table align-middle" id="usersTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Username</th>
                                            <th>First Name</th>
                                            <th>Last Name</th>
                                            <th>Role</th>
                                            <th>Email</th>
                                            <th>Password</th>
                                            <th>Access & Permissions</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (!empty($users)): ?>
                                        <?php foreach($users as $u): 
                                            $plainPass = htmlspecialchars($u['plain_password'] ?? '123');
                                            $allowedList = array_filter(explode(',', $u['allowed_sections'] ?? ''));
                                            $allowedCount = count($allowedList);
                                        ?>
                                            <tr>
                                                <td><?= (int)$u['id'] ?></td>
                                                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                                <td><?= htmlspecialchars($u['first_name'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($u['last_name'] ?? '') ?></td>
                                                <td><span class="badge badge-violet"><?= htmlspecialchars($u['role']) ?></span></td>
                                                <td><?= htmlspecialchars($u['email']) ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-1" style="max-width:140px;">
                                                        <input type="password" class="form-control form-control-sm border-0 bg-light user-pass-input" value="<?= $plainPass ?>" readonly style="font-family:monospace;font-size:12px;padding:3px 6px;">
                                                        <button type="button" class="btn btn-sm text-muted p-0 border-0 bg-transparent" onclick="togglePassVisibility(this)" title="Click to view/hide password"><i class="bi bi-eye"></i></button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info text-dark" style="font-size:11px;"><i class="bi bi-shield-check me-1"></i><?= $allowedCount ?> Modules Allowed</span>
                                                </td>
                                                <td>
                                                    <?php if(strtolower((string)$u['status']) === 'active'): ?>
                                                        <span class="badge badge-green">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-red"><?= htmlspecialchars((string)$u['status']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn-icon text-primary" type="button" title="Edit Permissions & Access" onclick="openEditUserPermissionsModal(<?= htmlspecialchars(json_encode($u)) ?>)"><i class="bi bi-shield-lock-fill"></i></button>
                                                    <button class="btn-icon text-warning" type="button" title="Change Password" onclick="openUserPasswordModal(<?= (int)$u['id'] ?>,'<?= addslashes($u['username']) ?>','<?= addslashes($plainPass) ?>')"><i class="bi bi-key-fill"></i></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="10" class="text-center text-muted py-4">No users found.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3" style="font-size:13px;color:var(--text-secondary);">Total users: <strong><?= count($users) ?></strong></div>
                        </div>
                        <div class="tab-pane fade" id="users-add">
                            <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label class="form-label">First Name <span style="color:var(--accent-rose);">*</span></label><input type="text" name="new_first_name" class="form-control" placeholder="First name" required></div></div>
                                    <div class="col-md-6"><div class="form-group"><label class="form-label">Last Name <span style="color:var(--accent-rose);">*</span></label><input type="text" name="new_last_name" class="form-control" placeholder="Last name" required></div></div>
                                    <div class="col-md-6"><div class="form-group"><label class="form-label">Username</label><input type="text" name="new_username" class="form-control" placeholder="Leave blank to auto-generate"></div></div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label class="form-label">Email <span style="color:var(--accent-rose);">*</span></label><input type="email" name="new_email" class="form-control" placeholder="Enter email" required></div></div>
                                    <div class="col-md-6"><div class="form-group"><label class="form-label">Role <span style="color:var(--accent-rose);">*</span></label><select name="new_role" class="form-select" required><option value="">Select role</option><option value="Purchaser">Purchaser</option><option value="Technician">Technician</option></select></div></div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label class="form-label">Password <span style="color:var(--accent-rose);">*</span></label><div class="input-group"><input type="password" name="new_password" class="form-control" required autocomplete="new-password"><button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div></div>
                                    <div class="col-md-6"><div class="form-group"><label class="form-label">Confirm Password <span style="color:var(--accent-rose);">*</span></label><div class="input-group"><input type="password" name="confirm_new_password" class="form-control" required autocomplete="new-password"><button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div></div>
                                </div>
                                <button type="submit" name="add_user" class="btn-primary"><i class="bi bi-person-plus me-2"></i>Add User</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════ ACCOUNT SETTINGS ═══════════ -->
        <div id="section-account" class="content-section">
            <div class="page-header"><h1>Account Settings</h1></div>
            <div class="card">
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="report-summary-item" style="height:100%;">
                                <div class="report-summary-label">First Name</div>
                                <div class="report-summary-value" style="font-size:20px;"><?= htmlspecialchars((string)($currentUserAccount['first_name'] ?? $first_name)) ?></div>
                                <div class="report-summary-label mt-3">Last Name</div>
                                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars((string)($currentUserAccount['last_name'] ?? ($_SESSION['last_name'] ?? ''))) ?></div>
                                <div class="report-summary-label mt-3">Username</div>
                                <div class="report-summary-value" style="font-size:20px;"><?= htmlspecialchars((string)($currentUserAccount['username'] ?? $username)) ?></div>
                                <div class="report-summary-label mt-3">Role</div>
                                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars((string)($currentUserAccount['role'] ?? $role)) ?></div>
                                <div class="report-summary-label mt-3">Email</div>
                                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars((string)($currentUserAccount['email'] ?? 'Not set')) ?></div>
                                <div class="report-summary-label mt-3">Password Status</div>
                                <?php
                                $pwChangedRaw = trim((string)($currentUserAccount['password_changed_at'] ?? ''));
                                $pwChangedKnown = $pwChangedRaw !== '' && $pwChangedRaw !== '0000-00-00 00:00:00';
                                ?>
                                <?php if (!empty($currentUserAccount['must_change_password'])): ?>
                                    <span class="badge badge-orange">Default password in use</span>
                                <?php elseif ($pwChangedKnown): ?>
                                    <span class="badge badge-green">Password updated</span>
                                <?php else: ?>
                                    <span class="badge badge-orange">Not yet changed</span>
                                <?php endif; ?>
                                <div class="report-summary-label mt-3">Last Password Change</div>
                                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($pwChangedKnown ? $pwChangedRaw : 'Not yet changed') ?></div>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                                <div class="form-group"><label class="form-label">Current Password</label><div class="input-group"><input type="password" name="current_password" class="form-control" required autocomplete="current-password"><button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
                                <div class="form-group"><label class="form-label">New Password</label><div class="input-group"><input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password"><button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
                                <div class="form-group"><label class="form-label">Confirm New Password</label><div class="input-group"><input type="password" name="confirm_new_password" class="form-control" required minlength="6" autocomplete="new-password"><button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
                                <button type="submit" name="change_own_password" class="btn-primary"><i class="bi bi-shield-lock"></i> Change My Password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isSecAllowed('sales-orders')): ?>
        <!-- ═══════════ SALES ORDERS SECTION ═══════════ -->
        <div id="section-sales-orders" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h1>Orders</h1>

                </div>
            </div>
            <?php if (!empty($isWarehouseHead) || !empty($isSystemAdmin)): ?>
            <?php salesOrderRenderPosForm(); ?>
            <?php endif; ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Sales Order List</div>
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
                            <?php salesOrderRenderListRows($sales_orders, ['show_creator' => true, 'can_manage' => (!empty($isWarehouseHead) || !empty($isSystemAdmin))]); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php salesOrderRenderCreditSection($sales_credit_agreements ?? []); ?>
        <?php endif; ?>

        <?php if (isSecAllowed('billing-invoices')): ?>
        <!-- ═══════════ BILLING & INVOICES SECTION ═══════════ -->
        <div id="section-billing-invoices" class="content-section">
            <div class="page-header">
                <h1>Account Receivable</h1>

            </div>
            <?php billingRenderPendingChequesCard($pendingCheques ?? [], billingCanUpdateChequeStatus()); ?>
            <div class="card">
                <div class="card-header flex-wrap gap-2">
                    <div class="card-title">Generated Invoices</div>
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
                                <td colspan="10" class="text-center">No generated invoices found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('unpaid-invoices')): ?>
        <!-- ═══════════ ACCOUNTS RECEIVABLE / PENDING ═══════════ -->
        <div id="section-unpaid-invoices" class="content-section">
            <div class="page-header">
                <h1>Accounts Receivable / Pending</h1>

            </div>
            <?php billingRenderPendingChequesCard($pendingCheques ?? [], billingCanUpdateChequeStatus()); ?>
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
                                    <form method="POST" class="d-inline"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

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
        <?php endif; ?>

        <!-- ═══════════ NOTIFICATIONS SECTION ═══════════ -->
        <div id="section-notifications" class="content-section">
            <div class="page-header">
                <h1>Alerts & System Notifications</h1>

            </div>

            <!-- Expiry timing settings -->
            <div class="notif-settings-box">
                <h5 class="mb-3" style="font-size: 14px; font-weight:600;"><i class="bi bi-gear-fill me-2"></i>Near Expiry Notification Timing Threshold</h5>
                <div class="d-flex align-items-center gap-3">
                    <span style="font-size: 13px; color:#4B5563;">Send alerts for batches expiring within:</span>
                    <select id="expiryThresholdInput" class="form-select form-select-sm" style="width:min(100%,140px); max-width:100%;">
                        <option value="30">30 Days</option>
                        <option value="60">60 Days</option>
                        <option value="90">90 Days</option>
                    </select>
                    <button class="btn btn-sm btn-primary" onclick="saveExpiryAlertSettings()">Save Configuration</button>
                </div>
            </div>

            <div class="card">
                <div class="card-header" style="flex-wrap: wrap; gap: 16px;">
                    <div class="card-title">System Audit Alerts Log</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <select id="histTypeFilter" class="form-select form-select-sm" style="width:min(100%,150px); max-width:100%;" onchange="loadNotificationsHistory()">
                            <option value="">All Types</option>
                            <option value="low_stock">Low Stock</option>
                            <option value="near_expiry">Near Expiry</option>
                            <option value="order_status">Order Status</option>
                            <option value="pending_approval">Pending Approval</option>
                        </select>
                        <input type="date" id="histDateFrom" class="form-control form-control-sm" style="width:min(100%,130px); max-width:100%;" placeholder="From date" onchange="loadNotificationsHistory()">
                        <input type="date" id="histDateTo" class="form-control form-control-sm" style="width:min(100%,130px); max-width:100%;" placeholder="To date" onchange="loadNotificationsHistory()">
                        <input type="text" id="histSearchInput" class="form-control form-control-sm" style="width:min(100%,180px); max-width:100%;" placeholder="Search message..." onkeyup="loadNotificationsHistory()">
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
                                <td colspan="6" class="text-center py-4 text-muted">Click Alerts History on the sidebar to load the history log.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════ REPORTS SECTION ═══════════ -->
        <div id="section-reports" class="content-section">
            <div class="page-header"><h1>Reports & Analytics</h1></div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Inventory Report</div>
                    <div class="d-flex gap-2">
                        <?php if ($canPrintRecords): ?>
                            <button class="print-btn" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                        <?php endif; ?>
                        <?php if (!$isSystemAdmin): ?>
                            <form method="POST" style="display:inline;"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                                <button type="submit" name="submit_inventory_report_to_admin" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Submit to Admin</button>
                            </form>
                        <?php endif; ?>
                        <button class="export-btn" onclick="exportToCSV()"><i class="bi bi-download"></i> Export CSV</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="report-summary">
                        <div class="report-summary-item"><div class="report-summary-label">Total Products</div><div class="report-summary-value"><?= count($inventory_items) ?></div></div>
                        <div class="report-summary-item"><div class="report-summary-label">Total Stock Units</div><div class="report-summary-value"><?= number_format($total_items) ?></div></div>
                        <div class="report-summary-item"><div class="report-summary-label">Total Value</div><div class="report-summary-value">₱<?= number_format($total_value,2) ?></div></div>
                        <div class="report-summary-item"><div class="report-summary-label">Low Stock Items</div><div class="report-summary-value" style="color:var(--accent-rose);"><?= $low_stock ?></div></div>
                    </div>
                    <div class="mb-3 d-flex gap-2 align-items-center">
                        <div class="search-box" style="width:min(100%,280px); max-width:100%;"><i class="bi bi-search"></i><input type="text" placeholder="Search report..." onkeyup="filterTable(this, 'inventoryReportTable')"></div>
                        <span style="font-size:12px;color:var(--text-tertiary);">Generated: <?= date('F j, Y') ?></span>
                    </div>
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="inventoryReportTable">
                            <thead><tr><th>SKU</th><th>Product Name</th><th>Category</th><th>Quantity</th><th>Selling Price</th><th>Total Value</th><th>Location</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if(!empty($inventory_items)): ?>
                            <?php foreach($inventory_items as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['sku']) ?></td>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= htmlspecialchars($item['category']) ?></td>
                                    <td><?= $item['qty'] ?></td>
                                    <td>₱<?= number_format($item['price'], 2) ?></td>
                                    <td>₱<?= number_format($item['qty'] * $item['price'], 2) ?></td>
                                    <td><?= htmlspecialchars($item['location']) ?></td>
                                    <td><span class="badge <?= $item['stock_class'] ?>"><?= htmlspecialchars($item['stock_label']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center">No inventory records found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($canViewSalesReports)) { require __DIR__ . '/sales_report_ui.php'; } ?>

        <!-- ═══════════ AUDIT LOG ═══════════ -->
        <!-- WAREHOUSE HEAD AUDIT LOG -->
        <?php if ($isWarehouseHead && isSecAllowed('warehouse-audit-log')): ?>
        <div id="section-warehouse-audit-log" class="content-section">
            <div class="page-header">
                <h1>Audit Log</h1>

            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Warehouse Activity Logs</div>
                    <div class="search-box" style="width:min(100%,280px); max-width:100%;">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Search audit logs..." onkeyup="filterTable(this, 'warehouseAuditLogsTable')">
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="warehouseAuditLogsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date/Time</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($warehouse_audit_logs)): ?>
                                    <?php foreach ($warehouse_audit_logs as $log): ?>
                                        <tr>
                                            <td><?= (int)$log['id'] ?></td>
                                            <td><?= htmlspecialchars((string)$log['created_at']) ?></td>
                                            <td><?= htmlspecialchars((string)$log['username']) ?></td>
                                            <td><span class="badge badge-violet"><?= htmlspecialchars((string)$log['user_role']) ?></span></td>
                                            <td><span class="badge badge-orange"><?= htmlspecialchars((string)$log['action_key']) ?></span></td>
                                            <td style="max-width:360px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string)($log['details'] ?: 'N/A')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:24px;">No warehouse audit records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isSystemAdmin): ?>
        <div id="section-audit-log" class="content-section">
            <div class="page-header"><h1>Audit Log</h1></div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Activity Logs</div>
                    <form method="POST" onsubmit="return appConfirmSubmit(event, 'Delete all audit logs? This cannot be undone.');"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                        <button type="submit" name="delete_all_audit_logs" class="btn-danger" style="padding:8px 14px;font-size:12px;"><i class="bi bi-trash3 me-1"></i>Delete All</button>
                    </form>
                </div>
                <div class="card-body">
                    <div class="mb-3"><div class="search-box"><i class="bi bi-search"></i><input type="text" placeholder="Search audit logs..." onkeyup="filterTable(this, 'auditLogsTable')"></div></div>
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="auditLogsTable">
                            <thead><tr><th>ID</th><th>Date/Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php if (!empty($auditLogs)): ?>
                                <?php foreach($auditLogs as $log): ?>
                                    <tr>
                                        <td><?= (int)$log['id'] ?></td>
                                        <td><?= htmlspecialchars((string)$log['created_at']) ?></td>
                                        <td><?= htmlspecialchars((string)$log['username']) ?></td>
                                        <td><span class="badge badge-violet"><?= htmlspecialchars((string)$log['user_role']) ?></span></td>
                                        <td><span class="badge badge-orange"><?= htmlspecialchars((string)$log['action_key']) ?></span></td>
                                        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string)$log['details']) ?></td>
                                        <td>
                                            <button type="button" class="btn-icon" title="Edit" onclick="editAuditLog(<?= (int)$log['id'] ?>, '<?= addslashes((string)$log['details']) ?>')"><i class="bi bi-pencil"></i></button>
                                            <form method="POST" style="display:inline;" onsubmit="return appConfirmSubmit(event, 'Delete this audit log entry?');"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                                                <input type="hidden" name="audit_log_id" value="<?= (int)$log['id'] ?>">
                                                <button type="submit" name="delete_audit_log" class="btn-icon delete" title="Delete"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align:center;color:var(--text-tertiary);padding:24px;">No audit logs yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <form method="POST" id="auditLogEditForm" style="display:none;"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                        <input type="hidden" name="audit_log_id" id="audit_log_edit_id">
                        <input type="hidden" name="audit_log_details" id="audit_log_edit_details">
                        <input type="hidden" name="update_audit_log" value="1">
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════ BARCODE SCANNER ═══════════ -->
        <!-- PURCHASER AUDIT LOG -->
        <?php if ($isPurchaser && isSecAllowed('purchaser-audit-log')): ?>
        <div id="section-purchaser-audit-log" class="content-section">
            <div class="page-header">
                <h1>Audit Log</h1>

            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">My Procurement Activity</div>
                    <div class="search-box" style="width:min(100%,280px); max-width:100%;">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Search audit log..." onkeyup="filterTable(this, 'purchaserAuditLogsTable')">
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="purchaserAuditLogsTable">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Record ID</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($purchaser_audit_logs)): ?>
                                    <?php foreach ($purchaser_audit_logs as $auditRow): ?>
                                        <?php
                                            $auditDetails = $auditRow['new_values'] ?? '';
                                            if ($auditDetails !== '') {
                                                $decodedDetails = json_decode((string)$auditDetails, true);
                                                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails)) {
                                                    $detailParts = [];
                                                    foreach ($decodedDetails as $detailKey => $detailValue) {
                                                        $detailParts[] = $detailKey . ': ' . (is_scalar($detailValue) ? (string)$detailValue : json_encode($detailValue));
                                                    }
                                                    $auditDetails = implode(', ', $detailParts);
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($auditRow['created_at'] ?? 'N/A')) ?></td>
                                            <td><?= htmlspecialchars((string)($auditRow['user_name'] ?? 'N/A')) ?></td>
                                            <td><span class="badge badge-orange"><?= htmlspecialchars((string)($auditRow['action_type'] ?? 'N/A')) ?></span></td>
                                            <td><?= htmlspecialchars((string)($auditRow['record_id'] ?? 'N/A')) ?></td>
                                            <td style="max-width:360px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string)($auditDetails ?: 'N/A')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align:center;color:var(--text-tertiary);padding:24px;">No Purchaser audit records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isSecAllowed('barcode-scanner')): ?>
        <?php require __DIR__ . '/includes/ui_barcode_scanner.php'; ?>
        <?php endif; ?>

        <?php productBarcodesRenderStockSection(); ?>

        <?php if (isSecAllowed('serials-view')): ?>
        <!-- ═══════════ SERIAL NUMBERS TRACKER ═══════════ -->
        <div id="section-serials-view" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h3 fw-bold mb-1"><i class="bi bi-qr-code text-primary me-2"></i>Serial Number Tracker</h1>

                </div>
                <?php if ($canManageSerialNumbers): ?>
                <button class="btn btn-primary btn-sm" onclick="openAddSerialModal()"><i class="bi bi-plus-lg me-1"></i>Add Serial</button>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <div class="card-title mb-0">Serial Numbers</div>
                    <div class="d-flex gap-2">
                        <select id="serialProductFilter" class="form-select form-select-sm" style="width:min(100%,200px); max-width:100%;" onchange="loadSerials()">
                            <option value="">All Products</option>
                        </select>
                        <select id="serialStatusFilter" class="form-select form-select-sm" style="width:min(100%,140px); max-width:100%;" onchange="loadSerials()">
                            <option value="">All Status</option>
                            <option value="Active">Active</option>
                            <option value="In Use">In Use</option>
                            <option value="Returned">Returned</option>
                            <option value="Retired">Retired</option>
                            <option value="Lost">Lost</option>
                            <option value="Damaged">Damaged</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table align-middle">
                            <thead>
                                <tr><th>Serial #</th><th>Product</th><th>SKU</th><th>Status</th><th>Purchase Date</th><th>Warranty Expiry</th><th>Location</th><th>Notes</th><th>Actions</th></tr>
                            </thead>
                            <tbody id="serialsBody">
                                <tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading serials...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- ═══════════ PROCUREMENT OVERVIEW ═══════════ -->
        <div id="section-procurement" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h3 fw-bold mb-1"><i class="bi bi-truck text-primary me-2"></i>Procurement Overview</h1>

                </div>
                <button class="btn btn-primary btn-sm" onclick="routeToSection('purchase-requests', this)"><i class="bi bi-file-earmark-plus me-1"></i>Create from Approved PR</button>
            </div>

            <!-- Stat Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-2 col-6">
                    <div class="card p-3 text-center border-0 shadow-sm" style="background:#f0f9ff;">
                        <div class="text-primary mb-1"><i class="bi bi-building fs-3"></i></div>
                        <div class="h4 fw-bold mb-0" id="kpiSuppliersCount"><?= count($suppliers_list ?? []) ?></div>
                        <div class="text-muted small">Active Suppliers</div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card p-3 text-center border-0 shadow-sm" style="background:#eff6ff;">
                        <div class="text-info mb-1"><i class="bi bi-file-earmark-text fs-3"></i></div>
                        <div class="h4 fw-bold mb-0" id="kpiOpenPOs">0</div>
                        <div class="text-muted small">Open POs</div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card p-3 text-center border-0 shadow-sm" style="background:#fffbeb;">
                        <div class="text-warning mb-1"><i class="bi bi-hourglass-split fs-3"></i></div>
                        <div class="h4 fw-bold mb-0" id="kpiPendingApproval">0</div>
                        <div class="text-muted small">Pending Approval</div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card p-3 text-center border-0 shadow-sm" style="background:#f0fdf4;">
                        <div class="text-success mb-1"><i class="bi bi-check-circle fs-3"></i></div>
                        <div class="h4 fw-bold mb-0" id="kpiReceivedPOs">0</div>
                        <div class="text-muted small">Received POs</div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card p-3 text-center border-0 shadow-sm" style="background:#f5f3ff;cursor:pointer" onclick="showSection('incoming-deliveries')">
                        <div style="color:#7c3aed" class="mb-1"><i class="bi bi-truck fs-3"></i></div>
                        <div class="h4 fw-bold mb-0 kpi-incoming-deliveries">0</div>
                        <div class="text-muted small">Incoming Deliveries</div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card p-3 text-center border-0 shadow-sm" style="background:#fef2f2;">
                        <div class="text-danger mb-1"><i class="bi bi-exclamation-triangle fs-3"></i></div>
                        <div class="h4 fw-bold mb-0" id="kpiReorderCount"><?= $low_stock ?></div>
                        <div class="text-muted small">Need Reorder</div>
                    </div>
                </div>
            </div>

            <!-- Recent POs and Reorder Suggestions -->
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="card-title mb-0"><i class="bi bi-file-earmark-text text-primary me-2"></i>Recent Purchase Orders</div>
                            <button class="btn btn-outline-primary btn-sm" onclick="showSection('purchase-orders')">View All POs →</button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container system-table-scroll">
                                <table class="modern-table align-middle">
                                    <thead>
                                        <tr><th>PO #</th><th>Supplier</th><th>Total</th><th>Status</th><th>Action</th></tr>
                                    </thead>
                                    <tbody id="dashRecentPOs">
                                        <tr class="loading-row"><td colspan="5" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading recent POs...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="card-title mb-0"><i class="bi bi-lightbulb text-warning me-2"></i>Reorder Suggestions</div>
                            <span class="badge bg-warning text-dark" id="suggestCount"></span>
                        </div>
                        <div class="card-body p-3" id="dashSuggestions">
                            <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Checking inventory suggestions...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ COMBINED: PURCHASE REQUESTS, PR APPROVAL QUEUE & SUPPLIER INVOICES ═══════════ -->
        <?php if (isSecAllowed('procurement') || isSecAllowed('purchase-requests') || isSecAllowed('pr-approval') || isSecAllowed('purchase-orders') || isSecAllowed('purchase-receipts') || isSecAllowed('supplier-invoices') || isSecAllowed('suppliers-view')): ?>
        <div id="section-purchase-requests" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h3 fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2"></i>Purchase Requests, Approvals & Invoices</h1>

                </div>
            </div>

            <!-- Horizontal Sub-Tabs -->
            <ul class="nav nav-tabs nav-tabs-bordered mb-3" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active fw-semibold" id="pr-tab-btn-requests" onclick="switchPRInvoiceTab('requests')"><i class="bi bi-file-earmark-plus me-1"></i>Purchase Requests</button>
                </li>
                <?php if ($isSystemAdmin): ?>
                <li class="nav-item">
                    <button class="nav-link fw-semibold" id="pr-tab-btn-approval" onclick="switchPRInvoiceTab('approval')"><i class="bi bi-check2-circle me-1"></i>PR Approval Queue <span class="badge bg-warning text-dark ms-1" id="prAppBadge"></span></button>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <button class="nav-link fw-semibold" id="pr-tab-btn-invoices" onclick="switchPRInvoiceTab('invoices')"><i class="bi bi-receipt me-1"></i>Supplier Invoices</button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- TAB 1: PURCHASE REQUESTS -->
                <div class="tab-pane fade show active" id="pr-tab-pane-requests">
                    <!-- Existing Create PR form is shown on this page (not in a popup). -->
                    <div class="modal-overlay open" id="createPRModal" data-pr-pos="inline">
                      <div class="modal modal-lg">
                        <div class="modal-header">
                          <span class="modal-title"><i class="bi bi-file-earmark-plus text-primary"></i> Create Purchase Request</span>
                          <button class="modal-close" onclick="closeModal('createPRModal')"><i class="bi bi-x"></i></button>
                        </div>
                        <div class="modal-body">
                          <form id="createPRForm">
                            <div class="form-grid" style="margin-bottom:20px">
                              <div class="form-group full"><label class="form-label">Purpose</label>
                                <select class="form-control" name="purpose" id="prPurpose" required onchange="onPRPurposeChange()">
                                  <option value="">-- Select Purpose --</option>
                                  <option value="Low Stock Replenishment">Low Stock Replenishment</option>
                                  <option value="Customer Order">Customer Order</option>
                                  <option value="Others">Others</option>
                                </select>
                              </div>
                              <div class="form-group full" id="prPreferredSupplierGroup">
                                <label class="form-label" for="prSupplierSearch"><i class="bi bi-building me-1"></i>Preferred Supplier <span style="color:#dc2626">*</span></label>
                                <div class="d-flex gap-2 align-items-start flex-wrap" id="prSupplierSelectRow">
                                  <div class="pr-supplier-combo flex-grow-1" id="prSupplierCombo" style="min-width:220px">
                                    <input type="hidden" name="supplier_id" id="prSupplierSelect" value="">
                                    <input type="text" class="form-control pr-supplier-search" id="prSupplierSearch" placeholder="Search or select supplier…" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-controls="prSupplierMenu" aria-autocomplete="list">
                                    <button type="button" class="pr-supplier-combo-caret" id="prSupplierToggle" tabindex="-1" aria-label="Show suppliers"><i class="bi bi-chevron-down"></i></button>
                                    <div class="pr-supplier-menu" id="prSupplierMenu" hidden role="listbox"></div>
                                  </div>
                                  <button type="button" class="btn btn-sm btn-outline-primary" id="prAddSupplierBtn" onclick="openPRAddSupplierModal()"><i class="bi bi-plus-lg me-1"></i> Add Supplier</button>
                                </div>
                                <div id="prSupplierWarn" class="pr-supplier-warn" hidden>Please select a supplier before adding a product. It applies only to the next item you add.</div>
                              </div>
                              <div class="form-group full" id="prCustomerOrderFieldsWrap" style="display:none">
                                <label class="form-label" id="prCustomerNameLabel" for="prCustomerNameSearch">Customer / Company Name</label>
                                <div class="d-flex gap-2 align-items-start flex-wrap" id="prCustomerOrderSelectRow">
                                  <div class="pr-co-order-combo flex-grow-1" id="prCustomerNameCombo" style="min-width:220px">
                                    <input type="hidden" id="prCustomerNameSelect" value="">
                                    <input type="hidden" id="prCustomerNameValue" value="">
                                    <input type="text" class="form-control pr-co-order-search" id="prCustomerNameSearch" placeholder="Search or enter customer / company name…" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-controls="prCustomerNameMenu" aria-autocomplete="list">
                                    <button type="button" class="pr-co-order-caret" id="prCustomerNameToggle" tabindex="-1" aria-label="Show customers and companies"><i class="bi bi-chevron-down"></i></button>
                                    <div class="pr-co-order-menu" id="prCustomerNameMenu" hidden role="listbox"></div>
                                  </div>
                                  <button type="button" class="btn btn-sm btn-outline-primary" id="prCoAddCustomerBtn" onclick="openPRAddCustomerModal()"><i class="bi bi-plus-lg me-1"></i> Add Customer</button>
                                  <button type="button" class="btn btn-sm btn-outline-primary" id="prCoAddCompanyBtn" onclick="openPRAddCompanyModal()"><i class="bi bi-plus-lg me-1"></i> Add Company</button>
                                </div>
                                <label class="form-label" id="prCustomerOrderRefLabel" for="prCustomerOrderSearch" style="margin-top:12px">Customer PO / Reference No.</label>
                                <div class="pr-co-order-combo flex-grow-1" id="prCustomerOrderCombo" style="min-width:220px">
                                  <input type="hidden" id="prCustomerOrderSelect" value="">
                                  <input type="text" class="form-control pr-co-order-search" id="prCustomerOrderSearch" placeholder="Search or enter PO / reference…" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-controls="prCustomerOrderMenu" aria-autocomplete="list">
                                  <button type="button" class="pr-co-order-caret" id="prCustomerOrderToggle" tabindex="-1" aria-label="Show customer orders"><i class="bi bi-chevron-down"></i></button>
                                  <div class="pr-co-order-menu" id="prCustomerOrderMenu" hidden role="listbox"></div>
                                </div>
                                <input type="hidden" name="customer_order_reference" id="prCustomerOrderRef" maxlength="120">
                                <label class="form-label" style="margin-top:12px">Order Details <span style="font-weight:400;color:#64748b">(optional)</span></label>
                                <textarea class="form-control" name="order_details" id="prOrderDetails" rows="2" placeholder="Specifications, required date, or other customer requirements…"></textarea>
                              </div>
                              <div class="form-group full" id="prPurposeDetailsWrap" style="display:none">
                                <label class="form-label">Purpose Details</label>
                                <textarea class="form-control" name="purpose_details" id="prPurposeDetails" rows="2" placeholder="Explain why these products are being requested…"></textarea>
                              </div>
                            </div>
                            <!-- Auto-detected Low Stock products (from live inventory) -->
                            <div id="prLowStockPanel" style="display:none"></div>
                            <div id="prCustomerOrderPanel" style="display:none"></div>
                            <div id="prCatalogPanel" style="display:none"></div>
                            <!-- Line Items — always visible for every purpose -->
                            <div id="prRequestedItemsSection" style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
                              <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
                                <span style="font-size:13px;font-weight:600;color:#0f172a"><i class="bi bi-list-check"></i> Requested Items</span>
                                <div class="d-flex gap-2">
                                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="openPRAddCatalogModal()"><i class="bi bi-plus-lg me-1"></i> Add Catalog Item</button>
                                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPRItemRow()"><i class="bi bi-plus-lg me-1"></i> Add Item</button>
                                </div>
                              </div>
                              <div style="padding:14px 16px">
                                <div class="pr-items-scroll system-table-scroll">
                                <table class="modern-table" style="margin-bottom:8px">
                                  <thead><tr><th>Product Catalog</th><th>Item Description</th><th style="width:170px">Supplier</th><th style="width:110px">Qty</th><th style="width:90px">Unit</th><th style="width:130px">Unit Price (₱)</th><th style="width:110px">Total (₱)</th><th style="width:36px"></th></tr></thead>
                                  <tbody id="prItemsBody"></tbody>
                                </table>
                                </div>
                                <div style="text-align:right;font-size:14px;font-weight:700;color:#0f172a;padding:8px 0">
                                  Grand Total: ₱<span id="prGrandTotal">0.00</span>
                                </div>
                              </div>
                            </div>
                          </form>
                        </div>
                        <div class="modal-footer">
                          <button class="btn btn-outline-secondary" onclick="closeModal('createPRModal')">Cancel</button>
                          <button class="btn btn-outline-primary" onclick="submitCreatePR(false)"><i class="bi bi-save me-1"></i> Save as Draft</button>
                          <button class="btn btn-primary" onclick="submitCreatePR(true)"><i class="bi bi-send me-1"></i> Submit for Admin Approval</button>
                        </div>
                      </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <div class="card-title mb-0" id="prListTitle">All Purchase Requests</div>
                            <div class="d-flex gap-2 flex-wrap">
                                <div class="search-box" style="width:min(100%,240px); max-width:100%;">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="prSearch" placeholder="Search PR..." oninput="filterPRs()">
                                </div>
                                <select id="prStatusFilter" class="form-select form-select-sm" style="width:min(100%,180px); max-width:100%;" onchange="loadPurchaseRequests()">
                                    <option value="all">All Status</option>
                                    <option value="Draft">Draft</option>
                                    <option value="Pending Approval">Pending Approval</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Rejected">Rejected</option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container system-table-scroll">
                                <table class="modern-table align-middle">
                                    <thead id="prListHead">
                                        <tr><th>PR Number</th><th>Supplier</th><th>Purpose</th><th>Items</th><th>Total Amount</th><th>Status</th><th>Requested By</th><th>Created Date</th><th>Actions</th></tr>
                                    </thead>
                                    <tbody id="prBody">
                                        <tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading purchase requests...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: PR APPROVAL QUEUE -->
                <div class="tab-pane fade" id="pr-tab-pane-approval">
                    <div class="card">
                        <div class="card-header py-3">
                            <div class="card-title mb-0"><i class="bi bi-clock-history me-1"></i>Pending Purchase Requests Needing Action</div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container system-table-scroll">
                                <table class="modern-table align-middle">
                                    <thead>
                                        <tr><th>PR Number</th><th>Supplier</th><th>Purpose</th><th>Items</th><th>Total</th><th>Requested By</th><th>Date Submitted</th><th>Actions</th></tr>
                                    </thead>
                                    <tbody id="approvalBody">
                                        <tr class="loading-row"><td colspan="8" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading approval queue...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: SUPPLIER INVOICES -->
                <div class="tab-pane fade" id="pr-tab-pane-invoices">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <div class="card-title mb-0">All Supplier Invoices</div>
                            <div class="d-flex gap-2 flex-wrap">
                                <div class="search-box" style="width:min(100%,240px); max-width:100%;">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="invoiceSearch" placeholder="Search Invoices..." oninput="filterInvoices()">
                                </div>
                                <select id="supplierInvoiceStatusFilter" class="form-select form-select-sm" style="width:min(100%,160px); max-width:100%;" onchange="loadSupplierInvoices()">
                                    <option value="all">All Payment Status</option>
                                    <option value="Unpaid">Unpaid</option>
                                    <option value="Partially Paid">Partially Paid</option>
                                    <option value="Paid">Paid</option>
                                </select>
                                <?php if ($isSystemAdmin || $isPurchaser): ?><button class="btn btn-primary btn-sm ms-2" onclick="openCreateInvoiceModal()"><i class="bi bi-plus-lg me-1"></i>Record Invoice</button><?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container system-table-scroll">
                                <table class="modern-table align-middle">
                                    <thead>
                                        <tr><th>Invoice #</th><th>PO Number</th><th>Receipt #</th><th>Supplier</th><th>Total Amount</th><th>Payment Status</th><th>Invoice Date</th><th>Due Date</th><th>Actions</th></tr>
                                    </thead>
                                    <tbody id="invoiceBody">
                                        <tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading supplier invoices...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php require __DIR__ . '/includes/ui_customers_section.php'; ?>

        <!-- ═══════════ SUPPLIER DIRECTORY ═══════════ -->
        <?php if (isSecAllowed('suppliers-view')): ?>
        <div id="section-suppliers-view" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h3 fw-bold mb-1"><i class="bi bi-building text-primary me-2"></i>Supplier Directory</h1>

                </div>
                <button class="btn btn-primary btn-sm" onclick="openModal('addSupplierModal')"><i class="bi bi-plus-lg me-1"></i>Add Supplier</button>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <div class="card-title mb-0">All Suppliers</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box" style="width:min(100%,240px); max-width:100%;">
                            <i class="bi bi-search"></i>
                            <input type="text" id="supplierSearch" placeholder="Search suppliers..." oninput="filterSuppliers()">
                        </div>
                        <select id="supplierStatusFilter" class="form-select form-select-sm" style="width:min(100%,140px); max-width:100%;" onchange="filterSuppliers()">
                            <option value="all">All Status</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Blacklisted">Blacklisted</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table align-middle" id="suppliersTable">
                            <thead>
                                <tr><th>Code</th><th>Company Name</th><th>Contact</th><th>Phone</th><th>Payment Terms</th><th>Lead Time</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody id="suppliersBody">
                                <tr class="loading-row"><td colspan="8" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading suppliers...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div><!-- end section-suppliers-view -->
        <?php endif; ?>
</div><!-- end content-area -->
</div><!-- end main-content -->
</div><!-- end dashboard-layout -->
<div class="modal fade" id="productImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="productImageModalTitle">Product Image</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center" id="productImageModalBody">
                <div class="product-image-empty">No image available.</div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                    <input type="hidden" name="edit_sku" id="edit_sku">
                    <div class="form-group"><label class="form-label">SKU</label><input type="text" class="form-control" id="edit_sku_display" disabled></div>
                    <div class="row">
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Brand</label><input type="text" name="edit_brand" id="edit_brand" class="form-control" <?= $isWarehouseHead ? 'readonly' : '' ?>></div></div>
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Size</label><input type="text" name="edit_size" id="edit_size" class="form-control" <?= $isWarehouseHead ? 'readonly' : '' ?>></div></div>
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Packaging / Specification</label><input type="text" name="edit_packaging" id="edit_packaging" class="form-control" placeholder="e.g. 100 pcs/box, 21G" <?= $isWarehouseHead ? 'readonly' : '' ?>></div></div>
                    </div>
                    <div class="form-group"><label class="form-label">Product Name</label><input type="text" name="edit_name" id="edit_name" class="form-control" required <?= $isWarehouseHead ? 'readonly' : '' ?>></div>
                    <div class="form-group"><label class="form-label">Item Description</label><textarea name="edit_description" id="edit_description" class="form-control" rows="2" <?= $isWarehouseHead ? 'readonly' : '' ?>></textarea></div>
                    <div class="form-group"><label class="form-label">Product Image</label><input type="file" name="edit_product_image" id="edit_product_image" class="form-control product-image-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-preview="editProductImagePreview" data-message="editProductImageMessage" <?= $isWarehouseHead ? 'disabled' : '' ?>><div id="editProductImageMessage" class="product-image-message">Current image is kept unless a replacement is selected.</div><div id="editProductImagePreview" class="product-upload-preview"><span>No image available.</span></div></div>
                    <div class="form-group"><label class="form-label">Supplier</label><select name="edit_supplier_id" id="edit_supplier_id" class="form-select" <?= $isWarehouseHead ? 'disabled' : '' ?>><option value="0">Select Supplier</option><?php foreach($suppliers_list as $s): ?><option value="<?= (int)$s['id'] ?>"><?= (int)$s['id'] ?> - <?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="row">
                        <div class="col-md-6"><div class="form-group"><label class="form-label">Category</label><select id="edit_parent_category_id" class="form-select" <?= $isWarehouseHead ? 'disabled' : '' ?> onchange="if(typeof onCatalogParentChange==='function')onCatalogParentChange('edit')"><option value="">Select Category</option><?php foreach($catalog_parents as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?></select></div></div>
                        <div class="col-md-6"><div class="form-group"><label class="form-label">Subcategory</label><select name="edit_product_subcategory_id" id="edit_product_subcategory_id" class="form-select" <?= $isWarehouseHead ? 'disabled' : '' ?> onchange="if(typeof applyCategoryExpiryDefault==='function')applyCategoryExpiryDefault(this)"><option value="">Select Subcategory</option></select><input type="hidden" name="edit_category" id="edit_category"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Quantity (Inventory)</label><input type="number" min="0" id="edit_quantity" class="form-control" disabled><div class="form-text">Stock quantity is managed in Inventory, not the catalog.</div></div></div>
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Reorder Level</label><input type="number" name="edit_reorder_level" id="edit_reorder_level" class="form-control" value="50" <?= $isWarehouseHead ? 'readonly' : '' ?>></div></div>
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Unit</label><select name="edit_unit_of_measure" id="edit_unit_of_measure" class="form-select" required <?= $isWarehouseHead ? 'disabled' : '' ?>><?= productCatalogRenderUnitOptions('pcs') ?></select></div></div>
                    </div>
                    <div class="form-group"><label class="form-label">Status</label><select name="edit_status" id="edit_status" class="form-select" <?= $isWarehouseHead ? 'disabled' : '' ?>><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Discontinued">Discontinued</option></select></div>
                    <div class="form-group"><label class="form-label">Expiry Tracking</label><select name="edit_expiry_tracking" id="edit_expiry_tracking" class="form-select" onchange="if(typeof syncExpiryTrackingFields==='function')syncExpiryTrackingFields()"><option value="0">No</option><option value="1">Yes</option></select></div>
                    <div class="form-group"><label class="form-label">Expiry Date</label><input type="date" name="edit_expiry_date" id="edit_expiry_date" class="form-control"></div>
                    <div class="form-group">
                        <label class="form-label">Storage Temperature</label>
                        <select name="edit_storage_temp" id="edit_storage_temp" class="form-select" onchange="if(typeof syncStorageTempOther==='function')syncStorageTempOther('edit')">
                            <option value="">Select...</option>
                            <?php foreach (productCatalogStorageTempOptions() as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="edit_storage_temp_other" id="edit_storage_temp_other" class="form-control mt-2 d-none" placeholder="Enter storage temperature">
                    </div>
                    <div class="row">
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Selling Price</label><input type="number" step="0.01" name="edit_price" id="edit_price" class="form-control" required <?= $isWarehouseHead ? 'readonly' : '' ?>></div></div>
                        <div class="col-md-4"><div class="form-group"><label class="form-label">Cost Price</label><input type="number" step="0.01" name="edit_cost" id="edit_cost" class="form-control" required <?= $isWarehouseHead ? 'readonly' : '' ?>></div></div>
                    </div>
                    <div class="form-group"><label class="form-label">Location</label><input type="text" name="edit_location" id="edit_location" class="form-control"></div>
                    <button type="submit" name="update_product" class="btn-primary w-100">Update Product</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Add Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

            <div class="form-group"><label class="form-label">Category Name <span style="color:var(--accent-rose);">*</span></label><input type="text" name="category_name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Parent Category</label><select name="category_parent_id" class="form-select"><option value="">None (top-level category)</option><?php foreach($catalog_parents as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Description</label><textarea name="category_description" class="form-control" rows="3"></textarea></div>
            <div class="form-group"><label class="form-label">Default Expiry Tracking</label><select name="category_default_expiry_tracking" class="form-select"><option value="">Not set</option><option value="1">Yes</option><option value="0">No</option></select></div>
            <button type="submit" name="add_category" class="btn-primary w-100"><i class="bi bi-plus-circle"></i> Add Category</button>
        </form></div>
    </div></div>
</div>

<div class="modal fade" id="addLocationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Add New Location</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

            <div class="form-group"><label class="form-label">Storage Area <span style="color:var(--accent-rose);">*</span></label><input type="text" name="location_storage_area" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Shelf/Rack</label><input type="text" name="location_shelf_rack" class="form-control"></div>
            <div class="form-group"><label class="form-label">Section</label><input type="text" name="location_section" class="form-control"></div>
            <button type="submit" name="add_location" class="btn-primary w-100"><i class="bi bi-plus-circle"></i> Add Location</button>
        </form></div>
    </div></div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                <div class="modal-header">
                    <h5 class="modal-title">Add New Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Supplier Code <span style="color:var(--accent-rose);">*</span></label>
                        <input type="text" name="supplier_code" class="form-control" required placeholder="e.g. SUP-001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier Name <span style="color:var(--accent-rose);">*</span></label>
                        <input type="text" name="supplier_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control js-ph-mobile" maxlength="11" inputmode="numeric" placeholder="09171234567">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_supplier" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                <input type="hidden" name="edit_supplier_id_field" id="edit_supplier_id_field">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Supplier Code <span style="color:var(--accent-rose);">*</span></label>
                        <input type="text" name="edit_sup_code" id="edit_sup_code" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier Name <span style="color:var(--accent-rose);">*</span></label>
                        <input type="text" name="edit_sup_name" id="edit_sup_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="edit_sup_contact" id="edit_sup_contact" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="edit_sup_phone" id="edit_sup_phone" class="form-control js-ph-mobile" maxlength="11" inputmode="numeric" placeholder="09171234567">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="edit_sup_email" id="edit_sup_email" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">City</label>
                        <input type="text" name="edit_sup_city" id="edit_sup_city" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_supplier" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Supplier Modal -->
<div class="modal fade" id="deleteSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                <input type="hidden" name="delete_sup_id" id="delete_sup_id">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div style="font-size:48px;color:var(--accent-rose);margin-bottom:16px;"><i class="bi bi-exclamation-circle"></i></div>
                    <h4>Are you sure?</h4>
                    <p class="text-muted">You are about to delete <strong id="delete_sup_name"></strong>. This action cannot be undone.</p>
                </div>
                <div class="modal-footer" style="border-top:none;justify-content:center;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_supplier" class="btn btn-danger">Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Edit Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

            <input type="hidden" name="edit_category_id" id="edit_category_id">
            <div class="form-group"><label class="form-label">Category Name <span style="color:var(--accent-rose);">*</span></label><input type="text" name="edit_category_name" id="edit_category_name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Parent Category</label><select name="edit_category_parent_id" id="edit_category_parent_id" class="form-select"><option value="">None (top-level category)</option><?php foreach($catalog_parents as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Description</label><textarea name="edit_category_description" id="edit_category_description" class="form-control" rows="3"></textarea></div>
            <div class="form-group"><label class="form-label">Default Expiry Tracking</label><select name="edit_category_default_expiry_tracking" id="edit_category_default_expiry_tracking" class="form-select"><option value="">Not set</option><option value="1">Yes</option><option value="0">No</option></select></div>
            <button type="submit" name="update_category" class="btn-primary w-100"><i class="bi bi-check-circle"></i> Update Category</button>
        </form></div>
    </div></div>
</div>

<div class="modal fade" id="deleteCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
        <div class="modal-header" style="border-bottom:none;"><h5 class="modal-title">Confirm Delete</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p>Are you sure you want to delete <strong id="delete_category_name"></strong>?</p>
        <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>
<input type="hidden" name="delete_category_id" id="delete_category_id">
            <div class="d-flex gap-2"><button type="button" class="btn-secondary flex-1" data-bs-dismiss="modal">Cancel</button><button type="submit" name="delete_category" class="btn-danger flex-1">Delete</button></div>
        </form></div>
    </div></div>
</div>

<div class="modal fade" id="deleteProductModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
        <div class="modal-header" style="border-bottom:none;"><h5 class="modal-title">Confirm Delete</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p>Are you sure you want to delete <strong id="delete_product_name"></strong>?</p>
        <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>
<input type="hidden" name="delete_sku" id="delete_sku">
            <div class="d-flex gap-2"><button type="button" class="btn-secondary flex-1" data-bs-dismiss="modal">Cancel</button><button type="submit" name="delete_product" class="btn-danger flex-1">Delete</button></div>
        </form></div>
    </div></div>
</div>

<!-- Change User Password Modal -->
<div class="modal fade" id="userPasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key-fill text-warning me-2"></i>Change User Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                    <input type="hidden" name="target_user_id" id="target_user_id">
                    <input type="hidden" name="target_username" id="target_username">
                    
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Target User</label>
                        <input type="text" id="target_username_display" class="form-control bg-light" disabled>
                    </div>

                    <!-- Current / Old Password display -->
                    <div class="mb-3">
                        <label class="form-label font-weight-bold"><i class="bi bi-unlock me-1"></i>Current / Old Password</label>
                        <div class="input-group">
                            <input type="password" id="target_old_password_display" class="form-control bg-light" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" title="Click to show/hide password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="mb-3">
                        <label class="form-label font-weight-bold"><i class="bi bi-eye me-1"></i>New Password</label>
                        <div class="input-group">
                            <input type="password" name="admin_new_password" id="admin_new_password" class="form-control" required minlength="6" autocomplete="new-password" placeholder="Enter new password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" title="Click to show/hide password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="mb-3">
                        <label class="form-label font-weight-bold"><i class="bi bi-shield-check me-1"></i>Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" name="admin_confirm_password" id="admin_confirm_password" class="form-control" required minlength="6" autocomplete="new-password" placeholder="Re-enter new password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility(this)" title="Click to show/hide password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="force_password_change" id="force_password_change" checked>
                        <label class="form-check-label" for="force_password_change">Require user to change this password on next login</label>
                    </div>

                    <button type="submit" name="admin_change_user_password" class="btn btn-primary w-100 py-2"><i class="bi bi-check-circle me-1"></i> Update User Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit User & Permissions Modal -->
<div class="modal fade" id="editUserPermissionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-lock-fill text-primary me-2"></i>Edit User Access & Permissions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editUserPermsForm"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

                    <input type="hidden" name="edit_perm_user_id" id="edit_perm_user_id">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label font-weight-bold">First Name</label>
                            <input type="text" name="edit_perm_first_name" id="edit_perm_first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-bold">Last Name</label>
                            <input type="text" name="edit_perm_last_name" id="edit_perm_last_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-bold">Email Address</label>
                            <input type="email" name="edit_perm_email" id="edit_perm_email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-bold">User Role</label>
                            <select name="edit_perm_role" id="edit_perm_role" class="form-select" required>
                                <option value="Purchaser">Purchaser</option>
                                <option value="Technician">Technician</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-bold">Account Status</label>
                            <select name="edit_perm_status" id="edit_perm_status" class="form-select" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Suspended">Suspended</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-grid-fill me-1 text-primary"></i>Module Access & Visibility Controls</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="dashboard" id="sec_dashboard"><label class="form-check-label" for="sec_dashboard">Dashboard Overview</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="analytics" id="sec_analytics"><label class="form-check-label" for="sec_analytics">Real-Time Analytics</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="inventory" id="sec_inventory"><label class="form-check-label" for="sec_inventory">Product Catalog</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="barcode-scanner" id="sec_barcode"><label class="form-check-label" for="sec_barcode">Barcode Scanner</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="data-entry" id="sec_dataentry"><label class="form-check-label" for="sec_dataentry">Stock Movements</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="serials-view" id="sec_serials"><label class="form-check-label" for="sec_serials">Serial Number Tracker</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="low-stock-view" id="sec_lowstock"><label class="form-check-label" for="sec_lowstock">Low Stock Alerts</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="expiry-view" id="sec_expiry"><label class="form-check-label" for="sec_expiry">Near Expiry Alerts</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="procurement" id="sec_procurement"><label class="form-check-label" for="sec_procurement">Procurement Overview</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="suppliers-view" id="sec_suppliers"><label class="form-check-label" for="sec_suppliers">Supplier Directory</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="customers" id="sec_customers"><label class="form-check-label" for="sec_customers">Customer Management</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="purchase-orders" id="sec_pos"><label class="form-check-label" for="sec_pos">Purchase Orders</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="markup-queue" id="sec_markup_queue"><label class="form-check-label" for="sec_markup_queue">Pricing Queue</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="sales-orders" id="sec_sales"><label class="form-check-label" for="sec_sales">Sales Orders</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="billing-invoices" id="sec_billing"><label class="form-check-label" for="sec_billing">Account Receivable</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="unpaid-invoices" id="sec_unpaid"><label class="form-check-label" for="sec_unpaid">Accounts Receivable / Pending</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="reports" id="sec_reports"><label class="form-check-label" for="sec_reports">Inventory Reports</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="notifications" id="sec_notifs"><label class="form-check-label" for="sec_notifs">Alerts & History</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="users" id="sec_users"><label class="form-check-label" for="sec_users">User Management</label></div></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input perm-sec-cb" type="checkbox" name="sections[]" value="audit-log" id="sec_audit"><label class="form-check-label" for="sec_audit">Activity Audit Logs</label></div></div>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-sliders me-1 text-primary"></i>Action Capabilities</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="perm_can_manage" value="1" id="perm_can_manage"><label class="form-check-label" for="perm_can_manage">Can Add/Edit Records</label></div></div>
                        <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="perm_can_delete" value="1" id="perm_can_delete"><label class="form-check-label" for="perm_can_delete">Can Delete Records</label></div></div>
                        <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="perm_can_print" value="1" id="perm_can_print"><label class="form-check-label" for="perm_can_print">Can Print Receipts</label></div></div>
                        <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="perm_can_approve" value="1" id="perm_can_approve"><label class="form-check-label" for="perm_can_approve">Can Approve POs</label></div></div>
                        <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="perm_can_manage_pricing" value="1" id="perm_can_manage_pricing"><label class="form-check-label" for="perm_can_manage_pricing">Can Manage Pricing</label></div></div>
                    </div>

                    <button type="submit" name="update_user_permissions" class="btn btn-primary w-100 py-2"><i class="bi bi-save me-1"></i> Save User Access & Permissions</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="recordPaymentForm" target="_blank"><?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>

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

<!-- ═══════════ PURCHASE ORDER MODALS ═══════════ -->

<!-- Create PO Modal -->
<div class="modal-overlay" id="createPOModalLegacy" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-file-earmark-plus"></i> Create Purchase Order</span>
      <button class="modal-close" onclick="closeModal('createPOModalLegacy')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="createPOFormLegacy">
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Supplier <span class="text-danger">*</span></label><select class="form-select" name="supplier_id" id="poSupplierSelectLegacy" required><option value="">-- Select Supplier --</option></select></div>
          <div class="col-md-6"><label class="form-label">Expected Delivery Date</label><input class="form-control" name="expected_delivery_date" type="date" id="poExpDeliveryLegacy"></div>
          <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" id="poNotesLegacy" rows="2" placeholder="Optional notes for this order..."></textarea></div>
        </div>
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold text-dark"><i class="bi bi-list-ul me-1"></i> Order Items</span>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPOItemRow()"><i class="bi bi-plus-lg me-1"></i> Add Item</button>
          </div>
          <div class="card-body p-2">
            <div class="system-table-scroll">
            <table class="po-items-table mb-2">
              <thead><tr><th>Product</th><th>Description</th><th style="width:80px">Qty</th><th style="width:110px">Unit Price (₱)</th><th style="width:100px">Total (₱)</th><th style="width:36px"></th></tr></thead>
              <tbody id="poItemsBodyLegacy"></tbody>
            </table>
            </div>
            <div class="text-end fw-bold fs-6 pt-2 border-top">
              Grand Total: <span class="text-primary">₱<span id="poGrandTotalLegacy">0.00</span></span>
            </div>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('createPOModalLegacy')">Cancel</button>
      <button class="btn btn-outline-primary" onclick="submitCreatePO(false)"><i class="bi bi-save me-1"></i> Save as Draft</button>
      <button class="btn btn-primary" onclick="submitCreatePO(true)"><i class="bi bi-send me-1"></i> Submit for Approval</button>
    </div>
  </div>
</div>

<!-- PO Detail Modal -->
<div class="modal-overlay" id="poDetailModal">
  <div class="modal modal-xl">
    <div class="modal-header">
      <span class="modal-title" id="poDetailTitle"><i class="bi bi-file-earmark-text"></i> Purchase Order</span>
      <button class="modal-close" onclick="closeModal('poDetailModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="poDetailBody"><div class="text-center py-4"><div class="spinner-border text-primary me-2"></div> Loading...</div></div>
    <div class="modal-footer" id="poDetailFooter">
      <button class="btn btn-outline-secondary" onclick="closeModal('poDetailModal')">Close</button>
    </div>
  </div>
</div>

<!-- Rejection Modal -->
<div class="modal-overlay" id="rejectPOModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-x-circle text-danger"></i> Reject Purchase Order</span>
      <button class="modal-close" onclick="closeModal('rejectPOModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="rejectPOId">
      <div class="mb-3"><label class="form-label">Reason for Rejection <span class="text-danger">*</span></label><textarea class="form-control" id="rejectReason" rows="4" placeholder="Enter rejection reason..."></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('rejectPOModal')">Cancel</button>
      <button class="btn btn-danger" onclick="confirmRejectPO()"><i class="bi bi-x-circle me-1"></i> Reject PO</button>
    </div>
  </div>
</div>

<!-- Receive PO Modal -->
<div class="modal-overlay" id="receivePOModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-check-circle text-success"></i> Mark PO as Received</span>
      <button class="modal-close" onclick="closeModal('receivePOModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="receivePOId">
      <p class="text-muted fs-6 mb-3">This will mark the PO as received and automatically create Stock In movements for all line items.</p>
      <div class="mb-3"><label class="form-label">Received Date</label><input class="form-control" type="date" id="receivedDate" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('receivePOModal')">Cancel</button>
      <button class="btn btn-success" onclick="confirmReceivePO()"><i class="bi bi-check-lg me-1"></i> Confirm Receipt</button>
    </div>
  </div>
</div>

<!-- Modal Overlay Styling for Stock Return and Inventory Modals -->
<style>
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:5000;align-items:center;justify-content:center;padding:12px;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal-overlay .modal{background:#fff;border-radius:16px;width:100%;max-width:620px;max-height:min(92vh,900px);overflow:hidden;display:flex;flex-direction:column;animation:slideUp .25s ease;margin:auto}
.modal-overlay .modal.modal-lg{max-width:min(1100px,96vw)}
.modal-overlay .modal.modal-xl{max-width:min(1280px,98vw)}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-overlay .modal-header{padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-overlay .modal-title{font-size:16px;font-weight:700;color:#0f172a}
.modal-overlay .modal-close{width:32px;height:32px;border:none;background:#f1f5f9;border-radius:8px;cursor:pointer;color:#64748b;font-size:16px;display:flex;align-items:center;justify-content:center;transition:.15s;flex-shrink:0}
.modal-overlay .modal-close:hover{background:#fee2e2;color:#ef4444}
.modal-overlay .modal-body{padding:20px;overflow-x:hidden;overflow-y:auto;flex:1 1 auto;min-height:0;min-width:0;-webkit-overflow-scrolling:touch}
.modal-overlay .modal-footer{padding:14px 20px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px;flex-shrink:0}
.modal-overlay .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.modal-overlay .form-group{display:flex;flex-direction:column;gap:5px}
.modal-overlay .form-group.full{grid-column:1/-1}
.modal-overlay .form-label{font-size:12px;font-weight:600;color:#374151}
.modal-overlay .form-control{padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13.5px;font-family:inherit;background:#fff;transition:.15s;width:100%}
.modal-overlay .pr-items-scroll,.modal-overlay .receipt-items-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.modal-overlay .pr-items-scroll .modern-table{min-width:920px;width:100%}
.modal-overlay .receipt-items-scroll .modern-table{min-width:980px;width:100%}
</style>
<link rel="stylesheet" href="css/popup-center.css?v=<?= @filemtime(__DIR__ . '/css/popup-center.css') ?: time() ?>">

<!-- Stock Return Modal -->
<div class="modal-overlay" id="stockReturnModal">
  <div class="modal modal-lg">
    <div class="modal-header" style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
      <span class="modal-title" style="color:#2563eb;display:flex;align-items:center;gap:8px">
        <i class="bi bi-arrow-counterclockwise" style="font-size:20px"></i> Process Stock Return
      </span>
      <button class="modal-close" onclick="closeModal('stockReturnModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="stockReturnForm">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Select Product <span>*</span></label>
            <select class="form-control" name="product_id" id="returnProductSelect" required onchange="onReturnProductChange()">
              <option value="">-- Select Product --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Return Action / Type <span>*</span></label>
            <select class="form-control" name="return_type" id="returnTypeSelect" required onchange="syncReturnReplacementVisibility()">
              <option value="Customer Return (Damaged - Replacement Exchange)">Customer Return (Damaged - Issue Replacement Item)</option>
              <option value="Customer Return (Damaged)">Customer Return (Damaged - Quarantine / Log Only)</option>
              <option value="Customer Return (Restockable)">Customer Return (Restockable - Add back to sellable stock)</option>
              <option value="Supplier Return (RMA)">Supplier Return (RMA - Return item to vendor)</option>
            </select>
          </div>
          <div class="form-group full" id="returnReplacementGroup" style="display:none">
            <label class="form-label">Replacement Product <span>*</span></label>
            <select class="form-control" name="replacement_product_id" id="returnReplacementSelect">
              <option value="">-- Select a valid sellable product --</option>
            </select>
            <small class="text-muted">Must be a different, non-expired product with available sellable stock.</small>
          </div>
          <div class="form-group">
            <label class="form-label">Product Batch / Lot Number</label>
            <select class="form-control" name="batch_id" id="returnBatchSelect">
              <option value="">-- Optional / Select Batch --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Return Quantity <span>*</span></label>
            <input class="form-control" name="quantity" type="number" min="1" required placeholder="e.g. 5">
          </div>
          <div class="form-group">
            <label class="form-label">Unit Price / Value (₱)</label>
            <input class="form-control" name="unit_price" id="returnUnitPrice" type="number" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="form-group">
            <label class="form-label">Reference Number</label>
            <input class="form-control" name="reference_number" placeholder="Auto-generated if blank (RET-2026-XXXX)">
          </div>
          <div class="form-group">
            <label class="form-label">SLF / Invoice / DR #</label>
            <input class="form-control" name="return_slf" placeholder="e.g. SLF-8842 / SI-1042">
          </div>
          <div class="form-group">
            <label class="form-label">Reason for Return <span>*</span></label>
            <select class="form-control" name="reason" id="returnReasonSelect" required>
              <option value="Return">General Return</option>
              <option value="Wrong Item">Wrong Item Delivered</option>
              <option value="Damage">Damaged / Defective Item</option>
              <option value="Expiry">Expired / Near Expiry</option>
              <option value="Overstocked">Customer Overstocked</option>
              <option value="Other">Other / Special Circumstance</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">From Location</label>
            <select class="form-control" name="from_location_id" id="returnFromLoc">
              <option value="">-- None / Customer --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">To Storage Location</label>
            <select class="form-control" name="to_location_id" id="returnToLoc">
              <option value="">-- None / Default Location --</option>
            </select>
          </div>
          <div class="form-group full">
            <label class="form-label">Remarks / Return Notes</label>
            <textarea class="form-control" name="notes" rows="2" placeholder="Provide additional details regarding the condition or reason for return..."></textarea>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('stockReturnModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitStockReturn()"><i class="bi bi-check-circle-fill me-1"></i> Complete Return</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/ui_stock_modals.php'; ?>
<?php require __DIR__ . '/includes/ui_customer_modals.php'; ?>

<!-- Product Replacement Modal -->
<div class="modal-overlay" id="productReplaceModal">
  <div class="modal">
    <div class="modal-header" style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
      <span class="modal-title" style="color:#2563eb;display:flex;align-items:center;gap:8px">
        <i class="bi bi-arrow-repeat" style="font-size:20px"></i> Product Replacement
      </span>
      <button class="modal-close" onclick="closeModal('productReplaceModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="productReplaceForm">
        <input type="hidden" name="original_product_id" id="replaceOriginalId">
        <div class="form-group mb-2">
          <label class="form-label">Original Product</label>
          <div id="replaceOriginalLabel" class="form-control" style="background:#f8fafc;min-height:38px"></div>
        </div>
        <div class="form-group mb-2">
          <label class="form-label">Reason <span>*</span></label>
          <select class="form-control" name="reason" id="replaceReason" required>
            <option value="Damaged">Damaged</option>
            <option value="Defective">Defective</option>
            <option value="Unavailable">Unavailable</option>
            <option value="Returned">Returned</option>
          </select>
        </div>
        <div class="form-group mb-2">
          <label class="form-label">Replacement Product <span>*</span></label>
          <select class="form-control" name="replacement_product_id" id="replaceProductSelect" required>
            <option value="">-- Select a valid sellable product --</option>
          </select>
        </div>
        <div class="form-group mb-2">
          <label class="form-label">Quantity <span>*</span></label>
          <input class="form-control" name="quantity" id="replaceQty" type="number" min="1" value="1" required>
        </div>
        <div class="form-group mb-2">
          <label class="form-label">Reference / Sales Order</label>
          <input class="form-control" name="reference_number" placeholder="Optional SO / ticket number">
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="2" placeholder="Traceability notes..."></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('productReplaceModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitProductReplacement()"><i class="bi bi-check-circle-fill me-1"></i> Record Replacement</button>
    </div>
  </div>
</div>

<!-- Transfer Stock Modal -->
<div class="modal-overlay" id="transferStockModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-arrow-left-right text-primary"></i> New Stock Transfer</span>
      <button class="modal-close" onclick="closeModal('transferStockModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="transferStockForm">
        <div class="form-group mb-2"><label class="form-label">Product</label>
          <select class="form-control" id="trfProduct" required></select></div>
        <div class="form-group mb-2"><label class="form-label">From Location</label>
          <select class="form-control" id="trfFrom" required></select></div>
        <div class="form-group mb-2"><label class="form-label">To Location</label>
          <select class="form-control" id="trfTo" required></select></div>
        <div class="form-group mb-2"><label class="form-label">Quantity</label>
          <input type="number" min="1" class="form-control" id="trfQty" value="1" required></div>
        <div class="form-group mb-2"><label class="form-label">Notes</label>
          <textarea class="form-control" id="trfNotes" rows="2"></textarea></div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('transferStockModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitTransfer()"><i class="bi bi-check-lg me-1"></i> Complete Transfer</button>
    </div>
  </div>
</div>

<?php if (isSecAllowed('serials-view')): ?>
<!-- Add Serial Modal -->
<div class="modal-overlay" id="addSerialModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-qr-code"></i> Add Serial Number</span>
      <button class="modal-close" onclick="closeModal('addSerialModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="addSerialForm">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Product <span>*</span></label><select class="form-control" name="product_id" id="serialProductSelect" required><option value="">-- Select Product --</option></select></div>
          <div class="form-group"><label class="form-label">Serial Number <span>*</span></label><input class="form-control" name="serial_number" required placeholder="e.g. SN-2026-0005"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select class="form-control" name="status">
              <option value="Active">Active</option>
              <option value="In Use">In Use</option>
              <option value="Returned">Returned</option>
              <option value="Retired">Retired</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Purchase Date</label><input class="form-control" name="purchase_date" type="date"></div>
          <div class="form-group"><label class="form-label">Warranty Expiry</label><input class="form-control" name="warranty_expiry" type="date"></div>
          <div class="form-group"><label class="form-label">Location</label><select class="form-control" name="location_id" id="serialLocationSelect"><option value="">-- Select --</option></select></div>
          <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="Optional…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('addSerialModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitAddSerial()"><i class="bi bi-save me-1"></i> Save Serial</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Add/Edit Supplier Modal -->
<div class="modal-overlay" id="addSupplierModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title" id="supplierModalTitle"><i class="bi bi-building"></i> Add New Supplier</span>
      <button class="modal-close" onclick="closeModal('addSupplierModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="supplierForm">
        <input type="hidden" name="id" id="supplierEditId">
        <div class="form-grid">
          <div class="form-group"><label class="form-label">Supplier Code <span>*</span></label><input class="form-control" name="supplier_code" id="supCode" required placeholder="e.g. SUP-006"></div>
          <div class="form-group"><label class="form-label">Company Name <span>*</span></label><input class="form-control" name="name" id="supName" required></div>
          <div class="form-group"><label class="form-label">Contact Person</label><input class="form-control" name="contact_person" id="supContact"></div>
          <div class="form-group"><label class="form-label">Email</label><input class="form-control" name="email" type="email" id="supEmail"></div>
          <div class="form-group"><label class="form-label">Phone</label><input class="form-control js-ph-mobile" name="phone" id="supPhone" maxlength="11" inputmode="numeric" placeholder="09171234567"></div>
          <div class="form-group"><label class="form-label">City</label><input class="form-control" name="city" id="supCity"></div>
          <div class="form-group"><label class="form-label">Country</label><input class="form-control" name="country" id="supCountry" value="Philippines"></div>
          <div class="form-group"><label class="form-label">Payment Terms</label>
            <select class="form-control" name="payment_terms" id="supPayTerms">
              <option value="Net 30">Net 30</option>
              <option value="Net 45">Net 45</option>
              <option value="Net 60">Net 60</option>
              <option value="Cash on Delivery">Cash on Delivery</option>
              <option value="Prepaid">Prepaid</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Lead Time (days)</label><input class="form-control" name="lead_time_days" type="number" min="1" id="supLeadTime" value="7"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select class="form-control" name="status" id="supStatus">
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
              <option value="Blacklisted">Blacklisted</option>
            </select>
          </div>
          <div class="form-group full"><label class="form-label">Address</label><input class="form-control" name="address" id="supAddress"></div>
          <div class="form-group full"><label class="form-label">Product Lines</label><input class="form-control" name="product_lines" id="supProductLines" placeholder="e.g. Gloves, Syringes, Face Masks"></div>
          <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-control" name="notes" id="supNotes" rows="2"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('addSupplierModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitSupplier()"><i class="bi bi-save me-1"></i> Save Supplier</button>
    </div>
  </div>
</div>

<!-- Create PO Modal -->
<div class="modal-overlay" id="createPOModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-file-earmark-plus"></i> Create Purchase Order</span>
      <button class="modal-close" onclick="closeModal('createPOModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="createPOForm">
        <div class="form-grid" style="margin-bottom:20px">
          <div class="form-group"><label class="form-label">Supplier <span>*</span></label><select class="form-control" name="supplier_id" id="poSupplierSelect" required onchange="filterPOProductsBySupplier(this.value)"><option value="">-- Select Supplier --</option></select></div>
          <div class="form-group"><label class="form-label">Expected Delivery Date</label><input class="form-control" name="expected_delivery_date" type="date" id="poExpDelivery"></div>
          <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-control" name="notes" id="poNotes" rows="2" placeholder="Optional notes for this order…"></textarea></div>
        </div>
        <!-- Line Items -->
        <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px">
          <div style="background:#f8fafc;padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:13px;font-weight:600;color:#0f172a"><i class="bi bi-list-ul"></i> Order Items</span>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPOItemRow()"><i class="bi bi-plus-lg me-1"></i> Add Item</button>
          </div>
          <div style="padding:14px 16px">
            <div class="system-table-scroll">
            <table class="modern-table" style="margin-bottom:8px">
              <thead><tr><th>Product</th><th>Description</th><th style="width:80px">Qty</th><th style="width:110px">Unit Price (₱)</th><th style="width:100px">Total (₱)</th><th style="width:36px"></th></tr></thead>
              <tbody id="poItemsBody"></tbody>
            </table>
            </div>
            <div style="text-align:right;font-size:14px;font-weight:700;color:#0f172a;padding:8px 0">
              Grand Total: ₱<span id="poGrandTotal">0.00</span>
            </div>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('createPOModal')">Cancel</button>
      <button class="btn btn-outline-primary" onclick="submitCreatePO(false)"><i class="bi bi-save me-1"></i> Save as Draft</button>
      <button class="btn btn-primary" onclick="submitCreatePO(true)"><i class="bi bi-send me-1"></i> Submit for Approval</button>
    </div>
  </div>
</div>

<!-- Log Performance Modal -->
<div class="modal-overlay" id="addPerfModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-graph-up"></i> Log Delivery Record</span>
      <button class="modal-close" onclick="closeModal('addPerfModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="addPerfForm">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Supplier <span>*</span></label><select class="form-control" name="supplier_id" id="perfSupplierSelect" required><option value="">-- Select Supplier --</option></select></div>
          <div class="form-group"><label class="form-label">Expected Delivery</label><input class="form-control" name="expected_delivery" type="date"></div>
          <div class="form-group"><label class="form-label">Actual Delivery</label><input class="form-control" name="actual_delivery" type="date"></div>
          <div class="form-group"><label class="form-label">Quality Issues (count)</label><input class="form-control" name="quality_issues" type="number" min="0" value="0"></div>
          <div class="form-group full"><label class="form-label">Quality Notes</label><textarea class="form-control" name="quality_notes" rows="2" placeholder="Describe any issues…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('addPerfModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitPerformance()">Save Record</button>
    </div>
  </div>
</div>
<!-- PR Detail Modal -->
<div class="modal-overlay" id="prDetailModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title" id="prDetailTitle"><i class="bi bi-file-earmark-text"></i> Purchase Request Details</span>
      <button class="modal-close" onclick="closeModal('prDetailModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="prDetailBody">
      <div class="text-center py-4"><div class="spinner-border spinner-border-sm me-2"></div> Loading...</div>
    </div>
    <div class="modal-footer" id="prDetailFooter">
      <button class="btn btn-outline-secondary" onclick="closeModal('prDetailModal')">Close</button>
    </div>
  </div>
</div>

<!-- Admin Reject PR Modal -->
<div class="modal-overlay" id="rejectPRModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title text-danger"><i class="bi bi-x-circle me-1"></i> Reject Purchase Request</span>
      <button class="modal-close" onclick="closeModal('rejectPRModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="rejectPRId">
      <p class="text-muted small">Purchase Request <strong id="rejectPRNumber" class="text-dark"></strong> will be rejected. The purchaser will see the rejection reason. A rejected PR cannot generate a PO.</p>
      <div class="form-group"><label class="form-label">Rejection Reason <span>*</span></label><textarea class="form-control" id="rejectAdminRemarks" rows="3" required placeholder="Enter a clear rejection reason..."></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('rejectPRModal')">Cancel</button>
      <button class="btn btn-danger" onclick="confirmRejectPR()"><i class="bi bi-x-circle me-1"></i> Confirm Rejection</button>
    </div>
  </div>
</div>

<!-- PO Detail Modal -->
<div class="modal-overlay" id="poDetailModal">
  <div class="modal modal-xl">
    <div class="modal-header">
      <span class="modal-title" id="poDetailTitle"><i class="bi bi-file-earmark-text"></i> Purchase Order Details</span>
      <button class="modal-close" onclick="closeModal('poDetailModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="poDetailBody">
      <div class="text-center py-4"><div class="spinner-border spinner-border-sm me-2"></div> Loading...</div>
    </div>
    <div class="modal-footer" id="poDetailFooter">
      <button class="btn btn-outline-secondary" onclick="closeModal('poDetailModal')">Close</button>
    </div>
  </div>
</div>

<!-- Create Goods Received Purchase Receipt Modal -->
<div class="modal-overlay" id="createReceiptModal">
  <div class="modal modal-xl">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-box-arrow-in-down text-success"></i> Receive & Inspect Delivery</span>
      <button class="modal-close" onclick="closeModal('createReceiptModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="receiptPoId">
      <input type="hidden" id="receiptRtsId" value="">
      <input type="hidden" id="receiptReceiveReplacement" value="0">
      <div class="p-3 mb-3 border rounded d-flex justify-content-between align-items-center flex-wrap gap-3" style="background:#f0fdf4">
        <div>
          <div class="text-muted small">Purchase Order</div>
          <div class="h6 fw-bold text-success mb-0" id="receiptPoNumberHeader">—</div>
        </div>
        <div>
          <div class="text-muted small">Supplier</div>
          <div class="fw-semibold text-dark" id="receiptSupplierHeader">—</div>
        </div>
        <div>
          <label class="form-label mb-0 small fw-bold">Date Received</label>
          <input type="date" id="receiptDate" class="form-control form-control-sm">
        </div>
      </div>

      <div class="alert alert-success py-2 small mb-3">
        Scan or enter a unique barcode for each physical good item. Camera and manual entry use the same process.
        While inspecting, the gap is <strong>Remaining to Inspect</strong>. Clicking Receive &amp; Inspect saves
        <strong>Replacement</strong> as Ordered − Good. Replacement is accounted for and is not remaining.
        Use Receive Remaining only when a product still has unprocessed quantity.
      </div>

      <div class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="form-label small fw-bold mb-1">Driver / Deliverer Name</label>
          <input type="text" id="receiptDriverName" class="form-control form-control-sm" placeholder="Who delivered this PO">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-bold mb-1">Supplier Delivery Receipt #</label>
          <input type="text" id="receiptDrNumber" class="form-control form-control-sm" placeholder="Supplier DR / packing list #">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-bold mb-1">Supplier Invoice #</label>
          <input type="text" id="receiptSupplierSi" class="form-control form-control-sm" placeholder="Supplier SI # from paper">
        </div>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-12">
          <label class="form-label">Receiving Remarks / Storage Notes</label>
          <input type="text" id="receiptRemarks" class="form-control form-control-sm" placeholder="e.g. Inspected on dock…">
        </div>
      </div>

      <h6 class="fw-bold mb-2">Item Delivery Inspection</h6>
      <div id="receiptBarcodeScannerPanel" class="receipt-scan-panel d-none mb-2">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="small fw-semibold text-success"><i class="bi bi-upc-scan me-1"></i> Barcode Scanner</span>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="stopReceiptBarcodeScanner()">Close</button>
        </div>
        <div id="receiptBarcodeScannerViewport" class="receipt-scan-viewport"></div>
        <div id="receiptBarcodeScannerHint" class="small text-muted mt-1">Point the camera at the barcode, or use a handheld scanner in the Barcode ID field.</div>
      </div>
      <div class="receipt-items-scroll system-table-scroll" style="border:1px solid #e2e8f0;border-radius:10px;overflow-x:auto">
        <table class="modern-table align-middle" style="margin-bottom:0;min-width:1180px">
          <thead>
            <tr>
              <th>Product</th>
              <th>Ordered</th>
              <th>Received</th>
              <th style="width:90px">Good</th>
              <th style="width:120px">To Received</th>
              <th style="width:100px">Purchase Price</th>
              <th style="width:220px">Barcode ID</th>
              <th style="width:170px">Lot Number</th>
              <th style="width:150px">Expiry</th>
            </tr>
          </thead>
          <tbody id="receiptItemsBody"></tbody>
        </table>
      </div>
      <div class="small text-muted mt-2">Received starts as the ordered quantity. Scan to inspect: <strong>Goods +1</strong>, remaining −1. FIFO batch is <code>BATCH-YYYYMMDD-001</code>. Only scanned Goods can be Stocked In.</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('createReceiptModal')">Cancel</button>
      <button class="btn btn-success" onclick="submitPurchaseReceipt()"><i class="bi bi-check-circle me-1"></i> Receive & Inspect</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="prAddSupplierModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-building-add me-1"></i> Add Supplier</span>
      <button class="modal-close" type="button" onclick="closeModal('prAddSupplierModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group mb-3">
        <label class="form-label" for="prNewSupplierCode">Supplier Code <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewSupplierCode" maxlength="50" placeholder="e.g. SUP-001" autocomplete="off">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewSupplierName">Supplier Name <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewSupplierName" maxlength="150" placeholder="Company name" autocomplete="organization">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewSupplierContact">Contact Person <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewSupplierContact" maxlength="100" placeholder="Contact person">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewSupplierPhone">Phone <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control js-ph-mobile" id="prNewSupplierPhone" maxlength="11" inputmode="numeric" placeholder="09171234567">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewSupplierEmail">Email <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="email" class="form-control" id="prNewSupplierEmail" maxlength="120" placeholder="email@example.com">
      </div>
      <div class="form-group mb-0">
        <label class="form-label" for="prNewSupplierCity">City <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewSupplierCity" maxlength="80" placeholder="City">
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('prAddSupplierModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="prAddSupplierSaveBtn" onclick="submitPRAddSupplier()"><i class="bi bi-check-lg me-1"></i> Save Supplier</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="prAddCustomerModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-person-plus me-1"></i> Add Customer</span>
      <button class="modal-close" onclick="closeModal('prAddCustomerModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCustomerFirstName">First Name <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewCustomerFirstName" maxlength="80" placeholder="First name" autocomplete="given-name">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCustomerMiddleName">Middle Name <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewCustomerMiddleName" maxlength="80" placeholder="Middle name" autocomplete="additional-name">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCustomerLastName">Last Name <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewCustomerLastName" maxlength="80" placeholder="Last name" autocomplete="family-name">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCustomerSuffix">Suffix <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewCustomerSuffix" maxlength="20" placeholder="e.g., Jr., Sr., III" autocomplete="off">
      </div>
      <input type="hidden" id="prNewCustomerName" value="">
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCustomerPoRef">Customer PO / Reference No. <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewCustomerPoRef" maxlength="120" placeholder="e.g. PO-234-2322" autocomplete="off">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCustomerPhone">Phone <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control js-ph-mobile" id="prNewCustomerPhone" maxlength="11" inputmode="numeric" placeholder="09171234567">
      </div>
      <div class="form-group mb-0">
        <label class="form-label" for="prNewCustomerEmail">Email <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="email" class="form-control" id="prNewCustomerEmail" maxlength="120" placeholder="email@example.com">
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('prAddCustomerModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="prAddCustomerSaveBtn" onclick="submitPRAddCustomer()"><i class="bi bi-check-lg me-1"></i> Save Customer</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="prAddCompanyModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-building-add me-1"></i> Add Company</span>
      <button class="modal-close" onclick="closeModal('prAddCompanyModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCompanyName">Company Name <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewCompanyName" maxlength="200" placeholder="Company name" autocomplete="organization">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCompanyAddress">Address <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewCompanyAddress" maxlength="255" placeholder="Address" autocomplete="street-address">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCompanyContact">Contact Person <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewCompanyContact" maxlength="150" placeholder="Contact person" autocomplete="name">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCompanyPhone">Phone <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control js-ph-mobile" id="prNewCompanyPhone" maxlength="11" inputmode="numeric" placeholder="09171234567">
      </div>
      <div class="form-group mb-0">
        <label class="form-label" for="prNewCompanyEmail">Email <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="email" class="form-control" id="prNewCompanyEmail" maxlength="120" placeholder="email@example.com">
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('prAddCompanyModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="prAddCompanySaveBtn" onclick="submitPRAddCompany()"><i class="bi bi-check-lg me-1"></i> Save Company</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="prAddCatalogModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-box-seam me-1"></i> Add Catalog Item</span>
      <button class="modal-close" type="button" onclick="closeModal('prAddCatalogModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCatalogName">Item Name <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewCatalogName" maxlength="200" placeholder="Item name" autocomplete="off">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCatalogSku">SKU / Item Code <span style="color:#dc2626">*</span></label>
        <input type="text" class="form-control" id="prNewCatalogSku" maxlength="50" placeholder="e.g. SKU-001" autocomplete="off">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCatalogBarcode">Barcode <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewCatalogBarcode" maxlength="100" placeholder="Barcode" autocomplete="off">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCatalogCategory">Category <span style="color:#dc2626">*</span></label>
        <select class="form-control" id="prNewCatalogCategory">
          <option value="">-- Select Category --</option>
        </select>
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCatalogBrand">Brand <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="text" class="form-control" id="prNewCatalogBrand" maxlength="100" placeholder="Brand" autocomplete="off">
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCatalogUnit">Unit <span style="color:#dc2626">*</span></label>
        <select class="form-control" id="prNewCatalogUnit">
          <option value="">-- Select Unit --</option>
          <?= productCatalogRenderUnitOptions() ?>
        </select>
      </div>
      <div class="form-group mb-3">
        <label class="form-label" for="prNewCatalogReorder">Reorder Level <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <input type="number" class="form-control" id="prNewCatalogReorder" min="0" step="1" placeholder="0" autocomplete="off">
      </div>
      <div class="form-group mb-0">
        <label class="form-label" for="prNewCatalogDescription">Description <span style="font-weight:400;color:#64748b">(optional)</span></label>
        <textarea class="form-control" id="prNewCatalogDescription" rows="2" maxlength="2000" placeholder="Item description"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('prAddCatalogModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="prAddCatalogSaveBtn" onclick="submitPRAddCatalogItem()"><i class="bi bi-check-lg me-1"></i> Save Catalog Item</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="noteToSupplierModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-chat-left-text me-1"></i> NOTE TO SUPPLIER</span>
      <button class="modal-close" onclick="closeModal('noteToSupplierModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="noteToSupplierBody"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('noteToSupplierModal')">Cancel</button>
      <button type="button" class="btn btn-success" id="noteToSupplierSaveBtn" onclick="saveNoteToSupplier()">Save Note</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="rtsReturnModal">
  <div class="modal modal-lg">
    <div class="modal-header" style="background:#fffbeb;border-bottom:1px solid #fde68a">
      <span class="modal-title" style="color:#b45309"><i class="bi bi-arrow-return-left me-1"></i> Return Item to Supplier</span>
      <button class="modal-close" onclick="closeModal('rtsReturnModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="rtsReturnBody"></div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('rtsReturnModal')">Cancel</button>
      <button class="btn btn-warning" onclick="submitRtsReturn()"><i class="bi bi-check-circle me-1"></i> Create RTS</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="rtsViewModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title" id="rtsViewTitle"><i class="bi bi-file-earmark-text me-1"></i> Return to Supplier</span>
      <button class="modal-close" onclick="closeModal('rtsViewModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="rtsViewBody"></div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('rtsViewModal')">Close</button>
      <button class="btn btn-primary" id="rtsViewPrintBtn" onclick="printRtsFromView()"><i class="bi bi-printer me-1"></i> Print Return Receipt</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="rtsReplaceModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-arrow-repeat me-1"></i> Request Replacement</span>
      <button class="modal-close" onclick="closeModal('rtsReplaceModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="rtsReplaceBody"></div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('rtsReplaceModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitRtsReplacement()"><i class="bi bi-send me-1"></i> Request Replacement</button>
    </div>
  </div>
</div>

<!-- Receipt Detail Modal -->
<div class="modal-overlay" id="receiptDetailModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title" id="receiptDetailTitle"><i class="bi bi-box-arrow-in-down"></i> Purchase Receipt Details</span>
      <button class="modal-close" onclick="closeModal('receiptDetailModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="receiptDetailBody">
      <div class="text-center py-4"><div class="spinner-border spinner-border-sm me-2"></div> Loading...</div>
    </div>
    <div class="modal-footer" id="receiptDetailFooter">
      <button class="btn btn-outline-secondary" onclick="closeModal('receiptDetailModal')">Close</button>
    </div>
  </div>
</div>

<!-- Record Supplier Invoice Modal -->
<div class="modal-overlay" id="createInvoiceModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-receipt text-primary"></i> Record Supplier Invoice</span>
      <button class="modal-close" onclick="closeModal('createInvoiceModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="createInvoiceForm" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group"><label class="form-label">Invoice Number <span>*</span></label><input class="form-control" name="invoice_number" id="invNumber" required placeholder="e.g. INV-2026-9901"></div>
          <div class="form-group"><label class="form-label">Linked Purchase Order <span>*</span></label><select class="form-control" name="po_id" id="invPoSelect" required onchange="onInvoicePOChange(this)"><option value="">-- Select PO --</option></select></div>
          <div class="form-group"><label class="form-label">Invoice Date <span>*</span></label><input class="form-control" name="invoice_date" type="date" required value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="form-label">Due Date</label><input class="form-control" name="due_date" type="date"></div>
          
          <div class="form-group"><label class="form-label">Subtotal (₱)</label><input class="form-control" name="subtotal" id="invSubtotal" type="number" step="0.01" min="0" value="0.00" oninput="recalcInvoiceTotal()"></div>
          <div class="form-group"><label class="form-label">Tax Amount (₱)</label><input class="form-control" name="tax_amount" id="invTax" type="number" step="0.01" min="0" value="0.00" oninput="recalcInvoiceTotal()"></div>
          <div class="form-group"><label class="form-label">Discount Amount (₱)</label><input class="form-control" name="discount_amount" id="invDiscount" type="number" step="0.01" min="0" value="0.00" oninput="recalcInvoiceTotal()"></div>
          <div class="form-group"><label class="form-label">Grand Total (₱) <span>*</span></label><input class="form-control fw-bold" name="total_amount" id="invTotal" type="number" step="0.01" min="0" value="0.00" required></div>

          <div class="form-group"><label class="form-label">Payment Status</label>
            <select class="form-control" name="payment_status" id="invPaymentStatus">
              <option value="Unpaid">Unpaid</option>
              <option value="Partially Paid">Partially Paid</option>
              <option value="Paid">Paid</option>
            </select>
          </div>

          <div class="form-group"><label class="form-label">Upload Scanned Invoice / PDF</label><input class="form-control" name="scanned_invoice" type="file" accept=".pdf,.png,.jpg,.jpeg"></div>
          <div class="form-group full"><label class="form-label">Invoice Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="Payment terms, bank reference, or payment schedule notes…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('createInvoiceModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitSupplierInvoice()"><i class="bi bi-save me-1"></i> Record Invoice</button>
    </div>
  </div>
</div>

<!-- Supplier Invoice Detail Modal -->
<div class="modal-overlay" id="invoiceDetailModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title" id="invoiceDetailTitle"><i class="bi bi-receipt"></i> Supplier Invoice Details</span>
      <button class="modal-close" onclick="closeModal('invoiceDetailModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="invoiceDetailBody">
      <div class="text-center py-4"><div class="spinner-border spinner-border-sm me-2"></div> Loading...</div>
    </div>
    <div class="modal-footer" id="invoiceDetailFooter">
      <button class="btn btn-outline-secondary" onclick="closeModal('invoiceDetailModal')">Close</button>
    </div>
  </div>
</div>

<?php if (isSecAllowed('sales-orders')): ?>
<?php salesOrderRenderModals(); ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="java/app_dialog.js?v=<?= time() ?>"></script>
<script>
function downloadProductImportTemplate() {
  var csv = 'name,supplier,category,brand,packaging,unit,cost_price,selling_price,quantity,reorder_level,barcode,description\n'
    + 'Surgical Gloves,MedSupply Co,Gloves,MedTech,100 pcs/box,pcs,45.00,70.00,0,50,,Latex examination gloves\n';
  var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'product-import-template.csv';
  link.click();
  URL.revokeObjectURL(link.href);
}
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script type="application/json" id="admin-js-data">
<?= json_encode(['categoryStats' => $category_stats, 'inventoryItems' => $inventory_items], JSON_UNESCAPED_UNICODE) ?>
</script>
<script>
window.CLZ_CATEGORY_TREE = <?= json_encode($catalogTreeForJs ?? new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
window.currentRoleKey = <?= json_encode($roleKey) ?>;
window.CLAR_AUTH_CONTEXT = <?= json_encode($roleKey) ?>;
window.canApprovePOs = <?= !empty($canApprovePOs) ? 'true' : 'false' ?>;
window.isWarehouseHeadRole = <?= !empty($isWarehouseHead) ? 'true' : 'false' ?>;
window.dashboardHomeUrl = 'purchaser.php';
<?php
$clarDeniedSections = [];
foreach (['serials-view', 'sales-orders', 'sales-credit', 'product-pricing', 'billing-invoices', 'unpaid-invoices', 'sales-reports', 'data-entry', 'barcode-scanner'] as $_clarSec) {
    if (!isSecAllowed($_clarSec)) {
        $clarDeniedSections[] = $_clarSec;
    }
}
?>
window.CLAR_DENIED_SECTIONS = <?= json_encode(array_values(array_unique($clarDeniedSections))) ?>;
window.INVOICE_DETAILS = <?= json_encode(billingInvoiceViewMap($invoices), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;
</script>
<script src="java/auth_context.js?v=<?= time() ?>"></script>
<script>window.CLAR_SALES_TAX_RATE = <?= json_encode((float)($salesTaxRate ?? 12)) ?>;
window.CLAR_CAN_MANAGE_RECEIPT_PRICING = <?= !empty($canManageReceiptPricing) ? 'true' : 'false' ?>;
window.CLAR_CAN_MANAGE_CUSTOMERS = <?= (!empty($isPurchaser) || !empty($isSystemAdmin) || !empty($isWarehouseHead)) ? 'true' : 'false' ?>;</script>
<script src="java/procurement_module.js?v=<?= time() ?>"></script>
<script src="java/customers_module.js?v=<?= time() ?>"></script>
<script src="java/sales_order_module.js?v=<?= time() ?>"></script>
<script src="java/inventory_module.js?v=<?= time() ?>"></script>
<script src="java/dashboard_main.js?v=<?= time() ?>"></script>
<script src="java/notifications.js?v=<?= time() ?>"></script>
<script src="java/sidebar_notification_badges.js?v=<?= time() ?>"></script>
<script src="java/admin_dashboard.js?v=<?= time() ?>"></script>
<script src="java/admin_modules/admin_core.js?v=<?= time() ?>"></script>
<script src="java/admin_modules/admin_inventory.js?v=<?= time() ?>"></script>
<script src="java/admin_modules/admin_users.js?v=<?= time() ?>"></script>
<script src="java/admin_modules/admin_sales_billing.js?v=<?= time() ?>"></script>
<script src="java/admin_modules/admin_audit.js?v=<?= time() ?>"></script>
<script src="java/admin_modules/admin_scanner.js?v=<?= time() ?>"></script>
<script src="java/realtime_audit.js?v=<?= time() ?>"></script>
<script src="java/list_pager.js?v=<?= time() ?>"></script>
<script>
(function () {
  function isInlineCreatePR(id) {
    if (id !== 'createPRModal') return false;
    const el = document.getElementById('createPRModal');
    return !!(el && el.getAttribute('data-pr-pos') === 'inline');
  }
  const prevOpen = window.openModal;
  const prevClose = window.closeModal;
  window.openModal = function (id) {
    if (isInlineCreatePR(id)) {
      const el = document.getElementById('createPRModal');
      if (el) {
        el.classList.add('open');
        el.style.removeProperty('display');
      }
      return;
    }
    if (typeof prevOpen === 'function') return prevOpen(id);
  };
  window.closeModal = function (id) {
    if (isInlineCreatePR(id)) {
      if (typeof stopPRSelectLiveSync === 'function') stopPRSelectLiveSync();
      if (typeof closeAllPRSupplierMenus === 'function') closeAllPRSupplierMenus();
      if (window._resettingInlinePR) return;
      if (typeof openCreatePR === 'function') {
        window._resettingInlinePR = true;
        try { openCreatePR(true); }
        finally { window._resettingInlinePR = false; }
      }
      return;
    }
    if (typeof prevClose === 'function') return prevClose(id);
  };
  const prevSwitch = window.switchPRInvoiceTab;
  window.switchPRInvoiceTab = function (tab) {
    if (typeof prevSwitch === 'function') prevSwitch(tab);
    if (tab === 'requests' && typeof ensureInlineCreatePRReady === 'function') {
      setTimeout(ensureInlineCreatePRReady, 50);
    }
  };
})();
</script>
<script>
/* Final section router — must load last so role dashboards switch content reliably */
(function () {
  const SECTION_TITLES = {
    dashboard: 'Overview',
    analytics: 'Analytics',
    inventory: 'Product Catalog',
    'stock-inventory': 'Inventory',
    'product-catalog': 'Product Catalog',
    'data-entry': 'Stock Movement',
    'barcode-scanner': 'Barcode Scanner',
    'serials-view': 'Serial Numbers',
    'stock-barcodes': 'Stock Barcodes',
    'low-stock-view': 'Low Stock Alerts',
    'expiry-view': 'Near Expiry Alerts',
    'location-stock-view': 'Location Stock',
    'transfers-view': 'Stock Transfers',
    'purchase-orders': 'PO Creation',
    'po-creation': 'PO Creation',
    'po-approvals': 'PO Approvals',
    'po-tracker': 'PO Tracker',
    'incoming-deliveries': 'Incoming Deliveries',
    'purchase-receipts': 'Goods Received',
    'markup-queue': 'Pricing Queue',
    'stock-in-queue': 'Stock-In Queue',
    'damage-returns': 'Replacement / Returns',
    'sales-orders': 'Orders',
    'sales-credit': 'Credit Agreements',
    'product-pricing': 'Price',
    price: 'Price',
    'billing-invoices': 'Account Receivable',
    'unpaid-invoices': 'Accounts Receivable / Pending',
    reports: 'Inventory Reports',
    'sales-reports': 'Sales Reports',
    account: 'My Account',
    'warehouse-audit-log': 'Audit Log',
    procurement: 'Procurement Overview',
    'purchase-requests': 'Purchase Requests',
    'pr-approval': 'PR Queue',
    'pr-queue': 'PR Queue',
    'supplier-invoices': 'Supplier Invoices',
    'suppliers-view': 'Supplier Directory',
    'customers': 'Customer Management',
    notifications: 'Alerts & History',
    users: 'Users',
    'audit-log': 'Activity Logs',
    'purchase-history': 'Purchase History',
    'delivery-schedule': 'Delivery Schedule',
    'purchaser-audit-log': 'Audit Log'
  };

  function setSectionVisible(el, visible) {
    if (!el) return;
    if (visible) {
      el.classList.add('active');
      el.style.setProperty('display', 'block', 'important');
      el.style.setProperty('visibility', 'visible', 'important');
      el.removeAttribute('hidden');
    } else {
      el.classList.remove('active');
      el.style.setProperty('display', 'none', 'important');
      el.style.setProperty('visibility', 'hidden', 'important');
    }
  }

  window.showSection = function showSection(section, clickedEl) {
    let requested = String(section || '').trim();
    if (!requested) return;
    if (typeof clarSectionIsDenied === 'function' && clarSectionIsDenied(requested)) {
      requested = 'dashboard';
      clickedEl = null;
    }

    if (requested !== 'data-entry' && typeof stopAdminScanner === 'function') {
      try { stopAdminScanner(); } catch (e) {}
    }
    if (requested !== 'barcode-scanner' && typeof dashStopScanner === 'function') {
      try { dashStopScanner(); } catch (e) {}
    }

    const prThreeMap = {
      'purchase-requests': 'requests',
      'pr-approval': 'approval',
      'supplier-invoices': 'invoices'
    };

    let normalized = requested === 'inv-mgmt' ? 'inventory' : requested;
    normalized = String(normalized).trim().toLowerCase().replace(/[_\s]+/g, '-');
    if (normalized === 'stock-in' || normalized === 'markup-stock-in-approval') normalized = 'stock-in-queue';
    if (normalized === 'product-catalog') normalized = 'inventory';
    if (normalized === 'po-creation') normalized = 'purchase-orders';
    if (normalized === 'pr-queue') normalized = 'pr-approval';
    if (normalized === 'price') normalized = 'product-pricing';
    const prTab = prThreeMap[requested] || prThreeMap[normalized];
    if (prTab) normalized = 'purchase-requests';

    document.querySelectorAll('.content-section').forEach((s) => setSectionVisible(s, false));
    document.querySelectorAll('.sidebar-nav .nav-item, .sidebar-nav .nav-subitem, .sidebar-nav .nav-group-toggle, .nav-subitem').forEach((el) => {
      el.classList.remove('active', 'active-group');
    });

    let target = document.getElementById('section-' + normalized);
    if (!target && prTab) target = document.getElementById('section-purchase-requests');
    if (!target) {
      requested = 'dashboard';
      normalized = 'dashboard';
      target = document.getElementById('section-dashboard');
    }
    if (target) {
      setSectionVisible(target, true);
    }

    if (prTab && typeof switchPRInvoiceTab === 'function') switchPRInvoiceTab(prTab);

    let nav = clickedEl;
    if (!nav) {
      nav = document.querySelector(`.sidebar-nav button[onclick*="'${requested}'"]`) ||
           document.querySelector(`.sidebar-nav button[onclick*="'${normalized}'"]`);
    }
    if (typeof incomingGrSidebarNav === 'function') {
      const incomingNav = incomingGrSidebarNav(normalized, clickedEl);
      if (incomingNav) nav = incomingNav;
    }
    if (typeof syncIncomingGrTabs === 'function') syncIncomingGrTabs(normalized);
    if (nav) {
      nav.classList.add('active');
      const parentGroup = nav.closest('.nav-group');
      if (parentGroup) {
        parentGroup.classList.add('open');
        parentGroup.querySelectorAll('.nav-group-toggle').forEach((t) => t.classList.add('active-group'));
      }
    }

    const title = SECTION_TITLES[requested] || SECTION_TITLES[normalized] || 'Overview';
    const crumb = document.getElementById('breadcrumbCurrent');
    if (crumb) crumb.textContent = title;
    const pageTitle = document.querySelector('.page-title');
    if (pageTitle) pageTitle.textContent = title;
    document.title = title + ' | Clarenzon Medical';

    if (typeof syncSalesOrderPosForSection === 'function') syncSalesOrderPosForSection(normalized);
    if (normalized === 'product-pricing' && typeof loadProductPricingTable === 'function') setTimeout(loadProductPricingTable, 40);
    if ((normalized === 'purchase-orders' || normalized === 'po-creation') && typeof loadPurchaseOrders === 'function') setTimeout(loadPurchaseOrders, 40);
    if (normalized === 'po-approvals' && typeof loadPoApprovals === 'function') setTimeout(loadPoApprovals, 40);
    if (normalized === 'po-tracker' && typeof loadPoTracker === 'function') setTimeout(loadPoTracker, 40);
    if (normalized === 'stock-inventory') {
      if (typeof loadInventoryOverview === 'function') setTimeout(loadInventoryOverview, 40);
      if (typeof loadReturnReplacementHistory === 'function') setTimeout(loadReturnReplacementHistory, 40);
      if (typeof loadStockConditionHistory === 'function') setTimeout(loadStockConditionHistory, 40);
    }
    if (normalized === 'purchase-receipts' && typeof loadPurchaseReceipts === 'function') setTimeout(loadPurchaseReceipts, 40);
    if (normalized === 'markup-queue' && typeof loadPurchaserMarkupQueue === 'function') setTimeout(loadPurchaserMarkupQueue, 40);
    if (normalized === 'stock-in-queue' && typeof loadStockInQueue === 'function') setTimeout(loadStockInQueue, 40);
    if (normalized === 'damage-returns' && typeof loadDamageReturns === 'function') setTimeout(loadDamageReturns, 40);
    if (normalized === 'suppliers-view' && typeof loadSuppliers === 'function') setTimeout(loadSuppliers, 40);
    if (normalized === 'customers' && typeof loadCustomers === 'function') setTimeout(loadCustomers, 40);
    if (normalized === 'serials-view' && typeof loadSerials === 'function') setTimeout(loadSerials, 40);
    if (normalized === 'stock-barcodes' && typeof loadStockBarcodes === 'function') setTimeout(loadStockBarcodes, 40);
    if (normalized === 'location-stock-view' && typeof loadLocationStock === 'function') setTimeout(loadLocationStock, 40);
    if ((normalized === 'inventory' || normalized === 'procurement') && typeof loadDashboard === 'function') setTimeout(loadDashboard, 40);
    if (normalized === 'analytics' && typeof initAnalyticsCharts === 'function') initAnalyticsCharts();
    if (normalized === 'dashboard' && typeof initDashboardCharts === 'function') initDashboardCharts();
    if ((normalized === 'dashboard' || normalized === 'incoming-deliveries') && typeof loadIncomingDeliveries === 'function') {
      setTimeout(() => loadIncomingDeliveries(normalized !== 'incoming-deliveries'), 40);
    }
    if (window.innerWidth <= 991 && typeof closeMobileSidebar === 'function') closeMobileSidebar();
  };

  window.routeToSection = function routeToSection(section, clickedEl) {
    if (typeof window.showSection === 'function') window.showSection(section, clickedEl || null);
  };
})();
</script>
</body>
</html>
