<?php
/**
 * Product selling-price / markup API for the Sales module.
 * Server-side ACL: only authorized roles/users may create or modify prices.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
header('Content-Type: application/json');

clarenzon_api_require();

require_once __DIR__ . '/mysqli_connect_fast.php';
require_once __DIR__ . '/product_pricing_lib.php';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'], 'root', '', 3);

function ppOut(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (!$conn || $conn->connect_error) {
    ppOut(['success' => false, 'message' => 'Database connection failed'], 500);
}

ensureProductPricingSchema($conn);

$uid = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$username = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
$canManage = userCanManageProductPricing($conn, $uid, $role);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if (function_exists('clarenzon_api_guard_method')) {
    clarenzon_api_guard_method($action);
}

switch ($action) {
    case 'capabilities':
        ppOut([
            'success' => true,
            'can_manage_pricing' => $canManage,
            'can_view_pricing' => true,
        ]);

    case 'list_product_pricing':
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $sql = "SELECT p.id, p.sku, p.name, p.quantity_in_stock, p.cost_price, p.unit_price,
                       p.selling_price, COALESCE(p.markup_percent, 0) AS markup_percent,
                       p.selling_price_updated_at, p.selling_price_updated_by, p.status,
                       u.full_name AS updated_by_name, u.username AS updated_by_username
                FROM products p
                LEFT JOIN users u ON u.id = p.selling_price_updated_by
                WHERE COALESCE(p.status, 'Active') <> 'Discontinued'";
        $params = [];
        $types = '';
        if ($q !== '') {
            $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
            $like = '%' . $q . '%';
            $params = [$like, $like, $like];
            $types = 'sss';
        }
        $sql .= " ORDER BY p.name ASC LIMIT 500";

        $rows = [];
        if ($types !== '') {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                ppOut(['success' => false, 'message' => $conn->error], 500);
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sql);
        }
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $cost = (float)($r['cost_price'] ?? 0);
                if ($cost <= 0) {
                    $cost = (float)($r['unit_price'] ?? 0);
                }
                $sell = (float)($r['selling_price'] ?? 0);
                $markup = (float)($r['markup_percent'] ?? 0);
                if ($markup <= 0 && $cost > 0 && $sell > 0) {
                    $markup = calcMarkupPercentFromPrices($cost, $sell);
                }
                $rows[] = [
                    'id' => (int)$r['id'],
                    'sku' => (string)$r['sku'],
                    'name' => (string)$r['name'],
                    'quantity_in_stock' => (int)$r['quantity_in_stock'],
                    'cost_price' => round($cost, 2),
                    'markup_percent' => round($markup, 2),
                    'selling_price' => round($sell, 2),
                    'last_updated' => $r['selling_price_updated_at'],
                    'updated_by' => $r['updated_by_name'] ?: ($r['updated_by_username'] ?: null),
                    'updated_by_id' => $r['selling_price_updated_by'] !== null ? (int)$r['selling_price_updated_by'] : null,
                    'status' => (string)($r['status'] ?? 'Active'),
                ];
            }
        }
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        ppOut(['success' => true, 'can_manage_pricing' => $canManage, 'data' => $rows]);

    case 'get_product_pricing':
        $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            ppOut(['success' => false, 'message' => 'Product ID required'], 400);
        }
        $stmt = $conn->prepare(
            "SELECT p.id, p.sku, p.name, p.quantity_in_stock, p.cost_price, p.unit_price,
                    p.selling_price, COALESCE(p.markup_percent, 0) AS markup_percent,
                    p.selling_price_updated_at, p.selling_price_updated_by,
                    u.full_name AS updated_by_name
             FROM products p
             LEFT JOIN users u ON u.id = p.selling_price_updated_by
             WHERE p.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) {
            ppOut(['success' => false, 'message' => 'Product not found'], 404);
        }
        $cost = (float)($r['cost_price'] ?? 0);
        if ($cost <= 0) {
            $cost = (float)($r['unit_price'] ?? 0);
        }
        $sell = (float)($r['selling_price'] ?? 0);
        $markup = (float)($r['markup_percent'] ?? 0);
        if ($markup <= 0 && $cost > 0 && $sell > 0) {
            $markup = calcMarkupPercentFromPrices($cost, $sell);
        }
        ppOut([
            'success' => true,
            'can_manage_pricing' => $canManage,
            'data' => [
                'id' => (int)$r['id'],
                'sku' => (string)$r['sku'],
                'name' => (string)$r['name'],
                'quantity_in_stock' => (int)$r['quantity_in_stock'],
                'cost_price' => round($cost, 2),
                'markup_percent' => round($markup, 2),
                'selling_price' => round($sell, 2),
                'last_updated' => $r['selling_price_updated_at'],
                'updated_by' => $r['updated_by_name'],
            ],
        ]);

    case 'update_product_selling_price':
        if (!$canManage) {
            ppOut(['success' => false, 'message' => 'Not authorized to create or modify selling prices'], 403);
        }
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            ppOut(['success' => false, 'message' => 'Product ID required'], 400);
        }

        $mode = strtolower(trim((string)($_POST['mode'] ?? 'markup')));
        $markupIn = array_key_exists('markup_percent', $_POST) ? (float)$_POST['markup_percent'] : null;
        $sellIn = array_key_exists('selling_price', $_POST) ? (float)$_POST['selling_price'] : null;
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($notes === '') {
            $notes = null;
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "SELECT id, sku, name, cost_price, unit_price, selling_price, COALESCE(markup_percent, 0) AS markup_percent
                 FROM products WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'Failed to lock product');
            }
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $prod = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$prod) {
                throw new RuntimeException('Product not found');
            }

            $cost = (float)($prod['cost_price'] ?? 0);
            if ($cost <= 0) {
                $cost = (float)($prod['unit_price'] ?? 0);
            }
            if ($cost < 0) {
                throw new RuntimeException('Invalid cost price on product');
            }

            $prevSell = (float)($prod['selling_price'] ?? 0);
            $prevMarkup = (float)($prod['markup_percent'] ?? 0);
            if ($prevMarkup <= 0 && $cost > 0 && $prevSell > 0) {
                $prevMarkup = calcMarkupPercentFromPrices($cost, $prevSell);
            }

            if ($mode === 'selling' || ($sellIn !== null && $markupIn === null)) {
                if ($sellIn === null || $sellIn < 0) {
                    throw new RuntimeException('Selling price must be 0 or greater');
                }
                $newSell = round($sellIn, 2);
                $newMarkup = calcMarkupPercentFromPrices($cost, $newSell);
            } else {
                if ($markupIn === null || $markupIn < 0) {
                    throw new RuntimeException('Markup % must be 0 or greater');
                }
                $newMarkup = round($markupIn, 2);
                $newSell = calcSellingPriceFromMarkup($cost, $newMarkup);
            }

            if (abs($newSell - $prevSell) < 0.00001 && abs($newMarkup - $prevMarkup) < 0.00001) {
                $conn->commit();
                ppOut([
                    'success' => true,
                    'message' => 'No price change',
                    'data' => [
                        'id' => $productId,
                        'cost_price' => round($cost, 2),
                        'markup_percent' => round($newMarkup, 2),
                        'selling_price' => round($newSell, 2),
                    ],
                ]);
            }

            $upd = $conn->prepare(
                "UPDATE products
                 SET selling_price = ?, unit_price = ?, markup_percent = ?,
                     selling_price_updated_at = NOW(), selling_price_updated_by = ?
                 WHERE id = ?"
            );
            if (!$upd) {
                throw new RuntimeException($conn->error);
            }
            $upd->bind_param('dddii', $newSell, $newSell, $newMarkup, $uid, $productId);
            if (!$upd->execute()) {
                throw new RuntimeException($upd->error ?: 'Failed to update selling price');
            }
            $upd->close();

            logProductPriceChange(
                $conn,
                $productId,
                [
                    'sku' => $prod['sku'],
                    'name' => $prod['name'],
                    'cost_price' => $cost,
                    'markup_percent' => $prevMarkup,
                    'selling_price' => $prevSell,
                ],
                [
                    'sku' => $prod['sku'],
                    'name' => $prod['name'],
                    'cost_price' => $cost,
                    'markup_percent' => $newMarkup,
                    'selling_price' => $newSell,
                ],
                $uid,
                $username,
                $role,
                'sales_pricing',
                $notes
            );

            $conn->commit();
            ppOut([
                'success' => true,
                'message' => 'Selling price updated',
                'data' => [
                    'id' => $productId,
                    'sku' => $prod['sku'],
                    'name' => $prod['name'],
                    'cost_price' => round($cost, 2),
                    'markup_percent' => round($newMarkup, 2),
                    'selling_price' => round($newSell, 2),
                    'last_updated' => date('Y-m-d H:i:s'),
                    'updated_by' => $username,
                ],
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            ppOut(['success' => false, 'message' => $e->getMessage()], 400);
        }

    case 'list_price_audit':
        if (!$canManage && !in_array(strtolower(str_replace([' ', '-'], '_', $role)), ['admin', 'administrator', 'warehouse_head'], true)) {
            // Viewers with sales access may still see audit for transparency on prices they can view
        }
        $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
        $sql = "SELECT * FROM product_price_audit";
        if ($productId > 0) {
            $sql .= " WHERE product_id = " . $productId;
        }
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 200";
        $rows = [];
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        ppOut(['success' => true, 'data' => $rows]);

    default:
        ppOut(['success' => false, 'message' => 'Unknown action'], 400);
}
