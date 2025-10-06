<?php
// Lightweight mail helper for PromoPilot

if (!function_exists('pp_mail_log')) {
    function pp_mail_log(string $event, array $context = []): void {
        $entry = [
            'time' => gmdate('c'),
            'event' => $event,
            'context' => $context,
        ];
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode([
                'time' => gmdate('c'),
                'event' => 'mail.log_encode_failed',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $logPath = PP_ROOT_PATH . '/logs/mail.log';
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($logPath, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('pp_mail_disabled_reason')) {
    function pp_mail_disabled_reason(): ?string {
        return $GLOBALS['pp_mail_last_disabled_reason'] ?? null;
    }
}

if (!function_exists('pp_mail_is_enabled')) {
    function pp_mail_is_enabled(): bool {
        $reason = null;

        $mailEnabled = strtolower(trim((string)get_setting('mail_enabled', '1')));
        if ($mailEnabled !== '' && in_array($mailEnabled, ['0', 'false', 'off', 'no', 'disabled', 'disable'], true)) {
            $reason = 'mail_enabled_off';
        }

        if ($reason === null) {
            $notifications = strtolower(trim((string)get_setting('notifications_email_enabled', '1')));
            if ($notifications !== '' && in_array($notifications, ['0', 'false', 'off', 'no', 'disabled', 'disable'], true)) {
                $reason = 'notifications_disabled';
            }
        }

        if ($reason === null) {
            $disableAll = strtolower(trim((string)get_setting('mail_disable_all', '0')));
            if (in_array($disableAll, ['1', 'true', 'yes', 'on', 'all', 'disable', 'disabled'], true)) {
                $reason = 'mail_disable_all';
            }
        }

        $GLOBALS['pp_mail_last_disabled_reason'] = $reason;
        return $reason === null;
    }
}

if (!function_exists('pp_mail_default_domain')) {
    function pp_mail_default_domain(): string {
        $baseUrl = defined('PP_BASE_URL') ? PP_BASE_URL : '';
        $host = 'promopilot.local';
        if ($baseUrl !== '') {
            $parsed = @parse_url($baseUrl);
            if (!empty($parsed['host'])) {
                $host = strtolower($parsed['host']);
            }
        }
        return $host;
    }
}

if (!function_exists('pp_mail_from_identity')) {
    function pp_mail_from_identity(array $override = []): array {
        $defaultName = trim((string)get_setting('mail_from_name', 'PromoPilot'));
        if ($defaultName === '') {
            $defaultName = 'PromoPilot';
        }
        $defaultEmail = trim((string)get_setting('mail_from_email', 'noreply@' . pp_mail_default_domain()));
        if (!filter_var($defaultEmail, FILTER_VALIDATE_EMAIL)) {
            $defaultEmail = 'noreply@' . pp_mail_default_domain();
        }
        $name = trim((string)($override['name'] ?? $defaultName));
        $email = trim((string)($override['email'] ?? $defaultEmail));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $defaultEmail;
        }
        return [$email, $name];
    }
}

if (!function_exists('pp_mail_format_address')) {
    function pp_mail_format_address(string $email, ?string $name = null): string {
        $email = trim($email);
        if ($email === '') {
            return '';
        }
        if ($name === null || $name === '') {
            return $email;
        }
        if (!function_exists('mb_encode_mimeheader')) {
            return sprintf('"%s" <%s>', addslashes($name), $email);
        }
        $encoded = mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
        return sprintf('%s <%s>', $encoded, $email);
    }
}

if (!function_exists('pp_mail_html_to_text')) {
    function pp_mail_html_to_text(string $html): string {
        $html = preg_replace('~<\s*br\s*/?>~i', "\n", $html);
        $html = preg_replace('~<\s*/p\s*>~i', "\n\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("~\n{3,}~", "\n\n", $text);
        return trim($text);
    }
}

if (!function_exists('pp_mail_send')) {
    function pp_mail_send(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null, array $options = []): bool {
        if (!pp_mail_is_enabled()) {
            pp_mail_log('mail.skipped.disabled', [
                'to' => $toEmail,
                'subject' => $subject,
                'reason' => pp_mail_disabled_reason(),
            ]);
            return false;
        }
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            pp_mail_log('mail.skipped.invalid_recipient', [
                'to' => $toEmail,
                'subject' => $subject,
            ]);
            return false;
        }

        $transport = strtolower((string)get_setting('mail_transport', 'native'));
        $smtpConfigOverride = null;
        if ($transport !== 'smtp' && !function_exists('mail')) {
            if (function_exists('error_log')) {
                @error_log('PromoPilot mail(): native mail() function is unavailable; attempting SMTP fallback for ' . $toEmail);
            }
            $possibleConfig = pp_mail_smtp_config();
            if (!empty($possibleConfig['host'])) {
                $smtpConfigOverride = $possibleConfig;
                $transport = 'smtp';
                pp_mail_log('mail.transport_fallback.smtp', [
                    'to' => $toEmail,
                    'subject' => $subject,
                ]);
            } else {
                pp_mail_log('mail.skipped.transport_missing', [
                    'to' => $toEmail,
                    'subject' => $subject,
                    'reason' => 'smtp_config_missing',
                ]);
                return false;
            }
        }

        if (!function_exists('mb_encode_mimeheader')) {
            if (function_exists('mb_internal_encoding')) {
                @mb_internal_encoding('UTF-8');
            }
        }
        $subject = trim($subject);
        if ($subject === '') {
            $subject = 'PromoPilot Notification';
        }
        $htmlBody = (string)$htmlBody;
        if ($htmlBody === '') {
            return false;
        }
        if ($textBody === null) {
            $textBody = pp_mail_html_to_text($htmlBody);
        }
        [$fromEmail, $fromName] = pp_mail_from_identity($options['from'] ?? []);

        try {
            $boundary = '=_PP_' . bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $fallbackSeed = microtime(true) . ':' . mt_rand();
            $fallbackHash = hash('sha256', $fallbackSeed, true);
            $boundary = '=_PP_' . bin2hex(substr($fallbackHash, 0, 12));
        }
        $subjectEncoded = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n") : $subject;

        try {
            $messageHash = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $fallbackSeed = microtime(true) . ':' . mt_rand();
            $messageHash = bin2hex(substr(hash('sha256', $fallbackSeed, true), 0, 16));
        }
        $messageId = sprintf('<%s@%s>', $messageHash, pp_mail_default_domain());

        $headers = [];
        $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s O');
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: PromoPilot';
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'From: ' . pp_mail_format_address($fromEmail, $fromName);
        $headers[] = 'To: ' . pp_mail_format_address($toEmail);
        $headers[] = 'Subject: ' . $subjectEncoded;

        $replyTo = trim((string)($options['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . pp_mail_format_address($replyTo, $options['reply_to_name'] ?? null);
        }

        $ccFiltered = [];
        $cc = $options['cc'] ?? [];
        if (is_string($cc)) { $cc = [$cc]; }
        if (is_array($cc)) {
            $ccFiltered = array_values(array_filter(array_map('trim', $cc), static fn($item) => $item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL)));
            if (!empty($ccFiltered)) {
                $headers[] = 'Cc: ' . implode(', ', array_map(static fn($item) => pp_mail_format_address($item), $ccFiltered));
            }
        }

        $bccFiltered = [];
        $bcc = $options['bcc'] ?? [];
        if (is_string($bcc)) { $bcc = [$bcc]; }
        if (is_array($bcc)) {
            $bccFiltered = array_values(array_filter(array_map('trim', $bcc), static fn($item) => $item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL)));
            if ($transport !== 'smtp' && !empty($bccFiltered)) {
                $headers[] = 'Bcc: ' . implode(', ', array_map(static fn($item) => pp_mail_format_address($item), $bccFiltered));
            }
        }

        if ($textBody !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '';
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $textBody . "\r\n\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n\r\n";
            $body .= '--' . $boundary . "--\r\n";
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $body = $htmlBody;
        }

        $headersForMessage = $headers;
        if ($transport === 'smtp') {
            $headersForMessage = array_values(array_filter($headersForMessage, static fn($line) => stripos($line, 'Bcc:') !== 0));
        }

        $payload = [
            'to' => $toEmail,
            'cc' => $ccFiltered,
            'bcc' => $bccFiltered,
            'subject' => $subject,
            'subject_encoded' => $subjectEncoded,
            'headers' => $headersForMessage,
            'body' => $body,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'message_id' => $messageId,
        ];

        if ($transport === 'smtp') {
            $config = $smtpConfigOverride ?? pp_mail_smtp_config();
            $sent = pp_mail_send_via_smtp($payload, $config);
        } else {
            $headerString = implode("\r\n", $headers);
            $sent = @mail($toEmail, $subjectEncoded, $body, $headerString);
        }

        pp_mail_log($sent ? 'mail.sent' : 'mail.failed', [
            'to' => $toEmail,
            'subject' => $subject,
            'transport' => $transport,
            'result' => $sent ? 'success' : 'error',
        ]);

        if (!$sent) {
            if ($transport === 'smtp') {
                pp_mail_log('mail.smtp.failed', [
                    'to' => $toEmail,
                    'subject' => $subject,
                ]);
            } else {
                $lastError = function_exists('error_get_last') ? error_get_last() : null;
                if ($lastError) {
                    pp_mail_log('mail.failed_error', [
                        'to' => $toEmail,
                        'subject' => $subject,
                        'error' => $lastError['message'] ?? null,
                    ]);
                }
            }
        }

        return $sent;
    }
}

if (!function_exists('pp_mail_smtp_config')) {
    function pp_mail_smtp_config(): array {
        $host = trim((string)get_setting('mail_smtp_host', ''));
        $port = (int)get_setting('mail_smtp_port', 587);
        if ($port <= 0 || $port > 65535) {
            $port = 587;
        }
        $username = trim((string)get_setting('mail_smtp_username', ''));
        $password = (string)get_setting('mail_smtp_password', '');
        $encryption = strtolower((string)get_setting('mail_smtp_encryption', 'tls'));
        if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $encryption = 'tls';
        }
        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption,
            'timeout' => 15,
        ];
    }
}

if (!function_exists('pp_mail_smtp_normalize_lines')) {
    function pp_mail_smtp_normalize_lines(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\n", "\r\n", $text);
        return $text;
    }
}

if (!function_exists('pp_mail_smtp_dot_stuff')) {
    function pp_mail_smtp_dot_stuff(string $text): string {
        return preg_replace('/^\./m', '..', $text);
    }
}

if (!function_exists('pp_mail_smtp_parse_response_code')) {
    function pp_mail_smtp_parse_response_code(string $response): int {
        $response = trim($response);
        if (strlen($response) < 3) {
            return 0;
        }
        return (int)substr($response, 0, 3);
    }
}

if (!function_exists('pp_mail_send_via_smtp')) {
    function pp_mail_send_via_smtp(array $payload, array $config): bool {
        $host = $config['host'] ?? '';
        $host = is_string($host) ? trim($host) : '';
        if ($host === '') {
            pp_mail_log('mail.smtp.invalid_config', ['reason' => 'host_empty']);
            return false;
        }
        $port = (int)($config['port'] ?? 587);
        if ($port <= 0 || $port > 65535) {
            $port = 587;
        }
        $timeout = (int)($config['timeout'] ?? 15);
        if ($timeout <= 0) {
            $timeout = 15;
        }
        $encryption = strtolower((string)($config['encryption'] ?? 'tls'));
        if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $encryption = 'tls';
        }

        $contextOptions = [];
        if (in_array($encryption, ['ssl', 'tls'], true)) {
            $contextOptions['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ];
        }
        $context = empty($contextOptions) ? null : stream_context_create($contextOptions);
        $remote = $host . ':' . $port;
        if ($encryption === 'ssl') {
            $remote = 'ssl://' . $remote;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            pp_mail_log('mail.smtp.connect_failed', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
                'errno' => $errno,
            ]);
            return false;
        }

        stream_set_timeout($socket, $timeout);

        $readResponse = static function ($socketResource): string {
            $response = '';
            while (($line = fgets($socketResource, 515)) !== false) {
                $response .= $line;
                if (strlen($line) < 4) {
                    break;
                }
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };

        $sendCommand = static function ($socketResource, string $command, array $expectedCodes = [250]) use ($readResponse) {
            if ($command !== '') {
                fwrite($socketResource, $command . "\r\n");
            }
            $response = $readResponse($socketResource);
            $code = pp_mail_smtp_parse_response_code($response);
            $success = in_array($code, $expectedCodes, true);
            return [$success, $response, $code];
        };

        $initial = $readResponse($socket);
        if (pp_mail_smtp_parse_response_code($initial) !== 220) {
            pp_mail_log('mail.smtp.unexpected_greeting', ['response' => trim($initial)]);
            fclose($socket);
            return false;
        }

        $fromEmail = $payload['from_email'] ?? '';
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'noreply@' . pp_mail_default_domain();
        }
        $heloDomain = pp_mail_default_domain();
        if (($pos = strrpos($fromEmail, '@')) !== false) {
            $hostPart = substr($fromEmail, $pos + 1);
            if ($hostPart !== '') {
                $heloDomain = $hostPart;
            }
        }

        [$ehloOk, $ehloResp] = $sendCommand($socket, 'EHLO ' . $heloDomain, [250]);
        if (!$ehloOk) {
            pp_mail_log('mail.smtp.ehlo_failed', ['response' => trim($ehloResp)]);
            fclose($socket);
            return false;
        }

        if ($encryption === 'tls') {
            [$tlsOk, $tlsResp] = $sendCommand($socket, 'STARTTLS', [220]);
            if (!$tlsOk) {
                pp_mail_log('mail.smtp.starttls_failed', ['response' => trim($tlsResp)]);
                fclose($socket);
                return false;
            }
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                pp_mail_log('mail.smtp.crypto_failed', []);
                fclose($socket);
                return false;
            }
            [$ehloOk2, $ehloResp2] = $sendCommand($socket, 'EHLO ' . $heloDomain, [250]);
            if (!$ehloOk2) {
                pp_mail_log('mail.smtp.ehlo_after_tls_failed', ['response' => trim($ehloResp2)]);
                fclose($socket);
                return false;
            }
        }

        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        if (is_string($username)) {
            $username = trim($username);
        } else {
            $username = '';
        }
        if (!is_string($password)) {
            $password = '';
        }

        if ($username !== '') {
            [$authOk, $authResp] = $sendCommand($socket, 'AUTH LOGIN', [334]);
            if (!$authOk) {
                pp_mail_log('mail.smtp.auth_init_failed', ['response' => trim($authResp)]);
                fclose($socket);
                return false;
            }
            [$userOk, $userResp] = $sendCommand($socket, base64_encode($username), [334]);
            if (!$userOk) {
                pp_mail_log('mail.smtp.auth_user_failed', ['response' => trim($userResp)]);
                fclose($socket);
                return false;
            }
            [$passOk, $passResp] = $sendCommand($socket, base64_encode($password), [235]);
            if (!$passOk) {
                pp_mail_log('mail.smtp.auth_pass_failed', ['response' => trim($passResp)]);
                fclose($socket);
                return false;
            }
        }

        [$mailOk, $mailResp] = $sendCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        if (!$mailOk) {
            pp_mail_log('mail.smtp.mail_from_failed', ['response' => trim($mailResp)]);
            fclose($socket);
            return false;
        }

        $recipients = [$payload['to'] ?? ''];
        if (!empty($payload['cc']) && is_array($payload['cc'])) {
            $recipients = array_merge($recipients, $payload['cc']);
        }
        if (!empty($payload['bcc']) && is_array($payload['bcc'])) {
            $recipients = array_merge($recipients, $payload['bcc']);
        }
        $recipients = array_values(array_unique(array_filter(array_map('trim', $recipients), static fn($item) => $item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL))));
        if (empty($recipients)) {
            pp_mail_log('mail.smtp.no_recipients', []);
            fclose($socket);
            return false;
        }

        foreach ($recipients as $rcpt) {
            [$rcptOk, $rcptResp, $rcptCode] = $sendCommand($socket, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            if (!$rcptOk) {
                pp_mail_log('mail.smtp.rcpt_failed', [
                    'recipient' => $rcpt,
                    'response' => trim($rcptResp),
                    'code' => $rcptCode,
                ]);
                fclose($socket);
                return false;
            }
        }

        [$dataOk, $dataResp] = $sendCommand($socket, 'DATA', [354]);
        if (!$dataOk) {
            pp_mail_log('mail.smtp.data_failed', ['response' => trim($dataResp)]);
            fclose($socket);
            return false;
        }

        $headersString = implode("\r\n", $payload['headers'] ?? []);
        $headersString = pp_mail_smtp_normalize_lines($headersString);
        $bodyString = pp_mail_smtp_normalize_lines((string)($payload['body'] ?? ''));
        $bodyString = pp_mail_smtp_dot_stuff($bodyString);
        $message = $headersString . "\r\n\r\n" . $bodyString;
        if (substr($message, -2) !== "\r\n") {
            $message .= "\r\n";
        }
        $message .= ".\r\n";
        fwrite($socket, $message);

        [$sendOk, $sendResp] = $sendCommand($socket, '', [250]);
        if (!$sendOk) {
            pp_mail_log('mail.smtp.delivery_failed', ['response' => trim($sendResp)]);
            fclose($socket);
            return false;
        }

        $sendCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    }
}

if (!function_exists('pp_mail_send_template')) {
    function pp_mail_send_template(string $toEmail, string $subject, string $htmlBody, array $options = []): bool {
        $text = pp_mail_html_to_text($htmlBody);
        return pp_mail_send($toEmail, $subject, $htmlBody, $text, $options);
    }
}

?>
