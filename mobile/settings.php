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
mobile_render_start('Settings', 'menu', ['page' => 'settings', 'back' => mobile_url('profile.php')]);
?>
<section class="m-panel">
    <h2><?= mobile_h(trim($user['first_name'] . ' ' . $user['last_name']) !== '' ? trim($user['first_name'] . ' ' . $user['last_name']) : $user['full_name']) ?></h2>
    <p class="m-muted"><?= mobile_h(mobile_role_label($user['role_key'])) ?> · <?= mobile_h($user['username']) ?></p>
</section>
<nav class="m-list m-navlist">
    <a class="m-card" id="nativeSettings" hidden href="#">Change warehouse server</a>
    <a class="m-card" href="install.php">Download Android APK</a>
    <a class="m-card" href="<?= mobile_h(mobile_parent_url(route_home_for_role($user['role']))) ?>">Open desktop app</a>
    <a class="m-card" href="stock_in.php">Stock-In queue</a>
    <a class="m-card m-card-danger" href="logout.php">Logout</a>
</nav>
<?php mobile_render_end('menu');