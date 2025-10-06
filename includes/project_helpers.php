<?php

// Project-related helper functions extracted from client/project.php to reduce duplication

if (!function_exists('pp_project_fetch_with_user')) {
    /**
     * Загружает проект вместе с данными пользователя.
     */
    function pp_project_fetch_with_user(int $projectId): ?array
    {
    $linksCount = 0;
    $conn = connect_db();
        if (!$conn) {
            return null;
        }

        $sql = 'SELECT p.*, u.username, u.promotion_discount, u.balance FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?';
        $project = null;

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('i', $projectId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $project = $res->fetch_assoc();
                }
                if ($res) {
                    $res->free();
                }
            }
            $stmt->close();
        }

        $conn->close();

        return $project ?: null;
    }
}

if (!function_exists('pp_project_fetch_links')) {
    /**
     * Возвращает ссылки проекта в виде унифицированного массива.
     */
    function pp_project_fetch_links(int $projectId, ?string $fallbackLanguage = null): array
    {
        $conn = connect_db();
        if (!$conn) {
            return [];
        }

        $links = [];
        $fallbackLanguage = $fallbackLanguage ?: 'ru';

        if ($stmt = $conn->prepare('SELECT id, url, anchor, language, wish FROM project_links WHERE project_id = ? ORDER BY id ASC')) {
            $stmt->bind_param('i', $projectId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $links[] = [
                            'id' => (int)($row['id'] ?? 0),
                            'url' => (string)($row['url'] ?? ''),
                            'anchor' => (string)($row['anchor'] ?? ''),
                            'language' => (string)($row['language'] ?? $fallbackLanguage),
                            'wish' => (string)($row['wish'] ?? ''),
                        ];
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }

        $conn->close();

        return $links;
    }
}

if (!function_exists('pp_normalize_host')) {
    /**
     * Приводит хост к единому виду, убирая www и пробелы.
     */
    function pp_normalize_host(?string $host): string
    {
        $host = strtolower(trim((string)$host));
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        return $host;
    }
}

if (!function_exists('pp_project_promotion_snapshot')) {
    /**
     * Собирает информацию о статусах промо-ссылок проекта.
     */
    function pp_project_promotion_snapshot(int $projectId, array $links): array
    {
        $summary = [
            'total' => count($links),
            'active' => 0,
            'completed' => 0,
            'idle' => 0,
            'issues' => 0,
        ];
        $statusByUrl = [];

        if ($summary['total'] === 0 || !function_exists('pp_promotion_get_status')) {
            $summary['idle'] = $summary['total'];
            return [
                'summary' => $summary,
                'status_by_url' => $statusByUrl,
                'can_delete' => true,
            ];
        }

        $activeStates = ['queued','running','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready'];
        $issueStates = ['failed','cancelled'];

        foreach ($links as $link) {
            $linkUrl = (string)($link['url'] ?? '');
            if ($linkUrl === '') {
                $summary['idle']++;
                continue;
            }

            $status = 'idle';
            $payload = pp_promotion_get_status($projectId, $linkUrl);
            if (is_array($payload) && !empty($payload['ok'])) {
                $statusByUrl[$linkUrl] = $payload;
                $status = (string)($payload['status'] ?? 'idle');
            }

            if (in_array($status, $activeStates, true)) {
                $summary['active']++;
            } elseif ($status === 'completed') {
                $summary['completed']++;
            } elseif (in_array($status, $issueStates, true)) {
                $summary['issues']++;
            } else {
                $summary['idle']++;
            }
        }

        $canDelete = ($summary['total'] === 0) || ($summary['idle'] === $summary['total']);

        return [
            'summary' => $summary,
            'status_by_url' => $statusByUrl,
            'can_delete' => $canDelete,
        ];
    }
}

if (!function_exists('pp_project_delete_with_relations')) {
    /**
     * Полностью удаляет проект и связанные сущности из базы данных.
     */
    function pp_project_delete_with_relations(int $projectId, int $userId, bool $isAdmin): array
    {
        $conn = null;
        $transactionStarted = false;
        $deleteOk = false;
        $error = null;

        try {
            $conn = connect_db();
            if (!$conn) {
                return ['ok' => false, 'error' => 'DB'];
            }

            if (method_exists($conn, 'begin_transaction')) {
                $transactionStarted = @$conn->begin_transaction();
            }

            $cleanupStatements = [
                'DELETE FROM promotion_crowd_tasks WHERE run_id IN (SELECT id FROM promotion_runs WHERE project_id = ?)',
                'DELETE FROM promotion_nodes WHERE run_id IN (SELECT id FROM promotion_runs WHERE project_id = ?)',
                'DELETE FROM promotion_nodes WHERE publication_id IN (SELECT id FROM publications WHERE project_id = ?)',
                'DELETE FROM promotion_runs WHERE project_id = ?',
                'DELETE FROM publication_queue WHERE project_id = ?',
                'DELETE FROM publications WHERE project_id = ?',
                'DELETE FROM project_links WHERE project_id = ?',
                'DELETE FROM page_meta WHERE project_id = ?',
            ];

            foreach ($cleanupStatements as $sql) {
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('i', $projectId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok === false) {
                        throw new RuntimeException('Cleanup failed: ' . $conn->error, (int)$conn->errno);
                    }
                }
            }

            if ($isAdmin) {
                $stmt = $conn->prepare('DELETE FROM projects WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $projectId);
                    $execOk = $stmt->execute();
                    $deleteOk = $execOk && (($stmt->affected_rows ?? 0) > 0);
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?');
                if ($stmt) {
                    $stmt->bind_param('ii', $projectId, $userId);
                    $execOk = $stmt->execute();
                    $deleteOk = $execOk && (($stmt->affected_rows ?? 0) > 0);
                    $stmt->close();
                }
            }

            if ($transactionStarted) {
                if ($deleteOk) {
                    @$conn->commit();
                } else {
                    @$conn->rollback();
                }
                $transactionStarted = false;
            }
        } catch (Throwable $e) {
            if ($conn && $transactionStarted) {
                @$conn->rollback();
                $transactionStarted = false;
            }
            $deleteOk = false;
            $error = $e->getMessage();
            @error_log('[PromoPilot] Project delete failed for #' . $projectId . ': ' . $error);
        } finally {
            if ($conn) {
                $conn->close();
            }
        }

        return ['ok' => $deleteOk, 'error' => $deleteOk ? null : ($error ?: null)];
    }
}

if (!function_exists('pp_project_update_main_info')) {
    /**
     * Обновляет основную информацию о проекте.
     */
    function pp_project_update_main_info(int $projectId, array &$project, array $payload, array $options = []): array
    {
        $allowedLangs = $options['allowed_languages'] ?? ['ru', 'en', 'es', 'fr', 'de'];
        $availableRegions = $options['available_regions'] ?? [];
        $availableTopics = $options['available_topics'] ?? [];

        $newName = trim($payload['project_name'] ?? '');
        if ($newName === '') {
            return ['ok' => false, 'message' => __('Название не может быть пустым.')];
        }

        $newDesc = trim($payload['project_description'] ?? '');
        $newWishes = trim($payload['project_wishes'] ?? '');
        $newLang = trim($payload['project_language'] ?? (string)($project['language'] ?? 'ru'));
        $newRegion = trim((string)($payload['project_region'] ?? ($project['region'] ?? '')));
        $newTopic = trim((string)($payload['project_topic'] ?? ($project['topic'] ?? '')));

        if (!in_array($newLang, $allowedLangs, true)) {
            $newLang = (string)($project['language'] ?? 'ru');
        }

        if (!empty($availableRegions) && !in_array($newRegion, $availableRegions, true)) {
            $newRegion = $availableRegions[0];
        }

        if (!empty($availableTopics) && !in_array($newTopic, $availableTopics, true)) {
            $newTopic = $availableTopics[0];
        }

        $conn = connect_db();
        if (!$conn) {
            return ['ok' => false, 'message' => __('Ошибка сохранения основной информации.')];
        }

        $stmt = $conn->prepare('UPDATE projects SET name = ?, description = ?, wishes = ?, language = ?, region = ?, topic = ? WHERE id = ?');
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'message' => __('Ошибка сохранения основной информации.')];
        }

        $stmt->bind_param('ssssssi', $newName, $newDesc, $newWishes, $newLang, $newRegion, $newTopic, $projectId);
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();

        if (!$ok) {
            return ['ok' => false, 'message' => __('Ошибка сохранения основной информации.')];
        }

        $project['name'] = $newName;
        $project['description'] = $newDesc;
        $project['wishes'] = $newWishes;
        $project['language'] = $newLang;
        $project['region'] = $newRegion;
        $project['topic'] = $newTopic;

        return ['ok' => true, 'message' => __('Основная информация обновлена.')];
    }
}

