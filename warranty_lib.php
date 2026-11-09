<?php
/**
 * Product warranty certificates for completed sales.
 *
 * Flow: authorized users mark products warranty-eligible with a configured duration →
 * after a sale is completed (approved + stocked out) a certificate is issued per sold unit →
 * the certificate can be printed / reprinted at any time, voided, or explicitly reissued.
 *
 * Warranty records never create sales or touch inventory. Each certificate stores a snapshot of
 * the customer, product, dates, and terms so reprints match the original.
 * Every create / reissue / print / void is written to warranty_certificate_events and audit_logs.
 */

const WARRANTY_STATUS_ACTIVE = 'Active';
const WARRANTY_STATUS_VOIDED = 'Voided';
const WARRANTY_STATUS_EXPIRED = 'Expired';

function warrantyEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS product_warranty_settings (
        product_id INT NOT NULL PRIMARY KEY,
        warranty_enabled TINYINT(1) NOT NULL DEFAULT 0,
        duration_value INT NOT NULL DEFAULT 0,
        duration_unit VARCHAR(10) NOT NULL DEFAULT 'months',
        model_number VARCHAR(120) NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pws_enabled (warranty_enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // active_key is "<sales_order_item_id>-<unit_seq>" while the certificate is Active and NULL once voided,
    // so the unique index allows exactly one active certificate per sold unit.
    $conn->query("CREATE TABLE IF NOT EXISTS warranty_certificates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        certificate_number VARCHAR(40) NOT NULL,
        sales_order_id INT NOT NULL,
        sales_order_item_id INT NOT NULL,
        unit_seq INT NOT NULL DEFAULT 1,
        active_key VARCHAR(40) NULL,
        product_id INT NOT NULL,
        invoice_id INT NULL,
        invoice_number VARCHAR(50) NULL,
        order_number VARCHAR(50) NULL,
        customer_name VARCHAR(150) NOT NULL,
        customer_contact VARCHAR(60) NULL,
        product_name VARCHAR(200) NOT NULL,
        sku VARCHAR(50) NULL,
        brand VARCHAR(100) NULL,
        model_number VARCHAR(120) NULL,
        serial_number VARCHAR(100) NULL,
        purchase_date DATE NOT NULL,
        duration_value INT NOT NULL,
        duration_unit VARCHAR(10) NOT NULL,
        start_date DATE NOT NULL,
        expiry_date DATE NOT NULL,
        terms_snapshot TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        issued_by INT NULL,
        issued_by_name VARCHAR(150) NULL,
        issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        voided_by INT NULL,
        voided_by_name VARCHAR(150) NULL,
        voided_at DATETIME NULL,
        void_reason VARCHAR(255) NULL,
        reissued_from_id INT NULL,
        print_count INT NOT NULL DEFAULT 0,
        last_printed_at DATETIME NULL,
        UNIQUE KEY uq_wc_number (certificate_number),
        UNIQUE KEY uq_wc_active_unit (active_key),
        INDEX idx_wc_so (sales_order_id),
        INDEX idx_wc_soi (sales_order_item_id),
        INDEX idx_wc_product (product_id),
        INDEX idx_wc_serial (serial_number),
        INDEX idx_wc_invoice (invoice_number),
        INDEX idx_wc_customer (customer_name),
        INDEX idx_wc_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS warranty_certificate_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        certificate_id INT NOT NULL,
        action VARCHAR(30) NOT NULL,
        remarks TEXT NULL,
        user_id INT NULL,
        user_name VARCHAR(150) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_wce_cert (certificate_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        setting_type VARCHAR(50) DEFAULT 'string',
        description TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Optional per-user grant (like can_manage_pricing) for non-admin warranty configuration.
    $up = $conn->query("SHOW TABLES LIKE 'user_permissions'");
    if ($up && $up->num_rows > 0) {
        $col = $conn->query("SHOW COLUMNS FROM user_permissions LIKE 'can_manage_warranty'");
        if ($col && $col->num_rows === 0) {
            @$conn->query("ALTER TABLE user_permissions ADD COLUMN can_manage_warranty TINYINT(1) NOT NULL DEFAULT 0");
        }
    }
}

/* ─── Permissions ────────────────────────────────────────────────────────── */

/** Roles that sell and may issue, view, search, and reprint certificates. */
function warrantyIssuerRoles(): array {
    return ['admin', 'warehouse_head', 'cashier', 'sales_marketing'];
}

function warrantyCanIssue(string $roleKey): bool {
    return in_array($roleKey, warrantyIssuerRoles(), true);
}

function warrantyHasGrant(mysqli $conn, int $uid): bool {
    if ($uid <= 0) return false;
    $col = $conn->query("SHOW COLUMNS FROM user_permissions LIKE 'can_manage_warranty'");
    if (!($col && $col->num_rows > 0)) return false;
    $st = $conn->prepare('SELECT can_manage_warranty FROM user_permissions WHERE user_id = ? LIMIT 1');
    if (!$st) return false;
    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row && !empty($row['can_manage_warranty']);
}

/** Configure product warranty coverage and the default terms: Administrator, or users granted can_manage_warranty. */
function warrantyCanManage(mysqli $conn, int $uid, string $roleKey): bool {
    return $roleKey === 'admin' || warrantyHasGrant($conn, $uid);
}

/** Void or reissue certificates: Administrator, Warehouse Head, or users granted can_manage_warranty. */
function warrantyCanVoid(mysqli $conn, int $uid, string $roleKey): bool {
    return in_array($roleKey, ['admin', 'warehouse_head'], true) || warrantyHasGrant($conn, $uid);
}

/* ─── Durations & dates ──────────────────────────────────────────────────── */

function warrantyDurationUnits(): array {
    return ['days' => 'Day(s)', 'months' => 'Month(s)', 'years' => 'Year(s)'];
}

function warrantyDurationMax(string $unit): int {
    return ['days' => 3650, 'months' => 120, 'years' => 10][$unit] ?? 0;
}

function warrantyDurationLabel(int $value, string $unit): string {
    if ($value <= 0) return '—';
    $names = ['days' => ['Day', 'Days'], 'months' => ['Month', 'Months'], 'years' => ['Year', 'Years']];
    $pair = $names[$unit] ?? ['', ''];
    return $value . ' ' . ($value === 1 ? $pair[0] : $pair[1]);
}

/** Start date + duration. Month/year steps clamp to the last day of the month (Jan 31 + 1 month = Feb 28/29). */
function warrantyComputeExpiry(string $startDate, int $value, string $unit): string {
    $start = new DateTimeImmutable(substr($startDate, 0, 10));
    if ($unit === 'days') {
        return $start->modify('+' . $value . ' days')->format('Y-m-d');
    }
    $months = $unit === 'years' ? $value * 12 : $value;
    $y = (int)$start->format('Y');
    $m = (int)$start->format('n') - 1 + $months;
    $y += intdiv($m, 12);
    $m = ($m % 12) + 1;
    $lastDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
    return sprintf('%04d-%02d-%02d', $y, $m, min((int)$start->format('j'), $lastDay));
}

/** Active / Expired / Voided, with Expired derived from the expiry date. */
function warrantyDisplayStatus(array $cert): string {
    if ((string)($cert['status'] ?? '') === WARRANTY_STATUS_VOIDED) return WARRANTY_STATUS_VOIDED;
    $exp = (string)($cert['expiry_date'] ?? '');
    if ($exp !== '' && $exp < date('Y-m-d')) return WARRANTY_STATUS_EXPIRED;
    return WARRANTY_STATUS_ACTIVE;
}

/* ─── Terms & conditions ─────────────────────────────────────────────────── */

function warrantyDefaultTerms(): string {
    return implode("\n", [
        "Warranty coverage is subject to the manufacturer's warranty policy.",
        'Proof of purchase and this warranty certificate may be required when requesting warranty service.',
        'Damage caused by misuse, unauthorized repair, or improper handling may be excluded from coverage, subject to the applicable warranty policy.',
        'Warranty claims must be verified by authorized personnel.',
    ]);
}

function warrantyLoadTerms(mysqli $conn): string {
    $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'warranty_terms' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $terms = $row ? trim((string)$row['setting_value']) : '';
    return $terms !== '' ? $terms : warrantyDefaultTerms();
}

/** @return string[] one clause per non-empty line */
function warrantyTermsList(string $terms): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $terms) as $line) {
        $line = trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line));
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

