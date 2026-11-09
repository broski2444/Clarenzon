<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
header('Content-Type: application/json');

require_once 'warehouse_permissions.php';
require_once __DIR__ . '/inventory_wms_lib.php';
require_once __DIR__ . '/product_expiry_lib.php';
require_once __DIR__ . '/product_barcodes_lib.php';

/* ═══════════════════════════════════════════════════════════════
   DATABASE CONNECTION
   ═══════════════════════════════════════════════════════════════ */
function getScannerConnection(): mysqli|false {
    require_once __DIR__ . '/mysqli_connect_fast.php';
    $conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'], 'root', '', 3);
    return $conn ?: false;
}

/* ═══════════════════════════════════════════════════════════════
   SCHEMA HELPERS
   ═══════════════════════════════════════════════════════════════ */
function scannerSafeError(string $fallback = 'Unable to update inventory. Please try again.'): string {
    return $fallback;
}

function scannerCategoryParentSql(mysqli $conn): array {
    static $has = null;
    if ($has === null) {
        $q = $conn->query("SHOW COLUMNS FROM categories LIKE 'parent_id'");
        $has = ($q && $q->num_rows > 0);
    }
    if ($has) {
        return [
            "c.parent_id AS parent_category_id, COALESCE(pc.name, '') AS parent_category_name,",
            "LEFT JOIN categories pc ON pc.id = c.parent_id",
        ];
    }
    return [
        "NULL AS parent_category_id, '' AS parent_category_name,",
        "",
    ];
}

function scannerProductImageColumn(mysqli $conn): string {
    static $col = null;
    if ($col !== null) {
        return $col;
    }
    $col = '';
    foreach (['product_image', 'image', 'image_path', 'photo', 'photo_path'] as $name) {
        $q = $conn->query("SHOW COLUMNS FROM products LIKE '" . $conn->real_escape_string($name) . "'");
        if ($q && $q->num_rows > 0) {
            $col = $name;
            break;
        }
    }
    return $col;
}

function scannerEnsureIdempotencyTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS scanner_idempotency (
        request_id VARCHAR(80) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL DEFAULT 0,
        route VARCHAR(40) NOT NULL DEFAULT '',
        response_json MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_scanner_idem_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function scannerIdempotencyGet(mysqli $conn, string $requestId, int $userId): ?array {
    $requestId = trim($requestId);
    if ($requestId === '' || strlen($requestId) > 80) {
        return null;
    }
    scannerEnsureIdempotencyTable($conn);
    $stmt = $conn->prepare("SELECT response_json FROM scanner_idempotency WHERE request_id=? AND user_id=? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('si', $requestId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['response_json'])) {
        return null;
    }
    $decoded = json_decode((string)$row['response_json'], true);
    return is_array($decoded) ? $decoded : null;
}

function scannerIdempotencyPut(mysqli $conn, string $requestId, int $userId, int $productId, string $route, array $payload): void {
    $requestId = trim($requestId);
    if ($requestId === '' || strlen($requestId) > 80) {
        return;
    }
    scannerEnsureIdempotencyTable($conn);
    $json = json_encode($payload);
    $stmt = $conn->prepare("INSERT IGNORE INTO scanner_idempotency (request_id, user_id, product_id, route, response_json) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('siiss', $requestId, $userId, $productId, $route, $json);
    $stmt->execute();
    $stmt->close();
}

function scannerProductBatches(mysqli $conn, int $productId): array {
    if ($productId <= 0) {
        return [];
    }
    $rows = [];
    $sql = "SELECT id, batch_number, expiry_date, quantity, quantity_remaining, status
            FROM product_batches
            WHERE product_id=?
            ORDER BY CASE WHEN quantity_remaining > 0 THEN 0 ELSE 1 END,
                     CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
                     expiry_date ASC, id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = [
            'id' => (int)$r['id'],
            'batch_number' => (string)($r['batch_number'] ?? ''),
            'expiry_date' => $r['expiry_date'] ?? null,
            'quantity' => (int)($r['quantity'] ?? 0),
            'quantity_remaining' => (int)($r['quantity_remaining'] ?? 0),
            'status' => (string)($r['status'] ?? 'Active'),
        ];
    }
    $stmt->close();
    return $rows;
}

function scannerCreditBatch(mysqli $conn, int $productId, int $qty, int $batchId = 0, string $batchNumber = '', ?string $expiryDate = null, int $supplierId = 0): int {
    if ($productId <= 0 || $qty <= 0) {
        return 0;
    }
    if ($batchId > 0) {
        $chk = $conn->prepare("SELECT id FROM product_batches WHERE id=? AND product_id=? LIMIT 1");
        if ($chk) {
            $chk->bind_param('ii', $batchId, $productId);
            $chk->execute();
            $ok = (bool)$chk->get_result()->fetch_assoc();
            $chk->close();
            if ($ok) {
                $conn->query("UPDATE product_batches SET quantity = quantity + $qty, quantity_remaining = quantity_remaining + $qty WHERE id=$batchId");
                return $batchId;
            }
        }
    }
    $batchNumber = trim($batchNumber);
    if ($batchNumber === '') {
        return 0;
    }
    if (function_exists('recvUpsertProductBatch')) {
        return (int)recvUpsertProductBatch($conn, $productId, $batchNumber, $qty, $supplierId, date('Y-m-d'), $expiryDate, '', 0, 0, 0);
    }
    $find = $conn->prepare("SELECT id FROM product_batches WHERE product_id=? AND batch_number=? LIMIT 1");
    if ($find) {
        $find->bind_param('is', $productId, $batchNumber);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if ($row) {
            $id = (int)$row['id'];
            $conn->query("UPDATE product_batches SET quantity = quantity + $qty, quantity_remaining = quantity_remaining + $qty WHERE id=$id");
            return $id;
        }
    }
    return 0;
}

function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    $check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function ensureScannerSchema(mysqli $conn): void {
    /* ── Products table – add any missing columns ── */
    addColumnIfMissing($conn, 'products', 'barcode',               'VARCHAR(100) NULL');
    addColumnIfMissing($conn, 'products', 'location',              'VARCHAR(150) NULL');
    addColumnIfMissing($conn, 'products', 'status',                'VARCHAR(50)  NULL DEFAULT "Active"');
    addColumnIfMissing($conn, 'products', 'unit_price',            'DECIMAL(12,2) NULL DEFAULT 0.00');
    addColumnIfMissing($conn, 'products', 'supplier_product_code', 'VARCHAR(100) NULL');
    addColumnIfMissing($conn, 'products', 'brand',                 'VARCHAR(100) NULL');
    addColumnIfMissing($conn, 'products', 'unit_of_measure',       'VARCHAR(50)  NULL DEFAULT "piece"');
    addColumnIfMissing($conn, 'products', 'reorder_level',         'INT NULL DEFAULT 50');
    addColumnIfMissing($conn, 'products', 'cost_price',            'DECIMAL(10,2) NULL DEFAULT 0.00');
    addColumnIfMissing($conn, 'products', 'selling_price',         'DECIMAL(10,2) NULL DEFAULT 0.00');
    addColumnIfMissing($conn, 'products', 'expiry_date',           'DATE NULL');
    addColumnIfMissing($conn, 'products', 'description',           'TEXT NULL');

    /* ── stock_movements – create only if absent (keep existing ENUM version) ── */
    $conn->query("CREATE TABLE IF NOT EXISTS stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        movement_type VARCHAR(100) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        unit_price DECIMAL(10,2) NULL,
        total_value DECIMAL(12,2) NULL,
        reference_number VARCHAR(100) NULL,
        user_id INT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product_id (product_id),
        INDEX idx_movement_type (movement_type)
    ) ENGINE=InnoDB");

    /* ── stock_movements – add reference document columns ── */
    addColumnIfMissing($conn, 'stock_movements', 'po_number',     'VARCHAR(100) NULL COMMENT "Purchase Order Number"');
    addColumnIfMissing($conn, 'stock_movements', 'si_number',     'VARCHAR(100) NULL COMMENT "Sales Invoice Number"');
    addColumnIfMissing($conn, 'stock_movements', 'dr_number',     'VARCHAR(100) NULL COMMENT "Delivery Receipt Number"');
    addColumnIfMissing($conn, 'stock_movements', 'return_slf',    'VARCHAR(100) NULL COMMENT "Return SLF"');
    addColumnIfMissing($conn, 'stock_movements', 'charge_slf',    'VARCHAR(100) NULL COMMENT "Charge SLF"');
    addColumnIfMissing($conn, 'stock_movements', 'quantity_unit', 'VARCHAR(50)  NULL COMMENT "Unit of measure at time of entry"');
    addColumnIfMissing($conn, 'stock_movements', 'source',        'VARCHAR(50)  NULL DEFAULT "manual" COMMENT "Origin: scanner|manual|delivery|api"');
    addColumnIfMissing($conn, 'stock_movements', 'batch_id',          'INT NULL');
    addColumnIfMissing($conn, 'stock_movements', 'from_location_id',  'INT NULL');
    addColumnIfMissing($conn, 'stock_movements', 'to_location_id',    'INT NULL');

    /* ── scan_history ── */
    $conn->query("CREATE TABLE IF NOT EXISTS scan_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        device_used VARCHAR(50) NOT NULL,
        scanned_code VARCHAR(255) NOT NULL,
        action_performed VARCHAR(50) NOT NULL,
        product_id INT NULL,
        scan_status VARCHAR(50) NULL DEFAULT 'product_not_found',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB");

    addColumnIfMissing($conn, 'scan_history', 'scan_status', 'VARCHAR(50) NULL DEFAULT "product_not_found"');

    /* ── product_batches – create only if absent ── */
    $conn->query("CREATE TABLE IF NOT EXISTS product_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        batch_number VARCHAR(100) NOT NULL,
        expiry_date DATE NULL,
        quantity INT NOT NULL DEFAULT 0,
        quantity_remaining INT NOT NULL DEFAULT 0,
        supplier_id INT NULL,
        status ENUM('Active','Expired','Recalled','Depleted') NOT NULL DEFAULT 'Active',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product (product_id),
        INDEX idx_batch_number (batch_number)
    ) ENGINE=InnoDB");
}

/* ═══════════════════════════════════════════════════════════════
   PRODUCT LOOKUP — full extended data
   ═══════════════════════════════════════════════════════════════ */
function getProductByCode(mysqli $conn, string $code): ?array {
    $raw = trim($code);
    if ($raw === '') return null;
    $searchCode = strtoupper($raw);
    productBarcodesEnsureSchema($conn);

    $imgCol = scannerProductImageColumn($conn);
    $imgSql = $imgCol !== '' ? ("p.`" . str_replace('`', '', $imgCol) . "` AS product_image,") : "'' AS product_image,";
    [$parentSelect, $parentJoin] = scannerCategoryParentSql($conn);
    $select = "
        SELECT
            p.id, p.sku, p.name, p.barcode, p.brand, p.description,
            $imgSql
            p.quantity_in_stock,
            COALESCE(p.unit_price, p.selling_price, 0.00)         AS unit_price,
            COALESCE(p.cost_price, 0.00)                           AS cost_price,
            COALESCE(p.selling_price, p.unit_price, 0.00)          AS selling_price,
            COALESCE(p.reorder_level, 50)                          AS reorder_level,
            COALESCE(p.unit_of_measure, 'piece')                   AS unit_of_measure,
            p.expiry_date,
            COALESCE(p.expiry_tracking, 0)                         AS expiry_tracking,
            COALESCE(p.supplier_product_code, '')                  AS supplier_product_code,
            p.status,
            p.category_id,
            COALESCE(c.name, '')                                   AS category_name,
            $parentSelect
            p.supplier_id,
            COALESCE(s.name, '')                                   AS supplier_name,
            p.location_id,
            COALESCE(
                CONCAT(l.storage_area,
                    IF(l.shelf_rack IS NOT NULL AND l.shelf_rack <> '',
                       CONCAT(' — ', l.shelf_rack), '')),
                p.location,
                'Not assigned'
            )                                                      AS location,
            COALESCE(l.location_code, '')                          AS location_code
        FROM products p
        LEFT JOIN categories        c ON c.id = p.category_id
        $parentJoin
        LEFT JOIN suppliers         s ON s.id = p.supplier_id
        LEFT JOIN storage_locations l ON l.id = p.location_id
    ";

    $linkedId = productBarcodesFindProductId($conn, $raw);
    if ($linkedId > 0) {
        $stmt = $conn->prepare($select . " WHERE p.id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $linkedId);
            $stmt->execute();
            $result  = $stmt->get_result();
            $product = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($product) {
                $hydrated = productBarcodesHydrateRows($conn, [$product], 'id');
                return $hydrated[0];
            }
        }
    }

    $stmt = $conn->prepare($select . " WHERE p.barcode = ? OR UPPER(TRIM(p.barcode)) = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $raw, $searchCode);
        $stmt->execute();
        $result  = $stmt->get_result();
        $product = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($product) {
            $hydrated = productBarcodesHydrateRows($conn, [$product], 'id');
            return $hydrated[0];
        }
    }

    $stmt = $conn->prepare($select . " WHERE UPPER(TRIM(p.sku)) = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $searchCode);
    $stmt->execute();
    $result  = $stmt->get_result();
    $product = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$product) return null;
    $hydrated = productBarcodesHydrateRows($conn, [$product], 'id');
    return $hydrated[0];
}

function searchProductsByName(mysqli $conn, string $query, int $limit = 15): array {
    $q = trim($query);
    if ($q === '' || strlen($q) < 2) return [];
    $like = '%' . $q . '%';
    $imgCol = scannerProductImageColumn($conn);
    $imgSql = $imgCol !== '' ? ("p.`" . str_replace('`', '', $imgCol) . "` AS product_image,") : "'' AS product_image,";
    [$parentSelect, $parentJoin] = scannerCategoryParentSql($conn);
    $select = "
        SELECT
            p.id, p.sku, p.name, p.barcode, p.brand, p.description,
            $imgSql
            p.quantity_in_stock,
            COALESCE(p.unit_price, p.selling_price, 0.00) AS unit_price,
            COALESCE(p.cost_price, 0.00) AS cost_price,
            COALESCE(p.selling_price, p.unit_price, 0.00) AS selling_price,
            COALESCE(p.reorder_level, 50) AS reorder_level,
            COALESCE(p.unit_of_measure, 'piece') AS unit_of_measure,
            p.expiry_date,
            COALESCE(p.expiry_tracking, 0) AS expiry_tracking,
            COALESCE(p.supplier_product_code, '') AS supplier_product_code,
            p.status,
            p.category_id,
            COALESCE(c.name, '') AS category_name,
            $parentSelect
            p.supplier_id,
            COALESCE(s.name, '') AS supplier_name,
            p.location_id,
            COALESCE(
                CONCAT(l.storage_area,
                    IF(l.shelf_rack IS NOT NULL AND l.shelf_rack <> '',
                       CONCAT(' — ', l.shelf_rack), '')),
                p.location,
                'Not assigned'
            ) AS location,
            COALESCE(l.location_code, '') AS location_code
        FROM products p
        LEFT JOIN categories        c ON c.id = p.category_id
        $parentJoin
        LEFT JOIN suppliers         s ON s.id = p.supplier_id
        LEFT JOIN storage_locations l ON l.id = p.location_id
        WHERE p.name LIKE ?
           OR p.sku LIKE ?
           OR p.barcode LIKE ?
           OR p.brand LIKE ?
           OR EXISTS (
                SELECT 1 FROM product_barcodes pb
                WHERE pb.product_id = p.id AND pb.status='active' AND pb.barcode_value LIKE ?
           )
        ORDER BY p.id DESC
        LIMIT " . max(1, min(30, $limit));
    $stmt = $conn->prepare($select);
    if (!$stmt) return [];
    $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    $stmt->close();
    return productBarcodesHydrateRows($conn, $rows, 'id');
}

function scannerFormatProduct(array $product, array $sellableMap, ?mysqli $conn = null, string $scannedCode = ''): array {
    $onHand = (int)($product['quantity_in_stock'] ?? 0);
    $pid = (int)$product['id'];
    $sellable = (int)($sellableMap[$pid] ?? $onHand);
    $tracksExpiry = (int)($product['expiry_tracking'] ?? 0) === 1;
    $parentName = trim((string)($product['parent_category_name'] ?? ''));
    $catName = trim((string)($product['category_name'] ?? ''));
    if ($parentName !== '') {
        $category = $parentName;
        $subcategory = $catName;
    } else {
        $category = $catName;
        $subcategory = '';
    }
    $barcodes = $product['barcode_values'] ?? [];
    if (!is_array($barcodes)) {
        $barcodes = [];
    }
    $batches = [];
    if ($conn instanceof mysqli) {
        $batches = scannerProductBatches($conn, $pid);
    }
    $image = trim((string)($product['product_image'] ?? ''));
    $unit = (string)($product['unit_of_measure'] ?? 'piece');
    return [
        'id'                    => $pid,
        'sku'                   => $product['sku'],
        'name'                  => $product['name'],
        'brand'                 => $product['brand']                 ?? '',
        'barcode'               => $product['barcode'],
        'scanned_barcode'       => $scannedCode !== '' ? $scannedCode : (string)($product['barcode'] ?? ''),
        'barcode_values'        => array_values($barcodes),
        'barcode_count'         => (int)($product['barcode_count'] ?? count($barcodes)),
        'description'           => $product['description']           ?? '',
        'image'                 => $image,
        'quantity_in_stock'     => $onHand,
        'sellable_qty'          => $sellable,
        'available_stock'       => $sellable,
        'unit_price'            => (float)($product['unit_price']    ?? 0),
        'cost_price'            => (float)($product['cost_price']    ?? 0),
        'cost'                  => (float)($product['cost_price']    ?? 0),
        'selling_price'         => (float)($product['selling_price'] ?? 0),
        'reorder_level'         => (int)($product['reorder_level']   ?? 50),
        'unit_of_measure'       => $unit,
        'unit'                  => $unit,
        'expiry_tracking'       => $tracksExpiry ? 1 : 0,
        'expiry_date'           => $tracksExpiry ? ($product['expiry_date'] ?? null) : ($product['expiry_date'] ?? null),
        'supplier_product_code' => $product['supplier_product_code'] ?? '',
        'status'                => $product['status']                ?? 'Active',
        'category_id'           => $product['category_id']  ? (int)$product['category_id']  : null,
        'category_name'         => $category,
        'subcategory_name'      => $subcategory,
        'supplier_id'           => $product['supplier_id']   ? (int)$product['supplier_id']   : null,
        'supplier_name'         => $product['supplier_name']         ?? '',
        'location_id'           => $product['location_id']   ? (int)$product['location_id']   : null,
        'location'              => $product['location']              ?? '',
        'location_code'         => $product['location_code']         ?? '',
        'batches'               => $batches,
        'batch_number'          => $batches[0]['batch_number'] ?? '',
        'batch_expiry'          => $batches[0]['expiry_date'] ?? ($tracksExpiry ? ($product['expiry_date'] ?? null) : null),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   PERMISSION CHECK
   ═══════════════════════════════════════════════════════════════ */
function canManageInventory(mysqli $conn, string $role): bool {
    if (isWarehouseHeadRole($role)) return false;
    if (isAdminWarehouseRole($role)) return true;
    if (!isWarehouseAssistantRole($role)) return false;
    $permissions = getWarehouseAssistantPermissions($conn);
    return (bool)$permissions['can_manage_records'];
}

/* ═══════════════════════════════════════════════════════════════
   SCAN LOGGING
   ═══════════════════════════════════════════════════════════════ */
function logScan(
    mysqli  $conn,
    int     $userId,
    string  $deviceUsed,
    string  $code,
    string  $actionPerformed,
    ?int    $productId  = null,
    ?string $notes      = null,
    string  $scanStatus = 'existing_product'
): void {
    /* Check whether scan_status column exists (added by ensureScannerSchema) */
    $chk = $conn->query("SHOW COLUMNS FROM scan_history LIKE 'scan_status'");
    if ($chk && $chk->num_rows > 0) {
        $stmt = $conn->prepare(
            "INSERT INTO scan_history
             (user_id, device_used, scanned_code, action_performed, product_id, scan_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssiss', $userId, $deviceUsed, $code, $actionPerformed, $productId, $scanStatus, $notes);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO scan_history
             (user_id, device_used, scanned_code, action_performed, product_id, notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssis', $userId, $deviceUsed, $code, $actionPerformed, $productId, $notes);
    }
    $stmt->execute();
    $stmt->close();
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSE HELPER
   ═══════════════════════════════════════════════════════════════ */
function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

/* ═══════════════════════════════════════════════════════════════
   AUTH GUARD
   ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
clarenzon_api_require();

/* ═══════════════════════════════════════════════════════════════
   CONNECT & ENSURE SCHEMA
   ═══════════════════════════════════════════════════════════════ */
$conn = getScannerConnection();
if ($conn === false) {
    respond(['success' => false, 'message' => 'Database connection failed'], 500);
}
ensureScannerSchema($conn);
if (function_exists('productExpiryEnsureSchema')) {
    productExpiryEnsureSchema($conn);
}

/* ═══════════════════════════════════════════════════════════════
   ROUTE PARSING
   ═══════════════════════════════════════════════════════════════ */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (function_exists('clarenzon_api_guard_method')) {
    clarenzon_api_guard_method((string)$action);
}
$param  = $_GET['code']   ?? $_POST['code']   ?? $_GET['param'] ?? '';

if ($action === '') {
    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/scanner_api.php', PHP_URL_PATH) ?: '/scanner_api.php';
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'scanner_api.php');
    $uri    = str_replace('/' . $script, '', $uri);
    $segs   = array_values(array_filter(explode('/', trim($uri, '/')), 'strlen'));
    $action = $segs[0] ?? '';
    if ($param === '' && isset($segs[1])) {
        $param = urldecode($segs[1]);
    }
    if (function_exists('clarenzon_api_guard_method')) {
        clarenzon_api_guard_method((string)$action);
    }
}

$route  = str_replace('_', '-', strtolower($action));
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? '');

// Cashier may resolve a scanned barcode to a product at the sales counter (read-only lookup).
$cashierProductLookup = scannerRoleKey($role) === 'cashier' && $method === 'GET' && $route === 'product';
if (!canUseMobileBarcodeScanner($role) && !$cashierProductLookup) {
    respond([
        'success' => false,
        'code'    => 'access_denied',
        'title'   => 'Access Denied',
        'message' => 'You do not have permission to use the mobile barcode scanner.',
    ], 403);
}

if ($method === 'GET' && ($route === 'session' || $route === 'access')) {
    respond([
        'success' => true,
        'data' => [
            'user_id' => $userId,
            'role' => $role,
            'role_key' => scannerRoleKey($role),
            'can_scan' => true,
            'can_stock' => canScannerModifyStock($role),
        ],
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   GET /recent-scans
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'GET' && $route === 'recent-scans') {
    $stmt = $conn->prepare("
        SELECT s.device_used, s.scanned_code, s.action_performed,
               COALESCE(s.scan_status, 'existing_product') AS scan_status,
               s.notes, s.created_at,
               COALESCE(p.name, '') AS product_name
        FROM scan_history s
        LEFT JOIN products p ON p.id = s.product_id
        ORDER BY s.id DESC
        LIMIT 10
    ");
    $stmt->execute();
    $rows = [];
    $res  = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    respond(['success' => true, 'data' => $rows]);
}

/* ═══════════════════════════════════════════════════════════════
   GET /product/{code}
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'GET' && $route === 'product' && $param !== '') {
    if (function_exists('productExpiryDisposeExpired')) {
        productExpiryDisposeExpired($conn, $userId);
    }
    $code    = trim($param);
    $product = getProductByCode($conn, $code);
    $matches = [];
    if (!$product) {
        $matches = searchProductsByName($conn, $code);
        if (count($matches) === 1) {
            $product = $matches[0];
            $matches = [];
        }
    }
    $status  = $product ? 'existing_product' : (!empty($matches) ? 'existing_product' : 'product_not_found');
    logScan($conn, $userId, 'lookup', $code,
            $product ? 'lookup_success' : (!empty($matches) ? 'lookup_success' : 'lookup_not_found'),
            $product ? (int)$product['id'] : null, null, $status);

    if (!$product && empty($matches)) {
        respond([
            'success'     => false,
            'title'       => 'Barcode Not Found',
            'message'     => 'No product is associated with this barcode.',
            'scan_status' => 'product_not_found',
        ], 404);
    }

    $sellableMap = invWmsSellableQtyMap($conn);
    if (!$product && $matches) {
        $results = [];
        foreach ($matches as $row) {
            $results[] = scannerFormatProduct($row, $sellableMap, $conn, $code);
        }
        respond([
            'success'     => true,
            'scan_status' => 'existing_product',
            'results'     => $results,
            'message'     => 'Select a product',
        ]);
    }

    respond(['success' => true, 'scan_status' => 'existing_product', 'data' => scannerFormatProduct($product, $sellableMap, $conn, $code)]);
}

/* ═══════════════════════════════════════════════════════════════
   GET /lookup-data  — categories + suppliers + locations in one shot
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'GET' && $route === 'lookup-data') {
    $cats = [];
    $res  = $conn->query("SELECT id, name FROM categories WHERE status='Active' ORDER BY name");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cats[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        }
    }

    $suppliers = [];
    $res = $conn->query("SELECT id, name, supplier_code FROM suppliers WHERE status='Active' ORDER BY name");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $suppliers[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'code' => $r['supplier_code']];
        }
    }

    $locations = [];
    $res = $conn->query("
        SELECT id, location_code, storage_area, COALESCE(shelf_rack,'') AS shelf_rack
        FROM storage_locations
        WHERE status='Active'
        ORDER BY storage_area, shelf_rack
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $label = $r['storage_area'];
            if ($r['shelf_rack'] !== '') $label .= ' — ' . $r['shelf_rack'];
            $locations[] = ['id' => (int)$r['id'], 'label' => $label, 'code' => $r['location_code']];
        }
    }

    respond(['success' => true, 'data' => [
        'categories' => $cats,
        'suppliers'  => $suppliers,
        'locations'  => $locations,
    ]]);
}

/* ═══════════════════════════════════════════════════════════════
   GET /categories  — backward compatibility
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'GET' && $route === 'categories') {
    $cats = [];
    $res  = $conn->query("SELECT id, name FROM categories WHERE status='Active' ORDER BY name");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cats[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        }
    }
    respond(['success' => true, 'data' => $cats]);
}

/* ═══════════════════════════════════════════════════════════════
   POST /scan
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'POST' && $route === 'scan') {
    $barcode    = trim((string)($_POST['barcode']     ?? ''));
    $deviceUsed = trim((string)($_POST['device_used'] ?? 'camera'));

    if ($barcode === '') respond(['success' => false, 'message' => 'Barcode is required'], 422);

    $product = getProductByCode($conn, $barcode);
    $status  = $product ? 'existing_product' : 'product_not_found';
    logScan($conn, $userId, $deviceUsed ?: 'camera', $barcode,
            $product ? 'scan_success' : 'scan_not_found',
            $product ? (int)$product['id'] : null, null, $status);

    if (!$product) {
        respond([
            'success'     => false,
            'title'       => 'Barcode Not Found',
            'message'     => 'No product is associated with this barcode.',
            'scan_status' => 'product_not_found',
        ], 404);
    }

    $sellableMap = invWmsSellableQtyMap($conn);
    respond([
        'success' => true,
        'message' => 'Product found',
        'scan_status' => 'existing_product',
        'data' => scannerFormatProduct($product, $sellableMap, $conn, $barcode),
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   POST /stock-in | /stock-out
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'POST' && ($route === 'stock-in' || $route === 'stock-out')) {
    if (!canScannerModifyStock($role)) {
        respond([
            'success' => false,
            'title'   => 'Access Denied',
            'message' => 'You do not have permission to use the mobile barcode scanner.',
        ], 403);
    }

    $barcode      = trim((string)($_POST['barcode']       ?? ''));
    $quantity     = (int)($_POST['quantity']              ?? 0);
    $notes        = trim((string)($_POST['notes']         ?? ''));
    $reference    = trim((string)($_POST['reference']     ?? ''));
    $deviceUsed   = trim((string)($_POST['device_used']   ?? 'scanner'));
    $requestId    = trim((string)($_POST['request_id']    ?? $_POST['idempotency_key'] ?? ''));
    $batchIdIn    = (int)($_POST['batch_id']              ?? 0);
    $batchNumber  = trim((string)($_POST['batch_number']  ?? ''));
    $batchExpiry  = trim((string)($_POST['expiry_date']   ?? '')) ?: null;
    /* ── Document reference numbers (Stock In only; ignored on Stock Out) ── */
    $poNumber   = trim((string)($_POST['po_number']     ?? '')) ?: null;
    $siNumber   = trim((string)($_POST['si_number']     ?? '')) ?: null;
    $drNumber   = trim((string)($_POST['dr_number']     ?? '')) ?: null;
    $returnSlf  = trim((string)($_POST['return_slf']    ?? '')) ?: null;
    $chargeSlf  = trim((string)($_POST['charge_slf']    ?? '')) ?: null;
    $qtyUnit    = trim((string)($_POST['quantity_unit'] ?? '')) ?: null;

    if ($requestId !== '') {
        $cached = scannerIdempotencyGet($conn, $requestId, $userId);
        if ($cached) {
            respond($cached);
        }
    }

    if ($barcode === '' || $quantity <= 0) {
        respond(['success' => false, 'message' => 'Enter a valid quantity greater than zero.'], 422);
    }

    $product = getProductByCode($conn, $barcode);
    if (!$product) {
        logScan($conn, $userId, $deviceUsed, $barcode, 'stock_failed_not_found', null,
                'Product not found', 'product_not_found');
        respond([
            'success' => false,
            'title'   => 'Barcode Not Found',
            'message' => 'No product is associated with this barcode.',
        ], 404);
    }

    $productId    = (int)$product['id'];
    $currentStock = (int)($product['quantity_in_stock'] ?? 0);
    $reorderLevel = (int)($product['reorder_level']     ?? 50);
    $newStock     = $route === 'stock-in'
        ? ($currentStock + $quantity)
        : ($currentStock - $quantity);

    if ($newStock < 0) {
        respond(['success' => false, 'message' => 'Insufficient stock for stock-out'], 422);
    }

    $newStatus    = $newStock <= 0 ? 'Out of Stock'
        : ($newStock <= $reorderLevel ? 'Low Stock' : 'Active');
    // Use ENUM-safe values (compatible with both ENUM and VARCHAR movement_type columns)
    $movementType = $route === 'stock-in' ? 'Stock In (Receipt)' : 'Stock Out (Issue)';
    $refNumber    = $reference ?: strtoupper(($route === 'stock-in' ? 'SCAN-IN-' : 'SCAN-OUT-') . date('Ymd-His'));

    require_once __DIR__ . '/inventory_wms_lib.php';
    if (is_file(__DIR__ . '/procurement_receiving_lib.php')) {
        require_once __DIR__ . '/procurement_receiving_lib.php';
    }

    $conn->begin_transaction();
    try {
        invWmsEnsureSchema($conn);

        $locId = invWmsResolveProductLocation($conn, $productId, !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null);
        $batchId = null;
        $fefoInfo = [];

        if ($route === 'stock-in') {
            if (!invWmsAdjustLocationStock($conn, $productId, $locId, $quantity)) {
                throw new RuntimeException('stock_adjust');
            }
            $credited = scannerCreditBatch(
                $conn,
                $productId,
                $quantity,
                $batchIdIn,
                $batchNumber,
                $batchExpiry,
                (int)($product['supplier_id'] ?? 0)
            );
            if ($credited > 0) {
                $batchId = $credited;
            }
        } else {
            $sellable = invWmsProductSellableQty($conn, $productId, $currentStock, $product['expiry_date'] ?? null);
            if ($sellable < $quantity) {
                throw new RuntimeException('insufficient_sellable');
            }
            if (!invWmsAdjustLocationStock($conn, $productId, $locId, -$quantity)) {
                throw new RuntimeException('insufficient_location');
            }
            $consumed = invWmsConsumeFefoBatches($conn, $productId, $quantity);
            if ($consumed === null) {
                throw new RuntimeException('insufficient_batch');
            }
            if (!empty($consumed)) {
                $batchId = (int)$consumed[0]['batch_id'];
                $fefoInfo = $consumed;
            }
        }

        $pRes = $conn->query("SELECT quantity_in_stock, status FROM products WHERE id=$productId")->fetch_assoc();
        $newStock = (int)($pRes['quantity_in_stock'] ?? 0);
        $newStatus = $pRes['status'] ?? 'Active';

        $unitPrice = (float)($product['unit_price'] ?? 0);
        $totalVal  = $unitPrice * $quantity;
        $resolvedUnit = $qtyUnit ?: ($product['unit_of_measure'] ?? 'piece');
        $movSource    = 'scanner';
        $fromLoc = $route === 'stock-out' ? $locId : null;
        $toLoc = $route === 'stock-in' ? $locId : null;
        $batchId = $batchId ? (int)$batchId : null;
        $mov = $conn->prepare("
            INSERT INTO stock_movements
                (product_id, batch_id, movement_type, quantity, unit_price, total_value,
                 from_location_id, to_location_id,
                 reference_number, user_id, notes,
                 po_number, si_number, dr_number, return_slf, charge_slf,
                 quantity_unit, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $mov->bind_param(
            'iisiddiiisssssssss',
            $productId, $batchId, $movementType, $quantity, $unitPrice, $totalVal,
            $fromLoc, $toLoc,
            $refNumber, $userId, $notes,
            $poNumber, $siNumber, $drNumber, $returnSlf, $chargeSlf,
            $resolvedUnit, $movSource
        );
        $mov->execute();
        $mov->close();

        $actionLog = $route === 'stock-in' ? 'stock_in' : 'stock_out';
        logScan($conn, $userId, $deviceUsed, $barcode, $actionLog, $productId, $notes, 'existing_product');
        $conn->commit();

        $unitLabel = $resolvedUnit !== '' ? $resolvedUnit : 'pcs';
        $payload = [
            'success' => true,
            'title'   => 'Stock Updated Successfully',
            'message' => 'Stock Updated Successfully',
            'data' => [
                'new_stock' => $newStock,
                'previous_stock' => $currentStock,
                'quantity_added' => $route === 'stock-in' ? $quantity : 0,
                'quantity' => $quantity,
                'unit' => $unitLabel,
                'status'    => $newStatus,
                'location_id' => $locId,
                'batch_id' => $batchId,
                'batch_number' => $batchNumber,
                'fefo' => $fefoInfo,
                'product'   => [
                    'id' => $productId,
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'unit_of_measure' => $unitLabel,
                ],
            ],
        ];
        if ($requestId !== '') {
            scannerIdempotencyPut($conn, $requestId, $userId, $productId, $route, $payload);
        }
        respond($payload);
    } catch (Throwable $e) {
        $conn->rollback();
        $map = [
            'insufficient_sellable' => 'Insufficient sellable stock. Expired units cannot be issued.',
            'insufficient_location' => 'Insufficient stock at this location.',
            'insufficient_batch' => 'Insufficient batch quantity.',
            'stock_adjust' => 'Unable to update inventory. Please try again.',
        ];
        $key = $e->getMessage();
        respond(['success' => false, 'message' => $map[$key] ?? scannerSafeError()], 500);
    }
}

/* ═══════════════════════════════════════════════════════════════
   POST /register-product  (also /add-product for backward compat)
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'POST' && ($route === 'register-product' || $route === 'add-product')) {
    if (!canManageInventory($conn, $role)) {
        respond(['success' => false, 'message' => 'You do not have permission to register products'], 403);
    }

    /* ── Parse all fields ── */
    $barcode             = trim((string)($_POST['barcode']              ?? ''));
    $name                = trim((string)($_POST['name']                 ?? ''));
    $brand               = trim((string)($_POST['brand']                ?? ''));
    $sku                 = trim((string)($_POST['sku']                  ?? ''));
    $description         = trim((string)($_POST['description']          ?? ''));
    $unitOfMeasure       = trim((string)($_POST['unit_of_measure']      ?? 'piece')) ?: 'piece';
    $quantity            = (int)($_POST['quantity']                     ?? 0);
    $reorderLevel        = (int)($_POST['reorder_level']                ?? 50);
    $costPrice           = (float)($_POST['cost_price']                 ?? 0);
    $sellingPrice        = (float)($_POST['selling_price']              ?? 0);
    $unitPrice           = $sellingPrice > 0 ? $sellingPrice : $costPrice;
    $categoryId          = !empty($_POST['category_id'])   ? (int)$_POST['category_id']   : null;
    $supplierId          = !empty($_POST['supplier_id'])   ? (int)$_POST['supplier_id']   : null;
    $supplierProductCode = trim((string)($_POST['supplier_product_code'] ?? ''));
    $batchNumber         = trim((string)($_POST['batch_number']          ?? ''));
    $expiryDate          = trim((string)($_POST['expiry_date']           ?? '')) ?: null;
    $locationId          = !empty($_POST['location_id'])   ? (int)$_POST['location_id']   : null;
    $notes               = trim((string)($_POST['notes']                 ?? ''));

    /* ── Validate required fields ── */
    if ($barcode === '' || $name === '') {
        respond(['success' => false, 'message' => 'Barcode and product name are required'], 422);
    }

    /* ── Auto-generate SKU if blank ── */
    if ($sku === '') {
        $sku = 'BRC-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $barcode));
    }

    /* ── Check barcode uniqueness ── */
    $existing = getProductByCode($conn, $barcode);
    if ($existing) {
        respond([
            'success' => false,
            'message' => 'A product with this barcode already exists (SKU: ' . $existing['sku'] . ')',
        ], 409);
    }

    /* ── Ensure SKU uniqueness ── */
    $chkSku = $conn->prepare("SELECT id FROM products WHERE sku = ?");
    $chkSku->bind_param('s', $sku);
    $chkSku->execute();
    if ($chkSku->get_result()->fetch_assoc()) {
        $sku .= '-' . date('His');
    }
    $chkSku->close();

    $status = $quantity <= 0 ? 'Out of Stock'
        : ($quantity <= $reorderLevel ? 'Low Stock' : 'Active');

    /* ── INSERT product ──
       Type string: ssssssiiidddiissis  (17 params)
       s×6 sku,name,barcode,brand,description,unit_of_measure
       i×2 quantity,reorder_level
       d×3 cost_price,selling_price,unit_price
       i×2 category_id,supplier_id
       s×2 supplier_product_code,expiry_date
       i×1 location_id
       s×1 status
    */
    $stmt = $conn->prepare("
        INSERT INTO products
            (sku, name, barcode, brand, description, unit_of_measure,
             quantity_in_stock, reorder_level, cost_price, selling_price, unit_price,
             category_id, supplier_id, supplier_product_code, expiry_date, location_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'ssssssiidddiissis',
        $sku, $name, $barcode, $brand, $description, $unitOfMeasure,
        $quantity, $reorderLevel, $costPrice, $sellingPrice, $unitPrice,
        $categoryId, $supplierId, $supplierProductCode, $expiryDate, $locationId, $status
    );

    if (!$stmt->execute()) {
        $err = $conn->error;
        $stmt->close();
        respond(['success' => false, 'message' => scannerSafeError()], 500);
    }

    $newProductId = (int)$conn->insert_id;
    $stmt->close();

    /* ── Initial stock movement ── */
    if ($quantity > 0) {
        $refNumber    = 'REG-IN-' . date('Ymd-His');
        $movementType = 'Stock In (Receipt)';
        $totalVal     = $costPrice * $quantity;
        $mov = $conn->prepare("
            INSERT INTO stock_movements
                (product_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $mov->bind_param('isiiddis',
            $newProductId, $movementType, $quantity, $costPrice, $totalVal, $refNumber, $userId, $notes);
        $mov->execute();
        $mov->close();
    }

    /* ── Create product batch record (if batch number provided) ── */
    if ($batchNumber !== '') {
        $batchQty = max(0, $quantity);
        $batch = $conn->prepare("
            INSERT INTO product_batches
                (product_id, batch_number, expiry_date, quantity, quantity_remaining, supplier_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $batch->bind_param('isissis',
            $newProductId, $batchNumber, $expiryDate, $batchQty, $batchQty, $supplierId, $notes);
        $batch->execute();
        $batch->close();
    }

    /* ── Log the registration ── */
    logScan($conn, $userId, 'scanner', $barcode, 'product_registered',
            $newProductId, 'Product registered from scanner', 'new_product_registered');

    /* ── Return full product data ── */
    $newProduct = getProductByCode($conn, $barcode);

    respond(['success' => true, 'message' => 'Product registered successfully', 'data' => [
        'id'                => $newProductId,
        'sku'               => $sku,
        'name'              => $name,
        'brand'             => $brand,
        'barcode'           => $barcode,
        'quantity_in_stock' => $quantity,
        'unit_price'        => $unitPrice,
        'cost_price'        => $costPrice,
        'selling_price'     => $sellingPrice,
        'reorder_level'     => $reorderLevel,
        'unit_of_measure'   => $unitOfMeasure,
        'expiry_date'       => $expiryDate,
        'status'            => $status,
        'location'          => $newProduct['location']      ?? 'Not assigned',
        'category_name'     => $newProduct['category_name'] ?? '',
        'supplier_name'     => $newProduct['supplier_name'] ?? '',
    ]]);
}

/* ═══════════════════════════════════════════════════════════════
   POST /update-product
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'POST' && $route === 'update-product') {
    if (!canManageInventory($conn, $role)) {
        respond(['success' => false, 'message' => 'You do not have permission to edit products'], 403);
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    $name      = trim((string)($_POST['name']       ?? ''));
    $sku       = trim((string)($_POST['sku']        ?? ''));
    $barcode   = trim((string)($_POST['barcode']    ?? ''));
    $location  = trim((string)($_POST['location']   ?? ''));
    $unitPrice = (float)($_POST['unit_price']        ?? 0);

    if ($productId <= 0 || $name === '' || $sku === '') {
        respond(['success' => false, 'message' => 'Product name and SKU are required'], 422);
    }

    $stmt = $conn->prepare("UPDATE products SET name=?, sku=?, barcode=?, location=?, unit_price=? WHERE id=?");
    $stmt->bind_param('ssssdi', $name, $sku, $barcode, $location, $unitPrice, $productId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) respond(['success' => false, 'message' => 'Unable to update product'], 500);

    logScan($conn, $userId, 'scanner', $barcode ?: $sku, 'product_updated',
            $productId, 'Product edited from scanner', 'existing_product');
    respond(['success' => true, 'message' => 'Product updated successfully']);
}

/* ═══════════════════════════════════════════════════════════════
   POST /delete-product
   ═══════════════════════════════════════════════════════════════ */
if ($method === 'POST' && $route === 'delete-product') {
    if (!in_array(strtolower(trim($role)), ['administrator', 'admin'], true)) {
        respond(['success' => false, 'message' => 'Only administrators can delete products'], 403);
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) respond(['success' => false, 'message' => 'Product ID is required'], 422);

    $stmt = $conn->prepare("UPDATE products SET status='Discontinued' WHERE id=?");
    $stmt->bind_param('i', $productId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) respond(['success' => false, 'message' => 'Unable to delete product'], 500);

    logScan($conn, $userId, 'scanner', 'deleted', 'product_deleted',
            $productId, 'Product deleted from scanner', 'existing_product');
    respond(['success' => true, 'message' => 'Product deleted successfully']);
}

/* ═══════════════════════════════════════════════════════════════
   FALLBACK
   ═══════════════════════════════════════════════════════════════ */
respond(['success' => false, 'message' => 'Route not found: ' . $route], 404);
$conn->close();
