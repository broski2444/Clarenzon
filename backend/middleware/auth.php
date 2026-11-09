<?php
/**
 * Backend authorization. Pages and APIs keep using the existing session and role checks.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'route_protection.php';

if (!function_exists('clarenzon_backend_require')) {
    function clarenzon_backend_require(array $allowedRoles = []): void
    {
        clarenzon_api_require($allowedRoles);
    }
}
