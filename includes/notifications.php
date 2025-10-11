<?php
// User notification preferences management for PromoPilot

if (!function_exists('pp_notification_event_catalog')) {
    function pp_notification_event_catalog(): array {
        static $catalog = null;
        if ($catalog !== null) {
            return $catalog;
        }
        $catalog = [
            'balance_topup' => [
                'label' => __('Пополнение баланса'),
                'description' => __('Письмо, когда на ваш счёт зачисляются средства.'),
                'category' => 'balance',
                'default_enabled' => true,
                'sort' => 10,
            ],
            'promotion_charge' => [
                'label' => __('Списание за продвижение'),
                'description' => __('Уведомление о списании средств при запуске продвижения.'),
                'category' => 'promotion',
                'default_enabled' => true,
                'sort' => 20,
            ],
            'promotion_completed' => [
                'label' => __('Продвижение завершено'),
                'description' => __('Письмо, когда продвижение по ссылке успешно завершается.'),
                'category' => 'promotion',
                'default_enabled' => true,
                'sort' => 30,
            ],
            'promotion_task_completed' => [
                'label' => __('Выполненные задачи продвижения'),
                'description' => __('Сообщение, когда задача по продвижению успешно завершена.'),
                'category' => 'promotion',
                'default_enabled' => true,
                'sort' => 35,
            ],
            'balance_manual_adjustment' => [
                'label' => __('Изменения администратором'),
                'description' => __('Письмо при корректировке баланса администратором.'),
                'category' => 'balance',
                'default_enabled' => true,
                'sort' => 40,
            ],
            'balance_debit' => [
                'label' => __('Прочие списания с баланса'),
                'description' => __('Предупреждение о любых других списаниях со счёта.'),
                'category' => 'balance',
                'default_enabled' => true,
                'sort' => 50,
            ],
            'service_updates' => [
                'label' => __('Новости и акции сервиса'),
                'description' => __('Получайте новости, акции и рекомендации от PromoPilot.'),
                'category' => 'updates',
                'default_enabled' => true,
                'sort' => 60,
            ],
        ];
        uasort($catalog, static function (array $a, array $b): int {
            $sa = $a['sort'] ?? 0;
            $sb = $b['sort'] ?? 0;
            if ($sa === $sb) {
                return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
            }
            return $sa <=> $sb;
        });
        return $catalog;
    }
}

if (!function_exists('pp_notification_event_categories')) {
    function pp_notification_event_categories(): array {
        return [
            'balance' => __('Баланс и платежи'),
            'promotion' => __('Продвижение'),
            'updates' => __('Новости и акции'),
            'other' => __('Прочее'),
        ];
    }
}

if (!function_exists('pp_notification_event_defaults')) {
    function pp_notification_event_defaults(): array {
        $catalog = pp_notification_event_catalog();
        $defaults = [];
        foreach ($catalog as $key => $info) {
            $defaults[$key] = !empty($info['default_enabled']);
        }
        return $defaults;
    }
}

if (!function_exists('pp_notification_event_info')) {
    function pp_notification_event_info(string $eventKey): ?array {
        $catalog = pp_notification_event_catalog();
        $eventKey = trim($eventKey);
        return $catalog[$eventKey] ?? null;
    }
}

if (!function_exists('pp_notification_get_user_settings')) {
    function pp_notification_get_user_settings(int $userId): array {
        $userId = (int)$userId;
        $defaults = pp_notification_event_defaults();
        if ($userId <= 0) {
            return $defaults;
        }
        if (!isset($GLOBALS['pp_notification_user_cache'])) {
            $GLOBALS['pp_notification_user_cache'] = [];
        }
        if (isset($GLOBALS['pp_notification_user_cache'][$userId])) {
            return $GLOBALS['pp_notification_user_cache'][$userId];
        }
        $settings = $defaults;
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if ($conn) {
            $stmt = $conn->prepare('SELECT event_key, enabled FROM user_notification_settings WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    if ($res = $stmt->get_result()) {
                        while ($row = $res->fetch_assoc()) {
                            $key = trim((string)($row['event_key'] ?? ''));
                            if ($key !== '' && array_key_exists($key, $defaults)) {
                                $settings[$key] = ((int)$row['enabled'] === 1);
                            }
                        }
                        $res->free();
                    }
                }
                $stmt->close();
            }
            $conn->close();
        }
        $GLOBALS['pp_notification_user_cache'][$userId] = $settings;
        return $settings;
    }
}

if (!function_exists('pp_notification_flush_user_cache')) {
    function pp_notification_flush_user_cache(int $userId): void {
        $userId = (int)$userId;
        if (isset($GLOBALS['pp_notification_user_cache'][$userId])) {
            unset($GLOBALS['pp_notification_user_cache'][$userId]);
        }
    }
}

