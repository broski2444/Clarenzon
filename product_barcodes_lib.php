<?php
/**
 * One product → many barcodes.
 * products.barcode remains as a deprecated/primary display field so existing
 * queries keep working. New registrations live in product_barcodes.
 */

function productBarcodesEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS product_barcodes (
        barcode_id INT AUTO_INCREMENT PRIMARY KEY,
        barcode_value VARCHAR(100) NOT NULL,
        product_id INT NOT NULL,
        sku VARCHAR(50) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        purchase_order_id INT NULL,
        receiving_transaction_id INT NULL,
        supplier_id INT NULL,
        batch_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        registered_by INT NULL,
        registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_product_barcodes_value (barcode_value),
        INDEX idx_pb_product (product_id),
        INDEX idx_pb_status (status),
        INDEX idx_pb_po (purchase_order_id),
        INDEX idx_pb_receipt (receiving_transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS barcode_reassignment_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barcode_id INT NULL,
        barcode_value VARCHAR(100) NOT NULL,
        from_product_id INT NULL,
        to_product_id INT NOT NULL,
        reason TEXT NOT NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'admin',
        purchase_order_id INT NULL,
        receiving_transaction_id INT NULL,
        overridden_by INT NULL,
        overridden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_brl_barcode (barcode_value),
        INDEX idx_brl_to_product (to_product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    productBarcodesBackfillFromProducts($conn);
    productBarcodesConsolidateDuplicates($conn);
}

/**
 * Keep one identity per barcode_value. Quantity lives on receiving/inventory, not extra rows.
 */
function productBarcodesConsolidateDuplicates(mysqli $conn): void
{
    static $done = false;
    if ($done || !$conn || !productBarcodesTableReady($conn)) {
        return;
    }
    $done = true;
    $d = @$conn->query("SELECT UPPER(TRIM(barcode_value)) AS k, MIN(barcode_id) AS keep_id, COUNT(*) AS c
        FROM product_barcodes
        GROUP BY UPPER(TRIM(barcode_value))
        HAVING c > 1");
    if (!$d) {
        return;
    }
    while ($row = $d->fetch_assoc()) {
        $keep = (int)($row['keep_id'] ?? 0);
        $key = (string)($row['k'] ?? '');
        if ($keep <= 0 || $key === '') {
            continue;
        }
        $st = $conn->prepare('DELETE FROM product_barcodes WHERE UPPER(TRIM(barcode_value)) = ? AND barcode_id <> ?');
        if (!$st) {
            continue;
        }
        $st->bind_param('si', $key, $keep);
        $st->execute();
        $st->close();
    }
}

function productBarcodesNormalize(string $value): string {
    return trim($value);
}

function productBarcodesKey(string $value): string {
    return strtoupper(trim($value));
}

function productBarcodesBackfillFromProducts(mysqli $conn): void {
    $tbl = $conn->query("SHOW TABLES LIKE 'product_barcodes'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return;
    }
    $col = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
    if (!($col && $col->num_rows > 0)) {
        return;
    }

    $sql = "INSERT IGNORE INTO product_barcodes
            (barcode_value, product_id, sku, source, status, is_primary, registered_at)
            SELECT TRIM(p.barcode), p.id, p.sku, 'manual', 'active', 1,
                   COALESCE(p.created_at, CURRENT_TIMESTAMP)
            FROM products p
            LEFT JOIN product_barcodes pb
                ON UPPER(TRIM(pb.barcode_value)) = UPPER(TRIM(p.barcode))
            WHERE p.barcode IS NOT NULL
              AND TRIM(p.barcode) <> ''
              AND pb.barcode_id IS NULL";
    @$conn->query($sql);

    $fixPrimary = $conn->query("SELECT product_id
        FROM product_barcodes
        WHERE status='active'
        GROUP BY product_id
        HAVING SUM(is_primary) = 0");
    if ($fixPrimary) {
        while ($row = $fixPrimary->fetch_assoc()) {
            $pid = (int)$row['product_id'];
            if ($pid > 0) {
                $conn->query("UPDATE product_barcodes SET is_primary=1
                    WHERE product_id=$pid AND status='active'
                    ORDER BY barcode_id ASC LIMIT 1");
            }
        }
    }
}

function productBarcodesTableReady(mysqli $conn): bool {
    $tbl = $conn->query("SHOW TABLES LIKE 'product_barcodes'");
    return (bool)($tbl && $tbl->num_rows > 0);
}

/**
 * @return array<string,mixed>|null
 */
function productBarcodesFind(mysqli $conn, string $barcode): ?array {
    $barcode = productBarcodesNormalize($barcode);
    if ($barcode === '' || !productBarcodesTableReady($conn)) {
        return productBarcodesFindLegacy($conn, $barcode);
    }
    $key = productBarcodesKey($barcode);
    $sql = "SELECT pb.*, p.sku AS product_sku, p.name AS product_name, p.barcode AS product_barcode
            FROM product_barcodes pb
            INNER JOIN products p ON p.id = pb.product_id
            WHERE UPPER(TRIM(pb.barcode_value)) = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return productBarcodesFindLegacy($conn, $barcode);
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return $row;
    }
    return productBarcodesFindLegacy($conn, $barcode);
}

/**
 * @return array<string,mixed>|null
 */
function productBarcodesFindLegacy(mysqli $conn, string $barcode): ?array {
    $barcode = productBarcodesNormalize($barcode);
    if ($barcode === '') {
        return null;
    }
    $col = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
    if (!($col && $col->num_rows > 0)) {
        return null;
    }
    $key = productBarcodesKey($barcode);
    $stmt = $conn->prepare("SELECT p.id AS product_id, p.sku, p.sku AS product_sku, p.name AS product_name,
                                   p.barcode AS barcode_value, p.barcode AS product_barcode,
                                   'manual' AS source, 'active' AS status, 1 AS is_primary,
                                   NULL AS barcode_id
                            FROM products p
                            WHERE UPPER(TRIM(p.barcode)) = ?
                            LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function productBarcodesFindProductId(mysqli $conn, string $barcode): int {
    $row = productBarcodesFind($conn, $barcode);
    return $row ? (int)$row['product_id'] : 0;
}

/**
 * Registered barcode = prefix + ending. When the typed value is only the ending,
 * return the one existing barcode it completes. Does not insert or edit barcodes.
 */
function productBarcodesCompleteRegisteredSuffix(mysqli $conn, string $ending): ?string
{
    $endingKey = productBarcodesKey($ending);
    if ($endingKey === '' || !productBarcodesTableReady($conn)) {
        return null;
    }
    if (productBarcodesFind($conn, $endingKey)) {
        return null;
    }
    $chars = function_exists('mb_strlen') ? mb_strlen($endingKey, 'UTF-8') : strlen($endingKey);
    if ($chars < 1) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT barcode_value FROM product_barcodes
         WHERE status='active'
           AND CHAR_LENGTH(TRIM(barcode_value)) > ?
           AND UPPER(RIGHT(TRIM(barcode_value), ?)) = ?"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iis', $chars, $chars, $endingKey);
    $stmt->execute();
    $res = $stmt->get_result();
    $matches = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $value = trim((string)($row['barcode_value'] ?? ''));
            $key = productBarcodesKey($value);
            if ($key === '' || isset($matches[$key])) {
                continue;
            }
            $prefix = function_exists('mb_substr')
                ? mb_substr($value, 0, mb_strlen($value, 'UTF-8') - $chars, 'UTF-8')
                : substr($value, 0, strlen($value) - $chars);
            if ($prefix === '' || productBarcodesKey($prefix . $endingKey) !== $key) {
                continue;
            }
            $matches[$key] = $value;
        }
    }
    $stmt->close();
    if (count($matches) !== 1) {
        return null;
    }
    return array_values($matches)[0];
}

/**
 * @return array<int,array<int,array<string,mixed>>>
 */
function productBarcodesMapForProductIds(mysqli $conn, array $productIds): array {
    $ids = [];
    foreach ($productIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids || !productBarcodesTableReady($conn)) {
        return [];
    }
    $list = implode(',', $ids);
    $sql = "SELECT pb.*, po.po_number, rec.receipt_number, s.name AS supplier_name,
                   u.full_name AS registered_by_name
            FROM product_barcodes pb
            LEFT JOIN purchase_orders po ON po.id = pb.purchase_order_id
            LEFT JOIN purchase_receipts rec ON rec.id = pb.receiving_transaction_id
            LEFT JOIN suppliers s ON s.id = pb.supplier_id
            LEFT JOIN users u ON u.id = pb.registered_by
            WHERE pb.product_id IN ($list)
            ORDER BY pb.is_primary DESC, pb.registered_at ASC, pb.barcode_id ASC";
    $res = $conn->query($sql);
    $map = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['product_id'];
            $map[$pid][] = productBarcodesFormatRow($row);
        }
    }
    return $map;
}

/**
 * @return array<int,array<string,mixed>>
 */
function productBarcodesListForProduct(mysqli $conn, int $productId): array {
    if ($productId <= 0) {
        return [];
    }
    $map = productBarcodesMapForProductIds($conn, [$productId]);
    return $map[$productId] ?? [];
}

/**
 * All registered barcodes for admin stock lookup (received / scanned / primary).
 *
 * @return array<int,array<string,mixed>>
 */
function productBarcodesEntrySourceKey(string $storedSource, string $inputMethod): string {
    $method = strtolower(trim($inputMethod));
    $source = strtolower(trim($storedSource));
    $scanner = ['camera', 'scanner', 'barcode scanner', 'barcode_scanner'];
    $split = ['split', 'split barcode entry', 'split_barcode'];
    if (in_array($method, $scanner, true) || in_array($source, $scanner, true)) {
        return 'scanner';
    }
    if (in_array($method, $split, true) || in_array($source, $split, true)) {
        return 'split';
    }
    if ($method === 'manual' || $source === 'manual') {
        return 'manual';
    }
    return '';
}

function productBarcodesEntrySourceLabel(string $key): string {
    if ($key === 'scanner') {
        return 'Barcode Scanner';
    }
    if ($key === 'split') {
        return 'Split Barcode Entry';
    }
    if ($key === 'manual') {
        return 'Manual';
    }
    return '';
}

/**
 * Latest receiving entry method for each barcode. Does not insert rows.
 *
 * @return array<string,array<string,mixed>>
 */
/**
 * Existing Split Barcode Entry rows were stored with the full barcode and
 * input method Manual. A line that shares one First Barcode and different
 * Last Digits is that saved split set. This only classifies rows for display.
 *
 * @return array<string,true>
 */
function productBarcodesSplitEntryKeys(mysqli $conn): array {
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return [];
    }
    $res = $conn->query("SELECT receipt_item_id, barcode_value, input_method FROM purchase_receipt_inspection_barcodes");
    $groups = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $method = strtolower(trim((string)($row['input_method'] ?? '')));
            if (!in_array($method, ['manual', 'split', 'split barcode entry'], true)) {
                continue;
            }
            $itemId = (int)($row['receipt_item_id'] ?? 0);
            $code = trim((string)($row['barcode_value'] ?? ''));
            if ($itemId <= 0 || $code === '') {
                continue;
            }
            $groups[$itemId][productBarcodesKey($code)] = $code;
        }
    }
    $keys = [];
    foreach ($groups as $codes) {
        $codes = array_values($codes);
        if (count($codes) < 2) {
            continue;
        }
        $prefix = $codes[0];
        foreach ($codes as $code) {
            $len = min(strlen($prefix), strlen($code));
            $i = 0;
            while ($i < $len && $prefix[$i] === $code[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
        }
        if (strlen($prefix) < 3) {
            continue;
        }
        $splitLine = true;
        foreach ($codes as $code) {
            if (strlen($code) <= strlen($prefix)) {
                $splitLine = false;
                break;
            }
        }
        if (!$splitLine) {
            continue;
        }
        foreach ($codes as $code) {
            $keys[productBarcodesKey($code)] = true;
        }
    }
    return $keys;
}

function productBarcodesInspectionByKey(mysqli $conn): array {
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return [];
    }
    $sql = "SELECT i.barcode_value, i.input_method, i.status, i.product_id, i.recorded_at,
                   p.sku AS product_sku, p.name AS product_name, p.quantity_in_stock,
                   p.status AS product_status, p.selling_price,
                   po.po_number, rec.receipt_number
            FROM purchase_receipt_inspection_barcodes i
            LEFT JOIN products p ON p.id = i.product_id
            LEFT JOIN purchase_orders po ON po.id = i.po_id
            LEFT JOIN purchase_receipts rec ON rec.id = i.receipt_id
            ORDER BY i.id ASC";
    $res = $conn->query($sql);
    $map = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = productBarcodesKey((string)($row['barcode_value'] ?? ''));
            if ($key === '') {
                continue;
            }
            $map[$key] = $row;
        }
    }
    return $map;
}

