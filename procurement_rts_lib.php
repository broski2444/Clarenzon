<?php
/**
 * Return to Supplier (RTS) + replacement tracking.
 * Side process for physically received damaged items. Does not change
 * PR → PO → Incoming → Inspect → PREC → Markup → Stock-In → Sales.
 */

function rtsAddColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    $chk = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
    if ($chk && $chk->num_rows > 0) {
        return;
    }
    @$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

function rtsEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS supplier_rts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rts_number VARCHAR(50) NOT NULL,
        damage_return_id INT NOT NULL,
        po_id INT NOT NULL,
        receipt_id INT NULL,
        receipt_item_id INT NULL,
        product_id INT NULL,
        supplier_id INT NULL,
        quantity_returned INT NOT NULL DEFAULT 0,
        return_reason VARCHAR(80) NOT NULL DEFAULT 'Damaged',
        notes TEXT NULL,
        return_date DATE NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Returned to Supplier',
        processed_by INT NULL,
        processed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rts_number (rts_number),
        INDEX idx_rts_damage (damage_return_id),
        INDEX idx_rts_po (po_id),
        INDEX idx_rts_receipt (receipt_id),
        INDEX idx_rts_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS supplier_rts_replacements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rts_id INT NOT NULL,
        replacement_number VARCHAR(50) NOT NULL,
        quantity_requested INT NOT NULL DEFAULT 0,
        notes TEXT NULL,
        request_date DATE NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Requested',
        requested_by INT NULL,
        replacement_receipt_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rts_repl_number (replacement_number),
        INDEX idx_rts_repl_rts (rts_id),
        INDEX idx_rts_repl_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    rtsAddColumnIfMissing($conn, 'purchase_receipts', 'rts_id', 'INT NULL AFTER po_id');
    rtsAddColumnIfMissing($conn, 'supplier_damage_returns', 'quantity_missing', 'INT NOT NULL DEFAULT 0 AFTER quantity_damaged');
}

