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

mobile_render_start('Reports', 'menu', ['page' => 'reports', 'back' => mobile_url('profile.php')]);
?>
<div class="m-dash-grid" id="repCards">
    <a class="m-dash-card m-dash-teal" href="deliveries.php" data-key="incoming"><b data-count>—</b><span>Incoming PO</span></a>
    <a class="m-dash-card m-dash-coral" href="stock_in.php" data-key="pending_stock_in"><b data-count>—</b><span>Pending Stock-In</span></a>
    <a class="m-dash-card m-dash-orange" href="alerts.php?tab=low" data-key="low_stock"><b data-count>—</b><span>Low Stock</span></a>
    <a class="m-dash-card m-dash-purple" href="alerts.php?tab=near" data-key="near_expiry"><b data-count>—</b><span>Near Expiry</span></a>
</div>
<nav class="m-list" style="margin-top:16px">
    <a class="m-card" href="stock_in.php">Stock-In authorization queue</a>
    <a class="m-card" href="returns.php">Damage / missing returns</a>
    <a class="m-card" href="expiry.php">Batch / expiry monitoring</a>
</nav>
<?php mobile_render_end('menu');