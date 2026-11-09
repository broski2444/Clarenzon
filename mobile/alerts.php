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

mobile_render_start('Alerts', 'alerts', ['page' => 'alerts']);
?>
<div class="m-seg" id="alertTabs">
    <button type="button" class="is-active" data-tab="all">All</button>
    <button type="button" data-tab="low">Low Stock</button>
    <button type="button" data-tab="near">Near Expiry</button>
    <button type="button" data-tab="expired">Expired</button>
</div>
<div id="alertSummary" class="m-list"></div>
<h2 class="m-section-h">Recent Alerts</h2>
<div id="alertList" class="m-list">Loading…</div>
<?php mobile_render_end('alerts');