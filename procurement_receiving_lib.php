<?php
/**
 * Receiving workflow helpers:
 * WH Receive → Goods Received → Purchaser Markup → WH Stock-In → Available Inventory
 */

/** Workflow status constants (purchase_receipts.workflow_status / line_stock_status) */
function recvWfPendingMarkup(): string { return 'Received - Pending Markup'; }
function recvWfPendingAdmin(): string { return 'Markup Ready - Pending Admin Approval'; }
function recvWfGranted(): string { return 'Approved - Pending Stock-In'; }
function recvWfStockedIn(): string { return 'Stocked In'; }
function recvWfIncomplete(): string { return 'Pending - Incomplete Delivery'; }

/** Normalize legacy status labels to current workflow names */
function recvNormalizeWorkflowStatus(?string $status): string {
    $s = trim((string)$status);
    $map = [
        'Pending Stock-In' => recvWfPendingMarkup(),
        'Pending for Stock-In' => recvWfPendingMarkup(),
        'Received - Pending Markup' => recvWfPendingMarkup(),
        'Ready for Stock-In' => recvWfPendingAdmin(),
        'Markup Ready - Pending Admin Approval' => recvWfPendingAdmin(),
        'Approved - Pending Stock-In' => recvWfGranted(),
        'Granted - Pending Stock-In' => recvWfGranted(),
        'Stocked In' => recvWfStockedIn(),
        'Pending – Incomplete Delivery' => recvWfIncomplete(),
        'Pending - Incomplete Delivery' => recvWfIncomplete(),
    ];
    return $map[$s] ?? $s;
}

/** Combined Damaged/Missing = max(0, Ordered − Good). Legacy damaged+missing kept as fallback. */
function recvCombinedShortage(array $item): int {
    $ordered = (int)($item['quantity_ordered'] ?? 0);
    $good = (int)($item['quantity_good'] ?? 0);
    if ($ordered > 0) {
        return max(0, $ordered - $good);
    }
    return max(0, (int)($item['quantity_damaged'] ?? 0) + (int)($item['quantity_missing'] ?? 0));
}

/** Stored combined Damage/Missing. This is accounted-for, not remaining. */
function recvLineDamageMissingQty(array $item): int {
    return max(0, (int)($item['quantity_damaged'] ?? 0) + (int)($item['quantity_missing'] ?? 0));
}

/** Good + Damage/Missing, capped at ordered. Never negative. */
function recvLineProcessedQty(array $item): int {
    $ordered = max(0, (int)($item['quantity_ordered'] ?? 0));
    $processed = max(0, (int)($item['quantity_good'] ?? 0) + recvLineDamageMissingQty($item));
    return $ordered > 0 ? min($ordered, $processed) : $processed;
}

/** True unprocessed qty. Damage/Missing is not remaining. */
function recvLineRemainingQty(array $item): int {
    return max(0, (int)($item['quantity_ordered'] ?? 0) - recvLineProcessedQty($item));
}

function recvSqlReceiptItemRemainingExpr(mysqli $conn, string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    $miss = recvHasColumn($conn, 'purchase_receipt_items', 'quantity_missing')
        ? "COALESCE({$p}quantity_missing,0)"
        : '0';
    return "GREATEST(0, COALESCE({$p}quantity_ordered,0) - COALESCE({$p}quantity_good,0) - COALESCE({$p}quantity_damaged,0) - $miss)";
}

/**
 * Per-PO-item inspect totals. Remaining is derived from receipt Good + Damage/Missing.
 *
 * @param array<int,int> $poIds
 * @return array<int,array<string,mixed>>
 */
function recvPoInspectTotalsBulk(mysqli $conn, array $poIds): array {
    $poIds = array_values(array_unique(array_filter(array_map('intval', $poIds))));
    $out = [];
    if (!$poIds) {
        return $out;
    }
    foreach ($poIds as $id) {
        $out[$id] = [
            'ordered' => 0,
            'good' => 0,
            'damage_missing' => 0,
            'processed' => 0,
            'remaining' => 0,
            'stocked' => 0,
            'has_issue' => false,
            'items' => [],
        ];
    }
    $list = implode(',', $poIds);
    $q = $conn->query("SELECT po_id, id, product_id, product_name, quantity_ordered, COALESCE(quantity_received,0) AS quantity_received
                       FROM purchase_order_items WHERE po_id IN ($list) ORDER BY po_id, id");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $poId = (int)$row['po_id'];
            $itemId = (int)$row['id'];
            $ord = max(0, (int)$row['quantity_ordered']);
            $out[$poId]['items'][$itemId] = [
                'po_item_id' => $itemId,
                'product_id' => (int)($row['product_id'] ?? 0),
                'product_name' => (string)($row['product_name'] ?? ''),
                'quantity_ordered' => $ord,
                'quantity_good' => 0,
                'quantity_damaged' => 0,
                'quantity_missing' => 0,
                'quantity_damage_missing' => 0,
                'quantity_processed' => 0,
                'quantity_remaining' => $ord,
                'poi_received' => max(0, (int)$row['quantity_received']),
            ];
            $out[$poId]['ordered'] += $ord;
            $out[$poId]['remaining'] += $ord;
        }
    }
    $missExpr = recvHasColumn($conn, 'purchase_receipt_items', 'quantity_missing')
        ? 'SUM(COALESCE(pri.quantity_missing,0))'
        : '0';
    $replGood = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? 'SUM(COALESCE(pri.quantity_good,0))'
        : 'SUM(COALESCE(pri.quantity_good,0))';
    $replDm = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? 'SUM(CASE WHEN COALESCE(rec.is_replacement,0)=0 THEN COALESCE(pri.quantity_damaged,0) ELSE 0 END)'
        : 'SUM(COALESCE(pri.quantity_damaged,0))';
    $replMiss = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? (recvHasColumn($conn, 'purchase_receipt_items', 'quantity_missing')
            ? 'SUM(CASE WHEN COALESCE(rec.is_replacement,0)=0 THEN COALESCE(pri.quantity_missing,0) ELSE 0 END)'
            : '0')
        : $missExpr;
    $stockedExpr = recvHasColumn($conn, 'purchase_receipt_items', 'stocked_in_qty')
        ? 'SUM(COALESCE(pri.stocked_in_qty,0))'
        : '0';
    $ri = $conn->query("SELECT rec.po_id, pri.po_item_id,
                               $replGood AS g,
                               $replDm AS d,
                               $replMiss AS m,
                               $stockedExpr AS stocked,
                               SUM(CASE WHEN COALESCE(pri.quantity_good,0)>0 THEN COALESCE(pri.quantity_good,0) ELSE 0 END) AS rcv
                        FROM purchase_receipt_items pri
                        INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
                        WHERE rec.po_id IN ($list)
                        GROUP BY rec.po_id, pri.po_item_id");
    if ($ri) {
        while ($row = $ri->fetch_assoc()) {
            $poId = (int)$row['po_id'];
            $itemId = (int)$row['po_item_id'];
            if (!isset($out[$poId]['items'][$itemId])) {
                continue;
            }
            $ord = (int)$out[$poId]['items'][$itemId]['quantity_ordered'];
            $good = max(0, (int)$row['g']);
            $dmg = max(0, (int)$row['d']);
            $miss = max(0, (int)$row['m']);
            $receivedGood = $ord > 0 ? min($ord, $good) : $good;
            $processed = $receivedGood;
            $remain = max(0, $ord - $receivedGood);
            $dm = $remain;
            $oldRemain = (int)$out[$poId]['items'][$itemId]['quantity_remaining'];
            $out[$poId]['items'][$itemId]['quantity_good'] = $good;
            $out[$poId]['items'][$itemId]['quantity_damaged'] = $dmg;
            $out[$poId]['items'][$itemId]['quantity_missing'] = $miss;
            $out[$poId]['items'][$itemId]['quantity_damage_missing'] = $dm;
            $stocked = max(0, (int)($row['stocked'] ?? 0));
            $out[$poId]['items'][$itemId]['quantity_processed'] = $processed;
            $out[$poId]['items'][$itemId]['quantity_remaining'] = $remain;
            $out[$poId]['items'][$itemId]['quantity_stocked'] = $stocked;
            $out[$poId]['good'] += $good;
            $out[$poId]['stocked'] += $stocked;
            $out[$poId]['damage_missing'] += $dm;
            $out[$poId]['processed'] += $processed;
            $out[$poId]['remaining'] += ($remain - $oldRemain);
            if ($dm > 0) {
                $out[$poId]['has_issue'] = true;
            }
        }
    }
    $pendingMap = recvPendingReplacementQtyMap($conn, $poIds);
    foreach ($out as $poId => &$totPre) {
        foreach ($totPre['items'] as $itemId => &$itemPre) {
            $rawDm = max(0, (int)($itemPre['quantity_damage_missing'] ?? 0));
            if (isset($pendingMap[$poId][$itemId])) {
                $itemPre['quantity_pending_replacement'] = max(0, (int)$pendingMap[$poId][$itemId]);
            } else {
                $itemPre['quantity_pending_replacement'] = $rawDm;
            }
        }
        unset($itemPre);
    }
    unset($totPre);
    foreach ($out as &$tot) {
        foreach ($tot['items'] as &$item) {
            if ((int)$item['quantity_processed'] > 0) {
                unset($item['poi_received']);
                continue;
            }
            $poiRcv = (int)($item['poi_received'] ?? 0);
            unset($item['poi_received']);
            if ($poiRcv <= 0) {
                continue;
            }
            $ord = (int)$item['quantity_ordered'];
            $processed = $ord > 0 ? min($ord, $poiRcv) : $poiRcv;
            $remain = max(0, $ord - $processed);
            $oldRemain = (int)$item['quantity_remaining'];
            $item['quantity_processed'] = $processed;
            $item['quantity_remaining'] = $remain;
            $tot['processed'] += $processed;
            $tot['remaining'] += ($remain - $oldRemain);
        }
        unset($item);
        $tot['remaining'] = max(0, (int)$tot['remaining']);
        $tot['processed'] = max(0, (int)$tot['processed']);
    }
    unset($tot);
    return $out;
}

function recvPoInspectTotals(mysqli $conn, int $poId): array {
    if ($poId <= 0) {
        return [
            'ordered' => 0,
            'good' => 0,
            'damage_missing' => 0,
            'processed' => 0,
            'remaining' => 0,
            'stocked' => 0,
            'has_issue' => false,
            'items' => [],
        ];
    }
    $map = recvPoInspectTotalsBulk($conn, [$poId]);
    return $map[$poId] ?? [
        'ordered' => 0,
        'good' => 0,
        'damage_missing' => 0,
        'processed' => 0,
        'remaining' => 0,
        'stocked' => 0,
        'has_issue' => false,
        'items' => [],
    ];
}

function recvAttachInspectTotalsToPoRows(mysqli $conn, array &$rows): void {
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return;
    }
    $map = recvPoInspectTotalsBulk($conn, array_values($ids));
    foreach ($rows as &$r) {
        $id = (int)($r['id'] ?? 0);
        $t = $map[$id] ?? null;
        if (!$t) {
            $ordered = (int)($r['qty_ordered'] ?? 0);
            $received = (int)($r['qty_received'] ?? 0);
            $r['qty_good'] = 0;
            $r['qty_damage_missing'] = 0;
            $r['qty_processed'] = max(0, $received);
            $r['qty_remaining'] = max(0, $ordered - $received);
            $r['qty_stocked'] = 0;
            $r['has_receiving_issue'] = 0;
            continue;
        }
        $r['qty_good'] = (int)$t['good'];
        $r['qty_damage_missing'] = (int)$t['damage_missing'];
        $r['qty_processed'] = (int)$t['processed'];
        $r['qty_remaining'] = max(0, (int)$t['remaining']);
        $r['qty_stocked'] = (int)($t['stocked'] ?? 0);
        $r['has_receiving_issue'] = !empty($t['has_issue']) ? 1 : 0;
    }
    unset($r);
}

function recvAttachPoItemInspectState(mysqli $conn, int $poId, array $poItems): array {
    $t = recvPoInspectTotals($conn, $poId);
    $map = $t['items'];
    foreach ($poItems as &$item) {
        $id = (int)($item['id'] ?? 0);
        $st = $map[$id] ?? null;
        if (!$st) {
            $ord = max(0, (int)($item['quantity_ordered'] ?? 0));
            $item['quantity_good'] = (int)($item['quantity_good'] ?? 0);
            $item['quantity_damage_missing'] = recvLineDamageMissingQty($item);
            $item['quantity_processed'] = recvLineProcessedQty($item);
            $item['quantity_remaining'] = recvLineRemainingQty($item);
            continue;
        }
        $item['quantity_good'] = (int)$st['quantity_good'];
        $item['quantity_damaged'] = (int)$st['quantity_damaged'];
        $item['quantity_missing'] = (int)$st['quantity_missing'];
        $item['quantity_damage_missing'] = (int)$st['quantity_damage_missing'];
        $item['quantity_processed'] = (int)$st['quantity_processed'];
        $item['quantity_remaining'] = (int)$st['quantity_remaining'];
    }
    unset($item);
    return $poItems;
}

function recvMergeInspectionRecords(array $existing, array $posted): array {
    $out = [];
    $seen = [];
    foreach (array_merge($existing, $posted) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = recvInspectionBarcodeKey((string)($row['barcode'] ?? $row['barcode_key'] ?? ''));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $row;
    }
    return $out;
}

/**
 * Record combined Damage/Missing on the existing supplier_damage_returns row.
 * Does not split the inspect field and does not auto-create an RTS.
 * Missing/combined shortage is never sellable stock.
 */
function recvUpsertDamageMissingRecord(
    mysqli $conn,
    int $poId,
    int $poItemId,
    int $receiptId,
    int $receiptItemId,
    int $productId,
    int $supplierId,
    int $qtyOrdered,
    int $qtyReceived,
    int $qtyDamaged,
    int $qtyMissing,
    float $costPrice,
    string $remarks,
    int $uid
): void {
    if ($receiptItemId <= 0) {
        return;
    }
    $qtyDamaged = max(0, $qtyDamaged);
    $qtyMissing = max(0, $qtyMissing);
    $combined = $qtyDamaged + $qtyMissing;
    $ex = $conn->query('SELECT id, COALESCE(quantity_returned,0) AS quantity_returned FROM supplier_damage_returns WHERE receipt_item_id=' . (int)$receiptItemId . ' LIMIT 1');
    $row = $ex ? $ex->fetch_assoc() : null;
    $existingId = $row ? (int)$row['id'] : 0;

    $hasMissing = recvHasColumn($conn, 'supplier_damage_returns', 'quantity_missing');
    if ($combined <= 0) {
        if ($existingId > 0 && (int)($row['quantity_returned'] ?? 0) <= 0) {
            if ($hasMissing) {
                $conn->query('UPDATE supplier_damage_returns SET quantity_damaged=0, quantity_missing=0 WHERE id=' . $existingId);
            } else {
                $conn->query('UPDATE supplier_damage_returns SET quantity_damaged=0 WHERE id=' . $existingId);
            }
        }
        return;
    }

    $status = 'Pending Replacement';
    $rem = $remarks !== '' ? $remarks : 'Replacement on delivery — not sellable';
    if ($existingId > 0) {
        if ($hasMissing) {
            $upd = $conn->prepare('UPDATE supplier_damage_returns SET quantity_ordered=?, quantity_received=?, quantity_damaged=?, quantity_missing=?, cost_price=?, remarks=? WHERE id=?');
            if (!$upd) {
                throw new RuntimeException($conn->error);
            }
            $upd->bind_param('iiiidsi', $qtyOrdered, $qtyReceived, $qtyDamaged, $qtyMissing, $costPrice, $rem, $existingId);
        } else {
            $upd = $conn->prepare('UPDATE supplier_damage_returns SET quantity_ordered=?, quantity_received=?, quantity_damaged=?, cost_price=?, remarks=? WHERE id=?');
            if (!$upd) {
                throw new RuntimeException($conn->error);
            }
            $upd->bind_param('iiidsi', $qtyOrdered, $qtyReceived, $qtyDamaged, $costPrice, $rem, $existingId);
        }
        if (!$upd->execute()) {
            throw new RuntimeException($upd->error ?: $conn->error);
        }
        $upd->close();
        return;
    }

    $zeroReturned = 0;
    $dr = $conn->prepare('INSERT INTO supplier_damage_returns (po_id, po_item_id, receipt_id, receipt_item_id, product_id, supplier_id, quantity_ordered, quantity_received, quantity_damaged, quantity_returned, cost_price, status, remarks, reported_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    if (!$dr) {
        throw new RuntimeException($conn->error);
    }
    $dr->bind_param(
        'iiiiiiiiiidssi',
        $poId,
        $poItemId,
        $receiptId,
        $receiptItemId,
        $productId,
        $supplierId,
        $qtyOrdered,
        $qtyReceived,
        $qtyDamaged,
        $zeroReturned,
        $costPrice,
        $status,
        $rem,
        $uid
    );
    if (!$dr->execute()) {
        throw new RuntimeException($dr->error ?: $conn->error);
    }
    $dmgId = (int)$conn->insert_id;
    $dr->close();
    $missCol = $conn->query("SHOW COLUMNS FROM supplier_damage_returns LIKE 'quantity_missing'");
    if ($missCol && $missCol->num_rows > 0) {
        $conn->query('UPDATE supplier_damage_returns SET quantity_missing=' . (int)$qtyMissing . ' WHERE id=' . $dmgId);
    }
}

function recvLineIsIncompleteDelivery(array $line): bool {
    return recvLineRemainingQty($line) > 0;
}

function recvWfWaitingForPricing(): string { return 'Waiting for Pricing'; }
function recvWfPricingConfirmed(): string { return 'Confirmed'; }

/** Safe percent cap matching DECIMAL(8,2) receipt markup/tax columns. */
function recvMaxPricingPercent(): float { return 999.99; }

/** Safe peso cap for purchaser-entered markup amount (not a percentage). */
function recvMaxMarkupAmount(): float { return 9999999.99; }

function recvParseRequiredYesNo($raw): ?bool {
    if ($raw === null) {
        return null;
    }
    $s = strtolower(trim((string)$raw));
    if ($s === '') {
        return null;
    }
    if (in_array($s, ['1', 'yes', 'y', 'true', 'on'], true)) {
        return true;
    }
    if (in_array($s, ['0', 'no', 'n', 'false', 'off'], true)) {
        return false;
    }
    return null;
}

function recvParseOptionalMarkupPercent($raw): array {
    if ($raw === null || $raw === '') {
        return ['apply' => false, 'percent' => 0.0];
    }
    if (is_string($raw) && trim($raw) === '') {
        return ['apply' => false, 'percent' => 0.0];
    }
    $pct = recvParseRequiredPercent($raw, 'Markup %');
    return ['apply' => $pct > 0, 'percent' => $pct];
}

function recvParseRequiredPercent($raw, string $label): float {
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
        throw new RuntimeException($label . ' is required');
    }
    if (is_bool($raw) || is_array($raw) || is_object($raw)) {
        throw new RuntimeException($label . ' must be a valid number');
    }
    $s = is_string($raw) ? trim($raw) : (string)$raw;
    if ($s === '' || !is_numeric($s) || preg_match('/[eE]/', $s)) {
        throw new RuntimeException($label . ' must be a valid number');
    }
    $v = (float)$s;
    if (!is_finite($v)) {
        throw new RuntimeException($label . ' is not a valid number');
    }
    if ($v < 0) {
        throw new RuntimeException($label . ' cannot be negative');
    }
    if ($v > recvMaxPricingPercent()) {
        throw new RuntimeException($label . ' cannot exceed ' . recvMaxPricingPercent() . '%');
    }
    return round($v, 2);
}

/**
 * Optional Markup Price (₱). Blank = not entered yet (not an approved ₱0.00).
 * Explicit 0 is a valid peso markup of ₱0.00.
 */
function recvParseOptionalMarkupAmount($raw): array {
    if ($raw === null || $raw === '') {
        return ['apply' => false, 'amount' => 0.0];
    }
    if (is_string($raw) && trim($raw) === '') {
        return ['apply' => false, 'amount' => 0.0];
    }
    $amount = recvParseRequiredMarkupAmount($raw, 'Markup Price');
    return ['apply' => true, 'amount' => $amount];
}

function recvParseRequiredMarkupAmount($raw, string $label): float {
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
        throw new RuntimeException($label . ' is required');
    }
    if (is_bool($raw) || is_array($raw) || is_object($raw)) {
        throw new RuntimeException($label . ' must be a valid number');
    }
    $s = is_string($raw) ? trim($raw) : (string)$raw;
    if ($s === '' || !is_numeric($s) || preg_match('/[eE]/', $s)) {
        throw new RuntimeException($label . ' must be a valid number');
    }
    $v = (float)$s;
    if (!is_finite($v)) {
        throw new RuntimeException($label . ' is not a valid number');
    }
    if ($v < 0) {
        throw new RuntimeException($label . ' cannot be negative');
    }
    if ($v > recvMaxMarkupAmount()) {
        throw new RuntimeException($label . ' cannot exceed ₱' . number_format(recvMaxMarkupAmount(), 2));
    }
    return round($v, 2);
}

/**
 * Selling Price (₱) = Purchase Price + Markup Price.
 * Blank/unapplied markup does not add an amount.
 */
function recvCalcSellingPriceFromMarkupAmount(float $cost, bool $applyMarkup, float $markupAmount): array {
    if ($cost < 0) {
        throw new RuntimeException('Purchase price cannot be negative');
    }
    $markupAmount = $applyMarkup ? $markupAmount : 0.0;
    $sell = round($cost + $markupAmount, 2);
    if ($sell < 0) {
        throw new RuntimeException('Selling Price cannot be negative');
    }
    return [
        'selling_price' => $sell,
        'markup_amount' => $markupAmount,
        'apply_markup' => $applyMarkup ? 1 : 0,
    ];
}

/**
 * Reference selling price only — does not change Good qty or inventory.
 * Price After Markup ± optional tax. Tax is stored as reference information, not a sale.
 */
function recvCalcReferenceSellingPrice(float $cost, bool $applyMarkup, float $markupPct, bool $applyTax, float $taxPct): array {
    if ($cost < 0) {
        throw new RuntimeException('Purchase price cannot be negative');
    }
    $markupPct = $applyMarkup ? $markupPct : 0.0;
    $taxPct = $applyTax ? $taxPct : 0.0;
    $afterMarkup = $applyMarkup
        ? round($cost + ($cost * ($markupPct / 100.0)), 2)
        : round($cost, 2);
    $taxAmount = $applyTax ? round($afterMarkup * ($taxPct / 100.0), 2) : 0.0;
    $ref = round($afterMarkup + $taxAmount, 2);
    if ($afterMarkup < 0 || $ref < 0) {
        throw new RuntimeException('Reference Selling Price cannot be negative');
    }
    return [
        'price_after_markup' => $afterMarkup,
        'tax_amount' => $taxAmount,
        'reference_selling_price' => $ref,
        'markup_percent' => $markupPct,
        'tax_percent' => $taxPct,
        'apply_markup' => $applyMarkup ? 1 : 0,
        'apply_tax' => $applyTax ? 1 : 0,
    ];
}

function recvLinePricingIsConfirmed(array $line): bool {
    $ps = trim((string)($line['pricing_status'] ?? ''));
    if (strcasecmp($ps, recvWfPricingConfirmed()) === 0) {
        return true;
    }
    $lineWf = recvNormalizeWorkflowStatus((string)($line['line_stock_status'] ?? ''));
    return in_array($lineWf, [recvWfStockedIn(), recvWfPendingAdmin(), recvWfGranted()], true);
}

function recvLinePricingStatus(array $line): string {
    if (recvLineIsIncompleteDelivery($line)) {
        return recvWfIncomplete();
    }
    if (recvLinePricingIsConfirmed($line)) {
        return recvWfPricingConfirmed();
    }
    return recvWfWaitingForPricing();
}

