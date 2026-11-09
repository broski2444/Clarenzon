<?php
/**
 * Official testing usernames. Internal roles stay admin / warehouse_head / purchaser.
 */

function clarenzonOfficialAccounts(): array
{
    return [
        [
            'username' => 'Admin@clarenzon.com',
            'email' => 'Admin@clarenzon.com',
            'role_keys' => ['admin'],
            'old_usernames' => ['admin', 'Admin', 'admin@clarenzon.com', 'admin@gmail.com'],
        ],
        [
            'username' => 'Warehousehead@clarenzon.com',
            'email' => 'Warehousehead@clarenzon.com',
            'role_keys' => ['warehouse_head'],
            'old_usernames' => ['warehousehead', 'warehouse_head', 'Warehousehead', 'warehousehead@gmail.com', 'warehouse@clarenzon.com'],
        ],
        [
            'username' => 'Purchaser@clarenzon.com',
            'email' => 'Purchaser@clarenzon.com',
            'role_keys' => ['purchaser'],
            'old_usernames' => ['purchaser', 'Purchaser', 'purchaser@gmail.com', 'purchaser@clarenzon.com'],
        ],
    ];
}

function clarenzonOfficialLoginAliases(string $login): array
{
    $login = trim($login);
    $out = $login !== '' ? [$login] : [];
    foreach (clarenzonOfficialAccounts() as $acct) {
        $names = array_merge([(string)$acct['username'], (string)$acct['email']], $acct['old_usernames']);
        foreach ($names as $n) {
            if (strcasecmp((string)$n, $login) === 0) {
                foreach ($names as $alias) {
                    $alias = trim((string)$alias);
                    if ($alias !== '') {
                        $out[] = $alias;
                    }
                }
                break 2;
            }
        }
    }
    $uniq = [];
    foreach ($out as $name) {
        $uniq[strtolower($name)] = $name;
    }
    return array_values($uniq);
}

function clarenzonUsersHasEmailColumn(mysqli $conn): bool
{
    $emailCol = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
    return (bool)($emailCol && $emailCol->num_rows > 0);
}

