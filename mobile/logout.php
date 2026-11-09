<?php
declare(strict_types=1);
$GLOBALS['MOBILE_ALLOW_GUEST'] = true;
require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

mobile_session_start();
clarenzon_logout_current();
mobile_redirect('login.php');
