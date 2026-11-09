<?php
/**
 * Incremental receiving for the warehouse mobile app.
 * Updates existing purchase_receipts / purchase_receipt_items using
 * Clarenzon's current inspection + stock-in workflow. Does not add
 * sellable inventory until Stock-In runs.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'procurement_receiving_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_barcodes_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'po_lifecycle_lib.php';

function mobile_idempotency_ensure(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS scanner_idempotency (
        request_id VARCHAR(80) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL DEFAULT 0,
        route VARCHAR(40) NOT NULL DEFAULT '',
        response_json MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_scanner_idem_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mobile_idempotency_get(mysqli $conn, string $requestId, int $userId): ?array
{
    $requestId = trim($requestId);
    if ($requestId === '' || strlen($requestId) > 80) {
        return null;
    }
    mobile_idempotency_ensure($conn);
    $stmt = $conn->prepare('SELECT response_json FROM scanner_idempotency WHERE request_id=? AND user_id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('si', $requestId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['response_json'])) {
        return null;
    }
    $decoded = json_decode((string)$row['response_json'], true);
    return is_array($decoded) ? $decoded : null;
}

function mobile_log_scan(
    mysqli $conn,
    int $userId,
    string $code,
    string $actionPerformed,
    ?int $productId = null,
    string $notes = '',
    string $scanStatus = 'existing_product',
    string $deviceUsed = 'mobile'
): void {
    $conn->query("CREATE TABLE IF NOT EXISTS scan_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        device_used VARCHAR(50) NOT NULL,
        scanned_code VARCHAR(255) NOT NULL,
        action_performed VARCHAR(50) NOT NULL,
        product_id INT NULL,
        scan_status VARCHAR(50) NULL DEFAULT 'product_not_found',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pid = ($productId !== null && (int)$productId > 0) ? (int)$productId : 0;
    $chk = $conn->query("SHOW COLUMNS FROM scan_history LIKE 'scan_status'");
    $hasStatus = $chk && $chk->num_rows > 0;
    if ($hasStatus) {
        $stmt = $conn->prepare(
            'INSERT INTO scan_history (user_id, device_used, scanned_code, action_performed, product_id, scan_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssiss', $userId, $deviceUsed, $code, $actionPerformed, $pid, $scanStatus, $notes);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO scan_history (user_id, device_used, scanned_code, action_performed, product_id, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssis', $userId, $deviceUsed, $code, $actionPerformed, $pid, $notes);
    }
    $stmt->execute();
    $stmt->close();
}

function mobile_idempotency_put(mysqli $conn, string $requestId, int $userId, int $productId, string $route, array $payload): void
{
    $requestId = trim($requestId);
    if ($requestId === '' || strlen($requestId) > 80) {
        return;
    }
    mobile_idempotency_ensure($conn);
    $json = json_encode($payload);
    $stmt = $conn->prepare('INSERT IGNORE INTO scanner_idempotency (request_id, user_id, product_id, route, response_json) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('siiss', $requestId, $userId, $productId, $route, $json);
    $stmt->execute();
    $stmt->close();
}

function mobile_is_catalog_barcode(mysqli $conn, string $code, int $productId): bool
{
    if ($productId <= 0 || trim($code) === '') {
        return false;
    }
    $found = productBarcodesFind($conn, $code);
    if ($found && (int)($found['product_id'] ?? 0) === $productId) {
        return true;
    }
    $key = strtoupper(trim($code));
    $stmt = $conn->prepare('SELECT id FROM products WHERE id=? AND (UPPER(TRIM(barcode))=? OR UPPER(TRIM(sku))=?) LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iss', $productId, $key, $key);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

function mobile_po_line_state(array $inspectItem, array $poi): array
{
    $ordered = max(0, (int)($inspectItem['quantity_ordered'] ?? $poi['quantity_ordered'] ?? 0));
    $good = max(0, (int)($inspectItem['quantity_good'] ?? 0));
    $dm = max(0, (int)($inspectItem['quantity_damage_missing'] ?? 0));
    $processed = $ordered > 0 ? min($ordered, $good + $dm) : ($good + $dm);
    $remaining = max(0, $ordered - $processed);
    return [
        'ordered' => $ordered,
        'good' => $good,
        'damage_missing' => $dm,
        'processed' => $processed,
        'remaining' => $remaining,
        'unit' => (string)($poi['unit_of_measure'] ?? $poi['unit'] ?? 'pcs'),
    ];
}

function mobile_map_delivery_status(array $row): string
{
    $wf = recvNormalizeWorkflowStatus((string)($row['receipt_workflow_status'] ?? $row['workflow_status'] ?? ''));
    $delivery = function_exists('poDeliveryDisplayStatus') ? poDeliveryDisplayStatus($row) : '';
    $remaining = (int)($row['qty_remaining'] ?? $row['remaining_boxes'] ?? 0);
    $processed = (int)($row['qty_processed'] ?? $row['qty_good'] ?? 0);

    if ($wf === recvWfStockedIn() || $delivery === 'Completed' || strcasecmp((string)($row['receiving_status'] ?? ''), 'Stocked In') === 0) {
        return 'Completed';
    }
    if (in_array($wf, [recvWfGranted(), recvWfPendingAdmin()], true)) {
        return 'Ready for Stock-In';
    }
    if ($wf === recvWfPendingMarkup()) {
        return $remaining > 0 ? 'Pending Inspection' : 'Pending Stock-In';
    }
    if ($wf === recvWfIncomplete() || $remaining > 0) {
        return $processed > 0 ? 'Pending Inspection' : 'Incoming';
    }
    if ($processed > 0) {
        return 'Received';
    }
    return 'Incoming';
}

function mobile_find_or_create_receipt(mysqli $conn, array $po, int $uid, string $dateReceived, string $remarks, string $driverName): array
{
    $poId = (int)$po['id'];
    $open = recvFindIncompleteReceipt($conn, $poId);
    if ($open) {
        $rid = (int)$open['id'];
        $lock = $conn->query('SELECT * FROM purchase_receipts WHERE id=' . $rid . ' FOR UPDATE');
        $row = $lock ? $lock->fetch_assoc() : $open;
        if ($row) {
            $upd = $conn->prepare('UPDATE purchase_receipts SET date_received=?, remarks=IF(?=\'\', remarks, ?), driver_name=IF(?=\'\', driver_name, ?) WHERE id=?');
            if ($upd) {
                $upd->bind_param('sssssi', $dateReceived, $remarks, $remarks, $driverName, $driverName, $rid);
                $upd->execute();
                $upd->close();
            }
            $row['date_received'] = $dateReceived;
            if ($remarks !== '') {
                $row['remarks'] = $remarks;
            }
            if ($driverName !== '') {
                $row['driver_name'] = $driverName;
            }
            return $row;
        }
    }

    $dup = $conn->query('SELECT id FROM purchase_receipts WHERE po_id=' . $poId . ' LIMIT 1');
    if ($dup && $dup->num_rows > 0 && !recvFindIncompleteReceipt($conn, $poId)) {
        throw new RuntimeException('This Purchase Order has already been received.');
    }

    recvLockOriginalReceiptNumbers($conn);
    try {
        $recNum = recvNextOriginalReceiptNumber($conn);
    $supplierId = (int)$po['supplier_id'];
    $prId = !empty($po['pr_id']) ? (int)$po['pr_id'] : null;
    if ($prId) {
        $prOk = $conn->prepare('SELECT id FROM purchase_requests WHERE id=? LIMIT 1');
        if ($prOk) {
            $prOk->bind_param('i', $prId);
            $prOk->execute();
            if (!$prOk->get_result()->fetch_assoc()) {
                $prId = null;
            }
            $prOk->close();
        }
    }
    $workflow = recvWfPendingMarkup();
    $stmt = $conn->prepare('INSERT INTO purchase_receipts (receipt_number, po_id, pr_id, supplier_id, date_received, received_at, received_by, remarks, workflow_status, driver_name) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'Unable to create receiving record.');
    }
    $stmt->bind_param('siiisisss', $recNum, $poId, $prId, $supplierId, $dateReceived, $uid, $remarks, $workflow, $driverName);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new RuntimeException($err ?: 'Unable to create receiving record.');
    }
    $rid = (int)$conn->insert_id;
    $stmt->close();
    } finally {
        recvUnlockOriginalReceiptNumbers($conn);
    }
    $row = $conn->query('SELECT * FROM purchase_receipts WHERE id=' . $rid)->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('Receiving record could not be loaded.');
    }
    return $row;
}

function mobile_load_receipt_item(mysqli $conn, int $receiptId, int $poItemId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM purchase_receipt_items WHERE receipt_id=? AND po_item_id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $receiptId, $poItemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mobile_insert_receipt_item(
    mysqli $conn,
    int $receiptId,
    int $poItemId,
    int $productId,
    string $productName,
    int $qtyOrd,
    int $qtyGood,
    int $qtyMissing,
    string $batchNum,
    ?string $expDate,
    array $priced,
    string $lineStatus,
    string $remarks
): int {
    $expDate = ($expDate !== null && $expDate !== '') ? (string)$expDate : null;
    $qtyRcv = min($qtyOrd, $qtyGood + $qtyMissing);
    $cost = (float)$priced['cost_price'];
    $markup = (float)$priced['markup_percent'];
    $sell = (float)$priced['selling_price'];
    $qtyDamaged = 0;
    $qtyAccepted = $qtyGood;
    $qtyReturn = 0;
    $hasMissing = recvHasColumn($conn, 'purchase_receipt_items', 'quantity_missing');
    if ($hasMissing) {
        $riStmt = $conn->prepare('INSERT INTO purchase_receipt_items (receipt_id, po_item_id, product_id, product_name, quantity_ordered, quantity_received, quantity_good, quantity_damaged, quantity_missing, quantity_accepted, quantity_return, batch_number, expiry_date, unit_price, cost_price, markup_percent, selling_price, line_stock_status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$riStmt) {
            throw new RuntimeException($conn->error);
        }
        $riStmt->bind_param(
            'iiisiiiiiiissddddss',
            $receiptId,
            $poItemId,
            $productId,
            $productName,
            $qtyOrd,
            $qtyRcv,
            $qtyGood,
            $qtyDamaged,
            $qtyMissing,
            $qtyAccepted,
            $qtyReturn,
            $batchNum,
            $expDate,
            $cost,
            $cost,
            $markup,
            $sell,
            $lineStatus,
            $remarks
        );
    } else {
        $riStmt = $conn->prepare('INSERT INTO purchase_receipt_items (receipt_id, po_item_id, product_id, product_name, quantity_ordered, quantity_received, quantity_good, quantity_damaged, quantity_accepted, quantity_return, batch_number, expiry_date, unit_price, cost_price, markup_percent, selling_price, line_stock_status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$riStmt) {
            throw new RuntimeException($conn->error);
        }
        $riStmt->bind_param(
            'iiisiiiiiissddddss',
            $receiptId,
            $poItemId,
            $productId,
            $productName,
            $qtyOrd,
            $qtyRcv,
            $qtyGood,
            $qtyDamaged,
            $qtyAccepted,
            $qtyReturn,
            $batchNum,
            $expDate,
            $cost,
            $cost,
            $markup,
            $sell,
            $lineStatus,
            $remarks
        );
    }
    if (!$riStmt->execute()) {
        $err = $riStmt->error ?: $conn->error;
        $riStmt->close();
        throw new RuntimeException($err ?: 'Unable to save received item.');
    }
    $id = (int)$conn->insert_id;
    $riStmt->close();
    if ($id > 0 && recvHasColumn($conn, 'purchase_receipt_items', 'received_at')) {
        $conn->query("UPDATE purchase_receipt_items SET received_at=NOW() WHERE id=$id AND received_at IS NULL");
    }
    return $id;
}

function mobile_match_po_item(mysqli $conn, int $poId, int $productId, int $poItemId, array $inspect): ?array
{
    $itemsStmt = $conn->prepare('SELECT poi.*, p.sku, p.barcode, p.brand, COALESCE(p.unit_of_measure, "pcs") AS unit_of_measure
        FROM purchase_order_items poi
        LEFT JOIN products p ON p.id = poi.product_id
        WHERE poi.po_id=?
        ORDER BY poi.id');
    if (!$itemsStmt) {
        return null;
    }
    $itemsStmt->bind_param('i', $poId);
    $itemsStmt->execute();
    $res = $itemsStmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $itemsStmt->close();

    $pick = static function (array $row) use ($inspect): array {
        $st = $inspect['items'][(int)$row['id']] ?? [];
        return $row + ['inspect' => $st, 'state' => mobile_po_line_state($st, $row)];
    };

    if ($poItemId > 0) {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $poItemId) {
                return $pick($row);
            }
        }
        return null;
    }
    if ($productId > 0) {
        $candidates = [];
        foreach ($rows as $row) {
            if ((int)$row['product_id'] === $productId) {
                $wrapped = $pick($row);
                if ($wrapped['state']['remaining'] > 0) {
                    $candidates[] = $wrapped;
                }
            }
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (count($candidates) > 1) {
            return $candidates[0];
        }
    }
    return null;
}

/**
 * @return array<string,mixed>
 */
