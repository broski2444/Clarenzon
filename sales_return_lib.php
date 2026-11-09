<?php
/**
 * Customer returns (Return Policy) against completed sales.
 *
 * Flow: record return (Pending Review) → review → Completed (stock + refund/replacement applied) or Rejected.
 * Inventory changes only on approval. Every step is written to sales_return_events and audit_logs.
 * Reuses the WMS / stock-condition helpers and writes product_returns + stock_movements so the
 * existing inventory and return reports pick the return up.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'inventory_wms_lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'inventory_condition_lib.php';

const SR_STATUS_PENDING = 'Pending Review';
const SR_STATUS_COMPLETED = 'Completed';
const SR_STATUS_REJECTED = 'Rejected';

function srEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS sales_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_number VARCHAR(50) NOT NULL,
        client_token VARCHAR(64) NULL,
        sales_order_id INT NOT NULL,
        invoice_id INT NULL,
        order_number VARCHAR(50) NULL,
        invoice_number VARCHAR(50) NULL,
        receipt_reference VARCHAR(100) NULL,
        customer_name VARCHAR(150) NULL,
        sale_date DATETIME NULL,
        return_date DATE NOT NULL,
        outcome VARCHAR(30) NOT NULL DEFAULT 'Refund',
        status VARCHAR(30) NOT NULL DEFAULT 'Pending Review',
        total_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        balance_credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        cash_refund DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notes TEXT NULL,
        policy_snapshot TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        review_remarks TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sr_number (return_number),
        UNIQUE KEY uq_sr_token (client_token),
        INDEX idx_sr_so (sales_order_id),
        INDEX idx_sr_status (status),
        INDEX idx_sr_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_return_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        sales_order_item_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(200) NULL,
        batch_id INT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unit_value DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        line_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        stock_condition VARCHAR(20) NOT NULL DEFAULT 'Sellable',
        reason VARCHAR(100) NOT NULL,
        refundable TINYINT(1) NOT NULL DEFAULT 1,
        product_return_id INT NULL,
        INDEX idx_sri_return (return_id),
        INDEX idx_sri_soi (sales_order_item_id),
        INDEX idx_sri_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_return_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        action VARCHAR(40) NOT NULL,
        status VARCHAR(30) NULL,
        remarks TEXT NULL,
        details TEXT NULL,
        user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sre_return (return_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        setting_type VARCHAR(50) DEFAULT 'string',
        description TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ─── Policy ─────────────────────────────────────────────────────────────── */

function srPolicyDefaults(): array {
    return [
        'return_window_days' => 7,
        'return_refund_sellable' => 1,
        'return_refund_damaged' => 1,
        'return_replacement_allowed' => 1,
    ];
}

function srPolicyDescriptions(): array {
    return [
        'return_window_days' => 'Days after the sale during which a customer return is accepted',
        'return_refund_sellable' => 'Allow refunds for returned items in sellable condition',
        'return_refund_damaged' => 'Allow refunds for returned items that are damaged / defective',
        'return_replacement_allowed' => 'Allow replacement (exchange for the same product) as a return outcome',
    ];
}

function srLoadPolicy(mysqli $conn): array {
    $policy = srPolicyDefaults();
    $keys = "'" . implode("','", array_keys($policy)) . "'";
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($keys)");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $policy[(string)$row['setting_key']] = (int)$row['setting_value'];
        }
    }
    $policy['return_window_days'] = max(0, min(365, (int)$policy['return_window_days']));
    foreach (['return_refund_sellable', 'return_refund_damaged', 'return_replacement_allowed'] as $k) {
        $policy[$k] = !empty($policy[$k]) ? 1 : 0;
    }
    return $policy;
}

function srSavePolicy(mysqli $conn, array $input, int $uid): array {
    $current = srLoadPolicy($conn);
    $days = (int)($input['return_window_days'] ?? $current['return_window_days']);
    if ($days < 0 || $days > 365) {
        return ['ok' => false, 'message' => 'Return window must be between 0 and 365 days.'];
    }
    $next = [
        'return_window_days' => $days,
        'return_refund_sellable' => !empty($input['return_refund_sellable']) ? 1 : 0,
        'return_refund_damaged' => !empty($input['return_refund_damaged']) ? 1 : 0,
        'return_replacement_allowed' => !empty($input['return_replacement_allowed']) ? 1 : 0,
    ];
    $desc = srPolicyDescriptions();
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, 'integer', ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) return ['ok' => false, 'message' => 'Could not save the return policy.'];
    foreach ($next as $key => $value) {
        $val = (string)$value;
        $d = $desc[$key];
        $stmt->bind_param('sss', $key, $val, $d);
        $stmt->execute();
    }
    $stmt->close();
    $changes = [];
    foreach ($next as $key => $value) {
        if ((int)$current[$key] !== (int)$value) {
            $changes[] = $key . ': ' . $current[$key] . ' → ' . $value;
        }
    }
    if ($changes) {
        srAuditLog($conn, $uid, 'return_policy_updated', 'Module: Returns. Return policy updated: ' . implode('; ', $changes) . '.');
    }
    return ['ok' => true, 'policy' => $next, 'changes' => $changes];
}

function srReasons(): array {
    return ['Defective / Damaged', 'Wrong Item', 'Expired / Near Expiry', 'Customer Changed Mind', 'Excess Quantity', 'Other'];
}

function srConditions(): array {
    return ['Sellable', 'Damaged', 'Missing'];
}

function srOutcomes(): array {
    return ['Refund', 'Replacement'];
}

