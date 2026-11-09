<?php
/**
 * Customer directory: reuse the customers table seeded for Create PR.
 * Purchase stats come from sales_orders / invoices — no duplicate customer ledgers.
 */

function clarenzonPhMobileError(string $phone): ?string
{
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }
    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        return 'Enter an 11-digit Philippine mobile number starting with 09, or leave the phone blank.';
    }
    return null;
}

function customersEnsureSchema(mysqli $conn): void
{
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(120) NULL,
        address TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_customers_name (name),
        INDEX idx_customers_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'phone' => "VARCHAR(50) NULL AFTER name",
        'email' => "VARCHAR(120) NULL AFTER phone",
        'address' => "TEXT NULL AFTER email",
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER address",
        'po_reference' => "VARCHAR(120) NULL AFTER email",
    ] as $col => $def) {
        $chk = @$conn->query("SHOW COLUMNS FROM customers LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE customers ADD COLUMN `$col` $def");
        }
    }
}

function customersHasTable(mysqli $conn, string $table): bool
{
    $t = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return (bool)($t && $t->num_rows);
}

function customersCode(int $id): string
{
    return 'CUS-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/** @return array<string, array{count:int, amount:float, last:string}> keyed by lowercase trimmed name */
function customersPurchaseMap(mysqli $conn): array
{
    $map = [];
    $add = static function (array &$map, string $name, int $count, float $amount, string $last): void {
        $key = strtolower(trim($name));
        if ($key === '' || $key === '0') {
            return;
        }
        if (!isset($map[$key])) {
            $map[$key] = ['count' => 0, 'amount' => 0.0, 'last' => ''];
        }
        $map[$key]['count'] += $count;
        $map[$key]['amount'] += $amount;
        if ($last !== '' && ($map[$key]['last'] === '' || strcmp($last, $map[$key]['last']) > 0)) {
            $map[$key]['last'] = $last;
        }
    };

    if (customersHasTable($conn, 'sales_orders')) {
        $sql = "SELECT TRIM(customer_name) AS name, COUNT(*) AS cnt,
                       COALESCE(SUM(grand_total), 0) AS amt,
                       MAX(created_at) AS last_at
                FROM sales_orders
                WHERE customer_name IS NOT NULL AND TRIM(customer_name) <> '' AND customer_name <> '0'
                  AND status = 'Approved'
                GROUP BY TRIM(customer_name)";
        $res = @$conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $add($map, (string)$row['name'], (int)$row['cnt'], (float)$row['amt'], (string)($row['last_at'] ?? ''));
            }
        }
    }

    if (customersHasTable($conn, 'invoices')) {
        $soJoin = customersHasTable($conn, 'sales_orders')
            ? "AND NOT EXISTS (
                 SELECT 1 FROM sales_orders so
                 WHERE so.id = invoices.sales_order_id AND so.status = 'Approved'
               )"
            : '';
        $sql = "SELECT TRIM(customer_name) AS name, COUNT(*) AS cnt,
                       COALESCE(SUM(grand_total), 0) AS amt,
                       MAX(created_at) AS last_at
                FROM invoices
                WHERE customer_name IS NOT NULL AND TRIM(customer_name) <> '' AND customer_name <> '0'
                $soJoin
                GROUP BY TRIM(customer_name)";
        $res = @$conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $add($map, (string)$row['name'], (int)$row['cnt'], (float)$row['amt'], (string)($row['last_at'] ?? ''));
            }
        }
    }

    return $map;
}

function customersAttachStats(array $row, array $purchaseMap): array
{
    $key = strtolower(trim((string)($row['name'] ?? '')));
    $stats = $purchaseMap[$key] ?? ['count' => 0, 'amount' => 0.0, 'last' => ''];
    $id = (int)($row['id'] ?? 0);
    $row['customer_code'] = customersCode($id);
    $row['total_purchases'] = (int)$stats['count'];
    $row['total_purchase_amount'] = round((float)$stats['amount'], 2);
    $row['last_purchase'] = $stats['last'] !== '' ? $stats['last'] : null;
    $row['phone'] = (string)($row['phone'] ?? '');
    $row['email'] = (string)($row['email'] ?? '');
    $row['address'] = (string)($row['address'] ?? '');
    $row['status'] = (string)($row['status'] ?? 'Active');
    return $row;
}

function customersList(mysqli $conn, array $filters = []): array
{
    customersEnsureSchema($conn);
    $search = trim((string)($filters['search'] ?? ''));
    $status = trim((string)($filters['status'] ?? 'all'));
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = (int)($filters['per_page'] ?? 15);
    if ($perPage < 5) {
        $perPage = 15;
    }
    if ($perPage > 100) {
        $perPage = 100;
    }

    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($status !== '' && strtolower($status) !== 'all') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $codeId = 0;
        if (preg_match('/^cus-?0*(\d+)$/i', $search, $m)) {
            $codeId = (int)$m[1];
        }
        if ($codeId > 0) {
            $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ? OR CAST(id AS CHAR) LIKE ? OR id = ?)';
            $types .= 'sssssi';
            array_push($params, $like, $like, $like, $like, $like, $codeId);
        } else {
            $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ? OR CAST(id AS CHAR) LIKE ?)';
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }
    }
    $wSQL = implode(' AND ', $where);

    $total = 0;
    $countSql = "SELECT COUNT(*) AS n FROM customers WHERE $wSQL";
    if ($types !== '') {
        $st = $conn->prepare($countSql);
        if ($st) {
            $st->bind_param($types, ...$params);
            $st->execute();
            $total = (int)($st->get_result()->fetch_assoc()['n'] ?? 0);
            $st->close();
        }
    } else {
        $res = $conn->query($countSql);
        $total = $res ? (int)($res->fetch_assoc()['n'] ?? 0) : 0;
    }

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $rows = [];
    $listSql = "SELECT id, name, phone, email, address, status, created_at, updated_at
                FROM customers WHERE $wSQL ORDER BY name ASC LIMIT ? OFFSET ?";
    $st = $conn->prepare($listSql);
    if ($st) {
        $listTypes = $types . 'ii';
        $listParams = $params;
        $listParams[] = $perPage;
        $listParams[] = $offset;
        $st->bind_param($listTypes, ...$listParams);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $st->close();
    }

    $purchaseMap = customersPurchaseMap($conn);
    foreach ($rows as &$row) {
        $row = customersAttachStats($row, $purchaseMap);
    }
    unset($row);

    return [
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}

