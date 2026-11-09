<?php
/**
 * Shared bootstrap for the Clarenzon warehouse mobile app.
 * Reuses existing sessions, users, roles, and MySQL — no second auth system.
 */
declare(strict_types=1);

if (!defined('CLARENZON_MOBILE')) {
    define('CLARENZON_MOBILE', true);
}

$__mobileAllowGuest = !empty($GLOBALS['MOBILE_ALLOW_GUEST']);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'warehouse_permissions.php';

function mobile_root_fs(): string
{
    return __DIR__;
}

function mobile_app_fs(): string
{
    return dirname(__DIR__);
}

function mobile_web_root(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/mobile/index.php'));
    if (preg_match('#^(.*?/)mobile/#', $script, $m)) {
        return $m[1];
    }
    return clarenzon_app_base_path();
}

function mobile_base_url(): string
{
    return rtrim(mobile_web_root(), '/') . '/mobile/';
}

function mobile_url(string $file = ''): string
{
    $file = ltrim($file, '/');
    return mobile_base_url() . $file;
}

function mobile_asset(string $file): string
{
    $file = ltrim($file, '/');
    $path = mobile_root_fs() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $file;
    $url = mobile_url('assets/' . $file);
    if (is_file($path)) {
        $url .= '?v=' . (string)filemtime($path);
    }
    return $url;
}

function mobile_parent_url(string $file): string
{
    return rtrim(mobile_web_root(), '/') . '/' . ltrim($file, '/');
}

function mobile_absolute_url(string $file = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host . mobile_url($file);
}

function mobile_session_start(): ?string
{
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        return (string)($_SESSION['_auth_context'] ?? clarenzon_normalize_auth_context((string)($_SESSION['role'] ?? '')));
    }

    $preferred = clarenzon_auth_context_from_request();
    $order = [];
    if (in_array($preferred, ['admin', 'warehouse_head', 'purchaser', 'technician'], true)
        && !empty($_COOKIE[clarenzon_session_cookie_name($preferred)])) {
        $order[] = $preferred;
    }
    foreach (['admin', 'warehouse_head', 'purchaser', 'technician'] as $ctx) {
        $cookie = clarenzon_session_cookie_name($ctx);
        if (!empty($_COOKIE[$cookie]) && !in_array($ctx, $order, true)) {
            $order[] = $ctx;
        }
    }
    if (!$order) {
        return null;
    }

    foreach ($order as $ctx) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!empty($_SESSION['user_id'])) {
                return (string)($_SESSION['_auth_context'] ?? $ctx);
            }
            session_write_close();
        }
        clarenzon_session_start($ctx);
        if (!empty($_SESSION['user_id'])) {
            return $ctx;
        }
    }
    return null;
}

function mobile_role_key(?string $role = null): string
{
    $role = $role ?? (string)($_SESSION['role'] ?? '');
    return normalize_user_role($role);
}

function mobile_is_allowed_role(?string $role = null): bool
{
    $key = mobile_role_key($role);
    return $key === 'admin' || $key === 'warehouse_head';
}

function mobile_is_admin(): bool
{
    return mobile_role_key() === 'admin';
}

function mobile_is_warehouse_head(): bool
{
    return mobile_role_key() === 'warehouse_head';
}

function mobile_user(): array
{
    $nameLib = mobile_app_fs() . DIRECTORY_SEPARATOR . 'user_name_lib.php';
    if (is_file($nameLib)) {
        require_once $nameLib;
    }
    $row = [
        'first_name' => (string)($_SESSION['first_name'] ?? ''),
        'last_name' => (string)($_SESSION['last_name'] ?? ''),
        'full_name' => (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
    ];
    $full = function_exists('userDisplayName')
        ? userDisplayName($row, (string)($_SESSION['username'] ?? ''))
        : ($row['full_name'] !== '' ? $row['full_name'] : (string)($_SESSION['username'] ?? ''));
    $first = function_exists('userFirstName') ? userFirstName($row, $full) : $full;
    $names = function_exists('userNameNormalize')
        ? userNameNormalize($row['first_name'], $row['last_name'], $full)
        : ['first_name' => $first, 'last_name' => '', 'full_name' => $full];
    return [
        'id' => (int)($_SESSION['user_id'] ?? 0),
        'username' => (string)($_SESSION['username'] ?? ''),
        'first_name' => $names['first_name'],
        'last_name' => $names['last_name'],
        'full_name' => $names['full_name'] !== '' ? $names['full_name'] : $full,
        'role' => (string)($_SESSION['role'] ?? ''),
        'role_key' => mobile_role_key(),
        'is_admin' => mobile_is_admin(),
        'is_warehouse_head' => mobile_is_warehouse_head(),
    ];
}

function mobile_db(): ?mysqli
{
    static $conn = null;
    static $tried = false;
    if ($tried) {
        return $conn instanceof mysqli ? $conn : null;
    }
    $tried = true;
    require_once mobile_app_fs() . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
    $conn = clarenzon_db_connect_first(['clarenzon_db', 'inventory_db', 'clarenzon_inventory'], 'root', '', 3);
    if ($conn instanceof mysqli) {
        $nameLib = mobile_app_fs() . DIRECTORY_SEPARATOR . 'user_name_lib.php';
        if (is_file($nameLib)) {
            require_once $nameLib;
            userNameEnsureSchema($conn);
        }
    }
    return $conn instanceof mysqli ? $conn : null;
}

function mobile_ensure_csrf(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        mobile_session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function mobile_csrf_ok(): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        return true;
    }
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    if ($sess === '' || $token === '') {
        return false;
    }
    return hash_equals($sess, $token);
}

function mobile_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobile_denied_message(): string
{
    return 'You are not authorized to use the Warehouse Mobile App.';
}

function mobile_redirect(string $file): void
{
    if (!headers_sent()) {
        header('Location: ' . mobile_url($file));
    }
    exit;
}

function mobile_require_api_role(): array
{
    mobile_session_start();
    if (empty($_SESSION['user_id'])) {
        mobile_json([
            'success' => false,
            'code' => 'auth',
            'message' => 'You are not authorized to perform this action.',
        ], 401);
    }
    $conn = mobile_db();
    if (!$conn) {
        mobile_json([
            'success' => false,
            'code' => 'db',
            'message' => 'Unable to connect to the server.',
        ], 503);
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $dbRole = (string)($_SESSION['role'] ?? '');
    $dbStatus = 'active';
    $stmt = $conn->prepare('SELECT id, role, status FROM users WHERE id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            mobile_json([
                'success' => false,
                'code' => 'auth',
                'message' => 'You are not authorized to perform this action.',
            ], 401);
        }
        $dbRole = (string)($row['role'] ?? $dbRole);
        $dbStatus = strtolower(trim((string)($row['status'] ?? 'active')));
    }
    if ($dbStatus !== '' && $dbStatus !== 'active') {
        mobile_json([
            'success' => false,
            'code' => 'no_access',
            'title' => 'Access Denied',
            'message' => mobile_denied_message(),
        ], 403);
    }
    if (!mobile_is_allowed_role($dbRole)) {
        mobile_json([
            'success' => false,
            'code' => 'no_access',
            'title' => 'Access Denied',
            'message' => mobile_denied_message(),
        ], 403);
    }
    return [$conn, mobile_user()];
}

if (!$__mobileAllowGuest) {
    mobile_session_start();
}

require_once mobile_app_fs() . DIRECTORY_SEPARATOR . 'config.php';
mobile_ensure_csrf();
if (function_exists('clarenzon_security_boot')) {
    clarenzon_security_boot();
}
unset($__mobileAllowGuest);
