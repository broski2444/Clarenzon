<?php
/**
 * Server-sent events for signed-in users.
 * WebSockets are not available: Apache's WebSocket proxy module is disabled
 * and the PHP sockets extension is not loaded. SSE is the compatible push channel.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'realtime_lib.php';

@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    @ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

if ((int)($_SESSION['user_id'] ?? 0) <= 0) {
    echo "event: denied\ndata: {}\n\n";
    @flush();
    exit;
}
session_write_close();

$lastId = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last_id'] ?? 0);
if ($lastId <= 0) {
    $existing = clarenzon_realtime_read_since(0);
    if ($existing) {
        $lastId = (int)$existing[count($existing) - 1]['id'];
    }
}
echo "retry: 1000\n";
echo ':' . str_repeat(' ', 2048) . "\n\n";
@flush();

$started = time();
$lastPing = time();
while (!connection_aborted() && (time() - $started) < 50) {
    $rows = clarenzon_realtime_read_since($lastId);
    if ($rows) {
        foreach ($rows as $row) {
            $lastId = (int)$row['id'];
            echo 'id: ' . $lastId . "\n";
            echo "event: change\n";
            echo 'data: ' . json_encode([
                'id' => $lastId,
                'channels' => array_values($row['channels'] ?? []),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        @flush();
        $lastPing = time();
    } elseif ((time() - $lastPing) >= 15) {
        echo ": ping\n\n";
        @flush();
        $lastPing = time();
    }
    usleep(200000);
}
echo "event: reconnect\ndata: {}\n\n";
@flush();
