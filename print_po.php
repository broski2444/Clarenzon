<?php
mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions when DB is unavailable
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$printRole = normalize_user_role((string)($_SESSION['role'] ?? $_SESSION['_auth_context'] ?? 'admin'));
$printHome = route_home_for_role($printRole);
$printFrom = strtolower(trim((string)($_GET['from'] ?? '')));
$printFromAllowed = [
    'pr-approval',
    'purchase-requests',
    'purchase-orders',
    'supplier-invoices',
    'incoming-deliveries',
    'procurement',
    'dashboard',
];
if (!in_array($printFrom, $printFromAllowed, true)) {
    if ($printRole === 'purchaser') {
        $printFrom = 'purchase-orders';
    } elseif ($printRole === 'warehouse_head') {
        $printFrom = 'incoming-deliveries';
    } else {
        $printFrom = 'pr-approval';
    }
}
$printBackHref = ($printFrom === 'dashboard') ? $printHome : ($printHome . '#' . $printFrom);

$po_id = (int)($_GET['po_id'] ?? 0);
if ($po_id <= 0) {
    die("Invalid Purchase Order ID.");
}

// DB connection
$conn = clarenzon_db_connect_first(['clarenzon_db', 'inventory_db', 'clarenzon_inventory']);
if (!$conn) {
    die("Database connection failed.");
}

