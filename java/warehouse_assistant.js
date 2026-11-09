let assistantScanner = null;
let assistantScannerActive = false;
let assistantLastScannedCode = '';

// Tab switching
function showTab(event, tabId) {
    if (tabId !== 'scan-code') stopAssistantScanner();
    // Hide all content cards
    document.querySelectorAll('.content-card').forEach(card => {
        card.classList.remove('active');
    });
    
    // Remove active class from all menu items
    document.querySelectorAll('.menu-item').forEach(item => {
        item.classList.remove('active');
    });
    
    // Show selected content card
    document.getElementById(tabId).classList.add('active');
    
    // Add active class to clicked menu item
    event.target.closest('.menu-item').classList.add('active');
    
    // Prevent default link behavior
    event.preventDefault();
}

function showTabById(tabId) {
    document.querySelectorAll('.content-card').forEach(card => card.classList.remove('active'));
    document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
    const card = document.getElementById(tabId);
    if (card) card.classList.add('active');
    const item = document.querySelector(`.menu-item[onclick*="'${tabId}'"]`);
    if (item) item.classList.add('active');
}

function applyAssistantScanToForms(code) {
    const cleaned = (code || '').trim().toUpperCase();
    if (!cleaned) return false;
    let matched = false;
    const stockIn = document.getElementById('assistantStockInProduct');
    const delivery = document.getElementById('assistantDeliveryProduct');
    [stockIn, delivery].forEach((el) => {
        if (!el) return;
        const matchOpt = Array.from(el.options).find((opt) => opt.value.trim().toUpperCase() === cleaned);
        if (matchOpt) {
            el.value = matchOpt.value;
            matched = true;
        }
    });

    const searchInput = document.getElementById('assistantStockSearch');
    if (searchInput) {
        searchInput.value = cleaned;
        filterTableBootstrap('stockTable', cleaned);
    }
    return matched;
}

function applyAssistantStockInBarcode() {
    const input = document.getElementById('assistantStockInBarcode');
    if (!input) return;
    applyAssistantScanToForms(input.value);
}

function applyAssistantDeliveryBarcode() {
    const input = document.getElementById('assistantDeliveryBarcode');
    if (!input) return;
    applyAssistantScanToForms(input.value);
}

function handleAssistantScan(decodedText) {
    assistantLastScannedCode = (decodedText || '').trim();
    const matched = applyAssistantScanToForms(decodedText);
    const resultEl = document.getElementById('assistant-scan-result');
    resultEl.innerHTML = matched
        ? `<span class="badge bg-success">Scanned: ${decodedText}</span> Product selected in stock/delivery forms.`
        : `<span class="badge bg-warning text-dark">Scanned: ${decodedText}</span> No exact SKU match found.`;
    const auto = document.getElementById('assistant-scan-auto');
    if (auto && auto.checked) processAssistantScan();
}

function processAssistantScan() {
    const resultEl = document.getElementById('assistant-scan-result');
    if (!assistantLastScannedCode) {
        resultEl.innerHTML = '<span class="badge bg-danger">No scan</span> Scan a code first.';
        return;
    }

    const payload = new URLSearchParams();
    payload.append('barcode', assistantLastScannedCode);
    payload.append('movement_type', document.getElementById('assistant-scan-movement').value);
    payload.append('quantity', document.getElementById('assistant-scan-qty').value || '1');
    payload.append('reference', document.getElementById('assistant-scan-ref').value || '');
    payload.append('notes', 'Processed via Warehouse Assistant scanner');

    fetch('process_scan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
    })
    .then(r => r.json().catch(() => ({ success:false, message:'Invalid server response' })))
    .then(data => {
        if (data.success) {
            const sku = data.data && data.data.sku ? data.data.sku : assistantLastScannedCode;
            const qty = data.data && data.data.new_stock !== undefined ? data.data.new_stock : '';
            resultEl.innerHTML = `<span class="badge bg-success">Updated</span> ${sku} stock is now ${qty}.`;
        } else {
            resultEl.innerHTML = `<span class="badge bg-danger">Failed</span> ${data.message || 'Unable to process scan.'}`;
        }
    })
    .catch(() => {
        resultEl.innerHTML = '<span class="badge bg-danger">Failed</span> Network or server error.';
    });
}

function startAssistantScanner() {
    if (assistantScannerActive) return;
    if (typeof Html5Qrcode === 'undefined' || typeof Html5QrcodeSupportedFormats === 'undefined') {
        document.getElementById('assistant-scan-result').textContent = "Scanner library not loaded. Check internet connection or CDN access.";
        return;
    }
    if (!assistantScanner) assistantScanner = new Html5Qrcode("assistant-scanner-reader");
    assistantScanner.start(
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
        (decodedText) => handleAssistantScan(decodedText)
    ).then(() => {
        assistantScannerActive = true;
    }).catch((err) => {
        document.getElementById('assistant-scan-result').textContent = "Unable to start camera scanner: " + err;
    });
}

function stopAssistantScanner() {
    if (!assistantScanner || !assistantScannerActive) return;
    assistantScanner.stop().then(() => {
        assistantScannerActive = false;
    }).catch(() => {});
}

// Table filter
function filterTableBootstrap(tableId, searchTerm) {
    const table = document.getElementById(tableId);
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    const term = searchTerm.toLowerCase();
    
    for (let row of rows) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    }
}