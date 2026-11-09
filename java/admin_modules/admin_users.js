/* User permission and password-related admin dashboard actions */
function togglePassVisibility(btn) {
    const input = btn.previousElementSibling || btn.parentElement.querySelector('input');
    if (input) {
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
        } else {
            input.type = 'password';
            btn.innerHTML = '<i class="bi bi-eye"></i>';
        }
    }
}

function splitPersonName(user) {
    const first = String(user && user.first_name || '').trim();
    const last = String(user && user.last_name || '').trim();
    if (first || last) return { first, last };
    const full = String(user && user.full_name || '').trim().replace(/\s+/g, ' ');
    if (!full) return { first: '', last: '' };
    const i = full.indexOf(' ');
    if (i < 0) return { first: full, last: '' };
    return { first: full.slice(0, i), last: full.slice(i + 1) };
}

function openEditUserPermissionsModal(user) {
    if (!user) return;
    document.getElementById('edit_perm_user_id').value = user.id || '';
    const parts = splitPersonName(user);
    const firstEl = document.getElementById('edit_perm_first_name');
    const lastEl = document.getElementById('edit_perm_last_name');
    const fullEl = document.getElementById('edit_perm_full_name');
    if (firstEl) firstEl.value = parts.first;
    if (lastEl) lastEl.value = parts.last;
    if (fullEl) fullEl.value = user.full_name || [parts.first, parts.last].filter(Boolean).join(' ');
    document.getElementById('edit_perm_email').value = user.email || '';
    document.getElementById('edit_perm_role').value = user.role || 'Staff';
    document.getElementById('edit_perm_status').value = user.status || 'Active';

    const allowed = (user.allowed_sections || '').split(',').map(s => s.trim());
    document.querySelectorAll('.perm-sec-cb').forEach(cb => {
        cb.checked = allowed.includes(cb.value);
    });

    document.getElementById('perm_can_manage').checked = !!user.can_manage;
    document.getElementById('perm_can_delete').checked = !!user.can_delete;
    document.getElementById('perm_can_print').checked = !!user.can_print;
    document.getElementById('perm_can_approve').checked = !!user.can_approve;
    const pricingCb = document.getElementById('perm_can_manage_pricing');
    if (pricingCb) pricingCb.checked = !!user.can_manage_pricing;

    new bootstrap.Modal(document.getElementById('editUserPermissionsModal')).show();
}

function openUserPasswordModal(userId, username, plainPassword) {
    document.getElementById('target_user_id').value = userId;
    document.getElementById('target_username').value = username;
    document.getElementById('target_username_display').value = username;
    const oldPassDisplay = document.getElementById('target_old_password_display');
    if (oldPassDisplay) oldPassDisplay.value = plainPassword || '';

    const newPass = document.getElementById('admin_new_password');
    if (newPass) newPass.value = '';
    const confirmPass = document.getElementById('admin_confirm_password');
    if (confirmPass) confirmPass.value = '';

    const forceCheckbox = document.getElementById('force_password_change');
    if (forceCheckbox) forceCheckbox.checked = true;
    new bootstrap.Modal(document.getElementById('userPasswordModal')).show();
}