/** Whether a line in this condition may be refunded under the policy. Missing units are never refunded. */
function srLineRefundable(array $policy, string $condition): bool {
    if ($condition === 'Missing') return false;
    if ($condition === 'Damaged') return !empty($policy['return_refund_damaged']);
    return !empty($policy['return_refund_sellable']);
}

/* ─── Helpers ────────────────────────────────────────────────────────────── */

function srUserName(mysqli $conn, int $uid): string {
    if ($uid <= 0) return 'System';
    $q = $conn->query('SELECT COALESCE(NULLIF(full_name,\'\'), username) AS n FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
    $row = $q ? $q->fetch_assoc() : null;
    return $row && !empty($row['n']) ? (string)$row['n'] : ('User #' . $uid);
}

function srAuditLog(mysqli $conn, int $uid, string $actionKey, string $details): void {
    $username = 'System';
    $roleLabel = 'System';
    if ($uid > 0) {
        $uq = $conn->query('SELECT COALESCE(NULLIF(full_name,\'\'), username) AS n, role FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
        if ($uq && ($ur = $uq->fetch_assoc())) {
            $username = (string)$ur['n'];
            $roleLabel = (string)$ur['role'];
        }
    }
    $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(100) NOT NULL,
        user_role VARCHAR(100) NOT NULL,
        action_key VARCHAR(100) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, ?, ?)');
    if ($stmt) {
        $u = $uid > 0 ? $uid : null;
        $stmt->bind_param('issss', $u, $username, $roleLabel, $actionKey, $details);
        $stmt->execute();
        $stmt->close();
    }
}

function srLogEvent(mysqli $conn, int $returnId, string $action, string $status, string $remarks, array $details, int $uid): void {
    $stmt = $conn->prepare('INSERT INTO sales_return_events (return_id, action, status, remarks, details, user_id) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) return;
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
    $u = $uid > 0 ? $uid : null;
    $stmt->bind_param('issssi', $returnId, $action, $status, $remarks, $json, $u);
    $stmt->execute();
    $stmt->close();
}

function srNotify(mysqli $conn, string $title, string $message, string $priority, string $relatedId, ?string $role, ?int $userId): void {
    if (function_exists('createNotification')) {
        createNotification($conn, $title, $message, 'sales_return', $priority, $relatedId, 'sales-returns', $role, $userId);
    }
}

/** Locate a completed sale by sales order, invoice, or official receipt number. */
function srFindSalesOrderId(mysqli $conn, string $reference): int {
    $ref = trim($reference);
    if ($ref === '') return 0;
    $queries = [
        'SELECT id FROM sales_orders WHERE order_number = ? LIMIT 1',
        'SELECT sales_order_id AS id FROM invoices WHERE invoice_number = ? LIMIT 1',
        'SELECT i.sales_order_id AS id FROM invoice_payments ip INNER JOIN invoices i ON i.id = ip.invoice_id WHERE ip.payment_number = ? LIMIT 1',
    ];
    foreach ($queries as $sql) {
        $st = $conn->prepare($sql);
        if (!$st) continue;
        $st->bind_param('s', $ref);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row && (int)$row['id'] > 0) return (int)$row['id'];
    }
    return 0;
}

/**
 * Quantities already claimed on other returns for each sales order line.
 * Pending and Completed returns both count, so the same units cannot be returned twice.
 */
function srClaimedQtyByLine(mysqli $conn, int $soId, int $excludeReturnId = 0): array {
    $map = [];
    $sql = "SELECT sri.sales_order_item_id, sr.status, SUM(sri.quantity) AS q
            FROM sales_return_items sri
            INNER JOIN sales_returns sr ON sr.id = sri.return_id
            WHERE sr.sales_order_id = " . (int)$soId . "
              AND sr.status IN ('" . SR_STATUS_PENDING . "','" . SR_STATUS_COMPLETED . "')";
    if ($excludeReturnId > 0) {
        $sql .= ' AND sr.id <> ' . (int)$excludeReturnId;
    }
    $sql .= ' GROUP BY sri.sales_order_item_id, sr.status';
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $lid = (int)$r['sales_order_item_id'];
            if (!isset($map[$lid])) $map[$lid] = ['pending' => 0, 'completed' => 0];
            $key = $r['status'] === SR_STATUS_COMPLETED ? 'completed' : 'pending';
            $map[$lid][$key] += (int)$r['q'];
        }
    }
    return $map;
}

/**
 * Sale header, lines, and returnable quantities. Also reports why the sale is not eligible, if so.
 * @return array{ok:bool,message?:string,sale?:array,lines?:array,eligible?:bool,blocked_reason?:string,policy?:array}
 */
