/* ════════════════════════════════════════════════════════════
   sales_order_module.js — dedicated Sales Order workflow
   Create / scan / tax / view / credit / stock-out
════════════════════════════════════════════════════════════ */

let _soProductsCache = [];
window._pendingSoBarcode = window._pendingSoBarcode || '';
window._soOutState = null;

function soEsc(s) {
  if (s == null) return '';
  const d = document.createElement('div');
  d.textContent = String(s);
  return d.innerHTML;
}

function soToast(msg, type) {
  if (typeof pToast === 'function') pToast(msg, type || 'success');
  else alert(msg);
}

function soMoney(n) {
  return '₱' + (Number(n) || 0).toFixed(2);
}

function soTaxRate() {
  const n = Number(window.CLAR_SALES_TAX_RATE);
  return Number.isFinite(n) && n >= 0 ? n : 12;
}

function soBarcodeKey(code) {
  if (typeof clarBarcodeKey === 'function') return clarBarcodeKey(code);
  return String(code || '').trim();
}

function soSellableQty(p) {
  if (!p) return 0;
  if (p.sellable_qty != null && p.sellable_qty !== '') return parseInt(p.sellable_qty, 10) || 0;
  return parseInt(p.quantity_in_stock, 10) || 0;
}

function soLocalYmd() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return d.getFullYear() + '-' + m + '-' + day;
}

function soTracksExpiry(p) {
  const v = p && p.expiry_tracking;
  return v === 1 || v === '1' || v === true;
}

function soCatalogExpiryPast(p) {
  if (!p || (p.expiry_tracking != null && p.expiry_tracking !== '' && !soTracksExpiry(p))) return false;
  const exp = String(p.expiry_date || '').slice(0, 10);
  return !!(exp && exp < soLocalYmd());
}

function soFindByBarcode(code) {
  const key = soBarcodeKey(code);
  if (!key) return null;
  return _soProductsCache.find(p => {
    if (soBarcodeKey(p.barcode) === key) return true;
    if (soBarcodeKey(p.sku) === key) return true;
    const extras = p.barcode_values || (Array.isArray(p.barcodes) ? p.barcodes.map(b => b.barcode_value || b.barcode || b) : []);
    return extras.some(v => soBarcodeKey(v) === key);
  }) || null;
}

function soQtyOfProduct(productId, exceptTr) {
  let n = 0;
  const pidWanted = parseInt(productId, 10);
  document.querySelectorAll('#soLinesBody tr').forEach(tr => {
    if (tr === exceptTr) return;
    const pid = parseInt(tr.querySelector('.so-product')?.value || 0, 10);
    const qty = parseInt(tr.querySelector('.so-qty')?.value || 0, 10);
    if (pid === pidWanted) n += qty;
  });
  document.querySelectorAll('#orderItemsTbody tr').forEach(tr => {
    if (tr === exceptTr) return;
    const pid = parseInt(tr.querySelector('.product-select, select[name="product_ids[]"]')?.value || 0, 10);
    const qty = parseInt(tr.querySelector('.qty-input, input[name="quantities[]"]')?.value || 0, 10);
    if (pid === pidWanted) n += qty;
  });
  return n;
}

function soFindLineRow(productId) {
  const pidWanted = String(parseInt(productId, 10));
  const soRow = Array.from(document.querySelectorAll('#soLinesBody tr')).find(tr => {
    const sel = tr.querySelector('.so-product');
    return sel && sel.value === pidWanted;
  });
  if (soRow) return soRow;
  return Array.from(document.querySelectorAll('#orderItemsTbody tr')).find(tr => {
    const sel = tr.querySelector('.product-select, select[name="product_ids[]"]');
    return sel && sel.value === pidWanted;
  }) || null;
}

function soProductLabel(p) {
  const price = parseFloat(p.selling_price ?? p.unit_price ?? 0) || 0;
  const stock = soSellableQty(p);
  return `${p.sku || ''} — ${p.name || ''} (₱${price.toFixed(2)}, stock ${stock})`;
}

function soProductImagePath(product) {
  const raw = String(product?.product_image || product?.image || product?.image_path || product?.photo || '').trim();
  if (!raw) return '';
  if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:image/') || raw.startsWith('/')) return raw;
  const normalized = raw.replace(/\\/g, '/').replace(/^\/+/, '');
  const script = document.querySelector('script[src*="sales_order_module.js"]');
  const src = script ? (script.getAttribute('src') || '') : '';
  const prefix = src.indexOf('../') === 0 ? '../' : '';
  return prefix + (normalized.includes('/') ? normalized : ('uploads/' + normalized));
}

function soPaintLineThumb(tr, product) {
  const slot = tr && tr.querySelector('.so-pos-thumb');
  if (!slot) return;
  const path = product ? soProductImagePath(product) : '';
  const name = product ? String(product.name || product.sku || 'Product') : 'Product';
  slot.replaceChildren();
  if (!path) {
    slot.classList.add('so-pos-thumb-empty');
    slot.innerHTML = '<i class="bi bi-image" aria-hidden="true"></i>';
    slot.setAttribute('aria-label', 'No product image');
    return;
  }
  slot.classList.remove('so-pos-thumb-empty');
  slot.removeAttribute('aria-label');
  const img = document.createElement('img');
  img.alt = name;
  img.src = path;
  img.addEventListener('error', () => {
    slot.classList.add('so-pos-thumb-empty');
    slot.replaceChildren();
    slot.innerHTML = '<i class="bi bi-image" aria-hidden="true"></i>';
    slot.setAttribute('aria-label', 'No product image');
  });
  slot.appendChild(img);
}

function soFindCachedProduct(id) {
  const n = parseInt(id, 10);
  if (!n) return null;
  return _soProductsCache.find(p => parseInt(p.id, 10) === n) || null;
}

function soRankedProducts(query) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return _soProductsCache.slice();
  const starts = [];
  const contains = [];
  _soProductsCache.forEach(p => {
    const sku = String(p.sku || '').toLowerCase();
    const name = String(p.name || '').toLowerCase();
    const barcode = String(p.barcode || '').toLowerCase();
    const extra = (p.barcode_values || []).join(' ').toLowerCase();
    const nameSpaced = name.replace(/[_]+/g, ' ');
    const skuTail = sku.replace(/^sku-/, '');
    const exactBarcode = barcode && barcode === q;
    const nameStart = name.startsWith(q) || nameSpaced.startsWith(q);
    const skuStart = sku.startsWith(q) || skuTail.startsWith(q);
    const barcodeStart = barcode && barcode.startsWith(q);
    if (exactBarcode || extra.split(/\s+/).includes(q)) starts.unshift(p);
    else if (nameStart || skuStart || barcodeStart) starts.push(p);
    else if (name.includes(q) || nameSpaced.includes(q) || sku.includes(q) || skuTail.includes(q) || barcode.includes(q) || extra.includes(q)) contains.push(p);
  });
  return starts.concat(contains);
}

function soCloseProductCombo(combo) {
  if (!combo) return;
  const menu = combo.querySelector('.so-product-menu');
  if (menu) {
    menu.hidden = true;
    menu.innerHTML = '';
    menu.style.position = '';
    menu.style.top = '';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.width = '';
    menu.style.zIndex = '';
  }
}

function soPlaceLineProductMenu(combo) {
  const menu = combo && combo.querySelector('.so-product-menu');
  const scroll = combo && combo.closest('.so-pos-lines-scroll');
  if (!menu || !scroll || menu.hidden) return;
  const anchor = combo.querySelector('.so-product-search') || combo;
  const rect = anchor.getBoundingClientRect();
  menu.style.position = 'fixed';
  menu.style.top = (rect.bottom + 2) + 'px';
  menu.style.left = rect.left + 'px';
  menu.style.right = 'auto';
  menu.style.width = Math.max(rect.width, 280) + 'px';
  menu.style.zIndex = '80';
}

function soCloseAllProductCombos(except) {
  document.querySelectorAll('#soLinesBody .so-product-combo').forEach(combo => {
    if (combo !== except) soCloseProductCombo(combo);
  });
}

function soBindProductComboDocClose() {
  if (window._soComboDocBound) return;
  window._soComboDocBound = true;
  document.addEventListener('mousedown', (e) => {
    document.querySelectorAll('#soLinesBody .so-product-combo').forEach(combo => {
      if (!combo.contains(e.target)) soCloseProductCombo(combo);
    });
  });
}

function soRenderProductMenu(combo, query, highlightFirst) {
  const menu = combo.querySelector('.so-product-menu');
  if (!menu) return;
  const matches = soRankedProducts(query);
  if (!matches.length) {
    menu.innerHTML = '<div class="so-product-empty">No products found</div>';
    menu.hidden = false;
    soPlaceLineProductMenu(combo);
    return;
  }
  menu.innerHTML = matches.map((p, i) => {
    const active = highlightFirst && i === 0 ? ' active' : '';
    return `<button type="button" class="so-product-opt${active}" role="option" data-id="${p.id}">${soEsc(soProductLabel(p))}</button>`;
  }).join('');
  menu.hidden = false;
  soPlaceLineProductMenu(combo);
}

function soMoveProductHighlight(combo, dir) {
  const menu = combo.querySelector('.so-product-menu');
  if (!menu || menu.hidden) return;
  const opts = [...menu.querySelectorAll('.so-product-opt')];
  if (!opts.length) return;
  let idx = opts.findIndex(o => o.classList.contains('active'));
  idx = idx < 0 ? (dir > 0 ? 0 : opts.length - 1) : (idx + dir + opts.length) % opts.length;
  opts.forEach(o => o.classList.remove('active'));
  opts[idx].classList.add('active');
  opts[idx].scrollIntoView({ block: 'nearest' });
}

