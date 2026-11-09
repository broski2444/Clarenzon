<?php

if (!function_exists('clarenzon_json_out')) {
    function clarenzon_json_out(bool $success, string $message, mixed $data = null, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ]);
        exit;
    }
}
