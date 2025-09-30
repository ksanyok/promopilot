<?php
// Networks registry and helpers (scan descriptors, list, taxonomy, enable/notes)

if (!function_exists('pp_networks_dir')) {
    function pp_networks_dir(): string {
        $dir = PP_ROOT_PATH . '/networks'; if (!is_dir($dir)) { @mkdir($dir, 0775, true); } return $dir;
    }
}

if (!function_exists('pp_normalize_slug')) {
    function pp_normalize_slug(string $slug): string { $slug = strtolower(trim($slug)); $slug = preg_replace('~[^a-z0-9_\-]+~', '-', $slug); return trim($slug, '-_'); }
}
if (!function_exists('pp_path_to_relative')) {
    function pp_path_to_relative(string $path): string {
        $path = str_replace(['\\', '\\'], '/', $path); $root = str_replace(['\\', '\\'], '/', PP_ROOT_PATH);
        if (strpos($path, $root) === 0) { $rel = ltrim(substr($path, strlen($root)), '/'); return $rel === '' ? '.' : $rel; }
        return $path;
    }
}

if (!function_exists('pp_network_descriptor_from_file')) {
    function pp_network_descriptor_from_file(string $file): ?array {
        if (!is_file($file)) { return null; }
        try { $descriptor = @include $file; } catch (Throwable $e) { return null; }
        if (!is_array($descriptor)) { return null; }
        $descriptor['slug'] = pp_normalize_slug((string)($descriptor['slug'] ?? ''));
        if ($descriptor['slug'] === '') { return null; }
        $descriptor['title'] = trim((string)($descriptor['title'] ?? ucfirst($descriptor['slug'])));
        $descriptor['description'] = trim((string)($descriptor['description'] ?? ''));
        $descriptor['handler'] = trim((string)($descriptor['handler'] ?? ''));
        if ($descriptor['handler'] === '') { return null; }
        $handler = $descriptor['handler']; $isAbsolute = preg_match('~^([a-zA-Z]:[\\/]|/)~', $handler) === 1;
        if (!$isAbsolute) { $handler = realpath(dirname($file) . '/' . $handler) ?: (dirname($file) . '/' . $handler); }
        $handlerAbs = realpath($handler) ?: $handler;
        $descriptor['handler_type'] = strtolower(trim((string)($descriptor['handler_type'] ?? 'node')));
        $descriptor['enabled'] = isset($descriptor['enabled']) ? (bool)$descriptor['enabled'] : true;
        $descriptor['meta'] = is_array($descriptor['meta'] ?? null) ? $descriptor['meta'] : [];
        $descriptor['source_file'] = $file; $descriptor['handler_abs'] = $handlerAbs; $descriptor['handler_rel'] = pp_path_to_relative($handlerAbs);
        return $descriptor;
    }
}