function warrantySaveTerms(mysqli $conn, string $terms, int $uid): array {
    $clauses = warrantyTermsList($terms);
    if (!$clauses) {
        return ['ok' => false, 'message' => 'Enter at least one warranty term.'];
    }
    if (count($clauses) > 30) {
        return ['ok' => false, 'message' => 'Use at most 30 terms.'];
    }
    foreach ($clauses as $c) {
        if (mb_strlen($c) > 600) {
            return ['ok' => false, 'message' => 'Each term must be 600 characters or fewer.'];
        }
    }
    $value = implode("\n", $clauses);
    $previous = warrantyLoadTerms($conn);
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
                            VALUES ('warranty_terms', ?, 'string', 'Warranty certificate terms and conditions (one per line)')
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) return ['ok' => false, 'message' => 'Could not save the warranty terms.'];
    $stmt->bind_param('s', $value);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return ['ok' => false, 'message' => 'Could not save the warranty terms.'];
    if ($previous !== $value) {
        warrantyAuditLog($conn, $uid, 'warranty_terms_updated', 'Module: Warranty. Default warranty terms updated (' . count($clauses) . ' clause(s)). New certificates use the updated terms; issued certificates keep their original terms.');
    }
    return ['ok' => true, 'terms' => $value];
}

/* ─── Audit helpers ──────────────────────────────────────────────────────── */

function warrantyUserInfo(mysqli $conn, int $uid): array {
    if ($uid <= 0) return ['name' => 'System', 'role' => 'System'];
    $q = $conn->query('SELECT COALESCE(NULLIF(full_name,\'\'), username) AS n, role FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
    $row = $q ? $q->fetch_assoc() : null;
    return [
        'name' => $row && !empty($row['n']) ? (string)$row['n'] : ('User #' . $uid),
        'role' => $row ? (string)$row['role'] : '',
    ];
}

function warrantyAuditLog(mysqli $conn, int $uid, string $actionKey, string $details): void {
    $info = warrantyUserInfo($conn, $uid);
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
        $stmt->bind_param('issss', $u, $info['name'], $info['role'], $actionKey, $details);
        $stmt->execute();
        $stmt->close();
    }
}

