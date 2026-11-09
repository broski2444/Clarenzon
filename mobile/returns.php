<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php';

if (empty($_SESSION['user_id'])) {
    mobile_redirect('login.php');
}
if (!mobile_is_allowed_role()) {
    mobile_render_denied(mobile_user());
}

mobile_render_start('Returns', 'menu', ['page' => 'returns', 'back' => mobile_url('profile.php')]);
?>
<div id="retList" class="m-list">Loading…</div>
<div id="retFormWrap" class="m-panel" hidden>
    <h2>Create return</h2>
    <form id="retForm">
        <input type="hidden" id="retDamageId">
        <p class="m-muted" id="retSummary"></p>
        <label class="m-label" for="retQty">Quantity</label>
        <input class="m-input" id="retQty" type="number" min="1" step="1" required>
        <label class="m-label" for="retReason">Reason</label>
        <select class="m-input" id="retReason">
            <option>Guba / Damaged</option>
            <option>Defective</option>
            <option>Expired</option>
            <option>Wrong item</option>
        </select>
        <label class="m-label" for="retDate">Return date</label>
        <input class="m-input" id="retDate" type="date">
        <label class="m-label" for="retNotes">Remarks</label>
        <textarea class="m-input" id="retNotes" rows="2"></textarea>
        <button class="m-btn m-btn-primary m-btn-block" type="submit">Submit return</button>
    </form>
</div>
<?php mobile_render_end('menu');