function rtsNextNumber(mysqli $conn, string $table, string $column, string $prefix): string {
    $year = date('Y');
    $like = $conn->real_escape_string($prefix . '-' . $year . '-%');
    $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $n = 0;
    $q = $conn->query("SELECT `$col` AS num FROM `$tbl` WHERE `$col` LIKE '$like' ORDER BY id DESC LIMIT 1");
    if ($q && ($row = $q->fetch_assoc())) {
        if (preg_match('/(\d+)$/', (string)$row['num'], $m)) {
            $n = (int)$m[1];
        }
    }
    for ($i = 1; $i <= 50; $i++) {
        $candidate = $prefix . '-' . $year . '-' . str_pad((string)($n + $i), 4, '0', STR_PAD_LEFT);
        $esc = $conn->real_escape_string($candidate);
        $exists = $conn->query("SELECT id FROM `$tbl` WHERE `$col`='$esc' LIMIT 1");
        if (!$exists || $exists->num_rows === 0) {
            return $candidate;
        }
    }
    return $prefix . '-' . $year . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function rtsReturnedSum(mysqli $conn, int $damageReturnId): int {
    if ($damageReturnId <= 0) {
        return 0;
    }
    $q = $conn->query("SELECT COALESCE(SUM(quantity_returned),0) AS t FROM supplier_rts WHERE damage_return_id=" . $damageReturnId);
    return $q ? (int)$q->fetch_assoc()['t'] : 0;
}

function rtsEligibleDamagedQty(mysqli $conn, array $damageRow): int {
    $damaged = (int)($damageRow['quantity_damaged'] ?? 0);
    $already = rtsReturnedSum($conn, (int)($damageRow['id'] ?? 0));
    if ($already === 0 && !empty($damageRow['returned_at'])) {
        $legacy = (int)($damageRow['quantity_returned'] ?? 0);
        if ($legacy > 0) {
            $already = $legacy;
        }
    }
    return max(0, $damaged - $already);
}

function rtsAllowedReasons(): array {
    return ['Damaged', 'Defective', 'Wrong Item', 'Other'];
}

function rtsNormalizeReason(string $reason): string {
    $reason = trim($reason);
    foreach (rtsAllowedReasons() as $allowed) {
        if (strcasecmp($reason, $allowed) === 0) {
            return $allowed;
        }
    }
    return 'Other';
}

function rtsLoadDamageRow(mysqli $conn, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $res = $conn->query("SELECT dr.*,
            po.po_number, po.pr_id,
            rec.receipt_number, rec.date_received, rec.dr_number, rec.supplier_si_number,
            pri.batch_number, pri.expiry_date,
            pri.quantity_good, pri.quantity_missing AS line_quantity_missing, pri.quantity_received AS line_qty_received,
            p.sku, p.name AS product_name, p.barcode, p.unit_of_measure,
            s.name AS supplier_name, s.contact_person AS supplier_contact, s.phone AS supplier_phone,
            u.full_name AS reported_by_name
        FROM supplier_damage_returns dr
        LEFT JOIN purchase_orders po ON po.id = dr.po_id
        LEFT JOIN purchase_receipts rec ON rec.id = dr.receipt_id
        LEFT JOIN purchase_receipt_items pri ON pri.id = dr.receipt_item_id
        LEFT JOIN products p ON p.id = dr.product_id
        LEFT JOIN suppliers s ON s.id = dr.supplier_id
        LEFT JOIN users u ON u.id = dr.reported_by
        WHERE dr.id = $id
        LIMIT 1");
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    return $row ?: null;
}

function rtsHydrateDamageRow(mysqli $conn, array $row): array {
    $row['quantity_returned_rts'] = rtsReturnedSum($conn, (int)$row['id']);
    $row['eligible_return_qty'] = rtsEligibleDamagedQty($conn, $row);
    $row['quantity_missing'] = (int)($row['quantity_missing'] ?? $row['line_quantity_missing'] ?? 0);
    $batch = trim((string)($row['batch_display'] ?? ''));
    if ($batch === '') {
        $batch = trim((string)($row['batch_number'] ?? ''));
    }
    $lot = trim((string)($row['manufacturer_lot'] ?? ''));
    $row['batch_display'] = $batch !== '' ? $batch : ($lot !== '' ? $lot : '');
    return $row;
}

function rtsCreate(mysqli $conn, int $damageReturnId, int $qty, string $reason, string $notes, string $returnDate, int $uid): array {
    rtsEnsureSchema($conn);
    if ($qty <= 0) {
        throw new RuntimeException('Return quantity must be greater than 0.');
    }
    $row = rtsLoadDamageRow($conn, $damageReturnId);
    if (!$row) {
        throw new RuntimeException('Damaged receiving record not found.');
    }
    $damaged = (int)$row['quantity_damaged'];
    if ($damaged <= 0) {
        throw new RuntimeException('Only physically received damaged items can be returned. Missing quantity cannot generate an RTS.');
    }
    $eligible = rtsEligibleDamagedQty($conn, $row);
    if ($qty > $eligible) {
        throw new RuntimeException('Return quantity cannot exceed remaining damaged quantity (' . $eligible . ').');
    }
    if ($returnDate === '') {
        $returnDate = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        throw new RuntimeException('Invalid return date.');
    }
    $reason = rtsNormalizeReason($reason);
    $rtsNumber = rtsNextNumber($conn, 'supplier_rts', 'rts_number', 'RTS');
    $stmt = $conn->prepare("INSERT INTO supplier_rts
        (rts_number, damage_return_id, po_id, receipt_id, receipt_item_id, product_id, supplier_id,
         quantity_returned, return_reason, notes, return_date, status, processed_by, processed_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'Returned to Supplier',?,NOW())");
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'Could not create RTS.');
    }
    $poId = (int)$row['po_id'];
    $receiptId = (int)($row['receipt_id'] ?? 0);
    $receiptItemId = (int)($row['receipt_item_id'] ?? 0);
    $productId = (int)($row['product_id'] ?? 0);
    $supplierId = (int)($row['supplier_id'] ?? 0);
    $stmt->bind_param(
        'siiiiiiisssi',
        $rtsNumber,
        $damageReturnId,
        $poId,
        $receiptId,
        $receiptItemId,
        $productId,
        $supplierId,
        $qty,
        $reason,
        $notes,
        $returnDate,
        $uid
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException($err ?: 'Could not save RTS.');
    }
    $rtsId = (int)$conn->insert_id;
    $stmt->close();

    $returnedTotal = rtsReturnedSum($conn, $damageReturnId);
    $st = $returnedTotal >= $damaged ? 'Returned to Supplier' : 'Pending Replacement';
    $noteEsc = $conn->real_escape_string(trim($notes) !== '' ? $notes : $reason);
    $stEsc = $conn->real_escape_string($st);
    $conn->query("UPDATE supplier_damage_returns
        SET quantity_returned=$returnedTotal,
            status='$stEsc',
            returned_at=COALESCE(returned_at, NOW()),
            remarks=CONCAT(COALESCE(remarks,''), IF(COALESCE(remarks,'')='','','\n'), 'RTS $rtsNumber: $noteEsc')
        WHERE id=$damageReturnId");

    return [
        'id' => $rtsId,
        'rts_number' => $rtsNumber,
        'status' => 'Returned to Supplier',
        'quantity_returned' => $qty,
        'eligible_remaining' => max(0, $damaged - $returnedTotal),
    ];
}

function rtsLoad(mysqli $conn, int $rtsId): ?array {
    rtsEnsureSchema($conn);
    if ($rtsId <= 0) {
        return null;
    }
    $res = $conn->query("SELECT r.*,
            po.po_number, po.pr_id,
            rec.receipt_number, rec.date_received, rec.dr_number, rec.supplier_si_number,
            pri.quantity_good, pri.quantity_damaged AS line_damaged, pri.quantity_missing AS line_missing,
            pri.quantity_received AS line_received, pri.expiry_date,
            COALESCE(NULLIF(pri.batch_number,''), '') AS batch_display,
            p.sku, p.name AS product_name, p.barcode, p.unit_of_measure,
            s.name AS supplier_name, s.contact_person AS supplier_contact, s.phone AS supplier_phone,
            u.full_name AS processed_by_name,
            dr.quantity_damaged, dr.quantity_missing, dr.quantity_ordered, dr.quantity_received
        FROM supplier_rts r
        LEFT JOIN supplier_damage_returns dr ON dr.id = r.damage_return_id
        LEFT JOIN purchase_orders po ON po.id = r.po_id
        LEFT JOIN purchase_receipts rec ON rec.id = r.receipt_id
        LEFT JOIN purchase_receipt_items pri ON pri.id = r.receipt_item_id
        LEFT JOIN products p ON p.id = r.product_id
        LEFT JOIN suppliers s ON s.id = r.supplier_id
        LEFT JOIN users u ON u.id = r.processed_by
        WHERE r.id = $rtsId
        LIMIT 1");
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    if (!$row) {
        return null;
    }
    $row['replacements'] = rtsListReplacements($conn, $rtsId);
    return $row;
}

function rtsFindByNumber(mysqli $conn, string $number): ?array {
    $esc = $conn->real_escape_string(trim($number));
    if ($esc === '') {
        return null;
    }
    $q = $conn->query("SELECT id FROM supplier_rts WHERE rts_number='$esc' LIMIT 1");
    if (!$q || !($row = $q->fetch_assoc())) {
        return null;
    }
    return rtsLoad($conn, (int)$row['id']);
}

function rtsList(mysqli $conn, int $limit = 200): array {
    rtsEnsureSchema($conn);
    $limit = max(1, min(500, $limit));
    $sql = "SELECT r.*,
            po.po_number, rec.receipt_number,
            p.sku, p.name AS product_name, p.barcode, p.unit_of_measure,
            s.name AS supplier_name,
            u.full_name AS processed_by_name,
            dr.quantity_damaged,
            (SELECT rr.status FROM supplier_rts_replacements rr WHERE rr.rts_id=r.id ORDER BY rr.id DESC LIMIT 1) AS replacement_status,
            (SELECT rr.quantity_requested FROM supplier_rts_replacements rr WHERE rr.rts_id=r.id ORDER BY rr.id DESC LIMIT 1) AS replacement_qty,
            (SELECT rec2.receipt_number FROM supplier_rts_replacements rr
                LEFT JOIN purchase_receipts rec2 ON rec2.id=rr.replacement_receipt_id
                WHERE rr.rts_id=r.id ORDER BY rr.id DESC LIMIT 1) AS replacement_receipt_number
        FROM supplier_rts r
        LEFT JOIN supplier_damage_returns dr ON dr.id = r.damage_return_id
        LEFT JOIN purchase_orders po ON po.id = r.po_id
        LEFT JOIN purchase_receipts rec ON rec.id = r.receipt_id
        LEFT JOIN products p ON p.id = r.product_id
        LEFT JOIN suppliers s ON s.id = r.supplier_id
        LEFT JOIN users u ON u.id = r.processed_by
        ORDER BY r.id DESC
        LIMIT $limit";
    $res = $conn->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function rtsListReplacements(mysqli $conn, int $rtsId): array {
    $rows = [];
    $res = $conn->query("SELECT rr.*, rec.receipt_number AS replacement_receipt_number, rec.workflow_status AS replacement_workflow,
            u.full_name AS requested_by_name
        FROM supplier_rts_replacements rr
        LEFT JOIN purchase_receipts rec ON rec.id = rr.replacement_receipt_id
        LEFT JOIN users u ON u.id = rr.requested_by
        WHERE rr.rts_id=$rtsId
        ORDER BY rr.id ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function rtsOpenReplacement(mysqli $conn, int $rtsId): ?array {
    $res = $conn->query("SELECT * FROM supplier_rts_replacements
        WHERE rts_id=$rtsId AND status IN ('Pending','Requested','Approved')
        ORDER BY id DESC LIMIT 1");
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    return $row ?: null;
}

function rtsRequestedReplacementQty(mysqli $conn, int $rtsId): int {
    $q = $conn->query("SELECT COALESCE(SUM(quantity_requested),0) AS t FROM supplier_rts_replacements WHERE rts_id=$rtsId AND status <> 'Rejected'");
    return $q ? (int)$q->fetch_assoc()['t'] : 0;
}

function rtsRequestReplacement(mysqli $conn, int $rtsId, int $qty, string $notes, string $requestDate, int $uid): array {
    rtsEnsureSchema($conn);
    $rts = rtsLoad($conn, $rtsId);
    if (!$rts) {
        throw new RuntimeException('RTS not found.');
    }
    if ($qty <= 0) {
        throw new RuntimeException('Replacement quantity must be greater than 0.');
    }
    $returned = (int)$rts['quantity_returned'];
    $already = rtsRequestedReplacementQty($conn, $rtsId);
    $max = max(0, $returned - $already);
    if ($qty > $max) {
        throw new RuntimeException('Replacement quantity cannot exceed remaining replaceable quantity (' . $max . ').');
    }
    if (rtsOpenReplacement($conn, $rtsId)) {
        throw new RuntimeException('A replacement request already exists for this RTS.');
    }
    if ($requestDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDate)) {
        $requestDate = date('Y-m-d');
    }
    $num = rtsNextNumber($conn, 'supplier_rts_replacements', 'replacement_number', 'RPL');
    $stmt = $conn->prepare("INSERT INTO supplier_rts_replacements
        (rts_id, replacement_number, quantity_requested, notes, request_date, status, requested_by)
        VALUES (?,?,?,?,?,'Requested',?)");
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'Could not request replacement.');
    }
    $stmt->bind_param('isissi', $rtsId, $num, $qty, $notes, $requestDate, $uid);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException($err ?: 'Could not save replacement request.');
    }
    $id = (int)$conn->insert_id;
    $stmt->close();

    $conn->query("UPDATE supplier_rts SET status='Replacement Requested' WHERE id=$rtsId");
    $damageId = (int)$rts['damage_return_id'];
    if ($damageId > 0) {
        $conn->query("UPDATE supplier_damage_returns SET status='Awaiting Replacement' WHERE id=$damageId");
    }
    return [
        'id' => $id,
        'replacement_number' => $num,
        'rts_number' => $rts['rts_number'],
        'quantity_requested' => $qty,
        'status' => 'Requested',
    ];
}

function rtsReplacementOpenQty(mysqli $conn, int $rtsId): int {
    $open = rtsOpenReplacement($conn, $rtsId);
    if (!$open) {
        return 0;
    }
    return max(0, (int)$open['quantity_requested']);
}

function rtsAttachReplacementReceipt(mysqli $conn, int $rtsId, int $receiptId): void {
    $open = rtsOpenReplacement($conn, $rtsId);
    if (!$open) {
        throw new RuntimeException('No open replacement request for this RTS.');
    }
    $rid = (int)$open['id'];
    $conn->query("UPDATE supplier_rts_replacements SET replacement_receipt_id=$receiptId, status='Replacement Received' WHERE id=$rid");
    $conn->query("UPDATE supplier_rts SET status='Replacement Received' WHERE id=$rtsId");
    $rts = $conn->query("SELECT damage_return_id, quantity_returned FROM supplier_rts WHERE id=$rtsId")->fetch_assoc();
    if ($rts) {
        $did = (int)$rts['damage_return_id'];
        $qty = (int)$open['quantity_requested'];
        $conn->query("UPDATE supplier_damage_returns
            SET quantity_replaced = quantity_replaced + $qty,
                status='Replacement Received',
                replacement_received_at=NOW()
            WHERE id=$did");
    }
}

function rtsMarkCompletedIfStocked(mysqli $conn, int $receiptId): void {
    if ($receiptId <= 0) {
        return;
    }
    rtsEnsureSchema($conn);
    $chk = $conn->query("SHOW COLUMNS FROM purchase_receipts LIKE 'rts_id'");
    if (!($chk && $chk->num_rows > 0)) {
        return;
    }
    $rec = $conn->query("SELECT id, rts_id, workflow_status, stock_in_number FROM purchase_receipts WHERE id=$receiptId LIMIT 1");
    if (!$rec || !($row = $rec->fetch_assoc())) {
        return;
    }
    $rtsId = (int)($row['rts_id'] ?? 0);
    if ($rtsId <= 0) {
        return;
    }
    $wf = (string)($row['workflow_status'] ?? '');
    $stocked = ($wf === 'Stocked In' || trim((string)($row['stock_in_number'] ?? '')) !== '');
    if (!$stocked) {
        return;
    }
    $conn->query("UPDATE supplier_rts SET status='Completed' WHERE id=$rtsId");
    $rts = $conn->query("SELECT damage_return_id FROM supplier_rts WHERE id=$rtsId")->fetch_assoc();
    if ($rts) {
        $did = (int)$rts['damage_return_id'];
        $conn->query("UPDATE supplier_damage_returns SET status='Closed', stocked_at=COALESCE(stocked_at, NOW()) WHERE id=$did");
    }
    $conn->query("UPDATE supplier_rts_replacements SET status='Replacement Received' WHERE rts_id=$rtsId AND replacement_receipt_id=$receiptId");
}

function rtsReplacementInspectPayload(mysqli $conn, int $rtsId): array {
    $rts = rtsLoad($conn, $rtsId);
    if (!$rts) {
        throw new RuntimeException('RTS not found.');
    }
    $open = rtsOpenReplacement($conn, $rtsId);
    if (!$open) {
        throw new RuntimeException('Request a replacement before receiving it.');
    }
    if (!empty($open['replacement_receipt_id'])) {
        throw new RuntimeException('This replacement was already received for inspection.');
    }
    $qty = (int)$open['quantity_requested'];
    $poItemId = 0;
    $cost = 0.0;
    $dr = $conn->query("SELECT po_item_id, cost_price FROM supplier_damage_returns WHERE id=" . (int)$rts['damage_return_id']);
    if ($dr && ($d = $dr->fetch_assoc())) {
        $poItemId = (int)$d['po_item_id'];
        $cost = (float)$d['cost_price'];
    }
    require_once __DIR__ . '/product_barcodes_lib.php';
    productBarcodesEnsureSchema($conn);
    $barcodeValues = [];
    if (!empty($rts['product_id'])) {
        $list = productBarcodesListForProduct($conn, (int)$rts['product_id']);
        foreach ($list as $bc) {
            if (strtolower((string)($bc['status'] ?? '')) !== 'active') {
                continue;
            }
            $val = trim((string)($bc['barcode_value'] ?? ''));
            if ($val !== '') {
                $barcodeValues[] = $val;
            }
        }
    }
    $et = 0;
    if (!empty($rts['product_id'])) {
        $p = $conn->query("SELECT COALESCE(expiry_tracking,0) et FROM products WHERE id=" . (int)$rts['product_id']);
        if ($p && ($pr = $p->fetch_assoc())) {
            $et = (int)$pr['et'];
        }
    }
    return [
        'rts_id' => $rtsId,
        'rts_number' => $rts['rts_number'],
        'po_id' => (int)$rts['po_id'],
        'po_number' => $rts['po_number'],
        'supplier_name' => $rts['supplier_name'],
        'replacement_number' => $open['replacement_number'],
        'items' => [[
            'id' => $poItemId,
            'product_id' => $rts['product_id'],
            'product_name' => $rts['product_name'],
            'sku' => $rts['sku'],
            'barcode' => $rts['barcode'],
            'barcode_values' => $barcodeValues,
            'unit_of_measure' => $rts['unit_of_measure'],
            'quantity_ordered' => $qty,
            'quantity_received' => 0,
            'unit_price' => $cost,
            'purchase_price' => $cost,
            'expiry_tracking' => $et,
        ]],
    ];
}
