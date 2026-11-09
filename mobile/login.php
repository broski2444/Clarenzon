<?php
declare(strict_types=1);
$GLOBALS['MOBILE_ALLOW_GUEST'] = true;
require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'login_otp_lib.php';

$error = '';
$success = '';
$isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';

if (!$isPost) {
    $loginCsrf = mobile_ensure_csrf();
    clarenzon_session_write_close_if_active();
    clarenzon_session_start('login');
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = $loginCsrf;
    }
    if (!clarenzonPendingLoginValid()) {
        clarenzon_session_write_close_if_active();
        mobile_session_start();
        if (!empty($_SESSION['user_id'])) {
            if (mobile_is_allowed_role()) {
                mobile_redirect('dashboard.php');
            }
            mobile_render_denied(mobile_user());
        }
        clarenzon_session_write_close_if_active();
        clarenzon_session_start('login');
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $loginCsrf;
        }
    }
}

if ($isPost) {
    clarenzon_session_start('login');
    if (!mobile_csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['cancel_login_otp'])) {
        clarenzonClearPendingLogin();
    } elseif (isset($_POST['verify_login_otp']) || isset($_POST['resend_login_otp'])) {
        clarenzon_session_start('login');
        if (!clarenzonPendingLoginValid()) {
            $error = 'Your verification session expired. Please sign in again.';
            clarenzonClearPendingLogin();
        } elseif (isset($_POST['resend_login_otp'])) {
            $conn = mobile_db();
            $pendingUser = [
                'id' => (int)($_SESSION['login_otp_user_id'] ?? 0),
                'username' => (string)($_SESSION['login_otp_username'] ?? ''),
                'role' => (string)($_SESSION['login_otp_role'] ?? ''),
                'email' => (string)($_SESSION['login_otp_email'] ?? ''),
            ];
            $issued = clarenzonIssueLoginOtp($conn, $pendingUser, true);
            if (empty($issued['ok'])) {
                $error = (string)($issued['error'] ?? 'We could not send your verification code. Please try again.');
            } else {
                $_SESSION['login_otp_token'] = (string)$issued['token'];
                $_SESSION['login_otp_email'] = (string)$issued['email'];
                $_SESSION['login_otp_expires'] = time() + CLARENZON_LOGIN_PENDING_TTL_SECONDS;
                $_SESSION['otp_pending'] = true;
                $error = '';
                $success = 'A new verification code was sent to your registered email.';
            }
        } else {
            $conn = mobile_db();
            $checked = clarenzonVerifyLoginOtp(
                $conn,
                (int)($_SESSION['login_otp_user_id'] ?? 0),
                (string)($_SESSION['login_otp_token'] ?? ''),
                (string)($_POST['otp'] ?? '')
            );
            if (empty($checked['ok'])) {
                $error = (string)($checked['error'] ?? 'Invalid verification code.');
                if (stripos($error, 'OTP verification failed') !== false) {
                    clarenzonClearPendingLogin();
                }
            } else {
                clarenzonLoginOtpAudit(
                    $conn,
                    (int)$_SESSION['login_otp_user_id'],
                    (string)$_SESSION['login_otp_username'],
                    (string)$_SESSION['login_otp_role'],
                    'LOGIN_SUCCESS',
                    'Successful sign in'
                );
                clarenzon_login_user(
                    (int)$_SESSION['login_otp_user_id'],
                    (string)$_SESSION['login_otp_username'],
                    (string)$_SESSION['login_otp_role'],
                    (string)$_SESSION['login_otp_full_name'],
                    (string)$_SESSION['login_otp_first_name'],
                    (string)$_SESSION['login_otp_last_name']
                );
                clarenzonClearPendingLogin();
                mobile_redirect('dashboard.php');
            }
        }
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $loggedIn = false;
            $authUserId = 0;
            $authUsername = $username;
            $authRole = '';
            $authFullName = $username;
            $authFirstName = '';
            $authLastName = '';
            $conn = mobile_db();
            $auth = clarenzonAuthenticateLogin($conn, $username, $password);
            if (!empty($auth['ok']) && !empty($auth['user'])) {
                $user = $auth['user'];
                $authUserId = (int)$user['id'];
                $authUsername = (string)$user['username'];
                $authRole = (string)$user['role'];
                $names = function_exists('userNameNormalize')
                    ? userNameNormalize(
                        (string)($user['first_name'] ?? ''),
                        (string)($user['last_name'] ?? ''),
                        (string)($user['full_name'] ?? $authUsername)
                    )
                    : ['first_name' => '', 'last_name' => '', 'full_name' => trim((string)($user['full_name'] ?? ''))];
                $authFullName = $names['full_name'] !== '' ? $names['full_name'] : $authUsername;
                $authFirstName = $names['first_name'];
                $authLastName = $names['last_name'];
                $loggedIn = true;
            }
            if ($loggedIn) {
                if (!mobile_is_allowed_role($authRole)) {
                    $error = 'You are not authorized to use this app.';
                } else {
                    if (function_exists('clarenzonLoginSkipsOtp') && clarenzonLoginSkipsOtp($user)) {
                        clarenzon_login_user($authUserId, $authUsername, $authRole, $authFullName, $authFirstName, $authLastName);
                        mobile_redirect('dashboard.php');
                    }
                    $issued = clarenzonIssueLoginOtp($conn, $user, false);
                    if (empty($issued['ok'])) {
                        if (mobile_role_key($authRole) === 'admin') {
                            clarenzon_login_user($authUserId, $authUsername, $authRole, $authFullName, $authFirstName, $authLastName);
                            mobile_redirect('dashboard.php');
                        }
                        $error = (string)($issued['error'] ?? 'We could not send your verification code. Please try again.');
                    } else {
                        clarenzonStorePendingLogin($user, $names, (string)$issued['token'], (string)$issued['email']);
                        $success = 'Enter the 6-digit code sent to your registered email.';
                    }
                }
            } elseif ($error === '') {
                $error = (string)($auth['error'] ?? 'Invalid username or password.');
            }
        }
    }
}

