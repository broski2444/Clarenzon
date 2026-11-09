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

mobile_render_start('Incoming Purchase Orders', 'home', ['page' => 'deliveries', 'back' => mobile_url('dashboard.php')]);
?>
<div class="m-search">
    <span class="m-search-ico">⌕</span>
    <input class="m-input" id="delSearch" placeholder="Search PO number or supplier..." autocomplete="off">
</div>
<div class="m-seg" id="delFilter">
    <button type="button" class="is-active" data-filter="all">All</button>
    <button type="button" data-filter="for_receiving">For Receiving</button>
    <button type="button" data-filter="pending">Pending</button>
    <button type="button" data-filter="receiving">Receiving</button>
    <button type="button" data-filter="received">Received</button>
</div>
<div id="delList" class="m-list">Loading…</div>
<?php mobile_render_end('home');