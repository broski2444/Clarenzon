/* Audit log admin dashboard actions */
function editAuditLog(id, currentDetails) {
    appPrompt('Edit audit details:', currentDetails || '').then(nextDetails => {
    if (nextDetails === null) return;
    const trimmed = String(nextDetails).trim();
    if (!trimmed) {
        alert('Details cannot be empty.');
        return;
    }
    const idInput = document.getElementById('audit_log_edit_id');
    const detailsInput = document.getElementById('audit_log_edit_details');
    const form = document.getElementById('auditLogEditForm');
    if (!idInput || !detailsInput || !form) return;
    idInput.value = id;
    detailsInput.value = trimmed;
    form.submit();
    });
}
