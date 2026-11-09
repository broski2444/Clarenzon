<?php
/**
 * Core WMS helpers: per-location stock, transfers, FEFO.
 * Shared by inventory_api.php, procurement_api.php, scanner_api.php.
 */

function invWmsEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS storage_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        location_code VARCHAR(50) NOT NULL UNIQUE,
        storage_area VARCHAR(100) NOT NULL,
        shelf_rack VARCHAR(50) NULL,
        section VARCHAR(50) NULL,
        capacity INT DEFAULT 0,
        current_usage INT DEFAULT 0,
        status ENUM('Active','Inactive','Full') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS product_location_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        location_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_product_location (product_id, location_id),
        INDEX idx_pls_location (location_id),
        INDEX idx_pls_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS stock_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_number VARCHAR(50) NOT NULL UNIQUE,
        from_location_id INT NOT NULL,
        to_location_id INT NOT NULL,
        status ENUM('Draft','Completed','Cancelled') NOT NULL DEFAULT 'Completed',
        notes TEXT NULL,
        transferred_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        INDEX idx_st_from (from_location_id),
        INDEX idx_st_to (to_location_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS stock_transfer_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        batch_id INT NULL,
        notes TEXT NULL,
        INDEX idx_sti_transfer (transfer_id),
        INDEX idx_sti_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $main = $conn->query("SELECT id FROM storage_locations WHERE location_code='MAIN' LIMIT 1");
    if (!$main || $main->num_rows === 0) {
        $conn->query("INSERT INTO storage_locations (location_code, storage_area, shelf_rack, section, status)
                      VALUES ('MAIN', 'Main Warehouse', 'A', '01', 'Active')");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS product_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_number VARCHAR(50) NOT NULL,
        party ENUM('Customer','Supplier') NOT NULL DEFAULT 'Customer',
        return_type VARCHAR(120) NOT NULL,
        condition_status VARCHAR(80) NULL,
        product_id INT NOT NULL,
        replacement_product_id INT NULL,
        quantity INT NOT NULL DEFAULT 1,
        batch_id INT NULL,
        sales_order_id INT NULL,
        reference_number VARCHAR(100) NULL,
        reason VARCHAR(255) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pret_product (product_id),
        INDEX idx_pret_party (party),
        INDEX idx_pret_created (created_at),
        INDEX idx_pret_number (return_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS product_replacements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        replacement_number VARCHAR(50) NOT NULL,
        original_product_id INT NOT NULL,
        replacement_product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        reason VARCHAR(100) NOT NULL DEFAULT 'Replacement',
        source VARCHAR(50) NOT NULL DEFAULT 'catalog',
        sales_order_id INT NULL,
        return_id INT NULL,
        return_reference VARCHAR(100) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prepl_orig (original_product_id),
        INDEX idx_prepl_new (replacement_product_id),
        INDEX idx_prepl_created (created_at),
        INDEX idx_prepl_number (replacement_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    invWmsBackfillLocationStock($conn);
    invWmsReconcileAllProductTotals($conn);
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'inventory_condition_lib.php')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'inventory_condition_lib.php';
        if (function_exists('invStockEnsureSchema')) {
            invStockEnsureSchema($conn);
        }
    }
}

function invWmsMainLocationId(mysqli $conn): int {
    $r = $conn->query("SELECT id FROM storage_locations WHERE location_code='MAIN' AND status='Active' LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) return (int)$row['id'];
    $r2 = $conn->query("SELECT id FROM storage_locations WHERE status='Active' ORDER BY id ASC LIMIT 1");
    if ($r2 && ($row = $r2->fetch_assoc())) return (int)$row['id'];
    $conn->query("INSERT INTO storage_locations (location_code, storage_area, status) VALUES ('MAIN', 'Main Warehouse', 'Active')");
    return (int)$conn->insert_id;
}

function invWmsBackfillLocationStock(mysqli $conn): void {
    $flag = $conn->query("SHOW TABLES LIKE 'product_location_stock'");
    if (!$flag || $flag->num_rows === 0) return;

    $cnt = $conn->query("SELECT COUNT(*) c FROM product_location_stock");
    $hasRows = $cnt && (int)$cnt->fetch_assoc()['c'] > 0;
    if ($hasRows) return;

    $mainId = invWmsMainLocationId($conn);
    $prod = $conn->query("SELECT id, location_id, quantity_in_stock FROM products WHERE status != 'Discontinued'");
    if (!$prod) return;
    while ($p = $prod->fetch_assoc()) {
        $qty = (int)$p['quantity_in_stock'];
        if ($qty <= 0) continue;
        $lid = !empty($p['location_id']) ? (int)$p['location_id'] : $mainId;
        $pid = (int)$p['id'];
        $conn->query("INSERT INTO product_location_stock (product_id, location_id, quantity)
                      VALUES ($pid, $lid, $qty)
                      ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
        if (empty($p['location_id'])) {
            $conn->query("UPDATE products SET location_id=$mainId WHERE id=$pid AND (location_id IS NULL OR location_id=0)");
        }
    }
}

/**
 * Set catalog on-hand quantity so it survives location-stock reconcile.
 * Places the full qty at the chosen location and zeros other location rows.
 */
function invWmsSetProductOnHand(mysqli $conn, int $productId, int $qty, ?int $locationId = null): bool {
    if ($productId <= 0) return false;
    $qty = max(0, $qty);
    $tbl = $conn->query("SHOW TABLES LIKE 'product_location_stock'");
    if (!($tbl && $tbl->num_rows > 0)) {
        $stmt = $conn->prepare("UPDATE products SET quantity_in_stock=? WHERE id=?");
        if (!$stmt) return false;
        $stmt->bind_param('ii', $qty, $productId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    $lid = invWmsResolveProductLocation($conn, $productId, $locationId);
    if ($lid <= 0) $lid = invWmsMainLocationId($conn);

    $conn->query("UPDATE product_location_stock SET quantity=0 WHERE product_id=$productId AND location_id<>$lid");
    $ok = $conn->query("INSERT INTO product_location_stock (product_id, location_id, quantity)
                        VALUES ($productId, $lid, $qty)
                        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
    $locStmt = $conn->prepare("UPDATE products SET location_id=? WHERE id=?");
    if ($locStmt) {
        $locStmt->bind_param('ii', $lid, $productId);
        $locStmt->execute();
        $locStmt->close();
    }
    invWmsSyncProductTotal($conn, $productId);
    return (bool)$ok;
}

function invWmsSetProductOnHandBySku(mysqli $conn, string $sku, int $qty, ?int $locationId = null): bool {
    if ($sku === '') return false;
    $stmt = $conn->prepare("SELECT id FROM products WHERE sku=? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $sku);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) return false;
    return invWmsSetProductOnHand($conn, $id, $qty, $locationId);
}

function invWmsSyncProductTotal(mysqli $conn, int $productId): void {
    if ($productId <= 0) return;
    $sumQ = $conn->query("SELECT COALESCE(SUM(quantity),0) AS s FROM product_location_stock WHERE product_id=$productId");
    $sum = $sumQ ? (int)$sumQ->fetch_assoc()['s'] : 0;
    $qtyStmt = $conn->prepare("UPDATE products SET quantity_in_stock=? WHERE id=?");
    if ($qtyStmt) {
        $qtyStmt->bind_param('ii', $sum, $productId);
        $qtyStmt->execute();
        $qtyStmt->close();
    }
    $conn->query("UPDATE products SET status=CASE
        WHEN status='Discontinued' THEN 'Discontinued'
        WHEN quantity_in_stock=0 THEN 'Out of Stock'
        WHEN quantity_in_stock<=reorder_level THEN 'Low Stock'
        ELSE 'Active' END WHERE id=$productId");
}

function invWmsReconcileAllProductTotals(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) return;
    $tbl = $conn->query("SHOW TABLES LIKE 'product_location_stock'");
    if (!($tbl && $tbl->num_rows > 0)) return;
    $done = true;
    $conn->query("UPDATE products p
        INNER JOIN (
            SELECT product_id, COALESCE(SUM(quantity),0) AS loc_qty
            FROM product_location_stock
            GROUP BY product_id
        ) loc ON loc.product_id = p.id
        SET p.quantity_in_stock = loc.loc_qty
        WHERE p.quantity_in_stock <> loc.loc_qty");
    $conn->query("UPDATE products SET status=CASE
        WHEN status='Discontinued' THEN 'Discontinued'
        WHEN quantity_in_stock=0 THEN 'Out of Stock'
        WHEN quantity_in_stock<=reorder_level THEN 'Low Stock'
        ELSE 'Active' END
        WHERE status <> 'Discontinued'");
}

function invWmsAdjustLocationStock(mysqli $conn, int $productId, int $locationId, int $delta, bool $allowCreate = true): bool {
    if ($productId <= 0 || $locationId <= 0 || $delta === 0) return $delta === 0;

    $chk = $conn->query("SELECT id, quantity FROM product_location_stock WHERE product_id=$productId AND location_id=$locationId LIMIT 1 FOR UPDATE");
    $row = $chk ? $chk->fetch_assoc() : null;

    if (!$row) {
        if ($delta < 0) return false;
        if (!$allowCreate) return false;
        $q = (int)$delta;
        $ok = $conn->query("INSERT INTO product_location_stock (product_id, location_id, quantity) VALUES ($productId, $locationId, $q)");
        if ($ok) invWmsSyncProductTotal($conn, $productId);
        return (bool)$ok;
    }

    $newQty = (int)$row['quantity'] + $delta;
    if ($newQty < 0) return false;
    $id = (int)$row['id'];
    $ok = $conn->query("UPDATE product_location_stock SET quantity=$newQty WHERE id=$id");
    if ($ok) invWmsSyncProductTotal($conn, $productId);
    return (bool)$ok;
}

function invWmsGetLocationQty(mysqli $conn, int $productId, int $locationId): int {
    $r = $conn->query("SELECT quantity FROM product_location_stock WHERE product_id=$productId AND location_id=$locationId LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) return (int)$row['quantity'];
    return 0;
}

function invWmsResolveProductLocation(mysqli $conn, int $productId, ?int $preferred = null): int {
    if ($preferred && $preferred > 0) return $preferred;
    $r = $conn->query("SELECT location_id FROM products WHERE id=$productId LIMIT 1");
    if ($r && ($row = $r->fetch_assoc()) && !empty($row['location_id'])) {
        return (int)$row['location_id'];
    }
    $r2 = $conn->query("SELECT location_id FROM product_location_stock WHERE product_id=$productId AND quantity > 0 ORDER BY quantity DESC LIMIT 1");
    if ($r2 && ($row = $r2->fetch_assoc())) return (int)$row['location_id'];
    return invWmsMainLocationId($conn);
}

function invWmsConsumeFefoBatches(mysqli $conn, int $productId, int $qty): ?array {
    return invWmsConsumeFifoBatches($conn, $productId, $qty, true);
}

function invWmsBatchHasManufacturerLot(mysqli $conn): bool {
    static $has = null;
    if ($has === null) {
        $c = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturer_lot'");
        $has = ($c && $c->num_rows > 0);
    }
    return $has;
}

/** FEFO: earliest expiry first. Lots with no expiry sort last. */
function invWmsFefoOrderSql(string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "ORDER BY CASE WHEN {$p}expiry_date IS NULL THEN 1 ELSE 0 END ASC, {$p}expiry_date ASC, {$p}id ASC";
}

function invWmsProductTracksExpiry(mysqli $conn, int $productId): bool {
    static $hasCol = null;
    if ($hasCol === null) {
        $c = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_tracking'");
        $hasCol = ($c && $c->num_rows > 0);
    }
    if (!$hasCol || $productId <= 0) {
        return false;
    }
    $r = $conn->query("SELECT COALESCE(expiry_tracking,0) AS et FROM products WHERE id=" . (int)$productId . " LIMIT 1");
    return $r && ($row = $r->fetch_assoc()) && (int)$row['et'] === 1;
}

function invWmsConsumeFifoBatches(mysqli $conn, int $productId, int $qty, bool $fefo = false): ?array {
    if ($productId <= 0 || $qty <= 0) return [];

    $anyBatch = $conn->query("SELECT COUNT(*) c FROM product_batches WHERE product_id=$productId");
    $batchCount = $anyBatch ? (int)$anyBatch->fetch_assoc()['c'] : 0;
    if ($batchCount === 0) {
        return [];
    }

    $need = $qty;
    $consumed = [];
    $trackExpiry = invWmsProductTracksExpiry($conn, $productId);
    $expFilter = $trackExpiry
        ? "AND (expiry_date IS NULL OR expiry_date >= CURDATE())"
        : "";
    $lotExpr = invWmsBatchHasManufacturerLot($conn)
        ? "COALESCE(manufacturer_lot, '') AS manufacturer_lot"
        : "'' AS manufacturer_lot";
    $order = $fefo
        ? invWmsFefoOrderSql()
        : "ORDER BY CASE WHEN received_date IS NULL THEN 1 ELSE 0 END ASC, received_date ASC, id ASC";
    $res = $conn->query("SELECT id, batch_number, quantity_remaining, expiry_date, received_date, $lotExpr
                         FROM product_batches
                         WHERE product_id=$productId
                           AND quantity_remaining > 0
                           $expFilter
                         $order
                         FOR UPDATE");
    if ($res) {
        while ($need > 0 && ($b = $res->fetch_assoc())) {
            $avail = (int)$b['quantity_remaining'];
            $take = min($need, $avail);
            if ($take <= 0) continue;
            $bid = (int)$b['id'];
            $conn->query("UPDATE product_batches SET quantity_remaining = quantity_remaining - $take WHERE id=$bid");
            $consumed[] = [
                'batch_id' => $bid,
                'qty' => $take,
                'batch_number' => (string)($b['batch_number'] ?? ''),
                'manufacturer_lot' => (string)($b['manufacturer_lot'] ?? ''),
                'expiry_date' => $b['expiry_date'] ?? null,
                'received_date' => $b['received_date'] ?? null,
            ];
            $need -= $take;
        }
    }

    // Leftover need is unbatched on-hand already counted in location qty, not expired lots.
    return $consumed;
}

function invWmsGetFefoBatches(mysqli $conn, int $productId): array {
    return invWmsGetFifoBatches($conn, $productId);
}

function invWmsGetFifoBatches(mysqli $conn, int $productId): array {
    $rows = [];
    $trackExpiry = invWmsProductTracksExpiry($conn, $productId);
    $expFilter = $trackExpiry
        ? "AND (pb.expiry_date IS NULL OR pb.expiry_date >= CURDATE())"
        : "";
    $lotExpr = invWmsBatchHasManufacturerLot($conn)
        ? "COALESCE(pb.manufacturer_lot, '') AS manufacturer_lot"
        : "'' AS manufacturer_lot";
    $res = $conn->query("SELECT pb.id, pb.batch_number, pb.quantity_remaining, pb.expiry_date, pb.received_date,
                                $lotExpr,
                                DATEDIFF(pb.expiry_date, CURDATE()) AS days_to_expiry
                         FROM product_batches pb
                         WHERE pb.product_id=$productId
                           AND pb.quantity_remaining > 0
                           $expFilter
                         " . invWmsFefoOrderSql('pb'));
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

function invWmsNextDocNumber(mysqli $conn, string $table, string $column, string $prefix): string {
    $year = date('Y');
    $like = $conn->real_escape_string($prefix . '-' . $year . '-%');
    $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $q = $conn->query("SELECT COUNT(*) AS c FROM `$tbl` WHERE `$col` LIKE '$like'");
    $cnt = $q ? (int)$q->fetch_assoc()['c'] : 0;
    return $prefix . '-' . $year . '-' . str_pad((string)($cnt + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Sellable units currently available to sell.
 * products.quantity_in_stock is the physical on-hand total.
 * product_batches.quantity_remaining is only used to exclude expired lots;
 * it is not an independent inventory total (historical Stock-In rows can remain unconsumed).
 *
 * Unbatched remainder (on-hand above current batch remaining) is still sellable
 * when those units are not expired. Stale leftover batch rows above on-hand
 * must not inflate or zero-out sellable.
 */
function invWmsResolveSellableQty(int $onHand, int $batchRows, int $unexpiredRemaining, ?string $productExpiry, int $remainingBatch = -1): int {
    $onHand = max(0, $onHand);
    $unexpiredRemaining = max(0, $unexpiredRemaining);
    if ($batchRows > 0) {
        $remaining = $remainingBatch >= 0 ? max(0, $remainingBatch) : $unexpiredRemaining;
        $unbatched = max(0, $onHand - $remaining);
        return min($onHand, $unexpiredRemaining + $unbatched);
    }
    if (!empty($productExpiry) && strtotime((string)$productExpiry) < strtotime(date('Y-m-d'))) {
        return 0;
    }
    return $onHand;
}

/**
 * Sellable = on-hand that is not expired.
 * Expired lots are excluded; unbatched on-hand is not treated as unsellable.
 */
function invWmsProductSellableQty(mysqli $conn, int $productId, ?int $onHand = null, ?string $productExpiry = null): int {
    if ($productId <= 0) return 0;
    $tracksExpiry = invWmsProductTracksExpiry($conn, $productId);
    if ($onHand === null || $productExpiry === null) {
        $st = $conn->prepare("SELECT quantity_in_stock, expiry_date FROM products WHERE id=? LIMIT 1");
        if ($st) {
            $st->bind_param('i', $productId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row) return 0;
            if ($onHand === null) $onHand = (int)$row['quantity_in_stock'];
            if ($productExpiry === null) $productExpiry = $row['expiry_date'] ?? null;
        } else {
            $onHand = (int)$onHand;
        }
    }
    $onHand = max(0, (int)$onHand);
    if (!$tracksExpiry) {
        $productExpiry = null;
    }

    $tbl = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if ($tbl && $tbl->num_rows > 0) {
        $expCase = $tracksExpiry
            ? "SUM(CASE WHEN quantity_remaining > 0 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) THEN quantity_remaining ELSE 0 END)"
            : "SUM(CASE WHEN quantity_remaining > 0 THEN quantity_remaining ELSE 0 END)";
        $bq = $conn->query("SELECT
                SUM(CASE WHEN quantity_remaining > 0 THEN 1 ELSE 0 END) AS batch_rows,
                $expCase AS sellable_batch,
                SUM(CASE WHEN quantity_remaining > 0 THEN quantity_remaining ELSE 0 END) AS remaining_batch
            FROM product_batches WHERE product_id=$productId");
        if ($bq && ($b = $bq->fetch_assoc())) {
            $batchRows = (int)($b['batch_rows'] ?? 0);
            $unexpired = (int)($b['sellable_batch'] ?? 0);
            $remaining = (int)($b['remaining_batch'] ?? 0);
            return invWmsResolveSellableQty($onHand, $batchRows, $unexpired, $productExpiry, $remaining);
        }
    }

    return invWmsResolveSellableQty($onHand, 0, 0, $productExpiry);
}

function invWmsSellableQtyMap(mysqli $conn): array {
    $onHands = [];
    $expiries = [];
    $tracked = [];
    $etCol = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_tracking'");
    $etSelect = ($etCol && $etCol->num_rows > 0) ? ", COALESCE(expiry_tracking,0) AS expiry_tracking" : ", 0 AS expiry_tracking";
    $res = $conn->query("SELECT id, quantity_in_stock, expiry_date{$etSelect} FROM products");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $pid = (int)$r['id'];
            $onHands[$pid] = max(0, (int)$r['quantity_in_stock']);
            $tracked[$pid] = ((int)($r['expiry_tracking'] ?? 0) === 1);
            $expiries[$pid] = $tracked[$pid] ? ($r['expiry_date'] ?? null) : null;
        }
    }

    $batchRowsByPid = [];
    $unexpiredByPid = [];
    $remainingByPid = [];
    $tbl = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if ($tbl && $tbl->num_rows > 0) {
        $bq = $conn->query("SELECT product_id,
                SUM(CASE WHEN quantity_remaining > 0 THEN 1 ELSE 0 END) AS batch_rows,
                SUM(CASE WHEN quantity_remaining > 0 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) THEN quantity_remaining ELSE 0 END) AS sellable_batch,
                SUM(CASE WHEN quantity_remaining > 0 THEN quantity_remaining ELSE 0 END) AS remaining_batch
            FROM product_batches
            GROUP BY product_id");
        if ($bq) {
            while ($b = $bq->fetch_assoc()) {
                $pid = (int)$b['product_id'];
                $batchRowsByPid[$pid] = (int)($b['batch_rows'] ?? 0);
                $remainingByPid[$pid] = (int)($b['remaining_batch'] ?? 0);
                $unexpiredByPid[$pid] = !empty($tracked[$pid])
                    ? (int)($b['sellable_batch'] ?? 0)
                    : (int)($b['remaining_batch'] ?? 0);
            }
        }
    }

    $map = [];
    foreach ($onHands as $pid => $onHand) {
        $map[$pid] = invWmsResolveSellableQty(
            $onHand,
            (int)($batchRowsByPid[$pid] ?? 0),
            (int)($unexpiredByPid[$pid] ?? 0),
            $expiries[$pid] ?? null,
            (int)($remainingByPid[$pid] ?? 0)
        );
    }
    return $map;
}

/** Low stock rule: sellable <= reorder level. */
function invWmsIsLowStock(int $sellable, int $reorderLevel): bool {
    return $sellable <= $reorderLevel;
}

function invWmsAttachSellableLowStock(array $row, array $sellableMap): array {
    $pid = (int)($row['id'] ?? 0);
    $onHand = (int)($row['quantity_in_stock'] ?? 0);
    $reorder = (int)($row['reorder_level'] ?? 0);
    $sellable = (int)($sellableMap[$pid] ?? $onHand);
    $row['quantity_in_stock'] = $onHand;
    $row['reorder_level'] = $reorder;
    $row['sellable_qty'] = $sellable;
    $row['is_low_stock'] = invWmsIsLowStock($sellable, $reorder) ? 1 : 0;
    $row['shortage'] = max(0, $reorder - $sellable);
    return $row;
}

function invWmsApplyOnHandDelta(mysqli $conn, int $productId, int $delta, ?int $locationId = null): bool {
    if ($productId <= 0) return false;
    if ($delta === 0) return true;
    $tbl = $conn->query("SHOW TABLES LIKE 'product_location_stock'");
    if (!($tbl && $tbl->num_rows > 0)) {
        if ($delta > 0) {
            return (bool)$conn->query("UPDATE products SET quantity_in_stock = quantity_in_stock + " . (int)$delta . " WHERE id=$productId");
        }
        $abs = abs((int)$delta);
        return (bool)$conn->query("UPDATE products SET quantity_in_stock = GREATEST(0, quantity_in_stock - $abs) WHERE id=$productId");
    }
    $loc = invWmsResolveProductLocation($conn, $productId, $locationId);
    return invWmsAdjustLocationStock($conn, $productId, $loc, $delta, $delta > 0);
}

function invWmsIssueSellableQty(mysqli $conn, int $productId, int $qty, ?int $batchId = null, ?int $locationId = null): array {
    if ($productId <= 0 || $qty <= 0) {
        return ['ok' => false, 'message' => 'Invalid product or quantity.'];
    }
    $sellable = invWmsProductSellableQty($conn, $productId);
    if ($sellable < $qty) {
        return ['ok' => false, 'message' => "Insufficient sellable stock. Sellable: $sellable, required: $qty. Expired units cannot be issued."];
    }
    if (!invWmsApplyOnHandDelta($conn, $productId, -$qty, $locationId)) {
        return ['ok' => false, 'message' => 'Insufficient location stock for this issue.'];
    }
    if ($batchId && $batchId > 0) {
        $chk = $conn->query("SELECT id, quantity_remaining, expiry_date FROM product_batches WHERE id=$batchId AND product_id=$productId LIMIT 1");
        $b = $chk ? $chk->fetch_assoc() : null;
        if (!$b) {
            invWmsApplyOnHandDelta($conn, $productId, $qty, $locationId);
            return ['ok' => false, 'message' => 'Selected batch was not found.'];
        }
        if (!empty($b['expiry_date']) && strtotime((string)$b['expiry_date']) < strtotime(date('Y-m-d')) && invWmsProductTracksExpiry($conn, $productId)) {
            invWmsApplyOnHandDelta($conn, $productId, $qty, $locationId);
            return ['ok' => false, 'message' => 'The selected batch is expired and cannot be issued.'];
        }
        if ((int)$b['quantity_remaining'] < $qty) {
            invWmsApplyOnHandDelta($conn, $productId, $qty, $locationId);
            return ['ok' => false, 'message' => 'Selected batch does not have enough remaining quantity.'];
        }
        $conn->query("UPDATE product_batches SET quantity_remaining = quantity_remaining - $qty WHERE id=$batchId");
        return ['ok' => true, 'consumed' => [['batch_id' => $batchId, 'qty' => $qty]]];
    }
    $consumed = invWmsConsumeFifoBatches($conn, $productId, $qty);
    if ($consumed === null) {
        invWmsApplyOnHandDelta($conn, $productId, $qty, $locationId);
        return ['ok' => false, 'message' => 'Insufficient non-expired batch quantity (FIFO).'];
    }
    return ['ok' => true, 'consumed' => $consumed];
}

function invWmsRecordReplacement(
    mysqli $conn,
    int $originalProductId,
    int $replacementProductId,
    int $qty,
    string $reason,
    string $source,
    int $uid,
    ?int $returnId = null,
    ?string $returnRef = null,
    ?int $salesOrderId = null,
    string $notes = ''
): array {
    $num = invWmsNextDocNumber($conn, 'product_replacements', 'replacement_number', 'RPL');
    $stmt = $conn->prepare("INSERT INTO product_replacements
        (replacement_number, original_product_id, replacement_product_id, quantity, reason, source,
         sales_order_id, return_id, return_reference, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Could not record replacement link: ' . $conn->error];
    }
    $so = $salesOrderId ?: null;
    $rid = $returnId ?: null;
    $stmt->bind_param('siiissiissi', $num, $originalProductId, $replacementProductId, $qty, $reason, $source, $so, $rid, $returnRef, $notes, $uid);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return ['ok' => false, 'message' => 'Could not record replacement link: ' . $err];
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    return ['ok' => true, 'id' => $id, 'number' => $num];
}

