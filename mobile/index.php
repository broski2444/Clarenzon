<?php
declare(strict_types=1);
$GLOBALS['MOBILE_ALLOW_GUEST'] = true;
require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php';

mobile_session_start();
$next = 'login.php';
if (!empty($_SESSION['user_id'])) {
    $next = mobile_is_allowed_role() ? 'dashboard.php' : 'login.php';
}
?>
<!DOCTYPE html>
<html lang="en" data-sw="<?= mobile_h(mobile_url('sw.js')) ?>">
<head>
    <?php mobile_pwa_head('Warehouse Mobile'); ?>
</head>
<body class="m-splash" id="splash">
    <div class="m-splash-mark"><?= mobile_icon('cross') ?></div>
    <h1>CLARENZON</h1>
    <p class="sub">Medical Supplies</p>
    <p class="kicker">Warehouse Mobile</p>
    <p class="tag">Better Inventory, Healthier Tomorrow.</p>
    <script>
    setTimeout(function () {
        window.location.replace(<?= json_encode(mobile_url($next)) ?>);
    }, 1600);
    </script>
    <script src="<?= mobile_h(mobile_asset('pwa.js')) ?>"></script>
</body>
</html>
