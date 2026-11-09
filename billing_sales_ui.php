<?php
/**
 * Shared sales / billing UI fragments: Cash vs Credit tabs, AR, pending cheques.
 */

function billingSaleCategoryTabsHtml(string $target = 'invoices'): string {
    $tabs = [
        '' => 'All',
        'Cash' => 'Cash Sales',
        'Credit' => 'Credit / Utang',
        'Cheque' => 'Cheque',
        'GCash' => 'GCash',
        'Maya' => 'Maya',
        'Bank Transfer' => 'Bank Transfer',
    ];
    $html = '<div class="d-flex flex-wrap gap-2 align-items-center">';
    $first = true;
    foreach ($tabs as $value => $label) {
        $cls = $first ? 'btn btn-sm btn-primary js-sales-type-tab' : 'btn btn-sm btn-outline-secondary js-sales-type-tab';
        $html .= '<button type="button" class="' . $cls . '" data-sale-type="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" data-target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '" onclick="setSalesCategoryTab(this)">' . htmlspecialchars($label) . '</button>';
        $first = false;
    }
    $html .= '</div>';
    return $html;
}

function billingPaymentStatusFilterHtml(string $selectId = 'invoiceStatusFilter'): string {
    return '<select id="' . htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8') . '" class="form-select form-select-sm" onchange="filterInvoices()">'
        . '<option value="">All Payments</option>'
        . '<option value="Paid">Paid</option>'
        . '<option value="Pending">Pending</option>'
        . '<option value="Unpaid / Outstanding">Unpaid / Outstanding</option>'
        . '</select>';
}

function billingRenderPendingChequesCard(array $pendingCheques, bool $canManage = false, bool $allowPayActions = true): void {
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <div class="card-title"><i class="bi bi-hourglass-split text-warning me-1"></i> Cheques waiting for clearance</div>
        </div>
        <div class="table-container system-table-scroll">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Payment</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Payment Method</th>
                        <th>Transaction Date</th>
                        <th>Cheque #</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pendingCheques)): ?>
                    <tr><td colspan="9" class="text-center text-muted">No cheques waiting for bank clearance.</td></tr>
                <?php else: ?>
                    <?php foreach ($pendingCheques as $ch):
                        $txDate = !empty($ch['payment_date']) ? date('M j, Y', strtotime((string)$ch['payment_date'])) : '—';
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string)($ch['payment_number'] ?? '')) ?></code></td>
                        <td><?= htmlspecialchars((string)($ch['invoice_number'] ?? '')) ?></td>
                        <td><strong><?= htmlspecialchars((string)($ch['customer_name'] ?? '')) ?></strong></td>
                        <td><span class="badge badge-blue">Cheque</span></td>
                        <td><?= htmlspecialchars($txDate) ?></td>
                        <td><?= $canManage ? htmlspecialchars((string)($ch['cheque_number'] ?? '—')) : '—' ?></td>
                        <td>₱<?= number_format((float)($ch['amount_paid'] ?? 0), 2) ?></td>
                        <td><span class="badge badge-warning"><i class="bi bi-hourglass-split"></i> Pending</span></td>
                        <td>
                            <?php if ($canManage && $allowPayActions): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="payment_id" value="<?= (int)($ch['id'] ?? 0) ?>">
                                <button type="submit" name="mark_cheque_paid" class="btn btn-success btn-sm"><i class="bi bi-check-circle"></i> Mark as Paid</button>
                                <button type="submit" name="bounce_cheque" class="btn btn-danger btn-sm" onclick="return appConfirmSubmit(event, 'Mark this cheque as Bounced/Rejected? The amount stays unpaid.')">Bounced/Rejected</button>
                            </form>
                            <?php endif; ?>
                            <?php if (!empty($ch['payment_number'])): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="print_payment_receipt.php?payment_number=<?= urlencode((string)$ch['payment_number']) ?>" target="_blank">Receipt</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted px-3 pb-2 mb-0">A cheque stays Pending until it clears. It is not counted as a completed payment. Mark as Paid after the bank confirms clearance. The original payment method remains Cheque.</p>
    </div>
    <?php
}

function billingIsOutstandingInvoice(array $inv): bool {
    if ((float)($inv['remaining_balance'] ?? 0) <= 0) {
        return false;
    }
    $raw = trim((string)($inv['latest_payment_method'] ?? ''));
    if ($raw === '') {
        $raw = (string)($inv['sale_type'] ?? '');
    }
    return billingNormalizePayMethod($raw) === 'Credit';
}

function billingPhilippineBanks(): array {
    $banks = [
        'AUB (Asia United Bank)',
        'BDO Unibank',
        'Bank of the Philippine Islands (BPI)',
        'Metrobank',
        'Philippine National Bank (PNB)',
        'Security Bank',
        'UnionBank',
        'RCBC',
        'China Bank',
        'EastWest Bank',
        'LandBank',
        'Development Bank of the Philippines (DBP)',
        'Bank of Commerce',
        'Philippine Business Bank',
        'Philippine Savings Bank (PSBank)',
        'Philippine Veterans Bank',
        'Maybank Philippines',
        'HSBC Philippines',
        'Standard Chartered Bank Philippines',
        'Citibank Philippines / UnionBank (legacy Citi consumer business)',
        'Rizal Commercial Banking Corporation (RCBC)',
        'Chinabank Savings',
        'CitySavings',
        'Sterling Bank of Asia',
        'Tonik Digital Bank',
        'GoTyme Bank',
        'Maya Bank',
        'SeaBank Philippines / MariBank Philippines',
        'OwnBank',
        'UNO Digital Bank',
        'Netbank',
        'Al-Amanah Islamic Investment Bank',
        'AllBank (A Thrift Bank)',
        'ANZ Philippines',
        'Bangkok Bank',
        'Bank of America',
        'Bank of China',
        'Bank of Makati',
        'BPI Direct BanKo',
        'CARD Bank',
        'Cathay United Bank',
        'Chang Hwa Commercial Bank',
        'CIMB Bank Philippines',
        'CTBC Bank (Philippines)',
        'Deutsche Bank',
        'Equicom Savings Bank',
        'First Commercial Bank',
        'First Consolidated Bank',
        'Hua Nan Commercial Bank',
        'ICBC Manila',
        'JPMorgan Chase Bank',
        'KEB Hana Bank',
        'Malayan Bank',
        'Mega International Commercial Bank',
        'Mizuho Bank',
        'MUFG Bank',
        'Overseas Filipino Bank (OFBank)',
        'Philippine Bank of Communications (PBCOM)',
        'Philtrust Bank',
        'Producers Savings Bank',
        'Queen City Development Bank',
        'Robinsons Bank',
        'Shinhan Bank',
        'Sumitomo Mitsui Banking Corporation',
        'UnionDigital Bank',
        'United Overseas Bank (UOB)',
        'WealthBank',
        'Yuanta Savings Bank',
    ];
    $banks = array_values(array_unique($banks));
    sort($banks, SORT_NATURAL | SORT_FLAG_CASE);
    return $banks;
}

function billingBankNameField(string $id, string $name = '', string $attrs = ''): void {
    echo '<select id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" class="form-control"';
    if ($name !== '') {
        echo ' name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($attrs !== '') {
        echo ' ' . $attrs;
    }
    echo '><option value=""></option>';
    foreach (billingPhilippineBanks() as $bank) {
        $safe = htmlspecialchars($bank, ENT_QUOTES, 'UTF-8');
        echo '<option value="' . $safe . '">' . $safe . '</option>';
    }
    echo '</select>';
}
