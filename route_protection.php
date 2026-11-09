<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';

function route_redirect(string $path): void
{
    if (!headers_sent()) {
        // Role dashboards live in subfolders — make relative homes root-absolute when needed
        if ($path !== '' && $path[0] !== '/' && !preg_match('#^https?://#i', $path)) {
            $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $base = '/';
            if (preg_match('#^(.*?/)(?:warehouse_head|purchaser|technician|admin)/#', $script, $m)) {
                $base = $m[1];
            } elseif (preg_match('#^(.*)/[^/]+\.php$#', $script, $m)) {
                $base = $m[1] . '/';
            }
            $path = $base . ltrim($path, '/');
        }
        header('Location: ' . $path);
    }
    exit;
}

function normalize_user_role(string $role): string
{
    $role = strtolower(trim($role));
    $role = str_replace([' ', '-', '/'], '_', $role);

    $aliases = [
        'admin' => 'admin',
        'administrator' => 'admin',
        'system_administrator' => 'admin',
        'purchaser' => 'purchaser',
        'warehouse_head' => 'warehouse_head',
        'warehousehead' => 'warehouse_head',
        'technician' => 'technician',
        'techinician' => 'technician',
        'inventory_manager___staff' => 'inventory_manager',
        'inventory_manager' => 'inventory_manager',
        'inventory_staff' => 'inventory_manager',
        'sales___marketing_staff' => 'sales_marketing',
        'sales_marketing_staff' => 'sales_marketing',
        'sales_marketing' => 'sales_marketing',
        'management___executive' => 'management',
        'management_executive' => 'management',
        'management' => 'management',
        'sales_representative' => 'sales_marketing',
        'sales_rep' => 'sales_marketing',
        'sales' => 'sales_marketing',
        'cashier' => 'cashier',
        'pos_cashier' => 'cashier',
    ];

    return $aliases[$role] ?? $role;
}

function clarenzon_request_is_api(): bool
{
    $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
    return str_ends_with($script, '_api.php') || str_contains($script, '/api.php');
}

function clarenzon_valid_role_keys(): array
{
    return ['admin', 'warehouse_head', 'purchaser', 'technician', 'inventory_manager', 'sales_marketing', 'management', 'cashier'];
}

/**
 * Reload role and account status from the database.
 * Request fields never override the signed-in role.
 */
function clarenzon_sync_session_account(): string
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return 'missing';
    }
    if (!function_exists('clarenzon_db_connect')) {
        $connector = __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
        if (is_file($connector)) {
            require_once $connector;
        }
    }
    if (!function_exists('clarenzon_db_connect')) {
        return 'ok';
    }
    $conn = clarenzon_db_connect('root', '', 'clarenzon_db', 2);
    if (!$conn) {
        return 'ok';
    }
    $stmt = $conn->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
    $found = false;
    $role = '';
    $status = '';
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($row)) {
            $found = true;
            $role = (string)($row['role'] ?? '');
            $status = strtolower(trim((string)($row['status'] ?? '')));
        }
    }
    $conn->close();
    if (!$found) {
        return 'missing';
    }
    if ($role !== '') {
        $_SESSION['role'] = $role;
    }
    if ($status === '' || $status === 'active') {
        return 'ok';
    }
    return 'inactive';
}

function clarenzon_deny_unauthenticated(): void
{
    clarenzon_reject_security(401, '401 Unauthorized', clarenzon_request_is_api());
}

function clarenzon_deny_forbidden(string $message = '403 Forbidden'): void
{
    clarenzon_reject_security(403, $message, clarenzon_request_is_api());
}

function route_require_login(array $allowedRoles = []): void
{
    clarenzon_session_start();

    if (empty($_SESSION['user_id']) && function_exists('clarenzon_session_start_any_authenticated')) {
        $otherContext = clarenzon_session_start_any_authenticated();
        $pageContext = clarenzon_normalize_auth_context(clarenzon_auth_context_from_request());
        if ($otherContext !== null && !empty($_SESSION['user_id']) && $pageContext !== '' && $pageContext !== 'login' && $otherContext !== $pageContext) {
            clarenzon_deny_forbidden('403 Forbidden');
        }
    }

    if (!empty($_SESSION['user_id']) && empty($_SESSION['authenticated'])) {
        $_SESSION['authenticated'] = true;
    }

    if (empty($_SESSION['user_id'])) {
        if (clarenzon_request_is_api()) {
            clarenzon_deny_unauthenticated();
        }
        $pendingOtp = false;
        $loginCookie = function_exists('clarenzon_session_cookie_name')
            ? clarenzon_session_cookie_name('login')
            : 'CLARSESS_login';
        if (!empty($_COOKIE[$loginCookie])) {
            $otpLib = __DIR__ . DIRECTORY_SEPARATOR . 'login_otp_lib.php';
            if (is_file($otpLib)) {
                require_once $otpLib;
            }
            clarenzon_session_write_close_if_active();
            clarenzon_session_start('login');
            $pendingOtp = function_exists('clarenzonPendingLoginValid') && clarenzonPendingLoginValid();
            clarenzon_session_write_close_if_active();
        }
        if ($pendingOtp) {
            route_redirect('verify_otp.php');
        }
        route_redirect('login.php');
    }

    $state = clarenzon_sync_session_account();
    if ($state === 'inactive') {
        if (function_exists('clarenzon_logout_current')) {
            clarenzon_logout_current();
        } else {
            $_SESSION = [];
        }
        if (clarenzon_request_is_api()) {
            clarenzon_reject_security(403, 'Your account is currently inactive. Please contact the administrator.', true);
        }
        route_redirect('login.php?account=inactive');
    }
    if ($state === 'missing') {
        if (function_exists('clarenzon_logout_current')) {
            clarenzon_logout_current();
        }
        clarenzon_deny_unauthenticated();
    }

    $currentRole = normalize_user_role((string)($_SESSION['role'] ?? ''));
    if ($currentRole === '' || !in_array($currentRole, clarenzon_valid_role_keys(), true)) {
        clarenzon_deny_forbidden('403 Forbidden');
    }

    if ($allowedRoles !== []) {
        $allowedRoles = array_map('normalize_user_role', $allowedRoles);
        if (!in_array($currentRole, $allowedRoles, true)) {
            clarenzon_deny_forbidden('403 Forbidden');
        }
    }
}

