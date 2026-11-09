<?php
/**
 * Read-only Sales Report. Summarizes existing sales orders, invoices, and payments.
 * Does not insert, update, or delete sales, payment, or inventory records.
 */

function salesReportMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function salesReportPreparedBy(): string
{
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($_SESSION['username'] ?? ''));
    }
    return $name !== '' ? $name : '—';
}

function salesReportDisplayMethodLabel(string $key): string
{
    if ($key === 'Credit') {
        return 'Credit / Accounts Receivable';
    }
    return salesReportMethodLabel($key);
}

function salesReportParseDate(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

function salesReportPresetRange(string $preset): array
{
    $today = new DateTimeImmutable('today');
    switch ($preset) {
        case 'today':
            $d = $today->format('Y-m-d');
            return [$d, $d];
        case 'week':
            $from = $today->modify('monday this week');
            return [$from->format('Y-m-d'), $from->modify('+6 days')->format('Y-m-d')];
        case 'month':
            return [$today->format('Y-m-01'), $today->format('Y-m-t')];
        case 'year':
            return [$today->format('Y-01-01'), $today->format('Y-12-31')];
        default:
            return [null, null];
    }
}

function salesReportReadFilters(array $src): array
{
    $preset = trim((string)($src['sr_preset'] ?? ''));
    $allowedPreset = ['', 'today', 'week', 'month', 'year', 'custom'];
    if (!in_array($preset, $allowedPreset, true)) {
        $preset = '';
    }
    $from = salesReportParseDate($src['sr_from'] ?? null);
    $to = salesReportParseDate($src['sr_to'] ?? null);
    if (in_array($preset, ['today', 'week', 'month', 'year'], true)) {
        [$from, $to] = salesReportPresetRange($preset);
    }
    if ($preset !== 'custom') {
        if ($preset === '') {
            $from = null;
            $to = null;
        }
    }
    if ($from && $to && $from > $to) {
        $tmp = $from;
        $from = $to;
        $to = $tmp;
    }

    $method = trim((string)($src['sr_method'] ?? ''));
    $allowedMethod = ['', 'Cash', 'Credit', 'Cheque', 'GCash', 'Maya', 'Bank Transfer'];
    if (!in_array($method, $allowedMethod, true)) {
        $method = '';
    }

    $status = trim((string)($src['sr_status'] ?? ''));
    $allowedStatus = ['', 'Paid', 'Partially Paid', 'Unpaid / Outstanding', 'Pending', 'Cancelled'];
    if (!in_array($status, $allowedStatus, true)) {
        $status = '';
    }

    $page = (int)($src['sr_page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }

    return [
        'preset' => $preset,
        'from' => $from,
        'to' => $to,
        'method' => $method,
        'status' => $status,
        'customer' => trim((string)($src['sr_customer'] ?? '')),
        'product_id' => (int)($src['sr_product'] ?? 0),
        'created_by' => (int)($src['sr_user'] ?? 0),
        'search' => trim((string)($src['sr_search'] ?? '')),
        'page' => $page,
        'page_size' => 10,
    ];
}

function salesReportMethodKey(string $saleType): string
{
    if (function_exists('billingNormalizeSaleType')) {
        return billingNormalizeSaleType($saleType);
    }
    $t = strtolower(trim($saleType));
    if ($t === 'credit') return 'Credit';
    if ($t === 'cheque' || $t === 'check') return 'Cheque';
    if ($t === 'gcash') return 'GCash';
    if ($t === 'maya' || $t === 'paymaya') return 'Maya';
    if ($t === 'bank transfer') return 'Bank Transfer';
    return 'Cash';
}

function salesReportMethodLabel(string $saleType): string
{
    $key = salesReportMethodKey($saleType);
    if (function_exists('billingSaleTypeLabel')) {
        return billingSaleTypeLabel($key);
    }
    return $key === 'Credit' ? 'Credit / Utang' : $key;
}

function salesReportMethods(): array
{
    return ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Cheque', 'Credit'];
}

function salesReportRowStatus(array $row): string
{
    $workflow = function_exists('salesOrderDisplayStatus')
        ? salesOrderDisplayStatus($row)
        : (string)($row['status'] ?? 'Pending');
    if ($workflow === 'Cancelled' || (string)($row['status'] ?? '') === 'Cancelled') {
        return 'Cancelled';
    }
    if ($workflow === 'Cheque Rejected') {
        return 'Cheque Rejected';
    }
    if ($workflow === 'Pending') {
        return 'Pending';
    }
    $pay = trim((string)($row['payment_status'] ?? ''));
    if ($pay === 'Paid' || $pay === 'Partially Paid') {
        return $pay;
    }
    if ($pay === '' && function_exists('billingSaleTypeIsImmediatePaid') && billingSaleTypeIsImmediatePaid($row['sale_type'] ?? '') && (string)($row['status'] ?? '') === 'Approved') {
        return 'Paid';
    }
    return 'Unpaid / Outstanding';
}

function salesReportAmounts(array $row, string $status): array
{
    $grand = round((float)($row['grand_total'] ?? 0), 2);
    $paid = round((float)($row['payments_sum'] ?? 0), 2);
    $hasInvoice = (int)($row['invoice_id'] ?? 0) > 0;
    $remaining = $hasInvoice ? round((float)($row['remaining_balance'] ?? 0), 2) : null;
    if ($status === 'Cancelled') {
        return ['paid' => 0.0, 'outstanding' => 0.0];
    }
    if ($status === 'Paid') {
        if ($paid <= 0) {
            $paid = $grand;
        }
        return ['paid' => $paid, 'outstanding' => 0.0];
    }
    if ($status === 'Partially Paid') {
        if ($remaining !== null) {
            $paid = round(max(0, $grand - $remaining), 2);
            return ['paid' => $paid, 'outstanding' => round(max(0, $remaining), 2)];
        }
        return ['paid' => $paid, 'outstanding' => round(max(0, $grand - $paid), 2)];
    }
    if ($status === 'Unpaid / Outstanding') {
        $out = $remaining !== null ? max(0, $remaining) : $grand;
        return ['paid' => $paid, 'outstanding' => round($out, 2)];
    }
    return ['paid' => $paid, 'outstanding' => round(max(0, $grand - $paid), 2)];
}

function salesReportIsCompleted(array $row, string $status): bool
{
    return (string)($row['status'] ?? '') === 'Approved'
        && !in_array($status, ['Pending', 'Cancelled', 'Cheque Rejected'], true);
}

function salesReportComposeAnalysis(array $rows, bool $includeVoided = false): array
{
    $gross = 0.0;
    $discounts = 0.0;
    $net = 0.0;
    $transactions = 0;
    $itemsSold = 0;
    $methods = [];
    foreach (salesReportMethods() as $key) {
        $methods[$key] = [
            'key' => $key,
            'label' => salesReportDisplayMethodLabel($key),
            'transactions' => 0,
            'amount' => 0.0,
        ];
    }
    $products = [];
    $categories = [];
    $detailRows = [];

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        if (!$includeVoided && ($status === 'Cancelled' || $status === 'Cheque Rejected')) {
            continue;
        }
        $lines = $row['items'] ?? [];
        $lineGrosses = [];
        $lineSum = 0.0;
        foreach ($lines as $i => $item) {
            $lineGross = round((float)($item['line_total'] ?? 0), 2);
            if ($lineGross <= 0) {
                $lineGross = round((int)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0), 2);
            }
            $lineGrosses[$i] = $lineGross;
            $lineSum += $lineGross;
        }
        $lineSum = round($lineSum, 2);
        $orderGross = $lineSum > 0 ? $lineSum : round((float)($row['subtotal'] ?? 0), 2);
        $orderDiscount = round(max(0, (float)($row['discount'] ?? 0)), 2);
        if ($orderGross > 0 && $orderDiscount > $orderGross) {
            $orderDiscount = $orderGross;
        }
        $orderNet = round($orderGross - $orderDiscount, 2);
        $orderTax = round(max(0, (float)($row['tax'] ?? 0)), 2);
        $orderDelivery = round(max(0, (float)($row['delivery_fee'] ?? 0)), 2);
        $orderGrand = round((float)($row['grand_total'] ?? 0), 2);

        $gross += $orderGross;
        $discounts += $orderDiscount;
        $net += $orderNet;
        $transactions++;

        $methodKey = (string)($row['method_key'] ?? 'Cash');
        if (!isset($methods[$methodKey])) {
            $methods[$methodKey] = [
                'key' => $methodKey,
                'label' => salesReportDisplayMethodLabel($methodKey),
                'transactions' => 0,
                'amount' => 0.0,
            ];
        }
        $methods[$methodKey]['transactions']++;
        $methods[$methodKey]['amount'] += $orderNet;

        $allocated = [];
        $count = count($lines);
        if ($count === 0 && ($orderGross > 0 || $orderDiscount > 0)) {
            $allocated[] = [
                'name' => 'Unspecified',
                'brand' => '',
                'unit' => '',
                'category' => 'Uncategorized',
                'product_id' => 0,
                'quantity' => 0,
                'unit_price' => 0.0,
                'gross' => $orderGross,
                'discount' => $orderDiscount,
                'tax' => $orderTax,
                'delivery_fee' => $orderDelivery,
                'net' => $orderNet,
            ];
        } else {
            $remainingDiscount = $orderDiscount;
            $remainingTax = $orderTax;
            $remainingDelivery = $orderDelivery;
            foreach ($lines as $i => $item) {
                $qty = (int)($item['quantity'] ?? 0);
                $itemsSold += $qty;
                $lineGross = $lineGrosses[$i];
                if ($i === $count - 1) {
                    $lineDiscount = $remainingDiscount;
                    $lineTax = $remainingTax;
                    $lineDelivery = $remainingDelivery;
                } else {
                    $share = $lineSum > 0 ? ($lineGross / $lineSum) : (1 / $count);
                    $lineDiscount = round($orderDiscount * $share, 2);
                    $lineTax = round($orderTax * $share, 2);
                    $lineDelivery = round($orderDelivery * $share, 2);
                    $remainingDiscount = round($remainingDiscount - $lineDiscount, 2);
                    $remainingTax = round($remainingTax - $lineTax, 2);
                    $remainingDelivery = round($remainingDelivery - $lineDelivery, 2);
                }
                $name = trim((string)($item['name'] ?? ''));
                if ($name === '') {
                    $name = 'Unspecified';
                }
                $category = trim((string)($item['category'] ?? ''));
                if ($category === '') {
                    $category = 'Uncategorized';
                }
                $allocated[] = [
                    'name' => $name,
                    'brand' => trim((string)($item['brand'] ?? '')),
                    'unit' => trim((string)($item['unit'] ?? '')) !== '' ? trim((string)$item['unit']) : 'pcs',
                    'category' => $category,
                    'product_id' => (int)($item['product_id'] ?? 0),
                    'quantity' => $qty,
                    'unit_price' => round((float)($item['unit_price'] ?? 0), 2),
                    'gross' => $lineGross,
                    'discount' => $lineDiscount,
                    'tax' => $lineTax,
                    'delivery_fee' => $lineDelivery,
                    'net' => round($lineGross - $lineDiscount, 2),
                ];
            }
        }

        foreach ($allocated as $line) {
            $pkey = ((int)$line['product_id'] > 0 ? (string)$line['product_id'] : $line['name']) . '|' . $line['category'];
            if (!isset($products[$pkey])) {
                $products[$pkey] = [
                    'name' => $line['name'],
                    'category' => $line['category'],
                    'quantity' => 0,
                    'gross' => 0.0,
                    'discount' => 0.0,
                    'net' => 0.0,
                    'unit_price' => $line['unit_price'],
                    'mixed_price' => false,
                ];
            } elseif (abs($products[$pkey]['unit_price'] - $line['unit_price']) > 0.001) {
                $products[$pkey]['mixed_price'] = true;
            }
            $products[$pkey]['quantity'] += (int)$line['quantity'];
            $products[$pkey]['gross'] += (float)$line['gross'];
            $products[$pkey]['discount'] += (float)$line['discount'];
            $products[$pkey]['net'] += (float)$line['net'];

            $ckey = $line['category'];
            if (!isset($categories[$ckey])) {
                $categories[$ckey] = ['category' => $ckey, 'net' => 0.0, 'percent' => 0.0];
            }
            $categories[$ckey]['net'] += (float)$line['net'];
        }

        $runningTotal = 0.0;
        $lastLine = count($allocated) - 1;
        foreach ($allocated as $i => $line) {
            $lineTotal = round((float)$line['gross'] - (float)$line['discount'] + (float)($line['tax'] ?? 0) + (float)($line['delivery_fee'] ?? 0), 2);
            if ($i === $lastLine) {
                $lineTotal = round($orderGrand - $runningTotal, 2);
            } else {
                $runningTotal = round($runningTotal + $lineTotal, 2);
            }
            $allocated[$i]['total'] = $lineTotal;
        }

        $invoiceNo = trim((string)($row['invoice_number'] ?? ''));
        if ($invoiceNo === '') {
            $invoiceNo = (string)($row['order_number'] ?? '');
        }
        $paymentLabel = salesReportDisplayMethodLabel((string)($row['method_key'] ?? 'Cash'));
        $showCash = (string)($row['method_key'] ?? '') === 'Cash' && isset($row['cash_tendered']) && $row['cash_tendered'] !== null && $row['cash_tendered'] !== '';
        $firstCashLine = true;
        foreach ($allocated as $line) {
            $detailRows[] = [
                'date' => (string)($row['sale_date_label'] ?? ''),
                'invoice' => $invoiceNo,
                'transaction_number' => $invoiceNo,
                'product' => (string)$line['name'],
                'brand' => (string)($line['brand'] ?? ''),
                'unit' => (string)($line['unit'] ?? ''),
                'qty' => (int)$line['quantity'],
                'unit_price' => round((float)$line['unit_price'], 2),
                'gross' => round((float)$line['gross'], 2),
                'tax' => round((float)($line['tax'] ?? 0), 2),
                'discount' => round((float)$line['discount'], 2),
                'delivery_fee' => round((float)($line['delivery_fee'] ?? 0), 2),
                'total' => round((float)($line['total'] ?? $line['net']), 2),
                'net' => round((float)$line['net'], 2),
                'payment' => $paymentLabel,
                'payment_status' => $status,
                'cash_tendered' => ($showCash && $firstCashLine) ? round((float)$row['cash_tendered'], 2) : null,
                'cash_change' => ($showCash && $firstCashLine) ? round((float)($row['cash_change'] ?? 0), 2) : null,
            ];
            $firstCashLine = false;
        }
    }

    $gross = round($gross, 2);
    $discounts = round($discounts, 2);
    $net = round($net, 2);
    $average = $transactions > 0 ? round($net / $transactions, 2) : 0.0;

    $payment = [];
    foreach (salesReportMethods() as $key) {
        if (!isset($methods[$key])) {
            continue;
        }
        $methods[$key]['amount'] = round($methods[$key]['amount'], 2);
        $payment[] = $methods[$key];
    }
    foreach ($methods as $key => $method) {
        if (!in_array($key, salesReportMethods(), true)) {
            $method['amount'] = round($method['amount'], 2);
            $payment[] = $method;
        }
    }

    $productRows = [];
    foreach ($products as $product) {
        $qty = (int)$product['quantity'];
        $productGross = round($product['gross'], 2);
        $unit = $product['mixed_price']
            ? ($qty > 0 ? round($productGross / $qty, 2) : 0.0)
            : round((float)$product['unit_price'], 2);
        $productRows[] = [
            'name' => $product['name'],
            'quantity' => $qty,
            'unit_price' => $unit,
            'gross' => $productGross,
            'discount' => round($product['discount'], 2),
            'net' => round($product['net'], 2),
        ];
    }
    usort($productRows, static function ($a, $b) {
        $cmp = $b['net'] <=> $a['net'];
        return $cmp !== 0 ? $cmp : strcasecmp($a['name'], $b['name']);
    });

    $categoryRows = array_values($categories);
    foreach ($categoryRows as &$category) {
        $category['net'] = round($category['net'], 2);
        $category['percent'] = abs($net) < 0.00001 ? 0.0 : round($category['net'] / $net * 100, 2);
    }
    unset($category);
    usort($categoryRows, static function ($a, $b) {
        $cmp = $b['net'] <=> $a['net'];
        return $cmp !== 0 ? $cmp : strcasecmp($a['category'], $b['category']);
    });
    if (abs($net) >= 0.00001 && $categoryRows) {
        $sumPct = 0.0;
        $largest = 0;
        foreach ($categoryRows as $i => $category) {
            $sumPct += $category['percent'];
            if ($category['net'] > $categoryRows[$largest]['net']) {
                $largest = $i;
            }
        }
        $diff = round(100 - $sumPct, 2);
        if (abs($diff) >= 0.01) {
            $categoryRows[$largest]['percent'] = round($categoryRows[$largest]['percent'] + $diff, 2);
        }
    }

    return [
        'performance' => ['gross' => $gross, 'discounts' => $discounts, 'net' => $net],
        'payment_summary' => $payment,
        'product_summary' => $productRows,
        'detail_rows' => $detailRows,
        'category_summary' => $categoryRows,
        'transaction_summary' => [
            'transactions' => $transactions,
            'items_sold' => $itemsSold,
            'average' => $average,
        ],
    ];
}

function salesReportEmpty(): array
{
    $methods = [];
    foreach (salesReportMethods() as $key) {
        $methods[] = [
            'key' => $key,
            'label' => salesReportDisplayMethodLabel($key),
            'transactions' => 0,
            'amount' => 0.0,
        ];
    }
    return [
        'rows' => [],
        'page_rows' => [],
        'summary' => [
            'total_sales' => 0.0,
            'transactions' => 0,
            'items_sold' => 0,
            'paid_sales' => 0.0,
            'partial_sales' => 0.0,
            'outstanding' => 0.0,
            'cancelled' => 0,
        ],
        'method_cards' => $methods,
        'payment_summary' => $methods,
        'product_summary' => [],
        'detail_rows' => [],
        'category_summary' => [],
        'performance' => ['gross' => 0.0, 'discounts' => 0.0, 'net' => 0.0],
        'transaction_summary' => ['transactions' => 0, 'items_sold' => 0, 'average' => 0.0],
        'prepared_by' => salesReportPreparedBy(),
        'customers' => [],
        'products' => [],
        'creators' => [],
        'total_rows' => 0,
        'total_pages' => 1,
        'page' => 1,
        'range_label' => 'All Dates',
        'filter_label' => 'Payment Method: All · Sales Status: All',
        'generated' => date('F j, Y'),
    ];
}

function salesReportFilterLabel(array $filters): string
{
    $method = trim((string)($filters['method'] ?? ''));
    $status = trim((string)($filters['status'] ?? ''));
    $methodLabel = $method !== '' ? salesReportDisplayMethodLabel($method) : 'All';
    $statusLabel = $status !== '' ? $status : 'All';
    return 'Payment Method: ' . $methodLabel . ' · Sales Status: ' . $statusLabel;
}

function salesReportRangeLabel(array $filters): string
{
    $map = [
        'today' => 'Today',
        'week' => 'This Week',
        'month' => 'This Month',
        'year' => 'This Year',
        'custom' => 'Custom Date Range',
    ];
    $preset = (string)($filters['preset'] ?? '');
    $label = $map[$preset] ?? 'All Dates';
    if (!empty($filters['from']) && !empty($filters['to'])) {
        $label .= ' (' . $filters['from'] . ' to ' . $filters['to'] . ')';
    }
    return $label;
}

function salesReportLoad(mysqli $conn, array $filters): array
{
    $empty = salesReportEmpty();
    $empty['range_label'] = salesReportRangeLabel($filters);
    $empty['filter_label'] = salesReportFilterLabel($filters);
    $table = $conn->query("SHOW TABLES LIKE 'sales_orders'");
    if (!($table && $table->num_rows > 0)) {
        return $empty;
    }

    $orders = [];
    $res = $conn->query("SELECT so.id, so.order_number, so.customer_name, so.subtotal, so.discount_amount, so.tax_amount,
            so.delivery_fee, so.grand_total, so.status, so.sale_type, so.payment_due_date, so.created_at, so.created_by,
            so.cheque_status, so.tax_rate,
            COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), '—') AS creator_name
        FROM sales_orders so
        LEFT JOIN users u ON u.id = so.created_by
        ORDER BY so.created_at DESC, so.id DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $orders[(int)$row['id']] = $row;
        }
    }
    if (!$orders) {
        return $empty;
    }

    $invoices = [];
    $inv = $conn->query("SELECT id, sales_order_id, invoice_number, remaining_balance, payment_status, payment_due_date, grand_total
        FROM invoices WHERE sales_order_id IS NOT NULL AND sales_order_id > 0 ORDER BY id ASC");
    if ($inv) {
        while ($row = $inv->fetch_assoc()) {
            $invoices[(int)$row['sales_order_id']] = $row;
        }
    }

    $paymentsByInvoice = [];
    $cashSelect = '';
    $cashCol = $conn->query("SHOW COLUMNS FROM invoice_payments LIKE 'cash_tendered'");
    if ($cashCol && $cashCol->num_rows > 0) {
        $cashSelect = ', cash_tendered, cash_change';
    }
    $pay = $conn->query("SELECT invoice_id, payment_number, amount_paid, payment_method, payment_date, notes{$cashSelect} FROM invoice_payments ORDER BY id ASC");
    if ($pay) {
        while ($row = $pay->fetch_assoc()) {
            $paymentsByInvoice[(int)$row['invoice_id']][] = $row;
        }
    }

    $hasCategory = false;
    $cat = $conn->query("SHOW TABLES LIKE 'categories'");
    $hasCategory = ($cat && $cat->num_rows > 0);
    $brandExpr = "''";
    $brandCol = $conn->query("SHOW COLUMNS FROM products LIKE 'brand'");
    if ($brandCol && $brandCol->num_rows > 0) {
        $brandExpr = "COALESCE(p.brand, '')";
    }
    $unitExpr = "'pcs'";
    $unitCol = $conn->query("SHOW COLUMNS FROM products LIKE 'unit_of_measure'");
    if ($unitCol && $unitCol->num_rows > 0) {
        $unitExpr = "COALESCE(NULLIF(TRIM(p.unit_of_measure), ''), 'pcs')";
    }
    $itemSql = $hasCategory
        ? "SELECT soi.sales_order_id, soi.product_id, soi.quantity, soi.unit_price,
                COALESCE(p.sku, '') AS sku, COALESCE(p.name, '') AS product_name, COALESCE(c.name, '') AS category,
                {$brandExpr} AS brand, {$unitExpr} AS unit
           FROM sales_order_items soi
           LEFT JOIN products p ON p.id = soi.product_id
           LEFT JOIN categories c ON c.id = p.category_id"
        : "SELECT soi.sales_order_id, soi.product_id, soi.quantity, soi.unit_price,
                COALESCE(p.sku, '') AS sku, COALESCE(p.name, '') AS product_name, '' AS category,
                {$brandExpr} AS brand, {$unitExpr} AS unit
           FROM sales_order_items soi
           LEFT JOIN products p ON p.id = soi.product_id";
    $itemsByOrder = [];
    $itemRes = $conn->query($itemSql);
    if ($itemRes) {
        while ($row = $itemRes->fetch_assoc()) {
            $itemsByOrder[(int)$row['sales_order_id']][] = $row;
        }
    }

    $customers = [];
    $products = [];
    $creators = [];
    $built = [];
    foreach ($orders as $id => $order) {
        $invoice = $invoices[$id] ?? null;
        $invoiceId = $invoice ? (int)$invoice['id'] : 0;
        $payments = $invoiceId > 0 ? ($paymentsByInvoice[$invoiceId] ?? []) : [];
        $paymentsSum = 0.0;
        $paymentRows = [];
        $cashTendered = null;
        $cashChange = null;
        foreach ($payments as $payment) {
            $paymentsSum += (float)$payment['amount_paid'];
            if (array_key_exists('cash_tendered', $payment) && $payment['cash_tendered'] !== null && $payment['cash_tendered'] !== ''
                && salesReportMethodKey((string)($payment['payment_method'] ?? '')) === 'Cash') {
                $cashTendered = round((float)$payment['cash_tendered'], 2);
                $cashChange = round((float)($payment['cash_change'] ?? 0), 2);
            }
            $paymentRows[] = [
                'number' => (string)($payment['payment_number'] ?? ''),
                'amount' => round((float)$payment['amount_paid'], 2),
                'method' => salesReportMethodLabel((string)($payment['payment_method'] ?? $order['sale_type'])),
                'date' => salesReportFormatDate((string)($payment['payment_date'] ?? '')),
                'notes' => (string)($payment['notes'] ?? ''),
            ];
        }
        $order['invoice_id'] = $invoiceId;
        $order['invoice_number'] = $invoice ? (string)$invoice['invoice_number'] : '';
        $order['payment_status'] = $invoice ? (string)$invoice['payment_status'] : '';
        $order['remaining_balance'] = $invoice ? (float)$invoice['remaining_balance'] : null;
        $order['payments_sum'] = round($paymentsSum, 2);
        if ($invoice && !empty($invoice['payment_due_date'])) {
            $order['payment_due_date'] = $invoice['payment_due_date'];
        }
        $status = salesReportRowStatus($order);
        $amounts = salesReportAmounts($order, $status);
        $itemRows = [];
        $labels = [];
        foreach ($itemsByOrder[$id] ?? [] as $item) {
            $qty = (int)$item['quantity'];
            $price = round((float)$item['unit_price'], 2);
            $name = (string)$item['product_name'];
            if ($name === '') {
                $name = 'Product #' . (int)$item['product_id'];
            }
            $labels[] = $name . ' x ' . $qty;
            $itemRows[] = [
                'product_id' => (int)$item['product_id'],
                'sku' => (string)$item['sku'],
                'name' => $name,
                'category' => (string)$item['category'],
                'quantity' => $qty,
                'unit_price' => $price,
                'brand' => (string)($item['brand'] ?? ''),
                'unit' => (string)($item['unit'] ?? 'pcs'),
                'line_total' => round($qty * $price, 2),
            ];
            if ((int)$item['product_id'] > 0) {
                $products[(int)$item['product_id']] = [
                    'id' => (int)$item['product_id'],
                    'sku' => (string)$item['sku'],
                    'name' => $name,
                ];
            }
        }
        $customer = trim((string)$order['customer_name']);
        if ($customer !== '' && $customer !== '0') {
            $customers[$customer] = $customer;
        }
        $creatorId = (int)$order['created_by'];
        if ($creatorId > 0) {
            $creators[$creatorId] = (string)$order['creator_name'];
        }
        $built[] = [
            'id' => $id,
            'order_number' => (string)$order['order_number'],
            'sale_date' => (string)$order['created_at'],
            'sale_date_label' => salesReportFormatDate((string)$order['created_at']),
            'due_date_label' => salesReportFormatDate((string)($order['payment_due_date'] ?? '')),
            'customer_name' => $customer,
            'items_label' => $labels ? implode(', ', $labels) : '—',
            'items' => $itemRows,
            'invoice_number' => trim((string)($order['invoice_number'] ?? '')),
            'method_key' => salesReportMethodKey((string)$order['sale_type']),
            'method_label' => salesReportDisplayMethodLabel(salesReportMethodKey((string)$order['sale_type'])),
            'subtotal' => round((float)$order['subtotal'], 2),
            'discount' => round((float)$order['discount_amount'], 2),
            'tax' => round((float)$order['tax_amount'], 2),
            'delivery_fee' => round((float)$order['delivery_fee'], 2),
            'grand_total' => round((float)$order['grand_total'], 2),
            'amount_paid' => $amounts['paid'],
            'outstanding' => $amounts['outstanding'],
            'due_date' => (string)($order['payment_due_date'] ?? ''),
            'created_by' => $creatorId,
            'creator_name' => (string)$order['creator_name'],
            'status' => $status,
            'order_status' => (string)$order['status'],
            'payments' => $paymentRows,
            'cash_tendered' => $cashTendered,
            'cash_change' => $cashChange,
        ];
    }

    $filtered = [];
    $search = strtolower((string)$filters['search']);
    foreach ($built as $row) {
        $day = substr((string)$row['sale_date'], 0, 10);
        if (!empty($filters['from']) && $day < $filters['from']) continue;
        if (!empty($filters['to']) && $day > $filters['to']) continue;
        if ($filters['method'] !== '' && $row['method_key'] !== $filters['method']) continue;
        if ($filters['status'] !== '' && $row['status'] !== $filters['status']) continue;
        if ($filters['customer'] !== '' && $row['customer_name'] !== $filters['customer']) continue;
        if ((int)$filters['created_by'] > 0 && (int)$row['created_by'] !== (int)$filters['created_by']) continue;
        if ((int)$filters['product_id'] > 0) {
            $hit = false;
            foreach ($row['items'] as $item) {
                if ((int)$item['product_id'] === (int)$filters['product_id']) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) continue;
        }
        if ($search !== '') {
            $hay = strtolower($row['order_number'] . ' ' . $row['customer_name'] . ' ' . $row['items_label'] . ' ' . $row['creator_name'] . ' ' . $row['method_label'] . ' ' . $row['status']);
            if (strpos($hay, $search) === false) continue;
        }
        $filtered[] = $row;
    }

    $includeVoided = in_array((string)($filters['status'] ?? ''), ['Cancelled', 'Cheque Rejected'], true);
    $analysis = salesReportComposeAnalysis($filtered, $includeVoided);
    $returns = salesReportReturns($conn, $filters);
    $analysis['performance']['returns'] = $returns['refunds'];
    $analysis['performance']['returns_count'] = $returns['count'];
    $analysis['performance']['net_after_returns'] = round((float)$analysis['performance']['net'] - $returns['refunds'], 2);
    $summary = $empty['summary'];
    $summary['total_sales'] = $analysis['performance']['net'];
    $summary['transactions'] = $analysis['transaction_summary']['transactions'];
    $summary['items_sold'] = $analysis['transaction_summary']['items_sold'];

    $pageSize = max(1, (int)$filters['page_size']);
    $totalRows = count($filtered);
    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    $page = min(max(1, (int)$filters['page']), $totalPages);
    natcasesort($customers);
    uasort($products, static function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    asort($creators);

    return [
        'rows' => $filtered,
        'page_rows' => array_slice($filtered, ($page - 1) * $pageSize, $pageSize),
        'summary' => $summary,
        'method_cards' => $analysis['payment_summary'],
        'payment_summary' => $analysis['payment_summary'],
        'product_summary' => $analysis['product_summary'],
        'detail_rows' => $analysis['detail_rows'],
        'category_summary' => $analysis['category_summary'],
        'performance' => $analysis['performance'],
        'transaction_summary' => $analysis['transaction_summary'],
        'prepared_by' => salesReportPreparedBy(),
        'customers' => array_values($customers),
        'products' => array_values($products),
        'creators' => $creators,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'page' => $page,
        'range_label' => salesReportRangeLabel($filters),
        'filter_label' => salesReportFilterLabel($filters),
        'generated' => date('F j, Y'),
    ];
}

/** Refunds from completed customer returns, dated by approval, within the report's date range. */
function salesReportReturns(mysqli $conn, array $filters): array
{
    $out = ['count' => 0, 'refunds' => 0.0];
    $t = $conn->query("SHOW TABLES LIKE 'sales_returns'");
    if (!($t && $t->num_rows > 0)) {
        return $out;
    }
    $where = "status = 'Completed'";
    $from = (string)($filters['from'] ?? '');
    $to = (string)($filters['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where .= " AND DATE(reviewed_at) >= '" . $from . "'";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where .= " AND DATE(reviewed_at) <= '" . $to . "'";
    }
    $q = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(refund_amount), 0) AS r FROM sales_returns WHERE $where");
    if ($q && ($row = $q->fetch_assoc())) {
        $out = ['count' => (int)$row['c'], 'refunds' => round((float)$row['r'], 2)];
    }
    return $out;
}

function salesReportQueryString(array $filters, array $overrides = []): string
{
    $merged = array_merge($filters, $overrides);
    $q = [
        'open' => 'sales-reports',
        'sr_preset' => $merged['preset'] ?? '',
        'sr_from' => ($merged['preset'] ?? '') === 'custom' ? ($merged['from'] ?? '') : '',
        'sr_to' => ($merged['preset'] ?? '') === 'custom' ? ($merged['to'] ?? '') : '',
        'sr_method' => $merged['method'] ?? '',
        'sr_status' => $merged['status'] ?? '',
        'sr_customer' => $merged['customer'] ?? '',
        'sr_product' => (int)($merged['product_id'] ?? 0) > 0 ? (int)$merged['product_id'] : '',
        'sr_user' => (int)($merged['created_by'] ?? 0) > 0 ? (int)$merged['created_by'] : '',
        'sr_search' => $merged['search'] ?? '',
        'sr_page' => (int)($merged['page'] ?? 1),
    ];
    return http_build_query(array_filter($q, static function ($v) {
        return $v !== '' && $v !== null && $v !== 0;
    }));
}

function salesReportStatusBadge(string $status): string
{
    $class = 'badge-gray';
    if ($status === 'Paid') $class = 'badge-green';
    elseif ($status === 'Partially Paid') $class = 'badge-orange';
    elseif ($status === 'Unpaid / Outstanding') $class = 'badge-danger';
    elseif ($status === 'Pending') $class = 'badge-warning';
    elseif ($status === 'Cancelled' || $status === 'Cheque Rejected') $class = 'badge-danger';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function salesReportFormatDate(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y', $ts) : '—';
}

function salesReportMaybeExport(array $report, bool $canExport): void
{
    $kind = strtolower(trim((string)($_GET['sr_export'] ?? '')));
    if ($kind === '' || $kind === '0') {
        return;
    }
    if (!$canExport || !in_array($kind, ['csv', 'xlsx', 'pdf'], true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'You do not have permission to export Sales Reports.';
        exit();
    }
    $stamp = date('Y-m-d');
    if ($kind === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sales_report_' . $stamp . '.csv"');
        echo salesReportCsv($report);
        exit();
    }
    if ($kind === 'xlsx') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="sales_report_' . $stamp . '.xlsx"');
        echo salesReportXlsx($report);
        exit();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="sales_report_' . $stamp . '.pdf"');
    echo salesReportPdf($report);
    exit();
}

function salesReportCsv(array $report): string
{
    $fh = fopen('php://temp', 'r+');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
    $perf = $report['performance'] ?? ['gross' => 0, 'discounts' => 0, 'net' => 0];
    fputcsv($fh, ['Monthly Sales Report']);
    fputcsv($fh, ['Date', 'Invoice No.', 'Product', 'Qty', 'Unit Price', 'Gross Sales', 'Discount', 'Net Sales', 'Payment']);
    foreach ($report['detail_rows'] ?? [] as $row) {
        fputcsv($fh, [
            $row['date'],
            $row['invoice'],
            $row['product'],
            (int)$row['qty'],
            salesReportMoney((float)$row['unit_price']),
            salesReportMoney((float)$row['gross']),
            salesReportMoney((float)$row['discount']),
            salesReportMoney((float)$row['net']),
            $row['payment'],
        ]);
    }
    fputcsv($fh, []);
    fputcsv($fh, ['Sales by Product']);
    fputcsv($fh, ['Product', 'Quantity Sold', 'Unit Selling Price', 'Gross Sales', 'Discounts', 'Net Sales']);
    $qty = 0;
    $gross = 0.0;
    $disc = 0.0;
    $net = 0.0;
    foreach ($report['product_summary'] ?? [] as $row) {
        $qty += (int)$row['quantity'];
        $gross += (float)$row['gross'];
        $disc += (float)$row['discount'];
        $net += (float)$row['net'];
        fputcsv($fh, [
            $row['name'],
            (int)$row['quantity'],
            salesReportMoney((float)$row['unit_price']),
            salesReportMoney((float)$row['gross']),
            salesReportMoney((float)$row['discount']),
            salesReportMoney((float)$row['net']),
        ]);
    }
    fputcsv($fh, ['TOTAL', $qty, '', salesReportMoney($gross), salesReportMoney($disc), salesReportMoney($net)]);
    fputcsv($fh, []);
    fputcsv($fh, ['Sales by Payment Method']);
    fputcsv($fh, ['Payment Method', 'Number of Transactions', 'Amount']);
    $payTx = 0;
    $payAmt = 0.0;
    foreach ($report['payment_summary'] ?? [] as $row) {
        $payTx += (int)$row['transactions'];
        $payAmt += (float)($row['amount'] ?? 0);
        fputcsv($fh, [$row['label'], (int)$row['transactions'], salesReportMoney((float)($row['amount'] ?? 0))]);
    }
    fputcsv($fh, ['TOTAL', $payTx, salesReportMoney($payAmt)]);
    fputcsv($fh, []);
    fputcsv($fh, ['Sales Performance Summary']);
    fputcsv($fh, ['Metric', 'Amount']);
    fputcsv($fh, ['Gross Sales', salesReportMoney((float)$perf['gross'])]);
    fputcsv($fh, ['Total Discounts', salesReportMoney((float)$perf['discounts'])]);
    fputcsv($fh, ['Net Sales', salesReportMoney((float)$perf['net'])]);
    fputcsv($fh, []);
    fputcsv($fh, ['Sales Report by Category']);
    fputcsv($fh, ['Category', 'Net Sales', 'Percentage of Total Sales']);
    $catNet = 0.0;
    $catPct = 0.0;
    foreach ($report['category_summary'] ?? [] as $row) {
        $catNet += (float)$row['net'];
        $catPct += (float)$row['percent'];
        fputcsv($fh, [$row['category'], salesReportMoney((float)$row['net']), number_format((float)$row['percent'], 2) . '%']);
    }
    if (abs((float)$perf['net']) >= 0.00001) {
        $catPct = 100.0;
    }
    fputcsv($fh, ['TOTAL', salesReportMoney($catNet), number_format($catPct, 2) . '%']);
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv === false ? '' : $csv;
}

function salesReportExcelPeriod(array $report): string
{
    $label = trim((string)($report['range_label'] ?? ''));
    if ($label === '' || strcasecmp($label, 'All Dates') === 0) {
        return 'All Dates';
    }
    if (preg_match('/\((\d{4}-\d{2}-\d{2}) to (\d{4}-\d{2}-\d{2})\)/', $label, $match)) {
        $from = strtotime($match[1]);
        $to = strtotime($match[2]);
        if ($from && $to) {
            if (date('Y-m-d', $from) === date('Y-m-d', $to)) {
                return date('F j, Y', $from);
            }
            if (date('Y', $from) === date('Y', $to) && date('n', $from) === date('n', $to)) {
                return date('F j', $from) . '–' . date('j, Y', $to);
            }
            if (date('Y', $from) === date('Y', $to)) {
                return date('F j', $from) . '–' . date('F j, Y', $to);
            }
            return date('F j, Y', $from) . '–' . date('F j, Y', $to);
        }
    }
    return $label;
}

function salesReportXlsx(array $report): string
{
    if (!function_exists('inventoryReportZipStore')) {
        require_once __DIR__ . '/inventory_report_lib.php';
    }
    $perf = $report['performance'] ?? ['gross' => 0, 'discounts' => 0, 'net' => 0];
    $rowsXml = '';
    $r = 1;
    $put = static function (int $rowNum, array $cells, int $style = 0) use (&$rowsXml) {
        $cols = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P'];
        $xml = '';
        foreach ($cells as $i => $text) {
            $xml .= inventoryReportCell($cols[$i] . $rowNum, (string)$text, $style);
        }
        $rowsXml .= '<row r="' . $rowNum . '">' . $xml . '</row>';
    };
    $preparedBy = trim((string)($report['prepared_by'] ?? ''));
    if ($preparedBy === '' || $preparedBy === '—') {
        $preparedBy = salesReportPreparedBy();
    }
    if ($preparedBy === '') {
        $preparedBy = '—';
    }
    $put($r, ['ABC GENERAL MERCHANDISE'], 1);
    $r++;
    $put($r, ['Sales Report'], 2);
    $r++;
    $put($r, ['Reporting Period: ' . salesReportExcelPeriod($report)]);
    $r++;
    $put($r, ['Filters: ' . (string)($report['filter_label'] ?? 'Payment Method: All · Sales Status: All')]);
    $r++;
    $put($r, ['Prepared by: ' . $preparedBy]);
    $r++;
    $put($r, ['Date Prepared: ' . date('F j, Y')]);
    $r += 2;
    $put($r, ['Sales Transactions'], 2);
    $r++;
    $put($r, ['Transaction Number', 'Transaction Date', 'Product Name', 'Brand', 'Quantity Sold', 'Unit', 'Unit Price', 'Subtotal', 'Tax', 'Discount', 'Delivery Fee', 'Total Amount', 'Payment Method', 'Payment Status', 'Cash Tendered', 'Change'], 4);
    $detailRows = $report['detail_rows'] ?? [];
    if (!$detailRows) {
        $r++;
        $put($r, ['No records found for the selected filters.']);
    }
    $salesTotal = 0.0;
    foreach ($detailRows as $row) {
        $r++;
        $lineTotal = (float)($row['total'] ?? $row['net'] ?? 0);
        $salesTotal += $lineTotal;
        $tendered = $row['cash_tendered'] ?? null;
        $change = $row['cash_change'] ?? null;
        $put($r, [
            (string)($row['transaction_number'] ?? $row['invoice'] ?? ''),
            (string)$row['date'],
            (string)$row['product'],
            (string)($row['brand'] ?? ''),
            (string)(int)$row['qty'],
            (string)($row['unit'] ?? ''),
            salesReportMoney((float)$row['unit_price']),
            salesReportMoney((float)$row['gross']),
            salesReportMoney((float)($row['tax'] ?? 0)),
            salesReportMoney((float)$row['discount']),
            salesReportMoney((float)($row['delivery_fee'] ?? 0)),
            salesReportMoney($lineTotal),
            (string)$row['payment'],
            (string)($row['payment_status'] ?? ''),
            ($tendered === null || $tendered === '') ? '' : salesReportMoney((float)$tendered),
            ($change === null || $change === '') ? '' : salesReportMoney((float)$change),
        ]);
    }
    if ($detailRows) {
        $r++;
        $put($r, ['TOTAL', '', '', '', '', '', '', '', '', '', '', salesReportMoney($salesTotal)], 2);
    }
    $r += 2;
    $put($r, ['Sales by Product'], 2);
    $r++;
    $put($r, ['Product', 'Quantity Sold', 'Unit Selling Price', 'Gross Sales', 'Discounts', 'Net Sales'], 4);
    $qty = 0;
    $gross = 0.0;
    $disc = 0.0;
    $net = 0.0;
    foreach ($report['product_summary'] ?? [] as $row) {
        $r++;
        $qty += (int)$row['quantity'];
        $gross += (float)$row['gross'];
        $disc += (float)$row['discount'];
        $net += (float)$row['net'];
        $put($r, [
            (string)$row['name'],
            (string)(int)$row['quantity'],
            salesReportMoney((float)$row['unit_price']),
            salesReportMoney((float)$row['gross']),
            salesReportMoney((float)$row['discount']),
            salesReportMoney((float)$row['net']),
        ]);
    }
    $r++;
    $put($r, ['TOTAL', (string)$qty, '', salesReportMoney($gross), salesReportMoney($disc), salesReportMoney($net)], 2);
    $r += 2;
    $put($r, ['Sales by Payment Method'], 2);
    $r++;
    $put($r, ['Payment Method', 'Number of Transactions', 'Amount'], 4);
    $payTx = 0;
    $payAmt = 0.0;
    foreach ($report['payment_summary'] ?? [] as $row) {
        $r++;
        $payTx += (int)$row['transactions'];
        $payAmt += (float)($row['amount'] ?? 0);
        $put($r, [$row['label'], (string)(int)$row['transactions'], salesReportMoney((float)($row['amount'] ?? 0))]);
    }
    $r++;
    $put($r, ['TOTAL', (string)$payTx, salesReportMoney($payAmt)], 2);
    $r += 2;
    $put($r, ['Sales Performance Summary'], 2);
    $r++;
    $put($r, ['Metric', 'Amount'], 4);
    $r++;
    $put($r, ['Gross Sales', salesReportMoney((float)$perf['gross'])]);
    $r++;
    $put($r, ['Total Discounts', salesReportMoney((float)$perf['discounts'])]);
    $r++;
    $put($r, ['Net Sales', salesReportMoney((float)$perf['net'])]);
    $r += 2;
    $put($r, ['Sales Report by Category'], 2);
    $r++;
    $put($r, ['Category', 'Net Sales', 'Percentage of Total Sales'], 4);
    $catNet = 0.0;
    foreach ($report['category_summary'] ?? [] as $row) {
        $r++;
        $catNet += (float)$row['net'];
        $put($r, [(string)$row['category'], salesReportMoney((float)$row['net']), number_format((float)$row['percent'], 2) . '%']);
    }
    $r++;
    $catPct = abs((float)$perf['net']) >= 0.00001 ? 100 : 0;
    $put($r, ['TOTAL', salesReportMoney($catNet), number_format($catPct, 2) . '%'], 2);

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols>'
        . '<col min="1" max="1" width="22" customWidth="1"/>'
        . '<col min="2" max="2" width="20" customWidth="1"/>'
        . '<col min="3" max="3" width="36" customWidth="1"/>'
        . '<col min="4" max="4" width="18" customWidth="1"/>'
        . '<col min="5" max="5" width="16" customWidth="1"/>'
        . '<col min="6" max="6" width="12" customWidth="1"/>'
        . '<col min="7" max="8" width="16" customWidth="1"/>'
        . '<col min="9" max="12" width="16" customWidth="1"/>'
        . '<col min="13" max="14" width="22" customWidth="1"/>'
        . '<col min="15" max="16" width="16" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $rowsXml . '</sheetData></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="5"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/><xf xfId="0" fontId="2" applyFont="1"/><xf xfId="0" numFmtId="14" applyNumberFormat="1"/><xf xfId="0" fontId="2" applyFont="1"/></cellXfs>'
        . '</styleSheet>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sales Report" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    return inventoryReportZipStore([
        '[Content_Types].xml' => $types,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheet,
        'xl/styles.xml' => $styles,
    ]);
}

function salesReportPdfEscape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function salesReportPdfMoney(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function salesReportPdf(array $report): string
{
    require_once __DIR__ . '/clarenzon_pdf_lib.php';
    $perf = $report['performance'] ?? ['gross' => 0, 'discounts' => 0, 'net' => 0];
    $money = static function ($amount): string {
        return clarenzonPdfMoney((float)$amount);
    };
    $detail = [];
    $salesTotal = 0.0;
    foreach ($report['detail_rows'] ?? [] as $row) {
        $lineTotal = (float)($row['total'] ?? $row['net'] ?? 0);
        $salesTotal += $lineTotal;
        $tendered = $row['cash_tendered'] ?? null;
        $change = $row['cash_change'] ?? null;
        $detail[] = [
            (string)($row['transaction_number'] ?? $row['invoice'] ?? ''),
            (string)($row['date'] ?? ''),
            (string)($row['product'] ?? ''),
            (string)($row['brand'] ?? ''),
            (string)(int)($row['qty'] ?? 0),
            (string)($row['unit'] ?? ''),
            $money($row['unit_price'] ?? 0),
            $money($row['gross'] ?? 0),
            $money($row['tax'] ?? 0),
            $money($row['discount'] ?? 0),
            $money($row['delivery_fee'] ?? 0),
            $money($lineTotal),
            (string)($row['payment'] ?? ''),
            (string)($row['payment_status'] ?? ''),
            ($tendered === null || $tendered === '') ? '' : $money($tendered),
            ($change === null || $change === '') ? '' : $money($change),
        ];
    }
    $product = [];
    $qty = 0;
    $gross = 0.0;
    $disc = 0.0;
    $net = 0.0;
    foreach ($report['product_summary'] ?? [] as $row) {
        $qty += (int)($row['quantity'] ?? 0);
        $gross += (float)($row['gross'] ?? 0);
        $disc += (float)($row['discount'] ?? 0);
        $net += (float)($row['net'] ?? 0);
        $product[] = [
            (string)($row['name'] ?? ''),
            (string)(int)($row['quantity'] ?? 0),
            $money($row['unit_price'] ?? 0),
            $money($row['gross'] ?? 0),
            $money($row['discount'] ?? 0),
            $money($row['net'] ?? 0),
        ];
    }
    $payRows = [];
    $payTx = 0;
    $payAmt = 0.0;
    foreach ($report['payment_summary'] ?? [] as $row) {
        $payTx += (int)($row['transactions'] ?? 0);
        $payAmt += (float)($row['amount'] ?? 0);
        $payRows[] = [
            (string)($row['label'] ?? ''),
            (string)(int)($row['transactions'] ?? 0),
            $money($row['amount'] ?? 0),
        ];
    }
    $catRows = [];
    $catNet = 0.0;
    foreach ($report['category_summary'] ?? [] as $row) {
        $catNet += (float)($row['net'] ?? 0);
        $catRows[] = [
            (string)($row['category'] ?? ''),
            $money($row['net'] ?? 0),
            number_format((float)($row['percent'] ?? 0), 2) . '%',
        ];
    }
    $catPct = abs((float)($perf['net'] ?? 0)) >= 0.00001 ? 100 : 0;
    $empty = 'No records found for the selected filters.';
    return clarenzonPdfDocument([
        'title' => 'Sales Report',
        'details' => [
            'Reporting Period: ' . (string)($report['range_label'] ?? 'All Dates'),
            'Filters: ' . (string)($report['filter_label'] ?? 'Payment Method: All · Sales Status: All'),
            'Prepared by: ' . (string)($report['prepared_by'] ?? '—'),
            'Date Prepared: ' . (string)($report['generated'] ?? date('F j, Y')),
        ],
        'summary' => [
            ['label' => 'Gross Sales', 'value' => $money($perf['gross'] ?? 0)],
            ['label' => 'Discounts', 'value' => $money($perf['discounts'] ?? 0)],
            ['label' => 'Net Sales', 'value' => $money($perf['net'] ?? 0)],
        ],
        'blocks' => [
            [
                'heading' => 'Sales Transactions',
                'columns' => [
                    ['label' => 'Transaction No.', 'width' => 1.15, 'align' => 'center'],
                    ['label' => 'Date', 'width' => 0.85, 'align' => 'center'],
                    ['label' => 'Product', 'width' => 1.5, 'align' => 'left'],
                    ['label' => 'Brand', 'width' => 0.8, 'align' => 'left'],
                    ['label' => 'Qty', 'width' => 0.4, 'align' => 'right'],
                    ['label' => 'Unit', 'width' => 0.45, 'align' => 'left'],
                    ['label' => 'Unit Price', 'width' => 0.75, 'align' => 'right'],
                    ['label' => 'Subtotal', 'width' => 0.75, 'align' => 'right'],
                    ['label' => 'Tax', 'width' => 0.6, 'align' => 'right'],
                    ['label' => 'Discount', 'width' => 0.7, 'align' => 'right'],
                    ['label' => 'Delivery', 'width' => 0.7, 'align' => 'right'],
                    ['label' => 'Total', 'width' => 0.75, 'align' => 'right'],
                    ['label' => 'Method', 'width' => 0.75, 'align' => 'center'],
                    ['label' => 'Status', 'width' => 0.7, 'align' => 'center'],
                    ['label' => 'Tendered', 'width' => 0.75, 'align' => 'right'],
                    ['label' => 'Change', 'width' => 0.65, 'align' => 'right'],
                ],
                'rows' => $detail,
                'total' => $detail ? ['TOTAL', '', '', '', '', '', '', '', '', '', '', $money($salesTotal), '', '', '', ''] : null,
                'empty' => $empty,
            ],
            [
                'heading' => 'Sales by Product',
                'columns' => [
                    ['label' => 'Product', 'width' => 2.4, 'align' => 'left'],
                    ['label' => 'Quantity Sold', 'width' => 0.9, 'align' => 'right'],
                    ['label' => 'Unit Selling Price', 'width' => 1.2, 'align' => 'right'],
                    ['label' => 'Gross Sales', 'width' => 1.1, 'align' => 'right'],
                    ['label' => 'Discounts', 'width' => 1.0, 'align' => 'right'],
                    ['label' => 'Net Sales', 'width' => 1.1, 'align' => 'right'],
                ],
                'rows' => $product,
                'total' => $product ? ['TOTAL', (string)$qty, '', $money($gross), $money($disc), $money($net)] : null,
                'empty' => $empty,
            ],
            [
                'heading' => 'Sales by Payment Method',
                'columns' => [
                    ['label' => 'Payment Method', 'width' => 2, 'align' => 'left'],
                    ['label' => 'Number of Transactions', 'width' => 1.4, 'align' => 'right'],
                    ['label' => 'Amount', 'width' => 1.2, 'align' => 'right'],
                ],
                'rows' => $payRows,
                'total' => ['TOTAL', (string)$payTx, $money($payAmt)],
            ],
            [
                'heading' => 'Sales Performance Summary',
                'columns' => [
                    ['label' => 'Metric', 'width' => 2, 'align' => 'left'],
                    ['label' => 'Amount', 'width' => 1.2, 'align' => 'right'],
                ],
                'rows' => [
                    ['Gross Sales', $money($perf['gross'] ?? 0)],
                    ['Total Discounts', $money($perf['discounts'] ?? 0)],
                    ['Net Sales', $money($perf['net'] ?? 0)],
                ],
            ],
            [
                'heading' => 'Sales Report by Category',
                'columns' => [
                    ['label' => 'Category', 'width' => 2, 'align' => 'left'],
                    ['label' => 'Net Sales', 'width' => 1.2, 'align' => 'right'],
                    ['label' => 'Percentage of Total Sales', 'width' => 1.4, 'align' => 'right'],
                ],
                'rows' => $catRows,
                'total' => $catRows ? ['TOTAL', $money($catNet), number_format($catPct, 2) . '%'] : null,
                'empty' => $empty,
            ],
        ],
    ]);
}
