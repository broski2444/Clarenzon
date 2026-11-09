<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}
clarenzon_logout_current();
header('Location: login.php');
exit;