function recvUserHasPricingQueueAccess(?mysqli $conn, int $userId, bool $isPurchaser, bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }
    if (!$isPurchaser || $userId <= 0 || !$conn) {
        return false;
    }
    $stmt = $conn->prepare('SELECT allowed_sections FROM user_permissions WHERE user_id=? LIMIT 1');
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || trim((string)($row['allowed_sections'] ?? '')) === '') {
        return true;
    }
    $secs = array_map('trim', explode(',', (string)$row['allowed_sections']));
    return in_array('markup-queue', $secs, true) || in_array('pricing-queue', $secs, true);
}

function recvUserCanManageReceiptPricing(?mysqli $conn, int $userId, bool $isPurchaser): bool {
    if (!$isPurchaser || $userId <= 0 || !$conn) {
        return false;
    }
    $col = $conn->query("SHOW COLUMNS FROM user_permissions LIKE 'can_manage_pricing'");
    if (!($col && $col->num_rows > 0)) {
        return true;
    }
    $stmt = $conn->prepare('SELECT can_manage_pricing FROM user_permissions WHERE user_id=? LIMIT 1');
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    return !empty($row['can_manage_pricing']);
}

/** True when a good received line still needs Purchaser markup confirmation. */
function recvLineIsPendingPurchaserMarkup(array $line): bool {
    if (recvLineIsIncompleteDelivery($line)) {
        return false;
    }
    $pendingGood = (int)($line['quantity_good'] ?? 0) - (int)($line['stocked_in_qty'] ?? 0);
    if ($pendingGood <= 0) {
        return false;
    }
    $lineWf = recvNormalizeWorkflowStatus((string)($line['line_stock_status'] ?? ''));
    if (in_array($lineWf, [recvWfStockedIn(), recvWfPendingAdmin(), recvWfGranted()], true)) {
        return false;
    }
    $ps = trim((string)($line['pricing_status'] ?? ''));
    if (strcasecmp($ps, recvWfPricingConfirmed()) === 0) {
        return false;
    }
    return true;
}

function recvReceiptHasPendingPurchaserMarkup(mysqli $conn, int $receiptId): bool {
    if ($receiptId <= 0) {
        return false;
    }
    $res = $conn->query("SELECT quantity_good, quantity_ordered, COALESCE(quantity_damaged,0) AS quantity_damaged, COALESCE(quantity_missing,0) AS quantity_missing, COALESCE(stocked_in_qty,0) AS stocked_in_qty, line_stock_status, pricing_status FROM purchase_receipt_items WHERE receipt_id=" . (int)$receiptId);
    if (!$res) {
        return false;
    }
    while ($row = $res->fetch_assoc()) {
        if (recvLineIsPendingPurchaserMarkup($row)) {
            return true;
        }
    }
    return false;
}

/**
 * Drop unread Pricing Queue alerts once the receipt no longer needs markup.
 * related_id stores the receipt number (PREC-…).
 */
function recvClearStaleMarkupQueueNotifications(mysqli $conn): void {
    $res = $conn->query("SELECT id, related_id FROM notifications WHERE is_read = 0 AND related_module = 'markup-queue'");
    if (!$res) {
        return;
    }
    $byReceipt = [];
    $clear = [];
    while ($row = $res->fetch_assoc()) {
        $num = trim((string)($row['related_id'] ?? ''));
        if ($num === '') {
            $clear[] = (int)$row['id'];
            continue;
        }
        $byReceipt[$num][] = (int)$row['id'];
    }
    if ($byReceipt) {
        $in = "'" . implode("','", array_map(static function ($num) use ($conn) {
            return $conn->real_escape_string($num);
        }, array_keys($byReceipt))) . "'";
        $found = [];
        $recRes = $conn->query("SELECT id, receipt_number FROM purchase_receipts WHERE receipt_number IN ($in)");
        if ($recRes) {
            while ($rec = $recRes->fetch_assoc()) {
                $found[(string)$rec['receipt_number']] = (int)$rec['id'];
            }
        }
        foreach ($byReceipt as $num => $ids) {
            $receiptId = $found[$num] ?? 0;
            if ($receiptId <= 0 || !recvReceiptHasPendingPurchaserMarkup($conn, $receiptId)) {
                foreach ($ids as $id) {
                    $clear[] = $id;
                }
            }
        }
    }
    $clear = array_values(array_unique(array_filter(array_map('intval', $clear))));
    if ($clear) {
        $conn->query('UPDATE notifications SET is_read = 1 WHERE id IN (' . implode(',', $clear) . ')');
    }
}

/** This line has already been confirmed into inventory. Sibling lines are not included. */
function recvLineIsStockedIn(array $line): bool {
    $lineWf = recvNormalizeWorkflowStatus((string)($line['line_stock_status'] ?? ''));
    if ($lineWf === recvWfStockedIn()) {
        return true;
    }
    $good = (int)($line['quantity_good'] ?? 0);
    $stocked = (int)($line['stocked_in_qty'] ?? 0);
    return $good > 0 && $stocked >= $good;
}

/** Stock-In Queue label for one received line. Empty when the line is still waiting for pricing. */
function recvLineQueueStatus(array $line): string {
    if (recvLineIsStockedIn($line)) {
        return 'Stocked';
    }
    if (recvLineReadyForWarehouseStockIn($line)) {
        return 'Ready for Stock-In';
    }
    return '';
}

/** Confirmed good qty that Warehouse Head can stock in. Other lines on the receipt may still be waiting for pricing. */
function recvLineReadyForWarehouseStockIn(array $line): bool {
    $pendingGood = (int)($line['quantity_good'] ?? 0) - (int)($line['stocked_in_qty'] ?? 0);
    if ($pendingGood <= 0) {
        return false;
    }
    if (recvLineIsIncompleteDelivery($line)) {
        return false;
    }
    if (recvLineIsPendingPurchaserMarkup($line)) {
        return false;
    }
    return recvLinePricingIsConfirmed($line);
}

/** Good qty on the receipt that has not entered inventory yet. */
function recvReceiptUnstockedGoodQty(mysqli $conn, int $receiptId): int {
    if ($receiptId <= 0) {
        return 0;
    }
    $res = $conn->query("SELECT COALESCE(SUM(GREATEST(0, quantity_good - COALESCE(stocked_in_qty,0))),0) AS pending_good FROM purchase_receipt_items WHERE receipt_id=" . (int)$receiptId);
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row['pending_good'] ?? 0);
}

function recvReceiptHasReadyStockInLine(mysqli $conn, int $receiptId): bool {
    if ($receiptId <= 0) {
        return false;
    }
    $res = $conn->query("SELECT quantity_good, quantity_ordered, COALESCE(quantity_damaged,0) AS quantity_damaged, COALESCE(quantity_missing,0) AS quantity_missing, COALESCE(stocked_in_qty,0) AS stocked_in_qty, line_stock_status, pricing_status FROM purchase_receipt_items WHERE receipt_id=" . (int)$receiptId);
    if (!$res) {
        return false;
    }
    while ($row = $res->fetch_assoc()) {
        if (recvLineReadyForWarehouseStockIn($row)) {
            return true;
        }
    }
    return false;
}

/** Markup confirmed — Warehouse Head may Stock In. No Admin grant required. */
function recvIsReadyForWarehouseStockIn(?string $status): bool {
    $wf = recvNormalizeWorkflowStatus($status);
    return in_array($wf, [recvWfGranted(), recvWfPendingAdmin()], true);
}

/** Warehouse-facing delivery status from existing PO + receipt fields (no extra records). */
function poDeliveryDisplayStatus(array $r): string {
    $recv = trim((string)($r['receiving_status'] ?? ''));
    $wf = recvNormalizeWorkflowStatus((string)($r['receipt_workflow_status'] ?? ''));
    $status = trim((string)($r['status'] ?? ''));
    $ordered = (int)($r['qty_ordered'] ?? $r['ordered_boxes'] ?? 0);
    $received = (int)($r['qty_received'] ?? $r['received_boxes'] ?? 0);

    if ($recv === 'Stocked In' || $wf === 'Stocked In') {
        return 'Completed';
    }
    if ($recv === recvWfIncomplete() || $wf === recvWfIncomplete()
        || $recv === 'Pending – Incomplete Delivery' || $wf === 'Pending – Incomplete Delivery') {
        return 'Pending - Incomplete Delivery';
    }
    if (in_array($recv, ['Pending Markup', 'Received - Pending Markup', 'Pending for Stock-In', 'Pending Stock-In'], true)
        || $wf === 'Received - Pending Markup') {
        return 'For Inspection';
    }
    if (in_array($recv, ['Pending Admin Approval', 'Ready for Stock-In', 'Markup Ready - Pending Admin Approval', 'Approved - Pending Stock-In', 'Granted - Pending Stock-In'], true)
        || in_array($wf, ['Markup Ready - Pending Admin Approval', 'Approved - Pending Stock-In'], true)) {
        return 'Received';
    }
    if ($received > 0 && $ordered > 0 && $received < $ordered) {
        return 'Partially Received';
    }
    if ($received >= $ordered && $ordered > 0) {
        return 'Received';
    }
    if (in_array($status, ['Sent', 'Supplier Confirmed', 'Waiting for Delivery'], true)) {
        return 'In Transit';
    }
    $edd = trim((string)($r['expected_delivery_date'] ?? ''));
    if ($edd !== '' && strtotime($edd) > strtotime(date('Y-m-d'))) {
        return 'In Transit';
    }
    if ($status === 'Approved') {
        return 'Approved';
    }
    return 'Expected';
}

function poIsIncomingForWarehouse(array $r): bool {
    $status = trim((string)($r['status'] ?? ''));
    if (in_array($status, ['Draft', 'Cancelled', 'Pending Approval'], true)) {
        return false;
    }
    if ((int)($r['qty_remaining'] ?? $r['remaining_boxes'] ?? 0) > 0) {
        return true;
    }
    if (!empty($r['has_receiving_issue']) || (int)($r['qty_damage_missing'] ?? 0) > 0) {
        return true;
    }
    $delivery = poDeliveryDisplayStatus($r);
    if ($delivery === recvWfIncomplete() || $delivery === 'Pending – Incomplete Delivery') {
        return true;
    }
    return in_array($delivery, ['Approved', 'In Transit', 'Expected', 'Partially Received'], true);
}

function poAttachDeliveryMeta(array &$r): void {
    $ordered = (int)($r['qty_ordered'] ?? 0);
    $received = (int)($r['qty_received'] ?? 0);
    $r['ordered_boxes'] = $ordered;
    $r['received_boxes'] = $received;
    if (isset($r['qty_remaining'])) {
        $r['remaining_boxes'] = max(0, (int)$r['qty_remaining']);
    } else {
        $r['remaining_boxes'] = max(0, $ordered - $received);
    }
    $r['qty_remaining'] = (int)$r['remaining_boxes'];
    $r['delivery_status'] = poDeliveryDisplayStatus($r);
    $r['is_incoming'] = poIsIncomingForWarehouse($r);
}

/** Unresolved Incoming qty: unprocessed remaining + still-pending Damage/Missing. */
function recvIncomingItemUnresolvedQty(array $inspectItem): int {
    $remain = max(0, (int)($inspectItem['quantity_remaining'] ?? 0));
    if (array_key_exists('quantity_pending_replacement', $inspectItem)) {
        $pending = max(0, (int)$inspectItem['quantity_pending_replacement']);
    } else {
        $pending = max(0, (int)($inspectItem['quantity_damage_missing'] ?? recvLineDamageMissingQty($inspectItem)));
    }
    return $remain + $pending;
}

function recvIncomingItemNeedsAttention(array $inspectItem): bool {
    return recvIncomingItemUnresolvedQty($inspectItem) > 0;
}

/**
 * PO item may appear in Goods Received only when the full ordered qty is accepted Good.
 * Remaining, damaged, and missing (including pending replacement) must all be resolved.
 * Replacement receipts are excluded from inspect Good totals; pending_replacement tracks that gap.
 */
function recvPoItemIsFullyAcceptedGood(array $inspectItem): bool {
    $ordered = max(0, (int)($inspectItem['quantity_ordered'] ?? 0));
    if ($ordered <= 0) {
        return false;
    }
    return !recvIncomingItemNeedsAttention($inspectItem);
}

function recvReceiptLineLooksFullyGood(array $line): bool {
    $ordered = max(0, (int)($line['quantity_ordered'] ?? 0));
    $good = max(0, (int)($line['quantity_good'] ?? 0));
    $dmg = max(0, (int)($line['quantity_damaged'] ?? 0));
    $miss = max(0, (int)($line['quantity_missing'] ?? 0));
    return $ordered > 0 && $good === $ordered && $dmg === 0 && $miss === 0;
}

function recvApplyGoodsReceivedAcceptedDisplay(array $line, array $inspectItem): array {
    $ordered = max(0, (int)($inspectItem['quantity_ordered'] ?? $line['quantity_ordered'] ?? 0));
    $line['quantity_ordered'] = $ordered;
    $line['quantity_good'] = $ordered;
    $line['quantity_received'] = $ordered;
    $line['quantity_damaged'] = 0;
    $line['quantity_missing'] = 0;
    return $line;
}

/**
 * Receiving Receipt display quantities for the current transaction.
 * Ordered stays the PO quantity. Received and Good are the good quantity received so far.
 * While any of that order is still unreceived, Damaged/Missing and Remaining both equal that pending quantity.
 * Once the pending quantity is received as Good, including on a replacement receipt, all four close out.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function recvApplyCurrentReceiptQtys(mysqli $conn, int $poId, array $items): array {
    if ($poId <= 0 || !$items) {
        return $items;
    }
    $poItemIds = [];
    foreach ($items as $item) {
        $id = (int)($item['po_item_id'] ?? 0);
        if ($id > 0) {
            $poItemIds[$id] = $id;
        }
    }
    if (!$poItemIds) {
        return $items;
    }
    $list = implode(',', $poItemIds);
    $orderedByItem = [];
    $oq = $conn->query('SELECT id, quantity_ordered FROM purchase_order_items WHERE po_id=' . (int)$poId . ' AND id IN (' . $list . ')');
    if ($oq) {
        while ($row = $oq->fetch_assoc()) {
            $orderedByItem[(int)$row['id']] = max(0, (int)$row['quantity_ordered']);
        }
    }
    $goodByItem = [];
    $replSql = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? ' AND COALESCE(rec.is_replacement,0)=0'
        : '';
    $gq = $conn->query('SELECT pri.po_item_id, SUM(COALESCE(pri.quantity_good,0)) AS g
                        FROM purchase_receipt_items pri
                        INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
                        WHERE rec.po_id=' . (int)$poId . ' AND pri.po_item_id IN (' . $list . ')' . $replSql . '
                        GROUP BY pri.po_item_id');
    if ($gq) {
        while ($row = $gq->fetch_assoc()) {
            $goodByItem[(int)$row['po_item_id']] = max(0, (int)$row['g']);
        }
    }
    foreach ($items as &$item) {
        $id = (int)($item['po_item_id'] ?? 0);
        if ($id <= 0 || !isset($orderedByItem[$id])) {
            continue;
        }
        $ordered = $orderedByItem[$id];
        $good = min($ordered, (int)($goodByItem[$id] ?? 0));
        $pending = max(0, $ordered - $good);
        $item['quantity_ordered'] = $ordered;
        $item['quantity_good'] = $good;
        $item['quantity_received'] = $good;
        $item['quantity_damaged'] = 0;
        $item['quantity_missing'] = $pending;
    }
    unset($item);
    return $items;
}

/**
 * Filter Goods Received list rows: hide replacement receipts and receipts with no fully-accepted PO item.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function recvFilterPurchaseReceiptsForGoodsReceived(mysqli $conn, array $rows): array {
    if (!$rows) {
        return [];
    }
    $receiptIds = [];
    $poIds = [];
    foreach ($rows as $r) {
        $rid = (int)($r['id'] ?? 0);
        $poId = (int)($r['po_id'] ?? 0);
        if ($rid > 0) {
            $receiptIds[$rid] = $rid;
        }
        if ($poId > 0) {
            $poIds[$poId] = $poId;
        }
    }
    if (!$receiptIds) {
        return [];
    }
    $inspect = recvPoInspectTotalsBulk($conn, array_values($poIds));
    $replByReceipt = [];
    if (recvHasColumn($conn, 'purchase_receipts', 'is_replacement')) {
        $list = implode(',', $receiptIds);
        $rq = $conn->query("SELECT id, COALESCE(is_replacement,0) AS is_replacement FROM purchase_receipts WHERE id IN ($list)");
        if ($rq) {
            while ($rr = $rq->fetch_assoc()) {
                $replByReceipt[(int)$rr['id']] = ((int)$rr['is_replacement'] === 1);
            }
        }
    }
    $list = implode(',', $receiptIds);
    $itemsByReceipt = [];
    $homeReceiptByPoItem = [];
    $iq = $conn->query("SELECT pri.receipt_id, pri.po_item_id, pri.quantity_good, pri.quantity_ordered, rec.po_id
                        FROM purchase_receipt_items pri
                        INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
                        WHERE pri.receipt_id IN ($list)
                        ORDER BY pri.receipt_id ASC, pri.id ASC");
    if ($iq) {
        while ($it = $iq->fetch_assoc()) {
            $rid = (int)$it['receipt_id'];
            $poItemId = (int)$it['po_item_id'];
            $poId = (int)$it['po_id'];
            $itemsByReceipt[$rid][] = $it;
            if (!empty($replByReceipt[$rid])) {
                continue;
            }
            $st = $inspect[$poId]['items'][$poItemId] ?? null;
            if ($poItemId <= 0 || !$st || !recvPoItemIsFullyAcceptedGood($st)) {
                continue;
            }
            if (!isset($homeReceiptByPoItem[$poItemId])) {
                $homeReceiptByPoItem[$poItemId] = $rid;
            }
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $rid = (int)($r['id'] ?? 0);
        $poId = (int)($r['po_id'] ?? 0);
        if (!empty($replByReceipt[$rid])) {
            $goodQty = 0;
            $lineCount = 0;
            foreach ($itemsByReceipt[$rid] ?? [] as $it) {
                $good = max(0, (int)($it['quantity_good'] ?? 0));
                if ($good <= 0) {
                    continue;
                }
                $lineCount++;
                $goodQty += $good;
            }
            if ($lineCount <= 0) {
                continue;
            }
            $r['item_count'] = $lineCount;
            $r['total_received_qty'] = $goodQty;
            $r['is_replacement'] = 1;
            $r['display_status'] = 'Received';
            $out[] = $r;
            continue;
        }
        $eligible = 0;
        $qty = 0;
        foreach ($itemsByReceipt[$rid] ?? [] as $it) {
            $poItemId = (int)$it['po_item_id'];
            $st = $inspect[$poId]['items'][$poItemId] ?? null;
            if ($poItemId <= 0 || !$st || !recvPoItemIsFullyAcceptedGood($st)) {
                continue;
            }
            if ((int)($homeReceiptByPoItem[$poItemId] ?? 0) !== $rid) {
                continue;
            }
            $eligible++;
            $qty += max(0, (int)($st['quantity_ordered'] ?? 0));
        }
        if ($eligible <= 0) {
            continue;
        }
        $r['item_count'] = $eligible;
        $r['total_received_qty'] = $qty;
        $out[] = $r;
    }
    return $out;
}

/**
 * Goods Received detail: only fully-accepted PO items, on their original (non-replacement) receipt.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function recvFilterReceiptItemsForGoodsReceived(mysqli $conn, array $items, int $poId, int $receiptId, bool $isReplacementReceipt = false): array {
    if ($isReplacementReceipt) {
        $out = [];
        foreach ($items as $item) {
            $ordered = max(0, (int)($item['quantity_ordered'] ?? 0));
            $good = max(0, (int)($item['quantity_good'] ?? 0));
            if ($good <= 0 && $ordered <= 0) {
                continue;
            }
            $item['quantity_ordered'] = $ordered;
            $item['quantity_good'] = $good;
            $item['quantity_received'] = $good;
            $item['quantity_missing'] = max(0, $ordered - $good);
            $item['display_status'] = ($ordered > 0 && $good >= $ordered) ? 'Received' : 'To Received';
            $out[] = $item;
        }
        return $out;
    }
    if ($poId <= 0 || $receiptId <= 0 || !$items) {
        return [];
    }
    $totals = recvPoInspectTotals($conn, $poId);
    $eligibleIds = [];
    foreach ($totals['items'] as $itemId => $st) {
        if (recvPoItemIsFullyAcceptedGood($st)) {
            $eligibleIds[(int)$itemId] = true;
        }
    }
    $out = [];
    $home = [];
    if ($eligibleIds) {
        $replFilter = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
            ? ' AND COALESCE(rec.is_replacement,0)=0'
            : '';
        $idList = implode(',', array_map('intval', array_keys($eligibleIds)));
        $hq = $conn->query("SELECT pri.po_item_id, MIN(pri.receipt_id) AS home_id
                            FROM purchase_receipt_items pri
                            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
                            WHERE rec.po_id=" . (int)$poId . " AND pri.po_item_id IN ($idList)$replFilter
                            GROUP BY pri.po_item_id");
        if ($hq) {
            while ($row = $hq->fetch_assoc()) {
                $home[(int)$row['po_item_id']] = (int)$row['home_id'];
            }
        }
    }
    foreach ($items as $line) {
        $itemId = (int)($line['po_item_id'] ?? 0);
        if ($itemId <= 0) {
            if (recvReceiptLineLooksFullyGood($line)) {
                $out[] = $line;
            }
            continue;
        }
        if (empty($eligibleIds[$itemId])) {
            continue;
        }
        if ((int)($home[$itemId] ?? $receiptId) !== $receiptId) {
            continue;
        }
        $storedDm = max(0, (int)($line['quantity_damaged'] ?? 0) + (int)($line['quantity_missing'] ?? 0));
        $out[] = $storedDm > 0
            ? $line
            : recvApplyGoodsReceivedAcceptedDisplay($line, $totals['items'][$itemId]);
    }
    return $out;
}

/**
 * Unresolved Damage/Missing qty still waiting for a Good replacement, keyed by po_id then po_item_id.
 *
 * @param array<int,int> $poIds
 * @return array<int,array<int,int>>
 */
function recvPendingReplacementQtyMap(mysqli $conn, array $poIds): array {
    $poIds = array_values(array_unique(array_filter(array_map('intval', $poIds))));
    $out = [];
    if (!$poIds) {
        return $out;
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'supplier_damage_returns'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return $out;
    }
    $list = implode(',', $poIds);
    $miss = recvHasColumn($conn, 'supplier_damage_returns', 'quantity_missing')
        ? 'COALESCE(quantity_missing,0)'
        : '0';
    $sql = "SELECT po_id, po_item_id,
                   SUM(GREATEST(0, COALESCE(quantity_damaged,0) + $miss - COALESCE(quantity_replaced,0))) AS pending
            FROM supplier_damage_returns
            WHERE po_id IN ($list)
            GROUP BY po_id, po_item_id";
    $res = $conn->query($sql);
    if (!$res) {
        return $out;
    }
    while ($row = $res->fetch_assoc()) {
        $poId = (int)$row['po_id'];
        $itemId = (int)$row['po_item_id'];
        if ($itemId <= 0) {
            continue;
        }
        $out[$poId][$itemId] = max(0, (int)$row['pending']);
    }
    return $out;
}

/**
 * Original receipt Damage/Missing for one PO item.
 * Uses damaged + missing on the original receiving lines only, never the original Good quantity.
 *
 * @return array{receipt_id:int,receipt_number:string,damage_qty:int}
 */