/**
 * API entry: authentication, active account, then optional role list.
 * Identity comes from the session row in the database.
 */
function clarenzon_api_require(array $allowedRoles = []): void
{
    route_require_login($allowedRoles);
}

/**
 * Home dashboard path for a role (relative to CAPSTONE root).
 */
function route_home_for_role(string $role): string
{
    $role = normalize_user_role($role);
    if ($role === 'purchaser') {
        return 'purchaser.php';
    }
    if ($role === 'warehouse_head') {
        return 'warehouse_head/dashboard.php';
    }
    if ($role === 'technician') {
        return 'technician/dashboard.php';
    }
    if ($role === 'sales_marketing') {
        return 'sales_dashboard.php';
    }
    return 'admin_dashboard.php';
}

function route_reject_inactive_account(): void
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    if (!function_exists('clarenzon_db_connect')) {
        $connector = __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
        if (is_file($connector)) {
            require_once $connector;
        }
    }
    if (!function_exists('clarenzon_db_connect')) {
        return;
    }
    $conn = clarenzon_db_connect('root', '', 'clarenzon_db', 2);
    if (!$conn) {
        return;
    }
    $stmt = $conn->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
    $status = '';
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $status = strtolower(trim((string)($row['status'] ?? '')));
    }
    $conn->close();
    if ($status === '' || $status === 'active') {
        return;
    }
    if (function_exists('clarenzon_logout_current')) {
        clarenzon_logout_current();
    } else {
        $_SESSION = [];
    }
    route_redirect('login.php?account=inactive');
}

/**
 * True when the current request is already on that role's dashboard.
 */
function route_is_on_role_dashboard(?string $role = null): bool
{
    $role = normalize_user_role($role ?? (string)($_SESSION['role'] ?? ''));
    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $uri = str_replace('\\', '/', strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')));

    if ($role === 'purchaser') {
        return $script === 'purchaser.php' || str_contains($uri, '/purchaser/');
    }
    if ($role === 'warehouse_head') {
        return str_contains($uri, '/warehouse_head/');
    }
    if ($role === 'technician') {
        return str_contains($uri, '/technician/') || $script === 'technician.php';
    }
    if ($role === 'sales_marketing') {
        return $script === 'sales_dashboard.php';
    }
    if ($role === 'cashier') {
        return $script === 'admin_dashboard.php';
    }
    if ($role === 'inventory_manager' || $role === 'management') {
        return $script === 'admin_dashboard.php' || str_contains($uri, '/admin/');
    }
    // admin / default
    return $script === 'admin_dashboard.php' || str_contains($uri, '/admin/');
}

/**
 * If the user is on another role's dashboard page, send them home.
 * Does nothing when already on the correct dashboard (so refresh stays put).
 */
function route_enforce_dashboard(): void
{
    $role = (string)($_SESSION['role'] ?? '');
    $roleKey = normalize_user_role($role);
    if ($roleKey === '') {
        route_redirect('login.php');
    }
    if (route_is_on_role_dashboard($roleKey)) {
        return;
    }

    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $uri = str_replace('\\', '/', strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $isRoleDashboard = in_array($script, ['admin_dashboard.php', 'purchaser.php', 'dashboard.php'], true)
        || str_contains($uri, '/warehouse_head/')
        || str_contains($uri, '/purchaser/')
        || str_contains($uri, '/technician/')
        || str_contains($uri, '/admin/');

    if ($isRoleDashboard) {
        clarenzon_deny_forbidden('403 Forbidden');
    }
}

/**
 * Send the current user to their role home.
 */
function route_redirect_home(?string $role = null): void
{
    $role = $role ?? (string)($_SESSION['role'] ?? '');
    $home = route_home_for_role($role);
    route_redirect($home);
}
