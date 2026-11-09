<?php
/**
 * Inventory lot condition: QTY, Damage, Missing, Sellable.
 * Extends product_batches / products — does not replace WMS on-hand.
 *
 * Display QTY  = sellable remaining + damage + missing (recorded on-hand for the lot)
 * SELLABLE     = QTY - DAMAGE - MISSING (never negative)
 * Damage/Missing never delete the recorded QTY.
 */

function invStockEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;

    $statusCol = $conn->query("SHOW COLUMNS FROM products LIKE 'status'");
    if ($statusCol && ($row = $statusCol->fetch_assoc())) {
        $type = strtolower((string)($row['Type'] ?? ''));
        if (strpos($type, 'inactive') === false) {
            @$conn->query("ALTER TABLE products MODIFY COLUMN status ENUM('Active','Inactive','Discontinued','Out of Stock','Low Stock') NOT NULL DEFAULT 'Active'");
        }
    }

    $tbl = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if ($tbl && $tbl->num_rows > 0) {
        foreach ([
            'qty_damage' => 'INT NOT NULL DEFAULT 0',
            'qty_missing' => 'INT NOT NULL DEFAULT 0',
            'receipt_item_id' => 'INT NULL',
            'po_id' => 'INT NULL',
        ] as $column => $definition) {
            $check = $conn->query("SHOW COLUMNS FROM product_batches LIKE '" . $conn->real_escape_string($column) . "'");
            if (!($check && $check->num_rows > 0)) {
                @$conn->query("ALTER TABLE product_batches ADD COLUMN `$column` $definition");
            }
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS stock_condition_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        batch_id INT NULL,
        batch_number VARCHAR(100) NULL,
        quantity INT NOT NULL DEFAULT 0,
        adjustment_type ENUM('Damage','Missing') NOT NULL,
        reason VARCHAR(255) NULL,
        remarks TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sca_product (product_id),
        INDEX idx_sca_batch (batch_id),
        INDEX idx_sca_type (adjustment_type),
        INDEX idx_sca_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function invStockSafeInt($value): int {
    return max(0, (int)$value);
}

function invStockComputeSellable(int $qty, int $damage, int $missing): int {
    $qty = invStockSafeInt($qty);
    $damage = invStockSafeInt($damage);
    $missing = invStockSafeInt($missing);
    if (($damage + $missing) > $qty) {
        return 0;
    }
    return max(0, $qty - $damage - $missing);
}

function invStockValidateCondition(int $qty, int $damage, int $missing, ?string &$error = null): bool {
    $qty = invStockSafeInt($qty);
    $damage = invStockSafeInt($damage);
    $missing = invStockSafeInt($missing);
    if ($damage + $missing > $qty) {
        $error = 'Damage plus Missing cannot exceed recorded quantity.';
        return false;
    }
    if (invStockComputeSellable($qty, $damage, $missing) < 0) {
        $error = 'Sellable quantity cannot be negative.';
        return false;
    }
    return true;
}

function invStockProductDamageMissing(mysqli $conn, int $productId): array {
    $damage = 0;
    $missing = 0;
    $tbl = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if ($tbl && $tbl->num_rows > 0) {
        $hasD = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'qty_damage'");
        $hasM = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'qty_missing'");
        if ($hasD && $hasD->num_rows > 0 && $hasM && $hasM->num_rows > 0) {
            $q = $conn->query("SELECT COALESCE(SUM(qty_damage),0) AS d, COALESCE(SUM(qty_missing),0) AS m FROM product_batches WHERE product_id=" . (int)$productId);
            if ($q && ($row = $q->fetch_assoc())) {
                $damage = invStockSafeInt($row['d'] ?? 0);
                $missing = invStockSafeInt($row['m'] ?? 0);
            }
        }
    }
    return ['damage' => $damage, 'missing' => $missing];
}

function invStockProductConditionMap(mysqli $conn): array {
    $map = [];
    $tbl = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return $map;
    }
    $hasD = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'qty_damage'");
    $hasM = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'qty_missing'");
    if (!($hasD && $hasD->num_rows > 0 && $hasM && $hasM->num_rows > 0)) {
        return $map;
    }
    $q = $conn->query("SELECT product_id, COALESCE(SUM(qty_damage),0) AS d, COALESCE(SUM(qty_missing),0) AS m FROM product_batches GROUP BY product_id");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $map[(int)$row['product_id']] = [
                'damage' => invStockSafeInt($row['d'] ?? 0),
                'missing' => invStockSafeInt($row['m'] ?? 0),
            ];
        }
    }
    return $map;
}

