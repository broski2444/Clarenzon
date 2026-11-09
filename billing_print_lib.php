<?php
/** Shared helpers and print markup for customer invoice + official receipt. */

function billingPrintWebBase(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/(warehouse_head|purchaser|admin)/[^/]+$#', $script)) {
        return '../';
    }
    return '';
}

function billingPrintConnect(): mysqli {
    $lib = __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
    if (is_file($lib)) {
        require_once $lib;
    }
    $c = function_exists('clarenzon_db_connect_first')
        ? clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db'])
        : null;
    if ($c instanceof mysqli) {
        return $c;
    }
    die('Database connection failed.');
}

function billingPrintCompany(): array {
    return [
        'name' => 'Clarenzon Medical Supplies',
        'tagline' => 'Medical Inventory & Distribution',
        'address' => 'Clarenzon HQ, Metro Manila, Philippines',
        'phone' => '+63-912-100-0001',
        'email' => 'admin@clarenzon.com',
        'logo' => 'images/clarenzon-removebg.png',
    ];
}

function billingPrintMoney($v): string {
    return '₱' . number_format((float)$v, 2);
}

function billingPrintDate(?string $d, string $fmt = 'F j, Y'): string {
    if (!$d || $d === '0000-00-00') return '—';
    $ts = strtotime($d);
    return $ts ? date($fmt, $ts) : '—';
}

function billingNumberToWords(int $n): string {
    if ($n === 0) return 'Zero';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $chunk = function (int $num) use ($ones, $tens): string {
        $out = '';
        if ($num >= 100) {
            $out .= $ones[intdiv($num, 100)] . ' Hundred';
            $num %= 100;
            if ($num > 0) $out .= ' ';
        }
        if ($num >= 20) {
            $out .= $tens[intdiv($num, 10)];
            $num %= 10;
            if ($num > 0) $out .= '-' . $ones[$num];
        } elseif ($num > 0) {
            $out .= $ones[$num];
        }
        return $out;
    };
    $parts = [];
    $scales = [
        1000000000 => 'Billion',
        1000000 => 'Million',
        1000 => 'Thousand',
    ];
    foreach ($scales as $value => $label) {
        if ($n >= $value) {
            $parts[] = $chunk(intdiv($n, $value)) . ' ' . $label;
            $n %= $value;
        }
    }
    if ($n > 0) $parts[] = $chunk($n);
    return trim(implode(' ', $parts));
}

function billingPesoInWords(float $amount): string {
    $pesos = (int)floor($amount + 0.00001);
    $centavos = (int)round(($amount - $pesos) * 100);
    if ($centavos === 100) {
        $pesos++;
        $centavos = 0;
    }
    $words = billingNumberToWords($pesos) . ' Pesos';
    if ($centavos > 0) {
        $words .= ' and ' . billingNumberToWords($centavos) . ' Centavos';
    }
    return strtoupper($words) . ' ONLY';
}

function billingFetchInvoiceBundle(mysqli $conn, string $invoiceNumber): ?array {
    $stmt = $conn->prepare('SELECT * FROM invoices WHERE invoice_number = ? LIMIT 1');
    $stmt->bind_param('s', $invoiceNumber);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice) return null;

    $invoiceId = (int)$invoice['id'];
    if (empty($invoice['sales_order_number']) && !empty($invoice['sales_order_id'])) {
        $soN = $conn->query('SELECT order_number FROM sales_orders WHERE id=' . (int)$invoice['sales_order_id'] . ' LIMIT 1');
        if ($soN && ($sr = $soN->fetch_assoc())) {
            $invoice['sales_order_number'] = $sr['order_number'];
        }
    }

    $items = [];
    $itemsQuery = $conn->query("SELECT ii.*, p.sku, p.name AS product_name, p.unit_of_measure FROM invoice_items ii LEFT JOIN products p ON ii.product_id = p.id WHERE ii.invoice_id = $invoiceId");
    if ($itemsQuery) {
        while ($row = $itemsQuery->fetch_assoc()) $items[] = $row;
    }

    $payments = [];
    $payQuery = $conn->query("SELECT * FROM invoice_payments WHERE invoice_id = $invoiceId ORDER BY id ASC");
    if ($payQuery) {
        while ($row = $payQuery->fetch_assoc()) $payments[] = $row;
    }

    return ['invoice' => $invoice, 'items' => $items, 'payments' => $payments];
}

