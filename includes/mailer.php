<?php
// Lightweight mail helper for PromoPilot

if (!function_exists('pp_mail_is_enabled')) {
    function pp_mail_is_enabled(): bool {
        $flags = [
            (string)get_setting('mail_enabled', '1'),
            (string)get_setting('notifications_email_enabled', '1'),
            (string)get_setting('mail_disable_all', '0'),
        ];
        $disabled = false;
        foreach ($flags as $flag) {
            $normalized = strtolower(trim($flag));
            if ($normalized === '0' || $normalized === '' || $normalized === 'off') {
                continue;
            }
            if (in_array($normalized, ['false', 'no'], true)) {
                continue;
            }
            if (in_array($normalized, ['disabled', 'disable'], true)) {
                $disabled = true;
                break;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                continue;
            }
            if ($normalized === 'all') {
                $disabled = true;
                break;
            }
        }
        $explicitDisable = strtolower(trim((string)get_setting('mail_disable_all', '0')));
        if (in_array($explicitDisable, ['1', 'true', 'yes', 'on', 'all'], true)) {
            $disabled = true;
        }
        return !$disabled;
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
            return false;
        }
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
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
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: PromoPilot';
        $headers[] = 'From: ' . pp_mail_format_address($fromEmail, $fromName);

        $replyTo = trim((string)($options['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . pp_mail_format_address($replyTo, $options['reply_to_name'] ?? null);
        }

        $cc = $options['cc'] ?? [];
        if (is_string($cc)) { $cc = [$cc]; }
        if (is_array($cc)) {
            $ccFiltered = array_values(array_filter(array_map('trim', $cc), static fn($item) => $item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL)));
            if (!empty($ccFiltered)) {
                $headers[] = 'Cc: ' . implode(', ', array_map(static fn($item) => pp_mail_format_address($item), $ccFiltered));
            }
        }

        $bcc = $options['bcc'] ?? [];
        if (is_string($bcc)) { $bcc = [$bcc]; }
        if (is_array($bcc)) {
            $bccFiltered = array_values(array_filter(array_map('trim', $bcc), static fn($item) => $item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL)));
            if (!empty($bccFiltered)) {
                $headers[] = 'Bcc: ' . implode(', ', array_map(static fn($item) => pp_mail_format_address($item), $bccFiltered));
            }
        }

        try {
            $boundary = '=_PP_' . bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $fallbackSeed = microtime(true) . ':' . mt_rand();
            $fallbackHash = hash('sha256', $fallbackSeed, true);
            $boundary = '=_PP_' . bin2hex(substr($fallbackHash, 0, 12));
        }
        $subjectEncoded = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n") : $subject;

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

        $headerString = implode("\r\n", $headers);
        return @mail($toEmail, $subjectEncoded, $body, $headerString);
    }
}

if (!function_exists('pp_mail_send_template')) {
    function pp_mail_send_template(string $toEmail, string $subject, string $htmlBody, array $options = []): bool {
        $text = pp_mail_html_to_text($htmlBody);
        return pp_mail_send($toEmail, $subject, $htmlBody, $text, $options);
    }
}

?>