function invStockResolveNaBatch(mysqli $conn, int $productId, int $onHand): int {
    if ($productId <= 0) {
        return 0;
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return 0;
    }
    $find = $conn->query("SELECT id FROM product_batches WHERE product_id=$productId AND (UPPER(batch_number)='N/A' OR batch_number='' OR batch_number IS NULL) ORDER BY id ASC LIMIT 1");
    if ($find && ($row = $find->fetch_assoc())) {
        return (int)$row['id'];
    }
    $qty = max(0, $onHand);
    $stmt = $conn->prepare("INSERT INTO product_batches (product_id, batch_number, quantity, quantity_remaining, qty_damage, qty_missing, status, received_date) VALUES (?, 'N/A', ?, ?, 0, 0, 'Active', CURDATE())");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('iii', $productId, $qty, $qty);
    if (!$stmt->execute()) {
        $stmt->close();
        $again = $conn->query("SELECT id FROM product_batches WHERE product_id=$productId AND UPPER(batch_number)='N/A' LIMIT 1");
        return $again && ($r = $again->fetch_assoc()) ? (int)$r['id'] : 0;
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Record Damage or Missing against a lot. Does not reduce products.quantity_in_stock (QTY).
 * Reduces quantity_remaining so FIFO/sellable cannot issue those units.
 */
function invStockRecordCondition(
    mysqli $conn,
    int $productId,
    ?int $batchId,
    string $type,
    int $quantity,
    string $reason,
    string $remarks,
    int $userId
): array {
    invStockEnsureSchema($conn);
    $type = strcasecmp($type, 'Missing') === 0 ? 'Missing' : 'Damage';
    $quantity = (int)$quantity;
    if ($productId <= 0 || $quantity <= 0) {
        return ['ok' => false, 'message' => 'Select a product and enter a valid quantity.'];
    }

    $prod = $conn->query("SELECT id, sku, name, quantity_in_stock FROM products WHERE id=$productId LIMIT 1");
    if (!$prod || !($p = $prod->fetch_assoc())) {
        return ['ok' => false, 'message' => 'Product was not found.'];
    }

    $batchId = $batchId && $batchId > 0 ? $batchId : 0;
    if ($batchId <= 0) {
        $batchId = invStockResolveNaBatch($conn, $productId, (int)$p['quantity_in_stock']);
        if ($batchId <= 0) {
            return ['ok' => false, 'message' => 'A batch / lot record could not be created for this product.'];
        }
    }

    $bq = $conn->query("SELECT id, batch_number, quantity, quantity_remaining, COALESCE(qty_damage,0) AS qty_damage, COALESCE(qty_missing,0) AS qty_missing FROM product_batches WHERE id=$batchId AND product_id=$productId LIMIT 1");
    if (!$bq || !($batch = $bq->fetch_assoc())) {
        return ['ok' => false, 'message' => 'Batch / lot was not found for this product.'];
    }

    $remaining = invStockSafeInt($batch['quantity_remaining']);
    $damage = invStockSafeInt($batch['qty_damage']);
    $missing = invStockSafeInt($batch['qty_missing']);
    $qty = $remaining + $damage + $missing;
    if ($qty <= 0) {
        $qty = invStockSafeInt($batch['quantity']);
    }

    if ($remaining < $quantity) {
        return ['ok' => false, 'message' => "Not enough sellable quantity on this lot. Sellable: $remaining."];
    }

    $newDamage = $damage + ($type === 'Damage' ? $quantity : 0);
    $newMissing = $missing + ($type === 'Missing' ? $quantity : 0);
    $newRemaining = $remaining - $quantity;
    $error = null;
    if (!invStockValidateCondition($qty, $newDamage, $newMissing, $error)) {
        return ['ok' => false, 'message' => $error ?: 'Invalid stock condition values.'];
    }
    if (invStockComputeSellable($qty, $newDamage, $newMissing) !== $newRemaining) {
        $newRemaining = invStockComputeSellable($qty, $newDamage, $newMissing);
    }

    $batchNumber = trim((string)($batch['batch_number'] ?? '')) ?: 'N/A';
    $reason = trim($reason);
    $remarks = trim($remarks);

    $conn->begin_transaction();
    try {
        if (function_exists('invWmsApplyOnHandDelta') && !invWmsApplyOnHandDelta($conn, $productId, -$quantity, null)) {
            throw new RuntimeException('Could not update on-hand stock for this adjustment.');
        }
        $upd = $conn->prepare("UPDATE product_batches SET quantity_remaining=?, qty_damage=?, qty_missing=? WHERE id=? AND product_id=?");
        if (!$upd) {
            throw new RuntimeException('Could not update inventory lot.');
        }
        $upd->bind_param('iiiii', $newRemaining, $newDamage, $newMissing, $batchId, $productId);
        if (!$upd->execute()) {
            throw new RuntimeException('Could not update inventory lot.');
        }
        $upd->close();

        $ins = $conn->prepare("INSERT INTO stock_condition_adjustments (product_id, batch_id, batch_number, quantity, adjustment_type, reason, remarks, created_by) VALUES (?,?,?,?,?,?,?,?)");
        if (!$ins) {
            throw new RuntimeException('Could not record the stock adjustment history.');
        }
        $ins->bind_param('iisisssi', $productId, $batchId, $batchNumber, $quantity, $type, $reason, $remarks, $userId);
        if (!$ins->execute()) {
            throw new RuntimeException('Could not record the stock adjustment history.');
        }
        $ins->close();

        $mtype = $type === 'Missing' ? 'Adjustment' : 'Damage';
        $note = '[' . $type . '] ' . ($reason !== '' ? $reason : $type) . ($remarks !== '' ? ' — ' . $remarks : '');
        $mv = $conn->prepare("INSERT INTO stock_movements (product_id, batch_id, movement_type, quantity, notes, user_id, source) VALUES (?,?,?,?,?,?,?)");
        if ($mv) {
            $source = 'stock_condition';
            $mv->bind_param('iisissi', $productId, $batchId, $mtype, $quantity, $note, $userId, $source);
            $mv->execute();
            $mv->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Could not save the stock adjustment.'];
    }

    return [
        'ok' => true,
        'qty' => $qty,
        'sellable' => $newRemaining,
        'damage' => $newDamage,
        'missing' => $newMissing,
        'batch_id' => $batchId,
        'batch_number' => $batchNumber,
    ];
}

/**
 * Damaged customer return: increase recorded QTY and Damage, not Sellable.
 */
function invStockApplyDamagedReturn(mysqli $conn, int $productId, int $qty, ?int $batchId, int $userId, string $reason, string $notes): array {
    invStockEnsureSchema($conn);
    if ($productId <= 0 || $qty <= 0) {
        return ['ok' => false, 'message' => 'Invalid product or quantity for damaged return.'];
    }
    if ($batchId && $batchId > 0) {
        $chk = $conn->query("SELECT id FROM product_batches WHERE id=$batchId AND product_id=$productId LIMIT 1");
        if (!($chk && $chk->num_rows > 0)) {
            $batchId = 0;
        }
    }
    if (!$batchId) {
        $onHand = 0;
        $pq = $conn->query("SELECT quantity_in_stock FROM products WHERE id=$productId LIMIT 1");
        if ($pq && ($pr = $pq->fetch_assoc())) {
            $onHand = (int)$pr['quantity_in_stock'];
        }
        $batchId = invStockResolveNaBatch($conn, $productId, $onHand);
    }
    if ($batchId <= 0) {
        return ['ok' => false, 'message' => 'Could not attach the return to a batch / lot.'];
    }

    $conn->query("UPDATE product_batches SET quantity = quantity + $qty, qty_damage = qty_damage + $qty WHERE id=$batchId AND product_id=$productId");

    $batchNumber = 'N/A';
    $bn = $conn->query("SELECT batch_number FROM product_batches WHERE id=$batchId LIMIT 1");
    if ($bn && ($brow = $bn->fetch_assoc())) {
        $batchNumber = trim((string)$brow['batch_number']) ?: 'N/A';
    }
    $reason = $reason !== '' ? $reason : 'Damaged return';
    $ins = $conn->prepare("INSERT INTO stock_condition_adjustments (product_id, batch_id, batch_number, quantity, adjustment_type, reason, remarks, created_by) VALUES (?,?,?,?,?,?,?,?)");
    if ($ins) {
        $type = 'Damage';
        $ins->bind_param('iisisssi', $productId, $batchId, $batchNumber, $qty, $type, $reason, $notes, $userId);
        $ins->execute();
        $ins->close();
    }
    return ['ok' => true, 'batch_id' => $batchId];
}

function invStockSearchClause(mysqli $conn, string $search, bool $includeBatchColumn): array
{
    $like = '%' . $search . '%';
    $clauses = [
        'p.name LIKE ?',
        'p.sku LIKE ?',
        "IFNULL(p.barcode,'') LIKE ?",
        "IFNULL(p.brand,'') LIKE ?",
        "IFNULL(c.name,'') LIKE ?",
        "IFNULL(pc.name,'') LIKE ?",
        "IFNULL(s.name,'') LIKE ?",
    ];
    $params = array_fill(0, count($clauses), $like);
    $types = str_repeat('s', count($clauses));
    if ($includeBatchColumn) {
        $clauses[] = "IFNULL(pb.batch_number,'') LIKE ?";
        $params[] = $like;
        $types .= 's';
    } else {
        $batchTable = $conn->query("SHOW TABLES LIKE 'product_batches'");
        if ($batchTable && $batchTable->num_rows > 0) {
            $clauses[] = "EXISTS (SELECT 1 FROM product_batches pb_search WHERE pb_search.product_id = p.id AND pb_search.batch_number LIKE ?)";
            $params[] = $like;
            $types .= 's';
        }
    }
    $barcodeTable = $conn->query("SHOW TABLES LIKE 'product_barcodes'");
    if ($barcodeTable && $barcodeTable->num_rows > 0) {
        $clauses[] = "EXISTS (SELECT 1 FROM product_barcodes pbc_search WHERE pbc_search.product_id = p.id AND pbc_search.barcode_value LIKE ?)";
        $params[] = $like;
        $types .= 's';
    }
    return ['(' . implode(' OR ', $clauses) . ')', $types, $params];
}

function invStockQuery(mysqli $conn, string $sql, string $types, array $params)
{
    if ($types === '') {
        return $conn->query($sql);
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function invStockLoadLotRows(mysqli $conn, array $filters = []): array {
    invStockEnsureSchema($conn);
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'product_expiry_lib.php')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'product_expiry_lib.php';
    }
    $rows = [];
    $imageCol = null;
    foreach (['product_image', 'image', 'image_path'] as $column) {
        $c = $conn->query("SHOW COLUMNS FROM products LIKE '" . $conn->real_escape_string($column) . "'");
        if ($c && $c->num_rows > 0) {
            $imageCol = $column;
            break;
        }
    }
    $imageSelect = $imageCol ? ", p.`$imageCol` AS product_image" : ", '' AS product_image";
    $hasDmg = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'qty_damage'");
    $dmgSelect = ($hasDmg && $hasDmg->num_rows > 0)
        ? "COALESCE(pb.qty_damage,0) AS qty_damage, COALESCE(pb.qty_missing,0) AS qty_missing"
        : "0 AS qty_damage, 0 AS qty_missing";
    $search = trim((string)($filters['search'] ?? ''));
    $categoryFilter = strtolower(trim((string)($filters['category'] ?? '')));
    $expiryFilter = strtolower(trim((string)($filters['expiry'] ?? '')));
    $stockFilter = strtolower(trim((string)($filters['stock'] ?? '')));
    $batchSearch = ['', '', []];
    $productSearch = ['', '', []];
    if ($search !== '') {
        $batchSearch = invStockSearchClause($conn, $search, true);
        $productSearch = invStockSearchClause($conn, $search, false);
    }

    $sql = "SELECT pb.id AS batch_id, pb.product_id, pb.batch_number, pb.quantity AS batch_qty,
                   pb.quantity_remaining, pb.expiry_date AS batch_expiry, pb.received_date,
                   pb.status AS batch_status, $dmgSelect,
                   p.sku, p.name, p.barcode, p.brand, p.packaging, p.description, p.supplier_id, p.category_id,
                   p.quantity_in_stock, p.reorder_level, p.unit_price, p.cost_price, p.selling_price,
                   p.unit_of_measure, p.status AS product_status, p.storage_temperature,
                   COALESCE(p.expiry_tracking,0) AS expiry_tracking, COALESCE(p.expiry_alert_days,90) AS expiry_alert_days,
                   c.name AS category_name, c.parent_id AS parent_category_id, pc.name AS parent_category_name,
                   s.name AS supplier_name,
                   COALESCE(NULLIF(CONCAT(sl.storage_area, IF(sl.shelf_rack IS NOT NULL AND sl.shelf_rack <> '', CONCAT(' - ', sl.shelf_rack), '')), ''), sl.location_code, 'Warehouse') AS location_name
                   $imageSelect
            FROM product_batches pb
            INNER JOIN products p ON p.id = pb.product_id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN categories pc ON pc.id = c.parent_id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN storage_locations sl ON p.location_id = sl.id
            " . ($batchSearch[0] !== '' ? ('WHERE ' . $batchSearch[0] . ' ') : '') . "
            ORDER BY p.name ASC, pb.id ASC";
    $res = invStockQuery($conn, $sql, $batchSearch[1], $batchSearch[2]);
    $covered = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $lot = invStockFormatLotRow($row);
            if ($lot['qty'] <= 0 && $lot['damage'] <= 0 && $lot['missing'] <= 0) {
                continue;
            }
            $rows[] = $lot;
            $covered[(int)$row['product_id']] = ($covered[(int)$row['product_id']] ?? 0) + (int)$lot['qty'];
        }
    }

    $prodSql = "SELECT p.id AS product_id, p.sku, p.name, p.barcode, p.brand, p.packaging, p.description, p.supplier_id, p.category_id,
                       p.quantity_in_stock, p.reorder_level, p.unit_price, p.cost_price, p.selling_price,
                       p.unit_of_measure, p.status AS product_status, p.storage_temperature,
                       COALESCE(p.expiry_tracking,0) AS expiry_tracking, COALESCE(p.expiry_alert_days,90) AS expiry_alert_days,
                       CASE WHEN COALESCE(p.expiry_tracking,0)=1 THEN p.expiry_date ELSE NULL END AS batch_expiry,
                       c.name AS category_name, c.parent_id AS parent_category_id, pc.name AS parent_category_name,
                       s.name AS supplier_name,
                       COALESCE(NULLIF(CONCAT(sl.storage_area, IF(sl.shelf_rack IS NOT NULL AND sl.shelf_rack <> '', CONCAT(' - ', sl.shelf_rack), '')), ''), sl.location_code, 'Warehouse') AS location_name
                       $imageSelect
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN categories pc ON pc.id = c.parent_id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                LEFT JOIN storage_locations sl ON p.location_id = sl.id
                WHERE p.quantity_in_stock > 0"
                . ($productSearch[0] !== '' ? (' AND ' . $productSearch[0]) : '');
    $pres = invStockQuery($conn, $prodSql, $productSearch[1], $productSearch[2]);
    if ($pres) {
        while ($row = $pres->fetch_assoc()) {
            $pid = (int)$row['product_id'];
            $onHand = (int)$row['quantity_in_stock'];
            $assigned = (int)($covered[$pid] ?? 0);
            $unbatched = max(0, $onHand - $assigned);
            if ($unbatched <= 0) {
                continue;
            }
            $row['batch_id'] = 0;
            $row['batch_number'] = 'N/A';
            $row['quantity_remaining'] = $unbatched;
            $row['qty_damage'] = 0;
            $row['qty_missing'] = 0;
            $row['batch_qty'] = $unbatched;
            $rows[] = invStockFormatLotRow($row);
        }
    }

    if ($categoryFilter !== '' || $expiryFilter !== '' || $stockFilter !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($categoryFilter, $expiryFilter, $stockFilter): bool {
            if ($categoryFilter !== '') {
                $cat = strtolower((string)($row['category'] ?? ''));
                $parent = strtolower((string)($row['parent_category'] ?? ''));
                if ($cat !== $categoryFilter && $parent !== $categoryFilter) {
                    return false;
                }
            }
            if ($expiryFilter !== '') {
                $expiry = strtolower(str_replace(' ', '-', (string)($row['expiry_label'] ?? 'n/a')));
                if ($expiry !== $expiryFilter) {
                    return false;
                }
            }
            if ($stockFilter !== '' && (string)($row['stock_filter'] ?? '') !== $stockFilter) {
                return false;
            }
            return true;
        }));
    }

    usort($rows, static function ($a, $b) {
        $n = strcasecmp((string)$a['name'], (string)$b['name']);
        if ($n !== 0) {
            return $n;
        }
        return strcasecmp((string)$a['batch_lot'], (string)$b['batch_lot']);
    });
    return $rows;
}

