<?php
require_once __DIR__ . '/../role_page_loader.php';

$target = role_page_prepare('Technician.php', ['technician']);
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