if (!function_exists('pp_refresh_networks')) {
    function pp_refresh_networks(bool $force = false): array {
        if (!$force) { $last = (int)get_setting('networks_last_refresh', 0); if ($last && (time() - $last) < 300) { return pp_get_networks(false, true); } }
        $dir = pp_networks_dir(); $files = glob($dir . '/*.php') ?: []; $descriptors = [];
        foreach ($files as $file) { $d = pp_network_descriptor_from_file($file); if ($d) { $descriptors[$d['slug']] = $d; } }
        try { $conn = @connect_db(); } catch (Throwable $e) { $conn = null; }
        if (!$conn) { return array_values($descriptors); }

        $existing = [];
        if ($res = @$conn->query('SELECT slug, enabled, priority, level, notes FROM networks')) {
            while ($row = $res->fetch_assoc()) {
                $existing[$row['slug']] = [
                    'enabled' => (int)($row['enabled'] ?? 0),
                    'priority' => (int)($row['priority'] ?? 0),
                    'level' => (string)($row['level'] ?? ''),
                    'notes' => (string)($row['notes'] ?? ''),
                ];
            }
            $res->free();
        }
        $defaultPrioritySetting = (int)get_setting('network_default_priority', 10);
        if ($defaultPrioritySetting < 0) { $defaultPrioritySetting = 0; }
        if ($defaultPrioritySetting > 999) { $defaultPrioritySetting = 999; }
        $defaultLevelsSetting = pp_normalize_network_levels(get_setting('network_default_levels', ''));

        $stmt = $conn->prepare('INSERT INTO networks (slug, title, description, handler, handler_type, meta, regions, topics, enabled, priority, level, notes, is_missing) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), handler = VALUES(handler), handler_type = VALUES(handler_type), meta = VALUES(meta), regions = VALUES(regions), topics = VALUES(topics), is_missing = 0, updated_at = CURRENT_TIMESTAMP');
        if ($stmt) {
            foreach ($descriptors as $slug => $descriptor) {
                $enabled = $descriptor['enabled'] ? 1 : 0;
                $priority = (int)($descriptor['priority'] ?? 0);
                $level = trim((string)($descriptor['level'] ?? ''));
                $notes = '';
                if (array_key_exists($slug, $existing)) {
                    $enabled = (int)$existing[$slug]['enabled'];
                    $priority = (int)$existing[$slug]['priority'];
                    $level = (string)$existing[$slug]['level'];
                    $notes = (string)$existing[$slug]['notes'];
                } else {
                    $priority = $defaultPrioritySetting; $level = $defaultLevelsSetting;
                }
                if ($priority < 0) { $priority = 0; } if ($priority > 999) { $priority = 999; }
                $level = pp_normalize_network_levels($level);
                if ($notes !== '') { $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 2000, 'UTF-8') : substr($notes, 0, 2000); }
                $metaJson = json_encode($descriptor['meta'], JSON_UNESCAPED_UNICODE);
                $regionsArr = []; $topicsArr = []; $meta = $descriptor['meta'] ?? [];
                $rawRegions = $meta['regions'] ?? []; if (is_string($rawRegions)) { $rawRegions = [$rawRegions]; }
                if (is_array($rawRegions)) { foreach ($rawRegions as $reg) { $val = trim((string)$reg); if ($val !== '') { $regionsArr[$val] = $val; } } }
                $rawTopics = $meta['topics'] ?? []; if (is_string($rawTopics)) { $rawTopics = [$rawTopics]; }
                if (is_array($rawTopics)) { foreach ($rawTopics as $topic) { $val = trim((string)$topic); if ($val !== '') { $topicsArr[$val] = $val; } } }
                $regionsStr = implode(', ', array_values($regionsArr)); $topicsStr = implode(', ', array_values($topicsArr));
                $stmt->bind_param('ssssssssiiss', $descriptor['slug'], $descriptor['title'], $descriptor['description'], $descriptor['handler_rel'], $descriptor['handler_type'], $metaJson, $regionsStr, $topicsStr, $enabled, $priority, $level, $notes);
                $stmt->execute();
            }
            $stmt->close();
        }

        $knownSlugs = array_keys($descriptors);
        if (!empty($knownSlugs)) {
            $placeholders = implode(',', array_fill(0, count($knownSlugs), '?'));
            $query = $conn->prepare("UPDATE networks SET is_missing = 1, enabled = 0 WHERE slug NOT IN ($placeholders)");
            if ($query) { $types = str_repeat('s', count($knownSlugs)); $query->bind_param($types, ...$knownSlugs); $query->execute(); $query->close(); }
        } else { @$conn->query('UPDATE networks SET is_missing = 1, enabled = 0'); }

        $conn->close(); set_setting('networks_last_refresh', (string)time());
        return array_values($descriptors);
    }
}

