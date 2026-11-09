<?php
/**
 * Web path helper. Credentials stay in the existing database connection.
 */

if (!function_exists('clarenzon_project_web_base')) {
    function clarenzon_project_web_base(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $marker = '/backend/api/';
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            $base = substr($script, 0, $pos);
            return ($base === '' ? '' : $base) . '/';
        }
        if (function_exists('clarenzon_app_base_path')) {
            return clarenzon_app_base_path();
        }
        return '/';
    }
}
