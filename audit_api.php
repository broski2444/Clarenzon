<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . '/route_protection.php';

header('Content-Type: application/json; charset=utf-8');

clarenzon_api_require();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mysqli_connect_fast.php';

$conn = clarenzon_db_connect('root', '', 'clarenzon_db', 3);

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function ensureAuditLogSchemaApi(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(100) NOT NULL,
            user_role VARCHAR(100) NOT NULL,
            action_key VARCHAR(100) NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
}

function checkTableExistsApi(mysqli $conn, string $table): bool {
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return ($res && $res->num_rows > 0);
}

ensureAuditLogSchemaApi($conn);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? '');
$role = (string)($_SESSION['role'] ?? '');
$roleKey = normalize_user_role($role);

$isSystemAdmin = in_array($roleKey, ['administrator', 'admin'], true);
$isWarehouseHead = in_array($roleKey, ['warehouse_head', 'warehousehead'], true);
$isPurchaser = ($roleKey === 'purchaser');
$isTechnician = in_array($roleKey, ['technician', 'techinician'], true);

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_logs';
if (function_exists('clarenzon_api_guard_method')) {
    clarenzon_api_guard_method((string)$action);
}

// Handle API Mutation Actions (Delete, Edit, Delete All)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete_log' || isset($_POST['delete_audit_log'])) {
        if (!$isSystemAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '403 Forbidden']);
            $conn->close();
            exit;
        }
        $logId = (int)($_POST['audit_log_id'] ?? $_GET['audit_log_id'] ?? 0);
        if ($logId > 0) {
            $stmt = $conn->prepare("DELETE FROM audit_logs WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $logId);
                $stmt->execute();
                $stmt->close();
                echo json_encode(['success' => true, 'message' => 'Audit log deleted successfully']);
                $conn->close();
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Invalid Log ID']);
        $conn->close();
        exit;
    }

    if ($action === 'delete_all' || isset($_POST['delete_all_audit_logs'])) {
        if (!$isSystemAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '403 Forbidden']);
            $conn->close();
            exit;
        }
        $conn->query("DELETE FROM audit_logs");
        echo json_encode(['success' => true, 'message' => 'All audit logs deleted']);
        $conn->close();
        exit;
    }

    if ($action === 'update_log' || isset($_POST['update_audit_log'])) {
        if (!$isSystemAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '403 Forbidden']);
            $conn->close();
            exit;
        }
        $logId = (int)($_POST['audit_log_id'] ?? 0);
        $details = trim((string)($_POST['audit_log_details'] ?? ''));
        if ($logId > 0 && $details !== '') {
            $stmt = $conn->prepare("UPDATE audit_logs SET details = ? WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("si", $details, $logId);
                $stmt->execute();
                $stmt->close();
                echo json_encode(['success' => true, 'message' => 'Audit log updated successfully']);
                $conn->close();
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        $conn->close();
        exit;
    }
}

// Default GET: Fetch Realtime Audit Logs
$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'audit_logs' => [],
    'warehouse_audit_logs' => [],
    'purchaser_audit_logs' => [],
    'technician_audit_logs' => [],
    'recent_activity' => [],
];

if (checkTableExistsApi($conn, 'audit_logs')) {
    // 1. Admin System Audit Logs (All)
    if ($isSystemAdmin) {
        $res = $conn->query("SELECT id, username, user_role, action_key, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 200");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $response['audit_logs'][] = $row;
            }
        }
    }

    // 2. Warehouse Audit Logs
    if ($isWarehouseHead || $isSystemAdmin) {
        $stmt = $conn->prepare(
            "SELECT id, username, user_role, action_key, details, created_at
             FROM audit_logs
             WHERE user_id = ?
                OR action_key LIKE '%product%'
                OR action_key LIKE '%movement%'
                OR action_key LIKE '%stock%'
                OR action_key LIKE '%inventory%'
                OR action_key LIKE '%serial%'
                OR action_key LIKE '%batch%'
                OR action_key LIKE '%scan%'
                OR action_key LIKE '%return%'
             ORDER BY id DESC
             LIMIT 200"
        );
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $response['warehouse_audit_logs'][] = $row;
            }
            $stmt->close();
        }
    }

    // 3. Technician Audit Logs
    if ($isTechnician || $isSystemAdmin) {
        $stmt = $conn->prepare(
            "SELECT id, username, user_role, action_key, details, created_at
             FROM audit_logs
             WHERE user_id = ? AND (action_key LIKE 'technician_%' OR action_key = 'login')
             ORDER BY id DESC
             LIMIT 100"
        );
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $response['technician_audit_logs'][] = $row;
            }
            $stmt->close();
        }
    }

    // 4. Dashboard Feed (Recent Activity) — Cashier sees only their own actions
    $res = $roleKey === 'cashier'
        ? $conn->query("SELECT username, user_role, action_key, details, created_at FROM audit_logs WHERE user_id = " . (int)$user_id . " ORDER BY id DESC LIMIT 20")
        : $conn->query("SELECT username, user_role, action_key, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 20");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $response['recent_activity'][] = $row;
        }
    }
}

// 5. Purchaser Audit Logs
if ($isPurchaser || $isSystemAdmin) {
    if (checkTableExistsApi($conn, 'audit_log')) {
        $stmt = $conn->prepare(
            "SELECT al.id, al.action_type, al.table_name, al.record_id, al.new_values, al.ip_address, al.created_at,
                    COALESCE(u.full_name, u.username, ?) AS user_name
             FROM audit_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE al.user_id = ?
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT 200"
        );
        if ($stmt) {
            $fallbackName = $username;
            $stmt->bind_param('si', $fallbackName, $user_id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $response['purchaser_audit_logs'][] = $row;
            }
            $stmt->close();
        }
    }

    if (empty($response['purchaser_audit_logs']) && checkTableExistsApi($conn, 'audit_logs')) {
        $stmt = $conn->prepare("SELECT id, username, user_role, action_key, details, created_at FROM audit_logs WHERE user_id = ? OR user_role = 'purchaser' OR action_key LIKE '%po%' OR action_key LIKE '%pr%' ORDER BY id DESC LIMIT 200");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $response['purchaser_audit_logs'][] = [
                    'id' => $row['id'],
                    'user_name' => $row['username'],
                    'action_type' => $row['action_key'],
                    'table_name' => 'system',
                    'record_id' => $row['id'],
                    'new_values' => $row['details'],
                    'created_at' => $row['created_at']
                ];
            }
            $stmt->close();
        }
    }
}

$conn->close();
echo json_encode($response);
