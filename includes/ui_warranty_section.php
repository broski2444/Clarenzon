        <div id="section-warranties" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h1 class="h3 fw-bold mb-1"><i class="bi bi-shield-check text-primary me-2"></i>Warranty Certificates</h1>
                    <div class="text-muted small">Generate, print, and look up warranty certificates for warranty-eligible products sold.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <input type="text" id="wcSaleReference" class="form-control form-control-sm" style="width:min(100%,250px);" placeholder="SO, invoice, or receipt no." onkeydown="if(event.key==='Enter'){event.preventDefault();wcFindSaleByReference();}">
                    <button type="button" class="btn btn-primary btn-sm" onclick="wcFindSaleByReference()"><i class="bi bi-shield-plus me-1"></i>Generate from Sale</button>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap mb-3">
                <button type="button" class="btn btn-sm btn-outline-primary active" data-wc-tab="records" onclick="wcShowTab('records')"><i class="bi bi-card-list me-1"></i>Warranty Records</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-wc-tab="coverage" onclick="wcShowTab('coverage')"><i class="bi bi-box-seam me-1"></i>Product Coverage</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-wc-tab="terms" onclick="wcShowTab('terms')"><i class="bi bi-file-text me-1"></i>Terms &amp; Conditions</button>
            </div>

            <div class="card" data-wc-pane="records">
                <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                    <div class="card-title mb-0">Issued Certificates</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box" style="width:min(100%,280px); max-width:100%;">
                            <i class="bi bi-search"></i>
                            <input type="text" id="wcSearch" placeholder="Certificate, customer, product, serial, invoice…" oninput="wcScheduleLoad()" onkeydown="if(event.key==='Enter'){event.preventDefault();wcLoadRecords();}">
                        </div>
                        <select id="wcStatusFilter" class="form-select form-select-sm" style="width:min(100%,150px); max-width:100%;" onchange="wcLoadRecords()">
                            <option value="all">All Status</option>
                            <option value="Active">Active</option>
                            <option value="Expired">Expired</option>
                            <option value="Voided">Voided</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="wcLoadRecords()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table align-middle">
                            <thead>
                                <tr>
                                    <th>Certificate #</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Serial / Unit</th>
                                    <th>Invoice / SO</th>
                                    <th>Purchased</th>
                                    <th>Expires</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="wcRecordsBody">
                                <tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted">Open this section to load warranty records…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card" data-wc-pane="coverage" style="display:none">
                <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                    <div class="card-title mb-0">Product Warranty Coverage</div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <div class="search-box" style="width:min(100%,240px); max-width:100%;">
                            <i class="bi bi-search"></i>
                            <input type="text" id="wcCoverageSearch" placeholder="Product, SKU, brand, category…" oninput="wcScheduleCoverage()">
                        </div>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="wcCoverageEligibleOnly" onchange="wcLoadCoverage()">
                            <label class="form-check-label small" for="wcCoverageEligibleOnly">Covered products only</label>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <p class="small text-muted px-3 pt-3 mb-2">Only products with coverage turned on receive a warranty certificate. Set the duration from the manufacturer's or company's actual warranty — certificates are not issued without a configured duration. Changes apply to certificates generated afterwards.</p>
                    <div class="table-container system-table-scroll">
                        <table class="modern-table align-middle">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Brand</th>
                                    <th>Model</th>
                                    <th>Warranty</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody id="wcCoverageBody">
                                <tr class="loading-row"><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card" data-wc-pane="terms" style="display:none">
                <div class="card-header py-2"><div class="card-title mb-0">Default Warranty Terms and Conditions</div></div>
                <div class="card-body">
                    <p class="small text-muted">One term per line. Printed on every new certificate. Issued certificates keep the terms they were issued with. Only describe coverage the company or manufacturer actually provides.</p>
                    <textarea id="wcTermsText" class="form-control" rows="9" maxlength="12000"></textarea>
                    <div class="d-flex gap-2 justify-content-end mt-3" data-wc-manage style="display:none">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="wcResetTerms()">Restore Defaults</button>
                        <button type="button" class="btn btn-primary btn-sm" onclick="wcSaveTerms()"><i class="bi bi-save me-1"></i>Save Terms</button>
                    </div>
                </div>
            </div>
        </div>
