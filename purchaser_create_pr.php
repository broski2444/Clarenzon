<?php
session_start();
require_once 'config.php';
require_once 'procurement_pr.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!isset($_SESSION['user_id']) || !in_array($role, ['purchaser','administrator','admin'], true)) {
    header('Location: login.php');
    exit;
}

$items = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['items'])) {
    $items = json_decode($_POST['items'], true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pr'])) {
    if (!csrf_validate_request()) { $error = 'Invalid CSRF token'; }
    else {
    // form submitted to create PR
    $payloadItems = [];
    foreach ($items as $it) {
        $payloadItems[] = [
            'product_id'=>$it['product_id'] ?? null,
            'sku'=>$it['sku'] ?? null,
            'name'=>$it['name'] ?? '',
            'brand'=>$it['brand'] ?? null,
            'unit'=>$it['unit'] ?? null,
            'quantity'=> (int)($_POST['quantity_' . ($it['sku'] ?? '')] ?? 1),
            'current_stock'=> (int)($it['stock'] ?? 0),
            'reorder_point'=> (int)($it['reorder_point'] ?? 0)
        ];
    }
    $res = createPurchaseRequest((int)$_SESSION['user_id'], $payloadItems, $_POST['reason'] ?? null);
    if ($res) {
        $_SESSION['flash_alert'] = '<div class="alert alert-success">PR created: ' . htmlspecialchars($res['pr_number']) . '</div>';
        header('Location: purchaser_create_pr.php');
        exit;
    } else {
        $error = 'Failed to create PR';
    }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Request | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <h2>Create Purchase Request</h2>
    <?php if (!empty($_SESSION['flash_alert'])) { echo $_SESSION['flash_alert']; unset($_SESSION['flash_alert']); } ?>
    <?php if (!empty($error)) { echo '<div class="alert alert-danger">'.htmlspecialchars($error).'</div>'; } ?>

    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="items" value='<?php echo json_encode($items, JSON_HEX_APOS|JSON_HEX_QUOT); ?>'>
        <div class="table-responsive system-table-scroll"><table class="table">
            <thead><tr><th>SKU</th><th>Name</th><th>Current Stock</th><th>Reorder Point</th><th>Quantity Requested</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it) {
                $sku = htmlspecialchars($it['sku'] ?? '');
                $name = htmlspecialchars($it['name'] ?? '');
                $stock = htmlspecialchars($it['stock'] ?? '0');
                $reorder = htmlspecialchars($it['reorder_point'] ?? '0');
                echo "<tr><td>$sku</td><td>$name</td><td>$stock</td><td>$reorder</td><td><input type=number name='quantity_$sku' value='1' min='1'></td></tr>";
            } ?>
            </tbody>
        </table></div>
        <div>
            <label>Reason (optional)</label><br>
            <textarea name="reason" rows="3" cols="80"></textarea>
        </div>
        <div style="margin-top:10px">
            <button type="submit" name="submit_pr">Submit PR</button>
        </div>
    </form>
    <p><a href="low_stock.php">Back to Low Stock</a></p>
</div>
</body>
</html>
