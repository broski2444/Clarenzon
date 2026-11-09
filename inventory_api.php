<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
header('Content-Type: application/json');
clarenzon_api_require();
require_once 'warehouse_permissions.php';
require_once __DIR__ . '/inventory_wms_lib.php';
require_once __DIR__ . '/inventory_condition_lib.php';
require_once __DIR__ . '/product_pricing_lib.php';
require_once __DIR__ . '/product_expiry_lib.php';
require_once __DIR__ . '/product_barcodes_lib.php';

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'inventory_db', 'clarenzon_inventory'], 'root', '', 3);

$action = $_GET['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? '';
if (function_exists('clarenzon_api_guard_method')) {
    clarenzon_api_guard_method((string)$action);
}
$uid    = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? '');
$isWarehouseHead = isWarehouseHeadRole($role);
$isSystemAdmin = in_array(strtolower(trim($role)), ['administrator', 'admin'], true);
$isPurchaserRole = strtolower(trim($role)) === 'purchaser';
$canManageWms = $isSystemAdmin || $isWarehouseHead;
// Cashier may look up products for sales, but never change inventory directly.
if (normalize_user_role($role) === 'cashier' && function_exists('clarenzon_api_action_is_read') && !clarenzon_api_action_is_read((string)$action)) {
    clarenzon_reject_security(403, 'Cashier accounts cannot modify inventory.', true);
}

ob_start();

function jOut($d) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($d);
    exit();
}

if (!$conn || $conn->connect_error) {
    jOut(['success' => false, 'message' => 'An error occurred while processing your request.']);
}

function ensureInventoryApiSchema(mysqli $conn): void {
    $columns = [
        'po_number' => 'VARCHAR(100) NULL',
        'si_number' => 'VARCHAR(100) NULL',
        'dr_number' => 'VARCHAR(100) NULL',
        'return_slf' => 'VARCHAR(100) NULL',
        'charge_slf' => 'VARCHAR(100) NULL',
        'quantity_unit' => 'VARCHAR(50) NULL',
        'source' => 'VARCHAR(50) NULL DEFAULT "manual"',
    ];

    foreach ($columns as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM stock_movements LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE stock_movements ADD COLUMN `$column` $definition");
        }
    }

    foreach ([
        'lead_time_days' => 'INT NOT NULL DEFAULT 7 AFTER payment_terms',
        'product_lines' => 'TEXT NULL AFTER lead_time_days',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM suppliers LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE suppliers ADD COLUMN `$column` $definition");
        }
    }
}

ensureInventoryApiSchema($conn);
invWmsEnsureSchema($conn);
invStockEnsureSchema($conn);
ensureProductPricingSchema($conn);
productBarcodesEnsureSchema($conn);
if (function_exists('productExpiryDisposeExpired')) {
    productExpiryDisposeExpired($conn, $uid);
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
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
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
    if ($mime !== '' && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $error = 'Product image type is not supported.';
        return null;
    }
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'product_images';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        $error = 'Product image folder could not be created.';
        return null;
    }
    $safeName = 'product_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    if (!move_uploaded_file($tmpName, $uploadDir . DIRECTORY_SEPARATOR . $safeName)) {
        $error = 'Product image could not be saved.';
        return null;
    }
    return 'uploads/product_images/' . $safeName;
}

