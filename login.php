<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_name_lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'official_accounts_lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'auth_service.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'login_otp_lib.php';

clarenzon_session_start('login');
clarenzon_security_boot();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

if (clarenzonPendingLoginValid()) {
    header('Location: verify_otp.php');
    exit();
}

$db_name = 'clarenzon_db';
$db_user = 'root';
$db_pass = '';
$conn = clarenzon_db_connect($db_user, $db_pass, $db_name, 2);

$error_message = '';
$success_message = '';
$postedUsername = '';
if (($_GET['account'] ?? '') === 'inactive' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error_message = 'Your account is currently inactive. Please contact the administrator.';
}
if ($error_message === '' && !empty($_SESSION['login_flash_error'])) {
    $error_message = (string)$_SESSION['login_flash_error'];
    $postedUsername = (string)($_SESSION['login_flash_username'] ?? '');
    unset($_SESSION['login_flash_error'], $_SESSION['login_flash_username']);
}
if ($success_message === '' && !empty($_SESSION['login_flash_success'])) {
    $success_message = (string)$_SESSION['login_flash_success'];
    unset($_SESSION['login_flash_success']);
}

if ($conn && !$conn->connect_error) {
    clarenzonEnsureOfficialUsernames($conn);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!clarenzon_csrf_validate_request()) {
        $error_message = 'Your session expired. Please try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? $_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $postedUsername = $username;
        $result = clarenzon_login_attempt($conn, $username, $password);
        if (!empty($result['ok'])) {
            header('Location: ' . (string)$result['redirect']);
            exit();
        }
        $error_message = (string)$result['message'];
    }
}

if ($conn) { $conn->close(); }
$cssVersion = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'login.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?= clarenzon_csrf_meta_tag() ?>
    <title>Login | Clarenzon Medical Supplies</title>
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
                    <p class="welcome-message">Sign in with your official Clarenzon account</p>
                    <p class="welcome-subtitle">CLARENZON Medical Supplies Inventory &amp; Operations System</p>
                    <div class="subtitle-underline"></div>

                    <?php if (!empty($error_message)): ?>
                        <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($success_message)): ?>
                        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="backend/api/auth/login.php" id="loginForm">
                        <?= clarenzon_csrf_field() ?>
                        <div class="form-group">
                            <label for="username">Username</label>
                            <div class="input-wrap">
                                <span class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                </span>
                                <input type="text" name="username" id="username" placeholder="Enter your username" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?php echo htmlspecialchars($postedUsername); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="password-wrap">
                                <input type="password" name="password" id="password" placeholder="Enter password" required autocomplete="current-password">
                                <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Show password">
                                    <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12C2 12 6 6 12 6C18 6 22 12 22 12C22 12 18 18 12 18C6 18 2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12C2 12 6 6 12 6C18 6 22 12 22 12C22 12 18 18 12 18C6 18 2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><line x1="3" y1="20" x2="21" y2="4"></line></svg>
                                </button>
                            </div>
                        </div>

                        <div class="links-container">
                            <div class="remember-me">
                                <input type="checkbox" id="remember" name="remember">
                                <label for="remember" style="margin: 0; cursor: pointer;">Remember me</label>
                            </div>
                            <a href="forgot_password.php" class="forgot-password">Forgot password?</a>
                        </div>

                        <button type="submit" class="login-button" id="loginButton">LOGIN</button>
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

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const btn = document.querySelector('.toggle-password');
            const eye = btn.querySelector('.icon-eye');
            const eyeOff = btn.querySelector('.icon-eye-off');
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            eye.style.display = isHidden ? 'none' : 'inline';
            eyeOff.style.display = isHidden ? 'inline' : 'none';
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        }
        document.getElementById('loginForm') && document.getElementById('loginForm').addEventListener('submit', function () {
            const btn = document.getElementById('loginButton');
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.textContent = 'Signing in...';
        });
    </script>
</body>
</html>
