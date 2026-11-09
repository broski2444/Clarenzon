<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
require_once 'warehouse_permissions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'] ?? '';
$isWarehouseHead = isAdminWarehouseRole($role);
$isWarehouseAssistant = isWarehouseAssistantRole($role);

$conn = clarenzon_db_connect_first(['inventory_db', 'clarenzon_db']);
if (!$conn) {
    die("Connection failed: database is unavailable.");
}

$permissions = getWarehouseAssistantPermissions($conn);
$assistantCanPrintReceipts = !empty($permissions['can_print_receipts']);

if (!$isWarehouseHead && !($isWarehouseAssistant && $assistantCanPrintReceipts)) {
    $conn->close();
    http_response_code(403);
    echo "Printing receipts is not allowed for your account.";
    exit();
}

$movementId = isset($_GET['movement_id']) ? (int)$_GET['movement_id'] : 0;
if ($movementId <= 0) {
    $conn->close();
    http_response_code(400);
    echo "Invalid movement ID.";
    exit();
}

$stmt = $conn->prepare(
    "SELECT
        sm.id,
        sm.reference_number,
        sm.transaction_date,
        sm.movement_type,
        sm.quantity,
        sm.notes,
        p.sku,
        p.name AS product_name,
        COALESCE(u.username, 'System') AS handled_by
     FROM stock_movements sm
     INNER JOIN products p ON sm.product_id = p.id
     LEFT JOIN users u ON sm.user_id = u.id
     WHERE sm.id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $movementId);
$stmt->execute();
$result = $stmt->get_result();
$movement = $result ? $result->fetch_assoc() : null;
$stmt->close();
$conn->close();

if (!$movement) {
    http_response_code(404);
    echo "Receipt not found.";
    exit();
}

$ref = $movement['reference_number'] ?: ('MV-' . str_pad((string)$movement['id'], 3, '0', STR_PAD_LEFT));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #111827; }
        .receipt { max-width: 720px; margin: 0 auto; border: 1px solid #d1d5db; padding: 24px; border-radius: 10px; }
        h1 { margin: 0 0 16px; font-size: 22px; }
        .meta { margin-bottom: 18px; color: #4b5563; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #e5e7eb; text-align: left; padding: 10px; font-size: 14px; }
        th { background: #f9fafb; width: 180px; }
        .print-wrap { margin: 16px auto; max-width: 720px; }
        button { padding: 10px 14px; border: 1px solid #1d4ed8; background: #2563eb; color: white; border-radius: 8px; cursor: pointer; }
        @media print { .print-wrap { display: none; } body { margin: 0; } .receipt { border: none; } }
        @media screen and (max-width: 520px) {
            body { margin: 0; padding: 12px; }
            .receipt { max-width: 100%; padding: 14px; overflow-x: auto; }
            .print-wrap { max-width: 100%; }
            th { width: 120px; }
            th, td { overflow-wrap: anywhere; }
            button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="print-wrap">
        <button onclick="window.print()">Print Receipt</button>
    </div>
    <div class="receipt">
        <h1>Warehouse Movement Receipt</h1>
        <div class="meta">Reference: <strong><?= htmlspecialchars($ref) ?></strong></div>
        <table>
            <tr><th>Date</th><td><?= htmlspecialchars((string)$movement['transaction_date']) ?></td></tr>
            <tr><th>Product</th><td><?= htmlspecialchars((string)$movement['product_name']) ?></td></tr>
            <tr><th>SKU</th><td><?= htmlspecialchars((string)$movement['sku']) ?></td></tr>
            <tr><th>Movement Type</th><td><?= htmlspecialchars((string)$movement['movement_type']) ?></td></tr>
            <tr><th>Quantity</th><td><?= (int)$movement['quantity'] ?></td></tr>
            <tr><th>Handled By</th><td><?= htmlspecialchars((string)$movement['handled_by']) ?></td></tr>
            <tr><th>Notes</th><td><?= htmlspecialchars((string)($movement['notes'] ?? '')) ?></td></tr>
        </table>
    </div>
</body>
</html>
