<?php
/**
 * Confirm the 6-digit login OTP. Session is created only after a valid code.
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
        clarenzon_json_out(false, 'Something went wrong. Please try again.', null, 500);
    }
    $_SESSION['login_flash_error'] = 'Something went wrong. Please try again.';
    header('Location: ' . $verifyUrl);
    exit;
}

$digits = trim((string)($_POST['otp'] ?? ''));
if ($digits === '') {
    for ($i = 1; $i <= 6; $i++) {
        $digits .= (string)($_POST['otp_' . $i] ?? '');
    }
}

$checked = clarenzonVerifyLoginOtp(
    $conn,
    (int)($_SESSION['login_otp_user_id'] ?? 0),
    (string)($_SESSION['login_otp_token'] ?? ''),
    $digits
);

if (empty($checked['ok'])) {
    $message = (string)($checked['error'] ?? 'Invalid verification code.');
    if (stripos($message, 'OTP verification failed') !== false) {
        clarenzonClearPendingLogin();
        $conn->close();
        if ($wantsJson) {
            clarenzon_json_out(false, $message, null, 401);
        }
        $_SESSION['login_flash_error'] = $message;
        header('Location: ' . $loginUrl);
        exit;
    }
    $conn->close();
    if ($wantsJson) {
        clarenzon_json_out(false, $message, ['remaining' => (int)($checked['remaining'] ?? 0)], 401);
    }
    $_SESSION['login_flash_error'] = $message;
    header('Location: ' . $verifyUrl);
    exit;
}

$done = clarenzonCompleteOtpLogin($conn);
$conn->close();
if (empty($done['ok'])) {
    if ($wantsJson) {
        clarenzon_json_out(false, (string)($done['error'] ?? 'Something went wrong. Please try again.'), null, 401);
    }
    $_SESSION['login_flash_error'] = (string)($done['error'] ?? 'Something went wrong. Please try again.');
    header('Location: ' . $loginUrl);
    exit;
}

if ($wantsJson) {
    clarenzon_json_out(true, 'Operation successful', [
        'redirect' => (string)$done['redirect'],
        'role' => (string)$done['role'],
    ]);
}
header('Location: ' . (string)$done['redirect']);
exit;
