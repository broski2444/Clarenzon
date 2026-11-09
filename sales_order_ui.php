<?php
/**
 * Shared Sales Order module markup for admin, warehouse, purchaser, and sales dashboards.
 */

function salesOrderStatusBadgeHtml(array $ord): string {
    $label = function_exists('salesOrderDisplayStatus') ? salesOrderDisplayStatus($ord) : (string)($ord['status'] ?? 'Pending');
    $raw = (string)($ord['status'] ?? 'Pending');
    if ($label === 'Cancelled' || $raw === 'Cancelled') {
        return '<span class="badge badge-danger"><i class="bi bi-x-circle"></i> Cancelled</span>';
    }
    if ($label === 'Cheque Rejected') {
        return '<span class="badge badge-danger"><i class="bi bi-x-octagon"></i> Cheque Rejected</span>';
    }
    if ($label === 'Completed' || $raw === 'Approved') {
        $saleType = strtolower((string)($ord['sale_type'] ?? ''));
        $balance = (float)($ord['remaining_balance'] ?? 0);
        if ($saleType === 'credit' && $balance > 0.009) {
            return '<span class="badge badge-warning"><i class="bi bi-clock-history"></i> Open Credit</span>';
        }
        return '<span class="badge badge-green"><i class="bi bi-check-circle"></i> ' . htmlspecialchars($label === 'Completed' ? 'Completed' : 'Approved') . '</span>';
    }
    return '<span class="badge badge-warning"><i class="bi bi-hourglass-split"></i> Pending</span>';
}

