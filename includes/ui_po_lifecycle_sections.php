        <?php if (isSecAllowed('purchase-orders')): ?>
        <div id="section-po-approvals" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>PO Approvals</h1>
                </div>
                <button class="btn btn-outline-secondary" type="button" onclick="loadPoApprovals()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
            </div>
            <ul class="nav nav-pills mb-3" id="poApprovalTabs">
                <li class="nav-item"><button class="nav-link active" type="button" data-po-approval-tab="For Approval" onclick="switchPoApprovalTab('For Approval', this)">For Approval</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-po-approval-tab="Approved" onclick="switchPoApprovalTab('Approved', this)">Approved</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-po-approval-tab="Sent to Supplier" onclick="switchPoApprovalTab('Sent to Supplier', this)">Sent to Supplier</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-po-approval-tab="Completed" onclick="switchPoApprovalTab('Completed', this)">Completed</button></li>
            </ul>
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>PO No.</th>
                                    <th>PO Date</th>
                                    <th>Supplier</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="poApprovalsBody">
                                <tr class="loading-row"><td colspan="6" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading purchase orders…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="section-po-tracker" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1>PO Tracker</h1>
                </div>
                <button class="btn btn-outline-secondary" type="button" onclick="loadPoTracker()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
            </div>
            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Search PO Number</label>
                            <input type="text" id="poTrackerNumber" class="form-control" placeholder="PO-2026-0001">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Search Supplier</label>
                            <input type="text" id="poTrackerSupplier" class="form-control" placeholder="Supplier name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Status</label>
                            <select id="poTrackerStatus" class="form-select">
                                <option value="all">All</option>
                                <option value="For Approval">For Approval</option>
                                <option value="Approved">Approved</option>
                                <option value="Sent to Supplier">Sent to Supplier</option>
                                <option value="Completed">Completed</option>
                                <option value="Draft">Draft</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary" type="button" onclick="loadPoTracker()"><i class="bi bi-funnel me-1"></i> Filter</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>PO No.</th>
                                    <th>PO Date</th>
                                    <th>Supplier</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="poTrackerBody">
                                <tr class="loading-row"><td colspan="6" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading purchase orders…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
