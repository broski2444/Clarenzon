<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    die("Invalid Supplier Invoice ID.");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'inventory_db', 'clarenzon_inventory']);
if (!$conn) {
    die("Database connection failed.");
}

// Fetch Invoice header
$stmt = $conn->prepare("
    SELECT inv.*,
           po.po_number,
           rec.receipt_number,
           s.name AS supplier_name, s.supplier_code, s.contact_person AS supplier_contact,
           s.email AS supplier_email, s.phone AS supplier_phone, s.address AS supplier_address,
           u.full_name AS recorded_by_name
    FROM supplier_invoices inv
    JOIN purchase_orders po ON inv.po_id = po.id
    LEFT JOIN purchase_receipts rec ON inv.receipt_id = rec.id
    LEFT JOIN suppliers s   ON inv.supplier_id = s.id
    LEFT JOIN users u       ON inv.recorded_by = u.id
    WHERE inv.id = ?
");
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$inv) {
    $conn->close();
    die("Supplier Invoice not found.");
}

// Fetch PO items
$items_stmt = $conn->prepare("
    SELECT poi.*, p.sku
    FROM purchase_order_items poi
    LEFT JOIN products p ON poi.product_id = p.id
    WHERE poi.po_id = ?
    ORDER BY poi.id ASC
");
$items_stmt->bind_param('i', $inv['po_id']);
$items_stmt->execute();
$items_res = $items_stmt->get_result();
$items = [];
while ($row = $items_res->fetch_assoc()) $items[] = $row;
$items_stmt->close();
$conn->close();

function fmtDate($d) {
    if (!$d || $d === '0000-00-00') return '—';
    return date('F j, Y', strtotime($d));
}
function fmtMoney($v) {
    return '₱' . number_format((float)$v, 2);
}

$statusColors = [
    'Unpaid'         => '#dc2626',
    'Partially Paid' => '#d97706',
    'Paid'           => '#059669',
];
$statusColor = $statusColors[$inv['payment_status']] ?? '#64748b';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supplier Invoice | Clarenzon Medical</title>
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
    background: #2563eb;
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
  .btn-print:hover { background: #1d4ed8; }
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
  .doc-type h1 { font-size: 22px; font-weight: 800; color: #2563eb; letter-spacing: -0.5px; }
  .doc-num { font-size: 14px; font-weight: 700; color: #334155; margin-top: 4px; }
  .status-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    background: <?= $statusColor ?>;
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
  .summary-box {
    margin-left: auto;
    width: 300px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 30px;
  }
  .summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 13.5px;
    margin-bottom: 8px;
    color: #475569;
  }
  .summary-row.total {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    border-top: 1px solid #e2e8f0;
    padding-top: 8px;
    margin-bottom: 0;
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
      display: block;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .summary-box {
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
  <div style="font-weight:600;color:#64748b">Supplier Invoice Record</div>
  <button class="btn-print" onclick="window.print()">🖨 Print / Export PDF</button>
</div>

<div class="document">
  <div class="doc-header">
    <div>
      <div class="company-title">CLARENZON MEDICAL SUPPLIES</div>
      <div class="company-sub">Accounts Payable & Procurement System</div>
    </div>
    <div class="doc-type">
      <h1>SUPPLIER INVOICE</h1>
      <div class="doc-num"><?= htmlspecialchars($inv['invoice_number']) ?></div>
      <div class="status-pill"><?= htmlspecialchars($inv['payment_status']) ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <div class="info-card-title">Invoice Details</div>
      <p><strong>Invoice Date:</strong> <?= fmtDate($inv['invoice_date']) ?></p>
      <p><strong>Due Date:</strong> <?= fmtDate($inv['due_date']) ?></p>
      <p><strong>Linked PO #:</strong> <?= htmlspecialchars($inv['po_number']) ?></p>
      <?php if (!empty($inv['receipt_number'])): ?>
      <p><strong>Linked Receipt #:</strong> <?= htmlspecialchars($inv['receipt_number']) ?></p>
      <?php endif; ?>
      <p><strong>Recorded By:</strong> <?= htmlspecialchars($inv['recorded_by_name'] ?? 'Staff') ?></p>
    </div>
    <div class="info-card">
      <div class="info-card-title">Supplier Information</div>
      <p><strong>Supplier:</strong> <?= htmlspecialchars($inv['supplier_name'] ?? '—') ?></p>
      <p><strong>Supplier Code:</strong> <?= htmlspecialchars($inv['supplier_code'] ?? '—') ?></p>
      <p><strong>Contact:</strong> <?= htmlspecialchars($inv['supplier_contact'] ?? '—') ?> (<?= htmlspecialchars($inv['supplier_phone'] ?? '') ?>)</p>
      <p><strong>Email:</strong> <?= htmlspecialchars($inv['supplier_email'] ?? '—') ?></p>
    </div>
  </div>

  <?php if (!empty($inv['notes']) || !empty($inv['scanned_invoice_url'])): ?>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:13px">
    <?php if (!empty($inv['notes'])): ?>
      <div><strong>Invoice Notes:</strong> <?= htmlspecialchars($inv['notes']) ?></div>
    <?php endif; ?>
    <?php if (!empty($inv['scanned_invoice_url'])): ?>
      <div style="margin-top:6px"><strong>Attached Document:</strong> <a href="<?= htmlspecialchars($inv['scanned_invoice_url']) ?>" target="_blank" style="color:#2563eb;text-decoration:none">View Scanned Copy 📄</a></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="system-table-scroll">
  <table class="items-tbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Product Description</th>
        <th>SKU</th>
        <th style="text-align:right">Ordered Qty</th>
        <th style="text-align:right">Unit Price</th>
        <th style="text-align:right">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $idx => $item): ?>
      <tr>
        <td><?= $idx + 1 ?></td>
        <td><strong><?= htmlspecialchars($item['product_name']) ?></strong></td>
        <td><code><?= htmlspecialchars($item['sku'] ?? '—') ?></code></td>
        <td style="text-align:right"><?= $item['quantity_ordered'] ?></td>
        <td style="text-align:right"><?= fmtMoney($item['unit_price']) ?></td>
        <td style="text-align:right;font-weight:700"><?= fmtMoney($item['total_price']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="summary-box">
    <div class="summary-row">
      <span>Subtotal:</span>
      <span><?= fmtMoney($inv['subtotal']) ?></span>
    </div>
    <?php if ($inv['tax_amount'] > 0): ?>
    <div class="summary-row">
      <span>Tax:</span>
      <span><?= fmtMoney($inv['tax_amount']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($inv['discount_amount'] > 0): ?>
    <div class="summary-row">
      <span>Discount:</span>
      <span>-<?= fmtMoney($inv['discount_amount']) ?></span>
    </div>
    <?php endif; ?>
    <div class="summary-row total">
      <span>Grand Total:</span>
      <span><?= fmtMoney($inv['total_amount']) ?></span>
    </div>
  </div>

  <div class="signatures">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($inv['recorded_by_name'] ?? 'Accounting Staff') ?></div>
      <div class="sig-title">Recorded By</div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name">Finance Manager / Admin</div>
      <div class="sig-title">Approved & Verified</div>
    </div>
  </div>
</div>

</body>
</html>
