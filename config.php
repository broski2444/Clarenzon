<?php
// Prevent fatal exceptions when MySQL is temporarily unavailable
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';

$clarDb = function_exists('clarenzon_db_settings') ? clarenzon_db_settings() : [
    'hosts' => ['127.0.0.1'],
    'user' => 'root',
    'pass' => '',
    'name' => 'clarenzon_db',
    'create_database' => true,
];
if (!defined('DB_HOST')) {
    define('DB_HOST', (string)($clarDb['hosts'][0] ?? '127.0.0.1'));
}
if (!defined('DB_USER')) {
    define('DB_USER', (string)($clarDb['user'] ?? 'root'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', (string)($clarDb['pass'] ?? ''));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', (string)($clarDb['name'] ?? 'clarenzon_db'));
}

// Create database connection
function getDBConnection() {
    $conn = clarenzon_db_connect();
    return $conn ?: false;
}

// Initialize database and tables if they don't exist
function initializeDatabase() {
    $cfg = function_exists('clarenzon_db_settings') ? clarenzon_db_settings() : [
        'name' => DB_NAME,
        'create_database' => true,
    ];
    if (!empty($cfg['create_database'])) {
        $conn = clarenzon_db_connect(DB_USER, DB_PASS, null, 3);
        if (!$conn) {
            return false;
        }
        $name = $conn->real_escape_string((string)$cfg['name']);
        $conn->query('CREATE DATABASE IF NOT EXISTS `' . $name . '`');
        $conn->select_db((string)$cfg['name']);
    } else {
        $conn = clarenzon_db_connect();
        if (!$conn) {
            return false;
        }
    }
    
    // Create users table with the current system roles only.
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        first_name VARCHAR(100) NOT NULL DEFAULT '',
        last_name VARCHAR(100) NOT NULL DEFAULT '',
        role ENUM('Admin','Warehouse Head','Purchaser','Technician') DEFAULT 'Purchaser',
        status ENUM('Active','Inactive','Suspended') DEFAULT 'Active',
        allocated_stocks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_username (username),
        INDEX idx_email (email),
        INDEX idx_role (role)
    )";
    $conn->query($sql);

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'official_accounts_lib.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_account_lib.php';
    @$conn->query("ALTER TABLE users MODIFY COLUMN username VARCHAR(120) NOT NULL");
    clarenzonEnsureUserRoleEnum($conn);
    $statusCol = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
    if (!($statusCol && $statusCol->num_rows > 0)) {
        $conn->query("ALTER TABLE users ADD COLUMN status ENUM('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active'");
    }

    clarenzonEnsureOfficialUsernames($conn);

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_name_lib.php';
    userNameEnsureSchema($conn);
    
    $conn->close();
}

// Initialize database on first load
initializeDatabase();

// CSRF helpers — prefer role-scoped session bootstrap when available
if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('clarenzon_session_start')) {
        clarenzon_session_start();
    } else {
        $sessionPath = session_save_path();
        $sessionWritable = false;
        if ($sessionPath !== '' && is_dir($sessionPath) && is_writable($sessionPath)) {
            $probe = @tempnam($sessionPath, 'sess_probe_');
            if ($probe !== false) {
                $sessionWritable = true;
                @unlink($probe);
            }
        }
        if (!$sessionWritable) {
            $fallbackSessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sessions';
            if (!is_dir($fallbackSessionPath)) {
                @mkdir($fallbackSessionPath, 0700, true);
            }
            if (is_dir($fallbackSessionPath) && is_writable($fallbackSessionPath)) {
                session_save_path($fallbackSessionPath);
            }
        }
        session_start();
    }
}
if (!function_exists('csrf_get_token')) {
    function csrf_get_token(): string {
        if (function_exists('clarenzon_csrf_get_token')) {
            return clarenzon_csrf_get_token();
        }
        if (session_status() === PHP_SESSION_NONE) {
            if (function_exists('clarenzon_session_start')) clarenzon_session_start();
            else session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        if (function_exists('clarenzon_csrf_field')) {
            return clarenzon_csrf_field();
        }
        $t = csrf_get_token();
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"" . htmlspecialchars($t, ENT_QUOTES) . "\">";
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token): bool {
        if (function_exists('clarenzon_csrf_validate')) {
            return clarenzon_csrf_validate($token);
        }
        if (session_status() === PHP_SESSION_NONE) {
            if (function_exists('clarenzon_session_start')) clarenzon_session_start();
            else session_start();
        }
        if (empty($_SESSION['csrf_token']) || empty($token)) return false;
        return hash_equals((string)$_SESSION['csrf_token'], (string)$token);
    }
}

if (!function_exists('csrf_validate_request')) {
    function csrf_validate_request(): bool {
        if (function_exists('clarenzon_csrf_validate_request')) {
            return clarenzon_csrf_validate_request();
        }
        $token = null;
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        if (!$token && isset($_POST['csrf_token'])) $token = $_POST['csrf_token'];
        return csrf_validate($token);
    }
}
?>