if (!function_exists('pp_project_handle_links_update')) {
    /**
     * Обрабатывает обновление ссылок и пожеланий проекта.
     */
    function pp_project_handle_links_update(int $projectId, array &$project, array $request): array
    {
        $pp_update_ok = false;
        $domainErrors = 0;
        $domainToSet = '';
        $newLinkPayload = null;
        $message = '';

        $pp_is_valid_lang = static function ($code): bool {
            $code = trim((string)$code);
            if ($code === '') return false;
            if (strlen($code) > 10) return false;
            return (bool)preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $code);
        };

        $defaultLang = strtolower((string)($project['language'] ?? 'ru')) ?: 'ru';
        $projectHost = pp_normalize_host($project['domain_host'] ?? '');

        $conn = connect_db();
        if (!$conn) {
            return ['ok' => false, 'message' => __('Ошибка обновления проекта.'), 'domain_errors' => 0, 'domain_host' => (string)($project['domain_host'] ?? ''), 'links_count' => 0, 'new_link' => null];
        }

        try {
            // Удаление ссылок
            $removeIds = array_map('intval', (array)($request['remove_links'] ?? []));
            $removeIds = array_values(array_filter($removeIds, static fn($v) => $v > 0));
            if (!empty($removeIds)) {
                $ph = implode(',', array_fill(0, count($removeIds), '?'));
                $types = str_repeat('i', count($removeIds) + 1);
                $sql = "DELETE FROM project_links WHERE project_id = ? AND id IN ($ph)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $params = array_merge([$projectId], $removeIds);
                    $stmt->bind_param($types, ...$params);
                    @$stmt->execute();
                    $stmt->close();
                }
            }

            // Обновление существующих ссылок
            if (!empty($request['edited_links']) && is_array($request['edited_links'])) {
                foreach ($request['edited_links'] as $lid => $row) {
                    $linkId = (int)$lid;
                    if ($linkId <= 0) continue;
                    $url = trim((string)($row['url'] ?? ''));
                    $anchor = trim((string)($row['anchor'] ?? ''));
                    $lang = strtolower(trim((string)($row['language'] ?? $defaultLang)));
                    $wish = trim((string)($row['wish'] ?? ''));
                    if ($lang === 'auto' || !$pp_is_valid_lang($lang)) { $lang = $defaultLang; }
                    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                        $uHost = pp_normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
                        if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                            $domainErrors++;
                            continue;
                        }
                        $st = $conn->prepare('UPDATE project_links SET url = ?, anchor = ?, language = ?, wish = ? WHERE id = ? AND project_id = ?');
                        if ($st) {
                            $st->bind_param('ssssii', $url, $anchor, $lang, $wish, $linkId, $projectId);
                            @$st->execute();
                            $st->close();
                        }
                        if ($projectHost === '' && $uHost !== '') {
                            $domainToSet = $uHost;
                            $projectHost = $uHost;
                        }
                    }
                }
            }

            // Добавление новых ссылок (bulk)
            if (!empty($request['added_links']) && is_array($request['added_links'])) {
                foreach ($request['added_links'] as $row) {
                    if (!is_array($row)) continue;
                    $url = trim((string)($row['url'] ?? ''));
                    $anchor = trim((string)($row['anchor'] ?? ''));
                    $lang = strtolower(trim((string)($row['language'] ?? $defaultLang)));
                    $wish = trim((string)($row['wish'] ?? ''));
                    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                        $uHost = pp_normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
                        if ($projectHost === '') { $domainToSet = $uHost; $projectHost = $uHost; }
                        if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                            $domainErrors++;
                            continue;
                        }
                        $meta = null;
                        if ($lang === 'auto' || !$pp_is_valid_lang($lang)) {
                            if (function_exists('pp_analyze_url_data')) {
                                try { $meta = pp_analyze_url_data($url); } catch (Throwable $e) { $meta = null; }
                                $detected = '';
                                if (is_array($meta)) {
                                    $detected = strtolower(trim((string)($meta['lang'] ?? '')));
                                    if ($detected === '' && !empty($meta['hreflang']) && is_array($meta['hreflang'])) {
                                        foreach ($meta['hreflang'] as $hl) {
                                            $h = strtolower(trim((string)($hl['hreflang'] ?? '')));
                                            if ($h === $defaultLang || strpos($h, $defaultLang . '-') === 0) { $detected = $h; break; }
                                        }
                                        if ($detected === '' && isset($meta['hreflang'][0]['hreflang'])) {
                                            $detected = strtolower(trim((string)$meta['hreflang'][0]['hreflang']));
                                        }
                                    }
                                }
                                if ($detected !== '' && $pp_is_valid_lang($detected)) { $lang = $detected; }
                                else { $lang = $defaultLang; }
                            } else {
                                $lang = $defaultLang;
                            }
                        }
                        $lang = substr($lang, 0, 10);

                        $ins = $conn->prepare('INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)');
                        if ($ins) {
                            $ins->bind_param('issss', $projectId, $url, $anchor, $lang, $wish);
                            if (@$ins->execute()) {
                                $newId = (int)$conn->insert_id;
                                $newLinkPayload = ['id' => $newId, 'url' => $url, 'anchor' => $anchor, 'language' => $lang, 'wish' => $wish];
                                try {
                                    if (function_exists('pp_save_page_meta')) {
                                        if (!is_array($meta) && function_exists('pp_analyze_url_data')) { $meta = pp_analyze_url_data($url); }
                                        if (is_array($meta)) { @pp_save_page_meta($projectId, $url, $meta); }
                                    }
                                } catch (Throwable $e) {
                                    // ignore meta errors
                                }
                            }
                            $ins->close();
                        }
                    }
                }
            } else {
                // Наследие: одиночное добавление
                $new_link = trim($request['new_link'] ?? '');
                $new_anchor = trim($request['new_anchor'] ?? '');
                $new_language = strtolower(trim($request['new_language'] ?? $defaultLang));
                $new_wish = trim($request['new_wish'] ?? '');
                if ($new_link && filter_var($new_link, FILTER_VALIDATE_URL)) {
                    $uHost = pp_normalize_host(parse_url($new_link, PHP_URL_HOST) ?: '');
                    if ($projectHost === '') { $domainToSet = $uHost; $projectHost = $uHost; }
                    if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                        $domainErrors++;
                    } else {
                        $meta = null;
                        if ($new_language === 'auto' || !$pp_is_valid_lang($new_language)) {
                            if (function_exists('pp_analyze_url_data')) {
                                try { $meta = pp_analyze_url_data($new_link); } catch (Throwable $e) { $meta = null; }
                                $detected = '';
                                if (is_array($meta)) {
                                    $detected = strtolower(trim((string)($meta['lang'] ?? '')));
                                    if ($detected === '' && !empty($meta['hreflang']) && is_array($meta['hreflang'])) {
                                        foreach ($meta['hreflang'] as $hl) {
                                            $h = strtolower(trim((string)($hl['hreflang'] ?? '')));
                                            if ($h === $defaultLang || strpos($h, $defaultLang . '-') === 0) { $detected = $h; break; }
                                        }
                                        if ($detected === '' && isset($meta['hreflang'][0]['hreflang'])) {
                                            $detected = strtolower(trim((string)$meta['hreflang'][0]['hreflang']));
                                        }
                                    }
                                }
                                if ($detected !== '' && $pp_is_valid_lang($detected)) { $new_language = $detected; }
                                else { $new_language = $defaultLang; }
                            } else {
                                $new_language = $defaultLang;
                            }
                        }
                        $new_language = substr($new_language, 0, 10);

                        $ins = $conn->prepare('INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)');
                        if ($ins) {
                            $ins->bind_param('issss', $projectId, $new_link, $new_anchor, $new_language, $new_wish);
                            if (@$ins->execute()) {
                                $newId = (int)$conn->insert_id;
                                $newLinkPayload = ['id' => $newId, 'url' => $new_link, 'anchor' => $new_anchor, 'language' => $new_language, 'wish' => $new_wish];
                                try {
                                    if (function_exists('pp_save_page_meta')) {
                                        if (!is_array($meta) && function_exists('pp_analyze_url_data')) { $meta = pp_analyze_url_data($new_link); }
                                        if (is_array($meta)) { @pp_save_page_meta($projectId, $new_link, $meta); }
                                    }
                                } catch (Throwable $e) {
                                    // ignore meta errors
                                }
                            }
                            $ins->close();
                        }
                    }
                }
            }

            if (isset($request['wishes'])) {
                $wishes = trim((string)$request['wishes']);
            } else {
                $wishes = (string)($project['wishes'] ?? '');
            }
            $language = $project['language'] ?? 'ru';

            if ($domainToSet !== '') {
                $stmt = $conn->prepare('UPDATE projects SET wishes = ?, language = ?, domain_host = ? WHERE id = ?');
                $stmt->bind_param('sssi', $wishes, $language, $domainToSet, $projectId);
                $project['domain_host'] = $domainToSet;
            } else {
                $stmt = $conn->prepare('UPDATE projects SET wishes = ?, language = ? WHERE id = ?');
                $stmt->bind_param('ssi', $wishes, $language, $projectId);
            }

            if ($stmt) {
                if ($stmt->execute()) {
                    $pp_update_ok = true;
                    $message = __('Проект обновлен.');
                    if ($domainErrors > 0) {
                        $message .= ' ' . sprintf(__('Отклонено ссылок с другим доменом: %d.'), $domainErrors);
                    }
                    $project['language'] = $language;
                    $project['wishes'] = $wishes;
                } else {
                    $message = __('Ошибка обновления проекта.');
                }
                $stmt->close();
            }

            if ($cst = $conn->prepare('SELECT COUNT(*) FROM project_links WHERE project_id = ?')) {
                $cst->bind_param('i', $projectId);
                $cst->execute();
                $cst->bind_result($linksCount);
                $cst->fetch();
                $cst->close();
            }
        } finally {
            $conn->close();
        }

        return [
            'ok' => (bool)$pp_update_ok,
            'message' => (string)$message,
            'domain_errors' => (int)$domainErrors,
            'domain_host' => (string)($project['domain_host'] ?? ''),
            'links_count' => (int)($linksCount ?? 0),
            'new_link' => $newLinkPayload,
        ];
    }
}

