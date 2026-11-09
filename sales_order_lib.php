<?php
/**
 * Dedicated Sales Order module helpers: schema, tax, credit agreements, list/detail.
 */
mysqli_report(MYSQLI_REPORT_OFF);

function salesOrderExclusiveTax(float $amount, float $rate): float {
    if ($amount <= 0 || $rate <= 0) return 0.0;
    return round($amount * ($rate / 100.0), 2);
}

function salesOrderComputeTotals(float $subtotal, float $discountAmount, float $taxRate, float $deliveryFee = 0.0): array {
    $subtotal = round(max(0, $subtotal), 2);
    $discountAmount = round(max(0, min($discountAmount, $subtotal)), 2);
    $deliveryFee = round(max(0, $deliveryFee), 2);
    $taxable = round($subtotal - $discountAmount, 2);
    $taxAmount = salesOrderExclusiveTax($taxable, $taxRate);
    $grandTotal = round($taxable + $taxAmount + $deliveryFee, 2);
    return [
        'subtotal' => $subtotal,
        'discount_amount' => $discountAmount,
        'tax_amount' => $taxAmount,
        'delivery_fee' => $deliveryFee,
        'grand_total' => $grandTotal,
        'tax_rate' => $taxRate,
    ];
}

function salesOrderComposeCustomerName(array $src): string {
    $first = trim((string)($src['customer_first_name'] ?? ''));
    $middle = trim((string)($src['customer_middle_name'] ?? ''));
    $last = trim((string)($src['customer_last_name'] ?? ''));
    $suffix = trim((string)($src['customer_suffix'] ?? ''));
    $parts = [];
    foreach ([$first, $middle, $last, $suffix] as $part) {
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    $composed = $parts
        ? trim(preg_replace('/\s+/', ' ', implode(' ', $parts)))
        : trim((string)($src['customer_name'] ?? ''));
    if ($composed === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($composed, 0, 150);
    }
    return substr($composed, 0, 150);
}

function salesOrderEnsureSchema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS sales_credit_agreements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sales_order_id INT NOT NULL UNIQUE,
        agreement_number VARCHAR(50) NOT NULL UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        agreed_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        terms TEXT NULL,
        due_date DATE NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Active',
        created_by INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        settled_at DATETIME NULL,
        INDEX idx_ca_status (status),
        INDEX idx_ca_customer (customer_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'discount_percent' => "DECIMAL(8,4) NOT NULL DEFAULT 0.0000",
        'stock_out_status' => "VARCHAR(20) NOT NULL DEFAULT 'Pending'",
        'credit_agreement_number' => "VARCHAR(50) NULL",
    ] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM sales_orders LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE sales_orders ADD COLUMN `$col` $def");
        }
    }

    @$conn->query("UPDATE sales_orders SET stock_out_status = 'Completed' WHERE status = 'Approved' AND (stock_out_status IS NULL OR stock_out_status = '' OR stock_out_status = 'Pending')");
    @$conn->query("UPDATE system_settings SET setting_value = setting_value, description = 'Sales tax rate (%) added on top of (subtotal − discount)' WHERE setting_key = 'sales_tax_rate'");
}

