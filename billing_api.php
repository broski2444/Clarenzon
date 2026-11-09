<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
// Handles billing and invoicing actions.
// Included at the top of admin_dashboard.php and Accounting.php.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once 'notifications_api.php';
require_once __DIR__ . '/product_pricing_lib.php';
require_once __DIR__ . '/sales_order_lib.php';
require_once __DIR__ . '/sales_order_ui.php';
require_once __DIR__ . '/credit_monitor_lib.php';

$billing_alert = '';
$billing_print_invoice = '';
$billing_print_receipt = '';
$billing_redirect_receipt = '';

// Load database connection in case it's not set
if (!isset($conn) || !$conn) {
    require_once __DIR__ . '/mysqli_connect_fast.php';
    $conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'], 'root', '', 3);
}

function ensureBillingSchema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS sales_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(50) NOT NULL UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        grand_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Pending', 'Approved', 'Cancelled') NOT NULL DEFAULT 'Pending',
        payment_due_date DATE NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_so_number (order_number),
        INDEX idx_so_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sales_order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        batch_id INT NULL,
        batch_number VARCHAR(100) NULL,
        INDEX idx_so_id (sales_order_id),
        INDEX idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'batch_id' => 'INT NULL',
        'batch_number' => 'VARCHAR(100) NULL',
    ] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM sales_order_items LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE sales_order_items ADD COLUMN `$col` $def");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) NOT NULL UNIQUE,
        sales_order_id INT NOT NULL UNIQUE,
        sales_order_number VARCHAR(50) NOT NULL,
        customer_name VARCHAR(150) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        grand_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        remaining_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_status ENUM('Unpaid', 'Partially Paid', 'Paid') NOT NULL DEFAULT 'Unpaid',
        payment_due_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inv_number (invoice_number),
        INDEX idx_inv_status (payment_status),
        INDEX idx_inv_so_id (sales_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS invoice_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        INDEX idx_inv_id (invoice_id),
        INDEX idx_inv_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS invoice_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        payment_number VARCHAR(50) NOT NULL UNIQUE,
        amount_paid DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT NULL,
        INDEX idx_pay_invoice_id (invoice_id),
        INDEX idx_pay_number (payment_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'cash_tendered' => 'DECIMAL(12,2) NULL AFTER amount_paid',
        'cash_change' => 'DECIMAL(12,2) NULL AFTER cash_tendered',
    ] as $payCol => $payDef) {
        $payChk = $conn->query("SHOW COLUMNS FROM invoice_payments LIKE '$payCol'");
        if ($payChk && $payChk->num_rows === 0) {
            @$conn->query("ALTER TABLE invoice_payments ADD COLUMN `$payCol` $payDef");
        }
    }

    foreach ([
        'customer_po_number' => "VARCHAR(80) NULL",
        'sale_type' => "VARCHAR(20) NOT NULL DEFAULT 'Cash'",
        'tax_rate' => "DECIMAL(8,4) NOT NULL DEFAULT 0.0000",
        'cheque_number' => "VARCHAR(80) NULL",
        'bank_name' => "VARCHAR(150) NULL",
        'cheque_date' => "DATE NULL",
        'cheque_status' => "VARCHAR(30) NULL",
        'delivery_fee' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    ] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM sales_orders LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE sales_orders ADD COLUMN `$col` $def");
        }
    }
    foreach ([
        'customer_po_number' => "VARCHAR(80) NULL",
        'sale_type' => "VARCHAR(20) NOT NULL DEFAULT 'Cash'",
        'tax_rate' => "DECIMAL(8,4) NOT NULL DEFAULT 0.0000",
        'delivery_fee' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    ] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM invoices LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE invoices ADD COLUMN `$col` $def");
        }
    }
    foreach ([
        'tax_rate' => "DECIMAL(8,4) NOT NULL DEFAULT 0.0000",
        'tax_included' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    ] as $col => $def) {
        foreach (['sales_order_items', 'invoice_items'] as $tbl) {
            $chk = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
            if ($chk && $chk->num_rows === 0) {
                @$conn->query("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
            }
        }
    }
    foreach ([
        'bank_name' => "VARCHAR(150) NULL",
        'cheque_number' => "VARCHAR(80) NULL",
        'cheque_status' => "VARCHAR(30) NULL",
        'cheque_date' => "DATE NULL",
        'wallet_name' => "VARCHAR(150) NULL",
        'wallet_number' => "VARCHAR(80) NULL",
        'reference_number' => "VARCHAR(80) NULL",
    ] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM invoice_payments LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE invoice_payments ADD COLUMN `$col` $def");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        setting_type VARCHAR(50) DEFAULT 'string',
        description TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, description)
        VALUES ('sales_tax_rate', '12', 'decimal', 'Sales tax rate (%) added on top of (subtotal - discount)')");

    // Repair invoices that stored customer_name as "0" when bind_param used type i
    @$conn->query("UPDATE invoices i
        INNER JOIN sales_orders so ON so.id = i.sales_order_id
        SET i.customer_name = so.customer_name
        WHERE (i.customer_name = '0' OR i.customer_name = '' OR i.customer_name IS NULL)
          AND so.customer_name IS NOT NULL
          AND TRIM(so.customer_name) <> ''
          AND so.customer_name <> '0'");
    @$conn->query("UPDATE invoices i
        INNER JOIN sales_orders so ON so.id = i.sales_order_id
        SET i.sales_order_number = so.order_number
        WHERE (i.sales_order_number = '' OR i.sales_order_number IS NULL OR i.sales_order_number = '0')
          AND so.order_number IS NOT NULL
          AND so.order_number <> ''");
}

function billingInvoiceViewPayload(array $inv): array {
    $items = $inv['items'] ?? [];
    if (empty($items) && !empty($inv['items_json'])) {
        $decoded = json_decode((string)$inv['items_json'], true);
        $items = is_array($decoded) ? $decoded : [];
    }
    $invRate = (float)($inv['tax_rate'] ?? 0);
    if ($invRate <= 0) {
        $invRate = 12.0;
    }
    $safeItems = [];
    $lineTaxSum = 0.0;
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $qty = (float)($it['quantity'] ?? 0);
        $price = (float)($it['unit_price'] ?? 0);
        $amount = $qty * $price;
        $lineRate = (float)($it['tax_rate'] ?? 0);
        if ($lineRate <= 0) {
            $lineRate = $invRate;
        }
        $taxIncluded = array_key_exists('tax_included', $it) && $it['tax_included'] !== null && $it['tax_included'] !== ''
            ? (float)$it['tax_included']
            : billingInclusiveTax($amount, $lineRate);
        $lineTaxSum += $taxIncluded;
        $safeItems[] = [
            'product_sku' => (string)($it['product_sku'] ?? $it['sku'] ?? ''),
            'product_name' => (string)($it['product_name'] ?? ''),
            'unit_of_measure' => (string)($it['unit_of_measure'] ?? $it['uom'] ?? ''),
            'quantity' => $qty,
            'unit_price' => $price,
            'tax_rate' => $lineRate,
            'tax_included' => $taxIncluded,
        ];
    }
    $taxAmount = (float)($inv['tax_amount'] ?? 0);
    if ($taxAmount <= 0 && $lineTaxSum > 0) {
        $taxAmount = round($lineTaxSum, 2);
    }
    return [
        'invoice_number' => (string)($inv['invoice_number'] ?? ''),
        'sales_order_number' => (string)($inv['sales_order_number'] ?? ''),
        'customer_po_number' => (string)($inv['customer_po_number'] ?? ''),
        'customer_name' => (string)($inv['customer_name'] ?? ''),
        'sale_type' => billingNormalizeSaleType($inv['sale_type'] ?? 'Cash'),
        'sale_type_label' => billingSaleTypeLabel($inv['sale_type'] ?? 'Cash'),
        'subtotal' => (float)($inv['subtotal'] ?? 0),
        'discount_amount' => (float)($inv['discount_amount'] ?? 0),
        'tax_rate' => (float)($inv['tax_rate'] ?? $invRate),
        'tax_amount' => $taxAmount,
        'grand_total' => (float)($inv['grand_total'] ?? 0),
        'remaining_balance' => (float)($inv['remaining_balance'] ?? 0),
        'created_at' => (string)($inv['created_at'] ?? ''),
        'payment_status' => (string)($inv['payment_status'] ?? 'Unpaid'),
        'display_status' => billingPaymentDisplayStatus($inv),
        'payment_method_label' => billingPaymentMethodLabel($inv),
        'latest_payment_number' => (string)($inv['latest_payment_number'] ?? ''),
        'items' => $safeItems,
    ];
}

function billingInvoiceViewMap(array $invoices): array {
    $map = [];
    foreach ($invoices as $inv) {
        $id = (string)($inv['id'] ?? '');
        if ($id === '') continue;
        $map[$id] = billingInvoiceViewPayload($inv);
    }
    return $map;
}

function billingInvoiceViewAttr(array $inv): string {
    return htmlspecialchars(
        json_encode(billingInvoiceViewPayload($inv), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
        ENT_QUOTES,
        'UTF-8'
    );
}

function billingNormalizeSaleType(?string $type): string {
    $t = strtolower(trim((string)$type));
    $t = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $t));
    if ($t === 'credit') return 'Credit';
    if ($t === 'cheque' || $t === 'check') return 'Cheque';
    if ($t === 'gcash' || $t === 'gcash/paymaya' || $t === 'gcash / paymaya') return 'GCash';
    if ($t === 'maya' || $t === 'paymaya' || $t === 'pay maya') return 'Maya';
    if ($t === 'bank transfer' || $t === 'banktransfer') return 'Bank Transfer';
    return 'Cash';
}

