<?php
/**
 * Application security helpers: headers, CSRF, origin checks, login throttle,
 * session lifetime, and upload sanitization.
 */

if (!defined('CLARENZON_SESSION_IDLE_SECONDS')) {
    define('CLARENZON_SESSION_IDLE_SECONDS', 8 * 3600);
}
if (!defined('CLARENZON_SESSION_ABSOLUTE_SECONDS')) {
    define('CLARENZON_SESSION_ABSOLUTE_SECONDS', 16 * 3600);
}
if (!defined('CLARENZON_LOGIN_MAX_ATTEMPTS')) {
    define('CLARENZON_LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('CLARENZON_LOGIN_WINDOW_SECONDS')) {
    define('CLARENZON_LOGIN_WINDOW_SECONDS', 15 * 60);
}
if (!defined('CLARENZON_RESET_MAX_ATTEMPTS')) {
    define('CLARENZON_RESET_MAX_ATTEMPTS', 5);
}
if (!defined('CLARENZON_RESET_WINDOW_SECONDS')) {
    define('CLARENZON_RESET_WINDOW_SECONDS', 15 * 60);
}

if (!function_exists('clarenzon_harden_php')) {
    function clarenzon_harden_php(): void
    {
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
        @ini_set('log_errors', '1');
        @ini_set('expose_php', '0');
        header_remove('X-Powered-By');
    }
}

if (!function_exists('clarenzon_is_https')) {
    function clarenzon_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $port = (string)($_SERVER['SERVER_PORT'] ?? '');
        if ($port === '443') {
            return true;
        }
        $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwarded === 'https';
    }
}

if (!function_exists('clarenzon_client_ip')) {
    function clarenzon_client_ip(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}

if (!function_exists('clarenzon_request_host')) {
    function clarenzon_request_host(): string
    {
        return strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    }
}

if (!function_exists('clarenzon_host_from_url')) {
    function clarenzon_host_from_url(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = strtolower((string)$parts['host']);
        if (!empty($parts['port'])) {
            $host .= ':' . (int)$parts['port'];
        }
        return $host;
    }
}

if (!function_exists('clarenzon_hosts_match')) {
    function clarenzon_hosts_match(string $left, string $right): bool
    {
        $left = strtolower(trim($left));
        $right = strtolower(trim($right));
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }
        $strip = static function (string $h): string {
            return preg_replace('/:(80|443)$/', '', $h) ?? $h;
        };
        return $strip($left) === $strip($right);
    }
}

if (!function_exists('clarenzon_same_origin_ok')) {
    function clarenzon_same_origin_ok(): bool
    {
        $host = clarenzon_request_host();
        if ($host === '') {
            return true;
        }
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') {
            return clarenzon_hosts_match(clarenzon_host_from_url($origin), $host);
        }
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            return clarenzon_hosts_match(clarenzon_host_from_url($referer), $host);
        }
        return true;
    }
}