function billingFetchPaymentBundle(mysqli $conn, string $paymentNumber = '', string $invoiceNumber = ''): ?array {
    $payment = null;
    if ($paymentNumber !== '') {
        $stmt = $conn->prepare('SELECT ip.* FROM invoice_payments ip WHERE ip.payment_number = ? LIMIT 1');
        $stmt->bind_param('s', $paymentNumber);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($invoiceNumber !== '') {
        $stmt = $conn->prepare('SELECT ip.* FROM invoice_payments ip JOIN invoices inv ON inv.id = ip.invoice_id WHERE inv.invoice_number = ? ORDER BY ip.id DESC LIMIT 1');
        $stmt->bind_param('s', $invoiceNumber);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$payment) return null;

    $invStmt = $conn->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $invId = (int)$payment['invoice_id'];
    $invStmt->bind_param('i', $invId);
    $invStmt->execute();
    $invoice = $invStmt->get_result()->fetch_assoc();
    $invStmt->close();
    if (!$invoice) return null;

    $bundle = billingFetchInvoiceBundle($conn, (string)$invoice['invoice_number']);
    if (!$bundle) return null;
    $bundle['payment'] = $payment;
    return $bundle;
}

function billingPrintCss(): string {
    return <<<CSS
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Inter, "Segoe UI", sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; padding: 30px 20px; }
  .toolbar { max-width: 820px; margin: 0 auto 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .toolbar h2 { flex: 1; font-size: 15px; font-weight: 600; color: #475569; }
  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; color: #334155; cursor: pointer; }
  .btn-primary { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
  .doc { max-width: 820px; margin: 0 auto 28px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(15,23,42,.06); }
  .doc-header { display: flex; justify-content: space-between; gap: 20px; padding: 28px 32px 20px; border-bottom: 3px solid #1d4ed8; }
  .company-name { font-size: 22px; font-weight: 800; color: #1e3a8a; letter-spacing: -.02em; }
  .company-sub { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .08em; margin-top: 2px; }
  .company-detail { font-size: 12px; color: #475569; margin-top: 8px; line-height: 1.5; }
  .doc-title-block { text-align: right; }
  .doc-label { font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #1d4ed8; }
  .doc-number { font-size: 22px; font-weight: 800; color: #0f172a; margin-top: 4px; }
  .doc-date { font-size: 12px; color: #475569; margin-top: 6px; }
  .banner { padding: 8px 32px; background: #eff6ff; border-bottom: 1px solid #dbeafe; font-size: 12px; color: #1e40af; }
  .banner.receipt { background: #ecfdf5; border-bottom-color: #a7f3d0; color: #065f46; }
  .meta { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid #e2e8f0; }
  .meta-cell { padding: 12px 16px; border-right: 1px solid #e2e8f0; }
  .meta-cell:last-child { border-right: none; }
  .meta-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; }
  .meta-value { font-size: 13px; font-weight: 700; margin-top: 3px; }
  .parties { display: grid; grid-template-columns: 1fr 1fr; }
  .parties.parties-single { grid-template-columns: 1fr; }
  .party { padding: 18px 32px; }
  .party + .party { border-left: 1px solid #e2e8f0; }
  .parties.parties-single .party + .party { border-left: none; }
  .party-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; margin-bottom: 6px; }
  .party-name { font-size: 16px; font-weight: 800; }
  .party-detail { font-size: 12px; color: #475569; margin-top: 4px; line-height: 1.5; }
  .items-wrap {
    padding: 8px 32px 20px;
    display: block;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  table.items { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.items th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; background: #f8fafc; border-bottom: 2px solid #e2e8f0; padding: 10px 8px; }
  table.items td { padding: 10px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .right { text-align: right; }
  .totals { width: 280px; margin: 0 32px 8px auto; }
  .totals td { padding: 6px 0; font-size: 13px; }
  .totals .grand td { font-size: 16px; font-weight: 800; color: #1d4ed8; border-top: 2px solid #e2e8f0; padding-top: 10px; }
  .totals .due td { font-weight: 800; color: #dc2626; }
  .totals .paid td { font-weight: 800; color: #059669; }
  .words { margin: 8px 32px 20px; padding: 10px 14px; background: #f8fafc; border: 1px dashed #cbd5e1; font-size: 12px; }
  .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
  .badge-green { background: #d1fae5; color: #047857; }
  .badge-warning { background: #fef3c7; color: #b45309; }
  .badge-danger { background: #fee2e2; color: #b91c1c; }
  .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; padding: 28px 32px 36px; }
  .sign-line { border-top: 1px solid #94a3b8; margin-top: 48px; padding-top: 6px; font-size: 12px; color: #475569; }
  .footer { padding: 0 32px 24px; font-size: 11px; color: #94a3b8; text-align: center; }
  .empty-note { margin: 20px 32px; padding: 16px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 13px; }
  @media print {
    body { background: #fff; padding: 0; }
    .toolbar, .no-print { display: none !important; }
    .doc { box-shadow: none; border: none; max-width: none; margin: 0; page-break-after: always; }
    .doc:last-child { page-break-after: auto; }
  }
  @media screen and (max-width: 700px) {
    .doc-header, .parties, .meta { display: block; }
    .doc-title-block, .party + .party { text-align: left; border-left: none; border-top: 1px solid #e2e8f0; }
    .totals { width: 100%; margin: 0 0 16px; padding: 0 32px; }
  }
CSS;
}

function billingPrintToolbar(string $title, array $extraLinks = []): void {
    echo '<div class="toolbar no-print"><h2>' . htmlspecialchars($title) . '</h2>';
    foreach ($extraLinks as $href => $label) {
        echo '<a class="btn" href="' . htmlspecialchars($href) . '" target="_blank">' . htmlspecialchars($label) . '</a>';
    }
    echo '<button class="btn btn-primary" type="button" onclick="window.print()">Print / Save as PDF</button></div>';
}

function billingPrintInclusiveTax(float $amount, float $rate): float {
    if ($amount <= 0 || $rate <= 0) return 0.0;
    return round($amount * $rate / (100.0 + $rate), 2);
}

function billingPrintSaleType(?string $type): string {
    $t = strtolower(trim((string)$type));
    $t = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $t));
    if ($t === 'credit') return 'Credit';
    if ($t === 'cheque' || $t === 'check') return 'Cheque';
    if ($t === 'gcash' || $t === 'gcash/paymaya' || $t === 'gcash / paymaya') return 'GCash';
    if ($t === 'maya' || $t === 'paymaya' || $t === 'pay maya') return 'Maya';
    if ($t === 'bank transfer' || $t === 'banktransfer') return 'Bank Transfer';
    return 'Cash';
}

function billingPrintPayMethod(?string $method): string {
    $raw = trim((string)$method);
    if ($raw === '') return 'Cash';
    $t = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $raw)));
    if ($t === 'credit') return 'Credit';
    if ($t === 'cheque' || $t === 'check') return 'Cheque';
    if ($t === 'gcash' || $t === 'gcash/paymaya' || $t === 'gcash / paymaya') return 'GCash';
    if ($t === 'maya' || $t === 'paymaya' || $t === 'pay maya') return 'Maya';
    if ($t === 'bank transfer' || $t === 'banktransfer') return 'Bank Transfer';
    if ($t === 'cash') return 'Cash';
    return $raw;
}

function billingPrintLineTax(array $item, array $invoice): float {
    if (array_key_exists('tax_included', $item) && $item['tax_included'] !== null && $item['tax_included'] !== '') {
        return (float)$item['tax_included'];
    }
    $amount = ((float)($item['quantity'] ?? 0)) * ((float)($item['unit_price'] ?? 0));
    $rate = (float)($item['tax_rate'] ?? 0);
    if ($rate <= 0) {
        $rate = (float)($invoice['tax_rate'] ?? 12);
    }
    if ($rate <= 0) {
        $rate = 12.0;
    }
    return billingPrintInclusiveTax($amount, $rate);
}

function billingPrintChequeStatus(?string $status): string {
    $st = trim((string)$status);
    if ($st === 'Bounced') return 'Bounced/Rejected';
    return $st;
}

function billingPrintReceiptStatus(?string $status): string {
    $st = trim((string)$status);
    if ($st === '' || $st === 'Pending Clearance') return 'Pending';
    if ($st === 'Cleared') return 'Paid';
    if ($st === 'Bounced') return 'Bounced/Rejected';
    return $st;
}

function billingRenderReceiptPaymentDetails(array $payment): void {
    $method = billingPrintPayMethod($payment['payment_method'] ?? '');
    if ($method === 'GCash') {
        $rows = [
            'Payment Method' => 'GCash',
            'GCash Name' => (string)($payment['wallet_name'] ?? ''),
            'GCash Number' => (string)($payment['wallet_number'] ?? ''),
            'Reference Number' => (string)($payment['reference_number'] ?? ''),
        ];
    } elseif ($method === 'Maya') {
        $rows = [
            'Payment Method' => 'Maya',
            'Maya Name' => (string)($payment['wallet_name'] ?? ''),
            'Maya Number' => (string)($payment['wallet_number'] ?? ''),
            'Reference Number' => (string)($payment['reference_number'] ?? ''),
        ];
    } elseif ($method === 'Cheque') {
        $rows = [
            'Payment Method' => 'Cheque',
            'Cheque Number' => (string)($payment['cheque_number'] ?? ''),
            'Bank Name' => (string)($payment['bank_name'] ?? ''),
            'Payment Status' => billingPrintReceiptStatus($payment['cheque_status'] ?? ''),
        ];
    } else {
        return;
    }
    echo '<div class="words">';
    echo '<div class="party-label">Payment Details</div>';
    foreach ($rows as $label => $value) {
        $shown = trim($value) !== '' ? $value : '—';
        echo htmlspecialchars($label) . ': <strong>' . htmlspecialchars($shown) . '</strong><br>';
    }
    echo '</div>';
}

function billingStatusBadge(string $status): string {
    $class = 'badge-warning';
    if ($status === 'Paid') $class = 'badge-green';
    elseif ($status === 'Unpaid' || $status === '') $class = 'badge-danger';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($status !== '' ? $status : 'Unpaid') . '</span>';
}

function billingRenderCustomerInvoice(array $invoice, array $items, array $payments): void {
    $co = billingPrintCompany();
    $saleType = billingPrintSaleType($invoice['sale_type'] ?? 'Cash');
    $taxIncludedTotal = (float)($invoice['tax_amount'] ?? 0);
    if ($taxIncludedTotal <= 0) {
        foreach ($items as $it) {
            $taxIncludedTotal += billingPrintLineTax($it, $invoice);
        }
        $taxIncludedTotal = round($taxIncludedTotal, 2);
    }
    ?>
    <div class="doc">
      <div class="doc-header">
        <div>
          <div class="company-name"><?= htmlspecialchars($co['name']) ?></div>
          <div class="company-sub"><?= htmlspecialchars($co['tagline']) ?></div>
          <div class="company-detail">
            <?= htmlspecialchars($co['address']) ?><br>
            Tel: <?= htmlspecialchars($co['phone']) ?> &nbsp;|&nbsp; <?= htmlspecialchars($co['email']) ?>
          </div>
        </div>
        <div class="doc-title-block">
          <div class="doc-label">Customer Sales Invoice</div>
          <div class="doc-number"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
          <div class="doc-date">Date: <?= billingPrintDate($invoice['created_at'] ?? '') ?></div>
          <div class="doc-date">Status: <?= billingStatusBadge((string)($invoice['payment_status'] ?? 'Unpaid')) ?></div>
        </div>
      </div>
      <div class="banner">This is the <strong>customer sales invoice</strong>. Clarenzon is the seller. This is not a supplier invoice.</div>
      <div class="meta">
        <div class="meta-cell"><div class="meta-label">Sales Order</div><div class="meta-value"><?= htmlspecialchars($invoice['sales_order_number'] ?: '—') ?></div></div>
        <div class="meta-cell"><div class="meta-label">Customer PO</div><div class="meta-value"><?= htmlspecialchars(($invoice['customer_po_number'] ?? '') !== '' ? $invoice['customer_po_number'] : '—') ?></div></div>
        <div class="meta-cell"><div class="meta-label">Sale Type</div><div class="meta-value"><?= htmlspecialchars($saleType) ?></div></div>
        <div class="meta-cell"><div class="meta-label">Due Date</div><div class="meta-value"><?= billingPrintDate($invoice['payment_due_date'] ?? '') ?></div></div>
      </div>
      <div class="parties parties-single">
        <div class="party">
          <div class="party-label">Billed To (Customer)</div>
          <div class="party-name"><?= htmlspecialchars($invoice['customer_name'] ?: '—') ?></div>
          <div class="party-detail">Hospital / clinic customer<br>Customer copy — keep with your records</div>
        </div>
      </div>
      <div class="items-wrap system-table-scroll">
        <table class="items">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Description</th>
              <th>UOM</th>
              <th class="right">Qty</th>
              <th class="right">Unit Price</th>
              <th class="right">Tax</th>
              <th class="right">Amount</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$items): ?>
            <tr><td colspan="7">No line items on this invoice.</td></tr>
          <?php endif; ?>
          <?php foreach ($items as $item):
              $lineAmount = ((float)$item['quantity']) * ((float)$item['unit_price']);
              $lineTax = billingPrintLineTax($item, $invoice);
          ?>
            <tr>
              <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
              <td><strong><?= htmlspecialchars($item['product_name'] ?? 'Item') ?></strong></td>
              <td><?= htmlspecialchars($item['unit_of_measure'] ?? 'box') ?></td>
              <td class="right"><?= number_format((float)$item['quantity']) ?></td>
              <td class="right"><?= billingPrintMoney($item['unit_price']) ?></td>
              <td class="right"><?= billingPrintMoney($lineTax) ?></td>
              <td class="right"><?= billingPrintMoney($lineAmount) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <table class="totals">
        <tr><td>Subtotal</td><td class="right"><?= billingPrintMoney($invoice['subtotal'] ?? 0) ?></td></tr>
        <?php if ((float)($invoice['discount_amount'] ?? 0) > 0): ?>
        <tr><td>Discount</td><td class="right">-<?= billingPrintMoney($invoice['discount_amount']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Tax</td><td class="right"><?= billingPrintMoney($taxIncludedTotal) ?></td></tr>
        <?php if ((float)($invoice['delivery_fee'] ?? 0) > 0): ?>
        <tr><td>Delivery Fee</td><td class="right"><?= billingPrintMoney($invoice['delivery_fee']) ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td>Grand Total</td><td class="right"><?= billingPrintMoney($invoice['grand_total'] ?? 0) ?></td></tr>
        <?php
            $cashPay = null;
            foreach ($payments as $pay) {
                if (billingPrintSaleType($pay['payment_method'] ?? '') !== 'Cash') {
                    continue;
                }
                if (!array_key_exists('cash_tendered', $pay) || $pay['cash_tendered'] === null || $pay['cash_tendered'] === '') {
                    continue;
                }
                $cashPay = $pay;
            }
            if ($cashPay):
        ?>
        <tr><td>Cash Tendered</td><td class="right"><?= billingPrintMoney($cashPay['cash_tendered']) ?></td></tr>
        <tr><td>Change (Sukli)</td><td class="right"><?= billingPrintMoney($cashPay['cash_change'] ?? 0) ?></td></tr>
        <tr><td>Payment Method</td><td class="right">Cash</td></tr>
        <tr><td>Payment Status</td><td class="right"><?= htmlspecialchars((string)($invoice['payment_status'] ?? 'Paid')) ?></td></tr>
        <?php else:
            $otherMethod = '';
            foreach ($payments as $pay) {
                $otherMethod = billingPrintSaleType($pay['payment_method'] ?? '');
                if ($otherMethod !== '') {
                    break;
                }
            }
            if ($otherMethod !== ''):
        ?>
        <tr><td>Payment Method</td><td class="right"><?= htmlspecialchars($otherMethod) ?></td></tr>
        <tr><td>Payment Status</td><td class="right"><?= htmlspecialchars((string)($invoice['payment_status'] ?? '')) ?></td></tr>
        <?php endif; endif; ?>
        <tr class="due"><td>Balance Due</td><td class="right"><?= billingPrintMoney($invoice['remaining_balance'] ?? 0) ?></td></tr>
      </table>
      <?php if ($payments): ?>
      <div class="items-wrap system-table-scroll">
        <div class="party-label" style="margin-bottom:8px">Payments applied</div>
        <table class="items">
          <thead>
            <tr>
              <th>Official Receipt</th>
              <th>Date</th>
              <th>Method</th>
              <th class="right">Amount</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($payments as $pay): ?>
            <tr>
              <td><?= htmlspecialchars($pay['payment_number']) ?></td>
              <td><?= billingPrintDate($pay['payment_date'] ?? '', 'M j, Y g:i A') ?></td>
              <td><?= htmlspecialchars($pay['payment_method']) ?><?php
                  $cst = billingPrintChequeStatus($pay['cheque_status'] ?? '');
                  echo $cst !== '' ? ' (' . htmlspecialchars($cst) . ')' : '';
              ?></td>
              <td class="right"><?= billingPrintMoney($pay['amount_paid']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <div class="signs">
        <div><div class="sign-line">Prepared by / Authorized representative</div></div>
        <div><div class="sign-line">Customer acknowledgment</div></div>
      </div>
      <div class="footer">Thank you for your business. Official Receipt is issued when payment is received.</div>
    </div>
    <?php
}

function billingRenderCustomerReceipt(array $invoice, array $items, ?array $payment): void {
    $co = billingPrintCompany();
    if (!$payment) {
        ?>
        <div class="doc">
          <div class="doc-header">
            <div>
              <div class="company-name"><?= htmlspecialchars($co['name']) ?></div>
              <div class="company-sub"><?= htmlspecialchars($co['tagline']) ?></div>
            </div>
            <div class="doc-title-block">
              <div class="doc-label">Customer Official Receipt</div>
              <div class="doc-number">Pending</div>
            </div>
          </div>
          <div class="empty-note">No official receipt yet for invoice <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>. Record Cash, Cheque, or transfer payment first, then print the customer receipt.</div>
        </div>
        <?php
        return;
    }
    $cst = billingPrintChequeStatus($payment['cheque_status'] ?? '');
    ?>
    <div class="doc">
      <div class="doc-header">
        <div>
          <div class="company-name"><?= htmlspecialchars($co['name']) ?></div>
          <div class="company-sub"><?= htmlspecialchars($co['tagline']) ?></div>
          <div class="company-detail">
            <?= htmlspecialchars($co['address']) ?><br>
            Tel: <?= htmlspecialchars($co['phone']) ?> &nbsp;|&nbsp; <?= htmlspecialchars($co['email']) ?>
          </div>
        </div>
        <div class="doc-title-block">
          <div class="doc-label">Customer Official Receipt</div>
          <div class="doc-number"><?= htmlspecialchars($payment['payment_number']) ?></div>
          <div class="doc-date">Date: <?= billingPrintDate($payment['payment_date'] ?? '', 'F j, Y g:i A') ?></div>
        </div>
      </div>
      <div class="banner receipt">This is the <strong>customer official receipt</strong> — acknowledgment of payment received. Keep this copy.</div>
      <div class="meta">
        <div class="meta-cell"><div class="meta-label">Invoice</div><div class="meta-value"><?= htmlspecialchars($invoice['invoice_number']) ?></div></div>
        <div class="meta-cell"><div class="meta-label">Sales Order</div><div class="meta-value"><?= htmlspecialchars($invoice['sales_order_number'] ?: '—') ?></div></div>
        <div class="meta-cell"><div class="meta-label">Customer PO</div><div class="meta-value"><?= htmlspecialchars(($invoice['customer_po_number'] ?? '') !== '' ? $invoice['customer_po_number'] : '—') ?></div></div>
        <div class="meta-cell"><div class="meta-label">Method</div><div class="meta-value"><?= htmlspecialchars(billingPrintPayMethod($payment['payment_method'] ?? '')) ?><?= $cst !== '' ? ' · ' . htmlspecialchars($cst) : '' ?></div></div>
      </div>
      <div class="parties">
        <div class="party">
          <div class="party-label">Received By</div>
          <div class="party-name"><?= htmlspecialchars($co['name']) ?></div>
          <div class="party-detail"><?= htmlspecialchars($co['address']) ?></div>
        </div>
        <div class="party">
          <div class="party-label">Received From (Customer)</div>
          <div class="party-name"><?= htmlspecialchars($invoice['customer_name'] ?: '—') ?></div>
          <div class="party-detail">In payment of Invoice <?= htmlspecialchars($invoice['invoice_number']) ?></div>
        </div>
      </div>
      <div class="items-wrap system-table-scroll">
        <table class="items">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Description</th>
              <th class="right">Qty</th>
              <th class="right">Amount</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
              <td><?= htmlspecialchars($item['product_name'] ?? 'Item') ?></td>
              <td class="right"><?= number_format((float)$item['quantity']) ?></td>
              <td class="right"><?= billingPrintMoney(((float)$item['quantity']) * ((float)$item['unit_price'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($payment['bank_name']) || !empty($payment['cheque_number']) || !empty($payment['notes'])): ?>
      <div class="words">
        <?php if (!empty($payment['bank_name']) || !empty($payment['cheque_number']) || !empty($payment['cheque_date'])): ?>
          Bank / Cheque: <strong><?= htmlspecialchars(trim(($payment['bank_name'] ?? '') . ' #' . ($payment['cheque_number'] ?? ''), ' #')) ?></strong><?php if (!empty($payment['cheque_date'])): ?> · Date: <?= billingPrintDate($payment['cheque_date']) ?><?php endif; ?><br>
        <?php endif; ?>
        <?php if (!empty($payment['notes'])): ?>
          Notes: <?= htmlspecialchars($payment['notes']) ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="words"><strong>Amount received in words:</strong> <?= htmlspecialchars(billingPesoInWords((float)$payment['amount_paid'])) ?></div>
      <table class="totals">
        <tr><td>Invoice total</td><td class="right"><?= billingPrintMoney($invoice['grand_total'] ?? 0) ?></td></tr>
        <tr class="paid"><td>Amount received</td><td class="right"><?= billingPrintMoney($payment['amount_paid']) ?></td></tr>
        <?php
            $payMethod = billingPrintSaleType($payment['payment_method'] ?? '');
            $tendered = $payment['cash_tendered'] ?? null;
            $change = $payment['cash_change'] ?? null;
            if ($payMethod === 'Cash' && $tendered !== null && $tendered !== ''):
        ?>
        <tr><td>Cash Tendered</td><td class="right"><?= billingPrintMoney($tendered) ?></td></tr>
        <tr class="grand"><td>Change (Sukli)</td><td class="right"><?= billingPrintMoney($change ?? 0) ?></td></tr>
        <tr><td>Payment Method</td><td class="right"><?= htmlspecialchars($payMethod) ?></td></tr>
        <tr><td>Payment Status</td><td class="right"><?= htmlspecialchars((string)($invoice['payment_status'] ?? 'Paid')) ?></td></tr>
        <?php endif; ?>
        <?php if ($payMethod !== 'Cash'): ?>
        <tr><td>Payment Method</td><td class="right"><?= htmlspecialchars($payMethod) ?></td></tr>
        <?php endif; ?>
        <tr class="due"><td>Remaining balance</td><td class="right"><?= billingPrintMoney($invoice['remaining_balance'] ?? 0) ?></td></tr>
      </table>
      <?php if ($cst === 'Pending Clearance'): ?>
      <div class="empty-note">Cheque is waiting for bank clearance and is not yet treated as paid.</div>
      <?php elseif ($cst === 'Bounced/Rejected' || $cst === 'Bounced'): ?>
      <div class="empty-note">This cheque was bounced/rejected. The invoice remains unpaid.</div>
      <?php endif; ?>
      <?php billingRenderReceiptPaymentDetails($payment); ?>
      <div class="signs">
        <div><div class="sign-line">Cashier / Authorized collector</div></div>
        <div><div class="sign-line">Customer / Received by</div></div>
      </div>
      <div class="footer">This official receipt is the customer’s proof of payment. Invoice <?= htmlspecialchars($invoice['invoice_number']) ?> remains the billing reference.</div>
    </div>
    <?php
}
