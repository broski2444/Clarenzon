<?php
/**
 * SMTP settings for login OTP mail.
 * Secrets come from environment variables or mail_config.local.php — never from this file.
 */

$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = trim($value, "\"'");
        }
        if ($key === '') {
            continue;
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

$env = static function (string $name, string $default = '') {
    $value = getenv($name);
    if ($value === false || $value === '') {
        $value = (string)($_ENV[$name] ?? '');
    }
    return $value !== '' ? $value : $default;
};

$defaults = [
    'smtp_host' => $env('SMTP_HOST', 'smtp.gmail.com'),
    'smtp_port' => (int)$env('SMTP_PORT', '465'),
    'smtp_secure' => $env('SMTP_ENCRYPTION', 'ssl'),
    'smtp_user' => $env('SMTP_USERNAME', $env('SMTP_USER')),
    'smtp_pass' => $env('SMTP_PASSWORD', $env('SMTP_PASS')),
    'from_email' => $env('MAIL_FROM'),
    'from_name' => $env('MAIL_FROM_NAME', 'Clarenzon'),
    // Resend HTTPS API — used instead of SMTP when set (Railway blocks SMTP below the Pro plan).
    'resend_api_key' => $env('RESEND_API_KEY'),
    'resend_from' => $env('RESEND_FROM', 'onboarding@resend.dev'),
];

$local = $root . DIRECTORY_SEPARATOR . 'mail_config.local.php';
if (is_file($local)) {
    $extra = include $local;
    if (is_array($extra)) {
        $defaults = array_merge($defaults, $extra);
    }
}

if ($defaults['from_email'] === '' && $defaults['smtp_user'] !== '') {
    $defaults['from_email'] = $defaults['smtp_user'];
}

return $defaults;
