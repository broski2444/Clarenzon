<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/route_protection.php';
require_once 'notifications_api.php';
require_once __DIR__ . '/inventory_wms_lib.php';
require_once __DIR__ . '/po_lifecycle_lib.php';
require_once __DIR__ . '/procurement_receiving_lib.php';
require_once __DIR__ . '/procurement_rts_lib.php';
header('Content-Type: application/json');

clarenzon_api_require();

// Allowed Roles: Purchaser/Admin for procurement, Warehouse Head for receiving,
// Sales/pricing users for receipt markup only (enforced per-action).
require_once __DIR__ . '/route_protection.php';
require_once __DIR__ . '/product_pricing_lib.php';
require_once __DIR__ . '/customers_lib.php';
$roleRaw = (string)($_SESSION['role'] ?? '');
$roleKey = normalize_user_role($roleRaw);
$allowedKeys = ['purchaser', 'admin', 'warehouse_head', 'technician', 'sales_representative', 'sales', 'sales_rep', 'cashier'];
// Cashier reaches this API only for customer lookups used by the sales screens.
if ($roleKey === 'cashier') {
    $cashierAction = (string)($_GET['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? '');
    if (!in_array($cashierAction, ['get_customers', 'customers_list', 'customers_get', 'customers_save'], true)) {
        clarenzon_reject_security(403, 'Cashier accounts do not have access to procurement.', true);
    }
}
if (!in_array($roleKey, $allowedKeys, true)) {
    // Legacy session values before normalize (keep access working)
    $legacy = strtolower(trim($roleRaw));
    $legacyKey = strtolower(str_replace([' ', '-'], '_', $legacy));
    if (!in_array($legacyKey, ['purchaser', 'administrator', 'admin', 'warehouse_head', 'sales_representative', 'sales', 'sales_rep'], true)
        && !in_array($legacy, ['warehouse head'], true)) {
        clarenzon_reject_security(403, '403 Forbidden', true);
    }
    $roleKey = normalize_user_role($legacy);
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'inventory_db', 'clarenzon_inventory'], 'root', '', 3);

function ensureProcurementSchema(mysqli $conn): void {
    static $schemaChecked = false;
    if ($schemaChecked || !$conn) return;
    $schemaChecked = true;
    poLifecycleEnsureSchema($conn);

    // 1. Uploads directory
    $uploadDir = __DIR__ . '/uploads/invoices';
    if (!file_exists($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    // 2. purchase_requests
    $conn->query("CREATE TABLE IF NOT EXISTS purchase_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pr_number VARCHAR(50) NOT NULL UNIQUE,
        requested_by INT NULL,
        supplier_id INT NULL,
        status ENUM('Draft', 'Pending Approval', 'Approved', 'Rejected') NOT NULL DEFAULT 'Draft',
        reason TEXT NULL,
        remarks TEXT NULL,
        purpose VARCHAR(64) NULL,
        customer_order_reference VARCHAR(120) NULL,
        customer_name VARCHAR(200) NULL,
        admin_remarks TEXT NULL,
        total_estimated_amount DECIMAL(12,2) DEFAULT 0.00,
        approved_by INT NULL,
        approved_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_pr_number (pr_number),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'purpose' => 'VARCHAR(64) NULL AFTER remarks',
        'customer_order_reference' => 'VARCHAR(120) NULL AFTER purpose',
        'customer_name' => 'VARCHAR(200) NULL AFTER customer_order_reference',
    ] as $column => $definition) {
        $col = $conn->query("SHOW COLUMNS FROM purchase_requests LIKE '$column'");
        if (!($col && $col->num_rows > 0)) {
            $conn->query("ALTER TABLE purchase_requests ADD COLUMN `$column` $definition");
        }
    }

    // 3. purchase_request_items
    $conn->query("CREATE TABLE IF NOT EXISTS purchase_request_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pr_id INT NOT NULL,
        product_id INT NULL,
        product_name VARCHAR(200) NOT NULL,
        quantity_requested INT NOT NULL DEFAULT 1,
        estimated_unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity_requested * estimated_unit_price) STORED,
        remarks TEXT NULL,
        FOREIGN KEY (pr_id) REFERENCES purchase_requests(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
        INDEX idx_pr (pr_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $priSupCol = $conn->query("SHOW COLUMNS FROM purchase_request_items LIKE 'supplier_id'");
    if (!($priSupCol && $priSupCol->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_request_items ADD COLUMN supplier_id INT NULL AFTER product_id");
        @$conn->query("ALTER TABLE purchase_request_items ADD CONSTRAINT fk_pri_supplier_id FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL");
    }

    // 4. purchase_orders alterations
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'pr_id'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN pr_id INT NULL AFTER id");
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'order_date'");
    if (!($col && $col->num_rows > 0)) {
        // If order_date doesn't exist but purchase_date does, rename it. Otherwise, add it.
        $pdCol = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'purchase_date'");
        if ($pdCol && $pdCol->num_rows > 0) {
            $conn->query("ALTER TABLE purchase_orders CHANGE purchase_date order_date DATE NULL");
        } else {
            $conn->query("ALTER TABLE purchase_orders ADD COLUMN order_date DATE NULL AFTER supplier_id");
        }
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'requested_by'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN requested_by INT NULL AFTER notes");
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'created_by'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN created_by INT NULL AFTER requested_by");
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'received_date'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN received_date DATE NULL AFTER expected_delivery_date");
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'actual_delivery_date'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN actual_delivery_date DATE NULL AFTER received_date");
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'rejection_reason'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN rejection_reason TEXT NULL AFTER notes");
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'approved_at'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by");
    }
    $conn->query("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Draft','Submitted','Pending Approval','Approved','Sent','Supplier Confirmed','Waiting for Delivery','Partially Received','Received','Cancelled','Rejected') NOT NULL DEFAULT 'Draft'");
    $fkCheck = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'pr_id' AND REFERENCED_TABLE_NAME = 'purchase_requests' LIMIT 1");
    if ($fkCheck && $fkCheck->num_rows === 0) {
        $idxCheck = $conn->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'idx_purchase_orders_pr_id'");
        if (!($idxCheck && $idxCheck->num_rows > 0)) {
            $conn->query("ALTER TABLE purchase_orders ADD INDEX idx_purchase_orders_pr_id (pr_id)");
        }
        $conn->query("ALTER TABLE purchase_orders ADD CONSTRAINT fk_purchase_orders_pr_id FOREIGN KEY (pr_id) REFERENCES purchase_requests(id) ON DELETE SET NULL");
    }
    // One PR may produce one PO per supplier. Keep a non-unique pr_id index for lookups.
    $idxPr = $conn->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'idx_purchase_orders_pr_id'");
    if (!($idxPr && $idxPr->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_orders ADD INDEX idx_purchase_orders_pr_id (pr_id)");
    }
    $uq = $conn->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'uq_purchase_orders_pr_id'");
    if ($uq && $uq->num_rows > 0) {
        @$conn->query("ALTER TABLE purchase_orders DROP INDEX uq_purchase_orders_pr_id");
    }
    $uqSup = $conn->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'uq_purchase_orders_pr_supplier'");
    if (!($uqSup && $uqSup->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_orders ADD UNIQUE INDEX uq_purchase_orders_pr_supplier (pr_id, supplier_id)");
    }

    foreach ([
        'product_name' => 'VARCHAR(200) NULL AFTER product_id',
        'notes' => 'TEXT NULL',
        'quantity_received' => 'INT NOT NULL DEFAULT 0 AFTER quantity_ordered',
    ] as $column => $definition) {
        $col = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE '$column'");
        if (!($col && $col->num_rows > 0)) {
            $conn->query("ALTER TABLE purchase_order_items ADD COLUMN `$column` $definition");
        }
    }

    // 5. purchase_receipts
    $conn->query("CREATE TABLE IF NOT EXISTS purchase_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_number VARCHAR(50) NOT NULL UNIQUE,
        po_id INT NOT NULL,
        pr_id INT NULL,
        supplier_id INT NULL,
        date_received DATE NOT NULL,
        received_by INT NULL,
        status ENUM('Partially Received', 'Fully Received') NOT NULL DEFAULT 'Fully Received',
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (pr_id) REFERENCES purchase_requests(id) ON DELETE SET NULL,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
        FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_receipt_number (receipt_number),
        INDEX idx_po (po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 6. purchase_receipt_items
    $conn->query("CREATE TABLE IF NOT EXISTS purchase_receipt_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_id INT NOT NULL,
        po_item_id INT NULL,
        product_id INT NULL,
        product_name VARCHAR(200) NOT NULL,
        quantity_ordered INT NOT NULL DEFAULT 0,
        quantity_received INT NOT NULL DEFAULT 0,
        batch_number VARCHAR(100) NULL,
        expiry_date DATE NULL,
        unit_price DECIMAL(10,2) DEFAULT 0.00,
        remarks TEXT NULL,
        FOREIGN KEY (receipt_id) REFERENCES purchase_receipts(id) ON DELETE CASCADE,
        FOREIGN KEY (po_item_id) REFERENCES purchase_order_items(id) ON DELETE SET NULL,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
        INDEX idx_receipt (receipt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 7. supplier_invoices
    $conn->query("CREATE TABLE IF NOT EXISTS supplier_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(100) NOT NULL,
        po_id INT NOT NULL,
        receipt_id INT NULL,
        supplier_id INT NULL,
        invoice_date DATE NOT NULL,
        due_date DATE NULL,
        subtotal DECIMAL(12,2) DEFAULT 0.00,
        tax_amount DECIMAL(10,2) DEFAULT 0.00,
        discount_amount DECIMAL(10,2) DEFAULT 0.00,
        total_amount DECIMAL(12,2) DEFAULT 0.00,
        payment_status ENUM('Unpaid', 'Partially Paid', 'Paid') NOT NULL DEFAULT 'Unpaid',
        scanned_invoice_url VARCHAR(255) NULL,
        notes TEXT NULL,
        recorded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (receipt_id) REFERENCES purchase_receipts(id) ON DELETE SET NULL,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
        FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_invoice_number (invoice_number),
        INDEX idx_po (po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 8. supplier_performance
    $conn->query("CREATE TABLE IF NOT EXISTS supplier_performance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        po_id INT NULL,
        expected_delivery DATE NULL,
        actual_delivery DATE NULL,
        on_time TINYINT(1) DEFAULT 1,
        quality_issues INT DEFAULT 0,
        quality_notes TEXT NULL,
        recorded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'lead_time_days' => 'INT NOT NULL DEFAULT 7 AFTER payment_terms',
        'product_lines' => 'TEXT NULL AFTER lead_time_days',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM suppliers LIKE '$column'");
        if (!($check && $check->num_rows > 0)) {
            $conn->query("ALTER TABLE suppliers ADD COLUMN `$column` $definition");
        }
    }

    // 9. audit_log table
    $conn->query("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action_type VARCHAR(50) NOT NULL,
        table_name VARCHAR(50) NULL,
        record_id INT NULL,
        old_values JSON NULL,
        new_values JSON NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    ensureReceivingWorkflowSchema($conn);
    require_once __DIR__ . '/product_expiry_lib.php';
    productExpiryEnsureSchema($conn);
    require_once __DIR__ . '/product_catalog_fields_lib.php';
    productCatalogEnsureSchema($conn);
    require_once __DIR__ . '/product_barcodes_lib.php';
    productBarcodesEnsureSchema($conn);
}

if ($conn) {
    ensureProcurementSchema($conn);
}

$action = $_GET['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? '';
if (function_exists('clarenzon_api_guard_method')) {
    clarenzon_api_guard_method((string)$action);
}
$uid    = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? $roleRaw ?? '');

// Always refresh role from DB so Admin approval works even if session role is stale/mismatched
if ($conn && $uid > 0) {
    $roleStmt = $conn->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    if ($roleStmt) {
        $roleStmt->bind_param('i', $uid);
        $roleStmt->execute();
        $roleRes = $roleStmt->get_result();
        if ($roleRes && ($roleRow = $roleRes->fetch_assoc()) && !empty($roleRow['role'])) {
            $role = (string)$roleRow['role'];
            $_SESSION['role'] = $role;
        }
        $roleStmt->close();
    }
}

$roleKey = normalize_user_role($role);
$isAdminRole = ($roleKey === 'admin');
$isPurchaserRole = ($roleKey === 'purchaser');
$isWarehouseHeadRole = ($roleKey === 'warehouse_head');
$isSalesRole = in_array($roleKey, ['sales_representative', 'sales', 'sales_rep'], true)
    || in_array(strtolower(str_replace([' ', '-'], '_', $role)), ['sales_representative', 'sales', 'sales_rep'], true);
if ($conn instanceof mysqli) {
    ensureProductPricingSchema($conn);
}
$canManagePricing = userCanManageProductPricing($conn instanceof mysqli ? $conn : null, $uid, $role);
$canViewPricingQueue = ($isPurchaserRole || $isAdminRole) && recvUserHasPricingQueueAccess($conn instanceof mysqli ? $conn : null, $uid, $isPurchaserRole, $isAdminRole);
$canManageReceiptPricing = recvUserCanManageReceiptPricing($conn instanceof mysqli ? $conn : null, $uid, $isPurchaserRole);
$canSetReceiptMarkup = $canManageReceiptPricing; // Purchaser with Can Manage Pricing confirms receipt pricing
$canGrantStockIn = false; // Admin grant before Stock-In is no longer required
$canExecuteStockIn = $isWarehouseHeadRole; // Only Warehouse Head performs physical Stock-In

ob_start();

function jOut($d) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($d);
    exit();
}

/** Lightweight read-only aggregate for live change detection. Never mutates data. */
function liveChangesStamp(mysqli $conn, string $sql): string {
    $res = $conn->query($sql);
    if (!$res) {
        error_log('[procurement] get_live_changes: ' . $conn->error);
        return '';
    }
    $row = $res->fetch_assoc();
    if (!$row) {
        return '0:0:';
    }
    return ((int)($row['cnt'] ?? 0)) . ':' . ((int)($row['max_id'] ?? 0)) . ':' . (string)($row['max_ts'] ?? '') . ':' . (string)($row['ver'] ?? '');
}

function logProcurementAudit(mysqli $conn, int $userId, string $actionType, string $tableName, ?int $recordId, $oldValues = null, $newValues = null): void {
    if (!$conn) return;
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action_type, table_name, record_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $oldJson = $oldValues !== null ? json_encode($oldValues) : null;
        $newJson = $newValues !== null ? json_encode($newValues) : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt->bind_param('ississs', $userId, $actionType, $tableName, $recordId, $oldJson, $newJson, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function logProcurementUiAudit(mysqli $conn, int $userId, string $actionKey, string $details): void {
    if (!$conn) return;
    $username = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');
    $roleLabel = (string)($_SESSION['role'] ?? 'admin');
    if ($userId > 0) {
        $uq = $conn->query('SELECT COALESCE(NULLIF(full_name,\'\'), username) AS n, role FROM users WHERE id=' . (int)$userId . ' LIMIT 1');
        if ($uq && ($ur = $uq->fetch_assoc())) {
            if (!empty($ur['n'])) $username = (string)$ur['n'];
            if (!empty($ur['role'])) $roleLabel = (string)$ur['role'];
        }
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(100) NOT NULL,
            user_role VARCHAR(100) NOT NULL,
            action_key VARCHAR(100) NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, ?, ?)');
    if ($stmt) {
        $stmt->bind_param('issss', $userId, $username, $roleLabel, $actionKey, $details);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Auto-create a supplier invoice for a PO (idempotent: one invoice per PO).
 * Uses PO totals/supplier; optional receipt_id can be linked later.
 */
function supplierInvoiceGoodQtyPayable(mysqli $conn, int $poId): float {
    $q = $conn->query("SELECT COALESCE(SUM(
            GREATEST(0, pri.quantity_good) * COALESCE(NULLIF(pri.cost_price, 0), pri.unit_price, 0)
        ), 0) AS t
        FROM purchase_receipt_items pri
        INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
        WHERE rec.po_id = $poId");
    return $q ? (float)$q->fetch_assoc()['t'] : 0.0;
}

function syncSupplierInvoiceAmountForPO(mysqli $conn, int $poId, ?int $receiptId = null): void {
    if ($poId <= 0) return;
    $existing = $conn->query("SELECT id, payment_status, notes FROM supplier_invoices WHERE po_id=$poId ORDER BY id ASC LIMIT 1");
    if (!$existing || !($row = $existing->fetch_assoc())) return;
    $pay = strtolower(trim((string)($row['payment_status'] ?? '')));
    if (in_array($pay, ['paid', 'cancelled'], true)) return;
    $payable = supplierInvoiceGoodQtyPayable($conn, $poId);
    if ($payable <= 0) return;
    $id = (int)$row['id'];
    $note = trim((string)($row['notes'] ?? ''));
    $tag = 'Payable = good received qty × purchase price (replacement deducted).';
    if (strpos($note, $tag) === false) {
        $note = ($note !== '' ? $note . ' ' : '') . $tag;
    }
    $noteEsc = $conn->real_escape_string($note);
    $sql = "UPDATE supplier_invoices SET subtotal=$payable, tax_amount=0, discount_amount=0, total_amount=$payable, notes='$noteEsc'";
    if ($receiptId && $receiptId > 0) {
        $sql .= ", receipt_id=" . (int)$receiptId;
    }
    $sql .= " WHERE id=$id";
    $conn->query($sql);
}

function ensureSupplierInvoiceForPO(mysqli $conn, int $poId, int $uid, ?int $receiptId = null): array {
    if ($poId <= 0) {
        return ['success' => false, 'message' => 'Invalid PO ID'];
    }

    $existing = $conn->query("SELECT id, invoice_number, payment_status, receipt_id FROM supplier_invoices WHERE po_id=$poId ORDER BY id ASC LIMIT 1");
    if ($existing && ($row = $existing->fetch_assoc())) {
        if ($receiptId && empty($row['receipt_id'])) {
            $rid = (int)$receiptId;
            $conn->query("UPDATE supplier_invoices SET receipt_id=$rid WHERE id=" . (int)$row['id'] . " AND (receipt_id IS NULL OR receipt_id=0)");
            $row['receipt_id'] = $rid;
        }
        syncSupplierInvoiceAmountForPO($conn, $poId, $receiptId);
        return [
            'success' => true,
            'invoice_id' => (int)$row['id'],
            'invoice_number' => $row['invoice_number'],
            'payment_status' => $row['payment_status'],
            'already_existed' => true
        ];
    }

    $poQ = $conn->query("SELECT po.*, s.payment_terms
                         FROM purchase_orders po
                         LEFT JOIN suppliers s ON s.id = po.supplier_id
                         WHERE po.id=$poId LIMIT 1");
    $po = $poQ ? $poQ->fetch_assoc() : null;
    if (!$po) {
        return ['success' => false, 'message' => 'Purchase Order not found'];
    }
    $supplier_id = (int)($po['supplier_id'] ?? 0);
    if ($supplier_id <= 0) {
        return ['success' => false, 'message' => 'PO has no supplier; cannot create supplier invoice'];
    }

    $goodPayable = supplierInvoiceGoodQtyPayable($conn, $poId);
    $subtotal = $goodPayable > 0 ? $goodPayable : (float)($po['total_amount'] ?? 0);
    if ($subtotal <= 0) {
        $sumQ = $conn->query("SELECT COALESCE(SUM(quantity_ordered * unit_price),0) AS t FROM purchase_order_items WHERE po_id=$poId");
        $subtotal = $sumQ ? (float)$sumQ->fetch_assoc()['t'] : 0.0;
    }
    $tax = 0.0;
    $discount = 0.0;
    $total = $subtotal + $tax - $discount;
    $inv_date = date('Y-m-d');

    // Due date from supplier payment terms (default Net 30)
    $dueDays = 30;
    $terms = strtolower(trim((string)($po['payment_terms'] ?? 'Net 30')));
    if (preg_match('/(\d+)/', $terms, $m)) {
        $dueDays = max(1, (int)$m[1]);
    }
    $due_date = date('Y-m-d', strtotime("+{$dueDays} days"));

    $r = $conn->query("SELECT COUNT(*) AS cnt FROM supplier_invoices WHERE YEAR(created_at)=" . date('Y'));
    $cnt = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
    $inv_num = 'INV-' . date('Y') . '-' . str_pad($cnt + 1, 4, '0', STR_PAD_LEFT);
    $existsNum = $conn->query("SELECT id FROM supplier_invoices WHERE invoice_number='" . $conn->real_escape_string($inv_num) . "' LIMIT 1");
    if ($existsNum && $existsNum->num_rows > 0) {
        $inv_num = 'INV-' . date('Y') . '-' . str_pad($cnt + 1, 4, '0', STR_PAD_LEFT) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    $pay_status = 'Unpaid';
    $notes = 'Auto-generated from Purchase Order ' . ($po['po_number'] ?? $poId);
    if ($goodPayable > 0) {
        $notes .= ' Payable = good received qty × purchase price (replacement deducted).';
    }
    $receiptParam = ($receiptId && $receiptId > 0) ? (int)$receiptId : null;

    if ($receiptParam) {
        $stmt = $conn->prepare("INSERT INTO supplier_invoices (invoice_number, po_id, receipt_id, supplier_id, invoice_date, due_date, subtotal, tax_amount, discount_amount, total_amount, payment_status, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return ['success' => false, 'message' => $conn->error];
        }
        $stmt->bind_param('siiissddddssi', $inv_num, $poId, $receiptParam, $supplier_id, $inv_date, $due_date, $subtotal, $tax, $discount, $total, $pay_status, $notes, $uid);
    } else {
        $stmt = $conn->prepare("INSERT INTO supplier_invoices (invoice_number, po_id, receipt_id, supplier_id, invoice_date, due_date, subtotal, tax_amount, discount_amount, total_amount, payment_status, notes, recorded_by) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return ['success' => false, 'message' => $conn->error];
        }
        $stmt->bind_param('siissddddssi', $inv_num, $poId, $supplier_id, $inv_date, $due_date, $subtotal, $tax, $discount, $total, $pay_status, $notes, $uid);
    }
    if (!$stmt->execute()) {
        // Race: another request created it
        $again = $conn->query("SELECT id, invoice_number, payment_status FROM supplier_invoices WHERE po_id=$poId ORDER BY id ASC LIMIT 1");
        if ($again && ($row = $again->fetch_assoc())) {
            return [
                'success' => true,
                'invoice_id' => (int)$row['id'],
                'invoice_number' => $row['invoice_number'],
                'payment_status' => $row['payment_status'],
                'already_existed' => true
            ];
        }
        return ['success' => false, 'message' => $stmt->error ?: $conn->error];
    }
    $invoice_id = (int)$conn->insert_id;

    logProcurementAudit($conn, $uid, 'INVOICE_AUTO_CREATED', 'supplier_invoices', $invoice_id, null, [
        'invoice_number' => $inv_num,
        'po_id' => $poId,
        'po_number' => $po['po_number'] ?? null,
        'total' => $total,
        'payment_status' => $pay_status
    ]);

    createNotification(
        $conn,
        "Supplier Invoice Created: " . $inv_num,
        "Invoice " . $inv_num . " auto-created for PO " . ($po['po_number'] ?? $poId) . ". Amount: ₱" . number_format($total, 2) . " (Unpaid).",
        "billing_invoice",
        "medium",
        $inv_num,
        "procurement"
    );

    return [
        'success' => true,
        'invoice_id' => $invoice_id,
        'invoice_number' => $inv_num,
        'payment_status' => $pay_status,
        'already_existed' => false
    ];
}

/**
 * Display pipeline for a PR without mutating the stored status.
 * Requested → Pending Approval → Approved/Rejected → PO Created → Ordered → Received → Stocked In
 */
function prComputePipelineStatus(array $pr): string {
    $st = trim((string)($pr['status'] ?? ''));
    if ($st === 'Rejected') return 'Rejected';
    if ($st === 'Draft' || $st === '') return 'Requested';
    if ($st === 'Pending Approval') return 'Pending Approval';
    if ($st !== 'Approved') return $st;

    if (empty($pr['generated_po_id'])) return 'Approved';

    $recv = trim((string)($pr['po_receiving_status'] ?? ''));
    $recvN = function_exists('recvNormalizeWorkflowStatus') ? recvNormalizeWorkflowStatus($recv) : $recv;
    if ($recvN === (function_exists('recvWfStockedIn') ? recvWfStockedIn() : 'Stocked In') || strcasecmp($recv, 'Stocked In') === 0) {
        return 'Stocked In';
    }
    if ($recv !== '' && !in_array($recv, ['Expected to Arrive', ''], true)) {
        return 'Received';
    }
    $poStatus = trim((string)($pr['generated_po_status'] ?? ''));
    if (in_array($poStatus, ['Sent', 'Supplier Confirmed', 'Waiting for Delivery', 'Partially Received'], true)) {
        return 'Ordered';
    }
    return 'PO Created';
}

/** Products already on a submitted or approved PR must not be requested again. */
function prItemsHaveDuplicateProducts(array $items): ?string {
    $seen = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid <= 0) continue;
        if (isset($seen[$pid])) {
            $name = trim((string)($item['product_name'] ?? '')) ?: ('Product #' . $pid);
            return 'The same product cannot be added twice in one Purchase Request: ' . $name;
        }
        $seen[$pid] = true;
    }
    return null;
}

function productHasActivePurchaseRequest(mysqli $conn, int $productId, int $exceptPrId = 0): bool {
    if ($productId <= 0) return false;
    $sql = "SELECT pr.id
            FROM purchase_request_items pri
            INNER JOIN purchase_requests pr ON pr.id = pri.pr_id
            LEFT JOIN purchase_orders po ON po.pr_id = pr.id
              AND (pri.supplier_id IS NULL OR pri.supplier_id = 0 OR po.supplier_id = pri.supplier_id)
            WHERE pri.product_id = ?
              AND pr.status IN ('Pending Approval', 'Approved')
              AND (po.id IS NULL OR COALESCE(po.receiving_status,'') <> 'Stocked In')";
    if ($exceptPrId > 0) {
        $sql .= " AND pr.id <> " . (int)$exceptPrId;
    }
    $sql .= " LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('i', $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return !empty($row);
}

/** History-only record of items dropped from a PR during purchaser revision. */
function prDiffRemovedItems(array $oldItems, array $newItems, string $prNumber): array {
    $newPids = [];
    foreach ($newItems as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid > 0) {
            $newPids[$pid] = true;
        }
    }
    $removed = [];
    foreach ($oldItems as $old) {
        $pid = (int)($old['product_id'] ?? 0);
        if ($pid <= 0 || isset($newPids[$pid])) {
            continue;
        }
        $removed[] = [
            'product_id' => $pid,
            'product_name' => (string)($old['product_name'] ?? ''),
            'original_qty' => (int)($old['quantity_requested'] ?? 0),
            'action' => 'Removed during PR revision',
            'reason' => 'Supplier unavailable / out of stock',
            'original_pr' => $prNumber,
        ];
    }
    return $removed;
}

function nextPRNumber(mysqli $conn, int &$yearCnt): string {
    $yearCnt++;
    $pr_num = 'PR-' . date('Y') . '-' . str_pad($yearCnt, 4, '0', STR_PAD_LEFT);
    $existsNum = $conn->query("SELECT id FROM purchase_requests WHERE pr_number='" . $conn->real_escape_string($pr_num) . "' LIMIT 1");
    if ($existsNum && $existsNum->num_rows > 0) {
        $pr_num = 'PR-' . date('Y') . '-' . str_pad($yearCnt, 4, '0', STR_PAD_LEFT) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
    return $pr_num;
}

function resolvePRItemSupplierId(mysqli $conn, array $pi, array $pr): int {
    $prSid = (int)($pr['supplier_id'] ?? 0);
    if ($prSid > 0) {
        return $prSid;
    }
    $sid = (int)($pi['supplier_id'] ?? 0);
    if ($sid > 0) {
        return $sid;
    }
    return 0;
}

function prActiveSupplierExists(mysqli $conn, int $supplierId): bool {
    if ($supplierId <= 0) {
        return false;
    }
    $st = $conn->prepare("SELECT id FROM suppliers WHERE id=? AND status='Active' LIMIT 1");
    if (!$st) {
        throw new RuntimeException($conn->error ?: 'Could not validate supplier');
    }
    $st->bind_param('i', $supplierId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return !empty($row);
}

/** Catalog supplier is not a PR constraint. Product may be requested from any active supplier. */
function prValidateRequestedItem(mysqli $conn, array &$item): ?string {
    $pid = !empty($item['product_id']) ? (int)$item['product_id'] : 0;
    $pn = trim((string)($item['product_name'] ?? ''));
    $qty = (int)($item['quantity_requested'] ?? 0);
    $sid = (int)($item['supplier_id'] ?? 0);
    $unit = trim((string)($item['unit_of_measure'] ?? ''));
    $priceRaw = $item['estimated_unit_price'] ?? null;

    if ($pid <= 0 && $pn === '') {
        return 'Each requested item must include a product, supplier, valid quantity, unit, and price.';
    }
    if ($qty <= 0) {
        return 'Each requested item must have a quantity of at least 1.';
    }
    if ($sid <= 0) {
        return 'Select a supplier for each requested item.';
    }
    if (!prActiveSupplierExists($conn, $sid)) {
        return 'Each requested item must have an active supplier.';
    }

    if ($pid > 0) {
        $pq = $conn->prepare("SELECT name, brand, cost_price, unit_of_measure FROM products WHERE id=? AND status != 'Discontinued' LIMIT 1");
        if (!$pq) {
            throw new RuntimeException($conn->error ?: 'Could not validate product');
        }
        $pq->bind_param('i', $pid);
        $pq->execute();
        $prod = $pq->get_result()->fetch_assoc();
        $pq->close();
        if (!$prod) {
            return 'Product not found or discontinued.';
        }
        if ($pn === '') {
            $pn = !empty($prod['brand']) ? ($prod['name'] . ' (' . $prod['brand'] . ')') : (string)$prod['name'];
        }
        if ($unit === '') {
            $unit = trim((string)($prod['unit_of_measure'] ?? ''));
        }
        if ($priceRaw === null || $priceRaw === '' || !is_numeric($priceRaw)) {
            $catalogCost = (float)($prod['cost_price'] ?? 0);
            if ($catalogCost >= 0) {
                $priceRaw = $catalogCost;
            }
        }
        $item['product_id'] = $pid;
        $item['product_name'] = $pn;
    }

    if ($priceRaw === null || $priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
        return 'Each requested item must have a valid price.';
    }
    if ($unit === '') {
        return 'Each requested item must have a unit.';
    }

    $item['supplier_id'] = $sid;
    $item['quantity_requested'] = $qty;
    $item['unit_of_measure'] = $unit;
    $item['estimated_unit_price'] = (float)$priceRaw;
    return null;
}

function prGroupItemsBySupplier(array $items): array {
    $groups = [];
    foreach ($items as $item) {
        $sid = (int)($item['supplier_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $groups[$sid][] = $item;
    }
    return $groups;
}

function prPurposeSupplierKey(string $purpose, int $supplierId): string {
    return $purpose . "\0" . $supplierId;
}

function prParsePurposeSupplierKey(string $key): array {
    $parts = explode("\0", $key, 2);
    return [
        'purpose' => (string)($parts[0] ?? ''),
        'supplier_id' => (int)($parts[1] ?? 0),
    ];
}

function prGroupItemsByPurposeAndSupplier(array $items): array {
    $groups = [];
    foreach ($items as $item) {
        $purpose = trim((string)($item['purpose'] ?? ''));
        $sid = (int)($item['supplier_id'] ?? 0);
        if ($purpose === '' || $sid <= 0) {
            continue;
        }
        $groups[prPurposeSupplierKey($purpose, $sid)][] = $item;
    }
    return $groups;
}

function prFallbackAllowedPurpose(string $purpose): string {
    $purpose = trim($purpose);
    return in_array($purpose, prAllowedPurposes(), true) ? $purpose : '';
}

function prPrepareSubmittedPRItems(
    array $items,
    string $fallbackPurpose,
    string $fallbackRemarks,
    string $fallbackCustomerRef
): array {
    $fallbackPurpose = prFallbackAllowedPurpose($fallbackPurpose);
    $out = [];
    foreach ($items as $item) {
        $purpose = trim((string)($item['purpose'] ?? ''));
        if ($purpose === '' || !in_array($purpose, prAllowedPurposes(), true)) {
            $purpose = $fallbackPurpose;
        }
        $item['purpose'] = $purpose;
        $itemRef = trim((string)($item['customer_order_reference'] ?? ''));
        $itemRemarks = trim((string)($item['remarks'] ?? ''));
        if ($itemRef === '' && $purpose === 'Customer Order' && $fallbackPurpose === 'Customer Order') {
            $itemRef = $fallbackCustomerRef;
        }
        if ($itemRemarks === '' && $purpose === $fallbackPurpose) {
            $itemRemarks = $fallbackRemarks;
        }
        $item['customer_order_reference'] = prNormalizeCustomerOrderRef($purpose, $itemRef);
        $item['remarks'] = prNormalizePurposeRemarks($purpose, $itemRemarks);
        $out[] = $item;
    }
    return $out;
}

function prGroupPurposeHeaderFields(array $groupItems, string $purpose): array {
    $ref = '';
    $remarks = '';
    foreach ($groupItems as $item) {
        if ($ref === '') {
            $ref = trim((string)($item['customer_order_reference'] ?? ''));
        }
        if ($remarks === '') {
            $remarks = trim((string)($item['remarks'] ?? ''));
        }
    }
    return [
        prNormalizeCustomerOrderRef($purpose, $ref),
        prNormalizePurposeRemarks($purpose, $remarks),
    ];
}

function prBuildPurposeSupplierGroups(array $items): array {
    $grouped = prGroupItemsByPurposeAndSupplier($items);
    $out = [];
    foreach ($grouped as $key => $groupItems) {
        $meta = prParsePurposeSupplierKey($key);
        $purpose = $meta['purpose'];
        [$ref, $remarks] = prGroupPurposeHeaderFields($groupItems, $purpose);
        $out[$key] = [
            'key' => $key,
            'purpose' => $purpose,
            'supplier_id' => $meta['supplier_id'],
            'items' => $groupItems,
            'customer_order_reference' => $ref,
            'remarks' => $remarks,
        ];
    }
    return $out;
}

function prDuplicateProductsInGroups(array $groups): ?string {
    foreach ($groups as $group) {
        $groupItems = isset($group['items']) && is_array($group['items']) ? $group['items'] : (is_array($group) ? $group : []);
        $msg = prItemsHaveDuplicateProducts($groupItems);
        if ($msg) {
            return $msg;
        }
    }
    return null;
}

function prNormalizeItemsToSupplier(array $items, int $supplierId): array {
    foreach ($items as &$item) {
        $item['supplier_id'] = $supplierId;
    }
    unset($item);
    return $items;
}

/** 1 PR = 1 supplier. Catalog product-supplier is not a constraint. */
function prAssertSingleSupplierItems(mysqli $conn, array $items, int $supplierId): void {
    if ($supplierId <= 0) {
        throw new RuntimeException('Each Purchase Request must have a supplier. One PR can only be for one supplier.');
    }
    if (!prActiveSupplierExists($conn, $supplierId)) {
        throw new RuntimeException('Each Purchase Request must have an active supplier.');
    }
    foreach ($items as $item) {
        $itemSid = (int)($item['supplier_id'] ?? 0);
        if ($itemSid <= 0) {
            throw new RuntimeException('Select a supplier for each requested item.');
        }
        if ($itemSid !== $supplierId) {
            throw new RuntimeException('A Purchase Request can only contain items from one supplier. Create a separate PR for each supplier.');
        }
        $tmp = $item;
        $msg = prValidateRequestedItem($conn, $tmp);
        if ($msg) {
            throw new RuntimeException($msg);
        }
    }
}

function prAllowedPurposes(): array {
    return ['Low Stock Replenishment', 'Customer Order', 'Others'];
}

function prDisplayPurpose($purpose): string {
    $purpose = trim((string)$purpose);
    return $purpose !== '' ? $purpose : 'Not Specified';
}

function prNormalizeCustomerOrderRef(string $purpose, string $ref): string {
    if ($purpose !== 'Customer Order') {
        return '';
    }
    return trim($ref);
}

function prNormalizePurposeRemarks(string $purpose, string $remarks): string {
    $remarks = trim($remarks);
    if ($purpose === 'Low Stock Replenishment') {
        return '';
    }
    return $remarks;
}

function prValidatePurposeFields(string $purpose, string $remarks, string $customerOrderRef, bool $requirePurpose = true): ?string {
    if ($purpose === '') {
        return $requirePurpose ? 'Please select a Purpose.' : null;
    }
    if (!in_array($purpose, prAllowedPurposes(), true)) {
        return 'Invalid Purpose selected.';
    }
    if ($purpose === 'Customer Order' && $customerOrderRef === '') {
        return 'Customer Order Reference is required.';
    }
    // Purpose Details are required only for Others — never for Low Stock or Customer Order.
    if ($purpose === 'Others' && $remarks === '') {
        return 'Purpose Details are required.';
    }
    if (strlen($customerOrderRef) > 120) {
        return 'Customer Order Reference must be 120 characters or fewer.';
    }
    return null;
}

function prCustomerOrdersTableExists(mysqli $conn): bool {
    $t1 = @$conn->query("SHOW TABLES LIKE 'sales_orders'");
    $t2 = @$conn->query("SHOW TABLES LIKE 'sales_order_items'");
    return (bool)($t1 && $t1->num_rows && $t2 && $t2->num_rows);
}

function ensureCustomersSchema(mysqli $conn): void {
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
        'first_name' => "VARCHAR(80) NULL AFTER name",
        'last_name' => "VARCHAR(80) NULL AFTER first_name",
        'po_reference' => "VARCHAR(120) NULL AFTER email",
    ] as $colName => $definition) {
        $col = @$conn->query("SHOW COLUMNS FROM customers LIKE '" . $conn->real_escape_string($colName) . "'");
        if (!$col || $col->num_rows === 0) {
            @$conn->query("ALTER TABLE customers ADD COLUMN `$colName` $definition");
        }
    }

    // Seed from existing sales / invoice customer names (read-only sources).
    $sources = [];
    $tSo = @$conn->query("SHOW TABLES LIKE 'sales_orders'");
    if ($tSo && $tSo->num_rows > 0) {
        $sources[] = "SELECT DISTINCT TRIM(customer_name) AS name FROM sales_orders
                      WHERE customer_name IS NOT NULL AND TRIM(customer_name) <> '' AND customer_name <> '0'";
    }
    $tInv = @$conn->query("SHOW TABLES LIKE 'invoices'");
    if ($tInv && $tInv->num_rows > 0) {
        $sources[] = "SELECT DISTINCT TRIM(customer_name) AS name FROM invoices
                      WHERE customer_name IS NOT NULL AND TRIM(customer_name) <> '' AND customer_name <> '0'";
    }
    foreach ($sources as $sql) {
        $res = @$conn->query($sql);
        if (!$res) {
            continue;
        }
        while ($row = $res->fetch_assoc()) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $ins = $conn->prepare("INSERT IGNORE INTO customers (name, status) VALUES (?, 'Active')");
            if ($ins) {
                $ins->bind_param('s', $name);
                $ins->execute();
                $ins->close();
            }
        }
    }
}

/** Active customers for Create PR Others searchable dropdown. */
function prLoadCustomersForCreate(mysqli $conn, string $q = ''): array {
    ensureCustomersSchema($conn);
    $q = trim($q);
    $rows = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $st = $conn->prepare("SELECT id, name, phone, email FROM customers
                             WHERE status='Active' AND name LIKE ?
                             ORDER BY name ASC LIMIT 100");
        if ($st) {
            $st->bind_param('s', $like);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) {
                $rows[] = [
                    'id' => (int)$r['id'],
                    'name' => (string)$r['name'],
                    'phone' => (string)($r['phone'] ?? ''),
                    'email' => (string)($r['email'] ?? ''),
                ];
            }
            $st->close();
        }
        return $rows;
    }
    $res = @$conn->query("SELECT id, name, phone, email FROM customers WHERE status='Active' ORDER BY name ASC LIMIT 300");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'phone' => (string)($r['phone'] ?? ''),
                'email' => (string)($r['email'] ?? ''),
            ];
        }
    }
    return $rows;
}

function ensureCompaniesSchema(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        address VARCHAR(255) NULL,
        contact_person VARCHAR(150) NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(120) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_companies_name (name),
        INDEX idx_companies_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function prLoadPartyNamesForCreate(mysqli $conn): array {
    ensureCustomersSchema($conn);
    ensureCompaniesSchema($conn);
    $rows = [];
    $cust = @$conn->query("SELECT id, name, po_reference FROM customers WHERE status='Active' AND TRIM(name) <> '' ORDER BY name ASC LIMIT 300");
    if ($cust) {
        while ($r = $cust->fetch_assoc()) {
            $rows[] = [
                'id' => 'cust-' . (int)$r['id'],
                'name' => trim((string)($r['name'] ?? '')),
                'kind' => 'customer',
                'po_reference' => trim((string)($r['po_reference'] ?? '')),
            ];
        }
    }
    $cos = @$conn->query("SELECT id, name FROM companies WHERE status='Active' AND TRIM(name) <> '' ORDER BY name ASC LIMIT 300");
    if ($cos) {
        while ($r = $cos->fetch_assoc()) {
            $rows[] = [
                'id' => 'co-' . (int)$r['id'],
                'name' => trim((string)($r['name'] ?? '')),
                'kind' => 'company',
                'po_reference' => '',
            ];
        }
    }
    return $rows;
}

/** Read-only list of existing customer orders/POs for Create PR (does not create sales records). */
function prLoadCustomerOrdersForCreate(mysqli $conn): array {
    if (!prCustomerOrdersTableExists($conn)) {
        return [];
    }
    $hasPo = false;
    $col = @$conn->query("SHOW COLUMNS FROM sales_orders LIKE 'customer_po_number'");
    if ($col && $col->num_rows > 0) {
        $hasPo = true;
    }
    $poSelect = $hasPo ? 'so.customer_po_number' : 'NULL AS customer_po_number';
    $sql = "SELECT so.id, so.order_number, so.customer_name, so.status, so.created_at, $poSelect
            FROM sales_orders so
            WHERE so.status <> 'Cancelled'
            ORDER BY so.created_at DESC
            LIMIT 300";
    $res = @$conn->query($sql);
    $orders = [];
    $ids = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $id = (int)$r['id'];
            $r['id'] = $id;
            $r['customer_po_number'] = trim((string)($r['customer_po_number'] ?? ''));
            $r['customer_name'] = trim((string)($r['customer_name'] ?? ''));
            $r['order_number'] = trim((string)($r['order_number'] ?? ''));
            $r['items'] = [];
            $orders[$id] = $r;
            $ids[] = $id;
        }
    }
    if (!$ids) {
        return [];
    }
    $idList = implode(',', array_map('intval', $ids));
    $itemSql = "SELECT soi.sales_order_id, soi.product_id, soi.quantity,
                       p.name AS product_name, p.sku, p.unit_of_measure, p.cost_price,
                       p.quantity_in_stock, p.reorder_level
                FROM sales_order_items soi
                LEFT JOIN products p ON p.id = soi.product_id
                WHERE soi.sales_order_id IN ($idList)
                ORDER BY soi.id ASC";
    $itemRes = @$conn->query($itemSql);
    $sellableMap = function_exists('invWmsSellableQtyMap') ? invWmsSellableQtyMap($conn) : [];
    if ($itemRes) {
        while ($it = $itemRes->fetch_assoc()) {
            $oid = (int)$it['sales_order_id'];
            if (!isset($orders[$oid])) {
                continue;
            }
            $pid = (int)$it['product_id'];
            $ordered = (int)$it['quantity'];
            $attached = function_exists('invWmsAttachSellableLowStock')
                ? invWmsAttachSellableLowStock([
                    'id' => $pid,
                    'quantity_in_stock' => (int)($it['quantity_in_stock'] ?? 0),
                    'reorder_level' => (int)($it['reorder_level'] ?? 0),
                ], $sellableMap)
                : ['sellable_qty' => (int)($it['quantity_in_stock'] ?? 0)];
            $available = (int)($attached['sellable_qty'] ?? 0);
            $orders[$oid]['items'][] = [
                'product_id' => $pid,
                'product_name' => (string)($it['product_name'] ?? 'Unknown product'),
                'sku' => (string)($it['sku'] ?? ''),
                'ordered_qty' => $ordered,
                'available_qty' => $available,
                'required_qty' => max(0, $ordered - $available),
                'unit' => (string)($it['unit_of_measure'] ?? ''),
                'cost_price' => (float)($it['cost_price'] ?? 0),
            ];
        }
    }
    return array_values($orders);
}

function prLoadCustomerPoEntriesForCreate(mysqli $conn): array {
    ensureCustomersSchema($conn);
    $hasPo = false;
    $col = @$conn->query("SHOW COLUMNS FROM customers LIKE 'po_reference'");
    if ($col && $col->num_rows > 0) {
        $hasPo = true;
    }
    if (!$hasPo) {
        return [];
    }
    $res = @$conn->query("SELECT id, name, phone, email, po_reference
                          FROM customers
                          WHERE status='Active'
                            AND po_reference IS NOT NULL
                            AND TRIM(po_reference) <> ''
                          ORDER BY updated_at DESC, name ASC
                          LIMIT 200");
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id' => 'cust-' . (int)$r['id'],
                'customer_id' => (int)$r['id'],
                'order_number' => trim((string)($r['po_reference'] ?? '')),
                'customer_po_number' => trim((string)($r['po_reference'] ?? '')),
                'customer_name' => trim((string)($r['name'] ?? '')),
                'status' => 'Active',
                'items' => [],
                '_from_customer' => true,
            ];
        }
    }
    return $rows;
}

function prMergeCustomerOrderLists(array $salesOrders, array $customerEntries): array {
    $seen = [];
    $out = [];
    foreach ($salesOrders as $o) {
        $po = strtolower(trim((string)($o['customer_po_number'] ?? $o['order_number'] ?? '')));
        $name = strtolower(trim((string)($o['customer_name'] ?? '')));
        $seen[$po . '|' . $name] = true;
        $out[] = $o;
    }
    foreach ($customerEntries as $c) {
        $po = strtolower(trim((string)($c['customer_po_number'] ?? '')));
        $name = strtolower(trim((string)($c['customer_name'] ?? '')));
        $key = $po . '|' . $name;
        if ($po !== '' && isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $c;
    }
    return $out;
}

function prAttachPurposeFields(array $row): array {
    $row['purpose'] = trim((string)($row['purpose'] ?? ''));
    $row['purpose_label'] = prDisplayPurpose($row['purpose']);
    $row['customer_order_reference'] = trim((string)($row['customer_order_reference'] ?? ''));
    $row['customer_name'] = trim((string)($row['customer_name'] ?? ''));
    return $row;
}

function insertPurchaseRequestWithItems(
    mysqli $conn,
    int $uid,
    int $supplier_id,
    array $items,
    string $reason,
    string $remarks,
    string $status,
    int &$yearCnt,
    string $purpose = '',
    string $customerOrderRef = '',
    bool $preserveItemSuppliers = false,
    string $customerName = ''
): array {
    if ($supplier_id <= 0) {
        throw new RuntimeException('Each Purchase Request must have a supplier');
    }
    if (empty($items)) {
        throw new RuntimeException('Purchase Request has no items');
    }
    $pr_num = nextPRNumber($conn, $yearCnt);
    $total = 0.0;
    foreach ($items as $item) {
        $total += ((int)($item['quantity_requested'] ?? 0)) * ((float)($item['estimated_unit_price'] ?? 0));
    }

    $customerName = $purpose === 'Customer Order' ? trim($customerName) : '';
    $stmt = $conn->prepare("INSERT INTO purchase_requests (pr_number, requested_by, supplier_id, status, reason, remarks, purpose, customer_order_reference, customer_name, total_estimated_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new RuntimeException($conn->error ?: 'Failed to prepare PR insert');
    $stmt->bind_param('siissssssd', $pr_num, $uid, $supplier_id, $status, $reason, $remarks, $purpose, $customerOrderRef, $customerName, $total);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
    $pr_id = (int)$conn->insert_id;

    $inserted = 0;
    foreach ($items as $item) {
        $pid  = !empty($item['product_id']) ? (int)$item['product_id'] : null;
        $pn   = trim($item['product_name'] ?? '');
        $qty  = (int)($item['quantity_requested'] ?? 1);
        $up   = (float)($item['estimated_unit_price'] ?? 0);
        $rem  = trim($item['remarks'] ?? '');
        $itemSupplierId = $preserveItemSuppliers
            ? (int)($item['supplier_id'] ?? $supplier_id)
            : $supplier_id;

        if ($pid) {
            $pq = $conn->prepare("SELECT p.name, p.brand, p.cost_price, p.unit_of_measure, p.quantity_in_stock, p.reorder_level, p.supplier_id
                                  FROM products p WHERE p.id = ? AND p.status != 'Discontinued' LIMIT 1");
            if ($pq) {
                $pq->bind_param('i', $pid);
                $pq->execute();
                $prod = $pq->get_result()->fetch_assoc();
                if ($prod) {
                    if ($pn === '') {
                        $pn = !empty($prod['brand']) ? ($prod['name'] . ' (' . $prod['brand'] . ')') : $prod['name'];
                    }
                    $catalogCost = (float)$prod['cost_price'];
                    if ($catalogCost <= 0) $catalogCost = (float)($prod['unit_price'] ?? 0);
                    if ($up <= 0 && $catalogCost > 0) $up = $catalogCost;
                    elseif ($catalogCost > 0 && abs($up - $catalogCost) < 0.0001) $up = $catalogCost;
                    if ($rem === '') {
                        $rem = 'Stock ' . (int)$prod['quantity_in_stock'] . ' / Min ' . (int)$prod['reorder_level']
                             . ' · Unit ' . ($prod['unit_of_measure'] ?: '—');
                    }
                }
            }
        }
        if ($pn === '' || $qty <= 0) continue;
        if ($itemSupplierId <= 0) {
            throw new RuntimeException('Each requested item must have a supplier');
        }
        if (!$preserveItemSuppliers && $itemSupplierId !== $supplier_id) {
            throw new RuntimeException('All items in a Purchase Request must belong to the same supplier');
        }

        $si = $conn->prepare("INSERT INTO purchase_request_items (pr_id, product_id, supplier_id, product_name, quantity_requested, estimated_unit_price, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $si->bind_param('iiisids', $pr_id, $pid, $itemSupplierId, $pn, $qty, $up, $rem);
        if (!$si->execute()) throw new RuntimeException($si->error ?: $conn->error);
        $inserted++;
    }
    if ($inserted === 0) {
        throw new RuntimeException('Purchase Request has no valid items');
    }

    $sumQ = $conn->query("SELECT COALESCE(SUM(quantity_requested * estimated_unit_price),0) AS t FROM purchase_request_items WHERE pr_id=$pr_id");
    $realTotal = $sumQ ? (float)$sumQ->fetch_assoc()['t'] : $total;
    $conn->query("UPDATE purchase_requests SET total_estimated_amount=" . (float)$realTotal . " WHERE id=$pr_id");

    logProcurementAudit($conn, $uid, 'PR_CREATED', 'purchase_requests', $pr_id, null, [
        'pr_number' => $pr_num,
        'status' => $status,
        'supplier_id' => $supplier_id,
        'purpose' => $purpose,
        'customer_order_reference' => $customerOrderRef,
        'customer_name' => $customerName,
        'total' => $realTotal,
    ]);

    return [
        'pr_id' => $pr_id,
        'pr_number' => $pr_num,
        'supplier_id' => $supplier_id,
        'total' => $realTotal,
        'item_count' => $inserted,
    ];
}

function nextGeneratedPONumber(mysqli $conn, int &$yearCnt): string {
    $year = date('Y');
    $maxSeq = 0;
    $like = 'PO-' . $year . '-%';
    $res = $conn->query("SELECT po_number FROM purchase_orders WHERE po_number LIKE '" . $conn->real_escape_string($like) . "'");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if (preg_match('/^PO-\d{4}-(\d{4})$/', (string)$r['po_number'], $m)) {
                $maxSeq = max($maxSeq, (int)$m[1]);
            }
        }
    }
    $seq = max($maxSeq, $yearCnt) + 1;
    for ($i = 0; $i < 10000; $i++, $seq++) {
        $po_num = 'PO-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        if (preg_match('/MIXTEST|MIXFIX|MIX-|TEST|DEBUG/i', $po_num)) {
            continue;
        }
        $existsNum = $conn->query("SELECT id FROM purchase_orders WHERE po_number='" . $conn->real_escape_string($po_num) . "' LIMIT 1");
        if ($existsNum && $existsNum->num_rows > 0) {
            continue;
        }
        $yearCnt = $seq;
        return $po_num;
    }
    throw new RuntimeException('Unable to allocate a unique Purchase Order number');
}

function packGeneratedPOResult(array $created): array {
    $allExisted = true;
    foreach ($created as $c) {
        if (empty($c['already_existed'])) $allExisted = false;
    }
    $first = $created[0];
    $nums = array_column($created, 'po_number');
    $invNums = array_values(array_filter(array_map(fn($c) => $c['invoice_number'] ?? null, $created)));
    return [
        'success' => true,
        'po_id' => (int)$first['po_id'],
        'po_number' => implode(', ', $nums),
        'po_numbers' => $nums,
        'po_ids' => array_map(fn($c) => (int)$c['po_id'], $created),
        'pos' => $created,
        'po_status' => $first['po_status'] ?? 'Approved',
        'already_existed' => $allExisted,
        'invoice_id' => $first['invoice_id'] ?? null,
        'invoice_number' => $invNums ? implode(', ', $invNums) : ($first['invoice_number'] ?? null),
        'message' => $allExisted ? ('PO already exists for this PR: ' . implode(', ', $nums)) : null,
    ];
}

function insertGeneratedPOFromPRItems(mysqli $conn, array $pr, int $uid, int $supplier_id, array $prItems, string $edd, string $notes, int &$yearCnt): array {
    $pr_id = (int)$pr['id'];
    try {
        $po_num = nextGeneratedPONumber($conn, $yearCnt);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
    if (!preg_match('/^PO-\d{4}-\d{4}$/', $po_num)) {
        return ['success' => false, 'message' => 'Purchase Order number does not match the system format.'];
    }
    $status = 'Approved';
    $total_amount = 0.0;
    $orderDate = date('Y-m-d');
    $requestedBy = (int)$pr['requested_by'];

    $stmt = $conn->prepare("INSERT INTO purchase_orders (pr_id, po_number, supplier_id, order_date, status, total_amount, expected_delivery_date, notes, requested_by, created_by, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) return ['success' => false, 'message' => $conn->error];
    $stmt->bind_param('isissdssiii', $pr_id, $po_num, $supplier_id, $orderDate, $status, $total_amount, $edd, $notes, $requestedBy, $uid, $uid);
    if (!$stmt->execute()) {
        if ($conn->errno === 1062) {
            $again = $conn->query("SELECT id, po_number, status FROM purchase_orders WHERE pr_id=$pr_id AND supplier_id=$supplier_id ORDER BY id DESC LIMIT 1");
            if ($again && ($row = $again->fetch_assoc())) {
                $inv = ensureSupplierInvoiceForPO($conn, (int)$row['id'], $uid);
                return [
                    'success' => true,
                    'po_id' => (int)$row['id'],
                    'po_number' => $row['po_number'],
                    'po_status' => $row['status'],
                    'already_existed' => true,
                    'invoice_id' => $inv['invoice_id'] ?? null,
                    'invoice_number' => $inv['invoice_number'] ?? null,
                    'message' => 'PO already exists for this supplier'
                ];
            }
        }
        return ['success' => false, 'message' => $conn->error];
    }
    $po_id = (int)$conn->insert_id;
    $conn->query("UPDATE purchase_orders SET receiving_status='Expected to Arrive' WHERE id=$po_id");

    foreach ($prItems as $pi) {
        $up = (float)$pi['estimated_unit_price'];
        $pid = !empty($pi['product_id']) ? (int)$pi['product_id'] : null;
        if ($pid && $up <= 0) {
            $cp = $conn->query("SELECT cost_price, unit_price FROM products WHERE id=$pid LIMIT 1");
            if ($cp && ($crow = $cp->fetch_assoc())) {
                $up = (float)$crow['cost_price'] > 0 ? (float)$crow['cost_price'] : (float)$crow['unit_price'];
            }
        }
        $si = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, product_name, quantity_ordered, unit_price, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $pname = $pi['product_name'];
        $qty = (int)$pi['quantity_requested'];
        $rem = $pi['remarks'] ?? '';
        $si->bind_param('iisids', $po_id, $pid, $pname, $qty, $up, $rem);
        $si->execute();
        $total_amount += $qty * $up;
    }
    $conn->query("UPDATE purchase_orders SET total_amount=" . (float)$total_amount . " WHERE id=$po_id");

    logProcurementAudit($conn, $uid, 'PO_GENERATED_FROM_PR', 'purchase_orders', $po_id, null, [
        'po_number' => $po_num,
        'pr_id' => $pr_id,
        'pr_number' => $pr['pr_number'],
        'supplier_id' => $supplier_id,
    ]);

    $inv = ensureSupplierInvoiceForPO($conn, $po_id, $uid);

    return [
        'success' => true,
        'po_id' => $po_id,
        'po_number' => $po_num,
        'po_status' => $status,
        'already_existed' => false,
        'invoice_id' => $inv['invoice_id'] ?? null,
        'invoice_number' => $inv['invoice_number'] ?? null,
        'supplier_id' => $supplier_id,
    ];
}

/**
 * Create one PO from an approved PR (1 PR = 1 supplier = 1 PO).
 * Uses the PR header supplier. Idempotent per (PR, supplier).
 */
function generatePOFromApprovedPR(mysqli $conn, array $pr, int $uid, ?int $supplierOverride = null, ?string $edd = null, ?string $notes = null): array {
    $pr_id = (int)$pr['id'];

    $priQ = $conn->query("SELECT * FROM purchase_request_items WHERE pr_id=$pr_id ORDER BY id ASC");
    $prItems = [];
    if ($priQ) while ($item = $priQ->fetch_assoc()) $prItems[] = $item;
    if (empty($prItems)) {
        return ['success' => false, 'message' => 'Purchase Request has no items to convert into a PO'];
    }
    foreach ($prItems as $pi) {
        if ((int)($pi['quantity_requested'] ?? 0) <= 0) {
            return ['success' => false, 'message' => 'All PR items must have a quantity greater than zero'];
        }
        if (trim((string)($pi['product_name'] ?? '')) === '') {
            return ['success' => false, 'message' => 'All PR items must have a product name'];
        }
    }

    $prSid = (int)($pr['supplier_id'] ?? 0);
    if ($prSid <= 0) {
        return ['success' => false, 'message' => 'Purchase Request has no supplier. One PR can only generate a PO for one supplier.'];
    }
    foreach ($prItems as $pi) {
        $itemSid = (int)($pi['supplier_id'] ?? 0);
        if ($itemSid > 0 && $itemSid !== $prSid) {
            return ['success' => false, 'message' => 'A Purchase Request can only contain items from one supplier. Cannot generate a Purchase Order.'];
        }
    }
    $groups = [$prSid => $prItems];

    $existingPOs = [];
    $exQ = $conn->query("SELECT id, po_number, status, supplier_id FROM purchase_orders WHERE pr_id=$pr_id ORDER BY id ASC");
    if ($exQ) while ($row = $exQ->fetch_assoc()) $existingPOs[] = $row;

    $existingBySupplier = [];
    foreach ($existingPOs as $row) {
        $sid = (int)($row['supplier_id'] ?? 0);
        if ($sid > 0 && !isset($existingBySupplier[$sid])) {
            $existingBySupplier[$sid] = $row;
        }
    }

    $alreadyProductIds = [];
    $poiQ = $conn->query("SELECT poi.product_id FROM purchase_order_items poi INNER JOIN purchase_orders po ON po.id=poi.po_id WHERE po.pr_id=$pr_id AND poi.product_id IS NOT NULL AND poi.product_id > 0");
    if ($poiQ) {
        while ($row = $poiQ->fetch_assoc()) {
            $alreadyProductIds[(int)$row['product_id']] = true;
        }
    }

    $edd = $edd ?: date('Y-m-d', strtotime('+7 days'));
    $notes = $notes !== null && $notes !== ''
        ? $notes
        : trim('Generated from Purchase Request ' . $pr['pr_number'] . '. ' . ($pr['reason'] ?? ''));

    $r = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_orders WHERE YEAR(created_at)=" . date('Y'));
    $yearCnt = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    $created = [];
    foreach ($groups as $sid => $items) {
        if (isset($existingBySupplier[$sid])) {
            $row = $existingBySupplier[$sid];
            $inv = ensureSupplierInvoiceForPO($conn, (int)$row['id'], $uid);
            $created[] = [
                'po_id' => (int)$row['id'],
                'po_number' => $row['po_number'],
                'po_status' => $row['status'],
                'already_existed' => true,
                'invoice_id' => $inv['invoice_id'] ?? null,
                'invoice_number' => $inv['invoice_number'] ?? null,
                'supplier_id' => $sid,
            ];
            continue;
        }
        $alreadyOnPo = false;
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            if ($pid > 0 && isset($alreadyProductIds[$pid])) {
                $alreadyOnPo = true;
                break;
            }
        }
        if ($alreadyOnPo) {
            continue;
        }
        $one = insertGeneratedPOFromPRItems($conn, $pr, $uid, (int)$sid, $items, $edd, $notes, $yearCnt);
        if (empty($one['success'])) return $one;
        $created[] = $one;
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            if ($pid > 0) $alreadyProductIds[$pid] = true;
        }
    }

    if (empty($created) && !empty($existingPOs)) {
        $pack = [];
        foreach ($existingPOs as $row) {
            $inv = ensureSupplierInvoiceForPO($conn, (int)$row['id'], $uid);
            $pack[] = [
                'po_id' => (int)$row['id'],
                'po_number' => $row['po_number'],
                'po_status' => $row['status'],
                'already_existed' => true,
                'invoice_id' => $inv['invoice_id'] ?? null,
                'invoice_number' => $inv['invoice_number'] ?? null,
                'supplier_id' => (int)($row['supplier_id'] ?? 0),
            ];
        }
        return packGeneratedPOResult($pack);
    }
    if (empty($created)) {
        return ['success' => false, 'message' => 'Could not generate a Purchase Order for this PR'];
    }

    return packGeneratedPOResult($created);
}

/** Current PR lines, used to describe what changed between two saves. */
function prItemsSnapshot(mysqli $conn, int $prId): array {
    $rows = [];
    $st = $conn->prepare("SELECT pri.id, pri.product_id, pri.product_name, pri.quantity_requested, pri.estimated_unit_price,
                                 pri.supplier_id, s.name AS supplier_name
                          FROM purchase_request_items pri
                          LEFT JOIN suppliers s ON s.id = pri.supplier_id
                          WHERE pri.pr_id = ? ORDER BY pri.id ASC");
    if (!$st) return $rows;
    $st->bind_param('i', $prId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = $r;
    }
    $st->close();
    return $rows;
}

function prItemDiffKey(array $item): string {
    $pid = (int)($item['product_id'] ?? 0);
    return $pid > 0 ? ('p' . $pid) : ('n' . strtolower(trim((string)($item['product_name'] ?? ''))));
}

/** Human-readable list of item/quantity/price changes between two PR snapshots. */
function prDescribeItemChanges(array $before, array $after): array {
    $old = [];
    foreach ($before as $it) $old[prItemDiffKey($it)] = $it;
    $new = [];
    foreach ($after as $it) $new[prItemDiffKey($it)] = $it;
    $changes = [];
    foreach ($new as $key => $it) {
        $name = trim((string)($it['product_name'] ?? '')) ?: 'Item';
        $qty = (int)($it['quantity_requested'] ?? 0);
        if (!isset($old[$key])) {
            $changes[] = 'Added ' . $name . ' × ' . $qty;
            continue;
        }
        $oldQty = (int)($old[$key]['quantity_requested'] ?? 0);
        if ($oldQty !== $qty) {
            $changes[] = $name . ': quantity ' . $oldQty . ' → ' . $qty;
        }
        $oldPrice = round((float)($old[$key]['estimated_unit_price'] ?? 0), 2);
        $newPrice = round((float)($it['estimated_unit_price'] ?? 0), 2);
        if (abs($oldPrice - $newPrice) >= 0.01) {
            $changes[] = $name . ': unit price ₱' . number_format($oldPrice, 2) . ' → ₱' . number_format($newPrice, 2);
        }
    }
    foreach ($old as $key => $it) {
        if (!isset($new[$key])) {
            $changes[] = 'Removed ' . (trim((string)($it['product_name'] ?? '')) ?: 'Item') . ' (was ' . (int)($it['quantity_requested'] ?? 0) . ')';
        }
    }
    return $changes;
}

function prActorName(mysqli $conn, int $uid): string {
    if ($uid <= 0) return 'System';
    $q = $conn->query('SELECT COALESCE(NULLIF(full_name,\'\'), username) AS n FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
    $row = $q ? $q->fetch_assoc() : null;
    return $row && !empty($row['n']) ? (string)$row['n'] : ('User #' . $uid);
}

/**
 * Tell the requesting Purchaser what changed on their PR (items, quantities, prices, status).
 * Skipped when the Purchaser made the change themselves.
 */
function prNotifyPurchaserOfChanges(mysqli $conn, array $pr, array $changes, int $actorUid, string $title = ''): void {
    $requester = (int)($pr['requested_by'] ?? 0);
    $changes = array_values(array_filter(array_map('strval', $changes), fn($c) => trim($c) !== ''));
    if ($requester <= 0 || $requester === $actorUid || empty($changes)) return;
    $prNumber = (string)($pr['pr_number'] ?? ('#' . (int)($pr['id'] ?? 0)));
    $msg = 'Your Purchase Request ' . $prNumber . ' was updated by ' . prActorName($conn, $actorUid) . '. Changes: ' . implode('; ', $changes) . '.';
    if (function_exists('mb_strlen') && mb_strlen($msg) > 1500) {
        $msg = mb_substr($msg, 0, 1497) . '...';
    }
    createNotification($conn, $title !== '' ? $title : ('PR Updated: ' . $prNumber), $msg, 'pr_update', 'high', $prNumber, 'purchase-requests', null, $requester);
}

/** A PR can be approved only when it has complete items with a supplier each. */
function prApprovalReadinessError(mysqli $conn, int $prId): ?string {
    $row = $conn->query("SELECT COUNT(*) AS c,
                                SUM(CASE WHEN supplier_id IS NULL OR supplier_id = 0 THEN 1 ELSE 0 END) AS no_supplier,
                                SUM(CASE WHEN quantity_requested <= 0 THEN 1 ELSE 0 END) AS bad_qty
                         FROM purchase_request_items WHERE pr_id=" . (int)$prId)->fetch_assoc();
    if ((int)($row['c'] ?? 0) === 0) return 'Cannot approve: PR has no items';
    if ((int)($row['no_supplier'] ?? 0) > 0) return 'Cannot approve: each requested item must have a supplier.';
    if ((int)($row['bad_qty'] ?? 0) > 0) return 'Cannot approve: each requested item must have a quantity of at least 1.';
    return null;
}

/**
 * Integrity guard: a PR must never show "Approved" without a recorded Admin approval.
 * Rows that slipped through (no approver, no PO) go back to the approval queue.
 */
function prReconcileApprovalIntegrity(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $res = $conn->query("SELECT id, pr_number, status FROM purchase_requests
                         WHERE status IN ('Approved','Partially Approved')
                           AND (approved_by IS NULL OR approved_by = 0)
                           AND NOT EXISTS (SELECT 1 FROM purchase_orders po WHERE po.pr_id = purchase_requests.id)");
    if (!$res) return;
    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
        if ($conn->query("UPDATE purchase_requests SET status='Pending Approval', approved_at=NULL WHERE id=$id")) {
            $ins = $conn->prepare("INSERT INTO audit_log (user_id, action_type, table_name, record_id, old_values, new_values, ip_address) VALUES (NULL, 'PR_STATUS_CORRECTED', 'purchase_requests', ?, ?, ?, ?)");
            if ($ins) {
                $oldJson = json_encode(['status' => $row['status']]);
                $newJson = json_encode(['status' => 'Pending Approval', 'reason' => 'Marked approved without a recorded Admin approval']);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $ins->bind_param('isss', $id, $oldJson, $newJson, $ip);
                $ins->execute();
                $ins->close();
            }
        }
    }
}

/** Audit trail rows for the PR review / details view. */
function prLoadHistory(mysqli $conn, int $prId): array {
    $labels = [
        'PR_CREATED' => 'Created',
        'PR_UPDATED' => 'Updated',
        'PR_ITEMS_ADDED' => 'Items added',
        'PR_RESUBMITTED' => 'Resubmitted for approval',
        'PR_APPROVED' => 'Approved',
        'PR_REJECTED' => 'Rejected',
        'PR_RETURNED' => 'Returned to queue',
        'PR_PENDING_APPROVAL' => 'Submitted for approval',
        'PR_DRAFT' => 'Moved back to draft',
        'PR_REVIEW_SAVED' => 'Review changes saved',
        'PR_STATUS_CORRECTED' => 'Status corrected',
    ];
    $rows = [];
    $st = $conn->prepare("SELECT a.id, a.action_type, a.created_at, a.old_values, a.new_values,
                                 COALESCE(NULLIF(u.full_name,''), u.username, 'System') AS user_name, u.role AS user_role
                          FROM audit_log a
                          LEFT JOIN users u ON u.id = a.user_id
                          WHERE a.table_name = 'purchase_requests' AND a.record_id = ?
                          ORDER BY a.created_at DESC, a.id DESC
                          LIMIT 50");
    if (!$st) return $rows;
    $st->bind_param('i', $prId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $new = json_decode((string)($r['new_values'] ?? ''), true);
        $new = is_array($new) ? $new : [];
        $details = [];
        if (!empty($new['changes']) && is_array($new['changes'])) {
            $details = array_merge($details, array_map('strval', $new['changes']));
        }
        if (!empty($new['admin_remarks'])) {
            $details[] = 'Remarks: ' . $new['admin_remarks'];
        }
        if (!empty($new['reason']) && $r['action_type'] === 'PR_STATUS_CORRECTED') {
            $details[] = (string)$new['reason'];
        }
        $type = (string)$r['action_type'];
        $rows[] = [
            'id' => (int)$r['id'],
            'action' => $type,
            'label' => $labels[$type] ?? ucwords(strtolower(str_replace(['PR_', '_'], ['', ' '], $type))),
            'user_name' => (string)$r['user_name'],
            'user_role' => (string)($r['user_role'] ?? ''),
            'created_at' => (string)$r['created_at'],
            'status' => (string)($new['status'] ?? ''),
            'details' => $details,
        ];
    }
    $st->close();
    return $rows;
}

if ($conn instanceof mysqli) {
    prReconcileApprovalIntegrity($conn);
}

switch ($action) {

    // ─── LIVE CHANGE DETECTION (READ-ONLY) ─────────────────────────────────────
    case 'get_live_changes':
        // Tiny SELECT aggregates only. No INSERT/UPDATE/DELETE. Role-scoped stamps.
        try {
            $out = ['success' => true];
            if ($isAdminRole) {
                $out['pr_pending'] = liveChangesStamp(
                    $conn,
                    "SELECT COUNT(*) AS cnt, IFNULL(MAX(id),0) AS max_id, IFNULL(MAX(updated_at),'') AS max_ts
                     FROM purchase_requests WHERE status='Pending Approval'"
                );
            }
            if ($isAdminRole || $isPurchaserRole) {
                $out['pr'] = liveChangesStamp(
                    $conn,
                    "SELECT COUNT(*) AS cnt, IFNULL(MAX(id),0) AS max_id, IFNULL(MAX(updated_at),'') AS max_ts
                     FROM purchase_requests"
                );
            }
            $out['po'] = liveChangesStamp(
                $conn,
                "SELECT COUNT(*) AS cnt, IFNULL(MAX(id),0) AS max_id, IFNULL(MAX(updated_at),'') AS max_ts,
                        IFNULL((SELECT SUM(COALESCE(quantity_received,0)) FROM purchase_order_items),0) AS ver
                 FROM purchase_orders"
            );
            $out['receipt'] = liveChangesStamp(
                $conn,
                "SELECT COUNT(*) AS cnt, IFNULL(MAX(id),0) AS max_id, IFNULL(MAX(created_at),'') AS max_ts,
                        IFNULL(SUM(CRC32(CONCAT(id,'|',IFNULL(workflow_status,''),'|',IFNULL(status,'')))),0) AS ver
                 FROM purchase_receipts"
            );
            $inspTbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
            if ($inspTbl && $inspTbl->num_rows > 0) {
                $out['inspect'] = liveChangesStamp(
                    $conn,
                    "SELECT COUNT(*) AS cnt, IFNULL(MAX(id),0) AS max_id, '' AS max_ts
                     FROM purchase_receipt_inspection_barcodes"
                );
            }
            $scanTbl = $conn->query("SHOW TABLES LIKE 'scan_history'");
            if ($scanTbl && $scanTbl->num_rows > 0) {
                $out['scan'] = liveChangesStamp(
                    $conn,
                    "SELECT COUNT(*) AS cnt, IFNULL(MAX(id),0) AS max_id, IFNULL(MAX(created_at),'') AS max_ts
                     FROM scan_history"
                );
            }
            jOut($out);
        } catch (Throwable $e) {
            error_log('[procurement] get_live_changes: ' . $e->getMessage());
            jOut(['success' => false, 'message' => 'Unable to check for updates']);
        }
        break;

    // ─── PURCHASE REQUESTS (PR) ────────────────────────────────────────────────
    case 'get_purchase_requests':
        $where = []; $params = []; $types = '';
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $where[] = 'pr.status=?'; $params[] = $_GET['status']; $types .= 's';
        }
        if (!empty($_GET['search'])) {
            $where[] = '(pr.pr_number LIKE ? OR s.name LIKE ? OR COALESCE(u.full_name, u.username) LIKE ?
                         OR EXISTS (
                             SELECT 1 FROM purchase_request_items pri_s
                             INNER JOIN suppliers s_item ON s_item.id = pri_s.supplier_id
                             WHERE pri_s.pr_id = pr.id AND s_item.name LIKE ?
                         ))';
            $sq = '%' . $_GET['search'] . '%';
            $params[] = $sq; $params[] = $sq; $params[] = $sq; $params[] = $sq; $types .= 'ssss';
        }
        $wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql  = "SELECT pr.*, s.name AS supplier_name,
                        COALESCE(u.full_name, u.username) AS requested_by_name,
                        COALESCE(u2.full_name, u2.username) AS approved_by_name,
                        (SELECT COUNT(*) FROM purchase_request_items WHERE pr_id=pr.id) AS item_count,
                        (SELECT GROUP_CONCAT(DISTINCT s_item.name ORDER BY s_item.name SEPARATOR ', ')
                           FROM purchase_request_items pri_sup
                           INNER JOIN suppliers s_item ON s_item.id = pri_sup.supplier_id
                          WHERE pri_sup.pr_id = pr.id AND pri_sup.supplier_id > 0) AS item_supplier_names,
                        (SELECT COUNT(DISTINCT pri_sup.supplier_id)
                           FROM purchase_request_items pri_sup
                          WHERE pri_sup.pr_id = pr.id AND pri_sup.supplier_id IS NOT NULL AND pri_sup.supplier_id > 0) AS item_supplier_count,
                        (SELECT id FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS generated_po_id,
                        (SELECT po_number FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS generated_po_number,
                        (SELECT GROUP_CONCAT(id ORDER BY id) FROM purchase_orders WHERE pr_id=pr.id) AS generated_po_ids,
                        (SELECT GROUP_CONCAT(po_number ORDER BY id SEPARATOR ', ') FROM purchase_orders WHERE pr_id=pr.id) AS generated_po_numbers,
                        (SELECT status FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS generated_po_status,
                        (SELECT receiving_status FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS po_receiving_status
                 FROM purchase_requests pr
                 LEFT JOIN suppliers s ON pr.supplier_id = s.id
                 LEFT JOIN users u ON pr.requested_by = u.id
                 LEFT JOIN users u2 ON pr.approved_by = u2.id
                 $wSQL ORDER BY pr.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $r['pipeline_status'] = prComputePipelineStatus($r);
            $rows[] = prAttachPurposeFields($r);
        }
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_pr_detail':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT pr.*, s.name AS supplier_name, s.supplier_code, s.email AS supplier_email,
                                       COALESCE(u.full_name, u.username) AS requested_by_name,
                                       COALESCE(u2.full_name, u2.username) AS approved_by_name,
                                       (SELECT id FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS generated_po_id,
                                       (SELECT po_number FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS generated_po_number,
                                       (SELECT GROUP_CONCAT(id ORDER BY id) FROM purchase_orders WHERE pr_id=pr.id) AS generated_po_ids,
                                       (SELECT GROUP_CONCAT(po_number ORDER BY id SEPARATOR ', ') FROM purchase_orders WHERE pr_id=pr.id) AS generated_po_numbers,
                                       (SELECT status FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS generated_po_status,
                                       (SELECT receiving_status FROM purchase_orders WHERE pr_id=pr.id LIMIT 1) AS po_receiving_status
                                FROM purchase_requests pr
                                LEFT JOIN suppliers s ON pr.supplier_id = s.id
                                LEFT JOIN users u ON pr.requested_by = u.id
                                LEFT JOIN users u2 ON pr.approved_by = u2.id
                                WHERE pr.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pr = $stmt->get_result()->fetch_assoc();
        if (!$pr) jOut(['success' => false, 'message' => 'Purchase Request not found']);
        $pr = prAttachPurposeFields($pr);

        $items_stmt = $conn->prepare("SELECT pri.*,
                                             p.sku, p.brand, p.unit_of_measure, p.quantity_in_stock, p.reorder_level,
                                             p.name AS catalog_name, p.supplier_id AS product_supplier_id,
                                             p.cost_price AS catalog_cost_price, p.unit_price AS catalog_unit_price,
                                             ps.name AS product_supplier_name,
                                             isup.name AS item_supplier_name
                                      FROM purchase_request_items pri
                                      LEFT JOIN products p ON pri.product_id = p.id
                                      LEFT JOIN suppliers ps ON p.supplier_id = ps.id
                                      LEFT JOIN suppliers isup ON isup.id = pri.supplier_id
                                      WHERE pri.pr_id=?
                                      ORDER BY pri.id ASC");
        $items_stmt->bind_param('i', $id);
        $items_stmt->execute();
        $items_res = $items_stmt->get_result();
        $items = [];
        while ($i = $items_res->fetch_assoc()) {
            $catalogCost = (float)($i['catalog_cost_price'] ?? 0);
            if ($catalogCost <= 0) $catalogCost = (float)($i['catalog_unit_price'] ?? 0);
            $stored = (float)($i['estimated_unit_price'] ?? 0);
            $i['unit_price'] = $stored > 0 ? $stored : $catalogCost;
            $i['actual_unit_price'] = $i['unit_price'];
            $i['total_price'] = ((int)($i['quantity_requested'] ?? 0)) * $i['unit_price'];
            $items[] = $i;
        }
        $pr['items'] = $items;
        $seenSuppliers = [];
        foreach ($items as $it) {
            $n = trim((string)($it['item_supplier_name'] ?? ''));
            if ($n !== '') $seenSuppliers[$n] = true;
        }
        $pr['item_supplier_names'] = implode(', ', array_keys($seenSuppliers));
        $pr['item_supplier_count'] = count($seenSuppliers);
        $pr['pipeline_status'] = prComputePipelineStatus($pr);
        $pr['total_estimated_amount'] = array_sum(array_map(fn($x) => (float)($x['total_price'] ?? 0), $items));
        $pr['history'] = prLoadHistory($conn, $id);
        $prStatus = (string)($pr['status'] ?? '');
        $isOwner = (int)($pr['requested_by'] ?? 0) === $uid;
        $pr['can_add_items'] = ($isAdminRole && in_array($prStatus, ['Draft', 'Pending Approval'], true))
            || ($isPurchaserRole && $isOwner && in_array($prStatus, ['Draft', 'Pending Approval'], true));
        jOut(['success' => true, 'data' => $pr]);
        break;

    case 'create_pr':
        if (!$isPurchaserRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Purchaser or Administrator can create purchase requests']);
        }
        $f           = $_POST;
        $items_json  = $f['items'] ?? '[]';
        $items       = json_decode($items_json, true) ?? [];
        $reason      = trim($f['reason'] ?? '');
        $purpose     = trim($f['purpose'] ?? '');
        $remarks     = prNormalizePurposeRemarks($purpose, trim($f['remarks'] ?? ''));
        $customerOrderRef = prNormalizeCustomerOrderRef($purpose, trim($f['customer_order_reference'] ?? ''));
        $customerName = $purpose === 'Customer Order' ? trim((string)($f['customer_name'] ?? '')) : '';
        if (strlen($customerName) > 200) {
            jOut(['success' => false, 'message' => 'Customer / Company Name must be 200 characters or fewer.']);
        }
        $submit_now  = ($f['submit_for_approval'] ?? '') === '1';

        if (empty($items)) jOut(['success' => false, 'message' => 'Add at least one complete requested item']);

        $items = prPrepareSubmittedPRItems($items, $purpose, $remarks, $customerOrderRef);
        foreach ($items as &$item) {
            if (trim((string)($item['purpose'] ?? '')) === '') {
                jOut(['success' => false, 'message' => 'Each requested item must have a Purpose.']);
            }
            if ((int)($item['supplier_id'] ?? 0) <= 0) {
                jOut(['success' => false, 'message' => 'Each requested item must have a supplier.']);
            }
            $err = prValidateRequestedItem($conn, $item);
            if ($err) {
                jOut(['success' => false, 'message' => $err]);
            }
        }
        unset($item);

        $groups = prBuildPurposeSupplierGroups($items);
        if (empty($groups)) {
            jOut(['success' => false, 'message' => 'Each requested item must have a Purpose and a supplier.']);
        }
        $dupMsg = prDuplicateProductsInGroups($groups);
        if ($dupMsg) jOut(['success' => false, 'message' => $dupMsg]);
        foreach ($groups as $group) {
            $groupPurpose = trim((string)($group['purpose'] ?? ''));
            $groupRemarks = $groupPurpose === 'Others' ? trim((string)($group['remarks'] ?? '')) : '';
            $groupRef = $groupPurpose === 'Customer Order' ? trim((string)($group['customer_order_reference'] ?? '')) : '';
            $purposeErr = prValidatePurposeFields(
                $groupPurpose,
                $groupRemarks,
                $groupRef,
                true
            );
            if ($purposeErr) jOut(['success' => false, 'message' => $purposeErr]);
        }

        $status = $submit_now ? 'Pending Approval' : 'Draft';
        $r = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_requests WHERE YEAR(created_at)=" . date('Y'));
        $yearCnt = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

        $conn->begin_transaction();
        try {
            $created = [];
            foreach ($groups as $group) {
                $created[] = insertPurchaseRequestWithItems(
                    $conn,
                    $uid,
                    (int)$group['supplier_id'],
                    $group['items'],
                    $reason,
                    $group['remarks'],
                    $status,
                    $yearCnt,
                    $group['purpose'],
                    $group['customer_order_reference'],
                    false,
                    trim((string)($group['purpose'] ?? '')) === 'Customer Order' ? $customerName : ''
                );
            }
            if ($submit_now) {
                foreach ($created as $one) {
                    createNotification(
                        $conn,
                        "New Purchase Request: " . $one['pr_number'],
                        "Purchase Request " . $one['pr_number'] . " submitted for Admin approval.",
                        "pending_approval",
                        "medium",
                        $one['pr_number'],
                        "procurement",
                        "admin"
                    );
                }
            }
            $conn->commit();
            $nums = array_column($created, 'pr_number');
            jOut([
                'success' => true,
                'pr_id' => $created[0]['pr_id'],
                'pr_ids' => array_column($created, 'pr_id'),
                'pr_number' => implode(', ', $nums),
                'pr_numbers' => $nums,
                'pr_count' => count($created),
                'status' => $status,
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'update_pr':
        // Admin edits an existing PR in-place (no duplicate PR created)
        if ($isWarehouseHeadRole) {
            jOut(['success' => false, 'message' => 'Warehouse Head cannot edit or approve Purchase Requests']);
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jOut(['success' => false, 'message' => 'Invalid PR ID']);

        $prQ = $conn->query("SELECT * FROM purchase_requests WHERE id=$id");
        $pr = $prQ ? $prQ->fetch_assoc() : null;
        if (!$pr) jOut(['success' => false, 'message' => 'Purchase Request not found']);
        $isOwnPurchaserDraft = $isPurchaserRole && !$isAdminRole
            && (int)($pr['requested_by'] ?? 0) === $uid
            && $pr['status'] === 'Draft';
        $isOwnPurchaserRejected = $isPurchaserRole && !$isAdminRole
            && (int)($pr['requested_by'] ?? 0) === $uid
            && $pr['status'] === 'Rejected';
        $isPurchaserEditingOwn = $isOwnPurchaserDraft || $isOwnPurchaserRejected;
        if ($isAdminRole) {
            if (!in_array($pr['status'], ['Draft', 'Pending Approval'], true)) {
                jOut(['success' => false, 'message' => 'Only Draft or Pending Approval PRs can be edited']);
            }
        } elseif (!$isPurchaserEditingOwn) {
            if (($pr['status'] ?? '') === 'Rejected') {
                jOut(['success' => false, 'message' => 'Only the requesting purchaser can revise this rejected Purchase Request']);
            }
            jOut(['success' => false, 'message' => 'Only administrators can edit purchase requests']);
        }

        $supplier_id = array_key_exists('supplier_id', $_POST)
            ? (!empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0)
            : (int)$pr['supplier_id'];
        $reason  = array_key_exists('reason', $_POST) ? trim($_POST['reason'] ?? '') : ($pr['reason'] ?? '');
        $remarks = array_key_exists('remarks', $_POST) ? trim($_POST['remarks'] ?? '') : ($pr['remarks'] ?? '');
        $purpose = array_key_exists('purpose', $_POST)
            ? trim((string)($_POST['purpose'] ?? ''))
            : trim((string)($pr['purpose'] ?? ''));
        $customerOrderRef = array_key_exists('customer_order_reference', $_POST)
            ? trim((string)($_POST['customer_order_reference'] ?? ''))
            : trim((string)($pr['customer_order_reference'] ?? ''));
        $customerOrderRef = prNormalizeCustomerOrderRef($purpose, $customerOrderRef);
        $customerName = array_key_exists('customer_name', $_POST)
            ? trim((string)($_POST['customer_name'] ?? ''))
            : trim((string)($pr['customer_name'] ?? ''));
        if ($purpose !== 'Customer Order') {
            $customerName = '';
        }
        if (strlen($customerName) > 200) {
            jOut(['success' => false, 'message' => 'Customer / Company Name must be 200 characters or fewer.']);
        }
        $remarks = prNormalizePurposeRemarks($purpose, $remarks);
        if (!$isPurchaserEditingOwn) {
            $purposeErr = prValidatePurposeFields($purpose, $remarks, $customerOrderRef, $purpose !== '');
            if ($purposeErr) jOut(['success' => false, 'message' => $purposeErr]);
        }
        $items_json = $_POST['items'] ?? null;
        $items = $items_json !== null ? (json_decode($items_json, true) ?? []) : null;

        if ($items !== null && empty($items)) {
            jOut(['success' => false, 'message' => 'PR must have at least one item']);
        }
        $submitNow = (($_POST['submit_for_approval'] ?? '') === '1')
            && ($isPurchaserEditingOwn || $isAdminRole)
            && in_array($pr['status'], ['Draft', 'Rejected'], true);
        $newStatus = $submitNow ? 'Pending Approval' : $pr['status'];
        $extraGroups = [];
        $preserveItemSuppliers = false;
        if ($items !== null) {
            $items = prPrepareSubmittedPRItems($items, $purpose, $remarks, $customerOrderRef);
            foreach ($items as &$item) {
                if ($isPurchaserEditingOwn && trim((string)($item['purpose'] ?? '')) === '') {
                    jOut(['success' => false, 'message' => 'Each requested item must have a Purpose.']);
                }
                $err = prValidateRequestedItem($conn, $item);
                if ($err) {
                    jOut(['success' => false, 'message' => $err]);
                }
            }
            unset($item);
            if ($isPurchaserEditingOwn) {
                $groups = prBuildPurposeSupplierGroups($items);
                if (empty($groups)) {
                    jOut(['success' => false, 'message' => 'Each requested item must have a Purpose and a supplier.']);
                }
                $dupMsg = prDuplicateProductsInGroups($groups);
                if ($dupMsg) jOut(['success' => false, 'message' => $dupMsg]);
                foreach ($groups as $group) {
                    $groupPurpose = trim((string)($group['purpose'] ?? ''));
                    $groupRemarks = $groupPurpose === 'Others' ? trim((string)($group['remarks'] ?? '')) : '';
                    $groupRef = $groupPurpose === 'Customer Order' ? trim((string)($group['customer_order_reference'] ?? '')) : '';
                    $purposeErr = prValidatePurposeFields(
                        $groupPurpose,
                        $groupRemarks,
                        $groupRef,
                        true
                    );
                    if ($purposeErr) jOut(['success' => false, 'message' => $purposeErr]);
                }
                $keepPurpose = trim((string)($pr['purpose'] ?? ''));
                $keepSid = (int)$pr['supplier_id'];
                $keepKey = prPurposeSupplierKey($keepPurpose, $keepSid);
                if ($keepSid <= 0 || $keepPurpose === '' || !isset($groups[$keepKey])) {
                    $keepKey = (string)array_key_first($groups);
                }
                if ($isOwnPurchaserRejected && count($groups) !== 1) {
                    jOut(['success' => false, 'message' => 'A revised Purchase Request must keep one purpose and one supplier. Create a new Purchase Request for a different supplier.']);
                }
                $keepGroup = $groups[$keepKey];
                $items = $keepGroup['items'];
                $supplier_id = (int)$keepGroup['supplier_id'];
                $purpose = $keepGroup['purpose'];
                $customerOrderRef = $keepGroup['customer_order_reference'];
                if ($purpose !== 'Customer Order') {
                    $customerName = '';
                }
                $remarks = $keepGroup['remarks'];
                unset($groups[$keepKey]);
                $extraGroups = $isOwnPurchaserRejected ? [] : array_values($groups);
            } else {
                $dupMsg = prItemsHaveDuplicateProducts($items);
                if ($dupMsg) jOut(['success' => false, 'message' => $dupMsg]);
                $groups = prGroupItemsBySupplier($items);
                if (count($groups) !== 1) {
                    jOut(['success' => false, 'message' => 'A Purchase Request can only contain items from one supplier.']);
                }
                $supplier_id = (int)array_key_first($groups);
            }
        }
        if ($supplier_id <= 0 || !prActiveSupplierExists($conn, $supplier_id)) {
            jOut(['success' => false, 'message' => 'Please select a supplier before adding a product. It applies only to the next item you add.']);
        }

        $conn->begin_transaction();
        try {
            $oldSnapshot = $pr;
            $beforeItems = prItemsSnapshot($conn, $id);
            $wasRejected = ($pr['status'] ?? '') === 'Rejected';
            $total = (float)$pr['total_estimated_amount'];
            $removedItems = [];
            $oldItems = [];
            if ($items !== null) {
                $oiRes = $conn->query("SELECT product_id, product_name, quantity_requested FROM purchase_request_items WHERE pr_id=$id");
                if ($oiRes) {
                    while ($oldRow = $oiRes->fetch_assoc()) {
                        $oldItems[] = $oldRow;
                    }
                }
                $removedItems = prDiffRemovedItems($oldItems, $items, (string)($pr['pr_number'] ?? ''));
            }

            if ($items !== null) {
                $total = 0;
                $conn->query("DELETE FROM purchase_request_items WHERE pr_id=$id");
                foreach ($items as $item) {
                    $pid  = !empty($item['product_id']) ? (int)$item['product_id'] : null;
                    $pn   = trim($item['product_name'] ?? '');
                    $qty  = (int)($item['quantity_requested'] ?? 1);
                    $up   = (float)($item['estimated_unit_price'] ?? 0);
                    $rem  = trim($item['remarks'] ?? '');
                    if ($pid) {
                        $pq = $conn->prepare("SELECT name, brand, cost_price, unit_of_measure, quantity_in_stock, reorder_level, supplier_id FROM products WHERE id=? LIMIT 1");
                        if ($pq) {
                            $pq->bind_param('i', $pid);
                            $pq->execute();
                            $prod = $pq->get_result()->fetch_assoc();
                            if ($prod) {
                                if ($pn === '') {
                                    $pn = !empty($prod['brand']) ? ($prod['name'] . ' (' . $prod['brand'] . ')') : $prod['name'];
                                }
                                $catalogCost = (float)$prod['cost_price'];
                                if ($catalogCost <= 0) $catalogCost = (float)($prod['unit_price'] ?? 0);
                                if ($up <= 0 && $catalogCost > 0) $up = $catalogCost;
                            }
                        }
                    }
                    if ($pn === '' || $qty <= 0) continue;
                    $itemSupplierId = $preserveItemSuppliers
                        ? (int)($item['supplier_id'] ?? $supplier_id)
                        : $supplier_id;
                    $si = $conn->prepare("INSERT INTO purchase_request_items (pr_id, product_id, supplier_id, product_name, quantity_requested, estimated_unit_price, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $si->bind_param('iiisids', $id, $pid, $itemSupplierId, $pn, $qty, $up, $rem);
                    $si->execute();
                    $total += $qty * $up;
                }
                $cntCheck = $conn->query("SELECT COUNT(*) AS c FROM purchase_request_items WHERE pr_id=$id")->fetch_assoc();
                if ((int)($cntCheck['c'] ?? 0) === 0) {
                    throw new RuntimeException('PR must have at least one valid item');
                }
            }

            $stmt = $conn->prepare("UPDATE purchase_requests SET supplier_id=?, reason=?, remarks=?, purpose=?, customer_order_reference=?, customer_name=?, total_estimated_amount=?, status=? WHERE id=?");
            $stmt->bind_param('isssssdsi', $supplier_id, $reason, $remarks, $purpose, $customerOrderRef, $customerName, $total, $newStatus, $id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);

            $extraCreated = [];
            if (!empty($extraGroups)) {
                $rCnt = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_requests WHERE YEAR(created_at)=" . date('Y'));
                $yearCnt = $rCnt ? (int)$rCnt->fetch_assoc()['cnt'] : 0;
                foreach ($extraGroups as $group) {
                    $extraCreated[] = insertPurchaseRequestWithItems(
                        $conn,
                        $uid,
                        (int)$group['supplier_id'],
                        $group['items'],
                        $reason,
                        $group['remarks'],
                        $newStatus,
                        $yearCnt,
                        $group['purpose'],
                        $group['customer_order_reference'],
                        false,
                        trim((string)($group['purpose'] ?? '')) === 'Customer Order' ? $customerName : ''
                    );
                }
            }

            $changes = prDescribeItemChanges($beforeItems, prItemsSnapshot($conn, $id));
            if ((int)$pr['supplier_id'] !== (int)$supplier_id) {
                $supQ = $conn->query('SELECT name FROM suppliers WHERE id=' . (int)$supplier_id . ' LIMIT 1');
                $supRow = $supQ ? $supQ->fetch_assoc() : null;
                $changes[] = 'Supplier changed to ' . ($supRow['name'] ?? ('#' . $supplier_id));
            }
            if (trim((string)($pr['remarks'] ?? '')) !== trim((string)$remarks)) {
                $changes[] = 'Purpose details updated';
            }
            if (($pr['status'] ?? '') !== $newStatus) {
                $changes[] = 'Status: ' . $pr['status'] . ' → ' . $newStatus;
            }
            if (!empty($extraCreated)) {
                $changes[] = 'Split into ' . implode(', ', array_column($extraCreated, 'pr_number'));
            }

            logProcurementAudit($conn, $uid, $wasRejected && $submitNow ? 'PR_RESUBMITTED' : 'PR_UPDATED', 'purchase_requests', $id, $oldSnapshot, [
                'changes' => $changes,
                'supplier_id' => $supplier_id,
                'reason' => $reason,
                'remarks' => $remarks,
                'purpose' => $purpose,
                'customer_order_reference' => $customerOrderRef,
                'customer_name' => $customerName,
                'total_estimated_amount' => $total,
                'status' => $newStatus,
                'items_replaced' => $items !== null,
                'split_pr_count' => 1 + count($extraCreated),
                'previous_admin_remarks' => $pr['admin_remarks'] ?? '',
                'removed_items' => $removedItems,
            ]);
            foreach ($removedItems as $removed) {
                logProcurementUiAudit(
                    $conn,
                    $uid,
                    'PR_ITEM_REMOVED_ON_REVISION',
                    'Product: ' . ($removed['product_name'] ?: ('#' . $removed['product_id']))
                    . '. Original Qty: ' . $removed['original_qty']
                    . '. Action: Removed during PR revision'
                    . '. Reason: Supplier unavailable / out of stock'
                    . '. Original PR: ' . ($pr['pr_number'] ?? '')
                );
            }
            if ($wasRejected && $submitNow) {
                logProcurementUiAudit(
                    $conn,
                    $uid,
                    'PR_RESUBMITTED',
                    'Resubmitted Purchase Request ' . ($pr['pr_number'] ?? ('#' . $id))
                    . ' for Admin approval after revision.'
                    . (!empty($pr['admin_remarks']) ? ' Previous rejection reason: ' . $pr['admin_remarks'] : '')
                );
            }

            if ($submitNow) {
                $notify = array_merge([['pr_number' => $pr['pr_number']]], $extraCreated);
                foreach ($notify as $one) {
                    $isResubmit = $wasRejected && empty($one['pr_id']);
                    createNotification(
                        $conn,
                        ($isResubmit ? 'PR Resubmitted: ' : 'New Purchase Request: ') . $one['pr_number'],
                        $isResubmit
                            ? ('Purchase Request ' . $one['pr_number'] . ' was revised and resubmitted for Admin approval.')
                            : ('Purchase Request ' . $one['pr_number'] . ' submitted for Admin approval.'),
                        "pending_approval",
                        "medium",
                        $one['pr_number'],
                        "procurement",
                        "admin"
                    );
                }
            }

            prNotifyPurchaserOfChanges($conn, $pr, $changes, $uid);

            $conn->commit();
            $nums = array_merge([$pr['pr_number']], array_column($extraCreated, 'pr_number'));
            jOut([
                'success' => true,
                'pr_id' => $id,
                'pr_ids' => array_merge([$id], array_column($extraCreated, 'pr_id')),
                'pr_number' => implode(', ', $nums),
                'pr_numbers' => $nums,
                'pr_count' => count($nums),
                'status' => $newStatus,
                'changes' => $changes,
                'message' => 'Purchase Request updated'
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'update_pr_status':
        $id            = (int)($_POST['id'] ?? 0);
        $status        = $_POST['status'] ?? '';
        $admin_remarks = trim($_POST['admin_remarks'] ?? '');

        if ($isWarehouseHeadRole) {
            jOut(['success' => false, 'message' => 'Warehouse Head cannot approve or reject Purchase Requests']);
        }
        if (!$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only administrators can update PR status']);
        }

        if (!$id) jOut(['success' => false, 'message' => 'Invalid PR ID']);
        if (!in_array($status, ['Approved', 'Rejected', 'Pending Approval', 'Draft'], true)) {
            jOut(['success' => false, 'message' => 'Invalid PR status']);
        }
        if ($status === 'Rejected' && $admin_remarks === '') {
            jOut(['success' => false, 'message' => 'A rejection reason is required']);
        }

        $prQ = $conn->query("SELECT * FROM purchase_requests WHERE id=$id");
        $pr = $prQ ? $prQ->fetch_assoc() : null;
        if (!$pr) jOut(['success' => false, 'message' => 'Purchase Request not found']);

        if ($status === 'Rejected' && ($pr['status'] ?? '') === 'Rejected') {
            jOut(['success' => true, 'status' => 'Rejected', 'already_rejected' => true, 'message' => 'This Purchase Request is already rejected.']);
        }

        $returnToAdmin = !empty($_POST['return_to_admin']) && $status === 'Pending Approval';
        if ($returnToAdmin && $admin_remarks === '') {
            jOut(['success' => false, 'message' => 'A return note is required']);
        }

        // Stored status must follow the real approval workflow.
        $fromStatus = (string)($pr['status'] ?? '');
        $allowedTransitions = [
            'Draft' => ['Pending Approval'],
            'Pending Approval' => ['Approved', 'Rejected', 'Draft', 'Pending Approval'],
            'Partially Approved' => ['Approved', 'Rejected'],
        ];
        if (!in_array($status, $allowedTransitions[$fromStatus] ?? [], true)
            || ($fromStatus === 'Pending Approval' && $status === 'Pending Approval' && !$returnToAdmin)) {
            jOut(['success' => false, 'message' => 'Purchase Request ' . ($pr['pr_number'] ?? ('#' . $id)) . ' is ' . $fromStatus . ' and cannot be changed to ' . $status . '.']);
        }
        if ($status === 'Approved') {
            $readyErr = prApprovalReadinessError($conn, $id);
            if ($readyErr) jOut(['success' => false, 'message' => $readyErr]);
        }

        if ($status === 'Approved') {
            $stmt = $conn->prepare("UPDATE purchase_requests SET status=?, admin_remarks=?, approved_by=?, approved_at=NOW() WHERE id=?");
            $stmt->bind_param('ssii', $status, $admin_remarks, $uid, $id);
        } elseif ($status === 'Rejected' || $returnToAdmin) {
            $stmt = $conn->prepare("UPDATE purchase_requests SET status=?, admin_remarks=? WHERE id=?");
            $stmt->bind_param('ssi', $status, $admin_remarks, $id);
        } else {
            $stmt = $conn->prepare("UPDATE purchase_requests SET status=? WHERE id=?");
            $stmt->bind_param('si', $status, $id);
        }

        if ($stmt->execute()) {
            $auditKey = $returnToAdmin ? 'PR_RETURNED' : ('PR_' . strtoupper(str_replace(' ', '_', $status)));
            logProcurementAudit($conn, $uid, $auditKey, 'purchase_requests', $id, ['status' => $pr['status']], ['status' => $status, 'admin_remarks' => $admin_remarks, 'return_to_admin' => $returnToAdmin]);
            if ($status === 'Rejected' && !$returnToAdmin) {
                $prNum = (string)($pr['pr_number'] ?? ('#' . $id));
                logProcurementUiAudit(
                    $conn,
                    $uid,
                    'PR_REJECTED',
                    'Rejected Purchase Request ' . $prNum . '. Reason: ' . $admin_remarks
                );
            }

            // Return stays on the Admin queue only — do not notify Purchaser or Warehouse Head to rework the PR.
            if (!$returnToAdmin) {
                $reqBy = (int)$pr['requested_by'];
                if ($reqBy > 0) {
                    $msg = $status === 'Rejected'
                        ? ('Your Purchase Request ' . $pr['pr_number'] . ' was rejected.' . ($admin_remarks ? ' Rejection reason: ' . $admin_remarks : ''))
                        : ('Your Purchase Request ' . $pr['pr_number'] . ' was ' . strtolower($status) . '.' . ($admin_remarks ? ' Remarks: ' . $admin_remarks : ''));
                    createNotification($conn, "PR Status: " . $pr['pr_number'], $msg, "order_status", "high", $pr['pr_number'], "procurement", null, $reqBy);
                }
            } else {
                createNotification($conn, "PR Returned: " . $pr['pr_number'], "Purchase Request " . $pr['pr_number'] . " was returned and remains in the Admin approval queue." . ($admin_remarks ? " Remarks: " . $admin_remarks : ""), "order_status", "high", $pr['pr_number'], "procurement", "admin");
            }

            jOut(['success' => true, 'status' => $status, 'returned' => $returnToAdmin, 'already_rejected' => false]);
        } else {
            jOut(['success' => false, 'message' => $conn->error]);
        }
        break;

    case 'approve_and_generate_po':
        // Admin only — Warehouse Head cannot approve PR or generate PO
        if ($isWarehouseHeadRole) {
            jOut(['success' => false, 'message' => 'Warehouse Head cannot approve Purchase Requests or generate POs. Only Admin can approve.']);
        }
        if (!$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only administrators can approve PRs and generate POs']);
        }
        $id = (int)($_POST['id'] ?? $_POST['pr_id'] ?? 0);
        $admin_remarks = trim($_POST['admin_remarks'] ?? '');
        if (!$id) jOut(['success' => false, 'message' => 'Invalid PR ID']);

        $conn->begin_transaction();
        try {
            $prQ = $conn->query("SELECT * FROM purchase_requests WHERE id=$id FOR UPDATE");
            $pr = $prQ ? $prQ->fetch_assoc() : null;
            if (!$pr) throw new RuntimeException('Purchase Request not found');

            if ($pr['status'] === 'Rejected') {
                throw new RuntimeException('Rejected PRs cannot be approved or converted to a PO');
            }
            if ($pr['status'] === 'Draft') {
                throw new RuntimeException('This PR is still a Draft. It must be submitted for Admin approval before it can be approved.');
            }
            if (!in_array($pr['status'], ['Pending Approval', 'Approved'], true)) {
                throw new RuntimeException('PR status does not allow approval');
            }

            // Completeness validation
            $readyErr = prApprovalReadinessError($conn, $id);
            if ($readyErr) {
                throw new RuntimeException($readyErr);
            }

            if ($pr['status'] !== 'Approved') {
                $stmt = $conn->prepare("UPDATE purchase_requests SET status='Approved', admin_remarks=?, approved_by=?, approved_at=NOW() WHERE id=?");
                $stmt->bind_param('sii', $admin_remarks, $uid, $id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
                logProcurementAudit($conn, $uid, 'PR_APPROVED', 'purchase_requests', $id, ['status' => $pr['status']], ['status' => 'Approved', 'admin_remarks' => $admin_remarks]);
                $pr['status'] = 'Approved';
            }

            $poResult = generatePOFromApprovedPR($conn, $pr, $uid, null);
            if (empty($poResult['success'])) {
                throw new RuntimeException($poResult['message'] ?? 'Failed to generate PO');
            }

            $reqBy = (int)$pr['requested_by'];
            $newPos = array_values(array_filter($poResult['pos'] ?? [], fn($p) => empty($p['already_existed'])));
            if ($reqBy > 0) {
                $invNote = !empty($poResult['invoice_number']) ? (' Supplier Invoice ' . $poResult['invoice_number'] . ' was also created.') : '';
                $remarkNote = $admin_remarks !== '' ? (' Admin remarks: ' . $admin_remarks) : '';
                createNotification(
                    $conn,
                    "PR Approved & PO Generated: " . $pr['pr_number'],
                    "Your Purchase Request " . $pr['pr_number'] . " was approved by " . prActorName($conn, $uid) . ". Purchase Order " . $poResult['po_number'] . " is ready to print." . $invNote . $remarkNote,
                    "order_status",
                    "high",
                    $poResult['po_number'],
                    "procurement",
                    null,
                    $reqBy
                );
            }
            foreach ($newPos as $np) {
                createNotification(
                    $conn,
                    "Incoming Delivery: " . $np['po_number'],
                    "Purchase Order " . $np['po_number'] . " was generated and is expected for warehouse receiving.",
                    "order_status",
                    "high",
                    $np['po_number'],
                    "incoming-deliveries",
                    "warehouse_head"
                );
            }

            $conn->commit();
            $msg = !empty($poResult['already_existed'])
                ? ('PR approved. Existing PO ' . $poResult['po_number'] . ' linked.')
                : ('PR approved and PO ' . $poResult['po_number'] . ' generated.');
            if (!empty($poResult['invoice_number'])) {
                $msg .= ' Supplier Invoice ' . $poResult['invoice_number'] . ' auto-created.';
            }
            jOut([
                'success' => true,
                'pr_id' => $id,
                'pr_number' => $pr['pr_number'],
                'status' => 'Approved',
                'po_id' => (int)$poResult['po_id'],
                'po_number' => $poResult['po_number'],
                'po_ids' => $poResult['po_ids'] ?? [(int)$poResult['po_id']],
                'po_numbers' => $poResult['po_numbers'] ?? [$poResult['po_number']],
                'invoice_id' => $poResult['invoice_id'] ?? null,
                'invoice_number' => $poResult['invoice_number'] ?? null,
                'already_existed' => !empty($poResult['already_existed']),
                'message' => $msg
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'add_pr_items':
        // Append extra lines to an existing PR without replacing what is already there.
        if ($isWarehouseHeadRole || (!$isAdminRole && !$isPurchaserRole)) {
            jOut(['success' => false, 'message' => 'Only the requesting Purchaser or an Administrator can add items to a Purchase Request']);
        }
        $id = (int)($_POST['id'] ?? $_POST['pr_id'] ?? 0);
        $newItems = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!$id) jOut(['success' => false, 'message' => 'Invalid PR ID']);
        if (!is_array($newItems) || empty($newItems)) {
            jOut(['success' => false, 'message' => 'Add at least one item.']);
        }

        $conn->begin_transaction();
        try {
            $prQ = $conn->query("SELECT * FROM purchase_requests WHERE id=$id FOR UPDATE");
            $pr = $prQ ? $prQ->fetch_assoc() : null;
            if (!$pr) throw new RuntimeException('Purchase Request not found');
            $prStatus = (string)$pr['status'];
            $isOwner = (int)($pr['requested_by'] ?? 0) === $uid;
            $editable = in_array($prStatus, ['Draft', 'Pending Approval'], true);
            if (!$editable) {
                if ($prStatus === 'Approved') {
                    throw new RuntimeException('Purchase Request ' . $pr['pr_number'] . ' is already approved and linked to a Purchase Order. Create a new Purchase Request for the additional items.');
                }
                if ($prStatus === 'Rejected') {
                    throw new RuntimeException('Rejected Purchase Requests must be revised with "Revise PR" before items can be added.');
                }
                throw new RuntimeException('Items cannot be added to a ' . $prStatus . ' Purchase Request.');
            }
            if (!$isAdminRole && !$isOwner) {
                throw new RuntimeException('You can only add items to your own Purchase Requests.');
            }

            $prSid = (int)($pr['supplier_id'] ?? 0);
            $supQ = $conn->query('SELECT name FROM suppliers WHERE id=' . $prSid . ' LIMIT 1');
            $supName = ($supQ && ($sr = $supQ->fetch_assoc())) ? (string)$sr['name'] : ('#' . $prSid);
            $beforeItems = prItemsSnapshot($conn, $id);
            $onPr = [];
            foreach ($beforeItems as $it) {
                if ((int)$it['product_id'] > 0) $onPr[(int)$it['product_id']] = $it;
            }

            $prepared = [];
            $seen = [];
            foreach ($newItems as $item) {
                if (!is_array($item)) continue;
                if (empty($item['product_id']) && trim((string)($item['product_name'] ?? '')) === '') continue;
                if ((int)($item['supplier_id'] ?? 0) <= 0) $item['supplier_id'] = $prSid;
                if ((int)$item['supplier_id'] !== $prSid) {
                    throw new RuntimeException('Items added to ' . $pr['pr_number'] . ' must use its supplier (' . $supName . '). Create a separate Purchase Request for another supplier.');
                }
                $err = prValidateRequestedItem($conn, $item);
                if ($err) throw new RuntimeException($err);
                $pid = (int)($item['product_id'] ?? 0);
                if ($pid > 0 && isset($onPr[$pid])) {
                    throw new RuntimeException($item['product_name'] . ' is already on this Purchase Request (qty ' . (int)$onPr[$pid]['quantity_requested'] . '). Change its quantity instead of adding it again.');
                }
                if ($pid > 0 && isset($seen[$pid])) {
                    throw new RuntimeException('The same product cannot be added twice in one Purchase Request: ' . $item['product_name']);
                }
                $seen[$pid > 0 ? $pid : ('n' . count($seen))] = true;
                $prepared[] = $item;
            }
            if (empty($prepared)) throw new RuntimeException('Add at least one complete item.');

            $si = $conn->prepare("INSERT INTO purchase_request_items (pr_id, product_id, supplier_id, product_name, quantity_requested, estimated_unit_price, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$si) throw new RuntimeException($conn->error ?: 'Could not add items');
            foreach ($prepared as $item) {
                $pid = !empty($item['product_id']) ? (int)$item['product_id'] : null;
                $pn = (string)$item['product_name'];
                $qty = (int)$item['quantity_requested'];
                $up = (float)$item['estimated_unit_price'];
                $rem = trim((string)($item['remarks'] ?? ''));
                $si->bind_param('iiisids', $id, $pid, $prSid, $pn, $qty, $up, $rem);
                if (!$si->execute()) throw new RuntimeException($si->error ?: $conn->error);
            }
            $si->close();

            $sumQ = $conn->query("SELECT COALESCE(SUM(quantity_requested * estimated_unit_price),0) AS t FROM purchase_request_items WHERE pr_id=$id");
            $newTotal = $sumQ ? (float)$sumQ->fetch_assoc()['t'] : 0.0;
            $conn->query("UPDATE purchase_requests SET total_estimated_amount=" . $newTotal . ", updated_at=NOW() WHERE id=$id");

            $changes = prDescribeItemChanges($beforeItems, prItemsSnapshot($conn, $id));
            logProcurementAudit($conn, $uid, 'PR_ITEMS_ADDED', 'purchase_requests', $id, [
                'status' => $prStatus,
                'total_estimated_amount' => (float)$pr['total_estimated_amount'],
            ], [
                'status' => $prStatus,
                'changes' => $changes,
                'added_count' => count($prepared),
                'total_estimated_amount' => $newTotal,
            ]);
            logProcurementUiAudit($conn, $uid, 'PR_ITEMS_ADDED', 'Added ' . count($prepared) . ' item(s) to Purchase Request ' . $pr['pr_number'] . ': ' . implode('; ', $changes));

            prNotifyPurchaserOfChanges($conn, $pr, $changes, $uid, 'Items Added to PR: ' . $pr['pr_number']);
            if ($prStatus === 'Pending Approval' && !$isAdminRole) {
                createNotification(
                    $conn,
                    'PR Updated: ' . $pr['pr_number'],
                    prActorName($conn, $uid) . ' added items to Purchase Request ' . $pr['pr_number'] . ' awaiting your approval: ' . implode('; ', $changes) . '. New total: ₱' . number_format($newTotal, 2) . '.',
                    'pending_approval',
                    'medium',
                    $pr['pr_number'],
                    'pr-approval',
                    'admin'
                );
            }

            $conn->commit();
            jOut([
                'success' => true,
                'pr_id' => $id,
                'pr_number' => $pr['pr_number'],
                'status' => $prStatus,
                'added' => count($prepared),
                'total_estimated_amount' => $newTotal,
                'changes' => $changes,
                'message' => count($prepared) . ' item(s) added to ' . $pr['pr_number'],
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'delete_pr':
        $id = (int)($_POST['id'] ?? 0);
        if (!$isAdminRole && !$isPurchaserRole) {
            jOut(['success' => false, 'message' => 'You do not have permission to delete Purchase Requests']);
        }
        $ownerSql = $isAdminRole ? '' : (' AND requested_by=' . (int)$uid);
        $conn->query("DELETE FROM purchase_requests WHERE id=$id AND status='Draft'" . $ownerSql);
        if ($conn->affected_rows > 0) {
            $conn->query("DELETE FROM purchase_request_items WHERE pr_id=$id");
            logProcurementAudit($conn, $uid, 'PR_DELETED', 'purchase_requests', $id, ['status' => 'Draft'], null);
        }
        jOut(['success' => true]);
        break;

    // ─── PURCHASE ORDERS (PO) ──────────────────────────────────────────────────
    case 'generate_po_from_pr':
        $pr_id = (int)($_POST['pr_id'] ?? 0);
        if (!$pr_id) jOut(['success' => false, 'message' => 'Invalid PR ID']);

        if ($isWarehouseHeadRole) {
            jOut(['success' => false, 'message' => 'Warehouse Head cannot generate POs. Only Admin can approve PRs and generate POs.']);
        }
        if (!$isPurchaserRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Purchaser or Administrator can generate a PO']);
        }

        $conn->begin_transaction();
        try {
            $prQ = $conn->query("SELECT * FROM purchase_requests WHERE id=$pr_id FOR UPDATE");
            $pr = $prQ ? $prQ->fetch_assoc() : null;
            if (!$pr) throw new RuntimeException('PR not found');
            if ($pr['status'] !== 'Approved') {
                throw new RuntimeException('Purchase Request must be Approved before generating a PO');
            }

            $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $edd = !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null;
            $notes = array_key_exists('notes', $_POST) ? trim($_POST['notes'] ?? '') : null;

            $poResult = generatePOFromApprovedPR($conn, $pr, $uid, null, $edd, $notes);
            if (empty($poResult['success'])) {
                throw new RuntimeException($poResult['message'] ?? 'Failed to generate PO');
            }

            if (empty($poResult['already_existed'])) {
                createNotification($conn, "PO Generated: " . $poResult['po_number'], "Purchase Order " . $poResult['po_number'] . " generated from PR " . $pr['pr_number'] . ".", "order_status", "medium", $poResult['po_number'], "procurement", null, (int)$pr['requested_by']);
                createNotification(
                    $conn,
                    "Incoming Delivery: " . $poResult['po_number'],
                    "Purchase Order " . $poResult['po_number'] . " was generated and is expected for warehouse receiving.",
                    "order_status",
                    "high",
                    $poResult['po_number'],
                    "incoming-deliveries",
                    "warehouse_head"
                );
            }

            $conn->commit();
            jOut([
                'success' => true,
                'po_id' => $poResult['po_id'],
                'po_number' => $poResult['po_number'],
                'invoice_id' => $poResult['invoice_id'] ?? null,
                'invoice_number' => $poResult['invoice_number'] ?? null,
                'already_existed' => !empty($poResult['already_existed']),
                'message' => $poResult['message'] ?? null
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_purchase_orders':
        poLifecycleEnsureSchema($conn);
        $where = []; $params = []; $types = '';
        $lifecycleFilter = trim((string)($_GET['lifecycle'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $filterKey = $lifecycleFilter !== '' ? $lifecycleFilter : $statusFilter;
        $statusList = poLifecycleFilterStatuses($filterKey);
        $postFilterLifecycle = in_array($filterKey, ['Sent to Supplier', 'Receiving', 'Completed'], true);
        if ($statusList !== null && $filterKey !== '' && strcasecmp($filterKey, 'all') !== 0 && !$postFilterLifecycle) {
            $placeholders = implode(',', array_fill(0, count($statusList), '?'));
            $where[] = "po.status IN ($placeholders)";
            foreach ($statusList as $st) {
                $params[] = $st;
                $types .= 's';
            }
        } elseif ($isWarehouseHeadRole && ($filterKey === '' || strcasecmp($filterKey, 'all') === 0)) {
            // Warehouse Head receiving view: hide drafts; keep approved / in-transit / receiving POs
            $where[] = "po.status NOT IN ('Draft','Cancelled','Rejected')";
        }
        if (!empty($_GET['supplier_id'])) {
            $where[] = 'po.supplier_id=?'; $params[] = (int)$_GET['supplier_id']; $types .= 'i';
        }
        $searchPo = trim((string)($_GET['po_number'] ?? ''));
        $searchSupplier = trim((string)($_GET['supplier'] ?? ''));
        if ($searchPo !== '') {
            $where[] = 'po.po_number LIKE ?';
            $params[] = '%' . $searchPo . '%';
            $types .= 's';
        }
        if ($searchSupplier !== '') {
            $where[] = 's.name LIKE ?';
            $params[] = '%' . $searchSupplier . '%';
            $types .= 's';
        }
        if (!empty($_GET['search'])) {
            $where[] = '(po.po_number LIKE ? OR s.name LIKE ? OR pr.pr_number LIKE ?)';
            $sq = '%' . $_GET['search'] . '%';
            $params[] = $sq; $params[] = $sq; $params[] = $sq; $types .= 'sss';
        }
        $wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql  = "SELECT po.*, s.name AS supplier_name, s.supplier_code, pr.pr_number,
                        u1.full_name AS requested_by_name, u2.full_name AS approved_by_name,
                        (SELECT COUNT(*) FROM purchase_order_items WHERE po_id=po.id) AS item_count,
                        (SELECT COALESCE(SUM(quantity_ordered),0) FROM purchase_order_items WHERE po_id=po.id) AS qty_ordered,
                        (SELECT COALESCE(SUM(COALESCE(quantity_received,0)),0) FROM purchase_order_items WHERE po_id=po.id) AS qty_received,
                        (SELECT GROUP_CONCAT(poi.product_name ORDER BY poi.id SEPARATOR ', ') FROM purchase_order_items poi WHERE poi.po_id=po.id) AS ordered_items,
                        (SELECT GROUP_CONCAT(DISTINCT bc.val ORDER BY bc.val SEPARATOR ', ')
                            FROM purchase_order_items poi
                            LEFT JOIN (
                                SELECT product_id, barcode_value AS val FROM product_barcodes WHERE status='active' AND barcode_value IS NOT NULL AND barcode_value <> ''
                                UNION
                                SELECT id, barcode FROM products WHERE barcode IS NOT NULL AND barcode <> ''
                            ) bc ON bc.product_id = poi.product_id
                            WHERE poi.po_id=po.id) AS barcodes,
                        (SELECT rec.id FROM purchase_receipts rec WHERE rec.po_id=po.id ORDER BY rec.id DESC LIMIT 1) AS latest_receipt_id,
                        (SELECT rec.receipt_number FROM purchase_receipts rec WHERE rec.po_id=po.id ORDER BY rec.id DESC LIMIT 1) AS latest_receipt_number,
                        (SELECT rec.workflow_status FROM purchase_receipts rec WHERE rec.po_id=po.id ORDER BY rec.id DESC LIMIT 1) AS receipt_workflow_status
                 FROM purchase_orders po
                 LEFT JOIN suppliers s ON po.supplier_id = s.id
                 LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
                 LEFT JOIN users u1 ON po.requested_by = u1.id
                 LEFT JOIN users u2 ON po.approved_by = u2.id
                 $wSQL ORDER BY po.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $ordered = (int)($r['qty_ordered'] ?? 0);
            $received = (int)($r['qty_received'] ?? 0);
            $r['qty_remaining'] = max(0, $ordered - $received);
            if (!empty($r['receipt_workflow_status'])) {
                $r['receipt_workflow_status'] = recvNormalizeWorkflowStatus($r['receipt_workflow_status']);
            }
            if (empty($r['receiving_status'])) {
                $r['receiving_status'] = $received > 0
                    ? ($received >= $ordered && $ordered > 0 ? 'Fully Received' : 'Partially Received')
                    : 'Expected to Arrive';
            }
            $rows[] = $r;
        }
        recvAttachInspectTotalsToPoRows($conn, $rows);
        foreach ($rows as &$r) {
            poAttachDeliveryMeta($r);
            poLifecycleAttach($r);
        }
        unset($r);
        if ($filterKey !== '' && strcasecmp($filterKey, 'all') !== 0) {
            $rows = array_values(array_filter($rows, static function ($row) use ($filterKey) {
                return poLifecycleMatches($row, $filterKey);
            }));
        }
        attachPricingToPurchaseOrders($conn, $rows);
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_incoming_deliveries':
        $incoming = [];
        $dateFromIn = trim((string)($_GET['date_from'] ?? ''));
        $dateToIn = trim((string)($_GET['date_to'] ?? ''));
        if ($dateFromIn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromIn)) {
            $dateFromIn = '';
        }
        if ($dateToIn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToIn)) {
            $dateToIn = '';
        }
        if ($dateFromIn !== '' && $dateToIn !== '' && $dateToIn < $dateFromIn) {
            jOut(['success' => false, 'message' => 'To Date cannot be earlier than From Date.']);
        }
        $incoming = recvFetchIncomingDeliveryRows($conn);
        $totalIncoming = count($incoming);
        if ($dateFromIn !== '' || $dateToIn !== '') {
            $incoming = array_values(array_filter($incoming, static function ($row) use ($dateFromIn, $dateToIn) {
                $edd = substr(trim((string)($row['expected_delivery_date'] ?? '')), 0, 10);
                if ($edd === '' || $edd === '0000-00-00') {
                    return false;
                }
                if ($dateFromIn !== '' && $edd < $dateFromIn) {
                    return false;
                }
                if ($dateToIn !== '' && $edd > $dateToIn) {
                    return false;
                }
                return true;
            }));
        }
        jOut(['success' => true, 'data' => $incoming, 'count' => $totalIncoming, 'filtered_count' => count($incoming)]);
        break;

    case 'get_note_to_supplier':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head (or Admin) can add a Note to Supplier']);
        }
        $ntsPoId = (int)($_GET['po_id'] ?? $_REQUEST['po_id'] ?? 0);
        if ($ntsPoId <= 0) {
            jOut(['success' => false, 'message' => 'Invalid PO ID']);
        }
        ensureReceivingWorkflowSchema($conn);
        $ntsLines = recvLoadDamagedReceivingNotes($conn, $ntsPoId);
        if (!$ntsLines) {
            jOut(['success' => false, 'message' => 'No damaged item was found on this incoming delivery.']);
        }
        $firstNts = $ntsLines[0];
        jOut([
            'success' => true,
            'data' => [
                'po_id' => $ntsPoId,
                'po_number' => (string)($firstNts['po_number'] ?? ''),
                'supplier_name' => (string)($firstNts['supplier_name'] ?? ''),
                'receipt_id' => (int)($firstNts['receipt_id'] ?? 0),
                'receipt_number' => (string)($firstNts['receipt_number'] ?? ''),
                'items' => $ntsLines,
            ],
        ]);
        break;

    case 'save_note_to_supplier':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head (or Admin) can add a Note to Supplier']);
        }
        ensureReceivingWorkflowSchema($conn);
        $ntsItems = [];
        $ntsJson = $_POST['notes'] ?? '';
        if (is_string($ntsJson) && $ntsJson !== '') {
            $decodedNts = json_decode($ntsJson, true);
            if (is_array($decodedNts)) {
                $ntsItems = $decodedNts;
            }
        }
        if (!$ntsItems) {
            $ntsItems[] = [
                'receipt_item_id' => (int)($_POST['receipt_item_id'] ?? 0),
                'note' => (string)($_POST['note'] ?? $_POST['note_to_supplier'] ?? ''),
            ];
        }
        try {
            $saved = 0;
            foreach ($ntsItems as $ntsRow) {
                if (!is_array($ntsRow)) {
                    continue;
                }
                $rid = (int)($ntsRow['receipt_item_id'] ?? 0);
                $note = trim((string)($ntsRow['note'] ?? $ntsRow['note_to_supplier'] ?? ''));
                if ($rid <= 0) {
                    continue;
                }
                recvSaveNoteToSupplierOnItem($conn, $rid, $note);
                $saved++;
            }
            if ($saved <= 0) {
                jOut(['success' => false, 'message' => 'No damaged receiving record was updated.']);
            }
            jOut(['success' => true, 'message' => 'Note to Supplier saved on the existing receiving record.']);
        } catch (Throwable $e) {
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'lookup_receive_barcode':
        require_once __DIR__ . '/product_barcodes_lib.php';
        productBarcodesEnsureSchema($conn);
        ensureReceivingWorkflowSchema($conn);
        $code = trim((string)($_GET['barcode'] ?? $_POST['barcode'] ?? ''));
        $lineProductId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
        $exact = (string)($_GET['exact'] ?? $_POST['exact'] ?? '') === '1';
        $look = recvLookupUniqueItemBarcode($conn, $code, $lineProductId, $exact);
        $payload = array_merge(['success' => true], $look);
        if (($look['status'] ?? '') === 'conflict' && empty($payload['conflict'])) {
            $payload['conflict'] = [
                'product_id' => (int)($look['product_id'] ?? 0),
                'product_name' => (string)($look['product_name'] ?? ''),
                'sku' => (string)($look['sku'] ?? ''),
            ];
        }
        jOut($payload);
        break;

    case 'register_receive_barcode':
        require_once __DIR__ . '/product_barcodes_lib.php';
        productBarcodesEnsureSchema($conn);
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head (or Admin) can register a barcode while receiving.']);
        }
        $code = trim((string)($_POST['barcode'] ?? $_GET['barcode'] ?? ''));
        $productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
        $poId = (int)($_POST['po_id'] ?? $_GET['po_id'] ?? 0);
        if ($code === '' || $productId <= 0) {
            jOut(['success' => false, 'message' => 'Product and barcode are required.']);
        }
        $supplierId = 0;
        if ($poId > 0) {
            $poRow = $conn->query('SELECT supplier_id FROM purchase_orders WHERE id=' . $poId . ' LIMIT 1');
            if ($poRow && ($pr = $poRow->fetch_assoc())) {
                $supplierId = (int)($pr['supplier_id'] ?? 0);
            }
        }
        $result = productBarcodesRegister($conn, $productId, $code, [
            'source' => 'received',
            'purchase_order_id' => $poId,
            'supplier_id' => $supplierId,
            'registered_by' => $uid,
        ]);
        if (!empty($result['ok'])) {
            productBarcodesLogAudit($conn, $uid, 'BARCODE_REGISTERED', $code . ' → product ' . $productId);
            $list = productBarcodesListForProduct($conn, $productId);
            $values = [];
            foreach ($list as $bc) {
                if (strtolower((string)($bc['status'] ?? '')) !== 'active') continue;
                $val = trim((string)($bc['barcode_value'] ?? ''));
                if ($val !== '') $values[] = $val;
            }
            jOut([
                'success' => true,
                'status' => $result['status'] ?? 'registered',
                'message' => ($result['status'] ?? '') === 'linked'
                    ? 'Barcode already linked to this product.'
                    : 'Barcode registered to this product.',
                'barcode' => $code,
                'product_id' => $productId,
                'barcode_values' => $values,
            ]);
        }
        jOut([
            'success' => false,
            'status' => $result['status'] ?? 'error',
            'message' => $result['message'] ?? 'Could not register barcode.',
            'conflict' => $result['conflict'] ?? null,
            'barcode' => $code,
        ]);
        break;

    case 'reassign_product_barcode':
        require_once __DIR__ . '/product_barcodes_lib.php';
        productBarcodesEnsureSchema($conn);
        if (!$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Admin can reassign a barcode.']);
        }
        $code = trim((string)($_POST['barcode'] ?? ''));
        $toId = (int)($_POST['product_id'] ?? $_POST['to_product_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $result = productBarcodesReassign($conn, $code, $toId, $reason, [
            'registered_by' => $uid,
            'override_source' => 'admin',
        ]);
        if (!empty($result['ok'])) {
            productBarcodesLogAudit($conn, $uid, 'BARCODE_REASSIGNED', $code . ' → product ' . $toId . ': ' . $reason);
            logProcurementAudit($conn, $uid, 'BARCODE_REASSIGNED', 'product_barcodes', $toId, null, [
                'barcode' => $code,
                'to_product_id' => $toId,
                'reason' => $reason,
            ]);
        }
        jOut([
            'success' => !empty($result['ok']),
            'message' => $result['message'] ?? 'Unable to reassign barcode.',
            'status' => $result['status'] ?? 'error',
        ]);
        break;

    case 'get_po_detail':
        $id = (int)($_GET['id'] ?? 0);
        ensureReceivingWorkflowSchema($conn);
        $stmt = $conn->prepare("SELECT po.*, s.name AS supplier_name, s.supplier_code, s.email AS supplier_email, s.phone AS supplier_phone, s.address AS supplier_address, s.payment_terms AS supplier_payment_terms,
                                       pr.pr_number, u1.full_name AS requested_by_name, u2.full_name AS approved_by_name
                                FROM purchase_orders po
                                LEFT JOIN suppliers s ON po.supplier_id = s.id
                                LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
                                LEFT JOIN users u1 ON po.requested_by = u1.id
                                LEFT JOIN users u2 ON po.approved_by = u2.id
                                WHERE po.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $po = $stmt->get_result()->fetch_assoc();
        if (!$po) jOut(['success' => false, 'message' => 'PO not found']);

        $etCol = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_tracking'");
        $etSelect = ($etCol && $etCol->num_rows > 0) ? 'COALESCE(p.expiry_tracking, 0) AS expiry_tracking' : '0 AS expiry_tracking';
        $items_stmt = $conn->prepare("SELECT poi.*, p.sku, p.brand, p.barcode, p.unit_of_measure,
                                       COALESCE(p.selling_price, 0) AS product_selling,
                                       $etSelect
                                FROM purchase_order_items poi LEFT JOIN products p ON poi.product_id=p.id WHERE poi.po_id=?");
        $items_stmt->bind_param('i', $id);
        $items_stmt->execute();
        $items_res = $items_stmt->get_result();
        $items = [];
        while ($i = $items_res->fetch_assoc()) $items[] = $i;

        $latestRid = 0;
        $latestWf = '';
        $lr = $conn->query("SELECT id, workflow_status FROM purchase_receipts WHERE po_id=$id ORDER BY id DESC LIMIT 1");
        if ($lr && ($lrRow = $lr->fetch_assoc())) {
            $latestRid = (int)($lrRow['id'] ?? 0);
            $latestWf = recvNormalizeWorkflowStatus($lrRow['workflow_status'] ?? '');
        }
        $po['latest_receipt_id'] = $latestRid;
        $po['receipt_workflow_status'] = $latestWf;
        $byPoItem = [];
        if ($latestRid > 0) {
            $ri = $conn->query("SELECT po_item_id, product_name,
                COALESCE(NULLIF(cost_price, 0), unit_price, 0) AS purchase_price,
                COALESCE(markup_percent, 0) AS markup_percent,
                COALESCE(selling_price, 0) AS selling_price
                FROM purchase_receipt_items WHERE receipt_id=$latestRid ORDER BY id");
            if ($ri) {
                while ($it = $ri->fetch_assoc()) {
                    $priced = normalizeReceiptPricing(
                        (float)$it['purchase_price'],
                        $it['markup_percent'],
                        $it['selling_price']
                    );
                    $key = (int)($it['po_item_id'] ?? 0);
                    if ($key > 0) $byPoItem[$key] = $priced;
                    $nameKey = strtolower(trim((string)($it['product_name'] ?? '')));
                    if ($nameKey !== '') $byPoItem['n:' . $nameKey] = $priced;
                }
            }
        }
        $items = productBarcodesHydrateRows($conn, $items, 'product_id');
        foreach ($items as &$i) {
            $keyed = $byPoItem[(int)($i['id'] ?? 0)]
                ?? $byPoItem['n:' . strtolower(trim((string)($i['product_name'] ?? '')))]
                ?? null;
            $cost = (float)($i['unit_price'] ?? 0);
            $sell = round((float)($i['product_selling'] ?? 0), 2);
            $mk = ($cost > 0 && $sell > 0) ? round((($sell - $cost) / $cost) * 100, 2) : 0;
            if ($sell <= 0) {
                $sell = $cost;
                $mk = 0;
            }
            $priced = ($keyed && $keyed['selling_price'] !== null && (float)$keyed['selling_price'] > 0)
                ? $keyed
                : normalizeReceiptPricing($cost, $mk, $sell);
            $i['purchase_price'] = $priced['purchase_price'];
            $i['markup_percent'] = $priced['markup_percent'];
            $i['markup_amount'] = $priced['markup_amount'];
            $i['selling_price'] = $priced['selling_price'];
        }
        unset($i);

        $items = recvAttachPoItemInspectState($conn, $id, $items);
        $inspectTot = recvPoInspectTotals($conn, $id);
        $po['qty_good'] = (int)$inspectTot['good'];
        $po['qty_damage_missing'] = (int)$inspectTot['damage_missing'];
        $po['qty_processed'] = (int)$inspectTot['processed'];
        $po['qty_remaining'] = max(0, (int)$inspectTot['remaining']);
        $po['qty_stocked'] = (int)($inspectTot['stocked'] ?? 0);
        $po['has_receiving_issue'] = !empty($inspectTot['has_issue']) ? 1 : 0;

        // Attach saved Note to Supplier from the original receiving lines (no qty changes).
        $noteByPoItem = [];
        foreach (recvLoadDamagedReceivingNotes($conn, $id) as $ntsRow) {
            $poiKey = (int)($ntsRow['po_item_id'] ?? 0);
            if ($poiKey > 0) {
                $noteByPoItem[$poiKey] = $ntsRow;
            }
        }
        foreach ($items as &$i) {
            $hit = $noteByPoItem[(int)($i['id'] ?? 0)] ?? null;
            $i['note_to_supplier'] = $hit ? trim((string)($hit['note_to_supplier'] ?? '')) : '';
            if ($hit && (int)($i['quantity_damage_missing'] ?? 0) <= 0) {
                $i['quantity_damage_missing'] = max(0, (int)($hit['damaged_qty'] ?? 0));
            }
        }
        unset($i);

        $printReceiptId = 0;
        if (recvHasColumn($conn, 'purchase_receipts', 'is_replacement')) {
            $origRec = $conn->query("SELECT id FROM purchase_receipts WHERE po_id=$id AND COALESCE(is_replacement,0)=0 ORDER BY id DESC LIMIT 1");
            if ($origRec && ($or = $origRec->fetch_assoc())) {
                $printReceiptId = (int)($or['id'] ?? 0);
            }
        }
        if ($printReceiptId <= 0) {
            $printReceiptId = $latestRid;
        }
        $po['receiving_receipt_id'] = $printReceiptId;

        $openInc = recvFindIncompleteReceipt($conn, $id);
        if ($openInc) {
            $po['incomplete_receipt_id'] = (int)$openInc['id'];
            $po['incomplete_receipt'] = [
                'id' => (int)$openInc['id'],
                'receipt_number' => (string)($openInc['receipt_number'] ?? ''),
                'driver_name' => (string)($openInc['driver_name'] ?? ''),
                'dr_number' => (string)($openInc['dr_number'] ?? ''),
                'supplier_si_number' => (string)($openInc['supplier_si_number'] ?? ''),
                'remarks' => (string)($openInc['remarks'] ?? ''),
                'date_received' => (string)($openInc['date_received'] ?? ''),
            ];
            $items = recvHydratePoItemsForContinueReceive($conn, $items, $id);
        } else {
            $po['incomplete_receipt_id'] = 0;
        }

        $po['items'] = $items;
        if (empty($po['receiving_status'])) $po['receiving_status'] = 'Expected to Arrive';
        $po['qty_ordered'] = (int)($inspectTot['ordered'] ?? 0);
        $po['qty_received'] = (int)($inspectTot['processed'] ?? 0);
        poAttachDeliveryMeta($po);
        jOut(['success' => true, 'data' => $po]);
        break;

    case 'create_po':
        jOut(['success' => false, 'message' => 'Purchase Orders must be generated from an approved Purchase Request.']);
        $f           = $_POST;
        $supplier_id = !empty($f['supplier_id']) ? (int)$f['supplier_id'] : null;
        $items_json  = $f['items'] ?? '[]';
        $items       = json_decode($items_json, true) ?? [];
        $edd         = !empty($f['expected_delivery_date']) ? $f['expected_delivery_date'] : null;
        $notes       = trim($f['notes'] ?? '');
        if (empty($items)) jOut(['success' => false, 'message' => 'No items in purchase order']);

        $r = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_orders WHERE YEAR(created_at)=" . date('Y'));
        $yearCnt = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
        $po_num = nextGeneratedPONumber($conn, $yearCnt);
        $total  = array_sum(array_map(fn($i) => ($i['quantity_ordered'] ?? 0) * ($i['unit_price'] ?? 0), $items));
        $status = ($f['submit_for_approval'] ?? '') === '1' ? 'Pending Approval' : 'Draft';

        $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, supplier_id, status, total_amount, expected_delivery_date, notes, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sisdssi', $po_num, $supplier_id, $status, $total, $edd, $notes, $uid);
        if (!$stmt->execute()) jOut(['success' => false, 'message' => $conn->error]);
        $po_id = $conn->insert_id;

        foreach ($items as $item) {
            $pid = !empty($item['product_id']) ? (int)$item['product_id'] : null;
            $pn  = trim($item['product_name'] ?? '');
            $qty = (int)($item['quantity_ordered'] ?? 1);
            $up  = (float)($item['unit_price'] ?? 0);
            $in  = trim($item['notes'] ?? '');
            $si  = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, product_name, quantity_ordered, unit_price, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $si->bind_param('iisids', $po_id, $pid, $pn, $qty, $up, $in);
            $si->execute();
        }

        logProcurementAudit($conn, $uid, 'PO_CREATED', 'purchase_orders', $po_id, null, ['po_number' => $po_num, 'status' => $status]);

        jOut(['success' => true, 'po_id' => $po_id, 'po_number' => $po_num]);
        break;

    case 'update_po_status':
        poLifecycleEnsureSchema($conn);
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['rejection_reason'] ?? '');
        if ($status === 'Rejected') {
            $status = 'Rejected';
        }
        $allowed_statuses = poLifecycleAllowedDbStatuses();
        if (!in_array($status, $allowed_statuses, true)) jOut(['success' => false, 'message' => 'Invalid status']);
        if ($isWarehouseHeadRole) {
            jOut(['success' => false, 'message' => 'Warehouse Head cannot approve or change PO status. Use Mark as Received / Stock-In for deliveries.']);
        }
        if (in_array($status, ['Approved', 'Cancelled', 'Rejected', 'Pending Approval'], true) && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only administrators can approve or reject purchase orders']);
        }
        if (!$isAdminRole && !$isPurchaserRole) {
            jOut(['success' => false, 'message' => 'You do not have permission to update purchase orders']);
        }

        $poQ = $conn->query("SELECT id, po_number, requested_by, status FROM purchase_orders WHERE id = $id");
        $po  = $poQ ? $poQ->fetch_assoc() : null;
        if (!$po) jOut(['success' => false, 'message' => 'PO not found']);

        if ($status === 'Rejected' && $notes === '') {
            jOut(['success' => false, 'message' => 'Please provide a rejection reason. The PO will be kept with status Rejected.']);
        }

        // Always UPDATE the same PO row. Never INSERT a new PO for approval/rejection.
        if ($status === 'Approved') {
            $stmt = $conn->prepare("UPDATE purchase_orders SET status=?, approved_by=?, approved_at=NOW() WHERE id=?");
            $stmt->bind_param('sii', $status, $uid, $id);
        } elseif ($status === 'Cancelled' || $status === 'Rejected') {
            $stmt = $conn->prepare("UPDATE purchase_orders SET status=?, rejection_reason=? WHERE id=?");
            $stmt->bind_param('ssi', $status, $notes, $id);
        } else {
            $stmt = $conn->prepare("UPDATE purchase_orders SET status=? WHERE id=?");
            $stmt->bind_param('si', $status, $id);
        }

        if ($stmt->execute()) {
            logProcurementAudit($conn, $uid, 'PO_STATUS_' . strtoupper($status), 'purchase_orders', $id, ['old_status' => $po['status'], 'po_number' => $po['po_number']], ['new_status' => $status, 'po_number' => $po['po_number']]);
            
            $poNum = $po['po_number'];
            $reqBy = (int)$po['requested_by'];
            createNotification($conn, "PO Status Update: " . $poNum, "Purchase Order " . $poNum . " status updated to: " . $status, "order_status", "medium", $poNum, "procurement", null, $reqBy);
            
            jOut(['success' => true, 'po_id' => $id, 'po_number' => $poNum, 'status' => $status]);
        } else {
            jOut(['success' => false, 'message' => 'Could not update purchase order status.']);
        }
        break;

    // ─── PURCHASE RECEIPTS (GOODS RECEIVED) ──────────────────────────────────
    case 'get_purchase_receipts':
        $where = []; $params = []; $types = '';
        if (!empty($_GET['search'])) {
            $where[] = '(rec.receipt_number LIKE ? OR po.po_number LIKE ? OR s.name LIKE ?)';
            $sq = '%' . $_GET['search'] . '%';
            $params[] = $sq; $params[] = $sq; $params[] = $sq; $types .= 'sss';
        }
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $where[] = 'rec.status = ?';
            $params[] = $_GET['status'];
            $types .= 's';
        }
        $wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql  = "SELECT rec.*, po.po_number, s.name AS supplier_name, u.full_name AS received_by_name,
                        (SELECT COUNT(*) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS item_count,
                        (SELECT SUM(quantity_received) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS total_received_qty
                 FROM purchase_receipts rec
                 JOIN purchase_orders po ON rec.po_id = po.id
                 LEFT JOIN suppliers s ON rec.supplier_id = s.id
                 LEFT JOIN users u ON rec.received_by = u.id
                 $wSQL ORDER BY rec.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $rows = recvFilterPurchaseReceiptsForGoodsReceived($conn, $rows);
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_receipt_detail':
        ensureReceivingWorkflowSchema($conn);
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) jOut(['success' => false, 'message' => 'Purchase Receipt not found']);
        $stmt = $conn->prepare("SELECT rec.*, po.po_number, s.name AS supplier_name, s.supplier_code,
                                       u.full_name AS received_by_name,
                                       um.full_name AS markup_by_name,
                                       ua.full_name AS approved_by_name
                                FROM purchase_receipts rec
                                JOIN purchase_orders po ON rec.po_id = po.id
                                LEFT JOIN suppliers s ON rec.supplier_id = s.id
                                LEFT JOIN users u ON rec.received_by = u.id
                                LEFT JOIN users um ON rec.markup_by = um.id
                                LEFT JOIN users ua ON rec.approved_by = ua.id
                                WHERE rec.id=?");
        if (!$stmt) {
            jOut(['success' => false, 'message' => 'Could not load receipt: ' . $conn->error]);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        if (!$rec) jOut(['success' => false, 'message' => 'Purchase Receipt not found']);
        $rec['workflow_status'] = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');

        $items_stmt = $conn->prepare("SELECT pri.*, p.sku, p.barcode, p.brand, COALESCE(NULLIF(TRIM(p.unit_of_measure), ''), 'pcs') AS unit_of_measure FROM purchase_receipt_items pri LEFT JOIN products p ON pri.product_id=p.id WHERE pri.receipt_id=?");
        $items_stmt->bind_param('i', $id);
        $items_stmt->execute();
        $items_res = $items_stmt->get_result();
        $items = [];
        while ($i = $items_res->fetch_assoc()) {
            $i['line_stock_status'] = recvNormalizeWorkflowStatus($i['line_stock_status'] ?? '');
            $ord = (int)($i['quantity_ordered'] ?? 0);
            $rcv = (int)($i['quantity_received'] ?? 0);
            $missing = isset($i['quantity_missing']) ? (int)$i['quantity_missing'] : max(0, $ord - $rcv);
            $i['quantity_missing'] = $missing;
            $i['reference_selling_price'] = round((float)($i['selling_price'] ?? 0), 2);
            $items[] = $i;
        }
        require_once __DIR__ . '/product_barcodes_lib.php';
        $items = productBarcodesHydrateReceiptItems($conn, $items, $id, (int)($rec['po_id'] ?? 0));
        $items = recvAttachLotsToReceiptItems($conn, $items);
        $items = recvAttachInspectionToReceiptItems($conn, $items);
        $forGoodsReceived = (int)($_GET['goods_received'] ?? $_POST['goods_received'] ?? 0) === 1;
        $isRepl = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
            && (int)($rec['is_replacement'] ?? 0) === 1;
        if ($forGoodsReceived && !$isRepl) {
            $items = recvApplyCurrentReceiptQtys($conn, (int)($rec['po_id'] ?? 0), $items);
            $items = recvFilterReceiptItemsForGoodsReceived($conn, $items, (int)($rec['po_id'] ?? 0), $id, false);
        } elseif ($isRepl) {
            $items = recvFilterReceiptItemsForGoodsReceived($conn, $items, (int)($rec['po_id'] ?? 0), $id, true);
        }
        $rec['is_replacement'] = $isRepl ? 1 : 0;
        $rec['replacement_order_number'] = $isRepl ? (string)($rec['receipt_number'] ?? '') : '';
        $rec['original_po_number'] = (string)($rec['po_number'] ?? '');
        $rec['original_receipt_number'] = '';
        if ($isRepl && (int)($rec['replaces_receipt_id'] ?? 0) > 0) {
            $orig = $conn->query('SELECT receipt_number FROM purchase_receipts WHERE id=' . (int)$rec['replaces_receipt_id'] . ' LIMIT 1');
            if ($orig && ($origRow = $orig->fetch_assoc())) {
                $rec['original_receipt_number'] = (string)($origRow['receipt_number'] ?? '');
            }
        }
        $displayStatus = (string)($rec['status'] ?? '');
        if ($isRepl) {
            $displayStatus = 'Received';
            foreach ($items as $replItem) {
                $replOrdered = max(0, (int)($replItem['quantity_ordered'] ?? 0));
                $replGood = max(0, (int)($replItem['quantity_good'] ?? 0));
                if ($replOrdered <= 0 || $replGood < $replOrdered) {
                    $displayStatus = 'To Received';
                    break;
                }
            }
        }
        $rec['display_status'] = $displayStatus;
        $rec['items'] = $items;
        $rec['can_set_markup'] = $canSetReceiptMarkup;
        $rec['can_grant_stock_in'] = $canGrantStockIn;
        $rec['can_execute_stock_in'] = $canExecuteStockIn;
        $rec['can_receive'] = $isWarehouseHeadRole || $isAdminRole;
        jOut(['success' => true, 'data' => $rec]);
        break;

    case 'update_purchase_receipt':
        if (!$isWarehouseHeadRole && !$isAdminRole && !$isPurchaserRole) {
            jOut(['success' => false, 'message' => 'You do not have permission to edit this Goods Received record']);
        }
        ensureReceivingWorkflowSchema($conn);
        $receiptId = (int)($_POST['receipt_id'] ?? $_POST['id'] ?? 0);
        if ($receiptId <= 0) {
            jOut(['success' => false, 'message' => 'Purchase Receipt not found']);
        }

        $curStmt = $conn->prepare("SELECT id, receipt_number, po_id, driver_name, dr_number, supplier_si_number, remarks FROM purchase_receipts WHERE id=? LIMIT 1");
        if (!$curStmt) {
            jOut(['success' => false, 'message' => $conn->error]);
        }
        $curStmt->bind_param('i', $receiptId);
        $curStmt->execute();
        $cur = $curStmt->get_result()->fetch_assoc();
        $curStmt->close();
        if (!$cur) {
            jOut(['success' => false, 'message' => 'Purchase Receipt not found']);
        }

        $driver = substr(trim((string)($_POST['driver_name'] ?? '')), 0, 150);
        $drNumber = substr(trim((string)($_POST['dr_number'] ?? '')), 0, 80);
        $supplierSi = substr(trim((string)($_POST['supplier_si_number'] ?? '')), 0, 80);
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        $upd = $conn->prepare("UPDATE purchase_receipts SET driver_name=?, dr_number=?, supplier_si_number=?, remarks=? WHERE id=? AND receipt_number=?");
        if (!$upd) {
            jOut(['success' => false, 'message' => $conn->error]);
        }
        $lockedNumber = (string)$cur['receipt_number'];
        $upd->bind_param('ssssis', $driver, $drNumber, $supplierSi, $remarks, $receiptId, $lockedNumber);
        if (!$upd->execute()) {
            jOut(['success' => false, 'message' => $upd->error ?: 'Failed to update Goods Received']);
        }
        $upd->close();

        $check = $conn->query("SELECT id, receipt_number FROM purchase_receipts WHERE id=" . (int)$receiptId);
        $after = $check ? $check->fetch_assoc() : null;
        if (!$after || $after['receipt_number'] !== $lockedNumber) {
            jOut(['success' => false, 'message' => 'Receipt # could not be preserved']);
        }

        jOut([
            'success' => true,
            'receipt_id' => (int)$after['id'],
            'receipt_number' => $after['receipt_number'],
            'message' => 'Goods Received updated. Receipt # ' . $after['receipt_number'] . ' is unchanged.',
        ]);
        break;

    case 'create_purchase_receipt':
        $po_id         = (int)($_POST['po_id'] ?? 0);
        $date_received = !empty($_POST['date_received']) ? $_POST['date_received'] : date('Y-m-d');
        $remarks       = trim($_POST['remarks'] ?? '');
        $driver_name   = trim($_POST['driver_name'] ?? '');
        $dr_number     = trim($_POST['dr_number'] ?? '');
        $supplier_si   = trim($_POST['supplier_si_number'] ?? '');
        $items_json    = $_POST['items'] ?? '[]';
        $items         = json_decode($items_json, true, 512, JSON_BIGINT_AS_STRING) ?? [];
        $rtsId         = (int)($_POST['rts_id'] ?? 0);
        $dmRepl        = (int)($_POST['receive_replacement'] ?? 0) === 1;

        // Warehouse Head receives deliveries. Admin may override. Purchaser cannot stock-bypass via receive.
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head (or Admin) can mark a PO as received']);
        }

        if (!$po_id) jOut(['success' => false, 'message' => 'Invalid PO ID']);
        if (empty($items)) jOut(['success' => false, 'message' => 'No items received in this receipt']);

        ensureReceivingWorkflowSchema($conn);
        require_once __DIR__ . '/product_barcodes_lib.php';
        productBarcodesEnsureSchema($conn);
        productBarcodesEnsureReceiptItemColumn($conn);

        $conn->begin_transaction();
        try {
            $poQ = $conn->query("SELECT * FROM purchase_orders WHERE id=$po_id FOR UPDATE");
            $po  = $poQ ? $poQ->fetch_assoc() : null;
            if (!$po) throw new RuntimeException('Purchase Order not found');
            if (in_array($po['status'], ['Cancelled'], true)) {
                throw new RuntimeException('This Purchase Order cannot be received because it is Cancelled.');
            }

            $postedReceiptId = (int)($_POST['receipt_id'] ?? 0);
            $openInc = ($postedReceiptId <= 0 && $rtsId <= 0 && !$dmRepl) ? recvFindIncompleteReceipt($conn, $po_id) : null;
            $isContinue = false;
            $receipt_id = 0;
            $rec_num = '';

            if ($postedReceiptId > 0) {
                $kept = recvKeepOriginalReceipt($conn, $postedReceiptId, $po_id, $date_received, $remarks, $driver_name, $dr_number, $supplier_si);
                $isContinue = true;
                $receipt_id = (int)$kept['id'];
                $rec_num = (string)$kept['receipt_number'];
            } elseif ($rtsId <= 0 && !$dmRepl) {
                $dupQ = $conn->query("SELECT id FROM purchase_receipts WHERE po_id=$po_id LIMIT 1");
                if ($dupQ && $dupQ->num_rows > 0) {
                    if (!$openInc) {
                        throw new RuntimeException('This Purchase Order has already been received. Stock will not be added twice.');
                    }
                    $kept = recvKeepOriginalReceipt($conn, (int)$openInc['id'], $po_id, $date_received, $remarks, $driver_name, $dr_number, $supplier_si);
                    $isContinue = true;
                    $receipt_id = (int)$kept['id'];
                    $rec_num = (string)$kept['receipt_number'];
                }
            }
            if ($rtsId > 0) {
                rtsEnsureSchema($conn);
                $openRepl = rtsOpenReplacement($conn, $rtsId);
                if (!$openRepl) {
                    throw new RuntimeException('Request a replacement on the RTS before receiving the replacement shipment.');
                }
                if (!empty($openRepl['replacement_receipt_id']) && (int)$openRepl['replacement_receipt_id'] !== (int)$receipt_id) {
                    throw new RuntimeException('This RTS replacement was already received.');
                }
            }

            $supplier_id = (int)$po['supplier_id'];
            $pr_id       = !empty($po['pr_id']) ? (int)$po['pr_id'] : null;
            $wfPendingMarkup = recvWfPendingMarkup();
            $workflow = $wfPendingMarkup;
            if (!$isContinue) {
                recvLockOriginalReceiptNumbers($conn);
                try {
                    $rec_num = recvNextOriginalReceiptNumber($conn);
                    $stmt = $conn->prepare("INSERT INTO purchase_receipts (receipt_number, po_id, pr_id, supplier_id, date_received, received_at, received_by, remarks, workflow_status, driver_name, dr_number, supplier_si_number) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('siiisisssss', $rec_num, $po_id, $pr_id, $supplier_id, $date_received, $uid, $remarks, $workflow, $driver_name, $dr_number, $supplier_si);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
                    $receipt_id = (int)$conn->insert_id;
                    $stmt->close();
                } finally {
                    recvUnlockOriginalReceiptNumbers($conn);
                }
            }
            if ($rtsId > 0) {
                $rtsCol = $conn->query("SHOW COLUMNS FROM purchase_receipts LIKE 'rts_id'");
                if ($rtsCol && $rtsCol->num_rows > 0) {
                    $conn->query("UPDATE purchase_receipts SET rts_id=$rtsId WHERE id=$receipt_id");
                }
                $rtsRow = rtsLoad($conn, $rtsId);
                $rtsTag = $rtsRow ? (string)$rtsRow['rts_number'] : ('#' . $rtsId);
                $tagEsc = $conn->real_escape_string('RTS replacement for ' . $rtsTag);
                $conn->query("UPDATE purchase_receipts SET remarks=CONCAT(COALESCE(remarks,''), IF(COALESCE(remarks,'')='','',' | '), '$tagEsc') WHERE id=$receipt_id");
            }
            if ($dmRepl) {
                if (recvHasColumn($conn, 'purchase_receipts', 'is_replacement')) {
                    $conn->query("UPDATE purchase_receipts SET is_replacement=1 WHERE id=$receipt_id");
                }
            }

            $receivedLines = 0;
            $hasDiscrepancy = false;
            $totalDamaged = 0;
            $totalMissing = 0;
            $progressed = false;
            $inspectBefore = recvPoInspectTotals($conn, $po_id);
            $replRemarkParts = [];
            $replacesReceiptId = 0;
            $replOriginDamage = 0;

            foreach ($items as $item) {
                $po_item_id   = !empty($item['po_item_id']) ? (int)$item['po_item_id'] : 0;
                $qty_damaged  = (int)($item['quantity_damaged'] ?? 0);
                $qty_missing  = (int)($item['quantity_missing'] ?? 0);
                $headerLot    = trim((string)($_POST['lot_number'] ?? $_POST['manufacturer_lot'] ?? ''));
                $mfr_lot      = trim((string)($item['manufacturer_lot'] ?? $item['lot_number'] ?? ''));
                if ($mfr_lot === '') $mfr_lot = $headerLot;
                $item_barcode = trim((string)($item['barcode'] ?? $item['barcode_id'] ?? ''));
                $inspectRecords = recvNormalizeInspectionRecords($item['inspected_barcodes'] ?? $item['inspection_barcodes'] ?? []);
                $item_barcodes = [];
                if (!empty($item['barcodes']) && is_array($item['barcodes'])) {
                    foreach ($item['barcodes'] as $bcVal) {
                        if (is_array($bcVal)) {
                            $bcVal = (string)($bcVal['barcode'] ?? $bcVal['barcode_value'] ?? '');
                        }
                        $bcVal = trim((string)$bcVal);
                        if ($bcVal !== '') $item_barcodes[] = $bcVal;
                    }
                }
                if ($item_barcode !== '') $item_barcodes[] = $item_barcode;
                if (!$inspectRecords && $item_barcodes) {
                    $inspectRecords = recvNormalizeInspectionRecords($item_barcodes);
                }
                $item_barcodes = recvSellableInspectionBarcodes($inspectRecords);
                if (!$item_barcodes) {
                    $item_barcodes = productBarcodesParseValueList($item_barcodes);
                }
                $barcodeOverride = !empty($item['barcode_override']) || !empty($item['override']);
                $barcodeOverrideReason = trim((string)($item['barcode_override_reason'] ?? $item['override_reason'] ?? ''));
                $exp_raw      = trim((string)($item['expiry_date'] ?? ''));
                $item_rem     = trim($item['remarks'] ?? '');

                if ($qty_damaged < 0 || $qty_missing < 0) throw new RuntimeException('Replacement quantity must be a whole number of 0 or greater.');
                if ($po_item_id <= 0) throw new RuntimeException('Received item is not linked to a Purchase Order item.');

                $lineStmt = $conn->prepare("SELECT poi.id, poi.product_id, poi.product_name, poi.quantity_ordered, COALESCE(poi.quantity_received, 0) AS quantity_received, poi.unit_price, p.sku, p.barcode FROM purchase_order_items poi LEFT JOIN products p ON poi.product_id = p.id WHERE poi.id = ? AND poi.po_id = ? FOR UPDATE");
                if (!$lineStmt) throw new RuntimeException($conn->error);
                $lineStmt->bind_param('ii', $po_item_id, $po_id);
                $lineStmt->execute();
                $line = $lineStmt->get_result()->fetch_assoc();
                $lineStmt->close();
                if (!$line) throw new RuntimeException('PO item not found for receiving.');

                $qty_ord = (int)$line['quantity_ordered'];
                $poLineOrdered = $qty_ord;
                $alreadyReceived = (int)$line['quantity_received'];
                $existingItemId = 0;
                $existingItem = null;
                if ($isContinue && $receipt_id > 0) {
                    $exQ = $conn->query("SELECT * FROM purchase_receipt_items WHERE receipt_id=$receipt_id AND po_item_id=$po_item_id LIMIT 1");
                    $existingItem = $exQ ? $exQ->fetch_assoc() : null;
                    $existingItemId = $existingItem ? (int)$existingItem['id'] : 0;
                }
                if ($alreadyReceived > 0 && $rtsId <= 0 && !$isContinue && !$dmRepl) {
                    throw new RuntimeException('PO item ' . $line['product_name'] . ' has already been received. Stock will not be added twice.');
                }
                $qty_rcv = $qty_ord;
                if ($rtsId > 0) {
                    $postedRcv = (int)($item['quantity_received'] ?? $item['quantity_ordered'] ?? 0);
                    $remainRepl = rtsReplacementOpenQty($conn, $rtsId);
                    if ($postedRcv <= 0) {
                        $postedRcv = $remainRepl;
                    }
                    if ($postedRcv <= 0 || $postedRcv > $remainRepl) {
                        throw new RuntimeException('Replacement quantity must be between 1 and ' . $remainRepl . '.');
                    }
                    $qty_ord = $postedRcv;
                    $qty_rcv = $postedRcv;
                }
                $postedGood = (int)($item['quantity_good'] ?? 0);
                if ($postedGood < 0) throw new RuntimeException('Good quantity cannot be negative.');
                $postedDm = max(0, (int)($item['quantity_damaged'] ?? 0) + (int)($item['quantity_missing'] ?? 0));
                $qty_damaged = 0;
                $prevGood = $existingItem ? (int)($existingItem['quantity_good'] ?? 0) : 0;
                $prevStocked = $existingItem ? (int)($existingItem['stocked_in_qty'] ?? 0) : 0;
                $prevLine = [
                    'quantity_ordered' => $qty_ord,
                    'quantity_good' => $prevGood,
                    'quantity_damaged' => $existingItem ? (int)($existingItem['quantity_damaged'] ?? 0) : 0,
                    'quantity_missing' => $existingItem ? (int)($existingItem['quantity_missing'] ?? 0) : 0,
                ];
                $prevProcessed = recvLineProcessedQty($prevLine);
                $inspectItem = $inspectBefore['items'][$po_item_id] ?? null;
                $remainingBefore = $inspectItem !== null
                    ? max(0, (int)$inspectItem['quantity_remaining'])
                    : recvLineRemainingQty($prevLine);
                if ($rtsId > 0) {
                    $qty_good = $postedGood;
                    if ($qty_good > $qty_ord) {
                        throw new RuntimeException('Goods cannot exceed ordered quantity for ' . $line['product_name'] . '.');
                    }
                    $qty_missing = max(0, $qty_ord - $qty_good);
                    $qty_rcv = $qty_good;
                    $lineIncomplete = $qty_missing > 0;
                    if ($lineIncomplete) $hasDiscrepancy = true;
                } elseif ($dmRepl) {
                    $origin = recvOriginalDamageReplacementSource($conn, $po_id, $po_item_id);
                    $shipment = recvUnresolvedOriginalDamageQty($conn, $po_id, $po_item_id);
                    if ($shipment <= 0) {
                        throw new RuntimeException('No unresolved To Received quantity remains for ' . $line['product_name'] . '.');
                    }
                    if ($postedGood > $shipment) {
                        throw new RuntimeException('Good quantity cannot exceed the To Received quantity (' . $shipment . ') for ' . $line['product_name'] . '.');
                    }
                    $qty_damaged = 0;
                    $qty_good = $postedGood;
                    $qty_missing = max(0, $shipment - $qty_good);
                    $qty_ord = $shipment;
                    $qty_rcv = $qty_good;
                    $lineIncomplete = $qty_missing > 0;
                    $qty_processed = $qty_good;
                    $progressed = true;
                    if ($replacesReceiptId <= 0 && (int)($origin['receipt_id'] ?? 0) > 0) {
                        $replacesReceiptId = (int)$origin['receipt_id'];
                    }
                    $causeQty = (int)($origin['damage_qty'] ?? 0);
                    if ($causeQty <= 0) {
                        $causeQty = $shipment;
                    }
                    $originNo = trim((string)($origin['receipt_number'] ?? ''));
                    if ($originNo === '') {
                        $originNo = 'the original receipt';
                    }
                    if (!isset($replRemarkParts[$originNo])) {
                        $replRemarkParts[$originNo] = 0;
                    }
                    $replRemarkParts[$originNo] += $causeQty;
                    $replOriginDamage += $causeQty;
                } else {
                    if ($isContinue) {
                        if ($remainingBefore <= 0) {
                            continue;
                        }
                        if (($postedGood + $postedDm) > $remainingBefore) {
                            throw new RuntimeException('Cannot receive more than the remaining quantity for ' . $line['product_name'] . '.');
                        }
                        if (($postedGood + $postedDm) <= 0) {
                            throw new RuntimeException('Enter a remaining quantity to receive for ' . $line['product_name'] . '.');
                        }
                        $qty_good = $prevGood + $postedGood;
                        $qty_missing = recvLineDamageMissingQty($prevLine) + $postedDm;
                    } else {
                        if (($postedGood + $postedDm) > $qty_ord) {
                            throw new RuntimeException('Goods plus Replacement cannot exceed ordered quantity for ' . $line['product_name'] . '.');
                        }
                        $qty_good = $postedGood;
                        $qty_missing = $postedDm;
                    }
                    if ($qty_good > $qty_ord) {
                        throw new RuntimeException('Goods cannot exceed ordered quantity for ' . $line['product_name'] . '.');
                    }
                    $qty_missing = max(0, $qty_ord - $qty_good);
                    $qty_processed = $qty_good;
                    $qty_rcv = $qty_good;
                    $lineIncomplete = recvLineRemainingQty([
                        'quantity_ordered' => $qty_ord,
                        'quantity_good' => $qty_good,
                        'quantity_damaged' => 0,
                        'quantity_missing' => $qty_missing,
                    ]) > 0;
                    if ($qty_missing > 0) $hasDiscrepancy = true;
                    if ($qty_processed > $prevGood) {
                        $progressed = true;
                    }
                }
                if ($qty_good < $prevStocked) {
                    throw new RuntimeException('Previously stocked good quantity cannot be reduced for ' . $line['product_name'] . '.');
                }

                $product_name = (string)$line['product_name'];
                if ($isContinue && $rtsId <= 0) {
                    $existingRecords = $existingItemId > 0 ? recvLoadInspectionBarcodes($conn, $existingItemId) : [];
                    $inspectRecords = recvMergeInspectionRecords($existingRecords, $inspectRecords);
                    $item_barcodes = recvSellableInspectionBarcodes($inspectRecords);
                } elseif ($isContinue) {
                    $existingRecords = $existingItemId > 0 ? recvLoadInspectionBarcodes($conn, $existingItemId) : [];
                    $existingKeys = [];
                    foreach ($existingRecords as $er) {
                        $ek = recvInspectionBarcodeKey((string)($er['barcode'] ?? ''));
                        if ($ek === '') continue;
                        $existingKeys[$ek] = $er;
                        if (recvInspectionIsSellable((string)($er['status'] ?? 'Good'))) {
                            $kept = false;
                            foreach ($inspectRecords as $nr) {
                                if (recvInspectionBarcodeKey((string)($nr['barcode'] ?? '')) === $ek) {
                                    $kept = true;
                                    break;
                                }
                            }
                            if (!$kept) {
                                throw new RuntimeException('Previously accepted barcodes cannot be removed for ' . $product_name . '.');
                            }
                        }
                    }
                    foreach ($inspectRecords as $idxRec => $nr) {
                        $nk = recvInspectionBarcodeKey((string)($nr['barcode'] ?? ''));
                        if ($nk !== '' && !isset($existingKeys[$nk])) {
                            $inspectRecords[$idxRec]['status'] = 'Replacement';
                        }
                    }
                    $item_barcodes = recvSellableInspectionBarcodes($inspectRecords);
                }
                $uniqueBarcodeCount = count($item_barcodes);
                if ($qty_good > 0 && $uniqueBarcodeCount === 0) {
                    throw new RuntimeException('Scan a barcode for ' . $product_name . ' before completing inspection.');
                }
                if ($qty_good > 0 && $uniqueBarcodeCount > 1 && $uniqueBarcodeCount !== $qty_good) {
                    throw new RuntimeException('Unique serial barcodes must match the good quantity for ' . $product_name . '.');
                }
                if ($uniqueBarcodeCount > $qty_good) {
                    throw new RuntimeException('Accepted barcodes cannot exceed the good quantity for ' . $product_name . '.');
                }
                if ($qty_good <= 0 && $item_barcodes) {
                    throw new RuntimeException('Good quantity must match the accepted barcodes for ' . $product_name . '.');
                }
                $product_id = recvResolveCatalogProductId(
                    $conn,
                    (int)($line['product_id'] ?? 0),
                    $product_name,
                    $line['sku'] ?? null,
                    $line['barcode'] ?? null
                );
                if ($product_id <= 0) {
                    error_log('[receiving] No valid products.id for PO item ' . $po_item_id . ' (' . $product_name . '), raw product_id=' . var_export($line['product_id'], true));
                    throw new RuntimeException('Unable to receive this delivery. Please check the item information and try again.');
                }
                if ((int)($line['product_id'] ?? 0) !== $product_id) {
                    $fixPid = $conn->prepare('UPDATE purchase_order_items SET product_id=? WHERE id=? AND po_id=?');
                    if ($fixPid) {
                        $fixPid->bind_param('iii', $product_id, $po_item_id, $po_id);
                        $fixPid->execute();
                        $fixPid->close();
                    }
                }
                foreach ($inspectRecords as $recBc) {
                    $code = trim((string)($recBc['barcode'] ?? ''));
                    if ($code === '') continue;
                    recvAssertBarcodeFreeForItem($conn, $code, $product_id, $existingItemId);
                }
                recvRegisterInspectedBarcodes($conn, $product_id, $item_barcodes, $product_name, [
                    'purchase_order_id' => $po_id,
                    'receiving_transaction_id' => $receipt_id,
                    'supplier_id' => $supplier_id,
                    'registered_by' => $uid,
                    'override' => $barcodeOverride,
                    'override_reason' => $barcodeOverrideReason,
                    'override_source' => 'receive_override',
                    'source' => 'received',
                ]);
                $lots = recvNormalizeLotEntries($item, $qty_good, $headerLot);
                if ($qty_good > 0 && $lots) {
                    $mfr_lot = trim((string)($lots[0]['lot_number'] ?? $mfr_lot));
                    $exp_raw = !empty($lots[0]['no_expiry']) ? '' : trim((string)($lots[0]['expiry_date'] ?? $exp_raw));
                }
                $exp_date = recvValidateExpiryDate($exp_raw, $date_received, false);
                $unit_price = (float)$line['unit_price'];
                $priced = recvResolveLinePricing($conn, $product_id, $unit_price);
                $qty_accepted = $qty_good;
                $qty_return = $qty_damaged;
                $unit_price = $priced['cost_price'];
                $markup = $priced['markup_percent'];
                $sell = $priced['selling_price'];
                $prevLineWf = $existingItem ? recvNormalizeWorkflowStatus((string)($existingItem['line_stock_status'] ?? '')) : '';
                if ($prevStocked > 0 && $qty_good === $prevStocked && $prevLineWf === recvWfStockedIn()) {
                    $lineStatus = recvWfStockedIn();
                } elseif ($qty_good > 0) {
                    $lineStatus = $wfPendingMarkup;
                } elseif ($lineIncomplete) {
                    $lineStatus = recvWfIncomplete();
                } else {
                    // Damage/Missing-only: inspection done, no good qty to price or stock.
                    $lineStatus = 'Received';
                }
                $totalMissing += $qty_missing;

                $batch_num = null;
                if ($existingItem && trim((string)($existingItem['batch_number'] ?? $existingItem['system_batch_number'] ?? '')) !== '') {
                    $batch_num = trim((string)($existingItem['system_batch_number'] ?? $existingItem['batch_number']));
                } elseif ($qty_good > 0) {
                    $batch_num = generateSystemBatchNumber($conn, $date_received);
                }

                $lots = recvNormalizeLotEntries($item, $qty_good, $headerLot);
                $inspectRecords = recvAttachLotsToInspectionRecords($inspectRecords, $lots);

                $receipt_item_id = $existingItemId;
                if ($receipt_item_id > 0) {
                    $updItem = $conn->prepare("UPDATE purchase_receipt_items SET quantity_ordered=?, quantity_received=?, quantity_good=?, quantity_damaged=?, quantity_missing=?, quantity_accepted=?, quantity_return=?, expiry_date=?, line_stock_status=?, remarks=? WHERE id=? AND receipt_id=?");
                    if (!$updItem) throw new RuntimeException($conn->error);
                    $updItem->bind_param(
                        'iiiiiiisssii',
                        $qty_ord,
                        $qty_rcv,
                        $qty_good,
                        $qty_damaged,
                        $qty_missing,
                        $qty_accepted,
                        $qty_return,
                        $exp_date,
                        $lineStatus,
                        $item_rem,
                        $receipt_item_id,
                        $receipt_id
                    );
                    if (!$updItem->execute()) throw new RuntimeException($updItem->error ?: $conn->error);
                    $updItem->close();
                } else {
                    // Prefer extended insert with quantity_missing when column exists
                    $hasMissingCol = false;
                    $mc = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE 'quantity_missing'");
                    if ($mc && $mc->num_rows > 0) $hasMissingCol = true;

                    if ($hasMissingCol) {
                        $riStmt = $conn->prepare("INSERT INTO purchase_receipt_items (receipt_id, po_item_id, product_id, product_name, quantity_ordered, quantity_received, quantity_good, quantity_damaged, quantity_missing, quantity_accepted, quantity_return, batch_number, expiry_date, unit_price, cost_price, markup_percent, selling_price, line_stock_status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        if (!$riStmt) throw new RuntimeException($conn->error);
                        $riStmt->bind_param(
                            'iiisiiiiiiissddddss',
                            $receipt_id,
                            $po_item_id,
                            $product_id,
                            $product_name,
                            $qty_ord,
                            $qty_rcv,
                            $qty_good,
                            $qty_damaged,
                            $qty_missing,
                            $qty_accepted,
                            $qty_return,
                            $batch_num,
                            $exp_date,
                            $unit_price,
                            $unit_price,
                            $markup,
                            $sell,
                            $lineStatus,
                            $item_rem
                        );
                    } else {
                        $riStmt = $conn->prepare("INSERT INTO purchase_receipt_items (receipt_id, po_item_id, product_id, product_name, quantity_ordered, quantity_received, quantity_good, quantity_damaged, quantity_accepted, quantity_return, batch_number, expiry_date, unit_price, cost_price, markup_percent, selling_price, line_stock_status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        if (!$riStmt) throw new RuntimeException($conn->error);
                        $riStmt->bind_param(
                            'iiisiiiiiissddddss',
                            $receipt_id,
                            $po_item_id,
                            $product_id,
                            $product_name,
                            $qty_ord,
                            $qty_rcv,
                            $qty_good,
                            $qty_damaged,
                            $qty_accepted,
                            $qty_return,
                            $batch_num,
                            $exp_date,
                            $unit_price,
                            $unit_price,
                            $markup,
                            $sell,
                            $lineStatus,
                            $item_rem
                        );
                    }
                    if (!$riStmt->execute()) throw new RuntimeException($riStmt->error ?: $conn->error);
                    $receipt_item_id = (int)$conn->insert_id;
                    $riStmt->close();
                    if ($receipt_item_id > 0 && recvHasColumn($conn, 'purchase_receipt_items', 'received_at')) {
                        $conn->query("UPDATE purchase_receipt_items SET received_at=NOW() WHERE id=$receipt_item_id AND received_at IS NULL");
                    }
                }

                $lotEsc = $conn->real_escape_string($mfr_lot);
                $sysEsc = $conn->real_escape_string((string)$batch_num);
                @$conn->query("UPDATE purchase_receipt_items SET manufacturer_lot='$lotEsc', system_batch_number='$sysEsc' WHERE id=$receipt_item_id");
                recvSaveReceiptItemLots($conn, $receipt_item_id, $receipt_id, $po_id, $po_item_id, $product_id, $lots);
                recvSaveInspectionBarcodes($conn, $receipt_id, $receipt_item_id, $po_id, $po_item_id, $product_id, $inspectRecords);
                productBarcodesSaveReceiptLine($conn, $receipt_item_id, $item_barcodes);
                productBarcodesStampReceiving($conn, $product_id, $item_barcodes, $receipt_id, $po_id);

                if ($rtsId <= 0) {
                    $goodSum = 0;
                    $sumQ = $conn->query("SELECT COALESCE(SUM(pri.quantity_good),0) AS g
                        FROM purchase_receipt_items pri
                        INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
                        WHERE pri.po_item_id=$po_item_id AND rec.po_id=$po_id");
                    if ($sumQ && ($sumRow = $sumQ->fetch_assoc())) {
                        $goodSum = max(0, (int)$sumRow['g']);
                    }
                    $poReceivedQty = min($poLineOrdered, $goodSum);
                    $updateLine = $conn->prepare("UPDATE purchase_order_items SET quantity_received = ? WHERE id = ?");
                    if (!$updateLine) throw new RuntimeException($conn->error);
                    $updateLine->bind_param('ii', $poReceivedQty, $po_item_id);
                    if (!$updateLine->execute()) throw new RuntimeException($updateLine->error ?: $conn->error);
                    $updateLine->close();
                }

                if ($dmRepl) {
                    if ($qty_good > 0) {
                        recvApplyGoodReplacementToDamageRecord($conn, $po_id, $po_item_id, $qty_good);
                    }
                } else {
                    // Combined Damage/Missing is never sellable. Do not auto-create an RTS.
                    recvUpsertDamageMissingRecord(
                        $conn,
                        $po_id,
                        $po_item_id,
                        $receipt_id,
                        $receipt_item_id,
                        $product_id,
                        $supplier_id,
                        $qty_ord,
                        $qty_rcv,
                        $qty_damaged,
                        $qty_missing,
                        $unit_price,
                        $item_rem,
                        $uid
                    );
                }
                if ($qty_damaged > 0) {
                    $totalDamaged += $qty_damaged;
                }

                // Damaged never enters sellable inventory. Good qty is stocked after this receipt if selling price is set.
                $receivedLines++;
            }

            if ($receivedLines === 0) {
                throw new RuntimeException('No valid received quantities were entered.');
            }
            if ($isContinue && $rtsId <= 0 && !$progressed) {
                throw new RuntimeException('This delivery has already been received. Duplicate receiving is not allowed.');
            }

            $inspectTot = recvPoInspectTotals($conn, $po_id);
            $totalOrd = (int)$inspectTot['ordered'];
            $totalRcv = (int)$inspectTot['processed'];
            $remainQty = max(0, (int)$inspectTot['remaining']);
            $remainN = 0;
            foreach ($inspectTot['items'] as $itRow) {
                if ((int)($itRow['quantity_remaining'] ?? 0) > 0) {
                    $remainN++;
                }
            }
            if (!empty($inspectTot['has_issue'])) {
                $hasDiscrepancy = true;
            }

            $newPoStatus = ($remainQty > 0) ? 'Partially Received' : 'Received';
            $recStatus = ($remainQty > 0) ? 'Partially Received' : 'Fully Received';

            $badPrice = $conn->query("SELECT COUNT(*) c FROM purchase_receipt_items WHERE receipt_id=$receipt_id AND quantity_good>0 AND (selling_price IS NULL OR selling_price<=0)");
            $missingPrice = $badPrice ? (int)$badPrice->fetch_assoc()['c'] : 1;
            $inventoryUpdated = false;
            $stockedQty = 0;
            $stockInNumber = '';
            $finalWf = $wfPendingMarkup;
            $recvLabel = 'Pending Stock-In';

            $goodSum = $conn->query("SELECT COALESCE(SUM(quantity_good),0) g FROM purchase_receipt_items WHERE receipt_id=$receipt_id");
            $goodTotal = $goodSum ? (int)$goodSum->fetch_assoc()['g'] : 0;
            $incStatus = recvWfIncomplete();
            $incEsc = $conn->real_escape_string($incStatus);
            $pendingGoodQ = $conn->query("SELECT COALESCE(SUM(GREATEST(0, quantity_good - COALESCE(stocked_in_qty,0))),0) g FROM purchase_receipt_items WHERE receipt_id=$receipt_id");
            $pendingGood = $pendingGoodQ ? (int)$pendingGoodQ->fetch_assoc()['g'] : 0;
            $remainExpr = recvSqlReceiptItemRemainingExpr($conn);
            if ($remainN > 0) {
                if (!$conn->query("UPDATE purchase_receipt_items SET line_stock_status='$incEsc' WHERE receipt_id=$receipt_id AND $remainExpr > 0 AND COALESCE(quantity_good,0) <= 0 AND COALESCE(line_stock_status,'') NOT IN ('Stocked In','Rejected','Cancelled')")) {
                    throw new RuntimeException($conn->error);
                }
            }
            $pendingMarkupEsc = $conn->real_escape_string($wfPendingMarkup);
            if ($pendingGood > 0) {
                if (!$conn->query("UPDATE purchase_receipt_items SET line_stock_status='$pendingMarkupEsc' WHERE receipt_id=$receipt_id AND quantity_good > COALESCE(stocked_in_qty,0) AND COALESCE(line_stock_status,'') NOT IN ('Stocked In','Rejected','Cancelled','Approved - Pending Stock-In','Granted - Pending Stock-In')")) {
                    throw new RuntimeException($conn->error);
                }
                if (recvHasColumn($conn, 'purchase_receipt_items', 'pricing_status')) {
                    $waitPs = $conn->real_escape_string(recvWfWaitingForPricing());
                    $conn->query("UPDATE purchase_receipt_items SET pricing_status='$waitPs' WHERE receipt_id=$receipt_id AND quantity_good > COALESCE(stocked_in_qty,0) AND COALESCE(pricing_status,'') <> 'Confirmed'");
                }
                $finalWf = $wfPendingMarkup;
                $recvLabel = 'Pending Markup';
            } elseif ($remainN > 0) {
                $finalWf = $incStatus;
                $recvLabel = $incStatus;
            } elseif ($goodTotal <= 0) {
                $finalWf = 'Received';
                $recvLabel = 'Received';
            } else {
                $alreadySi = $conn->query("SELECT COALESCE(SUM(COALESCE(stocked_in_qty,0)),0) s FROM purchase_receipt_items WHERE receipt_id=$receipt_id");
                $stockedQty = $alreadySi ? (int)$alreadySi->fetch_assoc()['s'] : 0;
                $inventoryUpdated = $stockedQty > 0;
                $finalWf = $inventoryUpdated ? recvWfStockedIn() : recvWfPendingMarkup();
                $recvLabel = $inventoryUpdated ? 'Stocked In' : 'Pending Markup';
            }
            if (recvHasColumn($conn, 'purchase_receipt_items', 'pricing_status')) {
                $conn->query("UPDATE purchase_receipt_items SET pricing_status=NULL WHERE receipt_id=$receipt_id AND COALESCE(quantity_good,0) <= 0");
            }

            if (!$conn->query("UPDATE purchase_receipts SET status='$recStatus', workflow_status='" . $conn->real_escape_string($finalWf) . "' WHERE id=$receipt_id")) throw new RuntimeException($conn->error);
            if (!$conn->query("UPDATE purchase_orders SET status='$newPoStatus', received_date='$date_received', actual_delivery_date='$date_received', receiving_status='" . $conn->real_escape_string($recvLabel) . "' WHERE id=$po_id")) throw new RuntimeException($conn->error);
            syncPoReceivingStatus($conn, $po_id);

            logProcurementAudit($conn, $uid, 'GOODS_RECEIVED', 'purchase_receipts', $receipt_id, null, [
                'receipt_number' => $rec_num,
                'po_id' => $po_id,
                'po_status' => $newPoStatus,
                'receipt_status' => $recStatus,
                'workflow_status' => $finalWf,
                'damaged_qty' => $totalDamaged,
                'missing_qty' => $totalMissing,
                'inventory_updated' => $inventoryUpdated,
                'driver_name' => $driver_name,
                'dr_number' => $dr_number,
                'supplier_si_number' => $supplier_si,
            ]);

            $inv = ensureSupplierInvoiceForPO($conn, $po_id, $uid, $dmRepl ? null : $receipt_id);

            if ($dmRepl && $replRemarkParts) {
                $sentences = [];
                foreach ($replRemarkParts as $originNo => $causeQty) {
                    $causeQty = (int)$causeQty;
                    if ($causeQty <= 0) {
                        continue;
                    }
                    $sentences[] = 'Replacement for ' . $causeQty . ' Replacement units from original receiving ' . $originNo;
                }
                if ($sentences) {
                    $sentence = implode(' | ', $sentences);
                    $postedNote = trim((string)$remarks);
                    $autoTitle = stripos($postedNote, 'Receive Replacement') === 0;
                    if ($postedNote !== '' && !$autoTitle && stripos($postedNote, 'Replacement for ') !== 0) {
                        $sentence .= ' | ' . $postedNote;
                    }
                    $sentenceEsc = $conn->real_escape_string($sentence);
                    $linkSql = '';
                    if ($replacesReceiptId > 0 && recvHasColumn($conn, 'purchase_receipts', 'replaces_receipt_id')) {
                        $linkSql .= ', replaces_receipt_id=' . (int)$replacesReceiptId;
                    }
                    if (recvHasColumn($conn, 'purchase_receipts', 'original_damage_qty')) {
                        $linkSql .= ', original_damage_qty=' . (int)$replOriginDamage;
                    }
                    if (!$conn->query("UPDATE purchase_receipts SET remarks='$sentenceEsc'$linkSql WHERE id=$receipt_id")) {
                        throw new RuntimeException($conn->error ?: 'Unable to record the original Replacement reference.');
                    }
                }
            }

            if ($rtsId > 0) {
                rtsAttachReplacementReceipt($conn, $rtsId, $receipt_id);
                logProcurementAudit($conn, $uid, 'RTS_REPLACEMENT_RECEIVED', 'purchase_receipts', $receipt_id, null, [
                    'rts_id' => $rtsId,
                    'receipt_number' => $rec_num,
                ]);
            }

            $conn->commit();

            if ($inventoryUpdated) {
                createNotification($conn, "Stocked In: " . $rec_num, "PO " . $po['po_number'] . " received. Good qty ($stockedQty) added to inventory. Damaged: $totalDamaged (not sellable).", "inventory_alert", "medium", $rec_num, "stock-in-queue", "admin");
            } elseif ($goodTotal > 0) {
                createNotification($conn, "Delivery Received: " . $rec_num, "PO " . $po['po_number'] . " was received. Good qty is waiting for Purchaser Markup. Replacement: $totalMissing (not sellable).", "order_status", "medium", $rec_num, "markup-queue", "purchaser");
            } else {
                createNotification($conn, "Delivery Received: " . $rec_num, "PO " . $po['po_number'] . " was received with no good qty to stock. Damaged: $totalDamaged.", "order_status", "medium", $rec_num, "purchase-receipts", "warehouse_head");
            }
            createNotification(
                $conn,
                "Delivery Received: " . ($po['po_number'] ?? $rec_num),
                $inventoryUpdated
                    ? ("PO " . ($po['po_number'] ?? '') . " was received and stocked in. Good qty ($stockedQty) is now in available inventory.")
                    : ($goodTotal > 0
                        ? ("PO " . ($po['po_number'] ?? '') . " was received. Good quantity continues to Purchaser Markup. Replacement stays pending.")
                        : ("PO " . ($po['po_number'] ?? '') . " was marked received. No good qty to add to inventory.")),
                "order_status",
                "medium",
                (string)($po['po_number'] ?? $rec_num),
                "incoming-deliveries",
                "warehouse_head"
            );

            $alreadyStockedQ = $conn->query("SELECT COALESCE(SUM(COALESCE(stocked_in_qty,0)),0) s FROM purchase_receipt_items WHERE receipt_id=$receipt_id");
            $alreadyStocked = $alreadyStockedQ ? (int)$alreadyStockedQ->fetch_assoc()['s'] : 0;
            if ($remainQty > 0) {
                $msg = 'Inspection saved. Status: Partially Received. Remaining: ' . $remainQty . '.'
                    . ($goodTotal > 0
                        ? ' Good quantity continues to Purchaser Markup.'
                        : ' No good qty to price yet.')
                    . ' Replacement is not remaining. Use Receive Remaining only for unprocessed quantities.';
            } elseif ($hasDiscrepancy) {
                $msg = 'Received with Issue. All ordered quantities are accounted for. Good quantity continues to Purchaser Markup. Replacement stays pending and is not listed as accepted goods.';
            } else {
                $msg = $goodTotal <= 0
                    ? 'Marked as Received. No good qty to stock (replacement only).'
                    : 'Received. Good quantity continues to Purchaser Markup. Replacement is not sellable.';
            }
            if ($dmRepl) {
                $msg = $goodTotal > 0
                    ? 'Replacement received. Good quantity continues to Purchaser Markup. Unresolved Replacement stays Pending.'
                    : 'Replacement inspected. No good quantity accepted. Pending Replacement is unchanged.';
                logProcurementAudit($conn, $uid, 'DAMAGE_REPLACEMENT_RECEIVED', 'purchase_receipts', $receipt_id, null, [
                    'receipt_number' => $rec_num,
                    'po_id' => $po_id,
                    'good_qty' => $goodTotal,
                ]);
            }

            jOut([
                'success' => true,
                'receipt_id' => $receipt_id,
                'receipt_number' => $rec_num,
                'po_id' => $po_id,
                'po_status' => $newPoStatus,
                'receiving_status' => $recvLabel,
                'workflow_status' => $finalWf,
                'damaged_qty' => $totalDamaged,
                'missing_qty' => $totalMissing,
                'inventory_updated' => $inventoryUpdated,
                'stocked_qty' => $stockedQty,
                'stock_in_number' => $stockInNumber,
                'remaining_qty' => $remainQty,
                'has_receiving_issue' => $hasDiscrepancy ? 1 : 0,
                'invoice_id' => $inv['invoice_id'] ?? null,
                'invoice_number' => $inv['invoice_number'] ?? null,
                'print_receipt' => $dmRepl ? ($goodTotal > 0) : true,
                'message' => $msg
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => recvPublicFailureMessage($e)]);
        }
        break;

    // ─── SUPPLIER INVOICES ───────────────────────────────────────────────────
    case 'get_supplier_invoices':
        $where = []; $params = []; $types = '';
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $where[] = 'inv.payment_status=?'; $params[] = $_GET['status']; $types .= 's';
        }
        if (!empty($_GET['search'])) {
            $where[] = '(inv.invoice_number LIKE ? OR po.po_number LIKE ? OR s.name LIKE ?)';
            $sq = '%' . $_GET['search'] . '%';
            $params[] = $sq; $params[] = $sq; $params[] = $sq; $types .= 'sss';
        }
        $wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql  = "SELECT inv.*, po.po_number, rec.receipt_number, s.name AS supplier_name, u.full_name AS recorded_by_name
                 FROM supplier_invoices inv
                 JOIN purchase_orders po ON inv.po_id = po.id
                 LEFT JOIN purchase_receipts rec ON inv.receipt_id = rec.id
                 LEFT JOIN suppliers s ON inv.supplier_id = s.id
                 LEFT JOIN users u ON inv.recorded_by = u.id
                 $wSQL ORDER BY inv.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_invoice_detail':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT inv.*, po.po_number, rec.receipt_number, s.name AS supplier_name, s.supplier_code, s.email AS supplier_email, u.full_name AS recorded_by_name
                                FROM supplier_invoices inv
                                JOIN purchase_orders po ON inv.po_id = po.id
                                LEFT JOIN purchase_receipts rec ON inv.receipt_id = rec.id
                                LEFT JOIN suppliers s ON inv.supplier_id = s.id
                                LEFT JOIN users u ON inv.recorded_by = u.id
                                WHERE inv.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $inv = $stmt->get_result()->fetch_assoc();
        if (!$inv) jOut(['success' => false, 'message' => 'Supplier Invoice not found']);

        // Fetch PO items for reference
        $items_stmt = $conn->prepare("SELECT poi.*, p.sku FROM purchase_order_items poi LEFT JOIN products p ON poi.product_id=p.id WHERE poi.po_id=?");
        $items_stmt->bind_param('i', $inv['po_id']);
        $items_stmt->execute();
        $items_res = $items_stmt->get_result();
        $items = [];
        while ($i = $items_res->fetch_assoc()) $items[] = $i;
        $inv['items'] = $items;

        jOut(['success' => true, 'data' => $inv]);
        break;

    case 'create_supplier_invoice':
        $inv_num    = trim($_POST['invoice_number'] ?? '');
        $po_id      = (int)($_POST['po_id'] ?? 0);
        $receipt_id = !empty($_POST['receipt_id']) ? (int)$_POST['receipt_id'] : null;
        $inv_date   = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d');
        $due_date   = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $subtotal   = (float)($_POST['subtotal'] ?? 0);
        $tax        = (float)($_POST['tax_amount'] ?? 0);
        $discount   = (float)($_POST['discount_amount'] ?? 0);
        $total      = (float)($_POST['total_amount'] ?? 0);
        $pay_status = $_POST['payment_status'] ?? 'Unpaid';
        $notes      = trim($_POST['notes'] ?? '');

        if (!$inv_num) jOut(['success' => false, 'message' => 'Invoice Number is required']);
        if (!$po_id) jOut(['success' => false, 'message' => 'Purchase Order selection is required']);

        $poQ = $conn->query("SELECT supplier_id, po_number FROM purchase_orders WHERE id=$po_id");
        $po  = $poQ ? $poQ->fetch_assoc() : null;
        if (!$po) jOut(['success' => false, 'message' => 'Selected PO does not exist']);

        $supplier_id = $po['supplier_id'];

        // File upload handling
        $scanned_url = null;
        if (!empty($_FILES['scanned_invoice']['name'])) {
            $stored = function_exists('clarenzon_store_upload')
                ? clarenzon_store_upload(
                    $_FILES['scanned_invoice'],
                    __DIR__ . '/uploads/invoices',
                    ['pdf', 'png', 'jpg', 'jpeg'],
                    ['application/pdf', 'image/png', 'image/jpeg'],
                    8 * 1024 * 1024
                )
                : ['ok' => false];
            if (!empty($stored['ok']) && !empty($stored['path'])) {
                $scanned_url = 'uploads/invoices/' . $stored['path'];
            }
        }

        $stmt = $conn->prepare("INSERT INTO supplier_invoices (invoice_number, po_id, receipt_id, supplier_id, invoice_date, due_date, subtotal, tax_amount, discount_amount, total_amount, payment_status, scanned_invoice_url, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('siiissddddsssi', $inv_num, $po_id, $receipt_id, $supplier_id, $inv_date, $due_date, $subtotal, $tax, $discount, $total, $pay_status, $scanned_url, $notes, $uid);

        if (!$stmt->execute()) jOut(['success' => false, 'message' => $conn->error]);
        $invoice_id = $conn->insert_id;

        logProcurementAudit($conn, $uid, 'INVOICE_RECORDED', 'supplier_invoices', $invoice_id, null, ['invoice_number' => $inv_num, 'total' => $total, 'payment_status' => $pay_status]);

        createNotification($conn, "Supplier Invoice Recorded: " . $inv_num, "Invoice " . $inv_num . " recorded for PO " . $po['po_number'] . ". Amount: ₱" . number_format($total, 2) . " (" . $pay_status . ").", "billing_invoice", "medium", $inv_num, "procurement");

        jOut(['success' => true, 'invoice_id' => $invoice_id, 'invoice_number' => $inv_num]);
        break;

    case 'update_invoice_payment_status':
        $id         = (int)($_POST['id'] ?? 0);
        $pay_status = $_POST['payment_status'] ?? 'Unpaid';
        if (!in_array($pay_status, ['Unpaid', 'Partially Paid', 'Paid'], true)) jOut(['success' => false, 'message' => 'Invalid payment status']);

        $stmt = $conn->prepare("UPDATE supplier_invoices SET payment_status=? WHERE id=?");
        $stmt->bind_param('si', $pay_status, $id);
        if ($stmt->execute()) {
            logProcurementAudit($conn, $uid, 'INVOICE_PAYMENT_UPDATED', 'supplier_invoices', $id, null, ['payment_status' => $pay_status]);
            jOut(['success' => true]);
        } else jOut(['success' => false, 'message' => $conn->error]);
        break;

    // ─── SUPPLIERS & PERFORMANCE (EXISTING) ─────────────────────────────────
    case 'get_suppliers':
        $where = []; $params = []; $types = '';
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $where[] = 'status=?'; $params[] = $_GET['status']; $types .= 's';
        }
        if (!empty($_GET['search'])) {
            $where[] = '(name LIKE ? OR supplier_code LIKE ? OR contact_person LIKE ?)';
            $s = '%' . $_GET['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s; $types .= 'sss';
        }
        $wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $conn->prepare("SELECT * FROM suppliers $wSQL ORDER BY name");
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'add_supplier':
        $f    = $_POST;
        $f['phone'] = trim((string)($f['phone'] ?? ''));
        $supplierPhoneError = clarenzonPhMobileError($f['phone']);
        if ($supplierPhoneError !== null) {
            jOut(['success' => false, 'message' => $supplierPhoneError]);
        }
        $stmt = $conn->prepare("INSERT INTO suppliers (supplier_code,name,contact_person,email,phone,address,city,country,payment_terms,lead_time_days,product_lines,rating,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ld = (int)($f['lead_time_days'] ?? 7);
        $rt = (float)($f['rating'] ?? 0);
        $stmt->bind_param('sssssssssisdss',
            $f['supplier_code']??'', $f['name']??'', $f['contact_person']??'',
            $f['email']??'', $f['phone']??'', $f['address']??'', $f['city']??'', $f['country']??'',
            $f['payment_terms']??'Net 30', $ld, $f['product_lines']??'', $rt,
            $f['status']??'Active', $f['notes']??'');
        if ($stmt->execute()) jOut(['success' => true, 'id' => $conn->insert_id]);
        else jOut(['success' => false, 'message' => $conn->error]);
        break;

    case 'update_supplier':
        $id   = (int)($_POST['id'] ?? 0);
        $f    = $_POST;
        $f['phone'] = trim((string)($f['phone'] ?? ''));
        $supplierPhoneError = clarenzonPhMobileError($f['phone']);
        if ($supplierPhoneError !== null) {
            jOut(['success' => false, 'message' => $supplierPhoneError]);
        }
        $ld   = (int)($f['lead_time_days'] ?? 7);
        $rt   = (float)($f['rating'] ?? 0);
        $stmt = $conn->prepare("UPDATE suppliers SET name=?,contact_person=?,email=?,phone=?,address=?,city=?,country=?,payment_terms=?,lead_time_days=?,product_lines=?,rating=?,status=?,notes=? WHERE id=?");
        $stmt->bind_param('sssssssssisdsi',
            $f['name']??'', $f['contact_person']??'', $f['email']??'', $f['phone']??'',
            $f['address']??'', $f['city']??'', $f['country']??'',
            $f['payment_terms']??'Net 30', $ld, $f['product_lines']??'', $rt,
            $f['status']??'Active', $f['notes']??'', $id);
        if ($stmt->execute()) jOut(['success' => true]);
        else jOut(['success' => false, 'message' => $conn->error]);
        break;

    case 'get_supplier_detail':
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if ($row) {
            $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE supplier_id=$id AND status!='Discontinued'");
            $row['product_count'] = $r2 ? (int)$r2->fetch_assoc()['cnt'] : 0;
            $r3 = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_orders WHERE supplier_id=$id");
            $row['po_count'] = $r3 ? (int)$r3->fetch_assoc()['cnt'] : 0;
            jOut(['success' => true, 'data' => $row]);
        } else jOut(['success' => false, 'message' => 'Supplier not found']);
        break;

    case 'get_performance':
        $sql = "SELECT s.id, s.name AS supplier_name, s.supplier_code, s.rating, s.lead_time_days,
                       COUNT(sp.id) AS total_deliveries,
                       SUM(sp.on_time) AS on_time_count,
                       ROUND(100.0*SUM(sp.on_time)/NULLIF(COUNT(sp.id),0),1) AS on_time_rate,
                       SUM(sp.quality_issues) AS quality_issues,
                       (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id=s.id AND status='Received') AS received_pos
                FROM suppliers s
                LEFT JOIN supplier_performance sp ON s.id = sp.supplier_id
                WHERE s.status='Active'
                GROUP BY s.id ORDER BY on_time_rate DESC";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'add_performance':
        $f   = $_POST;
        $sid = (int)($f['supplier_id'] ?? 0);
        $pid = !empty($f['po_id']) ? (int)$f['po_id'] : null;
        $exp = !empty($f['expected_delivery']) ? $f['expected_delivery'] : null;
        $act = !empty($f['actual_delivery'])   ? $f['actual_delivery']   : null;
        $ot  = ($act && $exp && $act <= $exp) ? 1 : 0;
        $qi  = (int)($f['quality_issues'] ?? 0);
        $qualityNotes = trim($f['quality_notes'] ?? '');
        $stmt = $conn->prepare("INSERT INTO supplier_performance (supplier_id,po_id,expected_delivery,actual_delivery,on_time,quality_issues,quality_notes,recorded_by) VALUES (?,?,?,?,?,?,?,?)");
        if (!$stmt) jOut(['success' => false, 'message' => $conn->error]);
        $stmt->bind_param('iissiisi', $sid, $pid, $exp, $act, $ot, $qi, $qualityNotes, $uid);
        if ($stmt->execute()) {
            $conn->query("UPDATE suppliers SET rating=(SELECT ROUND(AVG(on_time)*5,2) FROM supplier_performance WHERE supplier_id=$sid) WHERE id=$sid");
            jOut(['success' => true]);
        } else jOut(['success' => false, 'message' => $conn->error]);
        break;

    case 'get_customer_orders_for_pr':
        $exists = prCustomerOrdersTableExists($conn);
        $sales = $exists ? prLoadCustomerOrdersForCreate($conn) : [];
        $fromCustomers = prLoadCustomerPoEntriesForCreate($conn);
        $rows = prMergeCustomerOrderLists($sales, $fromCustomers);
        jOut([
            'success' => true,
            'source_available' => $exists || count($fromCustomers) > 0 || count($rows) > 0,
            'data' => $rows,
            'party_names' => prLoadPartyNamesForCreate($conn),
        ]);
        break;

    case 'get_customers':
        ensureCustomersSchema($conn);
        $q = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
        jOut(['success' => true, 'data' => prLoadCustomersForCreate($conn, $q)]);
        break;

    case 'create_customer':
        if (!$isPurchaserRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Purchaser or Admin can add customers']);
        }
        ensureCustomersSchema($conn);
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $suffix = trim((string)($_POST['suffix'] ?? ''));
        $name = trim((string)($_POST['name'] ?? $_POST['customer_name'] ?? ''));
        if ($name === '') {
            $name = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([$firstName, $middleName, $lastName, $suffix], static function ($part) {
                return $part !== '';
            }))));
        }
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $poRef = trim((string)($_POST['po_reference'] ?? $_POST['customer_po'] ?? $_POST['customer_order_reference'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            jOut(['success' => false, 'message' => 'First Name and Last Name are required.']);
        }
        if ($name === '') {
            jOut(['success' => false, 'message' => 'Customer name is required.']);
        }
        if ($poRef === '') {
            jOut(['success' => false, 'message' => 'Customer PO / Reference No. is required.']);
        }
        if (strlen($name) > 150) {
            jOut(['success' => false, 'message' => 'Customer name must be 150 characters or fewer.']);
        }
        if (strlen($firstName) > 80 || strlen($lastName) > 80) {
            jOut(['success' => false, 'message' => 'First Name and Last Name must be 80 characters or fewer.']);
        }
        if (strlen($poRef) > 120) {
            jOut(['success' => false, 'message' => 'Customer PO / Reference No. must be 120 characters or fewer.']);
        }
        $phoneError = clarenzonPhMobileError($phone);
        if ($phoneError !== null) {
            jOut(['success' => false, 'message' => $phoneError]);
        }
        if (strlen($email) > 120) {
            jOut(['success' => false, 'message' => 'Email must be 120 characters or fewer.']);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jOut(['success' => false, 'message' => 'Enter a valid email address.']);
        }
        $hasPoCol = false;
        $poCol = @$conn->query("SHOW COLUMNS FROM customers LIKE 'po_reference'");
        if ($poCol && $poCol->num_rows > 0) {
            $hasPoCol = true;
        }
        $hasNameParts = false;
        $namePartCol = @$conn->query("SHOW COLUMNS FROM customers LIKE 'first_name'");
        if ($namePartCol && $namePartCol->num_rows > 0) {
            $hasNameParts = true;
        }
        $chk = $conn->prepare("SELECT id, name, phone, email" . ($hasPoCol ? ', po_reference' : '') . " FROM customers WHERE name=? LIMIT 1");
        if ($chk) {
            $chk->bind_param('s', $name);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($existing) {
                $cid = (int)$existing['id'];
                $phoneSave = $phone !== '' ? $phone : (string)($existing['phone'] ?? '');
                $emailSave = $email !== '' ? $email : (string)($existing['email'] ?? '');
                $poSave = $poRef !== '' ? $poRef : (string)($existing['po_reference'] ?? '');
                if ($hasNameParts && $hasPoCol) {
                    $upd = $conn->prepare("UPDATE customers SET first_name=?, last_name=?, phone=?, email=?, po_reference=? WHERE id=?");
                    if ($upd) {
                        $upd->bind_param('sssssi', $firstName, $lastName, $phoneSave, $emailSave, $poSave, $cid);
                        $upd->execute();
                        $upd->close();
                    }
                } elseif ($hasPoCol) {
                    $upd = $conn->prepare("UPDATE customers SET phone=?, email=?, po_reference=? WHERE id=?");
                    if ($upd) {
                        $upd->bind_param('sssi', $phoneSave, $emailSave, $poSave, $cid);
                        $upd->execute();
                        $upd->close();
                    }
                }
                jOut([
                    'success' => true,
                    'message' => 'Customer already exists.',
                    'data' => [
                        'id' => $cid,
                        'name' => (string)$existing['name'],
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'phone' => $phoneSave,
                        'email' => $emailSave,
                        'po_reference' => $poSave,
                    ],
                    'existing' => true,
                ]);
            }
        }
        if ($hasNameParts && $hasPoCol) {
            $ins = $conn->prepare("INSERT INTO customers (name, first_name, last_name, phone, email, po_reference, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
            if (!$ins) {
                jOut(['success' => false, 'message' => $conn->error ?: 'Unable to save customer.']);
            }
            $ins->bind_param('ssssss', $name, $firstName, $lastName, $phone, $email, $poRef);
        } elseif ($hasPoCol) {
            $ins = $conn->prepare("INSERT INTO customers (name, phone, email, po_reference, status) VALUES (?, ?, ?, ?, 'Active')");
            if (!$ins) {
                jOut(['success' => false, 'message' => $conn->error ?: 'Unable to save customer.']);
            }
            $ins->bind_param('ssss', $name, $phone, $email, $poRef);
        } else {
            $ins = $conn->prepare("INSERT INTO customers (name, phone, email, status) VALUES (?, ?, ?, 'Active')");
            if (!$ins) {
                jOut(['success' => false, 'message' => $conn->error ?: 'Unable to save customer.']);
            }
            $ins->bind_param('sss', $name, $phone, $email);
        }
        if (!$ins->execute()) {
            jOut(['success' => false, 'message' => $ins->error ?: 'Unable to save customer.']);
        }
        $newId = (int)$conn->insert_id;
        $ins->close();
        jOut([
            'success' => true,
            'message' => 'Customer added.',
            'data' => [
                'id' => $newId,
                'name' => $name,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'email' => $email,
                'po_reference' => $poRef,
            ],
            'existing' => false,
        ]);
        break;

    case 'create_company':
        if (!$isPurchaserRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Purchaser or Admin can add companies']);
        }
        ensureCompaniesSchema($conn);
        $name = trim((string)($_POST['name'] ?? $_POST['company_name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $contact = trim((string)($_POST['contact_person'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($name === '') {
            jOut(['success' => false, 'message' => 'Company Name is required.']);
        }
        if (strlen($name) > 200) {
            jOut(['success' => false, 'message' => 'Company Name must be 200 characters or fewer.']);
        }
        if (strlen($address) > 255) {
            jOut(['success' => false, 'message' => 'Address must be 255 characters or fewer.']);
        }
        if (strlen($contact) > 150) {
            jOut(['success' => false, 'message' => 'Contact Person must be 150 characters or fewer.']);
        }
        $phoneError = clarenzonPhMobileError($phone);
        if ($phoneError !== null) {
            jOut(['success' => false, 'message' => $phoneError]);
        }
        if (strlen($email) > 120) {
            jOut(['success' => false, 'message' => 'Email must be 120 characters or fewer.']);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jOut(['success' => false, 'message' => 'Enter a valid email address.']);
        }
        $chk = $conn->prepare("SELECT id, name, address, contact_person, phone, email FROM companies WHERE name=? LIMIT 1");
        if ($chk) {
            $chk->bind_param('s', $name);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($existing) {
                $cid = (int)$existing['id'];
                $addressSave = $address !== '' ? $address : (string)($existing['address'] ?? '');
                $contactSave = $contact !== '' ? $contact : (string)($existing['contact_person'] ?? '');
                $phoneSave = $phone !== '' ? $phone : (string)($existing['phone'] ?? '');
                $emailSave = $email !== '' ? $email : (string)($existing['email'] ?? '');
                $upd = $conn->prepare("UPDATE companies SET address=?, contact_person=?, phone=?, email=? WHERE id=?");
                if ($upd) {
                    $upd->bind_param('ssssi', $addressSave, $contactSave, $phoneSave, $emailSave, $cid);
                    $upd->execute();
                    $upd->close();
                }
                jOut([
                    'success' => true,
                    'message' => 'Company already exists.',
                    'data' => [
                        'id' => $cid,
                        'name' => (string)$existing['name'],
                        'address' => $addressSave,
                        'contact_person' => $contactSave,
                        'phone' => $phoneSave,
                        'email' => $emailSave,
                    ],
                    'existing' => true,
                ]);
            }
        }
        $ins = $conn->prepare("INSERT INTO companies (name, address, contact_person, phone, email, status) VALUES (?, ?, ?, ?, ?, 'Active')");
        if (!$ins) {
            jOut(['success' => false, 'message' => $conn->error ?: 'Unable to save company.']);
        }
        $ins->bind_param('sssss', $name, $address, $contact, $phone, $email);
        if (!$ins->execute()) {
            jOut(['success' => false, 'message' => $ins->error ?: 'Unable to save company.']);
        }
        $newId = (int)$conn->insert_id;
        $ins->close();
        jOut([
            'success' => true,
            'message' => 'Company added.',
            'data' => [
                'id' => $newId,
                'name' => $name,
                'address' => $address,
                'contact_person' => $contact,
                'phone' => $phone,
                'email' => $email,
            ],
            'existing' => false,
        ]);
        break;

    case 'customers_list':
        ensureCustomersSchema($conn);
        customersEnsureSchema($conn);
        $list = customersList($conn, [
            'search' => (string)($_GET['search'] ?? ''),
            'status' => (string)($_GET['status'] ?? 'all'),
            'page' => (int)($_GET['page'] ?? 1),
            'per_page' => (int)($_GET['per_page'] ?? 15),
        ]);
        jOut(['success' => true] + $list);
        break;

    case 'customers_get':
        customersEnsureSchema($conn);
        $cid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $row = customersGet($conn, $cid);
        if (!$row) {
            jOut(['success' => false, 'message' => 'Customer not found.']);
        }
        jOut(['success' => true, 'data' => $row]);
        break;

    case 'customers_save':
        $canManageCustomers = $isPurchaserRole || $isAdminRole || $isWarehouseHeadRole || $isSalesRole || $roleKey === 'cashier';
        if (!$canManageCustomers) {
            jOut(['success' => false, 'message' => 'You do not have permission to save customers.']);
        }
        $saved = customersSave($conn, array_merge($_GET, $_POST));
        if (empty($saved['ok'])) {
            jOut(['success' => false, 'message' => $saved['message'] ?? 'Unable to save customer.']);
        }
        jOut(['success' => true, 'message' => $saved['message'], 'data' => $saved['data'] ?? null]);
        break;

    case 'customers_delete':
        $canManageCustomers = $isPurchaserRole || $isAdminRole || $isWarehouseHeadRole || $isSalesRole;
        if (!$canManageCustomers) {
            jOut(['success' => false, 'message' => 'You do not have permission to delete customers.']);
        }
        $deleted = customersDelete($conn, (int)($_POST['id'] ?? $_GET['id'] ?? 0));
        if (empty($deleted['ok'])) {
            jOut(['success' => false, 'message' => $deleted['message'] ?? 'Unable to delete customer.']);
        }
        jOut(['success' => true, 'message' => $deleted['message']]);
        break;

    case 'get_reorder_suggestions':
        // Same low-stock rule as Low Stock Alerts: sellable <= reorder_level.
        // Include every low-stock product so Create PR can add them. has_active_pr is
        // informational (already on a pending/approved PR that is not yet stocked in).
        $res = $conn->query("SELECT p.id, p.sku, p.name, p.brand, c.name AS category,
                                    p.quantity_in_stock, p.reorder_level, p.reorder_quantity,
                                    p.unit_of_measure, p.cost_price, p.status,
                                    s.id AS supplier_id, s.name AS supplier_name, s.lead_time_days,
                                    (p.reorder_quantity * p.cost_price) AS estimated_cost,
                                    opr_pr.pr_number AS open_pr_number,
                                    opr_pr.status AS open_pr_status,
                                    opr_po.po_number AS open_po_number,
                                    opr_po.receiving_status AS open_po_receiving_status,
                                    CASE WHEN EXISTS (
                                        SELECT 1 FROM purchase_request_items pri
                                        INNER JOIN purchase_requests pr ON pr.id = pri.pr_id
                                        LEFT JOIN purchase_orders po ON po.pr_id = pr.id
                                        WHERE pri.product_id = p.id
                                          AND pr.status IN ('Pending Approval', 'Approved')
                                          AND (po.id IS NULL OR COALESCE(po.receiving_status,'') <> 'Stocked In')
                                    ) THEN 1 ELSE 0 END AS has_active_pr
                             FROM products p
                             LEFT JOIN categories c ON p.category_id = c.id
                             LEFT JOIN suppliers s ON p.supplier_id = s.id
                             LEFT JOIN (
                                SELECT pri.product_id, MAX(pr.id) AS pr_id
                                FROM purchase_request_items pri
                                INNER JOIN purchase_requests pr ON pr.id = pri.pr_id
                                WHERE pr.status IN ('Draft','Pending Approval','Approved')
                                GROUP BY pri.product_id
                             ) opr ON opr.product_id = p.id
                             LEFT JOIN purchase_requests opr_pr ON opr_pr.id = opr.pr_id
                             LEFT JOIN purchase_orders opr_po ON opr_po.pr_id = opr_pr.id
                             WHERE p.status != 'Discontinued'
                             ORDER BY p.name ASC");
        $rows = [];
        $sellableMap = invWmsSellableQtyMap($conn);
        if ($res) while ($r = $res->fetch_assoc()) {
            $r['reorder_quantity'] = (int)$r['reorder_quantity'];
            $r = invWmsAttachSellableLowStock($r, $sellableMap);
            if ((int)$r['is_low_stock'] !== 1) continue;
            $suggested = (int)$r['reorder_quantity'];
            if ($suggested <= 0) $suggested = max(1, (int)$r['shortage']);
            $r['suggested_quantity'] = $suggested;
            $r['has_active_pr'] = (int)($r['has_active_pr'] ?? 0);
            $pipe = [
                'status' => $r['open_pr_status'] ?? '',
                'generated_po_id' => !empty($r['open_po_number']) ? 1 : 0,
                'generated_po_status' => '',
                'po_receiving_status' => $r['open_po_receiving_status'] ?? '',
            ];
            $r['procurement_status'] = !empty($r['open_pr_number'])
                ? prComputePipelineStatus($pipe)
                : '';
            $rows[] = $r;
        }
        usort($rows, static function ($a, $b) {
            $sc = ((int)$b['shortage'] <=> (int)$a['shortage']);
            if ($sc !== 0) return $sc;
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_catalog_categories':
        $res = $conn->query("SELECT id, name, parent_id FROM categories WHERE status='Active' ORDER BY name ASC");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'add_catalog_item':
        if (!$isPurchaserRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Purchaser or Admin can add catalog items.']);
        }
        require_once __DIR__ . '/product_catalog_fields_lib.php';
        require_once __DIR__ . '/product_barcodes_lib.php';
        $name = trim((string)($_POST['name'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $brand = trim((string)($_POST['brand'] ?? ''));
        $unit = trim((string)($_POST['unit_of_measure'] ?? ''));
        $reorderRaw = trim((string)($_POST['reorder_level'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($name === '') jOut(['success' => false, 'message' => 'Item Name is required.']);
        if ($sku === '') jOut(['success' => false, 'message' => 'SKU / Item Code is required.']);
        if ($categoryId <= 0) jOut(['success' => false, 'message' => 'Category is required.']);
        if ($unit === '') jOut(['success' => false, 'message' => 'Unit is required.']);
        if (strlen($name) > 200) jOut(['success' => false, 'message' => 'Item Name must be 200 characters or fewer.']);
        if (strlen($sku) > 50) jOut(['success' => false, 'message' => 'SKU / Item Code must be 50 characters or fewer.']);
        if (strlen($barcode) > 100) jOut(['success' => false, 'message' => 'Barcode must be 100 characters or fewer.']);
        if (strlen($brand) > 100) jOut(['success' => false, 'message' => 'Brand must be 100 characters or fewer.']);
        if (!in_array($unit, productCatalogUnitOptions(), true)) {
            jOut(['success' => false, 'message' => 'Select a valid unit.']);
        }
        $reorder = 0;
        if ($reorderRaw !== '') {
            if (!preg_match('/^\d+$/', $reorderRaw)) {
                jOut(['success' => false, 'message' => 'Reorder Level must be a whole number of 0 or greater.']);
            }
            $reorder = (int)$reorderRaw;
        }
        $catStmt = $conn->prepare("SELECT id FROM categories WHERE id=? AND status='Active' LIMIT 1");
        if (!$catStmt) jOut(['success' => false, 'message' => $conn->error]);
        $catStmt->bind_param('i', $categoryId);
        $catStmt->execute();
        $catRow = $catStmt->get_result()->fetch_assoc();
        $catStmt->close();
        if (!$catRow) jOut(['success' => false, 'message' => 'Select a valid category.']);
        $skuStmt = $conn->prepare("SELECT id FROM products WHERE LOWER(sku)=LOWER(?) LIMIT 1");
        if (!$skuStmt) jOut(['success' => false, 'message' => $conn->error]);
        $skuStmt->bind_param('s', $sku);
        $skuStmt->execute();
        $skuExists = $skuStmt->get_result()->fetch_assoc();
        $skuStmt->close();
        if ($skuExists) jOut(['success' => false, 'message' => 'This SKU is already used by another catalog item.']);
        if ($barcode !== '') {
            $linked = productBarcodesFind($conn, $barcode);
            if ($linked) jOut(['success' => false, 'message' => 'This barcode is already used by another catalog item.']);
            $bcStmt = $conn->prepare("SELECT id FROM products WHERE barcode IS NOT NULL AND TRIM(barcode)<>'' AND UPPER(TRIM(barcode))=UPPER(?) LIMIT 1");
            if (!$bcStmt) jOut(['success' => false, 'message' => $conn->error]);
            $bcStmt->bind_param('s', $barcode);
            $bcStmt->execute();
            $bcExists = $bcStmt->get_result()->fetch_assoc();
            $bcStmt->close();
            if ($bcExists) jOut(['success' => false, 'message' => 'This barcode is already used by another catalog item.']);
        }
        $status = 'Out of Stock';
        $barcodeVal = $barcode === '' ? null : $barcode;
        $brandVal = $brand === '' ? null : $brand;
        $descVal = $description === '' ? null : $description;
        $ins = $conn->prepare("INSERT INTO products (sku, name, barcode, brand, description, category_id, unit_of_measure, reorder_level, quantity_in_stock, unit_price, cost_price, selling_price, status) VALUES (?,?,?,?,?,?,?,?,0,0,0,0,?)");
        if (!$ins) jOut(['success' => false, 'message' => $conn->error]);
        $ins->bind_param('sssssisis', $sku, $name, $barcodeVal, $brandVal, $descVal, $categoryId, $unit, $reorder, $status);
        if (!$ins->execute()) {
            $err = $ins->error ?: $conn->error;
            $ins->close();
            if (stripos($err, 'Duplicate') !== false) {
                jOut(['success' => false, 'message' => 'This SKU or barcode is already used by another catalog item.']);
            }
            jOut(['success' => false, 'message' => $err]);
        }
        $newId = (int)$conn->insert_id;
        $ins->close();
        if ($barcodeVal === null && $newId > 0) {
            $conn->query("UPDATE products SET barcode=NULL WHERE id=$newId AND TRIM(COALESCE(barcode,''))=''");
        }
        if ($barcode !== '' && $newId > 0) {
            $reg = productBarcodesRegister($conn, $newId, $barcode, [
                'source' => 'manual',
                'registered_by' => $uid,
                'is_primary' => true,
            ]);
            if (empty($reg['ok'])) {
                $conn->query("DELETE FROM products WHERE id=$newId");
                jOut(['success' => false, 'message' => $reg['message'] ?? 'This barcode is already used by another catalog item.']);
            }
        }
        jOut([
            'success' => true,
            'id' => $newId,
            'message' => 'Catalog item added.',
            'data' => [
                'id' => $newId,
                'sku' => $sku,
                'name' => $name,
                'brand' => $brand,
                'unit_of_measure' => $unit,
                'reorder_level' => $reorder,
            ],
        ]);
        break;

    case 'get_products_for_po':
        // Enriched catalog for PR/PO: includes live stock, reorder levels, unit, brand, and low-stock flag.
        // Low-stock rule (single source of truth): sellable <= reorder_level, not Discontinued.
        $res  = $conn->query("SELECT p.id, p.sku, p.name, p.brand, p.cost_price, p.status,
                                     p.quantity_in_stock, p.reorder_level, p.reorder_quantity,
                                     p.unit_of_measure, p.supplier_id, s.name AS supplier_name,
                                     c.name AS category,
                                     CASE WHEN EXISTS (
                                         SELECT 1 FROM purchase_request_items pri
                                         INNER JOIN purchase_requests pr ON pr.id = pri.pr_id
                                         LEFT JOIN purchase_orders po ON po.pr_id = pr.id
                                         WHERE pri.product_id = p.id
                                           AND pr.status IN ('Pending Approval', 'Approved')
                                           AND (po.id IS NULL OR COALESCE(po.receiving_status,'') <> 'Stocked In')
                                     ) THEN 1 ELSE 0 END AS has_active_pr
                              FROM products p
                              LEFT JOIN suppliers s ON p.supplier_id = s.id
                              LEFT JOIN categories c ON p.category_id = c.id
                              WHERE p.status != 'Discontinued'
                              ORDER BY p.name ASC
                              LIMIT 500");
        $rows = [];
        $sellableMap = invWmsSellableQtyMap($conn);
        if ($res) while ($r = $res->fetch_assoc()) {
            $r['reorder_quantity'] = (int)$r['reorder_quantity'];
            $r = invWmsAttachSellableLowStock($r, $sellableMap);
            $suggested = (int)$r['reorder_quantity'];
            if ($suggested <= 0) $suggested = max(1, (int)$r['shortage']);
            $r['suggested_quantity'] = $suggested;
            $r['has_active_pr'] = (int)($r['has_active_pr'] ?? 0);
            $rows[] = $r;
        }
        usort($rows, static function ($a, $b) {
            $lc = ((int)$b['is_low_stock'] <=> (int)$a['is_low_stock']);
            if ($lc !== 0) return $lc;
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'get_suppliers_dropdown':
        $res  = $conn->query("SELECT id, supplier_code, name, contact_person, email, phone, status FROM suppliers WHERE status='Active' ORDER BY name");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        jOut(['success' => true, 'data' => $rows]);
        break;

    // ─── STOCK-IN QUEUE / MARKUP / DAMAGE RETURNS ─────────────────────────────
    case 'get_stock_in_queue':
        ensureReceivingWorkflowSchema($conn);
        $wf = trim($_GET['workflow_status'] ?? '');
        $pendingMarkup = recvWfPendingMarkup();
        $pendingAdmin = recvWfPendingAdmin();
        $granted = recvWfGranted();
        $where = "WHERE 1=1";
        if ($wf !== '' && $wf !== 'all') {
            $wfNorm = recvNormalizeWorkflowStatus($wf);
            $wfEsc = $conn->real_escape_string($wfNorm);
            // Include legacy aliases
            if ($wfNorm === $pendingMarkup) {
                $where .= " AND rec.workflow_status IN ('$wfEsc','Pending Stock-In')";
            } elseif ($wfNorm === $pendingAdmin) {
                $where .= " AND rec.workflow_status IN ('$wfEsc','Ready for Stock-In')";
            } else {
                $where .= " AND rec.workflow_status='$wfEsc'";
            }
        } else {
            $granted = recvWfGranted();
            $stocked = recvWfStockedIn();
            $confirmedPricing = $conn->real_escape_string(recvWfPricingConfirmed());
            $where .= " AND (
                rec.workflow_status IN ('$pendingAdmin','$granted','Ready for Stock-In','Approved - Pending Stock-In','Granted - Pending Stock-In')
                OR rec.id IN (
                    SELECT id FROM (
                        SELECT id FROM purchase_receipts
                        WHERE workflow_status='$stocked'
                        ORDER BY id DESC
                        LIMIT 25
                    ) recent_stocked
                )
                OR rec.id IN (
                    SELECT receipt_id FROM purchase_receipt_items
                    WHERE pricing_status='$confirmedPricing'
                      AND GREATEST(0, quantity_good - COALESCE(stocked_in_qty,0)) > 0
                      AND COALESCE(line_stock_status,'') NOT IN ('Stocked In','Rejected','Cancelled')
                )
                OR rec.id IN (
                    SELECT receipt_id FROM (
                        SELECT pri.receipt_id
                        FROM purchase_receipt_items pri
                        WHERE pri.line_stock_status='$stocked'
                           OR (
                                COALESCE(pri.quantity_good,0) > 0
                                AND COALESCE(pri.stocked_in_qty,0) >= pri.quantity_good
                           )
                        GROUP BY pri.receipt_id
                        ORDER BY MAX(pri.id) DESC
                        LIMIT 25
                    ) recent_item_stocked
                )
            )";
        }
        $sql = "SELECT rec.*, po.po_number, po.receiving_status, s.name AS supplier_name,
                       u.full_name AS received_by_name, um.full_name AS markup_by_name,
                       (SELECT COUNT(*) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS item_count,
                       (SELECT COALESCE(SUM(quantity_good),0) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS total_good,
                       (SELECT COALESCE(SUM(quantity_damaged),0) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS total_damaged,
                       (SELECT COALESCE(SUM(COALESCE(quantity_missing,0)),0) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS total_missing
                FROM purchase_receipts rec
                JOIN purchase_orders po ON rec.po_id=po.id
                LEFT JOIN suppliers s ON rec.supplier_id=s.id
                LEFT JOIN users u ON rec.received_by=u.id
                LEFT JOIN users um ON rec.markup_by=um.id
                $where
                ORDER BY
                  CASE
                    WHEN rec.workflow_status IN ('$pendingAdmin','Ready for Stock-In') THEN 0
                    WHEN rec.workflow_status IN ('$granted','Approved - Pending Stock-In','Granted - Pending Stock-In') THEN 1
                    WHEN rec.workflow_status IN ('$pendingMarkup','Pending Stock-In') THEN 2
                    ELSE 3
                  END,
                  rec.id DESC
                LIMIT 200";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['workflow_status'] = recvNormalizeWorkflowStatus($r['workflow_status'] ?? '');
                $r['items'] = [];
                $rows[] = $r;
            }
        }
        if ($rows) {
            $idList = implode(',', array_map(static function ($r) {
                return (int)$r['id'];
            }, $rows));
            $markupAmtExpr = recvHasColumn($conn, 'purchase_receipt_items', 'markup_amount')
                ? 'pri.markup_amount'
                : 'NULL';
            $itemSql = "SELECT pri.id AS receipt_item_id, pri.receipt_id, pri.product_id, pri.product_name, pri.quantity_good,
                               COALESCE(pri.received_at, (SELECT rcv.received_at FROM purchase_receipts rcv WHERE rcv.id = pri.receipt_id LIMIT 1)) AS received_at,
                               pri.pricing_confirmed_at,
                               COALESCE(pri.quantity_ordered, 0) AS quantity_ordered,
                               COALESCE(pri.quantity_damaged, 0) AS quantity_damaged,
                               COALESCE(pri.quantity_missing, 0) AS quantity_missing,
                               COALESCE(pri.stocked_in_qty, 0) AS stocked_in_qty,
                               COALESCE(pri.line_stock_status, '') AS line_stock_status,
                               COALESCE(pri.pricing_status, '') AS pricing_status,
                               COALESCE(NULLIF(pri.cost_price, 0), pri.unit_price, 0) AS purchase_price,
                               COALESCE(pri.markup_percent, 0) AS markup_percent,
                               $markupAmtExpr AS markup_amount,
                               COALESCE(pri.tax_percent, 0) AS tax_percent,
                               COALESCE(pri.tax_amount, 0) AS tax_amount,
                               COALESCE(pri.apply_markup, 0) AS apply_markup,
                               COALESCE(pri.apply_tax, 0) AS apply_tax,
                               COALESCE(pri.selling_price, 0) AS selling_price,
                               COALESCE(pri.manufacturer_lot, '') AS manufacturer_lot,
                               pri.expiry_date,
                               COALESCE(p.barcode, '') AS barcode,
                               COALESCE(p.sku, '') AS sku,
                               " . (recvHasColumn($conn, 'purchase_receipt_items', 'scanned_barcodes') ? 'pri.scanned_barcodes' : "'' AS scanned_barcodes") . "
                        FROM purchase_receipt_items pri
                        LEFT JOIN products p ON p.id = pri.product_id
                        WHERE pri.receipt_id IN ($idList)
                        ORDER BY pri.id";
            $itemRes = $conn->query($itemSql);
            $byRec = [];
            $stockInLotMap = [];
            $stockInLotEntries = [];
            if ($itemRes) {
                $flatItems = [];
                while ($it = $itemRes->fetch_assoc()) {
                    $flatItems[] = $it;
                }
                $barcodeValuesByProduct = [];
                $primaryBarcodeByProduct = [];
                $productIds = [];
                foreach ($flatItems as $it) {
                    $pid = (int)($it['product_id'] ?? 0);
                    if ($pid > 0) {
                        $productIds[$pid] = $pid;
                    }
                }
                if ($productIds && function_exists('productBarcodesTableReady') && productBarcodesTableReady($conn)) {
                    $bcRes = $conn->query(
                        'SELECT product_id, barcode_value, is_primary, status FROM product_barcodes'
                        . ' WHERE product_id IN (' . implode(',', $productIds) . ')'
                        . ' ORDER BY is_primary DESC, registered_at ASC, barcode_id ASC'
                    );
                    if ($bcRes) {
                        while ($bc = $bcRes->fetch_assoc()) {
                            if (strtolower((string)($bc['status'] ?? 'active')) !== 'active') {
                                continue;
                            }
                            $val = trim((string)($bc['barcode_value'] ?? ''));
                            if ($val === '') {
                                continue;
                            }
                            $pid = (int)$bc['product_id'];
                            $barcodeValuesByProduct[$pid][] = $val;
                            if (!isset($primaryBarcodeByProduct[$pid]) && (int)($bc['is_primary'] ?? 0) === 1) {
                                $primaryBarcodeByProduct[$pid] = $val;
                            }
                        }
                    }
                }
                foreach ($flatItems as &$it) {
                    $pid = (int)($it['product_id'] ?? 0);
                    $values = $barcodeValuesByProduct[$pid] ?? [];
                    $it['barcode_values'] = $values;
                    $primary = $primaryBarcodeByProduct[$pid] ?? '';
                    if ($primary === '' && $values) {
                        $primary = $values[0];
                    }
                    if ($primary !== '') {
                        $it['barcode'] = $primary;
                    }
                }
                unset($it);
                $scanMap = recvInspectionBarcodeMap($conn, array_map(static function ($it) {
                    return (int)($it['receipt_item_id'] ?? 0);
                }, $flatItems));
                foreach ($flatItems as &$it) {
                    $itemId = (int)($it['receipt_item_id'] ?? 0);
                    $scanned = $scanMap[$itemId] ?? [];
                    if (!$scanned && !empty($it['scanned_barcodes'])) {
                        $scanned = recvSellableInspectionBarcodes(recvNormalizeInspectionRecords($it['scanned_barcodes']));
                    }
                    $seen = [];
                    $scannedUnique = [];
                    foreach ($scanned as $code) {
                        $key = strtoupper(trim((string)$code));
                        if ($key === '' || isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $scannedUnique[] = (string)$code;
                    }
                    $it['scanned_barcodes'] = $scannedUnique;
                    $values = is_array($it['barcode_values'] ?? null) ? $it['barcode_values'] : [];
                    foreach ($scannedUnique as $code) {
                        $values[] = $code;
                    }
                    $merged = [];
                    $mergedSeen = [];
                    foreach ($values as $code) {
                        $text = trim((string)$code);
                        $key = strtoupper($text);
                        if ($key === '' || isset($mergedSeen[$key])) {
                            continue;
                        }
                        $mergedSeen[$key] = true;
                        $merged[] = $text;
                    }
                    $it['barcode_values'] = $merged;
                }
                unset($it);
                foreach ($flatItems as $it) {
                    $rid = (int)$it['receipt_id'];
                    $byRec[$rid][] = $it;
                }
                $lotItemIds = [];
                foreach ($flatItems as $it) {
                    $lotItemId = (int)($it['receipt_item_id'] ?? 0);
                    if ($lotItemId > 0) {
                        $lotItemIds[$lotItemId] = $lotItemId;
                    }
                }
                if ($lotItemIds) {
                    $lotIdList = implode(',', $lotItemIds);
                    $lotTable = $conn->query("SHOW TABLES LIKE 'purchase_receipt_item_lots'");
                    if ($lotTable && $lotTable->num_rows > 0) {
                        $lotRes = $conn->query("SELECT receipt_item_id, lot_number, lot_na, quantity, expiry_date, no_expiry FROM purchase_receipt_item_lots WHERE receipt_item_id IN ($lotIdList) ORDER BY id");
                        if ($lotRes) {
                            while ($lr = $lotRes->fetch_assoc()) {
                                $stockInLotMap[(int)$lr['receipt_item_id']][] = [
                                    'lot_number' => (string)($lr['lot_number'] ?? ''),
                                    'lot_na' => (int)($lr['lot_na'] ?? 0),
                                    'quantity' => (int)($lr['quantity'] ?? 0),
                                    'expiry_date' => $lr['expiry_date'] ?: null,
                                    'no_expiry' => (int)($lr['no_expiry'] ?? 0),
                                ];
                            }
                        }
                    }
                    if (recvHasColumn($conn, 'purchase_receipt_items', 'lot_entries')) {
                        $leRes = $conn->query("SELECT id, lot_entries FROM purchase_receipt_items WHERE id IN ($lotIdList)");
                        if ($leRes) {
                            while ($le = $leRes->fetch_assoc()) {
                                if (empty($le['lot_entries'])) {
                                    continue;
                                }
                                $decoded = json_decode((string)$le['lot_entries'], true);
                                if (is_array($decoded) && $decoded) {
                                    $stockInLotEntries[(int)$le['id']] = $decoded;
                                }
                            }
                        }
                    }
                }
            }
            $queueByItem = ($wf === '' || $wf === 'all');
            $visible = [];
            foreach ($rows as $r) {
                $wfShown = recvNormalizeWorkflowStatus($r['workflow_status'] ?? '');
                $items = $byRec[(int)$r['id']] ?? [];
                if ($queueByItem) {
                    $items = array_values(array_filter($items, static function ($it) {
                        return recvLineQueueStatus($it) !== '';
                    }));
                    if (!$items) {
                        continue;
                    }
                } elseif ($wfShown !== recvWfStockedIn()) {
                    $items = array_values(array_filter($items, static function ($it) {
                        return recvLineReadyForWarehouseStockIn($it);
                    }));
                    if (!$items) {
                        continue;
                    }
                    if (!recvIsReadyForWarehouseStockIn($wfShown)) {
                        $r['workflow_status'] = recvWfGranted();
                    }
                }
                $mapped = [];
                foreach ($items as $it) {
                    $mapped[] = [
                        'receipt_item_id' => (int)($it['receipt_item_id'] ?? 0),
                        'received_at' => $it['received_at'] ?? null,
                        'pricing_confirmed_at' => $it['pricing_confirmed_at'] ?? null,
                        'queue_status' => recvLineQueueStatus($it),
                        'line_stock_status' => (string)($it['line_stock_status'] ?? ''),
                        'stocked_in_qty' => (int)($it['stocked_in_qty'] ?? 0),
                        'product_id' => (int)($it['product_id'] ?? 0),
                        'product_name' => $it['product_name'],
                        'sku' => (string)($it['sku'] ?? ''),
                        'barcode' => (string)($it['barcode'] ?? ''),
                        'barcodes' => $it['barcodes'] ?? [],
                        'barcode_values' => $it['barcode_values'] ?? [],
                        'scanned_barcodes' => $it['scanned_barcodes'] ?? [],
                        'quantity_good' => (int)$it['quantity_good'],
                        'quantity_ordered' => (int)($it['quantity_ordered'] ?? 0),
                        'quantity_damaged' => (int)($it['quantity_damaged'] ?? 0),
                        'quantity_missing' => (int)($it['quantity_missing'] ?? 0),
                        'purchase_price' => round((float)$it['purchase_price'], 2),
                        'markup_percent' => round((float)$it['markup_percent'], 2),
                        'markup_amount' => ($it['markup_amount'] === null || $it['markup_amount'] === '')
                            ? null
                            : round((float)$it['markup_amount'], 2),
                        'tax_percent' => round((float)($it['tax_percent'] ?? 0), 2),
                        'tax_amount' => round((float)($it['tax_amount'] ?? 0), 2),
                        'apply_markup' => (int)($it['apply_markup'] ?? 0),
                        'apply_tax' => (int)($it['apply_tax'] ?? 0),
                        'selling_price' => round((float)$it['selling_price'], 2),
                        'reference_selling_price' => round((float)$it['selling_price'], 2),
                        'manufacturer_lot' => (string)($it['manufacturer_lot'] ?? ''),
                        'expiry_date' => $it['expiry_date'] ?? '',
                        'lots' => (static function (array $it) use ($stockInLotMap, $stockInLotEntries): array {
                            $itemId = (int)($it['receipt_item_id'] ?? 0);
                            if ($itemId > 0 && !empty($stockInLotMap[$itemId])) {
                                return $stockInLotMap[$itemId];
                            }
                            if ($itemId > 0 && !empty($stockInLotEntries[$itemId])) {
                                return $stockInLotEntries[$itemId];
                            }
                            $lotNo = trim((string)($it['manufacturer_lot'] ?? ''));
                            $qty = (int)($it['quantity_good'] ?? 0);
                            $exp = !empty($it['expiry_date']) ? (string)$it['expiry_date'] : null;
                            if ($qty <= 0 && $lotNo === '' && $exp === null) {
                                return [];
                            }
                            return [[
                                'lot_number' => $lotNo !== '' ? $lotNo : 'N/A',
                                'lot_na' => recvLotIsNotApplicable($lotNo) ? 1 : 0,
                                'quantity' => $qty,
                                'expiry_date' => $exp,
                                'no_expiry' => $exp ? 0 : 1,
                            ]];
                        })($it),
                    ];
                }
                $r['items'] = $mapped;
                $first = $mapped[0] ?? null;
                $r['purchase_price'] = $first['purchase_price'] ?? 0;
                $r['markup_percent'] = $first['markup_percent'] ?? 0;
                $r['selling_price'] = $first['selling_price'] ?? 0;
                $r['reference_selling_price'] = $first['reference_selling_price'] ?? $r['selling_price'];
                $r['total_good'] = 0;
                $r['total_damaged'] = 0;
                $r['total_missing'] = 0;
                foreach ($items as $it) {
                    $r['total_good'] += (int)($it['quantity_good'] ?? 0);
                    $r['total_damaged'] += (int)($it['quantity_damaged'] ?? 0);
                    $r['total_missing'] += (int)($it['quantity_missing'] ?? 0);
                }
                $visible[] = $r;
            }
            $rows = $visible;
        }
        jOut([
            'success' => true,
            'data' => $rows,
            'can_set_markup' => $canSetReceiptMarkup,
            'can_manage_pricing' => $canManageReceiptPricing,
            'can_view_pricing_queue' => $canViewPricingQueue,
            'can_grant_stock_in' => $canGrantStockIn,
            'can_execute_stock_in' => $canExecuteStockIn,
            'can_receive' => $isWarehouseHeadRole || $isAdminRole,
        ]);
        break;

    case 'get_pending_stock_in_items':
        ensureReceivingWorkflowSchema($conn);
        jOut([
            'success' => true,
            'data' => recvFetchPendingStockInItems($conn),
            'can_stock_in' => $canExecuteStockIn,
        ]);
        break;

    case 'confirm_stock_in_item':
        if (!$canExecuteStockIn) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head can complete Stock-In after Purchaser markup']);
        }
        ensureReceivingWorkflowSchema($conn);
        $itemId = (int)($_POST['receipt_item_id'] ?? $_POST['po_item_id'] ?? 0);
        $markup = (float)($_POST['markup_percent'] ?? 0);
        $sellPosted = isset($_POST['selling_price']) && $_POST['selling_price'] !== '' ? (float)$_POST['selling_price'] : null;
        $expiryRaw = trim((string)($_POST['expiry_date'] ?? ''));
        if ($itemId <= 0) jOut(['success' => false, 'message' => 'Received item is required']);
        if ($markup < 0) jOut(['success' => false, 'message' => 'Markup cannot be negative']);

        $conn->begin_transaction();
        try {
            $done = recvCompleteStockInItem($conn, $itemId, $uid, $markup, $expiryRaw === '' ? null : $expiryRaw);
            logProcurementAudit($conn, $uid, 'ADMIN_STOCK_MOVEMENT_STOCK_IN', 'purchase_receipt_items', $itemId, null, [
                'stocked_qty' => $done['stocked_qty'],
                'markup_percent' => $done['markup_percent'],
                'selling_price' => $done['selling_price'],
                'expiry_date' => $done['expiry_date'],
                'stock_in_number' => $done['stock_in_number'],
            ]);
            $conn->commit();
            createNotification(
                $conn,
                'Stocked In: ' . ($done['stock_in_number'] ?: 'item #' . $itemId),
                $done['stocked_qty'] . ' good unit(s) added to inventory from Admin Stock Movement.',
                'inventory_alert',
                'medium',
                (string)$done['stock_in_number'],
                'data-entry',
                'admin'
            );
            jOut([
                'success' => true,
                'message' => $done['stocked_qty'] . ' good unit(s) stocked in. Replacement was not added.',
                'stocked_qty' => $done['stocked_qty'],
                'stock_in_number' => $done['stock_in_number'],
                'selling_price' => $done['selling_price'],
                'expiry_date' => $done['expiry_date'],
                'receipt_item_id' => $done['receipt_item_id'],
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'set_receipt_markup':
        if (!$canSetReceiptMarkup) {
            jOut(['success' => false, 'message' => 'Only Purchaser can set markup after Goods Received']);
        }
        ensureReceivingWorkflowSchema($conn);
        $receiptId = (int)($_POST['receipt_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true) ?? [];
        $submitForApproval = !empty($_POST['submit_for_approval']);
        if (!$receiptId || empty($items)) jOut(['success' => false, 'message' => 'Receipt and markup items required']);

        $recRow = $conn->query("SELECT * FROM purchase_receipts WHERE id=$receiptId")->fetch_assoc();
        if (!$recRow) jOut(['success' => false, 'message' => 'Receipt not found']);
        $wf = recvNormalizeWorkflowStatus($recRow['workflow_status'] ?? '');
        if (!in_array($wf, [recvWfPendingMarkup(), recvWfPendingAdmin()], true)
            && !in_array($recRow['workflow_status'], ['Pending Stock-In', 'Ready for Stock-In'], true)) {
            jOut(['success' => false, 'message' => 'Markup can only be set before good qty is stocked in']);
        }
        if ($wf === recvWfPendingAdmin() && !$isAdminRole && !$isWarehouseHeadRole) {
            jOut(['success' => false, 'message' => 'Markup already submitted']);
        }

        $conn->begin_transaction();
        try {
            $rec = $conn->query("SELECT * FROM purchase_receipts WHERE id=$receiptId FOR UPDATE")->fetch_assoc();
            if (!$rec) throw new RuntimeException('Receipt not found');
            $updQty = $conn->prepare("UPDATE purchase_receipt_items SET quantity_good=?, quantity_received=?, quantity_missing=?, quantity_accepted=? WHERE id=? AND receipt_id=?");
            $upd = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, selling_price=?, cost_price=? WHERE id=? AND receipt_id=?");
            $qtyChanged = false;
            foreach ($items as $it) {
                $itemId = (int)($it['id'] ?? 0);
                if (!$itemId) continue;
                $line = $conn->query("SELECT quantity_good, quantity_damaged, quantity_ordered, quantity_received, unit_price, cost_price, po_item_id FROM purchase_receipt_items WHERE id=$itemId AND receipt_id=$receiptId")->fetch_assoc();
                if (!$line) continue;
                $damaged = (int)$line['quantity_damaged'];
                $ordered = (int)$line['quantity_ordered'];
                $oldGood = (int)$line['quantity_good'];
                $newGood = array_key_exists('quantity_good', $it) && $it['quantity_good'] !== ''
                    ? (int)$it['quantity_good']
                    : $oldGood;
                if ($newGood < 0) throw new RuntimeException('Good quantity cannot be negative');
                if ($newGood > $oldGood) {
                    throw new RuntimeException('Good quantity cannot be increased after inspection. Only scanned Goods can be Stocked In.');
                }
                $maxGood = max(0, $ordered - $damaged);
                if ($newGood > $maxGood) {
                    throw new RuntimeException('Good quantity cannot exceed ordered minus damaged (' . $maxGood . ')');
                }
                $oldRcv = (int)$line['quantity_received'];
                if ($oldRcv <= 0) $oldRcv = $oldGood + $damaged;
                $newRcv = $newGood + $damaged;
                $missing = max(0, $ordered - $newRcv);
                if ($newGood !== $oldGood) {
                    $updQty->bind_param('iiiiii', $newGood, $newRcv, $missing, $newGood, $itemId, $receiptId);
                    if (!$updQty->execute()) throw new RuntimeException($updQty->error ?: 'Failed to update good quantity');
                    $delta = $newRcv - $oldRcv;
                    $poItemId = (int)($line['po_item_id'] ?? 0);
                    if ($delta !== 0 && $poItemId > 0) {
                        if ($delta > 0) {
                            $poi = $conn->query("SELECT quantity_ordered, COALESCE(quantity_received,0) AS quantity_received FROM purchase_order_items WHERE id=$poItemId")->fetch_assoc();
                            if ($poi) {
                                $remain = (int)$poi['quantity_ordered'] - (int)$poi['quantity_received'];
                                if ($delta > $remain) throw new RuntimeException('Good quantity exceeds remaining PO quantity');
                            }
                        }
                        $adj = $conn->prepare("UPDATE purchase_order_items SET quantity_received = GREATEST(0, COALESCE(quantity_received,0) + ?) WHERE id=?");
                        $adj->bind_param('ii', $delta, $poItemId);
                        if (!$adj->execute()) throw new RuntimeException($adj->error ?: 'Failed to update PO received quantity');
                        $adj->close();
                    }
                    $qtyChanged = true;
                }
                if ($newGood <= 0) continue;
                $markup = (float)($it['markup_percent'] ?? 0);
                if ($markup < 0) throw new RuntimeException('Markup % cannot be negative');
                $cost = isset($it['cost_price']) && $it['cost_price'] !== ''
                    ? (float)$it['cost_price']
                    : (float)(($line['cost_price'] > 0) ? $line['cost_price'] : $line['unit_price']);
                if ($cost < 0) throw new RuntimeException('Purchase price cannot be negative');
                $sell = isset($it['selling_price']) && $it['selling_price'] !== ''
                    ? (float)$it['selling_price']
                    : round($cost * (1 + ($markup / 100)), 2);
                if ($sell <= 0) throw new RuntimeException('Selling price must be greater than zero for good quantities');
                $upd->bind_param('dddii', $markup, $sell, $cost, $itemId, $receiptId);
                $upd->execute();
            }

            $conn->query("UPDATE purchase_receipts SET markup_by=$uid, markup_at=NOW() WHERE id=$receiptId");
            if ($qtyChanged) {
                syncPoReceivingStatus($conn, (int)$rec['po_id']);
            }

            $conn->commit();

            $msg = 'Markup saved. Confirm Stock In on Admin Stock Movement to add good qty to inventory.';

            jOut([
                'success' => true,
                'message' => $msg,
                'workflow_status' => recvWfPendingMarkup(),
                'inventory_updated' => false,
                'stocked_qty' => 0,
                'submitted_for_approval' => false,
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_purchaser_markup_queue':
        if ($isWarehouseHeadRole) {
            jOut(['success' => false, 'message' => 'Warehouse Head can view confirmed pricing only during Stock-In']);
        }
        if (!$isPurchaserRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'You do not have access to the Pricing Queue']);
        }
        if ($isPurchaserRole && !$canViewPricingQueue) {
            jOut(['success' => false, 'message' => 'You do not have permission to open the Pricing Queue']);
        }
        ensureReceivingWorkflowSchema($conn);
        jOut([
            'success' => true,
            'data' => recvFetchPurchaserMarkupQueue($conn),
            'can_manage_pricing' => $canManageReceiptPricing,
            'can_view_pricing_queue' => $canViewPricingQueue,
        ]);
        break;

    case 'confirm_purchaser_markup':
        if (!$isPurchaserRole) {
            jOut(['success' => false, 'message' => 'Only Purchaser can confirm pricing after Goods Received']);
        }
        if (!$canManageReceiptPricing) {
            jOut(['success' => false, 'message' => 'You do not have permission to enter or confirm Markup']);
        }
        if (!$canViewPricingQueue) {
            jOut(['success' => false, 'message' => 'You do not have permission to open the Pricing Queue']);
        }
        ensureReceivingWorkflowSchema($conn);
        $itemId = (int)($_POST['receipt_item_id'] ?? 0);
        $pricing = [
            'apply_markup' => $_POST['apply_markup'] ?? '',
            'markup_percent' => $_POST['markup_percent'] ?? '',
            'apply_tax' => $_POST['apply_tax'] ?? '',
            'tax_percent' => $_POST['tax_percent'] ?? '',
            'selling_price' => $_POST['selling_price'] ?? $_POST['reference_selling_price'] ?? '',
        ];
        if (array_key_exists('markup_amount', $_POST)) {
            $pricing['markup_amount'] = $_POST['markup_amount'];
        }
        $conn->begin_transaction();
        try {
            $done = recvConfirmPurchaserMarkup($conn, $itemId, $uid, $pricing);
            if (empty($done['already_confirmed'])) {
                logProcurementAudit($conn, $uid, 'PURCHASER_CONFIRM_PRICING', 'purchase_receipt_items', $itemId, null, [
                    'apply_markup' => $done['apply_markup'],
                    'markup_percent' => $done['markup_percent'],
                    'markup_amount' => $done['markup_amount'] ?? null,
                    'apply_tax' => $done['apply_tax'],
                    'tax_percent' => $done['tax_percent'],
                    'tax_amount' => $done['tax_amount'],
                    'selling_price' => $done['selling_price'],
                    'reference_selling_price' => $done['reference_selling_price'],
                    'pricing_status' => $done['pricing_status'],
                    'inventory_updated' => false,
                ]);
                if (function_exists('logProcurementUiAudit')) {
                    $markupAudit = 'Not Applied';
                    if (!empty($done['apply_markup'])) {
                        $markupAudit = number_format((float)($done['markup_percent'] ?? 0), 2) . '%'
                            . ' (₱' . number_format((float)($done['markup_amount'] ?? 0), 2) . ')';
                    }
                    logProcurementUiAudit(
                        $conn,
                        $uid,
                        'PRICING_CONFIRMED',
                        'Confirmed pricing for receipt item #' . $itemId
                        . '. Markup: ' . $markupAudit
                        . '; Selling Price: ₱' . number_format((float)$done['selling_price'], 2)
                    );
                }
            }
            $conn->commit();
            if (function_exists('recvClearStaleMarkupQueueNotifications')) {
                recvClearStaleMarkupQueueNotifications($conn);
            }
            if (!empty($done['ready_for_stock_in']) && empty($done['already_confirmed'])) {
                createNotification(
                    $conn,
                    'Ready for Stock-In',
                    'Purchaser confirmed pricing. Warehouse Head can now stock in good quantity from the Stock-In Queue.',
                    'inventory_alert',
                    'medium',
                    (string)$done['receipt_id'],
                    'stock-in-queue',
                    'warehouse_head'
                );
            }
            jOut([
                'success' => true,
                'message' => !empty($done['already_confirmed'])
                    ? 'Pricing is already confirmed. Inventory was not increased.'
                    : (!empty($done['ready_for_stock_in'])
                        ? 'Pricing confirmed. Inventory was not increased. Item is ready for Warehouse Head Stock-In.'
                        : 'Pricing confirmed. Inventory was not increased.'),
                'data' => $done,
                'selling_price' => $done['selling_price'],
                'reference_selling_price' => $done['reference_selling_price'],
                'markup_percent' => $done['markup_percent'],
                'markup_amount' => $done['markup_amount'] ?? null,
                'tax_percent' => $done['tax_percent'],
                'already_confirmed' => !empty($done['already_confirmed']),
                'inventory_updated' => false,
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'mark_ready_for_stock_in':
    case 'submit_markup_for_admin_approval':
        if (!$canSetReceiptMarkup) {
            jOut(['success' => false, 'message' => 'Not allowed to submit markup for Admin approval']);
        }
        ensureReceivingWorkflowSchema($conn);
        $receiptId = (int)($_POST['receipt_id'] ?? 0);
        $rec = $conn->query("SELECT * FROM purchase_receipts WHERE id=$receiptId")->fetch_assoc();
        if (!$rec) jOut(['success' => false, 'message' => 'Receipt not found']);
        $wf = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');
        if ($wf !== recvWfPendingMarkup() && $rec['workflow_status'] !== 'Pending Stock-In') {
            jOut(['success' => false, 'message' => 'Only Received - Pending Markup receipts can be submitted for Admin approval']);
        }
        $bad = $conn->query("SELECT COUNT(*) c FROM purchase_receipt_items WHERE receipt_id=$receiptId AND quantity_good>0 AND (selling_price IS NULL OR selling_price<=0)");
        if ($bad && (int)$bad->fetch_assoc()['c'] > 0) {
            jOut(['success' => false, 'message' => 'Set markup/selling price for all good lines before submitting for Admin approval']);
        }
        $conn->begin_transaction();
        try {
            $readyStatus = recvWfPendingAdmin();
            $conn->query("UPDATE purchase_receipts SET workflow_status='" . $conn->real_escape_string($readyStatus) . "', markup_by=COALESCE(markup_by,$uid), markup_at=NOW() WHERE id=$receiptId");
            $conn->query("UPDATE purchase_receipt_items SET line_stock_status='" . $conn->real_escape_string($readyStatus) . "' WHERE receipt_id=$receiptId AND quantity_good>0 AND COALESCE(stocked_in_qty,0)=0");
            $conn->commit();
            jOut([
                'success' => true,
                'workflow_status' => $readyStatus,
                'inventory_updated' => false,
                'stocked_qty' => 0,
                'message' => 'Markup submitted for Admin approval. Inventory is not increased until Stock In is submitted.',
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'finalize_stock_in':
    case 'grant_stock_in':
        if (!$canGrantStockIn) {
            jOut(['success' => false, 'message' => 'Admin grant is no longer required. Warehouse Head can Stock In after Purchaser markup.']);
        }
        ensureReceivingWorkflowSchema($conn);
        $receiptId = (int)($_POST['receipt_id'] ?? 0);

        $conn->begin_transaction();
        try {
            $recStmt = $conn->prepare("SELECT rec.*, po.po_number, s.name AS supplier_name FROM purchase_receipts rec JOIN purchase_orders po ON po.id=rec.po_id LEFT JOIN suppliers s ON s.id=rec.supplier_id WHERE rec.id=? FOR UPDATE");
            if (!$recStmt) throw new RuntimeException($conn->error);
            $recStmt->bind_param('i', $receiptId);
            $recStmt->execute();
            $rec = $recStmt->get_result()->fetch_assoc();
            $recStmt->close();
            if (!$rec) throw new RuntimeException('Receipt not found');

            $wf = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');
            if ($wf === recvWfStockedIn() || !empty($rec['stock_in_number'])) {
                throw new RuntimeException('This receipt was already stocked in. Duplicate Stock-In is not allowed.');
            }

            recvBackfillReceiptLinePricing($conn, $receiptId);
            $goodCheck = $conn->query("SELECT COALESCE(SUM(GREATEST(0, quantity_good - COALESCE(stocked_in_qty,0))),0) AS pending_good
                                       FROM purchase_receipt_items WHERE receipt_id=$receiptId");
            $chkRow = $goodCheck ? $goodCheck->fetch_assoc() : ['pending_good' => 0];
            if ((int)($chkRow['pending_good'] ?? 0) <= 0) {
                throw new RuntimeException('No good quantity available to grant (replacement excluded).');
            }

            $allowedGrant = [
                recvWfPendingAdmin(),
                recvWfGranted(),
                'Ready for Stock-In',
                'Granted - Pending Stock-In',
            ];
            if (recvReceiptHasPendingPurchaserMarkup($conn, $receiptId)) {
                throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before Stock-In.');
            }
            if (!in_array($wf, $allowedGrant, true) && !in_array($rec['workflow_status'], $allowedGrant, true)) {
                throw new RuntimeException('Receipt is not ready for Admin Grant Stock-In');
            }

            $grantedStatus = recvWfGranted();
            if ($wf === recvWfGranted()) {
                $conn->commit();
                jOut([
                    'success' => true,
                    'workflow_status' => $grantedStatus,
                    'stocked_qty' => 0,
                    'inventory_updated' => false,
                    'message' => 'Already approved. Complete Stock In from the Stock Movement form. Inventory is not increased until Stock In is submitted.',
                ]);
            }

            $conn->query("UPDATE purchase_receipts SET workflow_status='" . $conn->real_escape_string($grantedStatus) . "', approved_by=$uid, approved_at=NOW() WHERE id=$receiptId");
            $conn->query("UPDATE purchase_receipt_items SET line_stock_status='" . $conn->real_escape_string($grantedStatus) . "' WHERE receipt_id=$receiptId AND quantity_good>0 AND COALESCE(stocked_in_qty,0)=0");
            logProcurementAudit($conn, $uid, 'ADMIN_GRANT_STOCK_IN', 'purchase_receipts', $receiptId, ['workflow_status' => $wf], [
                'workflow_status' => $grantedStatus,
                'stocked_qty' => 0,
                'inventory_updated' => false,
            ]);
            $conn->commit();
            createNotification(
                $conn,
                "Approved for Stock In: " . $rec['receipt_number'],
                "Admin approved received items for PO " . $rec['po_number'] . " (" . $rec['receipt_number'] . "). Complete Stock In from Stock Movement to add good qty to inventory.",
                "inventory_alert",
                "high",
                $rec['receipt_number'],
                "data-entry",
                "admin"
            );
            jOut([
                'success' => true,
                'workflow_status' => $grantedStatus,
                'stocked_qty' => 0,
                'inventory_updated' => false,
                'message' => 'Approved. The received item is now available on the Stock In form. Inventory will increase only after Stock In is submitted.',
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'execute_stock_in':
    case 'warehouse_stock_in':
        if (!$canExecuteStockIn) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head can complete Stock-In after Purchaser markup']);
        }
        ensureReceivingWorkflowSchema($conn);
        $receiptId = (int)($_POST['receipt_id'] ?? 0);

        $conn->begin_transaction();
        try {
            $recStmt = $conn->prepare("SELECT rec.*, po.po_number, s.name AS supplier_name FROM purchase_receipts rec JOIN purchase_orders po ON po.id=rec.po_id LEFT JOIN suppliers s ON s.id=rec.supplier_id WHERE rec.id=? FOR UPDATE");
            if (!$recStmt) throw new RuntimeException($conn->error);
            $recStmt->bind_param('i', $receiptId);
            $recStmt->execute();
            $rec = $recStmt->get_result()->fetch_assoc();
            $recStmt->close();
            if (!$rec) throw new RuntimeException('Receipt not found');

            $wf = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');
            if (($wf === recvWfStockedIn() || !empty($rec['stock_in_number'])) && recvReceiptUnstockedGoodQty($conn, $receiptId) <= 0) {
                throw new RuntimeException('This receipt was already stocked in. Duplicate Stock-In is not allowed.');
            }
            if ($wf === recvWfIncomplete()) {
                throw new RuntimeException('Unprocessed remaining quantity cannot be stocked in. Replacement does not block Good items.');
            }
            if (!recvReceiptHasReadyStockInLine($conn, $receiptId)) {
                throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before Stock-In.');
            }

            $done = recvCompleteStockIn($conn, $rec, $uid);
            logProcurementAudit($conn, $uid, 'WAREHOUSE_STOCK_IN', 'purchase_receipts', $receiptId, null, [
                'stocked_qty' => $done['stocked_qty'],
                'stock_in_number' => $done['stock_in_number'],
                'workflow_status' => $done['workflow_status'],
            ]);
            $conn->commit();
            createNotification($conn, "Stock-In Completed: " . $done['stock_in_number'], "Warehouse Head stocked in " . $done['stock_in_number'] . ". Good qty (" . $done['stocked_qty'] . ") added to available inventory for PO " . $rec['po_number'] . ". Replacement excluded.", "inventory_alert", "medium", $done['stock_in_number'], "inventory", "admin");
            jOut([
                'success' => true,
                'stocked_qty' => $done['stocked_qty'],
                'stock_in_number' => $done['stock_in_number'],
                'workflow_status' => $done['workflow_status'],
                'message' => "Stock-In " . $done['stock_in_number'] . " completed. " . $done['stocked_qty'] . " good unit(s) added to available inventory.",
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'execute_stock_in_batch':
        if (!$canExecuteStockIn) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head can complete Stock-In after Purchaser markup']);
        }
        ensureReceivingWorkflowSchema($conn);
        $idsRaw = $_POST['receipt_ids'] ?? '[]';
        $ids = is_array($idsRaw) ? $idsRaw : json_decode((string)$idsRaw, true);
        if (!is_array($ids) || !$ids) {
            jOut(['success' => false, 'message' => 'Select at least one ready receipt']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) jOut(['success' => false, 'message' => 'Select at least one ready receipt']);

        $ok = [];
        $fail = [];
        $totalQty = 0;
        foreach ($ids as $receiptId) {
            $conn->begin_transaction();
            try {
                $recStmt = $conn->prepare("SELECT rec.*, po.po_number, s.name AS supplier_name FROM purchase_receipts rec JOIN purchase_orders po ON po.id=rec.po_id LEFT JOIN suppliers s ON s.id=rec.supplier_id WHERE rec.id=? FOR UPDATE");
                if (!$recStmt) throw new RuntimeException($conn->error);
                $recStmt->bind_param('i', $receiptId);
                $recStmt->execute();
                $rec = $recStmt->get_result()->fetch_assoc();
                $recStmt->close();
                if (!$rec) throw new RuntimeException('Receipt not found');
                $wf = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');
                if (($wf === recvWfStockedIn() || !empty($rec['stock_in_number'])) && recvReceiptUnstockedGoodQty($conn, $receiptId) <= 0) {
                    throw new RuntimeException('Already stocked in');
                }
                if ($wf === recvWfIncomplete()) {
                    throw new RuntimeException('Unprocessed remaining quantity cannot be stocked in. Replacement does not block Good items.');
                }
                if (!recvReceiptHasReadyStockInLine($conn, $receiptId)) {
                    throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before Stock-In.');
                }
                $done = recvCompleteStockIn($conn, $rec, $uid);
                logProcurementAudit($conn, $uid, 'WAREHOUSE_STOCK_IN', 'purchase_receipts', $receiptId, null, [
                    'stocked_qty' => $done['stocked_qty'],
                    'stock_in_number' => $done['stock_in_number'],
                    'workflow_status' => $done['workflow_status'],
                ]);
                $conn->commit();
                $totalQty += (int)$done['stocked_qty'];
                $ok[] = $done['stock_in_number'] ?? ('#' . $receiptId);
            } catch (Throwable $e) {
                $conn->rollback();
                $fail[] = 'Receipt #' . $receiptId . ': ' . $e->getMessage();
            }
        }
        if (!$ok && $fail) {
            jOut(['success' => false, 'message' => implode('; ', $fail)]);
        }
        jOut([
            'success' => true,
            'stocked_qty' => $totalQty,
            'stocked_count' => count($ok),
            'failed' => $fail,
            'message' => count($ok) . ' receipt(s) stocked in (' . $totalQty . ' good unit(s)).' . ($fail ? ' Some failed: ' . implode('; ', $fail) : ''),
        ]);
        break;

    case 'get_damage_returns':
        ensureReceivingWorkflowSchema($conn);
        rtsEnsureSchema($conn);
        $status = trim($_GET['status'] ?? '');
        $where = 'WHERE 1=1';
        if ($status !== '' && $status !== 'all') {
            $where .= " AND dr.status='" . $conn->real_escape_string($status) . "'";
        }
        $sql = "SELECT dr.*, po.po_number, p.sku, p.name AS product_name, p.brand, p.barcode, p.unit_of_measure,
                       s.name AS supplier_name, s.contact_person AS supplier_contact, s.phone AS supplier_phone,
                       rec.receipt_number, rec.date_received, rec.dr_number, rec.supplier_si_number,
                       pri.expiry_date, pri.quantity_missing AS line_quantity_missing, pri.quantity_good,
                       COALESCE(pri.batch_number, '') AS batch_display,
                       u.full_name AS reported_by_name
                FROM supplier_damage_returns dr
                LEFT JOIN purchase_orders po ON po.id=dr.po_id
                LEFT JOIN products p ON p.id=dr.product_id
                LEFT JOIN suppliers s ON s.id=dr.supplier_id
                LEFT JOIN purchase_receipts rec ON rec.id=dr.receipt_id
                LEFT JOIN purchase_receipt_items pri ON pri.id=dr.receipt_item_id
                LEFT JOIN users u ON u.id=dr.reported_by
                $where
                ORDER BY dr.created_at DESC LIMIT 200";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r = rtsHydrateDamageRow($conn, $r);
                $rows[] = $r;
            }
        }
        jOut(['success' => true, 'data' => $rows]);
        break;

    case 'update_damage_return_status':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Not allowed']);
        }
        ensureReceivingWorkflowSchema($conn);
        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $allowed = ['Pending Replacement','Returned to Supplier','Awaiting Replacement','Replacement Received','Closed'];
        if (($status === 'Returned to Supplier' || $status === 'Awaiting Replacement') && $remarks === '') {
            jOut(['success' => false, 'message' => 'A return note is required (e.g. Damaged Item).']);
        }
        $row = $conn->query("SELECT * FROM supplier_damage_returns WHERE id=$id")->fetch_assoc();
        if (!$row) jOut(['success' => false, 'message' => 'Damage return not found']);

        $extra = '';
        if ($status === 'Returned to Supplier' || $status === 'Awaiting Replacement') {
            $extra = ", returned_at=COALESCE(returned_at,NOW()), quantity_returned=GREATEST(quantity_returned, quantity_damaged)";
        }
        if ($status === 'Replacement Received') {
            $extra = ", replacement_received_at=NOW()";
        }
        $remSql = $remarks !== '' ? ", remarks='" . $conn->real_escape_string($remarks) . "'" : '';
        $stEsc = $conn->real_escape_string($status);
        $conn->query("UPDATE supplier_damage_returns SET status='$stEsc' $extra $remSql WHERE id=$id");
        jOut(['success' => true, 'status' => $status, 'receipt_id' => (int)($row['receipt_id'] ?? 0)]);
        break;

    case 'record_damage_replacement':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Not allowed']);
        }
        ensureReceivingWorkflowSchema($conn);
        $id = (int)($_POST['id'] ?? 0);
        $qty = (int)($_POST['quantity_replaced'] ?? 0);
        $markup = (float)($_POST['markup_percent'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        if (!$id || $qty <= 0) jOut(['success' => false, 'message' => 'Replacement quantity required']);
        $row = $conn->query("SELECT * FROM supplier_damage_returns WHERE id=$id FOR UPDATE")->fetch_assoc();
        if (!$row) jOut(['success' => false, 'message' => 'Not found']);
        $remain = (int)$row['quantity_damaged'] - (int)$row['quantity_replaced'];
        if ($qty > $remain) jOut(['success' => false, 'message' => 'Replacement qty exceeds remaining damaged qty']);
        $cost = (float)$row['cost_price'];
        $sell = $markup > 0 ? round($cost * (1 + $markup / 100), 2) : (float)$row['selling_price'];
        $newRep = (int)$row['quantity_replaced'] + $qty;
        $st = $newRep >= (int)$row['quantity_damaged'] ? 'Replacement Received' : 'Awaiting Replacement';
        $remEsc = $conn->real_escape_string($remarks);
        $conn->query("UPDATE supplier_damage_returns SET quantity_replaced=$newRep, markup_percent=$markup, selling_price=$sell, status='$st', replacement_received_at=NOW(), remarks=CONCAT(COALESCE(remarks,''), IF('$remEsc'='','', CONCAT('\n', '$remEsc'))) WHERE id=$id");
        jOut(['success' => true, 'status' => $st, 'quantity_replaced' => $newRep]);
        break;

    case 'stock_in_damage_replacement':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Not allowed']);
        }
        ensureReceivingWorkflowSchema($conn);
        $id = (int)($_POST['id'] ?? 0);
        $row = $conn->query("SELECT * FROM supplier_damage_returns WHERE id=$id")->fetch_assoc();
        if (!$row) jOut(['success' => false, 'message' => 'Not found']);
        if (!in_array($row['status'], ['Replacement Received', 'Awaiting Replacement'], true)) {
            jOut(['success' => false, 'message' => 'Replacement must be received first']);
        }
        $pending = (int)$row['quantity_replaced'] - (int)$row['quantity_stocked'];
        if ($pending <= 0) jOut(['success' => false, 'message' => 'No replacement qty left to stock in']);
        if ((float)$row['selling_price'] <= 0) jOut(['success' => false, 'message' => 'Set markup/selling price before stock-in']);

        $conn->begin_transaction();
        try {
            $line = [
                'product_id' => (int)$row['product_id'],
                'quantity_good' => $pending,
                'stocked_in_qty' => 0,
                'cost_price' => (float)$row['cost_price'],
                'selling_price' => (float)$row['selling_price'],
                'batch_number' => '',
                'expiry_date' => null,
            ];
            $poNum = '';
            $poR = $conn->query("SELECT po_number FROM purchase_orders WHERE id=" . (int)$row['po_id']);
            if ($poR && ($p = $poR->fetch_assoc())) $poNum = $p['po_number'];
            $ref = 'REPL-' . $id . '-' . $poNum;
            $added = creditGoodStockFromReceiptLine($conn, $line, $ref, $uid, (int)$row['supplier_id'], date('Y-m-d'));
            $newStocked = (int)$row['quantity_stocked'] + $added;
            $st = $newStocked >= (int)$row['quantity_damaged'] ? 'Closed' : 'Replacement Received';
            $conn->query("UPDATE supplier_damage_returns SET quantity_stocked=$newStocked, status='$st', stocked_at=NOW() WHERE id=$id");
            $conn->commit();
            jOut(['success' => true, 'stocked_qty' => $added, 'status' => $st]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_supplier_rts':
        ensureReceivingWorkflowSchema($conn);
        rtsEnsureSchema($conn);
        jOut(['success' => true, 'data' => rtsList($conn)]);
        break;

    case 'get_rts_detail':
        ensureReceivingWorkflowSchema($conn);
        rtsEnsureSchema($conn);
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $num = trim((string)($_GET['rts_number'] ?? $_POST['rts_number'] ?? ''));
        $row = $id > 0 ? rtsLoad($conn, $id) : ($num !== '' ? rtsFindByNumber($conn, $num) : null);
        if (!$row) jOut(['success' => false, 'message' => 'RTS not found']);
        jOut(['success' => true, 'data' => $row]);
        break;

    case 'get_rts_returnable':
        ensureReceivingWorkflowSchema($conn);
        rtsEnsureSchema($conn);
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $row = rtsLoadDamageRow($conn, $id);
        if (!$row) jOut(['success' => false, 'message' => 'Damaged receiving record not found']);
        $row = rtsHydrateDamageRow($conn, $row);
        if (function_exists('productBarcodesListForProduct') || true) {
            require_once __DIR__ . '/product_barcodes_lib.php';
            productBarcodesEnsureSchema($conn);
            $values = [];
            if (!empty($row['product_id'])) {
                foreach (productBarcodesListForProduct($conn, (int)$row['product_id']) as $bc) {
                    if (strtolower((string)($bc['status'] ?? '')) !== 'active') continue;
                    $val = trim((string)($bc['barcode_value'] ?? ''));
                    if ($val !== '') $values[] = $val;
                }
            }
            $row['barcode_values'] = $values;
            if ($values && empty($row['barcode'])) $row['barcode'] = $values[0];
        }
        jOut(['success' => true, 'data' => $row]);
        break;

    case 'create_supplier_rts':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head or Admin can return items to the supplier.']);
        }
        ensureReceivingWorkflowSchema($conn);
        rtsEnsureSchema($conn);
        $damageId = (int)($_POST['damage_return_id'] ?? $_POST['id'] ?? 0);
        $qty = (int)($_POST['quantity_returned'] ?? $_POST['quantity'] ?? 0);
        $reason = trim((string)($_POST['return_reason'] ?? $_POST['reason'] ?? 'Damaged'));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $returnDate = trim((string)($_POST['return_date'] ?? ''));
        $conn->begin_transaction();
        try {
            $created = rtsCreate($conn, $damageId, $qty, $reason, $notes, $returnDate, $uid);
            logProcurementAudit($conn, $uid, 'RTS_CREATED', 'supplier_rts', (int)$created['id'], null, $created);
            $conn->commit();
            jOut(['success' => true, 'data' => $created, 'rts_number' => $created['rts_number'], 'message' => 'Return to Supplier recorded: ' . $created['rts_number']]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'request_rts_replacement':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head or Admin can request a replacement.']);
        }
        ensureReceivingWorkflowSchema($conn);
        rtsEnsureSchema($conn);
        $rtsId = (int)($_POST['rts_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? $_POST['quantity_requested'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $requestDate = trim((string)($_POST['request_date'] ?? ''));
        $conn->begin_transaction();
        try {
            $created = rtsRequestReplacement($conn, $rtsId, $qty, $notes, $requestDate, $uid);
            logProcurementAudit($conn, $uid, 'RTS_REPLACEMENT_REQUESTED', 'supplier_rts_replacements', (int)$created['id'], null, $created);
            $conn->commit();
            jOut(['success' => true, 'data' => $created, 'message' => 'Replacement requested for ' . $created['rts_number']]);
        } catch (Throwable $e) {
            $conn->rollback();
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_rts_replacement_inspect':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head or Admin can receive a replacement.']);
        }
        ensureReceivingWorkflowSchema($conn);
        rtsEnsureSchema($conn);
        $rtsId = (int)($_GET['rts_id'] ?? $_POST['rts_id'] ?? 0);
        try {
            jOut(['success' => true, 'data' => rtsReplacementInspectPayload($conn, $rtsId)]);
        } catch (Throwable $e) {
            jOut(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_damage_replacement_inspect':
        if (!$isWarehouseHeadRole && !$isAdminRole) {
            jOut(['success' => false, 'message' => 'Only Warehouse Head or Admin can receive a replacement.']);
        }
        ensureReceivingWorkflowSchema($conn);
        $replPoId = (int)($_GET['po_id'] ?? $_POST['po_id'] ?? 0);
        $replItemId = (int)($_GET['po_item_id'] ?? $_POST['po_item_id'] ?? 0);
        try {
            jOut(['success' => true, 'data' => recvDamageReplacementInspectPayload($conn, $replPoId, $replItemId)]);
        } catch (Throwable $e) {
            jOut(['success' => false, 'message' => recvPublicFailureMessage($e)]);
        }
        break;

    case 'log_rts_print':
        ensureReceivingWorkflowSchema($conn);
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $row = rtsLoad($conn, $id);
        if ($row) {
            logProcurementAudit($conn, $uid, 'RTS_RECEIPT_PRINTED', 'supplier_rts', $id, null, [
                'rts_number' => $row['rts_number'],
            ]);
        }
        jOut(['success' => true]);
        break;

    default:
        jOut(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}
$conn->close();
