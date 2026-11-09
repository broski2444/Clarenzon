<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$receipt_id = (int)($_GET['receipt_id'] ?? 0);
if ($receipt_id <= 0) {
    die("Invalid Purchase Receipt ID.");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
$conn = clarenzon_db_connect_first(['clarenzon_db', 'inventory_db', 'clarenzon_inventory']);
if (!$conn) {
    die("Database connection failed.");
}

// Fetch Receipt header
$stmt = $conn->prepare("
    SELECT rec.*,
           po.po_number, po.expected_delivery_date,
           s.name AS supplier_name, s.supplier_code, s.contact_person AS supplier_contact, s.phone AS supplier_phone,
           u.full_name AS received_by_name
    FROM purchase_receipts rec
    JOIN purchase_orders po ON rec.po_id = po.id
    LEFT JOIN suppliers s   ON rec.supplier_id = s.id
    LEFT JOIN users u       ON rec.received_by = u.id
    WHERE rec.id = ?
");
$stmt->bind_param('i', $receipt_id);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rec) {
    $conn->close();
    die("Purchase Receipt not found.");
}

$ntsCol = $conn->query("SHOW COLUMNS FROM purchase_receipt_items LIKE 'note_to_supplier'");
if (!($ntsCol && $ntsCol->num_rows > 0)) {
    @$conn->query("ALTER TABLE purchase_receipt_items ADD COLUMN note_to_supplier TEXT NULL AFTER remarks");
}

// Fetch Receipt items
$items_stmt = $conn->prepare("
    SELECT pri.*, p.sku, p.barcode, p.brand,
           COALESCE(NULLIF(TRIM(p.unit_of_measure), ''), 'pcs') AS unit_of_measure
    FROM purchase_receipt_items pri
    LEFT JOIN products p ON pri.product_id = p.id
    WHERE pri.receipt_id = ?
    ORDER BY pri.id ASC
");
$items_stmt->bind_param('i', $receipt_id);
$items_stmt->execute();
$items_res = $items_stmt->get_result();
$items = [];
while ($row = $items_res->fetch_assoc()) $items[] = $row;
$items_stmt->close();
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product_barcodes_lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'procurement_receiving_lib.php';
productBarcodesEnsureSchema($conn);
$items = productBarcodesHydrateReceiptItems($conn, $items, $receipt_id, (int)($rec['po_id'] ?? 0));
$isReplacementReceipt = (int)($rec['is_replacement'] ?? 0) === 1;
$originalReceiptNumber = '';
if ($isReplacementReceipt && (int)($rec['replaces_receipt_id'] ?? 0) > 0) {
    $origStmt = $conn->prepare('SELECT receipt_number FROM purchase_receipts WHERE id=? LIMIT 1');
    if ($origStmt) {
        $origId = (int)$rec['replaces_receipt_id'];
        $origStmt->bind_param('i', $origId);
        $origStmt->execute();
        $origRow = $origStmt->get_result()->fetch_assoc();
        $origStmt->close();
        $originalReceiptNumber = (string)($origRow['receipt_number'] ?? '');
    }
}
if (!$isReplacementReceipt) {
    $items = recvApplyCurrentReceiptQtys($conn, (int)($rec['po_id'] ?? 0), $items);
}
$inspectDamageByItem = [];
$inspTbl = $conn->query("SHOW TABLES LIKE 'purchase_receipt_inspection_barcodes'");
if ($inspTbl && $inspTbl->num_rows > 0) {
    $inspStmt = $conn->prepare("SELECT receipt_item_id, status FROM purchase_receipt_inspection_barcodes WHERE receipt_id=?");
    if ($inspStmt) {
        $inspStmt->bind_param('i', $receipt_id);
        $inspStmt->execute();
        $inspRes = $inspStmt->get_result();
        while ($inspRow = $inspRes->fetch_assoc()) {
            $status = strtolower(trim((string)($inspRow['status'] ?? '')));
            if ($status === '' || $status === 'good' || $status === 'replacement') {
                continue;
            }
            $itemId = (int)($inspRow['receipt_item_id'] ?? 0);
            $inspectDamageByItem[$itemId] = ($inspectDamageByItem[$itemId] ?? 0) + 1;
        }
        $inspStmt->close();
    }
}
$conn->close();

function fmtDate($d) {
    if (!$d || $d === '0000-00-00') return '—';
    return date('M j, Y', strtotime($d));
}
function fmtMoney($v) {
    return '₱' . number_format((float)$v, 2);
}
function receiptLineDisplayQtys(array $item, int $inspectDamage = 0): array {
    $ordered = max(0, (int)($item['quantity_ordered'] ?? 0));
    $good = max(0, (int)($item['quantity_good'] ?? 0));
    if ($ordered > 0) {
        $good = min($ordered, $good);
    }
    $gap = max(0, $ordered - $good);
    $disc = $gap;
    $received = $good;
    $remaining = $gap;
    return ['good' => $good, 'disc' => $disc, 'received' => $received, 'remaining' => $remaining];
}
$scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$backUrl = ($scriptDir === '' || $scriptDir === '.' ? '' : $scriptDir) . '/admin_dashboard.php#purchase-receipts';

$grandGood = 0;
$grandPayable = 0;
$printItems = [];
$remarkItems = [];
foreach ($items as $item) {
    $qty = receiptLineDisplayQtys($item, (int)($inspectDamageByItem[(int)($item['id'] ?? 0)] ?? 0));
    $good = $qty['good'];
    $disc = $qty['disc'];
    $note = trim((string)($item['note_to_supplier'] ?? ''));
    $cost = (float)(($item['cost_price'] ?? 0) > 0 ? $item['cost_price'] : ($item['unit_price'] ?? 0));
    $grandGood += $good;
    $grandPayable += $good * $cost;
    // Show inspected lines that have good and/or damaged/missing qty.
    if ($good > 0 || (!$isReplacementReceipt && $disc > 0)) {
        $printItems[] = $item;
    }
    if (!$isReplacementReceipt && ($disc > 0 || $note !== '')) {
        $unit = trim((string)($item['unit_of_measure'] ?? 'pcs')) ?: 'pcs';
        $remarkItems[] = [
            'product_name' => (string)($item['product_name'] ?? ''),
            'damaged_qty' => $disc,
            'unit' => $unit,
            'note' => $note,
        ];
    }
}
$replacementFullyReceived = true;
if ($isReplacementReceipt) {
    if (!$printItems) {
        $replacementFullyReceived = false;
    }
    foreach ($printItems as $pi) {
        $oq = (int)($pi['quantity_ordered'] ?? 0);
        $gq = (int)($pi['quantity_good'] ?? 0);
        if ($oq <= 0 || $gq < $oq) {
            $replacementFullyReceived = false;
        }
    }
}
$receiptStatusLabel = $isReplacementReceipt
    ? ($replacementFullyReceived ? 'Received' : 'To Received')
    : (string)($rec['status'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receiving Receipt | Clarenzon Marketing</title>
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
    max-width: 920px;
    margin: 0 auto 20px auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
  }
  .print-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .btn-back {
    background: #fff;
    color: #334155;
    border: 1px solid #cbd5e1;
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
  }
  .btn-back:hover { background: #f8fafc; color: #0f172a; }
  .btn-print {
    background: #10b981;
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
  .btn-print:hover { background: #059669; }
  .document {
    max-width: 920px;
    margin: 0 auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    padding: 32px 28px;
    overflow: hidden;
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
  .doc-type h1 { font-size: 22px; font-weight: 800; color: #10b981; letter-spacing: -0.5px; }
  .doc-num { font-size: 14px; font-weight: 700; color: #334155; margin-top: 4px; }
  .status-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    background: <?= in_array($receiptStatusLabel, ['Fully Received', 'Received'], true) ? '#10b981' : '#f59e0b' ?>;
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
  .table-wrap {
    width: 100%;
    overflow-x: auto;
    margin-bottom: 20px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
  }
  table.items-tbl {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    margin: 0;
  }
  table.items-tbl th {
    background: #f1f5f9;
    padding: 8px 8px;
    font-size: 9px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0;
    text-align: left;
    border-bottom: 2px solid #cbd5e1;
    white-space: nowrap;
    line-height: 1.3;
    vertical-align: bottom;
    overflow: hidden;
  }
  table.items-tbl th.wrap {
    white-space: normal;
    overflow-wrap: break-word;
    hyphens: none;
  }
  table.items-tbl td {
    padding: 8px 8px;
    font-size: 11px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
    vertical-align: top;
    overflow-wrap: break-word;
    word-break: break-word;
    overflow: hidden;
  }
  table.items-tbl th.num { text-align: right; }
  table.items-tbl td.num { text-align: right; white-space: nowrap; }
  table.items-tbl td.date { white-space: nowrap; overflow: hidden; }
  table.items-tbl td.sku { white-space: nowrap; overflow: hidden; }
  table.items-tbl td.sku .meta {
    white-space: nowrap;
    overflow-wrap: normal;
    word-break: normal;
    overflow: hidden;
  }
  table.items-tbl .prod {
    font-weight: 700;
    color: #0f172a;
    line-height: 1.35;
    white-space: normal;
    overflow-wrap: break-word;
    word-break: normal;
  }
  table.items-tbl .meta { font-size: 10px; color: #64748b; margin-top: 2px; font-family: ui-monospace, monospace; overflow-wrap: anywhere; }
  table.items-tbl td.prod-cell { overflow: visible; }
  .payable-box {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 20px;
    padding: 14px 18px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
  }
  .payable-box .remarks-side {
    flex: 1 1 auto;
    min-width: 0;
  }
  .payable-box .remarks-title {
    font-size: 11px;
    font-weight: 700;
    color: #166534;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 8px;
  }
  .payable-box .remarks-item {
    font-size: 12px;
    color: #334155;
    line-height: 1.45;
  }
  .payable-box .remarks-item + .remarks-item {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px dashed #bbf7d0;
  }
  .payable-box .remarks-item .ri-label {
    color: #64748b;
  }
  .payable-box .payable-side {
    flex: 0 0 auto;
    text-align: right;
  }
  table.items-tbl col.c-num { width: 4%; }
  table.items-tbl col.c-prod { width: 16%; }
  table.items-tbl col.c-sku { width: 12%; }
  table.items-tbl col.c-qty { width: 9%; }
  table.items-tbl col.c-disc { width: 11%; }
  table.items-tbl col.c-pay { width: 12%; }
  table.items-tbl col.c-exp { width: 13%; }
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
    .print-actions { flex-direction: column; align-items: stretch; }
    .btn-back, .btn-print { justify-content: center; width: 100%; }
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
    .table-wrap { overflow-x: auto; }
  }
  @media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .document { box-shadow: none; padding: 0; max-width: none; overflow: visible; }
    .table-wrap { overflow: visible; border: none; }
    table.items-tbl { width: 100%; table-layout: fixed; }
    table.items-tbl col.c-num { width: 4%; }
    table.items-tbl col.c-prod { width: 14%; }
    table.items-tbl col.c-sku { width: 14%; }
    table.items-tbl col.c-qty { width: 8%; }
    table.items-tbl col.c-disc { width: 12%; }
    table.items-tbl col.c-pay { width: 14%; }
    table.items-tbl col.c-exp { width: 14%; }
    table.items-tbl th { font-size: 8px; padding: 6px 7px; overflow: hidden; }
    table.items-tbl td { font-size: 10px; padding: 6px 7px; overflow: hidden; }
    table.items-tbl td.prod-cell { overflow: visible; }
    .payable-box { gap: 16px; padding: 12px 14px; }
    .payable-box .remarks-title { font-size: 10px; }
    .payable-box .remarks-item { font-size: 10px; }
    table.items-tbl td.num { white-space: nowrap; }
    table.items-tbl td.date { white-space: nowrap; }
    table.items-tbl td.sku .meta { white-space: nowrap; }
    @page { size: A4 portrait; margin: 12mm; }
  }
</style>
</head>
<body>

<div class="print-bar">
  <div style="font-weight:600;color:#64748b">Receiving copy for the supplier driver — not a Purchase Request</div>
  <div class="print-actions">
    <button type="button" class="btn-back" onclick="goBackFromReceipt()">← Back</button>
    <button type="button" class="btn-print" onclick="window.print()">🖨 Print / Export PDF</button>
  </div>
</div>
<script>
function goBackFromReceipt() {
  if (window.opener && !window.opener.closed) {
    window.close();
    return;
  }
  if (window.history.length > 1) {
    window.history.back();
    return;
  }
  window.location.href = <?= json_encode($backUrl, JSON_UNESCAPED_SLASHES) ?>;
}
</script>

<div class="document">
  <div class="doc-header">
    <div>
      <div class="company-title">CLARENZON MARKETING</div>
      <div class="company-sub">Internal Receiving Receipt / Receiving Copy</div>
    </div>
    <div class="doc-type">
      <h1><?= $isReplacementReceipt ? 'REPLACEMENT RECEIVING RECEIPT' : 'RECEIVING RECEIPT / RECEIVING COPY' ?></h1>
      <div class="doc-num"><?= $isReplacementReceipt ? 'Replacement Order #:' : 'Receipt #:' ?> <?= htmlspecialchars($rec['receipt_number']) ?></div>
      <div class="status-pill"><?= htmlspecialchars($receiptStatusLabel) ?></div>
      <div style="font-size:11px;color:#64748b;margin-top:6px">Give this to the driver with the PO</div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <div class="info-card-title">Receipt Reference</div>
      <p><strong><?= $isReplacementReceipt ? 'Replacement Order Number:' : 'Receipt #:' ?></strong> <?= htmlspecialchars($rec['receipt_number']) ?></p>
      <p><strong><?= $isReplacementReceipt ? 'Original PO Reference:' : 'PO #:' ?></strong> <?= htmlspecialchars($rec['po_number']) ?></p>
      <?php if ($isReplacementReceipt && $originalReceiptNumber !== ''): ?>
      <p><strong>Original Receipt:</strong> <?= htmlspecialchars($originalReceiptNumber) ?></p>
      <?php endif; ?>
      <p><strong>Date Received:</strong> <?= fmtDate($rec['date_received']) ?></p>
      <p><strong>Received By:</strong> <?= htmlspecialchars($rec['received_by_name'] ?? 'Warehouse Staff') ?></p>
      <p><strong>Driver / Deliverer:</strong> <?= htmlspecialchars($rec['driver_name'] ?? '—') ?></p>
    </div>
    <div class="info-card">
      <div class="info-card-title">Supplier Papers</div>
      <p><strong>Supplier:</strong> <?= htmlspecialchars($rec['supplier_name'] ?? '—') ?></p>
      <p><strong>Supplier DR #:</strong> <?= htmlspecialchars($rec['dr_number'] ?? '—') ?></p>
      <p><strong>Supplier Invoice #:</strong> <?= htmlspecialchars($rec['supplier_si_number'] ?? '—') ?></p>
      <p><strong>Contact:</strong> <?= htmlspecialchars($rec['supplier_contact'] ?? '—') ?> (<?= htmlspecialchars($rec['supplier_phone'] ?? '') ?>)</p>
    </div>
  </div>

  <?php if (!empty($rec['remarks'])): ?>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:13px">
    <strong>Receiving Remarks:</strong> <?= htmlspecialchars($rec['remarks']) ?>
  </div>
  <?php endif; ?>

  <div class="table-wrap system-table-scroll">
  <table class="items-tbl">
    <colgroup>
      <col class="c-num">
      <col class="c-prod">
      <col class="c-sku">
      <col class="c-qty">
      <col class="c-qty">
      <col class="c-qty">
      <col class="c-disc">
      <col class="c-qty">
      <col class="c-pay">
      <col class="c-exp">
    </colgroup>
    <thead>
      <tr>
        <th>#</th>
        <th>Product</th>
        <th>SKU</th>
        <?php if ($isReplacementReceipt): ?>
        <th>Brand</th>
        <th class="num">Replacement Qty</th>
        <th>Unit</th>
        <th class="num">Actual Received</th>
        <th>Status</th>
        <?php else: ?>
        <th class="num">Ordered</th>
        <th class="num">Received</th>
        <th class="num">Good</th>
        <th class="num wrap">Replacement</th>
        <th class="num wrap">Remaining</th>
        <?php endif; ?>
        <th class="num wrap">Purchase<br>Price</th>
        <th>Expiry</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($printItems as $idx => $item):
        $qty = receiptLineDisplayQtys($item, (int)($inspectDamageByItem[(int)($item['id'] ?? 0)] ?? 0));
        $good = $qty['good'];
        $disc = $qty['disc'];
        $received = $qty['received'];
        $remaining = $qty['remaining'];
        $cost = (float)(($item['cost_price'] ?? 0) > 0 ? $item['cost_price'] : ($item['unit_price'] ?? 0));
        $pay = $good * $cost;
        $batch = trim((string)($item['system_batch_number'] ?? $item['batch_number'] ?? ''));
        $lot = trim((string)($item['manufacturer_lot'] ?? ''));
      ?>
      <tr>
        <td><?= $idx + 1 ?></td>
        <td class="prod-cell">
          <div class="prod"><?= str_replace('_', "_\u{200B}", htmlspecialchars($item['product_name'])) ?></div>
          <?php if ($batch !== '' || $lot !== ''): ?>
          <div class="meta"><?= htmlspecialchars($batch !== '' ? $batch : '') ?><?= $lot !== '' ? ' · Lot ' . htmlspecialchars($lot) : '' ?></div>
          <?php endif; ?>
        </td>
        <td class="sku"><div class="meta"><?= htmlspecialchars($item['sku'] ?? '—') ?></div></td>
        <?php if ($isReplacementReceipt):
            $lineStatus = ((int)$item['quantity_ordered'] > 0 && $good >= (int)$item['quantity_ordered']) ? 'Received' : 'To Received';
            $unit = trim((string)($item['unit_of_measure'] ?? 'pcs')) ?: 'pcs';
        ?>
        <td><?= htmlspecialchars((string)($item['brand'] ?? '—')) ?></td>
        <td class="num" style="font-weight:700"><?= (int)$item['quantity_ordered'] ?></td>
        <td><?= htmlspecialchars($unit) ?></td>
        <td class="num" style="font-weight:800;color:#10b981"><?= $good ?></td>
        <td><?= htmlspecialchars($lineStatus) ?></td>
        <?php else: ?>
        <td class="num" style="color:#64748b"><?= (int)$item['quantity_ordered'] ?></td>
        <td class="num" style="color:#64748b"><?= $received ?></td>
        <td class="num" style="font-weight:800;color:#10b981"><?= $good ?></td>
        <td class="num" style="font-weight:700;color:#dc2626"><?= $disc ?></td>
        <td class="num" style="color:#64748b"><?= (int)$remaining ?></td>
        <?php endif; ?>
        <td class="num" style="font-weight:700"><?= fmtMoney($cost) ?></td>
        <td class="date"><?= fmtDate($item['expiry_date']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="payable-box">
    <div class="remarks-side">
      <?php if ($remarkItems): ?>
      <div class="remarks-title">Remarks / Note to Supplier</div>
      <?php foreach ($remarkItems as $riIdx => $ri): ?>
      <div class="remarks-item">
        <?php if (count($remarkItems) > 1): ?>
        <div><strong><?= (int)$riIdx + 1 ?>. <?= htmlspecialchars($ri['product_name']) ?></strong></div>
        <?php else: ?>
        <div><span class="ri-label">Product:</span> <strong><?= htmlspecialchars($ri['product_name']) ?></strong></div>
        <?php endif; ?>
        <div><span class="ri-label">Replacement Qty:</span> <?= (int)$ri['damaged_qty'] ?> <?= htmlspecialchars($ri['unit']) ?></div>
        <?php if ($ri['note'] !== ''): ?>
        <div><span class="ri-label">Reason:</span> <?= htmlspecialchars($ri['note']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="payable-side">
      <div style="font-size:12px;color:#64748b">Amount payable to supplier (good qty only — replacement deducted)</div>
      <div style="font-size:22px;font-weight:800;color:#059669"><?= fmtMoney($grandPayable) ?></div>
      <div style="font-size:11px;color:#64748b;margin-top:4px">Good units: <?= (int)$grandGood ?></div>
    </div>
  </div>

  <div style="font-size:12px;color:#64748b;margin-bottom:16px">This is a Purchase Order receiving copy for the supplier driver. It is not a Purchase Request and not a customer sales invoice.</div>

  <div class="signatures">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($rec['received_by_name'] ?? 'Receiving Staff') ?></div>
      <div class="sig-title">Receiving Staff Signature</div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($rec['driver_name'] ?? 'Supplier Driver') ?></div>
      <div class="sig-title">Driver Acknowledgement</div>
    </div>
  </div>
</div>

</body>
</html>
