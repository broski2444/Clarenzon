<style>
#customerFormModal.modal-overlay.open,
#customerViewModal.modal-overlay.open {
  display: flex !important;
  z-index: 6000;
}
#customerFormModal > .modal,
#customerViewModal > .modal {
  display: flex !important;
  position: relative !important;
  width: auto !important;
  height: auto !important;
}
#customerViewModal > .modal.modal-lg { max-width: min(1100px, 96vw); }
</style>
<div class="modal-overlay" id="customerFormModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="customerFormTitle"><i class="bi bi-person-plus"></i> Add Customer</span>
      <button type="button" class="modal-close" onclick="closeModal('customerFormModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="customerForm" onsubmit="return submitCustomerForm(event)">
        <input type="hidden" name="id" id="customerEditId" value="">
        <input type="hidden" name="name" id="custName" value="">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Customer Type <span>*</span></label>
            <select class="form-control" name="customer_type" id="custType" onchange="if(typeof syncCustomerFormType==='function')syncCustomerFormType()">
              <option value="individual">Individual</option>
              <option value="company">Company</option>
            </select>
          </div>
          <div id="custIndividualFields" style="display:contents">
            <div class="form-group">
              <label class="form-label">First Name <span>*</span></label>
              <input class="form-control" id="custFirstName" maxlength="80" placeholder="First name" autocomplete="given-name">
            </div>
            <div class="form-group">
              <label class="form-label">Middle Name</label>
              <input class="form-control" id="custMiddleName" maxlength="80" placeholder="Middle name" autocomplete="additional-name">
            </div>
            <div class="form-group">
              <label class="form-label">Last Name <span>*</span></label>
              <input class="form-control" id="custLastName" maxlength="80" placeholder="Last name" autocomplete="family-name">
            </div>
            <div class="form-group">
              <label class="form-label">Suffix</label>
              <input class="form-control" id="custSuffix" maxlength="20" placeholder="Jr., Sr., II, III" autocomplete="off">
            </div>
          </div>
          <div id="custCompanyFields" style="display:none">
            <div class="form-group full">
              <label class="form-label">Company Name <span>*</span></label>
              <input class="form-control" id="custCompanyName" maxlength="150" placeholder="Company name" autocomplete="organization">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Contact Number</label>
            <input class="form-control js-ph-mobile" name="phone" id="custPhone" maxlength="11" inputmode="numeric" placeholder="09171234567" autocomplete="tel">
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-control" name="email" id="custEmail" type="email" maxlength="120" placeholder="name@example.com">
          </div>
          <div class="form-group full">
            <label class="form-label">Address</label>
            <textarea class="form-control" name="address" id="custAddress" rows="2" placeholder="Street, city, province"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select class="form-control" name="status" id="custStatus">
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('customerFormModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitCustomerForm(event)"><i class="bi bi-save me-1"></i> Save Customer</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="customerViewModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-person-vcard"></i> Customer Details</span>
      <button type="button" class="modal-close" onclick="closeModal('customerViewModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="customerViewBody">
      <p class="text-muted mb-0">Loading…</p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closeModal('customerViewModal')">Close</button>
      <button type="button" class="btn btn-primary" id="customerViewEditBtn" onclick="editCustomerFromView()"><i class="bi bi-pencil me-1"></i> Edit</button>
    </div>
  </div>
</div>
