<?php
session_start();
require_once 'config.php';
require_once 'procurement_pr.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!isset($_SESSION['user_id']) || !in_array($role, ['purchaser','administrator','admin'], true)) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
// fetch approved PRs for this purchaser
$prs = $conn ? $conn->query("SELECT * FROM purchase_requests WHERE requested_by = " . (int)$_SESSION['user_id'] . " AND status = 'Approved' ORDER BY created_at DESC") : false;
$suppliers = $conn ? $conn->query("SELECT * FROM suppliers ORDER BY name ASC") : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pr_id']) && !empty($_POST['supplier_id'])) {
    if (!csrf_validate_request()) { $error = 'Invalid CSRF token'; }
    else {
    // call API to create PO
    $prId = (int)$_POST['pr_id'];
    $supplierId = (int)$_POST['supplier_id'];
    $delivery = $_POST['delivery_date'] ?? null;
    // simple server-side call
    $res = createPurchaseOrderFromPR($prId, $supplierId, (int)$_SESSION['user_id'], $delivery);
    if ($res) {
        $_SESSION['flash_alert'] = '<div class="alert alert-success">PO created: ' . htmlspecialchars($res['po_number']) . '</div>';
        header('Location: purchaser_create_po.php'); exit;
    } else {
        $error = 'Failed to create PO. Ensure the PR is approved and try again.';
    }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Order | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <h2>Create Purchase Order from Approved PR</h2>
    <?php if (!empty($_SESSION['flash_alert'])) { echo $_SESSION['flash_alert']; unset($_SESSION['flash_alert']); } ?>
    <?php if (!empty($error)) echo '<div class="alert alert-danger">'.htmlspecialchars($error).'</div>'; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div>
            <label>Select PR</label>
            <select name="pr_id">
                <?php if ($prs && $prs->num_rows>0) { while($p=$prs->fetch_assoc()) { echo '<option value="'.(int)$p['id'].'">'.htmlspecialchars($p['pr_number']).' - '.htmlspecialchars($p['created_at']).'</option>'; }} else { echo '<option value="">No approved PRs</option>'; } ?>
            </select>
        </div>
        <div>
            <label>Select Supplier</label>
            <select name="supplier_id">
                <?php if ($suppliers && $suppliers->num_rows>0) { while($s=$suppliers->fetch_assoc()) { echo '<option value="'.(int)$s['id'].'">'.htmlspecialchars($s['name']).'</option>'; }} else { echo '<option value="">No suppliers</option>'; } ?>
            </select>
        </div>
        <div>
            <label>Delivery Date</label><br>
            <input type="date" name="delivery_date">
        </div>
        <div style="margin-top:10px"><button type="submit">Generate PO</button></div>
    </form>
    <p><a href="low_stock.php">Back to Low Stock</a></p>
</div>
</body>
</html>
