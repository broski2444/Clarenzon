<?php
/**
 * Credit / Accounts Receivable due-date monitoring.
 * Invoice payment_status (Unpaid / Partially Paid / Paid) is left unchanged.
 * Collection status on the credit agreement is Pending, Overdue, or Paid.
 */

function creditMonitorEnsureSchema(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    foreach ([
        'invoice_id' => 'INT NULL',
        'invoice_number' => 'VARCHAR(50) NULL',
        'credit_date' => 'DATE NULL',
        'collection_status' => "VARCHAR(20) NOT NULL DEFAULT 'Pending'",
        'paid_at' => 'DATETIME NULL',
    ] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM sales_credit_agreements LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE sales_credit_agreements ADD COLUMN `$col` $def");
        }
    }
    $conn->query("CREATE TABLE IF NOT EXISTS credit_collection_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agreement_id INT NOT NULL,
        invoice_id INT NULL,
        event_type VARCHAR(40) NOT NULL,
        event_key VARCHAR(40) NULL,
        title VARCHAR(255) NOT NULL,
        details TEXT NULL,
        amount DECIMAL(12,2) NULL,
        actor_user_id INT NULL,
        actor_name VARCHAR(150) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ccl_agreement (agreement_id),
        INDEX idx_ccl_key (agreement_id, event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function creditMonitorCanRecordFollowUp(): bool {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    $role = str_replace([' ', '-'], '_', $role);
    return in_array($role, [
        'administrator', 'admin', 'warehouse_head', 'warehousehead',
        'accounting', 'accountant',
        'sales_representative', 'sales', 'sales_rep', 'sales_marketing',
    ], true);
}

function creditMonitorDate(string $raw): string {
    $raw = substr(trim($raw), 0, 10);
    if ($raw === '' || $raw === '0000-00-00') {
        return '';
    }
    $ts = strtotime($raw . ' 00:00:00');
    return $ts ? date('Y-m-d', $ts) : '';
}

function creditMonitorDaysUntil(string $due): ?int {
    $due = creditMonitorDate($due);
    if ($due === '') {
        return null;
    }
    $dueTs = strtotime($due . ' 00:00:00');
    $todayTs = strtotime(date('Y-m-d') . ' 00:00:00');
    if (!$dueTs || !$todayTs) {
        return null;
    }
    return (int)round(($dueTs - $todayTs) / 86400);
}

function creditDateIsPast(string $due): bool {
    $days = creditMonitorDaysUntil($due);
    return $days !== null && $days < 0;
}

function creditDaysOverdue(string $due): int {
    $days = creditMonitorDaysUntil($due);
    if ($days === null || $days >= 0) {
        return 0;
    }
    return abs($days);
}

function creditMonitorEventExists(mysqli $conn, int $agreementId, string $eventKey): bool {
    if ($agreementId <= 0 || $eventKey === '') {
        return false;
    }
    $st = $conn->prepare('SELECT id FROM credit_collection_log WHERE agreement_id = ? AND event_key = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('is', $agreementId, $eventKey);
    $st->execute();
    $exists = $st->get_result()->num_rows > 0;
    $st->close();
    return $exists;
}

function creditMonitorLog(mysqli $conn, int $agreementId, ?int $invoiceId, string $eventType, ?string $eventKey, string $title, string $details, ?float $amount, ?int $actorId, string $actorName): void {
    if ($agreementId <= 0) {
        return;
    }
    if ($eventKey !== null && $eventKey !== '' && creditMonitorEventExists($conn, $agreementId, $eventKey)) {
        return;
    }
    $key = ($eventKey !== null && $eventKey !== '') ? $eventKey : null;
    $st = $conn->prepare('INSERT INTO credit_collection_log (agreement_id, invoice_id, event_type, event_key, title, details, amount, actor_user_id, actor_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$st) {
        return;
    }
    $inv = $invoiceId !== null && $invoiceId > 0 ? $invoiceId : null;
    $amt = $amount;
    $uid = $actorId !== null && $actorId > 0 ? $actorId : null;
    $st->bind_param('iissssdis', $agreementId, $inv, $eventType, $key, $title, $details, $amt, $uid, $actorName);
    $st->execute();
    $st->close();
}

function creditMonitorAudit(mysqli $conn, string $action, string $details, ?int $userId = null, string $username = 'system', string $role = 'system'): void {
    $chk = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if (!$chk || $chk->num_rows === 0) {
        return;
    }
    $uid = $userId !== null ? $userId : 0;
    $st = $conn->prepare('INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, ?, ?)');
    if (!$st) {
        return;
    }
    $st->bind_param('issss', $uid, $username, $role, $action, $details);
    $st->execute();
    $st->close();
}

function creditMonitorNotifyStaff(mysqli $conn, string $title, string $message, string $priority, string $invoiceNumber): void {
    if (!function_exists('createNotification')) {
        return;
    }
    foreach (['admin', 'warehouse_head', 'accounting', 'sales_representative', 'sales'] as $role) {
        createNotification($conn, $title, $message, 'credit_due', $priority, $invoiceNumber, 'unpaid-invoices', $role);
    }
}

function creditMonitorBind(mysqli $conn, int $salesOrderId, int $invoiceId, string $invoiceNumber, string $customerName, float $creditAmount, string $dueDate, string $creditDate): void {
    if ($salesOrderId <= 0) {
        return;
    }
    creditMonitorEnsureSchema($conn);
    $due = creditMonitorDate($dueDate);
    $creditDay = creditMonitorDate($creditDate);
    if ($creditDay === '') {
        $creditDay = date('Y-m-d');
    }
    $st = $conn->prepare('SELECT id, collection_status FROM sales_credit_agreements WHERE sales_order_id = ? LIMIT 1');
    if (!$st) {
        return;
    }
    $st->bind_param('i', $salesOrderId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return;
    }
    $agreementId = (int)$row['id'];
    $balance = 0.0;
    if ($invoiceId > 0) {
        $bq = $conn->prepare('SELECT remaining_balance FROM invoices WHERE id = ? LIMIT 1');
        if ($bq) {
            $bq->bind_param('i', $invoiceId);
            $bq->execute();
            $brow = $bq->get_result()->fetch_assoc();
            $bq->close();
            $balance = (float)($brow['remaining_balance'] ?? $creditAmount);
        }
    }
    $status = 'Pending';
    $paidAt = null;
    if ($balance <= 0.009) {
        $status = 'Paid';
        $paidAt = date('Y-m-d H:i:s');
    } elseif (creditDateIsPast($due)) {
        $status = 'Overdue';
    }
    $dueParam = $due !== '' ? $due : null;
    $up = $conn->prepare('UPDATE sales_credit_agreements SET invoice_id = ?, invoice_number = ?, customer_name = ?, agreed_amount = ?, credit_date = ?, due_date = COALESCE(?, due_date), collection_status = ?, paid_at = ? WHERE id = ?');
    if ($up) {
        $up->bind_param('issdssssi', $invoiceId, $invoiceNumber, $customerName, $creditAmount, $creditDay, $dueParam, $status, $paidAt, $agreementId);
        $up->execute();
        $up->close();
    }
    creditMonitorLog(
        $conn,
        $agreementId,
        $invoiceId,
        'arrangement',
        'opened',
        'Credit arrangement saved',
        'Customer ' . $customerName . ', invoice ' . $invoiceNumber . ', credit amount ₱' . number_format($creditAmount, 2) . ', due ' . ($due !== '' ? $due : '—') . '.',
        $creditAmount,
        (int)($_SESSION['user_id'] ?? 0),
        (string)($_SESSION['username'] ?? 'system')
    );
}

function creditMonitorFindAgreement(mysqli $conn, int $invoiceId, int $salesOrderId): ?array {
    creditMonitorEnsureSchema($conn);
    if ($invoiceId > 0) {
        $st = $conn->prepare('SELECT * FROM sales_credit_agreements WHERE invoice_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $invoiceId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                return $row;
            }
        }
    }
    if ($salesOrderId > 0) {
        $st = $conn->prepare('SELECT * FROM sales_credit_agreements WHERE sales_order_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $salesOrderId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                return $row;
            }
        }
    }
    return null;
}

function creditMonitorNotePayment(mysqli $conn, array $inv, float $amount, float $newBalance, string $invoiceStatus): void {
    creditMonitorEnsureSchema($conn);
    creditMonitorBackfillMissing($conn);
    $agreement = creditMonitorFindAgreement($conn, (int)($inv['id'] ?? 0), (int)($inv['sales_order_id'] ?? 0));
    if (!$agreement) {
        return;
    }
    $agreementId = (int)$agreement['id'];
    $invoiceId = (int)($inv['id'] ?? 0);
    $invoiceNumber = (string)($inv['invoice_number'] ?? $agreement['invoice_number'] ?? '');
    $actorId = (int)($_SESSION['user_id'] ?? 0);
    $actor = (string)($_SESSION['username'] ?? 'staff');
    $role = (string)($_SESSION['role'] ?? 'staff');
    creditMonitorLog(
        $conn,
        $agreementId,
        $invoiceId,
        'payment',
        null,
        'Payment recorded',
        'Payment of ₱' . number_format($amount, 2) . ' recorded on ' . date('F j, Y') . ' for invoice ' . $invoiceNumber . '. Invoice status: ' . $invoiceStatus . '.',
        $amount,
        $actorId,
        $actor
    );
    creditMonitorAudit($conn, 'credit_payment', 'Invoice ' . $invoiceNumber . ' payment ₱' . number_format($amount, 2) . ' on ' . date('Y-m-d') . '. Balance ₱' . number_format($newBalance, 2) . '.', $actorId, $actor, $role);
    if ($newBalance <= 0.009) {
        $paidAt = date('Y-m-d H:i:s');
        $up = $conn->prepare("UPDATE sales_credit_agreements SET collection_status = 'Paid', paid_at = ? WHERE id = ?");
        if ($up) {
            $up->bind_param('si', $paidAt, $agreementId);
            $up->execute();
            $up->close();
        }
        if (!creditMonitorEventExists($conn, $agreementId, 'paid')) {
            creditMonitorLog(
                $conn,
                $agreementId,
                $invoiceId,
                'payment',
                'paid',
                'Credit paid in full',
                'Outstanding balance cleared on ' . date('F j, Y') . '. Original credit arrangement kept.',
                $amount,
                $actorId,
                $actor
            );
            $msg = 'Invoice ' . $invoiceNumber . ' for ' . (string)($agreement['customer_name'] ?? '') . ' is paid in full. Payment date ' . date('F j, Y') . ', amount ₱' . number_format($amount, 2) . '.';
            creditMonitorNotifyStaff($conn, 'Credit Paid: ' . $invoiceNumber, $msg, 'low', $invoiceNumber);
        }
        return;
    }
    $due = creditMonitorDate((string)($inv['payment_due_date'] ?? $agreement['due_date'] ?? ''));
    $status = creditDateIsPast($due) ? 'Overdue' : 'Pending';
    $up = $conn->prepare('UPDATE sales_credit_agreements SET collection_status = ? WHERE id = ? AND collection_status <> \'Paid\'');
    if ($up) {
        $up->bind_param('si', $status, $agreementId);
        $up->execute();
        $up->close();
    }
}

function creditMonitorBackfillMissing(mysqli $conn): void {
    $sql = "SELECT inv.id, inv.invoice_number, inv.sales_order_id, inv.customer_name, inv.grand_total, inv.payment_due_date, inv.created_at, inv.remaining_balance
            FROM invoices inv
            LEFT JOIN sales_credit_agreements ca ON ca.sales_order_id = inv.sales_order_id
            WHERE inv.sale_type = 'Credit' AND ca.id IS NULL AND inv.sales_order_id > 0";
    $res = $conn->query($sql);
    if (!$res) {
        return;
    }
    while ($inv = $res->fetch_assoc()) {
        $soId = (int)$inv['sales_order_id'];
        $number = function_exists('salesOrderNextAgreementNumber')
            ? salesOrderNextAgreementNumber($conn)
            : ('CA-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        $customer = (string)$inv['customer_name'];
        $amount = (float)$inv['grand_total'];
        $due = creditMonitorDate((string)($inv['payment_due_date'] ?? ''));
        $creditDay = creditMonitorDate((string)($inv['created_at'] ?? ''));
        if ($creditDay === '') {
            $creditDay = date('Y-m-d');
        }
        $terms = 'Recorded from existing credit sale.';
        $dueParam = $due !== '' ? $due : null;
        $status = ((float)$inv['remaining_balance'] <= 0.009) ? 'Paid' : (creditDateIsPast($due) ? 'Overdue' : 'Pending');
        $paidAt = $status === 'Paid' ? date('Y-m-d H:i:s') : null;
        $invoiceId = (int)$inv['id'];
        $invoiceNumber = (string)$inv['invoice_number'];
        $ins = $conn->prepare("INSERT INTO sales_credit_agreements (sales_order_id, agreement_number, customer_name, agreed_amount, terms, due_date, status, created_by, invoice_id, invoice_number, credit_date, collection_status, paid_at) VALUES (?, ?, ?, ?, ?, ?, 'Active', 0, ?, ?, ?, ?, ?)");
        if (!$ins) {
            continue;
        }
        $ins->bind_param('issdssissss', $soId, $number, $customer, $amount, $terms, $dueParam, $invoiceId, $invoiceNumber, $creditDay, $status, $paidAt);
        $ins->execute();
        $newId = (int)$ins->insert_id;
        $ins->close();
        if ($newId > 0) {
            creditMonitorLog($conn, $newId, $invoiceId, 'arrangement', 'opened', 'Credit arrangement saved', 'Backfilled from invoice ' . $invoiceNumber . '.', $amount, 0, 'system');
        }
    }
}

function creditMonitorSweep(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    creditMonitorEnsureSchema($conn);
    creditMonitorBackfillMissing($conn);
    $sql = "SELECT ca.id, ca.customer_name, ca.agreed_amount, ca.due_date, ca.collection_status, ca.invoice_id, ca.invoice_number, ca.credit_date, ca.created_at,
                   inv.id AS inv_id, inv.invoice_number AS inv_number, inv.payment_due_date, inv.remaining_balance, inv.grand_total, inv.payment_status, inv.created_at AS inv_created, inv.customer_name AS inv_customer
            FROM sales_credit_agreements ca
            LEFT JOIN invoices inv ON inv.id = ca.invoice_id OR (ca.invoice_id IS NULL AND inv.sales_order_id = ca.sales_order_id)";
    $res = $conn->query($sql);
    if (!$res) {
        return;
    }
    $seen = [];
    while ($row = $res->fetch_assoc()) {
        $agreementId = (int)$row['id'];
        if (isset($seen[$agreementId])) {
            continue;
        }
        $seen[$agreementId] = true;
        $invoiceId = (int)($row['inv_id'] ?? 0);
        $invoiceNumber = trim((string)($row['inv_number'] ?? $row['invoice_number'] ?? ''));
        $customer = trim((string)($row['inv_customer'] ?? $row['customer_name'] ?? ''));
        $due = creditMonitorDate((string)($row['payment_due_date'] ?? ''));
        if ($due === '') {
            $due = creditMonitorDate((string)($row['due_date'] ?? ''));
        }
        $creditDay = creditMonitorDate((string)($row['credit_date'] ?? ''));
        if ($creditDay === '') {
            $creditDay = creditMonitorDate((string)($row['inv_created'] ?? $row['created_at'] ?? ''));
        }
        $balance = $row['remaining_balance'] === null ? (float)$row['agreed_amount'] : (float)$row['remaining_balance'];
        $current = (string)($row['collection_status'] ?? 'Pending');
        if ($current !== 'Paid' && $current !== 'Overdue' && $current !== 'Pending') {
            $current = 'Pending';
        }
        $next = 'Pending';
        if ($balance <= 0.009 || (string)($row['payment_status'] ?? '') === 'Paid') {
            $next = 'Paid';
        } elseif (creditDateIsPast($due)) {
            $next = 'Overdue';
        }
        $paidAt = $next === 'Paid' ? date('Y-m-d H:i:s') : null;
        $dueParam = $due !== '' ? $due : null;
        $creditParam = $creditDay !== '' ? $creditDay : null;
        $invParam = $invoiceId > 0 ? $invoiceId : null;
        $up = $conn->prepare('UPDATE sales_credit_agreements SET invoice_id = COALESCE(?, invoice_id), invoice_number = IF(? = \'\', invoice_number, ?), customer_name = IF(? = \'\', customer_name, ?), credit_date = COALESCE(credit_date, ?), due_date = COALESCE(?, due_date), collection_status = ?, paid_at = IF(? = \'Paid\', COALESCE(paid_at, ?), paid_at) WHERE id = ?');
        if ($up) {
            $up->bind_param('isssssssssi', $invParam, $invoiceNumber, $invoiceNumber, $customer, $customer, $creditParam, $dueParam, $next, $next, $paidAt, $agreementId);
            $up->execute();
            $up->close();
        }
        if ($next === 'Paid') {
            continue;
        }
        $daysUntil = creditMonitorDaysUntil($due);
        if ($daysUntil === null) {
            continue;
        }
        $amount = (float)($row['remaining_balance'] ?? $row['agreed_amount'] ?? 0);
        $money = '₱' . number_format($amount, 2);
        $dueLabel = date('F j, Y', strtotime($due));
        $base = $customer . ' — invoice ' . $invoiceNumber . ', outstanding ' . $money . ', due ' . $dueLabel . '.';
        if ($daysUntil < 0) {
            $daysOver = abs($daysUntil);
            $title = 'Payment Overdue';
            $msg = $base . ' Overdue by ' . $daysOver . ' day' . ($daysOver === 1 ? '' : 's') . '.';
            if (!creditMonitorEventExists($conn, $agreementId, 'overdue')) {
                creditMonitorLog($conn, $agreementId, $invoiceId, 'notification', 'overdue', $title, $msg, $amount, 0, 'system');
                creditMonitorNotifyStaff($conn, $title . ': ' . $invoiceNumber, $msg, 'high', $invoiceNumber);
                creditMonitorAudit($conn, 'credit_overdue', $msg, 0, 'system', 'system');
            }
            continue;
        }
        if ($daysUntil === 0 && !creditMonitorEventExists($conn, $agreementId, 'due_today')) {
            $title = 'Payment Due Today';
            creditMonitorLog($conn, $agreementId, $invoiceId, 'notification', 'due_today', $title, $base, $amount, 0, 'system');
            creditMonitorNotifyStaff($conn, $title . ': ' . $invoiceNumber, $base, 'high', $invoiceNumber);
            creditMonitorAudit($conn, 'credit_due_today', $base, 0, 'system', 'system');
        } elseif ($daysUntil >= 1 && $daysUntil <= 3 && !creditMonitorEventExists($conn, $agreementId, 'due_3')) {
            $title = 'Payment Reminder';
            $msg = $base . ' Due in ' . $daysUntil . ' day' . ($daysUntil === 1 ? '' : 's') . '.';
            creditMonitorLog($conn, $agreementId, $invoiceId, 'notification', 'due_3', $title, $msg, $amount, 0, 'system');
            creditMonitorNotifyStaff($conn, $title . ': ' . $invoiceNumber, $msg, 'medium', $invoiceNumber);
            creditMonitorAudit($conn, 'credit_reminder', $msg, 0, 'system', 'system');
        } elseif ($daysUntil >= 4 && $daysUntil <= 7 && !creditMonitorEventExists($conn, $agreementId, 'due_7')) {
            $title = 'Payment Due Soon';
            $msg = $base . ' Due in ' . $daysUntil . ' day' . ($daysUntil === 1 ? '' : 's') . '.';
            creditMonitorLog($conn, $agreementId, $invoiceId, 'notification', 'due_7', $title, $msg, $amount, 0, 'system');
            creditMonitorNotifyStaff($conn, $title . ': ' . $invoiceNumber, $msg, 'medium', $invoiceNumber);
            creditMonitorAudit($conn, 'credit_due_soon', $msg, 0, 'system', 'system');
        }
    }
}

function creditMonitorAttachInvoices(mysqli $conn, array &$invoices): void {
    if (!$invoices) {
        return;
    }
    creditMonitorEnsureSchema($conn);
    $byInvoice = [];
    $byOrder = [];
    $q = $conn->query('SELECT id, sales_order_id, invoice_id, collection_status, credit_date, due_date, agreed_amount, invoice_number, customer_name, paid_at FROM sales_credit_agreements');
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $iid = (int)($row['invoice_id'] ?? 0);
            $soid = (int)($row['sales_order_id'] ?? 0);
            if ($iid > 0) {
                $byInvoice[$iid] = $row;
            }
            if ($soid > 0) {
                $byOrder[$soid] = $row;
            }
        }
    }
    foreach ($invoices as &$inv) {
        $hit = $byInvoice[(int)($inv['id'] ?? 0)] ?? $byOrder[(int)($inv['sales_order_id'] ?? 0)] ?? null;
        if (!$hit) {
            continue;
        }
        $due = creditMonitorDate((string)($inv['payment_due_date'] ?? ''));
        if ($due === '') {
            $due = creditMonitorDate((string)($hit['due_date'] ?? ''));
        }
        $inv['credit_agreement_id'] = (int)$hit['id'];
        $inv['credit_collection_status'] = (string)($hit['collection_status'] ?? 'Pending');
        $inv['credit_date'] = (string)($hit['credit_date'] ?? '');
        $inv['credit_amount'] = (float)($hit['agreed_amount'] ?? 0);
        $inv['credit_days_overdue'] = creditDaysOverdue($due);
        if ((float)($inv['remaining_balance'] ?? 0) <= 0.009) {
            $inv['credit_collection_status'] = 'Paid';
        } elseif (creditDateIsPast($due)) {
            $inv['credit_collection_status'] = 'Overdue';
        }
    }
    unset($inv);
}