function salesOrderRenderListRows(array $orders, array $opts = []): void {
    $showCreator = !empty($opts['show_creator']);
    $canManage = !empty($opts['can_manage']);
    $colspan = $showCreator ? 10 : 9;
    if (!$orders) {
        echo '<tr><td colspan="' . (int)$colspan . '" class="text-center">No sales orders found.</td></tr>';
        return;
    }
    foreach ($orders as $ord) {
        $id = (int)($ord['id'] ?? 0);
        $saleType = function_exists('billingNormalizeSaleType') ? billingNormalizeSaleType($ord['sale_type'] ?? 'Cash') : (string)($ord['sale_type'] ?? 'Cash');
        $saleLabel = function_exists('billingSaleTypeLabel') ? billingSaleTypeLabel($saleType) : $saleType;
        $saleBadge = function_exists('billingSaleTypeBadgeClass') ? billingSaleTypeBadgeClass($saleType) : 'badge-blue';
        $balance = (float)($ord['remaining_balance'] ?? 0);
        $isImmediatePaid = function_exists('billingSaleTypeIsImmediatePaid')
            ? billingSaleTypeIsImmediatePaid($saleType)
            : ($saleType === 'Cash');
        if (!array_key_exists('remaining_balance', $ord) && $isImmediatePaid && ($ord['status'] ?? '') === 'Approved') {
            $balance = 0;
        }
        $payLabel = function_exists('salesOrderPaymentLabel') ? salesOrderPaymentLabel($ord) : (string)($ord['payment_status'] ?? '—');
        $invoiceId = (int)($ord['invoice_id'] ?? 0);
        $invoiceNumber = (string)($ord['invoice_number'] ?? '');
        $agreement = (string)($ord['agreement_number'] ?? $ord['credit_agreement_number'] ?? '');
        $rawStatus = (string)($ord['status'] ?? '');
        $displayLabel = function_exists('salesOrderDisplayStatus') ? salesOrderDisplayStatus($ord) : $rawStatus;
        $visibleStatus = 'Pending';
        if ($displayLabel === 'Cancelled' || $rawStatus === 'Cancelled') {
            $visibleStatus = 'Cancelled';
        } elseif ($displayLabel === 'Cheque Rejected') {
            $visibleStatus = 'Cheque Rejected';
        } elseif ($displayLabel === 'Completed' || $rawStatus === 'Approved') {
            $visibleStatus = (strtolower($saleType) === 'credit' && $balance > 0.009)
                ? 'Open Credit'
                : ($displayLabel === 'Completed' ? 'Completed' : 'Approved');
        }
        echo '<tr data-status="' . htmlspecialchars($rawStatus) . '" data-display-status="' . htmlspecialchars($visibleStatus) . '" data-sale-type="' . htmlspecialchars($saleType) . '" data-so-id="' . $id . '">';
        echo '<td><span class="badge badge-purple">' . htmlspecialchars((string)$ord['order_number']) . '</span></td>';
        echo '<td><strong>' . htmlspecialchars((string)$ord['customer_name']) . '</strong></td>';
        echo '<td><span class="badge ' . htmlspecialchars($saleBadge) . '">' . htmlspecialchars($saleLabel) . '</span></td>';
        echo '<td>₱' . number_format((float)$ord['grand_total'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($payLabel) . '</td>';
        echo '<td><strong class="' . ($balance > 0.009 ? 'text-danger' : 'text-success') . '">₱' . number_format($balance, 2) . '</strong></td>';
        echo '<td>' . htmlspecialchars((string)($ord['payment_due_date'] ?? '')) . '</td>';
        if ($showCreator) {
            echo '<td>' . htmlspecialchars((string)($ord['creator_name'] ?? '')) . '</td>';
        }
        echo '<td>' . salesOrderStatusBadgeHtml($ord) . '</td>';
        echo '<td class="text-nowrap">';
        echo '<button type="button" class="btn btn-outline-primary btn-sm" onclick="openSalesOrderView(' . $id . ')"><i class="bi bi-eye"></i> View</button> ';
        if ($agreement !== '') {
            echo '<a class="btn btn-outline-secondary btn-sm" target="_blank" href="print_credit_agreement.php?so_id=' . $id . '"><i class="bi bi-file-earmark-text"></i> Contract</a> ';
        }
        if ($invoiceId > 0 && $balance > 0.009 && $canManage && $saleType === 'Credit') {
            echo '<button class="btn btn-success btn-sm" onclick="openPaymentModal(' . $invoiceId . ',\'' . htmlspecialchars($invoiceNumber, ENT_QUOTES) . '\',' . (float)$ord['grand_total'] . ',' . $balance . ')"><i class="bi bi-cash"></i> Pay</button> ';
        }
        if (($ord['status'] ?? '') === 'Pending' && $canManage) {
            if ($saleType !== 'Cheque') {
                echo '<form method="POST" class="d-inline">' . (function_exists('clarenzon_csrf_field') ? clarenzon_csrf_field() : '') . '<input type="hidden" name="sales_order_id" value="' . $id . '">';
                echo '<button type="submit" name="approve_sales_order" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Process</button></form> ';
            }
            echo '<form method="POST" class="d-inline" onsubmit="return appConfirmSubmit(event, \'Cancel this sales order?\')">' . (function_exists('clarenzon_csrf_field') ? clarenzon_csrf_field() : '');
            echo '<input type="hidden" name="sales_order_id" value="' . $id . '">';
            echo '<button type="submit" name="cancel_sales_order" class="btn btn-danger btn-sm"><i class="bi bi-x-lg"></i> Cancel</button></form> ';
        } elseif ($invoiceNumber !== '') {
            echo '<a href="print_invoice.php?invoice_number=' . urlencode($invoiceNumber) . '&with_receipt=1" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Invoice</a>';
        }
        echo '</td></tr>';
    }
}

function salesOrderRenderCreditSection(array $agreements): void {
    ?>
    <div id="section-sales-credit" class="content-section">
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1>Credit Agreements</h1>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <div class="card-title">Credit / Contract Records</div>
            </div>
            <div class="table-container system-table-scroll">
                <table class="modern-table" id="salesCreditTable">
                    <thead>
                        <tr>
                            <th>Agreement</th>
                            <th>Sales Order</th>
                            <th>Customer</th>
                            <th>Agreed Amount</th>
                            <th>Outstanding</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$agreements): ?>
                        <tr><td colspan="8" class="text-center text-muted">No credit agreements yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($agreements as $ca):
                            $bal = (float)($ca['remaining_balance'] ?? $ca['agreed_amount'] ?? 0);
                            $st = (string)($ca['status'] ?? 'Active');
                            if ($bal <= 0.009) $st = 'Settled';
                            $collection = (string)($ca['collection_status'] ?? '');
                            if ($bal <= 0.009) $collection = 'Paid';
                            $dueRaw = (string)($ca['due_date'] ?? '');
                            $daysOver = function_exists('creditDaysOverdue') ? creditDaysOverdue($dueRaw) : 0;
                            if ($collection === '' && $bal > 0.009 && $daysOver > 0) $collection = 'Overdue';
                            if ($collection === '' && $bal > 0.009) $collection = 'Pending';
                        ?>
                        <tr>
                            <td><span class="badge badge-blue"><?= htmlspecialchars((string)$ca['agreement_number']) ?></span></td>
                            <td><span class="badge badge-purple"><?= htmlspecialchars((string)$ca['order_number']) ?></span></td>
                            <td><strong><?= htmlspecialchars((string)$ca['customer_name']) ?></strong></td>
                            <td>₱<?= number_format((float)$ca['agreed_amount'], 2) ?></td>
                            <td><strong class="<?= $bal > 0.009 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($bal, 2) ?></strong></td>
                            <td><?php if ($collection === 'Overdue'): ?><strong class="credit-overdue-due"><?= htmlspecialchars($dueRaw) ?></strong><?php else: ?><?= htmlspecialchars($dueRaw) ?><?php endif; ?></td>
                            <td>
                                <?php if ($st === 'Settled'): ?>
                                    <span class="badge badge-green">Settled</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Active</span>
                                <?php endif; ?>
                                <?php if ($collection === 'Overdue'): ?>
                                    <span class="badge badge-danger">Overdue<?= $daysOver > 0 ? ' · ' . (int)$daysOver . 'd' : '' ?></span>
                                <?php elseif ($collection === 'Paid'): ?>
                                    <span class="badge badge-green">Paid</span>
                                <?php elseif ($collection === 'Pending'): ?>
                                    <span class="badge badge-warning">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="openSalesOrderView(<?= (int)$ca['sales_order_id'] ?>)"><i class="bi bi-eye"></i> Sales Order</button>
                                <a class="btn btn-outline-secondary btn-sm" target="_blank" href="print_credit_agreement.php?so_id=<?= (int)$ca['sales_order_id'] ?>"><i class="bi bi-printer"></i> Print</a>
                                <?php if (!empty($ca['id']) && function_exists('creditArActionButtons')): ?>
                                    <?= creditArActionButtons([
                                        'sale_type' => 'Credit',
                                        'credit_agreement_id' => (int)$ca['id'],
                                        'customer_name' => (string)($ca['customer_name'] ?? ''),
                                        'invoice_number' => (string)($ca['invoice_number'] ?? ''),
                                        'remaining_balance' => $bal,
                                    ]) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

function salesOrderRenderPosForm(): void {
    ?>
<div class="card mb-3 so-pos" id="createSalesOrderModal" data-so-pos="inline">
  <div class="card-header so-pos-top d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="card-title mb-0"><i class="bi bi-cart3 text-primary me-2"></i> Point of Sale</div>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetSalesOrderPos()"><i class="bi bi-arrow-counterclockwise me-1"></i> Clear</button>
  </div>
  <div class="card-body">
    <div class="so-pos-layout">
      <div class="so-pos-main">
      <section class="so-pos-card">
        <div class="so-pos-card-title"><span class="so-pos-ico"><i class="bi bi-person"></i></span> Customer Information</div>
      <div class="so-pos-customer-search mb-3">
        <div class="so-pos-customer-search-field">
          <label class="form-label" for="soCustomerSearch">Customer <span class="text-danger">*</span></label>
          <div class="so-product-combo" id="soCustomerCombo">
            <input type="text" id="soCustomerSearch" class="form-control so-product-search" placeholder="Search or select customer..." autocomplete="off" spellcheck="false">
            <div id="soCustomerMenu" class="so-product-menu" hidden role="listbox"></div>
          </div>
          <input type="hidden" id="soCustomerId" value="">
        </div>
        <button type="button" class="btn btn-outline-primary" id="soNewCustomerBtn" onclick="soStartNewCustomer()">+ New Customer</button>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-md-3">
          <label class="form-label" for="soCustomerFirstName">First Name <span class="text-danger">*</span></label>
          <input type="text" id="soCustomerFirstName" class="form-control" maxlength="80" autocomplete="given-name" oninput="if(typeof soClearCustomerNameError==='function')soClearCustomerNameError('soCustomerFirstName')">
          <div class="invalid-feedback" id="soCustomerFirstNameError">First Name is required.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="soCustomerMiddleName">Middle Name</label>
          <input type="text" id="soCustomerMiddleName" class="form-control" maxlength="80" autocomplete="additional-name" placeholder="Optional">
        </div>
        <div class="col-md-3">
          <label class="form-label" for="soCustomerLastName">Last Name <span class="text-danger">*</span></label>
          <input type="text" id="soCustomerLastName" class="form-control" maxlength="80" autocomplete="family-name" oninput="if(typeof soClearCustomerNameError==='function')soClearCustomerNameError('soCustomerLastName')">
          <div class="invalid-feedback" id="soCustomerLastNameError">Last Name is required.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="soCustomerSuffix">Suffix</label>
          <input type="text" id="soCustomerSuffix" class="form-control" maxlength="20" placeholder="Jr., Sr., II, III" autocomplete="off">
        </div>
      </div>
      <input type="hidden" id="soCustomerName" value="">
      <div id="soLoyalCustomerPanel" class="mb-3" style="display:none">
        <div class="alert alert-info py-2 small mb-2">
          <strong>Loyal Customer</strong> — previous orders are available. Choose products or an order to add; items already on this sale are kept.
        </div>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="soToggleCustomerHistory()"><i class="bi bi-clock-history me-1"></i>Previous Orders</button>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="soShowFrequentProducts()"><i class="bi bi-star me-1"></i>Frequently Ordered Products</button>
        </div>
        <div id="soCustomerHistoryBox" class="table-container system-table-scroll mb-2" style="display:none;max-height:220px">
          <table class="modern-table">
            <thead><tr><th>Order</th><th>Date</th><th>Amount</th><th></th></tr></thead>
            <tbody id="soCustomerHistoryBody"></tbody>
          </table>
        </div>
        <div id="soFrequentBox" class="table-container system-table-scroll" style="display:none;max-height:220px">
          <table class="modern-table">
            <thead><tr><th>Product</th><th>Times</th><th></th></tr></thead>
            <tbody id="soFrequentBody"></tbody>
          </table>
        </div>
        <div id="soPrevOrderItemsBox" class="table-container system-table-scroll mt-2" style="display:none;max-height:220px">
          <table class="modern-table">
            <thead><tr><th>Previous order items</th><th>Qty</th><th></th></tr></thead>
            <tbody id="soPrevOrderItemsBody"></tbody>
          </table>
        </div>
      </div>
      </section>
      <section class="so-pos-card">
        <div class="so-pos-card-title"><span class="so-pos-ico"><i class="bi bi-box-seam"></i></span> Product Entry</div>
      <div class="so-pos-scan-row">
          <input type="text" id="soBarcodeScan" class="form-control" placeholder="Scan barcode — each scan adds +1 qty" autocomplete="off" spellcheck="false">
          <button type="button" class="btn btn-primary" id="soScanBtn" onclick="submitSalesOrderBarcode()"><i class="bi bi-upc-scan me-1"></i>Scan</button>
          <button type="button" class="btn btn-outline-primary" onclick="addSalesOrderLine()"><i class="bi bi-plus"></i> Add Line</button>
      </div>
      <div class="table-container system-table-scroll mb-3 so-pos-lines-scroll">
        <table class="modern-table">
          <thead><tr><th>Product</th><th>Available</th><th>Selling Price</th><th>FEFO Lot</th><th>Qty</th><th>Line Total</th><th>Actions</th></tr></thead>
          <tbody id="soLinesBody"></tbody>
        </table>
      </div>
      <p class="small text-muted mb-0">Type a barcode or use a handheld scanner in the field above, then press Enter or Scan. Each scan adds 1 to the quantity. Quantity cannot exceed available stock.</p>
      </section>
      </div>
      <aside class="so-pos-checkout">
      <section class="so-pos-card">
        <div class="so-pos-card-title"><span class="so-pos-ico"><i class="bi bi-credit-card"></i></span> Payment Details</div>
      <div class="mb-3">
        <label class="form-label">Payment Method <span class="text-danger">*</span></label>
          <select id="soSaleType" class="form-select" onchange="toggleSoChequeFields()">
            <option value="Cash">Cash</option>
            <option value="GCash">GCash</option>
            <option value="Maya">Maya</option>
            <option value="Bank Transfer">Bank Transfer</option>
            <option value="Cheque">Cheque</option>
            <option value="Credit">Credit / Accounts Receivable</option>
          </select>
      </div>
      <div class="row g-2 mb-3 so-pos-split-3">
        <div class="col-4"><label class="form-label" for="soAmount">Amount</label><input type="number" id="soAmount" class="form-control" min="0" step="0.01" value="0" inputmode="decimal" oninput="this.dataset.touched='1'"></div>
        <div class="col-4"><label class="form-label">Discount (%)</label><input type="number" id="soDiscountPercent" class="form-control" min="0" max="100" step="0.01" value="0" oninput="if(Number(this.value)>100)this.value=100;recalcSalesOrderTotals()"></div>
        <div class="col-4"><label class="form-label">Delivery Fee</label><input type="number" id="soDeliveryFee" class="form-control" min="0" step="0.01" value="0" oninput="recalcSalesOrderTotals()"></div>
      </div>
      <div class="row g-2 mb-3 so-pos-split-2" id="soCashFields">
        <div class="col-6"><label class="form-label" for="soCashReceived">Cash Received</label><input type="number" name="cash_tendered" id="soCashReceived" class="form-control" min="0" step="0.01" inputmode="decimal" oninput="soUpdateCashChange()"></div>
        <div class="col-6"><label class="form-label" for="soCashChange">Change</label><input type="text" id="soCashChange" class="form-control" value="₱0.00" readonly tabindex="-1"></div>
      </div>
      <div class="row g-2 mb-3" id="soDueDateWrap" style="display:none">
        <div class="col-md-6"><label class="form-label" for="soDueDate">Payment Due Date <span class="text-danger">*</span></label><input type="date" id="soDueDate" class="form-control"></div>
      </div>

      <div id="soChequeFields" class="row g-2 mb-3" style="display:none">
        <div class="col-12"><div class="alert alert-warning py-2 mb-0 small">Cheque sales stay <strong>Pending</strong> until the cheque is marked Paid. Inventory is not deducted until then.</div></div>
        <div class="col-md-4"><label class="form-label">Cheque Number <span class="text-danger">*</span></label><input type="text" id="soChequeNumber" class="form-control" autocomplete="off"></div>
        <div class="col-md-4"><label class="form-label">Bank Name <span class="text-danger">*</span></label><?php billingBankNameField('soBankName', '', 'autocomplete="off"'); ?></div>
        <div class="col-md-4"><label class="form-label">Cheque Date <span class="text-danger">*</span></label><input type="date" id="soChequeDate" class="form-control"></div>
      </div>
      <div id="soGcashFields" class="row g-2 mb-3" style="display:none">
        <div class="col-md-4">
          <label class="form-label" for="soGcashAccountName">GCash Account Name <span class="text-danger">*</span></label>
          <input type="text" id="soGcashAccountName" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soGcashAccountName')">
          <div class="invalid-feedback" id="soGcashAccountNameError">GCash Account Name is required.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soGcashAccountNumber">GCash Account Number <span class="text-danger">*</span></label>
          <input type="text" id="soGcashAccountNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soGcashAccountNumber')">
          <div class="invalid-feedback" id="soGcashAccountNumberError">GCash Account Number is required.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soGcashReferenceNumber">GCash Reference Number <span class="text-danger">*</span></label>
          <input type="text" id="soGcashReferenceNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soGcashReferenceNumber')">
          <div class="invalid-feedback" id="soGcashReferenceNumberError">GCash Reference Number is required.</div>
        </div>
      </div>
      <div id="soMayaFields" class="row g-2 mb-3" style="display:none">
        <div class="col-md-4">
          <label class="form-label" for="soMayaAccountName">Maya Account Name <span class="text-danger">*</span></label>
          <input type="text" id="soMayaAccountName" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soMayaAccountName')">
          <div class="invalid-feedback" id="soMayaAccountNameError">Maya Account Name is required.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soMayaAccountNumber">Maya Account Number <span class="text-danger">*</span></label>
          <input type="text" id="soMayaAccountNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soMayaAccountNumber')">
          <div class="invalid-feedback" id="soMayaAccountNumberError">Maya Account Number is required.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soMayaReferenceNumber">Maya Reference Number <span class="text-danger">*</span></label>
          <input type="text" id="soMayaReferenceNumber" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soMayaReferenceNumber')">
          <div class="invalid-feedback" id="soMayaReferenceNumberError">Maya Reference Number is required.</div>
        </div>
      </div>
      <div id="soBankTransferFields" class="row g-2 mb-3" style="display:none">
        <div class="col-md-4">
          <label class="form-label" for="soBtBankName">Bank Name <span class="text-danger">*</span></label>
          <?php billingBankNameField('soBtBankName', '', 'autocomplete="off" oninput="if(typeof soClearPayFieldError===\'function\')soClearPayFieldError(\'soBtBankName\')" onchange="if(typeof soClearPayFieldError===\'function\')soClearPayFieldError(\'soBtBankName\')"'); ?>
          <div class="invalid-feedback" id="soBtBankNameError">Bank Name is required.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soBtSenderName">Sender / Account Name <span class="text-danger">*</span></label>
          <input type="text" id="soBtSenderName" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soBtSenderName')">
          <div class="invalid-feedback" id="soBtSenderNameError">Sender / Account Name is required.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soBtSenderAccount">Sender Account Number</label>
          <input type="text" id="soBtSenderAccount" class="form-control" autocomplete="off" placeholder="Optional">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soBtReference">Reference / Transaction Number <span class="text-danger">*</span></label>
          <input type="text" id="soBtReference" class="form-control" autocomplete="off" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soBtReference')">
          <div class="invalid-feedback" id="soBtReferenceError">Reference / Transaction Number is required.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="soBtDate">Transfer Date <span class="text-danger">*</span></label>
          <input type="date" id="soBtDate" class="form-control" oninput="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soBtDate')" onchange="if(typeof soClearPayFieldError==='function')soClearPayFieldError('soBtDate')">
          <div class="invalid-feedback" id="soBtDateError">Transfer Date is required.</div>
        </div>
      </div>
      <div id="soCreditFields" class="mb-3" style="display:none">
        <div class="alert alert-info py-2 small">A <strong>Credit Agreement / Contract</strong> is generated for this sale. The order stays tracked until the outstanding balance is settled.</div>
        <label class="form-label">Credit terms / contract notes <span class="text-danger">*</span></label>
        <textarea id="soCreditTerms" class="form-control" rows="3" placeholder="Payment schedule, due terms, authorized purchaser, etc."></textarea>
        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="soPrintCreditAgreementBtn" onclick="printPosCreditAgreementPreview()">Preview / Print Credit Agreement</button>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="soCreditAck">
          <label class="form-check-label" for="soCreditAck">Generate credit agreement/contract for this customer</label>
        </div>
      </div>
      </section>
      <section class="so-pos-card so-pos-summary">
        <div class="so-pos-card-title"><span class="so-pos-ico"><i class="bi bi-receipt"></i></span> Order Summary</div>
      <div class="so-totals">
        <div class="d-flex justify-content-between mb-2"><span>Subtotal</span><strong id="soLblSubtotal">₱0.00</strong></div>
        <div class="d-flex justify-content-between mb-2"><span>Discount</span><strong id="soLblDiscount" class="text-danger">₱0.00</strong></div>
        <div class="d-flex justify-content-between mb-2"><span>Tax (<span id="soTaxRateLabel">12</span>%)</span><strong id="soLblTax">₱0.00</strong></div>
        <div class="d-flex justify-content-between mb-2"><span>Delivery</span><strong id="soLblDelivery">₱0.00</strong></div>
        <div class="so-pos-total">
          <span>Total Amount</span>
          <strong id="soLblGrand">₱0.00</strong>
        </div>
        <p class="small text-muted mb-0 mt-2">Total Amount = Subtotal − Discount + Tax (+ delivery if any). Tax updates automatically when products, quantity, price, or discount change.</p>
      </div>
      <button type="button" class="btn btn-primary so-pos-submit" onclick="submitCreateSalesOrder()"><i class="bi bi-check-lg me-1"></i> Submit Sale</button>
      </section>
      </aside>
    </div>
  </div>
</div>
    <?php
}

function salesOrderRenderModals(): void {
    ?>

<div class="modal-overlay" id="viewSalesOrderModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-receipt text-primary"></i> Sales Order <span id="soViewNumber"></span></span>
      <button class="modal-close" onclick="closeModal('viewSalesOrderModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="soViewBody">
      <p class="text-muted mb-0">Loading…</p>
    </div>
    <div class="modal-footer" id="soViewFooter">
      <button class="btn btn-outline-secondary" onclick="closeModal('viewSalesOrderModal')">Close</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="salesStockOutModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-box-arrow-up text-warning"></i> Stock-Out — <span id="soOutNumber"></span></span>
      <button class="modal-close" onclick="closeModal('salesStockOutModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <p class="small text-muted">Scan each unit leaving the warehouse. Quantity increases by 1 per valid scan and cannot exceed the sales order qty or available stock.</p>
      <div class="d-flex gap-2 mb-3">
        <input type="text" id="soOutBarcodeScan" class="form-control" placeholder="Scan barcode…" autocomplete="off">
        <button type="button" class="btn btn-outline-primary" onclick="submitSalesStockOutBarcode()"><i class="bi bi-upc-scan"></i> Scan</button>
      </div>
      <div class="table-container system-table-scroll">
        <table class="modern-table">
          <thead><tr><th>Product</th><th>Ordered</th><th>Scanned</th><th>Available</th></tr></thead>
          <tbody id="soOutLinesBody"></tbody>
        </table>
      </div>
      <p class="small mt-2 mb-0" id="soOutHint"></p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" onclick="closeModal('salesStockOutModal')">Cancel</button>
      <button class="btn btn-warning" id="soOutSubmitBtn" onclick="submitSalesStockOut()"><i class="bi bi-box-arrow-up me-1"></i> Confirm Stock-Out</button>
    </div>
  </div>
</div>
    <?php
}
