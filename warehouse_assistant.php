<?php
session_start();
require_once 'config.php';
require_once 'route_protection.php';
require_once 'warehouse_permissions.php';

route_require_login();

$role = (string)($_SESSION['role'] ?? '');

if (!isWarehouseAssistantRole($role) && !isWarehouseHeadRole($role) && !isAdminWarehouseRole($role)) {
    header("Location: admin_dashboard.php");
    exit();
}

header("Location: warehouse_head.php");
exit();


