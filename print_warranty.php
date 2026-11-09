<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once __DIR__ . '/billing_print_lib.php';
require_once __DIR__ . '/warranty_lib.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access.');
}
$roleKey = normalize_user_role((string)($_SESSION['role'] ?? ''));
if (!warrantyCanIssue($roleKey)) {
    http_response_code(403);
    die('You do not have permission to view warranty certificates.');
}

$certId = (int)($_GET['id'] ?? 0);
$soId = (int)($_GET['so_id'] ?? 0);
if ($certId <= 0 && $soId <= 0) {
    die('Warranty certificate is required.');
}

$conn = billingPrintConnect();
warrantyEnsureSchema($conn);
$certs = [];
if ($certId > 0) {
    $one = warrantyGet($conn, $certId);
    if ($one) $certs[] = $one;
} else {
    // All active certificates for one sale, in line / unit order.
    foreach (array_reverse(warrantyList($conn, ['sales_order_id' => $soId])) as $row) {
        if ($row['status'] !== WARRANTY_STATUS_VOIDED) {
            $full = warrantyGet($conn, (int)$row['id']);
            if ($full) $certs[] = $full;
        }
    }
    $saleRow = $conn->query('SELECT order_number FROM sales_orders WHERE id = ' . $soId . ' LIMIT 1');
    $saleNumber = $saleRow && ($sr = $saleRow->fetch_assoc()) ? (string)$sr['order_number'] : '';
}
$conn->close();

if (!$certs && $certId > 0) {
    die('Warranty certificate not found.');
}