function recvOriginalDamageReplacementSource(mysqli $conn, int $poId, int $poItemId): array {
    $empty = ['receipt_id' => 0, 'receipt_number' => '', 'damage_qty' => 0];
    if ($poId <= 0 || $poItemId <= 0) {
        return $empty;
    }
    $miss = recvHasColumn($conn, 'purchase_receipt_items', 'quantity_missing')
        ? 'COALESCE(pri.quantity_missing,0)'
        : '0';
    $repl = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? ' AND COALESCE(rec.is_replacement,0)=0'
        : '';
    $sql = "SELECT rec.id, rec.receipt_number,
                   SUM(COALESCE(pri.quantity_damaged,0) + $miss) AS dm
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            WHERE rec.po_id=" . (int)$poId . " AND pri.po_item_id=" . (int)$poItemId . " $repl
            GROUP BY rec.id, rec.receipt_number
            HAVING dm > 0
            ORDER BY rec.id ASC";
    $res = @$conn->query($sql);
    if (!$res) {
        return $empty;
    }
    $id = 0;
    $num = '';
    $dm = 0;
    while ($row = $res->fetch_assoc()) {
        $rowDm = max(0, (int)($row['dm'] ?? 0));
        if ($rowDm <= 0) {
            continue;
        }
        if ($id <= 0) {
            $id = (int)$row['id'];
            $num = (string)($row['receipt_number'] ?? '');
        }
        $dm += $rowDm;
    }
    return ['receipt_id' => $id, 'receipt_number' => $num, 'damage_qty' => $dm];
}

/** Lot Number from the original (non-replacement) receipt line. Does not modify that line. */
function recvOriginalItemLotNumber(mysqli $conn, int $poId, int $poItemId): string {
    if ($poId <= 0 || $poItemId <= 0) {
        return '';
    }
    $repl = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? ' AND COALESCE(rec.is_replacement,0)=0'
        : '';
    $lotCol = recvHasColumn($conn, 'purchase_receipt_items', 'manufacturer_lot')
        ? 'pri.manufacturer_lot'
        : "''";
    $sql = "SELECT pri.id, $lotCol AS manufacturer_lot
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            WHERE rec.po_id=" . (int)$poId . " AND pri.po_item_id=" . (int)$poItemId . " $repl
            ORDER BY pri.id ASC";
    $res = @$conn->query($sql);
    if (!$res) {
        return '';
    }
    $fallback = '';
    while ($row = $res->fetch_assoc()) {
        $lot = trim((string)($row['manufacturer_lot'] ?? ''));
        if ($lot === '') {
            foreach (recvLoadReceiptItemLots($conn, (int)$row['id'], $row) as $lotRow) {
                $lot = trim((string)($lotRow['lot_number'] ?? ''));
                if ($lot !== '') {
                    break;
                }
            }
        }
        if ($lot === '') {
            continue;
        }
        if ($fallback === '') {
            $fallback = $lot;
        }
        if (!recvLotIsNotApplicable($lot)) {
            return $lot;
        }
    }
    return $fallback;
}

/** Latest Lot Number saved on a replacement receipt for this PO item. Does not read or change the original receipt. */
function recvLatestReplacementItemLotNumber(mysqli $conn, int $poId, int $poItemId): string {
    if ($poId <= 0 || $poItemId <= 0 || !recvHasColumn($conn, 'purchase_receipts', 'is_replacement')) {
        return '';
    }
    $lotCol = recvHasColumn($conn, 'purchase_receipt_items', 'manufacturer_lot')
        ? 'pri.manufacturer_lot'
        : "''";
    $sql = "SELECT pri.id, $lotCol AS manufacturer_lot
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            WHERE rec.po_id=" . (int)$poId . " AND pri.po_item_id=" . (int)$poItemId . "
              AND COALESCE(rec.is_replacement,0)=1
            ORDER BY pri.id DESC
            LIMIT 1";
    $res = @$conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        return '';
    }
    $lot = trim((string)($row['manufacturer_lot'] ?? ''));
    if ($lot !== '') {
        return $lot;
    }
    foreach (recvLoadReceiptItemLots($conn, (int)$row['id'], $row) as $lotRow) {
        $lot = trim((string)($lotRow['lot_number'] ?? ''));
        if ($lot !== '') {
            return $lot;
        }
    }
    return '';
}

function recvLotNumbersMatch(string $a, string $b): bool {
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if (recvLotIsNotApplicable($a) && recvLotIsNotApplicable($b)) {
        return true;
    }
    return strcasecmp($a, $b) === 0;
}