function warrantyLogEvent(mysqli $conn, int $certId, string $action, string $remarks, int $uid): void {
    $info = warrantyUserInfo($conn, $uid);
    $stmt = $conn->prepare('INSERT INTO warranty_certificate_events (certificate_id, action, remarks, user_id, user_name) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) return;
    $u = $uid > 0 ? $uid : null;
    $stmt->bind_param('issis', $certId, $action, $remarks, $u, $info['name']);
    $stmt->execute();
    $stmt->close();
}

/* ─── Product settings ───────────────────────────────────────────────────── */

function warrantyGetProductSettings(mysqli $conn, int $productId): array {
    $settings = ['product_id' => $productId, 'warranty_enabled' => 0, 'duration_value' => 0, 'duration_unit' => 'months', 'model_number' => ''];
    if ($productId <= 0) return $settings;
    $st = $conn->prepare('SELECT warranty_enabled, duration_value, duration_unit, model_number, updated_at FROM product_warranty_settings WHERE product_id = ? LIMIT 1');
    if (!$st) return $settings;
    $st->bind_param('i', $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $settings['warranty_enabled'] = (int)$row['warranty_enabled'];
        $settings['duration_value'] = (int)$row['duration_value'];
        $settings['duration_unit'] = (string)$row['duration_unit'];
        $settings['model_number'] = (string)($row['model_number'] ?? '');
        $settings['updated_at'] = (string)($row['updated_at'] ?? '');
    }
    return $settings;
}

function warrantyProductIsEligible(array $settings): bool {
    return !empty($settings['warranty_enabled'])
        && (int)$settings['duration_value'] > 0
        && isset(warrantyDurationUnits()[(string)$settings['duration_unit']]);
}

/** Validate and save a product's warranty coverage. Duration is required when coverage is enabled. */
function warrantySaveProductSettings(mysqli $conn, int $productId, array $input, int $uid): array {
    if ($productId <= 0) return ['ok' => false, 'message' => 'Product not found.'];
    $pq = $conn->prepare('SELECT id, sku, name FROM products WHERE id = ? LIMIT 1');
    $pq->bind_param('i', $productId);
    $pq->execute();
    $product = $pq->get_result()->fetch_assoc();
    $pq->close();
    if (!$product) return ['ok' => false, 'message' => 'Product not found.'];

    $enabled = !empty($input['warranty_enabled']) && (string)$input['warranty_enabled'] !== '0' ? 1 : 0;
    $unit = strtolower(trim((string)($input['warranty_duration_unit'] ?? 'months')));
    $rawValue = trim((string)($input['warranty_duration_value'] ?? ''));
    $model = trim((string)($input['warranty_model_number'] ?? ''));
    if (mb_strlen($model) > 120) {
        return ['ok' => false, 'message' => 'Model must be 120 characters or fewer.'];
    }
    if (!isset(warrantyDurationUnits()[$unit])) {
        return ['ok' => false, 'message' => 'Choose a warranty duration unit (days, months, or years).'];
    }
    $value = 0;
    if ($rawValue !== '') {
        if (!preg_match('/^\d+$/', $rawValue)) {
            return ['ok' => false, 'message' => 'Warranty duration must be a whole number.'];
        }
        $value = (int)$rawValue;
    }
    if ($enabled) {
        $max = warrantyDurationMax($unit);
        if ($value < 1 || $value > $max) {
            return ['ok' => false, 'message' => 'Enter the warranty duration for this product (1–' . $max . ' ' . $unit . ').'];
        }
    }

    $before = warrantyGetProductSettings($conn, $productId);
    $stmt = $conn->prepare('INSERT INTO product_warranty_settings (product_id, warranty_enabled, duration_value, duration_unit, model_number, updated_by)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE warranty_enabled = VALUES(warranty_enabled), duration_value = VALUES(duration_value),
                                duration_unit = VALUES(duration_unit), model_number = VALUES(model_number), updated_by = VALUES(updated_by)');
    if (!$stmt) return ['ok' => false, 'message' => 'Could not save warranty settings.'];
    $modelVal = $model !== '' ? $model : null;
    $u = $uid > 0 ? $uid : null;
    $stmt->bind_param('iiissi', $productId, $enabled, $value, $unit, $modelVal, $u);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return ['ok' => false, 'message' => 'Could not save warranty settings.'];

    $after = warrantyGetProductSettings($conn, $productId);
    $describe = static function (array $s): string {
        return !empty($s['warranty_enabled'])
            ? 'Enabled, ' . warrantyDurationLabel((int)$s['duration_value'], (string)$s['duration_unit'])
            : 'Disabled';
    };
    $changes = [];
    if ($describe($before) !== $describe($after)) $changes[] = 'coverage: ' . $describe($before) . ' → ' . $describe($after);
    if ((string)$before['model_number'] !== (string)$after['model_number']) $changes[] = 'model: "' . $before['model_number'] . '" → "' . $after['model_number'] . '"';
    if ($changes) {
        warrantyAuditLog($conn, $uid, 'warranty_settings_updated', 'Module: Warranty. Product ' . $product['sku'] . ' (' . $product['name'] . ') warranty settings: ' . implode('; ', $changes) . '.');
    }
    return ['ok' => true, 'settings' => $after, 'changes' => $changes];
}

function warrantyListProductSettings(mysqli $conn, string $search, bool $eligibleOnly): array {
    $sql = "SELECT p.id, p.sku, p.name, p.brand, p.status, c.name AS category_name,
                   COALESCE(w.warranty_enabled, 0) AS warranty_enabled, COALESCE(w.duration_value, 0) AS duration_value,
                   COALESCE(w.duration_unit, 'months') AS duration_unit, w.model_number, w.updated_at
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_warranty_settings w ON w.product_id = p.id
            WHERE 1=1";
    $types = '';
    $params = [];
    if ($eligibleOnly) {
        $sql .= ' AND w.warranty_enabled = 1';
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ? OR w.model_number LIKE ? OR c.name LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY COALESCE(w.warranty_enabled, 0) DESC, p.name ASC LIMIT 500';
    $st = $conn->prepare($sql);
    if (!$st) return [];
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['warranty_enabled'] = (int)$r['warranty_enabled'];
        $r['duration_value'] = (int)$r['duration_value'];
        $r['duration_label'] = $r['warranty_enabled'] ? warrantyDurationLabel($r['duration_value'], (string)$r['duration_unit']) : '';
        $rows[] = $r;
    }
    $st->close();
    return $rows;
}

/* ─── Sales lookup ───────────────────────────────────────────────────────── */

/** Sales order id from a sales order, invoice, or official receipt number. */
function warrantyFindSalesOrderId(mysqli $conn, string $reference): int {
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

/** Units refunded through completed customer returns, per sales order line. Those units lose warranty eligibility. */
function warrantyRefundedQtyByLine(mysqli $conn, int $soId): array {
    $map = [];
    $t = $conn->query("SHOW TABLES LIKE 'sales_returns'");
    if (!($t && $t->num_rows > 0)) return $map;
    $res = $conn->query("SELECT sri.sales_order_item_id, SUM(sri.quantity) AS q
                         FROM sales_return_items sri
                         INNER JOIN sales_returns sr ON sr.id = sri.return_id
                         WHERE sr.sales_order_id = " . (int)$soId . " AND sr.status = 'Completed' AND sr.outcome = 'Refund'
                         GROUP BY sri.sales_order_item_id");
    while ($res && ($r = $res->fetch_assoc())) {
        $map[(int)$r['sales_order_item_id']] = (int)$r['q'];
    }
    return $map;
}

function warrantyCustomerContact(mysqli $conn, string $customerName): string {
    $name = trim($customerName);
    if ($name === '') return '';
    $t = $conn->query("SHOW TABLES LIKE 'customers'");
    if (!($t && $t->num_rows > 0)) return '';
    $st = $conn->prepare('SELECT phone FROM customers WHERE name = ? LIMIT 1');
    if (!$st) return '';
    $st->bind_param('s', $name);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? trim((string)($row['phone'] ?? '')) : '';
}

/**
 * Sale header, warranty-eligible lines, and every sold unit with its certificate history.
 * @return array{ok:bool,message?:string,sale?:array,lines?:array,blocked_reason?:string,eligible_units?:int,issued_units?:int}
 */
function warrantyLoadSale(mysqli $conn, int $soId, bool $lock = false): array {
    if ($soId <= 0) return ['ok' => false, 'message' => 'Sale not found. Check the sales order, invoice, or receipt number.'];
    $st = $conn->prepare('SELECT * FROM sales_orders WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $st->bind_param('i', $soId);
    $st->execute();
    $so = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$so) return ['ok' => false, 'message' => 'Sale not found. Check the sales order, invoice, or receipt number.'];

    $iq = $conn->prepare('SELECT id, invoice_number, created_at FROM invoices WHERE sales_order_id = ? LIMIT 1');
    $iq->bind_param('i', $soId);
    $iq->execute();
    $inv = $iq->get_result()->fetch_assoc();
    $iq->close();

    $blocked = '';
    if ((string)$so['status'] === 'Cancelled') {
        $blocked = 'This sale was cancelled. Warranty certificates are only issued for completed sales.';
    } elseif ((string)$so['status'] !== 'Approved' || strtolower((string)($so['stock_out_status'] ?? '')) !== 'completed') {
        $blocked = 'This sale is not completed yet (it must be approved and released from stock). Generate the warranty after the sale is completed.';
    } elseif (!$inv) {
        $blocked = 'This sale has no sales invoice yet.';
    }

    $purchaseDate = substr((string)($so['created_at'] ?? ''), 0, 10);
    $refunded = warrantyRefundedQtyByLine($conn, $soId);

    $certsByUnit = [];
    $cq = $conn->prepare('SELECT id, certificate_number, sales_order_item_id, unit_seq, serial_number, status, expiry_date, issued_at, issued_by_name, void_reason, print_count
                          FROM warranty_certificates WHERE sales_order_id = ? ORDER BY id ASC');
    $cq->bind_param('i', $soId);
    $cq->execute();
    $cr = $cq->get_result();
    while ($c = $cr->fetch_assoc()) {
        $c['display_status'] = warrantyDisplayStatus($c);
        $certsByUnit[(int)$c['sales_order_item_id'] . '-' . (int)$c['unit_seq']][] = $c;
    }
    $cq->close();

    $lines = [];
    $eligibleUnits = 0;
    $issuedUnits = 0;
    $lq = $conn->prepare('SELECT soi.id, soi.product_id, soi.quantity, p.name AS product_name, p.sku, p.brand
                          FROM sales_order_items soi
                          LEFT JOIN products p ON p.id = soi.product_id
                          WHERE soi.sales_order_id = ? ORDER BY soi.id ASC');
    $lq->bind_param('i', $soId);
    $lq->execute();
    $lr = $lq->get_result();
    while ($row = $lr->fetch_assoc()) {
        $lid = (int)$row['id'];
        $settings = warrantyGetProductSettings($conn, (int)$row['product_id']);
        $eligible = warrantyProductIsEligible($settings);
        $sold = (int)$row['quantity'];
        $refundedQty = min($sold, (int)($refunded[$lid] ?? 0));
        $coveredQty = $sold - $refundedQty;
        $units = [];
        if ($eligible) {
            // Show every sold unit plus any unit beyond the current coverage that already has history.
            $maxSeq = $sold;
            for ($seq = 1; $seq <= $maxSeq; $seq++) {
                $history = $certsByUnit[$lid . '-' . $seq] ?? [];
                $active = null;
                foreach ($history as $h) {
                    if ($h['status'] === WARRANTY_STATUS_ACTIVE) $active = $h;
                }
                if ($seq <= $coveredQty) {
                    $eligibleUnits++;
                    if ($active) $issuedUnits++;
                }
                $units[] = [
                    'unit_seq' => $seq,
                    'covered' => $seq <= $coveredQty,
                    'active' => $active,
                    'history' => $history,
                ];
            }
        }
        $lines[] = [
            'sales_order_item_id' => $lid,
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)($row['product_name'] ?? ('Product #' . (int)$row['product_id'])),
            'sku' => (string)($row['sku'] ?? ''),
            'brand' => (string)($row['brand'] ?? ''),
            'model_number' => (string)$settings['model_number'],
            'quantity' => $sold,
            'refunded_qty' => $refundedQty,
            'warranty_eligible' => $eligible,
            'duration_value' => (int)$settings['duration_value'],
            'duration_unit' => (string)$settings['duration_unit'],
            'duration_label' => $eligible ? warrantyDurationLabel((int)$settings['duration_value'], (string)$settings['duration_unit']) : '',
            'expiry_preview' => ($eligible && $purchaseDate !== '') ? warrantyComputeExpiry($purchaseDate, (int)$settings['duration_value'], (string)$settings['duration_unit']) : '',
            'units' => $units,
        ];
    }
    $lq->close();

    return [
        'ok' => true,
        'sale' => [
            'id' => (int)$so['id'],
            'order_number' => (string)$so['order_number'],
            'customer_name' => (string)$so['customer_name'],
            'customer_contact' => warrantyCustomerContact($conn, (string)$so['customer_name']),
            'status' => (string)$so['status'],
            'stock_out_status' => (string)($so['stock_out_status'] ?? ''),
            'sale_type' => (string)($so['sale_type'] ?? ''),
            'purchase_date' => $purchaseDate,
            'invoice_id' => $inv ? (int)$inv['id'] : 0,
            'invoice_number' => $inv ? (string)$inv['invoice_number'] : '',
        ],
        'lines' => $lines,
        'blocked_reason' => $blocked,
        'eligible_units' => $eligibleUnits,
        'issued_units' => $issuedUnits,
    ];
}

/** Cheap check used by receipts / invoices to decide whether to offer the warranty link. */
function warrantySaleHasEligibleItems(mysqli $conn, int $soId): bool {
    if ($soId <= 0) return false;
    $res = $conn->query('SELECT 1 FROM sales_order_items soi
                         INNER JOIN product_warranty_settings w ON w.product_id = soi.product_id
                         WHERE soi.sales_order_id = ' . (int)$soId . ' AND w.warranty_enabled = 1 AND w.duration_value > 0 LIMIT 1');
    return $res && $res->num_rows > 0;
}

/* ─── Issue / reissue / void ─────────────────────────────────────────────── */

function warrantyNextCertificateNumber(mysqli $conn): string {
    for ($i = 0; $i < 10; $i++) {
        $num = 'WC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $chk = $conn->prepare('SELECT id FROM warranty_certificates WHERE certificate_number = ? LIMIT 1');
        if (!$chk) break;
        $chk->bind_param('s', $num);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if (!$exists) return $num;
    }
    return 'WC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function warrantyNormalizeSerial(string $serial): array {
    $serial = trim(preg_replace('/\s+/', ' ', $serial));
    if ($serial === '') return ['ok' => true, 'value' => ''];
    if (mb_strlen($serial) > 100) return ['ok' => false, 'message' => 'Serial number must be 100 characters or fewer.'];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/#-]*$/', $serial)) {
        return ['ok' => false, 'message' => 'Serial number may only contain letters, numbers, spaces, and . _ / # -'];
    }
    return ['ok' => true, 'value' => $serial];
}

function warrantyNormalizeContact(string $contact): array {
    $contact = trim($contact);
    if ($contact === '') return ['ok' => true, 'value' => ''];
    if (mb_strlen($contact) > 60 || !preg_match('/^[0-9+()\/ .-]{5,60}$/', $contact)) {
        return ['ok' => false, 'message' => 'Enter a valid contact number (digits, spaces, + ( ) - . /).'];
    }
    return ['ok' => true, 'value' => $contact];
}

/**
 * Issue a certificate for one sold unit of a completed sale.
 * A unit that already has (or had) a certificate needs an explicit reissue, which voids the current one.
 * Does not create sales, invoices, or stock movements.
 */
function warrantyIssue(mysqli $conn, array $input, int $uid, bool $canReissue): array {
    $soId = (int)($input['sales_order_id'] ?? 0);
    $soiId = (int)($input['sales_order_item_id'] ?? 0);
    $unitSeq = (int)($input['unit_seq'] ?? 0);
    $reissue = !empty($input['reissue']) && (string)$input['reissue'] !== '0';
    $reason = trim((string)($input['reason'] ?? ''));

    $serialCheck = warrantyNormalizeSerial((string)($input['serial_number'] ?? ''));
    if (!$serialCheck['ok']) return ['ok' => false, 'message' => $serialCheck['message']];
    $contactCheck = warrantyNormalizeContact((string)($input['customer_contact'] ?? ''));
    if (!$contactCheck['ok']) return ['ok' => false, 'message' => $contactCheck['message']];
    if ($reissue) {
        if (!$canReissue) return ['ok' => false, 'message' => 'Only an authorized user can reissue a warranty certificate.'];
        if ($reason === '') return ['ok' => false, 'message' => 'Enter the reason for reissuing this certificate.'];
        if (mb_strlen($reason) > 255) return ['ok' => false, 'message' => 'Reason must be 255 characters or fewer.'];
    }
    if ($soId <= 0 || $soiId <= 0 || $unitSeq <= 0) return ['ok' => false, 'message' => 'Select the sold unit to cover.'];

    $conn->begin_transaction();
    try {
        // Locking the sale row serializes concurrent issue requests for the same transaction.
        $pack = warrantyLoadSale($conn, $soId, true);
        if (empty($pack['ok'])) throw new RuntimeException($pack['message'] ?? 'Sale not found.');
        if ($pack['blocked_reason'] !== '') throw new RuntimeException($pack['blocked_reason']);
        $line = null;
        foreach ($pack['lines'] as $l) {
            if ($l['sales_order_item_id'] === $soiId) $line = $l;
        }
        if (!$line) throw new RuntimeException('That item is not part of this sale.');
        if (!$line['warranty_eligible']) {
            throw new RuntimeException($line['product_name'] . ' is not configured for warranty coverage. Only warranty-eligible products receive a certificate.');
        }
        $unit = $line['units'][$unitSeq - 1] ?? null;
        if (!$unit) throw new RuntimeException('Unit ' . $unitSeq . ' does not exist on this sale line.');
        if (!$unit['covered']) throw new RuntimeException('Unit ' . $unitSeq . ' was refunded through a customer return and is no longer covered.');

        $active = $unit['active'];
        if (!$reissue && $unit['history']) {
            $msg = $active
                ? 'Unit ' . $unitSeq . ' already has certificate ' . $active['certificate_number'] . '. Use Reprint to print it again.'
                : 'Unit ' . $unitSeq . ' already had a certificate that was voided. An authorized user must reissue it.';
            throw new RuntimeException($msg);
        }

        $serial = $serialCheck['value'];
        if ($serial !== '') {
            $dup = $conn->prepare("SELECT certificate_number FROM warranty_certificates
                                   WHERE product_id = ? AND serial_number = ? AND status = 'Active'
                                     AND NOT (sales_order_item_id = ? AND unit_seq = ?) LIMIT 1");
            $dup->bind_param('isii', $line['product_id'], $serial, $soiId, $unitSeq);
            $dup->execute();
            $dupRow = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($dupRow) {
                throw new RuntimeException('Serial number ' . $serial . ' is already covered by certificate ' . $dupRow['certificate_number'] . '.');
            }
        }

        $issuer = warrantyUserInfo($conn, $uid);
        $sale = $pack['sale'];
        $newNumber = warrantyNextCertificateNumber($conn);

        if ($active) {
            $voidReason = 'Reissued as ' . $newNumber . ': ' . $reason;
            $vs = $conn->prepare("UPDATE warranty_certificates SET status = 'Voided', active_key = NULL, voided_by = ?, voided_by_name = ?, voided_at = NOW(), void_reason = ?
                                  WHERE id = ? AND status = 'Active'");
            $activeId = (int)$active['id'];
            $vs->bind_param('issi', $uid, $issuer['name'], $voidReason, $activeId);
            $vs->execute();
            $vs->close();
            warrantyLogEvent($conn, $activeId, 'voided', $voidReason, $uid);
        }

        $contact = $contactCheck['value'] !== '' ? $contactCheck['value'] : $sale['customer_contact'];
        $activeKey = $soiId . '-' . $unitSeq;
        $start = $sale['purchase_date'];
        $expiry = warrantyComputeExpiry($start, $line['duration_value'], $line['duration_unit']);
        $terms = warrantyLoadTerms($conn);
        $reissuedFrom = $active ? (int)$active['id'] : null;
        if ($reissuedFrom === null && $unit['history']) {
            $last = end($unit['history']);
            $reissuedFrom = (int)$last['id'];
        }
        $ins = $conn->prepare('INSERT INTO warranty_certificates
            (certificate_number, sales_order_id, sales_order_item_id, unit_seq, active_key, product_id, invoice_id, invoice_number, order_number,
             customer_name, customer_contact, product_name, sku, brand, model_number, serial_number, purchase_date,
             duration_value, duration_unit, start_date, expiry_date, terms_snapshot, status, issued_by, issued_by_name, reissued_from_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Active\',?,?,?)');
        if (!$ins) throw new RuntimeException('Could not save the warranty certificate.');
        $invoiceId = $sale['invoice_id'] > 0 ? $sale['invoice_id'] : null;
        $contactVal = $contact !== '' ? $contact : null;
        $brand = $line['brand'] !== '' ? $line['brand'] : null;
        $model = $line['model_number'] !== '' ? $line['model_number'] : null;
        $serialVal = $serial !== '' ? $serial : null;
        $u = $uid > 0 ? $uid : null;
        $ins->bind_param(
            'siiisiissssssssssissssisi',
            $newNumber, $soId, $soiId, $unitSeq, $activeKey, $line['product_id'], $invoiceId, $sale['invoice_number'], $sale['order_number'],
            $sale['customer_name'], $contactVal, $line['product_name'], $line['sku'], $brand, $model, $serialVal, $start,
            $line['duration_value'], $line['duration_unit'], $start, $expiry, $terms, $u, $issuer['name'], $reissuedFrom
        );
        if (!$ins->execute()) {
            $err = $ins->errno;
            $ins->close();
            if ($err === 1062) throw new RuntimeException('A certificate for this unit was just issued by another user. Refresh to see it.');
            throw new RuntimeException('Could not save the warranty certificate.');
        }
        $certId = (int)$ins->insert_id;
        $ins->close();

        $summary = $line['product_name'] . ' unit ' . $unitSeq . ' of ' . $line['quantity'] . ($serial !== '' ? ', S/N ' . $serial : '')
            . ', sale ' . $sale['order_number'] . ' / ' . $sale['invoice_number'] . ', valid ' . $start . ' to ' . $expiry;
        if ($reissue) {
            $prev = $active ? $active['certificate_number'] : 'a voided certificate';
            warrantyLogEvent($conn, $certId, 'reissued', 'Reissued (replaces ' . $prev . '): ' . $reason, $uid);
            warrantyAuditLog($conn, $uid, 'warranty_reissued', 'Module: Warranty. Certificate ' . $newNumber . ' reissued, replacing ' . $prev . '. Reason: ' . $reason . '. ' . $summary . '.');
        } else {
            warrantyLogEvent($conn, $certId, 'created', 'Certificate issued.', $uid);
            warrantyAuditLog($conn, $uid, 'warranty_issued', 'Module: Warranty. Certificate ' . $newNumber . ' issued for ' . $summary . '.');
        }
        $conn->commit();
        return ['ok' => true, 'id' => $certId, 'certificate_number' => $newNumber, 'expiry_date' => $expiry];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function warrantyVoid(mysqli $conn, int $certId, string $reason, int $uid): array {
    $reason = trim($reason);
    if ($certId <= 0) return ['ok' => false, 'message' => 'Certificate not found.'];
    if ($reason === '') return ['ok' => false, 'message' => 'Enter the reason for voiding this certificate.'];
    if (mb_strlen($reason) > 255) return ['ok' => false, 'message' => 'Reason must be 255 characters or fewer.'];
    $cert = warrantyGet($conn, $certId);
    if (!$cert) return ['ok' => false, 'message' => 'Certificate not found.'];
    if ($cert['status'] === WARRANTY_STATUS_VOIDED) return ['ok' => false, 'message' => 'This certificate is already voided.'];
    $info = warrantyUserInfo($conn, $uid);
    $st = $conn->prepare("UPDATE warranty_certificates SET status = 'Voided', active_key = NULL, voided_by = ?, voided_by_name = ?, voided_at = NOW(), void_reason = ?
                          WHERE id = ? AND status = 'Active'");
    $st->bind_param('issi', $uid, $info['name'], $reason, $certId);
    $st->execute();
    $changed = $st->affected_rows > 0;
    $st->close();
    if (!$changed) return ['ok' => false, 'message' => 'This certificate is already voided.'];
    warrantyLogEvent($conn, $certId, 'voided', $reason, $uid);
    warrantyAuditLog($conn, $uid, 'warranty_voided', 'Module: Warranty. Certificate ' . $cert['certificate_number'] . ' (' . $cert['product_name']
        . ($cert['serial_number'] ? ', S/N ' . $cert['serial_number'] : '') . ', ' . $cert['customer_name'] . ') voided. Reason: ' . $reason . '.');
    return ['ok' => true];
}

function warrantyLogPrint(mysqli $conn, int $certId, int $uid): array {
    $cert = warrantyGet($conn, $certId);
    if (!$cert) return ['ok' => false, 'message' => 'Certificate not found.'];
    $isReprint = (int)$cert['print_count'] > 0;
    $st = $conn->prepare('UPDATE warranty_certificates SET print_count = print_count + 1, last_printed_at = NOW() WHERE id = ?');
    $st->bind_param('i', $certId);
    $st->execute();
    $st->close();
    $action = $isReprint ? 'reprinted' : 'printed';
    $note = $cert['status'] === WARRANTY_STATUS_VOIDED ? ' (voided copy)' : '';
    warrantyLogEvent($conn, $certId, $action, ($isReprint ? 'Reprinted' : 'Printed') . $note . '.', $uid);
    if ($isReprint) {
        warrantyAuditLog($conn, $uid, 'warranty_reprinted', 'Module: Warranty. Certificate ' . $cert['certificate_number'] . ' reprinted' . $note . ' (copy #' . ((int)$cert['print_count'] + 1) . ').');
    }
    return ['ok' => true, 'print_count' => (int)$cert['print_count'] + 1];
}

/* ─── Read ───────────────────────────────────────────────────────────────── */

function warrantyGet(mysqli $conn, int $certId, bool $withEvents = false): ?array {
    if ($certId <= 0) return null;
    $st = $conn->prepare('SELECT * FROM warranty_certificates WHERE id = ? LIMIT 1');
    $st->bind_param('i', $certId);
    $st->execute();
    $cert = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$cert) return null;
    $cert['display_status'] = warrantyDisplayStatus($cert);
    $cert['duration_label'] = warrantyDurationLabel((int)$cert['duration_value'], (string)$cert['duration_unit']);
    if ($withEvents) {
        $events = [];
        $eq = $conn->prepare('SELECT action, remarks, user_name, created_at FROM warranty_certificate_events WHERE certificate_id = ? ORDER BY id ASC');
        $eq->bind_param('i', $certId);
        $eq->execute();
        $er = $eq->get_result();
        while ($e = $er->fetch_assoc()) $events[] = $e;
        $eq->close();
        $cert['events'] = $events;
        $cert['reissued_from_number'] = '';
        if (!empty($cert['reissued_from_id'])) {
            $prev = warrantyGet($conn, (int)$cert['reissued_from_id']);
            $cert['reissued_from_number'] = $prev ? $prev['certificate_number'] : '';
        }
    }
    return $cert;
}

/** Search by certificate no., customer, product, serial, sales order, or invoice; filter by derived status. */
function warrantyList(mysqli $conn, array $filters): array {
    $sql = "SELECT id, certificate_number, sales_order_id, order_number, invoice_number, customer_name, customer_contact,
                   product_name, sku, brand, model_number, serial_number, unit_seq, purchase_date, duration_value, duration_unit,
                   expiry_date, status, issued_by_name, issued_at, print_count, void_reason
            FROM warranty_certificates WHERE 1=1";
    $types = '';
    $params = [];
    $status = (string)($filters['status'] ?? 'all');
    if ($status === WARRANTY_STATUS_VOIDED) {
        $sql .= " AND status = 'Voided'";
    } elseif ($status === WARRANTY_STATUS_EXPIRED) {
        $sql .= " AND status = 'Active' AND expiry_date < CURDATE()";
    } elseif ($status === WARRANTY_STATUS_ACTIVE) {
        $sql .= " AND status = 'Active' AND expiry_date >= CURDATE()";
    }
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (certificate_number LIKE ? OR customer_name LIKE ? OR product_name LIKE ? OR sku LIKE ? OR serial_number LIKE ?
                      OR invoice_number LIKE ? OR order_number LIKE ? OR brand LIKE ? OR model_number LIKE ?)';
        $types .= str_repeat('s', 9);
        $params = array_fill(0, 9, $like);
    }
    $soId = (int)($filters['sales_order_id'] ?? 0);
    if ($soId > 0) {
        $sql .= ' AND sales_order_id = ?';
        $types .= 'i';
        $params[] = $soId;
    }
    $sql .= ' ORDER BY id DESC LIMIT 500';
    $st = $conn->prepare($sql);
    if (!$st) return [];
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['display_status'] = warrantyDisplayStatus($r);
        $r['duration_label'] = warrantyDurationLabel((int)$r['duration_value'], (string)$r['duration_unit']);
        $rows[] = $r;
    }
    $st->close();
    return $rows;
}
