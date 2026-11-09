<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../procurement_pr.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$filters = [];
if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
if (isset($_GET['prepared_by'])) $filters['prepared_by'] = (int)$_GET['prepared_by'];
if (isset($_GET['pr_id'])) $filters['pr_id'] = (int)$_GET['pr_id'];
if (isset($_GET['po_id'])) $filters['po_id'] = (int)$_GET['po_id'];

// purchasers see only their prepared POs; admin sees all
if (strtolower($_SESSION['role'] ?? '') === 'purchaser') {
    $filters['prepared_by'] = (int)$_SESSION['user_id'];
}

$rows = getPurchaseOrders($filters);
echo json_encode(['success'=>true,'data'=>$rows]);

?>
