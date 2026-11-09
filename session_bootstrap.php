<?php
/**
 * Role-scoped session bootstrap.
 *
 * Each auth context (admin, warehouse_head, purchaser, technician) uses its own
 * session cookie name so concurrent role logins can coexist in one browser.
 */
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'security_lib.php';
if (!function_exists('clarenzon_app_base_path')) {
    function clarenzon_app_base_path(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $backend = strpos($script, '/backend/');
        if ($backend !== false) {
            $script = substr($script, 0, $backend) . '/index.php';
        }
        if (preg_match('#^(.*?/)(?:warehouse_head|technician|admin|purchaser|mobile)/#', $script, $m)) {
            return $m[1];
        }
        if (preg_match('#^(.*)/[^/]+\.php$#', $script, $m)) {
            return rtrim($m[1], '/') . '/';
        }
        return '/';
    }
}

if (!function_exists('clarenzon_normalize_auth_context')) {
    function clarenzon_normalize_auth_context(string $roleOrContext): string
    {
        $key = strtolower(trim($roleOrContext));
        $key = str_replace([' ', '-'], '_', $key);
        $aliases = [
            'admin' => 'admin',
            'administrator' => 'admin',
            'system_administrator' => 'admin',
            'warehouse_head' => 'warehouse_head',
            'warehousehead' => 'warehouse_head',
            'purchaser' => 'purchaser',
            'technician' => 'technician',
            'techinician' => 'technician',
            'login' => 'login',
        ];
        return $aliases[$key] ?? $key;
    }
}

if (!defined('CLARENZON_TAB_COOKIE')) {
    define('CLARENZON_TAB_COOKIE', 'CLARTAB');
}

if (!function_exists('clarenzon_tab_id')) {
    /**
     * Browser-tab id for per-tab sessions (java/tab_session.js).
     * Sent as a header on fetch/XHR, as ?_tab= on EventSource, and as the
     * CLARTAB cookie that the active tab sets right before it navigates.
     * Empty when the client does not run the tab script (legacy shared session).
     */
    function clarenzon_tab_id(): string
    {
        static $tabId = null;
        if ($tabId !== null) {
            return $tabId;
        }
        $candidates = [
            $_SERVER['HTTP_X_CLARENZON_TAB'] ?? '',
            $_GET['_tab'] ?? '',
            $_COOKIE[CLARENZON_TAB_COOKIE] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string)$candidate));
            if (preg_match('/^[a-z0-9]{8,16}$/', $candidate)) {
                return $tabId = $candidate;
            }
        }
        return $tabId = '';
    }
}

if (!function_exists('clarenzon_session_cookie_name')) {
    function clarenzon_session_cookie_name(string $context): string
    {
        $safe = preg_replace('/[^a-z0-9_]/', '', clarenzon_normalize_auth_context($context)) ?: 'app';
        $tabId = clarenzon_tab_id();
        return 'CLARSESS_' . $safe . ($tabId !== '' ? '__' . $tabId : '');
    }
}

if (!function_exists('clarenzon_parse_session_cookie_name')) {
    /**
     * @return array{context:string,tab:string}|null
     */
    function clarenzon_parse_session_cookie_name($cookieName): ?array
    {
        if (!is_string($cookieName) || !preg_match('/^CLARSESS_([a-z0-9_]+?)(?:__([a-z0-9]{8,16}))?$/', $cookieName, $m)) {
            return null;
        }
        return ['context' => $m[1], 'tab' => (string)($m[2] ?? '')];
    }
}

