<div class="modal-overlay" id="stockConditionModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header" style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
      <span class="modal-title" style="color:#2563eb;display:flex;align-items:center;gap:8px">
        <i class="bi bi-clipboard-pulse" style="font-size:20px"></i> <span id="stockConditionTitle">Stock Adjustment</span>
      </span>
      <button class="modal-close" type="button" onclick="closeModal('stockConditionModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="stockConditionForm" onsubmit="return submitStockCondition(event)">
        <input type="hidden" name="product_id" id="stockConditionProductId" value="">
        <input type="hidden" name="batch_id" id="stockConditionBatchId" value="">
        <input type="hidden" name="type" id="stockConditionType" value="Damage">
        <p class="text-muted mb-3" style="font-size:13px">QTY stays recorded. Sellable is reduced automatically. Damage + Missing cannot exceed QTY.</p>
        <div class="form-group mb-2">
          <label class="form-label">Product</label>
          <input type="text" class="form-control" id="stockConditionProductName" readonly>
        </div>
        <div class="form-group mb-2">
          <label class="form-label">Batch / Lot</label>
          <input type="text" class="form-control" id="stockConditionBatch" readonly>
        </div>
        <div class="form-group mb-2">
          <label class="form-label">Quantity <span class="text-danger">*</span></label>
          <input type="number" class="form-control" name="quantity" id="stockConditionQty" min="1" required>
          <small class="text-muted">Sellable available: <span id="stockConditionSellable">0</span></small>
        </div>
        <div class="form-group mb-2">
          <label class="form-label">Reason / Remarks</label>
          <textarea class="form-control" name="reason" id="stockConditionReason" rows="3" placeholder="Why is this stock damaged or missing?"></textarea>
        </div>
        <div id="stockConditionError" class="alert alert-danger d-none py-2" style="font-size:13px"></div>
        <button type="submit" class="btn btn-primary w-100">Save Adjustment</button>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="viewProductModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">View Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewProductModalBody"></div>
    </div>
  </div>
</div>