if (!function_exists('pp_project_publication_statuses')) {
    /**
     * Возвращает статусы публикаций для ссылок проекта.
     */
    function pp_project_publication_statuses(int $projectId): array
    {
        $status = [];
        try {
            $conn = connect_db();
            if (!$conn) {
                return $status;
            }
            $stmt = $conn->prepare('SELECT page_url, post_url, network, status FROM publications WHERE project_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $projectId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $url = (string)($row['page_url'] ?? '');
                    if ($url === '') { continue; }
                    $postUrl = (string)($row['post_url'] ?? '');
                    $st = trim((string)($row['status'] ?? ''));
                    $mapped = 'not_published';
                    if ($st === 'partial') {
                        $mapped = 'manual_review';
                    } elseif ($postUrl !== '' || $st === 'success') {
                        $mapped = 'published';
                    } elseif ($st === 'failed' || $st === 'cancelled') {
                        $mapped = 'not_published';
                    } elseif ($st === 'queued' || $st === 'running') {
                        $mapped = 'pending';
                    }
                    $info = [
                        'status' => $mapped,
                        'post_url' => $postUrl,
                        'network' => trim((string)($row['network'] ?? '')),
                    ];
                    if (!isset($status[$url]) || $mapped === 'published') {
                        $status[$url] = $info;
                    }
                }
                $stmt->close();
            }
            $conn->close();
        } catch (Throwable $e) {
            // ignore
        }

        return $status;
    }
}
