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

$user = mobile_user();
mobile_render_start('Warehouse', 'home', ['page' => 'dashboard', 'chrome' => 'brand']);
?>
<section class="m-welcome">
    <h1>Welcome, <?= mobile_h($user['first_name'] !== '' ? $user['first_name'] : ($user['full_name'] !== '' ? $user['full_name'] : mobile_role_label($user['role_key']))) ?></h1>
    <p>Warehouse Dashboard</p>
    <p class="m-sync" id="mSync"><span class="m-sync-dot" id="mSyncDot"></span><span id="mSyncText">Connecting…</span></p>
</section>
<a class="m-scan-cta" href="scan.php"><?= mobile_icon('scan') ?> Scan Barcode</a>
<div class="m-dash-grid" id="dashCards">
    <a class="m-dash-card m-dash-teal" href="deliveries.php" data-key="incoming">
        <i><?= mobile_icon('stock') ?></i>
        <b data-count>—</b>
        <span>Incoming PO</span>
    </a>
    <a class="m-dash-card m-dash-coral" href="deliveries.php?filter=for_receiving" data-key="for_receiving">
        <i><?= mobile_icon('scan') ?></i>
        <b data-count>—</b>
        <span>For Receiving</span>
    </a>
    <a class="m-dash-card m-dash-orange" href="alerts.php?tab=low" data-key="low_stock">
        <i><?= mobile_icon('bell') ?></i>
        <b data-count>—</b>
        <span>Low Stock</span>
    </a>
    <a class="m-dash-card m-dash-purple" href="alerts.php?tab=near" data-key="near_expiry">
        <i><?= mobile_icon('bell') ?></i>
        <b data-count>—</b>
        <span>Near Expiry</span>
    </a>
</div>
<h2 class="m-section-h">Recent Activities</h2>
<div id="dashMoves">Loading…</div>
<?php mobile_render_end('home');