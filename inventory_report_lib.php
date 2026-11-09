<?php
/**
 * Inventory Report: historical available qty from stock movements, inventory-only.
 */

function inventoryReportParseDate(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

function inventoryReportFormatLong(?string $ymd): string
{
    if ($ymd === null || $ymd === '') {
        return date('F j, Y');
    }
    $ts = strtotime($ymd);
    return $ts ? date('F j, Y', $ts) : $ymd;
}

function inventoryReportReadFilters(array $src): array
{
    $preset = trim((string)($src['ir_preset'] ?? 'current'));
    $allowed = ['current', 'today', 'week', 'month', 'year', 'specific', 'custom'];
    if (!in_array($preset, $allowed, true)) {
        $preset = 'current';
    }

    $today = new DateTimeImmutable('today');
    $specific = inventoryReportParseDate($src['ir_date'] ?? null);
    $from = inventoryReportParseDate($src['ir_from'] ?? null);
    $to = inventoryReportParseDate($src['ir_to'] ?? null);

    $rangeFrom = null;
    $rangeTo = null;
    $asOf = $today->format('Y-m-d');
    $useLiveQty = ($preset === 'current');

    switch ($preset) {
        case 'today':
            $asOf = $today->format('Y-m-d');
            $rangeFrom = $asOf;
            $rangeTo = $asOf;
            break;
        case 'week':
            $weekStart = $today->modify('monday this week');
            $weekEnd = $weekStart->modify('+6 days');
            $rangeFrom = $weekStart->format('Y-m-d');
            $rangeTo = $weekEnd->format('Y-m-d');
            $asOf = min($rangeTo, $today->format('Y-m-d'));
            break;
        case 'month':
            $rangeFrom = $today->format('Y-m-01');
            $rangeTo = $today->format('Y-m-t');
            $asOf = min($rangeTo, $today->format('Y-m-d'));
            break;
        case 'year':
            $rangeFrom = $today->format('Y-01-01');
            $rangeTo = $today->format('Y-12-31');
            $asOf = min($rangeTo, $today->format('Y-m-d'));
            break;
        case 'specific':
            $asOf = $specific ?: $today->format('Y-m-d');
            $rangeFrom = $asOf;
            $rangeTo = $asOf;
            break;
        case 'custom':
            if ($from && $to && $from > $to) {
                $tmp = $from;
                $from = $to;
                $to = $tmp;
            }
            $rangeFrom = $from;
            $rangeTo = $to ?: ($from ?: $today->format('Y-m-d'));
            $rangeFrom = $rangeFrom ?: $rangeTo;
            $asOf = $rangeTo;
            break;
        case 'current':
        default:
            $asOf = $today->format('Y-m-d');
            break;
    }

    $categoryId = max(0, (int)($src['ir_category'] ?? 0));
    $reportType = trim((string)($src['ir_report'] ?? 'inventory'));
    if (!in_array($reportType, ['inventory', 'stock_out'], true)) {
        $reportType = 'inventory';
    }

    return [
        'preset' => $preset,
        'date' => $preset === 'specific' ? $asOf : $specific,
        'from' => $rangeFrom,
        'to' => $rangeTo,
        'as_of' => $asOf,
        'use_live_qty' => $useLiveQty,
        'category_id' => $categoryId,
        'report_type' => $reportType,
    ];
}

function inventoryReportPeriodLabel(array $filters): string
{
    $preset = $filters['preset'] ?? 'current';
    if ($preset === 'current') {
        return 'Current Inventory (as of ' . inventoryReportFormatLong($filters['as_of'] ?? null) . ')';
    }
    if (in_array($preset, ['today', 'specific'], true) || ($filters['from'] ?? '') === ($filters['to'] ?? '')) {
        return 'Inventory as of ' . inventoryReportFormatLong($filters['as_of'] ?? null);
    }
    $fromLabel = inventoryReportFormatLong($filters['from'] ?? null);
    $toLabel = inventoryReportFormatLong($filters['to'] ?? null);
    $fromTs = strtotime((string)($filters['from'] ?? ''));
    $toTs = strtotime((string)($filters['to'] ?? ''));
    if ($fromTs && $toTs && date('F Y', $fromTs) === date('F Y', $toTs)) {
        return 'Inventory Report: ' . date('F j', $fromTs) . '–' . date('j, Y', $toTs);
    }
    return 'Inventory Report: ' . $fromLabel . '–' . $toLabel;
}

function inventoryReportHasTable(mysqli $conn, string $table): bool
{
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

function inventoryReportHasColumn(mysqli $conn, string $table, string $column): bool
{
    $r = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '" . $conn->real_escape_string($column) . "'");
    return $r && $r->num_rows > 0;
}

function inventoryReportMovementDateSql(mysqli $conn): string
{
    $hasTx = inventoryReportHasColumn($conn, 'stock_movements', 'transaction_date');
    $hasCreated = inventoryReportHasColumn($conn, 'stock_movements', 'created_at');
    if ($hasTx && $hasCreated) {
        return 'DATE(COALESCE(sm.transaction_date, sm.created_at))';
    }
    if ($hasTx) {
        return 'DATE(sm.transaction_date)';
    }
    if ($hasCreated) {
        return 'DATE(sm.created_at)';
    }
    return 'CURDATE()';
}

function inventoryReportSignedQtySql(): string
{
    return "CASE
        WHEN sm.movement_type IN ('Stock In (Receipt)', 'Stock In', 'Receive', 'Return')
             OR sm.movement_type LIKE '%In%' THEN sm.quantity
        WHEN sm.movement_type IN ('Stock Out (Issue)', 'Stock Out', 'Issue', 'Damage', 'Expiry')
             OR sm.movement_type LIKE '%Out%' THEN -sm.quantity
        ELSE 0
    END";
}

function inventoryReportStatus(int $qty, int $reorder): array
{
    if ($qty === 0) {
        return ['Out of Stock', 'badge-red'];
    }
    if ($qty <= $reorder) {
        return ['Low Stock', 'badge-orange'];
    }
    return ['In Stock', 'badge-green'];
}

function inventoryReportRecommendedAction(string $status): string
{
    if ($status === 'Low Stock' || $status === 'Out of Stock') {
        return 'Reorder Immediately';
    }
    return 'Monitor';
}

function inventoryReportReceiptDateSql(mysqli $conn): string
{
    $parts = [];
    if (inventoryReportHasColumn($conn, 'purchase_receipts', 'date_received')) {
        $parts[] = 'rec.date_received';
    }
    if (inventoryReportHasColumn($conn, 'purchase_receipts', 'received_at')) {
        $parts[] = 'rec.received_at';
    }
    if (inventoryReportHasColumn($conn, 'purchase_receipts', 'created_at')) {
        $parts[] = 'rec.created_at';
    }
    if (!$parts) {
        return 'CURDATE()';
    }
    return 'DATE(COALESCE(' . implode(', ', $parts) . '))';
}

function inventoryReportDatePeriodClause(array $filters, string $dateSql): array
{
    $preset = (string)($filters['preset'] ?? 'current');
    $asOf = (string)($filters['as_of'] ?? date('Y-m-d'));
    $from = $filters['from'] ?? null;
    $to = $filters['to'] ?? null;
    if ($preset !== 'current' && $from && $to) {
        return ['sql' => "{$dateSql} >= ? AND {$dateSql} <= ?", 'types' => 'ss', 'params' => [$from, $to]];
    }
    return ['sql' => "{$dateSql} <= ?", 'types' => 's', 'params' => [$asOf]];
}

function inventoryReportLocationExpr(): string
{
    return "COALESCE(NULLIF(CONCAT(sl.storage_area, IF(sl.shelf_rack IS NOT NULL AND sl.shelf_rack <> '', CONCAT(' - ', sl.shelf_rack), '')), ''), sl.location_code, 'Warehouse')";
}

/**
 * Expired on-hand quantity from product batches (expiry_date < as-of date).
 * Near-expiry (expiry on or after as-of) is excluded. Qty remaining must still be in inventory.
 */
function inventoryReportExpiredDetails(mysqli $conn, array $filters): array
{
    $asOf = (string)($filters['as_of'] ?? date('Y-m-d'));
    if (!inventoryReportHasTable($conn, 'product_batches')
        || !inventoryReportHasColumn($conn, 'product_batches', 'expiry_date')
        || !inventoryReportHasColumn($conn, 'product_batches', 'quantity_remaining')) {
        return [];
    }

    $locJoin = '';
    $locExpr = "'Warehouse'";
    if (inventoryReportHasTable($conn, 'storage_locations') && inventoryReportHasColumn($conn, 'products', 'location_id')) {
        $locJoin = 'LEFT JOIN storage_locations sl ON p.location_id = sl.id';
        $locExpr = inventoryReportLocationExpr();
    }
    $batchCol = inventoryReportHasColumn($conn, 'product_batches', 'batch_number')
        ? "COALESCE(NULLIF(b.batch_number, ''), '—')"
        : "'—'";

    $sql = "SELECT b.id,
                   COALESCE(p.sku, '') AS sku,
                   COALESCE(p.name, '—') AS product_name,
                   {$batchCol} AS batch_number,
                   b.quantity_remaining AS quantity,
                   b.expiry_date AS expiry_date,
                   {$locExpr} AS location_name,
                   DATEDIFF(?, b.expiry_date) AS days_expired
            FROM product_batches b
            INNER JOIN products p ON p.id = b.product_id
            {$locJoin}
            WHERE b.expiry_date IS NOT NULL
              AND b.expiry_date < ?
              AND b.quantity_remaining > 0
            ORDER BY b.expiry_date ASC, p.sku ASC, b.id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('[inventory_report] expired details: ' . $conn->error);
        return [];
    }
    $stmt->bind_param('ss', $asOf, $asOf);
    if (!$stmt->execute()) {
        error_log('[inventory_report] expired details execute: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $rows = [];
    $seen = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
        }
        $qty = (int)($row['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $days = (int)($row['days_expired'] ?? 0);
        if ($days <= 0) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'sku' => (string)($row['sku'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? '—'),
            'batch_number' => (string)($row['batch_number'] ?? '—'),
            'quantity' => $qty,
            'expiry_date' => (string)($row['expiry_date'] ?? ''),
            'location' => (string)($row['location_name'] ?? 'Warehouse'),
            'days_expired' => $days,
            'status' => 'Expired',
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Returned quantity from product_returns (customer / supplier / damaged / other).
 * Does not include Goods Received damaged qty (that stays on Damaged Items).
 */
function inventoryReportReturnedDetails(mysqli $conn, array $filters): array
{
    if (!inventoryReportHasTable($conn, 'product_returns')
        || !inventoryReportHasColumn($conn, 'product_returns', 'quantity')) {
        return [];
    }

    $dateSql = inventoryReportHasColumn($conn, 'product_returns', 'created_at')
        ? 'DATE(r.created_at)'
        : 'CURDATE()';
    $period = inventoryReportDatePeriodClause($filters, $dateSql);
    $prodJoin = inventoryReportHasTable($conn, 'products')
        ? 'LEFT JOIN products p ON p.id = r.product_id'
        : '';
    $skuExpr = $prodJoin !== '' ? "COALESCE(p.sku, '')" : "''";
    $nameExpr = $prodJoin !== '' ? "COALESCE(p.name, '—')" : "'—'";
    $typeExpr = inventoryReportHasColumn($conn, 'product_returns', 'return_type')
        ? "COALESCE(NULLIF(r.return_type, ''), NULLIF(r.party, ''), 'Return')"
        : (inventoryReportHasColumn($conn, 'product_returns', 'party') ? "COALESCE(r.party, 'Return')" : "'Return'");
    $reasonExpr = inventoryReportHasColumn($conn, 'product_returns', 'reason')
        ? "COALESCE(NULLIF(TRIM(r.reason), ''), '—')"
        : "'—'";
    $refExpr = "COALESCE(NULLIF(r.reference_number, ''), NULLIF(r.return_number, ''), '—')";
    if (!inventoryReportHasColumn($conn, 'product_returns', 'reference_number')) {
        $refExpr = inventoryReportHasColumn($conn, 'product_returns', 'return_number')
            ? "COALESCE(NULLIF(r.return_number, ''), '—')"
            : "'—'";
    }
    $statusExpr = inventoryReportHasColumn($conn, 'product_returns', 'condition_status')
        ? "COALESCE(NULLIF(r.condition_status, ''), 'Returned')"
        : "'Returned'";
    $dateSelect = inventoryReportHasColumn($conn, 'product_returns', 'created_at')
        ? 'DATE(r.created_at)'
        : 'NULL';

    $sql = "SELECT r.id,
                   {$skuExpr} AS sku,
                   {$nameExpr} AS product_name,
                   r.quantity AS quantity_returned,
                   {$typeExpr} AS return_type,
                   {$reasonExpr} AS return_reason,
                   {$refExpr} AS reference_number,
                   {$dateSelect} AS date_returned,
                   {$statusExpr} AS status
            FROM product_returns r
            {$prodJoin}
            WHERE r.quantity > 0
              AND {$period['sql']}
            ORDER BY r.id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('[inventory_report] returned details: ' . $conn->error);
        return [];
    }
    $stmt->bind_param($period['types'], ...$period['params']);
    if (!$stmt->execute()) {
        error_log('[inventory_report] returned details execute: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $rows = [];
    $seen = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
        }
        $qty = (int)($row['quantity_returned'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'sku' => (string)($row['sku'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? '—'),
            'quantity_returned' => $qty,
            'return_type' => (string)($row['return_type'] ?? 'Return'),
            'return_reason' => (string)($row['return_reason'] ?? '—'),
            'reference_number' => (string)($row['reference_number'] ?? '—'),
            'date_returned' => (string)($row['date_returned'] ?? ''),
            'status' => (string)($row['status'] ?? 'Returned'),
        ];
    }
    $stmt->close();
    return $rows;
}

function inventoryReportSumQty(array $rows, string $field): int
{
    $total = 0;
    foreach ($rows as $row) {
        $total += (int)($row[$field] ?? 0);
    }
    return $total;
}

/**
 * Damaged units from Goods Received / Receive Items (quantity_damaged > 0).
 * One row per receipt line — not stocked as usable inventory.
 */
function inventoryReportDamagedDetails(mysqli $conn, array $filters): array
{
    if (!inventoryReportHasTable($conn, 'purchase_receipt_items')
        || !inventoryReportHasTable($conn, 'purchase_receipts')
        || !inventoryReportHasColumn($conn, 'purchase_receipt_items', 'quantity_damaged')) {
        return [];
    }

    $period = inventoryReportDatePeriodClause($filters, inventoryReportReceiptDateSql($conn));
    $sdrJoin = '';
    $reasonExpr = "COALESCE(NULLIF(TRIM(pri.remarks), ''), NULLIF(TRIM(rec.remarks), ''), '—')";
    $statusExpr = "COALESCE(NULLIF(rec.workflow_status, ''), 'Received')";
    if (inventoryReportHasTable($conn, 'supplier_damage_returns')) {
        $sdrJoin = "LEFT JOIN (
                        SELECT sdr.receipt_item_id, sdr.status, sdr.remarks
                        FROM supplier_damage_returns sdr
                        INNER JOIN (
                            SELECT receipt_item_id, MAX(id) AS max_id
                            FROM supplier_damage_returns
                            WHERE receipt_item_id IS NOT NULL
                            GROUP BY receipt_item_id
                        ) latest ON latest.max_id = sdr.id
                    ) sdr ON sdr.receipt_item_id = pri.id";
        $reasonExpr = "COALESCE(NULLIF(TRIM(pri.remarks), ''), NULLIF(TRIM(sdr.remarks), ''), NULLIF(TRIM(rec.remarks), ''), '—')";
        $statusExpr = "COALESCE(NULLIF(sdr.status, ''), NULLIF(rec.workflow_status, ''), 'Received')";
    }

    $poJoin = inventoryReportHasTable($conn, 'purchase_orders')
        ? 'LEFT JOIN purchase_orders po ON po.id = rec.po_id'
        : '';
    $poNumberExpr = $poJoin !== '' ? "COALESCE(po.po_number, '')" : "''";
    $prodJoin = inventoryReportHasTable($conn, 'products')
        ? 'LEFT JOIN products p ON p.id = pri.product_id'
        : '';
    $skuExpr = $prodJoin !== '' ? "COALESCE(p.sku, '')" : "''";
    $nameExpr = $prodJoin !== ''
        ? "COALESCE(NULLIF(p.name, ''), pri.product_name, '—')"
        : "COALESCE(pri.product_name, '—')";
    $supJoin = inventoryReportHasTable($conn, 'suppliers')
        ? 'LEFT JOIN suppliers s ON s.id = COALESCE(rec.supplier_id' . ($poJoin !== '' ? ', po.supplier_id' : '') . ')'
        : '';
    $supExpr = $supJoin !== '' ? "COALESCE(s.name, '—')" : "'—'";
    $dateSql = inventoryReportReceiptDateSql($conn);

    $sql = "SELECT pri.id,
                   {$skuExpr} AS sku,
                   {$nameExpr} AS product_name,
                   {$poNumberExpr} AS po_number,
                   {$supExpr} AS supplier_name,
                   {$dateSql} AS date_received,
                   COALESCE(pri.quantity_received, 0) AS quantity_received,
                   pri.quantity_damaged AS quantity_damaged,
                   {$reasonExpr} AS damage_reason,
                   {$statusExpr} AS status
            FROM purchase_receipt_items pri
            INNER JOIN purchase_receipts rec ON rec.id = pri.receipt_id
            {$poJoin}
            {$prodJoin}
            {$supJoin}
            {$sdrJoin}
            WHERE pri.quantity_damaged > 0
              AND {$period['sql']}
            ORDER BY {$dateSql} DESC, pri.id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('[inventory_report] damaged details: ' . $conn->error);
        return [];
    }
    $stmt->bind_param($period['types'], ...$period['params']);
    if (!$stmt->execute()) {
        error_log('[inventory_report] damaged details execute: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $rows = [];
    $seen = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
        }
        $rows[] = [
            'id' => $id,
            'sku' => (string)($row['sku'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? '—'),
            'po_number' => (string)($row['po_number'] ?? ''),
            'supplier_name' => (string)($row['supplier_name'] ?? '—'),
            'date_received' => (string)($row['date_received'] ?? ''),
            'quantity_received' => (int)($row['quantity_received'] ?? 0),
            'quantity_damaged' => (int)($row['quantity_damaged'] ?? 0),
            'damage_reason' => (string)($row['damage_reason'] ?? '—'),
            'status' => (string)($row['status'] ?? ''),
        ];
    }
    $stmt->close();
    return $rows;
}

function inventoryReportDamagedTotal(mysqli $conn, $asOfOrFilters): int
{
    $filters = is_array($asOfOrFilters)
        ? $asOfOrFilters
        : ['preset' => 'current', 'as_of' => (string)$asOfOrFilters, 'from' => null, 'to' => null];
    $total = 0;
    foreach (inventoryReportDamagedDetails($conn, $filters) as $row) {
        $total += (int)$row['quantity_damaged'];
    }
    return $total;
}

function inventoryReportNetAfter(mysqli $conn, string $asOf): array
{
    $net = [];
    if (!inventoryReportHasTable($conn, 'stock_movements')) {
        return $net;
    }
    $dateSql = inventoryReportMovementDateSql($conn);
    $signed = inventoryReportSignedQtySql();
    $stmt = $conn->prepare("SELECT sm.product_id, COALESCE(SUM($signed), 0) AS net_after
        FROM stock_movements sm
        WHERE {$dateSql} > ?
        GROUP BY sm.product_id");
    if (!$stmt) {
        return $net;
    }
    $stmt->bind_param('s', $asOf);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $net[(int)$row['product_id']] = (int)$row['net_after'];
    }
    $stmt->close();
    return $net;
}

function inventoryReportPeriodFlows(mysqli $conn, array $filters): array
{
    if (!inventoryReportHasTable($conn, 'stock_movements')) {
        return [];
    }
    $from = $filters['from'] ?? $filters['as_of'] ?? date('Y-m-d');
    $to = $filters['to'] ?? $filters['as_of'] ?? date('Y-m-d');
    $dateSql = inventoryReportMovementDateSql($conn);
    $t = "LOWER(TRIM(sm.movement_type))";
    $sql = "SELECT sm.product_id,
                SUM(CASE WHEN $t LIKE '%in%' AND $t NOT LIKE '%out%' AND $t NOT LIKE '%return%' THEN sm.quantity ELSE 0 END) AS qty_in,
                SUM(CASE WHEN ($t LIKE '%out%' OR $t LIKE '%issue%' OR $t LIKE '%sale%') AND $t NOT LIKE '%return%' AND $t NOT LIKE '%damage%' AND $t NOT LIKE '%expir%' THEN sm.quantity ELSE 0 END) AS qty_out,
                SUM(CASE WHEN $t LIKE '%return%' THEN sm.quantity ELSE 0 END) AS qty_return,
                SUM(CASE WHEN $t LIKE '%damage%' THEN sm.quantity ELSE 0 END) AS qty_damaged,
                SUM(CASE WHEN $t LIKE '%expir%' THEN sm.quantity ELSE 0 END) AS qty_expired
            FROM stock_movements sm
            WHERE DATE($dateSql) >= ? AND DATE($dateSql) <= ?
            GROUP BY sm.product_id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $out[(int)$row['product_id']] = [
            'in' => (int)$row['qty_in'],
            'out' => (int)$row['qty_out'],
            'returns' => (int)$row['qty_return'],
            'damaged' => (int)$row['qty_damaged'],
            'expired' => (int)$row['qty_expired'],
        ];
    }
    $stmt->close();
    return $out;
}

function inventoryReportMovementBucket(string $type): string
{
    $t = strtolower(trim($type));
    if (str_contains($t, 'return')) {
        return 'return';
    }
    if (str_contains($t, 'damage') || str_contains($t, 'missing')) {
        return 'damaged';
    }
    if (str_contains($t, 'expir')) {
        return 'expired';
    }
    if ((str_contains($t, 'out') || str_contains($t, 'issue') || str_contains($t, 'sale'))
        && !str_contains($t, 'return')
        && !str_contains($t, 'damage')
        && !str_contains($t, 'expir')) {
        return 'out';
    }
    if (str_contains($t, 'in') && !str_contains($t, 'out') && !str_contains($t, 'return')) {
        return 'in';
    }
    return 'other';
}

function inventoryReportTransactions(mysqli $conn, array $filters, array $productRows, string $reportType): array
{
    if (!$productRows || !inventoryReportHasTable($conn, 'stock_movements')) {
        return [];
    }
    $meta = [];
    foreach ($productRows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid > 0) {
            $meta[$pid] = $row;
        }
    }
    if (!$meta) {
        return [];
    }
    $from = (string)($filters['from'] ?? $filters['as_of'] ?? date('Y-m-d'));
    $to = (string)($filters['to'] ?? $filters['as_of'] ?? date('Y-m-d'));
    $dateSql = inventoryReportMovementDateSql($conn);
    $refs = [];
    foreach (['reference_number', 'po_number', 'si_number', 'dr_number'] as $col) {
        if (inventoryReportHasColumn($conn, 'stock_movements', $col)) {
            $refs[] = "NULLIF(TRIM(sm.`{$col}`), '')";
        }
    }
    $refExpr = $refs ? ('COALESCE(' . implode(', ', $refs) . ", '')") : "''";
    $ids = array_keys($meta);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT sm.id, sm.product_id, sm.movement_type, sm.quantity, {$dateSql} AS txn_date, {$refExpr} AS reference_no
            FROM stock_movements sm
            WHERE sm.product_id IN ({$placeholders})
              AND {$dateSql} >= ? AND {$dateSql} <= ?
            ORDER BY txn_date ASC, sm.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $types = str_repeat('i', count($ids)) . 'ss';
    $params = array_merge($ids, [$from, $to]);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $out = [];
    $seen = [];
    $labels = [
        'in' => 'Stock-In',
        'out' => 'Stock-Out',
        'return' => 'Return',
        'damaged' => 'Damaged / Missing',
        'expired' => 'Expired',
        'other' => 'Other',
    ];
    while ($res && ($row = $res->fetch_assoc())) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
        }
        $bucket = inventoryReportMovementBucket((string)($row['movement_type'] ?? ''));
        if ($reportType === 'stock_out' && $bucket !== 'out') {
            continue;
        }
        $pid = (int)($row['product_id'] ?? 0);
        $product = $meta[$pid] ?? null;
        if (!$product) {
            continue;
        }
        $qty = (int)($row['quantity'] ?? 0);
        if ($qty < 0) {
            $qty = abs($qty);
        }
        $reference = trim((string)($row['reference_no'] ?? ''));
        $out[] = [
            'id' => $id,
            'date' => (string)($row['txn_date'] ?? ''),
            'reference' => $reference !== '' ? $reference : '—',
            'sku' => (string)($product['sku'] ?? ''),
            'product' => (string)($product['name'] ?? ''),
            'category' => (string)($product['category'] ?? ''),
            'type' => $labels[$bucket] ?? 'Other',
            'bucket' => $bucket,
            'quantity' => $qty,
            'stock_in' => $bucket === 'in' ? $qty : 0,
            'stock_out' => $bucket === 'out' ? $qty : 0,
            'damaged' => $bucket === 'damaged' ? $qty : 0,
            'returns' => $bucket === 'return' ? $qty : 0,
            'expired' => $bucket === 'expired' ? $qty : 0,
            'remaining' => (int)($product['qty'] ?? 0),
        ];
    }
    $stmt->close();
    return $out;
}

function inventoryReportLoad(mysqli $conn, array $filters): array
{
    $asOf = (string)($filters['as_of'] ?? date('Y-m-d'));
    $useLive = !empty($filters['use_live_qty']);
    $netAfter = $useLive ? [] : inventoryReportNetAfter($conn, $asOf);
    $flows = inventoryReportPeriodFlows($conn, $filters);

    $rows = [];
    $unitExpr = inventoryReportHasColumn($conn, 'products', 'unit_of_measure')
        ? "COALESCE(NULLIF(TRIM(p.unit_of_measure), ''), 'pcs')"
        : "'pcs'";
    $costExpr = inventoryReportHasColumn($conn, 'products', 'cost_price')
        ? 'COALESCE(p.cost_price, 0)'
        : '0';
    $sql = "SELECT p.id, p.sku, p.name, p.quantity_in_stock, p.reorder_level,
                   COALESCE(c.id, 0) AS category_id,
                   COALESCE(c.parent_id, 0) AS parent_category_id,
                   COALESCE(c.name, 'Uncategorized') AS category,
                   COALESCE(pc.name, '') AS parent_category_name,
                   {$unitExpr} AS unit_name,
                   {$costExpr} AS unit_cost,
                   COALESCE(NULLIF(CONCAT(sl.storage_area, IF(sl.shelf_rack IS NOT NULL AND sl.shelf_rack <> '', CONCAT(' - ', sl.shelf_rack), '')), ''), sl.location_code, 'Warehouse') AS location_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN categories pc ON pc.id = c.parent_id
            LEFT JOIN storage_locations sl ON p.location_id = sl.id
            ORDER BY p.id DESC, p.sku";
    $res = $conn->query($sql);
    if (!$res) {
        $sql = "SELECT p.id, p.sku, p.name, p.quantity_in_stock, p.reorder_level,
                       COALESCE(c.id, 0) AS category_id,
                       COALESCE(c.parent_id, 0) AS parent_category_id,
                       COALESCE(c.name, 'Uncategorized') AS category,
                       '' AS parent_category_name,
                       {$unitExpr} AS unit_name,
                       {$costExpr} AS unit_cost,
                       'Warehouse' AS location_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                ORDER BY p.id DESC, p.sku";
        $res = $conn->query($sql);
    }

    $totalUnits = 0;
    $outOfStock = 0;
    $lowStock = 0;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['id'];
            $liveQty = (int)$row['quantity_in_stock'];
            $qty = $useLive ? $liveQty : max(0, $liveQty - (int)($netAfter[$pid] ?? 0));
            $reorder = (int)($row['reorder_level'] ?? 50);
            [$status, $statusClass] = inventoryReportStatus($qty, $reorder);
            $totalUnits += $qty;
            if ($qty === 0) {
                $outOfStock++;
            } elseif ($qty <= $reorder) {
                $lowStock++;
            }
            $flow = $flows[$pid] ?? ['in' => 0, 'out' => 0, 'returns' => 0, 'damaged' => 0, 'expired' => 0];
            $ending = $qty;
            $beginning = $ending - (int)$flow['in'] + (int)$flow['out'] - (int)$flow['returns'] + (int)$flow['damaged'] + (int)$flow['expired'];
            $unitCost = round((float)($row['unit_cost'] ?? 0), 2);
            $rows[] = [
                'product_id' => $pid,
                'sku' => (string)$row['sku'],
                'name' => (string)$row['name'],
                'category_id' => (int)($row['category_id'] ?? 0),
                'parent_category_id' => (int)($row['parent_category_id'] ?? 0),
                'parent_category_name' => (string)($row['parent_category_name'] ?? ''),
                'category' => (string)$row['category'],
                'unit' => (string)($row['unit_name'] ?? 'pcs'),
                'beginning' => $beginning,
                'stock_in' => (int)$flow['in'],
                'stock_out' => (int)$flow['out'],
                'returns' => (int)$flow['returns'],
                'damaged' => (int)$flow['damaged'],
                'expired' => (int)$flow['expired'],
                'qty' => $ending,
                'reorder_level' => $reorder,
                'unit_cost' => $unitCost,
                'inventory_value' => round($ending * $unitCost, 2),
                'location' => (string)($row['location_name'] ?? 'Warehouse'),
                'status' => $status,
                'status_class' => $statusClass,
            ];
        }
    }

    $categoryId = (int)($filters['category_id'] ?? 0);
    $reportType = (string)($filters['report_type'] ?? 'inventory');
    $categorySkuSet = null;
    if ($categoryId > 0) {
        $categorySkuSet = [];
        foreach ($rows as $row) {
            if ((int)$row['category_id'] === $categoryId || (int)$row['parent_category_id'] === $categoryId) {
                $categorySkuSet[(string)$row['sku']] = true;
            }
        }
    }
    if ($categoryId > 0 || $reportType === 'stock_out') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($categoryId, $reportType): bool {
            if ($categoryId > 0 && (int)$row['category_id'] !== $categoryId && (int)$row['parent_category_id'] !== $categoryId) {
                return false;
            }
            if ($reportType === 'stock_out' && (int)$row['stock_out'] <= 0) {
                return false;
            }
            return true;
        }));
        $totalUnits = 0;
        $outOfStock = 0;
        $lowStock = 0;
        foreach ($rows as $row) {
            $qty = (int)$row['qty'];
            $reorder = (int)$row['reorder_level'];
            $totalUnits += $qty;
            if ($qty === 0) {
                $outOfStock++;
            } elseif ($qty <= $reorder) {
                $lowStock++;
            }
        }
    }
    $categoryLabel = 'All Categories';
    if ($categoryId > 0) {
        $catStmt = $conn->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
        if ($catStmt) {
            $catStmt->bind_param('i', $categoryId);
            $catStmt->execute();
            $catRes = $catStmt->get_result();
            if ($catRes && ($catRow = $catRes->fetch_assoc())) {
                $categoryLabel = (string)$catRow['name'];
            }
            $catStmt->close();
        }
    }
    $reportTypeLabel = $reportType === 'stock_out' ? 'Inventory Stock-Out' : 'Inventory Report';
    $transactions = inventoryReportTransactions($conn, $filters, $rows, $reportType);

    $damagedRows = inventoryReportDamagedDetails($conn, $filters);
    $expiredRows = inventoryReportExpiredDetails($conn, $filters);
    $returnedRows = inventoryReportReturnedDetails($conn, $filters);
    if ($categorySkuSet !== null) {
        $keepSku = static function (array $list) use ($categorySkuSet): array {
            return array_values(array_filter($list, static function (array $row) use ($categorySkuSet): bool {
                return isset($categorySkuSet[(string)($row['sku'] ?? '')]);
            }));
        };
        $damagedRows = $keepSku($damagedRows);
        $expiredRows = $keepSku($expiredRows);
        $returnedRows = $keepSku($returnedRows);
    }

    return [
        'rows' => $rows,
        'transactions' => $transactions,
        'damaged_rows' => $damagedRows,
        'expired_rows' => $expiredRows,
        'returned_rows' => $returnedRows,
        'period_label' => inventoryReportPeriodLabel($filters),
        'category_label' => $categoryLabel,
        'report_type' => $reportType,
        'report_type_label' => $reportTypeLabel,
        'filter_label' => $reportTypeLabel . ' · ' . $categoryLabel,
        'generated' => date('F j, Y'),
        'generated_iso' => date('Y-m-d'),
        'as_of' => $asOf,
        'summary' => [
            'products' => count($rows),
            'units' => $totalUnits,
            'out_of_stock' => $outOfStock,
            'low_stock' => $lowStock,
            'damaged' => inventoryReportSumQty($damagedRows, 'quantity_damaged'),
            'expired' => inventoryReportSumQty($expiredRows, 'quantity'),
            'returned' => inventoryReportSumQty($returnedRows, 'quantity_returned'),
        ],
    ];
}