if (!function_exists('clarenzon_security_headers')) {
    function clarenzon_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
        header('X-XSS-Protection: 0');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        if (clarenzon_is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

if (!function_exists('clarenzon_csrf_get_token')) {
    function clarenzon_csrf_get_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (function_exists('clarenzon_session_start')) {
                clarenzon_session_start();
            } else {
                @session_start();
            }
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('clarenzon_csrf_field')) {
    function clarenzon_csrf_field(): string
    {
        $t = clarenzon_csrf_get_token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('clarenzon_csrf_meta_tag')) {
    function clarenzon_csrf_meta_tag(): string
    {
        $t = clarenzon_csrf_get_token();
        return '<meta name="csrf-token" content="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('clarenzon_csrf_validate')) {
    function clarenzon_csrf_validate(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (function_exists('clarenzon_session_start')) {
                clarenzon_session_start();
            } else {
                @session_start();
            }
        }
        $sess = (string)($_SESSION['csrf_token'] ?? '');
        $token = (string)$token;
        if ($sess === '' || $token === '') {
            return false;
        }
        return hash_equals($sess, $token);
    }
}

if (!function_exists('clarenzon_csrf_request_token')) {
    function clarenzon_csrf_request_token(): string
    {
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (isset($_POST['csrf_token'])) {
            return (string)$_POST['csrf_token'];
        }
        if (isset($_GET['csrf_token'])) {
            return (string)$_GET['csrf_token'];
        }
        return '';
    }
}

if (!function_exists('clarenzon_csrf_validate_request')) {
    function clarenzon_csrf_validate_request(): bool
    {
        return clarenzon_csrf_validate(clarenzon_csrf_request_token());
    }
}

if (!function_exists('clarenzon_reject_security')) {
    function clarenzon_reject_security(int $status, string $message, bool $json = false): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        $wantsJson = $json
            || (str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json'))
            || str_ends_with(strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')), '_api.php')
            || str_contains(strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/api.php');
        if ($wantsJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        $loginHref = function_exists('clarenzon_app_base_path')
            ? clarenzon_app_base_path() . 'login.php'
            : 'login.php';
        $signIn = $status === 401
            ? ' <a href="' . htmlspecialchars($loginHref, ENT_QUOTES, 'UTF-8') . '">Sign in</a>'
            : '';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</title></head><body style="font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a;"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . $signIn . '</p></body></html>';
        exit;
    }
}

if (!function_exists('clarenzon_guard_mutating_request')) {
    function clarenzon_guard_mutating_request(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (!clarenzon_same_origin_ok()) {
            clarenzon_reject_security(403, 'This request was blocked for security reasons.');
        }
    }
}

if (!function_exists('clarenzon_api_action_is_read')) {
    function clarenzon_api_action_is_read(string $action): bool
    {
        $action = strtolower(trim($action));
        if ($action === '') {
            return true;
        }
        if (preg_match('/^(get|list|load|fetch|search|lookup|read|show|view|product|recent|ping|status|health)([-_]|$)/', $action)) {
            return true;
        }
        $reads = [
            'product',
            'recent-scans',
            'get-settings',
            'get-notifications',
            'get_logs',
            'get_log',
            'customers_list',
            'customers_get',
        ];
        return in_array($action, $reads, true);
    }
}

if (!function_exists('clarenzon_api_guard_method')) {
    function clarenzon_api_guard_method(string $action): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (clarenzon_api_action_is_read($action)) {
            return;
        }
        if (in_array($method, ['GET', 'HEAD'], true)) {
            clarenzon_reject_security(405, 'This action must be submitted securely.', true);
        }
        if (!clarenzon_same_origin_ok()) {
            clarenzon_reject_security(403, 'This request was blocked for security reasons.', true);
        }
        if (!clarenzon_csrf_validate_request()) {
            clarenzon_reject_security(403, 'This request was blocked for security reasons.', true);
        }
    }
}

if (!function_exists('clarenzon_password_validate')) {
    function clarenzon_password_validate(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        return null;
    }
}

if (!function_exists('clarenzon_public_error')) {
    function clarenzon_public_error(string $fallback = 'An error occurred while processing your request.'): string
    {
        return $fallback;
    }
}

if (!function_exists('clarenzon_security_storage_dir')) {
    function clarenzon_security_storage_dir(): string
    {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }
}

if (!function_exists('clarenzon_throttle_file')) {
    function clarenzon_throttle_file(string $bucket, string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $bucket) ?: 'bucket';
        $hash = hash('sha256', $bucket . '|' . $key);
        return clarenzon_security_storage_dir() . DIRECTORY_SEPARATOR . 'throttle_' . $safe . '_' . $hash . '.json';
    }
}

if (!function_exists('clarenzon_throttle_read')) {
    function clarenzon_throttle_read(string $file): array
    {
        if (!is_file($file)) {
            return ['fails' => 0, 'window' => 0, 'until' => 0];
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['fails' => 0, 'window' => 0, 'until' => 0];
        }
        return [
            'fails' => (int)($data['fails'] ?? 0),
            'window' => (int)($data['window'] ?? 0),
            'until' => (int)($data['until'] ?? 0),
        ];
    }
}

if (!function_exists('clarenzon_throttle_write')) {
    function clarenzon_throttle_write(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
}

if (!function_exists('clarenzon_throttle_check')) {
    /**
     * @return array{blocked:bool,message?:string}
     */
    function clarenzon_throttle_check(string $bucket, string $key, int $maxAttempts, int $windowSeconds): array
    {
        $file = clarenzon_throttle_file($bucket, $key);
        $data = clarenzon_throttle_read($file);
        $now = time();
        if (($data['until'] ?? 0) > $now) {
            $mins = max(1, (int)ceil(($data['until'] - $now) / 60));
            return [
                'blocked' => true,
                'message' => 'Too many attempts. Please wait ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ' and try again.',
            ];
        }
        if (($data['window'] ?? 0) + $windowSeconds < $now) {
            return ['blocked' => false];
        }
        if ((int)($data['fails'] ?? 0) >= $maxAttempts) {
            $data['until'] = $now + $windowSeconds;
            clarenzon_throttle_write($file, $data);
            return [
                'blocked' => true,
                'message' => 'Too many attempts. Please wait a few minutes and try again.',
            ];
        }
        return ['blocked' => false];
    }
}

if (!function_exists('clarenzon_throttle_hit')) {
    function clarenzon_throttle_hit(string $bucket, string $key, int $maxAttempts, int $windowSeconds): void
    {
        $file = clarenzon_throttle_file($bucket, $key);
        $data = clarenzon_throttle_read($file);
        $now = time();
        if (($data['window'] ?? 0) + $windowSeconds < $now) {
            $data = ['fails' => 0, 'window' => $now, 'until' => 0];
        }
        $data['fails'] = (int)($data['fails'] ?? 0) + 1;
        $data['window'] = (int)($data['window'] ?? $now);
        if ($data['fails'] >= $maxAttempts) {
            $data['until'] = $now + $windowSeconds;
        }
        clarenzon_throttle_write($file, $data);
    }
}

if (!function_exists('clarenzon_throttle_clear')) {
    function clarenzon_throttle_clear(string $bucket, string $key): void
    {
        $file = clarenzon_throttle_file($bucket, $key);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if (!function_exists('clarenzon_login_throttle_state')) {
    /**
     * @return array{blocked:bool,message?:string}
     */
    function clarenzon_login_throttle_state(string $login): array
    {
        $ip = clarenzon_client_ip();
        $ipCheck = clarenzon_throttle_check('login_ip', $ip, CLARENZON_LOGIN_MAX_ATTEMPTS * 4, CLARENZON_LOGIN_WINDOW_SECONDS);
        if (!empty($ipCheck['blocked'])) {
            return $ipCheck;
        }
        $loginKey = strtolower(trim($login));
        if ($loginKey === '') {
            return ['blocked' => false];
        }
        return clarenzon_throttle_check('login_user', $loginKey, CLARENZON_LOGIN_MAX_ATTEMPTS, CLARENZON_LOGIN_WINDOW_SECONDS);
    }
}

if (!function_exists('clarenzon_login_throttle_fail')) {
    function clarenzon_login_throttle_fail(string $login): void
    {
        clarenzon_throttle_hit('login_ip', clarenzon_client_ip(), CLARENZON_LOGIN_MAX_ATTEMPTS * 4, CLARENZON_LOGIN_WINDOW_SECONDS);
        $loginKey = strtolower(trim($login));
        if ($loginKey !== '') {
            clarenzon_throttle_hit('login_user', $loginKey, CLARENZON_LOGIN_MAX_ATTEMPTS, CLARENZON_LOGIN_WINDOW_SECONDS);
        }
    }
}

if (!function_exists('clarenzon_login_throttle_clear')) {
    function clarenzon_login_throttle_clear(string $login): void
    {
        clarenzon_throttle_clear('login_ip', clarenzon_client_ip());
        $loginKey = strtolower(trim($login));
        if ($loginKey !== '') {
            clarenzon_throttle_clear('login_user', $loginKey);
        }
    }
}

if (!function_exists('clarenzon_session_touch')) {
    function clarenzon_session_touch(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $now = time();
        if (empty($_SESSION['user_id'])) {
            $_SESSION['_last_active'] = $now;
            return;
        }
        $idle = CLARENZON_SESSION_IDLE_SECONDS;
        $absolute = CLARENZON_SESSION_ABSOLUTE_SECONDS;
        $last = (int)($_SESSION['_last_active'] ?? $_SESSION['_login_at'] ?? $now);
        $loginAt = (int)($_SESSION['_login_at'] ?? $now);
        if (($now - $last) > $idle || ($now - $loginAt) > $absolute) {
            if (function_exists('clarenzon_logout_current')) {
                clarenzon_logout_current();
            } else {
                $_SESSION = [];
                session_destroy();
            }
            $script = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $isApi = str_ends_with($script, '_api.php') || str_contains($script, '/api.php');
            if ($isApi) {
                clarenzon_reject_security(401, 'Your session expired. Please sign in again.', true);
            }
            if (function_exists('route_redirect')) {
                route_redirect('login.php');
            }
            if (!headers_sent()) {
                header('Location: login.php');
            }
            exit;
        }
        $_SESSION['_last_active'] = $now;
    }
}

if (!function_exists('clarenzon_protect_session_dir')) {
    function clarenzon_protect_session_dir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $idx = $dir . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($idx)) {
            @file_put_contents($idx, "<?php\nhttp_response_code(403);\nexit;\n");
        }
    }
}

if (!function_exists('clarenzon_store_upload')) {
    /**
     * @param array<string,mixed> $file
     * @param list<string> $allowedExt
     * @param list<string> $allowedMime
     * @return array{ok:bool,path?:string,error?:string}
     */
    function clarenzon_store_upload(array $file, string $destDir, array $allowedExt, array $allowedMime, int $maxBytes = 5242880): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file was uploaded.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'File upload failed.'];
        }
        if ((int)($file['size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'error' => 'File is too large.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'File upload failed.'];
        }
        $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $blocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'cgi', 'pl', 'exe', 'js', 'html', 'htm', 'shtml'];
        if ($ext === '' || in_array($ext, $blocked, true) || !in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'error' => 'That file type is not allowed.'];
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if ($allowedMime !== [] && $mime !== '' && !in_array($mime, $allowedMime, true)) {
            return ['ok' => false, 'error' => 'That file type is not allowed.'];
        }
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Upload folder could not be created.'];
        }
        $name = 'file_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($tmp, $target)) {
            return ['ok' => false, 'error' => 'File could not be saved.'];
        }
        return ['ok' => true, 'path' => $name];
    }
}

if (!function_exists('clarenzon_security_boot')) {
    function clarenzon_security_boot(): void
    {
        static $headersDone = false;
        if (!$headersDone) {
            $headersDone = true;
            clarenzon_harden_php();
            clarenzon_security_headers();
            clarenzon_guard_mutating_request();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            clarenzon_session_touch();
            if (!empty($_SESSION['user_id'])) {
                clarenzon_csrf_get_token();
            }
        }
    }
}

clarenzon_harden_php();
