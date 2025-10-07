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
            'promotion_task_completed' => [
                'label' => __('Выполненные задачи продвижения'),
                'description' => __('Сообщение, когда задача по продвижению успешно завершена.'),
                'category' => 'promotion',
                'default_enabled' => true,
                'sort' => 30,
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
                    $userIdRef = $userId;
                    $bindParams = [$types, &$userIdRef];
                    foreach ($validKeys as $idx => $validKey) {
                        $bindParams[] = &$validKeys[$idx];
                    }
                    call_user_func_array([$del, 'bind_param'], $bindParams);
                    $del->execute();
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
