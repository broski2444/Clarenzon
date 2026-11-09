<?php
session_start();
require_once 'config.php';
require_once 'route_protection.php';

route_require_login(['admin']);

$conn = getDBConnection();
$rows = $conn ? $conn->query(
    "SELECT pr.receipt_number,
            po.po_number,
            COALESCE(s.name, pr.supplier_id) AS supplier_name,
            pr.date_received,
            COALESCE(u.full_name, u.username, pr.received_by) AS received_by_name
     FROM purchase_receipts pr
     LEFT JOIN purchase_orders po ON pr.po_id = po.id
     LEFT JOIN suppliers s ON pr.supplier_id = s.id
     LEFT JOIN users u ON pr.received_by = u.id
     ORDER BY pr.created_at DESC"
) : false;

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Goods Received | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script><link rel="stylesheet" href="css/style.css"></head>
<body>
<div class="container">
    <h2>Purchase Receipts</h2>
    <?php if (!$rows || $rows->num_rows === 0) { echo '<div>No receipts found.</div>'; } else { echo '<div class="table-responsive system-table-scroll"><table class="table"><thead><tr><th>Receipt#</th><th>PO#</th><th>Supplier</th><th>Date</th><th>Received By</th></tr></thead><tbody>'; while($r=$rows->fetch_assoc()) { echo '<tr><td>'.htmlspecialchars($r['receipt_number']).'</td><td>'.htmlspecialchars($r['po_number'] ?? '').'</td><td>'.htmlspecialchars($r['supplier_name'] ?? '').'</td><td>'.htmlspecialchars($r['date_received']).'</td><td>'.htmlspecialchars($r['received_by_name'] ?? '').'</td></tr>'; } echo '</tbody></table></div>'; } ?>
    <p><a href="admin_manage_po.php">Back to PO Management</a></p>
</div>
</body>
</html>
