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

$id = (int)($_GET['id'] ?? 0);
mobile_render_start('Product Details', 'stock', ['page' => 'product', 'hide_nav' => true, 'back' => mobile_url('inventory.php')]);
?>
<div id="prodApp" data-id="<?= $id ?>">
    <?php if ($id <= 0): ?>
        <p class="m-empty">Select a product from inventory.</p>
    <?php else: ?>
        <div id="prodBody">Loading…</div>
    <?php endif; ?>
</div>
<?php mobile_render_end('inventory');