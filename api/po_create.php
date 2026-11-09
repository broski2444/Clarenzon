<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../procurement_pr.php';

$role = strtolower(str_replace([' ', '-'], '_', trim((string)($_SESSION['role'] ?? ''))));
if (!isset($_SESSION['user_id']) || !in_array($role, ['purchaser', 'admin', 'administrator'], true)) {
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

// CSRF validation
if (!csrf_validate_request()) { http_response_code(403); echo json_encode(['error'=>'Invalid CSRF token']); exit; }

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload || empty($payload['pr_id']) || empty($payload['supplier_id'])) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid payload']);
    exit;
}

$res = createPurchaseOrderFromPR((int)$payload['pr_id'], (int)$payload['supplier_id'], (int)$_SESSION['user_id'], $payload['delivery_date'] ?? null);
if ($res) echo json_encode(['success'=>true,'po'=>$res]); else { http_response_code(500); echo json_encode(['error'=>'Failed to create PO']); }

?>