function creditMonitorLoadOverdue(mysqli $conn): array {
    creditMonitorEnsureSchema($conn);
    $sql = "SELECT ca.*, inv.invoice_number AS live_invoice_number, inv.remaining_balance, inv.grand_total, inv.payment_due_date, inv.payment_status, inv.created_at AS invoice_created
            FROM sales_credit_agreements ca
            INNER JOIN invoices inv ON inv.id = ca.invoice_id OR (ca.invoice_id IS NULL AND inv.sales_order_id = ca.sales_order_id)
            WHERE ca.collection_status = 'Overdue' AND inv.remaining_balance > 0.009
            ORDER BY inv.payment_due_date ASC, ca.id DESC";
    $rows = [];
    $seen = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $due = creditMonitorDate((string)($row['payment_due_date'] ?? $row['due_date'] ?? ''));
            if (!creditDateIsPast($due)) {
                continue;
            }
            $row['monitor_due'] = $due;
            $row['days_overdue'] = creditDaysOverdue($due);
            $row['live_invoice_number'] = (string)($row['live_invoice_number'] ?? $row['invoice_number'] ?? '');
            $rows[] = $row;
        }
    }
    return $rows;
}

function creditMonitorLoadHistory(mysqli $conn, int $agreementId): array {
    creditMonitorEnsureSchema($conn);
    $rows = [];
    if ($agreementId <= 0) {
        return $rows;
    }
    $st = $conn->prepare('SELECT * FROM credit_collection_log WHERE agreement_id = ? ORDER BY id DESC');
    if (!$st) {
        return $rows;
    }
    $st->bind_param('i', $agreementId);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $st->close();
    return $rows;
}

