<?php
/**
 * Shared barcode scanner section (desktop dashboard + mobile /scan).
 * Uses existing dashboard cards, buttons, and colors.
 */
$scannerCanDelete = !empty($canManageRecords) && empty($isWarehouseHead);
$scannerCanEdit = (!empty($canManageRecords) || !empty($isSystemAdmin)) && empty($isWarehouseHead);
$scannerStandalone = !empty($scannerStandalone);
if (empty($scannerHome) && function_exists('route_home_for_role')) {
    $scannerHome = route_home_for_role((string)($_SESSION['role'] ?? ''));
}
$scannerHome = $scannerHome ?? 'admin_dashboard.php';
?>
        <div id="section-barcode-scanner" class="content-section<?= $scannerStandalone ? ' active scanner-standalone-section' : '' ?>">
            <div class="page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div>
                <h1><i class="bi bi-upc-scan me-2"></i>Barcode Scanner</h1>
                </div>
                <?php if (empty($scannerStandalone)): ?>
                <a class="btn btn-outline-primary btn-sm d-md-none" href="scan.php"><i class="bi bi-phone me-1"></i>Full screen</a>
                <?php endif; ?>
            </div>

            <div class="row g-3 scanner-layout">
                <!-- Camera / Scanner Panel -->
                <div class="col-lg-7">
                    <div class="card scanner-camera-card">
                        <div class="card-header">
                            <div class="card-title"><i class="bi bi-camera-video me-2"></i>Camera Scanner</div>
                            <div id="scannerStatusBadge" class="badge badge-blue">Idle</div>
                        </div>
                        <div class="card-body">
                            <div id="dashScannerViewfinder" class="scanner-viewfinder">
                                <div id="dashScannerViewport"></div>
                                <div class="scanner-overlay">
                                    <div id="dashScanBrackets" class="scanner-brackets">
                                        <div class="scanner-bracket scanner-bracket-tl"></div>
                                        <div class="scanner-bracket scanner-bracket-tr"></div>
                                        <div class="scanner-bracket scanner-bracket-bl"></div>
                                        <div class="scanner-bracket scanner-bracket-br"></div>
                                    </div>
                                    <div id="dashScanLine" class="scanner-scan-line"></div>
                                </div>
                                <div id="dashScannerPlaceholder" class="scanner-placeholder">
                                    <i class="bi bi-upc-scan"></i>
                                    <p><strong>Tap to scan or use your camera.</strong><br>You can also enter a barcode manually below.</p>
                                </div>
                            </div>

                            <div id="dashCameraError" class="alert alert-warning scanner-camera-error" style="display:none;" role="alert">
                                <strong id="dashCameraErrorTitle">Camera Access Required</strong>
                                <p class="mb-2 mt-1" id="dashCameraErrorText">Please allow camera access in your browser settings to use the barcode scanner.</p>
                                <button type="button" class="btn btn-primary btn-sm" id="dashRetryCameraBtn" onclick="dashStartScanner()"><i class="bi bi-camera-video me-1"></i>Retry Camera</button>
                            </div>

                            <div class="scanner-controls d-flex gap-2 mb-3">
                                <button class="btn btn-primary flex-fill" id="dashStartScannerBtn" type="button" onclick="dashStartScanner()"><i class="bi bi-camera-video me-1"></i>Scan with Camera</button>
                                <button class="btn btn-outline-danger flex-fill" id="dashStopScannerBtn" type="button" onclick="dashStopScanner()" style="display:none;"><i class="bi bi-stop-circle me-1"></i>Stop</button>
                            </div>
                            <button type="button" class="scanner-fab" id="dashScannerFab" onclick="dashToggleCamera()" aria-label="Tap to scan">
                                <i class="bi bi-camera-fill"></i>
                            </button>
                            <p class="text-center text-muted scanner-fab-hint mb-3">Tap to scan or use your camera.</p>

                            <div class="scanner-or-divider"><span>OR</span></div>

                            <label class="form-label" style="font-weight:600;font-size:13px;" for="dashBarcodeInput"><i class="bi bi-keyboard me-1"></i>Enter Barcode Manually</label>
                            <div class="d-flex gap-2 scanner-manual-row">
                                <input type="text" class="form-control" id="dashBarcodeInput" placeholder="Enter barcode…" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="text">
                                <button class="btn btn-primary" type="button" onclick="dashManualLookup()"><i class="bi bi-search me-1"></i>Search</button>
                            </div>
                            <div class="text-muted mt-1" style="font-size:12px;">Same product lookup as camera scanning. USB scanners auto-submit.</div>
                        </div>
                    </div>
                </div>

                <!-- Product Details + Actions -->
                <div class="col-lg-5">
                    <div class="card mb-3 scanner-result-card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-box-seam me-2"></i>Product Details</div></div>
                        <div class="card-body">
                            <div id="dashProductInfo">
                                <div class="text-center py-4" style="color:var(--text-tertiary);">
                                    <i class="bi bi-box-seam" style="font-size:40px;opacity:0.4;"></i>
                                    <p class="mt-2 mb-0">No item scanned yet.<br>Scan a barcode to see product details.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3 scanner-actions-card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-box-arrow-in-down me-2"></i>Stock In</div></div>
                        <div class="card-body">
                            <div class="d-flex gap-2 mb-2 scanner-action-toggle">
                                <button type="button" class="btn btn-outline-success flex-fill dash-action-btn active" data-dash-action="stock_in" onclick="dashSelectAction('stock_in', this)"><i class="bi bi-box-arrow-in-down me-1"></i>Stock In</button>
                                <button type="button" class="btn btn-outline-warning flex-fill dash-action-btn" data-dash-action="stock_out" onclick="dashSelectAction('stock_out', this)"><i class="bi bi-box-arrow-up me-1"></i>Stock Out</button>
                            </div>

                            <div id="dashScanSession" class="scanner-count-box mb-2" style="display:none;">
                                <div class="d-flex justify-content-between"><span>Expected</span><strong id="dashExpectedLabel">—</strong></div>
                                <div class="d-flex justify-content-between"><span>Scanned / Received</span><strong id="dashReceivedLabel">0</strong></div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label" for="dashExpectedQty">Expected quantity (optional)</label>
                                <input type="number" class="form-control" id="dashExpectedQty" min="1" placeholder="e.g. 100" inputmode="numeric">
                            </div>

                            <div class="mb-2" id="dashBatchWrap" style="display:none;">
                                <label class="form-label" for="dashBatchSelect">Batch / Lot</label>
                                <select class="form-select" id="dashBatchSelect"></select>
                            </div>

                            <div class="d-flex gap-2 scanner-qty-row">
                                <div class="scanner-qty-field">
                                    <label class="form-label" for="dashQuantityInput">Scan Quantity</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="dashQuantityInput" min="1" value="1" inputmode="numeric">
                                        <span class="input-group-text" id="dashQtyUnit">pcs</span>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-primary flex-fill" id="dashApplyActionBtn" onclick="dashApplyAction()"><i class="bi bi-check-lg me-1"></i>Confirm</button>
                            </div>
                            <p class="text-muted small mt-2 mb-0">Each scan of the same product adds 1. Confirm once to save to inventory.</p>
                            <div id="dashReceivingHint" class="alert alert-info py-2 mt-2 mb-0 small" style="display:none;">
                                Receiving Good / Replacement is recorded on Incoming Deliveries.
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="(function(){var dest=<?= json_encode(($scannerHome ?? 'admin_dashboard.php') . '#incoming-deliveries') ?>;if(document.getElementById('section-incoming-deliveries')&&typeof showSection==='function'){showSection('incoming-deliveries');}else{window.location.href=dest;}})()">Open Incoming Deliveries</button>
                            </div>
                            <div id="dashStockConfirm" class="alert alert-success scanner-success-banner mt-2 mb-0" style="display:none;"></div>
                            <?php if ($scannerCanEdit || !empty($isWarehouseHead) || !empty($isSystemAdmin)): ?>
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="dashEditProduct()"><i class="bi bi-pencil me-1"></i><?= $scannerCanEdit ? 'View / Edit' : 'View Details' ?></button>
                                <?php if ($scannerCanDelete): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger flex-fill" onclick="dashDeleteProduct()"><i class="bi bi-trash me-1"></i>Delete</button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-clock-history me-2"></i>Recent Scans</div></div>
                        <div class="card-body">
                            <ul id="dashRecentScans" class="list-unstyled mb-0" style="max-height:250px;overflow-y:auto;"></ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="dashEditProductModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Edit Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <form id="dashEditProductForm">
                                <input type="hidden" id="dashEditProductId">
                                <div class="mb-2"><label class="form-label">Name</label><input type="text" class="form-control" id="dashEditName" required></div>
                                <div class="mb-2"><label class="form-label">SKU</label><input type="text" class="form-control" id="dashEditSku" required></div>
                                <div class="mb-2"><label class="form-label">Barcode</label><input type="text" class="form-control" id="dashEditBarcode"></div>
                                <div class="mb-2"><label class="form-label">Location</label><input type="text" class="form-control" id="dashEditLocation"></div>
                                <div class="mb-3"><label class="form-label">Unit Price</label><input type="number" class="form-control" id="dashEditPrice" step="0.01"></div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                                    <?php if ($scannerCanEdit): ?>
                                    <button type="submit" class="btn btn-primary flex-fill">Save</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
