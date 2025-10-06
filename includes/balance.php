<?php
// Balance management helpers: logging and notifications

if (!function_exists('pp_balance_user_info')) {
    function pp_balance_user_info(int $userId): ?array {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return null;
        }
        if (!$conn) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, username, full_name, email, balance FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $conn->close();
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
        $conn->close();
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['balance'] = (float)$row['balance'];
        return $row;
    }
}

if (!function_exists('pp_balance_normalize_event')) {
    function pp_balance_normalize_event(array $event): array {
        $userId = (int)($event['user_id'] ?? 0);
        $delta = round((float)($event['delta'] ?? 0), 2);
        $before = array_key_exists('balance_before', $event) ? round((float)$event['balance_before'], 2) : null;
        $after = array_key_exists('balance_after', $event) ? round((float)$event['balance_after'], 2) : null;
        if ($before === null && $after !== null) {
            $before = round($after - $delta, 2);
        }
        if ($after === null && $before !== null) {
            $after = round($before + $delta, 2);
        }
        if ($before === null) {
            $before = round((float)($event['before'] ?? 0), 2);
        }
        if ($after === null) {
            $after = round((float)($event['after'] ?? $before + $delta), 2);
        }
        $source = trim((string)($event['source'] ?? 'system'));
        if ($source === '') {
            $source = 'system';
        }
        $meta = $event['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = ['value' => $meta];
        }
        if (!isset($meta['currency'])) {
            $meta['currency'] = get_currency_code();
        }
        $normalized = [
            'user_id' => $userId,
            'delta' => $delta,
            'balance_before' => $before,
            'balance_after' => $after,
            'source' => $source,
            'meta' => $meta,
        ];
        if (isset($event['admin_id'])) {
            $normalized['admin_id'] = (int)$event['admin_id'];
        }
        if (isset($event['created_by'])) {
            $normalized['admin_id'] = (int)$event['created_by'];
        }
        if (isset($event['history_id'])) {
            $normalized['history_id'] = (int)$event['history_id'];
        }
        return $normalized;
    }
}