function inventoryReportRowsByStatus(array $rows, ?string $status): array
{
    if ($status === null || $status === '') {
        return array_values($rows);
    }
    $out = [];
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === $status) {
            $out[] = $row;
        }
    }
    return $out;
}

function inventoryReportRenderProductModal(
    string $modalId,
    string $title,
    string $iconClass,
    string $periodLabel,
    array $rows,
    string $emptyMessage,
    string $footerLabel,
    int $footerValue,
    bool $lowStock = false
): void {
    $modalIdAttr = htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8');
    ?>
        <div class="modal-overlay" id="<?= $modalIdAttr ?>">
            <div class="modal modal-xl">
                <div class="modal-header">
                    <span class="modal-title"><i class="bi <?= htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') ?>"></i> <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="modal-close" onclick="closeModal('<?= $modalIdAttr ?>')"><i class="bi bi-x"></i></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="report-details-scroll table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th><?= $lowStock ? 'Current Stock' : 'Quantity' ?></th>
                                    <?php if ($lowStock): ?><th>Reorder Level</th><?php endif; ?>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <?php if ($lowStock): ?><th>Recommended Action</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($item['sku'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($item['name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($item['category'] ?? '')) ?></td>
                                    <td><?= (int)($item['qty'] ?? 0) ?></td>
                                    <?php if ($lowStock): ?><td><?= (int)($item['reorder_level'] ?? 0) ?></td><?php endif; ?>
                                    <td><?= htmlspecialchars((string)($item['location'] ?? '')) ?></td>
                                    <td><span class="badge <?= htmlspecialchars((string)($item['status_class'] ?? 'badge-gray')) ?>"><?= htmlspecialchars((string)($item['status'] ?? '')) ?></span></td>
                                    <?php if ($lowStock): ?><td><?= htmlspecialchars(inventoryReportRecommendedAction((string)($item['status'] ?? ''))) ?></td><?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="<?= $lowStock ? 8 : 6 ?>" class="text-center"><?= htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <span class="me-auto small text-muted"><?= htmlspecialchars($footerLabel, ENT_QUOTES, 'UTF-8') ?>: <strong><?= number_format($footerValue) ?></strong></span>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeModal('<?= $modalIdAttr ?>')">Close</button>
                </div>
            </div>
        </div>
    <?php
}

function inventoryReportQueryString(array $filters, array $extra = []): string
{
    $q = [
        'open' => 'reports',
        'ir_preset' => $filters['preset'] ?? 'current',
        'ir_date' => ($filters['preset'] ?? '') === 'specific' ? ($filters['as_of'] ?? '') : '',
        'ir_from' => ($filters['preset'] ?? '') === 'custom' ? ($filters['from'] ?? '') : '',
        'ir_to' => ($filters['preset'] ?? '') === 'custom' ? ($filters['to'] ?? '') : '',
    ];
    $q = array_merge($q, $extra);
    return http_build_query(array_filter($q, static function ($v) {
        return $v !== '' && $v !== null;
    }));
}

function inventoryReportCsv(array $report): string
{
    $s = $report['summary'];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, [(string)($report['report_type_label'] ?? 'Inventory Report')]);
    fputcsv($fh, [$report['period_label']]);
    fputcsv($fh, ['Filters', (string)($report['filter_label'] ?? 'All Categories')]);
    fputcsv($fh, ['Date Generated', $report['generated']]);
    fputcsv($fh, ['Total Products', (int)$s['products']]);
    fputcsv($fh, ['Total Stock Units', (int)$s['units']]);
    fputcsv($fh, ['Out of Stock Items', (int)$s['out_of_stock']]);
    fputcsv($fh, ['Low Stock Items', (int)$s['low_stock']]);
    fputcsv($fh, ['Replacement Items', (int)$s['damaged']]);
    fputcsv($fh, ['Expired Items', (int)($s['expired'] ?? 0)]);
    fputcsv($fh, ['Returned Items', (int)($s['returned'] ?? 0)]);
    fputcsv($fh, []);
    fputcsv($fh, ['SKU', 'Product Name', 'Category', 'Unit', 'Opening Stock', 'Stock-In', 'Stock-Out', 'Returns', 'Replacement', 'Expired/Disposed', 'Ending Stock', 'Unit Cost', 'Inventory Value', 'Location', 'Status']);
    foreach ($report['rows'] as $row) {
        fputcsv($fh, [
            $row['sku'],
            $row['name'],
            $row['category'],
            (string)($row['unit'] ?? 'pcs'),
            (int)($row['beginning'] ?? $row['qty']),
            (int)($row['stock_in'] ?? 0),
            (int)($row['stock_out'] ?? 0),
            (int)($row['returns'] ?? 0),
            (int)($row['damaged'] ?? 0),
            (int)($row['expired'] ?? 0),
            (int)$row['qty'],
            number_format((float)($row['unit_cost'] ?? 0), 2, '.', ''),
            number_format((float)($row['inventory_value'] ?? 0), 2, '.', ''),
            $row['location'],
            $row['status'],
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

function inventoryReportXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function inventoryReportZipStore(array $files): string
{
    $now = getdate();
    $dosTime = (($now['hours'] << 11) | ($now['minutes'] << 5) | max(0, $now['seconds'] >> 1)) & 0xFFFF;
    $dosDate = ((($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday']) & 0xFFFF;
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', (string)$name);
        $crc = crc32($content);
        $size = strlen($content);
        $nameLen = strlen($name);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0);
        $local .= $header . $name . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($header) + $nameLen + $size;
    }
    $count = count($files);
    $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    return $local . $central . $eocd;
}

function inventoryReportCell(string $ref, string $text, int $style = 0, bool $number = false): string
{
    $sAttr = $style > 0 ? ' s="' . $style . '"' : '';
    if ($number) {
        return '<c r="' . $ref . '"' . $sAttr . ' t="n"><v>' . inventoryReportXml($text) . '</v></c>';
    }
    return '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t>' . inventoryReportXml($text) . '</t></is></c>';
}

function inventoryReportXlsx(array $report, string $preparedBy = ''): string
{
    $rowsXml = '';
    $r = 1;
    $preparedBy = trim($preparedBy);
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'ABC GENERAL MERCHANDISE', 1) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, (string)($report['report_type_label'] ?? 'Inventory Report'), 2) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Reporting Period: ' . (string)($report['period_label'] ?? '')) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Filters: ' . (string)($report['filter_label'] ?? 'All Categories')) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Prepared by: ' . ($preparedBy !== '' ? $preparedBy : '—')) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Date Prepared: ' . (string)($report['generated'] ?? date('F j, Y'))) . '</row>';
    $r += 2;
    $headers = ['Product Code', 'Product', 'Category', 'Unit', 'Opening Stock', 'Stock In', 'Stock Out', 'Returns', 'Replacement', 'Expired/Disposed', 'Ending Stock', 'Unit Cost', 'Inventory Value', 'Location', 'Status'];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'];
    $cells = '';
    foreach ($headers as $i => $h) {
        $cells .= inventoryReportCell($cols[$i] . $r, $h, 4);
    }
    $rowsXml .= '<row r="' . $r . '">' . $cells . '</row>';
    $totalOpening = 0;
    $totalIn = 0;
    $totalOut = 0;
    $totalReturns = 0;
    $totalReplacement = 0;
    $totalExpired = 0;
    $totalEnding = 0;
    $totalValue = 0.0;
    foreach ($report['rows'] as $row) {
        $ending = (int)($row['qty'] ?? 0);
        $unitCost = (float)($row['unit_cost'] ?? 0);
        $value = array_key_exists('inventory_value', $row)
            ? (float)$row['inventory_value']
            : round($ending * $unitCost, 2);
        $opening = (int)($row['beginning'] ?? $ending);
        $stockIn = (int)($row['stock_in'] ?? 0);
        $stockOut = (int)($row['stock_out'] ?? 0);
        $returns = (int)($row['returns'] ?? 0);
        $replacement = (int)($row['damaged'] ?? 0);
        $expired = (int)($row['expired'] ?? 0);
        $totalOpening += $opening;
        $totalIn += $stockIn;
        $totalOut += $stockOut;
        $totalReturns += $returns;
        $totalReplacement += $replacement;
        $totalExpired += $expired;
        $totalEnding += $ending;
        $totalValue += $value;
        $r++;
        $rowsXml .= '<row r="' . $r . '">'
            . inventoryReportCell('A' . $r, (string)$row['sku'])
            . inventoryReportCell('B' . $r, (string)$row['name'])
            . inventoryReportCell('C' . $r, (string)$row['category'])
            . inventoryReportCell('D' . $r, (string)($row['unit'] ?? 'pcs'))
            . inventoryReportCell('E' . $r, (string)$opening, 0, true)
            . inventoryReportCell('F' . $r, (string)$stockIn, 0, true)
            . inventoryReportCell('G' . $r, (string)$stockOut, 0, true)
            . inventoryReportCell('H' . $r, (string)$returns, 0, true)
            . inventoryReportCell('I' . $r, (string)$replacement, 0, true)
            . inventoryReportCell('J' . $r, (string)$expired, 0, true)
            . inventoryReportCell('K' . $r, (string)$ending, 0, true)
            . inventoryReportCell('L' . $r, number_format($unitCost, 2, '.', ''), 5, true)
            . inventoryReportCell('M' . $r, number_format($value, 2, '.', ''), 5, true)
            . inventoryReportCell('N' . $r, (string)$row['location'])
            . inventoryReportCell('O' . $r, (string)$row['status'])
            . '</row>';
    }
    $r++;
    $rowsXml .= '<row r="' . $r . '">'
        . inventoryReportCell('A' . $r, 'TOTAL', 2)
        . inventoryReportCell('E' . $r, (string)$totalOpening, 2, true)
        . inventoryReportCell('F' . $r, (string)$totalIn, 2, true)
        . inventoryReportCell('G' . $r, (string)$totalOut, 2, true)
        . inventoryReportCell('H' . $r, (string)$totalReturns, 2, true)
        . inventoryReportCell('I' . $r, (string)$totalReplacement, 2, true)
        . inventoryReportCell('J' . $r, (string)$totalExpired, 2, true)
        . inventoryReportCell('K' . $r, (string)$totalEnding, 2, true)
        . inventoryReportCell('M' . $r, number_format($totalValue, 2, '.', ''), 6, true)
        . '</row>';

    $txIn = 0;
    $txOut = 0;
    $txDamaged = 0;
    $tr = 1;
    $txXml = '<row r="' . $tr . '">' . inventoryReportCell('A' . $tr, 'ABC GENERAL MERCHANDISE', 1) . '</row>';
    $tr++;
    $txXml .= '<row r="' . $tr . '">' . inventoryReportCell('A' . $tr, 'Inventory Transactions', 2) . '</row>';
    $tr++;
    $txXml .= '<row r="' . $tr . '">' . inventoryReportCell('A' . $tr, 'Reporting Period: ' . (string)($report['period_label'] ?? '')) . '</row>';
    $tr++;
    $txXml .= '<row r="' . $tr . '">' . inventoryReportCell('A' . $tr, 'Filters: ' . (string)($report['filter_label'] ?? 'All Categories')) . '</row>';
    $tr++;
    $txXml .= '<row r="' . $tr . '">' . inventoryReportCell('A' . $tr, 'Date Prepared: ' . (string)($report['generated'] ?? date('F j, Y'))) . '</row>';
    $tr += 2;
    $txHeaders = ['Date', 'Reference', 'Product Code', 'Product', 'Category', 'Transaction Type', 'Quantity', 'Stock-In', 'Stock-Out', 'Damaged/Missing', 'Remaining Stock'];
    $txCols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
    $txHead = '';
    foreach ($txHeaders as $i => $h) {
        $txHead .= inventoryReportCell($txCols[$i] . $tr, $h, 4);
    }
    $txXml .= '<row r="' . $tr . '">' . $txHead . '</row>';
    $transactions = $report['transactions'] ?? [];
    if (!$transactions) {
        $tr++;
        $txXml .= '<row r="' . $tr . '">' . inventoryReportCell('A' . $tr, 'No records found for the selected filters.') . '</row>';
    }
    foreach ($transactions as $tx) {
        $txIn += (int)$tx['stock_in'];
        $txOut += (int)$tx['stock_out'];
        $txDamaged += (int)$tx['damaged'];
        $tr++;
        $txXml .= '<row r="' . $tr . '">'
            . inventoryReportCell('A' . $tr, (string)$tx['date'])
            . inventoryReportCell('B' . $tr, (string)$tx['reference'])
            . inventoryReportCell('C' . $tr, (string)$tx['sku'])
            . inventoryReportCell('D' . $tr, (string)$tx['product'])
            . inventoryReportCell('E' . $tr, (string)$tx['category'])
            . inventoryReportCell('F' . $tr, (string)$tx['type'])
            . inventoryReportCell('G' . $tr, (string)(int)$tx['quantity'], 0, true)
            . inventoryReportCell('H' . $tr, (string)(int)$tx['stock_in'], 0, true)
            . inventoryReportCell('I' . $tr, (string)(int)$tx['stock_out'], 0, true)
            . inventoryReportCell('J' . $tr, (string)(int)$tx['damaged'], 0, true)
            . inventoryReportCell('K' . $tr, (string)(int)$tx['remaining'], 0, true)
            . '</row>';
    }
    if ($transactions) {
        $tr++;
        $txXml .= '<row r="' . $tr . '">'
            . inventoryReportCell('A' . $tr, 'TOTAL', 2)
            . inventoryReportCell('G' . $tr, (string)($txIn + $txOut + $txDamaged), 2, true)
            . inventoryReportCell('H' . $tr, (string)$txIn, 2, true)
            . inventoryReportCell('I' . $tr, (string)$txOut, 2, true)
            . inventoryReportCell('J' . $tr, (string)$txDamaged, 2, true)
            . '</row>';
    }
    $txSheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols>'
        . '<col min="1" max="1" width="14" customWidth="1"/>'
        . '<col min="2" max="2" width="22" customWidth="1"/>'
        . '<col min="3" max="3" width="18" customWidth="1"/>'
        . '<col min="4" max="4" width="36" customWidth="1"/>'
        . '<col min="5" max="5" width="20" customWidth="1"/>'
        . '<col min="6" max="6" width="20" customWidth="1"/>'
        . '<col min="7" max="11" width="16" customWidth="1"/>'
        . '</cols><sheetData>' . $txXml . '</sheetData></worksheet>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols>'
        . '<col min="1" max="1" width="22" customWidth="1"/>'
        . '<col min="2" max="2" width="36" customWidth="1"/>'
        . '<col min="3" max="3" width="22" customWidth="1"/>'
        . '<col min="4" max="4" width="14" customWidth="1"/>'
        . '<col min="5" max="5" width="16" customWidth="1"/>'
        . '<col min="6" max="6" width="14" customWidth="1"/>'
        . '<col min="7" max="7" width="14" customWidth="1"/>'
        . '<col min="8" max="8" width="12" customWidth="1"/>'
        . '<col min="9" max="9" width="16" customWidth="1"/>'
        . '<col min="10" max="10" width="18" customWidth="1"/>'
        . '<col min="11" max="11" width="16" customWidth="1"/>'
        . '<col min="12" max="12" width="14" customWidth="1"/>'
        . '<col min="13" max="13" width="16" customWidth="1"/>'
        . '<col min="14" max="14" width="24" customWidth="1"/>'
        . '<col min="15" max="15" width="16" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/></numFmts>'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf xfId="0"/>'
        . '<xf xfId="0" fontId="1" applyFont="1"/>'
        . '<xf xfId="0" fontId="2" applyFont="1"/>'
        . '<xf xfId="0" numFmtId="14" applyNumberFormat="1"/>'
        . '<xf xfId="0" fontId="2" applyFont="1"/>'
        . '<xf xfId="0" numFmtId="164" applyNumberFormat="1"/>'
        . '<xf xfId="0" fontId="2" numFmtId="164" applyFont="1" applyNumberFormat="1"/>'
        . '</cellXfs>'
        . '</styleSheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Inventory Report" sheetId="1" r:id="rId1"/><sheet name="Transactions" sheetId="2" r:id="rId3"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    return inventoryReportZipStore([
        '[Content_Types].xml' => $types,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
        'xl/worksheets/sheet2.xml' => $txSheet,
    ]);
}

function inventoryReportLowStockXlsx(array $report, string $preparedBy = ''): string
{
    $rows = inventoryReportRowsByStatus($report['rows'] ?? [], 'Low Stock');
    $preparedBy = trim($preparedBy);
    $totalStock = 0;
    foreach ($rows as $row) {
        $totalStock += (int)($row['qty'] ?? 0);
    }
    $r = 1;
    $rowsXml = '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'ABC GENERAL MERCHANDISE', 1) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'LOW STOCK MONITORING REPORT', 2) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Reporting Period: ' . (string)($report['period_label'] ?? '')) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Prepared by: ' . ($preparedBy !== '' ? $preparedBy : '—')) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Date Prepared: ' . (string)($report['generated'] ?? date('F j, Y'))) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Total Low Stock Items: ' . count($rows), 2) . '</row>';
    $r += 2;
    $headers = ['SKU', 'Product Name', 'Category', 'Current Stock', 'Reorder Level', 'Location', 'Status', 'Recommended Action'];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    $cells = '';
    foreach ($headers as $i => $h) {
        $cells .= inventoryReportCell($cols[$i] . $r, $h, 4);
    }
    $rowsXml .= '<row r="' . $r . '">' . $cells . '</row>';
    foreach ($rows as $row) {
        $r++;
        $rowsXml .= '<row r="' . $r . '">'
            . inventoryReportCell('A' . $r, (string)($row['sku'] ?? ''))
            . inventoryReportCell('B' . $r, (string)($row['name'] ?? ''))
            . inventoryReportCell('C' . $r, (string)($row['category'] ?? ''))
            . inventoryReportCell('D' . $r, (string)(int)($row['qty'] ?? 0), 0, true)
            . inventoryReportCell('E' . $r, (string)(int)($row['reorder_level'] ?? 0), 0, true)
            . inventoryReportCell('F' . $r, (string)($row['location'] ?? ''))
            . inventoryReportCell('G' . $r, (string)($row['status'] ?? ''))
            . inventoryReportCell('H' . $r, inventoryReportRecommendedAction((string)($row['status'] ?? '')))
            . '</row>';
    }
    if ($rows) {
        $r++;
        $rowsXml .= '<row r="' . $r . '">'
            . inventoryReportCell('A' . $r, 'TOTAL', 2)
            . inventoryReportCell('D' . $r, (string)$totalStock, 2, true)
            . '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols>'
        . '<col min="1" max="1" width="28" customWidth="1"/>'
        . '<col min="2" max="2" width="36" customWidth="1"/>'
        . '<col min="3" max="3" width="20" customWidth="1"/>'
        . '<col min="4" max="4" width="16" customWidth="1"/>'
        . '<col min="5" max="5" width="16" customWidth="1"/>'
        . '<col min="6" max="6" width="22" customWidth="1"/>'
        . '<col min="7" max="7" width="16" customWidth="1"/>'
        . '<col min="8" max="8" width="24" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf xfId="0"/>'
        . '<xf xfId="0" fontId="1" applyFont="1"/>'
        . '<xf xfId="0" fontId="2" applyFont="1"/>'
        . '<xf xfId="0" numFmtId="14" applyNumberFormat="1"/>'
        . '<xf xfId="0" fontId="2" applyFont="1"/>'
        . '</cellXfs>'
        . '</styleSheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Low Stock Items" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    return inventoryReportZipStore([
        '[Content_Types].xml' => $types,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ]);
}

