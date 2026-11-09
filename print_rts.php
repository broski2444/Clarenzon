<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . '/procurement_rts_lib.php';
require_once __DIR__ . '/mysqli_connect_fast.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access.');
}

$rtsId = (int)($_GET['id'] ?? $_GET['rts_id'] ?? 0);
$rtsNumber = trim((string)($_GET['rts'] ?? $_GET['rts_number'] ?? ''));
if ($rtsId <= 0 && $rtsNumber === '') {
    die('Invalid RTS reference.');
}

$conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'], 'root', '', 3);
if (!$conn) {
    die('Database connection failed.');
}

rtsEnsureSchema($conn);
$row = $rtsId > 0 ? rtsLoad($conn, $rtsId) : rtsFindByNumber($conn, $rtsNumber);
if (!$row) {
    $conn->close();
    die('Return to Supplier record not found.');
}
$rtsId = (int)$row['id'];
$conn->close();

function rtsFmtDate($d) {
    if (!$d || $d === '0000-00-00') return '—';
    return date('F j, Y', strtotime((string)$d));
}

$scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$backUrl = ($scriptDir === '' || $scriptDir === '.' ? '' : $scriptDir) . '/admin_dashboard.php#damage-returns';
$uom = trim((string)($row['unit_of_measure'] ?? '')) ?: 'unit';
$batch = trim((string)($row['batch_display'] ?? '')) ?: '—';
$damaged = (int)($row['quantity_damaged'] ?? $row['line_damaged'] ?? 0);
$returned = (int)($row['quantity_returned'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Return to Supplier <?= htmlspecialchars($row['rts_number']) ?> | Clarenzon Marketing</title>
<link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #1e293b; padding: 30px 20px; }
  .print-bar { max-width: 920px; margin: 0 auto 20px auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
  .btn-back, .btn-print { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; text-decoration: none; }
  .btn-back { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
  .btn-print { background: #2563eb; color: #fff; border: none; }
  .document { max-width: 920px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 32px 28px; }
  .doc-header { display: flex; justify-content: space-between; border-bottom: 2px solid #e2e8f0; padding-bottom: 24px; margin-bottom: 24px; }
  .company-title { font-size: 22px; font-weight: 800; }
  .company-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
  .doc-type h1 { font-size: 22px; font-weight: 800; color: #b45309; }
  .doc-num { font-size: 14px; font-weight: 700; margin-top: 4px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 22px; }
  .info-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; font-size: 13px; }
  .info-card-title { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; margin-bottom: 8px; }
  .info-card p { margin: 4px 0; }
  table.items-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.items-tbl th { background: #f1f5f9; text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; color: #64748b; }
  table.items-tbl td { padding: 10px; border-top: 1px solid #e2e8f0; }
  .sign-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; margin-top: 36px; }
  .sign-box { border-top: 1px solid #94a3b8; padding-top: 8px; font-size: 12px; color: #475569; min-height: 72px; }
  .note { font-size: 12px; color: #64748b; margin-top: 18px; }
  @media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .document { box-shadow: none; padding: 0; }
    @page { size: A4 portrait; margin: 12mm; }
  }
</style>
<link rel="stylesheet" href="css/system-table-scroll.css">
</head>
<body>
<div class="print-bar">
  <div style="font-weight:600;color:#64748b">Read-only Return to Supplier receipt — reprinting does not create a new RTS</div>
  <div>
    <button type="button" class="btn-back" onclick="goBack()">← Back</button>
    <button type="button" class="btn-print" onclick="window.print()">Print / Export PDF</button>
  </div>
</div>
<script>
function goBack() {
  if (window.opener && !window.opener.closed) { window.close(); return; }
  if (window.history.length > 1) { window.history.back(); return; }
  window.location.href = <?= json_encode($backUrl, JSON_UNESCAPED_SLASHES) ?>;
}
</script>
<div class="document">
  <div class="doc-header">
    <div>
      <div class="company-title">CLARENZON MARKETING</div>
      <div class="company-sub">Return to Supplier — physical damaged / defective / wrong item</div>
    </div>
    <div class="doc-type">
      <h1>RETURN TO SUPPLIER</h1>
      <div class="doc-num">RTS #: <?= htmlspecialchars($row['rts_number']) ?></div>
      <div style="font-size:12px;color:#64748b;margin-top:4px"><?= htmlspecialchars($row['status']) ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <div class="info-card-title">Return Information</div>
      <p><strong>RTS #:</strong> <?= htmlspecialchars($row['rts_number']) ?></p>
      <p><strong>Return Date:</strong> <?= rtsFmtDate($row['return_date']) ?></p>
      <p><strong>Status:</strong> <?= htmlspecialchars($row['status']) ?></p>
      <p><strong>Processed By:</strong> <?= htmlspecialchars($row['processed_by_name'] ?? '—') ?></p>
      <p><strong>Date Processed:</strong> <?= rtsFmtDate($row['processed_at'] ?? $row['created_at'] ?? '') ?></p>
    </div>
    <div class="info-card">
      <div class="info-card-title">Supplier Information</div>
      <p><strong>Supplier:</strong> <?= htmlspecialchars($row['supplier_name'] ?? '—') ?></p>
      <p><strong>Contact Person:</strong> <?= htmlspecialchars($row['supplier_contact'] ?? '—') ?></p>
      <p><strong>Contact Number:</strong> <?= htmlspecialchars($row['supplier_phone'] ?? '—') ?></p>
    </div>
    <div class="info-card">
      <div class="info-card-title">Original Transaction</div>
      <p><strong>PO #:</strong> <?= htmlspecialchars($row['po_number'] ?? '—') ?></p>
      <p><strong>Receiving #:</strong> <?= htmlspecialchars($row['receipt_number'] ?? '—') ?></p>
      <p><strong>Supplier DR #:</strong> <?= htmlspecialchars($row['dr_number'] ?? '—') ?></p>
      <p><strong>Supplier Invoice #:</strong> <?= htmlspecialchars($row['supplier_si_number'] ?? '—') ?></p>
      <p><strong>Date Received:</strong> <?= rtsFmtDate($row['date_received'] ?? '') ?></p>
    </div>
    <div class="info-card">
      <div class="info-card-title">Return Details</div>
      <p><strong>Reason:</strong> <?= htmlspecialchars($row['return_reason'] ?? '—') ?></p>
      <p><strong>Notes:</strong> <?= htmlspecialchars($row['notes'] !== '' ? $row['notes'] : '—') ?></p>
    </div>
  </div>

  <div class="system-table-scroll">
  <table class="items-tbl">
    <thead>
      <tr>
        <th>Product</th>
        <th>SKU</th>
        <th>Barcode</th>
        <th>Unit</th>
        <th>Batch / Lot</th>
        <th>Expiry</th>
        <th>Damaged</th>
        <th>Returned</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><?= htmlspecialchars($row['product_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['sku'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['barcode'] ?? '—') ?></td>
        <td><?= htmlspecialchars($uom) ?></td>
        <td><?= htmlspecialchars($batch) ?></td>
        <td><?= htmlspecialchars($row['expiry_date'] ? rtsFmtDate($row['expiry_date']) : '—') ?></td>
        <td><?= $damaged ?> <?= htmlspecialchars($uom) ?></td>
        <td><strong><?= $returned ?> <?= htmlspecialchars($uom) ?></strong></td>
      </tr>
    </tbody>
  </table>
  </div>

  <div class="sign-grid">
    <div class="sign-box">Received By Supplier<br><span style="color:#94a3b8">________________________</span></div>
    <div class="sign-box">Date Received by Supplier<br><span style="color:#94a3b8">________________________</span></div>
    <div class="sign-box">Supplier Representative Signature<br><span style="color:#94a3b8">________________________</span></div>
  </div>
  <p class="note">This printout is read-only. It does not change inventory, create another RTS, create a stock movement, or create a replacement request.</p>
</div>
</body>
</html>