function customersGet(mysqli $conn, int $id): ?array
{
    customersEnsureSchema($conn);
    if ($id <= 0) {
        return null;
    }
    $st = $conn->prepare("SELECT id, name, phone, email, address, status, created_at, updated_at FROM customers WHERE id=? LIMIT 1");
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }
    $row = customersAttachStats($row, customersPurchaseMap($conn));
    $row['orders'] = customersRecentOrders($conn, (string)$row['name']);
    return $row;
}

function customersRecentOrders(mysqli $conn, string $name): array
{
    $orders = [];
    $name = trim($name);
    if ($name === '') {
        return $orders;
    }
    if (customersHasTable($conn, 'sales_orders')) {
        $st = $conn->prepare("SELECT id, order_number, grand_total, status, created_at
                              FROM sales_orders
                              WHERE TRIM(customer_name) = ?
                              ORDER BY created_at DESC LIMIT 20");
        if ($st) {
            $st->bind_param('s', $name);
            $st->execute();
            $res = $st->get_result();
                            while ($r = $res->fetch_assoc()) {
                $orders[] = [
                    'id' => (int)$r['id'],
                    'source' => 'Sales Order',
                    'number' => (string)$r['order_number'],
                    'amount' => (float)$r['grand_total'],
                    'status' => (string)$r['status'],
                    'date' => (string)$r['created_at'],
                ];
            }
            $st->close();
        }
    }
    if (customersHasTable($conn, 'invoices')) {
        $st = $conn->prepare("SELECT invoice_number, grand_total, payment_status, created_at
                              FROM invoices
                              WHERE TRIM(customer_name) = ?
                              ORDER BY created_at DESC LIMIT 20");
        if ($st) {
            $st->bind_param('s', $name);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) {
                $orders[] = [
                    'source' => 'Invoice',
                    'number' => (string)$r['invoice_number'],
                    'amount' => (float)$r['grand_total'],
                    'status' => (string)$r['payment_status'],
                    'date' => (string)$r['created_at'],
                ];
            }
            $st->close();
        }
    }
    usort($orders, static function ($a, $b) {
        return strcmp((string)$b['date'], (string)$a['date']);
    });
    return array_slice($orders, 0, 20);
}

function customersSave(mysqli $conn, array $input): array
{
    customersEnsureSchema($conn);
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $status = trim((string)($input['status'] ?? 'Active'));
    if ($status !== 'Inactive') {
        $status = 'Active';
    }
    if ($name === '') {
        return ['ok' => false, 'message' => 'Customer name is required.'];
    }
    if (strlen($name) > 150) {
        return ['ok' => false, 'message' => 'Customer name must be 150 characters or fewer.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid email address.'];
    }
    $phoneError = clarenzonPhMobileError($phone);
    if ($phoneError !== null) {
        return ['ok' => false, 'message' => $phoneError];
    }

    $dup = $conn->prepare("SELECT id FROM customers WHERE name=? AND id<>? LIMIT 1");
    if ($dup) {
        $dup->bind_param('si', $name, $id);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            return ['ok' => false, 'message' => 'A customer with this name already exists.'];
        }
        $dup->close();
    }

    if ($id > 0) {
        $st = $conn->prepare("UPDATE customers SET name=?, phone=?, email=?, address=?, status=? WHERE id=?");
        if (!$st) {
            return ['ok' => false, 'message' => $conn->error ?: 'Unable to update customer.'];
        }
        $st->bind_param('sssssi', $name, $phone, $email, $address, $status, $id);
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();
            return ['ok' => false, 'message' => $err ?: 'Unable to update customer.'];
        }
        $st->close();
        $row = customersGet($conn, $id);
        return ['ok' => true, 'message' => 'Customer updated.', 'data' => $row];
    }

    $st = $conn->prepare("INSERT INTO customers (name, phone, email, address, status) VALUES (?, ?, ?, ?, ?)");
    if (!$st) {
        return ['ok' => false, 'message' => $conn->error ?: 'Unable to save customer.'];
    }
    $st->bind_param('sssss', $name, $phone, $email, $address, $status);
    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        return ['ok' => false, 'message' => $err ?: 'Unable to save customer.'];
    }
    $newId = (int)$conn->insert_id;
    $st->close();
    $row = customersGet($conn, $newId);
    return ['ok' => true, 'message' => 'Customer added.', 'data' => $row];
}

function customersDelete(mysqli $conn, int $id): array
{
    customersEnsureSchema($conn);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Invalid customer.'];
    }
    $existing = customersGet($conn, $id);
    if (!$existing) {
        return ['ok' => false, 'message' => 'Customer not found.'];
    }
    $st = $conn->prepare("DELETE FROM customers WHERE id=?");
    if (!$st) {
        return ['ok' => false, 'message' => $conn->error ?: 'Unable to delete customer.'];
    }
    $st->bind_param('i', $id);
    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        return ['ok' => false, 'message' => $err ?: 'Unable to delete customer.'];
    }
    $st->close();
    return ['ok' => true, 'message' => 'Customer deleted.'];
}