function clarenzonEnsureOfficialUsernames(mysqli $conn): void
{
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;

    @$conn->query("ALTER TABLE users MODIFY COLUMN username VARCHAR(120) NOT NULL");
    $hasEmail = clarenzonUsersHasEmailColumn($conn);
    if ($hasEmail) {
        @$conn->query("ALTER TABLE users MODIFY COLUMN email VARCHAR(120) NULL");
    }

    if (!function_exists('normalize_user_role')) {
        $wp = __DIR__ . DIRECTORY_SEPARATOR . 'warehouse_permissions.php';
        if (is_file($wp)) {
            require_once $wp;
        }
    }

    foreach (clarenzonOfficialAccounts() as $acct) {
        $newUser = (string)$acct['username'];
        $newEmail = (string)$acct['email'];
        $roleKeys = $acct['role_keys'];
        $targetId = 0;

        $st = $conn->prepare($hasEmail
            ? 'SELECT id, username, role, email FROM users WHERE username = ? LIMIT 1'
            : 'SELECT id, username, role FROM users WHERE username = ? LIMIT 1');
        if ($st) {
            $st->bind_param('s', $newUser);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                $targetId = (int)$row['id'];
            }
        }

        if ($targetId <= 0) {
            foreach ($acct['old_usernames'] as $old) {
                $st = $conn->prepare($hasEmail
                    ? 'SELECT id, username, role, email FROM users WHERE username = ? OR LOWER(username) = LOWER(?) LIMIT 1'
                    : 'SELECT id, username, role FROM users WHERE username = ? OR LOWER(username) = LOWER(?) LIMIT 1');
                if (!$st) {
                    continue;
                }
                $st->bind_param('ss', $old, $old);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$row) {
                    continue;
                }
                $roleKey = function_exists('normalize_user_role')
                    ? normalize_user_role((string)$row['role'])
                    : strtolower(str_replace([' ', '-'], '_', (string)$row['role']));
                if (!in_array($roleKey, $roleKeys, true)) {
                    continue;
                }
                $targetId = (int)$row['id'];
                break;
            }
        }

        if ($targetId <= 0) {
            continue;
        }

        $clash = null;
        if ($hasEmail) {
            $taken = $conn->prepare('SELECT id FROM users WHERE id <> ? AND (username = ? OR email = ? OR LOWER(username) = LOWER(?) OR LOWER(COALESCE(email,\'\')) = LOWER(?)) LIMIT 1');
            if ($taken) {
                $taken->bind_param('issss', $targetId, $newUser, $newEmail, $newUser, $newEmail);
                $taken->execute();
                $clash = $taken->get_result()->fetch_assoc();
                $taken->close();
            }
        } else {
            $taken = $conn->prepare('SELECT id FROM users WHERE id <> ? AND (username = ? OR LOWER(username) = LOWER(?)) LIMIT 1');
            if ($taken) {
                $taken->bind_param('iss', $targetId, $newUser, $newUser);
                $taken->execute();
                $clash = $taken->get_result()->fetch_assoc();
                $taken->close();
            }
        }
        if ($clash) {
            continue;
        }

        $currentEmail = $hasEmail ? trim((string)($row['email'] ?? '')) : '';
        $keepGmail = $hasEmail && (bool)preg_match('/@gmail\.com$/i', $currentEmail);

        if ($hasEmail && !$keepGmail) {
            $upd = $conn->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
            if ($upd) {
                $upd->bind_param('ssi', $newUser, $newEmail, $targetId);
                $upd->execute();
                $upd->close();
            }
        } else {
            $upd = $conn->prepare('UPDATE users SET username = ? WHERE id = ?');
            if ($upd) {
                $upd->bind_param('si', $newUser, $targetId);
                $upd->execute();
                $upd->close();
            }
        }
    }

    clarenzonEnsureLocalNoOtpAccounts($conn);
}

function clarenzonIsProduction(): bool
{
    return strtolower((string)getenv('APP_ENV')) === 'production';
}

function clarenzonLocalNoOtpAccounts(): array
{
    return [
        [
            'username' => 'admin.test',
            'password' => '123',
            'email' => 'admin.test@example.com',
            'role' => 'Admin',
            'full_name' => 'Admin Test',
            'first_name' => 'Admin',
            'last_name' => 'Test',
        ],
        [
            'username' => 'purchaser.test',
            'password' => '123',
            'email' => 'purchaser.test@example.com',
            'role' => 'Purchaser',
            'full_name' => 'Purchaser Test',
            'first_name' => 'Purchaser',
            'last_name' => 'Test',
        ],
        [
            'username' => 'warehouse.test',
            'password' => '123',
            'email' => 'warehouse.test@example.com',
            'role' => 'Warehouse Head',
            'full_name' => 'Warehouse Test',
            'first_name' => 'Warehouse',
            'last_name' => 'Test',
        ],
        [
            'username' => 'cashier.test',
            'password' => '123',
            'email' => 'cashier.test@example.com',
            'role' => 'Cashier',
            'full_name' => 'Cashier Test',
            'first_name' => 'Cashier',
            'last_name' => 'Test',
        ],
    ];
}

function clarenzonLoginSkipsOtp(array $user): bool
{
    if (clarenzonIsProduction()) {
        return false;
    }
    $username = strtolower(trim((string)($user['username'] ?? '')));
    foreach (clarenzonLocalNoOtpAccounts() as $acct) {
        if ($username === strtolower((string)$acct['username'])) {
            return true;
        }
    }
    return false;
}