function soEnsureProductOption(sel, product) {
  if (!sel || !product) return;
  const id = String(product.id);
  if ([...sel.options].some(o => o.value === id)) return;
  const opt = document.createElement('option');
  const price = parseFloat(product.selling_price ?? product.unit_price ?? 0) || 0;
  const stock = soSellableQty(product);
  opt.value = id;
  opt.dataset.stock = String(stock);
  opt.dataset.price = String(price);
  opt.textContent = soProductLabel(product);
  sel.appendChild(opt);
}

function soApplyProductChoice(combo, product) {
  const sel = combo.querySelector('.so-product');
  const input = combo.querySelector('.so-product-search');
  if (!sel) return;
  if (!product) {
    sel.value = '';
    if (input) input.value = '';
  } else {
    soEnsureProductOption(sel, product);
    sel.value = String(product.id);
    if (input) input.value = soProductLabel(product);
  }
  sel.dispatchEvent(new Event('change', { bubbles: true }));
  soCloseProductCombo(combo);
}

function soPickHighlightedProduct(combo) {
  const active = combo.querySelector('.so-product-opt.active') || combo.querySelector('.so-product-opt');
  if (!active) return;
  soApplyProductChoice(combo, soFindCachedProduct(active.getAttribute('data-id')));
}

function soBindProductCombo(combo) {
  if (!combo || combo.dataset.bound === '1') return;
  combo.dataset.bound = '1';
  soBindProductComboDocClose();
  const input = combo.querySelector('.so-product-search');
  const menu = combo.querySelector('.so-product-menu');
  const sel = combo.querySelector('.so-product');
  if (!input || !menu || !sel) return;
  const scroll = combo.closest('.so-pos-lines-scroll');
  if (scroll && scroll.dataset.menuScrollBound !== '1') {
    scroll.dataset.menuScrollBound = '1';
    scroll.addEventListener('scroll', () => {
      document.querySelectorAll('#soLinesBody .so-product-combo').forEach((openCombo) => {
        const openMenu = openCombo.querySelector('.so-product-menu');
        if (openMenu && !openMenu.hidden) soPlaceLineProductMenu(openCombo);
      });
    });
  }

  const openFiltered = (highlightFirst) => {
    soCloseAllProductCombos(combo);
    soRenderProductMenu(combo, input.value, highlightFirst);
  };

  input.addEventListener('focus', () => openFiltered(!!String(input.value || '').trim()));
  input.addEventListener('input', () => {
    const chosen = soFindCachedProduct(sel.value);
    if (chosen && input.value !== soProductLabel(chosen)) {
      sel.value = '';
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    openFiltered(true);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (menu.hidden) openFiltered(true);
      else soMoveProductHighlight(combo, 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (menu.hidden) openFiltered(false);
      else soMoveProductHighlight(combo, -1);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (!menu.hidden) soPickHighlightedProduct(combo);
    } else if (e.key === 'Escape') {
      soCloseProductCombo(combo);
    }
  });
  menu.addEventListener('mousedown', (e) => {
    const opt = e.target.closest('.so-product-opt');
    if (!opt) return;
    e.preventDefault();
    soApplyProductChoice(combo, soFindCachedProduct(opt.getAttribute('data-id')));
  });
}

function addSalesOrderLine(opts) {
  const body = document.getElementById('soLinesBody');
  if (!body) {
    if (typeof addOrderRow === 'function') addOrderRow();
    return body;
  }
  const startQty = opts && opts.qty ? parseInt(opts.qty, 10) || 1 : 1;
  const optsHtml = '<option value="">-- Select product --</option>' +
    _soProductsCache.map(p => {
      const price = parseFloat(p.selling_price ?? p.unit_price ?? 0) || 0;
      const stock = soSellableQty(p);
      return `<option value="${p.id}" data-stock="${stock}" data-price="${price}">${soEsc(p.sku)} — ${soEsc(p.name)} (₱${price.toFixed(2)}, stock ${stock})</option>`;
    }).join('');
  const tr = document.createElement('tr');
  tr.innerHTML = `<td>
      <div class="so-pos-product-cell">
        <div class="so-pos-thumb so-pos-thumb-empty" aria-label="No product image"><i class="bi bi-image" aria-hidden="true"></i></div>
        <div class="so-product-combo">
          <select class="form-control form-control-sm so-product so-product-native" tabindex="-1" aria-hidden="true" onchange="onSoProductChange(this)">${optsHtml}</select>
          <input type="text" class="form-control form-control-sm so-product-search" placeholder="-- Select product --" autocomplete="off" spellcheck="false">
          <div class="so-product-menu" hidden role="listbox"></div>
        </div>
      </div>
    </td>
    <td class="so-stock">—</td>
    <td class="so-price">—</td>
    <td class="so-batch small text-muted">FEFO: —</td>
    <td><input type="number" min="1" value="${startQty}" class="form-control form-control-sm so-qty" style="width:90px" oninput="onSoQtyInput(this)"></td>
    <td class="so-line-amt">₱0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); recalcSalesOrderTotals();"><i class="bi bi-x"></i></button></td>`;
  body.appendChild(tr);
  soBindProductCombo(tr.querySelector('.so-product-combo'));
  return tr;
}

function onSoQtyInput(input) {
  const tr = input.closest('tr');
  const sel = tr?.querySelector('.so-product');
  const pid = parseInt(sel?.value || 0, 10);
  const stock = parseInt(sel?.selectedOptions[0]?.dataset.stock || 0, 10);
  let qty = parseInt(input.value || 0, 10) || 0;
  if (pid && stock > 0) {
    const others = soQtyOfProduct(pid, tr);
    const max = Math.max(0, stock - others);
    if (qty > max) {
      qty = max;
      input.value = String(max);
      if (max <= 0) soToast('Quantity cannot exceed available stock.', 'warn');
    }
    input.max = String(max);
  }
  recalcSalesOrderTotals();
}

function onSoProductChange(sel) {
  const opt = sel.selectedOptions[0];
  const tr = sel.closest('tr');
  if (!tr || !opt) return;
  if (opt.value && (opt.getAttribute('data-stock') || opt.dataset.stock)) {
    tr.dataset.baseStock = opt.getAttribute('data-stock') || opt.dataset.stock;
  } else {
    delete tr.dataset.baseStock;
  }
  soPaintLineAvailable(tr);
  soPaintLineThumb(tr, sel.value ? soFindCachedProduct(sel.value) : null);
  tr.querySelector('.so-price').textContent = opt.dataset.price ? ('₱' + parseFloat(opt.dataset.price).toFixed(2)) : '—';
  const qty = tr.querySelector('.so-qty');
  if (qty && opt.dataset.stock) qty.max = opt.dataset.stock;
  const batchCell = tr.querySelector('.so-batch');
  if (batchCell) batchCell.textContent = 'FEFO: loading…';
  const pid = sel.value;
  if (!pid) {
    if (batchCell) batchCell.textContent = 'FEFO: —';
    recalcSalesOrderTotals();
    return;
  }
  const apiUrl = (typeof clarAuthUrl === 'function')
    ? clarAuthUrl('inventory_api.php?action=get_fifo_batches&product_id=' + encodeURIComponent(pid))
    : 'inventory_api.php?action=get_fifo_batches&product_id=' + encodeURIComponent(pid);
  fetch(apiUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(r => {
      if (!batchCell) return;
      const batches = r.data || [];
      if (!batches.length) {
        batchCell.textContent = 'FEFO: no lots (stocked qty only)';
        return;
      }
      const first = batches[0];
      const lot = String(first.manufacturer_lot || '').trim();
      const exp = String(first.expiry_date || '').trim();
      batchCell.innerHTML = `FEFO: <strong>${soEsc(lot || '—')}</strong>`
        + (exp && exp !== '0000-00-00' ? ` · exp ${soEsc(exp)}` : '')
        + ` (${first.quantity_remaining} avail)`;
    })
    .catch(() => { if (batchCell) batchCell.textContent = 'FEFO: —'; });
  recalcSalesOrderTotals();
}

function soLineOriginalStock(tr, fallback) {
  const incoming = parseInt(fallback, 10) || 0;
  if (!tr) return incoming;
  const stored = parseInt(tr.dataset.baseStock || '0', 10) || 0;
  if (stored > 0) return stored;
  const opt = tr.querySelector('.so-product')?.selectedOptions?.[0];
  const fromOpt = parseInt(opt?.getAttribute('data-stock') || opt?.dataset?.stock || '0', 10) || 0;
  const base = fromOpt || incoming;
  if (base > 0) tr.dataset.baseStock = String(base);
  return base;
}

function soPaintLineAvailable(tr, fallbackStock) {
  const cell = tr?.querySelector('.so-stock');
  if (!cell) return;
  const sel = tr.querySelector('.so-product');
  const pid = parseInt(sel?.value || '0', 10) || 0;
  if (!pid) {
    cell.textContent = '—';
    return;
  }
  const original = soLineOriginalStock(tr, fallbackStock);
  const qty = parseInt(tr.querySelector('.so-qty')?.value || '0', 10) || 0;
  cell.textContent = String(Math.max(0, original - qty));
}

function soRefreshAvailableDisplay() {
  document.querySelectorAll('#soLinesBody tr').forEach(tr => soPaintLineAvailable(tr));
}

function recalcSalesOrderTotals() {
  soRefreshAvailableDisplay();
  const rate = soTaxRate();
  const rateLbl = document.getElementById('soTaxRateLabel');
  if (rateLbl) rateLbl.textContent = String(rate);
  let subtotal = 0;
  document.querySelectorAll('#soLinesBody tr').forEach(tr => {
    const price = parseFloat(tr.querySelector('.so-product')?.selectedOptions[0]?.dataset.price || 0) || 0;
    const qty = parseInt(tr.querySelector('.so-qty')?.value || 0, 10) || 0;
    const line = qty * price;
    const cell = tr.querySelector('.so-line-amt');
    if (cell) cell.textContent = soMoney(line);
    subtotal += line;
  });
  document.querySelectorAll('#orderItemsTbody tr').forEach(tr => {
    const qty = parseInt(tr.querySelector('.qty-input, input[name="quantities[]"]')?.value || 0, 10) || 0;
    const price = parseFloat(tr.querySelector('.price-input, input[name="prices[]"]')?.value || 0) || 0;
    const line = qty * price;
    const rowTotal = tr.querySelector('.row-total-input');
    if (rowTotal) rowTotal.value = line.toFixed(2);
    subtotal += line;
  });
  const discountPct = parseFloat(document.getElementById('soDiscountPercent')?.value || document.getElementById('discountPercent')?.value || '0') || 0;
  const discount = subtotal * (Math.max(0, Math.min(100, discountPct)) / 100);
  const delivery = parseFloat(document.getElementById('soDeliveryFee')?.value || document.getElementById('deliveryFee')?.value || '0') || 0;
  const taxable = Math.max(0, subtotal - discount);
  const tax = taxable * (rate / 100);
  const grand = taxable + tax + Math.max(0, delivery);

  const amt = document.getElementById('soAmount');
  const saleType = document.getElementById('soSaleType')?.value || 'Cash';
  if (amt && amt.dataset.touched !== '1' && saleType !== 'Credit') {
    amt.value = grand > 0 ? grand.toFixed(2) : '0';
  }

  const setTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = soMoney(val); };
  setTxt('soLblSubtotal', subtotal);
  setTxt('soLblDiscount', discount);
  setTxt('soLblTax', tax);
  setTxt('soLblDelivery', delivery);
  setTxt('soLblGrand', grand);
  setTxt('lblSubtotal', subtotal);
  setTxt('lblDiscount', discount);
  setTxt('lblTax', tax);
  setTxt('lblDeliveryFee', delivery);
  setTxt('lblGrandTotal', grand);
  soUpdateCashChange();
}

function soRememberScannedKey(product, key) {
  if (!product || !key) return product;
  const extra = Array.isArray(product.barcode_values) ? product.barcode_values.slice() : [];
  if (!extra.some(v => soBarcodeKey(v) === key)) extra.push(key);
  product.barcode_values = extra;
  return product;
}

function soCommitScannedProduct(product) {
  if (!product) return false;
  const stock = soSellableQty(product);
  const price = parseFloat(product.selling_price ?? product.unit_price ?? 0) || 0;
  if (stock <= 0 || !(price > 0)) {
    soToast(
      stock <= 0 && soCatalogExpiryPast(product)
        ? 'This item has no sellable (unexpired) stock and cannot be sold or stocked out.'
        : 'This item is unavailable or not sellable.',
      'warn'
    );
    return false;
  }
  const used = soQtyOfProduct(product.id);
  if (used + 1 > stock) {
    soToast('Stock-out quantity cannot exceed available inventory (' + stock + ').', 'warn');
    return false;
  }
  if (!_soProductsCache.some(p => parseInt(p.id, 10) === parseInt(product.id, 10))) {
    _soProductsCache.push(product);
  }
  const existing = soFindLineRow(product.id);
  if (existing) {
    if (stock > 0 && !existing.dataset.baseStock) existing.dataset.baseStock = String(stock);
    const qtyInput = existing.querySelector('.so-qty, .qty-input, input[name="quantities[]"]');
    if (qtyInput) {
      qtyInput.value = String((parseInt(qtyInput.value || '0', 10) || 0) + 1);
      qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
    soPaintLineAvailable(existing, stock);
  } else {
    const empty = Array.from(document.querySelectorAll('#soLinesBody tr, #orderItemsTbody tr')).find(tr => {
      const sel = tr.querySelector('.so-product, .product-select, select[name="product_ids[]"]');
      return sel && !sel.value;
    }) || null;
    if (empty && empty.querySelector('.so-product-combo')) {
      const qtyInput = empty.querySelector('.so-qty');
      if (qtyInput) qtyInput.value = '1';
      soApplyProductChoice(empty.querySelector('.so-product-combo'), product);
    } else if (empty) {
      const sel = empty.querySelector('.product-select, select[name="product_ids[]"]');
      const qtyInput = empty.querySelector('.qty-input, input[name="quantities[]"]');
      if (qtyInput) qtyInput.value = '1';
      if (sel) {
        sel.value = String(product.id);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    } else if (document.getElementById('soLinesBody')) {
      const tr = addSalesOrderLine({ qty: 1 });
      const combo = tr.querySelector('.so-product-combo');
      if (combo) soApplyProductChoice(combo, product);
    } else if (typeof addOrderRow === 'function') {
      addOrderRow();
      const rows = document.querySelectorAll('#orderItemsTbody tr');
      const last = rows[rows.length - 1];
      const sel = last?.querySelector('.product-select, select[name="product_ids[]"]');
      if (sel) {
        sel.value = String(product.id);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    } else {
      return false;
    }
  }
  const scanInput = document.getElementById('soBarcodeScan') || document.getElementById('sdBarcodeScan');
  if (scanInput) scanInput.value = '';
  recalcSalesOrderTotals();
  soPaintLineAvailable(soFindLineRow(product.id), stock);
  soToast((product.sku || product.name || 'Item') + ' qty +1', 'success');
  focusSalesOrderBarcode();
  return true;
}

let _soLastScanKey = '';
let _soLastScanAt = 0;
let _soLookupBusy = false;

function soLookupProductThenCommit(key) {
  if (_soLookupBusy) return;
  _soLookupBusy = true;
  const url = (typeof clarAuthUrl === 'function')
    ? clarAuthUrl('scanner_api.php?action=product&code=' + encodeURIComponent(key))
    : 'scanner_api.php?action=product&code=' + encodeURIComponent(key);
  fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json().catch(() => ({ success: false })))
    .then(r => {
      _soLookupBusy = false;
      const product = (r && r.success) ? (r.data || (Array.isArray(r.results) ? r.results[0] : null)) : null;
      if (!product || !product.id) {
        soToast(r && r.message ? r.message : 'Scanned item is not in the Product Catalog for this sale.', 'warn');
        return;
      }
      soRememberScannedKey(product, key);
      soCommitScannedProduct(product);
    })
    .catch(() => {
      _soLookupBusy = false;
      soToast('Could not look up that barcode.', 'danger');
    });
}

function applySalesOrderBarcode(code) {
  const key = soBarcodeKey(code);
  if (!key) return false;
  const now = Date.now();
  if (_soLastScanKey === key && now - _soLastScanAt < 700) return true;
  _soLastScanKey = key;
  _soLastScanAt = now;
  const product = soFindByBarcode(key);
  if (product) return soCommitScannedProduct(product);
  if (!_soProductsCache.length) {
    window._pendingSoBarcode = key;
    soToast('Loading products…', 'info');
    loadSalesOrderProducts();
    return false;
  }
  soLookupProductThenCommit(key);
  return true;
}

function startSalesOrderCameraScanner() {}

function stopSalesOrderCameraScanner() {}

function toggleSalesOrderCameraScanner() {}

function submitSalesOrderBarcode() {
  const input = document.getElementById('soBarcodeScan') || document.getElementById('sdBarcodeScan');
  const val = input ? String(input.value || '').trim() : '';
  if (val) {
    if (applySalesOrderBarcode(val) && input) input.value = '';
    return;
  }
  soToast('Type a barcode or use a handheld scanner, then press Scan.', 'warn');
}

function isSalesOrderPosSectionActive() {
  const section = document.getElementById('section-sales-orders');
  return !!(section && section.classList.contains('active') && document.getElementById('soBarcodeScan'));
}

function bindSalesOrderWedgeScanner() {
  if (window._soWedgeBound) return;
  window._soWedgeBound = true;
  let buf = '';
  let last = 0;
  document.addEventListener('keydown', (e) => {
    if (!isSalesOrderPosSectionActive()) return;
    const t = e.target;
    if (t && (t.id === 'soCreditTerms' || t.tagName === 'TEXTAREA')) return;
    if (t && (t.id === 'soBarcodeScan' || t.id === 'sdBarcodeScan' || t.id === 'soOutBarcodeScan')) return;
    if (e.key === 'Enter') {
      if (buf.length >= 3 && Date.now() - last < 120) {
        e.preventDefault();
        applySalesOrderBarcode(buf);
        buf = '';
      }
      return;
    }
    if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) return;
    const now = Date.now();
    const gap = now - last;
    if (gap > 80) buf = '';
    last = now;
    buf += e.key;
    if (buf.length >= 2 && gap < 50) e.preventDefault();
  }, true);
}

function soClearPayFieldError(fieldId) {
  const el = document.getElementById(fieldId);
  const err = document.getElementById(fieldId + 'Error');
  if (el) el.classList.remove('is-invalid');
  if (err) err.style.removeProperty('display');
}

function soShowPayFieldError(fieldId, message, shouldFocus) {
  const el = document.getElementById(fieldId);
  const err = document.getElementById(fieldId + 'Error');
  if (el) {
    el.classList.add('is-invalid');
    if (shouldFocus) {
      try { el.focus(); } catch (e) {}
    }
  }
  if (err) {
    err.textContent = message || 'This field is required.';
    err.style.removeProperty('display');
  }
}

function soPayDetailGroups() {
  return [
    {
      type: 'GCash',
      boxes: ['soGcashFields', 'sdGcashFields'],
      required: [
        { so: 'soGcashAccountName', sd: 'sdGcashAccountName', message: 'GCash Account Name is required.' },
        { so: 'soGcashAccountNumber', sd: 'sdGcashAccountNumber', message: 'GCash Account Number is required.' },
        { so: 'soGcashReferenceNumber', sd: 'sdGcashReferenceNumber', message: 'GCash Reference Number is required.' }
      ],
      optional: []
    },
    {
      type: 'Maya',
      boxes: ['soMayaFields', 'sdMayaFields'],
      required: [
        { so: 'soMayaAccountName', sd: 'sdMayaAccountName', message: 'Maya Account Name is required.' },
        { so: 'soMayaAccountNumber', sd: 'sdMayaAccountNumber', message: 'Maya Account Number is required.' },
        { so: 'soMayaReferenceNumber', sd: 'sdMayaReferenceNumber', message: 'Maya Reference Number is required.' }
      ],
      optional: []
    },
    {
      type: 'Bank Transfer',
      boxes: ['soBankTransferFields', 'sdBankTransferFields'],
      required: [
        { so: 'soBtBankName', sd: 'sdBtBankName', message: 'Bank Name is required.' },
        { so: 'soBtSenderName', sd: 'sdBtSenderName', message: 'Sender / Account Name is required.' },
        { so: 'soBtReference', sd: 'sdBtReference', message: 'Reference / Transaction Number is required.' },
        { so: 'soBtDate', sd: 'sdBtDate', message: 'Transfer Date is required.', date: true }
      ],
      optional: ['soBtSenderAccount', 'sdBtSenderAccount']
    }
  ];
}

function soPayDetailFieldId(spec) {
  const soEl = document.getElementById(spec.so);
  if (soEl) return spec.so;
  if (document.getElementById(spec.sd)) return spec.sd;
  return spec.so;
}

function soClearPayDetailGroup(group, clearValues) {
  (group.required || []).forEach((spec) => {
    [spec.so, spec.sd].forEach((id) => {
      const el = document.getElementById(id);
      if (el && clearValues) el.value = '';
      soClearPayFieldError(id);
    });
  });
  (group.optional || []).forEach((id) => {
    const el = document.getElementById(id);
    if (el && clearValues) el.value = '';
  });
}

function soSyncPayDetailFields() {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  soPayDetailGroups().forEach((group) => {
    const visible = st === group.type;
    (group.boxes || []).forEach((boxId) => {
      const box = document.getElementById(boxId);
      if (box) box.style.display = visible ? '' : 'none';
    });
    if (!visible) soClearPayDetailGroup(group, true);
  });
}

function soValidatePaymentDetailFields() {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  const group = soPayDetailGroups().find((g) => g.type === st);
  if (!group) return true;
  let ok = true;
  let focused = false;
  group.required.forEach((spec) => {
    const fieldId = soPayDetailFieldId(spec);
    const el = document.getElementById(fieldId);
    if (!el) return;
    const val = (el.value || '').trim();
    if (!val) {
      soShowPayFieldError(fieldId, spec.message, !focused);
      focused = true;
      ok = false;
      return;
    }
    if (spec.date && Number.isNaN(Date.parse(val))) {
      soShowPayFieldError(fieldId, 'Transfer Date is invalid.', !focused);
      focused = true;
      ok = false;
      return;
    }
    soClearPayFieldError(fieldId);
  });
  return ok;
}

function soAppendPayDetailFields(fd) {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  const val = (id) => (document.getElementById(id)?.value || '').trim();
  if (st === 'GCash') {
    fd.append('gcash_account_name', val('soGcashAccountName') || val('sdGcashAccountName'));
    fd.append('gcash_account_number', val('soGcashAccountNumber') || val('sdGcashAccountNumber'));
    fd.append('gcash_reference_number', val('soGcashReferenceNumber') || val('sdGcashReferenceNumber'));
  } else if (st === 'Maya') {
    fd.append('maya_account_name', val('soMayaAccountName') || val('sdMayaAccountName'));
    fd.append('maya_account_number', val('soMayaAccountNumber') || val('sdMayaAccountNumber'));
    fd.append('maya_reference_number', val('soMayaReferenceNumber') || val('sdMayaReferenceNumber'));
  } else if (st === 'Bank Transfer') {
    fd.append('bt_bank_name', val('soBtBankName') || val('sdBtBankName'));
    fd.append('bt_sender_name', val('soBtSenderName') || val('sdBtSenderName'));
    fd.append('bt_sender_account', val('soBtSenderAccount') || val('sdBtSenderAccount'));
    fd.append('bt_reference', val('soBtReference') || val('sdBtReference'));
    fd.append('bt_transfer_date', val('soBtDate') || val('sdBtDate'));
  }
}

function soSyncPaymentDueField() {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  const isCredit = st === 'Credit';
  const wrap = document.getElementById('soDueDateWrap');
  const due = document.getElementById('soDueDate');
  if (wrap) wrap.style.display = isCredit ? '' : 'none';
  if (due) {
    due.required = isCredit;
    if (!isCredit) {
      due.value = '';
    } else if (!due.value) {
      due.value = new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0];
    }
  }
  const amt = document.getElementById('soAmount');
  if (amt && amt.dataset.touched !== '1' && isCredit) amt.value = '';
  if (typeof recalcSalesOrderTotals === 'function') recalcSalesOrderTotals();
}

function soSyncCreditAgreementPrintButton() {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  const show = st === 'Credit';
  ['soPrintCreditAgreementBtn', 'sdPrintCreditAgreementBtn'].forEach((id) => {
    const btn = document.getElementById(id);
    if (btn) btn.style.display = show ? '' : 'none';
  });
}

function printPosCreditAgreementPreview() {
  const saleType = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || '';
  if (saleType !== 'Credit') return;
  let customer = '';
  if (document.getElementById('soCustomerFirstName') || document.getElementById('soCustomerLastName')) {
    customer = soComposeCustomerName();
  }
  if (!customer) {
    customer = (document.querySelector('#salesOrderForm [name="customer_name"]')?.value || document.getElementById('soCustomerName')?.value || '').trim();
  }
  const due = document.getElementById('soDueDate')?.value
    || document.querySelector('#salesOrderForm [name="due_date"]')?.value
    || '';
  const terms = document.getElementById('soCreditTerms')?.value || '';
  const total = soCurrentOrderTotal();
  const paidRaw = parseFloat(document.getElementById('soAmount')?.value || '0');
  const paid = Number.isFinite(paidRaw) ? Math.max(0, paidRaw) : 0;
  const balance = Math.max(0, Math.round((total - Math.min(paid, total)) * 100) / 100);
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'print_credit_agreement.php';
  form.target = '_blank';
  const fields = {
    preview: '1',
    customer_name: customer,
    due_date: due,
    credit_terms: terms,
    total_amount: total.toFixed(2),
    outstanding_balance: balance.toFixed(2)
  };
  Object.keys(fields).forEach((name) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = fields[name];
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
  form.remove();
}

function soCurrentOrderTotal() {
  const el = document.getElementById('soLblGrand') || document.getElementById('lblGrandTotal');
  const n = parseFloat(String(el?.textContent || '').replace(/[^\d.-]/g, ''));
  return Number.isFinite(n) ? n : 0;
}

function soCashReceivedInput() {
  return document.getElementById('soCashReceived') || document.getElementById('sdCashReceived');
}

function soCashChangeOutput() {
  return document.getElementById('soCashChange') || document.getElementById('sdCashChange');
}

function soUpdateCashChange() {
  const out = soCashChangeOutput();
  if (!out) return;
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  if (st !== 'Cash') {
    out.value = '₱0.00';
    return;
  }
  const raw = String(soCashReceivedInput()?.value || '').trim();
  const received = raw === '' ? 0 : parseFloat(raw);
  const change = (Number.isFinite(received) ? received : 0) - soCurrentOrderTotal();
  out.value = soMoney(Math.max(0, change));
}

function soSyncCashFields() {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  const isCash = st === 'Cash';
  ['soCashFields', 'sdCashFields'].forEach((id) => {
    const box = document.getElementById(id);
    if (box) box.style.display = isCash ? '' : 'none';
  });
  const received = soCashReceivedInput();
  if (received) {
    received.required = isCash;
    if (!isCash) received.value = '';
  }
  soUpdateCashChange();
}

function soValidateCashReceived() {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  if (st !== 'Cash') return true;
  const receivedEl = soCashReceivedInput();
  const total = soCurrentOrderTotal();
  const received = parseFloat(String(receivedEl?.value || '').trim());
  if (!Number.isFinite(received) || Math.round(received * 100) < Math.round(total * 100)) {
    const msg = 'Cash Received cannot be less than the Total Amount';
    if (typeof soToast === 'function') soToast(msg, 'warn');
    else alert(msg);
    try { receivedEl?.focus(); } catch (e) {}
    return false;
  }
  return true;
}

function toggleSoChequeFields() {
  const st = document.getElementById('soSaleType')?.value || document.getElementById('sdSaleType')?.value || 'Cash';
  const box = document.getElementById('soChequeFields') || document.getElementById('sdChequeFields');
  const credit = document.getElementById('soCreditFields') || document.getElementById('sdCreditFields');
  const isCheque = st === 'Cheque' || st === 'Check';
  if (box) box.style.display = isCheque ? '' : 'none';
  if (credit) credit.style.display = (st === 'Credit') ? '' : 'none';
  soSyncCreditAgreementPrintButton();
  soSyncPaymentDueField();
  ['soChequeNumber', 'soBankName', 'soChequeDate', 'sdChequeNumber', 'sdBankName', 'sdChequeDate'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.required = isCheque;
  });
  soSyncPayDetailFields();
  soSyncCashFields();
}

function isSalesOrderPosInline() {
  const el = document.getElementById('createSalesOrderModal');
  return !!(el && el.getAttribute('data-so-pos') === 'inline');
}

function canOpenSalesOrderPos() {
  return !!document.getElementById('createSalesOrderModal');
}

function focusSalesOrderBarcode() {
  const scan = document.getElementById('soBarcodeScan');
  if (scan) {
    try { scan.focus(); } catch (e) {}
  }
}

function soClearCustomerNameError(fieldId) {
  const el = document.getElementById(fieldId);
  const err = document.getElementById(fieldId + 'Error');
  if (el) el.classList.remove('is-invalid');
  if (err) err.style.removeProperty('display');
}

function soShowCustomerNameError(fieldId, message, shouldFocus) {
  const el = document.getElementById(fieldId);
  const err = document.getElementById(fieldId + 'Error');
  if (el) {
    el.classList.add('is-invalid');
    if (shouldFocus) {
      try { el.focus(); } catch (e) {}
    }
  }
  if (err) {
    err.textContent = message || 'This field is required.';
    err.style.removeProperty('display');
  }
}

function soComposeCustomerName() {
  const first = (document.getElementById('soCustomerFirstName')?.value || '').trim();
  const middle = (document.getElementById('soCustomerMiddleName')?.value || '').trim();
  const last = (document.getElementById('soCustomerLastName')?.value || '').trim();
  const suffix = (document.getElementById('soCustomerSuffix')?.value || '').trim();
  let full = [first, middle, last, suffix].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
  if (window._soSelectedCustomer && window._soSelectedCustomer.name) {
    const parsed = soSplitCustomerFullName(window._soSelectedCustomer.name);
    if (parsed.first === first && parsed.middle === middle && parsed.last === last && parsed.suffix === suffix) {
      full = String(window._soSelectedCustomer.name).trim();
    }
  }
  if (full.length > 150) full = full.slice(0, 150).trim();
  const hidden = document.getElementById('soCustomerName');
  if (hidden) hidden.value = full;
  return full;
}

let _soCustomerCache = [];
let _soCustomerCacheLoading = null;
window._soSelectedCustomer = null;

function soSplitCustomerFullName(full) {
  const raw = String(full || '').trim().replace(/\s+/g, ' ');
  if (!raw) return { first: '', middle: '', last: '', suffix: '' };
  const parts = raw.split(' ');
  const sufRe = /^(jr\.?|sr\.?|ii|iii|iv|v|2nd|3rd)$/i;
  let suffix = '';
  if (parts.length > 1 && sufRe.test(parts[parts.length - 1].replace(/,/g, ''))) {
    suffix = parts.pop();
  }
  const first = parts.shift() || '';
  let last = '';
  let middle = '';
  if (parts.length === 0) {
    last = first;
  } else {
    last = parts.pop() || '';
    middle = parts.join(' ');
  }
  return { first, middle, last, suffix };
}

function soCustomerApi(action, extra, cb) {
  const query = Object.assign({ action: action }, extra || {});
  if (typeof pApi === 'function') {
    pApi({ query: query }, cb);
    return;
  }
  const url = new URL('procurement_api.php', window.location.href);
  Object.keys(query).forEach((k) => url.searchParams.set(k, query[k]));
  fetch(url.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then((r) => r.json())
    .then(cb)
    .catch(() => cb({ success: false, data: [] }));
}

function soLoadCustomerCache(force) {
  if (_soCustomerCache.length && !force) {
    return Promise.resolve(_soCustomerCache);
  }
  if (_soCustomerCacheLoading && !force) return _soCustomerCacheLoading;
  _soCustomerCacheLoading = new Promise((resolve) => {
    soCustomerApi('get_customers', {}, (r) => {
      _soCustomerCache = (r && r.success && Array.isArray(r.data)) ? r.data : [];
      _soCustomerCacheLoading = null;
      resolve(_soCustomerCache);
    });
  });
  return _soCustomerCacheLoading;
}

function soFilterCustomerCache(q) {
  const s = String(q || '').trim().toLowerCase();
  if (!s) return _soCustomerCache.slice(0, 80);
  return _soCustomerCache.filter((c) => {
    const name = String(c.name || '').toLowerCase();
    const phone = String(c.phone || '').toLowerCase();
    const code = 'cus-' + String(c.id || 0).padStart(5, '0');
    return name.includes(s) || phone.includes(s) || code.includes(s) || String(c.id).includes(s);
  }).slice(0, 80);
}

function soCloseCustomerMenu() {
  const menu = document.getElementById('soCustomerMenu');
  if (menu) {
    menu.hidden = true;
    menu.innerHTML = '';
  }
}

function soRenderCustomerMenu(query, highlightFirst) {
  const menu = document.getElementById('soCustomerMenu');
  if (!menu) return;
  const rows = soFilterCustomerCache(query);
  if (!rows.length) {
    menu.innerHTML = '<div class="so-product-empty">No matching customer. Use + New Customer to enter a name.</div>';
    menu.hidden = false;
    return;
  }
  menu.innerHTML = rows.map((c, i) => {
    const label = soEsc(c.name || '');
    const extra = c.phone ? ' · ' + soEsc(c.phone) : '';
    return `<button type="button" class="so-product-opt${i === 0 && highlightFirst ? ' active' : ''}" data-id="${Number(c.id)}" role="option">${label}${extra}</button>`;
  }).join('');
  menu.hidden = false;
}

function soBindCustomerCombo() {
  const combo = document.getElementById('soCustomerCombo');
  const input = document.getElementById('soCustomerSearch');
  const menu = document.getElementById('soCustomerMenu');
  if (!combo || !input || !menu || combo.dataset.bound === '1') return;
  combo.dataset.bound = '1';
  soLoadCustomerCache();
  input.addEventListener('focus', () => {
    soLoadCustomerCache().then(() => soRenderCustomerMenu(input.value, false));
  });
  input.addEventListener('input', () => {
    soLoadCustomerCache().then(() => soRenderCustomerMenu(input.value, true));
    clearTimeout(combo._soCustTimer);
    const q = String(input.value || '').trim();
    if (q.length < 2) return;
    combo._soCustTimer = setTimeout(() => {
      soCustomerApi('get_customers', { q: q }, (r) => {
        const extra = (r && r.success && Array.isArray(r.data)) ? r.data : [];
        extra.forEach((c) => {
          if (!_soCustomerCache.some((x) => Number(x.id) === Number(c.id))) _soCustomerCache.push(c);
        });
        if (document.getElementById('soCustomerSearch')?.value.trim() === q) {
          soRenderCustomerMenu(q, true);
        }
      });
    }, 250);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (menu.hidden) soRenderCustomerMenu(input.value, true);
      const opts = Array.from(menu.querySelectorAll('.so-product-opt'));
      if (!opts.length) return;
      let idx = opts.findIndex((o) => o.classList.contains('active'));
      idx = e.key === 'ArrowDown' ? idx + 1 : idx - 1;
      if (idx < 0) idx = opts.length - 1;
      if (idx >= opts.length) idx = 0;
      opts.forEach((o) => o.classList.remove('active'));
      opts[idx].classList.add('active');
      opts[idx].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      const active = menu.querySelector('.so-product-opt.active') || menu.querySelector('.so-product-opt');
      if (active && !menu.hidden) {
        e.preventDefault();
        soSelectCustomerById(parseInt(active.getAttribute('data-id'), 10));
      }
    } else if (e.key === 'Escape') {
      soCloseCustomerMenu();
    }
  });
  menu.addEventListener('mousedown', (e) => {
    const opt = e.target.closest('.so-product-opt');
    if (!opt) return;
    e.preventDefault();
    soSelectCustomerById(parseInt(opt.getAttribute('data-id'), 10));
  });
  document.addEventListener('mousedown', (e) => {
    if (!combo.contains(e.target)) soCloseCustomerMenu();
  });
}

function soFillCustomerNameFields(fullName) {
  const parts = soSplitCustomerFullName(fullName);
  const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
  setVal('soCustomerFirstName', parts.first);
  setVal('soCustomerMiddleName', parts.middle);
  setVal('soCustomerLastName', parts.last);
  setVal('soCustomerSuffix', parts.suffix);
  soClearCustomerNameError('soCustomerFirstName');
  soClearCustomerNameError('soCustomerLastName');
  soComposeCustomerName();
}

function soHideLoyalCustomerPanel() {
  const panel = document.getElementById('soLoyalCustomerPanel');
  if (panel) panel.style.display = 'none';
  ['soCustomerHistoryBox', 'soFrequentBox', 'soPrevOrderItemsBox'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
}

function soClearCustomerSelection() {
  window._soSelectedCustomer = null;
  const idEl = document.getElementById('soCustomerId');
  if (idEl) idEl.value = '';
  const search = document.getElementById('soCustomerSearch');
  if (search) search.value = '';
  soCloseCustomerMenu();
  soHideLoyalCustomerPanel();
}

function soStartNewCustomer() {
  soClearCustomerSelection();
  ['soCustomerFirstName', 'soCustomerMiddleName', 'soCustomerLastName', 'soCustomerSuffix', 'soCustomerName'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  soClearCustomerNameError('soCustomerFirstName');
  soClearCustomerNameError('soCustomerLastName');
  try { document.getElementById('soCustomerFirstName')?.focus(); } catch (e) {}
}

function soSelectCustomerById(id) {
  const cid = parseInt(id, 10);
  if (!cid) return;
  soCloseCustomerMenu();
  soCustomerApi('customers_get', { id: String(cid) }, (r) => {
    if (!r || !r.success || !r.data) {
      soToast(r?.message || 'Customer not found', 'warn');
      return;
    }
    const c = r.data;
    window._soSelectedCustomer = {
      id: Number(c.id),
      name: String(c.name || ''),
      orders: Array.isArray(c.orders) ? c.orders : [],
      total_purchases: Number(c.total_purchases || 0)
    };
    const idEl = document.getElementById('soCustomerId');
    if (idEl) idEl.value = String(c.id);
    const search = document.getElementById('soCustomerSearch');
    if (search) search.value = String(c.name || '');
    soFillCustomerNameFields(c.name || '');
    const isLoyal = Number(c.total_purchases || 0) > 0 || (c.orders && c.orders.length);
    const panel = document.getElementById('soLoyalCustomerPanel');
    if (panel) panel.style.display = isLoyal ? '' : 'none';
    if (!isLoyal) soHideLoyalCustomerPanel();
    else {
      const hist = document.getElementById('soCustomerHistoryBox');
      const freq = document.getElementById('soFrequentBox');
      const prev = document.getElementById('soPrevOrderItemsBox');
      if (hist) hist.style.display = 'none';
      if (freq) freq.style.display = 'none';
      if (prev) prev.style.display = 'none';
    }
  });
}

function soToggleCustomerHistory() {
  const box = document.getElementById('soCustomerHistoryBox');
  const body = document.getElementById('soCustomerHistoryBody');
  if (!box || !body) return;
  if (box.style.display !== 'none') {
    box.style.display = 'none';
    return;
  }
  const freq = document.getElementById('soFrequentBox');
  if (freq) freq.style.display = 'none';
  const orders = (window._soSelectedCustomer && window._soSelectedCustomer.orders) || [];
  const sales = orders.filter((o) => String(o.source || '') === 'Sales Order' && Number(o.id || 0) > 0);
  if (!sales.length) {
    body.innerHTML = '<tr><td colspan="4" class="text-muted">No previous sales orders for this customer.</td></tr>';
  } else {
    body.innerHTML = sales.map((o) => {
      const when = String(o.date || '').slice(0, 10);
      return `<tr>
        <td><strong>${soEsc(o.number || '')}</strong></td>
        <td>${soEsc(when)}</td>
        <td>${soMoney(o.amount)}</td>
        <td><button type="button" class="btn btn-sm btn-outline-primary" onclick="soViewPreviousOrder(${Number(o.id)})">Load Previous Order</button></td>
      </tr>`;
    }).join('');
  }
  box.style.display = '';
}

function soViewPreviousOrder(soId) {
  const box = document.getElementById('soPrevOrderItemsBox');
  const body = document.getElementById('soPrevOrderItemsBody');
  if (!box || !body || !soId) return;
  body.innerHTML = '<tr><td colspan="3" class="text-muted">Loading…</td></tr>';
  box.style.display = '';
  fetch((typeof clarAuthUrl === 'function')
    ? clarAuthUrl('billing_api.php?action=get_sales_order&id=' + encodeURIComponent(soId))
    : 'billing_api.php?action=get_sales_order&id=' + encodeURIComponent(soId), {
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then((r) => r.json())
    .then((r) => {
      const items = (r && r.success && r.items) ? r.items : [];
      if (!items.length) {
        body.innerHTML = '<tr><td colspan="3" class="text-muted">No products on this order.</td></tr>';
        return;
      }
      body.innerHTML = items.map((it) => {
        const pid = Number(it.product_id || 0);
        const qty = Number(it.quantity || 0) || 1;
        const label = soEsc((it.sku ? it.sku + ' — ' : '') + (it.product_name || ''));
        return `<tr>
          <td>${label}</td>
          <td>${qty}</td>
          <td><button type="button" class="btn btn-sm btn-outline-primary" onclick="soAddProductQty(${pid}, ${qty})">Add</button></td>
        </tr>`;
      }).join('');
    })
    .catch(() => {
      body.innerHTML = '<tr><td colspan="3" class="text-danger">Could not load order items.</td></tr>';
    });
}

function soShowFrequentProducts() {
  const box = document.getElementById('soFrequentBox');
  const body = document.getElementById('soFrequentBody');
  const hist = document.getElementById('soCustomerHistoryBox');
  if (hist) hist.style.display = 'none';
  if (!box || !body) return;
  const name = (window._soSelectedCustomer && window._soSelectedCustomer.name) || '';
  body.innerHTML = '<tr><td colspan="3" class="text-muted">Loading…</td></tr>';
  box.style.display = '';
  soCustomerApi('get_customer_orders_for_pr', {}, (r) => {
    const orders = (r && r.success && Array.isArray(r.data)) ? r.data : [];
    const mine = orders.filter((o) => String(o.customer_name || '').trim().toLowerCase() === name.trim().toLowerCase());
    const freq = {};
    mine.forEach((o) => {
      (o.items || []).forEach((it) => {
        const pid = Number(it.product_id || 0);
        if (!pid) return;
        if (!freq[pid]) {
          freq[pid] = { product_id: pid, name: it.product_name || '', sku: it.sku || '', times: 0, qty: 0 };
        }
        freq[pid].times += 1;
        freq[pid].qty += Number(it.ordered_qty || it.quantity || 0) || 0;
      });
    });
    const rows = Object.values(freq).sort((a, b) => b.times - a.times || b.qty - a.qty).slice(0, 20);
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="3" class="text-muted">No frequent products found for this customer.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((p) => {
      const label = soEsc((p.sku ? p.sku + ' — ' : '') + p.name);
      return `<tr>
        <td>${label}</td>
        <td>${p.times}</td>
        <td><button type="button" class="btn btn-sm btn-outline-primary" onclick="soAddProductQty(${p.product_id}, 1)">Add</button></td>
      </tr>`;
    }).join('');
  });
}

function soAddProductQty(productId, qty) {
  const pid = parseInt(productId, 10);
  const want = Math.max(1, parseInt(qty, 10) || 1);
  if (!pid) return 0;
  const run = () => {
    const product = soFindCachedProduct(pid);
    if (!product) {
      soToast('That product is not available to sell.', 'warn');
      return 0;
    }
    const stock = soSellableQty(product);
    const used = soQtyOfProduct(product.id);
    const room = stock - used;
    if (room <= 0) {
      soToast('Quantity cannot exceed available stock.', 'warn');
      return 0;
    }
    const add = Math.min(want, room);
    const existing = soFindLineRow(product.id);
    if (existing) {
      const qtyInput = existing.querySelector('.so-qty, .qty-input, input[name="quantities[]"]');
      if (qtyInput) {
        qtyInput.value = String((parseInt(qtyInput.value || '0', 10) || 0) + add);
        qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
    } else if (document.getElementById('soLinesBody')) {
      const tr = addSalesOrderLine({ qty: add });
      const combo = tr.querySelector('.so-product-combo');
      if (combo) soApplyProductChoice(combo, product);
      const qtyInput = tr.querySelector('.so-qty');
      if (qtyInput) {
        qtyInput.value = String(add);
        qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
    recalcSalesOrderTotals();
    soToast((product.sku || product.name || 'Item') + ' added', 'success');
    return add;
  };
  if (!_soProductsCache.length) {
    const p = loadSalesOrderProducts();
    if (p && typeof p.then === 'function') {
      p.then(run);
      return want;
    }
  }
  return run();
}

function soValidateCustomerNameFields() {
  soClearCustomerNameError('soCustomerFirstName');
  soClearCustomerNameError('soCustomerLastName');
  const firstEl = document.getElementById('soCustomerFirstName');
  const lastEl = document.getElementById('soCustomerLastName');
  const hasSplit = !!(firstEl || lastEl);
  if (!hasSplit) {
    return !!(document.getElementById('soCustomerName')?.value || '').trim();
  }
  const first = (firstEl?.value || '').trim();
  const last = (lastEl?.value || '').trim();
  let ok = true;
  let focused = false;
  if (!first) {
    soShowCustomerNameError('soCustomerFirstName', 'First Name is required.', true);
    focused = true;
    ok = false;
  }
  if (!last) {
    soShowCustomerNameError('soCustomerLastName', 'Last Name is required.', !focused);
    ok = false;
  }
  soComposeCustomerName();
  return ok;
}

function resetSalesOrderPos() {
  const due = document.getElementById('soDueDate');
  if (due) due.value = '';
  const amt = document.getElementById('soAmount');
  if (amt) {
    delete amt.dataset.touched;
    amt.value = '0';
  }
  soClearCustomerSelection();
  ['soCustomerFirstName', 'soCustomerMiddleName', 'soCustomerLastName', 'soCustomerSuffix', 'soCustomerName'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  soClearCustomerNameError('soCustomerFirstName');
  soClearCustomerNameError('soCustomerLastName');
  const st = document.getElementById('soSaleType');
  if (st) st.value = 'Cash';
  const disc = document.getElementById('soDiscountPercent');
  if (disc) disc.value = '0';
  const fee = document.getElementById('soDeliveryFee');
  if (fee) fee.value = '0';
  ['soChequeNumber', 'soBankName', 'soChequeDate', 'soCreditTerms', 'soCashReceived'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  soPayDetailGroups().forEach((group) => soClearPayDetailGroup(group, true));
  const ack = document.getElementById('soCreditAck');
  if (ack) ack.checked = false;
  if (typeof toggleSoChequeFields === 'function') toggleSoChequeFields();
  const body = document.getElementById('soLinesBody');
  if (body) body.innerHTML = '';
  if (typeof recalcSalesOrderTotals === 'function') recalcSalesOrderTotals();
  focusSalesOrderBarcode();
}

function loadSalesOrderProducts() {
  if (window._soProductsLoading) return window._soProductsLoading;
  const apiUrl = (typeof clarAuthUrl === 'function')
    ? clarAuthUrl('inventory_api.php?action=get_products_list')
    : 'inventory_api.php?action=get_products_list';
  window._soProductsLoading = fetch(apiUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(r => {
      if (!r.success) {
        _soProductsCache = [];
        soToast(r.message || 'Could not load products for sale', 'danger');
        return;
      }
      _soProductsCache = (r.data || []).filter(p => {
        const stock = soSellableQty(p);
        const sell = parseFloat(p.selling_price ?? p.unit_price ?? 0) || 0;
        return stock > 0 && sell > 0;
      });
      if (!_soProductsCache.length) {
        soToast('No sellable products yet. Need stocked-in qty and selling price > 0.', 'warn');
      }
      const pending = soBarcodeKey(window._pendingSoBarcode);
      if (pending) {
        window._pendingSoBarcode = '';
        if (_soProductsCache.length) applySalesOrderBarcode(pending);
        else soLookupProductThenCommit(pending);
      }
    })
    .catch(() => {
      _soProductsCache = [];
      soToast('Failed to load products', 'danger');
    })
    .finally(() => { window._soProductsLoading = null; });
  return window._soProductsLoading;
}

function syncSalesOrderPosForSection(sectionId) {
  const normalized = String(sectionId || '').trim().toLowerCase().replace(/[_\s]+/g, '-');
  const modal = document.getElementById('createSalesOrderModal');
  if (normalized === 'sales-orders') {
    if (!canOpenSalesOrderPos()) return;
    setTimeout(() => {
      loadSalesOrderProducts();
      soBindCustomerCombo();
      soLoadCustomerCache();
      focusSalesOrderBarcode();
      bindSalesOrderBarcodeInputs();
    }, 40);
    return;
  }
  stopSalesOrderCameraScanner();
  if (modal && !isSalesOrderPosInline() && modal.classList.contains('open') && typeof closeModal === 'function') {
    closeModal('createSalesOrderModal');
  }
}

function openCreateSalesOrderModal() {
  resetSalesOrderPos();
  if (!isSalesOrderPosInline() && typeof openModal === 'function') {
    openModal('createSalesOrderModal');
  }
  loadSalesOrderProducts();
  soBindCustomerCombo();
  soLoadCustomerCache();
}

function submitCreateSalesOrder() {
  if (!soValidateCustomerNameFields()) {
    soToast('First Name and Last Name are required', 'warn');
    return;
  }
  const customer_name = soComposeCustomerName();
  const sale_type = document.getElementById('soSaleType')?.value || 'Cash';
  const due_date = sale_type === 'Credit' ? (document.getElementById('soDueDate')?.value || '') : '';
  const amount_paid = Math.max(0, parseFloat(document.getElementById('soAmount')?.value || '0') || 0);
  const cheque_number = document.getElementById('soChequeNumber')?.value?.trim() || '';
  const bank_name = document.getElementById('soBankName')?.value?.trim() || '';
  const cheque_date = document.getElementById('soChequeDate')?.value || '';
  const delivery_fee = parseFloat(document.getElementById('soDeliveryFee')?.value || '0') || 0;
  const discount_percent = parseFloat(document.getElementById('soDiscountPercent')?.value || '0') || 0;
  const credit_terms = document.getElementById('soCreditTerms')?.value?.trim() || '';
  const credit_ack = !!document.getElementById('soCreditAck')?.checked;
  if (!customer_name) { soToast('Customer is required', 'warn'); return; }
  if (sale_type === 'Credit' && !due_date) { soToast('Payment Due Date is required for Credit', 'warn'); return; }
  if (sale_type === 'Cheque' || sale_type === 'Check') {
    if (!cheque_number || !bank_name || !cheque_date) {
      soToast('Cheque Number, Bank Name, and Cheque Date are required', 'warn');
      return;
    }
  }
  if (!soValidatePaymentDetailFields()) {
    soToast('Complete the required ' + sale_type + ' payment details', 'warn');
    return;
  }
  if (!soValidateCashReceived()) return;
  if (sale_type === 'Credit') {
    if (!credit_ack) {
      soToast('Credit sales require a generated credit agreement/contract.', 'warn');
      return;
    }
    if (!credit_terms) {
      soToast('Enter credit terms for the agreement/contract.', 'warn');
      return;
    }
  }
  const product_ids = [];
  const quantities = [];
  let stockError = false;
  document.querySelectorAll('#soLinesBody tr').forEach(tr => {
    const pid = parseInt(tr.querySelector('.so-product')?.value || 0, 10);
    const qty = parseInt(tr.querySelector('.so-qty')?.value || 0, 10);
    const stock = parseInt(tr.querySelector('.so-product')?.selectedOptions[0]?.dataset.stock || 0, 10);
    if (pid > 0 && qty > 0) {
      if (qty > stock) stockError = true;
      product_ids.push(pid);
      quantities.push(qty);
    }
  });
  if (stockError) { soToast('Qty exceeds available stock', 'warn'); return; }
  if (!product_ids.length) { soToast('Scan or add at least one product', 'warn'); return; }

  const fd = new FormData();
  fd.append('create_sales_order', '1');
  fd.append('customer_name', customer_name);
  fd.append('customer_first_name', (document.getElementById('soCustomerFirstName')?.value || '').trim());
  fd.append('customer_middle_name', (document.getElementById('soCustomerMiddleName')?.value || '').trim());
  fd.append('customer_last_name', (document.getElementById('soCustomerLastName')?.value || '').trim());
  fd.append('customer_suffix', (document.getElementById('soCustomerSuffix')?.value || '').trim());
  fd.append('sale_type', sale_type);
  fd.append('due_date', due_date);
  fd.append('amount_paid', String(amount_paid));
  fd.append('discount_percent', String(Math.max(0, discount_percent)));
  fd.append('delivery_fee', String(Math.max(0, delivery_fee)));
  if (sale_type === 'Cheque' || sale_type === 'Check') {
    fd.append('cheque_number', cheque_number);
    fd.append('bank_name', bank_name);
    fd.append('cheque_date', cheque_date);
  }
  soAppendPayDetailFields(fd);
  if (sale_type === 'Cash') {
    fd.append('cash_tendered', String(parseFloat(String(soCashReceivedInput()?.value || '0')) || 0));
  }
  if (sale_type === 'Credit') {
    fd.append('credit_terms', credit_terms);
    fd.append('credit_ack', '1');
  }
  product_ids.forEach(id => fd.append('product_ids[]', id));
  quantities.forEach(q => fd.append('quantities[]', q));

  fetch('billing_api.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected response' })))
    .then(r => {
      if (r.success) {
        soToast(r.message || 'Sales order saved');
        if (isSalesOrderPosInline()) {
          resetSalesOrderPos();
        } else if (typeof closeModal === 'function') {
          closeModal('createSalesOrderModal');
        }
        if (r.invoice_number && sale_type !== 'Cheque') {
          window.open('print_invoice.php?invoice_number=' + encodeURIComponent(r.invoice_number) + '&with_receipt=1', '_blank');
        }
        if (r.sales_order_id && typeof wcRememberSale === 'function') wcRememberSale(r.sales_order_id);
        setTimeout(() => location.reload(), 800);
      } else soToast(r.message || 'Failed to create sale', 'danger');
    })
    .catch(() => soToast('Network error creating sale', 'danger'));
}

function openSalesOrderView(id) {
  const body = document.getElementById('soViewBody');
  const numEl = document.getElementById('soViewNumber');
  if (body) body.innerHTML = '<p class="text-muted mb-0">Loading…</p>';
  if (typeof openModal === 'function') openModal('viewSalesOrderModal');
  fetch('billing_api.php?action=get_sales_order&id=' + encodeURIComponent(id), {
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(r => r.json())
    .then(r => {
      if (!r.success || !r.order) {
        if (body) body.innerHTML = '<p class="text-danger">' + soEsc(r.message || 'Not found') + '</p>';
        return;
      }
      const o = r.order;
      if (numEl) numEl.textContent = o.order_number || '';
      const items = r.items || [];
      const pays = r.payments || [];
      const balance = Number(o.remaining_balance ?? 0);
      const stockOut = o.stock_out_status === 'Completed' ? 'Stocked out' : 'Not deducted';
      let html = `<div class="row g-2 mb-3">
        <div class="col-md-4"><div class="text-muted small">Customer</div><strong>${soEsc(o.customer_name)}</strong></div>
        <div class="col-md-4"><div class="text-muted small">Sales Type</div><strong>${soEsc(o.sale_type_label || o.sale_type)}</strong></div>
        <div class="col-md-4"><div class="text-muted small">Status</div><strong>${soEsc(o.display_status || o.status)}</strong></div>
        <div class="col-md-4"><div class="text-muted small">Payment</div><strong>${soEsc(o.payment_label || o.payment_status || '—')}</strong></div>
        <div class="col-md-4"><div class="text-muted small">Outstanding</div><strong class="${balance > 0.009 ? 'text-danger' : 'text-success'}">${soMoney(balance)}</strong></div>
        <div class="col-md-4"><div class="text-muted small">Stock-Out</div><strong>${soEsc(stockOut)}</strong></div>
      </div>`;
      if (o.sale_type === 'Cheque') {
        html += `<div class="alert alert-warning py-2 small">Cheque ${soEsc(o.cheque_number || '')} · ${soEsc(o.bank_name || '')} · ${soEsc(o.cheque_status || 'Pending')}</div>`;
      }
      if (o.agreement_number) {
        html += `<div class="alert alert-info py-2 small">Credit contract <strong>${soEsc(o.agreement_number)}</strong> (${soEsc(o.credit_agreement_status || 'Active')}). <a target="_blank" href="print_credit_agreement.php?so_id=${o.id}">Print agreement</a></div>`;
        if (o.credit_terms) html += `<p class="small">${soEsc(o.credit_terms)}</p>`;
      }
      html += `<div class="table-container system-table-scroll mb-3"><table class="modern-table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Amount</th></tr></thead><tbody>`;
      items.forEach(it => {
        const amt = Number(it.quantity || 0) * Number(it.unit_price || 0);
        html += `<tr><td>${soEsc(it.sku || '')} — ${soEsc(it.product_name || '')}</td><td>${soEsc(it.quantity)}</td><td>${soMoney(it.unit_price)}</td><td>${soMoney(amt)}</td></tr>`;
      });
      html += `</tbody></table></div>
        <div class="so-totals">
          <div class="d-flex justify-content-between"><span>Subtotal</span><strong>${soMoney(o.subtotal)}</strong></div>
          <div class="d-flex justify-content-between"><span>Discount</span><strong class="text-danger">${soMoney(o.discount_amount)}</strong></div>
          <div class="d-flex justify-content-between"><span>Tax</span><strong>${soMoney(o.tax_amount)}</strong></div>
          <div class="d-flex justify-content-between"><span>Delivery</span><strong>${soMoney(o.delivery_fee)}</strong></div>
          <div class="d-flex justify-content-between"><span>Total Amount</span><strong class="text-primary">${soMoney(o.grand_total)}</strong></div>
        </div>`;
      if (pays.length) {
        html += `<h6 class="mt-3">Payments</h6><ul class="small mb-0">`;
        pays.forEach(p => {
          html += `<li>${soEsc(p.payment_number)} · ${soEsc(p.payment_method)} · ${soMoney(p.amount_paid)}${p.cheque_status ? ' (' + soEsc(p.cheque_status) + ')' : ''}</li>`;
        });
        html += `</ul>`;
      }
      if (body) body.innerHTML = html;
      const foot = document.getElementById('soViewFooter');
      if (foot) {
        let extra = '<button class="btn btn-outline-secondary" onclick="closeModal(\'viewSalesOrderModal\')">Close</button>';
        if (o.invoice_number) {
          extra += ` <a class="btn btn-outline-secondary" target="_blank" href="print_invoice.php?invoice_number=${encodeURIComponent(o.invoice_number)}&with_receipt=1">Invoice</a>`;
        }
        if (o.stock_out_status !== 'Completed' && o.status !== 'Cancelled') {
          extra += ` <button class="btn btn-warning" onclick="closeModal('viewSalesOrderModal'); openSalesStockOutModal(${o.id})">Stock-Out</button>`;
        }
        if (o.invoice_id && balance > 0.009 && o.sale_type === 'Credit' && typeof openPaymentModal === 'function') {
          extra += ` <button class="btn btn-success" onclick="openPaymentModal(${o.invoice_id},'${soEsc(o.invoice_number)}',${Number(o.grand_total)},${balance})">Record Payment</button>`;
        }
        foot.innerHTML = extra;
      }
      if (typeof wcDecorateSalesOrderView === 'function') wcDecorateSalesOrderView(o);
    })
    .catch(() => { if (body) body.innerHTML = '<p class="text-danger">Failed to load sales order.</p>'; });
}

function openSalesStockOutModal(id) {
  window._soOutState = { id, items: [] };
  const body = document.getElementById('soOutLinesBody');
  if (body) body.innerHTML = '<tr><td colspan="4" class="text-muted">Loading…</td></tr>';
  if (typeof openModal === 'function') openModal('salesStockOutModal');
  fetch('billing_api.php?action=get_sales_order&id=' + encodeURIComponent(id), {
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(r => r.json())
    .then(r => {
      if (!r.success || !r.order) {
        soToast(r.message || 'Sales order not found', 'danger');
        return;
      }
      const o = r.order;
      if (o.sale_type === 'Cheque' && String(o.cheque_status || '') !== 'Cleared') {
        soToast('Cheque sales cannot be stocked out until the cheque is marked Paid.', 'warn');
      }
      const numEl = document.getElementById('soOutNumber');
      if (numEl) numEl.textContent = o.order_number || '';
      window._soOutState = {
        id: o.id,
        sale_type: o.sale_type,
        cheque_status: o.cheque_status,
        items: (r.items || []).map(it => ({
          product_id: parseInt(it.product_id, 10),
          name: it.product_name || it.sku || ('#' + it.product_id),
          sku: it.sku || '',
          barcode: it.barcode || '',
          ordered: parseInt(it.quantity, 10) || 0,
          scanned: 0,
          available: parseInt(it.quantity_in_stock, 10) || 0
        }))
      };
      renderSalesStockOutLines();
      if (!_soProductsCache.length) {
        const apiUrl = (typeof clarAuthUrl === 'function')
          ? clarAuthUrl('inventory_api.php?action=get_products_list')
          : 'inventory_api.php?action=get_products_list';
        fetch(apiUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
          .then(x => x.json())
          .then(x => { if (x.success) _soProductsCache = x.data || []; })
          .catch(() => {});
      }
    })
    .catch(() => soToast('Failed to load stock-out lines', 'danger'));
}

function renderSalesStockOutLines() {
  const body = document.getElementById('soOutLinesBody');
  const st = window._soOutState;
  if (!body || !st) return;
  body.innerHTML = (st.items || []).map(it => `<tr>
    <td>${soEsc(it.sku)} — ${soEsc(it.name)}</td>
    <td>${it.ordered}</td>
    <td><strong>${it.scanned}</strong></td>
    <td>${it.available}</td>
  </tr>`).join('');
  const ready = (st.items || []).every(it => it.scanned === it.ordered);
  const hint = document.getElementById('soOutHint');
  if (hint) hint.textContent = ready ? 'All lines matched. Confirm to deduct inventory.' : 'Scan each ordered unit. Stock-out is blocked until scanned qty matches the order and does not exceed available stock.';
  const btn = document.getElementById('soOutSubmitBtn');
  if (btn) btn.disabled = !ready;
}

function applySalesStockOutBarcode(code) {
  const st = window._soOutState;
  if (!st) return false;
  const key = soBarcodeKey(code);
  if (!key) return false;
  const product = soFindByBarcode(key);
  const pid = product ? parseInt(product.id, 10) : 0;
  const line = (st.items || []).find(it => {
    if (pid && it.product_id === pid) return true;
    return soBarcodeKey(it.barcode) === key;
  });
  if (!line) {
    soToast('Scanned item is not on this sales order.', 'warn');
    return false;
  }
  if (line.scanned + 1 > line.ordered) {
    soToast('Cannot scan more than the ordered quantity.', 'warn');
    return false;
  }
  if (line.scanned + 1 > line.available) {
    soToast('Stock-out quantity cannot exceed available inventory.', 'warn');
    return false;
  }
  line.scanned += 1;
  const scanInput = document.getElementById('soOutBarcodeScan');
  if (scanInput) scanInput.value = '';
  renderSalesStockOutLines();
  return true;
}

function submitSalesStockOutBarcode() {
  const input = document.getElementById('soOutBarcodeScan');
  applySalesStockOutBarcode(input ? input.value : '');
}

function submitSalesStockOut() {
  const st = window._soOutState;
  if (!st) return;
  if ((st.items || []).some(it => it.scanned !== it.ordered)) {
    soToast('Scan every ordered unit before confirming stock-out.', 'warn');
    return;
  }
  const fd = new FormData();
  fd.append('process_sales_stock_out', '1');
  fd.append('sales_order_id', String(st.id));
  fetch('billing_api.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected response' })))
    .then(r => {
      if (r.success) {
        soToast(r.message || 'Stock-out completed');
        if (typeof closeModal === 'function') closeModal('salesStockOutModal');
        setTimeout(() => location.reload(), 600);
      } else soToast(r.message || 'Stock-out failed', 'danger');
    })
    .catch(() => soToast('Network error during stock-out', 'danger'));
}

function bindSalesOrderBarcodeInputs() {
  ['soBarcodeScan', 'sdBarcodeScan', 'soOutBarcodeScan'].forEach((id) => {
    const el = document.getElementById(id);
    if (!el || el.dataset.soBound === '1') return;
    el.dataset.soBound = '1';
    el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(el._soScanTimer);
        el._soScanRapid = 0;
        if (id === 'soOutBarcodeScan') submitSalesStockOutBarcode();
        else applySalesOrderBarcode(el.value);
        return;
      }
      if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) return;
      const now = Date.now();
      const gap = now - (el._soScanLast || 0);
      el._soScanLast = now;
      el._soScanRapid = gap > 0 && gap < 50 ? (el._soScanRapid || 0) + 1 : 0;
      clearTimeout(el._soScanTimer);
      el._soScanTimer = setTimeout(() => {
        if ((el._soScanRapid || 0) >= 3 && soBarcodeKey(el.value) && document.activeElement === el) {
          if (id === 'soOutBarcodeScan') submitSalesStockOutBarcode();
          else applySalesOrderBarcode(el.value);
        }
        el._soScanRapid = 0;
      }, 80);
    });
  });
}

function ensureSalesDashboardProducts() {
  if (_soProductsCache.length || !document.getElementById('orderItemsTbody')) return;
  fetch('inventory_api.php?action=get_products_list', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(r => {
      if (r.success) {
        _soProductsCache = (r.data || []).filter(p => soSellableQty(p) > 0 && (parseFloat(p.selling_price ?? p.unit_price ?? 0) || 0) > 0);
      }
    })
    .catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
  if (typeof window.openModal !== 'function') {
    window.openModal = function (id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.add('open');
      if (el.classList.contains('modal-overlay')) el.style.display = 'flex';
    };
    window.closeModal = function (id) { document.getElementById(id)?.classList.remove('open'); };
  }
  bindSalesOrderBarcodeInputs();
  bindSalesOrderWedgeScanner();
  ensureSalesDashboardProducts();
  const disc = document.getElementById('discountPercent');
  if (disc && !disc.dataset.soTaxBound) {
    disc.dataset.soTaxBound = '1';
    disc.addEventListener('input', recalcSalesOrderTotals);
  }
  const fee = document.getElementById('deliveryFee');
  if (fee && !fee.dataset.soTaxBound) {
    fee.dataset.soTaxBound = '1';
    fee.addEventListener('input', recalcSalesOrderTotals);
  }
  if (typeof toggleSoChequeFields === 'function') toggleSoChequeFields();
  recalcSalesOrderTotals();
});
