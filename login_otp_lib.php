<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mail_lib.php';

const CLARENZON_LOGIN_OTP_TTL_SECONDS = 300;
const CLARENZON_LOGIN_OTP_MAX_ATTEMPTS = 5;
const CLARENZON_LOGIN_OTP_RESEND_SECONDS = 30;
// Must outlive the OTP itself so the user can still press Resend after a code expires.
const CLARENZON_LOGIN_PENDING_TTL_SECONDS = 900;

function clarenzonEnsureLoginOtpSchema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS login_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_token CHAR(64) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            email VARCHAR(120) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            verified_at DATETIME NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_otp_user (user_id),
            INDEX idx_login_otp_token (request_token),
            INDEX idx_login_otp_expires (expires_at)
        ) ENGINE=InnoDB"
    );
    $verified = $conn->query("SHOW COLUMNS FROM login_otps LIKE 'verified_at'");
    if (!($verified && $verified->num_rows > 0)) {
        @$conn->query('ALTER TABLE login_otps ADD COLUMN verified_at DATETIME NULL AFTER used_at');
    }
    $expiresIdx = $conn->query("SHOW INDEX FROM login_otps WHERE Key_name = 'idx_login_otp_expires'");
    if (!($expiresIdx && $expiresIdx->num_rows > 0)) {
        @$conn->query('ALTER TABLE login_otps ADD INDEX idx_login_otp_expires (expires_at)');
    }
}

function clarenzonLoginOtpAudit(mysqli $conn, int $userId, string $username, string $role, string $action, string $details): void
{
    if (function_exists('clarenzon_record_login_audit')) {
        clarenzon_record_login_audit($conn, $userId, $username, $role, $action, $details);
        return;
    }
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

function clarenzonInvalidateLoginOtps(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare('UPDATE login_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function clarenzonClearPendingLogin(): void
{
    unset(
        $_SESSION['otp_pending'],
        $_SESSION['otp_user_id'],
        $_SESSION['login_otp_user_id'],
        $_SESSION['login_otp_username'],
        $_SESSION['login_otp_role'],
        $_SESSION['login_otp_token'],
        $_SESSION['login_otp_email'],
        $_SESSION['login_otp_expires'],
        $_SESSION['login_otp_ip'],
        $_SESSION['login_otp_first_name'],
        $_SESSION['login_otp_last_name'],
        $_SESSION['login_otp_full_name'],
        $_SESSION['login_otp_resend_at']
    );
}

function clarenzonStorePendingLogin(array $user, array $names, string $token, string $email): void
{
    $userId = (int)($user['id'] ?? 0);
    $_SESSION['otp_pending'] = true;
    $_SESSION['otp_user_id'] = $userId;
    $_SESSION['login_otp_user_id'] = $userId;
    $_SESSION['login_otp_username'] = (string)($user['username'] ?? '');
    $_SESSION['login_otp_role'] = (string)($user['role'] ?? '');
    $_SESSION['login_otp_token'] = $token;
    $_SESSION['login_otp_email'] = $email;
    $_SESSION['login_otp_expires'] = time() + CLARENZON_LOGIN_PENDING_TTL_SECONDS;
    $_SESSION['login_otp_ip'] = function_exists('clarenzon_client_ip') ? clarenzon_client_ip() : '';
    $_SESSION['login_otp_first_name'] = (string)($names['first_name'] ?? '');
    $_SESSION['login_otp_last_name'] = (string)($names['last_name'] ?? '');
    $_SESSION['login_otp_full_name'] = (string)($names['full_name'] ?? '');
}

function clarenzonPendingLoginValid(): bool
{
    if (empty($_SESSION['otp_pending']) && empty($_SESSION['login_otp_user_id'])) {
        return false;
    }
    if (empty($_SESSION['login_otp_token']) || (int)($_SESSION['login_otp_user_id'] ?? $_SESSION['otp_user_id'] ?? 0) <= 0) {
        clarenzonClearPendingLogin();
        return false;
    }
    if ((int)($_SESSION['login_otp_expires'] ?? 0) < time()) {
        clarenzonClearPendingLogin();
        return false;
    }
    $ip = function_exists('clarenzon_client_ip') ? clarenzon_client_ip() : '';
    if ($ip !== '' && (string)($_SESSION['login_otp_ip'] ?? '') !== $ip) {
        clarenzonClearPendingLogin();
        return false;
    }
    return true;
}

function clarenzonLoginOtpResendWait(): int
{
    $until = (int)($_SESSION['login_otp_resend_at'] ?? 0) + CLARENZON_LOGIN_OTP_RESEND_SECONDS;
    return max(0, $until - time());
}

function clarenzonLoginUserRegisteredEmail(mysqli $conn, array $user): string
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0) {
        $stmt = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (is_array($row)) {
                $user['email'] = (string)($row['email'] ?? '');
            }
        }
    }
    return clarenzon_otp_destination_email($user);
}