function clarenzonEnsureLocalNoOtpAccounts(mysqli $conn): void
{
    static $done = false;
    if ($done || !$conn || clarenzonIsProduction()) {
        return;
    }
    $done = true;

    $permTable = $conn->query("SHOW TABLES LIKE 'user_permissions'");
    $hasPerms = (bool)($permTable && $permTable->num_rows > 0);
    if ($hasPerms && !function_exists('clarenzonDefaultSectionsForRole')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_account_lib.php';
    }

    foreach (clarenzonLocalNoOtpAccounts() as $acct) {
        $username = (string)$acct['username'];
        $email = (string)$acct['email'];
        $role = (string)$acct['role'];
        $fullName = (string)$acct['full_name'];
        $firstName = (string)$acct['first_name'];
        $lastName = (string)$acct['last_name'];
        $status = 'Active';
        $mustChange = 0;
        $emptyPlain = '';

        $st = $conn->prepare('SELECT id, password FROM users WHERE username = ? LIMIT 1');
        $existingId = 0;
        $existingHash = '';
        if ($st) {
            $st->bind_param('s', $username);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            $existingId = (int)($row['id'] ?? 0);
            $existingHash = (string)($row['password'] ?? '');
        }

        if ($existingId > 0) {
            $upd = $conn->prepare(
                'UPDATE users SET email = ?, full_name = ?, first_name = ?, last_name = ?, role = ?, status = ?, must_change_password = ? WHERE id = ?'
            );
            if ($upd) {
                $upd->bind_param(
                    'ssssssii',
                    $email,
                    $fullName,
                    $firstName,
                    $lastName,
                    $role,
                    $status,
                    $mustChange,
                    $existingId
                );
                $upd->execute();
                $upd->close();
            }
            if ($existingHash === '' || !password_verify((string)$acct['password'], $existingHash)) {
                $hash = password_hash((string)$acct['password'], PASSWORD_DEFAULT);
                $pw = $conn->prepare('UPDATE users SET password = ?, plain_password = ? WHERE id = ?');
                if ($pw) {
                    $pw->bind_param('ssi', $hash, $emptyPlain, $existingId);
                    $pw->execute();
                    $pw->close();
                }
            }
            $userId = $existingId;
        } else {
            $hash = password_hash((string)$acct['password'], PASSWORD_DEFAULT);
            $ins = $conn->prepare(
                'INSERT INTO users (username, password, plain_password, email, full_name, first_name, last_name, role, status, must_change_password) VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            if (!$ins) {
                continue;
            }
            $ins->bind_param(
                'sssssssssi',
                $username,
                $hash,
                $emptyPlain,
                $email,
                $fullName,
                $firstName,
                $lastName,
                $role,
                $status,
                $mustChange
            );
            $ok = $ins->execute();
            $userId = (int)$conn->insert_id;
            $ins->close();
            if (!$ok || $userId <= 0) {
                continue;
            }
        }

        if (!$hasPerms || $userId <= 0 || !function_exists('clarenzonDefaultSectionsForRole')) {
            continue;
        }
        $sections = clarenzonDefaultSectionsForRole($role);
        $canPrice = strcasecmp($role, 'Purchaser') === 0 ? 1 : 0;
        $canApprove = strcasecmp($role, 'Cashier') === 0 ? 0 : 1;
        $perm = $conn->prepare(
            'INSERT INTO user_permissions (user_id, allowed_sections, can_manage_records, can_print_records, can_approve_pos, can_delete_records, can_manage_pricing)
             VALUES (?, ?, 1, 1, ?, 0, ?)
             ON DUPLICATE KEY UPDATE allowed_sections = VALUES(allowed_sections)'
        );
        if ($perm) {
            $perm->bind_param('isii', $userId, $sections, $canApprove, $canPrice);
            $perm->execute();
            $perm->close();
        }
    }
}

