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
$error = null;

// fetch receipts and POs
$receipts = $conn ? $conn->query("SELECT * FROM purchase_receipts ORDER BY created_at DESC") : false;
$suppliers = $conn ? $conn->query("SELECT * FROM suppliers ORDER BY name ASC") : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['po_id']) && !empty($_POST['receipt_id'])) {
    if (!csrf_validate_request()) { $error = 'Invalid CSRF token'; }
    else {
    $poId = (int)$_POST['po_id'];
    $receiptId = (int)$_POST['receipt_id'];
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $items = [];
    if (!empty($_POST['items'])) {
        $posted = json_decode($_POST['items'], true) ?: [];
        foreach ($posted as $it) {
            $sku = $it['sku'] ?? null;
            $items[] = [
                'sku'=>$sku,
                'name'=>$it['name'] ?? '',
                'quantity'=> (int)($_POST['qty_' . ($sku ?? '')] ?? 0),
                'unit_price'=> (float)($_POST['price_' . ($sku ?? '')] ?? 0)
            ];
        }
    }
    $total = (float)($_POST['total_amount'] ?? 0);
    $invDate = $_POST['invoice_date'] ?? null;
    $res = createSupplierInvoice($poId, $receiptId, $supplierId, $items, $total, $invDate);
    if ($res) { $_SESSION['flash_alert'] = '<div class="alert alert-success">Invoice created: '.htmlspecialchars($res['invoice_number']).'</div>'; header('Location: purchaser_create_invoice.php'); exit; } else { $error='Failed to create invoice'; }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Supplier Invoice | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <h2>Create Supplier Invoice</h2>
    <?php if (!empty($_SESSION['flash_alert'])) { echo $_SESSION['flash_alert']; unset($_SESSION['flash_alert']); } ?>
    <?php if ($error) echo '<div class="alert alert-danger">'.htmlspecialchars($error).'</div>'; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div>
            <label>PO</label>
            <select id="poSelect" name="po_id" onchange="loadItems()">
                <option value="">-- Select PO --</option>
                <?php $pos = $conn ? $conn->query("SELECT * FROM purchase_orders ORDER BY created_at DESC") : false; if ($pos && $pos->num_rows>0) { while($p=$pos->fetch_assoc()) { echo '<option value="'.(int)$p['id'].'">'.htmlspecialchars($p['po_number']).'</option>'; }} ?>
            </select>
        </div>
        <div>
            <label>Receipt</label>
            <select id="receiptSelect" name="receipt_id">
                <option value="">-- Select Receipt --</option>
                <?php if ($receipts && $receipts->num_rows>0) { while($r=$receipts->fetch_assoc()) { echo '<option value="'.(int)$r['id'].'">'.htmlspecialchars($r['receipt_number']).' (PO: '.htmlspecialchars($r['po_id']).')</option>'; }} ?>
            </select>
        </div>
        <div>
            <label>Supplier</label>
            <select name="supplier_id">
                <?php if ($suppliers && $suppliers->num_rows>0) { while($s=$suppliers->fetch_assoc()) { echo '<option value="'.(int)$s['id'].'">'.htmlspecialchars($s['name']).'</option>'; }} else { echo '<option value="">No suppliers</option>'; } ?>
            </select>
        </div>
        <div id="itemsArea"></div>
        <div>
            <label>Invoice Date</label><br>
            <input type="date" name="invoice_date">
        </div>
        <div>
            <label>Total Amount</label><br>
            <input type="number" step="0.01" name="total_amount">
        </div>
        <div style="margin-top:10px"><button type="submit">Create Invoice</button></div>
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
        area.innerHTML = '<div class="table-responsive system-table-scroll"><table class="table"><thead><tr><th>SKU</th><th>Name</th><th>Qty</th><th>Unit Price</th></tr></thead><tbody>';
        const posted = [];
        for (const it of items) {
            const sku = it.product_sku || '';
            posted.push({sku:sku, name: it.product_name});
            area.innerHTML += `<tr><td>${sku}</td><td>${it.product_name || ''}</td><td><input type=number name="qty_${sku}" value="${it.quantity_ordered || 0}" min="0"></td><td><input type=number step="0.01" name="price_${sku}" value="${it.unit_price || 0}"></td></tr>`;
        }
        area.innerHTML += '</tbody></table></div>';
        const hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='items'; hidden.value = JSON.stringify(posted); document.querySelector('form').appendChild(hidden);
    }
    </script>

    <p><a href="purchaser_create_receipt.php">Back to Receipt</a></p>
</div>
</body>
</html>