if (!function_exists('clarenzon_expire_cookie')) {
    function clarenzon_expire_cookie(string $cookieName): void
    {
        if (!headers_sent()) {
            setcookie($cookieName, '', [
                'expires' => time() - 42000,
                'path' => clarenzon_app_base_path(),
                'secure' => function_exists('clarenzon_is_https') && clarenzon_is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE[$cookieName]);
    }
}

if (!function_exists('clarenzon_prune_tab_cookies')) {
    /**
     * Every tab adds its own session cookies; drop ones left behind by closed
     * tabs so the Cookie header stays well under server limits.
     */
    function clarenzon_prune_tab_cookies(): void
    {
        static $done = false;
        if ($done || headers_sent()) {
            return;
        }
        $done = true;

        $currentTab = clarenzon_tab_id();
        $others = [];
        foreach (array_keys($_COOKIE) as $cookieName) {
            $parsed = clarenzon_parse_session_cookie_name($cookieName);
            if ($parsed !== null && $parsed['tab'] !== '' && $parsed['tab'] !== $currentTab) {
                $others[] = $cookieName;
            }
        }
        if (count($others) <= 12) {
            return;
        }

        $dir = (string)session_save_path();
        $kept = [];
        foreach ($others as $cookieName) {
            $id = (string)$_COOKIE[$cookieName];
            $file = $dir !== '' && preg_match('/^[A-Za-z0-9,-]{6,128}$/', $id)
                ? rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $id
                : '';
            $data = $file !== '' && is_file($file) ? (string)@file_get_contents($file) : '';
            $recent = $data !== '' && (time() - (int)@filemtime($file)) < 900; // e.g. a tab mid-OTP
            if ($data === '' || (strpos($data, 'user_id|') === false && !$recent)) {
                clarenzon_expire_cookie($cookieName);
                continue;
            }
            $kept[$cookieName] = (int)@filemtime($file);
        }
        if (count($kept) > 24) {
            asort($kept);
            foreach (array_slice(array_keys($kept), 0, count($kept) - 24) as $cookieName) {
                clarenzon_expire_cookie($cookieName);
            }
        }
    }
}

if (!function_exists('clarenzon_auth_context_from_request')) {
    function clarenzon_auth_context_from_request(): string
    {
        $headerCtx = '';
        foreach (['HTTP_X_CLARENZON_AUTH_CONTEXT', 'HTTP_X_AUTH_CONTEXT'] as $h) {
            if (!empty($_SERVER[$h])) {
                $headerCtx = clarenzon_normalize_auth_context((string)$_SERVER[$h]);
                break;
            }
        }
        if ($headerCtx !== '' && in_array($headerCtx, ['admin', 'warehouse_head', 'purchaser', 'technician', 'login'], true)) {
            return $headerCtx;
        }

        if (!empty($_GET['auth_ctx'])) {
            $q = clarenzon_normalize_auth_context((string)$_GET['auth_ctx']);
            if (in_array($q, ['admin', 'warehouse_head', 'purchaser', 'technician', 'login'], true)) {
                return $q;
            }
        }

        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptLower = strtolower($script);
        $uri = str_replace('\\', '/', strtolower((string)($_SERVER['REQUEST_URI'] ?? $script)));
        $base = basename($scriptLower);

        if ($base === 'login.php' || str_ends_with($scriptLower, '/login.php')) {
            return 'login';
        }
        if ($base === 'verify_otp.php' || $base === 'resend_otp.php' || str_contains($scriptLower, '/backend/api/auth/')) {
            return 'login';
        }
        if ($base === 'warehouse_head.php') {
            return 'warehouse_head';
        }
        if (str_contains($scriptLower, '/mobile/') || str_contains($uri, '/mobile/')) {
            // Dedicated warehouse mobile app: pick the role cookie from the header
            // or from whichever allowed session is already logged in.
            return 'login';
        }
        if (str_contains($scriptLower, '/warehouse_head/') || str_contains($uri, '/warehouse_head/')) {
            return 'warehouse_head';
        }
        if (str_contains($scriptLower, '/technician/') || str_contains($uri, '/technician/') || $base === 'technician.php') {
            return 'technician';
        }
        if ($base === 'purchaser.php' || str_contains($scriptLower, '/purchaser/') || str_contains($uri, '/purchaser/')) {
            return 'purchaser';
        }

        $ref = strtolower(str_replace('\\', '/', (string)($_SERVER['HTTP_REFERER'] ?? '')));
        if ($ref !== '') {
            if (str_contains($ref, '/warehouse_head/')) {
                return 'warehouse_head';
            }
            if (str_contains($ref, '/technician/') || str_contains($ref, 'technician.php')) {
                return 'technician';
            }
            if (str_contains($ref, 'purchaser.php') || str_contains($ref, '/purchaser/')) {
                return 'purchaser';
            }
            if (str_contains($ref, 'admin_dashboard.php') || str_contains($ref, '/admin/')) {
                return 'admin';
            }
        }

        return 'admin';
    }
}

if (!function_exists('clarenzon_configure_session_storage')) {
    function clarenzon_configure_session_storage(): void
    {
        $sessionDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0700, true);
        }
        if (is_dir($sessionDir) && is_writable($sessionDir)) {
            session_save_path($sessionDir);
            if (function_exists('clarenzon_protect_session_dir')) {
                clarenzon_protect_session_dir($sessionDir);
            }
        }

        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_samesite', 'Lax');
        @ini_set('session.gc_maxlifetime', (string)(defined('CLARENZON_SESSION_ABSOLUTE_SECONDS') ? CLARENZON_SESSION_ABSOLUTE_SECONDS : 57600));

        $secure = function_exists('clarenzon_is_https') ? clarenzon_is_https() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => clarenzon_app_base_path(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('clarenzon_session_start')) {
    function clarenzon_session_start(?string $context = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $context = clarenzon_normalize_auth_context($context ?? clarenzon_auth_context_from_request());
        clarenzon_configure_session_storage();
        $cookieName = clarenzon_session_cookie_name($context);
        session_name($cookieName);
        $incoming = (string)($_COOKIE[$cookieName] ?? '');
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9,-]{6,128}$/', $incoming)) {
            session_id($incoming);
        } elseif (session_id() !== '') {
            session_id(bin2hex(random_bytes(16)));
        }
        session_start();
        clarenzon_prune_tab_cookies();

        if (!isset($_SESSION['_auth_context'])) {
            $_SESSION['_auth_context'] = $context;
        }
    }
}

