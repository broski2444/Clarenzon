<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once __DIR__ . '/../route_protection.php';
route_require_login();
header('Location: ../scan.php?auth_ctx=purchaser');
exit;
