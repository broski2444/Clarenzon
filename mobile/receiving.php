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

$poId = (int)($_GET['po_id'] ?? 0);
mobile_render_start('Receive & Inspect Delivery', 'home', [
    'page' => 'receiving',
    'back' => mobile_url('deliveries.php'),
]);
?>
<div id="recvApp" data-po-id="<?= $poId ?>">
    <?php if ($poId <= 0): ?>
        <p class="m-empty">Open an incoming purchase order to start receiving.</p>
        <a class="m-btn m-btn-primary m-btn-block" href="deliveries.php">Incoming Purchase Orders</a>
    <?php else: ?>
        <div id="recvHeader">Loading purchase order…</div>
        <button type="button" class="m-scan-hero" id="recvStart">Scan Items<br><small>Scan barcode to receive</small></button>
        <button type="button" class="m-btn m-btn-ghost m-btn-block" id="recvStop" hidden>Stop camera</button>
        <div class="m-viewport" id="recvCamWrap" hidden>
            <div id="recvViewport" class="m-cam"></div>
            <div class="m-scan-frame"></div>
            <p class="m-scan-hint" id="recvHint">Point camera at barcode</p>
        </div>
        <div class="m-seg" id="recvMode">
            <button type="button" class="is-active" data-mode="good">Goods</button>
            <button type="button" data-mode="damage">Replacement</button>
        </div>
        <p class="m-perm" id="recvPerm" hidden></p>
        <form class="m-manual" id="recvManual">
            <label class="m-label" for="recvCode">Manual barcode</label>
            <div class="m-row">
                <input class="m-input" id="recvCode" autocomplete="off" placeholder="Type barcode">
                <button class="m-btn m-btn-primary" type="submit">Add</button>
            </div>
        </form>
        <section class="m-panel">
            <h2>Batch / lot</h2>
            <label class="m-label" for="recvLot">Lot number</label>
            <input class="m-input" id="recvLot" placeholder="Auto if left blank">
            <label class="m-label" for="recvExp">Expiry date</label>
            <input class="m-input" id="recvExp" type="date">
            <label class="m-label" for="recvMfg">Manufacturing date</label>
            <input class="m-input" id="recvMfg" type="date">
            <label class="m-label" for="recvDriver">Delivered by</label>
            <input class="m-input" id="recvDriver" placeholder="Driver / courier">
            <label class="m-label" for="recvNotes">Remarks</label>
            <textarea class="m-input" id="recvNotes" rows="2" placeholder="e.g. guba ang box"></textarea>
        </section>
        <section class="m-panel">
            <h2>Items</h2>
            <div id="recvLines" class="m-list">Loading…</div>
        </section>
        <button type="button" class="m-btn m-btn-primary m-btn-block" id="recvComplete" style="margin-bottom:12px">Complete Receiving</button>
    <?php endif; ?>
</div>
<?php mobile_render_end('home');