function billingSaleTypeIsImmediatePaid(?string $type): bool {
    return in_array(billingNormalizeSaleType($type), ['Cash', 'GCash', 'Maya', 'Bank Transfer'], true);
}

function billingSaleTypeLabel(?string $type): string {
    $t = billingNormalizeSaleType($type);
    if ($t === 'Credit') return 'Credit / Utang';
    return $t;
}

function billingSaleTypeBadgeClass(?string $type): string {
    $t = billingNormalizeSaleType($type);
    if ($t === 'Credit') return 'badge-warning';
    if ($t === 'Cheque') return 'badge-blue';
    return 'badge-green';
}

function billingInclusiveTax(float $amount, float $rate): float {
    if ($amount <= 0 || $rate <= 0) return 0.0;
    return round($amount * $rate / (100.0 + $rate), 2);
}

function billingExclusiveTax(float $amount, float $rate): float {
    return salesOrderExclusiveTax($amount, $rate);
}

function billingGetTaxRate(mysqli $conn): float {
    $rate = 12.0;
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'sales_tax_rate' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && is_numeric($row['setting_value'])) {
            $rate = (float)$row['setting_value'];
        }
    }
    if ($rate < 0) $rate = 0.0;
    if ($rate > 100) $rate = 100.0;
    return $rate;
}

function billingCanUpdateChequeStatus(): bool {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    $role = str_replace([' ', '-'], '_', $role);
    return in_array($role, ['administrator', 'admin', 'warehouse_head', 'warehousehead', 'accounting', 'accountant'], true);
}

function billingPaymentMethodLabel(array $inv): string {
    $method = trim((string)($inv['latest_payment_method'] ?? ''));
    if ($method === '') {
        $method = billingNormalizeSaleType($inv['sale_type'] ?? 'Cash');
    }
    $method = billingNormalizePayMethod($method);
    if ($method === 'Credit') {
        return 'Credit / Utang';
    }
    if ($method === 'Check') {
        return 'Cheque';
    }
    return $method !== '' ? $method : '—';
}

function billingPaymentDisplayStatus(array $inv): string {
    $pay = (string)($inv['payment_status'] ?? 'Unpaid');
    if ($pay === 'Paid') {
        return 'Paid';
    }
    $saleType = billingNormalizeSaleType($inv['sale_type'] ?? '');
    $collection = (string)($inv['credit_collection_status'] ?? '');
    if ($saleType === 'Credit' || $collection !== '') {
        if ($collection === 'Paid') {
            return 'Paid';
        }
        $due = (string)($inv['payment_due_date'] ?? '');
        $balance = (float)($inv['remaining_balance'] ?? 0);
        if ($collection === 'Overdue' || ($saleType === 'Credit' && $balance > 0.009 && function_exists('creditDateIsPast') && creditDateIsPast($due))) {
            return 'Overdue';
        }
        if ($saleType === 'Credit') {
            return 'Pending';
        }
    }
    if (!empty($inv['has_pending_cheque'])) {
        return 'Pending';
    }
    return 'Unpaid / Outstanding';
}

function billingPaymentDisplayBadgeClass(string $status): string {
    if ($status === 'Paid') {
        return 'badge-green';
    }
    if ($status === 'Pending') {
        return 'badge-warning';
    }
    return 'badge-danger';
}

function billingInvoiceRowAttrs(array $inv): string {
    $saleType = billingNormalizeSaleType($inv['sale_type'] ?? 'Cash');
    $display = billingPaymentDisplayStatus($inv);
    $status = (string)($inv['payment_status'] ?? 'Unpaid');
    $class = $display === 'Overdue' ? ' class="credit-overdue-row"' : '';
    return $class
        . ' data-status="' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-display-status="' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-sale-type="' . htmlspecialchars($saleType, ENT_QUOTES, 'UTF-8') . '"';
}

function billingPaymentStatusBadgeHtml(array $inv): string {
    $display = billingPaymentDisplayStatus($inv);
    $cls = billingPaymentDisplayBadgeClass($display);
    $icon = 'bi-exclamation-triangle';
    if ($display === 'Paid') {
        $icon = 'bi-cash-stack';
    } elseif ($display === 'Overdue') {
        $icon = 'bi-calendar-x';
    } elseif ($display === 'Pending') {
        $icon = 'bi-hourglass-split';
    }
    return '<span class="badge ' . $cls . '"><i class="bi ' . $icon . '"></i> ' . htmlspecialchars($display) . '</span>';
}

