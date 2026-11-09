<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . '/billing_print_lib.php';
require_once __DIR__ . '/sales_order_lib.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access.');
}

$isPreview = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['preview'] ?? '') === '1';
if ($isPreview) {
    $agreement = '';
    $customer = trim((string)($_POST['customer_name'] ?? ''));
    if ($customer === '') {
        $customer = '—';
    }
    $orderNo = '—';
    $total = round((float)($_POST['total_amount'] ?? 0), 2);
    $balance = array_key_exists('outstanding_balance', $_POST)
        ? round((float)$_POST['outstanding_balance'], 2)
        : $total;
    if ($balance < 0) {
        $balance = 0.0;
    }
    $agreementDate = billingPrintDate(date('Y-m-d'));
    $dueDate = billingPrintDate((string)($_POST['due_date'] ?? ''));
    $terms = trim((string)($_POST['credit_terms'] ?? ''));
} else {
    $soId = (int)($_GET['so_id'] ?? 0);
    if ($soId <= 0) {
        die('Sales order is required.');
    }

    $conn = billingPrintConnect();
    salesOrderEnsureSchema($conn);
    $detail = salesOrderLoadDetail($conn, $soId);
    $conn->close();
    if (!$detail || empty($detail['order'])) {
        die('Sales order not found.');
    }

    $o = $detail['order'];
    $agreement = trim((string)($o['agreement_number'] ?? ''));
    if ($agreement === '') {
        die('This sales order has no credit agreement.');
    }

    $customer = trim((string)($o['customer_name'] ?? ''));
    if ($customer === '') {
        $customer = '—';
    }
    $orderNo = trim((string)($o['order_number'] ?? ''));
    if ($orderNo === '') {
        $orderNo = '—';
    }
    $total = (float)($o['grand_total'] ?? $o['agreed_amount'] ?? 0);
    $balance = array_key_exists('remaining_balance', $o) && $o['remaining_balance'] !== null && $o['remaining_balance'] !== ''
        ? (float)$o['remaining_balance']
        : $total;
    $agreementDate = billingPrintDate((string)($o['created_at'] ?? ''));
    $dueDate = billingPrintDate((string)($o['payment_due_date'] ?? ''));
    $terms = trim((string)($o['credit_terms'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Agreement<?= $agreement !== '' ? ' ' . htmlspecialchars($agreement) : '' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 16mm 18mm 18mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
            color: #1c1917;
            background: #e7e5e4;
        }
        .toolbar {
            max-width: 210mm;
            margin: 20px auto 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .toolbar p { margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #57534e; }
        .toolbar button {
            padding: 10px 18px;
            border: 0;
            background: #1c1917;
            color: #fff;
            border-radius: 4px;
            font: 600 13px/1 "Source Sans 3", "Segoe UI", sans-serif;
            letter-spacing: .04em;
            cursor: pointer;
        }
        .toolbar button:hover { background: #44403c; }
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 32px;
            background: #fff;
            padding: 18mm 18mm 16mm;
            box-shadow: 0 16px 40px rgba(28, 25, 23, .12);
        }
        .mast {
            text-align: center;
            padding-bottom: 18px;
            border-bottom: 1px solid #1c1917;
        }
        .mast .rule {
            height: 3px;
            background: #1c1917;
            margin-bottom: 3px;
        }
        .mast .rule-thin {
            height: 1px;
            background: #1c1917;
            margin-bottom: 18px;
        }
        .co-name {
            margin: 0;
            font-family: "Libre Baskerville", Georgia, serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .22em;
            text-transform: uppercase;
        }
        h1 {
            margin: 8px 0 0;
            font-family: "Libre Baskerville", Georgia, serif;
            font-size: 28px;
            font-weight: 400;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .doc-meta {
            margin-top: 14px;
            display: flex;
            justify-content: center;
            gap: 28px;
            font-size: 12px;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #44403c;
        }
        .doc-meta strong { font-weight: 700; color: #1c1917; }
        h2 {
            margin: 28px 0 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #1c1917;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
        }
        dl.details {
            margin: 0;
            display: grid;
            grid-template-columns: 200px 1fr;
        }
        dl.details div {
            display: contents;
        }
        dl.details dt, dl.details dd {
            margin: 0;
            padding: 11px 0;
            border-bottom: 1px solid #d6d3d1;
            font-size: 14.5px;
        }
        dl.details dt {
            color: #57534e;
            font-weight: 600;
            letter-spacing: .02em;
        }
        dl.details dd {
            font-family: "Libre Baskerville", Georgia, serif;
            font-size: 15px;
        }
        dl.details .emphasis dd {
            font-weight: 700;
        }
        .intro {
            margin: 16px 0 0;
            font-family: "Libre Baskerville", Georgia, serif;
            font-size: 14.5px;
            line-height: 1.7;
        }
        ol.terms {
            margin: 14px 0 0;
            padding: 0 0 0 22px;
        }
        ol.terms li {
            margin: 0 0 10px;
            padding-left: 6px;
            font-family: "Libre Baskerville", Georgia, serif;
            font-size: 13.5px;
            line-height: 1.65;
        }
        .signs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            margin-top: 22px;
        }
        .sign-block h3 {
            margin: 0 0 28px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .sign-row {
            margin: 0 0 18px;
            font-size: 12px;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #57534e;
        }
        .sign-line {
            display: block;
            margin-top: 22px;
            border-bottom: 1px solid #1c1917;
            height: 1px;
        }
        .doc-footer {
            margin-top: 36px;
            padding-top: 10px;
            border-top: 1px solid #d6d3d1;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #78716c;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page { width: auto; min-height: auto; margin: 0; padding: 0; box-shadow: none; }
            .signs { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <p>Credit Agreement</p>
    <button type="button" onclick="window.print()">Print agreement</button>
</div>
<article class="page">
    <header class="mast">
        <div class="rule"></div>
        <div class="rule-thin"></div>
        <p class="co-name">Clarenzon Marketing</p>
        <h1>Credit Agreement</h1>
        <div class="doc-meta">
            <?php if ($agreement !== ''): ?><span>No. <strong><?= htmlspecialchars($agreement) ?></strong></span><?php endif; ?>
            <span>Date <strong><?= htmlspecialchars($agreementDate) ?></strong></span>
            <span>Due <strong><?= htmlspecialchars($dueDate) ?></strong></span>
        </div>
    </header>

    <h2>Parties and amount</h2>
    <dl class="details">
        <div><dt>Customer</dt><dd><?= htmlspecialchars($customer) ?></dd></div>
        <div><dt>Sales order</dt><dd><?= htmlspecialchars($orderNo) ?></dd></div>
        <div><dt>Agreement date</dt><dd><?= htmlspecialchars($agreementDate) ?></dd></div>
        <div><dt>Total amount</dt><dd><?= billingPrintMoney($total) ?></dd></div>
        <div class="emphasis"><dt>Outstanding balance</dt><dd><?= billingPrintMoney($balance) ?></dd></div>
        <div class="emphasis"><dt>Payment due</dt><dd><?= htmlspecialchars($dueDate) ?></dd></div>
    </dl>

    <h2>Terms</h2>
    <p class="intro">This agreement records a credit sale between Clarenzon Marketing and the customer named above.</p>
    <ol class="terms">
        <li>I, <?= htmlspecialchars($customer) ?>, acknowledge that I have purchased the products listed in Sales Order <?= htmlspecialchars($orderNo) ?> from Clarenzon Marketing under a credit arrangement.</li>
        <li>I agree to pay the outstanding balance of <?= billingPrintMoney($balance) ?> on or before <?= htmlspecialchars($dueDate) ?>, according to the agreed payment terms.</li>
        <li>I acknowledge that the outstanding balance remains my responsibility until it is fully paid.</li>
        <li>I agree to comply with the agreed payment terms and settle the amount stated in this agreement.</li>
        <li>By signing below, I confirm that I have read, understood, and agreed to the terms of this Credit Agreement.</li>
        <?php if ($terms !== ''): ?>
        <li>Agreed payment terms recorded for this sale: <?= nl2br(htmlspecialchars($terms)) ?></li>
        <?php endif; ?>
    </ol>

    <h2>Signatures</h2>
    <div class="signs">
        <div class="sign-block">
            <h3>Customer / Authorized purchaser</h3>
            <div class="sign-row">Signature<span class="sign-line"></span></div>
            <div class="sign-row">Printed name<span class="sign-line"></span></div>
            <div class="sign-row">Date<span class="sign-line"></span></div>
        </div>
        <div class="sign-block">
            <h3>Authorized representative</h3>
            <div class="sign-row">Signature<span class="sign-line"></span></div>
            <div class="sign-row">Printed name<span class="sign-line"></span></div>
            <div class="sign-row">Date<span class="sign-line"></span></div>
        </div>
    </div>

    <div class="doc-footer">
        <span>Clarenzon Marketing</span>
        <span>Credit Agreement<?= $agreement !== '' ? ' · ' . htmlspecialchars($agreement) : '' ?></span>
    </div>
</article>
</body>
</html>