function inventoryReportLowStockMonitorRows(array $report, string $status, string $search): array
{
    $status = trim($status);
    if (!in_array($status, ['In Stock', 'Low Stock', 'Out of Stock'], true)) {
        $status = '';
    }
    $search = strtolower(trim($search));
    $out = [];
    foreach ($report['rows'] ?? [] as $row) {
        if ($status !== '' && (string)($row['status'] ?? '') !== $status) {
            continue;
        }
        if ($search !== '') {
            $hay = strtolower(implode(' ', [
                (string)($row['sku'] ?? ''),
                (string)($row['name'] ?? ''),
                (string)($row['category'] ?? ''),
                (string)($row['location'] ?? ''),
                (string)($row['status'] ?? ''),
            ]));
            if (strpos($hay, $search) === false) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

function inventoryReportLowStockMonitorXlsx(array $report, string $preparedBy = ''): string
{
    $rows = $report['rows'] ?? [];
    $preparedBy = trim($preparedBy);
    $r = 1;
    $rowsXml = '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'ABC GENERAL MERCHANDISE', 1) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'LOW STOCK MONITORING REPORT', 2) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Reporting Period: ' . (string)($report['period_label'] ?? '')) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Prepared by: ' . ($preparedBy !== '' ? $preparedBy : '—')) . '</row>';
    $r++;
    $rowsXml .= '<row r="' . $r . '">' . inventoryReportCell('A' . $r, 'Date Prepared: ' . (string)($report['generated'] ?? date('F j, Y'))) . '</row>';
    $r += 2;
    $headers = ['SKU / Product Code', 'Product Name', 'Category', 'Current Stock', 'Reorder Level', 'Location', 'Status', 'Recommended Action'];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    $cells = '';
    foreach ($headers as $i => $h) {
        $cells .= inventoryReportCell($cols[$i] . $r, $h, 4);
    }
    $rowsXml .= '<row r="' . $r . '">' . $cells . '</row>';
    foreach ($rows as $row) {
        $r++;
        $rowsXml .= '<row r="' . $r . '">'
            . inventoryReportCell('A' . $r, (string)($row['sku'] ?? ''))
            . inventoryReportCell('B' . $r, (string)($row['name'] ?? ''))
            . inventoryReportCell('C' . $r, (string)($row['category'] ?? ''))
            . inventoryReportCell('D' . $r, (string)(int)($row['qty'] ?? 0), 0, true)
            . inventoryReportCell('E' . $r, (string)(int)($row['reorder_level'] ?? 0), 0, true)
            . inventoryReportCell('F' . $r, (string)($row['location'] ?? ''))
            . inventoryReportCell('G' . $r, (string)($row['status'] ?? ''))
            . inventoryReportCell('H' . $r, inventoryReportRecommendedAction((string)($row['status'] ?? '')))
            . '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols>'
        . '<col min="1" max="1" width="22" customWidth="1"/>'
        . '<col min="2" max="2" width="36" customWidth="1"/>'
        . '<col min="3" max="3" width="20" customWidth="1"/>'
        . '<col min="4" max="4" width="16" customWidth="1"/>'
        . '<col min="5" max="5" width="16" customWidth="1"/>'
        . '<col min="6" max="6" width="22" customWidth="1"/>'
        . '<col min="7" max="7" width="16" customWidth="1"/>'
        . '<col min="8" max="8" width="24" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf xfId="0"/>'
        . '<xf xfId="0" fontId="1" applyFont="1"/>'
        . '<xf xfId="0" fontId="2" applyFont="1"/>'
        . '<xf xfId="0" numFmtId="14" applyNumberFormat="1"/>'
        . '<xf xfId="0" fontId="2" applyFont="1"/>'
        . '</cellXfs>'
        . '</styleSheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Low Stock Monitoring" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    return inventoryReportZipStore([
        '[Content_Types].xml' => $types,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ]);
}

function inventoryReportLowStockMonitorPdf(array $report, string $preparedBy = ''): string
{
    require_once __DIR__ . '/clarenzon_pdf_lib.php';
    $preparedBy = trim($preparedBy);
    $rows = [];
    foreach ($report['rows'] ?? [] as $row) {
        $rows[] = [
            (string)($row['sku'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($row['category'] ?? ''),
            (string)(int)($row['qty'] ?? 0),
            (string)(int)($row['reorder_level'] ?? 0),
            (string)($row['location'] ?? ''),
            (string)($row['status'] ?? ''),
            inventoryReportRecommendedAction((string)($row['status'] ?? '')),
        ];
    }
    return clarenzonPdfDocument([
        'title' => 'Low Stock Monitoring Report',
        'details' => [
            'Reporting Period: ' . (string)($report['period_label'] ?? ''),
            'Filters: ' . (string)($report['filter_label'] ?? 'All stock statuses'),
            'Prepared by: ' . ($preparedBy !== '' ? $preparedBy : '—'),
            'Date Prepared: ' . (string)($report['generated'] ?? date('F j, Y')),
        ],
        'summary' => [
            ['label' => 'Products', 'value' => (string)count($rows)],
        ],
        'blocks' => [[
            'heading' => 'Stock Status',
            'columns' => [
                ['label' => 'SKU / Product Code', 'width' => 1.3, 'align' => 'left'],
                ['label' => 'Product Name', 'width' => 2.2, 'align' => 'left'],
                ['label' => 'Category', 'width' => 1.3, 'align' => 'left'],
                ['label' => 'Current Stock', 'width' => 0.9, 'align' => 'right'],
                ['label' => 'Reorder Level', 'width' => 0.9, 'align' => 'right'],
                ['label' => 'Location', 'width' => 1.2, 'align' => 'left'],
                ['label' => 'Status', 'width' => 1.0, 'align' => 'center'],
                ['label' => 'Recommended Action', 'width' => 1.5, 'align' => 'left'],
            ],
            'rows' => $rows,
            'empty' => 'No records found for the selected filters.',
        ]],
    ]);
}

function inventoryReportCategories(mysqli $conn): array
{
    if (!inventoryReportHasTable($conn, 'categories')) {
        return [];
    }
    $rows = [];
    $res = $conn->query('SELECT id, name FROM categories ORDER BY name ASC');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rows[] = ['id' => (int)$row['id'], 'name' => $name];
        }
    }
    return $rows;
}

function inventoryReportPdf(array $report, string $preparedBy = ''): string
{
    require_once __DIR__ . '/clarenzon_pdf_lib.php';
    $preparedBy = trim($preparedBy);
    $title = (string)($report['report_type_label'] ?? 'Inventory Report');
    $rows = [];
    $stockOutTotal = 0;
    $valueTotal = 0.0;
    foreach ($report['rows'] ?? [] as $row) {
        $stockOut = (int)($row['stock_out'] ?? 0);
        $value = (float)($row['inventory_value'] ?? 0);
        $stockOutTotal += $stockOut;
        $valueTotal += $value;
        $rows[] = [
            (string)($row['sku'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($row['category'] ?? ''),
            (string)($row['unit'] ?? ''),
            (string)(int)($row['beginning'] ?? 0),
            (string)(int)($row['stock_in'] ?? 0),
            (string)$stockOut,
            (string)(int)($row['returns'] ?? 0),
            (string)(int)($row['damaged'] ?? 0),
            (string)(int)($row['expired'] ?? 0),
            (string)(int)($row['qty'] ?? 0),
            clarenzonPdfMoney((float)($row['unit_cost'] ?? 0)),
            clarenzonPdfMoney($value),
            (string)($row['location'] ?? ''),
            (string)($row['status'] ?? ''),
        ];
    }
    $total = $rows ? [
        'TOTAL', '', '', '', '', '', (string)$stockOutTotal, '', '', '', '', '', clarenzonPdfMoney($valueTotal), '', '',
    ] : null;
    $summary = $report['summary'] ?? [];
    $txRows = [];
    $txIn = 0;
    $txOut = 0;
    $txDamaged = 0;
    foreach ($report['transactions'] ?? [] as $tx) {
        $txIn += (int)($tx['stock_in'] ?? 0);
        $txOut += (int)($tx['stock_out'] ?? 0);
        $txDamaged += (int)($tx['damaged'] ?? 0);
        $txRows[] = [
            (string)($tx['date'] ?? ''),
            (string)($tx['reference'] ?? ''),
            (string)($tx['product'] ?? ''),
            (string)($tx['category'] ?? ''),
            (string)($tx['type'] ?? ''),
            (string)(int)($tx['quantity'] ?? 0),
            (string)(int)($tx['stock_in'] ?? 0),
            (string)(int)($tx['stock_out'] ?? 0),
            (string)(int)($tx['damaged'] ?? 0),
            (string)(int)($tx['remaining'] ?? 0),
        ];
    }
    $txTotal = $txRows ? ['TOTAL', '', '', '', '', (string)($txIn + $txOut + $txDamaged), (string)$txIn, (string)$txOut, (string)$txDamaged, ''] : null;
    return clarenzonPdfDocument([
        'title' => $title,
        'details' => [
            'Reporting Period: ' . (string)($report['period_label'] ?? ''),
            'Filters: ' . (string)($report['filter_label'] ?? 'All Categories'),
            'Prepared by: ' . ($preparedBy !== '' ? $preparedBy : '—'),
            'Date Prepared: ' . (string)($report['generated'] ?? date('F j, Y')),
        ],
        'summary' => [
            ['label' => 'Products', 'value' => number_format((int)($summary['products'] ?? 0))],
            ['label' => 'Stock Units', 'value' => number_format((int)($summary['units'] ?? 0))],
            ['label' => 'Stock-Out Qty', 'value' => number_format($stockOutTotal)],
            ['label' => 'Inventory Value', 'value' => clarenzonPdfMoney($valueTotal)],
        ],
        'blocks' => [[
            'heading' => $title,
            'columns' => [
                ['label' => 'SKU', 'width' => 1.05, 'align' => 'left'],
                ['label' => 'Product', 'width' => 1.8, 'align' => 'left'],
                ['label' => 'Category', 'width' => 1.15, 'align' => 'left'],
                ['label' => 'Unit', 'width' => 0.55, 'align' => 'left'],
                ['label' => 'Opening', 'width' => 0.7, 'align' => 'right'],
                ['label' => 'In', 'width' => 0.5, 'align' => 'right'],
                ['label' => 'Stock-Out', 'width' => 0.75, 'align' => 'right'],
                ['label' => 'Returns', 'width' => 0.65, 'align' => 'right'],
                ['label' => 'Replacement', 'width' => 0.85, 'align' => 'right'],
                ['label' => 'Expired', 'width' => 0.65, 'align' => 'right'],
                ['label' => 'Ending', 'width' => 0.65, 'align' => 'right'],
                ['label' => 'Unit Cost', 'width' => 0.9, 'align' => 'right'],
                ['label' => 'Value', 'width' => 0.95, 'align' => 'right'],
                ['label' => 'Location', 'width' => 0.9, 'align' => 'left'],
                ['label' => 'Status', 'width' => 0.8, 'align' => 'center'],
            ],
            'rows' => $rows,
            'total' => $total,
            'empty' => 'No records found for the selected filters.',
        ], [
            'heading' => 'Inventory Transactions',
            'columns' => [
                ['label' => 'Date', 'width' => 0.9, 'align' => 'center'],
                ['label' => 'Reference', 'width' => 1.1, 'align' => 'left'],
                ['label' => 'Product', 'width' => 1.8, 'align' => 'left'],
                ['label' => 'Category', 'width' => 1.1, 'align' => 'left'],
                ['label' => 'Type', 'width' => 1.15, 'align' => 'left'],
                ['label' => 'Qty', 'width' => 0.55, 'align' => 'right'],
                ['label' => 'Stock-In', 'width' => 0.7, 'align' => 'right'],
                ['label' => 'Stock-Out', 'width' => 0.75, 'align' => 'right'],
                ['label' => 'Damaged/Missing', 'width' => 1.0, 'align' => 'right'],
                ['label' => 'Remaining', 'width' => 0.75, 'align' => 'right'],
            ],
            'rows' => $txRows,
            'total' => $txTotal,
            'empty' => 'No records found for the selected filters.',
        ]],
    ]);
}