function billingLoadPendingCheques(mysqli $conn): array {
    $rows = [];
    $cq = @$conn->query("SELECT ip.*, inv.invoice_number, inv.customer_name, inv.customer_po_number, inv.sale_type, inv.created_at AS invoice_date, inv.payment_status, inv.remaining_balance
                         FROM invoice_payments ip
                         JOIN invoices inv ON inv.id = ip.invoice_id
                         WHERE ip.cheque_status = 'Pending Clearance'
                         ORDER BY ip.id DESC");
    if ($cq) {
        while ($row = $cq->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function billingAttachPaymentMetaBatch(mysqli $conn, array &$invoices): void {
    if (!$invoices) {
        return;
    }
    $ids = [];
    foreach ($invoices as $inv) {
        $id = (int)($inv['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (!$ids) {
        return;
    }
    $idList = implode(',', array_map('intval', $ids));
    $meta = [];
    $q = @$conn->query("SELECT id, invoice_id, payment_method, cheque_status, cheque_number, bank_name, cheque_date, payment_number, payment_date, amount_paid
                        FROM invoice_payments
                        WHERE invoice_id IN ($idList)
                        ORDER BY id DESC");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $iid = (int)$row['invoice_id'];
            if (!isset($meta[$iid])) {
                $meta[$iid] = [
                    'latest_payment_method' => (string)$row['payment_method'],
                    'latest_payment_number' => (string)$row['payment_number'],
                    'latest_payment_date' => (string)$row['payment_date'],
                    'has_pending_cheque' => false,
                    'pending_cheque_id' => 0,
                    'pending_cheque_number' => '',
                    'pending_cheque_bank' => '',
                ];
            }
            if ((string)$row['cheque_status'] === 'Pending Clearance') {
                $meta[$iid]['has_pending_cheque'] = true;
                if ((int)$meta[$iid]['pending_cheque_id'] === 0) {
                    $meta[$iid]['pending_cheque_id'] = (int)$row['id'];
                    $meta[$iid]['pending_cheque_number'] = (string)($row['cheque_number'] ?? '');
                    $meta[$iid]['pending_cheque_bank'] = (string)($row['bank_name'] ?? '');
                }
            }
        }
    }
    if (function_exists('creditMonitorAttachInvoices')) {
        creditMonitorAttachInvoices($conn, $invoices);
    }
    foreach ($invoices as &$inv) {
        $iid = (int)($inv['id'] ?? 0);
        $m = $meta[$iid] ?? [];
        $inv['latest_payment_method'] = $m['latest_payment_method'] ?? billingNormalizeSaleType($inv['sale_type'] ?? 'Cash');
        if (empty($inv['latest_payment_number'])) {
            $inv['latest_payment_number'] = $m['latest_payment_number'] ?? '';
        }
        $inv['has_pending_cheque'] = !empty($m['has_pending_cheque']);
        $inv['pending_cheque_id'] = (int)($m['pending_cheque_id'] ?? 0);
        $inv['pending_cheque_number'] = (string)($m['pending_cheque_number'] ?? '');
        $inv['pending_cheque_bank'] = (string)($m['pending_cheque_bank'] ?? '');
        $inv['display_status'] = billingPaymentDisplayStatus($inv);
        $inv['payment_method_label'] = billingPaymentMethodLabel($inv);
    }
    unset($inv);
}

function billingChequeIsBounced(?string $status): bool {
    $st = trim((string)$status);
    return $st === 'Bounced/Rejected' || strcasecmp($st, 'Bounced') === 0;
}

function billingParseChequeFields(array $src, bool $required): array {
    $number = trim((string)($src['cheque_number'] ?? ''));
    $bank = trim((string)($src['bank_name'] ?? ''));
    $date = trim((string)($src['cheque_date'] ?? ''));
    if (!$required) {
        $normDate = '';
        if ($date !== '') {
            $ts = strtotime($date);
            $normDate = $ts ? date('Y-m-d', $ts) : '';
        }
        return ['cheque_number' => $number, 'bank_name' => $bank, 'cheque_date' => $normDate];
    }
    if ($number === '' || $bank === '') {
        throw new Exception('Cheque Number, Bank Name, and Cheque Date are required.');
    }
    if ($date === '') {
        throw new Exception('Cheque Date is required.');
    }
    $ts = strtotime($date);
    if (!$ts) {
        throw new Exception('Cheque Date is invalid.');
    }
    return [
        'cheque_number' => $number,
        'bank_name' => $bank,
        'cheque_date' => date('Y-m-d', $ts),
    ];
}

function billingChequeDuplicateExists(mysqli $conn, string $bankName, string $chequeNumber, int $excludePaymentId = 0): bool {
    $bankName = trim($bankName);
    $chequeNumber = trim($chequeNumber);
    if ($bankName === '' || $chequeNumber === '') {
        return false;
    }
    $sql = "SELECT id FROM invoice_payments
            WHERE TRIM(cheque_number) = ?
              AND LOWER(TRIM(bank_name)) = LOWER(?)
              AND cheque_status IN ('Pending Clearance', 'Cleared')";
    if ($excludePaymentId > 0) {
        $sql .= " AND id <> ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssi', $chequeNumber, $bankName, $excludePaymentId);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $chequeNumber, $bankName);
    }
    if ($stmt) {
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
    }
    $soSql = "SELECT id FROM sales_orders
              WHERE TRIM(cheque_number) = ?
                AND LOWER(TRIM(bank_name)) = LOWER(?)
                AND cheque_status IN ('Pending Clearance', 'Cleared')";
    $soStmt = $conn->prepare($soSql);
    if ($soStmt) {
        $soStmt->bind_param('ss', $chequeNumber, $bankName);
        $soStmt->execute();
        $found = $soStmt->get_result()->num_rows > 0;
        $soStmt->close();
        if ($found) return true;
    }
    return false;
}

function billingSyncSalesOrderChequeStatus(mysqli $conn, int $soId, string $status): void {
    if ($soId <= 0) return;
    $chk = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'cheque_status'");
    if (!$chk || $chk->num_rows === 0) return;
    $up = $conn->prepare("UPDATE sales_orders SET cheque_status = ? WHERE id = ? AND sale_type = 'Cheque'");
    if ($up) {
        $up->bind_param('si', $status, $soId);
        $up->execute();
        $up->close();
    }
}

function billingNormalizePayMethod(?string $method): string {
    $m = trim((string)$method);
    $map = [
        'check' => 'Cheque',
        'cheque' => 'Cheque',
        'cash' => 'Cash',
        'credit' => 'Credit',
        'bank transfer' => 'Bank Transfer',
        'banktransfer' => 'Bank Transfer',
        'gcash/paymaya' => 'GCash',
        'gcash / paymaya' => 'GCash',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'paymaya' => 'Maya',
        'pay maya' => 'Maya',
    ];
    $key = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $m)));
    return $map[$key] ?? ($m !== '' ? $m : 'Cash');
}

function billingAssertSellableStock(mysqli $conn, int $productId, int $qty, string $productName, ?int $onHand = null): void {
    require_once __DIR__ . '/inventory_wms_lib.php';
    require_once __DIR__ . '/product_expiry_lib.php';
    invWmsEnsureSchema($conn);
    if (function_exists('productExpiryDisposeExpired')) {
        productExpiryDisposeExpired($conn, (int)($_SESSION['user_id'] ?? 0));
    }
    $sellable = invWmsProductSellableQty($conn, $productId, $onHand);
    if ($sellable < $qty) {
        $extra = ($onHand !== null && $onHand > $sellable) ? ' Expired units are not sellable.' : '';
        throw new Exception("Insufficient sellable stock for '" . $productName . "'. Sellable: " . $sellable . ", Required: " . $qty . "." . $extra);
    }
}

function billingLoadSalesOrderItems(mysqli $conn, int $soId): array {
    $itemsQuery = $conn->prepare("SELECT soi.*, p.name AS product_name, p.quantity_in_stock FROM sales_order_items soi JOIN products p ON soi.product_id = p.id WHERE soi.sales_order_id = ?");
    $itemsQuery->bind_param("i", $soId);
    $itemsQuery->execute();
    $itemsRes = $itemsQuery->get_result();
    $items = [];
    while ($row = $itemsRes->fetch_assoc()) {
        $items[] = $row;
    }
    $itemsQuery->close();
    return $items;
}

function billingEnsureSalesStockOut(mysqli $conn, int $soId, int $uid, ?array $so = null, ?array $items = null): void {
    if ($so === null) {
        $q = $conn->prepare("SELECT * FROM sales_orders WHERE id = ? FOR UPDATE");
        $q->bind_param('i', $soId);
        $q->execute();
        $so = $q->get_result()->fetch_assoc();
        $q->close();
    }
    if (!$so) {
        throw new Exception('Sales Order not found.');
    }
    if ((string)($so['status'] ?? '') === 'Cancelled') {
        throw new Exception('Cancelled sales orders cannot be stocked out.');
    }
    if (strtolower((string)($so['stock_out_status'] ?? '')) === 'completed') {
        return;
    }
    $saleType = billingNormalizeSaleType($so['sale_type'] ?? 'Cash');
    $chequeStatus = trim((string)($so['cheque_status'] ?? ''));
    if ($saleType === 'Cheque' && $chequeStatus !== 'Cleared') {
        throw new Exception('Cheque sales cannot be stocked out until the cheque is marked Paid.');
    }
    if ($items === null) {
        $items = billingLoadSalesOrderItems($conn, $soId);
    }
    if (empty($items)) {
        throw new Exception('Sales Order has no items.');
    }
    foreach ($items as $item) {
        billingAssertSellableStock(
            $conn,
            (int)$item['product_id'],
            (int)$item['quantity'],
            (string)$item['product_name'],
            (int)$item['quantity_in_stock']
        );
    }

    require_once __DIR__ . '/inventory_wms_lib.php';
    invWmsEnsureSchema($conn);
    $mvtStmt = $conn->prepare("INSERT INTO stock_movements (product_id, batch_id, movement_type, quantity, unit_price, total_value, from_location_id, reference_number, user_id, notes, source) VALUES (?, ?, 'Stock Out (Issue)', ?, ?, ?, ?, ?, ?, ?, 'sales')");

    foreach ($items as $item) {
        $qty = (int)$item['quantity'];
        $pId = (int)$item['product_id'];
        $price = (float)$item['unit_price'];
        $locId = invWmsResolveProductLocation($conn, $pId, null);
        if (!invWmsAdjustLocationStock($conn, $pId, $locId, -$qty)) {
            throw new Exception("Insufficient location stock for product '" . $item['product_name'] . "'.");
        }
        $consumed = invWmsConsumeFifoBatches($conn, $pId, $qty, true);
        if ($consumed === null) {
            throw new Exception("Insufficient lot quantity (FEFO) for product '" . $item['product_name'] . "'.");
        }
        $ref = (string)($so['order_number'] ?? ('SO-' . $soId));
        if (empty($consumed)) {
            $totalVal = $qty * $price;
            $notes = 'Sale stock-out (no batch tracking)';
            $nullBatch = null;
            $mvtStmt->bind_param("iiiddisis", $pId, $nullBatch, $qty, $price, $totalVal, $locId, $ref, $uid, $notes);
            $mvtStmt->execute();
        } else {
            $firstBatchId = (int)$consumed[0]['batch_id'];
            $lotBits = [];
            foreach ($consumed as $c) {
                $take = (int)$c['qty'];
                $bid = (int)$c['batch_id'];
                $lotNo = trim((string)($c['manufacturer_lot'] ?? ''));
                $totalVal = $take * $price;
                $notes = 'Sale stock-out · FEFO lot ' . ($lotNo !== '' ? $lotNo : '—');
                $mvtStmt->bind_param("iiiddisis", $pId, $bid, $take, $price, $totalVal, $locId, $ref, $uid, $notes);
                $mvtStmt->execute();
                $lotBits[] = ($lotNo !== '' ? $lotNo : '—') . ' x' . $take;
            }
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId > 0) {
                $lotLabel = implode(', ', $lotBits);
                $upItem = $conn->prepare("UPDATE sales_order_items SET batch_id=?, batch_number=? WHERE id=?");
                if ($upItem) {
                    $upItem->bind_param('isi', $firstBatchId, $lotLabel, $itemId);
                    $upItem->execute();
                    $upItem->close();
                }
            }
        }
    }
    $mvtStmt->close();
    $done = 'Completed';
    $up = $conn->prepare("UPDATE sales_orders SET stock_out_status = ? WHERE id = ?");
    if ($up) {
        $up->bind_param('si', $done, $soId);
        $up->execute();
        $up->close();
    }
}

function billingFinalizeSaleInvoice(mysqli $conn, int $soId, int $uid, array $opts = []): array {
    $deductStock = array_key_exists('deduct_stock', $opts) ? (bool)$opts['deduct_stock'] : true;
    $approve = array_key_exists('approve', $opts) ? (bool)$opts['approve'] : true;

    $soQuery = $conn->prepare("SELECT * FROM sales_orders WHERE id = ? FOR UPDATE");
    $soQuery->bind_param("i", $soId);
    $soQuery->execute();
    $so = $soQuery->get_result()->fetch_assoc();
    $soQuery->close();
    if (!$so) {
        throw new Exception("Sales Order not found.");
    }
    if ((string)($so['status'] ?? '') === 'Cancelled') {
        throw new Exception("Sales Order was cancelled.");
    }

    $items = billingLoadSalesOrderItems($conn, $soId);
    if (empty($items)) {
        throw new Exception('Sales Order has no items.');
    }

    if ($deductStock) {
        billingEnsureSalesStockOut($conn, $soId, $uid, $so, $items);
    }

    if ($approve && (string)($so['status'] ?? '') === 'Pending') {
        $upSO = $conn->prepare("UPDATE sales_orders SET status = 'Approved' WHERE id = ?");
        $upSO->bind_param("i", $soId);
        $upSO->execute();
        $upSO->close();
    }

    $existingInv = $conn->prepare("SELECT id, invoice_number FROM invoices WHERE sales_order_id = ? LIMIT 1");
    $existingInv->bind_param('i', $soId);
    $existingInv->execute();
    $existing = $existingInv->get_result()->fetch_assoc();
    $existingInv->close();
    if ($existing) {
        return [
            'invoice_id' => (int)$existing['id'],
            'invoice_number' => (string)$existing['invoice_number'],
            'order_number' => (string)($so['order_number'] ?? ''),
            'customer_po_number' => (string)($so['customer_po_number'] ?? ''),
            'sale_type' => billingNormalizeSaleType($so['sale_type'] ?? 'Cash'),
            'cheque_number' => (string)($so['cheque_number'] ?? ''),
            'bank_name' => (string)($so['bank_name'] ?? ''),
            'cheque_date' => (string)($so['cheque_date'] ?? ''),
        ];
    }

    $unique = false;
    $invoiceNumber = '';
    while (!$unique) {
        $invoiceNumber = 'INV-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $chk = $conn->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
        $chk->bind_param("s", $invoiceNumber);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $unique = true;
        }
        $chk->close();
    }

    $custPo = (string)($so['customer_po_number'] ?? '');
    $saleType = billingNormalizeSaleType($so['sale_type'] ?? 'Cash');
    $soNumber = (string)($so['order_number'] ?? '');
    $customerName = trim((string)($so['customer_name'] ?? ''));
    if ($customerName === '' || $customerName === '0') {
        throw new Exception('Sales order is missing a customer name.');
    }
    $subtotal = (float)($so['subtotal'] ?? 0);
    $discountAmount = (float)($so['discount_amount'] ?? 0);
    $taxAmount = (float)($so['tax_amount'] ?? 0);
    $taxRate = (float)($so['tax_rate'] ?? 0);
    $grandTotal = (float)($so['grand_total'] ?? 0);
    $deliveryFee = (float)($so['delivery_fee'] ?? 0);
    $dueDate = (string)($so['payment_due_date'] ?? '');
    $insInv = $conn->prepare("INSERT INTO invoices (invoice_number, sales_order_id, sales_order_number, customer_name, customer_po_number, sale_type, subtotal, discount_amount, tax_amount, tax_rate, grand_total, remaining_balance, payment_status, payment_due_date, delivery_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', ?, ?)");
    if (!$insInv) {
        throw new Exception('Could not create invoice: ' . $conn->error);
    }
    $insInv->bind_param("sissssddddddsd", $invoiceNumber, $soId, $soNumber, $customerName, $custPo, $saleType, $subtotal, $discountAmount, $taxAmount, $taxRate, $grandTotal, $grandTotal, $dueDate, $deliveryFee);
    if (!$insInv->execute() || (int)$insInv->insert_id <= 0) {
        $err = $insInv->error ?: $conn->error;
        $insInv->close();
        throw new Exception('Could not save invoice: ' . $err);
    }
    $invoiceId = (int)$insInv->insert_id;
    $insInv->close();

    $insInvItem = $conn->prepare("INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, tax_rate, tax_included) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($items as $item) {
        $qty = (int)$item['quantity'];
        $price = (float)$item['unit_price'];
        $lineRate = (float)($item['tax_rate'] ?? $taxRate);
        $lineTax = array_key_exists('tax_included', $item) && $item['tax_included'] !== null && $item['tax_included'] !== ''
            ? (float)$item['tax_included']
            : billingExclusiveTax($qty * $price, $lineRate);
        $insInvItem->bind_param("iiiddd", $invoiceId, $item['product_id'], $qty, $price, $lineRate, $lineTax);
        $insInvItem->execute();
    }
    $insInvItem->close();

    createNotification($conn, "Sales Order Invoiced: " . $soNumber, "Sales order " . $soNumber . " for " . $customerName . " created invoice " . $invoiceNumber . ".", "order_status", "medium", $soNumber, "sales-orders", null, (int)($so['created_by'] ?? $uid));
    createNotification($conn, "Invoice Generated: " . $invoiceNumber, "Invoice $invoiceNumber was created automatically from Sales Order " . $soNumber . ".", "order_status", "medium", $invoiceNumber, "billing-invoices", "admin");

    return [
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'order_number' => $soNumber,
        'customer_po_number' => $custPo,
        'sale_type' => $saleType,
        'cheque_number' => (string)($so['cheque_number'] ?? ''),
        'bank_name' => (string)($so['bank_name'] ?? ''),
        'cheque_date' => (string)($so['cheque_date'] ?? ''),
    ];
}

