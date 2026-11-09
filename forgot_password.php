<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'official_accounts_lib.php';

clarenzon_session_start('login');
clarenzon_security_boot();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'password_reset_lib.php';
$conn = clarenzon_db_connect('root', '', 'clarenzon_db', 3);
if (!$conn) {
    die("Unable to connect. Please try again.");
}

clarenzonEnsureOfficialUsernames($conn);
clarenzonEnsurePasswordResetSchema($conn);

$error_message = '';
$success_message = '';
$step = 1;
$postedEmail = (string)($_SESSION['reset_email'] ?? '');

function forgotResetStep(): int
{
    if (empty($_SESSION['reset_token']) || empty($_SESSION['reset_user_id'])) {
        return 1;
    }
    if ((int)($_SESSION['reset_expires'] ?? 0) < time() || (string)($_SESSION['reset_ip'] ?? '') !== clarenzon_client_ip()) {
        clarenzonClearPasswordResetSession();
        return 1;
    }
    return !empty($_SESSION['reset_verified']) ? 3 : 2;
}

function forgotPasswordRedirect(string $query = '', string $error = '', string $success = ''): void
{
    if ($error !== '') {
        $_SESSION['reset_flash_error'] = $error;
    }
    if ($success !== '') {
        $_SESSION['reset_flash_success'] = $success;
    }
    $url = 'forgot_password.php';
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header('Location: ' . $url, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clarenzon_csrf_validate_request()) {
        $resume = forgotResetStep();
        $query = $resume === 3 ? 'password=1' : ($resume === 2 ? 'verify=1' : '');
        forgotPasswordRedirect($query, 'Your session expired. Please try again.');
    } elseif (isset($_POST['send_otp']) || isset($_POST['resend_otp'])) {
        $isResend = isset($_POST['resend_otp']);
        $email = trim((string)($isResend ? ($_SESSION['reset_email'] ?? '') : ($_POST['email'] ?? '')));
        $postedEmail = $email;
        if (!$isResend && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            forgotPasswordRedirect('', 'Enter a valid email address.');
        }
        $resetThrottle = clarenzon_throttle_check('reset_ip', clarenzon_client_ip(), CLARENZON_RESET_MAX_ATTEMPTS, CLARENZON_RESET_WINDOW_SECONDS);
        if (!empty($resetThrottle['blocked'])) {
            forgotPasswordRedirect($isResend ? 'verify=1' : '', (string)$resetThrottle['message']);
        }
        clarenzon_throttle_hit('reset_ip', clarenzon_client_ip(), CLARENZON_RESET_MAX_ATTEMPTS, CLARENZON_RESET_WINDOW_SECONDS);
        $user = clarenzonFindUserByEmail($conn, $email);
        if (!$user) {
            forgotPasswordRedirect('', 'No account was found for that email address.');
        }
        $issued = clarenzonIssuePasswordOtp($conn, $user);
        if (empty($issued['ok'])) {
            forgotPasswordRedirect(
                $isResend ? 'verify=1' : '',
                (string)($issued['error'] ?? 'Unable to send the verification email. Please try again.')
            );
        }
        $_SESSION['reset_user_id'] = (int)$user['id'];
        $_SESSION['reset_email'] = (string)$user['email'];
        $_SESSION['reset_token'] = (string)$issued['token'];
        $_SESSION['reset_verified'] = false;
        $_SESSION['reset_expires'] = time() + CLARENZON_OTP_TTL_SECONDS;
        $_SESSION['reset_ip'] = clarenzon_client_ip();
        forgotPasswordRedirect(
            'verify=1',
            '',
            $isResend
                ? 'A new verification code was sent to your email. The previous code is no longer valid.'
                : 'A verification code was sent to your registered email.'
        );
    } elseif (isset($_POST['verify_otp'])) {
        $postedToken = (string)($_POST['reset_token'] ?? '');
        $sessionToken = (string)($_SESSION['reset_token'] ?? '');
        $digits = '';
        for ($i = 1; $i <= 6; $i++) {
            $digits .= (string)($_POST['otp_' . $i] ?? '');
        }
        if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
            clarenzonClearPasswordResetSession();
            forgotPasswordRedirect('', 'Your session expired. Please request a new code.');
        } elseif ((int)($_SESSION['reset_expires'] ?? 0) < time()) {
            forgotPasswordRedirect('verify=1', 'This verification code has expired. Request a new code.');
        } elseif ((string)($_SESSION['reset_ip'] ?? '') !== clarenzon_client_ip()) {
            clarenzonClearPasswordResetSession();
            forgotPasswordRedirect('', 'This password reset is no longer valid. Please start over.');
        } else {
            $checked = clarenzonVerifyPasswordOtp($conn, (int)($_SESSION['reset_user_id'] ?? 0), $sessionToken, $digits);
            if (empty($checked['ok'])) {
                $error_message = (string)($checked['error'] ?? 'The verification code is incorrect.');
                if (stripos($error_message, 'expired') !== false || stripos($error_message, 'no longer valid') !== false || stripos($error_message, 'already been used') !== false || stripos($error_message, 'Too many') !== false) {
                    clarenzonClearPasswordResetSession();
                    forgotPasswordRedirect('', $error_message);
                }
                forgotPasswordRedirect('verify=1', $error_message);
            }
            $_SESSION['reset_verified'] = true;
            $_SESSION['reset_expires'] = time() + CLARENZON_OTP_TTL_SECONDS;
            forgotPasswordRedirect('password=1', '', 'Verification successful. Create your new password.');
        }
    } elseif (isset($_POST['new_password'])) {
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');
        if (empty($_SESSION['reset_verified']) || empty($_SESSION['reset_user_id'])) {
            clarenzonClearPasswordResetSession();
            forgotPasswordRedirect('', 'Verify the code from your email before changing the password.');
        } elseif ((int)($_SESSION['reset_expires'] ?? 0) < time()) {
            clarenzonClearPasswordResetSession();
            forgotPasswordRedirect('', 'This password reset expired. Please start over.');
        } elseif ((string)($_SESSION['reset_ip'] ?? '') !== clarenzon_client_ip()) {
            clarenzonClearPasswordResetSession();
            forgotPasswordRedirect('', 'This password reset is no longer valid. Please start over.');
        } elseif (($resetTok = (string)($_SESSION['reset_token'] ?? '')) === ''
            || ($postedTok = (string)($_POST['reset_token'] ?? '')) === ''
            || !hash_equals($resetTok, $postedTok)) {
            forgotPasswordRedirect('password=1', 'Your session expired. Please try again.');
        } elseif ($new_password === '' || $confirm_password === '') {
            forgotPasswordRedirect('password=1', 'Please fill in all fields.');
        } elseif ($new_password !== $confirm_password) {
            forgotPasswordRedirect('password=1', 'Passwords do not match.');
        } elseif (($pwErr = clarenzon_password_validate($new_password)) !== null) {
            forgotPasswordRedirect('password=1', $pwErr);
        } else {
            $user_id = (int)$_SESSION['reset_user_id'];
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $emptyPlain = '';
            $stmt = $conn->prepare("UPDATE users SET password = ?, plain_password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ssi', $hashed_password, $emptyPlain, $user_id);
                if ($stmt->execute()) {
                    $auditUser = 'User';
                    $auditRole = 'User';
                    $who = $conn->prepare('SELECT username, role FROM users WHERE id = ? LIMIT 1');
                    if ($who) {
                        $who->bind_param('i', $user_id);
                        $who->execute();
                        $whoRow = $who->get_result()->fetch_assoc();
                        $who->close();
                        if ($whoRow) {
                            $auditUser = (string)$whoRow['username'];
                            $auditRole = (string)$whoRow['role'];
                        }
                    }
                    $log = $conn->prepare("INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, 'password_reset', 'Module: User Management. Password was reset using email verification.')");
                    if ($log) {
                        $log->bind_param('iss', $user_id, $auditUser, $auditRole);
                        $log->execute();
                        $log->close();
                    }
                    clarenzonClearPasswordResetSession();
                    $success_message = 'Password changed successfully. You can now log in using your new password.';
                    $step = 4;
                    header('refresh:2;url=login.php');
                } else {
                    forgotPasswordRedirect('password=1', 'Unable to reset password. Please try again.');
                }
                $stmt->close();
            } else {
                forgotPasswordRedirect('password=1', 'Unable to reset password. Please try again.');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $step !== 4) {
    $resume = forgotResetStep();
    if (isset($_GET['verify'])) {
        $step = $resume === 2 ? 2 : 1;
    } elseif (isset($_GET['password'])) {
        $step = $resume === 3 ? 3 : 1;
    } else {
        $step = 1;
    }
    if ($step === 1) {
        clarenzonClearPasswordResetSession();
        $postedEmail = '';
    } else {
        $postedEmail = (string)($_SESSION['reset_email'] ?? $postedEmail);
    }
}

if (!empty($_SESSION['reset_flash_error'])) {
    $error_message = (string)$_SESSION['reset_flash_error'];
    unset($_SESSION['reset_flash_error']);
}
if (!empty($_SESSION['reset_flash_success'])) {
    $success_message = (string)$_SESSION['reset_flash_success'];
    unset($_SESSION['reset_flash_success']);
}

$conn->close();
$cssVersion = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'forgot_password.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= clarenzon_csrf_meta_tag() ?>
    <title>Forgot Password | Clarenzon Medical Supplies</title>
    <link rel="icon" type="image/png" href="images/clarenzon3-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/forgot_password.css?v=<?= (int)$cssVersion ?>">
</head>
<body>
    <div class="bg-left">
        <div class="left-overlay"></div>
        <div class="left-content">
            <p class="eyebrow">Account Recovery</p>
            <h1 class="brand-title">Clarenzon<br><span>Marketing Corporation</span></h1>
            <div class="brand-divider"></div>
            <p class="brand-tagline">CLARENZON Medical Supplies Inventory &amp; Operations System</p>
        </div>
    </div>
    <div class="bg-right"></div>

    <?php
        $backHref = ($step === 2 || $step === 3) ? 'forgot_password.php' : 'login.php';
        $backLabel = ($step === 2 || $step === 3) ? 'Back to email' : 'Back to login';
    ?>
    <a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>" class="back-button" aria-label="<?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
    </a>

    <div class="container">
        <div class="reset-wrap">
            <div class="reset-columns">
                <div class="reset-card">
                    <img src="images/clarenzon3-removebg.png" alt="Clarenzon" class="logo-img">
                    <h1>Forgot Password</h1>

                    <?php if ($step === 1): ?>
                        <p class="subtitle">Enter your registered email address and we will send a verification code</p>
                    <?php elseif ($step === 2): ?>
                        <p class="subtitle">Enter the verification code sent to your registered email</p>
                    <?php else: ?>
                        <p class="subtitle">Create a new password for your account</p>
                    <?php endif; ?>

                    <div class="step-indicator steps-3">
                        <div class="step-item">
                            <div class="step <?= $step === 1 ? 'active' : 'done' ?>">
                                <?= $step === 1 ? '1' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' ?>
                            </div>
                            <span class="step-label">Email</span>
                        </div>
                        <div class="step-connector <?= $step >= 2 ? 'filled' : '' ?>"></div>
                        <div class="step-item">
                            <div class="step <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : 'inactive') ?>">
                                <?= $step > 2 ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' : '2' ?>
                            </div>
                            <span class="step-label">Verify OTP</span>
                        </div>
                        <div class="step-connector <?= $step >= 3 ? 'filled' : '' ?>"></div>
                        <div class="step-item">
                            <div class="step <?= $step >= 3 ? 'active' : 'inactive' ?>">3</div>
                            <span class="step-label">New Password</span>
                        </div>
                    </div>

                    <?php if (!empty($error_message)): ?>
                        <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($success_message)): ?>
                        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
                    <?php endif; ?>

                    <?php if ($step === 1): ?>
                        <form method="POST" action="">
                            <?= clarenzon_csrf_field() ?>
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <div class="input-wrap">
                                    <span class="input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 6l-10 7L2 6"></path><rect x="2" y="4" width="20" height="16" rx="2"></rect></svg>
                                    </span>
                                    <input type="email" name="email" id="email" placeholder="Enter your email address" required autocomplete="email" autocapitalize="none" spellcheck="false" value="<?php echo htmlspecialchars($postedEmail); ?>">
                                </div>
                            </div>
                            <button type="submit" name="send_otp" class="reset-button">Send OTP</button>
                        </form>
                    <?php elseif ($step === 2): ?>
                        <form method="POST" action="" id="otpForm">
                            <?= clarenzon_csrf_field() ?>
                            <input type="hidden" name="reset_token" value="<?= htmlspecialchars((string)($_SESSION['reset_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <p class="subtitle" style="margin-top:0;">Enter Verification Code</p>
                            <p class="subtitle">We sent a verification code to your registered email.</p>
                            <div class="otp-row">
                                <?php for ($i = 1; $i <= 6; $i++): ?>
                                    <input class="otp-box" type="text" inputmode="numeric" name="otp_<?= $i ?>" id="otp_<?= $i ?>" maxlength="1" pattern="[0-9]" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>" required>
                                <?php endfor; ?>
                            </div>
                            <button type="submit" name="verify_otp" class="reset-button">Verify OTP</button>
                        </form>
                        <form method="POST" action="" class="resend-form">
                            <?= clarenzon_csrf_field() ?>
                            <button type="submit" name="resend_otp" class="resend-link">Resend OTP</button>
                        </form>
                    <?php elseif ($step === 3): ?>
                        <form method="POST" action="">
                            <?= clarenzon_csrf_field() ?>
                            <input type="hidden" name="reset_token" value="<?= htmlspecialchars((string)($_SESSION['reset_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <div class="password-wrap">
                                    <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required autocomplete="new-password">
                                    <button type="button" class="toggle-password" onclick="togglePassword('new_password', this)" aria-label="Show password">
                                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12C2 12 6 6 12 6C18 6 22 12 22 12C22 12 18 18 12 18C6 18 2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12C2 12 6 6 12 6C18 6 22 12 22 12C22 12 18 18 12 18C6 18 2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><line x1="3" y1="20" x2="21" y2="4"></line></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <div class="password-wrap">
                                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required autocomplete="new-password">
                                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password', this)" aria-label="Show password">
                                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12C2 12 6 6 12 6C18 6 22 12 22 12C22 12 18 18 12 18C6 18 2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12C2 12 6 6 12 6C18 6 22 12 22 12C22 12 18 18 12 18C6 18 2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><line x1="3" y1="20" x2="21" y2="4"></line></svg>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="change_password" class="reset-button">Change Password</button>
                        </form>
                    <?php endif; ?>

                    <div class="login-link">
                        Remember your password? <a href="login.php">Login here</a>
                    </div>
                </div>
            </div>

            <div class="reset-footer">
                <p class="footer-tags">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Secure &bull; Reliable &bull; Efficient
                </p>
                <p class="footer-copy">&copy; 2026 Clarenzon Marketing Corporation<br>All rights reserved.</p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const eye = btn.querySelector('.icon-eye');
            const eyeOff = btn.querySelector('.icon-eye-off');
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            eye.style.display = isHidden ? 'none' : 'inline';
            eyeOff.style.display = isHidden ? 'inline' : 'none';
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        }
        document.querySelectorAll('.otp-box').forEach(function (box, index, boxes) {
            box.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 1);
                if (this.value && boxes[index + 1]) boxes[index + 1].focus();
            });
            box.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && !this.value && boxes[index - 1]) boxes[index - 1].focus();
            });
            box.addEventListener('paste', function (event) {
                const text = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                if (!text) return;
                event.preventDefault();
                text.split('').forEach(function (digit, digitIndex) {
                    if (boxes[digitIndex]) boxes[digitIndex].value = digit;
                });
                const focusAt = Math.min(text.length, boxes.length - 1);
                boxes[focusAt].focus();
            });
        });
    </script>
</body>
</html>
