<?php
/**
 * Safe transaction-data reset. Command line only.
 *
 * Preview (no deletes):
 *   php clear_transaction_data.php
 *
 * Clear only after an explicit confirmation, and only on a local database:
 *   php clear_transaction_data.php --confirm="YES, CLEAR TRANSACTION DATA"
 *
 * A SQL backup is written before any delete. Master data is not touched.
 * Tables that are not clearly transactional are listed and skipped.
 */
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo "This reset runs only from the command line.\n";
    exit;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';

const CLEAR_TX_CONFIRM = 'YES, CLEAR TRANSACTION DATA';

/** Child tables first. */
const CLEAR_TX_TABLES = [
    'job_order_issued_supplies',
    'cycle_count_items',
    'sales_return_events',
    'sales_return_items',
    'sales_returns',
    'invoice_payments',
    'invoice_items',
    'sales_order_items',
    'stock_transfer_items',
    'purchase_receipt_inspection_barcodes',
    'purchase_receipt_item_lots',
    'purchase_receipt_items',
    'supplier_rts_replacements',
    'supplier_damage_returns',
    'supplier_rts',
    'supplier_invoices',
    'supplier_performance',
    'purchase_order_status_history',
    'product_returns',
    'product_replacements',
    'product_price_audit',
    'stock_in_approvals',
    'stock_adjustments',
    'stock_condition_adjustments',
    'stock_movements',
    'job_orders',
    'cycle_counts',
    'invoices',
    'sales_credit_agreements',
    'sales_orders',
    'stock_transfers',
    'purchase_receipts',
    'purchase_order_items',
    'purchase_orders',
    'purchase_request_items',
    'purchase_requests',
];

const CLEAR_TX_KEEP = [
    'categories',
    'companies',
    'customers',
    'login_otps',
    'password_reset_otps',
    'product_barcodes',
    'products',
    'storage_locations',
    'suppliers',
    'system_settings',
    'user_permissions',
    'users',
    'warehouse_assistant_permissions',
];

/** Not deleted. Reported so they can be confirmed later. */
const CLEAR_TX_UNCERTAIN = [
    'audit_log' => 'Mixes sign-in and business events. Not clearly transaction-only.',
    'audit_logs' => 'Mixes sign-in and business events. Not clearly transaction-only.',
    'barcode_reassignment_log' => 'Barcode history, not a stock or sales document.',
    'notifications' => 'Holds mixed module alerts, not only transaction documents.',
    'product_batches' => 'Lot balances. Tied to receipts, but also current inventory.',
    'product_location_stock' => 'Current quantity by location, not a transaction document.',
    'scan_history' => 'Scanner activity log, not a posted stock or sales document.',
    'scanner_idempotency' => 'Scanner request keys, not a business document.',
    'serial_numbers' => 'Could be asset records rather than posted transactions.',
];

$database = 'clarenzon_db';
$confirm = '';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--database=') === 0) {
        $database = substr($arg, 11);
    } elseif (strpos($arg, '--confirm=') === 0) {
        $confirm = substr($arg, 10);
    }
}
$database = preg_replace('/[^A-Za-z0-9_]/', '', $database);
if ($database === '') {
    fwrite(STDERR, "Invalid database name.\n");
    exit(1);
}

$cfg = clarenzon_db_settings();
$remote = [];
foreach ($cfg['hosts'] as $host) {
    if (!in_array(strtolower((string)$host), ['127.0.0.1', 'localhost', '::1'], true)) {
        $remote[] = (string)$host;
    }
}

$conn = clarenzon_db_connect($cfg['user'], $cfg['pass'], $database, 5);
if (!$conn) {
    fwrite(STDERR, "Could not connect to local database {$database}.\n");
    exit(1);
}

$baseTables = clear_tx_base_tables($conn, $database);
$known = array_merge(CLEAR_TX_TABLES, CLEAR_TX_KEEP, array_keys(CLEAR_TX_UNCERTAIN));
$unknown = array_values(array_diff($baseTables, $known));
sort($unknown);

echo "Database: {$database}\n";
echo "Mode: " . ($confirm === CLEAR_TX_CONFIRM ? "clear after backup" : "preview only") . "\n\n";

echo "TRANSACTION RECORDS THAT WOULD BE CLEARED\n";
$clearCounts = clear_tx_print_counts($conn, CLEAR_TX_TABLES);

echo "\nMASTER DATA THAT WILL BE KEPT\n";
$keepCounts = clear_tx_print_counts($conn, CLEAR_TX_KEEP);

echo "\nNOT CLEARED — NEEDS CONFIRMATION\n";
foreach (CLEAR_TX_UNCERTAIN as $table => $reason) {
    $count = clear_tx_count($conn, $table);
    echo str_pad((string)$count, 8, ' ', STR_PAD_LEFT) . "  {$table}\n    {$reason}\n";
}
if ($unknown) {
    echo "\nUNKNOWN TABLES — NOT CLEARED\n";
    foreach ($unknown as $table) {
        $count = clear_tx_count($conn, $table);
        echo str_pad((string)$count, 8, ' ', STR_PAD_LEFT) . "  {$table}\n    This table is not classified. It will not be deleted.\n";
    }
}

