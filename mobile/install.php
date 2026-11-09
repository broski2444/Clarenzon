<?php
declare(strict_types=1);
$GLOBALS['MOBILE_ALLOW_GUEST'] = true;
require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php';

$appUrl = mobile_absolute_url('index.php');
$apkFile = __DIR__ . DIRECTORY_SEPARATOR . 'ClarenzonWarehouse.apk';
$hasApk = is_file($apkFile);
$apkUrl = mobile_url('ClarenzonWarehouse.apk');
?>
<!DOCTYPE html>
<html lang="en" data-sw="<?= mobile_h(mobile_url('sw.js')) ?>">
<head>
    <?php mobile_pwa_head('Install app'); ?>
</head>
<body class="m-login-body">
    <main class="m-login m-install-page">
        <img src="<?= mobile_h(mobile_url('assets/icon-192.png')) ?>" alt="Clarenzon Warehouse" class="m-login-logo" width="96" height="96">
        <p class="m-login-kicker">Clarenzon Warehouse</p>
        <h1>Install the mobile app</h1>

        <?php if ($hasApk): ?>
            <a class="m-btn m-btn-primary m-btn-block" href="<?= mobile_h($apkUrl) ?>">Download Android APK</a>
        <?php endif; ?>
        <button class="m-btn m-btn-ghost m-btn-block" type="button" id="mInstallBtn" data-install="<?= mobile_h(mobile_url('install.php')) ?>">Add to Home Screen</button>
        <a class="m-btn m-btn-ghost m-btn-block" href="<?= mobile_h($appUrl) ?>">Open the app now</a>

        <section class="m-install-steps">
            <?php if ($hasApk): ?>
            <h2>Android APK</h2>
            <ol>
                <li>Download the APK on the phone.</li>
                <li>Allow install from this browser / unknown sources if Android asks.</li>
                <li>Open <strong>Clarenzon Warehouse</strong>.</li>
                <li>Keep this PC and the phone on the same Wi‑Fi, with Laragon Apache running.</li>
                <li>If the app cannot load, tap Change server and enter this PC’s address.</li>
            </ol>
            <?php endif; ?>
            <h2>Android (Chrome)</h2>
            <ol>
                <li>Open this page on your phone.</li>
                <li>Tap the browser menu (⋮).</li>
                <li>Choose <strong>Install app</strong> or <strong>Add to Home screen</strong>.</li>
            </ol>
            <h2>iPhone / iPad (Safari)</h2>
            <ol>
                <li>Open this page in Safari.</li>
                <li>Tap the Share button.</li>
                <li>Choose <strong>Add to Home Screen</strong>.</li>
            </ol>
        </section>
        <p class="m-muted m-install-url">App address:<br><code><?= mobile_h($appUrl) ?></code></p>
        <a class="m-login-desktop" href="<?= mobile_h(mobile_parent_url('login.php')) ?>">Open desktop login</a>
    </main>
    <script src="<?= mobile_h(mobile_asset('pwa.js')) ?>"></script>
</body>
</html>