function deleteProductImageIfUnused(mysqli $conn, string $imagePath, string $imageColumn, int $excludeId = 0): void {
    $imagePath = trim($imagePath);
    if ($imagePath === '' || strpos($imagePath, 'uploads/product_images/') !== 0) {
        return;
    }
    $sql = "SELECT COUNT(*) AS used_count FROM products WHERE `$imageColumn` = ?";
    if ($excludeId > 0) {
        $sql .= " AND id <> ?";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    if ($excludeId > 0) {
        $stmt->bind_param('si', $imagePath, $excludeId);
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

$productImageColumn = ensureProductImageSchema($conn);

function inventoryOverviewHasTable(mysqli $conn, string $table): bool
{
    $t = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return (bool)($t && $t->num_rows);
}

function inventoryOverviewQtyMap(mysqli $conn, string $sql, string $idCol = 'product_id', string $qtyCol = 'qty'): array
{
    $map = [];
    $res = @$conn->query($sql);
    if (!$res) {
        return $map;
    }
    while ($row = $res->fetch_assoc()) {
        $pid = (int)($row[$idCol] ?? 0);
        if ($pid > 0) {
            $map[$pid] = (int)($row[$qtyCol] ?? 0);
        }
    }
    return $map;
}

function inventoryOverviewLoad(mysqli $conn, array $filters = []): array
{
    $soldMap = [];
    if (inventoryOverviewHasTable($conn, 'sales_order_items') && inventoryOverviewHasTable($conn, 'sales_orders')) {
        $soldMap = inventoryOverviewQtyMap(
            $conn,
            "SELECT soi.product_id, COALESCE(SUM(soi.quantity),0) AS qty
             FROM sales_order_items soi
             INNER JOIN sales_orders so ON so.id = soi.sales_order_id
             WHERE so.status = 'Approved'
             GROUP BY soi.product_id"
        );
    } elseif (inventoryOverviewHasTable($conn, 'invoice_items') && inventoryOverviewHasTable($conn, 'invoices')) {
        $soldMap = inventoryOverviewQtyMap(
            $conn,
            "SELECT ii.product_id, COALESCE(SUM(ii.quantity),0) AS qty
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id
             GROUP BY ii.product_id"
        );
    }

    $inMap = [];
    $outMap = [];
    if (inventoryOverviewHasTable($conn, 'stock_movements')) {
        $res = @$conn->query("SELECT product_id, movement_type, COALESCE(SUM(quantity),0) AS qty
                              FROM stock_movements
                              GROUP BY product_id, movement_type");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $pid = (int)$row['product_id'];
                $type = strtolower(trim((string)$row['movement_type']));
                $qty = (int)$row['qty'];
                $isIn = (strpos($type, 'stock in') !== false || $type === 'return');
                $isOut = (strpos($type, 'stock out') !== false || $type === 'damage' || $type === 'expiry');
                if ($isIn) {
                    $inMap[$pid] = ($inMap[$pid] ?? 0) + $qty;
                } elseif ($isOut) {
                    $outMap[$pid] = ($outMap[$pid] ?? 0) + $qty;
                }
            }
        }
    }

    $sellableMap = function_exists('invWmsSellableQtyMap') ? invWmsSellableQtyMap($conn) : [];
    $rows = [];
    $summary = [
        'total_products' => 0,
        'total_stock' => 0,
        'total_sold' => 0,
        'low_stock' => 0,
        'out_of_stock' => 0,
    ];
    $search = trim((string)($filters['search'] ?? ''));
    $categoryFilter = trim((string)($filters['category'] ?? ''));
    $statusFilter = trim((string)($filters['status'] ?? ''));
    $sql = "SELECT p.id, p.sku, p.name, p.quantity_in_stock, p.reorder_level, p.status AS product_status,
                   p.unit_of_measure, p.brand, p.barcode,
                   c.name AS category_name, pc.name AS parent_category,
                   s.name AS supplier_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN categories pc ON pc.id = c.parent_id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.status <> 'Discontinued'";
    $params = [];
    $types = '';
    if ($categoryFilter !== '') {
        $sql .= " AND (c.name = ? OR pc.name = ?)";
        $params[] = $categoryFilter;
        $params[] = $categoryFilter;
        $types .= 'ss';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $searchSql = "p.name LIKE ? OR p.sku LIKE ? OR IFNULL(p.brand,'') LIKE ? OR IFNULL(p.barcode,'') LIKE ?
            OR IFNULL(c.name,'') LIKE ? OR IFNULL(pc.name,'') LIKE ? OR IFNULL(s.name,'') LIKE ?";
        $searchTypes = 'sssssss';
        $searchParams = array_fill(0, 7, $like);
        if (inventoryOverviewHasTable($conn, 'product_batches')) {
            $searchSql .= " OR EXISTS (SELECT 1 FROM product_batches pb WHERE pb.product_id = p.id AND pb.batch_number LIKE ?)";
            $searchTypes .= 's';
            $searchParams[] = $like;
        }
        if (inventoryOverviewHasTable($conn, 'product_barcodes')) {
            $searchSql .= " OR EXISTS (SELECT 1 FROM product_barcodes pbc WHERE pbc.product_id = p.id AND pbc.barcode_value LIKE ?)";
            $searchTypes .= 's';
            $searchParams[] = $like;
        }
        $sql .= ' AND (' . $searchSql . ')';
        $types .= $searchTypes;
        $params = array_merge($params, $searchParams);
    }
    $sql .= ' ORDER BY p.name ASC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['data' => [], 'summary' => $summary, 'categories' => []];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        return ['data' => [], 'summary' => $summary, 'categories' => []];
    }
    $categories = [];
    while ($r = $res->fetch_assoc()) {
        $pid = (int)$r['id'];
        $current = max(0, (int)$r['quantity_in_stock']);
        $available = (int)($sellableMap[$pid] ?? $current);
        $reorder = (int)($r['reorder_level'] ?? 0);
        $sold = (int)($soldMap[$pid] ?? 0);
        $stockIn = (int)($inMap[$pid] ?? 0);
        $stockOut = (int)($outMap[$pid] ?? 0);
        if ($available <= 0) {
            $stockStatus = 'Out of Stock';
        } elseif (function_exists('invWmsIsLowStock') ? invWmsIsLowStock($available, $reorder) : ($available <= $reorder)) {
            $stockStatus = 'Low Stock';
        } else {
            $stockStatus = 'In Stock';
        }
        $cat = trim((string)($r['category_name'] ?? ''));
        if ($cat !== '' && $categoryFilter === '') {
            $categories[$cat] = true;
        }
        if ($statusFilter !== '' && $stockStatus !== $statusFilter) {
            continue;
        }
        $rows[] = [
            'id' => $pid,
            'sku' => (string)$r['sku'],
            'name' => (string)$r['name'],
            'brand' => (string)($r['brand'] ?? ''),
            'barcode' => (string)($r['barcode'] ?? ''),
            'category' => $cat,
            'parent_category' => (string)($r['parent_category'] ?? ''),
            'uom' => (string)($r['unit_of_measure'] ?? ''),
            'current_stock' => $current,
            'available_stock' => $available,
            'total_sold' => $sold,
            'stock_in' => $stockIn,
            'stock_out' => $stockOut,
            'reorder_level' => $reorder,
            'stock_status' => $stockStatus,
        ];
        $summary['total_products']++;
        $summary['total_stock'] += $current;
        $summary['total_sold'] += $sold;
        if ($stockStatus === 'Out of Stock') {
            $summary['out_of_stock']++;
        } elseif ($stockStatus === 'Low Stock') {
            $summary['low_stock']++;
        }
    }
    $stmt->close();
    if ($rows && inventoryOverviewHasTable($conn, 'product_batches')) {
        $idList = implode(',', array_map(static function ($row) {
            return (int)$row['id'];
        }, $rows));
        $hasMfr = false;
        $mfrCol = @$conn->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturer_lot'");
        if ($mfrCol && $mfrCol->num_rows > 0) {
            $hasMfr = true;
        }
        $mfrSql = $hasMfr ? "COALESCE(pb.manufacturer_lot, '')" : "''";
        $lotOrder = ['pb.product_id'];
        $receivedCol = @$conn->query("SHOW COLUMNS FROM product_batches LIKE 'received_date'");
        if ($receivedCol && $receivedCol->num_rows > 0) {
            $lotOrder[] = 'pb.received_date DESC';
        }
        $createdCol = @$conn->query("SHOW COLUMNS FROM product_batches LIKE 'created_at'");
        if ($createdCol && $createdCol->num_rows > 0) {
            $lotOrder[] = 'pb.created_at DESC';
        }
        $lotOrder[] = 'pb.id DESC';
        $lotOrderSql = implode(', ', $lotOrder);
        $lotRes = @$conn->query("SELECT pb.product_id, pb.batch_number, $mfrSql AS manufacturer_lot, pb.expiry_date, pb.quantity, pb.quantity_remaining
            FROM product_batches pb
            WHERE pb.product_id IN ($idList)
            ORDER BY $lotOrderSql");
        $lotMap = [];
        if ($lotRes) {
            while ($lot = $lotRes->fetch_assoc()) {
                $pid = (int)$lot['product_id'];
                $lotMap[$pid][] = [
                    'batch_number' => (string)($lot['batch_number'] ?? ''),
                    'manufacturer_lot' => (string)($lot['manufacturer_lot'] ?? ''),
                    'expiry_date' => $lot['expiry_date'] ? (string)$lot['expiry_date'] : '',
                    'quantity' => (int)($lot['quantity_remaining'] ?? 0),
                ];
            }
        }
        foreach ($rows as $idx => $row) {
            $rows[$idx]['lots'] = $lotMap[(int)$row['id']] ?? [];
        }
    }
    $catList = array_keys($categories);
    natcasesort($catList);
    return ['data' => $rows, 'summary' => $summary, 'categories' => array_values($catList)];
}

if ($isWarehouseHead) {
    $blockedWarehouseHeadActions = [
        'add_product',
        'delete_product',
        'add_movement',
        'add_batch',
        'legacy_add_product',
        'legacy_delete_product',
        'process_stock_return',
        'process_product_replacement',
        'record_stock_condition',
    ];
    if (in_array($action, $blockedWarehouseHeadActions, true)) {
        jOut(['success' => false, 'message' => 'Warehouse Head access is view-only for this action.']);
    }
}

switch ($action) {

    // ─── PRODUCTS ─────────────────────────────────────────────────────────────
    case 'get_products':
        $where  = ["p.status != 'Discontinued'"];
        $params = [];
        $types  = '';
        if (!empty($_GET['category_id'])) {
            $where[] = 'p.category_id = ?'; $params[] = (int)$_GET['category_id']; $types .= 'i';
        }
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $where[] = 'p.status = ?'; $params[] = $_GET['status']; $types .= 's';
        }
        if (!empty($_GET['search'])) {
            $searchParts = [productBarcodesSearchSql('p')];
            $s = '%' . $_GET['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $types .= 'sssss';
            $searchParts[] = "EXISTS (SELECT 1 FROM categories csearch WHERE csearch.id = p.category_id AND csearch.name LIKE ?)";
            $searchParts[] = "EXISTS (SELECT 1 FROM categories pcsearch WHERE pcsearch.id = (SELECT parent_id FROM categories WHERE id = p.category_id) AND pcsearch.name LIKE ?)";
            $searchParts[] = "EXISTS (SELECT 1 FROM suppliers ssearch WHERE ssearch.id = p.supplier_id AND ssearch.name LIKE ?)";
            $params[] = $s; $params[] = $s; $params[] = $s;
            $types .= 'sss';
            $batchTable = $conn->query("SHOW TABLES LIKE 'product_batches'");
            if ($batchTable && $batchTable->num_rows > 0) {
                $searchParts[] = "EXISTS (SELECT 1 FROM product_batches pbsearch WHERE pbsearch.product_id = p.id AND pbsearch.batch_number LIKE ?)";
                $params[] = $s;
                $types .= 's';
            }
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
        $wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $imageColumn = getProductImageColumn($conn);
        $imageSelect = $imageColumn ? ", p.`" . str_replace('`', '``', $imageColumn) . "` AS product_image" : ", '' AS product_image";
        $sql  = "SELECT p.*{$imageSelect}, c.name AS category_name, s.name AS supplier_name,
                        sl.storage_area, sl.shelf_rack, sl.section
                 FROM products p
                 LEFT JOIN categories c ON p.category_id = c.id
                 LEFT JOIN suppliers s ON p.supplier_id = s.id
                 LEFT JOIN storage_locations sl ON p.location_id = sl.id
                 $wSQL ORDER BY p.id DESC, p.sku";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        $sellableMap = invWmsSellableQtyMap($conn);
        while ($r = $res->fetch_assoc()) {
            $pid = (int)($r['id'] ?? 0);
            $onHand = (int)($r['quantity_in_stock'] ?? 0);
            $sellable = (int)($sellableMap[$pid] ?? $onHand);
            $r['sellable_qty'] = $sellable;
            $r['expired_qty'] = max(0, $onHand - $sellable);
            $rows[] = $r;
        }
        $rows = productBarcodesHydrateRows($conn, $rows, 'id');
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'add_product':
        $f   = $_POST;
        $sku = trim($f['sku'] ?? '');
        $name = trim($f['name'] ?? '');
        if ($sku === '' || $name === '') {
            jOut(['success' => false, 'message' => 'SKU and product name are required.']);
        }
        $barcode = trim($f['barcode'] ?? '') ?: null;
        $brand = trim($f['brand'] ?? '');
        $size = trim($f['size'] ?? '');
        $packaging = trim($f['packaging'] ?? '');
        $description = trim($f['description'] ?? '');
        $unitOfMeasure = trim($f['unit_of_measure'] ?? '') ?: 'piece';
        $manufacturer = trim($f['manufacturer'] ?? '');
        $notes = trim($f['notes'] ?? '');
        $ed  = !empty($f['expiry_date'])      ? $f['expiry_date']      : null;
        $lid = !empty($f['location_id'])       ? (int)$f['location_id'] : null;
        $cid = !empty($f['category_id'])       ? (int)$f['category_id'] : null;
        $sid = !empty($f['supplier_id'])       ? (int)$f['supplier_id'] : null;
        $ml  = !empty($f['max_stock_level'])   ? (int)$f['max_stock_level'] : null;
        $bt  = !empty($f['batch_tracking'])    ? 1 : 0;
        $et  = !empty($f['expiry_tracking'])   ? 1 : 0;
        $qty = (int)($f['quantity_in_stock'] ?? 0);
        $rl  = (int)($f['reorder_level']     ?? 50);
        $rq  = (int)($f['reorder_quantity']  ?? 100);
        $up  = (float)($f['unit_price']       ?? 0);
        $cp  = (float)($f['cost_price']       ?? 0);
        $sp  = (float)($f['selling_price']    ?? 0);
        $st  = $qty <= 0 ? 'Out of Stock' : ($qty <= $rl ? 'Low Stock' : 'Active');
        $uploadError = '';
        $newImagePath = $productImageColumn ? handleProductImageUpload('product_image', $uploadError) : null;
        if ($uploadError !== '') {
            jOut(['success' => false, 'message' => $uploadError]);
        }
        $sql = "INSERT INTO products (sku,name,barcode,brand,size,packaging,description,category_id,supplier_id,
                    unit_price,cost_price,selling_price,quantity_in_stock,reorder_level,reorder_quantity,
                    max_stock_level,unit_of_measure,manufacturer,batch_tracking,expiry_tracking,
                    expiry_date,location_id,status,notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            if ($newImagePath !== null && $productImageColumn) {
                deleteProductImageIfUnused($conn, $newImagePath, $productImageColumn);
            }
            jOut(['success' => false, 'message' => $conn->error]);
        }
        $stmt->bind_param('sssssssiidddiiiissiisiss',
            $sku, $name, $barcode, $brand, $size, $packaging, $description, $cid, $sid,
            $up, $cp, $sp, $qty, $rl, $rq, $ml, $unitOfMeasure, $manufacturer, $bt, $et,
            $ed, $lid, $st, $notes);
        if ($stmt->execute()) {
            $newId = (int)$conn->insert_id;
            if ($productImageColumn && $newImagePath !== null) {
                $imageStmt = $conn->prepare("UPDATE products SET `$productImageColumn` = ? WHERE id = ?");
                if ($imageStmt) {
                    $imageStmt->bind_param('si', $newImagePath, $newId);
                    if (!$imageStmt->execute()) {
                        deleteProductImageIfUnused($conn, $newImagePath, $productImageColumn);
                        jOut(['success' => false, 'message' => $conn->error]);
                    }
                    $imageStmt->close();
                } else {
                    deleteProductImageIfUnused($conn, $newImagePath, $productImageColumn);
                    jOut(['success' => false, 'message' => $conn->error]);
                }
            }
            if ($barcode) {
                productBarcodesRegister($conn, $newId, (string)$barcode, [
                    'source' => 'manual',
                    'registered_by' => $uid,
                    'is_primary' => true,
                ]);
            }
            jOut(['success' => true, 'id' => $newId, 'sku' => $sku]);
        } else {
            if ($newImagePath !== null && $productImageColumn) {
                deleteProductImageIfUnused($conn, $newImagePath, $productImageColumn);
            }
            jOut(['success' => false, 'message' => $conn->error]);
        }
        break;

    case 'update_product':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jOut(['success' => false, 'message' => 'Missing product ID']);
        if ($isWarehouseHead && !$isSystemAdmin) {
            $sets = [];
            $prm = [];
            $typ = '';
            if (array_key_exists('location_id', $_POST)) {
                $sets[] = 'location_id=?';
                $prm[] = ($_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null);
                $typ .= 'i';
            }
            if (array_key_exists('expiry_date', $_POST)) {
                $sets[] = 'expiry_date=?';
                $prm[] = $_POST['expiry_date'] ?: null;
                $typ .= 's';
            }
            if (!$sets) {
                jOut(['success' => false, 'message' => 'Warehouse Head can only update warehouse product fields.']);
            }
            $prm[] = $id;
            $typ .= 'i';
            $stmt = $conn->prepare("UPDATE products SET " . implode(',', $sets) . " WHERE id=?");
            if (!$stmt) jOut(['success' => false, 'message' => $conn->error]);
            $stmt->bind_param($typ, ...$prm);
            if ($stmt->execute()) jOut(['success' => true]);
            jOut(['success' => false, 'message' => $conn->error]);
        }
        $f    = $_POST;
        if (array_key_exists('selling_price', $f) || array_key_exists('markup_percent', $f)) {
            if (!userCanManageProductPricing($conn, $uid, $role)) {
                jOut(['success' => false, 'message' => 'Not authorized to modify selling price / markup']);
            }
        }
        $sets = []; $prm = []; $typ = '';
        foreach (['name','brand','size','packaging','description','unit_of_measure','manufacturer','notes','status'] as $field) {
            if (array_key_exists($field, $f)) { $sets[] = "$field=?"; $prm[] = $f[$field]; $typ .= 's'; }
        }
        foreach (['category_id','supplier_id','location_id','quantity_in_stock','reorder_level','reorder_quantity','max_stock_level'] as $field) {
            if (array_key_exists($field, $f)) { $sets[] = "$field=?"; $prm[] = ($f[$field] !== '' ? (int)$f[$field] : null); $typ .= 'i'; }
        }
        foreach (['unit_price','cost_price','selling_price','markup_percent'] as $field) {
            if (array_key_exists($field, $f)) { $sets[] = "$field=?"; $prm[] = (float)$f[$field]; $typ .= 'd'; }
        }
        if (array_key_exists('selling_price', $f) && userCanManageProductPricing($conn, $uid, $role)) {
            $sets[] = 'selling_price_updated_at=NOW()';
            $sets[] = 'selling_price_updated_by=?';
            $prm[] = $uid;
            $typ .= 'i';
        }
        if (array_key_exists('expiry_date', $f)) {
            $sets[] = 'expiry_date=?'; $prm[] = $f['expiry_date'] ?: null; $typ .= 's';
        }
        $oldImagePath = '';
        $newImagePath = null;
        if ($productImageColumn) {
            $oldImageStmt = $conn->prepare("SELECT `$productImageColumn` AS product_image FROM products WHERE id = ? LIMIT 1");
            if ($oldImageStmt) {
                $oldImageStmt->bind_param('i', $id);
                $oldImageStmt->execute();
                $oldImagePath = (string)($oldImageStmt->get_result()->fetch_assoc()['product_image'] ?? '');
                $oldImageStmt->close();
            }
            $uploadError = '';
            $newImagePath = handleProductImageUpload('product_image', $uploadError);
            if ($uploadError !== '') {
                jOut(['success' => false, 'message' => $uploadError]);
            }
            if ($newImagePath !== null) {
                $sets[] = "`$productImageColumn`=?";
                $prm[] = $newImagePath;
                $typ .= 's';
            }
        }
        $sets[] = 'batch_tracking=?';  $prm[] = !empty($f['batch_tracking'])  ? 1 : 0; $typ .= 'i';
        $sets[] = 'expiry_tracking=?'; $prm[] = !empty($f['expiry_tracking']) ? 1 : 0; $typ .= 'i';
        if (!$sets) jOut(['success' => false, 'message' => 'No update data']);
        $prm[] = $id; $typ .= 'i';
        $stmt = $conn->prepare("UPDATE products SET " . implode(',', $sets) . " WHERE id=?");
        $stmt->bind_param($typ, ...$prm);
        if ($stmt->execute()) {
            if ($productImageColumn && $newImagePath !== null) {
                deleteProductImageIfUnused($conn, $oldImagePath, $productImageColumn, $id);
            }
            jOut(['success' => true]);
        } else {
            if ($productImageColumn && $newImagePath !== null) {
                deleteProductImageIfUnused($conn, $newImagePath, $productImageColumn);
            }
            jOut(['success' => false, 'message' => $conn->error]);
        }
        break;

    case 'delete_product':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE products SET status='Discontinued' WHERE id=?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) jOut(['success' => true]);
        else jOut(['success' => false, 'message' => $conn->error]);
        break;

    // ─── STOCK MOVEMENTS ──────────────────────────────────────────────────────
    case 'get_movements':
        $where = []; $params = []; $types = '';
        if (!empty($_GET['product_id']))    { $where[] = 'sm.product_id=?'; $params[] = (int)$_GET['product_id']; $types .= 'i'; }
        if (!empty($_GET['movement_type']) && $_GET['movement_type'] !== 'all') { $where[] = 'sm.movement_type=?'; $params[] = $_GET['movement_type']; $types .= 's'; }
        if (!empty($_GET['date_from']))     { $where[] = 'DATE(sm.transaction_date)>=?'; $params[] = $_GET['date_from']; $types .= 's'; }
        if (!empty($_GET['date_to']))       { $where[] = 'DATE(sm.transaction_date)<=?'; $params[] = $_GET['date_to']; $types .= 's'; }
        $wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql  = "SELECT sm.*,
                        COALESCE(sm.po_number,'')     AS po_number,
                        COALESCE(sm.si_number,'')     AS si_number,
                        COALESCE(sm.dr_number,'')     AS dr_number,
                        COALESCE(sm.return_slf,'')    AS return_slf,
                        COALESCE(sm.charge_slf,'')    AS charge_slf,
                        COALESCE(sm.quantity_unit,'') AS quantity_unit,
                        COALESCE(sm.source,'manual')  AS source,
                        p.name AS product_name, p.sku,
                        u.full_name AS user_name,
                        fl.storage_area AS from_location,
                        tl.storage_area AS to_location
                 FROM stock_movements sm
                 LEFT JOIN products p ON sm.product_id = p.id
                 LEFT JOIN users u ON sm.user_id = u.id
                 LEFT JOIN storage_locations fl ON sm.from_location_id = fl.id
                 LEFT JOIN storage_locations tl ON sm.to_location_id = tl.id
                 $wSQL ORDER BY sm.transaction_date DESC LIMIT 300";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_transaction_history':
        if ($isPurchaserRole || (!$isSystemAdmin && !$isWarehouseHead)) {
            jOut(['success' => false, 'message' => 'You are not authorized to view Transaction History.']);
        }
        $txnId = (int)($_GET['id'] ?? 0);
        $searchQ = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
        $typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
        if (!in_array($typeFilter, ['all', 'stock-in', 'stock-out'], true)) {
            $typeFilter = 'all';
        }
        $fromDate = trim((string)($_GET['from'] ?? ''));
        $toDate = trim((string)($_GET['to'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = '';

        $dateExpr = 'sm.created_at';
        $dateCol = @$conn->query("SHOW COLUMNS FROM stock_movements LIKE 'transaction_date'");
        if ($dateCol && $dateCol->num_rows > 0) {
            $dateExpr = 'COALESCE(sm.transaction_date, sm.created_at)';
        }
        $userNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'System')";
        $fnCol = @$conn->query("SHOW COLUMNS FROM users LIKE 'first_name'");
        if ($fnCol && $fnCol->num_rows > 0) {
            $userNameExpr = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))), ''), NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'System')";
        }

        $customerExpr = "''";
        $linePriceExpr = "NULL";
        $refMatch = "CONVERT(COALESCE(%s,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(COALESCE(sm.reference_number,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $soTbl = @$conn->query("SHOW TABLES LIKE 'sales_orders'");
        if ($soTbl && $soTbl->num_rows > 0) {
            $soEq = sprintf($refMatch, 'so.order_number');
            $customerExpr = "(SELECT so.customer_name FROM sales_orders so WHERE $soEq LIMIT 1)";
            $soiTbl = @$conn->query("SHOW TABLES LIKE 'sales_order_items'");
            if ($soiTbl && $soiTbl->num_rows > 0) {
                $linePriceExpr = "(SELECT soi.unit_price FROM sales_order_items soi INNER JOIN sales_orders so ON so.id = soi.sales_order_id WHERE $soEq AND soi.product_id = sm.product_id LIMIT 1)";
            }
        }
        $invTbl = @$conn->query("SHOW TABLES LIKE 'invoices'");
        if ($invTbl && $invTbl->num_rows > 0) {
            $invNumCol = @$conn->query("SHOW COLUMNS FROM invoices LIKE 'invoice_number'");
            $invSoCol = @$conn->query("SHOW COLUMNS FROM invoices LIKE 'sales_order_number'");
            $invCustCol = @$conn->query("SHOW COLUMNS FROM invoices LIKE 'customer_name'");
            if ($invCustCol && $invCustCol->num_rows > 0 && $invNumCol && $invNumCol->num_rows > 0) {
                $invWhere = sprintf($refMatch, 'inv.invoice_number');
                if ($invSoCol && $invSoCol->num_rows > 0) {
                    $invWhere .= ' OR ' . sprintf($refMatch, 'inv.sales_order_number');
                }
                $invCust = "(SELECT inv.customer_name FROM invoices inv WHERE $invWhere LIMIT 1)";
                $customerExpr = $customerExpr === "''" ? $invCust : "COALESCE($customerExpr, $invCust)";
            }
        }

        $where = [];
        $params = [];
        $types = '';
        if ($txnId > 0) {
            $where[] = 'sm.id=?';
            $params[] = $txnId;
            $types .= 'i';
        }
        if ($isWarehouseHead && !$isSystemAdmin) {
            $where[] = "(LOWER(COALESCE(sm.movement_type,'')) LIKE '%in%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%out%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%receive%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%issue%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%stock%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%transfer%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%return%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%expir%')";
        }
        if ($typeFilter === 'stock-in') {
            $where[] = "((
                LOWER(COALESCE(sm.movement_type,'')) LIKE '%stock in%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%stock-in%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%receive%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%receipt%'
            ) AND LOWER(COALESCE(sm.movement_type,'')) NOT LIKE '%out%'
              AND LOWER(COALESCE(sm.movement_type,'')) NOT LIKE '%issue%')";
        } elseif ($typeFilter === 'stock-out') {
            $where[] = "(
                LOWER(COALESCE(sm.movement_type,'')) LIKE '%stock out%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%stock-out%'
                OR LOWER(COALESCE(sm.movement_type,'')) LIKE '%issue%'
                OR LOWER(COALESCE(sm.source,'')) = 'sales'
            )";
        }
        if ($fromDate !== '') {
            $where[] = "DATE($dateExpr) >= ?";
            $params[] = $fromDate;
            $types .= 's';
        }
        if ($toDate !== '') {
            $where[] = "DATE($dateExpr) <= ?";
            $params[] = $toDate;
            $types .= 's';
        }
        if ($searchQ !== '') {
            $like = '%' . $searchQ . '%';
            $searchParts = [
                'CAST(sm.id AS CHAR) LIKE ?',
                "COALESCE(sm.reference_number,'') LIKE ?",
                "COALESCE(sm.po_number,'') LIKE ?",
                "COALESCE(sm.si_number,'') LIKE ?",
                "COALESCE(sm.dr_number,'') LIKE ?",
                "COALESCE(p.name,'') LIKE ?",
                "COALESCE(p.sku,'') LIKE ?",
                "COALESCE(u.full_name,'') LIKE ?",
                "COALESCE(u.username,'') LIKE ?",
                "COALESCE($customerExpr,'') LIKE ?",
            ];
            $searchCount = 10;
            if ($fnCol && $fnCol->num_rows > 0) {
                $searchParts[] = "COALESCE(u.first_name,'') LIKE ?";
                $searchParts[] = "COALESCE(u.last_name,'') LIKE ?";
                $searchCount += 2;
            }
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
            for ($i = 0; $i < $searchCount; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }
        $wSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT sm.id,
                       $dateExpr AS transaction_datetime,
                       sm.movement_type,
                       COALESCE(sm.reference_number,'') AS reference_number,
                       COALESCE(sm.po_number,'') AS po_number,
                       COALESCE(sm.si_number,'') AS si_number,
                       COALESCE(sm.dr_number,'') AS dr_number,
                       sm.quantity,
                       COALESCE(sm.quantity_unit,'') AS quantity_unit,
                       sm.unit_price,
                       sm.total_value,
                       COALESCE(sm.notes,'') AS notes,
                       COALESCE(sm.source,'') AS source,
                       p.name AS product_name,
                       p.sku,
                       $userNameExpr AS processed_by,
                       COALESCE(u.role,'') AS processed_by_role,
                       COALESCE($customerExpr,'') AS customer_name,
                       $linePriceExpr AS line_unit_price
                FROM stock_movements sm
                LEFT JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.user_id = u.id
                $wSQL
                ORDER BY $dateExpr DESC, sm.id DESC
                LIMIT 500";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            jOut(['success' => false, 'message' => $conn->error]);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $ref = trim((string)($r['reference_number'] ?? ''));
            if ($ref === '') {
                foreach (['po_number', 'si_number', 'dr_number'] as $alt) {
                    $altVal = trim((string)($r[$alt] ?? ''));
                    if ($altVal !== '') {
                        $ref = $altVal;
                        break;
                    }
                }
            }
            $type = trim((string)($r['movement_type'] ?? ''));
            $source = strtolower(trim((string)($r['source'] ?? '')));
            $typeLower = strtolower($type);
            $isStockOut = ($source === 'sales'
                || strpos($typeLower, 'stock out') !== false
                || strpos($typeLower, 'stock-out') !== false
                || strpos($typeLower, 'issue') !== false);
            $isStockIn = !$isStockOut && (
                strpos($typeLower, 'stock in') !== false
                || strpos($typeLower, 'stock-in') !== false
                || strpos($typeLower, 'receive') !== false
                || strpos($typeLower, 'receipt') !== false
            );
            $customer = trim((string)($r['customer_name'] ?? ''));
            $sales = null;
            $isCustomerOrder = ($customer !== '' || $source === 'sales' || strncasecmp($ref, 'SO-', 3) === 0);
            if ($isStockOut && $isCustomerOrder) {
                $linePrice = $r['line_unit_price'];
                $qty = (int)($r['quantity'] ?? 0);
                if ($linePrice !== null && $linePrice !== '') {
                    $sales = round((float)$linePrice * $qty, 2);
                } elseif ($r['total_value'] !== null && $r['total_value'] !== '') {
                    $sales = round((float)$r['total_value'], 2);
                }
            }
            $rows[] = [
                'id' => (int)$r['id'],
                'transaction_no' => 'TXN-' . str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT),
                'transaction_datetime' => (string)($r['transaction_datetime'] ?? ''),
                'transaction_type' => $type !== '' ? $type : 'Stock Movement',
                'reference_no' => $ref,
                'customer' => $customer,
                'product' => trim((string)($r['product_name'] ?? '')) !== '' ? (string)$r['product_name'] : 'N/A',
                'sku' => (string)($r['sku'] ?? ''),
                'quantity' => (int)($r['quantity'] ?? 0),
                'quantity_unit' => (string)($r['quantity_unit'] ?? ''),
                'sales' => $isStockIn ? null : $sales,
                'unit_price' => $r['unit_price'],
                'total_value' => $r['total_value'],
                'processed_by' => trim((string)($r['processed_by'] ?? '')) !== '' ? (string)$r['processed_by'] : 'System',
                'processed_by_role' => (string)($r['processed_by_role'] ?? ''),
                'status' => 'Completed',
                'notes' => (string)($r['notes'] ?? ''),
                'source' => (string)($r['source'] ?? ''),
                'po_number' => (string)($r['po_number'] ?? ''),
                'si_number' => (string)($r['si_number'] ?? ''),
                'dr_number' => (string)($r['dr_number'] ?? ''),
            ];
        }
        $stmt->close();
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'add_movement':
        $f     = $_POST;
        $pid   = (int)($f['product_id']   ?? 0);
        $mtype = trim($f['movement_type'] ?? '');
        $qty   = (int)($f['quantity']     ?? 0);
        $up    = (float)($f['unit_price'] ?? 0);
        $fl    = !empty($f['from_location_id']) ? (int)$f['from_location_id'] : null;
        $tl    = !empty($f['to_location_id'])   ? (int)$f['to_location_id']   : null;
        $ref   = trim($f['reference_number']    ?? '');
        $notes = trim($f['notes']               ?? '');
        $bid   = !empty($f['batch_id'])          ? (int)$f['batch_id']         : null;
        $total = $qty * $up;
        if (!$pid || !$mtype || $qty <= 0) jOut(['success' => false, 'message' => 'Missing required fields (product, type, qty)']);
        $poNum    = trim($f['po_number']     ?? '')  ?: null;
        $siNum    = trim($f['si_number']     ?? '')  ?: null;
        $drNum    = trim($f['dr_number']     ?? '')  ?: null;
        $retSlf   = trim($f['return_slf']   ?? '')   ?: null;
        $chgSlf   = trim($f['charge_slf']   ?? '')   ?: null;
        $qtyUnit  = trim($f['quantity_unit'] ?? '') ?: null;
        $source   = trim($f['source'] ?? 'manual')  ?: 'manual';
        if (!$ref) {
            $pfx = (strpos($mtype, 'In') !== false) ? 'RCV' : ((strpos($mtype, 'Out') !== false) ? 'ISS' : 'TRF');
            $ref = $pfx . '-' . date('Y') . '-' . str_pad(rand(1,9999), 4, '0', STR_PAD_LEFT);
        }

        $conn->begin_transaction();
        try {
            $isIn = (strpos($mtype, 'In') !== false || $mtype === 'Return');
            $isOut = (strpos($mtype, 'Out') !== false || in_array($mtype, ['Damage','Expiry'], true));
            $isTransfer = (stripos($mtype, 'Transfer') !== false);

            if ($isTransfer) {
                if (!$fl || !$tl || $fl === $tl) throw new RuntimeException('Transfer requires different from/to locations');
                if (!invWmsAdjustLocationStock($conn, $pid, $fl, -$qty)) {
                    throw new RuntimeException('Insufficient stock at source location');
                }
                if (!invWmsAdjustLocationStock($conn, $pid, $tl, $qty)) {
                    throw new RuntimeException('Failed to credit destination location');
                }
            } elseif ($isIn) {
                $loc = invWmsResolveProductLocation($conn, $pid, $tl);
                $tl = $loc;
                if (!invWmsAdjustLocationStock($conn, $pid, $loc, $qty)) {
                    throw new RuntimeException('Failed to credit location stock');
                }
            } elseif ($isOut) {
                $loc = invWmsResolveProductLocation($conn, $pid, $fl);
                $fl = $loc;
                if (!invWmsAdjustLocationStock($conn, $pid, $loc, -$qty)) {
                    throw new RuntimeException('Insufficient stock at location');
                }
                // FEFO when no explicit batch
                if (!$bid) {
                    $consumed = invWmsConsumeFefoBatches($conn, $pid, $qty);
                    if ($consumed === null) throw new RuntimeException('Insufficient batch quantity (FEFO)');
                    if (!empty($consumed)) $bid = (int)$consumed[0]['batch_id'];
                } else {
                    $conn->query("UPDATE product_batches SET quantity_remaining = GREATEST(0, quantity_remaining - $qty) WHERE id=$bid");
                }
            }

            $stmt = $conn->prepare("INSERT INTO stock_movements
                (product_id,batch_id,movement_type,quantity,unit_price,total_value,from_location_id,to_location_id,
                 reference_number,user_id,notes,
                 po_number,si_number,dr_number,return_slf,charge_slf,quantity_unit,source)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iisiddiisisssssss',
                $pid, $bid, $mtype, $qty, $up, $total, $fl, $tl, $ref, $uid, $notes,
                $poNum, $siNum, $drNum, $retSlf, $chgSlf, $qtyUnit, $source);
            if (!$stmt->execute()) throw new RuntimeException($conn->error);

            $conn->commit();
            jOut(['success' => true, 'reference' => $ref, 'batch_id' => $bid]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ─── BATCHES ──────────────────────────────────────────────────────────────
    case 'get_batches':
        $pid  = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $wSQL = $pid ? "WHERE pb.product_id=$pid" : '';
        $sql  = "SELECT pb.*, p.name AS product_name, p.sku, s.name AS supplier_name,
                        DATEDIFF(pb.expiry_date, CURDATE()) AS days_to_expiry
                 FROM product_batches pb
                 LEFT JOIN products p ON pb.product_id = p.id
                 LEFT JOIN suppliers s ON pb.supplier_id = s.id
                 $wSQL ORDER BY pb.expiry_date ASC, pb.received_date DESC";
        $res  = $conn->query($sql);
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'add_batch':
        $f   = $_POST;
        $pid = (int)($f['product_id'] ?? 0);
        $sid = !empty($f['supplier_id']) ? (int)$f['supplier_id'] : null;
        $qty = (int)($f['quantity']     ?? 0);
        $batchNumber = trim($f['batch_number'] ?? '');
        $batchNotes = trim($f['notes'] ?? '');
        $mfg = !empty($f['manufacture_date']) ? $f['manufacture_date'] : null;
        $exp = !empty($f['expiry_date'])       ? $f['expiry_date']      : null;
        $rd  = !empty($f['received_date'])     ? $f['received_date']    : date('Y-m-d');
        if ($pid <= 0 || $batchNumber === '' || $qty <= 0) {
            jOut(['success' => false, 'message' => 'Product, batch number, and quantity are required.']);
        }
        $stmt = $conn->prepare("INSERT INTO product_batches
            (product_id,batch_number,manufacture_date,expiry_date,quantity,quantity_remaining,supplier_id,received_date,notes)
            VALUES (?,?,?,?,?,?,?,?,?)");
        if (!$stmt) jOut(['success' => false, 'message' => $conn->error]);
        $stmt->bind_param('isssiisis', $pid, $batchNumber, $mfg, $exp, $qty, $qty, $sid, $rd, $batchNotes);
        if ($stmt->execute()) jOut(['success' => true, 'id' => $conn->insert_id]);
        $dup = stripos((string)$stmt->error, 'duplicate') !== false;
        jOut(['success' => false, 'message' => $dup ? 'This batch / lot already exists for the product. Different receipts must keep separate batch records.' : 'Could not add the batch.']);
        break;

    case 'get_inventory_stock':
        try {
            require_once __DIR__ . '/product_expiry_lib.php';
            $lots = invStockLoadLotRows($conn, [
                'search' => trim((string)($_GET['search'] ?? '')),
                'category' => trim((string)($_GET['category'] ?? '')),
                'expiry' => trim((string)($_GET['expiry'] ?? '')),
                'stock' => trim((string)($_GET['stock'] ?? '')),
            ]);
            if (function_exists('productBarcodesHydrateRows')) {
                $lots = productBarcodesHydrateRows($conn, $lots, 'id');
            }
            jOut(['success' => true, 'data' => $lots]);
        } catch (Throwable $e) {
            jOut(['success' => false, 'message' => 'Could not load inventory stock.']);
        }
        break;

    case 'get_inventory_overview':
        try {
            $overview = inventoryOverviewLoad($conn, [
                'search' => trim((string)($_GET['search'] ?? '')),
                'category' => trim((string)($_GET['category'] ?? '')),
                'status' => trim((string)($_GET['status'] ?? '')),
            ]);
            jOut(['success' => true, 'data' => $overview['data'], 'summary' => $overview['summary'], 'categories' => $overview['categories']]);
        } catch (Throwable $e) {
            jOut(['success' => false, 'message' => 'Could not load inventory overview.']);
        }
        break;

    case 'record_stock_condition':
        $pid = (int)($_POST['product_id'] ?? 0);
        $bid = (int)($_POST['batch_id'] ?? 0);
        $type = trim((string)($_POST['type'] ?? $_POST['adjustment_type'] ?? 'Damage'));
        $qty = (int)($_POST['quantity'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($pid <= 0 || $qty <= 0) {
            jOut(['success' => false, 'message' => 'Select a product and enter a valid quantity.']);
        }
        $result = invStockRecordCondition($conn, $pid, $bid > 0 ? $bid : null, $type, $qty, $reason, $remarks, $uid);
        if (empty($result['ok'])) {
            jOut(['success' => false, 'message' => $result['message'] ?? 'Could not record the stock adjustment.']);
        }
        jOut(['success' => true, 'data' => $result, 'message' => $type . ' recorded. Sellable was updated automatically.']);
        break;

    case 'get_stock_condition_history':
        try {
            $rows = invStockLoadConditionHistory($conn);
            jOut(['success' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            jOut(['success' => false, 'message' => 'Could not load stock adjustment history.']);
        }
        break;

    // ─── PRODUCT CRUD ────────────────────────────────────────────────────────
    case 'legacy_add_product':
        $f    = $_POST;
        $sku  = !empty($f['sku']) ? trim($f['sku']) : 'SKU-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $name = trim($f['name'] ?? '');
        $bc   = !empty($f['barcode']) ? trim($f['barcode']) : null;
        $bnd  = !empty($f['brand']) ? trim($f['brand']) : null;
        $pkg  = !empty($f['packaging']) ? trim($f['packaging']) : null;
        $cid  = !empty($f['category_id']) ? (int)$f['category_id'] : null;
        $sid  = !empty($f['supplier_id']) ? (int)$f['supplier_id'] : null;
        $lid  = !empty($f['location_id']) ? (int)$f['location_id'] : null;
        $uom  = !empty($f['unit_of_measure']) ? trim($f['unit_of_measure']) : 'piece';
        $cost = floatval($f['cost_price'] ?? 0);
        $prc  = floatval($f['selling_price'] ?? $f['unit_price'] ?? 0);
        $qty  = intval($f['quantity_in_stock'] ?? 0);
        $reord= intval($f['reorder_level'] ?? 50);
        $exp  = !empty($f['expiry_date']) ? $f['expiry_date'] : null;
        $notes= !empty($f['notes']) ? trim($f['notes']) : null;

        $stmt = $conn->prepare("INSERT INTO products (sku, name, barcode, brand, packaging, category_id, supplier_id, location_id, unit_of_measure, cost_price, selling_price, unit_price, quantity_in_stock, reorder_level, expiry_date, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Active')");
        $stmt->bind_param('sssssiiisdddiiss', $sku, $name, $bc, $bnd, $pkg, $cid, $sid, $lid, $uom, $cost, $prc, $prc, $qty, $reord, $exp, $notes);
        if ($stmt->execute()) jOut(['success' => true, 'id' => $conn->insert_id, 'sku' => $sku]);
        else jOut(['success' => false, 'message' => $conn->error]);
        break;

    case 'edit_product':
        $f    = $_POST;
        $id   = (int)($f['id'] ?? 0);
        $sku  = trim($f['sku'] ?? '');
        $name = trim($f['name'] ?? '');
        $bc   = !empty($f['barcode']) ? trim($f['barcode']) : null;
        $bnd  = !empty($f['brand']) ? trim($f['brand']) : null;
        $pkg  = !empty($f['packaging']) ? trim($f['packaging']) : null;
        $cid  = !empty($f['category_id']) ? (int)$f['category_id'] : null;
        $sid  = !empty($f['supplier_id']) ? (int)$f['supplier_id'] : null;
        $lid  = !empty($f['location_id']) ? (int)$f['location_id'] : null;
        $uom  = !empty($f['unit_of_measure']) ? trim($f['unit_of_measure']) : 'piece';
        $cost = floatval($f['cost_price'] ?? 0);
        $prc  = floatval($f['selling_price'] ?? $f['unit_price'] ?? 0);
        $reord= intval($f['reorder_level'] ?? 50);
        $exp  = !empty($f['expiry_date']) ? $f['expiry_date'] : null;

        // Block unauthorized selling-price changes (URL/API/devtools)
        $prevSell = null;
        if ($id > 0) {
            $chk = $conn->prepare("SELECT selling_price FROM products WHERE id=? LIMIT 1");
            if ($chk) {
                $chk->bind_param('i', $id);
                $chk->execute();
                $prev = $chk->get_result()->fetch_assoc();
                $chk->close();
                $prevSell = $prev ? (float)$prev['selling_price'] : null;
            }
        } elseif ($sku !== '') {
            $chk = $conn->prepare("SELECT selling_price FROM products WHERE sku=? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $sku);
                $chk->execute();
                $prev = $chk->get_result()->fetch_assoc();
                $chk->close();
                $prevSell = $prev ? (float)$prev['selling_price'] : null;
            }
        }
        if ($prevSell !== null && abs($prevSell - $prc) > 0.00001 && !userCanManageProductPricing($conn, $uid, $role)) {
            jOut(['success' => false, 'message' => 'Not authorized to modify selling price / markup']);
        }

        if ($id) {
            $stmt = $conn->prepare("UPDATE products SET name=?, barcode=?, brand=?, packaging=?, category_id=?, supplier_id=?, location_id=?, unit_of_measure=?, cost_price=?, selling_price=?, unit_price=?, reorder_level=?, expiry_date=? WHERE id=?");
            $stmt->bind_param('ssssiiisdddisi', $name, $bc, $bnd, $pkg, $cid, $sid, $lid, $uom, $cost, $prc, $prc, $reord, $exp, $id);
        } else {
            $stmt = $conn->prepare("UPDATE products SET name=?, barcode=?, brand=?, packaging=?, category_id=?, supplier_id=?, location_id=?, unit_of_measure=?, cost_price=?, selling_price=?, unit_price=?, reorder_level=?, expiry_date=? WHERE sku=?");
            $stmt->bind_param('ssssiiisdddiss', $name, $bc, $bnd, $pkg, $cid, $sid, $lid, $uom, $cost, $prc, $prc, $reord, $exp, $sku);
        }
        
        if ($stmt->execute()) jOut(['success' => true]);
        else jOut(['success' => false, 'message' => $conn->error]);
        break;

    case 'legacy_delete_product':
        $id  = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
        $sku = !empty($_POST['sku']) ? trim($_POST['sku']) : (!empty($_POST['id']) ? trim($_POST['id']) : '');
        if ($id) {
            $stmt = $conn->prepare("UPDATE products SET status = 'Discontinued' WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            jOut(['success' => true]);
        } elseif ($sku !== '') {
            $stmt = $conn->prepare("UPDATE products SET status = 'Discontinued' WHERE sku = ?");
            $stmt->bind_param("s", $sku);
            $stmt->execute();
            jOut(['success' => true]);
        } else {
            jOut(['success' => false, 'message' => 'Invalid product identifier']);
        }
        break;

    // ─── SERIAL NUMBERS ───────────────────────────────────────────────────────
    case 'get_serials':
        $pid  = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $wSQL = $pid ? "WHERE sn.product_id=$pid" : "WHERE (c.name LIKE '%Equipment%' OR c.name LIKE '%Computer%' OR c.name LIKE '%Peripheral%' OR p.unit_of_measure = 'unit' OR p.name LIKE '%UPS%' OR p.name LIKE '%Desktop%' OR p.name LIKE '%Machine%' OR p.name LIKE '%Analyzer%')";
        $sql  = "SELECT sn.*, p.name AS product_name, p.sku, sl.storage_area, c.name AS category_name
                 FROM serial_numbers sn
                 LEFT JOIN products p ON sn.product_id = p.id
                 LEFT JOIN categories c ON p.category_id = c.id
                 LEFT JOIN storage_locations sl ON sn.location_id = sl.id
                 $wSQL ORDER BY sn.created_at DESC";
        $res  = $conn->query($sql);
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'add_serial':
        $f   = $_POST;
        $pid = (int)($f['product_id'] ?? 0);
        $lid = !empty($f['location_id']) ? (int)$f['location_id'] : null;
        $pd  = !empty($f['purchase_date'])   ? $f['purchase_date']   : null;
        $we  = !empty($f['warranty_expiry']) ? $f['warranty_expiry'] : null;
        $serialNumber = trim($f['serial_number'] ?? '');
        $serialStatus = trim($f['status'] ?? '') ?: 'Active';
        $serialNotes = trim($f['notes'] ?? '');
        if ($pid <= 0 || $serialNumber === '') {
            jOut(['success' => false, 'message' => 'Product and serial number are required.']);
        }
        $stmt = $conn->prepare("INSERT INTO serial_numbers (product_id,serial_number,status,purchase_date,warranty_expiry,location_id,notes) VALUES (?,?,?,?,?,?,?)");
        if (!$stmt) jOut(['success' => false, 'message' => $conn->error]);
        $stmt->bind_param('issssis', $pid, $serialNumber, $serialStatus, $pd, $we, $lid, $serialNotes);
        if ($stmt->execute()) jOut(['success' => true]);
        else jOut(['success' => false, 'message' => $conn->error]);
        break;

    case 'update_serial_status':
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'Active';
        $stmt   = $conn->prepare("UPDATE serial_numbers SET status=? WHERE id=?");
        $stmt->bind_param('si', $status, $id);
        if ($stmt->execute()) jOut(['success' => true]);
        else jOut(['success' => false, 'message' => $conn->error]);
        break;

    // ─── ALERTS ───────────────────────────────────────────────────────────────
    case 'get_alerts':
        $low = [];
        $r   = $conn->query("SELECT p.id,p.sku,p.name,p.brand,c.name AS category,
                                    p.quantity_in_stock,p.reorder_level,p.reorder_quantity,
                                    s.name AS supplier_name, s.id AS supplier_id, p.cost_price
                             FROM products p
                             LEFT JOIN categories c ON p.category_id = c.id
                             LEFT JOIN suppliers s ON p.supplier_id = s.id
                             WHERE p.status != 'Discontinued'
                             ORDER BY p.name ASC");
        $sellableMap = invWmsSellableQtyMap($conn);
        if ($r) while ($row = $r->fetch_assoc()) {
            $row = invWmsAttachSellableLowStock($row, $sellableMap);
            if ((int)$row['is_low_stock'] !== 1) continue;
            $low[] = $row;
        }
        usort($low, static function ($a, $b) {
            return ((int)$b['shortage'] <=> (int)$a['shortage']);
        });

        $exp = [];
        $etCol = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_tracking'");
        $etWhere = ($etCol && $etCol->num_rows > 0) ? " AND COALESCE(p.expiry_tracking,0)=1" : "";
        $r   = $conn->query("SELECT p.id,p.sku,p.name,p.brand,p.expiry_date,
                                    p.quantity_in_stock,
                                    DATEDIFF(p.expiry_date, CURDATE()) AS days_left
                             FROM products p
                             WHERE p.expiry_date IS NOT NULL
                               AND p.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                               AND p.status != 'Discontinued' AND p.quantity_in_stock > 0
                               $etWhere
                             ORDER BY p.expiry_date ASC");
        if ($r) while ($row = $r->fetch_assoc()) $exp[] = $row;

        jOut(['success' => true, 'low_stock' => $low, 'near_expiry' => $exp,
              'low_count' => count($low), 'expiry_count' => count($exp)]);
        break;

    // ─── LOOKUPS ──────────────────────────────────────────────────────────────
    case 'get_categories':
        $res  = $conn->query("SELECT id, name FROM categories WHERE status='Active' ORDER BY name");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_locations':
        $res  = $conn->query("SELECT id, location_code, storage_area, shelf_rack FROM storage_locations WHERE status='Active' ORDER BY storage_area, shelf_rack");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_suppliers_list':
        $res  = $conn->query("SELECT id, supplier_code, name FROM suppliers WHERE status='Active' ORDER BY name");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_products_list':
        $imageColumn = getProductImageColumn($conn);
        $imageSelect = $imageColumn ? ", p.`" . str_replace('`', '``', $imageColumn) . "` AS product_image" : ", '' AS product_image";
        $res  = $conn->query("SELECT p.id, p.sku, p.name, p.barcode, p.quantity_in_stock, p.cost_price, p.selling_price,
                                     p.unit_price, p.reorder_level, p.expiry_date,
                                     COALESCE(p.expiry_tracking, 0) AS expiry_tracking, c.name AS category{$imageSelect}
                              FROM products p LEFT JOIN categories c ON p.category_id=c.id
                              WHERE p.status!='Discontinued' ORDER BY p.id DESC, p.name LIMIT 500");
        if (!$res) {
            $res = $conn->query("SELECT p.id, p.sku, p.name, p.barcode, p.quantity_in_stock, p.cost_price, p.selling_price,
                                         p.unit_price, p.reorder_level, p.expiry_date, c.name AS category{$imageSelect}
                                  FROM products p LEFT JOIN categories c ON p.category_id=c.id
                                  WHERE p.status!='Discontinued' ORDER BY p.id DESC, p.name LIMIT 500");
        }
        $rows = [];
        $sellableMap = invWmsSellableQtyMap($conn);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $pid = (int)$r['id'];
                $onHand = (int)$r['quantity_in_stock'];
                $r['sellable_qty'] = (int)($sellableMap[$pid] ?? $onHand);
                $r['expiry_tracking'] = (int)($r['expiry_tracking'] ?? 0);
                if ($r['expiry_tracking'] !== 1) {
                    $r['expiry_date'] = null;
                }
                $rows[] = $r;
            }
        }
        $rows = productBarcodesHydrateRows($conn, $rows, 'id');
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_product_barcodes':
        $pid = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
        $sku = trim((string)($_GET['sku'] ?? $_POST['sku'] ?? ''));
        if ($pid <= 0 && $sku !== '') {
            $st = $conn->prepare('SELECT id FROM products WHERE sku=? LIMIT 1');
            if ($st) {
                $st->bind_param('s', $sku);
                $st->execute();
                $pid = (int)($st->get_result()->fetch_assoc()['id'] ?? 0);
                $st->close();
            }
        }
        $list = productBarcodesListForProduct($conn, $pid);
        jOut(['success' => true, 'product_id' => $pid, 'data' => $list, 'barcode' => productBarcodesPrimaryValue($conn, $pid)]);
        break;

    case 'get_stock_barcodes':
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $source = trim((string)($_GET['source'] ?? $_POST['source'] ?? ''));
        $status = trim((string)($_GET['status'] ?? $_POST['status'] ?? 'active'));
        jOut(['success' => true, 'data' => productBarcodesListInStock($conn, $q, $source, $status)]);
        break;

    case 'get_product_batches':
        $pid = (int)($_GET['product_id'] ?? 0);
        if (!$pid) jOut(['success' => true, 'data' => []]);
        $res = $conn->query("SELECT id, batch_number, quantity_remaining, expiry_date, received_date FROM product_batches WHERE product_id=$pid AND quantity_remaining>0 ORDER BY CASE WHEN received_date IS NULL THEN 1 ELSE 0 END ASC, received_date ASC, id ASC");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'process_stock_return':
        $f       = $_POST;
        $pid     = (int)($f['product_id'] ?? 0);
        $retType = trim($f['return_type'] ?? 'Customer Return (Restockable)');
        $qty     = (int)($f['quantity']   ?? 0);
        $up      = (float)($f['unit_price'] ?? 0);
        $bid     = !empty($f['batch_id']) ? (int)$f['batch_id'] : null;
        $fl      = !empty($f['from_location_id']) ? (int)$f['from_location_id'] : null;
        $tl      = !empty($f['to_location_id'])   ? (int)$f['to_location_id']   : null;
        $ref     = trim($f['reference_number'] ?? '');
        $reason  = trim($f['reason'] ?? 'Return');
        $notes   = trim($f['notes']  ?? '');
        $retSlf  = trim($f['return_slf'] ?? '') ?: null;
        $replPid = (int)($f['replacement_product_id'] ?? 0);
        $soId    = (int)($f['sales_order_id'] ?? 0) ?: null;

        if (!$pid || $qty <= 0) {
            jOut(['success' => false, 'message' => 'Please select a product and valid return quantity.']);
        }

        $isCustomer = stripos($retType, 'Supplier') === false;
        $party = $isCustomer ? 'Customer' : 'Supplier';
        $isRestockable = $retType === 'Customer Return (Restockable)';
        $isReplacement = stripos($retType, 'Replacement') !== false;
        $isSupplierRma = $retType === 'Supplier Return (RMA)';
        $isQuarantine = !$isRestockable && !$isReplacement && !$isSupplierRma;

        if ($isReplacement) {
            if ($replPid <= 0) {
                jOut(['success' => false, 'message' => 'Select a valid replacement product.']);
            }
            if ($replPid === $pid) {
                jOut(['success' => false, 'message' => 'Replacement product must be different from the original.']);
            }
        } else {
            $replPid = 0;
        }

        $pRes = $conn->query("SELECT id, sku, name, quantity_in_stock, selling_price, cost_price, expiry_date FROM products WHERE id=$pid");
        if (!$pRes || $pRes->num_rows === 0) {
            jOut(['success' => false, 'message' => 'Product not found.']);
        }
        $prodData = $pRes->fetch_assoc();
        $qtyBefore = (int)$prodData['quantity_in_stock'];
        if ($up <= 0) $up = (float)$prodData['selling_price'];

        if (!$ref) {
            $ref = invWmsNextDocNumber($conn, 'product_returns', 'return_number', 'RET');
        }

        $condition = $isRestockable ? 'Restockable' : ($isSupplierRma ? 'Returned to supplier' : ($isReplacement ? 'Damaged - replaced' : 'Damaged - quarantine'));
        $formattedNotes = "[$retType - $party - $condition - Reason: $reason] " . $notes;
        $total = $qty * $up;
        $mtype = 'Return';
        $qtyAfter = $qtyBefore;

        $conn->begin_transaction();
        try {
            if ($isRestockable) {
                if (!invWmsApplyOnHandDelta($conn, $pid, $qty, $tl ?: $fl)) {
                    throw new RuntimeException('Could not add returned quantity back to inventory.');
                }
                if ($bid) {
                    $conn->query("UPDATE product_batches SET quantity_remaining = quantity_remaining + $qty WHERE id=$bid AND product_id=$pid");
                }
                $qtyAfter = $qtyBefore + $qty;
            } elseif ($isSupplierRma) {
                if (!invWmsApplyOnHandDelta($conn, $pid, -$qty, $fl ?: $tl)) {
                    throw new RuntimeException('Insufficient on-hand quantity to return to the supplier.');
                }
                if ($bid) {
                    $conn->query("UPDATE product_batches SET quantity_remaining = GREATEST(0, quantity_remaining - $qty) WHERE id=$bid AND product_id=$pid");
                } else {
                    $need = $qty;
                    $bres = $conn->query("SELECT id, quantity_remaining FROM product_batches WHERE product_id=$pid AND quantity_remaining > 0 ORDER BY id ASC");
                    if ($bres) {
                        while ($need > 0 && ($bb = $bres->fetch_assoc())) {
                            $take = min($need, (int)$bb['quantity_remaining']);
                            $bidRow = (int)$bb['id'];
                            $conn->query("UPDATE product_batches SET quantity_remaining = quantity_remaining - $take WHERE id=$bidRow");
                            $need -= $take;
                        }
                    }
                }
                $qtyAfter = max(0, $qtyBefore - $qty);
            } elseif ($isReplacement) {
                $issue = invWmsIssueSellableQty($conn, $replPid, $qty, null, $fl ?: $tl);
                if (!$issue['ok']) {
                    throw new RuntimeException($issue['message']);
                }
                $dmgRet = invStockApplyDamagedReturn($conn, $pid, $qty, $bid, $uid, $reason !== '' ? $reason : 'Damaged return', $formattedNotes);
                if (!$dmgRet['ok']) {
                    throw new RuntimeException($dmgRet['message']);
                }
                if (!empty($dmgRet['batch_id'])) {
                    $bid = (int)$dmgRet['batch_id'];
                }
            } elseif ($isQuarantine) {
                $dmgRet = invStockApplyDamagedReturn($conn, $pid, $qty, $bid, $uid, $reason !== '' ? $reason : 'Damaged return', $formattedNotes);
                if (!$dmgRet['ok']) {
                    throw new RuntimeException($dmgRet['message']);
                }
                if (!empty($dmgRet['batch_id'])) {
                    $bid = (int)$dmgRet['batch_id'];
                }
                $qtyAfter = $qtyBefore;
            }

            $retNum = $ref;
            $cond = $condition;
            $stmtRet = $conn->prepare("INSERT INTO product_returns
                (return_number, party, return_type, condition_status, product_id, replacement_product_id,
                 quantity, batch_id, sales_order_id, reference_number, reason, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmtRet) {
                throw new RuntimeException('Could not record return history: ' . $conn->error);
            }
            $replBind = $replPid > 0 ? $replPid : 0;
            $bidBind = $bid ?: 0;
            $soBind = $soId ?: 0;
            $stmtRet->bind_param('ssssiiiiisssi', $retNum, $party, $retType, $cond, $pid, $replBind, $qty, $bidBind, $soBind, $ref, $reason, $formattedNotes, $uid);
            if (!$stmtRet->execute()) {
                throw new RuntimeException('Could not record return history: ' . $stmtRet->error);
            }
            $returnId = (int)$conn->insert_id;
            $stmtRet->close();

            $stmt = $conn->prepare("INSERT INTO stock_movements
                (product_id, batch_id, movement_type, quantity, unit_price, total_value, from_location_id, to_location_id,
                 reference_number, user_id, notes, return_slf, source)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'stock_return')");
            $stmt->bind_param('iisiddiisiss', $pid, $bid, $mtype, $qty, $up, $total, $fl, $tl, $ref, $uid, $formattedNotes, $retSlf);
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to record stock movement: ' . $conn->error);
            }

            if ($isReplacement) {
                $replRef = 'EXCH-' . date('Y') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $replNotes = "[Replacement issued for $party return $ref] original product #$pid -> replacement #$replPid. " . $notes;
                $stmtRepl = $conn->prepare("INSERT INTO stock_movements
                    (product_id, batch_id, movement_type, quantity, unit_price, total_value, from_location_id, to_location_id,
                     reference_number, user_id, notes, return_slf, source)
                    VALUES (?,?,'Stock Out (Issue)',?,?,?,?,?,?,?,?,?,'replacement_issue')");
                $nullBid = null;
                $stmtRepl->bind_param('iiiddiisiss', $replPid, $nullBid, $qty, $up, $total, $fl, $tl, $replRef, $uid, $replNotes, $retSlf);
                $stmtRepl->execute();
                $link = invWmsRecordReplacement($conn, $pid, $replPid, $qty, $reason !== '' ? $reason : 'Returned', 'return', $uid, $returnId, $ref, $soId, $replNotes);
                if (!$link['ok']) {
                    throw new RuntimeException($link['message']);
                }
            }

            $adjNum = 'ADJ-RET-' . date('YmdHis') . '-' . rand(10, 99);
            $adjType = $isRestockable ? 'Increase' : (($isSupplierRma || $isReplacement) ? 'Decrease' : 'Set Exact');
            $allowedReasons = ['Damage', 'Expiry', 'Loss', 'Found', 'Count Correction', 'Return', 'Other'];
            $adjustmentReason = in_array($reason, $allowedReasons, true) ? $reason : 'Other';
            $stmtAdj = $conn->prepare("INSERT INTO stock_adjustments
                (adjustment_number, product_id, batch_id, adjustment_type, quantity_before, quantity_adjusted, quantity_after, reason, reference_number, adjusted_by, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmtAdj->bind_param('siisiiissis', $adjNum, $pid, $bid, $adjType, $qtyBefore, $qty, $qtyAfter, $adjustmentReason, $ref, $uid, $formattedNotes);
            $stmtAdj->execute();

            $conn->query("UPDATE products SET status=CASE WHEN quantity_in_stock=0 THEN 'Out of Stock' WHEN quantity_in_stock<=reorder_level THEN 'Low Stock' ELSE 'Active' END WHERE id=$pid" . ($replPid ? " OR id=$replPid" : '') . " AND status <> 'Discontinued'");

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }

        jOut([
            'success' => true,
            'message' => "Stock Return recorded successfully ($ref).",
            'reference' => $ref,
            'new_stock' => $qtyAfter
        ]);
        break;

    case 'process_product_replacement':
        $origId = (int)($_POST['original_product_id'] ?? 0);
        $replId = (int)($_POST['replacement_product_id'] ?? 0);
        $qty    = (int)($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Replacement');
        $notes  = trim($_POST['notes'] ?? '');
        $ref    = trim($_POST['reference_number'] ?? '');
        $soId   = (int)($_POST['sales_order_id'] ?? 0) ?: null;
        $alsoRemoveOrig = isset($_POST['remove_original']) && $_POST['remove_original'] !== '0' && $_POST['remove_original'] !== '';

        $allowedReasons = ['Damaged', 'Defective', 'Unavailable', 'Returned', 'Replacement'];
        if (!in_array($reason, $allowedReasons, true)) $reason = 'Replacement';

        if ($origId <= 0 || $replId <= 0 || $qty <= 0) {
            jOut(['success' => false, 'message' => 'Original product, replacement product, and quantity are required.']);
        }
        if ($origId === $replId) {
            jOut(['success' => false, 'message' => 'Replacement product must be different from the original.']);
        }

        $orig = $conn->query("SELECT id, sku, name, quantity_in_stock FROM products WHERE id=$origId AND status!='Discontinued'")->fetch_assoc();
        $repl = $conn->query("SELECT id, sku, name, quantity_in_stock, selling_price FROM products WHERE id=$replId AND status!='Discontinued'")->fetch_assoc();
        if (!$orig || !$repl) {
            jOut(['success' => false, 'message' => 'Original or replacement product was not found.']);
        }

        $link = ['number' => ''];
        $conn->begin_transaction();
        try {
            if ($alsoRemoveOrig || (int)$orig['quantity_in_stock'] >= $qty) {
                if ((int)$orig['quantity_in_stock'] > 0) {
                    $pullQty = min($qty, (int)$orig['quantity_in_stock']);
                    if ($pullQty > 0 && !invWmsApplyOnHandDelta($conn, $origId, -$pullQty, null)) {
                        throw new RuntimeException('Could not remove the original product from stock.');
                    }
                }
            }

            $issue = invWmsIssueSellableQty($conn, $replId, $qty, null, null);
            if (!$issue['ok']) {
                throw new RuntimeException($issue['message']);
            }

            $link = invWmsRecordReplacement($conn, $origId, $replId, $qty, $reason, 'catalog', $uid, null, $ref !== '' ? $ref : null, $soId, $notes);
            if (!$link['ok']) {
                throw new RuntimeException($link['message']);
            }

            $up = (float)$repl['selling_price'];
            $total = $qty * $up;
            $mvtNotes = "[Product replacement $reason] {$orig['sku']} -> {$repl['sku']}. " . $notes;
            $mref = $ref !== '' ? $ref : $link['number'];
            $stmtM = $conn->prepare("INSERT INTO stock_movements
                (product_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes, source)
                VALUES (?,'Stock Out (Issue)',?,?,?,?,?,?,'replacement_issue')");
            $stmtM->bind_param('iiddsis', $replId, $qty, $up, $total, $mref, $uid, $mvtNotes);
            $stmtM->execute();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }

        jOut([
            'success' => true,
            'message' => "Replacement recorded ({$link['number']}): {$orig['sku']} → {$repl['sku']}.",
            'replacement_number' => $link['number']
        ]);
        break;

    case 'list_product_returns':
        $rows = [];
        $sql = "SELECT r.id, r.return_number, r.party, r.return_type, r.condition_status, r.quantity,
                       r.reference_number, r.reason, r.notes, r.created_at,
                       p.sku, p.name AS product_name, p.barcode,
                       rp.sku AS replacement_sku, rp.name AS replacement_name,
                       COALESCE(u.full_name, u.username) AS created_by_name
                FROM product_returns r
                INNER JOIN products p ON p.id = r.product_id
                LEFT JOIN products rp ON rp.id = r.replacement_product_id
                LEFT JOIN users u ON u.id = r.created_by
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 150";
        $res = $conn->query($sql);
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'list_product_replacements':
        $rows = [];
        $sql = "SELECT r.id, r.replacement_number, r.quantity, r.reason, r.source, r.return_reference,
                       r.notes, r.created_at,
                       op.sku AS original_sku, op.name AS original_name, op.barcode AS original_barcode,
                       rp.sku AS replacement_sku, rp.name AS replacement_name, rp.barcode AS replacement_barcode,
                       COALESCE(u.full_name, u.username) AS created_by_name
                FROM product_replacements r
                INNER JOIN products op ON op.id = r.original_product_id
                INNER JOIN products rp ON rp.id = r.replacement_product_id
                LEFT JOIN users u ON u.id = r.created_by
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 150";
        $res = $conn->query($sql);
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_analytics_data':
        // 1. Stock Level Trends (Monthly aggregated Stock In vs Stock Out)
        $monthlyLabels = [];
        $monthlyIn = [];
        $monthlyOut = [];
        for ($i = 6; $i >= 0; $i--) {
            $mStart = date('Y-m-01', strtotime("-$i months"));
            $mEnd   = date('Y-m-t 23:59:59', strtotime("-$i months"));
            $monthlyLabels[] = date('M', strtotime("-$i months"));
            
            $qIn = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM stock_movements WHERE transaction_date BETWEEN '$mStart' AND '$mEnd' AND (movement_type LIKE '%In%' OR movement_type = 'Receive' OR movement_type = 'Return')");
            $rIn = $qIn ? $qIn->fetch_assoc() : ['total' => 0];
            $monthlyIn[] = (int)$rIn['total'];
            
            $qOut = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM stock_movements WHERE transaction_date BETWEEN '$mStart' AND '$mEnd' AND (movement_type LIKE '%Out%' OR movement_type = 'Issue' OR movement_type = 'Damage' OR movement_type = 'Expiry')");
            $rOut = $qOut ? $qOut->fetch_assoc() : ['total' => 0];
            $monthlyOut[] = (int)$rOut['total'];
        }

        // 2. Weekly Movement (Past 7 Days)
        $dailyLabels = [];
        $dailyIn = [];
        $dailyOut = [];
        for ($i = 6; $i >= 0; $i--) {
            $dDate  = date('Y-m-d', strtotime("-$i days"));
            $dailyLabels[] = date('D', strtotime("-$i days"));
            
            $qIn = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM stock_movements WHERE DATE(transaction_date) = '$dDate' AND (movement_type LIKE '%In%' OR movement_type = 'Receive' OR movement_type = 'Return')");
            $rIn = $qIn ? $qIn->fetch_assoc() : ['total' => 0];
            $dailyIn[] = (int)$rIn['total'];
            
            $qOut = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM stock_movements WHERE DATE(transaction_date) = '$dDate' AND (movement_type LIKE '%Out%' OR movement_type = 'Issue' OR movement_type = 'Damage' OR movement_type = 'Expiry')");
            $rOut = $qOut ? $qOut->fetch_assoc() : ['total' => 0];
            $dailyOut[] = (int)$rOut['total'];
        }

        // 3. Inventory Value by Category
        $categoryLabels = [];
        $categoryValues = [];
        $catRes = $conn->query("SELECT c.name AS category_name, COALESCE(SUM(p.quantity_in_stock * COALESCE(NULLIF(p.selling_price, 0), NULLIF(p.unit_price, 0), p.cost_price)), 0) AS total_val 
                                FROM categories c 
                                LEFT JOIN products p ON p.category_id = c.id AND p.status != 'Discontinued' 
                                GROUP BY c.id HAVING total_val > 0 ORDER BY total_val DESC LIMIT 7");
        if ($catRes && $catRes->num_rows > 0) {
            while ($cRow = $catRes->fetch_assoc()) {
                $categoryLabels[] = $cRow['category_name'];
                $categoryValues[] = round((float)$cRow['total_val'], 2);
            }
        }
        if (empty($categoryLabels)) {
            $categoryLabels = ['No inventory'];
            $categoryValues = [0];
        }

        // 4. Supplier Performance
        $supplierScores = [
            'labels' => ['Delivery Speed', 'Product Quality', 'Price', 'Communication', 'Reliability'],
            'top_supplier' => [0, 0, 0, 0, 0],
            'average' => [0, 0, 0, 0, 0]
        ];
        $perfRes = $conn->query(
            "SELECT s.rating, s.lead_time_days,
                    COUNT(sp.id) AS delivery_count,
                    AVG(sp.on_time) AS on_time_rate,
                    AVG(sp.quality_issues) AS avg_quality_issues
             FROM suppliers s
             LEFT JOIN supplier_performance sp ON s.id = sp.supplier_id
             WHERE s.status = 'Active'
             GROUP BY s.id, s.rating, s.lead_time_days
             HAVING delivery_count > 0 OR s.rating > 0"
        );
        $supplierRows = [];
        if ($perfRes) {
            while ($row = $perfRes->fetch_assoc()) {
                $speed = max(0, min(100, 100 - (((int)$row['lead_time_days']) * 5)));
                $quality = max(0, min(100, 100 - (((float)$row['avg_quality_issues']) * 20)));
                $price = max(0, min(100, ((float)$row['rating']) * 20));
                $communication = $price;
                $reliability = max(0, min(100, ((float)$row['on_time_rate']) * 100));
                if ((int)$row['delivery_count'] === 0) {
                    $quality = $price;
                    $reliability = $price;
                }
                $supplierRows[] = [$speed, $quality, $price, $communication, $reliability];
            }
        }
        if ($supplierRows) {
            $averages = [];
            for ($i = 0; $i < 5; $i++) {
                $averages[$i] = round(array_sum(array_column($supplierRows, $i)) / count($supplierRows), 1);
            }
            usort($supplierRows, fn($a, $b) => array_sum($b) <=> array_sum($a));
            $supplierScores['top_supplier'] = array_map(fn($v) => round($v, 1), $supplierRows[0]);
            $supplierScores['average'] = $averages;
        }

        jOut([
            'success' => true,
            'data' => [
                'monthly'  => ['labels' => $monthlyLabels, 'in' => $monthlyIn, 'out' => $monthlyOut],
                'daily'    => ['labels' => $dailyLabels, 'in' => $dailyIn, 'out' => $dailyOut],
                'category' => ['labels' => $categoryLabels, 'values' => $categoryValues],
                'supplier' => $supplierScores
            ]
        ]);
        break;

    // ─── WMS: LOCATION STOCK / TRANSFERS / FEFO ───────────────
    case 'get_location_stock':
        $pid = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $lid = !empty($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
        $search = trim($_GET['search'] ?? '');
        $where = ['p.status != \'Discontinued\''];
        if ($pid) $where[] = "pls.product_id=$pid";
        if ($lid) $where[] = "pls.location_id=$lid";
        if ($search !== '') {
            $s = $conn->real_escape_string($search);
            $where[] = "(p.sku LIKE '%$s%' OR p.name LIKE '%$s%' OR p.brand LIKE '%$s%' OR sl.location_code LIKE '%$s%')";
        }
        $wSQL = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT pls.id, pls.product_id, pls.location_id, pls.quantity,
                       p.sku, p.name, p.brand, p.unit_of_measure, p.reorder_level, p.quantity_in_stock AS total_stock,
                       sl.location_code, sl.storage_area, sl.shelf_rack
                FROM product_location_stock pls
                JOIN products p ON p.id = pls.product_id
                JOIN storage_locations sl ON sl.id = pls.location_id
                $wSQL
                ORDER BY p.name, sl.location_code";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'transfer_stock':
        if (!$canManageWms) jOut(['success' => false, 'message' => 'Only Admin or Warehouse Head can transfer stock']);
        $pid = (int)($_POST['product_id'] ?? 0);
        $from = (int)($_POST['from_location_id'] ?? 0);
        $to = (int)($_POST['to_location_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $bid = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;
        if (!$pid || !$from || !$to || $qty <= 0) jOut(['success' => false, 'message' => 'Product, from/to locations, and quantity are required']);
        if ($from === $to) jOut(['success' => false, 'message' => 'From and To locations must be different']);

        $conn->begin_transaction();
        try {
            if (!invWmsAdjustLocationStock($conn, $pid, $from, -$qty)) {
                throw new RuntimeException('Insufficient stock at source location');
            }
            if (!invWmsAdjustLocationStock($conn, $pid, $to, $qty)) {
                throw new RuntimeException('Failed to credit destination location');
            }

            $cnt = $conn->query("SELECT COUNT(*) c FROM stock_transfers WHERE YEAR(created_at)=" . date('Y'));
            $n = $cnt ? (int)$cnt->fetch_assoc()['c'] : 0;
            $trNum = 'TRF-' . date('Y') . '-' . str_pad($n + 1, 4, '0', STR_PAD_LEFT);
            $status = 'Completed';
            $stmt = $conn->prepare("INSERT INTO stock_transfers (transfer_number, from_location_id, to_location_id, status, notes, transferred_by, completed_at) VALUES (?,?,?,?,?,?,NOW())");
            $stmt->bind_param('siissi', $trNum, $from, $to, $status, $notes, $uid);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
            $trId = (int)$conn->insert_id;

            $si = $conn->prepare("INSERT INTO stock_transfer_items (transfer_id, product_id, quantity, batch_id, notes) VALUES (?,?,?,?,?)");
            $si->bind_param('iiiis', $trId, $pid, $qty, $bid, $notes);
            if (!$si->execute()) throw new RuntimeException($si->error ?: $conn->error);

            $ref = $trNum;
            $mtype = 'Transfer';
            $up = 0.0; $total = 0.0;
            $mv = $conn->prepare("INSERT INTO stock_movements (product_id, batch_id, movement_type, quantity, unit_price, total_value, from_location_id, to_location_id, reference_number, user_id, notes, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,'transfer')");
            $mv->bind_param('iisiddiisis', $pid, $bid, $mtype, $qty, $up, $total, $from, $to, $ref, $uid, $notes);
            if (!$mv->execute()) throw new RuntimeException($mv->error ?: $conn->error);

            $conn->commit();
            jOut(['success' => true, 'transfer_id' => $trId, 'transfer_number' => $trNum]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_transfers':
        $sql = "SELECT st.*,
                       fl.location_code AS from_code, fl.storage_area AS from_area,
                       tl.location_code AS to_code, tl.storage_area AS to_area,
                       COALESCE(u.full_name, u.username) AS transferred_by_name,
                       (SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id=st.id) AS item_count,
                       (SELECT SUM(quantity) FROM stock_transfer_items WHERE transfer_id=st.id) AS total_qty
                FROM stock_transfers st
                LEFT JOIN storage_locations fl ON fl.id = st.from_location_id
                LEFT JOIN storage_locations tl ON tl.id = st.to_location_id
                LEFT JOIN users u ON u.id = st.transferred_by
                ORDER BY st.created_at DESC LIMIT 200";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_transfer_detail':
        $id = (int)($_GET['id'] ?? 0);
        $st = $conn->query("SELECT st.*, fl.location_code AS from_code, tl.location_code AS to_code,
                                   COALESCE(u.full_name,u.username) AS transferred_by_name
                            FROM stock_transfers st
                            LEFT JOIN storage_locations fl ON fl.id=st.from_location_id
                            LEFT JOIN storage_locations tl ON tl.id=st.to_location_id
                            LEFT JOIN users u ON u.id=st.transferred_by
                            WHERE st.id=$id")->fetch_assoc();
        if (!$st) jOut(['success' => false, 'message' => 'Transfer not found']);
        $items = [];
        $ir = $conn->query("SELECT sti.*, p.sku, p.name AS product_name, p.brand
                            FROM stock_transfer_items sti
                            LEFT JOIN products p ON p.id=sti.product_id
                            WHERE sti.transfer_id=$id");
        if ($ir) while ($r = $ir->fetch_assoc()) $items[] = $r;
        $st['items'] = $items;
        jOut(['success' => true, 'data' => $st]);
        break;

    case 'get_fefo_batches':
    case 'get_fifo_batches':
        $pid = (int)($_GET['product_id'] ?? 0);
        if (!$pid) jOut(['success' => true, 'data' => []]);
        jOut(['success' => true, 'data' => invWmsGetFifoBatches($conn, $pid)]);
        break;

    case 'get_stock_movement_lookup':
        $sku = trim((string)($_GET['sku'] ?? $_POST['sku'] ?? ''));
        $pid = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
        if ($sku === '' && $pid <= 0) {
            jOut(['success' => false, 'message' => 'Product SKU or ID is required.']);
        }

        if ($pid > 0) {
            $stmt = $conn->prepare("SELECT p.id, p.sku, p.name, p.barcode, p.brand, p.size, p.packaging, p.description,
                p.quantity_in_stock, p.reorder_level, p.unit_of_measure, p.unit_price, p.cost_price, p.selling_price,
                p.expiry_date, p.status, p.manufacturer, c.name AS category_name, s.name AS supplier_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                WHERE p.id = ? LIMIT 1");
            $stmt->bind_param('i', $pid);
        } else {
            $stmt = $conn->prepare("SELECT p.id, p.sku, p.name, p.barcode, p.brand, p.size, p.packaging, p.description,
                p.quantity_in_stock, p.reorder_level, p.unit_of_measure, p.unit_price, p.cost_price, p.selling_price,
                p.expiry_date, p.status, p.manufacturer, c.name AS category_name, s.name AS supplier_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                WHERE p.sku = ? OR " . productBarcodesMatchProductSql('p') . " LIMIT 1");
            $key = strtoupper($sku);
            $stmt->bind_param('sss', $sku, $key, $key);
        }
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$product && $sku !== '') {
            $linkedId = productBarcodesFindProductId($conn, $sku);
            if ($linkedId > 0) {
                $stmt = $conn->prepare("SELECT p.id, p.sku, p.name, p.barcode, p.brand, p.size, p.packaging, p.description,
                    p.quantity_in_stock, p.reorder_level, p.unit_of_measure, p.unit_price, p.cost_price, p.selling_price,
                    p.expiry_date, p.status, p.manufacturer, c.name AS category_name, s.name AS supplier_name
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN suppliers s ON p.supplier_id = s.id
                    WHERE p.id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $linkedId);
                    $stmt->execute();
                    $product = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
            }
        }
        if (!$product) {
            jOut(['success' => false, 'message' => 'Product not found.']);
        }
        $hydrated = productBarcodesHydrateRows($conn, [$product], 'id');
        $product = $hydrated[0];

        $productId = (int)$product['id'];
        $availableQty = invWmsProductSellableQty($conn, $productId, (int)$product['quantity_in_stock'], $product['expiry_date'] ?? null);
        $product['sellable_qty'] = $availableQty;
        $product['quantity_in_stock'] = (int)$product['quantity_in_stock'];
        $uom = trim((string)($product['unit_of_measure'] ?? 'piece')) ?: 'piece';
        $packaging = trim((string)($product['packaging'] ?? ''));
        $piecesPerUnit = 1;
        if (preg_match('/(\d+)/', $packaging, $m)) {
            $piecesPerUnit = max(1, (int)$m[1]);
        }
        $availableDisplay = number_format($availableQty) . ' ' . $uom;
        if ($piecesPerUnit > 1 && strcasecmp($uom, 'piece') !== 0 && strcasecmp($uom, 'pcs') !== 0) {
            $availableDisplay .= ' / ' . number_format($availableQty * $piecesPerUnit) . ' pieces';
        }

        $references = [];
        $seenRefs = [];
        $addRef = static function (array &$references, array &$seenRefs, string $value, string $type, string $label, ?int $qty = null, ?string $extra = null) {
            $value = trim($value);
            if ($value === '' || $value === '0') return;
            $key = strtoupper($type . '|' . $value);
            if (isset($seenRefs[$key])) return;
            $seenRefs[$key] = true;
            $references[] = [
                'value' => $value,
                'type' => $type,
                'label' => $label,
                'quantity' => $qty,
                'extra' => $extra,
            ];
        };

        // Purchase Orders linked to this product
        $poSql = "SELECT po.po_number, po.status, poi.quantity_ordered, poi.quantity_received, po.order_date
                  FROM purchase_order_items poi
                  INNER JOIN purchase_orders po ON po.id = poi.po_id
                  WHERE poi.product_id = ?
                  ORDER BY po.order_date DESC, po.id DESC
                  LIMIT 20";
        if ($st = $conn->prepare($poSql)) {
            $st->bind_param('i', $productId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $addRef(
                    $references,
                    $seenRefs,
                    (string)$row['po_number'],
                    'PO',
                    'PO ' . $row['po_number'] . ' (' . ($row['status'] ?? 'N/A') . ')',
                    (int)$row['quantity_ordered'],
                    'Ordered ' . (int)$row['quantity_ordered'] . ', received ' . (int)$row['quantity_received']
                );
            }
            $st->close();
        }

        // Purchase Requests
        $prSql = "SELECT pr.pr_number, pr.status, pri.quantity_requested, pr.created_at
                  FROM purchase_request_items pri
                  INNER JOIN purchase_requests pr ON pr.id = pri.pr_id
                  WHERE pri.product_id = ?
                  ORDER BY pr.created_at DESC, pr.id DESC
                  LIMIT 15";
        if ($st = $conn->prepare($prSql)) {
            $st->bind_param('i', $productId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $addRef(
                    $references,
                    $seenRefs,
                    (string)$row['pr_number'],
                    'PR',
                    'PR ' . $row['pr_number'] . ' (' . ($row['status'] ?? 'N/A') . ')',
                    (int)$row['quantity_requested'],
                    'Requested ' . (int)$row['quantity_requested']
                );
            }
            $st->close();
        }

        // Receiving / Stock-in references
        $rcSql = "SELECT pr.receipt_number, pr.stock_in_number, pr.workflow_status, pr.date_received,
                         pri.quantity_good, pri.quantity_received, pri.stocked_in_qty, po.po_number
                  FROM purchase_receipt_items pri
                  INNER JOIN purchase_receipts pr ON pr.id = pri.receipt_id
                  LEFT JOIN purchase_orders po ON po.id = pr.po_id
                  WHERE pri.product_id = ?
                  ORDER BY pr.date_received DESC, pr.id DESC
                  LIMIT 20";
        if ($st = $conn->prepare($rcSql)) {
            $st->bind_param('i', $productId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $qtyHint = (int)($row['quantity_good'] ?: $row['quantity_received'] ?: 0);
                if (!empty($row['stock_in_number'])) {
                    $addRef(
                        $references,
                        $seenRefs,
                        (string)$row['stock_in_number'],
                        'SI',
                        'Stock-In ' . $row['stock_in_number'],
                        $qtyHint,
                        trim(($row['receipt_number'] ?? '') . (empty($row['po_number']) ? '' : ' / ' . $row['po_number']))
                    );
                }
                if (!empty($row['receipt_number'])) {
                    $addRef(
                        $references,
                        $seenRefs,
                        (string)$row['receipt_number'],
                        'RECEIPT',
                        'Receipt ' . $row['receipt_number'] . ' (' . ($row['workflow_status'] ?? 'N/A') . ')',
                        $qtyHint,
                        empty($row['po_number']) ? null : ('PO ' . $row['po_number'])
                    );
                }
                if (!empty($row['po_number'])) {
                    $addRef(
                        $references,
                        $seenRefs,
                        (string)$row['po_number'],
                        'PO',
                        'PO ' . $row['po_number'] . ' (from receipt)',
                        $qtyHint,
                        (string)($row['receipt_number'] ?? '')
                    );
                }
            }
            $st->close();
        }

        // Prior stock movement references for this product
        $mvSql = "SELECT reference_number, po_number, si_number, dr_number, movement_type, quantity, transaction_date
                  FROM stock_movements
                  WHERE product_id = ?
                  ORDER BY transaction_date DESC, id DESC
                  LIMIT 25";
        if ($st = $conn->prepare($mvSql)) {
            $st->bind_param('i', $productId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                foreach (['po_number' => 'PO', 'si_number' => 'SI', 'dr_number' => 'DR', 'reference_number' => 'MOV'] as $col => $type) {
                    if (!empty($row[$col]) && (string)$row[$col] !== '0') {
                        $addRef(
                            $references,
                            $seenRefs,
                            (string)$row[$col],
                            $type,
                            strtoupper($type) . ' ' . $row[$col] . ' (' . ($row['movement_type'] ?? 'movement') . ')',
                            isset($row['quantity']) ? (int)$row['quantity'] : null,
                            !empty($row['transaction_date']) ? date('Y-m-d', strtotime($row['transaction_date'])) : null
                        );
                    }
                }
            }
            $st->close();
        }

        // Prefer procurement refs (PO/SI/RECEIPT/PR) over generic movement refs for suggested value
        $suggestedReference = '';
        $suggestedQuantity = null;
        foreach (['PO', 'SI', 'RECEIPT', 'PR', 'DR', 'MOV'] as $pref) {
            foreach ($references as $ref) {
                if ($ref['type'] === $pref) {
                    $suggestedReference = $ref['value'];
                    $suggestedQuantity = $ref['quantity'];
                    break 2;
                }
            }
        }

        $batches = [];
        $batchSql = "SELECT id, batch_number, quantity_remaining, quantity, expiry_date, status, received_date
                     FROM product_batches
                     WHERE product_id = ? AND quantity_remaining > 0
                     ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC
                     LIMIT 50";
        if ($st = $conn->prepare($batchSql)) {
            $st->bind_param('i', $productId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $batches[] = [
                    'id' => (int)$row['id'],
                    'batch_number' => (string)$row['batch_number'],
                    'quantity_remaining' => (int)$row['quantity_remaining'],
                    'quantity' => (int)$row['quantity'],
                    'expiry_date' => $row['expiry_date'],
                    'status' => $row['status'],
                    'received_date' => $row['received_date'],
                    'label' => $row['batch_number']
                        . ' — ' . (int)$row['quantity_remaining'] . ' left'
                        . (!empty($row['expiry_date']) ? (' · exp ' . $row['expiry_date']) : ''),
                ];
            }
            $st->close();
        }

        jOut([
            'success' => true,
            'data' => [
                'product' => [
                    'id' => $productId,
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'barcode' => $product['barcode'] ?? '',
                    'brand' => $product['brand'] ?? '',
                    'size' => $product['size'] ?? '',
                    'packaging' => $packaging,
                    'category' => $product['category_name'] ?? 'Uncategorized',
                    'supplier' => $product['supplier_name'] ?? '',
                    'unit' => $uom,
                    'manufacturer' => $product['manufacturer'] ?? '',
                    'description' => $product['description'] ?? '',
                    'status' => $product['status'] ?? '',
                    'reorder_level' => (int)($product['reorder_level'] ?? 0),
                    'unit_price' => (float)($product['unit_price'] ?? 0),
                    'cost_price' => (float)($product['cost_price'] ?? 0),
                    'selling_price' => (float)($product['selling_price'] ?? 0),
                    'expiry_date' => $product['expiry_date'] ?? null,
                ],
                'available_quantity' => $availableQty,
                'available_display' => $availableDisplay,
                'pieces_per_unit' => $piecesPerUnit,
                'suggested_reference' => $suggestedReference,
                'suggested_quantity' => $suggestedQuantity,
                'references' => $references,
                'batches' => $batches,
            ],
        ]);
        break;

    default:
        jOut(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}
$conn->close();
