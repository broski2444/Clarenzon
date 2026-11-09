<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'login_otp_lib.php';

clarenzon_session_start('login');
clarenzon_security_boot();

if (function_exists('clarenzon_session_start_any_authenticated')) {
    clarenzon_session_write_close_if_active();
    $authed = clarenzon_session_start_any_authenticated();
    if ($authed && !empty($_SESSION['user_id']) && !empty($_SESSION['authenticated'])) {
        header('Location: ' . route_home_for_role((string)$_SESSION['role']));
        exit;
    }
    clarenzon_session_write_close_if_active();
    clarenzon_session_start('login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_login_otp'])) {
    if (clarenzon_csrf_validate_request()) {
        clarenzonClearPendingLogin();
    }
    header('Location: login.php');
    exit;
}

if (!clarenzonPendingLoginValid()) {
    $_SESSION['login_flash_error'] = (string)($_SESSION['login_flash_error'] ?? 'Please sign in to receive a verification code.');
    header('Location: login.php');
    exit;
}

$error_message = '';
$success_message = '';
if (!empty($_SESSION['login_flash_error'])) {
    $error_message = (string)$_SESSION['login_flash_error'];
    unset($_SESSION['login_flash_error']);
}
if (!empty($_SESSION['login_flash_success'])) {
    $success_message = (string)$_SESSION['login_flash_success'];
    unset($_SESSION['login_flash_success']);
}

$otpEmailMasked = clarenzon_mask_email((string)($_SESSION['login_otp_email'] ?? ''));
$resendWait = clarenzonLoginOtpResendWait();
$cssVersion = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'login.css') ?: time();
$jsVersion = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'java' . DIRECTORY_SEPARATOR . 'otp.js') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?= clarenzon_csrf_meta_tag() ?>
    <title>Verify Login | Clarenzon Medical Supplies</title>
    <link rel="icon" type="image/png" href="images/clarenzon3-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <script src="java/app_dialog.js?v=<?= time() ?>"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/login.css?v=<?= (int)$cssVersion ?>">
</head>
<body>
    <div class="bg-left">
        <div class="left-overlay"></div>
        <div class="left-content">
            <p class="eyebrow">Welcome to</p>
            <h1 class="brand-title">Clarenzon<br><span>Marketing Corporation</span></h1>
            <div class="brand-divider"></div>
            <p class="brand-tagline">CLARENZON Medical Supplies Inventory &amp; Operations System for laboratory equipment and medical supplies.</p>
        </div>
    </div>
    <div class="bg-right"></div>

    <div class="container">
        <div class="login-wrap">
            <div class="login-columns">
                <div class="login-card">
                    <img src="images/clarenzon3-removebg.png" alt="Clarenzon" class="logo-img">
                    <p class="welcome-message">Verify Your Login</p>
                    <p class="welcome-subtitle">We sent a 6-digit verification code to your registered email address.</p>
                    <div class="subtitle-underline"></div>

                    <?php if ($error_message !== ''): ?>
                        <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
                    <?php endif; ?>
                    <?php if ($success_message !== ''): ?>
                        <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="backend/api/auth/verify_otp.php" id="otpForm">
                        <?= clarenzon_csrf_field() ?>
                        <p class="otp-hint">Code sent to <strong><?= htmlspecialchars($otpEmailMasked) ?></strong>. It expires in 5 minutes.</p>
                        <p class="otp-hint otp-spam-note">Don't see the email? Please check your <strong>Spam</strong> or <strong>Junk</strong> folder.</p>
                        <div class="otp-row">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <input class="otp-box" type="text" inputmode="numeric" name="otp_<?= $i ?>" id="otp_<?= $i ?>" maxlength="1" pattern="[0-9]" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>" required>
                            <?php endfor; ?>
                        </div>
                        <button type="submit" name="verify_login_otp" class="login-button" value="1">VERIFY OTP</button>
                    </form>
                    <form method="POST" action="backend/api/auth/resend_otp.php" class="resend-form" id="resendForm">
                        <?= clarenzon_csrf_field() ?>
                        <button type="submit" name="resend_login_otp" class="resend-link" id="resendOtpBtn" value="1" <?= $resendWait > 0 ? 'disabled' : '' ?>>
                            <?= $resendWait > 0 ? 'Resend available in ' . (int)$resendWait . ' seconds' : "Didn't receive the code? Resend OTP" ?>
                        </button>
                    </form>
                    <form method="POST" action="verify_otp.php" class="resend-form">
                        <?= clarenzon_csrf_field() ?>
                        <button type="submit" name="cancel_login_otp" class="resend-link" value="1">Use a different account</button>
                    </form>
                </div>
            </div>

            <div class="login-footer">
                <p class="footer-tags">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Secure &bull; Reliable &bull; Efficient
                </p>
                <p class="footer-copy">&copy; 2026 Clarenzon Marketing Corporation<br>All rights reserved.</p>
            </div>
        </div>
    </div>

    <button class="help-button" onclick="alert('For assistance, please contact your system administrator.\n\nEmail: support@clarenzon.com')">?</button>
    <script>window.CLARENZON_OTP_RESEND_WAIT = <?= (int)$resendWait ?>;</script>
    <script src="java/otp.js?v=<?= (int)$jsVersion ?>"></script>
</body>
</html>