function recvFindProductLotBatchId(mysqli $conn, int $productId, string $lot): int {
    if ($productId <= 0 || trim($lot) === '' || !recvHasColumn($conn, 'product_batches', 'manufacturer_lot')) {
        return 0;
    }
    $lot = trim($lot);
    if (recvLotIsNotApplicable($lot)) {
        $q = @$conn->query("SELECT id FROM product_batches WHERE product_id=" . (int)$productId . "
            AND LOWER(TRIM(COALESCE(manufacturer_lot,''))) IN ('n/a','na','not applicable')
            ORDER BY id ASC LIMIT 1");
    } else {
        $esc = $conn->real_escape_string($lot);
        $q = @$conn->query("SELECT id FROM product_batches WHERE product_id=" . (int)$productId . "
            AND LOWER(TRIM(COALESCE(manufacturer_lot,''))) = LOWER('$esc')
            ORDER BY id ASC LIMIT 1");
    }
    $row = $q ? $q->fetch_assoc() : null;
    return (int)($row['id'] ?? 0);
}

function recvReceiptIsReplacement(mysqli $conn, int $receiptId): bool {
    if ($receiptId <= 0 || !recvHasColumn($conn, 'purchase_receipts', 'is_replacement')) {
        return false;
    }
    $q = @$conn->query('SELECT COALESCE(is_replacement,0) AS r FROM purchase_receipts WHERE id=' . (int)$receiptId . ' LIMIT 1');
    $row = $q ? $q->fetch_assoc() : null;
    return (int)($row['r'] ?? 0) === 1;
}

function recvReplacedQtyForPoItem(mysqli $conn, int $poId, int $poItemId): int {
    if ($poId <= 0 || $poItemId <= 0) {
        return 0;
    }
    $tbl = @$conn->query("SHOW TABLES LIKE 'supplier_damage_returns'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return 0;
    }
    $q = @$conn->query("SELECT COALESCE(SUM(COALESCE(quantity_replaced,0)),0) AS r
                        FROM supplier_damage_returns
                        WHERE po_id=" . (int)$poId . " AND po_item_id=" . (int)$poItemId);
    $row = $q ? $q->fetch_assoc() : null;
    return max(0, (int)($row['r'] ?? 0));
}

/** Replacement shipment size: unresolved original Damage/Missing, never original Good. */
function recvUnresolvedOriginalDamageQty(mysqli $conn, int $poId, int $poItemId): int {
    $src = recvOriginalDamageReplacementSource($conn, $poId, $poItemId);
    $base = (int)$src['damage_qty'];
    if ($base <= 0) {
        return recvPoItemPendingReplacementQty($conn, $poId, $poItemId);
    }
    return max(0, $base - recvReplacedQtyForPoItem($conn, $poId, $poItemId));
}

function recvPoItemPendingReplacementQty(mysqli $conn, int $poId, int $poItemId): int {
    if ($poId <= 0 || $poItemId <= 0) {
        return 0;
    }
    $map = recvPendingReplacementQtyMap($conn, [$poId]);
    if (isset($map[$poId][$poItemId])) {
        return max(0, (int)$map[$poId][$poItemId]);
    }
    $t = recvPoInspectTotals($conn, $poId);
    $item = $t['items'][$poItemId] ?? null;
    return $item ? max(0, (int)($item['quantity_pending_replacement'] ?? $item['quantity_damage_missing'] ?? 0)) : 0;
}

/**
 * Credit accepted Good replacement against original Damage/Missing records. Does not rewrite original receipt lines.
 */
function recvApplyGoodReplacementToDamageRecord(mysqli $conn, int $poId, int $poItemId, int $goodQty): void {
    $goodQty = max(0, $goodQty);
    if ($poId <= 0 || $poItemId <= 0 || $goodQty <= 0) {
        return;
    }
    $missExpr = recvHasColumn($conn, 'supplier_damage_returns', 'quantity_missing')
        ? 'COALESCE(quantity_missing,0)'
        : '0';
    $res = $conn->query("SELECT id, COALESCE(quantity_damaged,0) AS quantity_damaged, $missExpr AS quantity_missing, COALESCE(quantity_replaced,0) AS quantity_replaced, status
                         FROM supplier_damage_returns
                         WHERE po_id=" . (int)$poId . " AND po_item_id=" . (int)$poItemId . "
                         ORDER BY id ASC
                         FOR UPDATE");
    if (!$res) {
        throw new RuntimeException($conn->error ?: 'Unable to load Replacement records for replacement.');
    }
    $left = $goodQty;
    while ($left > 0 && ($row = $res->fetch_assoc())) {
        $pending = max(0, (int)$row['quantity_damaged'] + (int)$row['quantity_missing'] - (int)$row['quantity_replaced']);
        if ($pending <= 0) {
            continue;
        }
        $take = min($pending, $left);
        $newRep = (int)$row['quantity_replaced'] + $take;
        $newPending = $pending - $take;
        $status = $newPending <= 0 ? 'Replacement Received' : (string)($row['status'] ?: 'Pending Replacement');
        if ($newPending > 0 && $status === 'Replacement Received') {
            $status = 'Pending Replacement';
        }
        $stEsc = $conn->real_escape_string($status);
        $id = (int)$row['id'];
        $sql = "UPDATE supplier_damage_returns SET quantity_replaced=$newRep, status='$stEsc'";
        if ($take > 0) {
            $sql .= ', replacement_received_at=NOW()';
        }
        $sql .= " WHERE id=$id";
        if (!$conn->query($sql)) {
            throw new RuntimeException($conn->error ?: 'Unable to update Replacement quantity.');
        }
        $left -= $take;
    }
    if ($left > 0) {
        throw new RuntimeException('Replacement quantity exceeds unresolved Replacement.');
    }
}

/**
 * Payload for Receive Replacement using the existing Receive & Inspect form.
 *
 * @return array<string,mixed>
 */
function recvDamageReplacementInspectPayload(mysqli $conn, int $poId, int $poItemId = 0): array {
    if ($poId <= 0) {
        throw new RuntimeException('Purchase Order is required.');
    }
    $poQ = $conn->query("SELECT po.*, s.name AS supplier_name, pr.pr_number
                         FROM purchase_orders po
                         LEFT JOIN suppliers s ON s.id = po.supplier_id
                         LEFT JOIN purchase_requests pr ON pr.id = po.pr_id
                         WHERE po.id=" . (int)$poId . " LIMIT 1");
    $po = $poQ ? $poQ->fetch_assoc() : null;
    if (!$po) {
        throw new RuntimeException('Purchase Order not found.');
    }
    $totals = recvPoInspectTotals($conn, $poId);
    require_once __DIR__ . '/product_barcodes_lib.php';
    productBarcodesEnsureSchema($conn);
    $items = [];
    foreach ($totals['items'] as $itemId => $st) {
        $itemId = (int)$itemId;
        if ($poItemId > 0 && $itemId !== $poItemId) {
            continue;
        }
        $origin = recvOriginalDamageReplacementSource($conn, $poId, $itemId);
        $pending = recvUnresolvedOriginalDamageQty($conn, $poId, $itemId);
        if ($pending <= 0) {
            continue;
        }
        $poi = $conn->query("SELECT poi.*, p.sku, p.barcode, p.brand, COALESCE(NULLIF(TRIM(p.unit_of_measure),''),'pcs') AS unit_of_measure, COALESCE(p.expiry_tracking,0) AS expiry_tracking
                             FROM purchase_order_items poi
                             LEFT JOIN products p ON p.id = poi.product_id
                             WHERE poi.id=$itemId AND poi.po_id=" . (int)$poId . " LIMIT 1");
        $line = $poi ? $poi->fetch_assoc() : null;
        if (!$line) {
            continue;
        }
        $pid = (int)($line['product_id'] ?? $st['product_id'] ?? 0);
        $barcodeValues = [];
        if ($pid > 0) {
            foreach (productBarcodesListForProduct($conn, $pid) as $bc) {
                if (strtolower((string)($bc['status'] ?? '')) !== 'active') {
                    continue;
                }
                $val = trim((string)($bc['barcode_value'] ?? ''));
                if ($val !== '') {
                    $barcodeValues[] = $val;
                }
            }
        }
        $cost = (float)($line['unit_price'] ?? 0);
        $items[] = [
            'id' => $itemId,
            'product_id' => $pid,
            'product_name' => (string)($line['product_name'] ?? $st['product_name'] ?? ''),
            'sku' => (string)($line['sku'] ?? ''),
            'brand' => (string)($line['brand'] ?? ''),
            'barcode' => (string)($line['barcode'] ?? ''),
            'barcode_values' => $barcodeValues,
            'unit_of_measure' => (string)($line['unit_of_measure'] ?? 'pcs'),
            'quantity_ordered' => $pending,
            'quantity_received' => $pending,
            'quantity_remaining' => $pending,
            'quantity_pending_replacement' => $pending,
            'original_damage_qty' => (int)($origin['damage_qty'] ?? $pending),
            'original_receipt_number' => (string)($origin['receipt_number'] ?? ''),
            'original_lot_number' => recvOriginalItemLotNumber($conn, $poId, $itemId),
            'replacement_lot_number' => recvLatestReplacementItemLotNumber($conn, $poId, $itemId),
            'unit_price' => $cost,
            'purchase_price' => $cost,
            'expiry_tracking' => (int)($line['expiry_tracking'] ?? 0),
        ];
    }
    if (!$items) {
        throw new RuntimeException('No unresolved To Received quantity is waiting on this Purchase Order.');
    }
    return [
        'id' => $poId,
        'po_id' => $poId,
        'po_number' => (string)$po['po_number'],
        'pr_number' => (string)($po['pr_number'] ?? ''),
        'supplier_name' => (string)($po['supplier_name'] ?? ''),
        'receive_replacement' => 1,
        'qty_remaining' => 0,
        'incomplete_receipt_id' => 0,
        'items' => $items,
    ];
}

/** Incoming Deliveries status for a PO that still has unresolved item(s). */
function recvIncomingDisplayStatus(array $r): string {
    return 'Pending';
}

/**
 * Display-only product lines for Incoming Deliveries (name, brand/variant, SKU, qty, catalog unit).
 * Lists only PO items that still need incoming attention: unprocessed remaining and/or Damage/Missing.
 * Successfully processed Good items are omitted. Does not read or change barcode records.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function recvAttachIncomingOrderedItems(mysqli $conn, array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return $rows;
    }
    $inspect = recvPoInspectTotalsBulk($conn, array_values($ids));
    $list = implode(',', $ids);
    $map = [];
    $sql = "SELECT poi.id, poi.po_id, poi.product_name, poi.quantity_ordered,
                   COALESCE(p.brand, '') AS brand,
                   COALESCE(p.size, '') AS size,
                   COALESCE(p.packaging, '') AS packaging,
                   COALESCE(p.sku, '') AS sku,
                   COALESCE(NULLIF(TRIM(p.unit_of_measure), ''), 'pcs') AS unit_of_measure
            FROM purchase_order_items poi
            LEFT JOIN products p ON p.id = poi.product_id
            WHERE poi.po_id IN ($list)
            ORDER BY poi.po_id, poi.id";
    $res = $conn->query($sql);
    if ($res) {
        while ($line = $res->fetch_assoc()) {
            $poId = (int)$line['po_id'];
            $itemId = (int)$line['id'];
            $ordered = (int)($line['quantity_ordered'] ?? 0);
            $st = $inspect[$poId]['items'][$itemId] ?? [
                'quantity_ordered' => $ordered,
                'quantity_remaining' => $ordered,
                'quantity_damage_missing' => 0,
                'quantity_good' => 0,
            ];
            if (!recvIncomingItemNeedsAttention($st)) {
                continue;
            }
            $brand = trim((string)($line['brand'] ?? ''));
            $size = trim((string)($line['size'] ?? ''));
            $pack = trim((string)($line['packaging'] ?? ''));
            $variant = $brand !== '' ? $brand : ($size !== '' ? $size : $pack);
            $pendingRepl = max(0, (int)($st['quantity_pending_replacement'] ?? $st['quantity_damage_missing'] ?? 0));
            $remain = max(0, (int)($st['quantity_remaining'] ?? 0));
            $map[$poId][] = [
                'po_item_id' => $itemId,
                'product_name' => (string)($line['product_name'] ?? ''),
                'brand' => $variant,
                'sku' => trim((string)($line['sku'] ?? '')),
                'quantity_ordered' => $ordered,
                'quantity_remaining' => $remain,
                'quantity_damage_missing' => max(0, (int)($st['quantity_damage_missing'] ?? 0)),
                'quantity_pending_replacement' => $pendingRepl,
                'can_receive_replacement' => $pendingRepl > 0 ? 1 : 0,
                'unit_of_measure' => trim((string)($line['unit_of_measure'] ?? 'pcs')) ?: 'pcs',
            ];
        }
    }
    foreach ($rows as &$row) {
        $poId = (int)($row['id'] ?? 0);
        $lines = $map[$poId] ?? [];
        $row['ordered_item_lines'] = $lines;
        $row['incoming_status'] = recvIncomingDisplayStatus($row);
        $pendingReplTotal = 0;
        foreach ($lines as $ln) {
            $pendingReplTotal += max(0, (int)($ln['quantity_pending_replacement'] ?? 0));
        }
        $row['pending_replacement_qty'] = $pendingReplTotal;
        $row['has_pending_replacement'] = $pendingReplTotal > 0 ? 1 : 0;
        $hasDamaged = 0;
        foreach ($lines as $ln) {
            if ((int)($ln['quantity_damage_missing'] ?? 0) > 0 || (int)($ln['quantity_pending_replacement'] ?? 0) > 0) {
                $hasDamaged = 1;
                break;
            }
        }
        $row['has_damaged_receiving'] = $hasDamaged;
    }
    unset($row);
    return recvAttachNoteToSupplierToIncomingRows($conn, $rows);
}

/**
 * Drop POs whose remaining Incoming lines are empty (all Good items already forwarded).
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function recvFinalizeIncomingDeliveryRows(mysqli $conn, array $rows): array {
    $rows = recvAttachIncomingOrderedItems($conn, $rows);
    return array_values(array_filter($rows, static function ($row) {
        return !empty($row['ordered_item_lines']);
    }));
}

/**
 * Incoming Deliveries list used by desktop and warehouse mobile.
 * Same tables and is_incoming rules as procurement_api get_incoming_deliveries.
 *
 * @return array<int,array<string,mixed>>
 */
function recvFetchIncomingDeliveryRows(mysqli $conn): array {
    $sqlIn = "SELECT po.*, s.name AS supplier_name, s.supplier_code, pr.pr_number,
                    u1.full_name AS requested_by_name, u2.full_name AS approved_by_name,
                    (SELECT COUNT(*) FROM purchase_order_items WHERE po_id=po.id) AS item_count,
                    (SELECT COALESCE(SUM(quantity_ordered),0) FROM purchase_order_items WHERE po_id=po.id) AS qty_ordered,
                    (SELECT COALESCE(SUM(COALESCE(quantity_received,0)),0) FROM purchase_order_items WHERE po_id=po.id) AS qty_received,
                    (SELECT GROUP_CONCAT(poi.product_name ORDER BY poi.id SEPARATOR ', ') FROM purchase_order_items poi WHERE poi.po_id=po.id) AS ordered_items,
                    (SELECT rec.id FROM purchase_receipts rec WHERE rec.po_id=po.id ORDER BY rec.id DESC LIMIT 1) AS latest_receipt_id,
                    (SELECT rec.receipt_number FROM purchase_receipts rec WHERE rec.po_id=po.id ORDER BY rec.id DESC LIMIT 1) AS latest_receipt_number,
                    (SELECT rec.workflow_status FROM purchase_receipts rec WHERE rec.po_id=po.id ORDER BY rec.id DESC LIMIT 1) AS receipt_workflow_status
             FROM purchase_orders po
             LEFT JOIN suppliers s ON po.supplier_id = s.id
             LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
             LEFT JOIN users u1 ON po.requested_by = u1.id
             LEFT JOIN users u2 ON po.approved_by = u2.id
             WHERE po.status NOT IN ('Draft','Cancelled','Pending Approval')
             ORDER BY COALESCE(po.order_date, DATE(po.created_at)) DESC, po.id DESC";
    $resIn = $conn->query($sqlIn);
    $incomingRows = [];
    if ($resIn) {
        while ($r = $resIn->fetch_assoc()) {
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
            $incomingRows[] = $r;
        }
    }
    recvAttachInspectTotalsToPoRows($conn, $incomingRows);
    $incoming = [];
    foreach ($incomingRows as $r) {
        poAttachDeliveryMeta($r);
        if (!empty($r['is_incoming'])) {
            $incoming[] = $r;
        }
    }
    return recvFinalizeIncomingDeliveryRows($conn, $incoming);
}

/**
 * Attach saved Note to Supplier text to existing incoming / damaged receiving lines.
 * Does not create receipts, POs, or PRs.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function recvAttachNoteToSupplierToIncomingRows(mysqli $conn, array $rows): array {
    if (!$rows) {
        return $rows;
    }
    ensureReceivingWorkflowSchema($conn);
    $ids = [];
    foreach ($rows as $row) {
        $poId = (int)($row['id'] ?? 0);
        if ($poId > 0) {
            $ids[$poId] = $poId;
        }
    }
    if (!$ids || !recvHasColumn($conn, 'purchase_receipt_items', 'note_to_supplier')) {
        return $rows;
    }
    $list = implode(',', $ids);
    $replFilter = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? ' AND COALESCE(rec.is_replacement,0)=0'
        : '';
    $sql = "SELECT rec.po_id, pri.po_item_id, pri.id AS receipt_item_id, pri.receipt_id,
                   TRIM(COALESCE(pri.note_to_supplier,'')) AS note_to_supplier,
                   GREATEST(0, COALESCE(pri.quantity_damaged,0)+COALESCE(pri.quantity_missing,0)) AS damaged_qty
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            WHERE rec.po_id IN ($list)
              $replFilter
              AND (COALESCE(pri.quantity_damaged,0)+COALESCE(pri.quantity_missing,0)) > 0
            ORDER BY pri.id ASC";
    $byPoItem = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($n = $res->fetch_assoc()) {
            $poId = (int)$n['po_id'];
            $itemId = (int)$n['po_item_id'];
            if ($poId <= 0 || $itemId <= 0) {
                continue;
            }
            if (!isset($byPoItem[$poId][$itemId])) {
                $byPoItem[$poId][$itemId] = $n;
            }
        }
    }
    foreach ($rows as &$row) {
        $poId = (int)($row['id'] ?? 0);
        $lines = $row['ordered_item_lines'] ?? [];
        $rowNote = '';
        $hasDamaged = (int)($row['has_damaged_receiving'] ?? 0);
        foreach ($lines as &$ln) {
            $itemId = (int)($ln['po_item_id'] ?? 0);
            $hit = $byPoItem[$poId][$itemId] ?? null;
            if ($hit) {
                $ln['receipt_item_id'] = (int)$hit['receipt_item_id'];
                $ln['receipt_id'] = (int)$hit['receipt_id'];
                $ln['note_to_supplier'] = (string)$hit['note_to_supplier'];
                if ((int)$hit['damaged_qty'] > 0) {
                    $hasDamaged = 1;
                }
                if ($rowNote === '' && $ln['note_to_supplier'] !== '') {
                    $rowNote = $ln['note_to_supplier'];
                }
            } else {
                $ln['note_to_supplier'] = (string)($ln['note_to_supplier'] ?? '');
            }
        }
        unset($ln);
        $row['ordered_item_lines'] = $lines;
        $row['has_damaged_receiving'] = $hasDamaged;
        $row['note_to_supplier'] = $rowNote;
    }
    unset($row);
    return $rows;
}

/**
 * Damaged lines on the original (non-replacement) receiving receipt for a PO.
 *
 * @return array<int,array<string,mixed>>
 */
function recvLoadDamagedReceivingNotes(mysqli $conn, int $poId): array {
    ensureReceivingWorkflowSchema($conn);
    $poId = (int)$poId;
    if ($poId <= 0 || !recvHasColumn($conn, 'purchase_receipt_items', 'note_to_supplier')) {
        return [];
    }
    $replFilter = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? ' AND COALESCE(rec.is_replacement,0)=0'
        : '';
    $sql = "SELECT pri.id AS receipt_item_id, pri.receipt_id, pri.po_item_id, pri.product_name,
                   GREATEST(0, COALESCE(pri.quantity_damaged,0)+COALESCE(pri.quantity_missing,0)) AS damaged_qty,
                   TRIM(COALESCE(pri.note_to_supplier,'')) AS note_to_supplier,
                   rec.receipt_number, po.po_number, s.name AS supplier_name
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            INNER JOIN purchase_orders po ON po.id = rec.po_id
            LEFT JOIN suppliers s ON s.id = rec.supplier_id
            WHERE rec.po_id = $poId
              $replFilter
              AND (COALESCE(pri.quantity_damaged,0)+COALESCE(pri.quantity_missing,0)) > 0
            ORDER BY pri.id ASC";
    $out = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
    }
    return $out;
}

/** Save Note to Supplier on the existing damaged receipt line only. */
function recvSaveNoteToSupplierOnItem(mysqli $conn, int $receiptItemId, string $note): void {
    ensureReceivingWorkflowSchema($conn);
    $receiptItemId = (int)$receiptItemId;
    $note = trim($note);
    if ($receiptItemId <= 0) {
        throw new RuntimeException('Invalid receiving record.');
    }
    if ($note === '') {
        throw new RuntimeException('Enter a reason / note for the supplier.');
    }
    if (!recvHasColumn($conn, 'purchase_receipt_items', 'note_to_supplier')) {
        throw new RuntimeException('Unable to save the supplier note.');
    }
    $replJoin = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? ' AND COALESCE(rec.is_replacement,0)=0'
        : '';
    $chk = $conn->query(
        "SELECT pri.id, GREATEST(0, COALESCE(pri.quantity_damaged,0)+COALESCE(pri.quantity_missing,0)) AS damaged_qty
         FROM purchase_receipt_items pri
         INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
         WHERE pri.id = $receiptItemId $replJoin
         LIMIT 1"
    );
    $row = $chk ? $chk->fetch_assoc() : null;
    if (!$row) {
        throw new RuntimeException('Damaged receiving record was not found.');
    }
    if ((int)($row['damaged_qty'] ?? 0) <= 0) {
        throw new RuntimeException('Note to Supplier is only available for damaged items.');
    }
    $upd = $conn->prepare('UPDATE purchase_receipt_items SET note_to_supplier=? WHERE id=?');
    if (!$upd) {
        throw new RuntimeException($conn->error);
    }
    $upd->bind_param('si', $note, $receiptItemId);
    if (!$upd->execute()) {
        throw new RuntimeException($upd->error ?: $conn->error);
    }
    $upd->close();
    $sdrTbl = $conn->query("SHOW TABLES LIKE 'supplier_damage_returns'");
    if ($sdrTbl && $sdrTbl->num_rows > 0 && recvHasColumn($conn, 'supplier_damage_returns', 'note_to_supplier')) {
        $upd2 = $conn->prepare('UPDATE supplier_damage_returns SET note_to_supplier=? WHERE receipt_item_id=?');
        if ($upd2) {
            $upd2->bind_param('si', $note, $receiptItemId);
            $upd2->execute();
            $upd2->close();
        }
    }
}

function recvProductExists(mysqli $conn, int $productId): bool {
    if ($productId <= 0) {
        return false;
    }
    $st = $conn->prepare('SELECT id FROM products WHERE id=? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $productId);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
}

/**
 * Resolve an existing catalog products.id from the PO line.
 * Never returns 0/invalid IDs and never creates a product.
 */
function recvResolveCatalogProductId(mysqli $conn, int $poProductId, string $productName, ?string $sku = null, ?string $barcode = null): int {
    if (recvProductExists($conn, $poProductId)) {
        return $poProductId;
    }

    $lookups = [];
    $sku = trim((string)$sku);
    $barcode = trim((string)$barcode);
    $name = trim($productName);
    if ($sku !== '') {
        $lookups[] = ['sql' => 'SELECT id FROM products WHERE sku=? LIMIT 1', 'val' => $sku];
    }
    if ($barcode !== '') {
        require_once __DIR__ . '/product_barcodes_lib.php';
        productBarcodesEnsureSchema($conn);
        $lookups[] = ['sql' => 'SELECT id FROM products WHERE barcode=? LIMIT 1', 'val' => $barcode];
        $linkedId = productBarcodesFindProductId($conn, $barcode);
        if ($linkedId > 0 && recvProductExists($conn, $linkedId)) {
            return $linkedId;
        }
    }
    if ($name !== '') {
        $lookups[] = ['sql' => 'SELECT id FROM products WHERE name=? LIMIT 1', 'val' => $name];
        $lookups[] = ['sql' => "SELECT id FROM products WHERE CONCAT(name, ' (', COALESCE(NULLIF(brand,''), ''), ')')=? LIMIT 1", 'val' => $name];
        if (preg_match('/^(.+?)\s+\(([^)]+)\)\s*$/', $name, $m)) {
            $base = trim($m[1]);
            $brand = trim($m[2]);
            if ($base !== '') {
                $lookups[] = ['sql' => 'SELECT id FROM products WHERE name=? LIMIT 1', 'val' => $base];
            }
            if ($base !== '' && $brand !== '') {
                $lookups[] = ['sql' => 'SELECT id FROM products WHERE name=? AND brand=? LIMIT 1', 'val' => $base, 'val2' => $brand];
            }
        }
    }

    foreach ($lookups as $lookup) {
        $sql = $lookup['sql'];
        $st = $conn->prepare($sql);
        if (!$st) {
            continue;
        }
        if (isset($lookup['val2'])) {
            $st->bind_param('ss', $lookup['val'], $lookup['val2']);
        } else {
            $st->bind_param('s', $lookup['val']);
        }
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $id = (int)($row['id'] ?? 0);
        if (recvProductExists($conn, $id)) {
            return $id;
        }
    }
    return 0;
}

/**
 * Confirm Receive & Inspect barcodes against the product catalog.
 * Empty is allowed (receive without registering a barcode).
 * Unknown barcodes are registered to this PO line. A barcode linked to a
 * different product is a conflict unless an explicit override is provided.
 *
 * @param array<string,mixed> $meta
 */
function recvValidateInspectedBarcode(mysqli $conn, int $productId, string $submitted, string $productName, array $meta = []): string {
    require_once __DIR__ . '/product_barcodes_lib.php';
    productBarcodesEnsureSchema($conn);
    $barcode = trim((string)$submitted);
    if ($barcode === '') {
        return '';
    }
    $result = productBarcodesResolveForReceive($conn, $productId, $barcode, array_merge($meta, [
        'source' => 'received',
    ]));
    if (!empty($result['ok'])) {
        return (string)($result['barcode'] ?? $barcode);
    }
    if (($result['status'] ?? '') === 'conflict') {
        $conflictName = (string)(($result['conflict']['product_name'] ?? '') ?: 'another product');
        $conflictSku = (string)($result['conflict']['sku'] ?? '');
        $label = $conflictName . ($conflictSku !== '' ? ' (' . $conflictSku . ')' : '');
        throw new RuntimeException('BARCODE_CONFLICT: This barcode is already registered to ' . $label . '. Confirm override with a reason to reassign it.');
    }
    throw new RuntimeException($result['message'] !== '' ? $result['message'] : ('Could not save barcode for ' . $productName . '.'));
}

/**
 * Register one or more scanned barcodes onto the received catalog product.
 *
 * @param array<int,string> $barcodes
 * @param array<string,mixed> $meta
 */
function recvRegisterInspectedBarcodes(mysqli $conn, int $productId, array $barcodes, string $productName, array $meta = []): string {
    $last = '';
    $seen = [];
    foreach ($barcodes as $code) {
        $code = trim((string)$code);
        if ($code === '') {
            continue;
        }
        $key = strtoupper($code);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $last = recvValidateInspectedBarcode($conn, $productId, $code, $productName, $meta);
    }
    return $last;
}

/**
 * Save Receive & Inspect Barcode ID onto product_barcodes (and keep products.barcode
 * as the primary/legacy display field). Empty input does not wipe existing barcodes.
 */
function recvApplyProductBarcode(mysqli $conn, int $productId, string $submitted, string $productName, array $meta = []): string {
    return recvValidateInspectedBarcode($conn, $productId, $submitted, $productName, $meta);
}

function recvIsTechnicalDbError(string $message): bool {
    return (bool)preg_match('/foreign key|constraint fails|SQLSTATE|Duplicate entry|Incorrect integer|cannot add or update|mysqli|Integrity constraint|PHP (Fatal|Warning)|Undefined (index|variable)|Stack trace/i', $message);
}

function recvPublicFailureMessage(Throwable $e): string {
    $msg = trim($e->getMessage());
    if ($msg === '' || recvIsTechnicalDbError($msg)) {
        error_log('[receiving] ' . ($msg !== '' ? $msg : $e));
        return 'Unable to update inventory. Please try again.';
    }
    return $msg;
}

/**
 * Next Original Receipt number, OR-000001, OR-000002, …
 * Call only while recvLockOriginalReceiptNumbers() is held, and only when inserting a new receiving transaction.
 */
function recvNextOriginalReceiptNumber(mysqli $conn): string
{
    $max = 0;
    $res = $conn->query("SELECT receipt_number FROM purchase_receipts WHERE receipt_number LIKE 'OR-%'");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (preg_match('/^OR-(\d+)$/i', (string)($row['receipt_number'] ?? ''), $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
    }
    for ($n = $max + 1; $n <= $max + 50; $n++) {
        $candidate = 'OR-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
        $esc = $conn->real_escape_string($candidate);
        $exists = $conn->query("SELECT id FROM purchase_receipts WHERE receipt_number='$esc' LIMIT 1");
        if (!$exists || $exists->num_rows === 0) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not assign an Original Receipt number. Please try again.');
}

function recvLockOriginalReceiptNumbers(mysqli $conn): void
{
    $lockRes = $conn->query("SELECT GET_LOCK('clar_or_receipt', 15) AS lk");
    $lockRow = $lockRes ? $lockRes->fetch_assoc() : null;
    if (!$lockRow || (int)$lockRow['lk'] !== 1) {
        throw new RuntimeException('Could not assign an Original Receipt number. Please try again.');
    }
}

function recvUnlockOriginalReceiptNumbers(mysqli $conn): void
{
    $conn->query("SELECT RELEASE_LOCK('clar_or_receipt')");
}

/** Keep the receipt number already stored on this receiving transaction. */
function recvKeepOriginalReceipt(mysqli $conn, int $receiptId, int $poId, string $dateReceived, string $remarks, string $driver, string $dr, string $si): array
{
    $stmt = $conn->prepare('SELECT * FROM purchase_receipts WHERE id=? AND po_id=? FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'Unable to open this receiving transaction.');
    }
    $stmt->bind_param('ii', $receiptId, $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('This receiving transaction was not found.');
    }
    $number = (string)($row['receipt_number'] ?? '');
    if ($number === '') {
        throw new RuntimeException('This receiving transaction has no Original Receipt number.');
    }
    $upd = $conn->prepare('UPDATE purchase_receipts SET date_received=?, remarks=?, driver_name=?, dr_number=?, supplier_si_number=? WHERE id=? AND receipt_number=?');
    if (!$upd) {
        throw new RuntimeException($conn->error ?: 'Unable to update this receiving transaction.');
    }
    $upd->bind_param('sssssis', $dateReceived, $remarks, $driver, $dr, $si, $receiptId, $number);
    if (!$upd->execute()) {
        $err = $upd->error ?: $conn->error;
        $upd->close();
        throw new RuntimeException($err ?: 'Unable to update this receiving transaction.');
    }
    $upd->close();
    $row['receipt_number'] = $number;
    $row['date_received'] = $dateReceived;
    return $row;
}

/** System FIFO batch: BATCH-YYYYMMDD-001. Manufacturer lot is typed separately from the pack. */
function generateSystemBatchNumber(mysqli $conn, ?string $dateReceived = null): string {
    $ts = strtotime($dateReceived ?: date('Y-m-d'));
    $day = $ts ? date('Ymd', $ts) : date('Ymd');
    $base = 'BATCH-' . $day . '-';
    $tables = [
        "SELECT id FROM purchase_receipt_items WHERE batch_number='%s' OR system_batch_number='%s' LIMIT 1",
        "SELECT id FROM product_batches WHERE batch_number='%s' LIMIT 1",
    ];
    for ($n = 1; $n < 1000; $n++) {
        $num = $base . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $esc = $conn->real_escape_string($num);
        $exists = false;
        $c1 = @$conn->query(sprintf($tables[0], $esc, $esc));
        if ($c1 && $c1->num_rows > 0) $exists = true;
        if (!$exists) {
            $c2 = $conn->query(sprintf($tables[1], $esc));
            if ($c2 && $c2->num_rows > 0) $exists = true;
        }
        if (!$exists) return $num;
    }
    return $base . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

/** Unique FIFO batch for a product + receiving date. Always BATCH-YYYYMMDD-### (lot is a separate field). */
function generateUniqueBatchNumber(mysqli $conn, int $productId, ?string $sku, ?string $dateReceived = null): string {
    unset($productId, $sku);
    return generateSystemBatchNumber($conn, $dateReceived);
}

function ensureReceivingWorkflowSchema(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) return;
    $done = true;

    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'receiving_status'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN receiving_status VARCHAR(50) NOT NULL DEFAULT 'Expected to Arrive' AFTER status");
    }

    $col = $conn->query("SHOW COLUMNS FROM purchase_receipts LIKE 'workflow_status'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_receipts ADD COLUMN workflow_status VARCHAR(80) NOT NULL DEFAULT 'Received - Pending Markup' AFTER status");
    } else {
        $info = $col->fetch_assoc();
        $type = strtolower((string)($info['Type'] ?? ''));
        if (strpos($type, 'varchar') !== false && (int)preg_replace('/\D/', '', $type) < 80) {
            @$conn->query("ALTER TABLE purchase_receipts MODIFY COLUMN workflow_status VARCHAR(80) NOT NULL DEFAULT 'Received - Pending Markup'");
        }
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_receipts LIKE 'received_at'");
    if (!($col && $col->num_rows > 0)) {
        $conn->query("ALTER TABLE purchase_receipts ADD COLUMN received_at DATETIME NULL AFTER date_received");
    }

    foreach ([
        'markup_by' => 'INT NULL AFTER received_by',
        'markup_at' => 'DATETIME NULL AFTER markup_by',
        'approved_by' => 'INT NULL AFTER markup_at',
        'approved_at' => 'DATETIME NULL AFTER approved_by',
        'stock_in_number' => 'VARCHAR(50) NULL AFTER approved_at',
        'driver_name' => 'VARCHAR(150) NULL AFTER remarks',
        'dr_number' => 'VARCHAR(80) NULL AFTER driver_name',
        'supplier_si_number' => 'VARCHAR(80) NULL AFTER dr_number',
        'is_replacement' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER supplier_si_number',
        'replaces_receipt_id' => 'INT NULL',
        'original_damage_qty' => 'INT NULL',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM purchase_receipts LIKE '$column'");
        if (!($check && $check->num_rows > 0)) {
            @$conn->query("ALTER TABLE purchase_receipts ADD COLUMN `$column` $definition");
        }
    }

    $receiptPricingColsAdded = false;
    foreach ([
        'quantity_good' => 'INT NOT NULL DEFAULT 0 AFTER quantity_received',
        'quantity_damaged' => 'INT NOT NULL DEFAULT 0 AFTER quantity_good',
        'quantity_missing' => 'INT NOT NULL DEFAULT 0 AFTER quantity_damaged',
        'quantity_accepted' => 'INT NOT NULL DEFAULT 0 AFTER quantity_missing',
        'quantity_return' => 'INT NOT NULL DEFAULT 0 AFTER quantity_accepted',
        'stocked_in_qty' => 'INT NOT NULL DEFAULT 0 AFTER quantity_return',
        'cost_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_price',
        'markup_percent' => 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER cost_price',
        'selling_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER markup_percent',
        'line_stock_status' => "VARCHAR(80) NOT NULL DEFAULT 'Received - Pending Markup' AFTER selling_price",
        'apply_markup' => 'TINYINT(1) NULL AFTER line_stock_status',
        'apply_tax' => 'TINYINT(1) NULL AFTER apply_markup',
        'tax_percent' => 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER apply_tax',
        'tax_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_percent',
        'pricing_status' => "VARCHAR(40) NULL AFTER tax_amount",
        'pricing_confirmed_by' => 'INT NULL AFTER pricing_status',
        'pricing_confirmed_at' => 'DATETIME NULL AFTER pricing_confirmed_by',
        'manufacturer_lot' => 'VARCHAR(80) NULL AFTER batch_number',
        'system_batch_number' => 'VARCHAR(80) NULL AFTER manufacturer_lot',
        'scanned_barcodes' => 'TEXT NULL AFTER remarks',
        'note_to_supplier' => 'TEXT NULL AFTER remarks',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE '$column'");
        if (!($check && $check->num_rows > 0)) {
            @$conn->query("ALTER TABLE purchase_receipt_items ADD COLUMN `$column` $definition");
            if ($column === 'apply_markup') {
                $receiptPricingColsAdded = true;
            }
        }
    }
    $itemReceivedAt = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE 'received_at'");
    if (!($itemReceivedAt && $itemReceivedAt->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_receipt_items ADD COLUMN received_at DATETIME NULL AFTER expiry_date");
        recvHasColumn($conn, 'purchase_receipt_items', 'received_at', true);
    }
    if (recvHasColumn($conn, 'purchase_receipt_items', 'received_at')) {
        @$conn->query("UPDATE purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            SET pri.received_at = COALESCE(rec.received_at, TIMESTAMP(rec.date_received))
            WHERE pri.received_at IS NULL");
    }
    $markupAmountCol = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE 'markup_amount'");
    if (!($markupAmountCol && $markupAmountCol->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_receipt_items ADD COLUMN markup_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER markup_percent");
        recvHasColumn($conn, 'purchase_receipt_items', 'markup_amount', true);
    }
    if ($receiptPricingColsAdded) {
        @$conn->query("UPDATE user_permissions up
            INNER JOIN users u ON u.id = up.user_id
            SET up.can_manage_pricing = 1
            WHERE LOWER(REPLACE(REPLACE(u.role,' ','_'),'-','_')) = 'purchaser'");
        @$conn->query("UPDATE user_permissions up
            INNER JOIN users u ON u.id = up.user_id
            SET up.allowed_sections = TRIM(BOTH ',' FROM CONCAT(IFNULL(up.allowed_sections,''), IF(IFNULL(up.allowed_sections,'')='','',','), 'markup-queue'))
            WHERE LOWER(REPLACE(REPLACE(u.role,' ','_'),'-','_')) = 'purchaser'
              AND IFNULL(up.allowed_sections,'') NOT LIKE '%markup-queue%'
              AND IFNULL(up.allowed_sections,'') NOT LIKE '%pricing-queue%'");
    }

    $sdrTbl = $conn->query("SHOW TABLES LIKE 'supplier_damage_returns'");
    if ($sdrTbl && $sdrTbl->num_rows > 0 && !recvHasColumn($conn, 'supplier_damage_returns', 'note_to_supplier')) {
        @$conn->query("ALTER TABLE supplier_damage_returns ADD COLUMN note_to_supplier TEXT NULL AFTER remarks");
        recvHasColumn($conn, 'supplier_damage_returns', 'note_to_supplier', true);
    }

    $lotCol = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturer_lot'");
    if (!($lotCol && $lotCol->num_rows > 0)) {
        @$conn->query("ALTER TABLE product_batches ADD COLUMN manufacturer_lot VARCHAR(80) NULL AFTER batch_number");
    }
    foreach ([
        'cost_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER manufacturer_lot',
        'markup_percent' => 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER cost_price',
        'selling_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER markup_percent',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM product_batches LIKE '$column'");
        if (!($check && $check->num_rows > 0)) {
            @$conn->query("ALTER TABLE product_batches ADD COLUMN `$column` $definition");
        }
    }

    $smTbl = $conn->query("SHOW TABLES LIKE 'stock_movements'");
    if ($smTbl && $smTbl->num_rows > 0) {
        foreach ([
            'receipt_item_id' => 'INT NULL AFTER product_id',
            'po_item_id' => 'INT NULL AFTER receipt_item_id',
            'po_number' => 'VARCHAR(100) NULL',
            'si_number' => 'VARCHAR(100) NULL',
            'dr_number' => 'VARCHAR(100) NULL',
            'source' => 'VARCHAR(50) NULL DEFAULT "manual"',
            'markup_percent' => 'DECIMAL(8,2) NULL DEFAULT NULL',
            'expiry_date' => 'DATE NULL DEFAULT NULL',
        ] as $column => $definition) {
            $check = $conn->query("SHOW COLUMNS FROM stock_movements LIKE '$column'");
            if (!($check && $check->num_rows > 0)) {
                @$conn->query("ALTER TABLE stock_movements ADD COLUMN `$column` $definition");
            }
        }
        $idx = $conn->query("SHOW INDEX FROM stock_movements WHERE Key_name='uq_sm_receipt_item_stock_in'");
        if (!($idx && $idx->num_rows > 0)) {
            @$conn->query("ALTER TABLE stock_movements ADD UNIQUE KEY uq_sm_receipt_item_stock_in (receipt_item_id, movement_type)");
        }
    }

    $priIdx = $conn->query("SHOW INDEX FROM purchase_receipt_items WHERE Key_name='uq_pri_receipt_po_item'");
    if (!($priIdx && $priIdx->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_receipt_items ADD UNIQUE KEY uq_pri_receipt_po_item (receipt_id, po_item_id)");
    }

    $psCol = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE 'pricing_status'");
    if ($psCol && $psCol->num_rows > 0) {
        @$conn->query("UPDATE purchase_receipt_items
            SET pricing_status='Confirmed',
                apply_markup=CASE WHEN COALESCE(markup_percent,0) > 0 THEN 1 ELSE 0 END,
                apply_tax=COALESCE(apply_tax, 0),
                tax_percent=COALESCE(tax_percent, 0),
                tax_amount=COALESCE(tax_amount, 0)
            WHERE (pricing_status IS NULL OR pricing_status='')
              AND line_stock_status IN ('Approved - Pending Stock-In','Granted - Pending Stock-In','Stocked In','Markup Ready - Pending Admin Approval','Ready for Stock-In')");
        @$conn->query("UPDATE purchase_receipt_items
            SET pricing_status='Waiting for Pricing'
            WHERE (pricing_status IS NULL OR pricing_status='')
              AND line_stock_status IN ('Received - Pending Markup','Pending Stock-In','Pending Markup','Pending for Stock-In')");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS supplier_damage_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        po_item_id INT NULL,
        receipt_id INT NULL,
        receipt_item_id INT NULL,
        product_id INT NULL,
        supplier_id INT NULL,
        quantity_ordered INT NOT NULL DEFAULT 0,
        quantity_received INT NOT NULL DEFAULT 0,
        quantity_damaged INT NOT NULL DEFAULT 0,
        quantity_returned INT NOT NULL DEFAULT 0,
        quantity_replaced INT NOT NULL DEFAULT 0,
        quantity_stocked INT NOT NULL DEFAULT 0,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        markup_percent DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(50) NOT NULL DEFAULT 'Pending Replacement',
        remarks TEXT NULL,
        reported_by INT NULL,
        returned_at DATETIME NULL,
        replacement_received_at DATETIME NULL,
        stocked_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sdr_po (po_id),
        INDEX idx_sdr_status (status),
        INDEX idx_sdr_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS stock_in_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_id INT NOT NULL,
        stock_in_number VARCHAR(50) NOT NULL,
        po_id INT NULL,
        po_number VARCHAR(50) NULL,
        supplier_id INT NULL,
        supplier_name VARCHAR(150) NULL,
        product_id INT NULL,
        product_name VARCHAR(255) NULL,
        quantity_added INT NOT NULL DEFAULT 0,
        cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        markup_percent DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        receiving_date DATE NULL,
        received_by INT NULL,
        markup_by INT NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sia_receipt (receipt_id),
        INDEX idx_sia_stock_in (stock_in_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migrate legacy workflow labels
    @$conn->query("UPDATE purchase_receipts SET workflow_status='Received - Pending Markup' WHERE workflow_status IN ('Pending Stock-In','Pending for Stock-In')");
    @$conn->query("UPDATE purchase_receipts SET workflow_status='Markup Ready - Pending Admin Approval' WHERE workflow_status='Ready for Stock-In'");
    @$conn->query("UPDATE purchase_receipt_items SET line_stock_status='Received - Pending Markup' WHERE line_stock_status IN ('Pending Stock-In','Pending for Stock-In')");
    @$conn->query("UPDATE purchase_receipt_items SET line_stock_status='Markup Ready - Pending Admin Approval' WHERE line_stock_status='Ready for Stock-In'");
    @$conn->query("UPDATE purchase_orders SET receiving_status='Pending Markup' WHERE receiving_status IN ('Pending for Stock-In','Pending Stock-In')");
    @$conn->query("UPDATE purchase_orders SET receiving_status='Pending Admin Approval' WHERE receiving_status='Ready for Stock-In'");

    $conn->query("UPDATE purchase_orders SET receiving_status='Expected to Arrive'
                  WHERE (receiving_status IS NULL OR receiving_status='')
                    AND status IN ('Draft','Submitted','Pending Approval','Approved','Sent','Supplier Confirmed','Waiting for Delivery')");

    $lotEntriesCol = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE 'lot_entries'");
    if (!($lotEntriesCol && $lotEntriesCol->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_receipt_items ADD COLUMN lot_entries TEXT NULL AFTER manufacturer_lot");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS purchase_receipt_item_lots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_item_id INT NOT NULL,
        receipt_id INT NOT NULL,
        po_id INT NULL,
        po_item_id INT NULL,
        product_id INT NULL,
        lot_number VARCHAR(80) NULL,
        lot_na TINYINT(1) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 0,
        expiry_date DATE NULL,
        no_expiry TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pril_item (receipt_item_id),
        INDEX idx_pril_receipt (receipt_id),
        INDEX idx_pril_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS purchase_receipt_inspection_barcodes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_id INT NOT NULL DEFAULT 0,
        receipt_item_id INT NOT NULL DEFAULT 0,
        po_id INT NULL,
        po_item_id INT NULL,
        product_id INT NULL,
        barcode_value VARCHAR(100) NOT NULL,
        barcode_key VARCHAR(100) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'Good',
        input_method VARCHAR(20) NOT NULL DEFAULT 'Manual',
        lot_number VARCHAR(80) NULL,
        expiry_date DATE NULL,
        recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_prib_barcode_key (barcode_key),
        INDEX idx_prib_receipt (receipt_id),
        INDEX idx_prib_item (receipt_item_id),
        INDEX idx_prib_po_item (po_item_id),
        INDEX idx_prib_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    recvRepairOpenReceiptPricing($conn);
    require_once __DIR__ . '/procurement_rts_lib.php';
    rtsEnsureSchema($conn);
}

function syncPoReceivingStatus(mysqli $conn, int $poId): void {
    if ($poId <= 0) return;
    $pendingMarkup = recvWfPendingMarkup();
    $pendingAdmin = recvWfPendingAdmin();
    $granted = recvWfGranted();
    $stocked = recvWfStockedIn();
    $incomplete = recvWfIncomplete();

    $totals = recvPoInspectTotals($conn, $poId);
    $hasAny = $conn->query("SELECT COUNT(*) c FROM purchase_receipts WHERE po_id=$poId");
    $a = $hasAny ? (int)$hasAny->fetch_assoc()['c'] : 0;
    if ((int)($totals['remaining'] ?? 0) > 0 && $a > 0) {
        $stmt = $conn->prepare("UPDATE purchase_orders SET receiving_status=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('si', $incomplete, $poId);
            $stmt->execute();
        }
        $conn->query("UPDATE purchase_orders SET status='Partially Received' WHERE id=$poId AND status NOT IN ('Draft','Cancelled','Pending Approval')");
        return;
    }
    if ((int)($totals['processed'] ?? 0) > 0 && (int)($totals['remaining'] ?? 0) <= 0) {
        $conn->query("UPDATE purchase_orders SET status='Received' WHERE id=$poId AND status NOT IN ('Draft','Cancelled','Pending Approval')");
    }

    $hasPending = $conn->query("SELECT COUNT(*) c FROM purchase_receipts WHERE po_id=$poId AND workflow_status IN ('$pendingMarkup','Pending Stock-In')");
    $hasReady = $conn->query("SELECT COUNT(*) c FROM purchase_receipts WHERE po_id=$poId AND workflow_status IN ('$pendingAdmin','Ready for Stock-In')");
    $hasGranted = $conn->query("SELECT COUNT(*) c FROM purchase_receipts WHERE po_id=$poId AND workflow_status IN ('$granted','Granted - Pending Stock-In')");
    $hasStocked = $conn->query("SELECT COUNT(*) c FROM purchase_receipts WHERE po_id=$poId AND workflow_status='$stocked'");
    $p = $hasPending ? (int)$hasPending->fetch_assoc()['c'] : 0;
    $r = $hasReady ? (int)$hasReady->fetch_assoc()['c'] : 0;
    $g = $hasGranted ? (int)$hasGranted->fetch_assoc()['c'] : 0;
    $s = $hasStocked ? (int)$hasStocked->fetch_assoc()['c'] : 0;

    if ($a === 0) {
        $status = 'Expected to Arrive';
    } elseif ($p > 0) {
        $status = 'Pending Markup';
    } elseif ($r > 0) {
        $status = 'Pending Admin Approval';
    } elseif ($g > 0) {
        $status = 'Approved - Pending Stock-In';
    } elseif ($s > 0) {
        $status = 'Stocked In';
    } else {
        $status = 'Received';
    }
    $stmt = $conn->prepare("UPDATE purchase_orders SET receiving_status=? WHERE id=?");
    if ($stmt) {
        $stmt->bind_param('si', $status, $poId);
        $stmt->execute();
    }
}

function recvHasColumn(mysqli $conn, string $table, string $column, bool $refresh = false): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if ($refresh) {
        unset($cache[$key]);
    }
    if (array_key_exists($key, $cache)) return $cache[$key];
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = @$conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    $cache[$key] = $r && $r->num_rows > 0;
    return $cache[$key];
}

function recvProductIsExpirable(mysqli $conn, int $productId): bool {
    if ($productId <= 0) return false;
    if (recvHasColumn($conn, 'products', 'expiry_tracking')) {
        $r = $conn->query("SELECT COALESCE(expiry_tracking, 0) AS et FROM products WHERE id=$productId LIMIT 1");
        if ($r && ($row = $r->fetch_assoc()) && (int)$row['et'] === 1) return true;
    }
    return false;
}

function recvSyncProductExpiryFromBatches(mysqli $conn, int $productId): void {
    if ($productId <= 0 || !recvHasColumn($conn, 'products', 'expiry_date')) return;
    $soonest = $conn->query("SELECT MIN(expiry_date) AS exp FROM product_batches
                             WHERE product_id=$productId AND expiry_date IS NOT NULL AND quantity_remaining > 0");
    $row = $soonest ? $soonest->fetch_assoc() : null;
    $exp = !empty($row['exp']) ? (string)$row['exp'] : null;
    if ($exp === null) return;
    $esc = $conn->real_escape_string($exp);
    $conn->query("UPDATE products SET expiry_date='$esc'
                  WHERE id=$productId AND (expiry_date IS NULL OR expiry_date='' OR expiry_date > '$esc')");
}

function recvValidateExpiryDate(?string $expiryDate, ?string $dateReceived, bool $required): ?string {
    $expiryDate = trim((string)$expiryDate);
    if ($expiryDate === '' || strcasecmp($expiryDate, 'N/A') === 0) {
        if ($required) {
            throw new RuntimeException('Expiry Date is required for this expirable product.');
        }
        return null;
    }
    $expTs = strtotime($expiryDate);
    if ($expTs === false) {
        throw new RuntimeException('Expiry Date is invalid.');
    }
    $exp = date('Y-m-d', $expTs);
    if ($dateReceived) {
        $recvTs = strtotime($dateReceived);
        if ($recvTs !== false && $exp < date('Y-m-d', $recvTs)) {
            throw new RuntimeException('Expiry Date cannot be earlier than Date Received.');
        }
    }
    return $exp;
}

function recvLotIsNotApplicable(string $lot): bool {
    $k = strtolower(trim($lot));
    return $k === '' || $k === 'n/a' || $k === 'na' || $k === 'not applicable';
}

function recvNormalizeLotEntries(array $item, int $qtyGood, string $headerLot = ''): array {
    $raw = $item['lots'] ?? $item['lot_entries'] ?? null;
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : null;
    }
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $lot) {
            if (!is_array($lot)) continue;
            $qty = (int)($lot['quantity'] ?? $lot['qty'] ?? 0);
            $lotNo = trim((string)($lot['lot_number'] ?? $lot['manufacturer_lot'] ?? ''));
            $na = !empty($lot['lot_na']) || !empty($lot['not_applicable']) || recvLotIsNotApplicable($lotNo);
            if ($na) $lotNo = 'N/A';
            $noExp = !empty($lot['no_expiry']) || !empty($lot['no_exp']);
            $expRaw = trim((string)($lot['expiry_date'] ?? ''));
            if ($noExp) $expRaw = '';
            if ($qty <= 0 && $lotNo === 'N/A' && $expRaw === '' && empty($lot['lot_number'])) continue;
            $out[] = [
                'lot_number' => $lotNo,
                'lot_na' => $na ? 1 : 0,
                'quantity' => max(0, $qty),
                'expiry_date' => $expRaw,
                'no_expiry' => $noExp ? 1 : 0,
            ];
        }
    }
    if (!$out && $qtyGood > 0) {
        $lotNo = trim((string)($item['manufacturer_lot'] ?? $item['lot_number'] ?? $headerLot));
        $na = !empty($item['lot_na']) || recvLotIsNotApplicable($lotNo);
        $noExp = !empty($item['no_expiry']);
        $expRaw = $noExp ? '' : trim((string)($item['expiry_date'] ?? ''));
        $out[] = [
            'lot_number' => $na ? 'N/A' : $lotNo,
            'lot_na' => $na ? 1 : 0,
            'quantity' => $qtyGood,
            'expiry_date' => $expRaw,
            'no_expiry' => ($noExp || $expRaw === '') ? 1 : 0,
        ];
    }
    $sum = 0;
    foreach ($out as $lot) $sum += (int)$lot['quantity'];
    if (count($out) === 1 && $qtyGood > 0 && $sum !== $qtyGood) {
        $out[0]['quantity'] = $qtyGood;
        $sum = $qtyGood;
    }
    if (count($out) > 1 && $qtyGood > 0 && $sum !== $qtyGood) {
        throw new RuntimeException('Combined lot quantities must equal the Good quantity.');
    }
    if ($sum > $qtyGood) {
        throw new RuntimeException('Total lot quantities cannot exceed the accepted Good quantity.');
    }
    foreach ($out as $i => $lot) {
        $qty = (int)$lot['quantity'];
        if ($qty <= 0) continue;
        if (trim((string)$lot['lot_number']) === '') {
            throw new RuntimeException('Each lot must have a Lot Number or be marked Not Applicable.');
        }
        $noExp = !empty($lot['no_expiry']);
        $expRaw = trim((string)($lot['expiry_date']));
        if (!$noExp && $expRaw === '') {
            throw new RuntimeException('Each lot needs an Expiry Date or No Expiry.');
        }
        if (!$noExp && $expRaw !== '') {
            $out[$i]['expiry_date'] = recvValidateExpiryDate($expRaw, null, false);
        } else {
            $out[$i]['expiry_date'] = null;
            $out[$i]['no_expiry'] = 1;
        }
    }
    return $out;
}

function recvSaveReceiptItemLots(mysqli $conn, int $receiptItemId, int $receiptId, int $poId, int $poItemId, int $productId, array $lots): void {
    if ($receiptItemId <= 0) return;
    $json = json_encode($lots);
    if (recvHasColumn($conn, 'purchase_receipt_items', 'lot_entries') && $json !== false) {
        $esc = $conn->real_escape_string($json);
        $conn->query("UPDATE purchase_receipt_items SET lot_entries='$esc' WHERE id=$receiptItemId");
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_item_lots'");
    if (!($tbl && $tbl->num_rows > 0)) return;
    $conn->query("DELETE FROM purchase_receipt_item_lots WHERE receipt_item_id=$receiptItemId");
    foreach ($lots as $lot) {
        $qty = (int)($lot['quantity'] ?? 0);
        if ($qty <= 0) continue;
        $lotNo = $conn->real_escape_string(substr(trim((string)($lot['lot_number'] ?? '')), 0, 80));
        $na = !empty($lot['lot_na']) ? 1 : 0;
        $noExp = !empty($lot['no_expiry']) ? 1 : 0;
        $expSql = !empty($lot['expiry_date']) ? "'" . $conn->real_escape_string((string)$lot['expiry_date']) . "'" : 'NULL';
        $conn->query("INSERT INTO purchase_receipt_item_lots (receipt_item_id, receipt_id, po_id, po_item_id, product_id, lot_number, lot_na, quantity, expiry_date, no_expiry) VALUES ($receiptItemId, $receiptId, $poId, $poItemId, $productId, '$lotNo', $na, $qty, $expSql, $noExp)");
    }
}

function recvLoadReceiptItemLots(mysqli $conn, int $receiptItemId, array $line = []): array {
    if ($receiptItemId > 0) {
        $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_item_lots'");
        if ($tbl && $tbl->num_rows > 0) {
            $res = $conn->query("SELECT lot_number, lot_na, quantity, expiry_date, no_expiry FROM purchase_receipt_item_lots WHERE receipt_item_id=" . (int)$receiptItemId . " ORDER BY id");
            $rows = [];
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'lot_number' => (string)($r['lot_number'] ?? ''),
                        'lot_na' => (int)($r['lot_na'] ?? 0),
                        'quantity' => (int)($r['quantity'] ?? 0),
                        'expiry_date' => $r['expiry_date'] ?: null,
                        'no_expiry' => (int)($r['no_expiry'] ?? 0),
                    ];
                }
            }
            if ($rows) return $rows;
        }
        if (recvHasColumn($conn, 'purchase_receipt_items', 'lot_entries')) {
            $q = $conn->query("SELECT lot_entries FROM purchase_receipt_items WHERE id=" . (int)$receiptItemId . " LIMIT 1");
            $row = $q ? $q->fetch_assoc() : null;
            if (!empty($row['lot_entries'])) {
                $decoded = json_decode((string)$row['lot_entries'], true);
                if (is_array($decoded) && $decoded) return $decoded;
            }
        }
    }
    $lotNo = trim((string)($line['manufacturer_lot'] ?? ''));
    $qty = (int)($line['quantity_good'] ?? 0);
    $exp = !empty($line['expiry_date']) ? (string)$line['expiry_date'] : null;
    if ($qty <= 0 && $lotNo === '' && $exp === null) return [];
    return [[
        'lot_number' => $lotNo !== '' ? $lotNo : 'N/A',
        'lot_na' => recvLotIsNotApplicable($lotNo) ? 1 : 0,
        'quantity' => $qty,
        'expiry_date' => $exp,
        'no_expiry' => $exp ? 0 : 1,
    ]];
}

function recvAttachLotsToReceiptItems(mysqli $conn, array $items): array {
    foreach ($items as &$item) {
        $item['lots'] = recvLoadReceiptItemLots($conn, (int)($item['id'] ?? 0), $item);
    }
    unset($item);
    return $items;
}

function recvInspectionBarcodeKey(string $code): string {
    return strtoupper(trim($code));
}

function recvInspectionStatusLabel(string $status): string {
    $s = trim($status);
    if ($s === 'Replacement') return 'Replacement';
    if (in_array($s, ['Damaged', 'Rejected', 'Damaged/Rejected', 'Damaged / Rejected'], true)) {
        return 'Damaged/Rejected';
    }
    return 'Good';
}

function recvInspectionMethodLabel(string $method): string {
    $method = trim($method);
    if (strcasecmp($method, 'Camera') === 0 || strcasecmp($method, 'Scanner') === 0 || strcasecmp($method, 'Barcode Scanner') === 0) {
        return 'Camera';
    }
    if (strcasecmp($method, 'Split') === 0 || strcasecmp($method, 'Split Barcode Entry') === 0) {
        return 'Split';
    }
    return 'Manual';
}

function recvInspectionIsSellable(string $status): bool {
    $s = recvInspectionStatusLabel($status);
    return $s === 'Good' || $s === 'Replacement';
}

/** @return array<int,array<string,mixed>> */
function recvNormalizeInspectionRecords($raw): array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $row) {
        $code = '';
        $status = 'Good';
        $method = 'Manual';
        $lot = '';
        $exp = '';
        $recorded = '';
        if (is_string($row) || is_numeric($row)) {
            $code = trim((string)$row);
        } elseif (is_array($row)) {
            $code = trim((string)($row['barcode'] ?? $row['barcode_value'] ?? $row['value'] ?? ''));
            $status = recvInspectionStatusLabel((string)($row['status'] ?? 'Good'));
            $method = recvInspectionMethodLabel((string)($row['input_method'] ?? $row['method'] ?? 'Manual'));
            $lot = trim((string)($row['lot_number'] ?? ''));
            $exp = trim((string)($row['expiry_date'] ?? ''));
            $recorded = trim((string)($row['recorded_at'] ?? ''));
        }
        $key = recvInspectionBarcodeKey($code);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = [
            'barcode' => $code,
            'barcode_key' => $key,
            'status' => $status,
            'input_method' => $method,
            'lot_number' => $lot,
            'expiry_date' => $exp,
            'recorded_at' => $recorded,
        ];
    }
    return $out;
}

/** @param array<int,array<string,mixed>> $records @return array<int,string> */
function recvSellableInspectionBarcodes(array $records): array {
    $out = [];
    foreach ($records as $row) {
        if (!recvInspectionIsSellable((string)($row['status'] ?? 'Good'))) {
            continue;
        }
        $code = trim((string)($row['barcode'] ?? ''));
        if ($code !== '') {
            $out[] = $code;
        }
    }
    return $out;
}

function recvFindIncompleteReceipt(mysqli $conn, int $poId): ?array {
    if ($poId <= 0) {
        return null;
    }
    $totals = recvPoInspectTotals($conn, $poId);
    if ((int)($totals['remaining'] ?? 0) <= 0) {
        return null;
    }
    $replFilter = recvHasColumn($conn, 'purchase_receipts', 'is_replacement')
        ? ' AND COALESCE(is_replacement,0)=0'
        : '';
    $q = $conn->query("SELECT * FROM purchase_receipts WHERE po_id=" . (int)$poId . $replFilter . " ORDER BY id DESC LIMIT 1");
    $row = $q ? $q->fetch_assoc() : null;
    return $row ?: null;
}

function recvFindInspectionBarcode(mysqli $conn, string $code): ?array {
    $key = recvInspectionBarcodeKey($code);
    if ($key === '') {
        return null;
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return null;
    }
    $st = $conn->prepare('SELECT * FROM purchase_receipt_inspection_barcodes WHERE barcode_key=? LIMIT 1');
    if (!$st) {
        return null;
    }
    $st->bind_param('s', $key);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * @return array{status:string,message:string,product_id?:int,product_name?:string,sku?:string,receipt_item_id?:int}
 */
function recvBarcodeIsCatalogIdentity(mysqli $conn, string $code, int $lineProductId = 0): bool
{
    require_once __DIR__ . '/product_barcodes_lib.php';
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    $key = strtoupper($code);
    $found = productBarcodesFind($conn, $code);
    if ($found) {
        $pid = (int)($found['product_id'] ?? 0);
        if ($lineProductId > 0 && $pid > 0 && $pid !== $lineProductId) {
            return false;
        }
        if (!empty($found['is_primary']) || empty($found['barcode_id'])) {
            return true;
        }
        if (strtoupper(trim((string)($found['product_barcode'] ?? ''))) === $key) {
            return true;
        }
    }
    if ($lineProductId > 0) {
        $st = $conn->prepare('SELECT barcode FROM products WHERE id=? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $lineProductId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row && strtoupper(trim((string)($row['barcode'] ?? ''))) === $key) {
                return true;
            }
        }
        return false;
    }
    $st = $conn->prepare('SELECT id FROM products WHERE UPPER(TRIM(barcode))=? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('s', $key);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
}

function recvSafeUserMessage(string $raw, string $fallback = 'Unable to process barcode. Please try scanning again.'): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return $fallback;
    }
    if (function_exists('recvIsTechnicalDbError') && recvIsTechnicalDbError($raw)) {
        error_log('Clarenzon receiving: ' . $raw);
        return $fallback;
    }
    if (preg_match('/PHP (Fatal|Warning)|SQLSTATE|Undefined (index|variable)|mysqli|Stack trace/i', $raw)) {
        error_log('Clarenzon receiving: ' . $raw);
        return $fallback;
    }
    return $raw;
}

function recvLookupUniqueItemBarcode(mysqli $conn, string $code, int $lineProductId = 0, bool $exact = false): array {
    require_once __DIR__ . '/product_barcodes_lib.php';
    productBarcodesEnsureSchema($conn);
    $code = trim($code);
    if ($code === '') {
        return ['status' => 'empty', 'message' => 'Barcode cannot be empty.', 'barcode' => ''];
    }
    if (!$exact) {
        $completed = productBarcodesCompleteRegisteredSuffix($conn, $code);
        if ($completed !== null && $completed !== '') {
            $code = $completed;
        }
    }
    $found = productBarcodesFind($conn, $code);
    $linkedId = $found ? (int)($found['product_id'] ?? 0) : 0;
    $payload = [
        'barcode' => $found ? (string)($found['barcode_value'] ?? $code) : $code,
        'product_id' => $linkedId,
        'product_name' => $found ? (string)($found['product_name'] ?? '') : '',
        'sku' => $found ? (string)($found['product_sku'] ?? $found['sku'] ?? '') : '',
    ];
    if ($found && $lineProductId > 0 && $linkedId > 0 && $linkedId !== $lineProductId) {
        $payload['status'] = 'conflict';
        $payload['message'] = 'This barcode belongs to another product.';
        $payload['conflict'] = [
            'product_id' => $linkedId,
            'product_name' => $payload['product_name'],
            'sku' => $payload['sku'],
        ];
        return $payload;
    }

    $isCatalog = recvBarcodeIsCatalogIdentity($conn, $code, $lineProductId > 0 ? $lineProductId : $linkedId);
    $insp = recvFindInspectionBarcode($conn, $code);

    if ($insp && !$isCatalog) {
        $pid = (int)($insp['product_id'] ?? $linkedId);
        if ($lineProductId > 0 && $pid > 0 && $pid !== $lineProductId) {
            $payload['status'] = 'conflict';
            $payload['message'] = 'This barcode belongs to another product.';
            $payload['product_id'] = $pid;
            $payload['conflict'] = [
                'product_id' => $pid,
                'product_name' => $payload['product_name'],
                'sku' => $payload['sku'],
            ];
            return $payload;
        }
        $payload['status'] = 'already_used';
        $payload['message'] = 'This barcode was already recorded.';
        $payload['product_id'] = $pid;
        $payload['receipt_item_id'] = (int)($insp['receipt_item_id'] ?? 0);
        return $payload;
    }

    if ($isCatalog || $found) {
        if ($payload['product_id'] <= 0 && $lineProductId > 0) {
            $payload['product_id'] = $lineProductId;
        }
        if ($payload['product_name'] === '' && $payload['product_id'] > 0) {
            $pq = $conn->query('SELECT name, sku FROM products WHERE id=' . (int)$payload['product_id'] . ' LIMIT 1');
            $pr = $pq ? $pq->fetch_assoc() : null;
            $payload['product_name'] = (string)($pr['name'] ?? '');
            $payload['sku'] = (string)($pr['sku'] ?? $payload['sku']);
        }
        $payload['status'] = 'catalog';
        $payload['message'] = 'Catalog barcode — quantity will increase.';
        $payload['receipt_item_id'] = $insp ? (int)($insp['receipt_item_id'] ?? 0) : 0;
        return $payload;
    }

    return [
        'status' => 'not_found',
        'barcode' => $code,
        'message' => 'New barcode.',
    ];
}

function recvAssertBarcodeFreeForItem(mysqli $conn, string $code, int $productId, int $receiptItemId): void {
    $look = recvLookupUniqueItemBarcode($conn, $code, $productId);
    $status = (string)($look['status'] ?? '');
    if ($status === 'empty') {
        throw new RuntimeException('Barcode cannot be empty.');
    }
    if ($status === 'conflict') {
        $label = (string)(($look['conflict']['product_name'] ?? $look['product_name'] ?? '') ?: 'another product');
        throw new RuntimeException('This barcode belongs to another product (' . $label . ').');
    }
    if ($status === 'catalog') {
        return;
    }
    if ($status === 'already_used') {
        $ownerItem = (int)($look['receipt_item_id'] ?? 0);
        if ($receiptItemId > 0 && $ownerItem === $receiptItemId) {
            return;
        }
        if ($receiptItemId > 0) {
            $mine = recvFindInspectionBarcode($conn, $code);
            if ($mine && (int)($mine['receipt_item_id'] ?? 0) === $receiptItemId) {
                return;
            }
        }
        throw new RuntimeException('This barcode was already used.');
    }
}

/** Sellable barcodes scanned onto these receipt lines, keyed by receipt item id. */
function recvInspectionBarcodeMap(mysqli $conn, array $receiptItemIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $receiptItemIds))));
    $map = [];
    if (!$ids) {
        return $map;
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return $map;
    }
    $res = $conn->query('SELECT receipt_item_id, barcode_value, status FROM purchase_receipt_inspection_barcodes WHERE receipt_item_id IN (' . implode(',', $ids) . ') ORDER BY id');
    if (!$res) {
        return $map;
    }
    while ($r = $res->fetch_assoc()) {
        if (!recvInspectionIsSellable((string)($r['status'] ?? 'Good'))) {
            continue;
        }
        $code = trim((string)($r['barcode_value'] ?? ''));
        if ($code === '') {
            continue;
        }
        $id = (int)$r['receipt_item_id'];
        if (!isset($map[$id])) {
            $map[$id] = [];
        }
        $map[$id][] = $code;
    }
    return $map;
}

function recvLoadInspectionBarcodes(mysqli $conn, int $receiptItemId): array {
    if ($receiptItemId <= 0) {
        return [];
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return [];
    }
    $res = $conn->query('SELECT barcode_value, status, input_method, lot_number, expiry_date, recorded_at
        FROM purchase_receipt_inspection_barcodes WHERE receipt_item_id=' . (int)$receiptItemId . ' ORDER BY id');
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'barcode' => (string)($r['barcode_value'] ?? ''),
                'status' => recvInspectionStatusLabel((string)($r['status'] ?? 'Good')),
                'input_method' => recvInspectionMethodLabel((string)($r['input_method'] ?? 'Manual')),
                'lot_number' => (string)($r['lot_number'] ?? ''),
                'expiry_date' => $r['expiry_date'] ?: '',
                'recorded_at' => (string)($r['recorded_at'] ?? ''),
            ];
        }
    }
    return $rows;
}

function recvAttachInspectionToReceiptItems(mysqli $conn, array $items): array {
    foreach ($items as &$item) {
        $item['inspected_barcodes'] = recvLoadInspectionBarcodes($conn, (int)($item['id'] ?? $item['receipt_item_id'] ?? 0));
        if (empty($item['inspected_barcodes']) && !empty($item['scanned_barcodes'])) {
            $item['inspected_barcodes'] = recvNormalizeInspectionRecords($item['scanned_barcodes']);
        }
        $item['barcode_values'] = recvSellableInspectionBarcodes($item['inspected_barcodes'] ?? []);
        if (empty($item['barcode_values']) && !empty($item['scanned_barcodes'])) {
            $item['barcode_values'] = recvSellableInspectionBarcodes(recvNormalizeInspectionRecords($item['scanned_barcodes']));
        }
        $item['scanned_barcodes'] = $item['barcode_values'];
    }
    unset($item);
    return $items;
}

function recvAttachLotsToInspectionRecords(array $records, array $lots): array {
    $sellableLots = [];
    foreach ($lots as $lot) {
        $qty = (int)($lot['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $sellableLots[] = $lot + ['_left' => $qty];
    }
    foreach ($records as $i => $row) {
        if (!recvInspectionIsSellable((string)($row['status'] ?? 'Good'))) {
            continue;
        }
        $hasLot = trim((string)($row['lot_number'] ?? '')) !== '';
        if ($hasLot) {
            continue;
        }
        foreach ($sellableLots as $j => $lot) {
            if ($lot['_left'] <= 0) {
                continue;
            }
            $records[$i]['lot_number'] = (string)($lot['lot_number'] ?? '');
            $records[$i]['expiry_date'] = !empty($lot['no_expiry']) ? '' : (string)($lot['expiry_date'] ?? '');
            $sellableLots[$j]['_left']--;
            break;
        }
    }
    return $records;
}

function recvInsertInspectionBarcode(mysqli $conn, int $receiptId, int $receiptItemId, int $poId, int $poItemId, int $productId, array $row): void {
    $code = trim((string)($row['barcode'] ?? ''));
    $key = recvInspectionBarcodeKey($code);
    if ($key === '') {
        return;
    }
    $status = recvInspectionStatusLabel((string)($row['status'] ?? 'Good'));
    $method = recvInspectionMethodLabel((string)($row['input_method'] ?? 'Manual'));
    $lot = substr(trim((string)($row['lot_number'] ?? '')), 0, 80);
    $exp = trim((string)($row['expiry_date'] ?? ''));
    $expVal = ($exp !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $exp)) ? substr($exp, 0, 10) : null;
    $recorded = trim((string)($row['recorded_at'] ?? ''));
    if ($recorded === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $recorded)) {
        $recorded = date('Y-m-d H:i:s');
    } else {
        $recorded = substr(str_replace('T', ' ', $recorded), 0, 19);
    }
    if ($expVal === null) {
        $st = $conn->prepare('INSERT INTO purchase_receipt_inspection_barcodes
            (receipt_id, receipt_item_id, po_id, po_item_id, product_id, barcode_value, barcode_key, status, input_method, lot_number, expiry_date, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param(
            'iiiiissssss',
            $receiptId,
            $receiptItemId,
            $poId,
            $poItemId,
            $productId,
            $code,
            $key,
            $status,
            $method,
            $lot,
            $recorded
        );
    } else {
        $st = $conn->prepare('INSERT INTO purchase_receipt_inspection_barcodes
            (receipt_id, receipt_item_id, po_id, po_item_id, product_id, barcode_value, barcode_key, status, input_method, lot_number, expiry_date, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param(
            'iiiiisssssss',
            $receiptId,
            $receiptItemId,
            $poId,
            $poItemId,
            $productId,
            $code,
            $key,
            $status,
            $method,
            $lot,
            $expVal,
            $recorded
        );
    }
    if (!$st->execute()) {
        if ((int)$conn->errno === 1062 || stripos((string)$st->error, 'Duplicate') !== false) {
            $st->close();
            $existing = recvFindInspectionBarcode($conn, $code);
            if ($existing && (int)($existing['receipt_item_id'] ?? 0) === $receiptItemId) {
                return;
            }
            throw new RuntimeException('This barcode was already used.');
        }
        $err = $st->error ?: $conn->error;
        $st->close();
        throw new RuntimeException($err);
    }
    $st->close();
}

function recvSaveInspectionBarcodes(mysqli $conn, int $receiptId, int $receiptItemId, int $poId, int $poItemId, int $productId, array $records): void {
    if ($receiptItemId <= 0) {
        return;
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return;
    }
    foreach ($records as $row) {
        recvInsertInspectionBarcode($conn, $receiptId, $receiptItemId, $poId, $poItemId, $productId, $row);
    }
}

function recvHydratePoItemsForContinueReceive(mysqli $conn, array $poItems, int $poId): array {
    $open = recvFindIncompleteReceipt($conn, $poId);
    if (!$open) {
        return $poItems;
    }
    $rid = (int)$open['id'];
    $map = [];
    $ri = $conn->query('SELECT * FROM purchase_receipt_items WHERE receipt_id=' . $rid . ' ORDER BY id');
    if ($ri) {
        while ($row = $ri->fetch_assoc()) {
            $key = (int)($row['po_item_id'] ?? 0);
            if ($key > 0) {
                $map[$key] = $row;
            }
        }
    }
    foreach ($poItems as &$item) {
        $poiId = (int)($item['id'] ?? 0);
        $remaining = (int)($item['quantity_remaining'] ?? 0);
        if ($remaining <= 0) {
            $item['continue_receiving'] = 0;
            continue;
        }
        $item['continue_receiving'] = 1;
        $item['continue_remaining_only'] = 1;
        if ($poiId > 0 && !empty($map[$poiId])) {
            $saved = $map[$poiId];
            $item['receipt_item_id'] = (int)$saved['id'];
            $item['quantity_good_previous'] = (int)($saved['quantity_good'] ?? 0);
            $item['quantity_damaged_previous'] = (int)($saved['quantity_damaged'] ?? 0);
            $item['quantity_missing_previous'] = (int)($saved['quantity_missing'] ?? 0);
        }
        $item['inspected_barcodes'] = [];
        $item['lots'] = [];
    }
    unset($item);
    return $poItems;
}

function recvUpsertProductBatch(mysqli $conn, int $productId, string $batchNum, int $qty, int $supplierId, ?string $dateReceived, ?string $expDate, string $mfrLot, float $cost, float $markup, float $sell): int {
    if ($productId <= 0 || $batchNum === '' || $qty <= 0) return 0;
    $batchId = 0;
    $checkBatch = $conn->prepare("SELECT id FROM product_batches WHERE product_id=? AND batch_number=? LIMIT 1");
    if ($checkBatch) {
        $checkBatch->bind_param('is', $productId, $batchNum);
        $checkBatch->execute();
        $bRes = $checkBatch->get_result()->fetch_assoc();
        $checkBatch->close();
        if ($bRes) {
            $batchId = (int)$bRes['id'];
            $conn->query("UPDATE product_batches SET quantity = quantity + $qty, quantity_remaining = quantity_remaining + $qty WHERE id = $batchId");
        } else {
            $hasReceivedDate = recvHasColumn($conn, 'product_batches', 'received_date');
            $dateReceived = $dateReceived ?: date('Y-m-d');
            if ($hasReceivedDate) {
                $insBatch = $conn->prepare("INSERT INTO product_batches (product_id, batch_number, expiry_date, quantity, quantity_remaining, supplier_id, received_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
                if ($insBatch) {
                    $insBatch->bind_param('issiiis', $productId, $batchNum, $expDate, $qty, $qty, $supplierId, $dateReceived);
                    if ($insBatch->execute()) $batchId = (int)$conn->insert_id;
                }
            } else {
                $insBatch = $conn->prepare("INSERT INTO product_batches (product_id, batch_number, expiry_date, quantity, quantity_remaining, supplier_id, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                if ($insBatch) {
                    $insBatch->bind_param('issiii', $productId, $batchNum, $expDate, $qty, $qty, $supplierId);
                    if ($insBatch->execute()) $batchId = (int)$conn->insert_id;
                }
            }
        }
    }
    if ($batchId > 0) {
        $sets = [];
        if ($expDate) {
            $sets[] = "expiry_date='" . $conn->real_escape_string((string)$expDate) . "'";
        }
        if ($mfrLot !== '' && recvHasColumn($conn, 'product_batches', 'manufacturer_lot')) {
            $sets[] = "manufacturer_lot='" . $conn->real_escape_string($mfrLot) . "'";
        }
        if (recvHasColumn($conn, 'product_batches', 'cost_price')) {
            $sets[] = 'cost_price=' . number_format($cost, 2, '.', '');
        }
        if (recvHasColumn($conn, 'product_batches', 'markup_percent')) {
            $sets[] = 'markup_percent=' . number_format($markup, 2, '.', '');
        }
        if (recvHasColumn($conn, 'product_batches', 'selling_price')) {
            $sets[] = 'selling_price=' . number_format($sell, 2, '.', '');
        }
        if ($sets) {
            $conn->query("UPDATE product_batches SET " . implode(', ', $sets) . " WHERE id=$batchId");
        }
    }
    return $batchId;
}

/**
 * Replacement stock-in only: same Lot Number adds quantity to the existing Product + Lot row.
 * A different Lot Number keeps the normal new-lot insert. Original receipt lines are not updated.
 */
function recvCreditReplacementLotOrNewBatch(mysqli $conn, int $productId, string $batchNum, int $qty, int $supplierId, ?string $dateReceived, ?string $expDate, string $mfrLot, float $cost, float $markup, float $sell, bool $replacement, string $originalLot): int {
    if ($replacement && $qty > 0 && recvLotNumbersMatch($mfrLot, $originalLot)) {
        $existingId = recvFindProductLotBatchId($conn, $productId, $originalLot);
        if ($existingId > 0) {
            if (!$conn->query('UPDATE product_batches SET quantity = quantity + ' . (int)$qty . ', quantity_remaining = quantity_remaining + ' . (int)$qty . ' WHERE id=' . $existingId)) {
                throw new RuntimeException($conn->error ?: 'Failed to merge replacement quantity into the existing lot');
            }
            return $existingId;
        }
    }
    return recvUpsertProductBatch($conn, $productId, $batchNum, $qty, $supplierId, $dateReceived, $expDate, $mfrLot, $cost, $markup, $sell);
}

function creditGoodStockFromReceiptLine(mysqli $conn, array $line, string $ref, int $uid, int $supplierId, ?string $dateReceived): int {
    $productId = (int)($line['product_id'] ?? 0);
    $lineId = (int)($line['id'] ?? 0);
    $targetGood = (int)($line['quantity_good'] ?? 0);
    $goodQty = $targetGood - (int)($line['stocked_in_qty'] ?? 0);
    if ($productId <= 0 || $goodQty <= 0) return 0;

    $existingMvId = 0;
    $alreadyMoved = 0;
    if ($lineId > 0 && recvHasColumn($conn, 'stock_movements', 'receipt_item_id')) {
        $dup = $conn->query("SELECT id, quantity FROM stock_movements WHERE receipt_item_id=$lineId AND movement_type='Stock In (Receipt)' LIMIT 1");
        if ($dup && ($mvRow = $dup->fetch_assoc())) {
            $existingMvId = (int)$mvRow['id'];
            $alreadyMoved = (int)$mvRow['quantity'];
            if ($alreadyMoved >= $targetGood) {
                throw new RuntimeException('This received item was already stocked in. Duplicate Stock In is not allowed.');
            }
            $remainAfterMove = $targetGood - $alreadyMoved;
            if ($remainAfterMove < $goodQty) {
                $goodQty = $remainAfterMove;
            }
            if ($goodQty <= 0) {
                throw new RuntimeException('This received item was already stocked in. Duplicate Stock In is not allowed.');
            }
        }
    }

    require_once __DIR__ . '/inventory_wms_lib.php';
    invWmsEnsureSchema($conn);
    $locId = invWmsResolveProductLocation($conn, $productId, null);
    if (!invWmsAdjustLocationStock($conn, $productId, $locId, $goodQty)) {
        throw new RuntimeException('Failed to credit location stock for good received qty');
    }

    $cost = (float)($line['cost_price'] ?? $line['unit_price'] ?? 0);
    $sell = (float)($line['selling_price'] ?? 0);
    $markup = (float)($line['markup_percent'] ?? 0);
    if ($markup < 0) throw new RuntimeException('Markup cannot be negative');
    require_once __DIR__ . '/product_pricing_lib.php';
    if ($sell <= 0 && $cost >= 0) {
        $sell = calcSellingPriceFromMarkup($cost, $markup);
    }
    $tot = $goodQty * $cost;
    $note = 'Stock-In (good qty only) from ' . $ref;
    if ($markup > 0 || $sell > 0) {
        $note .= sprintf(' | cost=%.2f markup=%.2f%% sell=%.2f', $cost, $markup, $sell);
    }

    $batchNum = trim((string)($line['system_batch_number'] ?? ''));
    if ($batchNum === '') $batchNum = trim((string)($line['batch_number'] ?? ''));
    $expDate = !empty($line['expiry_date']) ? $line['expiry_date'] : null;
    $mfrLot = trim((string)($line['manufacturer_lot'] ?? ''));
    if ($batchNum === '') {
        $sku = '';
        $skuQ = $conn->query("SELECT sku FROM products WHERE id=$productId LIMIT 1");
        if ($skuQ && ($skuRow = $skuQ->fetch_assoc())) $sku = (string)$skuRow['sku'];
        $batchNum = generateUniqueBatchNumber($conn, $productId, $sku, $dateReceived);
        if ($lineId > 0) {
            $bn = $conn->real_escape_string($batchNum);
            $conn->query("UPDATE purchase_receipt_items SET batch_number='$bn', system_batch_number='$bn' WHERE id=$lineId");
        }
    }

    $lots = recvLoadReceiptItemLots($conn, $lineId, $line);
    if (!$lots) {
        $lots = [[
            'lot_number' => $mfrLot,
            'quantity' => $goodQty,
            'expiry_date' => $expDate,
            'no_expiry' => $expDate ? 0 : 1,
        ]];
    }
    $receiptId = (int)($line['receipt_id'] ?? 0);
    $isReplacement = recvReceiptIsReplacement($conn, $receiptId);
    $originalLot = '';
    if ($isReplacement) {
        $poId = (int)($line['po_id'] ?? 0);
        if ($poId <= 0 && $receiptId > 0) {
            $poQ = @$conn->query('SELECT po_id FROM purchase_receipts WHERE id=' . $receiptId . ' LIMIT 1');
            $poRow = $poQ ? $poQ->fetch_assoc() : null;
            $poId = (int)($poRow['po_id'] ?? 0);
        }
        $originalLot = recvOriginalItemLotNumber($conn, $poId, (int)($line['po_item_id'] ?? 0));
    }
    $batchId = 0;
    $remainingGood = $goodQty;
    foreach ($lots as $i => $lot) {
        if ($remainingGood <= 0) break;
        $lotQty = (int)($lot['quantity'] ?? 0);
        if ($lotQty <= 0) continue;
        if ($lotQty > $remainingGood) $lotQty = $remainingGood;
        $thisBatch = ($i === 0 && $batchNum !== '') ? $batchNum : generateUniqueBatchNumber($conn, $productId, '', $dateReceived);
        $thisExp = !empty($lot['no_expiry']) ? null : (!empty($lot['expiry_date']) ? (string)$lot['expiry_date'] : $expDate);
        $thisLot = trim((string)($lot['lot_number'] ?? $mfrLot));
        $createdId = recvCreditReplacementLotOrNewBatch($conn, $productId, $thisBatch, $lotQty, $supplierId, $dateReceived, $thisExp, $thisLot, $cost, $markup, $sell, $isReplacement, $originalLot);
        if ($batchId <= 0) $batchId = $createdId;
        $remainingGood -= $lotQty;
    }
    if ($remainingGood > 0) {
        $fallbackBatch = $batchNum !== '' ? $batchNum : generateUniqueBatchNumber($conn, $productId, '', $dateReceived);
        $createdId = recvCreditReplacementLotOrNewBatch($conn, $productId, $fallbackBatch, $remainingGood, $supplierId, $dateReceived, $expDate, $mfrLot, $cost, $markup, $sell, $isReplacement, $originalLot);
        if ($batchId <= 0) $batchId = $createdId;
    }

    $poNumber = trim((string)($line['po_number'] ?? ''));
    $poItemId = (int)($line['po_item_id'] ?? 0);
    $source = 'admin_stock_in';
    $hasBatchCol = recvHasColumn($conn, 'stock_movements', 'batch_id');
    $hasReceiptItemCol = recvHasColumn($conn, 'stock_movements', 'receipt_item_id');
    $hasPoNumCol = recvHasColumn($conn, 'stock_movements', 'po_number');
    $hasSourceCol = recvHasColumn($conn, 'stock_movements', 'source');
    $hasPoItemCol = recvHasColumn($conn, 'stock_movements', 'po_item_id');
    $hasMarkupCol = recvHasColumn($conn, 'stock_movements', 'markup_percent');
    $hasExpiryCol = recvHasColumn($conn, 'stock_movements', 'expiry_date');

    $cols = ['product_id', 'movement_type', 'quantity', 'unit_price', 'total_value', 'to_location_id', 'reference_number', 'user_id', 'notes'];
    $vals = ['?', "'Stock In (Receipt)'", '?', '?', '?', '?', '?', '?', '?'];
    if ($hasBatchCol && $batchId > 0) {
        $cols[] = 'batch_id';
        $vals[] = '?';
    }
    if ($hasReceiptItemCol && $lineId > 0) {
        $cols[] = 'receipt_item_id';
        $vals[] = '?';
    }
    if ($hasPoItemCol && $poItemId > 0) {
        $cols[] = 'po_item_id';
        $vals[] = '?';
    }
    if ($hasPoNumCol && $poNumber !== '') {
        $cols[] = 'po_number';
        $vals[] = '?';
    }
    if ($hasSourceCol) {
        $cols[] = 'source';
        $vals[] = '?';
    }
    if ($hasMarkupCol) {
        $cols[] = 'markup_percent';
        $vals[] = '?';
    }
    if ($hasExpiryCol && $expDate !== null) {
        $cols[] = 'expiry_date';
        $vals[] = '?';
    }

    if ($existingMvId > 0) {
        $newQty = $alreadyMoved + $goodQty;
        $newTot = $newQty * $cost;
        $updMv = $conn->prepare('UPDATE stock_movements SET quantity=?, total_value=?, reference_number=?, notes=? WHERE id=?');
        if (!$updMv) throw new RuntimeException($conn->error);
        $updMv->bind_param('idssi', $newQty, $newTot, $ref, $note, $existingMvId);
        if (!$updMv->execute()) {
            throw new RuntimeException($updMv->error ?: $conn->error);
        }
        $updMv->close();
    } else {
        $sql = 'INSERT INTO stock_movements (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
        $mv = $conn->prepare($sql);
        if (!$mv) throw new RuntimeException($conn->error);
        $typeStr = 'iiddisis';
        $params = [$productId, $goodQty, $cost, $tot, $locId, $ref, $uid, $note];
        if ($hasBatchCol && $batchId > 0) { $typeStr .= 'i'; $params[] = $batchId; }
        if ($hasReceiptItemCol && $lineId > 0) { $typeStr .= 'i'; $params[] = $lineId; }
        if ($hasPoItemCol && $poItemId > 0) { $typeStr .= 'i'; $params[] = $poItemId; }
        if ($hasPoNumCol && $poNumber !== '') { $typeStr .= 's'; $params[] = $poNumber; }
        if ($hasSourceCol) { $typeStr .= 's'; $params[] = $source; }
        if ($hasMarkupCol) { $typeStr .= 'd'; $params[] = $markup; }
        if ($hasExpiryCol && $expDate !== null) { $typeStr .= 's'; $params[] = $expDate; }
        $mv->bind_param($typeStr, ...$params);
        if (!$mv->execute()) {
            $err = $mv->error ?: $conn->error;
            $mv->close();
            if (stripos($err, 'duplicate') !== false) {
                throw new RuntimeException('This received item was already stocked in. Duplicate Stock In is not allowed.');
            }
            throw new RuntimeException($err);
        }
        $mv->close();
    }

    if ($sell > 0) {
        ensureProductPricingSchema($conn);
        if ($markup <= 0 && $cost > 0) {
            $markup = calcMarkupPercentFromPrices($cost, $sell);
        }
        $up = $conn->prepare("UPDATE products SET selling_price=?, unit_price=?, markup_percent=?, selling_price_updated_at=NOW(), selling_price_updated_by=? WHERE id=?");
        if ($up) {
            $up->bind_param('dddii', $sell, $sell, $markup, $uid, $productId);
            $up->execute();
            $up->close();
        }
    }
    $pc = $conn->query("SELECT cost_price FROM products WHERE id=$productId")->fetch_assoc();
    if ($pc && (float)$pc['cost_price'] <= 0 && $cost > 0) {
        $conn->query("UPDATE products SET cost_price=$cost WHERE id=$productId");
    }

    recvSyncProductExpiryFromBatches($conn, $productId);

    return $goodQty;
}

/** Catalog purchase / markup / selling for a receipt line. Selling never stays 0. */
function recvResolveLinePricing(mysqli $conn, int $productId, float $poUnitPrice): array {
    require_once __DIR__ . '/product_pricing_lib.php';
    $cost = round($poUnitPrice, 2);
    $sell = 0.0;
    $markup = 0.0;
    if ($productId > 0) {
        $pq = $conn->query("SELECT cost_price, selling_price, unit_price, COALESCE(markup_percent,0) AS markup_percent FROM products WHERE id=$productId LIMIT 1");
        $p = $pq ? $pq->fetch_assoc() : null;
        if ($p) {
            if ($cost <= 0) {
                $cost = (float)$p['cost_price'] > 0 ? (float)$p['cost_price'] : (float)$p['unit_price'];
            }
            $sell = (float)$p['selling_price'] > 0 ? (float)$p['selling_price'] : (float)$p['unit_price'];
            $markup = (float)$p['markup_percent'];
        }
    }
    if ($sell <= 0) $sell = $cost;
    if ($cost < 0) $cost = 0;
    if ($sell > 0 && $cost > 0 && abs($markup) < 0.0001) {
        $markup = calcMarkupPercentFromPrices($cost, $sell);
    }
    return [
        'cost_price' => round($cost, 2),
        'markup_percent' => round($markup, 2),
        'selling_price' => round($sell, 2),
    ];
}

function recvBackfillReceiptLinePricing(mysqli $conn, int $receiptId): void {
    if ($receiptId <= 0) return;
    $items = $conn->query("SELECT id, product_id, unit_price, cost_price, selling_price, quantity_good FROM purchase_receipt_items WHERE receipt_id=$receiptId");
    if (!$items) return;
    while ($line = $items->fetch_assoc()) {
        if ((int)$line['quantity_good'] <= 0) continue;
        if ((float)$line['selling_price'] > 0) continue;
        $priced = recvResolveLinePricing($conn, (int)$line['product_id'], (float)($line['cost_price'] > 0 ? $line['cost_price'] : $line['unit_price']));
        $lid = (int)$line['id'];
        $stmt = $conn->prepare("UPDATE purchase_receipt_items SET cost_price=?, markup_percent=?, selling_price=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('dddi', $priced['cost_price'], $priced['markup_percent'], $priced['selling_price'], $lid);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/**
 * Catalog selling prices are not copied onto pending-markup receipts.
 * Purchaser confirmation after Goods Received is the final markup source.
 */
function recvRepairOpenReceiptPricing(mysqli $conn): void {
    return;
}

/**
 * Credit good qty, write stock-in number, mark Stocked In.
 * Caller must already hold a transaction and a locked receipt row.
 */
function recvCompleteStockIn(mysqli $conn, array $rec, int $uid): array {
    $receiptId = (int)($rec['id'] ?? 0);
    if ($receiptId <= 0) throw new RuntimeException('Receipt not found');
    if (!recvReceiptHasReadyStockInLine($conn, $receiptId)) {
        throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before Stock-In.');
    }

    $stockInNumber = '';
    $unique = false;
    while (!$unique) {
        $stockInNumber = 'SI-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $chk = $conn->query("SELECT id FROM purchase_receipts WHERE stock_in_number='" . $conn->real_escape_string($stockInNumber) . "' LIMIT 1");
        if (!$chk || $chk->num_rows === 0) $unique = true;
    }

    $items = $conn->query("SELECT * FROM purchase_receipt_items WHERE receipt_id=$receiptId FOR UPDATE");
    $stockedTotal = 0;
    $ref = $stockInNumber . '/' . $rec['receipt_number'] . '/' . $rec['po_number'];
    $approvalRows = [];
    while ($line = $items->fetch_assoc()) {
        $pendingGood = (int)$line['quantity_good'] - (int)$line['stocked_in_qty'];
        if ($pendingGood <= 0) continue;
        if (!recvLineReadyForWarehouseStockIn($line)) {
            continue;
        }
        if ((float)$line['selling_price'] < 0) {
            throw new RuntimeException('Reference selling price cannot be negative for ' . $line['product_name']);
        }
        $line['po_number'] = (string)($rec['po_number'] ?? '');
        $added = creditGoodStockFromReceiptLine($conn, $line, $ref, $uid, (int)$rec['supplier_id'], $rec['date_received']);
        $newStocked = (int)$line['stocked_in_qty'] + $added;
        $lid = (int)$line['id'];
        $stockedStatus = recvWfStockedIn();
        $conn->query("UPDATE purchase_receipt_items SET stocked_in_qty=$newStocked, line_stock_status='" . $conn->real_escape_string($stockedStatus) . "' WHERE id=$lid");
        $stockedTotal += $added;
        $approvalRows[] = [
            'product_id' => (int)$line['product_id'],
            'product_name' => $line['product_name'],
            'quantity_added' => $added,
            'cost_price' => (float)($line['cost_price'] ?? $line['unit_price'] ?? 0),
            'markup_percent' => (float)($line['markup_percent'] ?? 0),
            'selling_price' => (float)($line['selling_price'] ?? 0),
        ];
    }

    if ($stockedTotal <= 0) {
        throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before Stock-In.');
    }

    $remainExpr = recvSqlReceiptItemRemainingExpr($conn);
    $stillInc = $conn->query("SELECT COUNT(*) c FROM purchase_receipt_items WHERE receipt_id=$receiptId AND $remainExpr > 0");
    $incLeft = $stillInc ? (int)$stillInc->fetch_assoc()['c'] : 0;
    $stillPricing = recvReceiptHasPendingPurchaserMarkup($conn, $receiptId);
    $stockedStatus = $stillPricing
        ? recvWfPendingMarkup()
        : ($incLeft > 0 ? recvWfIncomplete() : recvWfStockedIn());
    if ($stillPricing) {
        $pendEsc = $conn->real_escape_string($stockedStatus);
        if (!$conn->query("UPDATE purchase_receipts SET workflow_status='$pendEsc', markup_by=COALESCE(markup_by,$uid), markup_at=COALESCE(markup_at,NOW()) WHERE id=$receiptId")) {
            throw new RuntimeException($conn->error);
        }
    } elseif ($incLeft > 0) {
        $incEsc = $conn->real_escape_string($stockedStatus);
        if (!$conn->query("UPDATE purchase_receipts SET workflow_status='$incEsc', approved_by=COALESCE(approved_by,$uid), approved_at=COALESCE(approved_at,NOW()) WHERE id=$receiptId")) {
            throw new RuntimeException($conn->error);
        }
    } elseif (trim((string)($rec['stock_in_number'] ?? '')) !== '') {
        $doneEsc = $conn->real_escape_string($stockedStatus);
        if (!$conn->query("UPDATE purchase_receipts SET workflow_status='$doneEsc' WHERE id=$receiptId")) {
            throw new RuntimeException($conn->error);
        }
    } else {
        $updRec = $conn->prepare("UPDATE purchase_receipts SET workflow_status=?, stock_in_number=?, approved_by=COALESCE(approved_by,?), approved_at=COALESCE(approved_at,NOW()) WHERE id=? AND (stock_in_number IS NULL OR stock_in_number='')");
        if (!$updRec) throw new RuntimeException($conn->error);
        $updRec->bind_param('ssii', $stockedStatus, $stockInNumber, $uid, $receiptId);
        if (!$updRec->execute() || $updRec->affected_rows < 1) {
            $updRec->close();
            throw new RuntimeException('Stock-In already processed or receipt status changed. Duplicate prevented.');
        }
        $updRec->close();
    }

    $insA = $conn->prepare("INSERT INTO stock_in_approvals (receipt_id, stock_in_number, po_id, po_number, supplier_id, supplier_name, product_id, product_name, quantity_added, cost_price, markup_percent, selling_price, receiving_date, received_by, markup_by, approved_by, approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    foreach ($approvalRows as $ar) {
        $poId = (int)$rec['po_id'];
        $poNumber = (string)$rec['po_number'];
        $supplierId = (int)$rec['supplier_id'];
        $supplierName = (string)($rec['supplier_name'] ?? '');
        $pid = (int)$ar['product_id'];
        $pname = (string)$ar['product_name'];
        $qty = (int)$ar['quantity_added'];
        $cost = (float)$ar['cost_price'];
        $mk = (float)$ar['markup_percent'];
        $sell = (float)$ar['selling_price'];
        $recvDate = $rec['date_received'] ?? null;
        $recvBy = (int)($rec['received_by'] ?? 0);
        $mkBy = (int)($rec['markup_by'] ?? 0);
        $approvedBy = (int)($rec['approved_by'] ?? $uid);
        $insA->bind_param(
            'isisisisidddsiii',
            $receiptId,
            $stockInNumber,
            $poId,
            $poNumber,
            $supplierId,
            $supplierName,
            $pid,
            $pname,
            $qty,
            $cost,
            $mk,
            $sell,
            $recvDate,
            $recvBy,
            $mkBy,
            $approvedBy
        );
        $insA->execute();
    }
    $insA->close();

    syncPoReceivingStatus($conn, (int)$rec['po_id']);
    require_once __DIR__ . '/procurement_rts_lib.php';
    rtsMarkCompletedIfStocked($conn, $receiptId);
    return [
        'stocked_qty' => $stockedTotal,
        'stock_in_number' => $stockInNumber,
        'workflow_status' => $stockedStatus,
    ];
}

function recvLoadReceiptForStockIn(mysqli $conn, int $receiptId): ?array {
    $st = $conn->prepare("SELECT rec.*, po.po_number, s.name AS supplier_name
                          FROM purchase_receipts rec
                          JOIN purchase_orders po ON po.id = rec.po_id
                          LEFT JOIN suppliers s ON s.id = rec.supplier_id
                          WHERE rec.id=?");
    if (!$st) return null;
    $st->bind_param('i', $receiptId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function recvAssignStockInNumber(mysqli $conn): string {
    $stockInNumber = '';
    $unique = false;
    while (!$unique) {
        $stockInNumber = 'SI-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $chk = $conn->query("SELECT id FROM purchase_receipts WHERE stock_in_number='" . $conn->real_escape_string($stockInNumber) . "' LIMIT 1");
        if (!$chk || $chk->num_rows === 0) $unique = true;
    }
    return $stockInNumber;
}

/**
 * Stock a single received PO item. Inventory increases only here for that line.
 * Caller must hold a transaction.
 */
function recvCompleteStockInItem(mysqli $conn, int $receiptItemId, int $uid, float $markup, ?string $expiryDate): array {
    if ($receiptItemId <= 0) throw new RuntimeException('Received item is required');
    if ($markup < 0) throw new RuntimeException('Markup cannot be negative');

    $sql = "SELECT pri.*, rec.id AS receipt_id, rec.po_id, rec.receipt_number, rec.date_received,
                   rec.supplier_id, rec.received_by, rec.markup_by, rec.approved_by, rec.stock_in_number,
                   rec.workflow_status, rec.dr_number, po.po_number, s.name AS supplier_name
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            INNER JOIN purchase_orders po ON po.id = rec.po_id
            LEFT JOIN suppliers s ON s.id = rec.supplier_id
            WHERE pri.id=? FOR UPDATE";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException($conn->error);
    $st->bind_param('i', $receiptItemId);
    $st->execute();
    $line = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$line) throw new RuntimeException('Received item not found');

    $wf = recvNormalizeWorkflowStatus($line['workflow_status'] ?? '');
    $lineWf = recvNormalizeWorkflowStatus($line['line_stock_status'] ?? '');
    if ($wf === recvWfStockedIn() || $lineWf === recvWfStockedIn()) {
        throw new RuntimeException('This received item was already stocked in. Duplicate Stock In is not allowed.');
    }
    if (recvLineIsIncompleteDelivery($line) || $lineWf === recvWfIncomplete()) {
        throw new RuntimeException('Unprocessed remaining quantity cannot be stocked in. Replacement is handled separately.');
    }
    if (!recvLineReadyForWarehouseStockIn($line)) {
        throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before Stock-In.');
    }
    $markup = (float)$line['markup_percent'];

    $pendingGood = (int)$line['quantity_good'] - (int)$line['stocked_in_qty'];
    if ($pendingGood <= 0) {
        throw new RuntimeException('No good quantity available to stock in (replacement excluded).');
    }

    $cost = (float)(($line['cost_price'] > 0) ? $line['cost_price'] : $line['unit_price']);
    if ($cost < 0) throw new RuntimeException('Purchase price cannot be negative');
    $sell = (float)($line['selling_price'] ?? 0);
    if ($sell < 0) throw new RuntimeException('Reference selling price cannot be negative');

    $productId = (int)$line['product_id'];
    $requiredExp = recvProductIsExpirable($conn, $productId);
    $exp = recvValidateExpiryDate($expiryDate, $line['date_received'] ?? null, $requiredExp);

    $lid = (int)$line['id'];
    if ($exp === null) {
        $updLine = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, selling_price=?, cost_price=?, expiry_date=NULL WHERE id=?");
        if (!$updLine) throw new RuntimeException($conn->error);
        $updLine->bind_param('dddi', $markup, $sell, $cost, $lid);
    } else {
        $updLine = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, selling_price=?, cost_price=?, expiry_date=? WHERE id=?");
        if (!$updLine) throw new RuntimeException($conn->error);
        $updLine->bind_param('dddsi', $markup, $sell, $cost, $exp, $lid);
    }
    if (!$updLine->execute()) throw new RuntimeException($updLine->error ?: 'Failed to save markup and expiry');
    $updLine->close();

    $line['markup_percent'] = $markup;
    $line['selling_price'] = $sell;
    $line['cost_price'] = $cost;
    $line['expiry_date'] = $exp;
    $line['po_number'] = (string)$line['po_number'];

    $receiptId = (int)$line['receipt_id'];
    $ref = trim((string)$line['receipt_number']);
    if (!empty($line['po_number'])) $ref .= '/' . $line['po_number'];
    $added = creditGoodStockFromReceiptLine($conn, $line, $ref, $uid, (int)$line['supplier_id'], $line['date_received']);
    $newStocked = (int)$line['stocked_in_qty'] + $added;
    $stockedStatus = recvWfStockedIn();
    $conn->query("UPDATE purchase_receipt_items SET stocked_in_qty=$newStocked, line_stock_status='" . $conn->real_escape_string($stockedStatus) . "' WHERE id=$lid");

    $remainQ = $conn->query("SELECT COALESCE(SUM(GREATEST(0, quantity_good - COALESCE(stocked_in_qty,0))),0) AS pending
                             FROM purchase_receipt_items WHERE receipt_id=$receiptId");
    $remain = $remainQ ? (int)$remainQ->fetch_assoc()['pending'] : 0;
    $stockInNumber = trim((string)($line['stock_in_number'] ?? ''));
    if ($remain <= 0) {
        if ($stockInNumber === '') $stockInNumber = recvAssignStockInNumber($conn);
        $updRec = $conn->prepare("UPDATE purchase_receipts SET workflow_status=?, stock_in_number=?, markup_by=COALESCE(markup_by,?), approved_by=COALESCE(approved_by,?), approved_at=COALESCE(approved_at,NOW()) WHERE id=?");
        if (!$updRec) throw new RuntimeException($conn->error);
        $updRec->bind_param('ssiii', $stockedStatus, $stockInNumber, $uid, $uid, $receiptId);
        $updRec->execute();
        $updRec->close();
    } else {
        $grantedWf = recvReceiptHasPendingPurchaserMarkup($conn, $receiptId) ? recvWfPendingMarkup() : recvWfGranted();
        $conn->query("UPDATE purchase_receipts SET markup_by=COALESCE(markup_by,$uid), markup_at=NOW(), workflow_status='" . $conn->real_escape_string($grantedWf) . "' WHERE id=$receiptId AND workflow_status<>'" . $conn->real_escape_string($stockedStatus) . "'");
        if ($stockInNumber === '') $stockInNumber = $line['receipt_number'];
    }

    $insA = $conn->prepare("INSERT INTO stock_in_approvals (receipt_id, stock_in_number, po_id, po_number, supplier_id, supplier_name, product_id, product_name, quantity_added, cost_price, markup_percent, selling_price, receiving_date, received_by, markup_by, approved_by, approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    if ($insA) {
        $poId = (int)$line['po_id'];
        $poNumber = (string)$line['po_number'];
        $supplierId = (int)$line['supplier_id'];
        $supplierName = (string)($line['supplier_name'] ?? '');
        $pname = (string)$line['product_name'];
        $recvDate = $line['date_received'] ?? null;
        $recvBy = (int)($line['received_by'] ?? 0);
        $mkBy = $uid;
        $approvedBy = $uid;
        $insA->bind_param(
            'isisisisidddsiii',
            $receiptId,
            $stockInNumber,
            $poId,
            $poNumber,
            $supplierId,
            $supplierName,
            $productId,
            $pname,
            $added,
            $cost,
            $markup,
            $sell,
            $recvDate,
            $recvBy,
            $mkBy,
            $approvedBy
        );
        $insA->execute();
        $insA->close();
    }

    syncPoReceivingStatus($conn, (int)$line['po_id']);
    return [
        'stocked_qty' => $added,
        'stock_in_number' => $stockInNumber,
        'receipt_item_id' => $lid,
        'receipt_id' => $receiptId,
        'selling_price' => $sell,
        'markup_percent' => $markup,
        'expiry_date' => $exp,
        'workflow_status' => $remain <= 0 ? $stockedStatus : recvWfGranted(),
    ];
}

function recvFetchPurchaserMarkupQueue(mysqli $conn): array {
    ensureReceivingWorkflowSchema($conn);
    $hasTax = recvHasColumn($conn, 'purchase_receipt_items', 'tax_percent');
    $taxSelect = $hasTax
        ? 'COALESCE(pri.apply_markup, NULL) AS apply_markup, COALESCE(pri.apply_tax, NULL) AS apply_tax,
           COALESCE(pri.tax_percent, 0) AS tax_percent, COALESCE(pri.tax_amount, 0) AS tax_amount,
           COALESCE(pri.pricing_status, \'\') AS pricing_status'
        : 'NULL AS apply_markup, NULL AS apply_tax, 0 AS tax_percent, 0 AS tax_amount, \'\' AS pricing_status';
    $sql = "SELECT pri.id AS receipt_item_id, pri.receipt_id, pri.product_id, pri.product_name,
                   COALESCE(p.sku, '') AS sku, COALESCE(p.barcode, '') AS barcode,
                   COALESCE(NULLIF(TRIM(p.unit_of_measure), ''), 'pcs') AS unit_of_measure,
                   po.po_number, rec.receipt_number, rec.date_received,
                   COALESCE(s.name, '') AS supplier_name,
                   pri.quantity_good, pri.quantity_ordered,
                   COALESCE(pri.quantity_damaged, 0) AS quantity_damaged,
                   COALESCE(pri.quantity_missing, 0) AS quantity_missing,
                   COALESCE(pri.stocked_in_qty, 0) AS stocked_in_qty,
                   COALESCE(NULLIF(pri.cost_price, 0), pri.unit_price, 0) AS purchase_price,
                   COALESCE(pri.markup_percent, 0) AS markup_percent,
                   COALESCE(pri.selling_price, 0) AS selling_price,
                   COALESCE(pri.manufacturer_lot, '') AS manufacturer_lot,
                   pri.expiry_date,
                   $taxSelect,
                   pri.line_stock_status, rec.workflow_status
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            INNER JOIN purchase_orders po ON po.id = rec.po_id
            LEFT JOIN products p ON p.id = pri.product_id
            LEFT JOIN suppliers s ON s.id = rec.supplier_id
            WHERE pri.quantity_good > 0
              AND COALESCE(pri.stocked_in_qty, 0) = 0
              AND COALESCE(pri.line_stock_status,'') NOT IN ('Stocked In','Rejected','Cancelled')
              AND rec.workflow_status NOT IN ('Stocked In','Cancelled')
            ORDER BY rec.date_received DESC, rec.id DESC, pri.id ASC";
    $res = $conn->query($sql);
    $rows = [];
    if (!$res) {
        return $rows;
    }
    while ($r = $res->fetch_assoc()) {
        if (!recvLineIsPendingPurchaserMarkup($r)) {
            continue;
        }
        $cost = round((float)$r['purchase_price'], 2);
        $rows[] = [
            'receipt_item_id' => (int)$r['receipt_item_id'],
            'receipt_id' => (int)$r['receipt_id'],
            'product_id' => (int)$r['product_id'],
            'product_name' => (string)$r['product_name'],
            'sku' => (string)$r['sku'],
            'barcode' => (string)$r['barcode'],
            'unit_of_measure' => (string)($r['unit_of_measure'] ?? 'pcs'),
            'po_number' => (string)$r['po_number'],
            'receipt_number' => (string)$r['receipt_number'],
            'supplier_name' => (string)$r['supplier_name'],
            'quantity_good' => (int)$r['quantity_good'],
            'purchase_price' => $cost,
            'markup_percent' => '',
            'tax_percent' => '',
            'apply_markup' => $r['apply_markup'] === null || $r['apply_markup'] === '' ? '' : (int)$r['apply_markup'],
            'apply_tax' => $r['apply_tax'] === null || $r['apply_tax'] === '' ? '' : (int)$r['apply_tax'],
            'selling_price' => '',
            'reference_selling_price' => '',
            'pricing_status' => recvLinePricingStatus($r),
            'date_received' => (string)$r['date_received'],
            'workflow_status' => recvNormalizeWorkflowStatus($r['workflow_status'] ?? ''),
            'manufacturer_lot' => (string)($r['manufacturer_lot'] ?? ''),
            'expiry_date' => $r['expiry_date'] ?? '',
            'lots' => recvLoadReceiptItemLots($conn, (int)$r['receipt_item_id'], [
                'manufacturer_lot' => (string)($r['manufacturer_lot'] ?? ''),
                'quantity_good' => (int)$r['quantity_good'],
                'expiry_date' => $r['expiry_date'] ?? null,
            ]),
        ];
    }
    return $rows;
}

/**
 * Purchaser confirms optional markup for one received line. Does not stock in.
 * Pricing Queue collects Markup Price (₱). Selling Price = Purchase Price + Markup Price.
 * Tax is not required and is stored as 0 for new confirmations.
 */
function recvConfirmPurchaserMarkup(mysqli $conn, int $itemId, int $uid, $pricing, ?float $sellPosted = null): array {
    if ($itemId <= 0) {
        throw new RuntimeException('Received item is required');
    }
    if (!is_array($pricing)) {
        $pricing = [
            'markup_amount' => $pricing,
            'selling_price' => $sellPosted,
        ];
    }

    $enteredPct = null;
    if (!array_key_exists('markup_amount', $pricing) && is_scalar($pricing['markup_percent'] ?? null) && trim((string)$pricing['markup_percent']) !== '') {
        $enteredPct = recvParseRequiredPercent($pricing['markup_percent'], 'Markup %');
        $applyMarkup = true;
        $markupAmt = 0.0;
    } else {
        $rawMk = array_key_exists('markup_amount', $pricing)
            ? $pricing['markup_amount']
            : ($pricing['markup_percent'] ?? '');
        $parsedMk = recvParseOptionalMarkupAmount($rawMk);
        $applyMarkup = $parsedMk['apply'];
        $markupAmt = $parsedMk['amount'];
    }
    $applyTax = false;
    $taxPct = 0.0;
    $taxAmt = 0.0;
    $applyTaxI = 0;

    $sql = "SELECT pri.*, rec.id AS receipt_id, rec.po_id, rec.workflow_status, rec.stock_in_number
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            WHERE pri.id=? FOR UPDATE";
    $st = $conn->prepare($sql);
    if (!$st) {
        throw new RuntimeException($conn->error);
    }
    $st->bind_param('i', $itemId);
    $st->execute();
    $line = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$line) {
        throw new RuntimeException('Received item not found');
    }
    if ((!empty($line['stock_in_number']) || recvNormalizeWorkflowStatus($line['workflow_status'] ?? '') === recvWfStockedIn())
        && (int)$line['quantity_good'] - (int)($line['stocked_in_qty'] ?? 0) <= 0) {
        throw new RuntimeException('This item was already stocked in');
    }
    if (recvLineIsIncompleteDelivery($line)) {
        throw new RuntimeException('Unprocessed remaining quantity cannot enter pricing. Replacement does not block Good items.');
    }
    $storedAmt = null;
    if (array_key_exists('markup_amount', $line) && $line['markup_amount'] !== null && $line['markup_amount'] !== '') {
        $storedAmt = round((float)$line['markup_amount'], 2);
    }
    if (!recvLineIsPendingPurchaserMarkup($line)) {
        $costDone = (float)(($line['cost_price'] > 0) ? $line['cost_price'] : $line['unit_price']);
        return [
            'receipt_item_id' => (int)$line['id'],
            'receipt_id' => (int)$line['receipt_id'],
            'markup_percent' => (float)$line['markup_percent'],
            'markup_amount' => $storedAmt,
            'tax_percent' => (float)($line['tax_percent'] ?? 0),
            'tax_amount' => (float)($line['tax_amount'] ?? 0),
            'selling_price' => (float)$line['selling_price'],
            'reference_selling_price' => (float)$line['selling_price'],
            'purchase_price' => $costDone,
            'apply_markup' => (int)($line['apply_markup'] ?? 0),
            'apply_tax' => (int)($line['apply_tax'] ?? 0),
            'pricing_status' => recvWfPricingConfirmed(),
            'workflow_status' => recvNormalizeWorkflowStatus($line['workflow_status'] ?? ''),
            'inventory_updated' => false,
            'ready_for_stock_in' => recvNormalizeWorkflowStatus($line['workflow_status'] ?? '') === recvWfGranted(),
            'already_confirmed' => true,
        ];
    }
    $pendingGood = (int)$line['quantity_good'] - (int)($line['stocked_in_qty'] ?? 0);
    if ($pendingGood <= 0) {
        throw new RuntimeException('Only good quantity can receive pricing');
    }

    if (!$applyMarkup) {
        throw new RuntimeException('Markup is required before this product can continue.');
    }
    $cost = (float)(($line['cost_price'] > 0) ? $line['cost_price'] : $line['unit_price']);
    if ($enteredPct !== null) {
        $markupAmt = round($cost * ($enteredPct / 100.0), 2);
    }
    $calc = recvCalcSellingPriceFromMarkupAmount($cost, $applyMarkup, $markupAmt);
    $sell = $calc['selling_price'];
    $applyMarkupI = $calc['apply_markup'];
    $markupAmtStore = $applyMarkupI ? (float)$calc['markup_amount'] : null;
    $markup = 0.0;
    if ($enteredPct !== null) {
        $markup = round($enteredPct, 2);
    } elseif ($applyMarkupI && $cost > 0) {
        require_once __DIR__ . '/product_pricing_lib.php';
        $markup = calcMarkupPercentFromPrices($cost, $sell);
        if ($markup > recvMaxPricingPercent()) {
            $markup = recvMaxPricingPercent();
        }
    }

    $lid = (int)$line['id'];
    $granted = recvWfGranted();
    $confirmed = recvWfPricingConfirmed();
    $hasPricing = recvHasColumn($conn, 'purchase_receipt_items', 'pricing_status');
    $hasAmt = recvHasColumn($conn, 'purchase_receipt_items', 'markup_amount');
    if ($hasPricing && $hasAmt && $applyMarkupI) {
        $upd = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, markup_amount=?, selling_price=?, cost_price=?, apply_markup=?, apply_tax=?, tax_percent=?, tax_amount=?, pricing_status=?, pricing_confirmed_by=?, pricing_confirmed_at=NOW(), line_stock_status=? WHERE id=?");
        if (!$upd) {
            throw new RuntimeException($conn->error);
        }
        $upd->bind_param('ddddiiddsisi', $markup, $markupAmtStore, $sell, $cost, $applyMarkupI, $applyTaxI, $taxPct, $taxAmt, $confirmed, $uid, $granted, $lid);
    } elseif ($hasPricing && $hasAmt) {
        $upd = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, markup_amount=NULL, selling_price=?, cost_price=?, apply_markup=?, apply_tax=?, tax_percent=?, tax_amount=?, pricing_status=?, pricing_confirmed_by=?, pricing_confirmed_at=NOW(), line_stock_status=? WHERE id=?");
        if (!$upd) {
            throw new RuntimeException($conn->error);
        }
        $upd->bind_param('dddiiddsisi', $markup, $sell, $cost, $applyMarkupI, $applyTaxI, $taxPct, $taxAmt, $confirmed, $uid, $granted, $lid);
    } elseif ($hasPricing) {
        $upd = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, selling_price=?, cost_price=?, apply_markup=?, apply_tax=?, tax_percent=?, tax_amount=?, pricing_status=?, pricing_confirmed_by=?, pricing_confirmed_at=NOW(), line_stock_status=? WHERE id=?");
        if (!$upd) {
            throw new RuntimeException($conn->error);
        }
        $upd->bind_param('dddiiddsisi', $markup, $sell, $cost, $applyMarkupI, $applyTaxI, $taxPct, $taxAmt, $confirmed, $uid, $granted, $lid);
    } elseif ($hasAmt && $applyMarkupI) {
        $upd = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, markup_amount=?, selling_price=?, cost_price=?, line_stock_status=? WHERE id=?");
        if (!$upd) {
            throw new RuntimeException($conn->error);
        }
        $upd->bind_param('ddddsi', $markup, $markupAmtStore, $sell, $cost, $granted, $lid);
    } elseif ($hasAmt) {
        $upd = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, markup_amount=NULL, selling_price=?, cost_price=?, line_stock_status=? WHERE id=?");
        if (!$upd) {
            throw new RuntimeException($conn->error);
        }
        $upd->bind_param('dddsi', $markup, $sell, $cost, $granted, $lid);
    } else {
        $upd = $conn->prepare("UPDATE purchase_receipt_items SET markup_percent=?, selling_price=?, cost_price=?, line_stock_status=? WHERE id=?");
        if (!$upd) {
            throw new RuntimeException($conn->error);
        }
        $upd->bind_param('dddsi', $markup, $sell, $cost, $granted, $lid);
    }
    if (!$upd->execute()) {
        throw new RuntimeException($upd->error ?: 'Failed to save pricing');
    }
    $upd->close();
    if (recvHasColumn($conn, 'purchase_receipt_items', 'pricing_confirmed_at')) {
        $conn->query("UPDATE purchase_receipt_items SET pricing_confirmed_at=NOW() WHERE id=$lid AND pricing_confirmed_at IS NULL");
    }

    $receiptId = (int)$line['receipt_id'];
    $esc = $conn->real_escape_string($granted);
    $conn->query("UPDATE purchase_receipts SET workflow_status='$esc', markup_by=$uid, markup_at=NOW() WHERE id=$receiptId");
    syncPoReceivingStatus($conn, (int)$line['po_id']);
    $receiptGranted = true;

    return [
        'receipt_item_id' => $lid,
        'receipt_id' => $receiptId,
        'markup_percent' => $markup,
        'markup_amount' => $markupAmtStore,
        'tax_percent' => $taxPct,
        'tax_amount' => $taxAmt,
        'selling_price' => $sell,
        'reference_selling_price' => $sell,
        'purchase_price' => $cost,
        'apply_markup' => $applyMarkupI,
        'apply_tax' => $applyTaxI,
        'pricing_status' => $confirmed,
        'workflow_status' => $receiptGranted ? $granted : recvWfPendingMarkup(),
        'inventory_updated' => false,
        'ready_for_stock_in' => $receiptGranted,
        'already_confirmed' => false,
    ];
}

function recvFetchPendingStockInItems(mysqli $conn): array {
    ensureReceivingWorkflowSchema($conn);
    $hasMissing = recvHasColumn($conn, 'purchase_receipt_items', 'quantity_missing');
    $hasSysBatch = recvHasColumn($conn, 'purchase_receipt_items', 'system_batch_number');
    $hasMfrLot = recvHasColumn($conn, 'purchase_receipt_items', 'manufacturer_lot');
    $hasExpiryTracking = recvHasColumn($conn, 'products', 'expiry_tracking');
    $missingExpr = $hasMissing ? 'COALESCE(pri.quantity_missing, 0)' : '0';
    $batchExpr = $hasSysBatch ? "COALESCE(NULLIF(pri.system_batch_number,''), NULLIF(pri.batch_number,''), '')" : "COALESCE(pri.batch_number,'')";
    $lotExpr = $hasMfrLot ? "COALESCE(pri.manufacturer_lot,'')" : "''";
    $expTrackExpr = $hasExpiryTracking ? 'COALESCE(p.expiry_tracking, 0)' : '0';

    $sql = "SELECT pri.id AS receipt_item_id, pri.po_item_id, pri.receipt_id, pri.product_id,
                   pri.product_name, COALESCE(p.sku, '') AS sku, COALESCE(p.barcode, '') AS barcode,
                   COALESCE(p.brand, '') AS brand, COALESCE(p.size, '') AS size,
                   COALESCE(p.packaging, '') AS packaging, COALESCE(p.unit_of_measure, 'pcs') AS uom,
                   COALESCE(p.description, '') AS product_description, COALESCE(c.name, '') AS category,
                   $expTrackExpr AS expiry_tracking,
                   po.po_number, rec.receipt_number, rec.date_received, rec.dr_number,
                   COALESCE(s.name, '') AS supplier_name,
                   COALESCE(NULLIF(pri.cost_price, 0), pri.unit_price, 0) AS purchase_price,
                   COALESCE(pri.markup_percent, 0) AS markup_percent,
                   COALESCE(pri.tax_percent, 0) AS tax_percent,
                   COALESCE(pri.tax_amount, 0) AS tax_amount,
                   COALESCE(pri.apply_markup, 0) AS apply_markup,
                   COALESCE(pri.apply_tax, 0) AS apply_tax,
                   COALESCE(pri.pricing_status, '') AS pricing_status,
                   COALESCE(pri.selling_price, 0) AS selling_price,
                   pri.quantity_ordered, pri.quantity_good, COALESCE(pri.quantity_damaged, 0) AS quantity_damaged,
                   $missingExpr AS quantity_missing,
                   GREATEST(0, pri.quantity_good - COALESCE(pri.stocked_in_qty, 0)) AS pending_qty,
                   $batchExpr AS batch_number, $lotExpr AS manufacturer_lot,
                   pri.expiry_date, pri.line_stock_status, rec.workflow_status
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            INNER JOIN purchase_orders po ON po.id = rec.po_id
            LEFT JOIN products p ON p.id = pri.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN suppliers s ON s.id = rec.supplier_id
            WHERE GREATEST(0, pri.quantity_good - COALESCE(pri.stocked_in_qty, 0)) > 0
              AND (
                    rec.workflow_status IN ('Approved - Pending Stock-In', 'Granted - Pending Stock-In')
                    OR (
                        COALESCE(pri.pricing_status, '') = 'Confirmed'
                        AND COALESCE(pri.line_stock_status, '') IN ('Approved - Pending Stock-In', 'Granted - Pending Stock-In')
                    )
              )
              AND COALESCE(pri.line_stock_status, '') NOT IN ('Stocked In', 'Rejected', 'Cancelled', 'Pending - Incomplete Delivery', 'Pending – Incomplete Delivery')
            ORDER BY rec.date_received DESC, rec.id DESC, pri.id ASC";
    $res = $conn->query($sql);
    $rows = [];
    if (!$res) return $rows;
    while ($r = $res->fetch_assoc()) {
        if (recvNormalizeWorkflowStatus($r['workflow_status'] ?? '') !== recvWfGranted()
            && !recvLinePricingIsConfirmed($r)) {
            continue;
        }
        if (recvNormalizeWorkflowStatus($r['line_stock_status'] ?? '') === recvWfStockedIn()) {
            continue;
        }
        if (recvLineIsIncompleteDelivery($r)) {
            continue;
        }
        if (!recvLinePricingIsConfirmed($r)) {
            continue;
        }
        $cost = round((float)$r['purchase_price'], 2);
        $markup = round((float)$r['markup_percent'], 2);
        $sell = round((float)$r['selling_price'], 2);
        $details = array_filter([
            $r['brand'] !== '' ? ('Brand: ' . $r['brand']) : '',
            $r['category'] !== '' ? ('Category: ' . $r['category']) : '',
            $r['uom'] !== '' ? ('Unit: ' . $r['uom']) : '',
            $r['packaging'] !== '' ? ('Packaging: ' . $r['packaging']) : '',
            $r['size'] !== '' ? ('Size: ' . $r['size']) : '',
        ]);
        $batch = trim((string)$r['batch_number']);
        $lot = trim((string)$r['manufacturer_lot']);
        $batchDisplay = $batch !== '' ? $batch : '—';
        if ($lot !== '' && $lot !== $batch) $batchDisplay .= ' / ' . $lot;
        $ref = (string)$r['receipt_number'];
        if (!empty($r['po_number'])) $ref .= ' / ' . $r['po_number'];
        $expirable = ((int)$r['expiry_tracking'] === 1);
        $rows[] = [
            'receipt_item_id' => (int)$r['receipt_item_id'],
            'option_value' => 'recv:' . (int)$r['receipt_item_id'],
            'po_item_id' => (int)$r['po_item_id'],
            'receipt_id' => (int)$r['receipt_id'],
            'product_id' => (int)$r['product_id'],
            'product_name' => (string)$r['product_name'],
            'sku' => (string)$r['sku'],
            'barcode' => (string)($r['barcode'] ?? ''),
            'product_details' => implode('  ·  ', $details),
            'po_number' => (string)$r['po_number'],
            'supplier' => (string)$r['supplier_name'],
            'purchase_price' => $cost,
            'quantity_ordered' => (int)$r['quantity_ordered'],
            'quantity_good' => (int)$r['pending_qty'],
            'quantity_damaged' => (int)$r['quantity_damaged'],
            'quantity_missing' => (int)$r['quantity_missing'],
            'batch_number' => $batchDisplay,
            'date_received' => (string)$r['date_received'],
            'reference_number' => $ref,
            'markup_percent' => $markup,
            'tax_percent' => round((float)($r['tax_percent'] ?? 0), 2),
            'tax_amount' => round((float)($r['tax_amount'] ?? 0), 2),
            'apply_markup' => (int)($r['apply_markup'] ?? 0),
            'apply_tax' => (int)($r['apply_tax'] ?? 0),
            'selling_price' => $sell,
            'reference_selling_price' => $sell,
            'expiry_date' => $r['expiry_date'] ?: '',
            'expirable' => $expirable,
            'stock_in_status' => 'Pending Stock In',
            'lots' => recvLoadReceiptItemLots($conn, (int)$r['receipt_item_id'], [
                'manufacturer_lot' => $lot,
                'quantity_good' => (int)$r['pending_qty'],
                'expiry_date' => $r['expiry_date'] ?: null,
            ]),
        ];
    }
    return $rows;
}

/**
 * Stock good qty into inventory when selling price is set.
 * Damaged/missing never sellable. Returns whether stock-in ran.
 */
function recvAutoStockInIfReady(mysqli $conn, int $receiptId, int $uid): array {
    $rec = recvLoadReceiptForStockIn($conn, $receiptId);
    if (!$rec) throw new RuntimeException('Receipt not found');
    $wf = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');
    if ($wf === recvWfStockedIn() || !empty($rec['stock_in_number'])) {
        return ['stocked' => false, 'already' => true, 'needs_markup' => false, 'no_good' => false, 'rec' => $rec, 'done' => null];
    }
    if ($wf === recvWfIncomplete()) {
        return ['stocked' => false, 'already' => false, 'needs_markup' => false, 'no_good' => false, 'rec' => $rec, 'done' => null];
    }
    $chk = $conn->query("SELECT COALESCE(SUM(GREATEST(0, quantity_good - COALESCE(stocked_in_qty,0))),0) AS pending_good,
                                SUM(CASE WHEN quantity_good>0 AND (selling_price IS NULL OR selling_price<=0) THEN 1 ELSE 0 END) AS missing_price
                         FROM purchase_receipt_items WHERE receipt_id=$receiptId");
    $row = $chk ? $chk->fetch_assoc() : ['pending_good' => 0, 'missing_price' => 1];
    if ((int)($row['pending_good'] ?? 0) <= 0) {
        return ['stocked' => false, 'already' => false, 'needs_markup' => false, 'no_good' => true, 'rec' => $rec, 'done' => null];
    }
    if ((int)($row['missing_price'] ?? 0) > 0) {
        return ['stocked' => false, 'already' => false, 'needs_markup' => true, 'no_good' => false, 'rec' => $rec, 'done' => null];
    }
    $done = recvCompleteStockIn($conn, $rec, $uid);
    return ['stocked' => true, 'already' => false, 'needs_markup' => false, 'no_good' => false, 'rec' => $rec, 'done' => $done];
}

function normalizeReceiptPricing(float $purchase, $markupPct, $selling): array {
    $purchase = round($purchase, 2);
    $markupPct = $markupPct === null ? 0.0 : (float)$markupPct;
    $selling = round((float)$selling, 2);
    if ($selling <= 0) {
        return [
            'purchase_price' => $purchase,
            'markup_percent' => null,
            'markup_amount' => null,
            'selling_price' => null,
        ];
    }
    $markupAmt = round($selling - $purchase, 2);
    if ($purchase > 0 && abs($markupPct) < 0.0001 && abs($markupAmt) >= 0.01) {
        $markupPct = round(($markupAmt / $purchase) * 100, 2);
    }
    return [
        'purchase_price' => $purchase,
        'markup_percent' => round($markupPct, 2),
        'markup_amount' => $markupAmt,
        'selling_price' => $selling,
    ];
}

function attachPricingToPurchaseOrders(mysqli $conn, array &$rows): void {
    if (!$rows || !$conn) return;
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    if (!$ids) return;
    $idList = implode(',', $ids);

    $linesByPo = [];
    $poiRes = $conn->query("SELECT poi.po_id, poi.product_name, poi.unit_price,
            COALESCE(p.selling_price, 0) AS product_selling
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.id
            WHERE poi.po_id IN ($idList)
            ORDER BY poi.id");
    if ($poiRes) {
        while ($it = $poiRes->fetch_assoc()) {
            $pid = (int)$it['po_id'];
            $cost = round((float)$it['unit_price'], 2);
            $sell = round((float)$it['product_selling'], 2);
            $mk = ($cost > 0 && $sell > 0) ? round((($sell - $cost) / $cost) * 100, 2) : 0;
            if ($sell <= 0) {
                $sell = $cost;
                $mk = 0;
            }
            $priced = normalizeReceiptPricing($cost, $mk, $sell);
            $priced['product_name'] = (string)$it['product_name'];
            $linesByPo[$pid][] = $priced;
        }
    }

    $latest = [];
    $lr = $conn->query("SELECT po_id, MAX(id) AS rid FROM purchase_receipts WHERE po_id IN ($idList) GROUP BY po_id");
    if ($lr) {
        while ($row = $lr->fetch_assoc()) {
            $latest[(int)$row['po_id']] = (int)$row['rid'];
        }
    }
    if ($latest) {
        $ridList = implode(',', array_map('intval', array_values($latest)));
        $receiptLines = [];
        $ri = $conn->query("SELECT rec.po_id, pri.product_name,
            COALESCE(NULLIF(pri.cost_price, 0), pri.unit_price, 0) AS purchase_price,
            COALESCE(pri.markup_percent, 0) AS markup_percent,
            COALESCE(pri.selling_price, 0) AS selling_price
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            WHERE rec.id IN ($ridList)
            ORDER BY pri.id");
        if ($ri) {
            while ($it = $ri->fetch_assoc()) {
                $pid = (int)$it['po_id'];
                $priced = normalizeReceiptPricing(
                    (float)$it['purchase_price'],
                    $it['markup_percent'],
                    $it['selling_price']
                );
                $priced['product_name'] = (string)$it['product_name'];
                $receiptLines[$pid][] = $priced;
            }
        }
        foreach ($receiptLines as $pid => $lines) {
            $existing = $linesByPo[$pid] ?? [];
            $merged = [];
            foreach ($lines as $idx => $recLine) {
                $base = $existing[$idx] ?? $recLine;
                if ($recLine['selling_price'] !== null && (float)$recLine['selling_price'] > 0) {
                    $merged[] = $recLine;
                    continue;
                }
                $cost = (float)($recLine['purchase_price'] ?? $base['purchase_price'] ?? 0);
                $sell = (float)($base['selling_price'] ?? 0);
                $mk = $base['markup_percent'];
                if ($sell <= 0) $sell = $cost;
                $priced = normalizeReceiptPricing($cost, $mk, $sell);
                $priced['product_name'] = $recLine['product_name'] ?: ($base['product_name'] ?? '');
                $merged[] = $priced;
            }
            $linesByPo[$pid] = $merged;
        }
    }

    foreach ($rows as &$r) {
        $pid = (int)$r['id'];
        $lines = $linesByPo[$pid] ?? [];
        $r['price_lines'] = $lines;
        $first = $lines[0] ?? [
            'purchase_price' => 0,
            'markup_percent' => null,
            'markup_amount' => null,
            'selling_price' => null,
        ];
        $r['purchase_price'] = $first['purchase_price'] ?? 0;
        $r['markup_percent'] = $first['markup_percent'] ?? null;
        $r['markup_amount'] = $first['markup_amount'] ?? null;
        $r['selling_price'] = $first['selling_price'] ?? null;
    }
    unset($r);
}
