<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
header('Content-Type: application/json');

require_once 'warehouse_permissions.php';

if (empty($_SESSION['user_id']) && function_exists('clarenzon_session_start_any_authenticated')) {
    clarenzon_session_start_any_authenticated();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$barcodeRaw = trim((string)($_POST['barcode'] ?? ''));
$barcode = strtoupper($barcodeRaw);
$movementInput = strtolower(trim((string)($_POST['movement_type'] ?? 'stock_in')));
$quantity = (int)($_POST['quantity'] ?? 0);
$reference = trim((string)($_POST['reference'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($barcode === '' || $quantity <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Barcode and quantity are required']);
    exit();
}

if (!in_array($movementInput, ['stock_in', 'stock_out'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid movement type']);
    exit();
}

function getProcessConnection() {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
    return clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db']) ?: false;
}

$conn = getProcessConnection();
if ($conn === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$col = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
$hasBarcodeCol = $col && $col->num_rows > 0;
if (!$hasBarcodeCol) {
    $conn->query("ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL AFTER name");
    $col = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
    $hasBarcodeCol = $col && $col->num_rows > 0;
}
if ($hasBarcodeCol) {
    $idx = $conn->query("SHOW INDEX FROM products WHERE Column_name = 'barcode' AND Non_unique = 0");
    if (!($idx && $idx->num_rows > 0)) {
        $conn->query("ALTER TABLE products ADD UNIQUE INDEX uq_products_barcode (barcode)");
    }
}
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$role = (string)($_SESSION['role'] ?? '');
$canManage = canScannerModifyStock($role);

if (!$canManage) {
    $conn->close();
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'title' => 'Access Denied',
        'message' => 'You do not have permission to use the mobile barcode scanner.',
    ]);
    exit();
}

require_once __DIR__ . '/product_barcodes_lib.php';
productBarcodesEnsureSchema($conn);

if ($hasBarcodeCol) {
    $linkedId = productBarcodesFindProductId($conn, $barcodeRaw !== '' ? $barcodeRaw : $barcode);
    if ($linkedId > 0) {
        $stmt = $conn->prepare("SELECT id, sku, name, quantity_in_stock FROM products WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $linkedId);
    } else {
        $stmt = $conn->prepare("SELECT id, sku, name, quantity_in_stock FROM products WHERE UPPER(barcode) = ? OR UPPER(sku) = ? LIMIT 1");
        $stmt->bind_param("ss", $barcode, $barcode);
    }
} else {
    $stmt = $conn->prepare("SELECT id, sku, name, quantity_in_stock FROM products WHERE UPPER(sku) = ? LIMIT 1");
    $stmt->bind_param("s", $barcode);
}
$stmt->execute();
$res = $stmt->get_result();
$product = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$product) {
    $conn->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Product not found. Please register the product first.']);
    exit();
}

$productId = (int)$product['id'];
$currentStock = (int)$product['quantity_in_stock'];
$newStock = $movementInput === 'stock_in'
    ? ($currentStock + $quantity)
    : ($currentStock - $quantity);

if ($newStock < 0) {
    $conn->close();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Insufficient stock for stock-out']);
    exit();
}

$status = $newStock <= 30 ? 'Low Stock' : 'Active';
$movementType = $movementInput === 'stock_in' ? 'Stock In (Receipt)' : 'Stock Out (Issue)';
$userId = (int)$_SESSION['user_id'];
$refNumber = $reference !== '' ? $reference : strtoupper(($movementInput === 'stock_in' ? 'SCAN-IN-' : 'SCAN-OUT-') . date('Ymd-His'));

$conn->begin_transaction();
try {
    $update = $conn->prepare("UPDATE products SET quantity_in_stock = ?, status = ? WHERE id = ?");
    $update->bind_param("isi", $newStock, $status, $productId);
    if (!$update->execute()) {
        $update->close();
        throw new RuntimeException('Failed to update product stock');
    }
    $update->close();

    $conn->query("CREATE TABLE IF NOT EXISTS scan_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        device_used VARCHAR(50) NOT NULL,
        scanned_code VARCHAR(255) NOT NULL,
        action_performed VARCHAR(50) NOT NULL,
        product_id INT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB");

    $move = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_number, user_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $move->bind_param("isisis", $productId, $movementType, $quantity, $refNumber, $userId, $notes);
    if (!$move->execute()) {
        $move->close();
        throw new RuntimeException('Failed to save stock movement');
    }
    $movementId = (int)$move->insert_id;
    $move->close();

    $deviceUsed = trim((string)($_POST['device_used'] ?? 'scanner'));
    $actionPerf = $movementInput === 'stock_in' ? 'stock_in' : 'stock_out';
    $logStmt = $conn->prepare("INSERT INTO scan_history (user_id, device_used, scanned_code, action_performed, product_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
    if ($logStmt) {
        $logStmt->bind_param("isssis", $userId, $deviceUsed, $barcodeRaw, $actionPerf, $productId, $notes);
        $logStmt->execute();
        $logStmt->close();
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Scan processed successfully',
        'data' => [
            'movement_id' => $movementId,
            'product_id' => $productId,
            'sku' => $product['sku'],
            'name' => $product['name'],
            'movement_type' => $movementInput,
            'quantity' => $quantity,
            'new_stock' => $newStock
        ]
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
