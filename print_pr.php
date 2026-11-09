<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$pr_id = (int)($_GET['pr_id'] ?? 0);
if ($pr_id <= 0) {
    die("Invalid Purchase Request ID.");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'inventory_db', 'clarenzon_inventory']);
if (!$conn) {
    die("Database connection failed.");
}

// Fetch PR header
$stmt = $conn->prepare("
    SELECT pr.*,
           s.name AS supplier_name, s.supplier_code, s.contact_person AS supplier_contact,
           s.email AS supplier_email, s.phone AS supplier_phone,
           u1.full_name AS requested_by_name, u1.role AS requester_role,
           u2.full_name AS approved_by_name,
           (SELECT po_number FROM purchase_orders WHERE pr_id=pr.id ORDER BY id ASC LIMIT 1) AS generated_po_number
    FROM purchase_requests pr
    LEFT JOIN suppliers s  ON pr.supplier_id   = s.id
    LEFT JOIN users u1     ON pr.requested_by  = u1.id
    LEFT JOIN users u2     ON pr.approved_by   = u2.id
    WHERE pr.id = ?
");
$stmt->bind_param('i', $pr_id);
$stmt->execute();
$pr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pr) {
    $conn->close();
    die("Purchase Request not found.");
}

// Fetch PR items
$items_stmt = $conn->prepare("
    SELECT pri.*, p.sku, p.unit_of_measure, s.name AS item_supplier_name
    FROM purchase_request_items pri
    LEFT JOIN products p ON pri.product_id = p.id
    LEFT JOIN suppliers s ON s.id = pri.supplier_id
    WHERE pri.pr_id = ?
    ORDER BY pri.id ASC
");
$items_stmt->bind_param('i', $pr_id);
$items_stmt->execute();
$items_res = $items_stmt->get_result();
$items = [];
while ($row = $items_res->fetch_assoc()) $items[] = $row;
$items_stmt->close();
$headerSuppliers = $pr['supplier_name'] ?? 'To be assigned by Admin';
$conn->close();

$grand_total = array_sum(array_map(fn($i) => ($i['quantity_requested'] * $i['estimated_unit_price']), $items));

function fmtDate($d) {
    if (!$d || $d === '0000-00-00') return '—';
    return date('F j, Y', strtotime($d));
}
function fmtMoney($v) {
    return '₱' . number_format((float)$v, 2);
}

$statusColors = [
    'Draft'            => '#64748b',
    'Pending Approval' => '#d97706',
    'Approved'         => '#059669',
    'Rejected'         => '#dc2626',
];
$statusColor = $statusColors[$pr['status']] ?? '#64748b';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Request | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Inter', sans-serif;
    background: #f1f5f9;
    color: #1e293b;
    padding: 30px 20px;
  }
  .print-bar {
    max-width: 850px;
    margin: 0 auto 20px auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .btn-print {
    background: #7c3aed;
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  .btn-print:hover { background: #6d28d9; }
  .document {
    max-width: 850px;
    margin: 0 auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    padding: 40px;
  }
  .doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 24px;
    margin-bottom: 24px;
  }
  .company-title { font-size: 22px; font-weight: 800; color: #0f172a; }
  .company-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
  .doc-type { text-align: right; }
  .doc-type h1 { font-size: 24px; font-weight: 800; color: #7c3aed; letter-spacing: -0.5px; }
  .doc-num { font-size: 14px; font-weight: 700; color: #334155; margin-top: 4px; }
  .status-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    margin-top: 6px;
  }
  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 28px;
  }
  .info-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 20px;
  }
  .info-card-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
  }
  .info-card p { font-size: 13.5px; line-height: 1.5; color: #334155; }
  .info-card strong { color: #0f172a; }
  table.items-tbl {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 24px;
  }
  table.items-tbl th {
    background: #f1f5f9;
    padding: 10px 14px;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    text-align: left;
    border-bottom: 2px solid #cbd5e1;
  }
  table.items-tbl td {
    padding: 12px 14px;
    font-size: 13px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
  }
  .total-box {
    margin-left: auto;
    width: 280px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 30px;
  }
  .total-row {
    display: flex;
    justify-content: space-between;
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
  }
  .signatures {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-top: 40px;
    border-top: 1px solid #e2e8f0;
    padding-top: 30px;
  }
  .sig-block { text-align: center; }
  .sig-line { border-bottom: 1px solid #94a3b8; height: 40px; margin-bottom: 8px; }
  .sig-name { font-size: 13px; font-weight: 700; color: #0f172a; }
  .sig-title { font-size: 11px; color: #64748b; }
  @media screen and (max-width: 640px) {
    body { padding: 12px; }
    .print-bar {
      max-width: 100%;
      flex-direction: column;
      align-items: stretch;
      gap: 10px;
    }
    .btn-print { justify-content: center; width: 100%; }
    .document { max-width: 100%; padding: 18px; overflow-x: hidden; }
    .doc-header {
      flex-direction: column;
      gap: 16px;
    }
    .doc-type { text-align: left; }
    .info-grid,
    .signatures {
      grid-template-columns: 1fr;
      gap: 16px;
    }
    table.items-tbl {
      width: max-content;
      min-width: 100%;
    }
    .total-box {
      width: 100%;
      margin-left: 0;
    }
  }
  @media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .document { box-shadow: none; padding: 0; }
  }
</style>
<link rel="stylesheet" href="css/system-table-scroll.css">
</head>
<body>

<div class="print-bar">
  <div style="font-weight:600;color:#64748b">Purchase Request Printable Form</div>
  <button class="btn-print" onclick="window.print()">🖨 Print / Export PDF</button>
</div>

<div class="document">
  <div class="doc-header">
    <div>
      <div class="company-title">CLARENZON MEDICAL SUPPLIES</div>
      <div class="company-sub">Inventory & Operations Management System</div>
    </div>
    <div class="doc-type">
      <h1>PURCHASE REQUEST</h1>
      <div class="doc-num"><?= htmlspecialchars($pr['pr_number']) ?></div>
      <div class="status-pill" style="background:<?= $statusColor ?>"><?= htmlspecialchars($pr['status']) ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <div class="info-card-title">Requester Details</div>
      <p><strong>Requested By:</strong> <?= htmlspecialchars($pr['requested_by_name'] ?? '—') ?></p>
      <p><strong>Request Date:</strong> <?= fmtDate($pr['created_at']) ?></p>
      <?php if (!empty($pr['generated_po_number'])): ?>
      <p><strong>Linked PO #:</strong> <?= htmlspecialchars($pr['generated_po_number']) ?></p>
      <?php endif; ?>
    </div>
    <div class="info-card">
      <div class="info-card-title">Preferred Supplier (Optional)</div>
      <p><strong>Supplier Name:</strong> <?= htmlspecialchars($headerSuppliers) ?></p>
      <?php
        $purposeLabel = trim((string)($pr['purpose'] ?? ''));
        if ($purposeLabel === '') $purposeLabel = 'Not Specified';
        $customerOrderRef = trim((string)($pr['customer_order_reference'] ?? ''));
      ?>
      <p><strong>Purpose:</strong> <?= htmlspecialchars($purposeLabel) ?></p>
      <?php if ($purposeLabel === 'Customer Order' || $customerOrderRef !== ''): ?>
      <p><strong>Customer Order Reference:</strong> <?= htmlspecialchars($customerOrderRef !== '' ? $customerOrderRef : '—') ?></p>
      <?php endif; ?>
      <p><strong>Reason for Purchase:</strong> <?= htmlspecialchars($pr['reason'] ?? 'Inventory replenishment') ?></p>
    </div>
  </div>

  <?php
    $purposeRemarksLabel = 'Requester Remarks';
    if ($purposeLabel === 'Customer Order') $purposeRemarksLabel = 'Order Details';
    elseif ($purposeLabel === 'Others') $purposeRemarksLabel = 'Purpose Details';
  ?>
  <?php if (!empty($pr['remarks']) || !empty($pr['admin_remarks'])): ?>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:13px">
    <?php if (!empty($pr['remarks']) && $purposeLabel !== 'Low Stock Replenishment'): ?>
      <div><strong><?= htmlspecialchars($purposeRemarksLabel) ?>:</strong> <?= htmlspecialchars($pr['remarks']) ?></div>
    <?php endif; ?>
    <?php if (!empty($pr['admin_remarks'])): ?>
      <div style="margin-top:6px;color:#dc2626"><strong><?= (($pr['status'] ?? '') === 'Rejected') ? 'Rejection Reason:' : 'Admin Remarks:' ?></strong> <?= htmlspecialchars($pr['admin_remarks']) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="system-table-scroll">
  <table class="items-tbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Item Description</th>
        <th>Supplier</th>
        <th>SKU</th>
        <th style="text-align:right">Qty Requested</th>
        <th>Unit</th>
        <th style="text-align:right">Unit Price</th>
        <th style="text-align:right">Total Price</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $idx => $item): ?>
      <tr>
        <td><?= $idx + 1 ?></td>
        <td><strong><?= htmlspecialchars($item['product_name']) ?></strong></td>
        <td><?= htmlspecialchars($item['item_supplier_name'] ?? '—') ?></td>
        <td><code><?= htmlspecialchars($item['sku'] ?? '—') ?></code></td>
        <td style="text-align:right;font-weight:600"><?= $item['quantity_requested'] ?></td>
        <td><?= htmlspecialchars($item['unit_of_measure'] ?? '—') ?></td>
        <td style="text-align:right"><?= fmtMoney($item['estimated_unit_price']) ?></td>
        <td style="text-align:right;font-weight:700"><?= fmtMoney($item['total_price']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="total-box">
    <div class="total-row">
      <span>Grand Total:</span>
      <span><?= fmtMoney($grand_total) ?></span>
    </div>
  </div>

  <div class="signatures">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($pr['requested_by_name'] ?? 'Purchaser / Staff') ?></div>
      <div class="sig-title">Requested By</div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($pr['approved_by_name'] ?? 'System Administrator') ?></div>
      <div class="sig-title">Admin Approval Sign-Off</div>
    </div>
  </div>
</div>

</body>
</html>
