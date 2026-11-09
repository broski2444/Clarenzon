<?php
/**
 * Read-only Sales Report markup. Expects $salesReport, $salesReportFilters, $canExportSalesReports.
 * Layout follows the Inventory Report card: page header, card header, filters, then modern-table.
 */
$salesReport = $salesReport ?? salesReportEmpty();
$salesReportFilters = $salesReportFilters ?? salesReportReadFilters([]);
$canExportSalesReports = !empty($canExportSalesReports);
$srPerf = $salesReport['performance'] ?? ['gross' => 0, 'discounts' => 0, 'net' => 0];
$srDetail = $salesReport['detail_rows'] ?? [];
$srPayTx = 0;
$srPayAmt = 0.0;
foreach ($salesReport['payment_summary'] as $srPay) {
    $srPayTx += (int)$srPay['transactions'];
    $srPayAmt += (float)($srPay['amount'] ?? 0);
}
$srProdQty = 0;
$srProdGross = 0.0;
$srProdDisc = 0.0;
$srProdNet = 0.0;
foreach ($salesReport['product_summary'] as $srProd) {
    $srProdQty += (int)$srProd['quantity'];
    $srProdGross += (float)$srProd['gross'];
    $srProdDisc += (float)$srProd['discount'];
    $srProdNet += (float)$srProd['net'];
}
$srCatNet = 0.0;
foreach ($salesReport['category_summary'] as $srCat) {
    $srCatNet += (float)$srCat['net'];
}
$srCatPct = abs((float)$srPerf['net']) >= 0.00001 ? 100 : 0;
?>
<div id="section-sales-reports" class="content-section">
    <div class="page-header"><h1>Sales Reports</h1></div>
    <div class="card">
        <div class="card-header">
            <div class="card-title">Monthly Sales Report</div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="print-btn" type="button" onclick="printSalesReport()"><i class="bi bi-printer"></i> Print</button>
                <?php if ($canExportSalesReports): ?>
                    <button class="export-btn" type="button" onclick="exportSalesReport('csv')"><i class="bi bi-download"></i> Export CSV</button>
                    <button class="export-btn" type="button" onclick="exportSalesReport('xlsx')"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
                    <button class="export-btn" type="button" onclick="exportSalesReport('pdf')"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <p class="inventory-report-period"><?= htmlspecialchars((string)$salesReport['range_label']) ?></p>
            <form method="GET" id="salesReportFilterForm" class="inventory-report-filters mb-3" onsubmit="return prepareSalesReportSubmit(this)">
                <input type="hidden" name="open" value="sales-reports">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="srPreset">Date Range</label>
                        <select name="sr_preset" id="srPreset" class="form-select form-select-sm" onchange="applySalesReportPreset(this.value)">
                            <?php
                            $srPresets = ['' => 'All Dates', 'today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year', 'custom' => 'Custom Date Range'];
                            foreach ($srPresets as $value => $label):
                            ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= (string)$salesReportFilters['preset'] === (string)$value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3" id="srFromWrap">
                        <label class="form-label" for="srFrom">Date From</label>
                        <input type="date" name="sr_from" id="srFrom" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($salesReportFilters['from'] ?? '')) ?>">
                    </div>
                    <div class="col-6 col-md-3" id="srToWrap">
                        <label class="form-label" for="srTo">Date To</label>
                        <input type="date" name="sr_to" id="srTo" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($salesReportFilters['to'] ?? '')) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="srMethod">Payment Method</label>
                        <select name="sr_method" id="srMethod" class="form-select form-select-sm">
                            <option value="">All Methods</option>
                            <?php foreach (salesReportMethods() as $methodKey): ?>
                                <option value="<?= htmlspecialchars($methodKey) ?>" <?= $salesReportFilters['method'] === $methodKey ? 'selected' : '' ?>><?= htmlspecialchars(salesReportDisplayMethodLabel($methodKey)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="srStatus">Sales Status</label>
                        <select name="sr_status" id="srStatus" class="form-select form-select-sm">
                            <?php
                            $srStatuses = ['' => 'All Statuses', 'Paid' => 'Paid', 'Partially Paid' => 'Partially Paid', 'Unpaid / Outstanding' => 'Unpaid / Outstanding', 'Pending' => 'Pending', 'Cancelled' => 'Cancelled'];
                            foreach ($srStatuses as $value => $label):
                            ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= (string)$salesReportFilters['status'] === (string)$value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="print-btn"><i class="bi bi-funnel"></i> Apply</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetSalesReportFilters()">Reset Filters</button>
                    </div>
                </div>
            </form>
            <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
                <div class="search-box" style="width:min(100%,280px); max-width:100%;"><i class="bi bi-search"></i><input type="text" name="sr_search" form="salesReportFilterForm" placeholder="Search report..." value="<?= htmlspecialchars((string)$salesReportFilters['search']) ?>"></div>
                <span style="font-size:12px;color:var(--text-tertiary);">Generated: <?= htmlspecialchars((string)$salesReport['generated']) ?></span>
            </div>
            <div class="table-container system-table-scroll">
                <table class="modern-table" style="min-width:1080px;">
                    <thead>
                        <tr>
                            <th class="text-center">Date</th>
                            <th class="text-center">Invoice No.</th>
                            <th>Product</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Gross Sales</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Net Sales</th>
                            <th class="text-center">Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$srDetail): ?>
                        <tr><td colspan="9" class="text-center">No sales for this period.</td></tr>
                    <?php else: foreach ($srDetail as $srLine): ?>
                        <tr>
                            <td class="text-center text-nowrap"><?= htmlspecialchars((string)$srLine['date']) ?></td>
                            <td class="text-center text-nowrap"><?= htmlspecialchars((string)$srLine['invoice']) ?></td>
                            <td><?= htmlspecialchars((string)$srLine['product']) ?></td>
                            <td class="text-end"><?= number_format((int)$srLine['qty']) ?></td>
                            <td class="text-end text-nowrap"><?= salesReportMoney((float)$srLine['unit_price']) ?></td>
                            <td class="text-end text-nowrap"><?= salesReportMoney((float)$srLine['gross']) ?></td>
                            <td class="text-end text-nowrap"><?= salesReportMoney((float)$srLine['discount']) ?></td>
                            <td class="text-end text-nowrap"><?= salesReportMoney((float)$srLine['net']) ?></td>
                            <td class="text-center text-nowrap"><?= htmlspecialchars((string)$srLine['payment']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">
            <div class="card-title">Sales by Product</div>
        </div>
        <div class="card-body">
            <div class="table-container system-table-scroll">
                <table class="modern-table">
                    <thead><tr><th>Product</th><th>Quantity Sold</th><th>Unit Selling Price</th><th>Gross Sales</th><th>Discounts</th><th>Net Sales</th></tr></thead>
                    <tbody>
                    <?php if (!$salesReport['product_summary']): ?>
                        <tr><td colspan="6" class="text-center">No product sales for this period.</td></tr>
                    <?php else: foreach ($salesReport['product_summary'] as $prodRow): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$prodRow['name']) ?></td>
                            <td><?= number_format((int)$prodRow['quantity']) ?></td>
                            <td><?= salesReportMoney((float)$prodRow['unit_price']) ?></td>
                            <td><?= salesReportMoney((float)$prodRow['gross']) ?></td>
                            <td><?= salesReportMoney((float)$prodRow['discount']) ?></td>
                            <td><?= salesReportMoney((float)$prodRow['net']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                        <tr>
                            <td><strong>TOTAL</strong></td>
                            <td><strong><?= number_format($srProdQty) ?></strong></td>
                            <td></td>
                            <td><strong><?= salesReportMoney($srProdGross) ?></strong></td>
                            <td><strong><?= salesReportMoney($srProdDisc) ?></strong></td>
                            <td><strong><?= salesReportMoney($srProdNet) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">
            <div class="card-title">Sales by Payment Method</div>
        </div>
        <div class="card-body">
            <div class="table-container system-table-scroll">
                <table class="modern-table">
                    <thead><tr><th>Payment Method</th><th>Number of Transactions</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($salesReport['payment_summary'] as $payRow): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$payRow['label']) ?></td>
                            <td><?= number_format((int)$payRow['transactions']) ?></td>
                            <td><?= salesReportMoney((float)($payRow['amount'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <td><strong>TOTAL</strong></td>
                            <td><strong><?= number_format($srPayTx) ?></strong></td>
                            <td><strong><?= salesReportMoney($srPayAmt) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">
            <div class="card-title">Sales Performance Summary</div>
        </div>
        <div class="card-body">
            <div class="table-container system-table-scroll">
                <table class="modern-table">
                    <thead><tr><th>Metric</th><th>Amount</th></tr></thead>
                    <tbody>
                        <tr><td>Gross Sales</td><td><?= salesReportMoney((float)$srPerf['gross']) ?></td></tr>
                        <tr><td>Total Discounts</td><td><?= salesReportMoney((float)$srPerf['discounts']) ?></td></tr>
                        <tr><td>Net Sales</td><td><?= salesReportMoney((float)$srPerf['net']) ?></td></tr>
                        <tr><td>Returns / Refunds (<?= (int)($srPerf['returns_count'] ?? 0) ?>)</td><td><?= (float)($srPerf['returns'] ?? 0) > 0 ? '-' : '' ?><?= salesReportMoney((float)($srPerf['returns'] ?? 0)) ?></td></tr>
                        <tr><td>Net Sales After Returns</td><td><?= salesReportMoney((float)($srPerf['net_after_returns'] ?? $srPerf['net'])) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">
            <div class="card-title">Sales Report by Category</div>
        </div>
        <div class="card-body">
            <div class="table-container system-table-scroll">
                <table class="modern-table">
                    <thead><tr><th>Category</th><th>Net Sales</th><th>Percentage of Total Sales</th></tr></thead>
                    <tbody>
                    <?php if (!$salesReport['category_summary']): ?>
                        <tr><td colspan="3" class="text-center">No category sales for this period.</td></tr>
                    <?php else: foreach ($salesReport['category_summary'] as $catRow): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$catRow['category']) ?></td>
                            <td><?= salesReportMoney((float)$catRow['net']) ?></td>
                            <td><?= number_format((float)$catRow['percent'], 2) ?>%</td>
                        </tr>
                    <?php endforeach; endif; ?>
                        <tr>
                            <td><strong>TOTAL</strong></td>
                            <td><strong><?= salesReportMoney($srCatNet) ?></strong></td>
                            <td><strong><?= number_format($srCatPct, 2) ?>%</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
<script>
function applySalesReportPreset(value) {
  const fromWrap = document.getElementById('srFromWrap');
  const toWrap = document.getElementById('srToWrap');
  const show = value === 'custom';
  if (fromWrap) fromWrap.style.display = show ? '' : 'none';
  if (toWrap) toWrap.style.display = show ? '' : 'none';
}
function prepareSalesReportSubmit() {
  return true;
}
function resetSalesReportFilters() {
  window.location.href = window.location.pathname + '?open=sales-reports#sales-reports';
}
function printSalesReport() {
  if (typeof showSection === 'function') showSection('sales-reports');
  window.print();
}
function exportSalesReport(kind) {
  const form = document.getElementById('salesReportFilterForm');
  const params = new URLSearchParams(form ? new FormData(form) : window.location.search);
  params.set('sr_export', kind);
  params.set('open', 'sales-reports');
  params.delete('sr_page');
  window.location.href = window.location.pathname + '?' + params.toString();
}
applySalesReportPreset(document.getElementById('srPreset') ? document.getElementById('srPreset').value : '');
document.addEventListener('DOMContentLoaded', function () {
  const params = new URLSearchParams(window.location.search);
  if ((params.get('open') === 'sales-reports' || window.location.hash === '#sales-reports') && typeof showSection === 'function') {
    setTimeout(function () { showSection('sales-reports'); }, 180);
  }
});
</script>