function creditMonitorRecordFollowUp(mysqli $conn, int $agreementId, string $note, string $remindOn): void {
    if (!creditMonitorCanRecordFollowUp()) {
        throw new Exception('Not allowed to record a credit follow-up.');
    }
    $note = trim($note);
    if ($agreementId <= 0 || $note === '') {
        throw new Exception('A follow-up note is required.');
    }
    if (strlen($note) > 1000) {
        $note = substr($note, 0, 1000);
    }
    creditMonitorEnsureSchema($conn);
    $st = $conn->prepare('SELECT * FROM sales_credit_agreements WHERE id = ? LIMIT 1');
    if (!$st) {
        throw new Exception('Credit arrangement not found.');
    }
    $st->bind_param('i', $agreementId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        throw new Exception('Credit arrangement not found.');
    }
    $remind = creditMonitorDate($remindOn);
    $details = $note;
    if ($remind !== '') {
        $details .= ' Next reminder: ' . $remind . '.';
    }
    $actorId = (int)($_SESSION['user_id'] ?? 0);
    $actor = (string)($_SESSION['username'] ?? 'staff');
    $role = (string)($_SESSION['role'] ?? 'staff');
    $invoiceId = (int)($row['invoice_id'] ?? 0);
    $invoiceNumber = (string)($row['invoice_number'] ?? '');
    creditMonitorLog($conn, $agreementId, $invoiceId > 0 ? $invoiceId : null, 'follow_up', null, 'Follow-up recorded', $details, null, $actorId, $actor);
    creditMonitorAudit($conn, 'credit_follow_up', 'Invoice ' . $invoiceNumber . ' (' . (string)$row['customer_name'] . '): ' . $details, $actorId, $actor, $role);
    $msg = (string)$row['customer_name'] . ' — invoice ' . $invoiceNumber . '. ' . $details;
    creditMonitorNotifyStaff($conn, 'Credit Follow-up: ' . $invoiceNumber, $msg, 'medium', $invoiceNumber);
}

