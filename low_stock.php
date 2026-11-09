<?php
session_start();
require_once 'config.php';
require_once 'route_protection.php';

route_require_login();

// Allow purchasers and admins and others to view low stock
$role = $_SESSION['role'] ?? '';

$conn = getDBConnection();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo '<meta name="csrf-token" content="'.htmlspecialchars(csrf_get_token()).'">'; ?>
    <title>Low Stock Alerts | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <script src="java/app_dialog.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <h2>Low Stock Alerts</h2>
    <?php if (!$conn) { ?>
        <div class="alert alert-warning">No DB connection available.</div>
    <?php } else {
        // Best-effort: detect products table
        $res = $conn->query("SHOW TABLES LIKE 'products'");
        if ($res && $res->num_rows > 0) {
            require_once __DIR__ . '/inventory_wms_lib.php';
            invWmsEnsureSchema($conn);
            $q = "SELECT id, id AS product_id, sku, name, brand, unit_of_measure AS unit,
                         quantity_in_stock, quantity_in_stock AS stock, reorder_level,
                         reorder_level AS reorder_point, reorder_quantity, supplier_id
                  FROM products
                  WHERE status != 'Discontinued'
                  ORDER BY quantity_in_stock ASC";
            $rows = $conn->query($q);
            $sellableMap = invWmsSellableQtyMap($conn);
            $lowRows = [];
            if ($rows) {
                while ($r = $rows->fetch_assoc()) {
                    $attached = invWmsAttachSellableLowStock($r, $sellableMap);
                    if ((int)$attached['is_low_stock'] !== 1) continue;
                    $r['stock'] = (int)$attached['quantity_in_stock'];
                    $r['reorder_point'] = (int)$attached['reorder_level'];
                    $lowRows[] = $r;
                }
            }
            if (count($lowRows) > 0) {
                echo '<form id="prForm">';
                echo '<div class="table-responsive system-table-scroll"><table class="table"><thead><tr><th></th><th>SKU</th><th>Name</th><th>Brand</th><th>Unit</th><th>Stock</th><th>Reorder Point</th></tr></thead><tbody>';
                foreach ($lowRows as $r) {
                    $sku = htmlspecialchars($r['sku']);
                    $name = htmlspecialchars($r['name']);
                    echo "<tr>";
                    echo "<td><input type=checkbox name=sel[] value='".htmlspecialchars(json_encode($r),ENT_QUOTES)."'></td>";
                    echo "<td>$sku</td><td>$name</td><td>".htmlspecialchars($r['brand'])."</td><td>".htmlspecialchars($r['unit'])."</td><td>".htmlspecialchars($r['stock'])."</td><td>".htmlspecialchars($r['reorder_point'])."</td>";
                    echo "</tr>";
                }
                echo '</tbody></table></div>';
                echo '<button type="button" id="createPr">Create PR from selected</button>';
                echo '</form>';
            } else {
                echo '<div>No low-stock items detected.</div>';
            }
        } else {
            echo '<div>Products table not found. Low-stock detection requires a `products` table with inventory quantity and reorder level fields.</div>';
        }
    }
    ?>

    <script>
    document.getElementById('createPr')?.addEventListener('click', function(){
        const checked = Array.from(document.querySelectorAll('input[name="sel[]"]:checked'));
        if (checked.length === 0) { alert('Select at least one item'); return; }
        const items = checked.map(c => JSON.parse(c.value));
        // post to purchaser_create_pr.php using form POST with JSON payload
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = 'purchaser_create_pr.php';
        const inp = document.createElement('input'); inp.type='hidden'; inp.name='items'; inp.value = JSON.stringify(items); f.appendChild(inp);
        const token = document.querySelector('meta[name="csrf-token"]').content;
        const cs = document.createElement('input'); cs.type='hidden'; cs.name='csrf_token'; cs.value = token; f.appendChild(cs);
        document.body.appendChild(f); f.submit();
    });
    </script>
</div>
</body>
</html>