clarenzon_session_start('login');
$csrf = mobile_ensure_csrf();
$showOtp = clarenzonPendingLoginValid();
$otpEmailMasked = $showOtp ? clarenzon_mask_email((string)($_SESSION['login_otp_email'] ?? '')) : '';
$resendWait = $showOtp ? clarenzonLoginOtpResendWait() : 0;
?>
<!DOCTYPE html>
<html lang="en" data-sw="<?= mobile_h(mobile_url('sw.js')) ?>">
<head>
    <?php mobile_pwa_head('Warehouse Login'); ?>
</head>
<body class="m-login-body">
    <main class="m-login">
        <div class="m-login-mark"><?= mobile_icon('cross') ?></div>
        <p class="m-login-kicker">CLARENZON</p>
        <p class="m-login-lead" style="margin-top:0">Medical Supplies</p>
        <h1>Warehouse Mobile</h1>
        <?php if ($error !== ''): ?>
            <div class="m-alert m-alert-danger"><?= mobile_h($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="m-alert" style="background:#ecfdf5;color:#047857;"><?= mobile_h($success) ?></div>
        <?php endif; ?>
        <?php if ($showOtp): ?>
        <p class="m-login-lead">Enter the 6-digit code sent to <?= mobile_h($otpEmailMasked) ?></p>
        <p class="m-login-lead" style="font-size:12px;">Don't see the email? Please check your <strong>Spam</strong> or <strong>Junk</strong> folder.</p>
        <form method="post" action="<?= mobile_h(mobile_url('login.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= mobile_h($csrf) ?>">
            <label class="m-label" for="otp">Verification code</label>
            <input class="m-input" type="text" name="otp" id="otp" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code" placeholder="000000">
            <button class="m-btn m-btn-primary m-btn-block" type="submit" name="verify_login_otp" value="1">Verify OTP</button>
        </form>
        <form method="post" action="<?= mobile_h(mobile_url('login.php')) ?>" style="margin-top:10px;text-align:center;">
            <input type="hidden" name="csrf_token" value="<?= mobile_h($csrf) ?>">
            <button class="m-btn m-btn-ghost m-btn-block" type="submit" name="resend_login_otp" value="1" <?= $resendWait > 0 ? 'disabled' : '' ?>>
                <?= $resendWait > 0 ? 'Resend available in ' . (int)$resendWait . ' seconds' : 'Resend OTP' ?>
            </button>
        </form>
        <form method="post" action="<?= mobile_h(mobile_url('login.php')) ?>" style="margin-top:8px;text-align:center;">
            <input type="hidden" name="csrf_token" value="<?= mobile_h($csrf) ?>">
            <button class="m-btn m-btn-ghost m-btn-block" type="submit" name="cancel_login_otp" value="1">Use a different account</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?= mobile_h(mobile_url('login.php')) ?>" autocomplete="username">
            <input type="hidden" name="csrf_token" value="<?= mobile_h($csrf) ?>">
            <label class="m-label" for="username">Username</label>
            <input class="m-input" type="text" name="username" id="username" required autocomplete="username" autocapitalize="none" placeholder="Admin@clarenzon.com">
            <label class="m-label" for="password">Password</label>
            <div class="m-passwrap">
                <input class="m-input" type="password" name="password" id="password" required autocomplete="current-password" placeholder="Password">
                <button class="m-pass-toggle" type="button" id="togglePass" aria-label="Show password">Show</button>
            </div>
            <button class="m-btn m-btn-primary m-btn-block" type="submit">Login</button>
        </form>
        <?php endif; ?>
    </main>
    <script>
    var toggle = document.getElementById('togglePass');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var el = document.getElementById('password');
            var show = el.type === 'password';
            el.type = show ? 'text' : 'password';
            this.textContent = show ? 'Hide' : 'Show';
        });
    }
    </script>
    <script src="<?= mobile_h(mobile_asset('pwa.js')) ?>"></script>
</body>
</html>