if (!function_exists('pp_get_networks')) {
    function pp_get_networks(bool $onlyEnabled = false, bool $includeMissing = false): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return []; }
        if (!$conn) { return []; }
        $where = []; if ($onlyEnabled) { $where[] = 'enabled = 1'; } if (!$includeMissing) { $where[] = 'is_missing = 0'; }
        $sql = 'SELECT slug, title, description, handler, handler_type, meta, regions, topics, enabled, priority, level, notes, is_missing, last_check_status, last_check_run_id, last_check_started_at, last_check_finished_at, last_check_url, last_check_error, last_check_updated_at, created_at, updated_at FROM networks';
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY priority DESC, title ASC';
        $rows = [];
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $rel = (string)$row['handler']; $isAbsolute = preg_match('~^([a-zA-Z]:[\\/]|/)~', $rel) === 1;
                if ($rel === '.') { $abs = PP_ROOT_PATH; } elseif ($isAbsolute) { $abs = $rel; } else { $abs = PP_ROOT_PATH . '/' . ltrim($rel, '/'); }
                $absReal = realpath($abs); if ($absReal) { $abs = $absReal; }
                $regionsRaw = (string)($row['regions'] ?? ''); $topicsRaw = (string)($row['topics'] ?? '');
                $regionsList = array_values(array_filter(array_map(function($item){ return trim((string)$item); }, preg_split('~[,;\n]+~', $regionsRaw) ?: [])));
                $topicsList = array_values(array_filter(array_map(function($item){ return trim((string)$item); }, preg_split('~[,;\n]+~', $topicsRaw) ?: [])));
                $rows[] = [
                    'slug' => (string)$row['slug'], 'title' => (string)$row['title'], 'description' => (string)$row['description'],
                    'handler' => $rel, 'handler_abs' => $abs, 'handler_type' => (string)$row['handler_type'],
                    'meta' => json_decode((string)($row['meta'] ?? ''), true) ?: [],
                    'regions_raw' => $regionsRaw, 'topics_raw' => $topicsRaw, 'regions' => $regionsList, 'topics' => $topicsList,
                    'enabled' => (bool)$row['enabled'], 'priority' => (int)($row['priority'] ?? 0), 'level' => trim((string)($row['level'] ?? '')),
                    'notes' => (string)($row['notes'] ?? ''), 'is_missing' => (bool)$row['is_missing'],
                    'last_check_status' => $row['last_check_status'] !== null ? (string)$row['last_check_status'] : null,
                    'last_check_run_id' => $row['last_check_run_id'] !== null ? (int)$row['last_check_run_id'] : null,
                    'last_check_started_at' => $row['last_check_started_at'], 'last_check_finished_at' => $row['last_check_finished_at'],
                    'last_check_url' => (string)($row['last_check_url'] ?? ''), 'last_check_error' => (string)($row['last_check_error'] ?? ''),
                    'last_check_updated_at' => $row['last_check_updated_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
                ];
            }
            $res->free();
        }
        $conn->close(); return $rows;
    }
}

if (!function_exists('pp_get_network')) {
    function pp_get_network(string $slug): ?array { $slug = pp_normalize_slug($slug); foreach (pp_get_networks(false, true) as $n) { if (($n['slug'] ?? '') === $slug) return $n; } return null; }
}

if (!function_exists('pp_get_network_taxonomy')) {
    function pp_get_network_taxonomy(bool $onlyEnabled = true): array {
        $nets = pp_get_networks($onlyEnabled, true); $regions = []; $topics = []; $canon = function(string $s): string { return trim((string)$s); };
        foreach ($nets as $n) {
            $meta = $n['meta'] ?? [];
            $rs = $meta['regions'] ?? []; if (is_string($rs)) { $rs = [$rs]; }
            if (is_array($rs)) { foreach ($rs as $r) { $r = $canon((string)$r); if ($r !== '') { $regions[$r] = true; } } }
            $ts = $meta['topics'] ?? []; if (is_string($ts)) { $ts = [$ts]; }
            if (is_array($ts)) { foreach ($ts as $t) { $t = $canon((string)$t); if ($t !== '') { $topics[$t] = true; } } }
        }
        $rList = array_keys($regions); sort($rList, SORT_NATURAL | SORT_FLAG_CASE);
        $tList = array_keys($topics); sort($tList, SORT_NATURAL | SORT_FLAG_CASE);
        return ['regions' => $rList, 'topics' => $tList];
    }
}