function invStockFormatLotRow(array $row): array {
    $remaining = invStockSafeInt($row['quantity_remaining'] ?? 0);
    $damage = invStockSafeInt($row['qty_damage'] ?? 0);
    $missing = invStockSafeInt($row['qty_missing'] ?? 0);
    $qty = $remaining + $damage + $missing;
    if ($qty <= 0) {
        $qty = invStockSafeInt($row['batch_qty'] ?? $row['quantity_in_stock'] ?? 0);
    }
    $sellable = invStockComputeSellable($qty, $damage, $missing);
    $tracking = (int)($row['expiry_tracking'] ?? 0);
    $expiryRaw = $tracking === 1 ? ($row['batch_expiry'] ?? null) : null;
    $expiryMeta = function_exists('getProductExpiryStatus')
        ? getProductExpiryStatus($expiryRaw, $tracking, (int)($row['expiry_alert_days'] ?? 90))
        : ['raw' => '', 'display' => 'N/A', 'label' => 'N/A', 'class' => 'badge-gray'];
    if (($expiryMeta['label'] ?? '') === 'Expired') {
        $sellable = 0;
    }
    $reorder = (int)($row['reorder_level'] ?? 50);
    $expiredBlocked = ($expiryMeta['label'] ?? '') === 'Expired' && $sellable <= 0;
    if (function_exists('getStockLevelMeta')) {
        $stockMeta = getStockLevelMeta($qty, $reorder, $sellable, $expiredBlocked);
    } elseif ($expiredBlocked || ($sellable <= 0 && $qty > 0)) {
        $stockMeta = ['label' => $expiredBlocked ? 'Expired - Not Sellable' : 'Not Sellable', 'class' => 'badge-red', 'filter' => 'not-sellable'];
    } elseif ($qty <= 0) {
        $stockMeta = ['label' => 'Out of Stock', 'class' => 'badge-red', 'filter' => 'out'];
    } elseif ($sellable <= $reorder) {
        $stockMeta = ['label' => 'Low Stock', 'class' => 'badge-orange', 'filter' => 'low'];
    } else {
        $stockMeta = ['label' => 'In Stock', 'class' => 'badge-green', 'filter' => 'in'];
    }
    $batchLot = trim((string)($row['batch_number'] ?? ''));
    if ($batchLot === '') {
        $batchLot = 'N/A';
    }
    $parent = (string)($row['parent_category_name'] ?? '');
    $cat = (string)($row['category_name'] ?? 'Uncategorized');
    return [
        'batch_id' => (int)($row['batch_id'] ?? 0),
        'id' => (int)($row['product_id'] ?? 0),
        'sku' => (string)($row['sku'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'brand' => (string)($row['brand'] ?? ''),
        'category' => $cat,
        'parent_category' => $parent,
        'supplier_name' => (string)($row['supplier_name'] ?? 'Unassigned'),
        'supplier_id' => (int)($row['supplier_id'] ?? 0),
        'qty' => $qty,
        'sellable' => $sellable,
        'damage' => $damage,
        'missing' => $missing,
        'reorder_level' => $reorder,
        'batch_lot' => $batchLot,
        'expiry_raw' => $expiryMeta['raw'] ?? '',
        'expiry_display' => $expiryMeta['display'] ?? 'N/A',
        'expiry_label' => $expiryMeta['label'] ?? 'N/A',
        'expiry_class' => $expiryMeta['class'] ?? 'badge-gray',
        'price' => (float)($row['selling_price'] ?? $row['unit_price'] ?? 0),
        'cost' => (float)($row['cost_price'] ?? 0),
        'uom' => (string)($row['unit_of_measure'] ?? 'pcs'),
        'location' => (string)($row['location_name'] ?? 'Warehouse'),
        'barcode' => (string)($row['barcode'] ?? ''),
        'stock_filter' => $stockMeta['filter'] ?? 'in',
        'stock_label' => $stockMeta['label'] ?? '',
        'stock_class' => $stockMeta['class'] ?? 'badge-gray',
        'product_status' => (string)($row['product_status'] ?? 'Active'),
        'description' => (string)($row['description'] ?? ''),
        'packaging' => (string)($row['packaging'] ?? ''),
        'storage_temperature' => (string)($row['storage_temperature'] ?? ''),
        'image' => (string)($row['product_image'] ?? ''),
        'category_id' => (int)($row['category_id'] ?? 0),
        'parent_category_id' => (int)($row['parent_category_id'] ?? 0),
        'expiry_tracking' => $tracking,
    ];
}

function invStockLoadConditionHistory(mysqli $conn, int $limit = 80): array {
    invStockEnsureSchema($conn);
    $limit = max(1, min(300, $limit));
    $sql = "SELECT a.*, p.name AS product_name, p.sku, u.full_name AS user_name, u.username
            FROM stock_condition_adjustments a
            LEFT JOIN products p ON p.id = a.product_id
            LEFT JOIN users u ON u.id = a.created_by
            ORDER BY a.id DESC
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