function creditDueDateHtml(array $inv): string {
    $due = trim((string)($inv['payment_due_date'] ?? ''));
    $shown = $due !== '' ? $due : '—';
    $bal = (float)($inv['remaining_balance'] ?? 0);
    $past = creditDateIsPast($due) && $bal > 0.009;
    if (!$past) {
        return htmlspecialchars($shown);
    }
    $days = creditDaysOverdue($due);
    $isCredit = function_exists('billingNormalizeSaleType') && billingNormalizeSaleType($inv['sale_type'] ?? '') === 'Credit';
    $label = $isCredit
        ? ($days . ' day' . ($days === 1 ? '' : 's') . ' overdue')
        : 'Overdue';
    return '<strong class="credit-overdue-due">' . htmlspecialchars($shown) . '</strong><br><span class="badge badge-danger" style="font-size:10px;padding:2px 6px;"><i class="bi bi-calendar-x"></i> ' . htmlspecialchars($label) . '</span>';
}

function creditArActionButtons(array $inv): string {
    if (!function_exists('billingNormalizeSaleType') || billingNormalizeSaleType($inv['sale_type'] ?? '') !== 'Credit') {
        return '';
    }
    $aid = (int)($inv['credit_agreement_id'] ?? 0);
    if ($aid <= 0) {
        return '';
    }
    $html = ' <button type="button" class="btn btn-outline-secondary btn-sm" onclick="creditOpenHistory(' . $aid . ')"><i class="bi bi-clock-history"></i> History</button>';
    if (creditMonitorCanRecordFollowUp() && (float)($inv['remaining_balance'] ?? 0) > 0.009) {
        $customer = htmlspecialchars((string)($inv['customer_name'] ?? ''), ENT_QUOTES);
        $invoice = htmlspecialchars((string)($inv['invoice_number'] ?? ''), ENT_QUOTES);
        $html .= ' <button type="button" class="btn btn-outline-warning btn-sm" onclick="creditOpenFollowUp(' . $aid . ',\'' . $customer . '\',\'' . $invoice . '\')"><i class="bi bi-chat-left-text"></i> Follow-up</button>';
    }
    return $html;
}