if (!function_exists('pp_normalize_network_levels')) {
    function pp_normalize_network_levels($value): string {
        $rawList = [];
        if (is_array($value)) { $rawList = $value; }
        else { $str = (string)$value; if ($str !== '') { $rawList = preg_split('~[\s,;/]+~u', $str) ?: []; } }
        $levels = []; foreach ($rawList as $item) { if (!is_scalar($item)) { continue; } $token = trim((string)$item); if ($token === '') { continue; } if (preg_match('~([1-3])~', $token, $m)) { $lvl = $m[1]; $levels[$lvl] = $lvl; } }
        if (empty($levels)) { return ''; } ksort($levels, SORT_NUMERIC); return implode(',', array_values($levels));
    }
}

if (!function_exists('pp_set_networks_enabled')) {
    function pp_set_networks_enabled(array $slugsToEnable, array $priorityMap = [], array $levelMap = []): bool {
        $enabledMap = []; foreach ($slugsToEnable as $slug) { $norm = pp_normalize_slug((string)$slug); if ($norm !== '') { $enabledMap[$norm] = true; } }
        $priorityNorm = []; foreach ($priorityMap as $slug => $value) { $norm = pp_normalize_slug((string)$slug); if ($norm === '') { continue; } $priorityNorm[$norm] = max(0, (int)$value); }
        $levelNorm = [];
        foreach ($levelMap as $slug => $value) {
            $norm = pp_normalize_slug((string)$slug); if ($norm === '') { continue; }
            $str = pp_normalize_network_levels($value);
            if ($str !== '') {
                if (function_exists('mb_strlen')) { if (mb_strlen($str, 'UTF-8') > 50) { $str = mb_substr($str, 0, 50, 'UTF-8'); } }
                elseif (strlen($str) > 50) { $str = substr($str, 0, 50); }
            }
            $levelNorm[$norm] = $str;
        }
        try { $conn = @connect_db(); } catch (Throwable $e) { return false; }
        if (!$conn) { return false; }
        $stmt = $conn->prepare('UPDATE networks SET enabled = ?, priority = ?, level = ? WHERE slug = ?'); if (!$stmt) { $conn->close(); return false; }
        $slugs = []; if ($res = @$conn->query('SELECT slug FROM networks')) { while ($row = $res->fetch_assoc()) { $slugs[] = (string)$row['slug']; } $res->free(); }
        foreach ($slugs as $slug) { $enabled = isset($enabledMap[$slug]) ? 1 : 0; $priority = $priorityNorm[$slug] ?? 0; $level = $levelNorm[$slug] ?? ''; $stmt->bind_param('iiss', $enabled, $priority, $level, $slug); $stmt->execute(); }
        $stmt->close(); $conn->close(); return true;
    }
}

if (!function_exists('pp_set_network_note')) {
    function pp_set_network_note(string $slug, string $note): bool {
        $slug = pp_normalize_slug($slug); if ($slug === '') { return false; }
        $note = trim($note); if ($note !== '') { $note = function_exists('mb_substr') ? mb_substr($note, 0, 2000, 'UTF-8') : substr($note, 0, 2000); }
        try { $conn = @connect_db(); } catch (Throwable $e) { return false; } if (!$conn) { return false; }
        $ok = false; if ($stmt = $conn->prepare('UPDATE networks SET notes = ?, updated_at = CURRENT_TIMESTAMP WHERE slug = ? LIMIT 1')) { $stmt->bind_param('ss', $note, $slug); if ($stmt->execute()) { $ok = $stmt->affected_rows >= 0; } $stmt->close(); }
        $conn->close(); return $ok;
    }
}

