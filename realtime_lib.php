<?php
/**
 * Additive real-time bus. Writes a local event file only.
 * Does not read or write business tables.
 */

function clarenzon_realtime_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarenzon-realtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function clarenzon_realtime_log_path(): string
{
    return clarenzon_realtime_dir() . DIRECTORY_SEPARATOR . 'events.log';
}

function clarenzon_realtime_is_write_request(): bool
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return false;
    }
    $action = strtolower(trim((string)($_REQUEST['action'] ?? '')));
    if ($action !== '') {
        if (preg_match('/^(get_|load_|list_|search_|lookup_|preview_|check_|customers_get|customers_list)/', $action)) {
            return false;
        }
        return (bool)preg_match('/(create|update|delete|save|approve|confirm|execute|record|add_|set_|mark_|process|transfer|finalize|grant|register|reassign|cancel|submit|generate|remove|edit_|pay|stock|receive|bounce|clear_)/', $action);
    }
    foreach (array_keys($_POST) as $key) {
        $key = strtolower((string)$key);
        if (preg_match('/^(get_|load_|list_|search_|lookup_|preview_|check_)/', $key)) {
            continue;
        }
        if (preg_match('/(create|update|delete|save|approve|cancel|record|submit|process|payment|cheque|stock|receive|markup|sales_order)/', $key)) {
            return true;
        }
    }
    return false;
}

function clarenzon_realtime_channels(): array
{
    $action = strtolower(trim((string)($_REQUEST['action'] ?? '')));
    $blob = $action . ' ' . strtolower(implode(' ', array_keys($_POST)));
    $channels = [];
    $match = static function (string $pattern) use ($blob, &$channels): void {
        if (!preg_match($pattern, $blob)) {
            return;
        }
        $args = func_get_args();
        array_shift($args);
        foreach ($args as $channel) {
            $channels[$channel] = true;
        }
    };
    $match('/purchase_request|\bpr\b|create_pr|update_pr|delete_pr|approve_and_generate/', 'purchase-requests', 'pr-approval', 'dashboard');
    $match('/purchase_order|\bpo\b|generate_po|update_po/', 'purchase-orders', 'po-approval', 'incoming', 'dashboard');
    $match('/incoming|delivery/', 'incoming', 'dashboard');
    $match('/receipt|receive|inspect|replacement|damage|rts/', 'receiving', 'incoming', 'pricing', 'stock-in', 'inventory', 'transaction-history', 'dashboard');
    $match('/markup|pricing|stock_in|stock-in/', 'pricing', 'stock-in', 'inventory', 'dashboard');
    $match('/product|movement|batch|serial|transfer|inventory|stock_condition|stock_return/', 'inventory', 'transaction-history', 'dashboard', 'reports-inventory');
    $match('/sales_order|payment|cheque|invoice/', 'sales', 'payments', 'credit', 'reports-sales', 'dashboard');
    $match('/customer|company/', 'sales', 'dashboard');
    if (!$channels && $action !== '') {
        $channels['dashboard'] = true;
    }
    return array_keys($channels);
}

function clarenzon_realtime_emit(array $channels): void
{
    $channels = array_values(array_unique(array_filter($channels, 'strlen')));
    if (!$channels) {
        return;
    }
    $path = clarenzon_realtime_log_path();
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return;
        }
        $raw = stream_get_contents($fh);
        $lines = ($raw === false || trim($raw) === '') ? [] : preg_split("/\r\n|\n|\r/", trim($raw));
        $lastId = 0;
        if ($lines) {
            $last = json_decode((string)$lines[count($lines) - 1], true);
            $lastId = (int)($last['id'] ?? 0);
        }
        $lines[] = json_encode([
            'id' => $lastId + 1,
            'ts' => time(),
            'channels' => $channels,
            'actor' => (int)($_SESSION['user_id'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        if (count($lines) > 300) {
            $lines = array_slice($lines, -300);
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode("\n", $lines) . "\n");
        fflush($fh);
        flock($fh, LOCK_UN);
    } catch (Throwable $e) {
        @flock($fh, LOCK_UN);
    }
    fclose($fh);
}

function clarenzon_realtime_read_since(int $lastId): array
{
    $path = clarenzon_realtime_log_path();
    if (!is_file($path)) {
        return [];
    }
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return [];
    }
    $out = [];
    try {
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        $lines = ($raw === false || trim($raw) === '') ? [] : preg_split("/\r\n|\n|\r/", trim($raw));
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || (int)($row['id'] ?? 0) <= $lastId) {
                continue;
            }
            $out[] = $row;
        }
    } catch (Throwable $e) {
        $out = [];
    }
    fclose($fh);
    return $out;
}

function clarenzon_realtime_alert_is_success(string $html): bool
{
    if ($html === '' || stripos($html, 'alert-danger') !== false) {
        return false;
    }
    // Cancel Sales Order reports success with a warning-styled alert.
    return stripos($html, 'alert-success') !== false
        || stripos($html, 'Sales Order cancelled.') !== false;
}

function clarenzon_realtime_is_redirect(): bool
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'location:') === 0) {
            return true;
        }
    }
    return false;
}

function clarenzon_realtime_after_response(string $buffer): void
{
    static $done = false;
    if ($done || !clarenzon_realtime_is_write_request()) {
        return;
    }
    $trim = ltrim($buffer);
    $ok = false;
    if ($trim === '') {
        if (!clarenzon_realtime_is_redirect()) {
            return;
        }
        // Form POST + redirect: the outcome lives in the alerts the request produced.
        $ok = clarenzon_realtime_alert_is_success(
            (string)($_SESSION['flash_alert'] ?? '') . (string)($GLOBALS['billing_alert'] ?? '')
        );
    } elseif ($trim[0] === '{' || $trim[0] === '[') {
        $data = json_decode($buffer, true);
        $ok = is_array($data) && !empty($data['success']);
    } else {
        $ok = clarenzon_realtime_alert_is_success($buffer);
    }
    if (!$ok) {
        return;
    }
    $done = true;
    clarenzon_realtime_emit(clarenzon_realtime_channels());
}

function clarenzon_realtime_inject(string $buffer): string
{
    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    if (in_array($script, ['login.php', 'verify_otp.php', 'resend_otp.php', 'register.php', 'forgot_password.php'], true)) {
        return $buffer;
    }
    if ((int)($_SESSION['user_id'] ?? 0) <= 0) {
        return $buffer;
    }
    if ($buffer === '' || stripos($buffer, '</body>') === false || stripos($buffer, 'realtime_sync.js') !== false) {
        return $buffer;
    }
    $trim = ltrim($buffer);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[' || strncmp($trim, '%PDF', 4) === 0)) {
        return $buffer;
    }
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-disposition:') === 0) {
            return $buffer;
        }
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false && stripos($header, 'application/xhtml') === false) {
            return $buffer;
        }
    }
    $base = function_exists('clarenzon_app_base_path') ? clarenzon_app_base_path() : '/';
    $src = rtrim($base, '/') . '/java/realtime_sync.js?v=' . (@filemtime(__DIR__ . '/java/realtime_sync.js') ?: time());
    $tag = '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';
    $count = 0;
    $next = preg_replace('/<\/body>/i', $tag . '</body>', $buffer, 1, $count);
    return ($count === 1 && is_string($next)) ? $next : $buffer;
}
