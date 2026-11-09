<?php
require_once 'config.php';

function generateNumber(string $prefix): string {
    return $prefix . '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)),0,6);
}

function ensureLegacyProcurementSchema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS purchase_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pr_number VARCHAR(50) NOT NULL UNIQUE,
        requested_by INT NULL,
        supplier_id INT NULL,
        status ENUM('Draft', 'Pending Approval', 'Approved', 'Rejected') NOT NULL DEFAULT 'Draft',
        reason TEXT NULL,
        remarks TEXT NULL,
        admin_remarks TEXT NULL,
        total_estimated_amount DECIMAL(12,2) DEFAULT 0.00,
        approved_by INT NULL,
        approved_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS purchase_request_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pr_id INT NOT NULL,
        product_id INT NULL,
        product_name VARCHAR(200) NOT NULL,
        quantity_requested INT NOT NULL DEFAULT 1,
        estimated_unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity_requested * estimated_unit_price) STORED,
        remarks TEXT NULL,
        INDEX idx_pr (pr_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'pr_id' => 'INT NULL AFTER id',
        'requested_by' => 'INT NULL AFTER notes',
        'received_date' => 'DATE NULL AFTER expected_delivery_date',
        'actual_delivery_date' => 'DATE NULL AFTER received_date',
        'rejection_reason' => 'TEXT NULL AFTER notes',
        'approved_at' => 'TIMESTAMP NULL AFTER approved_by',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE purchase_orders ADD COLUMN `$column` $definition");
        }
    }
    $conn->query("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Draft','Submitted','Pending Approval','Approved','Sent','Supplier Confirmed','Waiting for Delivery','Partially Received','Received','Cancelled') NOT NULL DEFAULT 'Draft'");

    foreach ([
        'product_name' => 'VARCHAR(200) NULL AFTER product_id',
        'notes' => 'TEXT NULL',
        'quantity_received' => 'INT NOT NULL DEFAULT 0 AFTER quantity_ordered',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE purchase_order_items ADD COLUMN `$column` $definition");
        }
    }

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
        INDEX idx_receipt_number (receipt_number),
        INDEX idx_po (po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
        INDEX idx_receipt (receipt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
        INDEX idx_invoice_number (invoice_number),
        INDEX idx_po (po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function procurementNormalizePrStatus(string $status): string {
    $key = strtolower(str_replace([' ', '-'], '_', trim($status)));
    $map = [
        'pending' => 'Pending Approval',
        'pending_approval' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'draft' => 'Draft',
    ];
    return $map[$key] ?? $status;
}

function procurementNormalizePoStatus(string $status): string {
    $key = strtolower(str_replace([' ', '-'], '_', trim($status)));
    $map = [
        'pending' => 'Pending Approval',
        'pending_approval' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Cancelled',
        'cancelled' => 'Cancelled',
        'received' => 'Received',
        'partially_received' => 'Partially Received',
        'draft' => 'Draft',
    ];
    return $map[$key] ?? $status;
}

function createPurchaseRequest(int $requestedBy, array $items, ?string $reason = null) {
    $conn = getDBConnection();
    if (!$conn) return false;
    ensureLegacyProcurementSchema($conn);

    $prNumber = generateNumber('PR');

    $conn->begin_transaction();
    $status = 'Pending Approval';
    $stmt = $conn->prepare("INSERT INTO purchase_requests (pr_number, requested_by, reason, status) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('siss', $prNumber, $requestedBy, $reason, $status);
    if (!$stmt->execute()) {
        $conn->rollback();
        return false;
    }
    $prId = $conn->insert_id;
    $stmt->close();

    $itemStmt = $conn->prepare("INSERT INTO purchase_request_items (pr_id, product_id, product_name, quantity_requested, estimated_unit_price, remarks) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($items as $it) {
        $productId = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        $name = $it['name'] ?? '';
        $qty = (int)($it['quantity'] ?? 0);
        $price = (float)($it['unit_price'] ?? $it['estimated_unit_price'] ?? 0);
        $remarks = trim((string)($it['remarks'] ?? ($it['sku'] ?? '')));
        $itemStmt->bind_param('iisids', $prId, $productId, $name, $qty, $price, $remarks);
        if (!$itemStmt->execute()) {
            $conn->rollback();
            return false;
        }
    }
    $itemStmt->close();
    $conn->commit();

    // notify purchaser (self) that PR was created
    sendNotification($requestedBy, 'Purchase Request Created', "PR $prNumber has been created and is pending approval.");

    return ['id'=>$prId,'pr_number'=>$prNumber];
}

function sendNotification(?int $userId, string $title, string $message) {
    $conn = getDBConnection();
    if (!$conn) return false;
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        role VARCHAR(50) NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) NOT NULL,
        priority ENUM('low', 'medium', 'high') DEFAULT 'low',
        related_id VARCHAR(100) NULL,
        related_module VARCHAR(100) NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $type = 'order_status';
    $priority = 'medium';
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, priority) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('issss', $userId, $title, $message, $type, $priority);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

function getPurchaseRequests(array $filters = []) {
    $conn = getDBConnection();
    if (!$conn) return [];
    ensureLegacyProcurementSchema($conn);
    $where = [];
    $params = [];
    $types = '';
    if (!empty($filters['status'])) { $where[] = 'status = ?'; $params[] = procurementNormalizePrStatus($filters['status']); $types .= 's'; }
    if (!empty($filters['requested_by'])) { $where[] = 'requested_by = ?'; $params[] = (int)$filters['requested_by']; $types .= 'i'; }
    if (!empty($filters['date_from'])) { $where[] = 'DATE(created_at) >= ?'; $params[] = $filters['date_from']; $types .= 's'; }
    if (!empty($filters['date_to'])) { $where[] = 'DATE(created_at) <= ?'; $params[] = $filters['date_to']; $types .= 's'; }

    $sql = 'SELECT purchase_requests.*, created_at AS request_date FROM purchase_requests';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at DESC';

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function approvePurchaseRequest(int $prId, int $adminId, bool $approve, ?string $remarks = null) {
    $conn = getDBConnection();
    if (!$conn) return false;
    ensureLegacyProcurementSchema($conn);
    $status = $approve ? 'Approved' : 'Rejected';
    // Only a submitted PR awaiting approval can be decided, and approval needs complete items.
    $cur = $conn->query("SELECT status FROM purchase_requests WHERE id = " . (int)$prId . " LIMIT 1");
    $curRow = $cur ? $cur->fetch_assoc() : null;
    if (!$curRow || (string)$curRow['status'] !== 'Pending Approval') return false;
    if ($approve) {
        $chk = $conn->query("SELECT COUNT(*) AS c, SUM(CASE WHEN quantity_requested <= 0 THEN 1 ELSE 0 END) AS bad FROM purchase_request_items WHERE pr_id = " . (int)$prId);
        $chkRow = $chk ? $chk->fetch_assoc() : null;
        if (!$chkRow || (int)$chkRow['c'] === 0 || (int)$chkRow['bad'] > 0) return false;
    }
    $now = date('Y-m-d H:i:s');
    if ($approve) {
        $stmt = $conn->prepare("UPDATE purchase_requests SET status = ?, admin_remarks = ?, approved_by = ?, approved_at = ? WHERE id = ? AND status = 'Pending Approval'");
        $stmt->bind_param('ssisi', $status, $remarks, $adminId, $now, $prId);
    } else {
        $stmt = $conn->prepare("UPDATE purchase_requests SET status = ?, admin_remarks = ? WHERE id = ? AND status = 'Pending Approval'");
        $stmt->bind_param('ssi', $status, $remarks, $prId);
    }
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    if ($ok) {
        // fetch requester to notify
        $r = $conn->query("SELECT requested_by, pr_number FROM purchase_requests WHERE id = " . (int)$prId)->fetch_assoc();
        if ($r) {
            $msg = "Your Purchase Request " . $r['pr_number'] . " has been " . strtoupper($status) . ".";
            if ($remarks) $msg .= " Remarks: " . $remarks;
            sendNotification((int)$r['requested_by'], 'PR ' . strtoupper($status), $msg);
        }
    }
    return $ok;
}

function createPurchaseOrderFromPR(int $prId, int $supplierId, int $preparedBy, ?string $deliveryDate = null) {
    $conn = getDBConnection();
    if (!$conn) return false;
    ensureLegacyProcurementSchema($conn);
    // ensure PR is approved
    $pr = $conn->query("SELECT * FROM purchase_requests WHERE id = " . (int)$prId)->fetch_assoc();
    if (!$pr || $pr['status'] !== 'Approved') return false;
    if ($supplierId <= 0) return false;

    $poNumber = generateNumber('PO');
    $status = 'Submitted';
    $items = $conn->query("SELECT * FROM purchase_request_items WHERE pr_id = " . (int)$prId);
    if (!$items || $items->num_rows === 0) return false;
    $prItems = [];
    $total = 0.00;
    while ($it = $items->fetch_assoc()) {
        if (empty($it['product_id'])) return false;
        $total += ((int)$it['quantity_requested']) * ((float)$it['estimated_unit_price']);
        $prItems[] = $it;
    }

    $conn->begin_transaction();
    $orderDate = date('Y-m-d');
    $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, pr_id, supplier_id, order_date, status, total_amount, expected_delivery_date, requested_by, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('siissdsii', $poNumber, $prId, $supplierId, $orderDate, $status, $total, $deliveryDate, $preparedBy, $preparedBy);
    if (!$stmt->execute()) { $conn->rollback(); return false; }
    $poId = $conn->insert_id;
    $stmt->close();

    $itemStmt = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, product_name, quantity_ordered, unit_price, notes) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($prItems as $it) {
        $productId = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        $name = $it['product_name'];
        $qty = (int)$it['quantity_requested'];
        $price = (float)$it['estimated_unit_price'];
        $remarks = $it['remarks'];
        $itemStmt->bind_param('iisids', $poId, $productId, $name, $qty, $price, $remarks);
        if (!$itemStmt->execute()) { $conn->rollback(); return false; }
    }
    $itemStmt->close();
    $conn->commit();

    // notify purchaser
    sendNotification($preparedBy, 'PO Created', "PO $poNumber created from PR {$pr['pr_number']} and submitted to supplier.");

    return ['id'=>$poId,'po_number'=>$poNumber];
}

function approvePurchaseOrder(int $poId, int $adminId, bool $approve, ?string $remarks = null) {
    $conn = getDBConnection();
    if (!$conn) return false;
    ensureLegacyProcurementSchema($conn);
    $status = $approve ? 'Approved' : 'Cancelled';
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE purchase_orders SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = ? WHERE id = ?");
    $stmt->bind_param('ssisi', $status, $remarks, $adminId, $now, $poId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        // notify preparer
        $r = $conn->query("SELECT requested_by, po_number FROM purchase_orders WHERE id = " . (int)$poId)->fetch_assoc();
        if ($r) {
            $msg = "Your Purchase Order " . $r['po_number'] . " has been " . strtoupper($status) . ".";
            if ($remarks) $msg .= " Remarks: " . $remarks;
            sendNotification((int)$r['requested_by'], 'PO ' . strtoupper($status), $msg);
        }
    }
    return $ok;
}

function getPurchaseOrders(array $filters = []) {
    $conn = getDBConnection();
    if (!$conn) return [];
    ensureLegacyProcurementSchema($conn);
    $where = [];
    $params = [];
    $types = '';
    if (!empty($filters['status'])) { $where[] = 'status = ?'; $params[] = procurementNormalizePoStatus($filters['status']); $types .= 's'; }
    if (!empty($filters['prepared_by'])) { $where[] = 'requested_by = ?'; $params[] = (int)$filters['prepared_by']; $types .= 'i'; }
    if (!empty($filters['pr_id'])) { $where[] = 'pr_id = ?'; $params[] = (int)$filters['pr_id']; $types .= 'i'; }
    if (!empty($filters['po_id'])) { $where[] = 'id = ?'; $params[] = (int)$filters['po_id']; $types .= 'i'; }
    if (!empty($filters['date_from'])) { $where[] = 'DATE(created_at) >= ?'; $params[] = $filters['date_from']; $types .= 's'; }
    if (!empty($filters['date_to'])) { $where[] = 'DATE(created_at) <= ?'; $params[] = $filters['date_to']; $types .= 's'; }

    $sql = 'SELECT purchase_orders.*, created_at AS purchase_date FROM purchase_orders';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at DESC';

    $stmt = $conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function createReceipt(int $poId, int $receivedBy, array $items, ?string $deliveryDate = null, ?string $remarks = null) {
    $conn = getDBConnection();
    if (!$conn) return false;
    ensureLegacyProcurementSchema($conn);
    $po = $conn->query("SELECT * FROM purchase_orders WHERE id = " . (int)$poId)->fetch_assoc();
    if (!$po || !in_array($po['status'], ['Submitted', 'Approved', 'Sent', 'Supplier Confirmed', 'Waiting for Delivery', 'Partially Received'], true)) return false;

    require_once __DIR__ . '/procurement_receiving_lib.php';
    recvLockOriginalReceiptNumbers($conn);
    try {
        $receiptNumber = recvNextOriginalReceiptNumber($conn);
    $dateReceived = $deliveryDate ?: date('Y-m-d');
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO purchase_receipts (receipt_number, po_id, pr_id, supplier_id, date_received, received_by, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('siiisis', $receiptNumber, $poId, $po['pr_id'], $po['supplier_id'], $dateReceived, $receivedBy, $remarks);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: $conn->error);
        $receiptId = $conn->insert_id;
        $stmt->close();

        $receivedLines = 0;
        foreach ($items as $it) {
            $poItemId = !empty($it['po_item_id']) ? (int)$it['po_item_id'] : 0;
            $productId = !empty($it['product_id']) ? (int)$it['product_id'] : 0;
            $qty = (int)($it['quantity_received'] ?? 0);
            if ($qty <= 0) continue;

            if ($poItemId > 0) {
                $lineStmt = $conn->prepare("SELECT poi.*, COALESCE(NULLIF(poi.product_name, ''), p.name, '') AS line_name FROM purchase_order_items poi LEFT JOIN products p ON poi.product_id = p.id WHERE poi.id = ? AND poi.po_id = ? FOR UPDATE");
                if (!$lineStmt) throw new RuntimeException($conn->error);
                $lineStmt->bind_param('ii', $poItemId, $poId);
            } else {
                $lineStmt = $conn->prepare("SELECT poi.*, COALESCE(NULLIF(poi.product_name, ''), p.name, '') AS line_name FROM purchase_order_items poi LEFT JOIN products p ON poi.product_id = p.id WHERE poi.po_id = ? AND poi.product_id = ? FOR UPDATE");
                if (!$lineStmt) throw new RuntimeException($conn->error);
                $lineStmt->bind_param('ii', $poId, $productId);
            }
            $lineStmt->execute();
            $line = $lineStmt->get_result()->fetch_assoc();
            $lineStmt->close();
            if (!$line) throw new RuntimeException('PO item not found for receiving.');

            $poItemId = (int)$line['id'];
            $productId = (int)$line['product_id'];
            $name = (string)($line['line_name'] ?: ($it['name'] ?? ''));
            $ordered = (int)$line['quantity_ordered'];
            $alreadyReceived = (int)($line['quantity_received'] ?? 0);
            $remaining = max(0, $ordered - $alreadyReceived);
            if ($remaining <= 0 || $qty > $remaining) throw new RuntimeException('Received quantity exceeds remaining PO quantity.');

            $batch = $it['batch'] ?? ($it['batch_number'] ?? null);
            $expiry = $it['expiry'] ?? ($it['expiry_date'] ?? null);
            $unitPrice = (float)($line['unit_price'] ?? 0);

            $itemStmt = $conn->prepare("INSERT INTO purchase_receipt_items (receipt_id, po_item_id, product_id, product_name, quantity_ordered, quantity_received, batch_number, expiry_date, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$itemStmt) throw new RuntimeException($conn->error);
            $itemStmt->bind_param('iiisiissd', $receiptId, $poItemId, $productId, $name, $ordered, $qty, $batch, $expiry, $unitPrice);
            if (!$itemStmt->execute()) throw new RuntimeException($itemStmt->error ?: $conn->error);
            $itemStmt->close();

            $lineUpdate = $conn->prepare("UPDATE purchase_order_items SET quantity_received = COALESCE(quantity_received, 0) + ? WHERE id = ?");
            if (!$lineUpdate) throw new RuntimeException($conn->error);
            $lineUpdate->bind_param('ii', $qty, $poItemId);
            if (!$lineUpdate->execute()) throw new RuntimeException($lineUpdate->error ?: $conn->error);
            $lineUpdate->close();

            if ($productId > 0) {
                // Do NOT increase inventory here. Receiving must go through
                // Pending Markup → Admin Grant Stock-In (procurement_api workflow).
                // Legacy callers must not bypass Admin approval.
            }
            $receivedLines++;
        }
        if ($receivedLines === 0) throw new RuntimeException('No valid received quantities were entered.');

        $stats = $conn->query("SELECT SUM(quantity_ordered) AS total_ordered, SUM(COALESCE(quantity_received, 0)) AS total_received FROM purchase_order_items WHERE po_id = " . (int)$poId)->fetch_assoc();
        $newStatus = ((int)($stats['total_received'] ?? 0) >= (int)($stats['total_ordered'] ?? 0)) ? 'Received' : 'Partially Received';
        $receiptStatus = $newStatus === 'Received' ? 'Fully Received' : 'Partially Received';
        if (!$conn->query("UPDATE purchase_receipts SET status = '$receiptStatus', workflow_status='Received - Pending Markup' WHERE id = " . (int)$receiptId)) throw new RuntimeException($conn->error);
        if (!$conn->query("UPDATE purchase_orders SET status = '$newStatus', received_date = '$dateReceived', actual_delivery_date = '$dateReceived', receiving_status='Pending Markup' WHERE id = " . (int)$poId)) throw new RuntimeException($conn->error);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }

    sendNotification($receivedBy, 'Receipt Created', "Receipt $receiptNumber created. Status: Received - Pending Markup. Inventory not updated until Admin Grant Stock-In.");

    return ['id'=>$receiptId,'receipt_number'=>$receiptNumber];
    } finally {
        recvUnlockOriginalReceiptNumbers($conn);
    }
}

function createSupplierInvoice(int $poId, int $receiptId, int $supplierId, array $items, float $totalAmount, ?string $invoiceDate = null) {
    $conn = getDBConnection();
    if (!$conn) return false;
    ensureLegacyProcurementSchema($conn);
    $invoiceNumber = generateNumber('INV');
    $invoiceDate = $invoiceDate ?: date('Y-m-d');
    $paymentStatus = 'Unpaid';
    $notes = '';
    $conn->begin_transaction();
    $stmt = $conn->prepare("INSERT INTO supplier_invoices (invoice_number, po_id, receipt_id, supplier_id, invoice_date, subtotal, total_amount, payment_status, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)");
    $stmt->bind_param('siiisddss', $invoiceNumber, $poId, $receiptId, $supplierId, $invoiceDate, $totalAmount, $totalAmount, $paymentStatus, $notes);
    if (!$stmt->execute()) { $conn->rollback(); return false; }
    $invoiceId = $conn->insert_id;
    $stmt->close();
    $conn->commit();

    sendNotification(null, 'Invoice Created', "Invoice $invoiceNumber created for PO #$poId");
    return ['id'=>$invoiceId,'invoice_number'=>$invoiceNumber];
}

?>