if (!function_exists('pp_notification_update_user_settings')) {
    function pp_notification_update_user_settings(int $userId, array $values): bool {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $catalog = pp_notification_event_catalog();
        if (empty($catalog)) {
            return true;
        }
        $payload = [];
        foreach ($catalog as $key => $info) {
            $payload[$key] = !empty($values[$key]);
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if (!$conn) {
            return false;
        }
        $conn->set_charset('utf8mb4');
        $ok = true;
        $stmt = $conn->prepare('INSERT INTO user_notification_settings (user_id, event_key, enabled, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP');
        if ($stmt) {
            $eventKey = '';
            $enabled = 0;
            $stmt->bind_param('isi', $userId, $eventKey, $enabled);
            foreach ($payload as $key => $flag) {
                $eventKey = $key;
                $enabled = $flag ? 1 : 0;
                if (!$stmt->execute()) {
                    $ok = false;
                    break;
                }
            }
            $stmt->close();
        } else {
            $ok = false;
        }
        if ($ok) {
            $validKeys = array_keys($catalog);
            if (!empty($validKeys)) {
                $placeholders = implode(',', array_fill(0, count($validKeys), '?'));
                $sql = 'DELETE FROM user_notification_settings WHERE user_id = ? AND event_key NOT IN (' . $placeholders . ')';
                $del = $conn->prepare($sql);
                if ($del) {
                    $types = 'i' . str_repeat('s', count($validKeys));
                    $params = [$userId];
                    foreach ($validKeys as $validKey) {
                        $params[] = $validKey;
                    }
                    try {
                        pp_stmt_bind_safe_array($del, $types, $params);
                        $del->execute();
                    } catch (Throwable $bindError) {
                        error_log('notifications bind failed: ' . $bindError->getMessage());
                        $ok = false;
                    }
                    $del->close();
                }
            }
        }
        $conn->close();
        if ($ok) {
            pp_notification_flush_user_cache($userId);
        }
        return $ok;
    }
}

if (!function_exists('pp_notification_user_allows')) {
    function pp_notification_user_allows(int $userId, string $eventKey): bool {
        $eventKey = trim($eventKey);
        if ($eventKey === '') {
            return true;
        }
        if ($userId <= 0) {
            return true;
        }
        $catalog = pp_notification_event_catalog();
        if (!array_key_exists($eventKey, $catalog)) {
            return true;
        }
        $settings = pp_notification_get_user_settings($userId);
        return !empty($settings[$eventKey]);
    }
}

if (!function_exists('pp_notification_normalize_row')) {
    function pp_notification_normalize_row(array $row): array {
        $id = isset($row['id']) ? (int)$row['id'] : 0;
        $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        $eventKey = trim((string)($row['event_key'] ?? ''));
        $eventKey = ($eventKey !== '') ? $eventKey : null;
        $type = trim((string)($row['type'] ?? ''));
        $type = ($type !== '') ? $type : null;
        $title = trim((string)($row['title'] ?? ''));
        $message = (string)($row['message'] ?? '');
        $metaRaw = $row['meta_json'] ?? null;
        if (is_array($metaRaw)) {
            $meta = $metaRaw;
        } else {
            $decoded = json_decode((string)$metaRaw, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $ctaUrl = trim((string)($row['cta_url'] ?? ''));
        $ctaUrl = ($ctaUrl !== '') ? $ctaUrl : null;
        $ctaLabel = trim((string)($row['cta_label'] ?? ''));
        $ctaLabel = ($ctaLabel !== '') ? $ctaLabel : null;
        $isRead = !empty($row['is_read']);
        $readAt = $row['read_at'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        $createdAtIso = null;
        if ($createdAt) {
            $ts = strtotime((string)$createdAt);
            if ($ts !== false) {
                $createdAtIso = gmdate('c', $ts);
            }
        }

        return [
            'id' => $id,
            'user_id' => $userId,
            'event_key' => $eventKey,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'meta' => $meta,
            'cta_url' => $ctaUrl,
            'cta_label' => $ctaLabel,
            'is_read' => $isRead,
            'read_at' => $readAt,
            'created_at' => $createdAt,
            'created_at_iso' => $createdAtIso,
        ];
    }
}

if (!function_exists('pp_notification_store')) {
    function pp_notification_store(int $userId, array $data): ?array {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if (!$conn) {
            return null;
        }
        $conn->set_charset('utf8mb4');

        $eventKey = trim((string)($data['event_key'] ?? ''));
        $type = trim((string)($data['type'] ?? ''));
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $title = __('Уведомление');
        }
        $message = trim((string)($data['message'] ?? ''));
        $ctaUrl = trim((string)($data['cta_url'] ?? ''));
        $ctaLabel = trim((string)($data['cta_label'] ?? ''));

        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 190, 'UTF-8');
            $ctaLabel = mb_substr($ctaLabel, 0, 100, 'UTF-8');
        } else {
            $title = substr($title, 0, 190);
            $ctaLabel = substr($ctaLabel, 0, 100);
        }

        if ($ctaUrl !== '' && defined('PP_BASE_URL') && strpos($ctaUrl, 'http') !== 0 && strpos($ctaUrl, '//') !== 0) {
            if ($ctaUrl[0] === '/') {
                $ctaUrl = rtrim(PP_BASE_URL, '/') . $ctaUrl;
            }
        }

        if (array_key_exists('meta_json', $data)) {
            $metaJson = is_array($data['meta_json'])
                ? json_encode($data['meta_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$data['meta_json'];
            if ($metaJson === '' || $metaJson === false) {
                $metaJson = '{}';
            }
        } elseif (isset($data['meta']) && is_array($data['meta'])) {
            $metaJson = json_encode($data['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } else {
            $metaJson = '{}';
        }

        $isRead = !empty($data['is_read']) ? 1 : 0;

        $stmt = $conn->prepare('INSERT INTO user_notifications (user_id, event_key, type, title, message, meta_json, cta_url, cta_label, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        if (!$stmt) {
            $conn->close();
            return null;
        }
        $eventKeyParam = $eventKey !== '' ? $eventKey : null;
        $typeParam = $type !== '' ? $type : null;
        $messageParam = $message !== '' ? $message : null;
        $ctaUrlParam = $ctaUrl !== '' ? $ctaUrl : null;
        $ctaLabelParam = $ctaLabel !== '' ? $ctaLabel : null;
        $stmt->bind_param(
            'isssssssi',
            $userId,
            $eventKeyParam,
            $typeParam,
            $title,
            $messageParam,
            $metaJson,
            $ctaUrlParam,
            $ctaLabelParam,
            $isRead
        );
        $ok = $stmt->execute();
        $insertId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        if (!$ok) {
            $conn->close();
            return null;
        }

        $row = [
            'id' => $insertId,
            'user_id' => $userId,
            'event_key' => $eventKeyParam,
            'type' => $typeParam,
            'title' => $title,
            'message' => $messageParam ?? '',
            'meta_json' => $metaJson,
            'cta_url' => $ctaUrlParam,
            'cta_label' => $ctaLabelParam,
            'is_read' => $isRead,
            'read_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        $conn->close();

        return pp_notification_normalize_row($row);
    }
}

if (!function_exists('pp_notification_fetch_recent')) {
    function pp_notification_fetch_recent(int $userId, int $limit = 10, bool $includeRead = true): array {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return [];
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if (!$conn) {
            return [];
        }
        $conn->set_charset('utf8mb4');

        $limit = max(1, min(100, (int)$limit));
        $sql = 'SELECT id, user_id, event_key, type, title, message, meta_json, cta_url, cta_label, is_read, read_at, created_at FROM user_notifications WHERE user_id = ?';
        if (!$includeRead) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);

        $items = [];
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $items[] = pp_notification_normalize_row($row);
                }
                $res->free();
            }
        }
        $stmt->close();
        $conn->close();

        return $items;
    }
}

if (!function_exists('pp_notification_count_unread')) {
    function pp_notification_count_unread(int $userId): int {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0;
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if (!$conn) {
            return 0;
        }

        $count = 0;
        if ($stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM user_notifications WHERE user_id = ? AND is_read = 0')) {
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                if ($res = $stmt->get_result()) {
                    if ($row = $res->fetch_assoc()) {
                        $count = (int)($row['cnt'] ?? 0);
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }
        $conn->close();

        return $count;
    }
}

if (!function_exists('pp_notification_mark_read')) {
    function pp_notification_mark_read(int $userId, array $notificationIds): bool {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $notificationIds), static function ($value) {
            return $value > 0;
        })));
        if (empty($ids)) {
            return true;
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if (!$conn) {
            return false;
        }
        $conn->set_charset('utf8mb4');

        $idsSql = implode(',', $ids);
        $sql = "UPDATE user_notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE user_id = {$userId} AND is_read = 0 AND id IN ({$idsSql})";
        $ok = $conn->query($sql) === true;
        $conn->close();

        return $ok;
    }
}

if (!function_exists('pp_notification_mark_all_read')) {
    function pp_notification_mark_all_read(int $userId): bool {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if (!$conn) {
            return false;
        }
        $sql = "UPDATE user_notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE user_id = {$userId} AND is_read = 0";
        $ok = $conn->query($sql) === true;
        $conn->close();

        return $ok;
    }
}
