<?php
session_start();
require_once 'config.php';
require_once 'procurement_pr.php';

$role = strtolower(str_replace([' ', '-'], '_', trim((string)($_SESSION['role'] ?? ''))));
if (!isset($_SESSION['user_id']) || !in_array($role, ['purchaser','warehouse_head','administrator','admin'], true)) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$error = null;

// fetch POs that can still be received by Warehouse Head/Admin
$pos = $conn ? $conn->query("SELECT * FROM purchase_orders WHERE status IN ('Submitted','Approved','Sent','Supplier Confirmed','Waiting for Delivery','Partially Received') ORDER BY created_at DESC") : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['po_id'])) {
    if (!csrf_validate_request()) { $error = 'Invalid CSRF token'; }
    else {
    $poId = (int)$_POST['po_id'];
    $items = [];
    if (!empty($_POST['items'])) {
        $posted = json_decode($_POST['items'], true) ?: [];
        foreach ($posted as $it) {
            $sku = $it['sku'] ?? null;
            $items[] = [
                'product_id'=> (int)($it['product_id'] ?? 0),
                'sku'=>$sku,
                'name'=>$it['name'] ?? '',
                'quantity_ordered'=> (int)($it['quantity_ordered'] ?? 0),
                'quantity_received'=> (int)($_POST['qty_' . ($sku ?? '')] ?? 0),
                'batch'=> $_POST['batch_' . ($sku ?? '')] ?? null,
                'expiry'=> $_POST['expiry_' . ($sku ?? '')] ?? null
            ];
        }
    }
    $delivery = $_POST['delivery_date'] ?? null;
    $remarks = $_POST['remarks'] ?? null;
    $res = createReceipt($poId, (int)$_SESSION['user_id'], $items, $delivery, $remarks);
    if ($res) {
        $_SESSION['flash_alert'] = '<div class="alert alert-success">Receipt created: ' . htmlspecialchars($res['receipt_number']) . '</div>';
        header('Location: purchaser_create_receipt.php'); exit;
    } else {
        $error = 'Failed to create receipt. Ensure PO is approved and try again.';
    }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Receipt | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <h2>Create Purchase Receipt (Goods Received)</h2>
    <?php if (!empty($_SESSION['flash_alert'])) { echo $_SESSION['flash_alert']; unset($_SESSION['flash_alert']); } ?>
    <?php if ($error) echo '<div class="alert alert-danger">'.htmlspecialchars($error).'</div>'; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div>
            <label>Select Approved PO</label>
            <select id="poSelect" name="po_id" onchange="loadItems()">
                <option value="">-- Select PO --</option>
                <?php if ($pos && $pos->num_rows>0) { while($p=$pos->fetch_assoc()) { echo '<option value="'.(int)$p['id'].'">'.htmlspecialchars($p['po_number']).' (PR: '.htmlspecialchars($p['pr_id']).')</option>'; }} else { echo '<option value="">No approved POs</option>'; } ?>
            </select>
        </div>
        <div>
            <label>Delivery Date</label><br>
            <input type="date" name="delivery_date">
        </div>
        <div id="itemsArea">
            <!-- items loaded by JS -->
        </div>
        <div>
            <label>Remarks</label><br>
            <textarea name="remarks" rows="3"></textarea>
        </div>
        <div style="margin-top:10px"><button type="submit">Save Receipt</button></div>
    </form>

    <script>
    async function loadItems(){
        const poId = document.getElementById('poSelect').value;
        const area = document.getElementById('itemsArea');
        area.innerHTML = '';
        if (!poId) return;
        const itemsRes = await fetch('api/po_items.php?po_id=' + encodeURIComponent(poId));
        const itemsJson = await itemsRes.json();
        if (!itemsJson.success) { area.innerHTML = '<div>Unable to load items.</div>'; return; }
        const items = itemsJson.items;
        if (items.length === 0) { area.innerHTML = '<div>No items found for this PO.</div>'; return; }
        area.innerHTML = '<div class="table-responsive system-table-scroll"><table class="table"><thead><tr><th>SKU</th><th>Name</th><th>Qty Ordered</th><th>Qty Received</th><th>Batch</th><th>Expiry</th></tr></thead><tbody>';
        const posted = [];
        for (const it of items) {
            const sku = it.product_sku || '';
            const ordered = parseInt(it.quantity_ordered || 0, 10);
            posted.push({product_id: it.product_id || 0, sku:sku, name: it.product_name, quantity_ordered: ordered});
            area.innerHTML += `<tr><td>${sku}</td><td>${it.product_name || ''}</td><td>${ordered}</td><td><input type=number name="qty_${sku}" value="${ordered}" min="0"></td><td><input type=text name="batch_${sku}"></td><td><input type=date name="expiry_${sku}"></td></tr>`;
        }
        area.innerHTML += '</tbody></table></div>';
        const hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='items'; hidden.value = JSON.stringify(posted); document.querySelector('form').appendChild(hidden);
    }
    </script>

    <p><a href="purchaser_create_po.php">Back to PO Create</a></p>
</div>
</body>
</html>
