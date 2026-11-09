<?php
/**
 * Send mail through Gmail SMTP (ssl://smtp.gmail.com:465, STARTTLS 587 fallback).
 */

if (!function_exists('clarenzon_mail_config')) {
    function clarenzon_mail_config(bool $reload = false): array
    {
        static $cfg = null;
        if ($reload) {
            $cfg = null;
        }
        if ($cfg !== null) {
            return $cfg;
        }
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mail.php';
        if (!is_file($file)) {
            $file = __DIR__ . DIRECTORY_SEPARATOR . 'mail_config.php';
        }
        $loaded = is_file($file) ? include $file : [];
        $cfg = is_array($loaded) ? $loaded : [];
        return $cfg;
    }
}

if (!function_exists('clarenzon_mail_is_configured')) {
    function clarenzon_mail_is_configured(): bool
    {
        $cfg = clarenzon_mail_config();
        if (trim((string)($cfg['resend_api_key'] ?? '')) !== '') {
            return true;
        }
        $user = trim((string)($cfg['smtp_user'] ?? ''));
        $pass = preg_replace('/\s+/', '', (string)($cfg['smtp_pass'] ?? '')) ?? '';
        return $user !== '' && strlen($pass) >= 16 && !str_contains($pass, 'your-16-character');
    }
}

if (!function_exists('clarenzon_auth_debug_log')) {
    function clarenzon_auth_debug_log(string $message): void
    {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'auth.log';
        $line = date('Y-m-d H:i:s') . ' ' . preg_replace('/\d{6}/', '[otp]', $message) . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('clarenzon_otp_inbox_email')) {
    function clarenzon_otp_inbox_email(): string
    {
        $cfg = clarenzon_mail_config();
        $inbox = trim((string)($cfg['from_email'] ?? $cfg['smtp_user'] ?? ''));
        return filter_var($inbox, FILTER_VALIDATE_EMAIL) ? $inbox : '';
    }
}

if (!function_exists('clarenzon_otp_destination_email')) {
    function clarenzon_otp_destination_email(array $user): string
    {
        $email = trim((string)($user['email'] ?? ''));
        $inbox = clarenzon_otp_inbox_email();
        $isPlaceholder = $email === '' || (bool)preg_match('/@clarenzon\.com$/i', $email);
        if ($inbox !== '' && $isPlaceholder) {
            return $inbox;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $inbox;
    }
}

if (!function_exists('clarenzon_mask_email')) {
    function clarenzon_mask_email(string $email): string
    {
        $email = trim($email);
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return 'your email';
        }
        $name = $parts[0];
        $keep = 1;
        return substr($name, 0, $keep) . str_repeat('*', max(1, strlen($name) - $keep)) . '@' . $parts[1];
    }
}

if (!function_exists('clarenzon_save_mail_settings')) {
    /**
     * @return array{ok:bool,error?:string,email?:string}
     */
    function clarenzon_save_mail_settings(string $gmail, string $appPassword): array
    {
        $gmail = strtolower(trim($gmail));
        $pass = preg_replace('/\s+/', '', $appPassword) ?? '';
        if (!preg_match('/^[a-z0-9._%+\-]+@gmail\.com$/', $gmail)) {
            return ['ok' => false, 'error' => 'Enter a working Gmail address (example@gmail.com).'];
        }
        if (strlen($pass) < 8) {
            return ['ok' => false, 'error' => 'Enter the 16-character Gmail App Password from your Google Account.'];
        }
        $data = [
            'smtp_user' => $gmail,
            'smtp_pass' => $pass,
            'otp_inbox' => $gmail,
            'from_email' => $gmail,
            'from_name' => 'Clarenzon',
        ];
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'mail_config.local.php';
        $php = "<?php\nreturn " . var_export($data, true) . ";\n";
        if (@file_put_contents($path, $php) === false) {
            return ['ok' => false, 'error' => 'Could not save Gmail settings on the server.'];
        }
        clarenzon_mail_config(true);
        return ['ok' => true, 'email' => $gmail];
    }
}

if (!function_exists('clarenzon_ssl_cafile')) {
    function clarenzon_ssl_cafile(): string
    {
        $ini = trim((string)ini_get('openssl.cafile'));
        if ($ini !== '' && is_file($ini)) {
            return $ini;
        }
        $candidates = [
            'C:\\laragon\\etc\\ssl\\cacert.pem',
            'C:\\laragon\\bin\\apache\\cacert.pem',
            __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem',
        ];
        $phpDir = str_replace('\\', '/', (string)PHP_BINDIR);
        if ($phpDir !== '') {
            $candidates[] = $phpDir . '/extras/ssl/cacert.pem';
            $candidates[] = dirname($phpDir) . '/extras/ssl/cacert.pem';
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    }
}

if (!function_exists('clarenzon_smtp_read')) {
    function clarenzon_smtp_read($fp): string
    {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('clarenzon_smtp_cmd')) {
    function clarenzon_smtp_cmd($fp, string $command, int $expect): bool
    {
        fwrite($fp, $command . "\r\n");
        $reply = clarenzon_smtp_read($fp);
        $code = (int)substr($reply, 0, 3);
        return $code === $expect;
    }
}

if (!function_exists('clarenzon_smtp_connect')) {
    /**
     * @return resource|false
     */
    function clarenzon_smtp_connect(string $remote, bool $verifySsl)
    {
        $ssl = [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ];
        $ca = clarenzon_ssl_cafile();
        if ($verifySsl && $ca !== '') {
            $ssl['cafile'] = $ca;
        }
        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl])
        );
        unset($errno, $errstr);
        return $fp ?: false;
    }
}