function srLoadSaleForReturn(mysqli $conn, int $soId, int $excludeReturnId = 0, bool $lock = false): array {
    if ($soId <= 0) return ['ok' => false, 'message' => 'Sale not found. Check the sales order, invoice, or receipt number.'];
    $st = $conn->prepare('SELECT * FROM sales_orders WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $st->bind_param('i', $soId);
    $st->execute();
    $so = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$so) return ['ok' => false, 'message' => 'Sale not found. Check the sales order, invoice, or receipt number.'];

    $inv = null;
    $iq = $conn->prepare('SELECT * FROM invoices WHERE sales_order_id = ? LIMIT 1');
    $iq->bind_param('i', $soId);
    $iq->execute();
    $inv = $iq->get_result()->fetch_assoc();
    $iq->close();

    $receipts = [];
    if ($inv) {
        $pr = $conn->query('SELECT payment_number FROM invoice_payments WHERE invoice_id = ' . (int)$inv['id'] . ' ORDER BY id ASC');
        while ($pr && ($p = $pr->fetch_assoc())) $receipts[] = (string)$p['payment_number'];
    }

    $policy = srLoadPolicy($conn);
    $saleDate = (string)($inv['created_at'] ?? $so['created_at'] ?? '');
    $ageDays = $saleDate !== '' ? (int)floor((strtotime(date('Y-m-d')) - strtotime(substr($saleDate, 0, 10))) / 86400) : 0;
    $windowDays = (int)$policy['return_window_days'];
    $daysLeft = $windowDays - $ageDays;

    $blocked = '';
    if ((string)$so['status'] === 'Cancelled') {
        $blocked = 'This sale was cancelled. Cancelled sales cannot be returned.';
    } elseif ((string)$so['status'] !== 'Approved' || strtolower((string)($so['stock_out_status'] ?? '')) !== 'completed') {
        $blocked = 'This sale has not been completed and released from stock yet, so there is nothing to return.';
    } elseif (!$inv) {
        $blocked = 'This sale has no customer invoice yet.';
    } elseif ($ageDays > $windowDays) {
        $blocked = 'This sale is outside the ' . $windowDays . '-day return window (sold ' . $ageDays . ' day(s) ago).';
    }

    $subtotal = (float)($so['subtotal'] ?? 0);
    $valueBase = (float)($so['grand_total'] ?? 0) - (float)($so['delivery_fee'] ?? 0);
    $ratio = $subtotal > 0 ? max(0.0, $valueBase / $subtotal) : 1.0;

    $claimed = srClaimedQtyByLine($conn, $soId, $excludeReturnId);
    $lines = [];
    $lq = $conn->prepare('SELECT soi.id, soi.product_id, soi.quantity, soi.unit_price, soi.batch_id, soi.batch_number,
                                 p.name AS product_name, p.sku, p.unit_of_measure
                          FROM sales_order_items soi
                          LEFT JOIN products p ON p.id = soi.product_id
                          WHERE soi.sales_order_id = ? ORDER BY soi.id ASC');
    $lq->bind_param('i', $soId);
    $lq->execute();
    $lr = $lq->get_result();
    while ($row = $lr->fetch_assoc()) {
        $lid = (int)$row['id'];
        $sold = (int)$row['quantity'];
        $done = (int)($claimed[$lid]['completed'] ?? 0);
        $pend = (int)($claimed[$lid]['pending'] ?? 0);
        $unitValue = round((float)$row['unit_price'] * $ratio, 4);
        $lines[] = [
            'sales_order_item_id' => $lid,
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)($row['product_name'] ?? ('Product #' . (int)$row['product_id'])),
            'sku' => (string)($row['sku'] ?? ''),
            'unit_of_measure' => (string)($row['unit_of_measure'] ?? ''),
            'batch_id' => (int)($row['batch_id'] ?? 0),
            'batch_number' => (string)($row['batch_number'] ?? ''),
            'sold_qty' => $sold,
            'returned_qty' => $done,
            'pending_qty' => $pend,
            'returnable_qty' => max(0, $sold - $done - $pend),
            'unit_price' => round((float)$row['unit_price'], 2),
            'unit_value' => $unitValue,
        ];
    }
    $lq->close();

    if ($blocked === '' && !array_filter($lines, fn($l) => $l['returnable_qty'] > 0)) {
        $blocked = 'Every item on this sale has already been returned or is awaiting review.';
    }

    return [
        'ok' => true,
        'sale' => [
            'sales_order_id' => $soId,
            'order_number' => (string)$so['order_number'],
            'invoice_id' => $inv ? (int)$inv['id'] : 0,
            'invoice_number' => $inv ? (string)$inv['invoice_number'] : '',
            'receipt_numbers' => $receipts,
            'customer_name' => (string)$so['customer_name'],
            'sale_type' => (string)($so['sale_type'] ?? ''),
            'sale_date' => $saleDate,
            'grand_total' => round((float)$so['grand_total'], 2),
            'payment_status' => $inv ? (string)$inv['payment_status'] : '',
            'remaining_balance' => $inv ? round((float)$inv['remaining_balance'], 2) : 0.0,
            'age_days' => $ageDays,
            'window_days' => $windowDays,
            'days_left' => $daysLeft,
            'status' => (string)$so['status'],
        ],
        'lines' => $lines,
        'eligible' => $blocked === '',
        'blocked_reason' => $blocked,
        'policy' => $policy,
    ];
}

/**
 * Validate requested return lines against the sale and the policy.
 * @return array{ok:bool,message?:string,lines?:array,total_value?:float,refund_amount?:float}
 */
