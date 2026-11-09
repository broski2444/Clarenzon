<?php
/**
 * Sign-in used by the login page and backend/api/auth/login.php.
 * Password checks, account status, and the session stay here.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'user_name_lib.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'official_accounts_lib.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'login_otp_lib.php';

if (!function_exists('clarenzon_record_login_audit')) {
    function clarenzon_record_login_audit(mysqli $conn, int $userId, string $username, string $role, string $action, string $details): void
    {
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
        $ip = function_exists('clarenzon_client_ip') ? clarenzon_client_ip() : '0.0.0.0';
        $details .= ' ip=' . $ip;
        $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, ?, ?)');
        if ($stmt) {
            $stmt->bind_param('issss', $userId, $username, $role, $action, $details);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('clarenzon_login_attempt')) {
    /**
     * @return array{ok:bool,message:string,redirect:string,username:string,role:string,otp_required?:bool}
     */
    function clarenzon_login_attempt(mysqli $conn, string $username, string $password): array
    {
        $username = trim($username);
        $fail = static function (string $message) use ($username): array {
            return [
                'ok' => false,
                'message' => $message,
                'redirect' => '',
                'username' => $username,
                'role' => '',
            ];
        };

        if ($username === '' && $password === '') {
            return $fail('Please enter both username and password.');
        }
        if ($username === '') {
            return $fail('Please enter your username.');
        }
        if ($password === '') {
            return $fail('Please enter your password.');
        }

        $auth = clarenzonAuthenticateLogin($conn, $username, $password);
        if (empty($auth['ok']) || empty($auth['user'])) {
            clarenzon_record_login_audit($conn, 0, $username, '', 'FAILED_LOGIN', 'Sign-in rejected');
            return $fail((string)($auth['error'] ?? 'Invalid username or password.'));
        }

        $user = $auth['user'];
        $authUserId = (int)$user['id'];
        $authUsername = (string)$user['username'];
        $authRole = (string)$user['role'];
        $names = userNameNormalize(
            (string)($user['first_name'] ?? ''),
            (string)($user['last_name'] ?? ''),
            (string)($user['full_name'] ?? $authUsername)
        );

        clarenzon_record_login_audit($conn, $authUserId, $authUsername, $authRole, 'LOGIN_PASSWORD_SUCCESS', 'Password accepted; OTP required');

        $roleKey = function_exists('normalize_user_role') ? normalize_user_role($authRole) : strtolower($authRole);
        if (function_exists('clarenzonLoginSkipsOtp') && clarenzonLoginSkipsOtp($user)) {
            clarenzon_login_user($authUserId, $authUsername, $authRole, $names['full_name'], $names['first_name'], $names['last_name']);
            clarenzon_record_login_audit($conn, $authUserId, $authUsername, $authRole, 'LOGIN_SUCCESS', 'Successful sign in (local test account, OTP skipped)');
            $home = (function_exists('clarenzon_project_web_base') ? clarenzon_project_web_base() : '') . ltrim(route_home_for_role($authRole), '/');
            return [
                'ok' => true,
                'otp_required' => false,
                'message' => 'Operation successful',
                'redirect' => $home,
                'username' => $authUsername,
                'role' => $authRole,
            ];
        }
        $issued = clarenzonIssueLoginOtp($conn, $user, false);
        if (empty($issued['ok'])) {
            if ($roleKey === 'admin' && !clarenzonIsProduction()) {
                clarenzon_login_user($authUserId, $authUsername, $authRole, $names['full_name'], $names['first_name'], $names['last_name']);
                clarenzon_record_login_audit($conn, $authUserId, $authUsername, $authRole, 'LOGIN_SUCCESS', 'Successful sign in (OTP email unavailable)');
                $home = (function_exists('clarenzon_project_web_base') ? clarenzon_project_web_base() : '') . ltrim(route_home_for_role($authRole), '/');
                return [
                    'ok' => true,
                    'otp_required' => false,
                    'message' => 'Operation successful',
                    'redirect' => $home,
                    'username' => $authUsername,
                    'role' => $authRole,
                ];
            }
            return $fail((string)($issued['error'] ?? 'We could not send your verification code. Please try again.'));
        }
        clarenzonStorePendingLogin($user, $names, (string)$issued['token'], (string)$issued['email']);
        $verify = (function_exists('clarenzon_project_web_base') ? clarenzon_project_web_base() : '') . 'verify_otp.php';
        $_SESSION['login_flash_success'] = 'Enter the 6-digit code sent to your registered email.';
        return [
            'ok' => true,
            'otp_required' => true,
            'message' => 'Enter the verification code sent to your email.',
            'redirect' => $verify,
            'username' => $authUsername,
            'role' => $authRole,
        ];
    }
}
