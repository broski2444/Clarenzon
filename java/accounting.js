function showSection(section, btn) {
    const titles = {
        dashboard: 'Dashboard',
        'sales-orders': 'Sales Orders',
        'sales-credit': 'Credit Agreements',
        invoices: 'Account Receivable',
        'billing-invoices': 'Account Receivable',
        unpaid: 'Accounts Receivable / Pending',
        'unpaid-invoices': 'Accounts Receivable / Pending',
        reports: 'Inventory Reports',
        settings: 'Settings'
    };
    const currentTitle = titles[section] || 'Accounting Dashboard';
    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.sidebar-nav .nav-item').forEach(l => l.classList.remove('active'));
    const target = document.getElementById('section-' + section);
    if (target) target.classList.add('active');
    
    if (btn) {
        btn.classList.add('active');
    } else {
        const matchingLink = document.querySelector(`.sidebar-nav .nav-item[onclick*="${section}"]`);
        if (matchingLink) matchingLink.classList.add('active');
    }
    document.title = currentTitle + ' | Clarenzon Medical';
}

// ─── BILLING & INVOICING HANDLERS ───

function payMethodIsCheque() {
    const method = document.getElementById('pay_method')?.value || '';
    return method === 'Cheque' || method === 'Check';
}

function clearChequeDateError() {
    const cdt = document.getElementById('pay_cheque_date');
    const err = document.getElementById('pay_cheque_date_error');
    if (cdt) cdt.classList.remove('is-invalid');
}

function showChequeDateError(message) {
    const cdt = document.getElementById('pay_cheque_date');
    const err = document.getElementById('pay_cheque_date_error');
    if (cdt) {
        cdt.classList.add('is-invalid');
        try { cdt.focus(); } catch (e) {}
    }
    if (err) err.textContent = message || 'Cheque Date is required.';
}

function validateChequeDateField() {
    if (!payMethodIsCheque()) {
        clearChequeDateError();
        return true;
    }
    const cdt = document.getElementById('pay_cheque_date');
    const value = (cdt?.value || '').trim();
    if (value === '') {
        showChequeDateError('Cheque Date is required.');
        return false;
    }
    if (Number.isNaN(Date.parse(value))) {
        showChequeDateError('Cheque Date is invalid.');
        return false;
    }
    clearChequeDateError();
    return true;
}

function resetRecordPaymentSubmit() {
    const form = document.getElementById('recordPaymentForm') || document.querySelector('#recordPaymentModal form');
    if (form) form.dataset.submitting = '';
    const btn = document.getElementById('recordPaymentSubmitBtn') || form?.querySelector('[name="record_payment"]');
    if (btn) {
        btn.disabled = false;
        if (btn.dataset.originalLabel) btn.textContent = btn.dataset.originalLabel;
    }
}