function srValidateLines(array $sale, array $requested, string $outcome, array $policy): array {
    if (!in_array($outcome, srOutcomes(), true)) {
        return ['ok' => false, 'message' => 'Select a valid return outcome (Refund or Replacement).'];
    }
    if ($outcome === 'Replacement' && empty($policy['return_replacement_allowed'])) {
        return ['ok' => false, 'message' => 'Replacements are not allowed under the current return policy.'];
    }
    $byLine = [];
    foreach ($sale['lines'] as $line) {
        $byLine[(int)$line['sales_order_item_id']] = $line;
    }
    $out = [];
    $seen = [];
    $total = 0.0;
    $refund = 0.0;
    $physical = 0;
    foreach ($requested as $req) {
        if (!is_array($req)) continue;
        $lid = (int)($req['sales_order_item_id'] ?? 0);
        $qty = (int)($req['quantity'] ?? 0);
        if ($qty === 0) continue;
        if (!isset($byLine[$lid])) {
            return ['ok' => false, 'message' => 'A returned item does not belong to this sale.'];
        }
        if (isset($seen[$lid])) {
            return ['ok' => false, 'message' => 'Each sold item can appear only once in a return.'];
        }
        $seen[$lid] = true;
        $line = $byLine[$lid];
        $name = $line['product_name'];
        if ($qty < 0) {
            return ['ok' => false, 'message' => 'Return quantity for ' . $name . ' must be a positive number.'];
        }
        if ($qty > (int)$line['returnable_qty']) {
            $msg = 'Return quantity for ' . $name . ' (' . $qty . ') exceeds what can still be returned (' . (int)$line['returnable_qty'] . ' of ' . (int)$line['sold_qty'] . ' sold';
            $msg .= (int)$line['returned_qty'] > 0 ? ', ' . (int)$line['returned_qty'] . ' already returned' : '';
            $msg .= (int)$line['pending_qty'] > 0 ? ', ' . (int)$line['pending_qty'] . ' awaiting review' : '';
            return ['ok' => false, 'message' => $msg . ').'];
        }
        $condition = (string)($req['stock_condition'] ?? $req['condition'] ?? 'Sellable');
        if (!in_array($condition, srConditions(), true)) {
            return ['ok' => false, 'message' => 'Select a valid stock condition for ' . $name . '.'];
        }
        $reason = trim((string)($req['reason'] ?? ''));
        if (!in_array($reason, srReasons(), true)) {
            return ['ok' => false, 'message' => 'Select a reason for returning ' . $name . '.'];
        }
        $refundable = $outcome === 'Refund' && srLineRefundable($policy, $condition);
        if ($outcome === 'Refund' && $condition !== 'Missing' && !$refundable) {
            return ['ok' => false, 'message' => 'Refunds are not allowed for ' . strtolower($condition) . ' items under the current return policy. Choose Replacement for ' . $name . '.'];
        }
        if ($condition !== 'Missing') $physical++;
        $lineValue = round($qty * (float)$line['unit_value'], 2);
        $total += $lineValue;
        if ($refundable) $refund += $lineValue;
        $out[] = [
            'sales_order_item_id' => $lid,
            'product_id' => (int)$line['product_id'],
            'product_name' => $name,
            'batch_id' => (int)$line['batch_id'],
            'quantity' => $qty,
            'unit_price' => (float)$line['unit_price'],
            'unit_value' => (float)$line['unit_value'],
            'line_value' => $lineValue,
            'stock_condition' => $condition,
            'reason' => $reason,
            'refundable' => $refundable ? 1 : 0,
        ];
    }
    if (!$out) {
        return ['ok' => false, 'message' => 'Enter a return quantity for at least one item.'];
    }
    if ($physical === 0) {
        return ['ok' => false, 'message' => 'All returned items are marked Missing, so there is nothing to ' . strtolower($outcome) . '. Record at least one item that was physically returned.'];
    }
    return ['ok' => true, 'lines' => $out, 'total_value' => round($total, 2), 'refund_amount' => round($refund, 2)];
}

function srNextReturnNumber(mysqli $conn): string {
    $num = invWmsNextDocNumber($conn, 'sales_returns', 'return_number', 'SRN');
    $chk = $conn->prepare('SELECT id FROM sales_returns WHERE return_number = ? LIMIT 1');
    if ($chk) {
        $chk->bind_param('s', $num);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if ($exists) $num .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }
    return $num;
}

/* ─── Create ─────────────────────────────────────────────────────────────── */