if ($confirm === '') {
    echo "\nNo records were deleted. To clear the listed transaction tables on this local database, run:\n";
    echo "php clear_transaction_data.php --confirm=\"" . CLEAR_TX_CONFIRM . "\"\n";
    exit(0);
}

if ($confirm !== CLEAR_TX_CONFIRM) {
    fwrite(STDERR, "\nConfirmation did not match. No records were deleted.\n");
    exit(1);
}
if ($remote) {
    fwrite(STDERR, "\nRefusing to delete. Database host is not local: " . implode(', ', $remote) . "\n");
    exit(1);
}
if ($unknown) {
    fwrite(STDERR, "\nRefusing to delete until the unknown tables above are classified.\n");
    exit(1);
}

$backup = clear_tx_backup($cfg, $database);
if ($backup === '') {
    fwrite(STDERR, "\nBackup failed. No records were deleted.\n");
    exit(1);
}
echo "\nBackup written: {$backup}\n";

$conn->begin_transaction();
try {
    foreach (CLEAR_TX_TABLES as $table) {
        if (!clear_tx_table_exists($conn, $table)) {
            continue;
        }
        if (!$conn->query("DELETE FROM `{$table}`")) {
            throw new RuntimeException($table . ': ' . $conn->error);
        }
    }
    $afterClear = [];
    foreach (CLEAR_TX_TABLES as $table) {
        $afterClear[$table] = clear_tx_count($conn, $table);
        if ($afterClear[$table] !== 0) {
            throw new RuntimeException($table . ' still has ' . $afterClear[$table] . ' rows.');
        }
    }
    foreach (CLEAR_TX_KEEP as $table) {
        $now = clear_tx_count($conn, $table);
        if ($now !== ($keepCounts[$table] ?? null)) {
            throw new RuntimeException('Master table changed: ' . $table . ' was ' . ($keepCounts[$table] ?? '?') . ' and is now ' . $now . '.');
        }
    }
    foreach (array_keys(CLEAR_TX_UNCERTAIN) as $table) {
        if (!clear_tx_table_exists($conn, $table)) {
            continue;
        }
    }
    foreach (['vw_current_stock', 'vw_expiring_soon', 'vw_reorder_needed'] as $view) {
        if (!$conn->query("SELECT 1 FROM `{$view}` LIMIT 1") && $conn->errno) {
            throw new RuntimeException('View failed: ' . $view . ' ' . $conn->error);
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "\nClear aborted and rolled back: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Backup kept at: {$backup}\n");
    exit(1);
}

echo "\nINTEGRITY CHECK\n";
echo "Cleared tables are empty.\n";
echo "Master table counts are unchanged.\n";
echo "Stock views still query.\n";
echo "Uncertain tables were not modified.\n";
echo "Transaction data clear completed for {$database}.\n";
exit(0);

function clear_tx_base_tables(mysqli $conn, string $database): array
{
    $stmt = $conn->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'");
    $stmt->bind_param('s', $database);
    $stmt->execute();
    $res = $stmt->get_result();
    $names = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $names[] = (string)$row['TABLE_NAME'];
    }
    $stmt->close();
    return $names;
}

function clear_tx_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function clear_tx_count(mysqli $conn, string $table): int
{
    if (!clear_tx_table_exists($conn, $table)) {
        return 0;
    }
    $res = $conn->query("SELECT COUNT(*) AS c FROM `{$table}`");
    if (!$res) {
        return -1;
    }
    return (int)$res->fetch_assoc()['c'];
}

function clear_tx_print_counts(mysqli $conn, array $tables): array
{
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = clear_tx_count($conn, $table);
        echo str_pad((string)$counts[$table], 8, ' ', STR_PAD_LEFT) . "  {$table}\n";
    }
    return $counts;
}

function clear_tx_backup(array $cfg, string $database): string
{
    $dump = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe';
    if (!is_file($dump)) {
        fwrite(STDERR, "mysqldump was not found.\n");
        return '';
    }
    $dir = 'C:\\laragon\\backup\\clarenzon-db';
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        fwrite(STDERR, "Could not create backup directory.\n");
        return '';
    }
    $file = $dir . DIRECTORY_SEPARATOR . $database . '-' . date('Ymd-His') . '.sql';
    $host = '127.0.0.1';
    foreach ($cfg['hosts'] as $candidate) {
        if (in_array(strtolower((string)$candidate), ['127.0.0.1', 'localhost', '::1'], true)) {
            $host = (string)$candidate === 'localhost' ? '127.0.0.1' : (string)$candidate;
            break;
        }
    }
    $cmd = [
        $dump,
        '--host=' . $host,
        '--user=' . (string)$cfg['user'],
        '--password=' . (string)$cfg['pass'],
        '--single-transaction',
        '--routines',
        '--triggers',
        '--result-file=' . $file,
        $database,
    ];
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return '';
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_file($file) || filesize($file) < 100) {
        fwrite(STDERR, trim($err) . "\n");
        return '';
    }
    return $file;
}
