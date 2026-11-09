<?php
/**
 * Fast mysqli helpers — prefer 127.0.0.1 locally.
 * On InfinityFree, credentials come from db_config.local.php.
 */

date_default_timezone_set('Asia/Manila');

if (!function_exists('clarenzon_db_settings')) {
    /**
     * @return array{hosts:list<string>,user:string,pass:string,name:string,create_database:bool}
     */
    function clarenzon_db_settings(): array
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $cfg = [
            'hosts' => ['127.0.0.1', 'localhost'],
            'user' => 'root',
            'pass' => '',
            'name' => 'clarenzon_db',
            'create_database' => true,
        ];
        $local = __DIR__ . DIRECTORY_SEPARATOR . 'db_config.local.php';
        if (is_file($local) && is_readable($local)) {
            $extra = include $local;
            if (is_array($extra)) {
                if (!empty($extra['host'])) {
                    $cfg['hosts'] = [trim((string)$extra['host'])];
                }
                if (!empty($extra['hosts']) && is_array($extra['hosts'])) {
                    $cfg['hosts'] = array_values(array_filter(array_map('strval', $extra['hosts'])));
                }
                foreach (['user', 'pass', 'name'] as $key) {
                    if (isset($extra[$key]) && (string)$extra[$key] !== '') {
                        $cfg[$key] = (string)$extra[$key];
                    }
                }
                $cfg['create_database'] = array_key_exists('create_database', $extra)
                    ? (bool)$extra['create_database']
                    : false;
            }
        }
        // Railway MySQL plugin variables take precedence when present.
        $envHost = (string)getenv('MYSQLHOST');
        if ($envHost !== '') {
            $cfg['hosts'] = [$envHost];
            $cfg['port'] = (int)(getenv('MYSQLPORT') ?: 3306);
            $cfg['user'] = (string)(getenv('MYSQLUSER') ?: 'root');
            $cfg['pass'] = (string)getenv('MYSQLPASSWORD');
            $cfg['name'] = (string)(getenv('MYSQLDATABASE') ?: 'railway');
            $cfg['create_database'] = false;
        }
        if ($cfg['hosts'] === []) {
            $cfg['hosts'] = ['127.0.0.1'];
        }
        return $cfg;
    }
}

if (!function_exists('clarenzon_db_resolve_name')) {
    function clarenzon_db_resolve_name(?string $database): string
    {
        if ($database === null || $database === '') {
            return '';
        }
        $cfg = clarenzon_db_settings();
        if (!empty($cfg['create_database'])) {
            return $database;
        }
        $aliases = ['clarenzon_db', 'inventory_db', 'clarenzon_inventory'];
        if (in_array($database, $aliases, true)) {
            return $cfg['name'];
        }
        return $database;
    }
}

if (!function_exists('clarenzon_db_connect')) {
    function clarenzon_db_connect(
        string $user = 'root',
        string $pass = '',
        ?string $database = 'clarenzon_db',
        int $timeoutSec = 3,
        array $hosts = ['127.0.0.1', 'localhost']
    ): ?mysqli {
        unset($user, $pass, $hosts);
        $cfg = clarenzon_db_settings();
        $user = $cfg['user'];
        $pass = $cfg['pass'];
        $hosts = $cfg['hosts'];
        $database = clarenzon_db_resolve_name($database);
        mysqli_report(MYSQLI_REPORT_OFF);
        $timeoutSec = max(1, $timeoutSec);
        @ini_set('default_socket_timeout', (string)$timeoutSec);

        foreach ($hosts as $host) {
            $mysqli = mysqli_init();
            if (!$mysqli) {
                continue;
            }
            $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeoutSec);
            $ok = @$mysqli->real_connect($host, $user, $pass, $database, (int)($cfg['port'] ?? 3306));
            if ($ok && empty($mysqli->connect_error)) {
                // Keep MySQL NOW()/TIMESTAMP values in the same zone as PHP (Asia/Manila);
                // hosted MySQL (e.g. Railway) runs in UTC.
                @$mysqli->query("SET time_zone = '" . date('P') . "'");
                return $mysqli;
            }
            @$mysqli->close();
        }
        return null;
    }
}

if (!function_exists('clarenzon_db_connect_first')) {
    /**
     * @param list<string> $databases
     */
    function clarenzon_db_connect_first(
        array $databases,
        string $user = 'root',
        string $pass = '',
        int $timeoutSec = 3,
        array $hosts = ['127.0.0.1', 'localhost']
    ): ?mysqli {
        unset($user, $pass, $hosts);
        $cfg = clarenzon_db_settings();
        $names = [];
        foreach (array_merge([$cfg['name']], $databases) as $database) {
            $resolved = clarenzon_db_resolve_name((string)$database);
            if ($resolved !== '' && !in_array($resolved, $names, true)) {
                $names[] = $resolved;
            }
        }
        foreach ($names as $database) {
            $conn = clarenzon_db_connect($cfg['user'], $cfg['pass'], $database, $timeoutSec, $cfg['hosts']);
            if ($conn) {
                return $conn;
            }
        }
        return null;
    }
}
