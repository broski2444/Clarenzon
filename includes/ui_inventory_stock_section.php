        <div id="section-stock-inventory" class="content-section">
            <div class="page-header"><h1>Inventory</h1></div>
            <div class="stats-grid" id="inventoryOverviewStats">
                <div class="stat-card blue">
                    <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-archive"></i></div></div>
                    <div class="stat-card-label">Total Products</div>
                    <div class="stat-card-value" id="invStatProducts">0</div>
                </div>
                <div class="stat-card emerald">
                    <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-box-seam"></i></div></div>
                    <div class="stat-card-label">Total Stock</div>
                    <div class="stat-card-value" id="invStatStock">0</div>
                </div>
                <div class="stat-card violet">
                    <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-cart-check"></i></div></div>
                    <div class="stat-card-label">Total Items Sold</div>
                    <div class="stat-card-value" id="invStatSold">0</div>
                </div>
                <button type="button" class="stat-card amber" onclick="filterInventoryOverviewStatus('Low Stock')">
                    <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-exclamation-triangle"></i></div></div>
                    <div class="stat-card-label">Low Stock</div>
                    <div class="stat-card-value" id="invStatLow">0</div>
                </button>
                <button type="button" class="stat-card rose" onclick="filterInventoryOverviewStatus('Out of Stock')">
                    <div class="stat-card-header"><div class="stat-card-icon"><i class="bi bi-x-octagon"></i></div></div>
                    <div class="stat-card-label">Out of Stock</div>
                    <div class="stat-card-value" id="invStatOut">0</div>
                </button>
            </div>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="card-title mb-0">Stock Overview</div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <select id="inventoryOverviewCategory" class="form-select form-select-sm" style="width:min(100%,180px); max-width:100%;" onchange="inventoryOverviewFilterChanged()">
                            <option value="">All Categories</option>
                        </select>
                        <select id="inventoryOverviewStatus" class="form-select form-select-sm" style="width:min(100%,160px); max-width:100%;" onchange="inventoryOverviewFilterChanged()">
                            <option value="">All Stock</option>
                            <option value="In Stock">In Stock</option>
                            <option value="Low Stock">Low Stock</option>
                            <option value="Out of Stock">Out of Stock</option>
                        </select>
                        <select id="inventoryOverviewSort" class="form-select form-select-sm" style="width:min(100%,190px); max-width:100%;" onchange="inventoryOverviewFilterChanged()">
                            <option value="name">Name A–Z</option>
                            <option value="stock-desc">Stock (High–Low)</option>
                            <option value="stock-asc">Stock (Low–High)</option>
                            <option value="sold-desc">Total Sold (High–Low)</option>
                            <option value="sold-asc">Total Sold (Low–High)</option>
                        </select>
                        <div class="search-box" style="width:min(100%,240px); max-width:100%;">
                            <i class="bi bi-search"></i>
                            <input type="text" id="inventoryOverviewSearch" placeholder="Search products..." oninput="inventoryOverviewFilterChanged()">
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="inventoryOverviewTable">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Current Stock</th>
                                    <th>Total Sold</th>
                                    <th>Available Stock</th>
                                    <th>Stock-In</th>
                                    <th>Stock-Out</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="inventoryOverviewBody">
                                <tr class="loading-row"><td colspan="10" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading inventory...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2" id="inventoryOverviewPager" style="border-top:1px solid var(--border-default,#e2e8f0);"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Stock by Batch / Lot</div>
                    <?php
                        $invSearchValue = trim((string)($_GET['inv_search'] ?? ''));
                        $invCategoryValue = strtolower(trim((string)($_GET['inv_category'] ?? '')));
                        $invExpiryValue = strtolower(trim((string)($_GET['inv_expiry'] ?? '')));
                        $invStockValue = strtolower(trim((string)($_GET['inv_stock'] ?? '')));
                    ?>
                    <form class="catalog-toolbar" method="get" action="<?= htmlspecialchars(basename((string)($_SERVER['PHP_SELF'] ?? 'admin_dashboard.php'))) ?>#stock-inventory" id="inventoryLotFilterForm">
                        <?php if (!empty($canManageRecords) && empty($isWarehouseHead)): ?>
                            <button class="btn btn-primary btn-sm" type="button" onclick="openStockReturnModal()"><i class="bi bi-arrow-counterclockwise me-1"></i>Stock Return</button>
                        <?php endif; ?>
                        <select id="inventoryExpiryFilter" name="inv_expiry" class="form-select catalog-filter-select" onchange="this.form.submit()">
                            <option value="">All Expiry</option>
                            <?php foreach (['expired' => 'Expired', 'near-expiry' => 'Near Expiry', 'expiring-soon' => 'Expiring Soon', 'normal' => 'Normal', 'n/a' => 'N/A'] as $expiryVal => $expiryLabel): ?>
                                <option value="<?= $expiryVal ?>" <?= $invExpiryValue === $expiryVal ? 'selected' : '' ?>><?= $expiryLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="inventoryStockFilter" name="inv_stock" class="form-select catalog-filter-select" onchange="this.form.submit()">
                            <option value="">All Stock</option>
                            <?php foreach (['out' => 'Out of Stock', 'low' => 'Low Stock', 'in' => 'In Stock', 'not-sellable' => 'Not Sellable'] as $stockVal => $stockLabel): ?>
                                <option value="<?= $stockVal ?>" <?= $invStockValue === $stockVal ? 'selected' : '' ?>><?= $stockLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="inventoryCategoryFilter" name="inv_category" class="form-select catalog-category-filter" onchange="this.form.submit()">
                            <?= function_exists('productCatalogFilterSelectHtml') ? productCatalogFilterSelectHtml($catalog_parents ?? [], $catalog_by_parent ?? []) : '<option value="">All Categories</option>' ?>
                        </select>
                        <div class="search-box catalog-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="inventoryStockSearch" name="inv_search" value="<?= htmlspecialchars($invSearchValue) ?>" placeholder="Search name, SKU, batch/lot, barcode..." onkeydown="if(event.key==='Enter'){event.preventDefault();this.form.submit();}">
                        </div>
                    </form>
                    <script>
                        (function () {
                            var category = document.getElementById('inventoryCategoryFilter');
                            if (category) category.value = <?= json_encode($invCategoryValue) ?>;
                        })();
                    </script>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table" id="inventoryStockTable">
                            <thead>
                                <tr>
                                    <th>Product Name</th><th>Brand</th><th>Category</th><th>Supplier</th>
                                    <th>QTY</th><th>Sellable</th><th>Damage</th><th>Missing</th><th>Reorder</th>
                                    <th>Batch/Lot</th><th>Expiry Date</th><th>Expiry Status</th>
                                    <th>Selling Price</th><th>Cost</th><th>Unit</th><th>Location</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($inventory_lots)): ?>
                            <?php foreach ($inventory_lots as $lot): ?>
                                <tr
                                    data-category="<?= htmlspecialchars(strtolower((string)$lot['category'])) ?>"
                                    data-parent="<?= htmlspecialchars(strtolower((string)($lot['parent_category'] ?? ''))) ?>"
                                    data-expiry="<?= htmlspecialchars(strtolower(str_replace(' ', '-', (string)($lot['expiry_label'] ?? 'n/a')))) ?>"
                                    data-stock="<?= htmlspecialchars((string)$lot['stock_filter']) ?>"
                                    data-barcodes="<?= htmlspecialchars(trim(implode(' ', array_filter(array_merge($lot['barcode_values'] ?? [], [(string)($lot['barcode'] ?? '')]))))) ?>"
                                    data-batch="<?= htmlspecialchars(strtolower((string)$lot['batch_lot'])) ?>"
                                    data-sku="<?= htmlspecialchars(strtolower((string)$lot['sku'])) ?>"
                                >
                                    <td>
                                        <button type="button" class="product-image-link" onclick="showProductImageModal(<?= htmlspecialchars(json_encode($lot['name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($lot['image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)"><?= htmlspecialchars($lot['name']) ?></button>
                                        <div class="text-muted" style="font-size:11px"><?= htmlspecialchars($lot['sku']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($lot['brand'] ?: '—') ?></td>
                                    <td><?php $catShow = trim((string)($lot['parent_category'] ?? '')); echo htmlspecialchars($catShow !== '' ? ($catShow . ' / ' . $lot['category']) : $lot['category']); ?></td>
                                    <td><?= htmlspecialchars($lot['supplier_name']) ?></td>
                                    <td><span class="badge <?= ((int)$lot['qty'] <= (int)$lot['reorder_level']) ? 'badge-red' : 'badge-green' ?>"><?= (int)$lot['qty'] ?></span></td>
                                    <td><?php $sq = (int)$lot['sellable']; if ($sq <= 0): ?><span class="badge badge-red">0</span><?php elseif ($sq <= (int)$lot['reorder_level']): ?><span class="badge badge-orange"><?= $sq ?></span><?php else: ?><span class="badge badge-green"><?= $sq ?></span><?php endif; ?></td>
                                    <td><?= (int)$lot['damage'] > 0 ? '<span class="badge badge-orange">' . (int)$lot['damage'] . '</span>' : '0' ?></td>
                                    <td><?= (int)$lot['missing'] > 0 ? '<span class="badge badge-red">' . (int)$lot['missing'] . '</span>' : '0' ?></td>
                                    <td><?= (int)$lot['reorder_level'] ?></td>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($lot['batch_lot']) ?></span></td>
                                    <td><?= htmlspecialchars($lot['expiry_display']) ?></td>
                                    <td><span class="badge <?= htmlspecialchars($lot['expiry_class']) ?>"><?= htmlspecialchars($lot['expiry_label']) ?></span></td>
                                    <td>₱<?= number_format((float)$lot['price'], 2) ?></td>
                                    <td>₱<?= number_format((float)$lot['cost'], 2) ?></td>
                                    <td><?= htmlspecialchars($lot['uom']) ?></td>
                                    <td><?= htmlspecialchars($lot['location']) ?></td>
                                    <td>
                                        <?php if (!empty($canManageRecords) && empty($isWarehouseHead)): ?>
                                        <button class="btn-icon" title="Record damage" onclick="openStockConditionModal(<?= (int)$lot['id'] ?>, <?= (int)$lot['batch_id'] ?>, <?= htmlspecialchars(json_encode($lot['name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($lot['batch_lot']), ENT_QUOTES, 'UTF-8') ?>, 'Damage', <?= (int)$lot['sellable'] ?>)"><i class="bi bi-exclamation-octagon"></i></button>
                                        <button class="btn-icon" title="Record missing" onclick="openStockConditionModal(<?= (int)$lot['id'] ?>, <?= (int)$lot['batch_id'] ?>, <?= htmlspecialchars(json_encode($lot['name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($lot['batch_lot']), ENT_QUOTES, 'UTF-8') ?>, 'Missing', <?= (int)$lot['sellable'] ?>)"><i class="bi bi-question-circle"></i></button>
                                        <button class="btn-icon" title="Stock return" onclick="openStockReturnModal(<?= (int)$lot['id'] ?>)"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        <?php else: ?>
                                        <span class="text-muted" style="font-size:12px;">View only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="17" class="text-center text-muted py-4">No inventory lots yet. Receive stock to create batch / lot records.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-header">
                    <div class="card-title">Damage &amp; Missing History</div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadStockConditionHistory()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr><th>Product</th><th>Batch/Lot</th><th>Qty</th><th>Type</th><th>Reason / Remarks</th><th>Date</th><th>User</th></tr>
                            </thead>
                            <tbody id="stockConditionHistoryBody">
                                <tr><td colspan="7" class="text-center text-muted py-3">Loading adjustment history…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-header">
                    <div class="card-title">Returns &amp; Replacements</div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadReturnReplacementHistory()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-container system-table-scroll">
                        <table class="modern-table">
                            <thead>
                                <tr><th>Return #</th><th>Party</th><th>Original Product</th><th>Qty</th><th>Condition</th><th>Replacement</th><th>Reason</th><th>Date</th></tr>
                            </thead>
                            <tbody id="returnHistoryBody">
                                <tr><td colspan="8" class="text-center text-muted py-3">Loading return history…</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-container system-table-scroll" style="border-top:1px solid #e2e8f0;">
                        <table class="modern-table">
                            <thead>
                                <tr><th>Replacement #</th><th>Original</th><th>Replaced With</th><th>Qty</th><th>Reason</th><th>Source</th><th>Date</th></tr>
                            </thead>
                            <tbody id="replacementHistoryBody">
                                <tr><td colspan="7" class="text-center text-muted py-3">Loading replacement history…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
