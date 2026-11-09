<?php
/**
 * Ends the current role session. Existing dashboard logout links still work.
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'json_response.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

clarenzon_session_start();
if (function_exists('clarenzon_logout_current')) {
    clarenzon_logout_current();
}

$wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
if ($wantsJson) {
    clarenzon_json_out(true, 'Operation successful', null);
}

header('Location: ' . clarenzon_project_web_base() . 'login.php');
exit;