function creditRenderOverdueSection(?array $onlyInvoiceIds = null): void {
    global $conn;
    static $assets = false;
    if (!$conn instanceof mysqli) {
        return;
    }
    $rows = creditMonitorLoadOverdue($conn);
    if ($onlyInvoiceIds !== null) {
        $allow = [];
        foreach ($onlyInvoiceIds as $id) {
            $allow[(int)$id] = true;
        }
        $rows = array_values(array_filter($rows, function ($row) use ($allow) {
            return !empty($allow[(int)($row['invoice_id'] ?? 0)]);
        }));
    }
    $canFollow = creditMonitorCanRecordFollowUp();
    ?>
    <style>
        .credit-overdue-row { background: #fef2f2; }
        .credit-overdue-amt, .credit-overdue-due { color: #b91c1c; font-weight: 800; }
        .credit-overdue-card { border: 1px solid #fecaca; }
        .credit-overdue-card .card-title { color: #991b1b; }
    </style>
    <div class="card mb-3 credit-overdue-card">
        <div class="card-header">
            <div class="card-title">Overdue Credit / Accounts Receivable</div>
        </div>
        <div class="table-container system-table-scroll">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Invoice</th>
                        <th>Credit Amount</th>
                        <th>Outstanding</th>
                        <th>Credit Date</th>
                        <th>Due Date</th>
                        <th>Days Overdue</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted">No overdue credit accounts.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $invoiceNumber = (string)($row['live_invoice_number'] ?? '');
                        $invoiceId = (int)($row['invoice_id'] ?? 0);
                        $balance = (float)($row['remaining_balance'] ?? 0);
                        $grand = (float)($row['grand_total'] ?? $row['agreed_amount'] ?? 0);
                        $creditDate = (string)($row['credit_date'] ?? '');
                        if ($creditDate === '' && !empty($row['invoice_created'])) {
                            $creditDate = substr((string)$row['invoice_created'], 0, 10);
                        }
                        $due = (string)($row['monitor_due'] ?? '');
                        $days = (int)($row['days_overdue'] ?? 0);
                    ?>
                    <tr class="credit-overdue-row">
                        <td><strong><?= htmlspecialchars((string)$row['customer_name']) ?></strong></td>
                        <td><span class="badge badge-blue"><?= htmlspecialchars($invoiceNumber) ?></span></td>
                        <td>₱<?= number_format((float)$row['agreed_amount'], 2) ?></td>
                        <td><strong class="credit-overdue-amt">₱<?= number_format($balance, 2) ?></strong></td>
                        <td><?= htmlspecialchars($creditDate !== '' ? $creditDate : '—') ?></td>
                        <td><strong class="credit-overdue-due"><?= htmlspecialchars($due !== '' ? $due : '—') ?></strong></td>
                        <td><span class="badge badge-danger"><?= $days ?> day<?= $days === 1 ? '' : 's' ?></span></td>
                        <td><span class="badge badge-danger"><i class="bi bi-calendar-x"></i> Overdue</span></td>
                        <td>
                            <?php if ($invoiceId > 0 && $balance > 0.009): ?>
                            <button type="button" class="btn btn-success btn-sm" onclick="openPaymentModal(<?= $invoiceId ?>,'<?= htmlspecialchars($invoiceNumber, ENT_QUOTES) ?>',<?= $grand ?>,<?= $balance ?>)"><i class="bi bi-cash"></i> Pay</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="creditOpenHistory(<?= (int)$row['id'] ?>)"><i class="bi bi-clock-history"></i> History</button>
                            <?php if ($canFollow): ?>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="creditOpenFollowUp(<?= (int)$row['id'] ?>,'<?= htmlspecialchars((string)$row['customer_name'], ENT_QUOTES) ?>','<?= htmlspecialchars($invoiceNumber, ENT_QUOTES) ?>')"><i class="bi bi-chat-left-text"></i> Follow-up</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (!$assets): $assets = true; ?>
    <div class="modal fade" id="creditFollowUpModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <?php if (function_exists('clarenzon_csrf_field')) echo clarenzon_csrf_field(); ?>
                <input type="hidden" name="record_credit_followup" value="1">
                <input type="hidden" name="credit_agreement_id" id="creditFollowAgreementId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Record follow-up</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2" id="creditFollowContext"></p>
                    <div class="mb-3">
                        <label class="form-label" for="creditFollowNote">Follow-up / reminder <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="followup_note" id="creditFollowNote" rows="3" required maxlength="1000" placeholder="What was discussed or what reminder was sent"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="creditFollowRemindOn">Next reminder date</label>
                        <input type="date" class="form-control" name="followup_remind_on" id="creditFollowRemindOn">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save follow-up</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="creditHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Credit history</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="creditHistoryBody"><p class="text-muted mb-0">Loading…</p></div>
            </div>
        </div>
    </div>
    <script>
    function creditOpenFollowUp(agreementId, customer, invoiceNumber) {
        var idEl = document.getElementById('creditFollowAgreementId');
        var note = document.getElementById('creditFollowNote');
        var remind = document.getElementById('creditFollowRemindOn');
        var ctx = document.getElementById('creditFollowContext');
        if (idEl) idEl.value = String(agreementId || '');
        if (note) note.value = '';
        if (remind) remind.value = '';
        if (ctx) ctx.textContent = (customer || '') + (invoiceNumber ? ' · ' + invoiceNumber : '');
        var modalEl = document.getElementById('creditFollowUpModal');
        if (modalEl && window.bootstrap) new bootstrap.Modal(modalEl).show();
    }
    function creditOpenHistory(agreementId) {
        var body = document.getElementById('creditHistoryBody');
        if (body) body.innerHTML = '<p class="text-muted mb-0">Loading…</p>';
        var modalEl = document.getElementById('creditHistoryModal');
        if (modalEl && window.bootstrap) new bootstrap.Modal(modalEl).show();
        fetch('billing_api.php?action=credit_collection_history&id=' + encodeURIComponent(agreementId), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!body) return;
            if (!data || !data.success) {
                body.innerHTML = '<p class="text-danger mb-0">' + (data && data.message ? data.message : 'Could not load history.') + '</p>';
                return;
            }
            var rows = data.events || [];
            if (!rows.length) {
                body.innerHTML = '<p class="text-muted mb-0">No notifications, follow-ups, or payments yet.</p>';
                return;
            }
            var html = '<div class="table-container system-table-scroll"><table class="modern-table"><thead><tr><th>When</th><th>Event</th><th>Details</th><th>Amount</th><th>By</th></tr></thead><tbody>';
            rows.forEach(function (ev) {
                var when = ev.created_at || '';
                var amount = ev.amount !== null && ev.amount !== '' ? ('₱' + Number(ev.amount).toFixed(2)) : '—';
                html += '<tr><td>' + creditEsc(when) + '</td><td>' + creditEsc(ev.title || ev.event_type || '') + '</td><td>' + creditEsc(ev.details || '') + '</td><td>' + creditEsc(amount) + '</td><td>' + creditEsc(ev.actor_name || 'system') + '</td></tr>';
            });
            html += '</tbody></table></div>';
            body.innerHTML = html;
        }).catch(function () {
            if (body) body.innerHTML = '<p class="text-danger mb-0">Could not load history.</p>';
        });
    }
    function creditEsc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }
    </script>
    <?php endif;
}