function clarenzonLookupUserByLogin(mysqli $conn, string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    if (function_exists('userNameEnsureSchema')) {
        userNameEnsureSchema($conn);
    }
    $hasEmail = clarenzonUsersHasEmailColumn($conn);
    foreach (clarenzonOfficialLoginAliases($login) as $alias) {
        $queries = [];
        if ($hasEmail) {
            $queries[] = 'SELECT id, username, password, role, status, full_name, first_name, last_name, email
                FROM users
                WHERE username = ? OR email = ? OR LOWER(username) = LOWER(?) OR LOWER(COALESCE(email,\'\')) = LOWER(?)
                LIMIT 1';
            $queries[] = 'SELECT id, username, password, role, status, full_name, first_name, last_name
                FROM users
                WHERE username = ? OR LOWER(username) = LOWER(?)
                LIMIT 1';
        }
        $queries[] = 'SELECT id, username, password, role, status, full_name, first_name, last_name
            FROM users
            WHERE username = ? OR LOWER(username) = LOWER(?)
            LIMIT 1';
        $queries[] = 'SELECT id, username, password, role, status, full_name
            FROM users
            WHERE username = ? OR LOWER(username) = LOWER(?)
            LIMIT 1';
        foreach ($queries as $sql) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $n = substr_count($sql, '?');
            if ($n === 4) {
                $stmt->bind_param('ssss', $alias, $alias, $alias, $alias);
            } else {
                $stmt->bind_param('ss', $alias, $alias);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }
    return null;
}

/**
 * @return array{ok:bool,error?:string,user?:array}
 */
function clarenzonAuthenticateLogin(?mysqli $conn, string $login, string $password): array
{
    $login = trim($login);
    $genericError = 'Invalid username or password.';
    if ($login === '') {
        return ['ok' => false, 'error' => 'Please enter your username.'];
    }
    if ($password === '') {
        return ['ok' => false, 'error' => 'Please enter your password.'];
    }
    if (function_exists('clarenzon_login_throttle_state')) {
        $throttled = clarenzon_login_throttle_state($login);
        if (!empty($throttled['blocked'])) {
            return ['ok' => false, 'error' => (string)($throttled['message'] ?? 'Too many sign-in attempts. Please wait and try again.')];
        }
    }
    if (!$conn || $conn->connect_error) {
        return ['ok' => false, 'error' => 'Unable to sign in right now. Please try again.'];
    }
    clarenzonEnsureOfficialUsernames($conn);
    $user = clarenzonLookupUserByLogin($conn, $login);
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    if (!$user) {
        password_verify($password, $dummyHash);
        if (function_exists('clarenzon_login_throttle_fail')) {
            clarenzon_login_throttle_fail($login);
        }
        return ['ok' => false, 'error' => $genericError];
    }
    $hash = (string)($user['password'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        if (function_exists('clarenzon_login_throttle_fail')) {
            clarenzon_login_throttle_fail($login);
        }
        return ['ok' => false, 'error' => $genericError];
    }
    $status = strtolower(trim((string)($user['status'] ?? '')));
    if ($status !== 'active') {
        return ['ok' => false, 'error' => 'Your account is currently inactive. Please contact the administrator.'];
    }
    if (function_exists('clarenzon_login_throttle_clear')) {
        clarenzon_login_throttle_clear($login);
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT) && !empty($user['id'])) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $rehash = $conn->prepare('UPDATE users SET password = ? WHERE id = ? LIMIT 1');
        if ($rehash) {
            $uid = (int)$user['id'];
            $rehash->bind_param('si', $newHash, $uid);
            $rehash->execute();
            $rehash->close();
        }
    }
    if (!function_exists('normalize_user_role')) {
        $wp = __DIR__ . DIRECTORY_SEPARATOR . 'warehouse_permissions.php';
        if (is_file($wp)) {
            require_once $wp;
        }
    }
    $roleCheck = function_exists('normalize_user_role')
        ? normalize_user_role((string)($user['role'] ?? ''))
        : strtolower(str_replace([' ', '-'], '_', (string)($user['role'] ?? '')));
    $allowedLoginRoles = ['admin', 'warehouse_head', 'purchaser', 'technician', 'inventory_manager', 'sales_marketing', 'management', 'cashier'];
    if (!in_array($roleCheck, $allowedLoginRoles, true)) {
        return ['ok' => false, 'error' => 'This account is not allowed to sign in.'];
    }
    return ['ok' => true, 'user' => $user];
}
