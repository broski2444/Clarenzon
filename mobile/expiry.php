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

mobile_render_start('Expiry', 'alerts', ['page' => 'expiry', 'back' => mobile_url('alerts.php')]);
?>
<div class="m-seg" id="expFilter">
    <button type="button" class="is-active" data-status="all">All</button>
    <button type="button" data-status="Near Expiry">Near expiry</button>
    <button type="button" data-status="Expired">Expired</button>
    <button type="button" data-status="Safe">Safe</button>
</div>
<div id="expList" class="m-list">Loading…</div>
<?php mobile_render_end('alerts');