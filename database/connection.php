<?php
/**
 * Single database entry point for new backend files.
 * Reuses the existing mysqli helper. Do not request this file from the browser.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';

if (!function_exists('clarenzon_connection')) {
    function clarenzon_connection(?string $database = 'clarenzon_db'): ?mysqli
    {
        return clarenzon_db_connect('root', '', $database, 3);
    }
}