function productBarcodesListInStock(mysqli $conn, string $search = '', string $source = '', string $status = 'active'): array {
    productBarcodesEnsureSchema($conn);
    if (!productBarcodesTableReady($conn)) {
        return [];
    }
    $sellableMap = function_exists('invWmsSellableQtyMap') ? invWmsSellableQtyMap($conn) : [];
    $sql = "SELECT pb.*, p.sku AS product_sku, p.name AS product_name, p.quantity_in_stock,
                   p.status AS product_status, p.selling_price,
                   po.po_number, rec.receipt_number, s.name AS supplier_name,
                   u.full_name AS registered_by_name
            FROM product_barcodes pb
            INNER JOIN products p ON p.id = pb.product_id
            LEFT JOIN purchase_orders po ON po.id = pb.purchase_order_id
            LEFT JOIN purchase_receipts rec ON rec.id = pb.receiving_transaction_id
            LEFT JOIN suppliers s ON s.id = pb.supplier_id
            LEFT JOIN users u ON u.id = pb.registered_by
            ORDER BY pb.registered_at DESC, pb.barcode_id DESC
            LIMIT 2000";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $inspection = productBarcodesInspectionByKey($conn);
    $splitKeys = productBarcodesSplitEntryKeys($conn);
    $rows = [];
    $seen = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $formatted = productBarcodesFormatRow($row);
            $key = productBarcodesKey((string)$formatted['barcode_value']);
            $seen[$key] = true;
            $method = (string)($inspection[$key]['input_method'] ?? '');
            $entry = productBarcodesEntrySourceKey((string)$formatted['source'], $method);
            if (($entry === '' || $entry === 'manual') && isset($splitKeys[$key])) {
                $entry = 'split';
            }
            $formatted['source'] = $entry !== '' ? $entry : (string)$formatted['source'];
            $pid = (int)$row['product_id'];
            $onHand = (int)($row['quantity_in_stock'] ?? 0);
            $formatted['product_name'] = (string)($row['product_name'] ?? '');
            $formatted['sku'] = (string)($row['product_sku'] ?? $formatted['sku']);
            $formatted['quantity_in_stock'] = $onHand;
            $formatted['sellable_qty'] = (int)($sellableMap[$pid] ?? $onHand);
            $formatted['product_status'] = (string)($row['product_status'] ?? '');
            $formatted['selling_price'] = (float)($row['selling_price'] ?? 0);
            if ($formatted['receipt_number'] === '' && !empty($inspection[$key]['receipt_number'])) {
                $formatted['receipt_number'] = (string)$inspection[$key]['receipt_number'];
            }
            if ($formatted['po_number'] === '' && !empty($inspection[$key]['po_number'])) {
                $formatted['po_number'] = (string)$inspection[$key]['po_number'];
            }
            $rows[] = $formatted;
        }
    }
    $stmt->close();
    foreach ($inspection as $key => $row) {
        if (isset($seen[$key]) || trim((string)($row['barcode_value'] ?? '')) === '') {
            continue;
        }
        $inspectStatus = strtolower(trim((string)($row['status'] ?? '')));
        $active = in_array($inspectStatus, ['good', 'replacement', 'active'], true);
        $pid = (int)($row['product_id'] ?? 0);
        $onHand = (int)($row['quantity_in_stock'] ?? 0);
        $entry = productBarcodesEntrySourceKey('', (string)($row['input_method'] ?? ''));
        if (($entry === '' || $entry === 'manual') && isset($splitKeys[$key])) {
            $entry = 'split';
        }
        $rows[] = [
            'barcode_id' => 0,
            'barcode_value' => (string)$row['barcode_value'],
            'product_id' => $pid,
            'sku' => (string)($row['product_sku'] ?? ''),
            'source' => $entry,
            'purchase_order_id' => null,
            'receiving_transaction_id' => null,
            'supplier_id' => null,
            'batch_id' => null,
            'status' => $active ? 'active' : (string)($row['status'] ?? ''),
            'registered_by' => null,
            'registered_at' => (string)($row['recorded_at'] ?? ''),
            'is_primary' => false,
            'po_number' => (string)($row['po_number'] ?? ''),
            'receipt_number' => (string)($row['receipt_number'] ?? ''),
            'supplier_name' => '',
            'registered_by_name' => '',
            'product_name' => (string)($row['product_name'] ?? ''),
            'quantity_in_stock' => $onHand,
            'sellable_qty' => (int)($sellableMap[$pid] ?? $onHand),
            'product_status' => (string)($row['product_status'] ?? ''),
            'selling_price' => (float)($row['selling_price'] ?? 0),
        ];
    }
    return productBarcodesFilterStockRows($rows, $search, $source, $status);
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function productBarcodesFilterStockRows(array $rows, string $search, string $source, string $status): array {
    $status = strtolower(trim($status));
    $source = strtolower(trim($source));
    if (in_array($source, ['barcode scanner', 'barcode_scanner', 'camera'], true)) {
        $source = 'scanner';
    } elseif (in_array($source, ['split barcode entry', 'split_barcode'], true)) {
        $source = 'split';
    }
    $search = strtolower(trim($search));
    $out = [];
    foreach ($rows as $row) {
        $rowStatus = strtolower(trim((string)($row['status'] ?? '')));
        if ($status !== '' && $status !== 'all' && $rowStatus !== $status) {
            continue;
        }
        $entry = productBarcodesEntrySourceKey((string)($row['source'] ?? ''), '');
        if ($entry === '' && in_array(strtolower((string)($row['source'] ?? '')), ['scanner', 'split', 'manual'], true)) {
            $entry = strtolower((string)$row['source']);
        }
        if ($source !== '' && $entry !== $source) {
            continue;
        }
        if ($search !== '') {
            $label = strtolower(productBarcodesEntrySourceLabel($entry));
            $hay = strtolower(implode(' ', [
                (string)($row['barcode_value'] ?? ''),
                (string)($row['product_name'] ?? ''),
                (string)($row['sku'] ?? ''),
                (string)($row['receipt_number'] ?? ''),
                (string)($row['po_number'] ?? ''),
                $label,
                $entry,
            ]));
            if (strpos($hay, $search) === false) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

function productBarcodesRenderStockSection(): void {
    ?>
        <div id="section-stock-barcodes" class="content-section">
            <div class="page-header">
                <h1><i class="bi bi-upc text-primary me-2"></i>Stock Barcodes</h1>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="card-title mb-0">Registered barcodes</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="text" id="stockBarcodeSearch" class="form-control form-control-sm" placeholder="Search barcode, SKU, product…" style="min-width:220px;" onkeyup="if(event.key==='Enter')loadStockBarcodes()">
                        <select id="stockBarcodeSource" class="form-select form-select-sm" style="width:140px;" onchange="loadStockBarcodes()">
                            <option value="">All sources</option>
                            <option value="manual">Manual</option>
                            <option value="scanner">Barcode Scanner</option>
                            <option value="split">Split Barcode Entry</option>
                        </select>
                        <select id="stockBarcodeStatus" class="form-select form-select-sm" style="width:120px;" onchange="loadStockBarcodes()">
                            <option value="active" selected>Active</option>
                            <option value="all">All status</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadStockBarcodes()"><i class="bi bi-search"></i> Search</button>
                    </div>
                </div>
                <div class="table-container system-table-scroll">
                    <table class="modern-table" id="stockBarcodesTable">
                        <thead>
                            <tr>
                                <th>Barcode</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Qty</th>
                                <th>Sellable</th>
                                <th>Source</th>
                                <th>Receipt / PO</th>
                                <th>Registered</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="stockBarcodesBody">
                            <tr><td colspan="9" class="text-center text-muted py-4">Open this page to load barcodes…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function productBarcodesFormatRow(array $row): array {
    return [
        'barcode_id' => (int)($row['barcode_id'] ?? 0),
        'barcode_value' => (string)($row['barcode_value'] ?? ''),
        'product_id' => (int)($row['product_id'] ?? 0),
        'sku' => (string)($row['sku'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'purchase_order_id' => isset($row['purchase_order_id']) && $row['purchase_order_id'] !== null ? (int)$row['purchase_order_id'] : null,
        'receiving_transaction_id' => isset($row['receiving_transaction_id']) && $row['receiving_transaction_id'] !== null ? (int)$row['receiving_transaction_id'] : null,
        'supplier_id' => isset($row['supplier_id']) && $row['supplier_id'] !== null ? (int)$row['supplier_id'] : null,
        'batch_id' => isset($row['batch_id']) && $row['batch_id'] !== null ? (int)$row['batch_id'] : null,
        'status' => (string)($row['status'] ?? 'active'),
        'registered_by' => isset($row['registered_by']) && $row['registered_by'] !== null ? (int)$row['registered_by'] : null,
        'registered_at' => (string)($row['registered_at'] ?? ''),
        'is_primary' => (int)($row['is_primary'] ?? 0) === 1,
        'po_number' => (string)($row['po_number'] ?? ''),
        'receipt_number' => (string)($row['receipt_number'] ?? ''),
        'supplier_name' => (string)($row['supplier_name'] ?? ''),
        'registered_by_name' => (string)($row['registered_by_name'] ?? ''),
    ];
}

function productBarcodesPrimaryValue(mysqli $conn, int $productId): string {
    $list = productBarcodesListForProduct($conn, $productId);
    foreach ($list as $row) {
        if (!empty($row['is_primary']) && strtolower((string)$row['status']) === 'active') {
            return (string)$row['barcode_value'];
        }
    }
    foreach ($list as $row) {
        if (strtolower((string)$row['status']) === 'active' && trim((string)$row['barcode_value']) !== '') {
            return (string)$row['barcode_value'];
        }
    }
    return '';
}

function productBarcodesSyncPrimary(mysqli $conn, int $productId): void {
    if ($productId <= 0) {
        return;
    }
    $col = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
    if (!($col && $col->num_rows > 0)) {
        return;
    }
    $primary = productBarcodesPrimaryValue($conn, $productId);
    if ($primary === '') {
        $stmt = $conn->prepare('UPDATE products SET barcode=NULL WHERE id=?');
        if ($stmt) {
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    $stmt = $conn->prepare('UPDATE products SET barcode=? WHERE id=?');
    if ($stmt) {
        $stmt->bind_param('si', $primary, $productId);
        $stmt->execute();
        $stmt->close();
    }
}

function productBarcodesProductSku(mysqli $conn, int $productId): string {
    $stmt = $conn->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $sku = (string)($stmt->get_result()->fetch_assoc()['sku'] ?? '');
    $stmt->close();
    return $sku;
}

/**
 * Register a barcode onto a product. Never silently reassigns a barcode that
 * already belongs to a different product.
 *
 * @param array{
 *   source?:string,
 *   purchase_order_id?:?int,
 *   receiving_transaction_id?:?int,
 *   supplier_id?:?int,
 *   batch_id?:?int,
 *   registered_by?:?int,
 *   is_primary?:bool,
 *   override?:bool,
 *   override_reason?:string,
 *   override_source?:string
 * } $meta
 * @return array{ok:bool,status:string,message:string,barcode?:string,conflict?:array,record?:array}
 */
function productBarcodesRegister(mysqli $conn, int $productId, string $barcode, array $meta = []): array {
    productBarcodesEnsureSchema($conn);
    $barcode = productBarcodesNormalize($barcode);
    if ($productId <= 0) {
        return ['ok' => false, 'status' => 'error', 'message' => 'Product is required to register a barcode.'];
    }
    if ($barcode === '') {
        return ['ok' => true, 'status' => 'skipped', 'message' => 'No barcode provided.', 'barcode' => ''];
    }

    $existing = productBarcodesFind($conn, $barcode);
    $override = !empty($meta['override']);
    $reason = trim((string)($meta['override_reason'] ?? ''));

    if ($existing) {
        $linkedId = (int)$existing['product_id'];
        if ($linkedId === $productId) {
            $status = strtolower((string)($existing['status'] ?? 'active'));
            if ($status !== 'active' && !empty($existing['barcode_id'])) {
                $bid = (int)$existing['barcode_id'];
                $conn->query("UPDATE product_barcodes SET status='active' WHERE barcode_id=$bid");
            }
            if (!empty($existing['barcode_id'])) {
                productBarcodesStampReceiving(
                    $conn,
                    $productId,
                    [(string)$existing['barcode_value']],
                    (int)($meta['receiving_transaction_id'] ?? 0),
                    (int)($meta['purchase_order_id'] ?? 0)
                );
                return [
                    'ok' => true,
                    'status' => 'linked',
                    'message' => 'Barcode already linked to this product.',
                    'barcode' => (string)$existing['barcode_value'],
                    'record' => $existing,
                ];
            }
            // Legacy products.barcode match only — continue and insert product_barcodes row.
        } else {
            if (!$override) {
                return [
                    'ok' => false,
                    'status' => 'conflict',
                    'message' => 'This barcode is already registered to a different product.',
                    'barcode' => $barcode,
                    'conflict' => [
                        'barcode' => (string)$existing['barcode_value'],
                        'product_id' => $linkedId,
                        'product_name' => (string)($existing['product_name'] ?? ''),
                        'sku' => (string)($existing['product_sku'] ?? $existing['sku'] ?? ''),
                    ],
                ];
            }
            if ($reason === '') {
                return ['ok' => false, 'status' => 'conflict', 'message' => 'A reason is required to override a barcode conflict.', 'barcode' => $barcode];
            }
            $moved = productBarcodesReassign($conn, $barcode, $productId, $reason, $meta);
            if (!$moved['ok']) {
                return $moved;
            }
            return [
                'ok' => true,
                'status' => 'reassigned',
                'message' => 'Barcode reassigned to this product.',
                'barcode' => $barcode,
                'record' => $moved['record'] ?? null,
            ];
        }
    }

    $source = trim((string)($meta['source'] ?? 'manual'));
    if (!in_array($source, ['manual', 'received'], true)) {
        $source = 'manual';
    }
    $sku = productBarcodesProductSku($conn, $productId);
    $poId = isset($meta['purchase_order_id']) ? (int)$meta['purchase_order_id'] : 0;
    $receiptId = isset($meta['receiving_transaction_id']) ? (int)$meta['receiving_transaction_id'] : 0;
    $supplierId = isset($meta['supplier_id']) ? (int)$meta['supplier_id'] : 0;
    $batchId = isset($meta['batch_id']) ? (int)$meta['batch_id'] : 0;
    $userId = isset($meta['registered_by']) ? (int)$meta['registered_by'] : 0;
    $hasAny = productBarcodesListForProduct($conn, $productId);
    $makePrimary = !empty($meta['is_primary']) || !$hasAny;

    $sql = "INSERT INTO product_barcodes
            (barcode_value, product_id, sku, source, purchase_order_id, receiving_transaction_id,
             supplier_id, batch_id, status, registered_by, is_primary)
            VALUES (?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), 'active', NULLIF(?,0), ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'status' => 'error', 'message' => 'Could not register barcode.'];
    }
    $isPrimary = $makePrimary ? 1 : 0;
    $stmt->bind_param(
        'sissiiiiii',
        $barcode,
        $productId,
        $sku,
        $source,
        $poId,
        $receiptId,
        $supplierId,
        $batchId,
        $userId,
        $isPrimary
    );
    try {
        if (!$stmt->execute()) {
            $err = (string)$stmt->error;
            $stmt->close();
            if (stripos($err, 'duplicate') !== false) {
                $again = productBarcodesFind($conn, $barcode);
                if ($again && (int)$again['product_id'] === $productId) {
                    return ['ok' => true, 'status' => 'linked', 'message' => 'Barcode already linked to this product.', 'barcode' => $barcode, 'record' => $again];
                }
                return ['ok' => false, 'status' => 'conflict', 'message' => 'This barcode is already registered to a different product.', 'barcode' => $barcode];
            }
            return ['ok' => false, 'status' => 'error', 'message' => 'Could not register barcode.'];
        }
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        if (stripos($e->getMessage(), 'duplicate') !== false) {
            $again = productBarcodesFind($conn, $barcode);
            if ($again && (int)$again['product_id'] === $productId) {
                return ['ok' => true, 'status' => 'linked', 'message' => 'Barcode already linked to this product.', 'barcode' => $barcode, 'record' => $again];
            }
            return ['ok' => false, 'status' => 'conflict', 'message' => 'This barcode is already registered to a different product.', 'barcode' => $barcode];
        }
        throw $e;
    }
    $newId = (int)$conn->insert_id;
    $stmt->close();

    if ($makePrimary) {
        $conn->query("UPDATE product_barcodes SET is_primary=0 WHERE product_id=$productId AND barcode_id<>$newId");
        productBarcodesSyncPrimary($conn, $productId);
    }

    return [
        'ok' => true,
        'status' => 'registered',
        'message' => 'Barcode registered to this product.',
        'barcode' => $barcode,
        'record' => ['barcode_id' => $newId, 'product_id' => $productId, 'barcode_value' => $barcode],
    ];
}

/**
 * @param array<string,mixed> $meta
 * @return array{ok:bool,status:string,message:string,record?:array}
 */
function productBarcodesReassign(mysqli $conn, string $barcode, int $toProductId, string $reason, array $meta = []): array {
    productBarcodesEnsureSchema($conn);
    $barcode = productBarcodesNormalize($barcode);
    $reason = trim($reason);
    if ($barcode === '' || $toProductId <= 0) {
        return ['ok' => false, 'status' => 'error', 'message' => 'Barcode and destination product are required.'];
    }
    if ($reason === '') {
        return ['ok' => false, 'status' => 'error', 'message' => 'A reason is required to reassign a barcode.'];
    }

    $existing = productBarcodesFind($conn, $barcode);
    $fromId = $existing ? (int)$existing['product_id'] : 0;
    $barcodeId = $existing && !empty($existing['barcode_id']) ? (int)$existing['barcode_id'] : 0;
    $sku = productBarcodesProductSku($conn, $toProductId);
    $userId = isset($meta['registered_by']) ? (int)$meta['registered_by'] : 0;
    $poId = isset($meta['purchase_order_id']) ? (int)$meta['purchase_order_id'] : 0;
    $receiptId = isset($meta['receiving_transaction_id']) ? (int)$meta['receiving_transaction_id'] : 0;
    $source = trim((string)($meta['override_source'] ?? $meta['source'] ?? 'admin'));

    if ($barcodeId > 0) {
        $upd = $conn->prepare("UPDATE product_barcodes
            SET product_id=?, sku=?, source=IF(source='received','received','manual'), status='active', is_primary=0
            WHERE barcode_id=?");
        if (!$upd) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Could not reassign barcode.'];
        }
        $upd->bind_param('isi', $toProductId, $sku, $barcodeId);
        if (!$upd->execute()) {
            $upd->close();
            return ['ok' => false, 'status' => 'error', 'message' => 'Could not reassign barcode.'];
        }
        $upd->close();
    } else {
        $ins = productBarcodesRegister($conn, $toProductId, $barcode, array_merge($meta, [
            'override' => false,
            'is_primary' => false,
        ]));
        if (!$ins['ok']) {
            return $ins;
        }
        $again = productBarcodesFind($conn, $barcode);
        $barcodeId = $again ? (int)($again['barcode_id'] ?? 0) : 0;
    }

    $log = $conn->prepare("INSERT INTO barcode_reassignment_log
        (barcode_id, barcode_value, from_product_id, to_product_id, reason, source,
         purchase_order_id, receiving_transaction_id, overridden_by)
        VALUES (NULLIF(?,0), ?, NULLIF(?,0), ?, ?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0))");
    if ($log) {
        $log->bind_param(
            'isiissiii',
            $barcodeId,
            $barcode,
            $fromId,
            $toProductId,
            $reason,
            $source,
            $poId,
            $receiptId,
            $userId
        );
        $log->execute();
        $log->close();
    }

    if ($fromId > 0 && $fromId !== $toProductId) {
        $hasPrimary = $conn->query("SELECT barcode_id FROM product_barcodes WHERE product_id=$fromId AND is_primary=1 AND status='active' LIMIT 1");
        if (!($hasPrimary && $hasPrimary->num_rows > 0)) {
            $conn->query("UPDATE product_barcodes SET is_primary=1 WHERE product_id=$fromId AND status='active' ORDER BY barcode_id ASC LIMIT 1");
        }
        productBarcodesSyncPrimary($conn, $fromId);
    }
    $destList = productBarcodesListForProduct($conn, $toProductId);
    $hasDestPrimary = false;
    foreach ($destList as $row) {
        if (!empty($row['is_primary'])) {
            $hasDestPrimary = true;
            break;
        }
    }
    if (!$hasDestPrimary && $barcodeId > 0) {
        $conn->query("UPDATE product_barcodes SET is_primary=1 WHERE barcode_id=$barcodeId");
    }
    productBarcodesSyncPrimary($conn, $toProductId);

    return [
        'ok' => true,
        'status' => 'reassigned',
        'message' => 'Barcode reassigned.',
        'record' => productBarcodesFind($conn, $barcode),
    ];
}

/**
 * Attach barcodes[] and keep barcode as the primary/display value.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function productBarcodesHydrateRows(mysqli $conn, array $rows, string $idKey = 'id'): array {
    if (!$rows) {
        return $rows;
    }
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)($row[$idKey] ?? $row['product_id'] ?? 0);
    }
    $map = productBarcodesMapForProductIds($conn, $ids);
    foreach ($rows as &$row) {
        $pid = (int)($row[$idKey] ?? $row['product_id'] ?? 0);
        $list = $map[$pid] ?? [];
        $row['barcodes'] = $list;
        $values = [];
        $primary = trim((string)($row['barcode'] ?? ''));
        foreach ($list as $bc) {
            if (strtolower((string)$bc['status']) !== 'active') {
                continue;
            }
            $val = (string)$bc['barcode_value'];
            if ($val === '') {
                continue;
            }
            $values[] = $val;
            if (!empty($bc['is_primary'])) {
                $primary = $val;
            }
        }
        if ($primary === '' && $values) {
            $primary = $values[0];
        }
        $row['barcode'] = $primary;
        $row['barcode_count'] = count($values);
        $row['barcode_values'] = $values;
    }
    unset($row);
    return $rows;
}

function productBarcodesEnsureReceiptItemColumn(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_items'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return;
    }
    $done = true;
    $check = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE 'scanned_barcodes'");
    if (!($check && $check->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_receipt_items ADD COLUMN scanned_barcodes TEXT NULL AFTER remarks");
    }
}

/**
 * @param mixed $raw
 * @return array<int,string>
 */
function productBarcodesParseValueList($raw): array {
    if (is_array($raw)) {
        $values = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        if (isset($raw[0]) && $raw[0] === '[') {
            $decoded = json_decode($raw, true);
            $values = is_array($decoded) ? $decoded : [$raw];
        } else {
            $values = preg_split('/\s*,\s*/', $raw) ?: [];
        }
    }
    $out = [];
    $seen = [];
    foreach ($values as $v) {
        $v = productBarcodesNormalize((string)$v);
        if ($v === '') {
            continue;
        }
        $k = productBarcodesKey($v);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $v;
    }
    return $out;
}

function productBarcodesStampReceiving(mysqli $conn, int $productId, array $barcodes, int $receiptId, int $poId = 0): void {
    productBarcodesEnsureSchema($conn);
    $barcodes = productBarcodesParseValueList($barcodes);
    if ($productId <= 0 || !$barcodes) {
        return;
    }
    foreach ($barcodes as $code) {
        $key = productBarcodesKey($code);
        if ($receiptId > 0 && $poId > 0) {
            $stmt = $conn->prepare("UPDATE product_barcodes
                SET receiving_transaction_id=?, purchase_order_id=COALESCE(purchase_order_id, ?)
                WHERE product_id=? AND UPPER(TRIM(barcode_value))=?");
            if ($stmt) {
                $stmt->bind_param('iiis', $receiptId, $poId, $productId, $key);
                $stmt->execute();
                $stmt->close();
            }
            continue;
        }
        if ($receiptId > 0) {
            $stmt = $conn->prepare("UPDATE product_barcodes
                SET receiving_transaction_id=?
                WHERE product_id=? AND UPPER(TRIM(barcode_value))=?");
            if ($stmt) {
                $stmt->bind_param('iis', $receiptId, $productId, $key);
                $stmt->execute();
                $stmt->close();
            }
            continue;
        }
        if ($poId > 0) {
            $stmt = $conn->prepare("UPDATE product_barcodes
                SET purchase_order_id=COALESCE(purchase_order_id, ?)
                WHERE product_id=? AND UPPER(TRIM(barcode_value))=?");
            if ($stmt) {
                $stmt->bind_param('iis', $poId, $productId, $key);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

function productBarcodesSaveReceiptLine(mysqli $conn, int $receiptItemId, array $barcodes): void {
    productBarcodesEnsureReceiptItemColumn($conn);
    if ($receiptItemId <= 0) {
        return;
    }
    $list = productBarcodesParseValueList($barcodes);
    $json = $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare('UPDATE purchase_receipt_items SET scanned_barcodes=? WHERE id=?');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('si', $json, $receiptItemId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array<int,string>
 */
function productBarcodesLookupReceivedForLine(mysqli $conn, int $productId, int $receiptId, int $poId): array {
    productBarcodesEnsureSchema($conn);
    $out = [];
    $seen = [];
    $add = static function ($val) use (&$out, &$seen): void {
        $val = productBarcodesNormalize((string)$val);
        if ($val === '') {
            return;
        }
        $k = productBarcodesKey($val);
        if (isset($seen[$k])) {
            return;
        }
        $seen[$k] = true;
        $out[] = $val;
    };
    if ($productId <= 0) {
        return $out;
    }
    if ($receiptId > 0) {
        $stmt = $conn->prepare("SELECT barcode_value FROM product_barcodes
            WHERE product_id=? AND receiving_transaction_id=? AND status='active'
            ORDER BY registered_at ASC, barcode_id ASC");
        if ($stmt) {
            $stmt->bind_param('ii', $productId, $receiptId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $add($row['barcode_value'] ?? '');
            }
            $stmt->close();
        }
    }
    if (!$out && $poId > 0) {
        $stmt = $conn->prepare("SELECT barcode_value FROM product_barcodes
            WHERE product_id=? AND purchase_order_id=? AND source='received' AND status='active'
            ORDER BY registered_at ASC, barcode_id ASC");
        if ($stmt) {
            $stmt->bind_param('ii', $productId, $poId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $add($row['barcode_value'] ?? '');
            }
            $stmt->close();
        }
    }
    if (!$out && $receiptId > 0) {
        $stmt = $conn->prepare("SELECT pb.barcode_value
            FROM product_barcodes pb
            INNER JOIN purchase_receipts rec ON rec.id=?
            WHERE pb.product_id=? AND pb.source='received' AND pb.status='active'
              AND DATE(pb.registered_at) = rec.date_received
            ORDER BY pb.registered_at ASC, pb.barcode_id ASC");
        if ($stmt) {
            $stmt->bind_param('ii', $receiptId, $productId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $add($row['barcode_value'] ?? '');
            }
            $stmt->close();
        }
    }
    return $out;
}

/**
 * Receipt BARCODE ID = codes scanned on this receive, not only the catalog primary.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function productBarcodesHydrateReceiptItems(mysqli $conn, array $items, int $receiptId, int $poId = 0): array {
    productBarcodesEnsureSchema($conn);
    productBarcodesEnsureReceiptItemColumn($conn);
    foreach ($items as &$item) {
        $stored = productBarcodesParseValueList($item['scanned_barcodes'] ?? '');
        $pid = (int)($item['product_id'] ?? 0);
        if (!$stored) {
            $stored = productBarcodesLookupReceivedForLine($conn, $pid, $receiptId, $poId);
        }
        if (!$stored) {
            $legacy = trim((string)($item['barcode'] ?? ''));
            if ($legacy !== '') {
                $stored = [$legacy];
            }
        }
        $item['scanned_barcodes'] = $stored;
        $item['barcode_values'] = $stored;
        if ($stored) {
            $item['barcode'] = $stored[0];
        }
    }
    unset($item);
    return $items;
}

function productBarcodesDisplayLabel(array $product): string {
    $values = $product['barcode_values'] ?? [];
    if (!$values && !empty($product['barcode'])) {
        return (string)$product['barcode'];
    }
    if (!$values) {
        return '';
    }
    $primary = (string)($product['barcode'] ?? $values[0]);
    $extra = count($values) - 1;
    if ($extra > 0) {
        return $primary . ' +' . $extra;
    }
    return $primary;
}

function productBarcodesSearchSql(string $alias = 'p'): string {
    return "($alias.name LIKE ? OR $alias.sku LIKE ? OR $alias.brand LIKE ? OR $alias.barcode LIKE ?
            OR EXISTS (
                SELECT 1 FROM product_barcodes pb
                WHERE pb.product_id = $alias.id
                  AND pb.status = 'active'
                  AND pb.barcode_value LIKE ?
            ))";
}

function productBarcodesMatchProductSql(string $alias = 'p'): string {
    return "(UPPER(TRIM($alias.barcode)) = ?
            OR EXISTS (
                SELECT 1 FROM product_barcodes pb
                WHERE pb.product_id = $alias.id
                  AND pb.status = 'active'
                  AND UPPER(TRIM(pb.barcode_value)) = ?
            ))";
}

/**
 * Resolve a scanned code against a PO line: not found (register), matched, or conflict.
 *
 * @return array{ok:bool,status:string,message:string,barcode:string,conflict?:array,record?:array}
 */
function productBarcodesResolveForReceive(mysqli $conn, int $productId, string $barcode, array $meta = []): array {
    $barcode = productBarcodesNormalize($barcode);
    if ($barcode === '') {
        return ['ok' => true, 'status' => 'empty', 'message' => 'No barcode scanned.', 'barcode' => ''];
    }
    $completed = productBarcodesCompleteRegisteredSuffix($conn, $barcode);
    if ($completed !== null && $completed !== '') {
        $barcode = $completed;
    }
    return productBarcodesRegister($conn, $productId, $barcode, array_merge($meta, [
        'source' => $meta['source'] ?? 'received',
    ]));
}

function productBarcodesLogAudit(mysqli $conn, int $userId, string $action, string $details): void {
    if ($userId <= 0 || $details === '') {
        return;
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'audit_log'");
    if ($tbl && $tbl->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action_type, table_name, record_id, new_values, ip_address) VALUES (?, ?, 'product_barcodes', NULL, ?, ?)");
        if ($stmt) {
            $payload = json_encode(['details' => $details], JSON_UNESCAPED_UNICODE);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt->bind_param('isss', $userId, $action, $payload, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
    $tbl2 = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if ($tbl2 && $tbl2->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, '', '', ?, ?)");
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $action, $details);
            $stmt->execute();
            $stmt->close();
        }
    }
}