/** Record a return request. Nothing in inventory or billing changes until it is approved. */
function srCreateReturn(mysqli $conn, array $input, int $uid): array {
    srEnsureSchema($conn);
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($input['client_token'] ?? ''));
    $token = $token !== '' ? substr($token, 0, 64) : null;
    if ($token !== null) {
        $dup = $conn->prepare('SELECT id, return_number FROM sales_returns WHERE client_token = ? LIMIT 1');
        $dup->bind_param('s', $token);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($existing) {
            return ['ok' => false, 'duplicate' => true, 'message' => 'This return was already submitted as ' . $existing['return_number'] . '.', 'return_id' => (int)$existing['id'], 'return_number' => (string)$existing['return_number']];
        }
    }

    $soId = (int)($input['sales_order_id'] ?? 0);
    $outcome = (string)($input['outcome'] ?? 'Refund');
    $notes = trim((string)($input['notes'] ?? ''));
    $receiptRef = trim((string)($input['receipt_reference'] ?? ''));
    $returnDate = trim((string)($input['return_date'] ?? ''));
    if ($returnDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        $returnDate = date('Y-m-d');
    }
    if ($returnDate > date('Y-m-d')) {
        return ['ok' => false, 'message' => 'Return date cannot be in the future.'];
    }
    $requested = $input['items'] ?? [];
    if (is_string($requested)) {
        $requested = json_decode($requested, true) ?: [];
    }

    $conn->begin_transaction();
    try {
        $pack = srLoadSaleForReturn($conn, $soId, 0, true);
        if (empty($pack['ok'])) throw new RuntimeException($pack['message'] ?? 'Sale not found.');
        if (empty($pack['eligible'])) throw new RuntimeException($pack['blocked_reason']);
        $sale = $pack['sale'];
        if ($returnDate < substr((string)$sale['sale_date'], 0, 10)) {
            throw new RuntimeException('Return date cannot be earlier than the sale date.');
        }
        $policy = $pack['policy'];
        $valid = srValidateLines($pack, $requested, $outcome, $policy);
        if (empty($valid['ok'])) throw new RuntimeException($valid['message']);

        if ($receiptRef === '') {
            $receiptRef = $sale['receipt_numbers'][0] ?? $sale['invoice_number'];
        }
        $number = srNextReturnNumber($conn);
        $status = SR_STATUS_PENDING;
        $snapshot = json_encode($policy);
        $saleDate = $sale['sale_date'] !== '' ? $sale['sale_date'] : null;
        $invId = (int)$sale['invoice_id'] ?: null;
        $total = (float)$valid['total_value'];
        $refund = (float)$valid['refund_amount'];
        $ins = $conn->prepare('INSERT INTO sales_returns (return_number, client_token, sales_order_id, invoice_id, order_number, invoice_number, receipt_reference,
                                   customer_name, sale_date, return_date, outcome, status, total_value, refund_amount, notes, policy_snapshot, created_by)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$ins) throw new RuntimeException('Could not record the return.');
        $ins->bind_param('ssiissssssssddssi', $number, $token, $soId, $invId, $sale['order_number'], $sale['invoice_number'], $receiptRef,
            $sale['customer_name'], $saleDate, $returnDate, $outcome, $status, $total, $refund, $notes, $snapshot, $uid);
        if (!$ins->execute()) {
            $err = $ins->errno;
            $ins->close();
            if ($err === 1062) throw new RuntimeException('This return was already submitted.');
            throw new RuntimeException('Could not record the return.');
        }
        $returnId = (int)$conn->insert_id;
        $ins->close();

        $li = $conn->prepare('INSERT INTO sales_return_items (return_id, sales_order_item_id, product_id, product_name, batch_id, quantity, unit_price, unit_value, line_value, stock_condition, reason, refundable)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($valid['lines'] as $l) {
            $bid = $l['batch_id'] > 0 ? $l['batch_id'] : null;
            $li->bind_param('iiisiidddssi', $returnId, $l['sales_order_item_id'], $l['product_id'], $l['product_name'], $bid, $l['quantity'],
                $l['unit_price'], $l['unit_value'], $l['line_value'], $l['stock_condition'], $l['reason'], $l['refundable']);
            if (!$li->execute()) throw new RuntimeException('Could not record the returned items.');
        }
        $li->close();

        $summary = array_map(fn($l) => $l['product_name'] . ' × ' . $l['quantity'] . ' (' . $l['stock_condition'] . ', ' . $l['reason'] . ')', $valid['lines']);
        srLogEvent($conn, $returnId, 'Recorded', $status, $notes, ['items' => $summary, 'outcome' => $outcome, 'refund_amount' => $refund], $uid);
        srAuditLog($conn, $uid, 'sales_return_recorded', 'Module: Returns. Recorded return ' . $number . ' for ' . $sale['order_number']
            . ' (' . $sale['invoice_number'] . '): ' . implode('; ', $summary) . '. Outcome: ' . $outcome . ($outcome === 'Refund' ? ' ₱' . number_format($refund, 2) : '') . '.');

        $msg = 'Return ' . $number . ' for ' . $sale['customer_name'] . ' (' . $sale['invoice_number'] . ') was recorded by ' . srUserName($conn, $uid)
            . ' and is awaiting review. Outcome: ' . $outcome . ($outcome === 'Refund' ? ' of ₱' . number_format($refund, 2) : '') . '.';
        srNotify($conn, 'Return Pending Review: ' . $number, $msg, 'medium', $number, 'admin', null);
        srNotify($conn, 'Return Pending Review: ' . $number, $msg, 'medium', $number, 'warehouse_head', null);

        $conn->commit();
        return ['ok' => true, 'return_id' => $returnId, 'return_number' => $number, 'status' => $status, 'refund_amount' => $refund, 'total_value' => $total];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/* ─── Review (approve / reject) ──────────────────────────────────────────── */

function srCanReview(string $roleKey): bool {
    return in_array($roleKey, ['admin', 'warehouse_head'], true);
}

/**
 * Approve (process) or reject a pending return.
 * Approval re-validates quantities, then applies stock, refund / replacement, and history in one transaction.
 */