/**
 * Open whichever role session cookie is actually logged in.
 * Used by shared pages such as scan.php that are not under a role folder.
 */
if (!function_exists('clarenzon_session_start_any_authenticated')) {
    function clarenzon_session_start_any_authenticated(): ?string
    {
        $preferred = clarenzon_auth_context_from_request();
        $order = [$preferred, 'admin', 'warehouse_head', 'purchaser', 'technician'];
        $seen = [];
        foreach ($order as $ctx) {
            $ctx = clarenzon_normalize_auth_context((string)$ctx);
            if ($ctx === '' || $ctx === 'login' || isset($seen[$ctx])) {
                continue;
            }
            $seen[$ctx] = true;
            if (session_status() === PHP_SESSION_ACTIVE) {
                if (!empty($_SESSION['user_id'])) {
                    return (string)($_SESSION['_auth_context'] ?? $ctx);
                }
                session_write_close();
            }
            clarenzon_session_start($ctx);
            if (!empty($_SESSION['user_id'])) {
                return (string)($_SESSION['_auth_context'] ?? $ctx);
            }
        }
        return null;
    }
}

if (!function_exists('clarenzon_session_write_close_if_active')) {
    function clarenzon_session_write_close_if_active(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}

if (!function_exists('clarenzon_login_user')) {
    /**
     * Establish an authenticated session in the role's own cookie bag.
     * Does not clear other roles' session cookies.
     */
    function clarenzon_login_user(int $userId, string $username, string $role, ?string $fullName = null, ?string $firstName = null, ?string $lastName = null): string
    {
        $roleKey = clarenzon_normalize_auth_context($role);
        if (!in_array($roleKey, ['admin', 'warehouse_head', 'purchaser', 'technician'], true)) {
            $roleKey = 'admin';
        }

        clarenzon_session_write_close_if_active();

        clarenzon_configure_session_storage();
        session_name(clarenzon_session_cookie_name($roleKey));
        session_start();
        session_regenerate_id(true);

        $nameLib = __DIR__ . DIRECTORY_SEPARATOR . 'user_name_lib.php';
        if (is_file($nameLib)) {
            require_once $nameLib;
        }
        $names = function_exists('userNameNormalize')
            ? userNameNormalize(
                (string)($firstName ?? ''),
                (string)($lastName ?? ''),
                (string)($fullName ?: $username)
            )
            : ['first_name' => '', 'last_name' => '', 'full_name' => ($fullName ?: $username)];

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;
        $_SESSION['first_name'] = $names['first_name'];
        $_SESSION['last_name'] = $names['last_name'];
        $_SESSION['full_name'] = $names['full_name'] !== '' ? $names['full_name'] : ($fullName ?: $username);
        $_SESSION['_auth_context'] = $roleKey;
        $_SESSION['_login_at'] = time();
        $_SESSION['_last_active'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['authenticated'] = true;

        return $roleKey;
    }
}

if (!function_exists('clarenzon_logout_current')) {
    /**
     * Log out every Clarenzon role session in this browser.
     */
    function clarenzon_logout_current(?string $context = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            clarenzon_session_start($context);
        }

        $auditUserId = (int)($_SESSION['user_id'] ?? 0);
        $auditUsername = (string)($_SESSION['username'] ?? '');
        $auditRole = (string)($_SESSION['role'] ?? '');
        if ($auditUserId > 0 && $auditUsername !== '') {
            $connector = __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
            if (is_file($connector)) {
                require_once $connector;
            }
            if (function_exists('clarenzon_db_connect')) {
                $auditConn = clarenzon_db_connect('root', '', 'clarenzon_db', 2);
                if ($auditConn) {
                    $auditConn->query(
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
                    $details = 'Signed out. ip=' . (function_exists('clarenzon_client_ip') ? clarenzon_client_ip() : '0.0.0.0');
                    $action = 'LOGOUT';
                    $stmt = $auditConn->prepare('INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, ?, ?)');
                    if ($stmt) {
                        $stmt->bind_param('issss', $auditUserId, $auditUsername, $auditRole, $action, $details);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $auditConn->close();
                }
            }
        }

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            clarenzon_expire_cookie(session_name());
            session_destroy();
        }

        // Per-tab sessions: only this tab's role sessions end; other tabs stay signed in.
        $tabId = clarenzon_tab_id();
        foreach (array_keys($_COOKIE) as $cookieKey) {
            $parsed = clarenzon_parse_session_cookie_name($cookieKey);
            if ($parsed === null || $parsed['tab'] !== $tabId) {
                continue;
            }
            $ctx = $parsed['context'];
            if (!empty($_COOKIE[$cookieKey])) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                clarenzon_session_start($ctx);
                if ($ctx === 'login') {
                    $otpLib = __DIR__ . DIRECTORY_SEPARATOR . 'login_otp_lib.php';
                    if (is_file($otpLib)) {
                        require_once $otpLib;
                    }
                    if (function_exists('clarenzonClearPendingLogin')) {
                        clarenzonClearPendingLogin();
                    }
                }
                $_SESSION = [];
                session_destroy();
            }
            clarenzon_expire_cookie($cookieKey);
        }
    }
}

if (!function_exists('clarenzon_tab_inject')) {
    /**
     * Put the tab id and java/tab_session.js at the top of every HTML page so the
     * tab is identified before any other script fetches or navigates.
     */
    function clarenzon_tab_inject(string $buffer): string
    {
        if ($buffer === '' || stripos($buffer, 'tab_session.js') !== false || !preg_match('/<head\b[^>]*>/i', $buffer)) {
            return $buffer;
        }
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-disposition:') === 0) {
                return $buffer;
            }
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) {
                return $buffer;
            }
        }
        $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $isLogin = in_array($script, ['login.php', 'register.php', 'forgot_password.php'], true);
        $base = clarenzon_app_base_path();
        $src = rtrim($base, '/') . '/java/tab_session.js?v=' . (@filemtime(__DIR__ . '/java/tab_session.js') ?: 1);
        $tags = '<meta name="clar-tab" content="' . htmlspecialchars(clarenzon_tab_id(), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-path="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-login="' . ($isLogin ? '1' : '0') . '">'
            . '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';
        $next = preg_replace('/<head\b[^>]*>/i', '$0' . $tags, $buffer, 1, $count);
        return ($count === 1 && is_string($next)) ? $next : $buffer;
    }
}

// Auto-start for normal app entry points. Login uses clarenzon_login_user() instead.
// /mobile/* starts its own session (admin or warehouse_head cookie) in mobile/bootstrap.php.
$__clarScript = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$__clarPath = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
if (
    $__clarScript !== 'login.php'
    && $__clarScript !== 'verify_otp.php'
    && $__clarScript !== 'resend_otp.php'
    && $__clarScript !== 'register.php'
    && $__clarScript !== 'forgot_password.php'
    && strpos($__clarPath, '/mobile/') === false
) {
    clarenzon_session_start();
}
unset($__clarScript, $__clarPath);
if (function_exists('clarenzon_security_boot')) {
    clarenzon_security_boot();
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'realtime_prepend.php';
