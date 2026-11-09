<?php
require_once __DIR__ . '/../route_protection.php';
route_require_login(['purchaser']);
header("Location: ../purchaser.php");
exit;
