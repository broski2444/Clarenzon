<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mail_lib.php';

const CLARENZON_OTP_TTL_SECONDS = 300;
const CLARENZON_OTP_MAX_ATTEMPTS = 5;

function clarenzonEnsurePasswordResetSchema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS password_reset_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_token CHAR(64) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_reset_user (user_id),
            INDEX idx_reset_token (request_token)
        ) ENGINE=InnoDB"
    );
}

function clarenzonFindUserByEmail(mysqli $conn, string $email): ?array
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, username, email, role, full_name, first_name, last_name, status
         FROM users
         WHERE LOWER(email) = LOWER(?)
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function clarenzonInvalidateUserOtps(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * @return array{ok:bool,error?:string,token?:string}
 */
function clarenzonIssuePasswordOtp(mysqli $conn, array $user): array
{
    clarenzonEnsurePasswordResetSchema($conn);
    $userId = (int)($user['id'] ?? 0);
    $email = trim((string)($user['email'] ?? ''));
    if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter the email address registered on the account.'];
    }

    $otp = (string)random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));
    clarenzonInvalidateUserOtps($conn, $userId);
    $stmt = $conn->prepare(
        "INSERT INTO password_reset_otps (user_id, request_token, otp_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to start a password reset. Please try again.'];
    }
    $stmt->bind_param('iss', $userId, $token, $hash);
    $saved = $stmt->execute();
    $stmt->close();
    if (!$saved) {
        return ['ok' => false, 'error' => 'Unable to start a password reset. Please try again.'];
    }

    $sent = clarenzon_send_otp_email($email, $otp, 'password');
    if (empty($sent['ok'])) {
        clarenzonInvalidateUserOtps($conn, $userId);
        return ['ok' => false, 'error' => (string)($sent['error'] ?? 'Unable to send the verification email. Please try again.')];
    }

    return ['ok' => true, 'token' => $token];
}

function clarenzonSendOtpEmail(string $to, string $otp): bool
{
    $sent = clarenzon_send_otp_email($to, $otp, 'password');
    return !empty($sent['ok']);
}

function clarenzonClearPasswordResetSession(): void
{
    unset(
        $_SESSION['reset_user_id'],
        $_SESSION['reset_email'],
        $_SESSION['reset_token'],
        $_SESSION['reset_verified'],
        $_SESSION['reset_expires'],
        $_SESSION['reset_ip']
    );
}

/**
 * @return array{ok:bool,error?:string}
 */
function clarenzonVerifyPasswordOtp(mysqli $conn, int $userId, string $token, string $otp): array
{
    clarenzonEnsurePasswordResetSchema($conn);
    $otp = preg_replace('/\D+/', '', $otp) ?? '';
    if (strlen($otp) !== 6 || $token === '' || $userId <= 0) {
        return ['ok' => false, 'error' => 'Enter the 6-digit verification code.'];
    }
    $stmt = $conn->prepare(
        "SELECT id, otp_hash, expires_at, attempts, (expires_at < NOW()) AS is_expired
         FROM password_reset_otps
         WHERE user_id = ? AND request_token = ? AND used_at IS NULL
         ORDER BY id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to verify the code. Please try again.'];
    }
    $stmt->bind_param('is', $userId, $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return ['ok' => false, 'error' => 'This verification code is no longer valid. Request a new code.'];
    }
    $otpId = (int)$row['id'];
    $attempts = (int)$row['attempts'];
    if (!empty($row['is_expired'])) {
        $kill = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?");
        if ($kill) {
            $kill->bind_param('i', $otpId);
            $kill->execute();
            $kill->close();
        }
        return ['ok' => false, 'error' => 'This verification code has expired. Request a new code.'];
    }
    if ($attempts >= CLARENZON_OTP_MAX_ATTEMPTS) {
        $kill = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?");
        if ($kill) {
            $kill->bind_param('i', $otpId);
            $kill->execute();
            $kill->close();
        }
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new verification code.'];
    }
    if (!password_verify($otp, (string)$row['otp_hash'])) {
        $attempts++;
        $upd = $conn->prepare("UPDATE password_reset_otps SET attempts = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param('ii', $attempts, $otpId);
            $upd->execute();
            $upd->close();
        }
        if ($attempts >= CLARENZON_OTP_MAX_ATTEMPTS) {
            $kill = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?");
            if ($kill) {
                $kill->bind_param('i', $otpId);
                $kill->execute();
                $kill->close();
            }
            return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new verification code.'];
        }
        return ['ok' => false, 'error' => 'The verification code is incorrect.'];
    }
    $use = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    if (!$use) {
        return ['ok' => false, 'error' => 'Unable to verify the code. Please try again.'];
    }
    $use->bind_param('i', $otpId);
    $use->execute();
    $changed = $use->affected_rows;
    $use->close();
    if ($changed !== 1) {
        return ['ok' => false, 'error' => 'This verification code has already been used. Request a new code.'];
    }
    return ['ok' => true];
}
