<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

route_require_login(['admin']);

$conn = clarenzon_db_connect('root', '', 'clarenzon_db', 3);
if (!$conn) {
    die("Unable to connect. Please try again.");
}

$error_message = '';
$success_message = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_validate_request()) {
        $error_message = "Your session expired. Please try again.";
    } else {
    $currentRole = strtolower(str_replace([' ', '-'], '_', trim((string)($_SESSION['role'] ?? ''))));
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = trim($_POST['role'] ?? 'Purchaser');
    $full_name = trim($_POST['full_name'] ?? '');
    $names = [];
    $nameLib = __DIR__ . DIRECTORY_SEPARATOR . 'user_name_lib.php';
    if (is_file($nameLib)) {
        require_once $nameLib;
        if ($conn instanceof mysqli) {
            userNameEnsureSchema($conn);
        }
        $names = userNameNormalize(
            trim((string)($_POST['first_name'] ?? '')),
            trim((string)($_POST['last_name'] ?? '')),
            $full_name
        );
        $full_name = $names['full_name'];
    }
    
    // Validation
    if (!in_array($currentRole, ['admin', 'administrator'], true)) {
        $error_message = "User accounts must be created by an Admin.";
    } elseif (empty($username) || empty($email) || empty($password) || empty($confirm_password) || $full_name === '') {
        $error_message = "All fields are required!";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match!";
    } elseif (($pwErr = clarenzon_password_validate($password)) !== null) {
        $error_message = $pwErr;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format!";
    } else {
        $allowed_roles = ['Purchaser', 'Technician', 'Techinician'];
        if (!in_array($role, $allowed_roles, true)) {
            $role = 'Purchaser';
        }
        if ($role === 'Techinician') {
            $role = 'Technician';
        }

        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        
        if ($stmt === false) {
            $error_message = "Unable to create the account. Please try again.";
        } else {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error_message = "Username already exists!";
                $stmt->close();
            } else {
                $stmt->close();
                
                // Check if email already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                
                if ($stmt === false) {
                    $error_message = "Unable to create the account. Please try again.";
                } else {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error_message = "Email already registered!";
                        $stmt->close();
                    } else {
                        $stmt->close();
                        
                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Insert new user
                        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role, full_name, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        
                        if ($stmt === false) {
                            $error_message = "Unable to create the account. Please try again.";
                        } else {
                            $firstIns = $names['first_name'] ?? '';
                            $lastIns = $names['last_name'] ?? '';
                            $stmt->bind_param("sssssss", $username, $hashed_password, $email, $role, $full_name, $firstIns, $lastIns);
                            
                            if ($stmt->execute()) {
                                $success_message = "Account created successfully! Redirecting to dashboard...";
                                header("refresh:2;url=admin_dashboard.php");
                            } else {
                                $error_message = "Unable to create the account. Please try again.";
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title>Create Account | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('background.jpg');
            background-size: cover;
            background-position: center;
            filter: blur(8px);
            z-index: -1;
        }

        .container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 40px 20px;
        }

        .register-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 50px 40px;
            width: 100%;
            max-width: 500px;
            text-align: center;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #4FC3F7, #29B6F6);
            border-radius: 50%;
            margin-bottom: 25px;
            position: relative;
        }

        .logo::before {
            content: '';
            position: absolute;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid rgba(79, 195, 247, 0.3);
        }

        .logo-text {
            color: white;
            font-size: 32px;
            font-weight: bold;
            font-family: Arial, sans-serif;
        }

        h1 {
            font-size: 26px;
            color: #333;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .subtitle {
            color: #888;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }

        label {
            display: block;
            color: #333;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            color: #333;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }

        select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #29B6F6;
            background: white;
            box-shadow: 0 0 0 3px rgba(41, 182, 246, 0.1);
        }

        input:hover,
        select:hover {
            border-color: #bbb;
        }

        .register-button {
            width: 100%;
            padding: 14px;
            background: #29B6F6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .register-button:hover {
            background: #0288D1;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(41, 182, 246, 0.4);
        }

        .register-button:active {
            transform: translateY(0);
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #f5c6cb;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #c3e6cb;
        }

        .login-link {
            margin-top: 25px;
            font-size: 14px;
            color: #666;
        }

        .login-link a {
            color: #29B6F6;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .login-link a:hover {
            color: #0288D1;
            text-decoration: underline;
        }

        .back-button {
            position: fixed;
            top: 30px;
            left: 30px;
            width: 50px;
            height: 50px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 24px;
            color: #666;
            text-decoration: none;
        }

        .back-button:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 600px) {
            .register-card {
                padding: 40px 30px;
            }
            
            h1 {
                font-size: 22px;
            }

            .row {
                grid-template-columns: 1fr;
                gap: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="background"></div>
    
    <a href="login.php" class="back-button">←</a>
    
    <div class="container">
        <div class="register-card">
            <div class="logo">
                <span class="logo-text">cm</span>
            </div>
                        
            <h1>Create Account</h1>
            <p class="subtitle">Join Clarenzon today</p>
            
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success_message)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" name="first_name" id="first_name" placeholder="First name" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" name="last_name" id="last_name" placeholder="Last name" required>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" placeholder="Choose a username" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select name="role" id="role" required>
                        <option value="Purchaser">Purchaser</option>
                        <option value="Technician">Technician</option>
                    </select>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" name="password" id="password" placeholder="Create password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm password" required>
                    </div>
                </div>
                
                <button type="submit" class="register-button">Create Account</button>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>
    </div>
</body>
</html>