function billingNextPaymentNumber(mysqli $conn): string {
    $unique = false;
    $paymentNumber = '';
    while (!$unique) {
        $paymentNumber = 'PAY-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $chk = $conn->prepare('SELECT id FROM invoice_payments WHERE payment_number = ? LIMIT 1');
        $chk->bind_param('s', $paymentNumber);
        $chk->execute();
        $unique = $chk->get_result()->num_rows === 0;
        $chk->close();
    }
    return $paymentNumber;
}

function billingPaymentReceiptHref(string $paymentNumber): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = preg_match('#/(warehouse_head|purchaser|admin)/[^/]+$#', $script) ? '../' : '';
    return $base . 'print_payment_receipt.php?payment_number=' . rawurlencode($paymentNumber);
}

function billingCustomerInvoiceHref(string $invoiceNumber): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = preg_match('#/(warehouse_head|purchaser|admin)/[^/]+$#', $script) ? '../' : '';
    return $base . 'print_invoice.php?invoice_number=' . rawurlencode($invoiceNumber);
}

function billingRequestWantsJson(): bool {
    $xr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($xr === 'xmlhttprequest') {
        return true;
    }
    return strpos(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false;
}

function billingJsonExit(array $payload, int $code = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

function billingRedirectToPrintDoc(string $href): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: ' . $href, true, 303);
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"></head><body>';
    echo '<script>window.location.replace(' . json_encode($href) . ');</script>';
    echo 'Opening customer invoice… <a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">Continue</a></body></html>';
    exit;
}

function billingIssueOfficialReceipt(mysqli $conn, array $inv, float $amount, string $method, string $notes = '', string $bankName = '', string $chequeNumber = '', ?string $chequeStatus = null, ?string $chequeDate = null, ?float $cashTendered = null, ?float $cashChange = null, ?string $walletName = null, ?string $walletNumber = null, ?string $referenceNumber = null): string {
    $paymentNumber = billingNextPaymentNumber($conn);
    $invoiceId = (int)$inv['id'];
    $chequeDateVal = ($chequeDate !== null && trim($chequeDate) !== '') ? trim($chequeDate) : null;
    $walletNameVal = ($walletName !== null && trim($walletName) !== '') ? trim($walletName) : null;
    $walletNumberVal = ($walletNumber !== null && trim($walletNumber) !== '') ? trim($walletNumber) : null;
    $referenceVal = ($referenceNumber !== null && trim($referenceNumber) !== '') ? trim($referenceNumber) : null;
    $storeCash = billingNormalizeSaleType($method) === 'Cash' && $cashTendered !== null;
    if ($storeCash) {
        $insPay = $conn->prepare('INSERT INTO invoice_payments (invoice_id, payment_number, amount_paid, cash_tendered, cash_change, payment_method, notes, bank_name, cheque_number, cheque_status, cheque_date, wallet_name, wallet_number, reference_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insPay->bind_param('isdddsssssssss', $invoiceId, $paymentNumber, $amount, $cashTendered, $cashChange, $method, $notes, $bankName, $chequeNumber, $chequeStatus, $chequeDateVal, $walletNameVal, $walletNumberVal, $referenceVal);
    } else {
        $insPay = $conn->prepare('INSERT INTO invoice_payments (invoice_id, payment_number, amount_paid, payment_method, notes, bank_name, cheque_number, cheque_status, cheque_date, wallet_name, wallet_number, reference_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insPay->bind_param('isdsssssssss', $invoiceId, $paymentNumber, $amount, $method, $notes, $bankName, $chequeNumber, $chequeStatus, $chequeDateVal, $walletNameVal, $walletNumberVal, $referenceVal);
    }
    $insPay->execute();
    $insPay->close();
    // Pending / bounced cheques must not reduce the invoice balance.
    if ($chequeStatus === null || $chequeStatus === '') {
        billingApplyPaymentToInvoice($conn, $inv, $amount);
    }
    return $paymentNumber;
}

function billingApplyPaymentToInvoice(mysqli $conn, array $inv, float $amount): array {
    $newBalance = round((float)$inv['remaining_balance'] - $amount, 2);
    if ($newBalance < 0) $newBalance = 0;
    $status = 'Unpaid';
    if ($newBalance <= 0) {
        $status = 'Paid';
        $newBalance = 0;
    } elseif ($newBalance < (float)$inv['grand_total']) {
        $status = 'Partially Paid';
    }
    $id = (int)$inv['id'];
    $up = $conn->prepare("UPDATE invoices SET remaining_balance = ?, payment_status = ? WHERE id = ?");
    $up->bind_param("dsi", $newBalance, $status, $id);
    $up->execute();
    $up->close();
    $soId = (int)($inv['sales_order_id'] ?? 0);
    if ($soId > 0) {
        salesOrderSyncCreditStatus($conn, $soId, $newBalance);
    }
    if (function_exists('creditMonitorNotePayment')) {
        creditMonitorNotePayment($conn, $inv, $amount, $newBalance, $status);
    }
    return ['remaining_balance' => $newBalance, 'payment_status' => $status];
}

function billingPendingChequeTotal(mysqli $conn, int $invoiceId): float {
    if ($invoiceId <= 0) {
        return 0.0;
    }
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid),0) AS pending
        FROM invoice_payments
        WHERE invoice_id = ?
          AND LOWER(COALESCE(payment_method,'')) = 'cheque'
          AND cheque_status = 'Pending Clearance'");
    if (!$stmt) {
        return 0.0;
    }
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return round((float)($row['pending'] ?? 0), 2);
}

require_once __DIR__ . '/billing_sales_ui.php';

if ($conn instanceof mysqli) {
    ensureBillingSchema($conn);
    ensureProductPricingSchema($conn);
    salesOrderEnsureSchema($conn);
    creditMonitorEnsureSchema($conn);
}

// AJAX/API create sales order (Admin / Warehouse Head / Sales)
if (isset($_POST['create_sales_order'])) {
    $roleKey = strtolower(trim((string)($_SESSION['role'] ?? '')));
    $canCreateSale = in_array($roleKey, ['administrator', 'admin', 'warehouse_head', 'warehouse head', 'sales_representative', 'sales', 'cashier'], true);
    $wantsJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    $respond = function (array $payload, int $code = 200) use ($wantsJson) {
        if ($wantsJson) {
            http_response_code($code);
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }
        return $payload;
    };

    if (!$canCreateSale) {
        if ($wantsJson) $respond(['success' => false, 'message' => 'Not allowed to create sales orders'], 403);
        $billing_alert = '<div class="alert alert-danger">Not allowed to create sales orders.</div>';
    } else {
        $customerName = salesOrderComposeCustomerName($_POST);
        $customerFirstName = trim((string)($_POST['customer_first_name'] ?? ''));
        $customerLastName = trim((string)($_POST['customer_last_name'] ?? ''));
        $customerPo = '';
        $saleType = billingNormalizeSaleType($_POST['sale_type'] ?? 'Cash');
        $discountPercent = (float)($_POST['discount_percent'] ?? 0);
        $dueDate = trim($_POST['due_date'] ?? '');
        if ($saleType !== 'Credit' && $dueDate === '') {
            $dueDate = date('Y-m-d');
        }
        $productIds = $_POST['product_ids'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $taxRate = billingGetTaxRate($conn);
        $hasSplitName = array_key_exists('customer_first_name', $_POST) || array_key_exists('customer_last_name', $_POST);

        if ($hasSplitName && ($customerFirstName === '' || $customerLastName === '')) {
            if ($wantsJson) $respond(['success' => false, 'message' => 'First Name and Last Name are required'], 400);
            $billing_alert = '<div class="alert alert-danger">First Name and Last Name are required.</div>';
        } elseif ($customerName === '' || empty($productIds) || ($saleType === 'Credit' && $dueDate === '')) {
            $needMsg = $saleType === 'Credit'
                ? 'Customer, payment due date, and items are required'
                : 'Customer and items are required';
            if ($wantsJson) $respond(['success' => false, 'message' => $needMsg], 400);
            $billing_alert = '<div class="alert alert-danger">' . htmlspecialchars($needMsg) . '.</div>';
        } elseif ($saleType === 'Credit' && (empty($_POST['credit_ack']) || trim((string)($_POST['credit_terms'] ?? '')) === '')) {
            if ($wantsJson) $respond(['success' => false, 'message' => 'Credit sales require a generated credit agreement/contract and terms.'], 400);
            $billing_alert = '<div class="alert alert-danger">Credit sales require a generated credit agreement/contract and terms.</div>';
        } else {
            $conn->begin_transaction();
            try {
                $unique = false;
                $soNumber = '';
                while (!$unique) {
                    $soNumber = 'SO-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    $chk = $conn->prepare("SELECT id FROM sales_orders WHERE order_number = ? LIMIT 1");
                    $chk->bind_param("s", $soNumber);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) $unique = true;
                    $chk->close();
                }

                $subtotal = 0.00;
                $itemsData = [];
                for ($i = 0; $i < count($productIds); $i++) {
                    $pId = (int)$productIds[$i];
                    $qty = (int)($quantities[$i] ?? 0);
                    if ($pId <= 0 || $qty <= 0) continue;
                    // Always load approved selling_price from DB — never trust client-posted prices
                    $pStmt = $conn->prepare("SELECT name, selling_price, quantity_in_stock, status, expiry_date FROM products WHERE id = ? LIMIT 1");
                    $pStmt->bind_param("i", $pId);
                    $pStmt->execute();
                    $pRes = $pStmt->get_result()->fetch_assoc();
                    $pStmt->close();
                    if (!$pRes) throw new Exception("Product not found.");
                    if ((float)$pRes['selling_price'] <= 0) throw new Exception($pRes['name'] . ' has no selling price (complete markup/stock-in first).');
                    billingAssertSellableStock($conn, $pId, $qty, (string)$pRes['name'], (int)$pRes['quantity_in_stock']);
                    $price = (float)$pRes['selling_price'];
                    $lineAmount = $qty * $price;
                    $lineTax = billingExclusiveTax($lineAmount, $taxRate);
                    $subtotal += $lineAmount;
                    $fifoBatchId = null;
                    $fifoBatchNumber = '';
                    require_once __DIR__ . '/inventory_wms_lib.php';
                    invWmsEnsureSchema($conn);
                    $fifo = invWmsGetFifoBatches($conn, $pId);
                    if (!empty($fifo[0])) {
                        $fifoBatchId = (int)$fifo[0]['id'];
                        $fifoBatchNumber = trim((string)($fifo[0]['manufacturer_lot'] ?? ''));
                    }
                    $itemsData[] = [
                        'product_id' => $pId,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'batch_id' => $fifoBatchId,
                        'batch_number' => $fifoBatchNumber,
                        'tax_rate' => $taxRate,
                        'tax_included' => $lineTax,
                    ];
                }
                if (empty($itemsData)) throw new Exception('At least one valid item is required.');

                $cheque = billingParseChequeFields($_POST, $saleType === 'Cheque');
                if ($saleType === 'Cheque' && billingChequeDuplicateExists($conn, $cheque['bank_name'], $cheque['cheque_number'])) {
                    throw new Exception('This cheque number is already recorded for this bank.');
                }
                $chequeNumber = $cheque['cheque_number'];
                $bankName = $cheque['bank_name'];
                $chequeDate = $cheque['cheque_date'] !== '' ? $cheque['cheque_date'] : null;
                $chequeStatus = $saleType === 'Cheque' ? 'Pending Clearance' : '';
                $walletName = '';
                $walletNumber = '';
                $referenceNumber = '';
                if ($saleType === 'GCash') {
                    $walletName = trim((string)($_POST['gcash_account_name'] ?? ''));
                    $walletNumber = trim((string)($_POST['gcash_account_number'] ?? ''));
                    $referenceNumber = trim((string)($_POST['gcash_reference_number'] ?? ''));
                    if ($walletName === '' || $walletNumber === '' || $referenceNumber === '') {
                        throw new Exception('GCash Name, GCash Number, and Reference Number are required.');
                    }
                } elseif ($saleType === 'Maya') {
                    $walletName = trim((string)($_POST['maya_account_name'] ?? ''));
                    $walletNumber = trim((string)($_POST['maya_account_number'] ?? ''));
                    $referenceNumber = trim((string)($_POST['maya_reference_number'] ?? ''));
                    if ($walletName === '' || $walletNumber === '' || $referenceNumber === '') {
                        throw new Exception('Maya Name, Maya Number, and Reference Number are required.');
                    }
                }

                $discountPercent = max(0, min(100, $discountPercent));
                $discountAmount = $subtotal * ($discountPercent / 100);
                $deliveryFee = max(0, round((float)($_POST['delivery_fee'] ?? 0), 2));
                $totals = salesOrderComputeTotals($subtotal, $discountAmount, $taxRate, $deliveryFee);
                $discountAmount = $totals['discount_amount'];
                $taxAmount = $totals['tax_amount'];
                $grandTotal = $totals['grand_total'];
                $stockOutStatus = 'Pending';

                $insSO = $conn->prepare("INSERT INTO sales_orders (order_number, customer_name, customer_po_number, sale_type, subtotal, discount_amount, tax_amount, grand_total, tax_rate, status, payment_due_date, created_by, cheque_number, bank_name, cheque_date, cheque_status, delivery_fee, discount_percent, stock_out_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insSO->bind_param("ssssdddddsissssdds", $soNumber, $customerName, $customerPo, $saleType, $subtotal, $discountAmount, $taxAmount, $grandTotal, $taxRate, $dueDate, $userId, $chequeNumber, $bankName, $chequeDate, $chequeStatus, $deliveryFee, $discountPercent, $stockOutStatus);
                $insSO->execute();
                $salesOrderId = (int)$insSO->insert_id;
                $insSO->close();

                $insItemWithBatch = $conn->prepare("INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price, batch_id, batch_number, tax_rate, tax_included) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insItemNoBatch = $conn->prepare("INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price, batch_number, tax_rate, tax_included) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($itemsData as $item) {
                    $lineRate = (float)$item['tax_rate'];
                    $lineTax = (float)$item['tax_included'];
                    if (!empty($item['batch_id'])) {
                        $bid = (int)$item['batch_id'];
                        $bnum = (string)$item['batch_number'];
                        $insItemWithBatch->bind_param("iiidisdd", $salesOrderId, $item['product_id'], $item['quantity'], $item['unit_price'], $bid, $bnum, $lineRate, $lineTax);
                        $insItemWithBatch->execute();
                    } else {
                        $bnum = (string)$item['batch_number'];
                        $insItemNoBatch->bind_param("iiidsdd", $salesOrderId, $item['product_id'], $item['quantity'], $item['unit_price'], $bnum, $lineRate, $lineTax);
                        $insItemNoBatch->execute();
                    }
                }
                $insItemWithBatch->close();
                $insItemNoBatch->close();

                $agreementNumber = '';
                if ($saleType === 'Credit') {
                    $agreement = salesOrderCreateCreditAgreement(
                        $conn,
                        $salesOrderId,
                        $customerName,
                        $grandTotal,
                        trim((string)($_POST['credit_terms'] ?? '')),
                        $dueDate,
                        $userId
                    );
                    $agreementNumber = (string)($agreement['agreement_number'] ?? '');
                }

                $isCheque = ($saleType === 'Cheque');
                $done = billingFinalizeSaleInvoice($conn, $salesOrderId, $userId, [
                    'deduct_stock' => !$isCheque,
                    'approve' => !$isCheque,
                ]);
                $invNum = (string)($done['invoice_number'] ?? '');
                $payNum = '';
                $postedPaid = round(max(0, (float)($_POST['amount_paid'] ?? 0)), 2);
                if (!empty($done['invoice_id'])) {
                    $invRow = $conn->query('SELECT * FROM invoices WHERE id=' . (int)$done['invoice_id'] . ' LIMIT 1');
                    $invAssoc = $invRow ? $invRow->fetch_assoc() : null;
                    $grandPaid = $invAssoc ? (float)$invAssoc['grand_total'] : 0;
                    $payAmount = $postedPaid > 0 ? $postedPaid : $grandPaid;
                    if ($invAssoc && billingSaleTypeIsImmediatePaid($saleType)) {
                        $receiptNote = $saleType === 'Cash' ? 'Auto-issued on cash sale' : ('Auto-issued on ' . $saleType . ' sale');
                        $cashTendered = null;
                        $cashChange = null;
                        if ($saleType === 'Cash') {
                            $cashTendered = round((float)($_POST['cash_tendered'] ?? 0), 2);
                            if ($cashTendered + 0.009 < $grandPaid) {
                                throw new RuntimeException('Cash Received cannot be less than the Total Amount');
                            }
                            $cashChange = round($cashTendered - $grandPaid, 2);
                            $payAmount = $grandPaid;
                        }
                        $payNum = billingIssueOfficialReceipt($conn, $invAssoc, $payAmount, $saleType, $receiptNote, '', '', null, null, $cashTendered, $cashChange, $walletName, $walletNumber, $referenceNumber);
                    } elseif ($invAssoc && $isCheque) {
                        $payNum = billingIssueOfficialReceipt(
                            $conn,
                            $invAssoc,
                            $payAmount,
                            'Cheque',
                            'Cheque received — pending bank clearance',
                            $bankName,
                            $chequeNumber,
                            'Pending Clearance',
                            $chequeDate
                        );
                    } elseif ($invAssoc && $saleType === 'Credit' && $postedPaid > 0) {
                        $payNum = billingIssueOfficialReceipt($conn, $invAssoc, $postedPaid, 'Credit', 'Payment received on credit sale');
                    }
                    if ($saleType === 'Credit') {
                        $invSafe = is_array($invAssoc) ? $invAssoc : [];
                        creditMonitorBind(
                            $conn,
                            $salesOrderId,
                            (int)$done['invoice_id'],
                            (string)($invSafe['invoice_number'] ?? $invNum),
                            $customerName,
                            (float)($invSafe['grand_total'] ?? $grandTotal),
                            (string)($invSafe['payment_due_date'] ?? $dueDate),
                            date('Y-m-d')
                        );
                    }
                }
                $conn->commit();
                creditMonitorSweep($conn);

                $billing_print_invoice = $invNum;
                $billing_print_receipt = $payNum;
                if ($isCheque) {
                    $msg = "Sales order $soNumber created with status Pending. Cheque is waiting for approval. Inventory will be deducted after the cheque is marked Paid.";
                } elseif ($payNum !== '') {
                    $msg = "Sales order $soNumber created. Customer invoice $invNum and official receipt $payNum are ready to print. Stock has been deducted.";
                } else {
                    $msg = "Sales order $soNumber invoiced as $invNum (Credit). Credit agreement $agreementNumber created. Official receipt will be issued when the customer pays.";
                }
                if ($wantsJson) $respond([
                    'success' => true,
                    'order_number' => $soNumber,
                    'sales_order_id' => $salesOrderId,
                    'invoice_number' => $invNum,
                    'invoice_id' => (int)($done['invoice_id'] ?? 0),
                    'payment_number' => $payNum,
                    'agreement_number' => $agreementNumber,
                    'sale_type' => $saleType,
                    'message' => $msg,
                ]);
                $billing_alert = '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
            } catch (Throwable $e) {
                $conn->rollback();
                if ($wantsJson) $respond(['success' => false, 'message' => $e->getMessage()], 400);
                $billing_alert = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
}

foreach (['approve_sales_order', 'cancel_sales_order', 'record_payment'] as $__billingAction) {
    if (!isset($_POST[$__billingAction])) {
        continue;
    }
    if (!function_exists('clarenzon_csrf_validate_request') || !clarenzon_csrf_validate_request()) {
        if (billingRequestWantsJson()) {
            billingJsonExit(['success' => false, 'message' => 'Your session expired. Please try again.'], 403);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(403);
        echo 'Your session expired. Please try again.';
        exit;
    }
    $__billingRole = function_exists('normalize_user_role')
        ? normalize_user_role((string)($_SESSION['role'] ?? ''))
        : strtolower(trim((string)($_SESSION['role'] ?? '')));
    // Cashier processes sales and payments; cheque clearing stays with Admin / Warehouse Head.
    $__billingAllowed = billingCanUpdateChequeStatus()
        || $__billingRole === 'cashier'
        || ($__billingAction !== 'record_payment' && in_array($__billingRole, ['sales_marketing', 'sales_representative', 'sales'], true));
    if (!$__billingAllowed) {
        if (billingRequestWantsJson()) {
            billingJsonExit(['success' => false, 'message' => 'You do not have permission to perform this billing action.'], 403);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(403);
        echo 'You do not have permission to perform this billing action.';
        exit;
    }
}
unset($__billingAction, $__billingRole, $__billingAllowed);

if (isset($_POST['approve_sales_order'])) {
    $soId = (int)$_POST['sales_order_id'];
    $userId = (int)($_SESSION['user_id'] ?? 1);
    $conn->begin_transaction();
    try {
        $peek = $conn->prepare("SELECT sale_type, cheque_status, status FROM sales_orders WHERE id = ? LIMIT 1");
        $peek->bind_param('i', $soId);
        $peek->execute();
        $peekRow = $peek->get_result()->fetch_assoc();
        $peek->close();
        if (!$peekRow) throw new Exception('Sales Order not found.');
        $saleTypePeek = billingNormalizeSaleType($peekRow['sale_type'] ?? 'Cash');
        if ($saleTypePeek === 'Cheque' && trim((string)($peekRow['cheque_status'] ?? '')) !== 'Cleared') {
            throw new Exception('Cheque sales orders stay Pending until the cheque is marked Paid.');
        }
        $done = billingFinalizeSaleInvoice($conn, $soId, $userId);
        $payNum = '';
        $saleType = billingNormalizeSaleType($done['sale_type'] ?? 'Cash');
        if (!empty($done['invoice_id'])) {
            $invRow = $conn->query('SELECT * FROM invoices WHERE id=' . (int)$done['invoice_id'] . ' LIMIT 1');
            $invAssoc = $invRow ? $invRow->fetch_assoc() : null;
            if ($invAssoc && billingSaleTypeIsImmediatePaid($saleType)) {
                $hasPay = $conn->query('SELECT id FROM invoice_payments WHERE invoice_id=' . (int)$done['invoice_id'] . ' LIMIT 1');
                if (!$hasPay || $hasPay->num_rows === 0) {
                    $receiptNote = $saleType === 'Cash' ? 'Auto-issued on cash sale' : ('Auto-issued on ' . $saleType . ' sale');
                    $payNum = billingIssueOfficialReceipt($conn, $invAssoc, (float)$invAssoc['grand_total'], $saleType, $receiptNote);
                }
            }
        }
        $conn->commit();
        $billing_print_invoice = (string)($done['invoice_number'] ?? '');
        $billing_print_receipt = $payNum;
        $billing_alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Customer invoice <strong>' . htmlspecialchars($done['invoice_number']) . '</strong>' . ($payNum !== '' ? ' and official receipt <strong>' . htmlspecialchars($payNum) . '</strong>' : '') . ' created for ' . htmlspecialchars($done['order_number']) . '.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } catch (Exception $e) {
        $conn->rollback();
        $billing_alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Invoice failed: ' . htmlspecialchars($e->getMessage()) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

if (isset($_POST['cancel_sales_order'])) {
    $soId = (int)$_POST['sales_order_id'];
    
    $creatorId = 0;
    $soNum = '';
    $soQuery = $conn->prepare("SELECT created_by, order_number FROM sales_orders WHERE id = ?");
    if ($soQuery) {
        $soQuery->bind_param("i", $soId);
        $soQuery->execute();
        $soData = $soQuery->get_result()->fetch_assoc();
        if ($soData) {
            $creatorId = (int)$soData['created_by'];
            $soNum = $soData['order_number'];
        }
        $soQuery->close();
    }
    
    $upSO = $conn->prepare("UPDATE sales_orders SET status = 'Cancelled' WHERE id = ? AND status = 'Pending'");
    $upSO->bind_param("i", $soId);
    if ($upSO->execute()) {
        if ($creatorId > 0) {
            createNotification($conn, "Sales Order Cancelled: " . $soNum, "Your Sales Order " . $soNum . " has been cancelled.", "order_status", "medium", $soNum, "sales-orders", null, $creatorId);
        }
        $billing_alert = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-info-circle-fill"></i> Sales Order cancelled.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } else {
        $billing_alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to cancel Sales Order.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    $upSO->close();
}

if (isset($_POST['record_credit_followup'])) {
    try {
        creditMonitorRecordFollowUp(
            $conn,
            (int)($_POST['credit_agreement_id'] ?? 0),
            (string)($_POST['followup_note'] ?? ''),
            (string)($_POST['followup_remind_on'] ?? '')
        );
        $billing_alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill"></i> Follow-up saved on the credit account.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } catch (Throwable $e) {
        $billing_alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($e->getMessage()) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

if (isset($_POST['record_payment'])) {
    $invoiceId = (int)$_POST['invoice_id'];
    $amountPaid = (float)$_POST['amount_paid'];
    $payMethod = billingNormalizePayMethod($_POST['payment_method'] ?? 'Cash');
    $notes = trim($_POST['payment_notes'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $chequeNumber = trim($_POST['cheque_number'] ?? '');
    $chequeDateIn = trim($_POST['cheque_date'] ?? '');
    $wantsJson = billingRequestWantsJson();

    if ($amountPaid <= 0) {
        if ($wantsJson) {
            billingJsonExit(['success' => false, 'message' => 'Payment amount must be greater than zero.'], 400);
        }
        $billing_alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Payment amount must be greater than zero.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } elseif ($payMethod === 'Credit') {
        if ($wantsJson) {
            billingJsonExit(['success' => false, 'message' => 'Credit is the sale type (pay later). Record Cash or Cheque when the customer actually pays.'], 400);
        }
        $billing_alert = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-info-circle-fill"></i> Credit is the sale type (pay later). Record Cash or Cheque when the customer actually pays.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } else {
        $conn->begin_transaction();
        try {
            $invQuery = $conn->prepare("SELECT * FROM invoices WHERE id = ? FOR UPDATE");
            $invQuery->bind_param("i", $invoiceId);
            $invQuery->execute();
            $inv = $invQuery->get_result()->fetch_assoc();
            $invQuery->close();

            if (!$inv) {
                throw new Exception("Invoice not found.");
            }

            if ($amountPaid > $inv['remaining_balance']) {
                throw new Exception("Payment amount (₱" . number_format($amountPaid, 2) . ") cannot exceed the remaining balance (₱" . number_format($inv['remaining_balance'], 2) . ").");
            }

            $pendingCheques = billingPendingChequeTotal($conn, $invoiceId);
            $available = round((float)$inv['remaining_balance'] - $pendingCheques, 2);
            if ($available < 0) {
                $available = 0.0;
            }
            if ($amountPaid > $available) {
                throw new Exception("Payment amount (₱" . number_format($amountPaid, 2) . ") cannot exceed the remaining balance (₱" . number_format($available, 2) . ").");
            }

            $isCheque = ($payMethod === 'Cheque');
            $chequeDate = null;
            if ($isCheque) {
                $cheque = billingParseChequeFields([
                    'cheque_number' => $chequeNumber,
                    'bank_name' => $bankName,
                    'cheque_date' => $chequeDateIn,
                ], true);
                $bankName = $cheque['bank_name'];
                $chequeNumber = $cheque['cheque_number'];
                $chequeDate = $cheque['cheque_date'];
                if (billingChequeDuplicateExists($conn, $bankName, $chequeNumber)) {
                    throw new Exception('This cheque number is already recorded for this bank.');
                }
            }

            $chequeStatus = $isCheque ? 'Pending Clearance' : '';
            $paymentNumber = billingIssueOfficialReceipt($conn, $inv, $amountPaid, $payMethod, $notes, $bankName, $chequeNumber, $isCheque ? $chequeStatus : '', $chequeDate);
            $billing_print_receipt = $paymentNumber;
            $billing_print_invoice = (string)$inv['invoice_number'];

            if ($isCheque) {
                createNotification($conn, "Cheque Pending: " . $paymentNumber, "Cheque {$chequeNumber} ({$bankName}) for Invoice " . $inv['invoice_number'] . " awaits bank clearance. Amount: ₱" . number_format($amountPaid, 2) . ".", "order_status", "medium", $paymentNumber, "billing-invoices", "admin");
            } else {
                createNotification($conn, "Payment Received: " . $paymentNumber, "Payment of ₱" . number_format($amountPaid, 2) . " received for Invoice " . $inv['invoice_number'] . " via " . $payMethod . ".", "order_status", "low", $paymentNumber, "billing-invoices", "admin");
            }
            $conn->commit();
            $invoiceUrl = billingCustomerInvoiceHref((string)$inv['invoice_number']);
            if ($wantsJson) {
                // billingJsonExit() discards the realtime output buffer, so report the success to the hook first.
                if (function_exists('clarenzon_realtime_after_response')) {
                    clarenzon_realtime_after_response('{"success":true}');
                }
                billingJsonExit([
                    'success' => true,
                    'payment_number' => $paymentNumber,
                    'invoice_number' => (string)$inv['invoice_number'],
                    'invoice_url' => $invoiceUrl,
                    'receipt_url' => billingPaymentReceiptHref($paymentNumber),
                ]);
            }
            billingRedirectToPrintDoc($invoiceUrl);
        } catch (Exception $e) {
            $conn->rollback();
            if ($wantsJson) {
                billingJsonExit(['success' => false, 'message' => $e->getMessage()], 400);
            }
            $billing_alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill"></i> Failed to record payment: ' . htmlspecialchars($e->getMessage()) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }
}

if (isset($_POST['clear_cheque']) || isset($_POST['mark_cheque_paid']) || isset($_POST['bounce_cheque'])) {
    $payId = (int)($_POST['payment_id'] ?? 0);
    $doClear = isset($_POST['clear_cheque']) || isset($_POST['mark_cheque_paid']);
    if (!billingCanUpdateChequeStatus()) {
        $billing_alert = '<div class="alert alert-danger">Not authorized to update cheque status.</div>';
    } elseif ($payId <= 0) {
        $billing_alert = '<div class="alert alert-danger">Invalid cheque payment.</div>';
    } else {
        $conn->begin_transaction();
        try {
            $pq = $conn->prepare("SELECT * FROM invoice_payments WHERE id = ? FOR UPDATE");
            $pq->bind_param("i", $payId);
            $pq->execute();
            $pay = $pq->get_result()->fetch_assoc();
            $pq->close();
            if (!$pay) throw new Exception('Payment not found.');
            $st = (string)($pay['cheque_status'] ?? '');
            if ($st !== 'Pending Clearance') {
                throw new Exception('This cheque is not waiting for clearance.');
            }
            $invId = (int)$pay['invoice_id'];
            $invQ = $conn->prepare("SELECT * FROM invoices WHERE id = ? FOR UPDATE");
            $invQ->bind_param("i", $invId);
            $invQ->execute();
            $inv = $invQ->get_result()->fetch_assoc();
            $invQ->close();
            if (!$inv) throw new Exception('Invoice not found.');

            if ($doClear) {
                $newStatus = 'Cleared';
                $up = $conn->prepare("UPDATE invoice_payments SET cheque_status=? WHERE id=?");
                $up->bind_param("si", $newStatus, $payId);
                $up->execute();
                $up->close();
                $soIdClear = (int)($inv['sales_order_id'] ?? 0);
                billingSyncSalesOrderChequeStatus($conn, $soIdClear, $newStatus);
                if ($soIdClear > 0) {
                    billingEnsureSalesStockOut($conn, $soIdClear, (int)($_SESSION['user_id'] ?? 0));
                    $appr = $conn->prepare("UPDATE sales_orders SET status = 'Approved' WHERE id = ? AND status = 'Pending'");
                    if ($appr) {
                        $appr->bind_param('i', $soIdClear);
                        $appr->execute();
                        $appr->close();
                    }
                }
                $applied = billingApplyPaymentToInvoice($conn, $inv, (float)$pay['amount_paid']);
                createNotification($conn, "Cheque Cleared: " . $pay['payment_number'], "Cheque " . ($pay['cheque_number'] ?? '') . " cleared. Invoice " . $inv['invoice_number'] . " is now " . $applied['payment_status'] . ".", "order_status", "low", $pay['payment_number'], "billing-invoices", "admin");
                $conn->commit();
                $billing_alert = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-bank"></i> Cheque marked as Paid. Amount applied. Payment method remains Cheque. Invoice status: <strong>' . htmlspecialchars($applied['payment_status']) . '</strong>.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $newStatus = 'Bounced/Rejected';
                $up = $conn->prepare("UPDATE invoice_payments SET cheque_status=? WHERE id=?");
                $up->bind_param("si", $newStatus, $payId);
                $up->execute();
                $up->close();
                billingSyncSalesOrderChequeStatus($conn, (int)($inv['sales_order_id'] ?? 0), $newStatus);
                createNotification($conn, "Bounced/Rejected Cheque: " . $pay['payment_number'], "Cheque " . ($pay['cheque_number'] ?? '') . " was rejected. Invoice " . $inv['invoice_number'] . " remains unpaid.", "order_status", "high", $pay['payment_number'], "billing-invoices", "admin");
                $conn->commit();
                $billing_alert = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-octagon"></i> Cheque marked Bounced/Rejected. The amount stays unpaid.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        } catch (Exception $e) {
            $conn->rollback();
            $billing_alert = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

$__soJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);
$__soDirect = strcasecmp(basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')), 'billing_api.php') === 0;

if (($__soDirect || $__soJson) && (($_GET['action'] ?? '') === 'credit_collection_history')) {
    $agreementId = (int)($_GET['id'] ?? 0);
    header('Content-Type: application/json');
    if ($agreementId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Credit arrangement id is required']);
        exit;
    }
    $events = creditMonitorLoadHistory($conn, $agreementId);
    echo json_encode(['success' => true, 'events' => $events]);
    exit;
}

if (($__soDirect || $__soJson) && ((($_GET['action'] ?? '') === 'get_sales_order') || isset($_POST['get_sales_order']))) {
    $soId = (int)($_GET['id'] ?? $_POST['id'] ?? $_POST['sales_order_id'] ?? 0);
    header('Content-Type: application/json');
    if ($soId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sales order id is required']);
        exit;
    }
    $detail = salesOrderLoadDetail($conn, $soId);
    if (!$detail) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Sales order not found']);
        exit;
    }
    $ord = $detail['order'];
    $ord['sale_type'] = billingNormalizeSaleType($ord['sale_type'] ?? 'Cash');
    $ord['sale_type_label'] = billingSaleTypeLabel($ord['sale_type']);
    $ord['display_status'] = salesOrderDisplayStatus($ord);
    $ord['payment_label'] = salesOrderPaymentLabel($ord);
    $payments = $detail['payments'] ?? [];
    foreach ($payments as &$payRow) {
        $payRow['payment_method'] = billingNormalizePayMethod($payRow['payment_method'] ?? '');
    }
    unset($payRow);
    echo json_encode([
        'success' => true,
        'order' => $ord,
        'items' => $detail['items'],
        'payments' => $payments,
    ]);
    exit;
}

if (isset($_POST['process_sales_stock_out'])) {
    $wantsJson = $__soJson || $__soDirect;
    $respondSo = function (array $payload, int $code = 200) use ($wantsJson) {
        if ($wantsJson) {
            http_response_code($code);
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }
        return $payload;
    };
    $soId = (int)($_POST['sales_order_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($soId <= 0) {
        $respondSo(['success' => false, 'message' => 'Sales order id is required'], 400);
    } else {
        $conn->begin_transaction();
        try {
            billingEnsureSalesStockOut($conn, $soId, $userId);
            $soRow = $conn->query('SELECT sale_type, cheque_status, status FROM sales_orders WHERE id=' . $soId . ' LIMIT 1');
            $soAssoc = $soRow ? $soRow->fetch_assoc() : null;
            $saleType = billingNormalizeSaleType($soAssoc['sale_type'] ?? 'Cash');
            if ($saleType !== 'Cheque' || trim((string)($soAssoc['cheque_status'] ?? '')) === 'Cleared') {
                if ((string)($soAssoc['status'] ?? '') === 'Pending') {
                    $ap = $conn->prepare("UPDATE sales_orders SET status = 'Approved' WHERE id = ?");
                    $ap->bind_param('i', $soId);
                    $ap->execute();
                    $ap->close();
                }
            }
            $conn->commit();
            $respondSo(['success' => true, 'message' => 'Inventory deducted for this sales order.']);
        } catch (Throwable $e) {
            $conn->rollback();
            $respondSo(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}

if ($conn instanceof mysqli) {
    creditMonitorSweep($conn);
}