/**
 * @return array{ok:bool,error?:string,token?:string,email?:string,retry_after?:int}
 */
function clarenzonIssueLoginOtp(mysqli $conn, array $user, bool $isResend = false): array
{
    $genericSend = 'We could not send your verification code. Please try again.';
    clarenzonEnsureLoginOtpSchema($conn);
    $userId = (int)($user['id'] ?? 0);
    $username = (string)($user['username'] ?? '');
    $role = (string)($user['role'] ?? '');
    if ($isResend) {
        $wait = clarenzonLoginOtpResendWait();
        if ($wait > 0) {
            return ['ok' => false, 'error' => 'Please wait before requesting a new code.', 'retry_after' => $wait];
        }
    }
    $email = clarenzonLoginUserRegisteredEmail($conn, $user);
    if ($userId <= 0 || $email === '') {
        if (function_exists('clarenzon_auth_debug_log')) {
            clarenzon_auth_debug_log('otp: missing registered email for user ' . $userId);
        }
        return ['ok' => false, 'error' => 'This account has no registered email address. Please contact the administrator.'];
    }
    $otp = (string)random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));
    clarenzonInvalidateLoginOtps($conn, $userId);
    $stmt = $conn->prepare(
        'INSERT INTO login_otps (user_id, request_token, otp_hash, email, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
    );
    if (!$stmt) {
        if (function_exists('clarenzon_auth_debug_log')) {
            clarenzon_auth_debug_log('otp: insert prepare failed');
        }
        return ['ok' => false, 'error' => $genericSend];
    }
    $stmt->bind_param('isss', $userId, $token, $hash, $email);
    $saved = $stmt->execute();
    $stmt->close();
    if (!$saved) {
        if (function_exists('clarenzon_auth_debug_log')) {
            clarenzon_auth_debug_log('otp: insert failed');
        }
        return ['ok' => false, 'error' => $genericSend];
    }

    $sent = clarenzon_send_otp_email($email, $otp, 'sign-in');
    if (empty($sent['ok'])) {
        clarenzonInvalidateLoginOtps($conn, $userId);
        if (function_exists('clarenzon_auth_debug_log')) {
            clarenzon_auth_debug_log('otp: send failed for user ' . $userId);
        }
        return ['ok' => false, 'error' => $genericSend];
    }

    $_SESSION['login_otp_resend_at'] = time();
    clarenzonLoginOtpAudit(
        $conn,
        $userId,
        $username,
        $role,
        $isResend ? 'OTP_RESEND' : 'OTP_SENT',
        $isResend ? 'New login verification code sent' : 'Login verification code sent'
    );

    return ['ok' => true, 'token' => $token, 'email' => $email];
}

/**
 * @return array{ok:bool,error?:string,remaining?:int}
 */
