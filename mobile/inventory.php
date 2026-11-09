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

mobile_render_start('Stock', 'stock', ['page' => 'inventory']);
?>
<form class="m-search" id="invSearchForm">
    <span class="m-search-ico">⌕</span>
    <input class="m-input" id="invSearch" placeholder="Search product name or barcode..." autocomplete="off">
</form>
<div class="m-filters">
    <select class="m-input" id="invCat"><option value="">All Categories</option></select>
    <select class="m-input" id="invSup"><option value="">All Suppliers</option></select>
    <select class="m-input" id="invExp">
        <option value="">All Expiry</option>
        <option value="good">Good</option>
        <option value="near">Near Expiry</option>
        <option value="expired">Expired</option>
    </select>
</div>
<div class="m-th"><span>Product Name</span><span>Qty</span><span>Expiry</span></div>
<div id="invList" class="m-list">Loading live inventory…</div>
<?php mobile_render_end('stock');