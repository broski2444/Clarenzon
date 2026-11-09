<?php
/**
 * Product expiry tracking helpers.
 * Only products with expiry_tracking = 1 participate in expiry alerts.
 */

function productExpiryEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $et = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_tracking'");
    if (!($et && $et->num_rows > 0)) {
        @$conn->query("ALTER TABLE products ADD COLUMN expiry_tracking TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date");
    }
    $ad = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_alert_days'");
    if (!($ad && $ad->num_rows > 0)) {
        @$conn->query("ALTER TABLE products ADD COLUMN expiry_alert_days INT NULL DEFAULT 90 AFTER expiry_tracking");
    }

    $catTbl = $conn->query("SHOW TABLES LIKE 'categories'");
    if ($catTbl && $catTbl->num_rows > 0) {
        $det = $conn->query("SHOW COLUMNS FROM categories LIKE 'default_expiry_tracking'");
        if (!($det && $det->num_rows > 0)) {
            @$conn->query("ALTER TABLE categories ADD COLUMN default_expiry_tracking TINYINT(1) NULL DEFAULT NULL");
        }
        productExpirySeedCategoryDefaults($conn);
    }
}

function productExpiryCategoryDefaultFromName(string $name): ?int {
    $n = strtolower(trim($name));
    if ($n === '') {
        return null;
    }
    $noNeedles = [
        'laboratory equipment',
        'lab equipment',
        'computer & peripherals',
        'computers & peripherals',
        'computer and peripherals',
        'computers and peripherals',
        'computer / peripheral',
        'computers / peripherals',
    ];
    foreach ($noNeedles as $needle) {
        if ($n === $needle || strpos($n, $needle) !== false) {
            return 0;
        }
    }
    $yesExact = ['poct', 'chemistry', 'hematology', 'medical supplies', 'reagent', 'reagents'];
    foreach ($yesExact as $yes) {
        if ($n === $yes) {
            return 1;
        }
    }
    return null;
}

function productExpirySeedCategoryDefaults(mysqli $conn): void {
    $res = $conn->query("SELECT id, name, default_expiry_tracking FROM categories");
    if (!$res) {
        return;
    }
    while ($row = $res->fetch_assoc()) {
        if ($row['default_expiry_tracking'] !== null && $row['default_expiry_tracking'] !== '') {
            continue;
        }
        $def = productExpiryCategoryDefaultFromName((string)$row['name']);
        if ($def === null) {
            continue;
        }
        $id = (int)$row['id'];
        $conn->query("UPDATE categories SET default_expiry_tracking=$def WHERE id=$id AND default_expiry_tracking IS NULL");
    }
}

/**
 * Legacy one-time migration. Do not call on page load.
 * A leftover expiry_date must not force expiry_tracking back to Yes.
 */
function productExpiryBackfillSafely(mysqli $conn): void {
}

function productExpiryIsTrackedValue($value): bool {
    return (int)$value === 1;
}

function productExpiryIsTracked(mysqli $conn, int $productId): bool {
    if ($productId <= 0) {
        return false;
    }
    $et = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_tracking'");
    if (!($et && $et->num_rows > 0)) {
        return false;
    }
    $r = $conn->query("SELECT COALESCE(expiry_tracking, 0) AS et FROM products WHERE id=" . (int)$productId . " LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) {
        return false;
    }
    return productExpiryIsTrackedValue($row['et']);
}

function productExpirySaveTracking(mysqli $conn, string $sku, int $tracking): void {
    $sku = trim($sku);
    if ($sku === '') {
        return;
    }
    $et = $conn->query("SHOW COLUMNS FROM products LIKE 'expiry_tracking'");
    if (!($et && $et->num_rows > 0)) {
        return;
    }
    $tracking = $tracking ? 1 : 0;
    $stmt = $conn->prepare("UPDATE products SET expiry_tracking=? WHERE sku=?");
    if ($stmt) {
        $stmt->bind_param('is', $tracking, $sku);
        $stmt->execute();
        $stmt->close();
    }
}

function productExpiryPostedFlag(string $addKey, string $editKey): int {
    $raw = $_POST[$editKey] ?? $_POST[$addKey] ?? $_POST['expiry_tracking'] ?? '';
    $v = strtolower(trim((string)$raw));
    return in_array($v, ['1', 'yes', 'on'], true) ? 1 : 0;
}

/**
 * @return array{raw:string,display:string,label:string,class:string,days:?int,tracked:bool}
 */