if (!function_exists('pp_delete_network')) {
    function pp_delete_network(string $slug): bool {
        $slug = pp_normalize_slug($slug); if ($slug === '') { return false; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return false; } if (!$conn) { return false; }
        $isMissing = 0; if ($stmt = $conn->prepare('SELECT is_missing FROM networks WHERE slug = ? LIMIT 1')) { $stmt->bind_param('s', $slug); if ($stmt->execute()) { $stmt->bind_result($missingFlag); if ($stmt->fetch()) { $isMissing = (int)$missingFlag; } else { $stmt->close(); $conn->close(); return false; } } $stmt->close(); } else { $conn->close(); return false; }
        if ($isMissing !== 1) { $conn->close(); return false; }
        $deleted = false; if ($del = $conn->prepare('DELETE FROM networks WHERE slug = ? LIMIT 1')) { $del->bind_param('s', $slug); $del->execute(); $deleted = $del->affected_rows > 0; $del->close(); }
        if ($deleted) { if ($clean = $conn->prepare('DELETE FROM network_check_results WHERE network_slug = ?')) { $clean->bind_param('s', $slug); $clean->execute(); $clean->close(); } }
        $conn->close(); return $deleted;
    }
}

// Picking helpers
if (!function_exists('pp_pick_network_for')) {
    function pp_pick_network_for(?string $region, ?string $topic): ?array {
        $nets = pp_get_networks(true, false); if (empty($nets)) return null;
        $region = trim((string)$region); $topic  = trim((string)$topic);
        $norm = function(string $s): string { return preg_replace('~\s+~', ' ', strtolower(trim($s))); };
        $projectRegion = $norm($region); $projectTopic  = $norm($topic);
        $scored = [];
        foreach ($nets as $n) {
            $meta = $n['meta'] ?? []; $regions = $meta['regions'] ?? []; $topics  = $meta['topics'] ?? [];
            if (is_string($regions)) { $regions = [$regions]; } if (is_string($topics)) { $topics = [$topics]; }
            $regions = array_map($norm, is_array($regions) ? $regions : []); $topics  = array_map($norm, is_array($topics) ? $topics : []);
            $rScore = 0; if ($projectRegion === '') { $rScore = 1; } else { if (in_array($projectRegion, $regions, true)) { $rScore = 2; } elseif (in_array('global', $regions, true)) { $rScore = 1; } else { $rScore = 0; } }
            $tScore = 0; if ($projectTopic === '') { $tScore = 1; } else { if (in_array($projectTopic, $topics, true)) { $tScore = 2; } elseif (in_array('general', $topics, true)) { $tScore = 1; } else { $tScore = 0; } }
            $priority = (int)($n['priority'] ?? 0); $score = ($priority * 1000) + ($rScore * 10) + $tScore;
            $scored[] = ['score' => $score, 'net' => $n, 'title' => (string)($n['title'] ?? $n['slug'])];
        }
        if (empty($scored)) return null; usort($scored, function($a, $b){ if ($a['score'] === $b['score']) { return strnatcasecmp($a['title'], $b['title']); } return $a['score'] < $b['score'] ? 1 : -1; });
        $best = $scored[0]; if ((int)$best['score'] <= 0) { return $nets[0]; } return $best['net'];
    }
}
if (!function_exists('pp_pick_network')) {
    function pp_pick_network(): ?array {
        $nets = pp_get_networks(true, false); if (empty($nets)) { return null; }
        $weights = []; $total = 0; foreach ($nets as $net) { $priority = (int)($net['priority'] ?? 0); $weight = $priority > 0 ? $priority : 1; $weights[] = $weight; $total += $weight; }
        if ($total <= 0) { return $nets[array_rand($nets)]; }
        try { $target = random_int(1, $total); } catch (Throwable $e) { $target = mt_rand(1, max(1, $total)); }
        foreach ($nets as $idx => $net) { $target -= $weights[$idx]; if ($target <= 0) { return $net; } }
        return $nets[array_rand($nets)];
    }
}

?>