if (!function_exists('clarenzon_send_email_via_mail')) {
    /**
     * Hosting fallback when Gmail SMTP is not available (Laragon usually cannot deliver this).
     * @return array{ok:bool,error?:string}
     */
    function clarenzon_send_email_via_mail(string $to, string $subject, string $textBody, string $htmlBody = ''): array
    {
        $generic = 'We could not send your verification code. Please try again.';
        $fromEmail = clarenzon_otp_inbox_email();
        $cfg = clarenzon_mail_config();
        $fromName = trim((string)($cfg['from_name'] ?? 'Clarenzon'));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            if (function_exists('clarenzon_auth_debug_log')) {
                clarenzon_auth_debug_log('mail: PHP mail skipped (no from address)');
            }
            return ['ok' => false, 'error' => $generic];
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $body = $htmlBody !== '' ? $htmlBody : nl2br(htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8'));
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . sprintf('%s <%s>', $fromName, $fromEmail),
            'Reply-To: ' . $fromEmail,
        ]);
        $ok = @mail($to, $encodedSubject, $body, $headers);
        if (function_exists('clarenzon_auth_debug_log')) {
            clarenzon_auth_debug_log($ok ? 'mail: PHP mail accepted' : 'mail: PHP mail rejected');
        }
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => $generic];
    }
}

