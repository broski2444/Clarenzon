<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';

clarenzon_api_require();

function ensureNotificationsSchema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        role VARCHAR(50) NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) NOT NULL,
        priority ENUM('low', 'medium', 'high') DEFAULT 'low',
        related_id VARCHAR(100) NULL,
        related_module VARCHAR(100) NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_read (user_id, is_read),
        INDEX idx_role_read (role, is_read),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        setting_type VARCHAR(50) DEFAULT 'string',
        description TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$currentUserRole = $_SESSION['role'] ?? '';
$currentUserId = $_SESSION['user_id'] ?? 0;

// Connect to Database
require_once __DIR__ . '/mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'], 'root', '', 3);

if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

ensureNotificationsSchema($conn);

// ─── HELPER FUNCTIONS ───

/**
 * Creates a notification record in the database.
 */
function createNotification($conn, $title, $message, $type, $priority = 'low', $related_id = null, $related_module = null, $target_role = null, $target_user_id = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (title, message, type, priority, related_id, related_module, role, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $user_id_param = ($target_user_id > 0) ? (int)$target_user_id : null;
        $stmt->bind_param("sssssssi", $title, $message, $type, $priority, $related_id, $related_module, $target_role, $user_id_param);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    return false;
}

/**
 * Dynamically check reorder thresholds and expiry dates to auto-generate notifications.
 */
function checkDynamicAlerts($conn) {
    $creditLib = __DIR__ . '/credit_monitor_lib.php';
    if (is_file($creditLib)) {
        require_once $creditLib;
        if (function_exists('creditMonitorSweep')) {
            creditMonitorSweep($conn);
        }
    }
    // 1. Check Low Stock Items
    $lowRes = $conn->query("SELECT sku, name, quantity_in_stock, reorder_level FROM products WHERE quantity_in_stock <= reorder_level AND reorder_level > 0 AND status != 'Discontinued'");
    if ($lowRes) {
        while ($p = $lowRes->fetch_assoc()) {
            $sku = $p['sku'];
            $title = "Low Stock Alert: " . $p['name'];
            $msg = "Product " . $p['name'] . " (SKU: $sku) has reached low stock level (" . $p['quantity_in_stock'] . " remaining, threshold is " . $p['reorder_level'] . ").";
            
            // Look for existing unread low stock notification for this SKU
            $stmt = $conn->prepare("SELECT id FROM notifications WHERE type = 'low_stock' AND related_id = ? AND is_read = 0 LIMIT 1");
            $stmt->bind_param("s", $sku);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            
            if (!$exists) {
                // Trigger for warehouse roles and administrators
                createNotification($conn, $title, $msg, 'low_stock', 'high', $sku, 'low-stock-view', 'warehouse_head');
                createNotification($conn, $title, $msg, 'low_stock', 'high', $sku, 'low-stock-view', 'admin');
            }
        }
    }
    
    // 2. Fetch Near-Expiry Days Limit
    $limitDays = 30;
    $setRes = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'expiry_alert_days' LIMIT 1");
    if ($setRes && $setRow = $setRes->fetch_assoc()) {
        $limitDays = (int)$setRow['setting_value'];
    }
    
    // 3. Check Near-Expiry Items
    $expRes = $conn->query("SELECT sku, name, expiry_date FROM products WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $limitDays DAY) AND status != 'Discontinued'");
    if ($expRes) {
        while ($p = $expRes->fetch_assoc()) {
            $sku = $p['sku'];
            $title = "Expiry Warning: " . $p['name'];
            $msg = "Batch product " . $p['name'] . " (SKU: $sku) is expiring soon on " . date('M d, Y', strtotime($p['expiry_date'])) . ".";
            
            // Look for existing unread near expiry notification for this SKU
            $stmt = $conn->prepare("SELECT id FROM notifications WHERE type = 'near_expiry' AND related_id = ? AND is_read = 0 LIMIT 1");
            $stmt->bind_param("s", $sku);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            
            if (!$exists) {
                createNotification($conn, $title, $msg, 'near_expiry', 'high', $sku, 'expiry-view', 'warehouse_head');
                createNotification($conn, $title, $msg, 'near_expiry', 'high', $sku, 'expiry-view', 'admin');
            }
        }
    }
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'notifications_api.php') {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    if (function_exists('clarenzon_api_guard_method')) {
        clarenzon_api_guard_method((string)$action);
    }
    header('Content-Type: application/json');

    switch ($action) {
        case 'get-notifications':
            checkDynamicAlerts($conn);
            require_once __DIR__ . '/procurement_receiving_lib.php';
            if (function_exists('recvClearStaleMarkupQueueNotifications')) {
                recvClearStaleMarkupQueueNotifications($conn);
            }
            
            // Count unread notifications matching target user's role OR targeted user id specifically
            // Normalize role key representation
            $roleParam = strtolower(trim((string)$currentUserRole));
            if ($roleParam === 'administrator') $roleParam = 'admin';
            
            $countQuery = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE is_read = 0 AND (user_id = ? OR role = ? OR (role IS NULL AND user_id IS NULL))");
            $countQuery->bind_param("is", $currentUserId, $roleParam);
            $countQuery->execute();
            $unreadCount = (int)$countQuery->get_result()->fetch_assoc()['unread_count'];
            $countQuery->close();
            
            // Fetch latest 10 unread or recently created
            $listQuery = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? OR role = ? OR (role IS NULL AND user_id IS NULL) ORDER BY created_at DESC LIMIT 10");
            $listQuery->bind_param("is", $currentUserId, $roleParam);
            $listQuery->execute();
            $res = $listQuery->get_result();
            $items = [];
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }
            $listQuery->close();
            
            echo json_encode(['success' => true, 'count' => $unreadCount, 'notifications' => $items]);
            break;

        case 'get-sidebar-badges':
            require_once __DIR__ . '/procurement_receiving_lib.php';
            if (function_exists('recvClearStaleMarkupQueueNotifications')) {
                recvClearStaleMarkupQueueNotifications($conn);
            }
            $roleParam = strtolower(trim((string)$currentUserRole));
            if ($roleParam === 'administrator') $roleParam = 'admin';

            $badgeQuery = $conn->prepare(
                "SELECT COALESCE(NULLIF(related_module, ''), 'notifications') AS section_key, COUNT(*) AS cnt
                 FROM notifications
                 WHERE is_read = 0 AND (user_id = ? OR role = ? OR (role IS NULL AND user_id IS NULL))
                 GROUP BY section_key"
            );
            $sections = [];
            $total = 0;
            if ($badgeQuery) {
                $badgeQuery->bind_param('is', $currentUserId, $roleParam);
                $badgeQuery->execute();
                $badgeRes = $badgeQuery->get_result();
                while ($badgeRow = $badgeRes->fetch_assoc()) {
                    $key = (string)($badgeRow['section_key'] ?? '');
                    $cnt = (int)($badgeRow['cnt'] ?? 0);
                    if ($key === '' || $cnt <= 0) continue;
                    $sections[$key] = $cnt;
                    $total += $cnt;
                }
                $badgeQuery->close();
            }
            echo json_encode(['success' => true, 'total' => $total, 'sections' => $sections]);
            break;

        case 'mark-read':
            $notifId = (int)($_POST['id'] ?? 0);
            if ($notifId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID.']);
                break;
            }
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $stmt->bind_param("i", $notifId);
            $success = $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => $success]);
            break;

        case 'mark-all-read':
            $roleParam = strtolower(trim((string)$currentUserRole));
            if ($roleParam === 'administrator') $roleParam = 'admin';
            
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND (user_id = ? OR role = ? OR (role IS NULL AND user_id IS NULL))");
            $stmt->bind_param("is", $currentUserId, $roleParam);
            $success = $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => $success]);
            break;

        case 'load-history':
            $roleParam = strtolower(trim((string)$currentUserRole));
            if ($roleParam === 'administrator') $roleParam = 'admin';
            
            // Read filters
            $filterType = trim($_GET['type'] ?? '');
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo = trim($_GET['date_to'] ?? '');
            $search = trim($_GET['search'] ?? '');
            
            $queryStr = "SELECT * FROM notifications WHERE (user_id = ? OR role = ? OR (role IS NULL AND user_id IS NULL))";
            $types = "is";
            $params = [$currentUserId, $roleParam];
            
            if ($filterType !== '') {
                $queryStr .= " AND type = ?";
                $types .= "s";
                $params[] = $filterType;
            }
            if ($dateFrom !== '') {
                $queryStr .= " AND DATE(created_at) >= ?";
                $types .= "s";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $queryStr .= " AND DATE(created_at) <= ?";
                $types .= "s";
                $params[] = $dateTo;
            }
            if ($search !== '') {
                $queryStr .= " AND (title LIKE ? OR message LIKE ?)";
                $types .= "ss";
                $likeStr = "%" . $search . "%";
                $params[] = $likeStr;
                $params[] = $likeStr;
            }
            
            $queryStr .= " ORDER BY created_at DESC LIMIT 100";
            
            $stmt = $conn->prepare($queryStr);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $history = [];
                while ($row = $res->fetch_assoc()) {
                    $history[] = $row;
                }
                $stmt->close();
                echo json_encode(['success' => true, 'history' => $history]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to prepare query: ' . $conn->error]);
            }
            break;

        case 'update-settings':
            $days = (int)($_POST['expiry_days'] ?? 30);
            if (!in_array($days, [30, 60, 90], true)) {
                echo json_encode(['success' => false, 'message' => 'Threshold must be 30, 60, or 90 days.']);
                break;
            }
            
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES ('expiry_alert_days', ?, 'integer', 'Timing threshold for near-expiry alerts') ON DUPLICATE KEY UPDATE setting_value = ?");
            if ($stmt) {
                $daysStr = (string)$days;
                $stmt->bind_param("ss", $daysStr, $daysStr);
                $success = $stmt->execute();
                $stmt->close();
                
                checkDynamicAlerts($conn);
                
                echo json_encode(['success' => $success, 'message' => 'Expiration threshold timing updated to ' . $days . ' days.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to execute settings update.']);
            }
            break;

        case 'get-settings':
            $limitDays = 30;
            $setRes = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'expiry_alert_days' LIMIT 1");
            if ($setRes && $setRow = $setRes->fetch_assoc()) {
                $limitDays = (int)$setRow['setting_value'];
            }
            echo json_encode(['success' => true, 'expiry_alert_days' => $limitDays]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
            break;
    }
    $conn->close();
}
?>
