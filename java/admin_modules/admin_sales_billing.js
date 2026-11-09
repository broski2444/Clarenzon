/* Sales order and billing admin dashboard actions */
function payMethodIsCheque() {
    const method = document.getElementById('pay_method')?.value || '';
    return method === 'Cheque' || method === 'Check';
}

function clearChequeDateError() {
    const cdt = document.getElementById('pay_cheque_date');
    const err = document.getElementById('pay_cheque_date_error');
    if (cdt) cdt.classList.remove('is-invalid');
    if (err) {
        err.textContent = 'Cheque Date is required.';
        err.style.removeProperty('display');
    }
}

function showChequeDateError(message) {
    const cdt = document.getElementById('pay_cheque_date');
    const err = document.getElementById('pay_cheque_date_error');
    if (cdt) {
        cdt.classList.add('is-invalid');
        try { cdt.focus(); } catch (e) {}
    }
    if (err) {
        err.textContent = message || 'Cheque Date is required.';
        err.style.removeProperty('display');
    }
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
    resetRecordPaymentSubmit();
    clearChequeDateError();
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
    const supplierInvoiceSearch = document.getElementById('invoiceSearch');
    const billingInvoiceSearch = document.getElementById('invoiceSearchInput');
    const supplierInvoiceTabActive = document.getElementById('pr-tab-pane-invoices')?.classList.contains('active') &&
        document.getElementById('section-purchase-requests')?.classList.contains('active');

    if (supplierInvoiceTabActive && supplierInvoiceSearch && document.getElementById('invoiceBody') && typeof loadSupplierInvoices === 'function') {
        loadSupplierInvoices();
        return;
    }

    if (!billingInvoiceSearch || !document.getElementById('invoiceStatusFilter')) return;

    const filterText = billingInvoiceSearch.value.toLowerCase();
    const filterStatus = document.getElementById('invoiceStatusFilter').value.toLowerCase();
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
    if (!document.getElementById('soSearchInput') && !document.getElementById('soTypeFilter')) return;

    const filterText = (document.getElementById('soSearchInput')?.value || '').toLowerCase();
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

/* ── Product selling price / markup (Sales module) ── */
let _ppCache = [];
let _ppEditMode = 'markup';

function ppMoney(n) {
    return '₱' + Number(n || 0).toFixed(2);
}

function ppEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function ppApiUrl(path) {
    const raw = String(path || '');
    if (typeof clarAuthUrl === 'function') return clarAuthUrl(raw);
    return raw;
}

function loadProductPricingTable() {
    const tbody = document.getElementById('productPricingTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Loading pricing…</td></tr>';
    const q = (document.getElementById('pricingSearchInput')?.value || '').trim();
    const url = ppApiUrl('product_pricing_api.php?action=list_product_pricing' + (q ? '&q=' + encodeURIComponent(q) : ''));
    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(async r => {
            const text = await r.text();
            let res;
            try { res = JSON.parse(text); } catch (_) {
                throw new Error(r.ok ? 'Invalid pricing response' : ('HTTP ' + r.status));
            }
            if (!r.ok || !res.success) {
                throw new Error(res.message || ('HTTP ' + r.status));
            }
            return res;
        })
        .then(res => {
            if (typeof res.can_manage_pricing === 'boolean') {
                window.CLAR_CAN_MANAGE_PRICING = res.can_manage_pricing;
            }
            _ppCache = res.data || [];
            renderProductPricingTable(_ppCache);
        })
        .catch((err) => {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">${ppEsc(err.message || 'Failed to load pricing')}</td></tr>`;
        });
}

function renderProductPricingTable(rows) {
    const tbody = document.getElementById('productPricingTbody');
    if (!tbody) return;
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No products found</td></tr>';
        return;
    }
    const canEdit = !!window.CLAR_CAN_MANAGE_PRICING;
    tbody.innerHTML = rows.map(p => {
        const updated = p.last_updated ? ppEsc(p.last_updated) : '—';
        const by = p.updated_by ? ppEsc(p.updated_by) : '—';
        const action = canEdit
            ? `<button type="button" class="btn btn-outline-primary btn-sm" onclick="openProductPricingModal(${Number(p.id)})"><i class="bi bi-pencil"></i> Set Price</button>`
            : `<span class="text-muted" style="font-size:12px;">View only</span>`;
        return `<tr>
            <td><strong>${ppEsc(p.name)}</strong></td>
            <td><span class="badge badge-blue">${ppEsc(p.sku)}</span></td>
            <td>${Number(p.quantity_in_stock || 0)}</td>
            <td>${ppMoney(p.cost_price)}</td>
            <td>${Number(p.markup_percent || 0).toFixed(2)}%</td>
            <td><strong>${ppMoney(p.selling_price)}</strong></td>
            <td>${updated}</td>
            <td>${by}</td>
            <td>${action}</td>
        </tr>`;
    }).join('');
}

function filterProductPricingTable() {
    const q = (document.getElementById('pricingSearchInput')?.value || '').toLowerCase().trim();
    if (!q) {
        renderProductPricingTable(_ppCache);
        return;
    }
    renderProductPricingTable(_ppCache.filter(p =>
        String(p.name || '').toLowerCase().includes(q) ||
        String(p.sku || '').toLowerCase().includes(q)
    ));
}

function openProductPricingModal(productId) {
    if (!window.CLAR_CAN_MANAGE_PRICING) {
        alert('You are not authorized to modify selling prices.');
        return;
    }
    const p = _ppCache.find(x => Number(x.id) === Number(productId));
    if (!p) {
        fetch(ppApiUrl('product_pricing_api.php?action=get_product_pricing&product_id=' + encodeURIComponent(productId)))
            .then(r => r.json())
            .then(res => {
                if (!res.success || !res.data) {
                    alert(res.message || 'Product not found');
                    return;
                }
                fillProductPricingModal(res.data);
            });
        return;
    }
    fillProductPricingModal(p);
}

function fillProductPricingModal(p) {
    document.getElementById('pp_product_id').value = p.id;
    document.getElementById('pp_product_name').textContent = p.name || '—';
    document.getElementById('pp_product_sku').textContent = p.sku || '—';
    document.getElementById('pp_stock').value = Number(p.quantity_in_stock || 0);
    document.getElementById('pp_cost').value = Number(p.cost_price || 0).toFixed(2);
    document.getElementById('pp_markup').value = Number(p.markup_percent || 0).toFixed(2);
    document.getElementById('pp_selling').value = Number(p.selling_price || 0).toFixed(2);
    _ppEditMode = 'markup';
    if (typeof openModal === 'function') openModal('productPricingModal');
}

function ppRecalcFromMarkup() {
    _ppEditMode = 'markup';
    const cost = parseFloat(document.getElementById('pp_cost').value || 0);
    const markup = parseFloat(document.getElementById('pp_markup').value || 0);
    const sell = cost + (cost * (markup / 100));
    document.getElementById('pp_selling').value = sell.toFixed(2);
}

function ppRecalcFromSelling() {
    _ppEditMode = 'selling';
    const cost = parseFloat(document.getElementById('pp_cost').value || 0);
    const sell = parseFloat(document.getElementById('pp_selling').value || 0);
    const markup = cost > 0 ? ((sell - cost) / cost) * 100 : 0;
    document.getElementById('pp_markup').value = markup.toFixed(2);
}

function saveProductPricing() {
    if (!window.CLAR_CAN_MANAGE_PRICING) {
        alert('You are not authorized to modify selling prices.');
        return;
    }
    const productId = document.getElementById('pp_product_id').value;
    const markup = document.getElementById('pp_markup').value;
    const selling = document.getElementById('pp_selling').value;
    const fd = new FormData();
    fd.append('action', 'update_product_selling_price');
    fd.append('product_id', productId);
    fd.append('mode', _ppEditMode === 'selling' ? 'selling' : 'markup');
    fd.append('markup_percent', markup);
    fd.append('selling_price', selling);
    const btn = document.getElementById('pp_save_btn');
    if (btn) btn.disabled = true;
    fetch(ppApiUrl('product_pricing_api.php'), {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                alert(res.message || 'Failed to save selling price');
                return;
            }
            if (typeof closeModal === 'function') closeModal('productPricingModal');
            if (typeof pToast === 'function') pToast(res.message || 'Selling price updated', 'success');
            loadProductPricingTable();
        })
        .catch(() => alert('Failed to save selling price'))
        .finally(() => { if (btn) btn.disabled = false; });
}

document.addEventListener('DOMContentLoaded', () => {
    bindRecordPaymentForm();
    if (document.getElementById('productPricingTbody')) {
        loadProductPricingTable();
    }
    if (typeof filterSalesOrders === 'function' && document.getElementById('soStatusFilter')) {
        filterSalesOrders();
    }
});