if (!function_exists('clarenzon_send_email_via_resend')) {
    /**
     * Send through the Resend HTTPS API (https://resend.com).
     * @return array{ok:bool,error?:string}
     */
    function clarenzon_send_email_via_resend(string $to, string $subject, string $textBody, string $htmlBody = ''): array
    {
        $generic = 'We could not send your verification code. Please try again.';
        $cfg = clarenzon_mail_config();
        $apiKey = trim((string)($cfg['resend_api_key'] ?? ''));
        $fromEmail = trim((string)($cfg['resend_from'] ?? 'onboarding@resend.dev'));
        $fromName = trim((string)($cfg['from_name'] ?? 'Clarenzon'));
        if (!function_exists('curl_init')) {
            clarenzon_auth_debug_log('mail: Resend skipped (curl missing)');
            return ['ok' => false, 'error' => $generic];
        }
        $payload = [
            'from' => sprintf('%s <%s>', $fromName, $fromEmail),
            'to' => [$to],
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody !== '' ? $htmlBody : nl2br(htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8')),
        ];
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $ca = clarenzon_ssl_cafile();
        if ($ca !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($status >= 200 && $status < 300) {
            clarenzon_auth_debug_log('mail: Resend accepted');
            return ['ok' => true];
        }
        clarenzon_auth_debug_log('mail: Resend failed (HTTP ' . $status . ') ' . ($curlError !== '' ? $curlError : substr((string)$response, 0, 300)));
        return ['ok' => false, 'error' => $generic];
    }
}

if (!function_exists('clarenzon_send_email')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function clarenzon_send_email(string $to, string $subject, string $textBody, string $htmlBody = ''): array
    {
        $to = trim($to);
        $generic = 'We could not send your verification code. Please try again.';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            if (function_exists('clarenzon_auth_debug_log')) {
                clarenzon_auth_debug_log('mail: invalid recipient');
            }
            return ['ok' => false, 'error' => $generic];
        }
        if (trim((string)(clarenzon_mail_config()['resend_api_key'] ?? '')) !== '') {
            return clarenzon_send_email_via_resend($to, $subject, $textBody, $htmlBody);
        }
        if (!clarenzon_mail_is_configured()) {
            if (function_exists('clarenzon_auth_debug_log')) {
                clarenzon_auth_debug_log('mail: SMTP is not configured, trying PHP mail()');
            }
            return clarenzon_send_email_via_mail($to, $subject, $textBody, $htmlBody);
        }
        if (!function_exists('openssl_encrypt')) {
            if (function_exists('clarenzon_auth_debug_log')) {
                clarenzon_auth_debug_log('mail: openssl missing, trying PHP mail()');
            }
            return clarenzon_send_email_via_mail($to, $subject, $textBody, $htmlBody);
        }

        $cfg = clarenzon_mail_config();
        $host = (string)($cfg['smtp_host'] ?? 'smtp.gmail.com');
        $user = trim((string)($cfg['smtp_user'] ?? ''));
        $pass = preg_replace('/\s+/', '', (string)($cfg['smtp_pass'] ?? '')) ?? '';
        $fromEmail = trim((string)($cfg['from_email'] ?? $user));
        $fromName = trim((string)($cfg['from_name'] ?? 'Clarenzon'));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $user;
        }

        $attempts = [
            ['remote' => 'ssl://' . $host . ':465', 'starttls' => false],
            ['remote' => 'tcp://' . $host . ':587', 'starttls' => true],
        ];

        $fp = false;
        foreach ([true, false] as $verifySsl) {
            foreach ($attempts as $attempt) {
                $try = clarenzon_smtp_connect($attempt['remote'], $verifySsl);
                if (!$try) {
                    continue;
                }
                stream_set_timeout($try, 8);
                $banner = clarenzon_smtp_read($try);
                if ((int)substr($banner, 0, 3) !== 220) {
                    fclose($try);
                    continue;
                }
                if (!clarenzon_smtp_cmd($try, 'EHLO clarenzon.local', 250)) {
                    fclose($try);
                    continue;
                }
                if (!empty($attempt['starttls'])) {
                    if (!clarenzon_smtp_cmd($try, 'STARTTLS', 220)) {
                        fclose($try);
                        continue;
                    }
                    $crypto = @stream_socket_enable_crypto($try, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
                    if (!$crypto) {
                        fclose($try);
                        continue;
                    }
                    if (!clarenzon_smtp_cmd($try, 'EHLO clarenzon.local', 250)) {
                        fclose($try);
                        continue;
                    }
                }
                $authed = clarenzon_smtp_cmd($try, 'AUTH LOGIN', 334)
                    && clarenzon_smtp_cmd($try, base64_encode($user), 334)
                    && clarenzon_smtp_cmd($try, base64_encode($pass), 235);
                if (!$authed) {
                    fclose($try);
                    continue;
                }
                $fp = $try;
                break 2;
            }
        }

        if (!$fp) {
            if (function_exists('clarenzon_auth_debug_log')) {
                clarenzon_auth_debug_log('mail: SMTP connect/auth failed, trying PHP mail()');
            }
            return clarenzon_send_email_via_mail($to, $subject, $textBody, $htmlBody);
        }

        $ok = clarenzon_smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', 250)
            && clarenzon_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', 250)
            && clarenzon_smtp_cmd($fp, 'DATA', 354);
        if (!$ok) {
            fclose($fp);
            if (function_exists('clarenzon_auth_debug_log')) {
                clarenzon_auth_debug_log('mail: SMTP envelope rejected, trying PHP mail()');
            }
            return clarenzon_send_email_via_mail($to, $subject, $textBody, $htmlBody);
        }

        $boundary = 'clar' . bin2hex(random_bytes(8));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader = sprintf('%s <%s>', $fromName, $fromEmail);
        $headers = [
            'From: ' . $fromHeader,
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $html = $htmlBody !== '' ? $htmlBody : nl2br(htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8'));
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $textBody . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $html . "\r\n"
            . '--' . $boundary . "--\r\n.";

        fwrite($fp, $body . "\r\n");
        $dataReply = clarenzon_smtp_read($fp);
        $quitOk = clarenzon_smtp_cmd($fp, 'QUIT', 221);
        fclose($fp);
        if ((int)substr($dataReply, 0, 3) !== 250) {
            if (function_exists('clarenzon_auth_debug_log')) {
                clarenzon_auth_debug_log('mail: SMTP DATA rejected, trying PHP mail()');
            }
            return clarenzon_send_email_via_mail($to, $subject, $textBody, $htmlBody);
        }
        unset($quitOk);
        return ['ok' => true];
    }
}

if (!function_exists('clarenzon_send_otp_email')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function clarenzon_send_otp_email(string $to, string $otp, string $purpose = 'sign-in'): array
    {
        $subject = $purpose === 'password'
            ? 'CLARENZON password reset code'
            : 'CLARENZON login verification code';
        $heading = $purpose === 'password' ? 'Password Reset Code' : 'Login Verification Code';
        $text = "CLARENZON MEDICAL SUPPLIES\n\n"
            . $heading . "\n\n"
            . "Your Clarenzon verification code is:\n\n"
            . $otp . "\n\n"
            . "This code will expire in 5 minutes.\n\n"
            . "If you did not attempt to log in, please ignore this email.\n\n"
            . "Do not share this code with anyone.\n";
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#0b1f42;">'
            . '<h2 style="color:#1E88E5;margin:0 0 12px;">CLARENZON MEDICAL SUPPLIES</h2>'
            . '<p style="margin:0 0 16px;font-weight:600;">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 16px;">Your Clarenzon verification code is:</p>'
            . '<p style="font-size:28px;letter-spacing:8px;font-weight:700;margin:0 0 16px;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="color:#64748b;font-size:13px;margin:0 0 8px;">This code will expire in 5 minutes.</p>'
            . '<p style="color:#64748b;font-size:13px;margin:0;">If you did not attempt to log in, please ignore this email. Do not share this code with anyone.</p>'
            . '</div>';
        return clarenzon_send_email($to, $subject, $text, $html);
    }
}
