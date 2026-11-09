<?php
/**
 * Warehouse mobile API.
 * Server-side role check: admin and warehouse_head only.
 * Reads/writes the existing Clarenzon MySQL database.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'receive_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'inventory_wms_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'inventory_condition_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_expiry_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_barcodes_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_catalog_fields_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'procurement_rts_lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'po_lifecycle_lib.php';

[$conn, $user] = mobile_require_api_role();
$uid = (int)$user['id'];
$isAdmin = !empty($user['is_admin']);
$isWh = !empty($user['is_warehouse_head']);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST' && !mobile_csrf_ok()) {
    mobile_json(['success' => false, 'code' => 'csrf', 'message' => 'You are not authorized to perform this action.'], 403);
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
if ($action === '') {
    mobile_json(['success' => false, 'message' => 'Missing action.'], 400);
}

invWmsEnsureSchema($conn);
invStockEnsureSchema($conn);
productBarcodesEnsureSchema($conn);
productExpiryEnsureSchema($conn);
if (function_exists('productCatalogEnsureSchema')) {
    productCatalogEnsureSchema($conn);
}
ensureReceivingWorkflowSchema($conn);
rtsEnsureSchema($conn);
if (function_exists('productExpiryDisposeExpired')) {
    productExpiryDisposeExpired($conn, $uid);
}

function mobile_in(string $key, $default = '')
{
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function mobile_product_select_sql(mysqli $conn): array
{
    $imgCol = '';
    foreach (['product_image', 'image', 'image_path'] as $name) {
        $q = $conn->query("SHOW COLUMNS FROM products LIKE '" . $conn->real_escape_string($name) . "'");
        if ($q && $q->num_rows > 0) {
            $imgCol = $name;
            break;
        }
    }
    $imgSql = $imgCol !== '' ? ("p.`" . str_replace('`', '', $imgCol) . "` AS product_image,") : "'' AS product_image,";
    $parentSelect = "NULL AS parent_category_id, '' AS parent_category_name,";
    $parentJoin = '';
    $q = $conn->query("SHOW COLUMNS FROM categories LIKE 'parent_id'");
    if ($q && $q->num_rows > 0) {
        $parentSelect = "c.parent_id AS parent_category_id, COALESCE(pc.name, '') AS parent_category_name,";
        $parentJoin = 'LEFT JOIN categories pc ON pc.id = c.parent_id';
    }
    $pack = $conn->query("SHOW COLUMNS FROM products LIKE 'packaging'");
    $packSql = ($pack && $pack->num_rows > 0) ? 'COALESCE(p.packaging,\'\') AS packaging,' : '\'\' AS packaging,';
    $temp = $conn->query("SHOW COLUMNS FROM products LIKE 'storage_temperature'");
    $tempSql = ($temp && $temp->num_rows > 0) ? 'COALESCE(p.storage_temperature,\'\') AS storage_temperature,' : '\'\' AS storage_temperature,';
    $sql = "
        SELECT
            p.id, p.sku, p.name, p.barcode, p.brand, p.description,
            $imgSql $packSql $tempSql
            p.quantity_in_stock,
            COALESCE(p.unit_price, p.selling_price, 0.00) AS unit_price,
            COALESCE(p.cost_price, 0.00) AS cost_price,
            COALESCE(p.selling_price, p.unit_price, 0.00) AS selling_price,
            COALESCE(p.reorder_level, 50) AS reorder_level,
            COALESCE(p.unit_of_measure, 'pcs') AS unit_of_measure,
            p.expiry_date,
            COALESCE(p.expiry_tracking, 0) AS expiry_tracking,
            COALESCE(p.expiry_alert_days, 90) AS expiry_alert_days,
            COALESCE(p.supplier_product_code, '') AS supplier_product_code,
            p.status,
            p.category_id,
            COALESCE(c.name, '') AS category_name,
            $parentSelect
            p.supplier_id,
            COALESCE(s.name, '') AS supplier_name,
            p.location_id,
            COALESCE(
                CONCAT(l.storage_area, IF(l.shelf_rack IS NOT NULL AND l.shelf_rack <> '', CONCAT(' — ', l.shelf_rack), '')),
                p.location,
                'Not assigned'
            ) AS location,
            COALESCE(l.location_code, '') AS location_code
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        $parentJoin
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        LEFT JOIN storage_locations l ON l.id = p.location_id
    ";
    return [$sql];
}

function mobile_open_po_for_product(mysqli $conn, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    $sql = "SELECT poi.id, poi.id AS po_item_id, poi.po_id, poi.product_id, poi.product_name, poi.quantity_ordered,
                   po.po_number, s.name AS supplier_name
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.po_id
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            WHERE poi.product_id = ?
              AND po.status NOT IN ('Draft','Cancelled','Pending Approval')
            ORDER BY po.id DESC LIMIT 8";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $poId = (int)$row['po_id'];
        $items = recvAttachPoItemInspectState($conn, $poId, [$row]);
        $line = $items[0] ?? $row;
        $st = mobile_po_line_state($line, $line);
        if ($st['remaining'] > 0) {
            $stmt->close();
            return [
                'po_id' => $poId,
                'po_item_id' => (int)($line['id'] ?? $row['po_item_id'] ?? 0),
                'po_number' => (string)($row['po_number'] ?? ''),
                'supplier' => (string)($row['supplier_name'] ?? ''),
                'po_qty' => $st['ordered'],
                'received' => $st['good'],
                'remaining' => $st['remaining'],
                'damage_missing' => $st['damage_missing'],
            ];
        }
    }
    $stmt->close();
    return null;
}

function mobile_attach_incoming(mysqli $conn, array $fmt): array
{
    $fmt['incoming_po'] = mobile_open_po_for_product($conn, (int)($fmt['id'] ?? 0));
    if (!empty($fmt['incoming_po'])) {
        $fmt['po_qty'] = $fmt['incoming_po']['po_qty'];
        $fmt['po_received'] = $fmt['incoming_po']['received'];
        $fmt['po_remaining'] = $fmt['incoming_po']['remaining'];
        $fmt['po_damage_missing'] = $fmt['incoming_po']['damage_missing'];
    }
    return $fmt;
}

function mobile_format_product(mysqli $conn, array $product, array $sellableMap, string $scanned = ''): array
{
    $pid = (int)$product['id'];
    $onHand = (int)($product['quantity_in_stock'] ?? 0);
    $sellable = (int)($sellableMap[$pid] ?? $onHand);
    $parentName = trim((string)($product['parent_category_name'] ?? ''));
    $catName = trim((string)($product['category_name'] ?? ''));
    $category = $parentName !== '' ? $parentName : $catName;
    $sub = $parentName !== '' ? $catName : '';
    $hydrated = productBarcodesHydrateRows($conn, [$product], 'id');
    $product = $hydrated[0] ?? $product;
    $barcodes = $product['barcode_values'] ?? [];
    if (!is_array($barcodes)) {
        $barcodes = [];
    }
    $batches = [];
    $dmg = 0;
    $miss = 0;
    $bq = $conn->prepare('SELECT id, batch_number, expiry_date, quantity, quantity_remaining, status, COALESCE(qty_damage,0) AS qty_damage, COALESCE(qty_missing,0) AS qty_missing FROM product_batches WHERE product_id=? ORDER BY expiry_date ASC, id ASC');
    if (!$bq) {
        $bq = $conn->prepare('SELECT id, batch_number, expiry_date, quantity, quantity_remaining, status FROM product_batches WHERE product_id=? ORDER BY expiry_date ASC, id ASC');
    }
    if ($bq) {
        $bq->bind_param('i', $pid);
        $bq->execute();
        $res = $bq->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $tracking = (int)($product['expiry_tracking'] ?? 0);
            $meta = getProductExpiryStatus($r['expiry_date'] ?? null, $tracking, (int)($product['expiry_alert_days'] ?? 90));
            $label = (string)($meta['label'] ?? 'N/A');
            if ($label === 'Normal') {
                $label = 'Safe';
            }
            if ($label === 'Expiring Soon') {
                $label = 'Near Expiry';
            }
            $bd = (int)($r['qty_damage'] ?? 0);
            $bm = (int)($r['qty_missing'] ?? 0);
            $dmg += $bd;
            $miss += $bm;
            $batches[] = [
                'id' => (int)$r['id'],
                'batch_number' => (string)($r['batch_number'] ?? ''),
                'expiry_date' => $r['expiry_date'] ?? null,
                'quantity' => (int)($r['quantity'] ?? 0),
                'quantity_remaining' => (int)($r['quantity_remaining'] ?? 0),
                'status' => $label !== 'N/A' ? $label : (string)($r['status'] ?? 'Active'),
                'qty_damage' => $bd,
                'qty_missing' => $bm,
            ];
        }
        $bq->close();
    }
    $nearest = null;
    foreach ($batches as $b) {
        if (!empty($b['expiry_date'])) {
            $nearest = $b['expiry_date'];
            break;
        }
    }
    return [
        'id' => $pid,
        'sku' => (string)($product['sku'] ?? ''),
        'name' => (string)($product['name'] ?? ''),
        'description' => (string)($product['description'] ?? ''),
        'brand' => (string)($product['brand'] ?? ''),
        'barcode' => (string)($product['barcode'] ?? ''),
        'scanned_barcode' => $scanned !== '' ? $scanned : (string)($product['barcode'] ?? ''),
        'barcode_values' => array_values($barcodes),
        'packaging' => (string)($product['packaging'] ?? ''),
        'unit' => (string)($product['unit_of_measure'] ?? 'pcs'),
        'supplier' => (string)($product['supplier_name'] ?? ''),
        'supplier_id' => (int)($product['supplier_id'] ?? 0),
        'category' => $category,
        'subcategory' => $sub,
        'category_id' => (int)($product['category_id'] ?? 0),
        'supplier_id' => (int)($product['supplier_id'] ?? 0),
        'temperature' => (string)($product['storage_temperature'] ?? ''),
        'location' => (string)($product['location'] ?? 'Not assigned'),
        'quantity_in_stock' => $onHand,
        'sellable_qty' => $sellable,
        'damage_missing' => $dmg + $miss,
        'reorder_level' => (int)($product['reorder_level'] ?? 0),
        'cost' => (float)($product['cost_price'] ?? 0),
        'selling_price' => (float)($product['selling_price'] ?? 0),
        'expiry_date' => $nearest ?: ($product['expiry_date'] ?? null),
        'expiry_tracking' => (int)($product['expiry_tracking'] ?? 0),
        'status' => (string)($product['status'] ?? 'Active'),
        'batches' => $batches,
        'image' => (string)($product['product_image'] ?? ''),
    ];
}

function mobile_load_product(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    [$sql] = mobile_product_select_sql($conn);
    $stmt = $conn->prepare($sql . ' WHERE p.id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mobile_product_by_code(mysqli $conn, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    [$sql] = mobile_product_select_sql($conn);
    $linkedId = productBarcodesFindProductId($conn, $code);
    if ($linkedId > 0) {
        $stmt = $conn->prepare($sql . ' WHERE p.id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $linkedId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }
    $key = strtoupper($code);
    $stmt = $conn->prepare($sql . ' WHERE p.barcode=? OR UPPER(TRIM(p.barcode))=? OR UPPER(TRIM(p.sku))=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('sss', $code, $key, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mobile_decorate_incoming_row(array $r): array
{
    $r['mobile_status'] = mobile_map_delivery_status($r);
    $remaining = (int)($r['qty_remaining'] ?? 0);
    $processed = (int)($r['qty_processed'] ?? $r['qty_good'] ?? 0);
    if ($remaining <= 0 && $processed > 0) {
        $r['filter_bucket'] = 'received';
    } elseif ($processed > 0 && $remaining > 0) {
        $r['filter_bucket'] = 'receiving';
    } elseif ($remaining > 0) {
        $r['filter_bucket'] = 'pending';
    } else {
        $r['filter_bucket'] = 'received';
    }
    return $r;
}

function mobile_incoming_public_row(array $r): array
{
    return [
        'id' => (int)($r['id'] ?? 0),
        'po_number' => (string)($r['po_number'] ?? ''),
        'supplier_name' => (string)($r['supplier_name'] ?? ''),
        'order_date' => (string)($r['order_date'] ?? ''),
        'expected_delivery_date' => (string)($r['expected_delivery_date'] ?? ''),
        'status' => (string)($r['status'] ?? ''),
        'receiving_status' => (string)($r['receiving_status'] ?? ''),
        'mobile_status' => (string)($r['mobile_status'] ?? ''),
        'filter_bucket' => (string)($r['filter_bucket'] ?? 'pending'),
        'item_count' => (int)($r['item_count'] ?? 0),
        'qty_ordered' => (int)($r['qty_ordered'] ?? 0),
        'qty_received' => (int)($r['qty_received'] ?? 0),
        'qty_remaining' => (int)($r['qty_remaining'] ?? 0),
        'qty_good' => (int)($r['qty_good'] ?? $r['qty_processed'] ?? 0),
        'qty_damage_missing' => (int)($r['qty_damage_missing'] ?? 0),
        'ordered_items' => (string)($r['ordered_items'] ?? ''),
    ];
}

function mobile_incoming_rows(mysqli $conn): array
{
    $rows = function_exists('recvFetchIncomingDeliveryRows')
        ? recvFetchIncomingDeliveryRows($conn)
        : [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = mobile_decorate_incoming_row($r);
    }
    return $out;
}

function mobile_inventory_snapshot(mysqli $conn): array
{
    $sellableMap = invWmsSellableQtyMap($conn);
    $sql = "SELECT p.id, p.sku, p.name, p.quantity_in_stock, p.reorder_level, p.status,
                   COALESCE(p.cost_price,0) AS cost_price,
                   COALESCE(p.selling_price, p.unit_price, 0) AS selling_price,
                   COALESCE(p.expiry_tracking, 0) AS expiry_tracking,
                   COALESCE(p.expiry_alert_days, 90) AS expiry_alert_days,
                   CASE WHEN COALESCE(p.expiry_tracking,0)=1 THEN COALESCE(bx.batch_expiry, p.expiry_date) ELSE NULL END AS expiry_date
            FROM products p
            LEFT JOIN (
                SELECT product_id, MIN(expiry_date) AS batch_expiry
                FROM product_batches
                WHERE expiry_date IS NOT NULL AND quantity_remaining > 0
                GROUP BY product_id
            ) bx ON bx.product_id = p.id";
    $res = $conn->query($sql);
    if (!$res) {
        $sql = "SELECT p.id, p.sku, p.name, p.quantity_in_stock, p.reorder_level, p.status,
                       COALESCE(p.cost_price,0) AS cost_price,
                       COALESCE(p.selling_price, p.unit_price, 0) AS selling_price,
                       COALESCE(p.expiry_tracking, 0) AS expiry_tracking,
                       COALESCE(p.expiry_alert_days, 90) AS expiry_alert_days,
                       CASE WHEN COALESCE(p.expiry_tracking,0)=1 THEN p.expiry_date ELSE NULL END AS expiry_date
                FROM products p";
        $res = $conn->query($sql);
    }
    $totalProducts = 0;
    $totalStock = 0;
    $inventoryValue = 0.0;
    $low = 0;
    $near = 0;
    $expired = 0;
    $lowItems = [];
    $nearItems = [];
    $expiredItems = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $totalProducts++;
            $onHand = (int)($row['quantity_in_stock'] ?? 0);
            $totalStock += $onHand;
            $price = (float)($row['selling_price'] ?? 0);
            $inventoryValue += $onHand * $price;
            $row = invWmsAttachSellableLowStock($row, $sellableMap);
            if ((int)($row['is_low_stock'] ?? 0) === 1) {
                $low++;
                if (count($lowItems) < 40) {
                    $lowItems[] = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['name'],
                        'sku' => (string)($row['sku'] ?? ''),
                        'sellable_qty' => (int)($row['sellable_qty'] ?? $onHand),
                        'reorder_level' => (int)($row['reorder_level'] ?? 0),
                        'shortage' => (int)($row['shortage'] ?? 0),
                    ];
                }
            }
            $meta = getProductExpiryStatus(
                $row['expiry_date'] ?? null,
                (int)($row['expiry_tracking'] ?? 0),
                (int)($row['expiry_alert_days'] ?? 90)
            );
            $label = (string)($meta['label'] ?? '');
            if ($label === 'Expiring Soon' || $label === 'Near Expiry') {
                $near++;
                if (count($nearItems) < 40) {
                    $nearItems[] = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['name'],
                        'sku' => (string)($row['sku'] ?? ''),
                        'expiry_status' => $label === 'Expiring Soon' ? 'Near Expiry' : $label,
                        'expiry_display' => (string)($meta['display'] ?? ''),
                        'qty' => $onHand,
                    ];
                }
            } elseif ($label === 'Expired') {
                $expired++;
                if (count($expiredItems) < 40) {
                    $expiredItems[] = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['name'],
                        'sku' => (string)($row['sku'] ?? ''),
                        'expiry_status' => 'Expired',
                        'expiry_display' => (string)($meta['display'] ?? ''),
                        'qty' => $onHand,
                    ];
                }
            }
        }
    }
    return [
        'total_products' => $totalProducts,
        'total_stock_units' => $totalStock,
        'inventory_value' => (int)round($inventoryValue),
        'low_stock' => $low,
        'near_expiry' => $near,
        'expired' => $expired,
        'low_items' => $lowItems,
        'near_items' => $nearItems,
        'expired_items' => $expiredItems,
    ];
}

function mobile_recent_activities(mysqli $conn, array $incoming): array
{
    $activities = [];
    $seen = [];
    $sql = "SELECT po.id, po.po_number, po.expected_delivery_date, po.order_date, po.updated_at, po.created_at,
                   po.status, po.receiving_status,
                   rec.updated_at AS rec_updated, rec.created_at AS rec_created,
                   rec.workflow_status, rec.stock_in_number
            FROM purchase_orders po
            LEFT JOIN purchase_receipts rec ON rec.id = (
                SELECT r.id FROM purchase_receipts r WHERE r.po_id = po.id ORDER BY r.id DESC LIMIT 1
            )
            WHERE po.status NOT IN ('Draft','Cancelled','Pending Approval')
            ORDER BY COALESCE(rec.updated_at, rec.created_at, po.updated_at, po.created_at) DESC, po.id DESC
            LIMIT 12";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $poNo = (string)($row['po_number'] ?? '');
            if ($poNo === '' || isset($seen[$poNo])) {
                continue;
            }
            $seen[$poNo] = true;
            $wf = recvNormalizeWorkflowStatus((string)($row['workflow_status'] ?? ''));
            $recv = strtolower(trim((string)($row['receiving_status'] ?? '')));
            $sub = (string)($row['status'] ?? 'Updated');
            if ((string)($row['stock_in_number'] ?? '') !== '') {
                $sub = 'Stocked In';
            } elseif ($wf === recvWfStockedIn() || $recv === 'stocked in') {
                $sub = 'Stocked In';
            } elseif (in_array($wf, [recvWfGranted(), recvWfPendingAdmin()], true)) {
                $sub = 'Ready for Stock-In';
            } elseif ($recv === 'fully received' || strcasecmp((string)($row['status'] ?? ''), 'Received') === 0) {
                $sub = 'Received';
            } elseif ($recv === 'partially received' || $wf === recvWfIncomplete()) {
                $sub = 'Receiving in progress';
            } elseif ($wf === recvWfPendingMarkup()) {
                $sub = 'Pending Stock-In';
            }
            $when = (string)($row['rec_updated'] ?? $row['rec_created'] ?? $row['updated_at'] ?? $row['expected_delivery_date'] ?? $row['order_date'] ?? '');
            $activities[] = [
                'type' => (string)($row['stock_in_number'] ?? '') !== '' ? 'stock_in' : 'po',
                'title' => $poNo,
                'subtitle' => $sub,
                'when' => substr($when, 0, 10),
                'href' => 'receiving.php?po_id=' . (int)$row['id'],
            ];
        }
    }
    if (count($activities) < 6 && $incoming) {
        foreach ($incoming as $poAct) {
            $poNo = (string)($poAct['po_number'] ?? '');
            if ($poNo === '' || isset($seen[$poNo])) {
                continue;
            }
            $seen[$poNo] = true;
            $bucket = (string)($poAct['filter_bucket'] ?? 'pending');
            $sub = 'Awaiting Receiving';
            if ($bucket === 'receiving') {
                $sub = 'Receiving in progress';
            } elseif ($bucket === 'received') {
                $sub = 'Received';
            }
            $activities[] = [
                'type' => 'po',
                'title' => $poNo,
                'subtitle' => $sub,
                'when' => substr((string)($poAct['expected_delivery_date'] ?? $poAct['order_date'] ?? ''), 0, 10),
                'href' => 'receiving.php?po_id=' . (int)$poAct['id'],
            ];
            if (count($activities) >= 8) {
                break;
            }
        }
    }
    return array_slice($activities, 0, 8);
}

function mobile_dashboard_snapshot(mysqli $conn, bool $isAdmin): array
{
    $incomingRows = mobile_incoming_rows($conn);
    $forReceiving = 0;
    $compact = [];
    foreach ($incomingRows as $rowIn) {
        $compact[] = mobile_incoming_public_row($rowIn);
        $bucket = (string)($rowIn['filter_bucket'] ?? '');
        if ($bucket === 'pending' || $bucket === 'receiving') {
            $forReceiving++;
        }
    }
    $queueAll = mobile_stock_in_rows($conn, 'all');
    $pendingSi = 0;
    $readySi = 0;
    foreach ($queueAll as $q) {
        if (($q['bucket'] ?? '') === 'ready') {
            $readySi++;
        } else {
            $pendingSi++;
        }
    }
    $inv = mobile_inventory_snapshot($conn);
    $activeSuppliers = 0;
    $sr = @$conn->query("SELECT COUNT(*) c FROM suppliers WHERE status='Active'");
    if ($sr && ($srow = $sr->fetch_assoc())) {
        $activeSuppliers = (int)$srow['c'];
    }
    $dmg = 0;
    $dr = @$conn->query('SELECT COUNT(*) c FROM supplier_damage_returns WHERE (COALESCE(quantity_damaged,0)+COALESCE(quantity_missing,0)) > COALESCE(quantity_returned,0)');
    if ($dr && ($drow = $dr->fetch_assoc())) {
        $dmg = (int)$drow['c'];
    }
    $activities = mobile_recent_activities($conn, $incomingRows);
    $alerts = [];
    foreach ($inv['low_items'] as $item) {
        $alerts[] = [
            'kind' => 'low',
            'href' => 'product.php?id=' . (int)$item['id'],
            'title' => (string)$item['name'],
            'sub' => 'Low stock · ' . (int)$item['sellable_qty'] . ' left',
        ];
    }
    foreach ($inv['near_items'] as $item) {
        $alerts[] = [
            'kind' => 'near',
            'href' => 'product.php?id=' . (int)$item['id'],
            'title' => (string)$item['name'],
            'sub' => 'Near Expiry' . (!empty($item['expiry_display']) ? ' · ' . $item['expiry_display'] : ''),
        ];
    }
    foreach ($inv['expired_items'] as $item) {
        $alerts[] = [
            'kind' => 'exp',
            'href' => 'product.php?id=' . (int)$item['id'],
            'title' => (string)$item['name'],
            'sub' => 'Expired' . (!empty($item['expiry_display']) ? ' · ' . $item['expiry_display'] : ''),
        ];
    }
    $lastUpdated = date('Y-m-d H:i:s');
    $revision = md5(json_encode([
        count($incomingRows),
        $forReceiving,
        $inv['low_stock'],
        $inv['near_expiry'],
        $inv['expired'],
        $inv['total_stock_units'],
        $pendingSi,
        $readySi,
        $activities,
        $dmg,
    ], JSON_UNESCAPED_UNICODE));
    return [
        'incoming' => count($incomingRows),
        'incoming_po' => count($incomingRows),
        'for_receiving' => $forReceiving,
        'pending_stock_in' => $pendingSi,
        'ready_stock_in' => $readySi,
        'low_stock' => $inv['low_stock'],
        'near_expiry' => $inv['near_expiry'],
        'expired' => $inv['expired'],
        'damage_missing' => $dmg,
        'total_products' => $inv['total_products'],
        'total_stock_units' => $inv['total_stock_units'],
        'inventory_value' => $inv['inventory_value'],
        'active_suppliers' => $activeSuppliers,
        'alerts_count' => $forReceiving + $inv['low_stock'] + $inv['near_expiry'] + $inv['expired'] + $pendingSi,
        'activities' => $activities,
        'movements' => [],
        'deliveries' => $compact,
        'alerts' => $alerts,
        'is_admin' => $isAdmin,
        'last_updated' => $lastUpdated,
        'revision' => $revision,
    ];
}

function mobile_stock_in_rows(mysqli $conn, string $bucket = 'all'): array
{
    $pendingMarkup = recvWfPendingMarkup();
    $pendingAdmin = recvWfPendingAdmin();
    $granted = recvWfGranted();
    $stocked = recvWfStockedIn();
    $where = 'WHERE 1=1';
    if ($bucket === 'pending') {
        $where .= " AND rec.workflow_status IN ('" . $conn->real_escape_string($pendingMarkup) . "','Pending Stock-In','" . $conn->real_escape_string(recvWfIncomplete()) . "')";
    } elseif ($bucket === 'ready') {
        $where .= " AND rec.workflow_status IN ('" . $conn->real_escape_string($pendingAdmin) . "','" . $conn->real_escape_string($granted) . "','Ready for Stock-In','Approved - Pending Stock-In','Granted - Pending Stock-In')";
    } else {
        $where .= " AND rec.workflow_status IN (
            '" . $conn->real_escape_string($pendingMarkup) . "',
            '" . $conn->real_escape_string($pendingAdmin) . "',
            '" . $conn->real_escape_string($granted) . "',
            'Pending Stock-In','Ready for Stock-In','Approved - Pending Stock-In','Granted - Pending Stock-In'
        )";
    }
    $sql = "SELECT rec.*, po.po_number, po.receiving_status, s.name AS supplier_name,
                   u.full_name AS received_by_name,
                   (SELECT COUNT(*) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS item_count,
                   (SELECT COALESCE(SUM(quantity_good),0) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS total_good,
                   (SELECT COALESCE(SUM(quantity_damaged),0)+COALESCE(SUM(COALESCE(quantity_missing,0)),0) FROM purchase_receipt_items WHERE receipt_id=rec.id) AS total_damage_missing
            FROM purchase_receipts rec
            JOIN purchase_orders po ON rec.po_id=po.id
            LEFT JOIN suppliers s ON rec.supplier_id=s.id
            LEFT JOIN users u ON rec.received_by=u.id
            $where
            ORDER BY rec.id DESC
            LIMIT 150";
    $res = $conn->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $wf = recvNormalizeWorkflowStatus($r['workflow_status'] ?? '');
            $r['workflow_status'] = $wf;
            $r['bucket'] = in_array($wf, [$pendingAdmin, $granted], true) ? 'ready' : 'pending';
            $r['waiting_pricing'] = recvReceiptHasPendingPurchaserMarkup($conn, (int)$r['id']);
            $r['can_stock_in'] = !$r['waiting_pricing'] && recvIsReadyForWarehouseStockIn($wf);
            $rows[] = $r;
        }
    }
    return $rows;
}

function mobile_expiry_label(string $label): string
{
    if ($label === 'Normal') {
        return 'Safe';
    }
    if ($label === 'Expiring Soon') {
        return 'Near Expiry';
    }
    return $label !== '' ? $label : 'Safe';
}

try {
    switch ($action) {
        case 'session':
            mobile_json([
                'success' => true,
                'data' => $user + [
                    'csrf' => mobile_ensure_csrf(),
                    'can_approve' => $isAdmin,
                    'can_stock_in' => $isAdmin || $isWh,
                    'can_receive' => $isAdmin || $isWh,
                ],
            ]);
            break;

        case 'dashboard':
        case 'realtime_dashboard':
            $snap = mobile_dashboard_snapshot($conn, $isAdmin);
            mobile_json([
                'success' => true,
                'data' => $snap,
                'last_updated' => $snap['last_updated'],
            ]);
            break;

        case 'lookup_barcode':
            $code = trim((string)mobile_in('barcode', mobile_in('code')));
            $poId = (int)mobile_in('po_id', 0);
            $poItemId = (int)mobile_in('po_item_id', 0);
            if ($code === '') {
                mobile_json(['success' => false, 'code' => 'invalid', 'message' => 'Enter or scan a barcode.'], 400);
            }
            $product = mobile_product_by_code($conn, $code);
            $sellableMap = invWmsSellableQtyMap($conn);
            if ($poId > 0) {
                $look = recvLookupUniqueItemBarcode($conn, $code, 0);
                $payload = [
                    'success' => true,
                    'receive' => $look,
                    'product' => $product ? mobile_attach_incoming($conn, mobile_format_product($conn, $product, $sellableMap, $code)) : null,
                ];
                if (!$product && ($look['status'] ?? '') === 'not_found' && $poItemId <= 0) {
                    $payload['success'] = false;
                    $payload['code'] = 'unknown_barcode';
                    $payload['message'] = 'Barcode not found. Search for the product manually or enter the barcode.';
                    mobile_json($payload, 404);
                }
                mobile_json($payload);
            }
            if (!$product) {
                $q = trim($code);
                $matches = [];
                if (strlen($q) >= 2) {
                    [$sql] = mobile_product_select_sql($conn);
                    $like = '%' . $q . '%';
                    $stmt = $conn->prepare($sql . ' WHERE p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ? ORDER BY p.name LIMIT 8');
                    if ($stmt) {
                        $stmt->bind_param('sss', $like, $like, $like);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($res && ($row = $res->fetch_assoc())) {
                            $matches[] = mobile_format_product($conn, $row, $sellableMap, $code);
                        }
                        $stmt->close();
                    }
                }
                if (count($matches) === 1) {
                    mobile_json(['success' => true, 'message' => 'Item scanned successfully.', 'data' => mobile_attach_incoming($conn, $matches[0])]);
                }
                if ($matches) {
                    mobile_json(['success' => true, 'results' => $matches, 'message' => 'Select a product']);
                }
                mobile_json([
                    'success' => false,
                    'code' => 'unknown_barcode',
                    'message' => 'Barcode not found. Search for the product manually or enter the barcode.',
                ], 404);
            }
            mobile_json([
                'success' => true,
                'message' => 'Item scanned successfully.',
                'data' => mobile_attach_incoming($conn, mobile_format_product($conn, $product, $sellableMap, $code)),
            ]);
            break;

        case 'scan_record':
            $code = trim((string)mobile_in('barcode', mobile_in('code')));
            $requestId = trim((string)mobile_in('request_id'));
            $methodLabel = trim((string)mobile_in('input_method', 'Camera'));
            if ($code === '') {
                mobile_json(['success' => false, 'code' => 'invalid', 'message' => 'Enter or scan a barcode.'], 400);
            }
            if ($requestId !== '') {
                $cached = mobile_idempotency_get($conn, $requestId, $uid);
                if ($cached) {
                    mobile_json($cached);
                }
            }
            $product = mobile_product_by_code($conn, $code);
            $sellableMap = invWmsSellableQtyMap($conn);
            if (!$product) {
                mobile_log_scan($conn, $uid, $code, 'mobile_lookup', null, 'Unknown barcode from Warehouse Mobile', 'product_not_found', 'mobile');
                mobile_json([
                    'success' => false,
                    'code' => 'unknown_barcode',
                    'recorded' => false,
                    'message' => 'Barcode not found. Search for the product manually or enter the barcode.',
                ], 404);
            }
            $fmt = mobile_attach_incoming($conn, mobile_format_product($conn, $product, $sellableMap, $code));
            $po = $fmt['incoming_po'] ?? null;
            $recorded = false;
            $receive = null;
            if (is_array($po) && (int)($po['remaining'] ?? 0) > 0) {
                $conn->begin_transaction();
                try {
                    $receive = mobile_receive_scan_unit($conn, [
                        'po_id' => (int)$po['po_id'],
                        'po_item_id' => (int)($po['po_item_id'] ?? 0),
                        'barcode' => $code,
                        'status' => 'good',
                        'input_method' => $methodLabel !== '' ? $methodLabel : 'Camera',
                    ], $user);
                    $conn->commit();
                    $recorded = true;
                    $fmt = mobile_attach_incoming($conn, mobile_format_product($conn, $product, $sellableMap, $code));
                    mobile_log_scan(
                        $conn,
                        $uid,
                        $code,
                        'mobile_receive',
                        (int)$fmt['id'],
                        'Recorded on ' . (string)($po['po_number'] ?? 'PO') . ' from Warehouse Mobile',
                        'existing_product',
                        'mobile'
                    );
                } catch (Throwable $e) {
                    $conn->rollback();
                    $msg = $e->getMessage();
                    mobile_log_scan($conn, $uid, $code, 'mobile_lookup', (int)$fmt['id'], $msg, 'existing_product', 'mobile');
                    $payload = [
                        'success' => true,
                        'recorded' => false,
                        'message' => $msg,
                        'data' => $fmt,
                    ];
                    if ($requestId !== '') {
                        mobile_idempotency_put($conn, $requestId, $uid, (int)$fmt['id'], 'scan_record', $payload);
                    }
                    mobile_json($payload);
                }
            } else {
                mobile_log_scan(
                    $conn,
                    $uid,
                    $code,
                    'mobile_lookup',
                    (int)$fmt['id'],
                    'Lookup from Warehouse Mobile',
                    'existing_product',
                    'mobile'
                );
            }
            $payload = [
                'success' => true,
                'recorded' => $recorded,
                'inventory_updated' => false,
                'message' => $recorded
                    ? 'Scan recorded on the website receiving record.'
                    : 'Item scanned successfully.',
                'data' => $fmt,
                'receive' => $receive,
            ];
            if ($requestId !== '') {
                mobile_idempotency_put($conn, $requestId, $uid, (int)$fmt['id'], 'scan_record', $payload);
            }
            mobile_json($payload);
            break;

        case 'search_products':
            $q = trim((string)mobile_in('q', mobile_in('search')));
            $limit = max(1, min(40, (int)mobile_in('limit', 20)));
            if (strlen($q) < 2) {
                mobile_json(['success' => true, 'data' => []]);
            }
            [$sql] = mobile_product_select_sql($conn);
            $like = '%' . $q . '%';
            $stmt = $conn->prepare($sql . " WHERE p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.brand LIKE ?
                OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id=p.id AND pb.status='active' AND pb.barcode_value LIKE ?)
                ORDER BY p.name LIMIT $limit");
            $rows = [];
            $sellableMap = invWmsSellableQtyMap($conn);
            if ($stmt) {
                $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = mobile_format_product($conn, $row, $sellableMap, $q);
                }
                $stmt->close();
            }
            mobile_json(['success' => true, 'data' => $rows]);
            break;

        case 'product':
            $id = (int)mobile_in('id');
            $row = mobile_load_product($conn, $id);
            if (!$row) {
                mobile_json(['success' => false, 'message' => 'Product information could not be found.'], 404);
            }
            mobile_json(['success' => true, 'data' => mobile_attach_incoming($conn, mobile_format_product($conn, $row, invWmsSellableQtyMap($conn)))]);
            break;

        case 'inventory':
            $q = trim((string)mobile_in('q', mobile_in('search')));
            $catId = (int)mobile_in('category_id', 0);
            $supId = (int)mobile_in('supplier_id', 0);
            $expiry = strtolower(trim((string)mobile_in('expiry', '')));
            $sellableMap = invWmsSellableQtyMap($conn);
            [$sql] = mobile_product_select_sql($conn);
            $toRow = static function (array $fmt): array {
                return [
                    'id' => $fmt['id'],
                    'name' => $fmt['name'],
                    'sku' => $fmt['sku'],
                    'brand' => $fmt['brand'],
                    'barcode' => $fmt['barcode'],
                    'barcode_values' => $fmt['barcode_values'],
                    'sellable' => $fmt['sellable_qty'],
                    'qty' => $fmt['quantity_in_stock'],
                    'batch_lot' => $fmt['batches'][0]['batch_number'] ?? 'N/A',
                    'expiry_label' => $fmt['batches'][0]['status'] ?? 'N/A',
                    'expiry_date' => $fmt['expiry_date'],
                    'location' => $fmt['location'],
                    'price' => $fmt['selling_price'],
                    'cost' => $fmt['cost'],
                    'category' => $fmt['category'],
                    'category_id' => $fmt['category_id'] ?? 0,
                    'supplier_name' => $fmt['supplier'],
                    'supplier_id' => $fmt['supplier_id'] ?? 0,
                    'uom' => $fmt['unit'],
                    'damage' => $fmt['damage_missing'],
                    'reorder_level' => $fmt['reorder_level'],
                ];
            };
            $where = " WHERE p.status != 'Discontinued'";
            $types = '';
            $params = [];
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.brand LIKE ?
                    OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id=p.id AND pb.barcode_value LIKE ?)
                    OR EXISTS (SELECT 1 FROM product_batches pb2 WHERE pb2.product_id=p.id AND pb2.batch_number LIKE ?))";
                $types .= 'ssssss';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
            if ($catId > 0) {
                $where .= ' AND p.category_id=?';
                $types .= 'i';
                $params[] = $catId;
            }
            if ($supId > 0) {
                $where .= ' AND p.supplier_id=?';
                $types .= 'i';
                $params[] = $supId;
            }
            if ($expiry === 'expired') {
                $where .= ' AND EXISTS (SELECT 1 FROM product_batches pb3 WHERE pb3.product_id=p.id AND pb3.expiry_date < CURDATE() AND pb3.quantity_remaining > 0)';
            } elseif ($expiry === 'near' || $expiry === 'near_expiry') {
                $where .= ' AND EXISTS (SELECT 1 FROM product_batches pb3 WHERE pb3.product_id=p.id AND pb3.expiry_date >= CURDATE() AND pb3.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND pb3.quantity_remaining > 0)';
            } elseif ($expiry === 'good') {
                $where .= ' AND EXISTS (SELECT 1 FROM product_batches pb3 WHERE pb3.product_id=p.id AND pb3.expiry_date > DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND pb3.quantity_remaining > 0)';
            }
            $sqlFull = $sql . $where . ' ORDER BY p.name LIMIT 80';
            $rows = [];
            if ($types === '') {
                $res = $conn->query($sqlFull);
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $rows[] = $toRow(mobile_format_product($conn, $row, $sellableMap));
                    }
                }
            } else {
                $stmt = $conn->prepare($sqlFull);
                if ($stmt) {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($res && ($row = $res->fetch_assoc())) {
                        $rows[] = $toRow(mobile_format_product($conn, $row, $sellableMap, $q));
                    }
                    $stmt->close();
                }
            }
            mobile_json(['success' => true, 'data' => $rows]);
            break;

        case 'inventory_filters':
            $cats = [];
            $sups = [];
            $cq = $conn->query("SELECT id, name FROM categories ORDER BY name LIMIT 200");
            if ($cq) {
                while ($c = $cq->fetch_assoc()) {
                    $cats[] = $c;
                }
            }
            $sq = $conn->query("SELECT id, name FROM suppliers ORDER BY name LIMIT 200");
            if ($sq) {
                while ($s = $sq->fetch_assoc()) {
                    $sups[] = $s;
                }
            }
            mobile_json(['success' => true, 'categories' => $cats, 'suppliers' => $sups]);
            break;

        case 'low_stock':
            $sellableMap = invWmsSellableQtyMap($conn);
            $rows = [];
            $res = $conn->query("SELECT p.id, p.sku, p.name, p.brand, p.quantity_in_stock, p.reorder_level, s.name AS supplier_name
                                 FROM products p
                                 LEFT JOIN suppliers s ON s.id=p.supplier_id
                                 WHERE p.status != 'Discontinued'
                                 ORDER BY p.name");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $row = invWmsAttachSellableLowStock($row, $sellableMap);
                    if ((int)$row['is_low_stock'] !== 1) {
                        continue;
                    }
                    $rows[] = $row;
                }
            }
            usort($rows, static function ($a, $b) {
                return ((int)$b['shortage'] <=> (int)$a['shortage']);
            });
            mobile_json(['success' => true, 'data' => $rows]);
            break;

        case 'expiry':
            $lots = invStockLoadLotRows($conn);
            $out = [];
            foreach ($lots as $lot) {
                $lab = mobile_expiry_label((string)($lot['expiry_label'] ?? 'N/A'));
                if ($lab === 'N/A' || $lab === 'Not set') {
                    continue;
                }
                $lot['expiry_status'] = $lab;
                $out[] = $lot;
            }
            usort($out, static function ($a, $b) {
                return strcmp((string)($a['expiry_raw'] ?? '9999'), (string)($b['expiry_raw'] ?? '9999'));
            });
            mobile_json(['success' => true, 'data' => $out]);
            break;

        case 'deliveries':
            mobile_json(['success' => true, 'data' => mobile_incoming_rows($conn)]);
            break;

        case 'po_detail':
            $id = (int)mobile_in('id', mobile_in('po_id'));
            if ($id <= 0) {
                mobile_json(['success' => false, 'message' => 'Invalid PO ID'], 400);
            }
            $stmt = $conn->prepare("SELECT po.*, s.name AS supplier_name, s.supplier_code
                                    FROM purchase_orders po
                                    LEFT JOIN suppliers s ON s.id=po.supplier_id
                                    WHERE po.id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $po = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$po) {
                mobile_json(['success' => false, 'message' => 'Purchase Order not found'], 404);
            }
            $items = [];
            $it = $conn->prepare("SELECT poi.*, p.sku, p.brand, p.barcode, COALESCE(p.unit_of_measure,'pcs') AS unit_of_measure,
                                         COALESCE(p.expiry_tracking,0) AS expiry_tracking
                                  FROM purchase_order_items poi
                                  LEFT JOIN products p ON p.id=poi.product_id
                                  WHERE poi.po_id=? ORDER BY poi.id");
            $it->bind_param('i', $id);
            $it->execute();
            $ir = $it->get_result();
            while ($ir && ($row = $ir->fetch_assoc())) {
                $items[] = $row;
            }
            $it->close();
            $items = recvAttachPoItemInspectState($conn, $id, $items);
            $tot = recvPoInspectTotals($conn, $id);
            $open = recvFindIncompleteReceipt($conn, $id);
            $po['qty_good'] = (int)$tot['good'];
            $po['qty_damage_missing'] = (int)$tot['damage_missing'];
            $po['qty_remaining'] = (int)$tot['remaining'];
            $po['qty_ordered'] = (int)$tot['ordered'];
            $po['mobile_status'] = mobile_map_delivery_status($po + ['receipt_workflow_status' => $open['workflow_status'] ?? '']);
            $po['incomplete_receipt_id'] = $open ? (int)$open['id'] : 0;
            $po['driver_name'] = $open['driver_name'] ?? '';
            $po['remarks'] = $open['remarks'] ?? '';
            foreach ($items as &$line) {
                $st = mobile_po_line_state($line, $line);
                $line['quantity_good'] = $st['good'];
                $line['quantity_damage_missing'] = $st['damage_missing'];
                $line['quantity_remaining'] = $st['remaining'];
                $line['quantity_ordered'] = $st['ordered'];
            }
            unset($line);
            mobile_json(['success' => true, 'data' => $po, 'items' => $items]);
            break;

        case 'receive_scan':
            $requestId = trim((string)mobile_in('request_id'));
            if ($requestId !== '') {
                $cached = mobile_idempotency_get($conn, $requestId, $uid);
                if ($cached) {
                    mobile_json($cached);
                }
            }
            $conn->begin_transaction();
            try {
                $result = mobile_receive_scan_unit($conn, array_merge($_GET, $_POST), $user);
                $conn->commit();
                mobile_log_scan(
                    $conn,
                    $uid,
                    (string)($result['barcode'] ?? mobile_in('barcode')),
                    'mobile_receive',
                    (int)($result['product_id'] ?? 0) ?: null,
                    'Mobile receive recorded on PO ' . (string)($result['po_id'] ?? ''),
                    'existing_product',
                    'mobile'
                );
                if ($requestId !== '') {
                    mobile_idempotency_put($conn, $requestId, $uid, (int)$result['product_id'], 'receive_scan', $result);
                }
                mobile_json($result);
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = $e->getMessage();
                $code = 'error';
                $http = 400;
                if (stripos($msg, 'not found') !== false && stripos($msg, 'barcode') !== false) {
                    $code = 'unknown_barcode';
                    $http = 404;
                } elseif (stripos($msg, 'ordered quantity') !== false) {
                    $code = 'over_receive';
                } elseif (stripos($msg, 'already recorded') !== false || stripos($msg, 'already used') !== false) {
                    $code = 'duplicate';
                }
                if (function_exists('recvSafeUserMessage')) {
                    $msg = recvSafeUserMessage($msg, $code === 'unknown_barcode'
                        ? 'Unable to process barcode. Please try scanning again.'
                        : 'Unable to update inventory. Please try again.');
                }
                mobile_json(['success' => false, 'code' => $code, 'message' => $msg, 'submitted' => false], $http);
            }
            break;

        case 'stock_in_queue':
            $bucket = trim((string)mobile_in('bucket', 'all'));
            $rows = mobile_stock_in_rows($conn, $bucket);
            foreach ($rows as &$r) {
                $rid = (int)$r['id'];
                $items = [];
                $iq = $conn->query("SELECT pri.*, p.sku, p.barcode FROM purchase_receipt_items pri LEFT JOIN products p ON p.id=pri.product_id WHERE pri.receipt_id=$rid ORDER BY pri.id");
                if ($iq) {
                    while ($it = $iq->fetch_assoc()) {
                        $it['quantity_damage_missing'] = recvLineDamageMissingQty($it);
                        $items[] = $it;
                    }
                }
                $r['items'] = $items;
            }
            unset($r);
            mobile_json([
                'success' => true,
                'data' => $rows,
                'can_approve' => $isAdmin,
                'can_stock_in' => $isAdmin || $isWh,
            ]);
            break;

        case 'approve_stock_in':
            if (!$isAdmin) {
                mobile_json(['success' => false, 'message' => 'You are not authorized to perform this action.'], 403);
            }
            $receiptId = (int)mobile_in('receipt_id');
            if ($receiptId <= 0) {
                mobile_json(['success' => false, 'message' => 'Receipt is required.'], 400);
            }
            $conn->begin_transaction();
            try {
                $recStmt = $conn->prepare('SELECT rec.*, po.po_number FROM purchase_receipts rec JOIN purchase_orders po ON po.id=rec.po_id WHERE rec.id=? FOR UPDATE');
                $recStmt->bind_param('i', $receiptId);
                $recStmt->execute();
                $rec = $recStmt->get_result()->fetch_assoc();
                $recStmt->close();
                if (!$rec) {
                    throw new RuntimeException('Receipt not found');
                }
                $wf = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');
                if ($wf === recvWfStockedIn() || !empty($rec['stock_in_number'])) {
                    throw new RuntimeException('This receipt was already stocked in. Duplicate Stock-In is not allowed.');
                }
                if (recvReceiptHasPendingPurchaserMarkup($conn, $receiptId)) {
                    throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before approval.');
                }
                $granted = recvWfGranted();
                if ($wf === $granted) {
                    $conn->commit();
                    mobile_json([
                        'success' => true,
                        'workflow_status' => $granted,
                        'inventory_updated' => false,
                        'message' => 'Already approved. Complete Stock-In to update inventory.',
                    ]);
                }
                $allowed = [recvWfPendingAdmin(), recvWfPendingMarkup(), 'Ready for Stock-In'];
                if (!in_array($wf, $allowed, true) && !in_array((string)$rec['workflow_status'], $allowed, true)) {
                    throw new RuntimeException('Receipt is not ready for Admin approval.');
                }
                recvBackfillReceiptLinePricing($conn, $receiptId);
                $conn->query("UPDATE purchase_receipts SET workflow_status='" . $conn->real_escape_string($granted) . "', approved_by=$uid, approved_at=NOW() WHERE id=$receiptId");
                $conn->query("UPDATE purchase_receipt_items SET line_stock_status='" . $conn->real_escape_string($granted) . "' WHERE receipt_id=$receiptId AND quantity_good>0 AND COALESCE(stocked_in_qty,0)=0");
                $conn->commit();
                mobile_json([
                    'success' => true,
                    'workflow_status' => $granted,
                    'inventory_updated' => false,
                    'message' => 'Approved. The receipt is ready for Stock-In. Inventory is not increased until Stock-In is submitted.',
                ]);
            } catch (Throwable $e) {
                $conn->rollback();
                mobile_json(['success' => false, 'message' => $e->getMessage(), 'submitted' => false], 400);
            }
            break;

        case 'reject_stock_in':
            if (!$isAdmin) {
                mobile_json(['success' => false, 'message' => 'You are not authorized to perform this action.'], 403);
            }
            $receiptId = (int)mobile_in('receipt_id');
            $note = trim((string)mobile_in('remarks', mobile_in('reason')));
            if ($receiptId <= 0) {
                mobile_json(['success' => false, 'message' => 'Receipt is required.'], 400);
            }
            if ($note === '') {
                mobile_json(['success' => false, 'message' => 'Enter a correction note.'], 400);
            }
            $tag = '[CORRECTION REQUESTED] ' . $note;
            $back = recvWfPendingMarkup();
            $stmt = $conn->prepare("UPDATE purchase_receipts SET workflow_status=?, remarks=CONCAT(COALESCE(remarks,''), IF(COALESCE(remarks,'')='','',' | '), ?) WHERE id=?");
            $stmt->bind_param('ssi', $back, $tag, $receiptId);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                mobile_json(['success' => false, 'message' => 'Unable to save correction request.', 'submitted' => false], 400);
            }
            mobile_json([
                'success' => true,
                'inventory_updated' => false,
                'message' => 'Correction requested. Inventory was not changed.',
            ]);
            break;

        case 'execute_stock_in':
            if (!$isAdmin && !$isWh) {
                mobile_json(['success' => false, 'message' => 'You are not authorized to perform this action.'], 403);
            }
            $receiptId = (int)mobile_in('receipt_id');
            $requestId = trim((string)mobile_in('request_id'));
            if ($requestId !== '') {
                $cached = mobile_idempotency_get($conn, $requestId, $uid);
                if ($cached) {
                    mobile_json($cached);
                }
            }
            $conn->begin_transaction();
            try {
                $recStmt = $conn->prepare('SELECT rec.*, po.po_number, s.name AS supplier_name FROM purchase_receipts rec JOIN purchase_orders po ON po.id=rec.po_id LEFT JOIN suppliers s ON s.id=rec.supplier_id WHERE rec.id=? FOR UPDATE');
                $recStmt->bind_param('i', $receiptId);
                $recStmt->execute();
                $rec = $recStmt->get_result()->fetch_assoc();
                $recStmt->close();
                if (!$rec) {
                    throw new RuntimeException('Receipt not found');
                }
                $wf = recvNormalizeWorkflowStatus($rec['workflow_status'] ?? '');
                if ($wf === recvWfStockedIn() || !empty($rec['stock_in_number'])) {
                    throw new RuntimeException('This receipt was already stocked in. Duplicate Stock-In is not allowed.');
                }
                if (recvReceiptHasPendingPurchaserMarkup($conn, $receiptId)) {
                    throw new RuntimeException('Waiting for Pricing. Purchaser must confirm pricing before Stock-In.');
                }
                if (!recvIsReadyForWarehouseStockIn($rec['workflow_status'] ?? '') && $wf !== recvWfGranted()) {
                    if ($isAdmin && in_array($wf, [recvWfPendingAdmin(), recvWfPendingMarkup()], true) && !recvReceiptHasPendingPurchaserMarkup($conn, $receiptId)) {
                        $granted = recvWfGranted();
                        $conn->query("UPDATE purchase_receipts SET workflow_status='" . $conn->real_escape_string($granted) . "', approved_by=$uid, approved_at=NOW() WHERE id=$receiptId");
                        $rec['workflow_status'] = $granted;
                    } else {
                        throw new RuntimeException('This receipt is not ready for Stock-In.');
                    }
                }
                $done = recvCompleteStockIn($conn, $rec, $uid);
                $conn->commit();
                $payload = [
                    'success' => true,
                    'stocked_qty' => $done['stocked_qty'],
                    'stock_in_number' => $done['stock_in_number'],
                    'workflow_status' => $done['workflow_status'],
                    'inventory_updated' => true,
                    'message' => 'Stock-In ' . $done['stock_in_number'] . ' completed. ' . $done['stocked_qty'] . ' good unit(s) added to available inventory.',
                ];
                if ($requestId !== '') {
                    mobile_idempotency_put($conn, $requestId, $uid, 0, 'execute_stock_in', $payload);
                }
                mobile_json($payload);
            } catch (Throwable $e) {
                $conn->rollback();
                mobile_json(['success' => false, 'message' => $e->getMessage(), 'submitted' => false], 400);
            }
            break;

        case 'receive_complete':
            $poId = (int)mobile_in('po_id');
            if ($poId <= 0) {
                mobile_json(['success' => false, 'message' => 'Purchase order is required.'], 400);
            }
            $remarks = trim((string)mobile_in('remarks'));
            $driver = trim((string)mobile_in('delivered_by', mobile_in('driver_name')));
            $open = recvFindIncompleteReceipt($conn, $poId);
            if ($open) {
                $rid = (int)$open['id'];
                $upd = $conn->prepare('UPDATE purchase_receipts SET remarks=IF(?=\'\', remarks, ?), driver_name=IF(?=\'\', driver_name, ?) WHERE id=?');
                if ($upd) {
                    $upd->bind_param('ssssi', $remarks, $remarks, $driver, $driver, $rid);
                    $upd->execute();
                    $upd->close();
                }
            }
            mobile_json([
                'success' => true,
                'inventory_updated' => false,
                'message' => 'Receiving saved. Inventory increases only after Stock-In is completed.',
            ]);
            break;

        case 'returns':
            $rows = [];
            $sql = "SELECT dr.*, po.po_number, p.sku, p.name AS product_name, p.brand,
                           s.name AS supplier_name, rec.receipt_number,
                           COALESCE(pri.batch_number,'') AS batch_display, pri.expiry_date
                    FROM supplier_damage_returns dr
                    LEFT JOIN purchase_orders po ON po.id=dr.po_id
                    LEFT JOIN products p ON p.id=dr.product_id
                    LEFT JOIN suppliers s ON s.id=dr.supplier_id
                    LEFT JOIN purchase_receipts rec ON rec.id=dr.receipt_id
                    LEFT JOIN purchase_receipt_items pri ON pri.id=dr.receipt_item_id
                    ORDER BY dr.id DESC LIMIT 100";
            $res = $conn->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $r = rtsHydrateDamageRow($conn, $r);
                    $rows[] = $r;
                }
            }
            $rts = rtsList($conn);
            mobile_json(['success' => true, 'data' => $rows, 'rts' => $rts]);
            break;

        case 'create_return':
            $damageId = (int)mobile_in('damage_return_id', mobile_in('id'));
            $qty = (int)mobile_in('quantity', mobile_in('quantity_returned', 0));
            $reason = trim((string)mobile_in('reason', mobile_in('return_reason', 'Guba / Damaged')));
            $notes = trim((string)mobile_in('remarks', mobile_in('notes')));
            $returnDate = trim((string)mobile_in('return_date')) ?: date('Y-m-d');
            if ($damageId <= 0) {
                mobile_json(['success' => false, 'message' => 'Select a replacement record.'], 400);
            }
            $conn->begin_transaction();
            try {
                $created = rtsCreate($conn, $damageId, $qty, $reason, $notes, $returnDate, $uid);
                $conn->commit();
                mobile_json([
                    'success' => true,
                    'data' => $created,
                    'rts_number' => $created['rts_number'] ?? '',
                    'message' => 'Return to Supplier recorded: ' . ($created['rts_number'] ?? ''),
                    'inventory_updated' => false,
                ]);
            } catch (Throwable $e) {
                $conn->rollback();
                mobile_json(['success' => false, 'message' => $e->getMessage(), 'submitted' => false], 400);
            }
            break;

        default:
            mobile_json(['success' => false, 'message' => 'Unknown action.'], 404);
    }
} catch (Throwable $e) {
    mobile_json([
        'success' => false,
        'message' => 'Unable to connect to the server.',
        'submitted' => false,
    ], 500);
}