function srReviewReturn(mysqli $conn, int $returnId, string $decision, string $remarks, array $conditionOverrides, int $uid, string $roleKey): array {
    srEnsureSchema($conn);
    if (!srCanReview($roleKey)) {
        return ['ok' => false, 'message' => 'Only an Administrator or Warehouse Head can review returns.'];
    }
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approve', 'reject'], true)) {
        return ['ok' => false, 'message' => 'Choose to approve or reject the return.'];
    }
    $remarks = trim($remarks);
    if ($decision === 'reject' && $remarks === '') {
        return ['ok' => false, 'message' => 'A reason is required to reject a return.'];
    }

    $conn->begin_transaction();
    try {
        $hq = $conn->prepare('SELECT * FROM sales_returns WHERE id = ? FOR UPDATE');
        $hq->bind_param('i', $returnId);
        $hq->execute();
        $ret = $hq->get_result()->fetch_assoc();
        $hq->close();
        if (!$ret) throw new RuntimeException('Return not found.');
        if ($ret['status'] !== SR_STATUS_PENDING) {
            throw new RuntimeException('Return ' . $ret['return_number'] . ' was already ' . strtolower((string)$ret['status']) . '.');
        }
        if ($roleKey !== 'admin' && (int)$ret['created_by'] === $uid) {
            throw new RuntimeException('A return must be reviewed by someone other than the person who recorded it.');
        }
        $number = (string)$ret['return_number'];
        $creator = (int)($ret['created_by'] ?? 0);

        if ($decision === 'reject') {
            $st = SR_STATUS_REJECTED;
            $up = $conn->prepare('UPDATE sales_returns SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?');
            $up->bind_param('sisi', $st, $uid, $remarks, $returnId);
            $up->execute();
            $up->close();
            srLogEvent($conn, $returnId, 'Rejected', $st, $remarks, [], $uid);
            srAuditLog($conn, $uid, 'sales_return_rejected', 'Module: Returns. Rejected return ' . $number . '. Reason: ' . $remarks . '. No stock or billing changes were made.');
            if ($creator > 0 && $creator !== $uid) {
                srNotify($conn, 'Return Rejected: ' . $number, 'Return ' . $number . ' was rejected by ' . srUserName($conn, $uid) . '. Reason: ' . $remarks, 'high', $number, null, $creator);
            }
            $conn->commit();
            return ['ok' => true, 'status' => $st, 'return_number' => $number, 'message' => 'Return ' . $number . ' rejected.'];
        }

        // Approve: lock the sale and re-check quantities against everything else already claimed.
        $pack = srLoadSaleForReturn($conn, (int)$ret['sales_order_id'], $returnId, true);
        if (empty($pack['ok'])) throw new RuntimeException($pack['message'] ?? 'Sale not found.');
        if ((string)$pack['sale']['status'] !== 'Approved') {
            throw new RuntimeException('The original sale is no longer completed; this return cannot be processed.');
        }
        $policy = srLoadPolicy($conn);
        $outcome = (string)$ret['outcome'];

        $iq = $conn->query('SELECT * FROM sales_return_items WHERE return_id = ' . (int)$returnId . ' ORDER BY id ASC');
        $items = [];
        $requested = [];
        $conditionChanges = [];
        while ($iq && ($it = $iq->fetch_assoc())) {
            $cond = (string)$it['stock_condition'];
            $override = (string)($conditionOverrides[(string)$it['id']] ?? '');
            if ($override !== '' && $override !== $cond) {
                if (!in_array($override, srConditions(), true)) throw new RuntimeException('Invalid stock condition for ' . $it['product_name'] . '.');
                $conditionChanges[] = $it['product_name'] . ': ' . $cond . ' → ' . $override;
                $cond = $override;
            }
            $it['stock_condition'] = $cond;
            $items[] = $it;
            $requested[] = [
                'sales_order_item_id' => (int)$it['sales_order_item_id'],
                'quantity' => (int)$it['quantity'],
                'stock_condition' => $cond,
                'reason' => (string)$it['reason'],
            ];
        }
        $valid = srValidateLines($pack, $requested, $outcome, $policy);
        if (empty($valid['ok'])) throw new RuntimeException($valid['message']);
        $validByLine = [];
        foreach ($valid['lines'] as $vl) $validByLine[(int)$vl['sales_order_item_id']] = $vl;

        $soId = (int)$ret['sales_order_id'];
        $ref = $number;
        $productIds = [];
        foreach ($items as $it) {
            $v = $validByLine[(int)$it['sales_order_item_id']];
            $pid = (int)$it['product_id'];
            $qty = (int)$it['quantity'];
            $cond = $v['stock_condition'];
            $bid = (int)($it['batch_id'] ?? 0);
            $unit = (float)$it['unit_price'];
            $note = '[Customer return ' . $number . ' · ' . $cond . ' · ' . $it['reason'] . '] Sale ' . $ret['order_number'] . ' / ' . $ret['invoice_number'];
            $productIds[$pid] = true;
            $prodReturnId = null;

            if ($cond === 'Sellable') {
                if (!invWmsApplyOnHandDelta($conn, $pid, $qty, null)) {
                    throw new RuntimeException('Could not add ' . $it['product_name'] . ' back to inventory.');
                }
                if ($bid > 0) {
                    $conn->query("UPDATE product_batches SET quantity_remaining = quantity_remaining + $qty WHERE id = $bid AND product_id = $pid");
                }
            } elseif ($cond === 'Damaged') {
                $dmg = invStockApplyDamagedReturn($conn, $pid, $qty, $bid ?: null, $uid, 'Customer return - ' . $it['reason'], $note);
                if (empty($dmg['ok'])) throw new RuntimeException($dmg['message'] ?? 'Could not record the damaged return.');
                $bid = (int)($dmg['batch_id'] ?? $bid);
            }

            if ($cond !== 'Missing') {
                $total = round($qty * $unit, 2);
                $bidBind = $bid > 0 ? $bid : null;
                $mv = $conn->prepare("INSERT INTO stock_movements (product_id, batch_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes, source)
                                      VALUES (?, ?, 'Return', ?, ?, ?, ?, ?, ?, 'sales_return')");
                $mv->bind_param('iiiddsis', $pid, $bidBind, $qty, $unit, $total, $ref, $uid, $note);
                if (!$mv->execute()) throw new RuntimeException('Could not record the stock movement.');
                $mv->close();

                $retType = $cond === 'Sellable' ? 'Customer Return (Restockable)' : 'Customer Return (Damaged)';
                $condLabel = $cond === 'Sellable' ? 'Restockable' : 'Damaged - quarantine';
                $party = 'Customer';
                $replBind = 0;
                $bidPr = $bid > 0 ? $bid : 0;
                $invNo = (string)$ret['invoice_number'];
                $reason = (string)$it['reason'];
                $prs = $conn->prepare('INSERT INTO product_returns (return_number, party, return_type, condition_status, product_id, replacement_product_id,
                                          quantity, batch_id, sales_order_id, reference_number, reason, notes, created_by)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                if ($prs) {
                    $prs->bind_param('ssssiiiiisssi', $number, $party, $retType, $condLabel, $pid, $replBind, $qty, $bidPr, $soId, $invNo, $reason, $note, $uid);
                    $prs->execute();
                    $prodReturnId = (int)$conn->insert_id ?: null;
                    $prs->close();
                }
            }

            if ($outcome === 'Replacement' && $cond !== 'Missing') {
                $issue = invWmsIssueSellableQty($conn, $pid, $qty, null, null);
                if (empty($issue['ok'])) {
                    throw new RuntimeException('Cannot issue a replacement for ' . $it['product_name'] . ': ' . ($issue['message'] ?? 'insufficient stock') . ' Approve as a refund instead or restock first.');
                }
                $replRef = $number . '-RPL';
                $replNote = '[Replacement issued for customer return ' . $number . '] ' . $it['product_name'] . ' × ' . $qty;
                $mvr = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes, source)
                                       VALUES (?, 'Stock Out (Issue)', ?, ?, ?, ?, ?, ?, 'replacement_issue')");
                $tv = round($qty * $unit, 2);
                $mvr->bind_param('iiddsis', $pid, $qty, $unit, $tv, $replRef, $uid, $replNote);
                $mvr->execute();
                $mvr->close();
                $replReason = $cond === 'Damaged' ? 'Damaged' : 'Returned';
                $link = invWmsRecordReplacement($conn, $pid, $pid, $qty, $replReason, 'sales_return', $uid, $prodReturnId, $number, $soId, $replNote);
                if (empty($link['ok'])) throw new RuntimeException($link['message'] ?? 'Could not record the replacement.');
            }

            $upItem = $conn->prepare('UPDATE sales_return_items SET stock_condition = ?, refundable = ?, line_value = ?, batch_id = ?, product_return_id = ? WHERE id = ?');
            $bidItem = $bid > 0 ? $bid : null;
            $itemId = (int)$it['id'];
            $upItem->bind_param('sidiii', $cond, $v['refundable'], $v['line_value'], $bidItem, $prodReturnId, $itemId);
            $upItem->execute();
            $upItem->close();
        }

        if ($productIds) {
            $ids = implode(',', array_map('intval', array_keys($productIds)));
            $conn->query("UPDATE products SET status = CASE WHEN quantity_in_stock = 0 THEN 'Out of Stock' WHEN quantity_in_stock <= reorder_level THEN 'Low Stock' ELSE 'Active' END
                          WHERE id IN ($ids) AND status NOT IN ('Discontinued','Inactive')");
        }

        // Refund: first settle any unpaid balance on the invoice, the rest is paid back to the customer.
        $refund = $outcome === 'Refund' ? (float)$valid['refund_amount'] : 0.0;
        $credit = 0.0;
        $cash = 0.0;
        if ($refund > 0 && (int)$ret['invoice_id'] > 0) {
            $invQ = $conn->query('SELECT * FROM invoices WHERE id = ' . (int)$ret['invoice_id'] . ' FOR UPDATE');
            $inv = $invQ ? $invQ->fetch_assoc() : null;
            if ($inv) {
                $remaining = round((float)$inv['remaining_balance'], 2);
                $credit = round(min($refund, max(0.0, $remaining)), 2);
                $cash = round($refund - $credit, 2);
                if ($credit > 0) {
                    $newBal = round($remaining - $credit, 2);
                    $paidQ = $conn->query('SELECT COALESCE(SUM(amount_paid),0) AS s FROM invoice_payments WHERE invoice_id = ' . (int)$inv['id']);
                    $paidSum = $paidQ ? (float)$paidQ->fetch_assoc()['s'] : 0.0;
                    $payStatus = $newBal <= 0 ? 'Paid' : ($paidSum > 0 ? 'Partially Paid' : 'Unpaid');
                    $newBal = max(0.0, $newBal);
                    $ui = $conn->prepare('UPDATE invoices SET remaining_balance = ?, payment_status = ? WHERE id = ?');
                    $invId = (int)$inv['id'];
                    $ui->bind_param('dsi', $newBal, $payStatus, $invId);
                    $ui->execute();
                    $ui->close();
                    if (function_exists('salesOrderSyncCreditStatus')) {
                        salesOrderSyncCreditStatus($conn, $soId, $newBal);
                    }
                }
            } else {
                $cash = $refund;
            }
        } else {
            $cash = $refund;
        }

        $st = SR_STATUS_COMPLETED;
        $totalValue = (float)$valid['total_value'];
        $upr = $conn->prepare('UPDATE sales_returns SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?, total_value = ?, refund_amount = ?, balance_credit = ?, cash_refund = ? WHERE id = ?');
        $upr->bind_param('sisddddi', $st, $uid, $remarks, $totalValue, $refund, $credit, $cash, $returnId);
        $upr->execute();
        $upr->close();

        $summary = [];
        foreach ($valid['lines'] as $vl) {
            $effect = $vl['stock_condition'] === 'Sellable' ? 'restocked' : ($vl['stock_condition'] === 'Damaged' ? 'quarantined as damaged' : 'not received');
            $summary[] = $vl['product_name'] . ' × ' . $vl['quantity'] . ' ' . $effect;
        }
        $outcomeText = $outcome === 'Refund'
            ? ('Refund ₱' . number_format($refund, 2) . ($credit > 0 ? ' (₱' . number_format($credit, 2) . ' credited to the invoice balance, ₱' . number_format($cash, 2) . ' paid back)' : ''))
            : 'Replacement issued for returned items';
        srLogEvent($conn, $returnId, 'Approved', $st, $remarks, [
            'items' => $summary,
            'condition_changes' => $conditionChanges,
            'outcome' => $outcomeText,
        ], $uid);
        srAuditLog($conn, $uid, 'sales_return_approved', 'Module: Returns. Approved return ' . $number . ' for ' . $ret['order_number'] . ': '
            . implode('; ', $summary) . '. ' . $outcomeText . '.' . ($conditionChanges ? ' Condition updated on review: ' . implode('; ', $conditionChanges) . '.' : '')
            . ($remarks !== '' ? ' Remarks: ' . $remarks : ''));
        if ($creator > 0 && $creator !== $uid) {
            srNotify($conn, 'Return Approved: ' . $number, 'Return ' . $number . ' was approved by ' . srUserName($conn, $uid) . '. ' . $outcomeText . '.', 'high', $number, null, $creator);
        }

        $conn->commit();
        return [
            'ok' => true,
            'status' => $st,
            'return_number' => $number,
            'refund_amount' => $refund,
            'balance_credit' => $credit,
            'cash_refund' => $cash,
            'message' => 'Return ' . $number . ' approved. ' . $outcomeText . '.',
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/* ─── Read ───────────────────────────────────────────────────────────────── */

function srListReturns(mysqli $conn, array $filters): array {
    srEnsureSchema($conn);
    $where = [];
    $types = '';
    $params = [];
    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '' && $status !== 'all') {
        $where[] = 'sr.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(sr.return_number LIKE ? OR sr.order_number LIKE ? OR sr.invoice_number LIKE ? OR sr.receipt_reference LIKE ? OR sr.customer_name LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql = "SELECT sr.id, sr.return_number, sr.order_number, sr.invoice_number, sr.receipt_reference, sr.customer_name, sr.sale_date, sr.return_date,
                   sr.outcome, sr.status, sr.total_value, sr.refund_amount, sr.balance_credit, sr.cash_refund, sr.created_at, sr.reviewed_at,
                   sr.created_by,
                   COALESCE(NULLIF(cu.full_name,''), cu.username) AS created_by_name,
                   COALESCE(NULLIF(ru.full_name,''), ru.username) AS reviewed_by_name,
                   (SELECT COALESCE(SUM(quantity),0) FROM sales_return_items WHERE return_id = sr.id) AS total_qty,
                   (SELECT COUNT(*) FROM sales_return_items WHERE return_id = sr.id) AS line_count
            FROM sales_returns sr
            LEFT JOIN users cu ON cu.id = sr.created_by
            LEFT JOIN users ru ON ru.id = sr.reviewed_by"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY sr.created_at DESC, sr.id DESC LIMIT 200';
    $rows = [];
    $st = $conn->prepare($sql);
    if (!$st) return $rows;
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    $st->close();
    return $rows;
}

function srGetReturn(mysqli $conn, int $returnId): ?array {
    srEnsureSchema($conn);
    $st = $conn->prepare("SELECT sr.*, COALESCE(NULLIF(cu.full_name,''), cu.username) AS created_by_name, cu.role AS created_by_role,
                                 COALESCE(NULLIF(ru.full_name,''), ru.username) AS reviewed_by_name, ru.role AS reviewed_by_role
                          FROM sales_returns sr
                          LEFT JOIN users cu ON cu.id = sr.created_by
                          LEFT JOIN users ru ON ru.id = sr.reviewed_by
                          WHERE sr.id = ? LIMIT 1");
    $st->bind_param('i', $returnId);
    $st->execute();
    $ret = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$ret) return null;
    unset($ret['client_token']);

    $items = [];
    $iq = $conn->query('SELECT sri.*, p.sku, soi.quantity AS sold_qty FROM sales_return_items sri
                        LEFT JOIN products p ON p.id = sri.product_id
                        LEFT JOIN sales_order_items soi ON soi.id = sri.sales_order_item_id
                        WHERE sri.return_id = ' . (int)$returnId . ' ORDER BY sri.id ASC');
    while ($iq && ($r = $iq->fetch_assoc())) $items[] = $r;
    $ret['items'] = $items;

    $events = [];
    $eq = $conn->query("SELECT e.*, COALESCE(NULLIF(u.full_name,''), u.username, 'System') AS user_name, u.role AS user_role
                        FROM sales_return_events e LEFT JOIN users u ON u.id = e.user_id
                        WHERE e.return_id = " . (int)$returnId . ' ORDER BY e.created_at ASC, e.id ASC');
    while ($eq && ($r = $eq->fetch_assoc())) {
        $d = json_decode((string)($r['details'] ?? ''), true);
        $r['details'] = is_array($d) ? $d : [];
        $events[] = $r;
    }
    $ret['events'] = $events;
    return $ret;
}

/** Completed refunds in a date range, used by the Sales Report. */
function srRefundSummary(mysqli $conn, string $from = '', string $to = ''): array {
    $out = ['count' => 0, 'refunds' => 0.0, 'returned_value' => 0.0, 'units' => 0];
    $t = $conn->query("SHOW TABLES LIKE 'sales_returns'");
    if (!($t && $t->num_rows > 0)) return $out;
    $where = "sr.status = '" . SR_STATUS_COMPLETED . "'";
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $where .= " AND DATE(sr.reviewed_at) >= '" . $from . "'";
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $where .= " AND DATE(sr.reviewed_at) <= '" . $to . "'";
    $q = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(sr.refund_amount),0) AS r, COALESCE(SUM(sr.total_value),0) AS v,
                              COALESCE(SUM((SELECT SUM(quantity) FROM sales_return_items WHERE return_id = sr.id AND stock_condition <> 'Missing')),0) AS u
                       FROM sales_returns sr WHERE $where");
    if ($q && ($r = $q->fetch_assoc())) {
        $out = ['count' => (int)$r['c'], 'refunds' => round((float)$r['r'], 2), 'returned_value' => round((float)$r['v'], 2), 'units' => (int)$r['u']];
    }
    return $out;
}
