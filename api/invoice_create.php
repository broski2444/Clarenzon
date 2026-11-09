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

// CSRF validation
if (!csrf_validate_request()) { http_response_code(403); echo json_encode(['error'=>'Invalid CSRF token']); exit; }

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload || empty($payload['po_id']) || empty($payload['receipt_id']) || empty($payload['items']) || !isset($payload['total_amount'])) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid payload']);
    exit;
}

$res = createSupplierInvoice((int)$payload['po_id'], (int)$payload['receipt_id'], (int)$payload['supplier_id'], $payload['items'], (float)$payload['total_amount'], $payload['invoice_date'] ?? null);
if ($res) echo json_encode(['success'=>true,'invoice'=>$res]); else { http_response_code(500); echo json_encode(['error'=>'Failed to create invoice']); }

?>
