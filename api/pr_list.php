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
if (isset($_GET['requested_by'])) $filters['requested_by'] = (int)$_GET['requested_by'];
if (isset($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
if (isset($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];

// purchasers only see their own PRs unless admin
if (strtolower($_SESSION['role'] ?? '') === 'purchaser') {
    $filters['requested_by'] = (int)$_SESSION['user_id'];
}

$rows = getPurchaseRequests($filters);
echo json_encode(['success'=>true,'data'=>$rows]);

?>
