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

mobile_render_start('Scan Barcode', 'home', ['page' => 'scan', 'chrome' => 'scan', 'hide_nav' => true, 'back' => mobile_url('dashboard.php')]);
?>
<section class="m-scanner-wrap">
    <div class="m-viewport">
        <div id="scanViewport" class="m-cam"></div>
        <div class="m-scan-frame"></div>
        <p class="m-scan-hint" id="scanHint">Point camera at barcode</p>
    </div>
    <button type="button" class="m-btn m-btn-teal m-btn-block" id="scanStart">Scan barcode</button>
    <button type="button" class="m-btn m-btn-ghost m-btn-block" id="scanStop" hidden>Stop camera</button>
    <p class="m-perm" id="scanPerm" hidden></p>
    <button type="button" class="m-manual-btn" id="scanManualToggle">Enter Barcode Manually</button>
    <form class="m-manual" id="scanManual" hidden>
        <label class="m-label" for="scanCode">Barcode</label>
        <div class="m-row">
            <input class="m-input" id="scanCode" name="barcode" inputmode="numeric" autocomplete="off" placeholder="Type barcode">
            <button class="m-btn m-btn-primary" type="submit">Look up</button>
        </div>
    </form>
</section>
<section id="scanResult" hidden></section>
<?php mobile_render_end('home');