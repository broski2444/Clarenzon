<?php
$incomingGrActiveTab = $incomingGrActiveTab ?? 'incoming-deliveries';
$canIncomingTab = function_exists('isSecAllowed') && isSecAllowed('incoming-deliveries');
$canGoodsReceivedTab = function_exists('isSecAllowed') && isSecAllowed('purchase-receipts');
if (!$canIncomingTab || !$canGoodsReceivedTab) {
    return;
}
$incomingIsActive = ($incomingGrActiveTab === 'incoming-deliveries');
$grIsActive = ($incomingGrActiveTab === 'purchase-receipts');
?>
<ul class="nav nav-pills incoming-gr-tabs mb-3" role="tablist" aria-label="Incoming deliveries and goods received">
    <li class="nav-item" role="presentation">
        <button type="button"
            class="nav-link<?= $incomingIsActive ? ' active' : '' ?>"
            role="tab"
            data-incoming-gr-tab="incoming-deliveries"
            aria-controls="section-incoming-deliveries"
            aria-selected="<?= $incomingIsActive ? 'true' : 'false' ?>"
            <?= $incomingIsActive ? 'aria-current="page"' : '' ?>
            tabindex="<?= $incomingIsActive ? '0' : '-1' ?>"
            onclick="showSection('incoming-deliveries')">
            <i class="bi bi-truck me-1"></i>Incoming Deliveries
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button type="button"
            class="nav-link<?= $grIsActive ? ' active' : '' ?>"
            role="tab"
            data-incoming-gr-tab="purchase-receipts"
            aria-controls="section-purchase-receipts"
            aria-selected="<?= $grIsActive ? 'true' : 'false' ?>"
            <?= $grIsActive ? 'aria-current="page"' : '' ?>
            tabindex="<?= $grIsActive ? '0' : '-1' ?>"
            onclick="showSection('purchase-receipts')">
            <i class="bi bi-box-arrow-in-down me-1"></i>Goods Received
        </button>
    </li>
</ul>
