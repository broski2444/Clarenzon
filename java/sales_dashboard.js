function showSection(section, btn) {
    const titles = {
        dashboard: 'Dashboard',
        stocks: 'Product Catalog',
        orders: 'Sales Orders',
        'sales-orders': 'Sales Orders',
        'sales-credit': 'Credit Agreements',
        'product-pricing': 'Product Pricing',
        'my-stocks': 'My Allocated Stocks',
        'all-stocks': 'All Stock Levels',
        invoices: 'Billing & Invoices',
        unpaid: 'Accounts Receivable / Pending',
        'unpaid-invoices': 'Accounts Receivable / Pending',
        reports: 'Inventory Reports',
        settings: 'Settings',
        sales: 'Sales Activity',
        notifications: 'Alerts & History'
    };
    const currentTitle = titles[section] || 'Sales Orders';
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

function searchTable() {
    const input = document.getElementById('searchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const tables = ['allStocksTable', 'salesOrdersTable'];
    tables.forEach(tableId => {
        const table = document.getElementById(tableId);
        if (!table) return;
        const body = table.getElementsByTagName('tbody')[0];
        if (!body) return;
        const rows = body.getElementsByTagName('tr');
        for (let row of rows) {
            row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
        }
    });
}

// ─── SALES ORDER DYNAMIC ROWS ───
let productOptionsHtml = '';

function setProductOptions(optionsHtml) {
    productOptionsHtml = optionsHtml;
}

function addOrderRow() {
    const tbody = document.getElementById('orderItemsTbody');
    if (!tbody) return;
    
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <select name="product_ids[]" class="form-select product-select" required onchange="handleProductChange(this)">
                <option value="">-- Select Product --</option>
                ${productOptionsHtml}
            </select>
        </td>
        <td>
            <input type="number" name="quantities[]" class="form-control qty-input" min="1" value="1" required oninput="calculateTotals()">
        </td>
        <td>
            <div class="input-group">
                <span class="input-group-text">₱</span>
                <input type="number" name="prices[]" class="form-control price-input" step="0.01" min="0" readonly>
            </div>
        </td>
        <td>
            <div class="input-group">
                <span class="input-group-text">₱</span>
                <input type="number" class="form-control row-total-input" step="0.01" readonly value="0.00">
            </div>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeOrderRow(this)"><i class="bi bi-trash"></i></button>
        </td>
    `;
    tbody.appendChild(row);
    calculateTotals();
}

function removeOrderRow(btn) {
    const row = btn.closest('tr');
    if (row) {
        row.remove();
        calculateTotals();
    }
}

function handleProductChange(selectEl) {
    const option = selectEl.options[selectEl.selectedIndex];
    const price = parseFloat(option.dataset.price || 0);
    const stock = parseInt(option.dataset.stock || 0, 10) || 0;
    const row = selectEl.closest('tr');
    if (row) {
        const priceInput = row.querySelector('.price-input');
        if (priceInput) priceInput.value = price.toFixed(2);
        const qtyInput = row.querySelector('.qty-input');
        if (qtyInput && stock > 0) {
            qtyInput.max = String(stock);
            if ((parseInt(qtyInput.value || '0', 10) || 0) > stock) qtyInput.value = String(stock);
        }
        calculateTotals();
    }
}

function calculateTotals() {
    if (typeof recalcSalesOrderTotals === 'function') {
        recalcSalesOrderTotals();
        return;
    }
    let subtotal = 0;
    const rows = document.querySelectorAll('#orderItemsTbody tr');
    
    rows.forEach(row => {
        const select = row.querySelector('.product-select');
        const qtyInput = row.querySelector('.qty-input');
        const priceInput = row.querySelector('.price-input');
        const rowTotalInput = row.querySelector('.row-total-input');
        
        const qty = parseInt(qtyInput.value || 0);
        const price = parseFloat(priceInput.value || 0);
        const total = qty * price;
        
        rowTotalInput.value = total.toFixed(2);
        subtotal += total;
    });
    
    const discountPercent = parseFloat(document.getElementById('discountPercent').value || 0);
    const discountAmount = subtotal * (discountPercent / 100);
    const net = subtotal - discountAmount;
    const taxRatePct = 12;
    const taxAmount = net * taxRatePct / (100 + taxRatePct);
    const deliveryFee = parseFloat(document.getElementById('deliveryFee')?.value || 0) || 0;
    const grandTotal = net + Math.max(0, deliveryFee);

    document.getElementById('lblSubtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('lblDiscount').textContent = '₱' + discountAmount.toFixed(2);
    document.getElementById('lblTax').textContent = '₱' + taxAmount.toFixed(2);
    const feeLbl = document.getElementById('lblDeliveryFee');
    if (feeLbl) feeLbl.textContent = '₱' + Math.max(0, deliveryFee).toFixed(2);
    document.getElementById('lblGrandTotal').textContent = '₱' + grandTotal.toFixed(2);
    if (typeof soUpdateCashChange === 'function') soUpdateCashChange();
}

document.addEventListener('DOMContentLoaded', () => {
    // Initial calculation on load
    calculateTotals();
    if (typeof toggleSdChequeFields === 'function') toggleSdChequeFields();
    if (typeof filterSalesOrders === 'function') filterSalesOrders();
});

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

function filterSalesOrders() {
    const filterType = (document.getElementById('soTypeFilter')?.value || '').toLowerCase();
    const tbody = document.querySelector('#salesOrdersTable tbody');
    const rows = tbody ? tbody.querySelectorAll(':scope > tr') : [];
    rows.forEach(row => {
        if (row.children.length === 1 && row.children[0].hasAttribute('colspan')) return;
        const typeMatch = !filterType || (row.dataset.saleType || '').toLowerCase() === filterType;
        const isApproved = (row.dataset.status || '').toLowerCase() === 'approved';
        const visible = typeMatch && (filterType === '' || !isApproved);
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

function toggleSdChequeFields() {
    const st = document.getElementById('sdSaleType')?.value || 'Cash';
    const box = document.getElementById('sdChequeFields');
    const credit = document.getElementById('sdCreditFields');
    const isCheque = st === 'Cheque' || st === 'Check';
    if (box) box.style.display = isCheque ? '' : 'none';
    if (credit) credit.style.display = (st === 'Credit') ? '' : 'none';
    if (typeof soSyncCreditAgreementPrintButton === 'function') soSyncCreditAgreementPrintButton();
    ['sdChequeNumber', 'sdBankName', 'sdChequeDate'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.required = isCheque;
    });
    if (typeof soSyncPayDetailFields === 'function') soSyncPayDetailFields();
    if (typeof soSyncCashFields === 'function') soSyncCashFields();
}

function validateSdSalesOrder() {
    const st = document.getElementById('sdSaleType')?.value || 'Cash';
    if (typeof soValidateCashReceived === 'function' && !soValidateCashReceived()) {
        return false;
    }
    if (typeof soValidatePaymentDetailFields === 'function' && !soValidatePaymentDetailFields()) {
        return false;
    }
    if (st === 'Credit') {
        const terms = document.getElementById('soCreditTerms')?.value?.trim() || '';
        const ack = document.getElementById('soCreditAck')?.checked;
        if (!ack || !terms) {
            alert('Credit sales require a generated credit agreement/contract and terms.');
            return false;
        }
        return true;
    }
    if (st !== 'Cheque' && st !== 'Check') return true;
    const number = document.getElementById('sdChequeNumber')?.value?.trim() || '';
    const bank = document.getElementById('sdBankName')?.value?.trim() || '';
    const date = document.getElementById('sdChequeDate')?.value || '';
    if (!number || !bank || !date) {
        alert('Cheque Number, Bank Name, and Cheque Date are required.');
        return false;
    }
    return true;
}

/* ── Product selling price / markup ── */
let _ppCache = [];
let _ppEditMode = 'markup';

function ppMoney(n) {
    return '₱' + Number(n || 0).toFixed(2);
}

function ppEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function loadProductPricingTable() {
    const tbody = document.getElementById('productPricingTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Loading pricing…</td></tr>';
    const q = (document.getElementById('pricingSearchInput')?.value || '').trim();
    const url = 'product_pricing_api.php?action=list_product_pricing' + (q ? '&q=' + encodeURIComponent(q) : '');
    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">${ppEsc(res.message || 'Failed to load')}</td></tr>`;
                return;
            }
            if (typeof res.can_manage_pricing === 'boolean') {
                window.CLAR_CAN_MANAGE_PRICING = res.can_manage_pricing;
            }
            _ppCache = res.data || [];
            renderProductPricingTable(_ppCache);
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Failed to load pricing</td></tr>';
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
    if (!p) return;
    document.getElementById('pp_product_id').value = p.id;
    document.getElementById('pp_product_name').textContent = p.name || '—';
    document.getElementById('pp_product_sku').textContent = p.sku || '—';
    document.getElementById('pp_stock').value = Number(p.quantity_in_stock || 0);
    document.getElementById('pp_cost').value = Number(p.cost_price || 0).toFixed(2);
    document.getElementById('pp_markup').value = Number(p.markup_percent || 0).toFixed(2);
    document.getElementById('pp_selling').value = Number(p.selling_price || 0).toFixed(2);
    _ppEditMode = 'markup';
    const modalEl = document.getElementById('productPricingModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function ppRecalcFromMarkup() {
    _ppEditMode = 'markup';
    const cost = parseFloat(document.getElementById('pp_cost').value || 0);
    const markup = parseFloat(document.getElementById('pp_markup').value || 0);
    document.getElementById('pp_selling').value = (cost + (cost * (markup / 100))).toFixed(2);
}

function ppRecalcFromSelling() {
    _ppEditMode = 'selling';
    const cost = parseFloat(document.getElementById('pp_cost').value || 0);
    const sell = parseFloat(document.getElementById('pp_selling').value || 0);
    document.getElementById('pp_markup').value = (cost > 0 ? ((sell - cost) / cost) * 100 : 0).toFixed(2);
}

function saveProductPricing() {
    if (!window.CLAR_CAN_MANAGE_PRICING) {
        alert('You are not authorized to modify selling prices.');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'update_product_selling_price');
    fd.append('product_id', document.getElementById('pp_product_id').value);
    fd.append('mode', _ppEditMode === 'selling' ? 'selling' : 'markup');
    fd.append('markup_percent', document.getElementById('pp_markup').value);
    fd.append('selling_price', document.getElementById('pp_selling').value);
    const btn = document.getElementById('pp_save_btn');
    if (btn) btn.disabled = true;
    fetch('product_pricing_api.php', {
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
            const modalEl = document.getElementById('productPricingModal');
            if (modalEl && window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
            loadProductPricingTable();
        })
        .catch(() => alert('Failed to save selling price'))
        .finally(() => { if (btn) btn.disabled = false; });
}

/* Pending receipt markup (WH received → Sales pricing → Admin grant) */
function loadPendingReceiptMarkup() {
    const tbody = document.getElementById('pendingReceiptMarkupBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Loading…</td></tr>';
    fetch('procurement_api.php?action=get_stock_in_queue&workflow_status=' + encodeURIComponent('Received - Pending Markup'), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger">${ppEsc(res.message || 'Failed')}</td></tr>`;
                return;
            }
            const rows = res.data || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No receipts awaiting markup</td></tr>';
                return;
            }
            const canEdit = !!window.CLAR_CAN_MANAGE_PRICING || !!res.can_set_markup;
            tbody.innerHTML = rows.map(rec => `<tr>
                <td><strong>${ppEsc(rec.receipt_number)}</strong></td>
                <td>${ppEsc(rec.po_number)}</td>
                <td>${ppEsc(rec.supplier_name || '—')}</td>
                <td>${parseInt(rec.total_good, 10) || 0}</td>
                <td>${parseInt(rec.total_damaged, 10) || 0}</td>
                <td><span class="badge badge-warning">${ppEsc(rec.workflow_status)}</span></td>
                <td>${canEdit
                    ? `<button type="button" class="btn btn-sm btn-outline-primary" onclick="openSalesReceiptMarkup(${Number(rec.id)})">Set Markup</button>`
                    : '<span class="text-muted">View only</span>'}</td>
            </tr>`).join('');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Failed to load</td></tr>';
        });
}

function openSalesReceiptMarkup(receiptId) {
    fetch('procurement_api.php?action=get_receipt_detail&id=' + encodeURIComponent(receiptId), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success || !res.data) {
                alert(res.message || 'Receipt not found');
                return;
            }
            const rec = res.data;
            const lines = (rec.items || []).filter(it => (parseInt(it.quantity_good, 10) || 0) > 0);
            if (!lines.length) {
                alert('No good quantities to price on this receipt.');
                return;
            }
            appPrompt('Enter Markup % for all good lines on ' + rec.receipt_number + ' (cost-based):', '20').then(rawMarkup => {
            if (rawMarkup === null) return;
            const markup = parseFloat(rawMarkup);
            if (isNaN(markup) || markup < 0) {
                alert('Invalid markup %');
                return;
            }
            const items = lines.map(it => {
                const cost = parseFloat(it.cost_price || it.unit_price || 0);
                const sell = cost + (cost * (markup / 100));
                return { id: it.id, markup_percent: markup, selling_price: sell.toFixed(2) };
            });
            const fd = new FormData();
            fd.append('action', 'set_receipt_markup');
            fd.append('receipt_id', String(receiptId));
            fd.append('items', JSON.stringify(items));
            fd.append('submit_for_approval', '1');
            fetch('procurement_api.php', {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(r => r.json())
                .then(saveRes => {
                    alert(saveRes.message || (saveRes.success ? 'Submitted for Admin approval' : 'Failed'));
                    loadPendingReceiptMarkup();
                })
                .catch(() => alert('Failed to save markup'));
            });
        });
}
