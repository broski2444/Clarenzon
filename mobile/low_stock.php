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

mobile_render_start('Low stock', 'alerts', ['page' => 'low_stock', 'back' => mobile_url('alerts.php')]);
?>
<div id="lowList" class="m-list">Loading…</div>
<?php mobile_render_end('alerts');