function clarenzonVerifyLoginOtp(mysqli $conn, int $userId, string $token, string $otp): array
{
    clarenzonEnsureLoginOtpSchema($conn);
    $otp = preg_replace('/\D+/', '', $otp) ?? '';
    $username = (string)($_SESSION['login_otp_username'] ?? '');
    $role = (string)($_SESSION['login_otp_role'] ?? '');
    if (strlen($otp) !== 6 || $token === '' || $userId <= 0) {
        return ['ok' => false, 'error' => 'Enter the 6-digit verification code.'];
    }
    $stmt = $conn->prepare(
        'SELECT id, otp_hash, attempts, (expires_at < NOW()) AS is_expired
         FROM login_otps
         WHERE user_id = ? AND request_token = ? AND used_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Something went wrong. Please try again.'];
    }
    $stmt->bind_param('is', $userId, $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        clarenzonLoginOtpAudit($conn, $userId, $username, $role, 'OTP_VERIFICATION_FAILED', 'OTP no longer valid');
        return ['ok' => false, 'error' => 'This verification code is no longer valid. Please request a new verification code.'];
    }
    $otpId = (int)$row['id'];
    $attempts = (int)$row['attempts'];
    if (!empty($row['is_expired'])) {
        $kill = $conn->prepare('UPDATE login_otps SET used_at = NOW() WHERE id = ?');
        if ($kill) {
            $kill->bind_param('i', $otpId);
            $kill->execute();
            $kill->close();
        }
        clarenzonLoginOtpAudit($conn, $userId, $username, $role, 'OTP_EXPIRED', 'Expired OTP rejected');
        return ['ok' => false, 'error' => 'This OTP has expired. Please request a new verification code.'];
    }
    if ($attempts >= CLARENZON_LOGIN_OTP_MAX_ATTEMPTS) {
        clarenzonInvalidateLoginOtps($conn, $userId);
        return ['ok' => false, 'error' => 'OTP verification failed. Please request a new verification code.'];
    }
    if (!password_verify($otp, (string)$row['otp_hash'])) {
        $attempts++;
        $upd = $conn->prepare('UPDATE login_otps SET attempts = ? WHERE id = ?');
        if ($upd) {
            $upd->bind_param('ii', $attempts, $otpId);
            $upd->execute();
            $upd->close();
        }
        clarenzonLoginOtpAudit($conn, $userId, $username, $role, 'OTP_VERIFICATION_FAILED', 'Invalid verification code');
        if ($attempts >= CLARENZON_LOGIN_OTP_MAX_ATTEMPTS) {
            $kill = $conn->prepare('UPDATE login_otps SET used_at = NOW() WHERE id = ?');
            if ($kill) {
                $kill->bind_param('i', $otpId);
                $kill->execute();
                $kill->close();
            }
            return ['ok' => false, 'error' => 'OTP verification failed. Please request a new verification code.', 'remaining' => 0];
        }
        $left = CLARENZON_LOGIN_OTP_MAX_ATTEMPTS - $attempts;
        return ['ok' => false, 'error' => 'Invalid verification code. Attempts remaining: ' . $left, 'remaining' => $left];
    }
    $use = $conn->prepare('UPDATE login_otps SET used_at = NOW(), verified_at = NOW() WHERE id = ? AND used_at IS NULL');
    if (!$use) {
        return ['ok' => false, 'error' => 'Something went wrong. Please try again.'];
    }
    $use->bind_param('i', $otpId);
    $use->execute();
    $changed = $use->affected_rows;
    $use->close();
    if ($changed !== 1) {
        return ['ok' => false, 'error' => 'This verification code has already been used. Please request a new verification code.'];
    }
    clarenzonLoginOtpAudit($conn, $userId, $username, $role, 'OTP_VERIFICATION_SUCCESS', 'Login verification code accepted');
    return ['ok' => true];
}

function clarenzonCompleteOtpLogin(mysqli $conn): array
{
    if (!clarenzonPendingLoginValid()) {
        return ['ok' => false, 'error' => 'Your verification session expired. Please sign in again.'];
    }
    $authUserId = (int)$_SESSION['login_otp_user_id'];
    $authUsername = (string)$_SESSION['login_otp_username'];
    $authRole = (string)$_SESSION['login_otp_role'];
    $authFullName = (string)$_SESSION['login_otp_full_name'];
    $authFirstName = (string)$_SESSION['login_otp_first_name'];
    $authLastName = (string)$_SESSION['login_otp_last_name'];
    clarenzonClearPendingLogin();
    clarenzon_login_user($authUserId, $authUsername, $authRole, $authFullName, $authFirstName, $authLastName);
    clarenzonLoginOtpAudit($conn, $authUserId, $authUsername, $authRole, 'LOGIN_SUCCESS', 'Successful sign in');
    $home = function_exists('clarenzon_project_web_base')
        ? clarenzon_project_web_base() . ltrim(route_home_for_role($authRole), '/')
        : route_home_for_role($authRole);
    return [
        'ok' => true,
        'redirect' => $home,
        'role' => $authRole,
        'username' => $authUsername,
    ];
}

function clarenzonSyncOtpInboxEmails(mysqli $conn): void
{
    unset($conn);
}