$co = billingPrintCompany();
$base = billingPrintWebBase();
$companyName = 'CLARENZON Medical Supplies';
$authCtx = (string)($_SESSION['_auth_context'] ?? 'admin');
$title = $certId > 0 && $certs ? 'Warranty Certificate ' . $certs[0]['certificate_number'] : 'Warranty Certificates' . (!empty($saleNumber) ? ' · ' . $saleNumber : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
    <title><?= htmlspecialchars($title) ?> | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($base) ?>images/clarenzon-removebg.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Source Sans 3", "Segoe UI", Arial, sans-serif;
            color: #1f2933;
            background: #e5e9ef;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .toolbar {
            max-width: 210mm;
            margin: 20px auto 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .toolbar .t-title { margin: 0; font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #475569; }
        .toolbar .t-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .toolbar button {
            padding: 10px 18px;
            border: 1px solid #0b3d6e;
            background: #0b3d6e;
            color: #fff;
            border-radius: 6px;
            font: 600 13px/1 "Source Sans 3", "Segoe UI", sans-serif;
            letter-spacing: .03em;
            cursor: pointer;
        }
        .toolbar button.secondary { background: #fff; color: #0b3d6e; }
        .toolbar button:hover { filter: brightness(1.1); }
        .toolbar .note { flex-basis: 100%; font-size: 12px; color: #64748b; }
        .empty {
            max-width: 210mm; margin: 0 auto; background: #fff; padding: 28px; border: 1px solid #cbd5e1;
            font-size: 15px; color: #334155;
        }

        .page {
            position: relative;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 28px;
            background: #fff;
            padding: 12mm;
            box-shadow: 0 14px 36px rgba(15, 23, 42, .14);
        }
        .frame {
            position: relative;
            min-height: calc(297mm - 24mm);
            border: 2px solid #0b3d6e;
            padding: 3px;
        }
        .frame-inner {
            min-height: calc(297mm - 24mm - 10px);
            border: 1px solid #9fb3c8;
            padding: 9mm 10mm 7mm;
            display: flex;
            flex-direction: column;
        }

        .mast { display: flex; align-items: center; gap: 14px; padding-bottom: 12px; border-bottom: 1px solid #0b3d6e; }
        .mast img { width: 62px; height: 62px; object-fit: contain; }
        .mast .co { flex: 1; }
        .co-name { margin: 0; font-family: "Libre Baskerville", Georgia, serif; font-size: 21px; font-weight: 700; color: #0b3d6e; letter-spacing: .01em; }
        .co-tag { margin: 2px 0 0; font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #5b6b7d; }
        .co-contact { margin: 6px 0 0; font-size: 11.5px; color: #475569; line-height: 1.45; }

        .heading { text-align: center; margin: 16px 0 4px; }
        .heading h1 {
            margin: 0;
            font-family: "Libre Baskerville", Georgia, serif;
            font-size: 27px;
            font-weight: 400;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #10243d;
        }
        .heading .sub { margin: 6px 0 0; font-size: 12.5px; color: #475569; font-style: italic; font-family: "Libre Baskerville", Georgia, serif; }
        .cert-no {
            margin: 12px auto 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        .cert-no .box {
            border: 1.5px solid #0b3d6e;
            padding: 7px 18px;
            text-align: center;
            background: #f3f7fb;
        }
        .cert-no .label { display: block; font-size: 10px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: #5b6b7d; }
        .cert-no .value { display: block; margin-top: 2px; font-family: "Libre Baskerville", Georgia, serif; font-size: 19px; font-weight: 700; color: #0b3d6e; letter-spacing: .06em; }
        .status-chip { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; padding: 4px 10px; border: 1.5px solid; }
        .status-Active { color: #166534; border-color: #166534; }
        .status-Expired { color: #92400e; border-color: #92400e; }
        .status-Voided { color: #b91c1c; border-color: #b91c1c; }

        h2 {
            margin: 16px 0 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #0b3d6e;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: #0b3d6e;
        }
        .grid { display: grid; grid-template-columns: 1fr 1fr; column-gap: 18px; }
        dl.details { margin: 0; display: grid; grid-template-columns: 128px 1fr; }
        dl.details div { display: contents; }
        dl.details dt, dl.details dd { margin: 0; padding: 6px 0; border-bottom: 1px solid #e2e8f0; font-size: 12.5px; }
        dl.details dt { color: #5b6b7d; font-weight: 600; }
        dl.details dd { font-weight: 600; color: #10243d; overflow-wrap: anywhere; }

        .coverage { margin-top: 12px; display: grid; grid-template-columns: repeat(4, 1fr); border: 1.5px solid #0b3d6e; }
        .coverage .cell { padding: 9px 10px; border-right: 1px solid #9fb3c8; text-align: center; }
        .coverage .cell:last-child { border-right: 0; }
        .coverage .k { font-size: 9.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #5b6b7d; }
        .coverage .v { margin-top: 3px; font-family: "Libre Baskerville", Georgia, serif; font-size: 14px; font-weight: 700; color: #10243d; }
        .coverage .cell.expiry { background: #f3f7fb; }
        .coverage .cell.expiry .v { color: #0b3d6e; }

        ol.terms { margin: 8px 0 0; padding: 0 0 0 18px; }
        ol.terms li { margin: 0 0 4px; padding-left: 4px; font-size: 11.5px; line-height: 1.5; color: #334155; }
        .terms-note { margin: 6px 0 0; font-size: 10.5px; color: #64748b; font-style: italic; }

        .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; margin-top: auto; padding-top: 18px; }
        .sign-block .line { border-bottom: 1px solid #10243d; height: 34px; }
        .sign-block .name { margin-top: 5px; font-size: 12.5px; font-weight: 700; color: #10243d; min-height: 16px; }
        .sign-block .role { font-size: 10.5px; letter-spacing: .1em; text-transform: uppercase; color: #5b6b7d; }
        .sign-block .date { margin-top: 3px; font-size: 11px; color: #475569; }

        .foot {
            margin-top: 14px;
            padding-top: 6px;
            border-top: 1px solid #9fb3c8;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 9.5px;
            letter-spacing: .04em;
            color: #64748b;
        }

        .watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 2;
        }
        .watermark span {
            font-family: "Libre Baskerville", Georgia, serif;
            font-size: 120px;
            font-weight: 700;
            letter-spacing: .1em;
            color: rgba(185, 28, 28, .16);
            border: 10px solid rgba(185, 28, 28, .16);
            padding: 4px 34px;
            transform: rotate(-28deg);
        }
        .void-note { margin-top: 10px; padding: 7px 10px; border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; font-size: 11.5px; }

        @media print {
            body { background: #fff; }
            .toolbar, .empty, #clarRealtimeStatus { display: none !important; }
            .page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; page-break-after: always; break-after: page; }
            .page:last-of-type { page-break-after: auto; break-after: auto; }
            .frame { min-height: calc(297mm - 20mm - 3mm); }
            .frame-inner { min-height: calc(297mm - 20mm - 3mm - 10px); }
        }
        @media screen and (max-width: 840px) {
            .toolbar { margin: 12px; }
            .page { width: auto; min-height: 0; margin: 0 8px 18px; padding: 10px; }
            .frame, .frame-inner { min-height: 0; }
            .grid, .coverage { grid-template-columns: 1fr; }
            .coverage .cell { border-right: 0; border-bottom: 1px solid #9fb3c8; }
            .signs { grid-template-columns: 1fr; }
            .watermark span { font-size: 64px; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <p class="t-title"><?= htmlspecialchars($title) ?></p>
    <div class="t-actions">
        <?php if ($certs): ?>
        <button type="button" onclick="wcPrint()">Print Warranty Certificate<?= count($certs) > 1 ? 's (' . count($certs) . ')' : '' ?></button>
        <?php endif; ?>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
    </div>
    <?php if ($certs): ?>
    <div class="note">Print preview — A4 portrait. Choose "Save as PDF" in the print dialog for a digital copy.</div>
    <?php endif; ?>
</div>

<?php if (!$certs): ?>
<div class="empty">No active warranty certificate has been generated for this sale yet. Open the completed sales order and choose <strong>Warranty</strong> to generate one.</div>
<?php endif; ?>

<?php foreach ($certs as $c):
    $status = $c['display_status'];
    $terms = warrantyTermsList((string)($c['terms_snapshot'] ?? ''));
    if (!$terms) $terms = warrantyTermsList(warrantyDefaultTerms());
    $serial = trim((string)($c['serial_number'] ?? ''));
?>
<article class="page" data-cert-id="<?= (int)$c['id'] ?>">
    <?php if ($status === WARRANTY_STATUS_VOIDED): ?><div class="watermark"><span>VOID</span></div><?php endif; ?>
    <div class="frame"><div class="frame-inner">
        <header class="mast">
            <img src="<?= htmlspecialchars($base . $co['logo']) ?>" alt="">
            <div class="co">
                <p class="co-name"><?= htmlspecialchars($companyName) ?></p>
                <p class="co-tag"><?= htmlspecialchars($co['tagline']) ?></p>
                <p class="co-contact">
                    <?= htmlspecialchars($co['address']) ?><br>
                    Tel: <?= htmlspecialchars($co['phone']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($co['email']) ?>
                </p>
            </div>
        </header>

        <div class="heading">
            <h1>Warranty Certificate</h1>
            <p class="sub">This certifies that the product described below was purchased from <?= htmlspecialchars($companyName) ?>.</p>
        </div>
        <div class="cert-no">
            <div class="box">
                <span class="label">Warranty Certificate No.</span>
                <span class="value"><?= htmlspecialchars((string)$c['certificate_number']) ?></span>
            </div>
            <span class="status-chip status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
        </div>

        <div class="grid">
            <section>
                <h2>Customer</h2>
                <dl class="details">
                    <div><dt>Customer Name</dt><dd><?= htmlspecialchars((string)$c['customer_name']) ?></dd></div>
                    <div><dt>Contact Number</dt><dd><?= htmlspecialchars(trim((string)($c['customer_contact'] ?? '')) !== '' ? (string)$c['customer_contact'] : '—') ?></dd></div>
                </dl>
                <h2>Purchase Reference</h2>
                <dl class="details">
                    <div><dt>Sales Invoice No.</dt><dd><?= htmlspecialchars(($c['invoice_number'] ?? '') !== '' ? (string)$c['invoice_number'] : '—') ?></dd></div>
                    <div><dt>Sales Order No.</dt><dd><?= htmlspecialchars(($c['order_number'] ?? '') !== '' ? (string)$c['order_number'] : '—') ?></dd></div>
                    <div><dt>Purchase Date</dt><dd><?= htmlspecialchars(billingPrintDate((string)$c['purchase_date'])) ?></dd></div>
                </dl>
            </section>
            <section>
                <h2>Product</h2>
                <dl class="details">
                    <div><dt>Product</dt><dd><?= htmlspecialchars((string)$c['product_name']) ?></dd></div>
                    <div><dt>Brand</dt><dd><?= htmlspecialchars(trim((string)($c['brand'] ?? '')) !== '' ? (string)$c['brand'] : '—') ?></dd></div>
                    <div><dt>Model</dt><dd><?= htmlspecialchars(trim((string)($c['model_number'] ?? '')) !== '' ? (string)$c['model_number'] : '—') ?></dd></div>
                    <div><dt>Serial / Unit ID</dt><dd><?= htmlspecialchars($serial !== '' ? $serial : ('Unit ' . (int)$c['unit_seq'] . ' of sale line')) ?></dd></div>
                    <div><dt>SKU</dt><dd><?= htmlspecialchars(($c['sku'] ?? '') !== '' ? (string)$c['sku'] : '—') ?></dd></div>
                </dl>
            </section>
        </div>

        <div class="coverage">
            <div class="cell"><div class="k">Warranty Duration</div><div class="v"><?= htmlspecialchars((string)$c['duration_label']) ?></div></div>
            <div class="cell"><div class="k">Purchase Date</div><div class="v"><?= htmlspecialchars(billingPrintDate((string)$c['purchase_date'], 'M j, Y')) ?></div></div>
            <div class="cell"><div class="k">Warranty Start</div><div class="v"><?= htmlspecialchars(billingPrintDate((string)$c['start_date'], 'M j, Y')) ?></div></div>
            <div class="cell expiry"><div class="k">Warranty Expiration</div><div class="v"><?= htmlspecialchars(billingPrintDate((string)$c['expiry_date'], 'M j, Y')) ?></div></div>
        </div>
        <?php if ($status === WARRANTY_STATUS_VOIDED): ?>
        <div class="void-note"><strong>This certificate has been voided</strong><?= !empty($c['voided_at']) ? ' on ' . htmlspecialchars(billingPrintDate((string)$c['voided_at'])) : '' ?> and is no longer valid.<?= trim((string)($c['void_reason'] ?? '')) !== '' ? ' Reason: ' . htmlspecialchars((string)$c['void_reason']) : '' ?></div>
        <?php endif; ?>

        <h2>Warranty Terms and Conditions</h2>
        <ol class="terms">
            <?php foreach ($terms as $t): ?><li><?= htmlspecialchars($t) ?></li><?php endforeach; ?>
        </ol>
        <p class="terms-note">Coverage is limited to the terms above and the applicable manufacturer warranty policy. Please keep this certificate together with your official receipt.</p>

        <div class="signs">
            <div class="sign-block">
                <div class="line"></div>
                <div class="name"><?= htmlspecialchars(trim((string)($c['issued_by_name'] ?? '')) !== '' ? (string)$c['issued_by_name'] : '') ?></div>
                <div class="role">Authorized Representative / Issued By</div>
                <div class="date">Date issued: <?= htmlspecialchars(billingPrintDate((string)$c['issued_at'])) ?></div>
            </div>
            <div class="sign-block">
                <div class="line"></div>
                <div class="name"><?= htmlspecialchars((string)$c['customer_name']) ?></div>
                <div class="role">Customer Acknowledgment</div>
                <div class="date">Date received: ______________________</div>
            </div>
        </div>

        <div class="foot">
            <span><?= htmlspecialchars($companyName) ?> · Warranty Certificate <?= htmlspecialchars((string)$c['certificate_number']) ?></span>
            <span>Ref. <?= htmlspecialchars(($c['invoice_number'] ?? '') !== '' ? (string)$c['invoice_number'] : (string)$c['order_number']) ?></span>
        </div>
    </div></div>
</article>
<?php endforeach; ?>

<script>window.CLAR_AUTH_CONTEXT = <?= json_encode($authCtx) ?>;</script>
<script src="<?= htmlspecialchars($base) ?>java/auth_context.js"></script>
<script>
function wcPrint() {
  const ids = Array.from(document.querySelectorAll('.page[data-cert-id]')).map(el => el.getAttribute('data-cert-id'));
  ids.forEach(id => {
    try {
      fetch('warranty_api.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'log_print', id }),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        keepalive: true
      }).catch(() => {});
    } catch (e) {}
  });
  window.print();
}
</script>
</body>
</html>
