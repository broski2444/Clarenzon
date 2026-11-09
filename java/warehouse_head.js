let whScanner = null;
let whScannerActive = false;
let whLastScannedCode = '';

function showSection(section) {
    if (section !== 'scanner') stopWarehouseScanner();
    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(l => l.classList.remove('active'));
    const target = document.getElementById('section-' + section);
    if (target) target.classList.add('active');
    const nav = document.querySelector(`.sidebar-nav .nav-item[onclick="showSection('${section}')"]`);
    if (nav) nav.classList.add('active');
    // Update top-bar title
    const titles = { dashboard:'Dashboard', products:'Product Management', locations:'Warehouse Locations', adjustments:'Stock Adjustments', reports:'Inventory Reports', scanner:'Barcode Scanner' };
    const currentTitle = titles[section] || 'Dashboard';
    document.querySelector('.page-title').textContent = currentTitle;
    document.title = currentTitle + ' | Clarenzon Medical';
    // Init report chart lazily
    if (section === 'reports') initReportChart();
}

function applyScannedCodeToWarehouseForms(code) {
    const cleanCode = (code || '').trim().toUpperCase();
    if (!cleanCode) return false;
    let matched = false;
    document.querySelectorAll('select[name="product"], select[name="del_product"]').forEach((selectEl) => {
        const option = Array.from(selectEl.options).find((opt) => opt.value.trim().toUpperCase() === cleanCode);
        if (option) {
            selectEl.value = option.value;
            matched = true;
        }
    });
    return matched;
}

function handleWarehouseScan(decodedText) {
    whLastScannedCode = (decodedText || '').trim();
    const resultEl = document.getElementById('wh-scan-result');
    const matched = applyScannedCodeToWarehouseForms(decodedText);
    resultEl.innerHTML = matched
        ? `<span class="badge badge-green">Scanned: ${decodedText}</span> Product selected in available forms.`
        : `<span class="badge badge-orange">Scanned: ${decodedText}</span> No exact SKU match found.`;
    const auto = document.getElementById('wh-scan-auto');
    if (auto && auto.checked) processWarehouseScan();
}

function processWarehouseScan() {
    const resultEl = document.getElementById('wh-scan-result');
    if (!whLastScannedCode) {
        resultEl.innerHTML = '<span class="badge badge-red">No scan</span> Scan a code first.';
        return;
    }

    const payload = new URLSearchParams();
    payload.append('barcode', whLastScannedCode);
    payload.append('movement_type', document.getElementById('wh-scan-movement').value);
    payload.append('quantity', document.getElementById('wh-scan-qty').value || '1');
    payload.append('reference', document.getElementById('wh-scan-ref').value || '');
    payload.append('notes', 'Processed via Warehouse Head scanner');

    fetch('process_scan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
    })
    .then(r => r.json().catch(() => ({ success:false, message:'Invalid server response' })))
    .then(data => {
        if (data.success) {
            const sku = data.data && data.data.sku ? data.data.sku : whLastScannedCode;
            const qty = data.data && data.data.new_stock !== undefined ? data.data.new_stock : '';
            resultEl.innerHTML = `<span class="badge badge-green">Updated</span> ${sku} stock is now ${qty}.`;
        } else {
            resultEl.innerHTML = `<span class="badge badge-red">Failed</span> ${data.message || 'Unable to process scan.'}`;
        }
    })
    .catch(() => {
        resultEl.innerHTML = '<span class="badge badge-red">Failed</span> Network or server error.';
    });
}

function startWarehouseScanner() {
    if (whScannerActive) return;
    if (typeof Html5Qrcode === 'undefined' || typeof Html5QrcodeSupportedFormats === 'undefined') {
        document.getElementById('wh-scan-result').textContent = "Scanner library not loaded. Check internet connection or CDN access.";
        return;
    }
    if (!whScanner) whScanner = new Html5Qrcode("wh-scanner-reader");
    whScanner.start(
        { facingMode: "environment" },
        {
            fps: 10,
            qrbox: { width: 260, height: 260 },
            formatsToSupport: [
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E
            ]
        },
        (decodedText) => handleWarehouseScan(decodedText)
    ).then(() => {
        whScannerActive = true;
    }).catch((err) => {
        document.getElementById('wh-scan-result').textContent = "Unable to start camera scanner: " + err;
    });
}

function stopWarehouseScanner() {
    if (!whScanner || !whScannerActive) return;
    whScanner.stop().then(() => {
        whScannerActive = false;
    }).catch(() => {});
}

function filterTable(input, tableId) {
    const filter = input.value.toLowerCase();
    const rows = document.getElementById(tableId).getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (let row of rows) row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
}

function editProduct(sku, name, cat, qty, price, loc) {
    document.getElementById('edit_sku').value         = sku;
    document.getElementById('edit_sku_display').value = sku;
    document.getElementById('edit_name').value        = name;
    document.getElementById('edit_cat').value         = cat;
    document.getElementById('edit_qty').value         = qty;
    document.getElementById('edit_price').value       = price;
    document.getElementById('edit_loc').value         = loc;
    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}

function exportToCSV() {
    const table = document.querySelector('#valuationTable');
    if (!table) return;
    let csv = [];
    for (let row of table.querySelectorAll('tr')) {
        let rowData = [];
        for (let col of row.querySelectorAll('td,th')) rowData.push('"' + col.textContent.trim().replace(/"/g,'""') + '"');
        csv.push(rowData.join(','));
    }
    const blob = new Blob([csv.join('\n')], {type:'text/csv'});
    const url  = window.URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'warehouse_report_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// â”€â”€ Charts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const chartData = {
    type: 'bar',
    data: {
        labels: (window.whChartData && window.whChartData.labels) || ['No movement'],
        datasets: [
            { label:'Stock IN',  data:(window.whChartData && window.whChartData.stockIn) || [0], backgroundColor:'rgba(37,99,235,0.8)',  borderRadius:8 },
            { label:'Stock OUT', data:(window.whChartData && window.whChartData.stockOut) || [0], backgroundColor:'rgba(239,68,68,0.8)',  borderRadius:8 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display:true, position:'top' } },
        scales: {
            y: { beginAtZero:true, grid:{ color:'#f1f5f9' } },
            x: { grid:{ display:false } }
        }
    }
};

let reportChartInit = false;
function initReportChart() {
    if (reportChartInit) return;
    reportChartInit = true;
    const ctx = document.getElementById('reportStockChart');
    if (ctx) new Chart(ctx, JSON.parse(JSON.stringify(chartData)));
}

document.addEventListener('DOMContentLoaded', function() {
    // Dashboard chart
    const ctx = document.getElementById('stockChart');
    if (ctx) new Chart(ctx, JSON.parse(JSON.stringify(chartData)));

    // Auto-dismiss success alerts after 3s
    document.querySelectorAll('.alert.alert-success').forEach(function(el) {
        setTimeout(function() {
            try { bootstrap.Alert.getOrCreateInstance(el).close(); }
            catch(e) { el.style.opacity='0'; setTimeout(()=>el.remove(),300); }
        }, 3000);
    });
});
