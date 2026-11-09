<?php
/**
 * Output wrapper only. Passes the original response through and, after a
 * successful write, records a real-time event for other signed-in sessions.
 */
if (defined('CLARENZON_REALTIME_PREPEND')) {
    return;
}
define('CLARENZON_REALTIME_PREPEND', true);

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    return;
}

$__rtScript = strtolower(basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? '')));
if ($__rtScript === 'realtime_stream.php' || $__rtScript === 'realtime_prepend.php' || $__rtScript === 'realtime_lib.php') {
    unset($__rtScript);
    return;
}
unset($__rtScript);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'realtime_lib.php';

if (!function_exists('clarenzon_realtime_buffer')) {
    function clarenzon_realtime_buffer(string $buffer): string
    {
        try {
            clarenzon_realtime_after_response($buffer);
            $buffer = clarenzon_realtime_inject($buffer);
        } catch (Throwable $e) {
        }
        try {
            return function_exists('clarenzon_tab_inject') ? clarenzon_tab_inject($buffer) : $buffer;
        } catch (Throwable $e) {
            return $buffer;
        }
    }
}

ob_start('clarenzon_realtime_buffer');
