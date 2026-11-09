<?php
/**
 * Warehouse Head entry — include admin_dashboard at TOP LEVEL so role flags
 * are real PHP globals (required by isSecAllowed / section gating).
 */
require_once __DIR__ . '/../role_page_loader.php';

$target = role_page_prepare('admin_dashboard.php', ['warehouse_head']);
$rootDir = dirname(__DIR__);
$previousDir = getcwd();
chdir($rootDir);
ob_start();
require $target;
$html = ob_get_clean();
if ($previousDir !== false) {
    chdir($previousDir);
}
echo role_page_rewrite_assets($html);
