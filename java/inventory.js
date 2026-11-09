        let inventoryScanner = null;
        let inventoryScannerActive = false;
        let inventoryLastScannedCode = '';

        function filterInventoryRows(term) {
            const searchTerm = (term || '').toLowerCase();
            const tableRows = document.querySelectorAll('#inventoryTableBody tr');
            tableRows.forEach(row => {
                row.classList.remove('scan-match');
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }

        // Search functionality
        const searchInputEl = document.getElementById('searchInput');
        if (searchInputEl) {
            searchInputEl.addEventListener('keyup', function() {
                filterInventoryRows(this.value);
            });
        }

        function handleInventoryScan(decodedText) {
            const code = (decodedText || '').trim().toUpperCase();
            inventoryLastScannedCode = code;
            const resultEl = document.getElementById('inventoryScanResult');
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.value = code;
                filterInventoryRows(code);
            }

            let matchedRow = null;
            document.querySelectorAll('#inventoryTableBody tr').forEach(row => {
                const skuCell = row.querySelector('.sku-cell');
                if (skuCell && skuCell.textContent.trim().toUpperCase() === code) {
                    matchedRow = row;
                }
            });

            if (matchedRow) {
                matchedRow.classList.add('scan-match');
                matchedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                resultEl.textContent = 'Scanned: ' + code + ' (SKU matched)';
            } else {
                resultEl.textContent = 'Scanned: ' + code + ' (no exact SKU match)';
            }
            const auto = document.getElementById('inventoryScanAuto');
            if (auto && auto.checked) processInventoryScan();
        }

        function processInventoryScan() {
            const resultEl = document.getElementById('inventoryScanResult');
            if (!inventoryLastScannedCode) {
                resultEl.textContent = 'Scan a code first.';
                return;
            }

            const payload = new URLSearchParams();
            payload.append('barcode', inventoryLastScannedCode);
            payload.append('movement_type', document.getElementById('inventoryScanMovement').value);
            payload.append('quantity', document.getElementById('inventoryScanQty').value || '1');
            payload.append('reference', document.getElementById('inventoryScanRef').value || '');
            payload.append('notes', 'Processed via Inventory scanner');

            fetch('process_scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString()
            })
            .then(r => r.json().catch(() => ({ success:false, message:'Invalid server response' })))
            .then(data => {
                if (data.success) {
                    const sku = data.data && data.data.sku ? data.data.sku : inventoryLastScannedCode;
                    const qty = data.data && data.data.new_stock !== undefined ? data.data.new_stock : '';
                    resultEl.textContent = 'Updated ' + sku + ' stock. New quantity: ' + qty;
                } else {
                    resultEl.textContent = data.message || 'Unable to process scan.';
                }
            })
            .catch(() => {
                resultEl.textContent = 'Network or server error.';
            });
        }

        function startInventoryScanner() {
            if (inventoryScannerActive) return;
            if (typeof Html5Qrcode === 'undefined' || typeof Html5QrcodeSupportedFormats === 'undefined') {
                document.getElementById('inventoryScanResult').textContent = 'Scanner library not loaded. Check internet connection or CDN access.';
                return;
            }
            if (!inventoryScanner) inventoryScanner = new Html5Qrcode("inventoryScanner");
            inventoryScanner.start(
                { facingMode: "environment" },
                {
                    fps: 10,
                    qrbox: { width: 240, height: 240 },
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
                (decodedText) => handleInventoryScan(decodedText)
            ).then(() => {
                inventoryScannerActive = true;
            }).catch((err) => {
                document.getElementById('inventoryScanResult').textContent = 'Unable to start scanner: ' + err;
            });
        }

        function stopInventoryScanner() {
            if (!inventoryScanner || !inventoryScannerActive) return;
            inventoryScanner.stop().then(() => {
                inventoryScannerActive = false;
            }).catch(() => {});
        }

        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Navigation
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                if (!this.classList.contains('active')) {
                    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
    
