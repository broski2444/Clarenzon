<?php
/**
 * Issue a new login OTP and invalidate the previous code.
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'connection.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'auth_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'json_response.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'login_otp_lib.php';

clarenzon_session_start('login');
clarenzon_security_boot();

$wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$verifyUrl = clarenzon_project_web_base() . 'verify_otp.php';
$loginUrl = clarenzon_project_web_base() . 'login.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if ($wantsJson) {
        clarenzon_json_out(false, 'Operation failed', null, 405);
    }
    header('Location: ' . $loginUrl);
    exit;
}

if (!clarenzon_csrf_validate_request()) {
    if ($wantsJson) {
        clarenzon_json_out(false, 'Your session expired. Please try again.', null, 403);
    }
    $_SESSION['login_flash_error'] = 'Your session expired. Please try again.';
    header('Location: ' . $loginUrl);
    exit;
}

if (!clarenzonPendingLoginValid()) {
    clarenzonClearPendingLogin();
    if ($wantsJson) {
        clarenzon_json_out(false, 'Your verification session expired. Please sign in again.', null, 401);
    }
    $_SESSION['login_flash_error'] = 'Your verification session expired. Please sign in again.';
    header('Location: ' . $loginUrl);
    exit;
}

$conn = clarenzon_connection('clarenzon_db');
if (!$conn) {
    if ($wantsJson) {
        clarenzon_json_out(false, 'We could not send your verification code. Please try again.', null, 500);
    }
    $_SESSION['login_flash_error'] = 'We could not send your verification code. Please try again.';
    header('Location: ' . $verifyUrl);
    exit;
}

$pendingUser = [
    'id' => (int)($_SESSION['login_otp_user_id'] ?? 0),
    'username' => (string)($_SESSION['login_otp_username'] ?? ''),
    'role' => (string)($_SESSION['login_otp_role'] ?? ''),
    'email' => (string)($_SESSION['login_otp_email'] ?? ''),
];
$issued = clarenzonIssueLoginOtp($conn, $pendingUser, true);
$conn->close();

if (empty($issued['ok'])) {
    $message = (string)($issued['error'] ?? 'We could not send your verification code. Please try again.');
    if ($wantsJson) {
        clarenzon_json_out(false, $message, ['retry_after' => (int)($issued['retry_after'] ?? 0)], 429);
    }
    $_SESSION['login_flash_error'] = $message;
    header('Location: ' . $verifyUrl);
    exit;
}

$_SESSION['login_otp_token'] = (string)$issued['token'];
$_SESSION['login_otp_email'] = (string)$issued['email'];
$_SESSION['login_otp_expires'] = time() + CLARENZON_LOGIN_PENDING_TTL_SECONDS;
$_SESSION['otp_pending'] = true;
$_SESSION['login_flash_success'] = 'A new verification code was sent to your registered email.';

if ($wantsJson) {
    clarenzon_json_out(true, (string)$_SESSION['login_flash_success'], [
        'retry_after' => CLARENZON_LOGIN_OTP_RESEND_SECONDS,
        'email_masked' => clarenzon_mask_email((string)$issued['email']),
    ]);
}
header('Location: ' . $verifyUrl);
exit;