// Fetch PO header
$stmt = $conn->prepare("
    SELECT po.*,
           s.name AS supplier_name, s.supplier_code, s.contact_person AS supplier_contact,
           s.email AS supplier_email, s.phone AS supplier_phone,
           s.address AS supplier_address, s.city AS supplier_city,
           s.payment_terms,
           u1.full_name AS requested_by_name,
           u2.full_name AS approved_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s  ON po.supplier_id   = s.id
    LEFT JOIN users u1     ON po.requested_by  = u1.id
    LEFT JOIN users u2     ON po.approved_by   = u2.id
    WHERE po.id = ?
");
$stmt->bind_param('i', $po_id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$po) {
    $conn->close();
    die("Purchase Order not found.");
}

// Fetch PO items
$items_stmt = $conn->prepare("
    SELECT poi.*, p.sku, p.unit_of_measure
    FROM purchase_order_items poi
    LEFT JOIN products p ON poi.product_id = p.id
    WHERE poi.po_id = ?
    ORDER BY poi.id ASC
");
$items_stmt->bind_param('i', $po_id);
$items_stmt->execute();
$items_res = $items_stmt->get_result();
$items = [];
while ($row = $items_res->fetch_assoc()) $items[] = $row;
$items_stmt->close();
$conn->close();

$grand_total = array_sum(array_map(fn($i) => ($i['quantity_ordered'] * $i['unit_price']), $items));

function fmtDate($d) {
    if (!$d || $d === '0000-00-00') return '—';
    return date('F j, Y', strtotime($d));
}
function fmtMoney($v) {
    return '₱' . number_format((float)$v, 2);
}

$statusColors = [
    'Draft'              => '#64748b',
    'Pending Approval'   => '#d97706',
    'Approved'           => '#2563eb',
    'Sent'               => '#0891b2',
    'Partially Received' => '#ea580c',
    'Received'           => '#059669',
    'Cancelled'          => '#dc2626',
];
$statusColor = $statusColors[$po['status']] ?? '#64748b';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Order | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', sans-serif;
    background: #f1f5f9;
    color: #1e293b;
    min-height: 100vh;
    padding: 30px 20px;
  }

  /* ── Toolbar (hidden on print) ── */
  .toolbar {
    max-width: 820px;
    margin: 0 auto 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .toolbar h2 {
    flex: 1;
    font-size: 15px;
    font-weight: 600;
    color: #475569;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 8px;
    border: none;
    font-size: 13.5px;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    cursor: pointer;
    text-decoration: none;
    transition: all .18s;
  }
  .btn-primary { background: #7c3aed; color: #fff; }
  .btn-primary:hover { background: #6d28d9; }
  .btn-outline { background: #fff; border: 1px solid #e2e8f0; color: #374151; }
  .btn-outline:hover { background: #f8fafc; }
  .btn svg { width: 16px; height: 16px; }

  /* ── PO Document ── */
  .po-doc {
    max-width: 820px;
    margin: 0 auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 30px rgba(0,0,0,.08);
    overflow: hidden;
  }

  /* Header band */
  .po-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    padding: 36px 40px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
  }
  .po-brand .company-name {
    font-size: 22px;
    font-weight: 800;
    color: #f1f5f9;
    letter-spacing: -.3px;
  }
  .po-brand .company-sub {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 500;
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: .6px;
  }
  .po-brand .company-detail {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 10px;
    line-height: 1.6;
  }
  .po-title-block { text-align: right; }
  .po-title-block .doc-label {
    font-size: 11px;
    font-weight: 700;
    color: #7c3aed;
    text-transform: uppercase;
    letter-spacing: 1.5px;
  }
  .po-title-block .po-number {
    font-size: 26px;
    font-weight: 800;
    color: #fff;
    font-family: 'Courier New', monospace;
    letter-spacing: .5px;
    margin: 4px 0;
  }
  .po-title-block .po-date {
    font-size: 12px;
    color: #94a3b8;
  }
  .status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
    background: <?= $statusColor ?>;
    margin-top: 8px;
    text-transform: uppercase;
    letter-spacing: .6px;
  }

  /* Meta info strip */
  .po-meta {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-bottom: 1px solid #e2e8f0;
  }
  .meta-cell {
    padding: 18px 22px;
    border-right: 1px solid #e2e8f0;
  }
  .meta-cell:last-child { border-right: none; }
  .meta-label {
    font-size: 10px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 5px;
  }
  .meta-value {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.4;
  }

  /* Bill to / Ship to */
  .po-parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border-bottom: 1px solid #e2e8f0;
  }
  .party-block {
    padding: 22px 28px;
    border-right: 1px solid #e2e8f0;
  }
  .party-block:last-child { border-right: none; }
  .party-label {
    font-size: 10px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: 10px;
  }
  .party-name {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 4px;
  }
  .party-detail {
    font-size: 12.5px;
    color: #475569;
    line-height: 1.7;
  }

  /* Items table */
  .po-items-section { padding: 0; }
  .items-table-wrap,
  .system-table-scroll {
    display: block;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    overflow-x: auto !important;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    scrollbar-gutter: stable;
  }
  .items-table-wrap > table,
  .system-table-scroll > table {
    width: max-content;
    min-width: 100%;
  }
  table.items-tbl {
    width: 100%;
    border-collapse: collapse;
  }
  .items-tbl thead tr {
    background: #f8fafc;
  }
  .items-tbl thead th {
    padding: 12px 18px;
    text-align: left;
    font-size: 10.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .5px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
  }
  .items-tbl thead th.text-right { text-align: right; }
  .items-tbl tbody td {
    padding: 13px 18px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
    color: #374151;
    vertical-align: middle;
  }
  .items-tbl tbody td.text-right { text-align: right; }
  .items-tbl tbody tr:last-child td { border-bottom: none; }
  .items-tbl tbody tr:hover { background: #fafbfc; }
  .item-name { font-weight: 600; color: #1e293b; }
  .item-sku {
    font-size: 11px;
    color: #94a3b8;
    font-family: monospace;
    margin-top: 2px;
  }
  .qty-badge {
    display: inline-block;
    background: #ede9fe;
    color: #5b21b6;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
  }
  .rcv-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
  }
  .rcv-full { background: #d1fae5; color: #065f46; }
  .rcv-partial { background: #fef3c7; color: #92400e; }
  .rcv-none { background: #f1f5f9; color: #64748b; }

  /* Totals strip */
  .po-totals {
    border-top: 2px solid #e2e8f0;
    padding: 22px 28px;
    display: flex;
    justify-content: flex-end;
  }
  .totals-table { min-width: 280px; }
  .totals-table tr td {
    padding: 5px 10px;
    font-size: 13.5px;
    color: #475569;
  }
  .totals-table tr td:last-child { text-align: right; font-weight: 600; color: #1e293b; }
  .totals-table tr.grand td {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    padding-top: 10px;
    border-top: 1px solid #e2e8f0;
  }
  .totals-table tr.grand td:last-child { color: #7c3aed; }

  /* Notes & Approval */
  .po-footer-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border-top: 1px solid #e2e8f0;
  }
  .footer-block {
    padding: 22px 28px;
    border-right: 1px solid #e2e8f0;
  }
  .footer-block:last-child { border-right: none; }
  .footer-label {
    font-size: 10px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: 8px;
  }
  .footer-text { font-size: 13px; color: #475569; line-height: 1.6; }

  /* Signature lines */
  .signature-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    border-top: 1px solid #e2e8f0;
    padding: 28px 28px 32px;
  }
  .sig-block { text-align: center; }
  .sig-line {
    border-bottom: 1.5px solid #334155;
    margin-bottom: 6px;
    height: 40px;
  }
  .sig-label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
  .sig-name { font-size: 12px; color: #1e293b; font-weight: 700; margin-top: 2px; }

  /* Doc footer */
  .doc-footer {
    background: #f8fafc;
    padding: 14px 28px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: #94a3b8;
  }

  /* ── PRINT STYLES ── */
  @media screen and (max-width: 640px) {
    body { padding: 12px; }
    .toolbar {
      max-width: 100%;
      align-items: stretch;
    }
    .toolbar h2 { flex-basis: 100%; }
    .toolbar .btn { justify-content: center; flex: 1 1 auto; }
    .po-doc { max-width: 100%; overflow-x: hidden; }
    .po-header {
      flex-direction: column;
      padding: 24px 18px;
    }
    .po-title-block { text-align: left; }
    .po-meta,
    .po-parties,
    .po-footer-section,
    .signature-grid {
      grid-template-columns: 1fr;
    }
    .meta-cell,
    .footer-block {
      border-right: none;
      border-bottom: 1px solid #e2e8f0;
    }
    .po-totals {
      justify-content: stretch;
      padding: 18px;
    }
    .totals-table {
      width: 100%;
      min-width: 0;
    }
    table.items-tbl {
      width: max-content;
      min-width: 100%;
    }
    .doc-footer {
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
    }
  }

  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .po-doc {
      max-width: 100%;
      box-shadow: none;
      border-radius: 0;
    }
    @page { margin: 15mm; size: A4; }
    .po-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .status-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .qty-badge, .rcv-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
<link rel="stylesheet" href="css/system-table-scroll.css">
</head>
<body>

<!-- Toolbar -->
<div class="toolbar" id="printToolbar">
  <h2>📄 Purchase Order Preview</h2>
  <a href="<?= htmlspecialchars($printBackHref, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline" onclick="if (window.opener && !window.opener.closed) { try { window.opener.focus(); } catch (e) {} window.close(); return false; }">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Back
  </a>
  <button class="btn btn-primary" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
    Print / Save as PDF
  </button>
</div>

<!-- PO Document -->
<div class="po-doc">

  <!-- ── Header ── -->
  <div class="po-header">
    <div class="po-brand">
      <div class="company-name">Clarenzon Medical Supplies</div>
      <div class="company-sub">Medical Inventory & Distribution</div>
      <div class="company-detail">
        Clarenzon HQ, Metro Manila, Philippines<br>
        Tel: +63-912-100-0001 &nbsp;|&nbsp; admin@clarenzon.com
      </div>
    </div>
    <div class="po-title-block">
      <div class="doc-label">Purchase Order</div>
      <div class="po-number"><?= htmlspecialchars($po['po_number']) ?></div>
      <div class="po-date">Date Issued: <?= fmtDate($po['created_at']) ?></div>
      <div><span class="status-badge"><?= htmlspecialchars($po['status']) ?></span></div>
    </div>
  </div>

  <div style="padding:10px 28px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:12px;color:#475569">
    This is a <strong>Purchase Order</strong> / receiving copy for the supplier driver. Do not issue a Purchase Request as a delivery or payment document.
  </div>

  <!-- ── Meta strip ── -->
  <div class="po-meta">
    <div class="meta-cell">
      <div class="meta-label">PO Number</div>
      <div class="meta-value" style="font-family:monospace"><?= htmlspecialchars($po['po_number']) ?></div>
    </div>
    <div class="meta-cell">
      <div class="meta-label">Expected Delivery</div>
      <div class="meta-value"><?= fmtDate($po['expected_delivery_date']) ?></div>
    </div>
    <div class="meta-cell">
      <div class="meta-label">Payment Terms</div>
      <div class="meta-value"><?= htmlspecialchars($po['payment_terms'] ?? '—') ?></div>
    </div>
    <div class="meta-cell">
      <div class="meta-label">Requested By</div>
      <div class="meta-value"><?= htmlspecialchars($po['requested_by_name'] ?? '—') ?></div>
    </div>
  </div>

  <!-- ── Parties ── -->
  <div class="po-parties">
    <div class="party-block">
      <div class="party-label">Vendor / Supplier</div>
      <div class="party-name"><?= htmlspecialchars($po['supplier_name'] ?? '—') ?></div>
      <div class="party-detail">
        <?php if (!empty($po['supplier_code'])): ?>Code: <?= htmlspecialchars($po['supplier_code']) ?><br><?php endif; ?>
        <?php if (!empty($po['supplier_contact'])): ?><?= htmlspecialchars($po['supplier_contact']) ?><br><?php endif; ?>
        <?php if (!empty($po['supplier_email'])): ?><?= htmlspecialchars($po['supplier_email']) ?><br><?php endif; ?>
        <?php if (!empty($po['supplier_phone'])): ?><?= htmlspecialchars($po['supplier_phone']) ?><br><?php endif; ?>
        <?php if (!empty($po['supplier_address'])): ?><?= htmlspecialchars($po['supplier_address']) ?><?php if (!empty($po['supplier_city'])) echo ', ' . htmlspecialchars($po['supplier_city']); ?><?php endif; ?>
      </div>
    </div>
    <div class="party-block">
      <div class="party-label">Ship To / Bill To</div>
      <div class="party-name">Clarenzon Medical Supplies</div>
      <div class="party-detail">
        Clarenzon HQ<br>
        Metro Manila, Philippines<br>
        Tel: +63-912-100-0001<br>
        admin@clarenzon.com
      </div>
    </div>
  </div>

  <!-- ── Items Table ── -->
  <div class="po-items-section">
    <div class="items-table-wrap system-table-scroll">
      <table class="items-tbl">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Product / Description</th>
            <th>Unit</th>
            <th class="text-right">Qty Ordered</th>
            <th class="text-right">Qty Received</th>
            <th class="text-right">Unit Price</th>
            <th class="text-right">Line Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $item):
            $lineTotal = $item['quantity_ordered'] * $item['unit_price'];
            $rcvd = (int)($item['quantity_received'] ?? 0);
            $ordd = (int)$item['quantity_ordered'];
            if ($rcvd >= $ordd && $ordd > 0) {
                $rcvClass = 'rcv-full';
            } elseif ($rcvd > 0) {
                $rcvClass = 'rcv-partial';
            } else {
                $rcvClass = 'rcv-none';
            }
          ?>
          <tr>
            <td style="color:#94a3b8;font-size:12px"><?= $i + 1 ?></td>
            <td>
              <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
              <?php if (!empty($item['sku'])): ?>
                <div class="item-sku">SKU: <?= htmlspecialchars($item['sku']) ?></div>
              <?php endif; ?>
              <?php if (!empty($item['notes'])): ?>
                <div style="font-size:11px;color:#94a3b8;margin-top:2px"><?= htmlspecialchars($item['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#64748b"><?= htmlspecialchars($item['unit_of_measure'] ?? 'pcs') ?></td>
            <td class="text-right"><span class="qty-badge"><?= $ordd ?></span></td>
            <td class="text-right"><span class="rcv-badge <?= $rcvClass ?>"><?= $rcvd ?></span></td>
            <td class="text-right"><?= fmtMoney($item['unit_price']) ?></td>
            <td class="text-right" style="font-weight:700"><?= fmtMoney($lineTotal) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Totals ── -->
  <div class="po-totals">
    <table class="totals-table">
      <tr>
        <td>Subtotal (<?= count($items) ?> line item<?= count($items) !== 1 ? 's' : '' ?>)</td>
        <td><?= fmtMoney($grand_total) ?></td>
      </tr>
      <tr>
        <td style="color:#94a3b8">VAT / Tax</td>
        <td style="color:#94a3b8">Included</td>
      </tr>
      <tr class="grand">
        <td>Grand Total</td>
        <td><?= fmtMoney($grand_total) ?></td>
      </tr>
    </table>
  </div>

  <!-- ── Notes & Approval ── -->
  <div class="po-footer-section">
    <div class="footer-block">
      <div class="footer-label">Notes / Remarks</div>
      <div class="footer-text"><?= !empty($po['notes']) ? nl2br(htmlspecialchars($po['notes'])) : 'No additional notes.' ?></div>
    </div>
    <div class="footer-block">
      <div class="footer-label">Approval Information</div>
      <div class="footer-text">
        <?php if (!empty($po['approved_by_name'])): ?>
          <strong>Approved By:</strong> <?= htmlspecialchars($po['approved_by_name']) ?><br>
          <strong>Approved At:</strong> <?= fmtDate($po['approved_at'] ?? '') ?><br>
        <?php elseif ($po['status'] === 'Pending Approval'): ?>
          <span style="color:#d97706">⏳ Awaiting Approval</span>
        <?php elseif ($po['status'] === 'Cancelled'): ?>
          <span style="color:#dc2626">✕ Cancelled</span>
          <?php if (!empty($po['rejection_reason'])): ?>
            <br><strong>Reason:</strong> <?= htmlspecialchars($po['rejection_reason']) ?>
          <?php endif; ?>
        <?php else: ?>
          Not yet approved.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Signature Block ── -->
  <div class="signature-grid">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-label">Prepared By</div>
      <div class="sig-name"><?= htmlspecialchars($po['requested_by_name'] ?? '—') ?></div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-label">Approved By</div>
      <div class="sig-name"><?= htmlspecialchars($po['approved_by_name'] ?? '') ?></div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-label">Received By</div>
      <div class="sig-name"><?= !empty($po['received_date']) ? 'Date: ' . fmtDate($po['received_date']) : '' ?></div>
    </div>
  </div>

  <!-- ── Doc Footer ── -->
  <div class="doc-footer">
    <span>Clarenzon Medical Supplies · Inventory Management System</span>
    <span>Printed: <?= date('F j, Y g:i A') ?></span>
  </div>

</div><!-- end .po-doc -->

<script>
  // Auto-trigger print dialog if ?autoprint=1
  if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 600));
  }
</script>
</body>
</html>
