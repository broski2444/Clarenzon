<?php
/**
 * Customer returns (Return Policy) API.
 * Record: Admin, Warehouse Head, Cashier. Review (approve / reject): Admin, Warehouse Head. Policy: Admin.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
header('Content-Type: application/json');

clarenzon_api_require(['admin', 'warehouse_head', 'cashier']);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'notifications_api.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sales_order_lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sales_return_lib.php';

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'], 'root', '', 3);

function srOut(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (!$conn) {
    srOut(['success' => false, 'message' => 'Database connection failed.'], 500);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if (function_exists('clarenzon_api_guard_method')) {
    clarenzon_api_guard_method($action);
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$roleKey = normalize_user_role((string)($_SESSION['role'] ?? ''));
srEnsureSchema($conn);
ob_start();

switch ($action) {
    case 'get_policy':
        srOut([
            'success' => true,
            'policy' => srLoadPolicy($conn),
            'reasons' => srReasons(),
            'conditions' => srConditions(),
            'outcomes' => srOutcomes(),
            'can_review' => srCanReview($roleKey),
            'can_edit_policy' => $roleKey === 'admin',
            'user_id' => $uid,
        ]);
        break;

    case 'save_policy':
        if ($roleKey !== 'admin') {
            srOut(['success' => false, 'message' => 'Only an Administrator can change the return policy.'], 403);
        }
        $saved = srSavePolicy($conn, $_POST, $uid);
        srOut(['success' => !empty($saved['ok'])] + $saved);
        break;

    case 'lookup_sale':
        $ref = trim((string)($_GET['reference'] ?? ''));
        if ($ref === '') {
            srOut(['success' => false, 'message' => 'Enter a sales order, invoice, or official receipt number.']);
        }
        $soId = srFindSalesOrderId($conn, $ref);
        $pack = srLoadSaleForReturn($conn, $soId);
        if (empty($pack['ok'])) {
            srOut(['success' => false, 'message' => $pack['message'] ?? 'Sale not found.']);
        }
        srOut(['success' => true, 'reference' => $ref] + $pack);
        break;

    case 'create_return':
        $result = srCreateReturn($conn, $_POST, $uid);
        srOut(['success' => !empty($result['ok'])] + $result);
        break;

    case 'review_return':
        $overrides = json_decode((string)($_POST['conditions'] ?? '{}'), true);
        $result = srReviewReturn(
            $conn,
            (int)($_POST['id'] ?? 0),
            (string)($_POST['decision'] ?? ''),
            (string)($_POST['remarks'] ?? ''),
            is_array($overrides) ? $overrides : [],
            $uid,
            $roleKey
        );
        srOut(['success' => !empty($result['ok'])] + $result);
        break;

    case 'list_returns':
        srOut(['success' => true, 'data' => srListReturns($conn, $_GET)]);
        break;

    case 'get_return':
        $ret = srGetReturn($conn, (int)($_GET['id'] ?? 0));
        if (!$ret) {
            srOut(['success' => false, 'message' => 'Return not found.'], 404);
        }
        $ret['can_review'] = srCanReview($roleKey) && $ret['status'] === SR_STATUS_PENDING
            && ($roleKey === 'admin' || (int)$ret['created_by'] !== $uid);
        srOut(['success' => true, 'data' => $ret]);
        break;

    default:
        srOut(['success' => false, 'message' => 'Unknown action.'], 400);
}