if (!function_exists('pp_balance_record_event')) {
    function pp_balance_record_event(mysqli $conn, array $event): ?array {
        $event = pp_balance_normalize_event($event);
        if ($event['user_id'] <= 0) {
            return null;
        }
        if (abs($event['delta']) < 0.00001) {
            return null;
        }
        $metaJson = json_encode($event['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) {
            $metaJson = '{}';
        }
        $userId = $event['user_id'];
        $delta = $event['delta'];
        $before = $event['balance_before'];
        $after = $event['balance_after'];
        $sourceRaw = (string)$event['source'];
        if (function_exists('mb_substr')) {
            $source = mb_substr($sourceRaw, 0, 50, 'UTF-8');
        } else {
            $source = substr($sourceRaw, 0, 50);
        }
        $adminId = isset($event['admin_id']) ? (int)$event['admin_id'] : null;

        if ($adminId !== null) {
            $stmt = $conn->prepare('INSERT INTO balance_history (user_id, delta, balance_before, balance_after, source, meta_json, created_by_admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('idddssi', $userId, $delta, $before, $after, $source, $metaJson, $adminId);
        } else {
            $stmt = $conn->prepare('INSERT INTO balance_history (user_id, delta, balance_before, balance_after, source, meta_json, created_by_admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, CURRENT_TIMESTAMP)');
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('idddss', $userId, $delta, $before, $after, $source, $metaJson);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $stmt->close();
        $event['history_id'] = (int)$conn->insert_id;
        return $event;
    }
}

if (!function_exists('pp_balance_sign_amount')) {
    function pp_balance_sign_amount(float $amount): string {
        $formatted = format_currency(abs($amount));
        return ($amount >= 0 ? '+' : '−') . $formatted;
    }
}

if (!function_exists('pp_balance_event_reason')) {
    function pp_balance_event_reason(array $event): string {
        $event = pp_balance_normalize_event($event);
        $source = $event['source'];
        $meta = $event['meta'];
        switch ($source) {
            case 'payment':
                $gateway = strtoupper(trim((string)($meta['gateway_code'] ?? '')));
                if ($gateway !== '') {
                    return sprintf(__('Пополнение через %s'), $gateway);
                }
                return __('Пополнение баланса');
            case 'promotion':
                $projectId = (int)($meta['project_id'] ?? 0);
                if ($projectId > 0) {
                    return sprintf(__('Списание за продвижение проекта #%d'), $projectId);
                }
                return __('Списание за продвижение');
            case 'manual':
                $admin = trim((string)($meta['admin_username'] ?? $meta['admin_full_name'] ?? ''));
                if ($admin !== '') {
                    return sprintf(__('Изменение администратором %s'), $admin);
                }
                return __('Изменение администратором');
            default:
                return __('Изменение баланса');
        }
    }
}

if (!function_exists('pp_balance_event_comment')) {
    function pp_balance_event_comment(array $event): ?string {
        $meta = $event['meta'] ?? [];
        if (!is_array($meta)) {
            return null;
        }
        $comment = trim((string)($meta['comment'] ?? ''));
        return $comment === '' ? null : $comment;
    }
}

if (!function_exists('pp_balance_send_event_notification')) {
    function pp_balance_send_event_notification(array $event): bool {
        $event = pp_balance_normalize_event($event);
        if (abs($event['delta']) < 0.00001) {
            pp_mail_log('mail.balance_notification.skipped_delta', [
                'user_id' => $event['user_id'] ?? null,
                'delta' => $event['delta'] ?? null,
            ]);
            return false;
        }
        $user = pp_balance_user_info($event['user_id']);
        if (!$user) {
            pp_mail_log('mail.balance_notification.user_missing', [
                'user_id' => $event['user_id'] ?? null,
                'history_id' => $event['history_id'] ?? null,
            ]);
            return false;
        }
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pp_mail_log('mail.balance_notification.invalid_email', [
                'user_id' => $event['user_id'] ?? null,
                'email' => $user['email'] ?? null,
                'history_id' => $event['history_id'] ?? null,
            ]);
            return false;
        }
        $name = trim((string)($user['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($user['username'] ?? ''));
        }
        if ($name === '') {
            $name = 'клиент';
        }
        $delta = $event['delta'];
        $absFormatted = format_currency(abs($delta));
        $afterFormatted = format_currency($event['balance_after']);
        $reason = pp_balance_event_reason($event);
        $changeLabel = $delta >= 0 ? __('Пополнение баланса') : __('Списание с баланса');
        $subjectEmoji = $delta >= 0 ? '💰' : '⚠️';
        $subject = $subjectEmoji . ' ' . $changeLabel . ' — PromoPilot';
        $intro = $delta >= 0
            ? sprintf(__('Ваш баланс пополнен на %s.'), $absFormatted)
            : sprintf(__('С вашего баланса списано %s.'), $absFormatted);
        $comment = pp_balance_event_comment($event);
            $historyUrl = pp_url('client/balance.php');
            $topupUrl = pp_url('client/balance.php');
            $logoSrc = pp_url('assets/img/logo.svg');
            $logoPath = defined('PP_ROOT_PATH') ? PP_ROOT_PATH . '/assets/img/logo.svg' : null;
            if ($logoPath && is_readable($logoPath)) {
                $logoContent = @file_get_contents($logoPath);
                if ($logoContent !== false && $logoContent !== '') {
                    $encodedLogo = base64_encode($logoContent);
                    if ($encodedLogo !== '') {
                        $logoSrc = 'data:image/svg+xml;base64,' . $encodedLogo;
                    }
                }
            }
        $supportEmail = trim((string)get_setting('support_email', 'support@' . pp_mail_default_domain()));
        if (!filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            $supportEmail = 'support@' . pp_mail_default_domain();
        }
        $supportLink = 'mailto:' . $supportEmail;
        $greeting = sprintf(__('Здравствуйте, %s!'), htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $rows = [];
        $rows[] = ['label' => __('Сумма изменения'), 'value' => pp_balance_sign_amount($delta)];
        $rows[] = ['label' => __('Текущий баланс'), 'value' => $afterFormatted];
        $rows[] = ['label' => __('Причина'), 'value' => $reason];
        if ($comment !== null) {
            $rows[] = ['label' => __('Комментарий администратора'), 'value' => $comment];
        }

        pp_mail_log('mail.balance_notification.prepare', [
            'user_id' => $event['user_id'],
            'history_id' => $event['history_id'] ?? null,
            'delta' => $event['delta'],
            'email' => $email,
        ]);

        $tableRows = '';
        foreach ($rows as $rowItem) {
            $labelText = htmlspecialchars((string)$rowItem['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $valueRaw = (string)$rowItem['value'];
            $valueText = htmlspecialchars($valueRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tableRows .= '<tr>'
                . '<td style="padding:8px 12px;color:#555;font-size:14px;">' . $labelText . '</td>'
                . '<td style="padding:8px 12px;color:#111;font-size:14px;font-weight:600;">' . $valueText . '</td>'
                . '</tr>';
        }

        $highlights = [
            __('PromoPilot помогает управлять продвижением и бюджетом в одном окне.'),
            __('Следите за продвижением в реальном времени и получайте уведомления.'),
            __('Получайте автоматические отчеты и полную историю транзакций.'),
        ];

        $highlightsItems = '';
        foreach ($highlights as $highlight) {
            $highlightsItems .= '<li style="margin:0 0 8px;padding-left:0;color:#1f2937;font-size:13px;line-height:1.5;">'
                . htmlspecialchars($highlight, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</li>';
        }

        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>'
            . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title></head><body style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;">'
            . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;box-shadow:0 18px 40px rgba(15,23,42,0.12);overflow:hidden;">'
            . '<div style="background:#0f172a;padding:32px 36px;text-align:center;">'
            . '<a href="' . htmlspecialchars(pp_url(''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:inline-block;text-decoration:none;">'
                . '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="PromoPilot" style="height:44px;max-width:160px;margin:0 0 18px;" />'
            . '</a>'
            . '<div style="color:#e0e7ff;font-size:18px;font-weight:600;">' . htmlspecialchars($changeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
            . '<div style="margin-top:8px;color:#94a3b8;font-size:13px;line-height:1.6;">' . htmlspecialchars(__('PromoPilot — ваш центр управления продвижением и балансом.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
            . '</div>'
            . '<div style="padding:32px 36px 28px;">'
            . '<p style="margin:0 0 12px;font-size:15px;color:#1f2937;line-height:1.6;">' . $greeting . '</p>'
            . '<p style="margin:0 0 22px;font-size:16px;line-height:1.7;color:#0f172a;font-weight:500;">' . htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background:#f8fafc;border-radius:12px;overflow:hidden;">'
            . $tableRows
            . '</table>'
            . '<div style="margin-top:28px;padding:20px;background:#f1f5f9;border-radius:12px;">'
            . '<div style="font-size:13px;font-weight:600;color:#1f2937;margin-bottom:12px;">' . htmlspecialchars(__('Почему PromoPilot:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
            . '<ul style="margin:0;padding-left:20px;list-style:disc;text-align:left;">' . $highlightsItems . '</ul>'
            . '</div>'
            . '<div style="margin-top:28px;text-align:center;">'
            . '<a href="' . htmlspecialchars($historyUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:block;margin:0 0 12px;padding:14px 24px;background:#2563eb;color:#ffffff;font-weight:600;font-size:14px;text-decoration:none;border-radius:10px;">'
            . htmlspecialchars(__('Перейти в кабинет'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>'
            . '<a href="' . htmlspecialchars($topupUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '#topup" style="display:block;margin:0 0 12px;padding:14px 24px;background:#e0f2fe;color:#0369a1;font-weight:600;font-size:14px;text-decoration:none;border-radius:10px;">'
            . htmlspecialchars(__('Пополнить баланс'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>'
            . '<a href="' . htmlspecialchars($supportLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:block;padding:14px 24px;border:1px solid #cbd5f5;color:#1d4ed8;font-weight:600;font-size:14px;text-decoration:none;border-radius:10px;">'
            . htmlspecialchars(__('Связаться с нами'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>'
            . '</div>'
            . '<p style="margin:28px 0 0;font-size:13px;color:#6b7280;line-height:1.7;">' . htmlspecialchars(__('Если вы не выполняли это действие, срочно свяжитесь с поддержкой.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p style="margin:12px 0 0;font-size:13px;color:#475569;">' . htmlspecialchars(__('Поддержка:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' ' . htmlspecialchars($supportEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p style="margin:18px 0 0;font-size:13px;color:#111827;font-weight:600;">' . htmlspecialchars(__('Команда PromoPilot ✈️'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</div>'
            . '<div style="padding:18px 36px;background:#0f172a;color:#cbd5f5;font-size:12px;text-align:center;line-height:1.5;">'
            . 'PromoPilot &mdash; ' . htmlspecialchars(__('Панель управления продвижением и балансом'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '<br />' . htmlspecialchars(__('PromoPilot — ваш центр управления продвижением и балансом.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>'
            . '</div>'
            . '</body></html>';

        $textLines = [];
        $textLines[] = strip_tags($greeting);
        $textLines[] = '';
        $textLines[] = strip_tags($intro);
        $textLines[] = '';
        foreach ($rows as $rowItem) {
            $textLines[] = (string)$rowItem['label'] . ': ' . (string)$rowItem['value'];
        }
        $textLines[] = '';
        $textLines[] = __('История и пополнение:') . ' ' . $historyUrl;
        $textLines[] = __('Пополнить баланс:') . ' ' . $topupUrl;
        $textLines[] = '';
        $textLines[] = __('Почему PromoPilot:');
        foreach ($highlights as $highlight) {
            $textLines[] = '• ' . $highlight;
        }
        $textLines[] = '';
        $textLines[] = __('Если вы не выполняли это действие, срочно свяжитесь с поддержкой.');
        $textLines[] = __('Поддержка:') . ' ' . $supportEmail;
        $textLines[] = '';
        $textLines[] = __('Команда PromoPilot ✈️');
        $textBody = implode("\n", $textLines);

        $sent = pp_mail_send($email, $subject, $html, $textBody, [
            'reply_to' => get_setting('mail_reply_to', ''),
            'from' => ['name' => 'PromoPilot ✈️'],
        ]);
        if (!$sent) {
            pp_mail_log('mail.balance_notification.failed', [
                'user_id' => $event['user_id'],
                'history_id' => $event['history_id'] ?? null,
                'email' => $email,
                'reason' => pp_mail_disabled_reason(),
            ]);
        }
        return $sent;
    }
}

if (!function_exists('pp_balance_history_for_user')) {
    function pp_balance_history_for_user(int $userId, int $limit = 50, int $offset = 0): array {
        $userId = (int)$userId;
        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);
        if ($userId <= 0) {
            return [];
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return [];
        }
        if (!$conn) {
            return [];
        }

        $sql = 'SELECT bh.*, adm.username AS admin_username, adm.full_name AS admin_full_name
                FROM balance_history AS bh
                LEFT JOIN users AS adm ON adm.id = bh.created_by_admin_id
                WHERE bh.user_id = ?
                ORDER BY bh.created_at DESC, bh.id DESC
                LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            return [];
        }
        $stmt->bind_param('iii', $userId, $limit, $offset);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            return [];
        }
        $res = $stmt->get_result();
        $events = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $meta = [];
                if (isset($row['meta_json']) && $row['meta_json'] !== null && $row['meta_json'] !== '') {
                    $decoded = json_decode((string)$row['meta_json'], true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                $event = [
                    'history_id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'delta' => (float)$row['delta'],
                    'balance_before' => (float)$row['balance_before'],
                    'balance_after' => (float)$row['balance_after'],
                    'source' => (string)$row['source'],
                    'meta' => $meta,
                    'admin_id' => isset($row['created_by_admin_id']) ? (int)$row['created_by_admin_id'] : null,
                    'created_at' => (string)$row['created_at'],
                    'admin_username' => isset($row['admin_username']) ? (string)$row['admin_username'] : '',
                    'admin_full_name' => isset($row['admin_full_name']) ? (string)$row['admin_full_name'] : '',
                ];
                $event = pp_balance_normalize_event($event);
                $event['created_at'] = (string)$row['created_at'];
                $event['admin_username'] = isset($row['admin_username']) ? (string)$row['admin_username'] : '';
                $event['admin_full_name'] = isset($row['admin_full_name']) ? (string)$row['admin_full_name'] : '';
                $events[] = $event;
            }
            $res->free();
        }
        $stmt->close();
        $conn->close();
        return $events;
    }
}

?>
