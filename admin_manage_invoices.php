<?php
session_start();
require_once 'config.php';
require_once 'route_protection.php';

route_require_login(['admin']);

$conn = getDBConnection();
$rows = $conn ? $conn->query(
    "SELECT si.invoice_number,
            po.po_number,
            pr.receipt_number,
            COALESCE(s.name, si.supplier_id) AS supplier_name,
            si.invoice_date,
            si.total_amount,
            si.payment_status
     FROM supplier_invoices si
     LEFT JOIN purchase_orders po ON si.po_id = po.id
     LEFT JOIN purchase_receipts pr ON si.receipt_id = pr.id
     LEFT JOIN suppliers s ON si.supplier_id = s.id
     ORDER BY si.created_at DESC"
) : false;

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Manage Invoices | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script><link rel="stylesheet" href="css/style.css"></head>
<body>
<div class="container">
    <h2>Supplier Invoices</h2>
    <?php if (!$rows || $rows->num_rows === 0) { echo '<div>No invoices found.</div>'; } else { echo '<div class="table-responsive system-table-scroll"><table class="table"><thead><tr><th>Invoice#</th><th>PO#</th><th>Receipt#</th><th>Supplier</th><th>Date</th><th>Total</th><th>Status</th></tr></thead><tbody>'; while($r=$rows->fetch_assoc()) { echo '<tr><td>'.htmlspecialchars($r['invoice_number']).'</td><td>'.htmlspecialchars($r['po_number'] ?? '').'</td><td>'.htmlspecialchars($r['receipt_number'] ?? '').'</td><td>'.htmlspecialchars($r['supplier_name'] ?? '').'</td><td>'.htmlspecialchars($r['invoice_date']).'</td><td>'.htmlspecialchars($r['total_amount']).'</td><td>'.htmlspecialchars($r['payment_status']).'</td></tr>'; } echo '</tbody></table></div>'; } ?>
    <p><a href="admin_manage_po.php">Back to PO Management</a></p>
</div>
</body>
</html>
