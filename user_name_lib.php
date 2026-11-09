<?php
/**
 * Canonical person name: first_name + last_name, with full_name kept in sync.
 */
declare(strict_types=1);

function userNameSplit(?string $full): array
{
    $full = trim(preg_replace('/\s+/', ' ', (string)$full));
    if ($full === '') {
        return ['first_name' => '', 'last_name' => ''];
    }
    $parts = explode(' ', $full, 2);
    $first = userNameTitleCase($parts[0] ?? '');
    $last = userNameTitleCase($parts[1] ?? '');
    return ['first_name' => $first, 'last_name' => $last];
}

function userNameTitleCase(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value));
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_convert_case')) {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($value));
}

function userNameJoin(?string $first, ?string $last): string
{
    return trim(userNameTitleCase((string)$first) . ' ' . userNameTitleCase((string)$last));
}

/**
 * @return array{first_name:string,last_name:string,full_name:string}
 */
function userNameNormalize(?string $first, ?string $last, ?string $full = null): array
{
    $first = userNameTitleCase((string)$first);
    $last = userNameTitleCase((string)$last);
    if ($first === '' && $last === '') {
        $split = userNameSplit((string)$full);
        $first = $split['first_name'];
        $last = $split['last_name'];
    }
    $joined = userNameJoin($first, $last);
    if ($joined === '') {
        $joined = trim((string)$full);
    }
    return [
        'first_name' => $first,
        'last_name' => $last,
        'full_name' => $joined,
    ];
}

function userDisplayName(array $row, string $fallback = ''): string
{
    $n = userNameNormalize(
        (string)($row['first_name'] ?? ''),
        (string)($row['last_name'] ?? ''),
        (string)($row['full_name'] ?? $fallback)
    );
    return $n['full_name'] !== '' ? $n['full_name'] : $fallback;
}

function userFirstName(array $row, string $fallback = ''): string
{
    $n = userNameNormalize(
        (string)($row['first_name'] ?? ''),
        (string)($row['last_name'] ?? ''),
        (string)($row['full_name'] ?? $fallback)
    );
    return $n['first_name'] !== '' ? $n['first_name'] : ($n['full_name'] !== '' ? $n['full_name'] : $fallback);
}

function userNameEnsureSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $first = $conn->query("SHOW COLUMNS FROM users LIKE 'first_name'");
    if (!($first && $first->num_rows > 0)) {
        @$conn->query("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER full_name");
    }
    $last = $conn->query("SHOW COLUMNS FROM users LIKE 'last_name'");
    if (!($last && $last->num_rows > 0)) {
        @$conn->query("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name");
    }
    userNameBackfill($conn);
}

function userNameBackfill(mysqli $conn): void
{
    $res = @$conn->query(
        "SELECT id, full_name, first_name, last_name FROM users
         WHERE TRIM(COALESCE(first_name,'')) = ''
            OR TRIM(COALESCE(last_name,'')) = ''
            OR TRIM(COALESCE(full_name,'')) = ''
         LIMIT 500"
    );
    if (!$res) {
        return;
    }
    $upd = $conn->prepare('UPDATE users SET first_name=?, last_name=?, full_name=? WHERE id=?');
    if (!$upd) {
        return;
    }
    while ($row = $res->fetch_assoc()) {
        $n = userNameNormalize(
            (string)($row['first_name'] ?? ''),
            (string)($row['last_name'] ?? ''),
            (string)($row['full_name'] ?? '')
        );
        if (
            $n['first_name'] === (string)($row['first_name'] ?? '')
            && $n['last_name'] === (string)($row['last_name'] ?? '')
            && $n['full_name'] === (string)($row['full_name'] ?? '')
        ) {
            continue;
        }
        $id = (int)$row['id'];
        $upd->bind_param('sssi', $n['first_name'], $n['last_name'], $n['full_name'], $id);
        $upd->execute();
    }
    $upd->close();
}

function userNameSqlExpr(string $alias = 'u'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'u';
    return "TRIM(CONCAT(COALESCE({$a}.first_name,''), ' ', COALESCE({$a}.last_name,'')))";
}