function mobile_receive_scan_unit(mysqli $conn, array $input, array $user): array
{
    $uid = (int)$user['id'];
    $poId = (int)($input['po_id'] ?? 0);
    $poItemId = (int)($input['po_item_id'] ?? 0);
    $barcode = trim((string)($input['barcode'] ?? ''));
    $asDamage = !empty($input['as_damage']) || strtolower((string)($input['status'] ?? 'good')) === 'damage';
    $method = recvInspectionMethodLabel((string)($input['input_method'] ?? 'Camera'));
    $lot = trim((string)($input['lot_number'] ?? $input['batch_number'] ?? ''));
    $mfg = trim((string)($input['mfg_date'] ?? $input['manufacturing_date'] ?? ''));
    $exp = trim((string)($input['expiry_date'] ?? ''));
    $remarks = trim((string)($input['remarks'] ?? ''));
    $driver = trim((string)($input['delivered_by'] ?? $input['driver_name'] ?? ''));
    $dateReceived = trim((string)($input['date_received'] ?? '')) ?: date('Y-m-d');
    $noExpiry = !empty($input['no_expiry']) || $exp === '';

    if ($poId <= 0) {
        throw new RuntimeException('Purchase order is required.');
    }
    if ($barcode === '') {
        throw new RuntimeException('Enter or scan a barcode.');
    }

    ensureReceivingWorkflowSchema($conn);
    productBarcodesEnsureSchema($conn);
    poLifecycleEnsureSchema($conn);

    $poQ = $conn->prepare('SELECT po.*, s.name AS supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id WHERE po.id=? FOR UPDATE');
    if (!$poQ) {
        throw new RuntimeException($conn->error);
    }
    $poQ->bind_param('i', $poId);
    $poQ->execute();
    $po = $poQ->get_result()->fetch_assoc();
    $poQ->close();
    if (!$po) {
        throw new RuntimeException('Purchase Order not found.');
    }
    if (in_array((string)$po['status'], ['Cancelled', 'Draft'], true)) {
        throw new RuntimeException('This Purchase Order cannot be received.');
    }

    $inspect = recvPoInspectTotals($conn, $poId);
    $look = recvLookupUniqueItemBarcode($conn, $barcode, 0);
    $lookStatus = (string)($look['status'] ?? '');
    $linkedProductId = (int)($look['product_id'] ?? 0);

    $matched = mobile_match_po_item($conn, $poId, $linkedProductId, $poItemId, $inspect);
    if (!$matched && $lookStatus === 'not_found' && $poItemId <= 0) {
        throw new RuntimeException('Barcode not found. Search for the product manually or enter the barcode.');
    }
    if (!$matched) {
        throw new RuntimeException('Product information could not be found.');
    }

    $productId = recvResolveCatalogProductId(
        $conn,
        (int)($matched['product_id'] ?? 0),
        (string)$matched['product_name'],
        $matched['sku'] ?? null,
        $matched['barcode'] ?? null
    );
    if ($productId <= 0) {
        throw new RuntimeException('Product information could not be found.');
    }
    if ($linkedProductId > 0 && $linkedProductId !== $productId && $lookStatus === 'conflict') {
        throw new RuntimeException('This barcode belongs to another product.');
    }
    if ($linkedProductId > 0 && $linkedProductId !== $productId) {
        throw new RuntimeException('This barcode belongs to another product (' . (string)($look['product_name'] ?? '') . ').');
    }

    $state = $matched['state'];
    if ($state['remaining'] <= 0) {
        throw new RuntimeException('Cannot receive more than the ordered quantity.');
    }

    $inspHit = recvFindInspectionBarcode($conn, $barcode);
    $isCatalog = mobile_is_catalog_barcode($conn, $barcode, $productId)
        || (function_exists('recvBarcodeIsCatalogIdentity') && recvBarcodeIsCatalogIdentity($conn, $barcode, $productId))
        || $lookStatus === 'catalog';
    if ($inspHit && !$isCatalog) {
        throw new RuntimeException('This barcode was already recorded.');
    }

    $receipt = mobile_find_or_create_receipt($conn, $po, $uid, $dateReceived, $remarks, $driver);
    $receiptId = (int)$receipt['id'];
    $existing = mobile_load_receipt_item($conn, $receiptId, (int)$matched['id']);

    $qtyGood = $existing ? (int)$existing['quantity_good'] : 0;
    $qtyMissing = $existing ? recvLineDamageMissingQty($existing) : 0;
    if ($asDamage) {
        $qtyMissing++;
    } else {
        $qtyGood++;
    }
    $qtyOrd = $state['ordered'];
    if (($qtyGood + $qtyMissing) > $qtyOrd) {
        throw new RuntimeException('Cannot receive more than the ordered quantity.');
    }

    $batchNum = $existing ? trim((string)($existing['system_batch_number'] ?? $existing['batch_number'] ?? '')) : '';
    if ($batchNum === '' && $qtyGood > 0) {
        $batchNum = generateSystemBatchNumber($conn, $dateReceived);
    }
    $expDate = '';
    if (!$noExpiry && $exp !== '') {
        $expDate = (string)recvValidateExpiryDate($exp, $dateReceived, false);
    }
    $expBind = $expDate !== '' ? $expDate : null;
    $priced = recvResolveLinePricing($conn, $productId, (float)($matched['unit_price'] ?? 0));
    $lineStatus = $qtyGood > 0 ? recvWfPendingMarkup() : recvWfIncomplete();
    $itemRemarks = $remarks;
    if ($mfg !== '') {
        $itemRemarks = trim($itemRemarks . ' | Mfg: ' . $mfg);
    }

    if ($existing) {
        $receiptItemId = (int)$existing['id'];
        $qtyRcv = min($qtyOrd, $qtyGood + $qtyMissing);
        $upd = $conn->prepare('UPDATE purchase_receipt_items SET quantity_ordered=?, quantity_received=?, quantity_good=?, quantity_damaged=0, quantity_missing=?, quantity_accepted=?, expiry_date=?, line_stock_status=?, remarks=? WHERE id=? AND receipt_id=?');
        if (!$upd) {
            throw new RuntimeException($conn->error);
        }
        $upd->bind_param(
            'iiiiisssii',
            $qtyOrd,
            $qtyRcv,
            $qtyGood,
            $qtyMissing,
            $qtyGood,
            $expBind,
            $lineStatus,
            $itemRemarks,
            $receiptItemId,
            $receiptId
        );
        if (!$upd->execute()) {
            throw new RuntimeException($upd->error ?: 'Unable to update receiving line.');
        }
        $upd->close();
    } else {
        $receiptItemId = mobile_insert_receipt_item(
            $conn,
            $receiptId,
            (int)$matched['id'],
            $productId,
            (string)$matched['product_name'],
            $qtyOrd,
            $qtyGood,
            $qtyMissing,
            $batchNum,
            $expBind,
            $priced,
            $lineStatus,
            $itemRemarks
        );
    }

    if ($batchNum !== '') {
        $sysEsc = $conn->real_escape_string($batchNum);
        $lotEsc = $conn->real_escape_string($lot !== '' ? $lot : 'N/A');
        @$conn->query("UPDATE purchase_receipt_items SET manufacturer_lot='$lotEsc', system_batch_number='$sysEsc', batch_number='$sysEsc' WHERE id=" . (int)$receiptItemId);
    }

    $inspStatus = $asDamage ? 'Damaged/Rejected' : 'Good';
    recvInsertInspectionBarcode($conn, $receiptId, $receiptItemId, $poId, (int)$matched['id'], $productId, [
        'barcode' => $barcode,
        'status' => $inspStatus,
        'input_method' => $method,
        'lot_number' => $lot,
        'expiry_date' => $expDate ?: '',
    ]);

    if (!$asDamage) {
        $reg = productBarcodesRegister($conn, $productId, $barcode, [
            'source' => 'received',
            'purchase_order_id' => $poId,
            'receiving_transaction_id' => $receiptId,
            'supplier_id' => (int)$po['supplier_id'],
            'registered_by' => $uid,
        ]);
        if (empty($reg['ok']) && ($reg['status'] ?? '') === 'conflict') {
            throw new RuntimeException((string)($reg['message'] ?? 'This barcode belongs to another product.'));
        }
        if (function_exists('productBarcodesStampReceiving')) {
            productBarcodesStampReceiving($conn, $productId, [$barcode], $receiptId, $poId);
        }
        if (function_exists('productBarcodesSaveReceiptLine')) {
            $goodCodes = recvSellableInspectionBarcodes(recvLoadInspectionBarcodes($conn, $receiptItemId));
            productBarcodesSaveReceiptLine($conn, $receiptItemId, $goodCodes);
        }
    }

    $qtyRcv = min($qtyOrd, $qtyGood + $qtyMissing);
    $updPoi = $conn->prepare('UPDATE purchase_order_items SET quantity_received=? WHERE id=?');
    if ($updPoi) {
        $updPoi->bind_param('ii', $qtyRcv, $matched['id']);
        $updPoi->execute();
        $updPoi->close();
    }

    recvUpsertDamageMissingRecord(
        $conn,
        $poId,
        (int)$matched['id'],
        $receiptId,
        $receiptItemId,
        $productId,
        (int)$po['supplier_id'],
        $qtyOrd,
        $qtyRcv,
        0,
        $qtyMissing,
        (float)$priced['cost_price'],
        $remarks !== '' ? $remarks : 'Replacement on delivery — not sellable',
        $uid
    );

    $lots = [[
        'lot_number' => $lot !== '' ? $lot : 'N/A',
        'lot_na' => $lot === '' ? 1 : 0,
        'quantity' => max(1, $qtyGood),
        'expiry_date' => $expDate ?: '',
        'no_expiry' => $noExpiry || $expDate === null ? 1 : 0,
    ]];
    if ($qtyGood > 0) {
        try {
            recvSaveReceiptItemLots($conn, $receiptItemId, $receiptId, $poId, (int)$matched['id'], $productId, $lots);
        } catch (Throwable $e) {
            // Lot extras are optional during live scanning; quantities already saved.
        }
    }

    $wf = recvWfPendingMarkup();
    $remainAfter = max(0, $qtyOrd - $qtyGood - $qtyMissing);
    if ($remainAfter > 0 && $qtyGood <= 0) {
        $wf = recvWfIncomplete();
    }
    $wfEsc = $conn->real_escape_string($wf);
    $conn->query("UPDATE purchase_receipts SET workflow_status='$wfEsc' WHERE id=" . (int)$receiptId);
    if ($qtyGood > 0 && recvHasColumn($conn, 'purchase_receipt_items', 'pricing_status')) {
        $waitPs = $conn->real_escape_string(recvWfWaitingForPricing());
        $conn->query("UPDATE purchase_receipt_items SET pricing_status='$waitPs' WHERE id=" . (int)$receiptItemId . " AND COALESCE(pricing_status,'') <> 'Confirmed'");
    }

    $newPoStatus = $remainAfter > 0 ? 'Partially Received' : 'Received';
    $recvLabel = $remainAfter > 0 ? 'Partially Received' : 'Pending Markup';
    $st = $conn->prepare('UPDATE purchase_orders SET status=?, receiving_status=? WHERE id=? AND status NOT IN (\'Draft\',\'Cancelled\',\'Pending Approval\')');
    if ($st) {
        $st->bind_param('ssi', $newPoStatus, $recvLabel, $poId);
        $st->execute();
        $st->close();
    }
    syncPoReceivingStatus($conn, $poId);

    $inspectAfter = recvPoInspectTotals($conn, $poId);
    $lineAfter = $inspectAfter['items'][(int)$matched['id']] ?? [
        'quantity_ordered' => $qtyOrd,
        'quantity_good' => $qtyGood,
        'quantity_damage_missing' => $qtyMissing,
        'quantity_remaining' => $remainAfter,
    ];

    return [
        'success' => true,
        'message' => $asDamage ? 'Replacement recorded.' : 'Item scanned successfully.',
        'receipt_id' => $receiptId,
        'receipt_number' => (string)($receipt['receipt_number'] ?? ''),
        'receipt_item_id' => $receiptItemId,
        'po_id' => $poId,
        'po_item_id' => (int)$matched['id'],
        'product_id' => $productId,
        'product_name' => (string)$matched['product_name'],
        'sku' => (string)($matched['sku'] ?? ''),
        'barcode' => $barcode,
        'status' => $asDamage ? 'damage_missing' : 'good',
        'quantity_ordered' => (int)$lineAfter['quantity_ordered'],
        'quantity_good' => (int)$lineAfter['quantity_good'],
        'quantity_damage_missing' => (int)($lineAfter['quantity_damage_missing'] ?? $qtyMissing),
        'quantity_remaining' => (int)$lineAfter['quantity_remaining'],
        'batch_number' => $batchNum,
        'lot_number' => $lot,
        'expiry_date' => $expDate,
        'unit' => (string)($matched['unit_of_measure'] ?? 'pcs'),
        'workflow_status' => $wf,
        'inventory_updated' => false,
        'po_totals' => [
            'ordered' => (int)$inspectAfter['ordered'],
            'good' => (int)$inspectAfter['good'],
            'damage_missing' => (int)$inspectAfter['damage_missing'],
            'remaining' => (int)$inspectAfter['remaining'],
        ],
    ];
}
