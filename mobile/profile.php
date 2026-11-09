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
$initialSource = $user['first_name'] !== '' ? $user['first_name'] : ($user['full_name'] !== '' ? $user['full_name'] : $user['username']);
$initial = strtoupper(substr($initialSource, 0, 1));
mobile_render_start('Menu', 'menu', ['page' => 'profile']);
?>
<section class="m-menu-hero">
    <div class="m-avatar"><?= mobile_h($initial) ?></div>
    <div>
        <strong><?= mobile_h(trim($user['first_name'] . ' ' . $user['last_name']) !== '' ? trim($user['first_name'] . ' ' . $user['last_name']) : $user['full_name']) ?></strong>
        <p class="m-muted" style="color:#dbeafe;margin:4px 0 0"><?= mobile_h(mobile_role_label($user['role_key'])) ?> · <?= mobile_h($user['username']) ?></p>
    </div>
</section>
<nav>
    <a class="m-menu-item" href="scan.php"><?= mobile_icon('scan') ?> Scan Barcode</a>
    <a class="m-menu-item" href="deliveries.php"><?= mobile_icon('stock') ?> Incoming PO</a>
    <a class="m-menu-item" href="deliveries.php?filter=receiving"><?= mobile_icon('scan') ?> Receive &amp; Inspect</a>
    <a class="m-menu-item" href="inventory.php"><?= mobile_icon('stock') ?> Stock List</a>
    <a class="m-menu-item" href="reports.php"><?= mobile_icon('bell') ?> Reports</a>
    <a class="m-menu-item" href="settings.php"><?= mobile_icon('menu') ?> Settings</a>
    <a class="m-menu-item danger" href="logout.php"><?= mobile_icon('back') ?> Logout</a>
</nav>
<?php mobile_render_end('menu');