<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['error'=>'Unauthorized']); exit; }

$poId = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if (!$poId) { echo json_encode(['success'=>false,'error'=>'po_id required']); exit; }

$conn = getDBConnection();
if (!$conn) { echo json_encode(['success'=>false,'error'=>'db']); exit; }

$stmt = $conn->prepare(
    "SELECT poi.*,
            COALESCE(p.sku, '') AS product_sku,
            COALESCE(NULLIF(poi.product_name, ''), p.name, '') AS product_name
     FROM purchase_order_items poi
     LEFT JOIN products p ON poi.product_id = p.id
     WHERE poi.po_id = ?
     ORDER BY poi.id"
);
$res = false;
if ($stmt) {
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
}
$items = [];
if ($res) { while($r = $res->fetch_assoc()) $items[] = $r; }
if ($stmt) $stmt->close();

echo json_encode(['success'=>true,'items'=>$items]);

?>
