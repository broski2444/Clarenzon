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

$tab = strtolower(trim((string)($_GET['tab'] ?? 'pending')));
if (!in_array($tab, ['pending', 'ready', 'all'], true)) {
    $tab = 'pending';
}
mobile_render_start('Stock-In', 'menu', ['page' => 'stock_in', 'back' => mobile_url('profile.php')]);
?>
<div class="m-seg" id="siTabs" data-tab="<?= mobile_h($tab) ?>">
    <a href="stock_in.php?tab=pending" class="<?= $tab === 'pending' ? 'is-active' : '' ?>">Pending</a>
    <a href="stock_in.php?tab=ready" class="<?= $tab === 'ready' ? 'is-active' : '' ?>">Ready</a>
    <a href="stock_in.php?tab=all" class="<?= $tab === 'all' ? 'is-active' : '' ?>">All</a>
</div>
<div id="siList" class="m-list">Loading…</div>
<?php mobile_render_end('menu');