function getProductExpiryStatus(?string $expiryDate, $tracking = 0, int $soonDays = 90, int $urgentDays = 30): array {
    if (!productExpiryIsTrackedValue($tracking)) {
        return [
            'raw' => '',
            'display' => 'N/A',
            'label' => 'N/A',
            'class' => 'badge-gray',
            'days' => null,
            'tracked' => false,
        ];
    }
    $soonDays = $soonDays > 0 ? $soonDays : 90;
    $urgentDays = $urgentDays > 0 ? $urgentDays : 30;
    if ($urgentDays > $soonDays) {
        $urgentDays = $soonDays;
    }
    if (!$expiryDate) {
        return [
            'raw' => '',
            'display' => 'Not set',
            'label' => 'Not set',
            'class' => 'badge-blue',
            'days' => null,
            'tracked' => true,
        ];
    }
    $today = new DateTime('today');
    $expiry = DateTime::createFromFormat('Y-m-d', substr((string)$expiryDate, 0, 10));
    if (!$expiry) {
        return [
            'raw' => (string)$expiryDate,
            'display' => (string)$expiryDate,
            'label' => 'Invalid Date',
            'class' => 'badge-red',
            'days' => null,
            'tracked' => true,
        ];
    }
    $daysUntilExpiry = (int)$today->diff($expiry)->format('%r%a');
    $dateDisplay = $expiry->format('M d, Y');
    if ($daysUntilExpiry < 0) {
        return [
            'raw' => $expiry->format('Y-m-d'),
            'display' => $dateDisplay,
            'label' => 'Expired',
            'class' => 'badge-red',
            'days' => $daysUntilExpiry,
            'tracked' => true,
        ];
    }
    if ($daysUntilExpiry <= $urgentDays) {
        return [
            'raw' => $expiry->format('Y-m-d'),
            'display' => $daysUntilExpiry . ' days',
            'label' => 'Near Expiry',
            'class' => 'badge-red',
            'days' => $daysUntilExpiry,
            'tracked' => true,
        ];
    }
    if ($daysUntilExpiry <= $soonDays) {
        return [
            'raw' => $expiry->format('Y-m-d'),
            'display' => $daysUntilExpiry . ' days',
            'label' => 'Expiring Soon',
            'class' => 'badge-orange',
            'days' => $daysUntilExpiry,
            'tracked' => true,
        ];
    }
    return [
        'raw' => $expiry->format('Y-m-d'),
        'display' => $daysUntilExpiry . ' days',
        'label' => 'Normal',
        'class' => 'badge-green',
        'days' => $daysUntilExpiry,
        'tracked' => true,
    ];
}

function productExpiryIsAlertStatus(string $label): bool {
    return in_array($label, ['Expired', 'Expiring Soon', 'Near Expiry'], true);
}

function productExpiryDisposeExpired(mysqli $conn, int $uid = 0): int {
    static $ran = false;
    if ($ran) {
        return 0;
    }
    $ran = true;
    productExpiryEnsureSchema($conn);
    if (!function_exists('invWmsApplyOnHandDelta')) {
        require_once __DIR__ . '/inventory_wms_lib.php';
    }
    $tbl = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if (!($tbl && $tbl->num_rows > 0)) {
        return 0;
    }
    $sql = "SELECT b.id, b.product_id, b.quantity_remaining, COALESCE(b.batch_number,'') AS batch_number, b.expiry_date
            FROM product_batches b
            INNER JOIN products p ON p.id = b.product_id
            WHERE b.quantity_remaining > 0
              AND b.expiry_date IS NOT NULL
              AND b.expiry_date < CURDATE()
              AND COALESCE(p.expiry_tracking, 0) = 1";
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $count = 0;
    $hasSource = false;
    $srcChk = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'source'");
    if ($srcChk && $srcChk->num_rows > 0) {
        $hasSource = true;
    }
    while ($b = $res->fetch_assoc()) {
        $qty = (int)$b['quantity_remaining'];
        $pid = (int)$b['product_id'];
        $bid = (int)$b['id'];
        if ($qty <= 0 || $pid <= 0) {
            continue;
        }
        $conn->begin_transaction();
        try {
            if (!invWmsApplyOnHandDelta($conn, $pid, -$qty, null)) {
                throw new RuntimeException('on-hand');
            }
            $conn->query("UPDATE product_batches SET quantity_remaining=0 WHERE id=$bid");
            $ref = 'EXP-' . date('Ymd') . '-' . $bid;
            $notes = 'Auto-expired / moved out of sellable stock (batch ' . $conn->real_escape_string((string)$b['batch_number']) . ', expiry ' . $conn->real_escape_string((string)$b['expiry_date']) . ')';
            if ($hasSource) {
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, batch_id, movement_type, quantity, reference_number, user_id, notes, source) VALUES (?,?,?,?,?,?,?,?)");
                $type = 'Expiry';
                $src = 'expiry';
                $stmt->bind_param('iisissss', $pid, $bid, $type, $qty, $ref, $uid, $notes, $src);
            } else {
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, batch_id, movement_type, quantity, reference_number, user_id, notes) VALUES (?,?,?,?,?,?,?)");
                $type = 'Expiry';
                $stmt->bind_param('iisisss', $pid, $bid, $type, $qty, $ref, $uid, $notes);
            }
            if ($stmt) {
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
            $count++;
        } catch (Throwable $e) {
            $conn->rollback();
        }
    }
    return $count;
}
