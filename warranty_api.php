<?php
/**
 * Product warranty certificates API.
 * Issue / view / search / reprint: Admin, Warehouse Head, Cashier, Sales.
 * Void / reissue: Admin, Warehouse Head, or users granted can_manage_warranty.
 * Product warranty settings and default terms: Admin, or users granted can_manage_warranty.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
header('Content-Type: application/json');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'warranty_lib.php';
clarenzon_api_require(warrantyIssuerRoles());

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'], 'root', '', 3);

function wcOut(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (!$conn) {
    wcOut(['success' => false, 'message' => 'Database connection failed.'], 500);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if (function_exists('clarenzon_api_guard_method')) {
    clarenzon_api_guard_method($action);
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$roleKey = normalize_user_role((string)($_SESSION['role'] ?? ''));
warrantyEnsureSchema($conn);
$canManage = warrantyCanManage($conn, $uid, $roleKey);
$canVoid = warrantyCanVoid($conn, $uid, $roleKey);
ob_start();

switch ($action) {
    case 'get_meta':
        wcOut([
            'success' => true,
            'can_issue' => warrantyCanIssue($roleKey),
            'can_manage' => $canManage,
            'can_void' => $canVoid,
            'terms' => warrantyLoadTerms($conn),
            'default_terms' => warrantyDefaultTerms(),
            'units' => warrantyDurationUnits(),
        ]);
        break;

    case 'get_sale_warranty':
        $soId = (int)($_GET['so_id'] ?? 0);
        $ref = trim((string)($_GET['reference'] ?? ''));
        if ($soId <= 0 && $ref !== '') {
            $soId = warrantyFindSalesOrderId($conn, $ref);
        }
        $pack = warrantyLoadSale($conn, $soId);
        if (empty($pack['ok'])) {
            wcOut(['success' => false, 'message' => $pack['message'] ?? 'Sale not found.']);
        }
        wcOut(['success' => true, 'can_void' => $canVoid] + $pack);
        break;

    case 'issue_warranty':
        $result = warrantyIssue($conn, $_POST, $uid, $canVoid);
        wcOut(['success' => !empty($result['ok'])] + $result);
        break;

    case 'void_warranty':
        if (!$canVoid) {
            wcOut(['success' => false, 'message' => 'You do not have permission to void warranty certificates.'], 403);
        }
        $result = warrantyVoid($conn, (int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''), $uid);
        wcOut(['success' => !empty($result['ok'])] + $result);
        break;

    case 'log_print':
        $result = warrantyLogPrint($conn, (int)($_POST['id'] ?? 0), $uid);
        wcOut(['success' => !empty($result['ok'])] + $result);
        break;

    case 'list_warranties':
        wcOut(['success' => true, 'data' => warrantyList($conn, $_GET)]);
        break;

    case 'get_warranty':
        $cert = warrantyGet($conn, (int)($_GET['id'] ?? 0), true);
        if (!$cert) {
            wcOut(['success' => false, 'message' => 'Certificate not found.'], 404);
        }
        unset($cert['active_key']);
        wcOut(['success' => true, 'data' => $cert, 'can_void' => $canVoid]);
        break;

    case 'get_product_warranty':
        $productId = (int)($_GET['product_id'] ?? 0);
        $sku = trim((string)($_GET['sku'] ?? ''));
        if ($productId <= 0 && $sku !== '') {
            $pq = $conn->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
            $pq->bind_param('s', $sku);
            $pq->execute();
            $productId = (int)($pq->get_result()->fetch_assoc()['id'] ?? 0);
            $pq->close();
        }
        if ($productId <= 0) {
            wcOut(['success' => false, 'message' => 'Product not found.'], 404);
        }
        wcOut(['success' => true, 'data' => warrantyGetProductSettings($conn, $productId), 'can_manage' => $canManage]);
        break;

    case 'list_product_warranty':
        wcOut([
            'success' => true,
            'data' => warrantyListProductSettings($conn, (string)($_GET['search'] ?? ''), !empty($_GET['eligible_only'])),
            'can_manage' => $canManage,
        ]);
        break;

    case 'save_product_warranty':
        if (!$canManage) {
            wcOut(['success' => false, 'message' => 'You do not have permission to configure warranty coverage.'], 403);
        }
        $result = warrantySaveProductSettings($conn, (int)($_POST['product_id'] ?? 0), $_POST, $uid);
        wcOut(['success' => !empty($result['ok'])] + $result);
        break;

    case 'save_terms':
        if (!$canManage) {
            wcOut(['success' => false, 'message' => 'You do not have permission to edit the warranty terms.'], 403);
        }
        $result = warrantySaveTerms($conn, (string)($_POST['terms'] ?? ''), $uid);
        wcOut(['success' => !empty($result['ok'])] + $result);
        break;

    default:
        wcOut(['success' => false, 'message' => 'Unknown action.'], 400);
}