function closeRecordPaymentModal() {
    const modalEl = document.getElementById('recordPaymentModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    const inst = bootstrap.Modal.getInstance(modalEl);
    if (inst) inst.hide();
}

function bindRecordPaymentForm() {
    const form = document.getElementById('recordPaymentForm') || document.querySelector('#recordPaymentModal form');
    if (!form || form.dataset.chequeDateBound === '1') return;
    form.dataset.chequeDateBound = '1';
    form.setAttribute('target', '_blank');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!validateChequeDateField()) {
            resetRecordPaymentSubmit();
            return;
        }
        if (form.dataset.submitting === '1') return;
        if (!form.querySelector('input[type="hidden"][name="record_payment"]')) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'record_payment';
            hidden.value = '1';
            form.appendChild(hidden);
        }
        form.dataset.submitting = '1';
        const btn = document.getElementById('recordPaymentSubmitBtn') || form.querySelector('#recordPaymentSubmitBtn');
        if (btn) {
            if (!btn.dataset.originalLabel) btn.dataset.originalLabel = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Submitting…';
        }
        const invoiceWin = window.open('about:blank', '_blank');
        const fd = new FormData(form);
        if (!fd.has('record_payment')) fd.append('record_payment', '1');
        const postUrl = form.getAttribute('action') || (window.location.pathname + window.location.search);
        fetch(postUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(function (r) {
                return r.json().then(function (data) {
                    if (!r.ok || !data || data.success === false) {
                        throw new Error((data && data.message) || 'Failed to record payment.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                const url = data.invoice_url || data.receipt_url;
                if (url) {
                    if (invoiceWin && !invoiceWin.closed) invoiceWin.location.replace(url);
                    else window.open(url, '_blank');
                } else if (invoiceWin && !invoiceWin.closed) {
                    invoiceWin.close();
                }
                closeRecordPaymentModal();
            })
            .catch(function (err) {
                if (invoiceWin && !invoiceWin.closed) invoiceWin.close();
                alert(err.message || 'Failed to record payment.');
            })
            .finally(function () {
                resetRecordPaymentSubmit();
            });
    });
    const submitBtn = document.getElementById('recordPaymentSubmitBtn') || form.querySelector('[name="record_payment"]');
    if (submitBtn) {
        submitBtn.addEventListener('click', function (e) {
            if (!validateChequeDateField()) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }
}

function toggleChequePayFields() {
    const method = document.getElementById('pay_method')?.value || '';
    const box = document.getElementById('chequePayFields');
    const isCheque = method === 'Cheque' || method === 'Check';
    if (box) box.style.display = isCheque ? '' : 'none';
    const bank = document.getElementById('pay_bank_name');
    const chq = document.getElementById('pay_cheque_number');
    const cdt = document.getElementById('pay_cheque_date');
    if (bank) bank.required = isCheque;
    if (chq) chq.required = isCheque;
    if (cdt) cdt.required = false;
    if (!isCheque) clearChequeDateError();
}

function openPaymentModal(invoiceId, invoiceNumber, grandTotal, remainingBalance) {
    const modalEl = document.getElementById('recordPaymentModal');
    if (!modalEl) return;
    
    document.getElementById('pay_invoice_id').value = invoiceId;
    document.getElementById('pay_invoice_number').textContent = invoiceNumber;
    document.getElementById('pay_grand_total').textContent = '₱' + Number(grandTotal).toFixed(2);
    document.getElementById('pay_remaining_balance').textContent = '₱' + Number(remainingBalance).toFixed(2);
    
    const amountInput = document.getElementById('pay_amount_paid');
    amountInput.value = Number(remainingBalance).toFixed(2);
    amountInput.max = remainingBalance;
    const method = document.getElementById('pay_method');
    if (method) method.value = 'Cash';
    const bank = document.getElementById('pay_bank_name');
    const chq = document.getElementById('pay_cheque_number');
    const cdt = document.getElementById('pay_cheque_date');
    if (bank) bank.value = '';
    if (chq) chq.value = '';
    if (cdt) cdt.value = '';
    if (typeof resetRecordPaymentSubmit === 'function') resetRecordPaymentSubmit();
    if (typeof clearChequeDateError === 'function') clearChequeDateError();
    toggleChequePayFields();
    
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function resolveInvoiceDetails(source, itemsJsonStr) {
    if (source && source.nodeType === 1) {
        const id = String(source.getAttribute('data-invoice-id') || '');
        if (id && window.INVOICE_DETAILS && window.INVOICE_DETAILS[id]) {
            return window.INVOICE_DETAILS[id];
        }
        const raw = source.getAttribute('data-invoice') || (source.dataset && source.dataset.invoice) || '';
        if (raw) {
            try { return JSON.parse(raw); } catch (_) {}
        }
        return {};
    }
    let items = [];
    try { items = JSON.parse(itemsJsonStr || '[]'); } catch (_) { items = []; }
    return {
        invoice_number: source || '',
        items: items
    };
}

function openInvoiceDetailsModal(source, customer, subtotal, discount, tax, grandTotal, balance, date, status, itemsJsonStr) {
    const modalEl = document.getElementById('invoiceDetailsModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const data = resolveInvoiceDetails(source, itemsJsonStr);
    const invoiceNumber = data.invoice_number || (typeof source === 'string' ? source : '');
    customer = data.customer_name != null ? data.customer_name : (customer || '');
    subtotal = data.subtotal != null ? data.subtotal : subtotal;
    discount = data.discount_amount != null ? data.discount_amount : discount;
    tax = data.tax_amount != null ? data.tax_amount : tax;
    grandTotal = data.grand_total != null ? data.grand_total : grandTotal;
    balance = data.remaining_balance != null ? data.remaining_balance : balance;
    date = data.created_at || date || '';
    status = data.display_status || data.payment_status || status || 'Unpaid';
    const items = Array.isArray(data.items) ? data.items : [];

    const extra = [];
    if (data.sales_order_number) extra.push('SO ' + data.sales_order_number);
    if (data.customer_po_number) extra.push('Customer PO ' + data.customer_po_number);
    if (data.sale_type_label || data.sale_type) extra.push(data.sale_type_label || data.sale_type);
    if (data.payment_method_label) extra.push(data.payment_method_label);
    const extraEl = document.getElementById('det_invoice_refs');
    if (extraEl) extraEl.textContent = extra.join(' · ');

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };
    setText('det_inv_number', invoiceNumber);
    setText('det_customer', customer);
    setText('det_date', date);
    setText('det_subtotal', '₱' + Number(subtotal || 0).toFixed(2));
    setText('det_discount', '₱' + Number(discount || 0).toFixed(2));
    setText('det_tax', '₱' + Number(tax || 0).toFixed(2));
    setText('det_grand_total', '₱' + Number(grandTotal || 0).toFixed(2));
    setText('det_balance', '₱' + Number(balance || 0).toFixed(2));

    let statusClass = 'badge-warning';
    if (status === 'Paid') statusClass = 'badge-green';
    else if (status === 'Pending') statusClass = 'badge-warning';
    else if (status === 'Unpaid' || status === 'Unpaid / Outstanding' || !status) statusClass = 'badge-danger';

    const statusContainer = document.getElementById('det_status');
    if (statusContainer) {
        statusContainer.className = 'badge ' + statusClass;
        statusContainer.textContent = status || 'Unpaid';
    }

    const tbody = document.getElementById('det_items_tbody');
    if (tbody) {
        tbody.innerHTML = '';
        items.forEach(item => {
            const qty = Number(item.quantity || 0);
            const price = Number(item.unit_price || 0);
            const amount = qty * price;
            const rate = Number(item.tax_rate || data.tax_rate || 12);
            const taxInc = item.tax_included != null && item.tax_included !== ''
                ? Number(item.tax_included)
                : (rate > 0 ? amount * rate / (100 + rate) : 0);
            const uom = item.unit_of_measure || item.uom || '—';
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${item.product_sku || 'N/A'}</td>
                <td><strong>${item.product_name || 'N/A'}</strong></td>
                <td>${uom}</td>
                <td>${qty}</td>
                <td>₱${price.toFixed(2)}</td>
                <td>₱${taxInc.toFixed(2)}</td>
                <td><strong>₱${amount.toFixed(2)}</strong></td>
            `;
            tbody.appendChild(row);
        });
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No line items found for this invoice.</td></tr>';
        }
    }

    const printBtn = document.getElementById('btnPrintInvoice');
    if (printBtn) {
        printBtn.onclick = () => {
            window.open('print_invoice.php?invoice_number=' + encodeURIComponent(invoiceNumber) + '&with_receipt=1', '_blank');
        };
    }
    const receiptBtn = document.getElementById('btnPrintReceipt');
    if (receiptBtn) {
        receiptBtn.onclick = () => {
            const payNo = data.latest_payment_number || '';
            const url = payNo
                ? 'print_payment_receipt.php?payment_number=' + encodeURIComponent(payNo)
                : 'print_payment_receipt.php?invoice_number=' + encodeURIComponent(invoiceNumber);
            window.open(url, '_blank');
        };
    }

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

if (!window._invoiceViewClickBound) {
    window._invoiceViewClickBound = true;
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-view-invoice');
        if (!btn) return;
        e.preventDefault();
        openInvoiceDetailsModal(btn);
    });
}

function setSalesCategoryTab(btn) {
    if (!btn) return;
    const target = btn.dataset.target || 'invoices';
    const type = btn.dataset.saleType || '';
    document.querySelectorAll('.js-sales-type-tab[data-target="' + target + '"]').forEach(b => {
        b.classList.remove('btn-primary');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-primary');
    if (target === 'orders') {
        const el = document.getElementById('soTypeFilter');
        if (el) el.value = type;
        if (type === '') {
            const statusEl = document.getElementById('soStatusFilter');
            if (statusEl) statusEl.value = '';
        }
        if (typeof filterSalesOrders === 'function') filterSalesOrders();
        return;
    }
    const el = document.getElementById('invoiceTypeFilter');
    if (el) el.value = type;
    if (typeof filterInvoices === 'function') filterInvoices();
}

function billingStatusFilterMatches(row, filterStatus) {
    if (!filterStatus) return true;
    const display = (row.dataset.displayStatus || row.dataset.status || '').toLowerCase();
    const raw = (row.dataset.status || '').toLowerCase();
    const f = String(filterStatus).toLowerCase();
    if (f === 'unpaid / outstanding' || f === 'unpaid') {
        return display.indexOf('unpaid') !== -1 || raw === 'unpaid' || raw === 'partially paid';
    }
    return display === f || raw === f;
}

function filterInvoices() {
    const searchEl = document.getElementById('invoiceSearchInput');
    const statusEl = document.getElementById('invoiceStatusFilter');
    if (!searchEl || !statusEl) return;

    const filterText = searchEl.value.toLowerCase();
    const filterStatus = statusEl.value.toLowerCase();
    const filterType = (document.getElementById('invoiceTypeFilter')?.value || '').toLowerCase();
    const rows = document.querySelectorAll('#invoicesTable tbody tr');
    
    rows.forEach(row => {
        const textMatch = row.textContent.toLowerCase().includes(filterText);
        const statusMatch = billingStatusFilterMatches(row, filterStatus);
        const typeMatch = !filterType || (row.dataset.saleType || '').toLowerCase() === filterType;
        row.style.display = (textMatch && statusMatch && typeMatch) ? '' : 'none';
    });
}

function filterSalesOrders() {
    const searchEl = document.getElementById('soSearchInput');
    if (!searchEl && !document.getElementById('soTypeFilter')) return;

    const filterText = (searchEl?.value || '').toLowerCase();
    const filterStatus = (document.getElementById('soStatusFilter')?.value || '').toLowerCase();
    const filterType = (document.getElementById('soTypeFilter')?.value || '').toLowerCase();
    const tbody = document.querySelector('#salesOrdersTable tbody');
    const rows = tbody ? tbody.querySelectorAll(':scope > tr') : [];

    rows.forEach(row => {
        if (row.children.length === 1 && row.children[0].hasAttribute('colspan')) return;
        const textMatch = row.textContent.toLowerCase().includes(filterText);
        const rowStatus = (row.dataset.status || '').toLowerCase();
        const displayStatus = (row.dataset.displayStatus || '').toLowerCase();
        const statusMatch = filterStatus === ''
            || (filterStatus === 'active' ? rowStatus !== 'approved' : (
                filterStatus === 'open credit' ? displayStatus === 'open credit' : rowStatus === filterStatus
            ));
        const typeMatch = !filterType || (row.dataset.saleType || '').toLowerCase() === filterType;
        const visible = textMatch && statusMatch && typeMatch;
        if (visible) row.removeAttribute('data-clar-hide');
        else row.setAttribute('data-clar-hide', '1');
    });
    if (tbody && typeof window.clarenzonRefreshListPager === 'function') {
        window.clarenzonRefreshListPager(tbody, true);
        return;
    }
    rows.forEach(row => {
        if (row.children.length === 1 && row.children[0].hasAttribute('colspan')) return;
        row.style.display = row.getAttribute('data-clar-hide') === '1' ? 'none' : '';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof bindRecordPaymentForm === 'function') bindRecordPaymentForm();
    if (typeof filterSalesOrders === 'function') filterSalesOrders();
    const dataEl = document.getElementById('accounting-js-data');
    let totalValue = 0;
    let valueChartLabels = ['No inventory'];
    let valueChartValues = [0];
    if (dataEl) {
        try {
            const parsed = JSON.parse(dataEl.textContent || '{}');
            totalValue = Number(parsed.totalValue || 0);
            if (Array.isArray(parsed.valueChartLabels) && parsed.valueChartLabels.length) {
                valueChartLabels = parsed.valueChartLabels;
            }
            if (Array.isArray(parsed.valueChartValues) && parsed.valueChartValues.length) {
                valueChartValues = parsed.valueChartValues;
            }
        } catch (_) {}
    }

    const ctx = document.getElementById('valueChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: valueChartLabels,
                datasets: [{
                    label: 'Inventory Value',
                    data: valueChartValues,
                    borderColor: '#4F46E5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#F3F4F6' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
