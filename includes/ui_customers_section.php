        <?php if (isSecAllowed('customers')): ?>
        <div id="section-customers" class="content-section">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h1 class="h3 fw-bold mb-1"><i class="bi bi-people text-primary me-2"></i>Customer Management</h1>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="customerAddBtn" data-customer-action="add" onclick="openCustomerForm()"><i class="bi bi-plus-lg me-1"></i>Add Customer</button>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                    <div class="card-title mb-0">Customers</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box" style="width:min(100%,240px); max-width:100%;">
                            <i class="bi bi-search"></i>
                            <input type="text" id="customerSearch" placeholder="Search customer..." oninput="scheduleLoadCustomers()" onkeydown="if(event.key==='Enter'){event.preventDefault();loadCustomers(1);}">
                        </div>
                        <select id="customerStatusFilter" class="form-select form-select-sm" style="width:min(100%,150px); max-width:100%;" onchange="loadCustomers(1)">
                            <option value="all">All Status</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadCustomers(1)"><i class="bi bi-funnel me-1"></i> Filter</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table align-middle" id="customersTable">
                            <thead>
                                <tr>
                                    <th>Customer ID</th>
                                    <th>Customer Name</th>
                                    <th>Contact Number</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>Total Purchases</th>
                                    <th>Last Purchase</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="customersBody">
                                <tr class="loading-row"><td colspan="9" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading customers...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2" id="customersPager" style="border-top:1px solid var(--border-default,#e2e8f0);"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
