        <?php if (isSecAllowed('sales-returns')): ?>
        <div id="section-sales-returns" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h1 class="h3 fw-bold mb-1"><i class="bi bi-arrow-return-left text-primary me-2"></i>Customer Returns</h1>
                    <div class="text-muted small" id="srPolicySummary">Loading return policy…</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="srOpenPolicy()"><i class="bi bi-shield-check me-1"></i>Return Policy</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="srOpenNewReturn()"><i class="bi bi-plus-lg me-1"></i>Record Return</button>
                </div>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                    <div class="card-title mb-0">Return History <span class="badge bg-warning text-dark ms-1" id="srPendingBadge"></span></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box" style="width:min(100%,240px); max-width:100%;">
                            <i class="bi bi-search"></i>
                            <input type="text" id="srSearch" placeholder="Return, sale, invoice, OR, customer..." oninput="srScheduleLoad()" onkeydown="if(event.key==='Enter'){event.preventDefault();srLoadReturns();}">
                        </div>
                        <select id="srStatusFilter" class="form-select form-select-sm" style="width:min(100%,170px); max-width:100%;" onchange="srLoadReturns()">
                            <option value="all">All Status</option>
                            <option value="Pending Review">Pending Review</option>
                            <option value="Completed">Completed</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="srLoadReturns()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table align-middle">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>Return Date</th>
                                    <th>Sale / Invoice</th>
                                    <th>Customer</th>
                                    <th>Qty</th>
                                    <th>Outcome</th>
                                    <th>Refund</th>
                                    <th>Status</th>
                                    <th>Recorded By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="srReturnsBody">
                                <tr class="loading-row"><td colspan="10" class="text-center py-4 text-muted">Open this section to load returns…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