function salesOrderNextAgreementNumber(mysqli $conn): string {
    $unique = false;
    $num = '';
    while (!$unique) {
        $num = 'CA-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $chk = $conn->prepare("SELECT id FROM sales_credit_agreements WHERE agreement_number = ? LIMIT 1");
        if (!$chk) break;
        $chk->bind_param('s', $num);
        $chk->execute();
        $unique = $chk->get_result()->num_rows === 0;
        $chk->close();
    }
    return $num !== '' ? $num : ('CA-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3))));
}

function salesOrderCreateCreditAgreement(mysqli $conn, int $salesOrderId, string $customerName, float $agreedAmount, string $terms, string $dueDate, int $userId): array {
    $customerName = trim($customerName);
    $terms = trim($terms);
    if ($customerName === '') {
        throw new Exception('Customer name is required for a credit agreement.');
    }
    if ($terms === '') {
        throw new Exception('Credit terms are required. Generate a credit agreement/contract for Credit sales.');
    }
    $number = salesOrderNextAgreementNumber($conn);
    $ins = $conn->prepare("INSERT INTO sales_credit_agreements (sales_order_id, agreement_number, customer_name, agreed_amount, terms, due_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'Active', ?)");
    if (!$ins) {
        throw new Exception('Could not create credit agreement: ' . $conn->error);
    }
    $due = $dueDate !== '' ? $dueDate : null;
    $ins->bind_param('issdssi', $salesOrderId, $number, $customerName, $agreedAmount, $terms, $due, $userId);
    if (!$ins->execute()) {
        $err = $ins->error;
        $ins->close();
        throw new Exception('Could not save credit agreement: ' . $err);
    }
    $id = (int)$ins->insert_id;
    $ins->close();
    $up = $conn->prepare("UPDATE sales_orders SET credit_agreement_number = ? WHERE id = ?");
    if ($up) {
        $up->bind_param('si', $number, $salesOrderId);
        $up->execute();
        $up->close();
    }
    return [
        'id' => $id,
        'agreement_number' => $number,
        'customer_name' => $customerName,
        'agreed_amount' => $agreedAmount,
        'terms' => $terms,
        'due_date' => $dueDate,
        'status' => 'Active',
    ];
}

function salesOrderSyncCreditStatus(mysqli $conn, int $soId, float $remainingBalance): void {
    if ($soId <= 0) return;
    $status = $remainingBalance <= 0.009 ? 'Settled' : 'Active';
    $settledSql = $status === 'Settled' ? 'settled_at = COALESCE(settled_at, NOW())' : 'settled_at = NULL';
    $st = $conn->prepare("UPDATE sales_credit_agreements SET status = ?, $settledSql WHERE sales_order_id = ?");
    if ($st) {
        $st->bind_param('si', $status, $soId);
        $st->execute();
        $st->close();
    }
}

function salesOrderLoadList(mysqli $conn, ?int $createdBy = null): array {
    $sql = "SELECT so.*, u.full_name AS creator_name,
                   inv.id AS invoice_id, inv.invoice_number, inv.remaining_balance, inv.payment_status,
                   ca.agreement_number, ca.status AS credit_agreement_status, ca.terms AS credit_terms, ca.collection_status
            FROM sales_orders so
            LEFT JOIN users u ON so.created_by = u.id
            LEFT JOIN invoices inv ON inv.sales_order_id = so.id
            LEFT JOIN sales_credit_agreements ca ON ca.sales_order_id = so.id";
    if ($createdBy !== null && $createdBy > 0) {
        $sql .= " WHERE so.created_by = " . (int)$createdBy;
    }
    $sql .= " ORDER BY so.created_at DESC";
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function salesOrderLoadAgreements(mysqli $conn, ?int $createdBy = null): array {
    $sql = "SELECT ca.*, so.order_number, so.sale_type, so.status AS so_status, so.grand_total,
                   inv.invoice_number, inv.remaining_balance, inv.payment_status, u.full_name AS creator_name
            FROM sales_credit_agreements ca
            INNER JOIN sales_orders so ON so.id = ca.sales_order_id
            LEFT JOIN invoices inv ON inv.sales_order_id = so.id
            LEFT JOIN users u ON ca.created_by = u.id";
    if ($createdBy !== null && $createdBy > 0) {
        $sql .= " WHERE ca.created_by = " . (int)$createdBy;
    }
    $sql .= " ORDER BY ca.created_at DESC";
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function salesOrderLoadDetail(mysqli $conn, int $soId): ?array {
    if ($soId <= 0) return null;
    $st = $conn->prepare("SELECT so.*, u.full_name AS creator_name,
                                 inv.id AS invoice_id, inv.invoice_number, inv.remaining_balance, inv.payment_status,
                                 ca.id AS credit_agreement_id, ca.agreement_number, ca.terms AS credit_terms,
                                 ca.status AS credit_agreement_status, ca.agreed_amount
                          FROM sales_orders so
                          LEFT JOIN users u ON so.created_by = u.id
                          LEFT JOIN invoices inv ON inv.sales_order_id = so.id
                          LEFT JOIN sales_credit_agreements ca ON ca.sales_order_id = so.id
                          WHERE so.id = ? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('i', $soId);
    $st->execute();
    $so = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$so) return null;

    $items = [];
    $it = $conn->prepare("SELECT soi.*, p.sku, p.name AS product_name, p.barcode, p.quantity_in_stock, p.unit_of_measure
                          FROM sales_order_items soi
                          LEFT JOIN products p ON p.id = soi.product_id
                          WHERE soi.sales_order_id = ?");
    if ($it) {
        $it->bind_param('i', $soId);
        $it->execute();
        $res = $it->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
        $it->close();
    }

    $payments = [];
    $invoiceId = (int)($so['invoice_id'] ?? 0);
    if ($invoiceId > 0) {
        $pq = $conn->prepare("SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY id ASC");
        if ($pq) {
            $pq->bind_param('i', $invoiceId);
            $pq->execute();
            $pres = $pq->get_result();
            while ($row = $pres->fetch_assoc()) {
                $payments[] = $row;
            }
            $pq->close();
        }
    }

    return [
        'order' => $so,
        'items' => $items,
        'payments' => $payments,
    ];
}

function salesOrderDisplayStatus(array $ord): string {
    $status = (string)($ord['status'] ?? 'Pending');
    $saleType = strtolower((string)($ord['sale_type'] ?? ''));
    $cheque = trim((string)($ord['cheque_status'] ?? ''));
    if ($status === 'Cancelled') return 'Cancelled';
    if ($saleType === 'cheque' || $saleType === 'check') {
        if ($cheque === 'Cleared' && $status === 'Approved') return 'Completed';
        if ($cheque === 'Bounced/Rejected' || $cheque === 'Bounced') return 'Cheque Rejected';
        return 'Pending';
    }
    return $status;
}

function salesOrderPaymentLabel(array $ord): string {
    $pay = (string)($ord['payment_status'] ?? '');
    $saleType = strtolower((string)($ord['sale_type'] ?? ''));
    $cheque = trim((string)($ord['cheque_status'] ?? ''));
    if ($saleType === 'cheque' || $saleType === 'check') {
        if ($cheque === 'Cleared') return $pay !== '' ? $pay : 'Paid';
        if ($cheque === 'Bounced/Rejected' || $cheque === 'Bounced') return 'Cheque Rejected';
        return 'Pending (Cheque)';
    }
    if ($saleType === 'credit') {
        $collection = (string)($ord['collection_status'] ?? '');
        if ($pay === 'Paid' || $collection === 'Paid') return 'Paid';
        if ($collection === 'Overdue') return 'Overdue';
        return 'Pending';
    }
    if ($pay !== '') return $pay;
    return '—';
}
