<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../procurement_pr.php';

$role = strtolower(str_replace([' ', '-'], '_', trim((string)($_SESSION['role'] ?? ''))));
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'administrator'], true)) {
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

// CSRF validation
if (!csrf_validate_request()) { http_response_code(403); echo json_encode(['error'=>'Invalid CSRF token']); exit; }

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload || empty($payload['pr_id']) || !isset($payload['approve'])) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid payload']);
    exit;
}

$ok = approvePurchaseRequest((int)$payload['pr_id'], (int)$_SESSION['user_id'], (bool)$payload['approve'], $payload['remarks'] ?? null);
if ($ok) echo json_encode(['success'=>true]); else { http_response_code(500); echo json_encode(['error'=>'Failed']); }

?>
