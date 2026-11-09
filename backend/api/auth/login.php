<?php
/**
 * Sign-in endpoint. Browser form posts redirect; JSON clients receive JSON.
 * The login page remains the form.
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'connection.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'auth_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'json_response.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

clarenzon_session_start('login');
clarenzon_security_boot();

$wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if ($wantsJson) {
        clarenzon_json_out(false, 'Operation failed', null, 405);
    }
    header('Location: ' . clarenzon_project_web_base() . 'login.php');
    exit;
}

$conn = clarenzon_connection('clarenzon_db');
if (!$conn) {
    if ($wantsJson) {
        clarenzon_json_out(false, 'Unable to complete the request.', null, 500);
    }
    $_SESSION['login_flash_error'] = 'Unable to complete the request.';
    header('Location: ' . clarenzon_project_web_base() . 'login.php');
    exit;
}

if (function_exists('clarenzonEnsureOfficialUsernames')) {
    clarenzonEnsureOfficialUsernames($conn);
}

if (!clarenzon_csrf_validate_request()) {
    $conn->close();
    if ($wantsJson) {
        clarenzon_json_out(false, 'Your session expired. Please try again.', null, 403);
    }
    $_SESSION['login_flash_error'] = 'Your session expired. Please try again.';
    header('Location: ' . clarenzon_project_web_base() . 'login.php');
    exit;
}

$username = trim((string)($_POST['username'] ?? $_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$result = clarenzon_login_attempt($conn, $username, $password);
$conn->close();

if (!empty($result['ok'])) {
    if ($wantsJson) {
        clarenzon_json_out(true, (string)$result['message'], [
            'redirect' => (string)$result['redirect'],
            'role' => (string)$result['role'],
            'otp_required' => !empty($result['otp_required']),
        ]);
    }
    header('Location: ' . (string)$result['redirect']);
    exit;
}

if ($wantsJson) {
    clarenzon_json_out(false, (string)$result['message'], null, 401);
}

$_SESSION['login_flash_error'] = (string)$result['message'];
$_SESSION['login_flash_username'] = (string)$result['username'];
header('Location: ' . clarenzon_project_web_base() . 'login.php');
exit;
