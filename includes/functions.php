<?php
// Bootstrap and module loader for PromoPilot helpers

// Language (default ru)
$current_lang = $_SESSION['lang'] ?? 'ru';

// Ensure root path constant exists for reliable includes
if (!defined('PP_ROOT_PATH')) {
    define('PP_ROOT_PATH', realpath(__DIR__ . '/..'));
}

// Load modular helpers
// These files are guarded via function_exists checks and can be included repeatedly.
require_once __DIR__ . '/runtime.php';         // Node/Chrome and runner helpers
require_once __DIR__ . '/network_check.php';   // Network diagnostics helpers
require_once __DIR__ . '/core.php';            // Core (i18n, csrf, auth, base url, small utils)
require_once __DIR__ . '/db.php';              // DB, settings, currency, avatars
require_once __DIR__ . '/notifications.php';   // User notification preferences
require_once __DIR__ . '/mailer.php';          // Email sending helper
require_once __DIR__ . '/balance.php';         // Balance logging & notifications
require_once __DIR__ . '/payments.php';        // Payment gateways and transactions
require_once __DIR__ . '/networks.php';        // Networks registry and utilities
require_once __DIR__ . '/crowd_links.php';     // Crowd marketing links management
require_once __DIR__ . '/crowd_deep.php';      // Crowd deep submission verification
require_once __DIR__ . '/page_meta.php';       // Page meta + URL analysis helpers
require_once __DIR__ . '/project_brief.php';   // Project brief (AI-assisted naming)
require_once __DIR__ . '/publication_queue.php'; // Publication queue processing
require_once __DIR__ . '/update.php';          // Version and update checks
require_once __DIR__ . '/schema_bootstrap.php'; // Schema migrations bootstrap

// Load translations when not RU
if ($current_lang != 'ru') {
    $langFile = PP_ROOT_PATH . '/lang/' . basename($current_lang) . '.php';
    if (file_exists($langFile)) { include $langFile; }
}

// Ensure DB schema has required columns/tables
if (!function_exists('ensure_schema')) {
function ensure_schema(): void {
    pp_run_schema_bootstrap();
}
}
// The rest of the file keeps ensure_schema and domain-specific models.
    function pp_referral_cookie_name() {
        return 'pp_ref';
    }

    function pp_referral_set_cookie($code) {
        $days = (int)get_setting('referral_cookie_days', '30');
        if ($days <= 0) { $days = 30; }
        $expire = time() + ($days * 24 * 60 * 60);
        setcookie(pp_referral_cookie_name(), $code, $expire, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', true);
    }

    function pp_referral_get_cookie() {
        return isset($_COOKIE[pp_referral_cookie_name()]) ? trim($_COOKIE[pp_referral_cookie_name()]) : '';
    }

    function pp_referral_clear_cookie() {
        setcookie(pp_referral_cookie_name(), '', time() - 3600, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', true);
    }

    function pp_referral_capture_from_request() {
        if (!empty($_GET['ref'])) {
            $code = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['ref']);
            if ($code !== '') {
                pp_referral_set_cookie($code);
                // Log click once per session per code
                if (empty($_SESSION['pp_ref_click_logged']) || $_SESSION['pp_ref_click_logged'] !== $code) {
                    $_SESSION['pp_ref_click_logged'] = $code;
                    try { $conn = @connect_db(); if ($conn) { pp_referral_log_event_click($conn, $code); $conn->close(); } } catch (Throwable $e) { /* ignore */ }
                }
            }
        }
    }

    function pp_referral_log_event_click(mysqli $conn, string $code): void {
        $referrerId = 0;
        $st = $conn->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
        if ($st) { $st->bind_param('s', $code); $st->execute(); $r = $st->get_result(); $row = $r ? $r->fetch_assoc() : null; if ($r) $r->free(); $st->close(); if ($row) { $referrerId = (int)$row['id']; } }
        if ($referrerId <= 0) { return; }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $meta = json_encode(['ua' => $ua, 'ip' => $ip, 'referer' => $ref], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $ins = $conn->prepare("INSERT INTO referral_events (referrer_user_id, code, user_id, type, meta_json, created_at) VALUES (?, ?, NULL, 'click', ?, CURRENT_TIMESTAMP)");
        if ($ins) { $ins->bind_param('iss', $referrerId, $code, $meta); $ins->execute(); $ins->close(); }
    }

    function pp_referral_assign_user_if_needed($conn, $userId) {
        // Assign referred_by to user if not already set and cookie has a valid code
        $code = pp_referral_get_cookie();
        if ($code === '') return;
        $stmt = $conn->prepare("SELECT referred_by FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) return;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        if (!$row || (int)$row['referred_by'] > 0) return;
        $fs = $conn->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
        if (!$fs) return;
        $fs->bind_param('s', $code);
        $fs->execute();
        $fr = $fs->get_result();
        $refUser = $fr ? $fr->fetch_assoc() : null;
        if ($fr) $fr->free();
        $fs->close();
        if (!$refUser) return;
        $rid = (int)$refUser['id'];
        if ($rid === $userId) return; // prevent self-referral
        $us = $conn->prepare("UPDATE users SET referred_by = ? WHERE id = ? AND (referred_by IS NULL OR referred_by = 0)");
        if ($us) { $us->bind_param('ii', $rid, $userId); $us->execute(); $us->close(); }
        // Log signup event
    try { $meta = json_encode([], JSON_UNESCAPED_UNICODE) ?: '{}'; $ev = $conn->prepare("INSERT INTO referral_events (referrer_user_id, code, user_id, type, meta_json, created_at) VALUES (?, ?, ?, 'signup', ?, CURRENT_TIMESTAMP)"); if ($ev) { $ev->bind_param('isis', $rid, $code, $userId, $meta); $ev->execute(); $ev->close(); } } catch (Throwable $e) { /* ignore */ }
    }

    function pp_referral_get_or_create_user_code($conn, $userId) {
        $code = '';
        $st = $conn->prepare("SELECT referral_code FROM users WHERE id = ? LIMIT 1");
        if ($st) { $st->bind_param('i', $userId); $st->execute(); $r = $st->get_result(); if ($r) { $row = $r->fetch_assoc(); if ($row && !empty($row['referral_code'])) { $code = $row['referral_code']; } $r->free(); } $st->close(); }
        if ($code !== '') return $code;
        // Generate a unique code
        for ($i = 0; $i < 5; $i++) {
            $candidate = substr(strtoupper(bin2hex(random_bytes(6))), 0, 10);
            $ck = $conn->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
            if ($ck) { $ck->bind_param('s', $candidate); $ck->execute(); $cr = $ck->get_result(); $exists = $cr && $cr->fetch_assoc(); if ($cr) $cr->free(); $ck->close(); if (!$exists) { $code = $candidate; break; } }
        }
        if ($code === '') { $code = (string)$userId . 'R'; }
        $us = $conn->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
        if ($us) { $us->bind_param('si', $code, $userId); $us->execute(); $us->close(); }
        return $code;
    }


// ---------- Publication networks helpers ----------

// Networks helpers moved to includes/networks.php

// New: aggregate networks taxonomy (regions/topics)
// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php
if (!function_exists('pp_refresh_networks')) {
function pp_refresh_networks(bool $force = false): array {
    if (!$force) {
        $last = (int)get_setting('networks_last_refresh', 0);
        if ($last && (time() - $last) < 300) {
            return pp_get_networks(false, true);
        }
    }

    $dir = pp_networks_dir();
    $files = glob($dir . '/*.php') ?: [];
    $descriptors = [];
    foreach ($files as $file) {
        $descriptor = pp_network_descriptor_from_file($file);
        if ($descriptor) {
            $descriptors[$descriptor['slug']] = $descriptor;
        }
    }

    $conn = null;
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        $conn = null;
    }
    if (!$conn) { return array_values($descriptors); }

    // snapshot existing enabled flags
    $existing = [];
    if ($res = @$conn->query("SELECT slug, enabled, priority, level, notes FROM networks")) {
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

    $stmt = $conn->prepare("INSERT INTO networks (slug, title, description, handler, handler_type, meta, regions, topics, enabled, priority, level, notes, is_missing) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), handler = VALUES(handler), handler_type = VALUES(handler_type), meta = VALUES(meta), regions = VALUES(regions), topics = VALUES(topics), is_missing = 0, updated_at = CURRENT_TIMESTAMP");
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
            } else { $priority = $defaultPrioritySetting; $level = $defaultLevelsSetting; }
            if ($priority < 0) { $priority = 0; }
            if ($priority > 999) { $priority = 999; }
            $level = pp_normalize_network_levels($level);
            if ($notes !== '') { $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 2000, 'UTF-8') : substr($notes, 0, 2000); }
            $metaJson = json_encode($descriptor['meta'], JSON_UNESCAPED_UNICODE);
            $regionsArr = [];
            $topicsArr = [];
            $meta = $descriptor['meta'] ?? [];
            $rawRegions = $meta['regions'] ?? [];
            if (is_string($rawRegions)) { $rawRegions = [$rawRegions]; }
            if (is_array($rawRegions)) { foreach ($rawRegions as $reg) { $val = trim((string)$reg); if ($val !== '') { $regionsArr[$val] = $val; } } }
            $rawTopics = $meta['topics'] ?? [];
            if (is_string($rawTopics)) { $rawTopics = [$rawTopics]; }
            if (is_array($rawTopics)) { foreach ($rawTopics as $topic) { $val = trim((string)$topic); if ($val !== '') { $topicsArr[$val] = $val; } } }
            $regionsStr = implode(', ', array_values($regionsArr));
            $topicsStr = implode(', ', array_values($topicsArr));
            $stmt->bind_param(
                'ssssssssiiss',
                $descriptor['slug'],
                $descriptor['title'],
                $descriptor['description'],
                $descriptor['handler_rel'],
                $descriptor['handler_type'],
                $metaJson,
                $regionsStr,
                $topicsStr,
                $enabled,
                $priority,
                $level,
                $notes
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    $knownSlugs = array_keys($descriptors);
    if (!empty($knownSlugs)) {
        $placeholders = implode(',', array_fill(0, count($knownSlugs), '?'));
        $query = $conn->prepare("UPDATE networks SET is_missing = 1, enabled = 0 WHERE slug NOT IN ($placeholders)");
        if ($query) { $types = str_repeat('s', count($knownSlugs)); $query->bind_param($types, ...$knownSlugs); $query->execute(); $query->close(); }
    } else {
        @$conn->query("UPDATE networks SET is_missing = 1, enabled = 0");
    }

    $conn->close();
    set_setting('networks_last_refresh', (string)time());

    return array_values($descriptors);
}}

if (!function_exists('pp_get_networks')) {
function pp_get_networks(bool $onlyEnabled = false, bool $includeMissing = false): array {
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        return [];
    }
    if (!$conn) { return []; }

    $where = [];
    if ($onlyEnabled) { $where[] = "enabled = 1"; }
    if (!$includeMissing) { $where[] = "is_missing = 0"; }
    $sql = "SELECT slug, title, description, handler, handler_type, meta, regions, topics, enabled, priority, level, notes, is_missing, last_check_status, last_check_run_id, last_check_started_at, last_check_finished_at, last_check_url, last_check_error, last_check_updated_at, created_at, updated_at FROM networks";
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
            $rows[] = [ 'slug' => (string)$row['slug'], 'title' => (string)$row['title'], 'description' => (string)$row['description'], 'handler' => $rel, 'handler_abs' => $abs, 'handler_type' => (string)$row['handler_type'], 'meta' => json_decode((string)($row['meta'] ?? ''), true) ?: [], 'regions_raw' => $regionsRaw, 'topics_raw' => $topicsRaw, 'regions' => $regionsList, 'topics' => $topicsList, 'enabled' => (bool)$row['enabled'], 'priority' => (int)($row['priority'] ?? 0), 'level' => trim((string)($row['level'] ?? ''),), 'notes' => (string)($row['notes'] ?? ''), 'is_missing' => (bool)$row['is_missing'], 'last_check_status' => $row['last_check_status'] !== null ? (string)$row['last_check_status'] : null, 'last_check_run_id' => $row['last_check_run_id'] !== null ? (int)$row['last_check_run_id'] : null, 'last_check_started_at' => $row['last_check_started_at'], 'last_check_finished_at' => $row['last_check_finished_at'], 'last_check_url' => (string)($row['last_check_url'] ?? ''), 'last_check_error' => (string)($row['last_check_error'] ?? ''), 'last_check_updated_at' => $row['last_check_updated_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], ];
        }
        $res->free();
    }

    $conn->close();
    return $rows;
}}

if (!function_exists('pp_get_network')) { function pp_get_network(string $slug): ?array { $slug = pp_normalize_slug($slug); $all = pp_get_networks(false, true); foreach ($all as $network) { if ($network['slug'] === $slug) { return $network; } } return null; } }

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// Base URL helpers moved to includes/core.php

// Helpers for page metadata
// Page meta utilities moved to includes/page_meta.php

// -------- URL analysis utilities (microdata/meta extraction) --------
if (!function_exists('pp_http_fetch')) {
function pp_http_fetch(string $url, int $timeout = 12): array {
    $headers = [];
    $status = 0; $body = ''; $finalUrl = $url;
    // Use a realistic browser UA to avoid altered content for bots
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_USERAGENT => $ua,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9,ru;q=0.8',
                'Upgrade-Insecure-Requests: 1'
            ],
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($resp, 0, $headerSize);
            $body = substr($resp, $headerSize);
            if ($body !== '' && strlen($body) > 1048576) {
                $body = substr($body, 0, 1048576);
            }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            // Parse headers (multiple response headers possible on redirects; take the last block)
            $blocks = preg_split("/\r?\n\r?\n/", trim($rawHeaders));
            $last = end($blocks);
            foreach (preg_split("/\r?\n/", (string)$last) as $line) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 6,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: ' . $ua,
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9,ru;q=0.8',
                    'Upgrade-Insecure-Requests: 1',
                ],
            ],
            'ssl' => [ 'verify_peer' => true, 'verify_peer_name' => true ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $body = $resp !== false ? (string)$resp : '';
        $status = 0;
        $finalUrl = $url;
        global $http_response_header;
        if (is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('~^HTTP/\d\.\d\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; }
                elseif (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'final_url' => $finalUrl];
}}

if (!function_exists('pp_html_dom')) {
function pp_html_dom(string $html): ?DOMDocument {
    if ($html === '') return null;
    if (!class_exists('DOMDocument')) { return null; }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (stripos($html, '<meta') === false) {
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }
    $loaded = @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$loaded) return null;
    return $doc;
}}

if (!function_exists('pp_xpath')) { function pp_xpath(DOMDocument $doc): DOMXPath { return new DOMXPath($doc); } }
if (!function_exists('pp_text')) { function pp_text(?DOMNode $n): string { return trim($n ? $n->textContent : ''); } }
if (!function_exists('pp_attr')) { function pp_attr(?DOMElement $n, string $name): string { return trim($n ? (string)$n->getAttribute($name) : ''); } }

if (!function_exists('pp_abs_url')) {
function pp_abs_url(string $href, string $base): string {
    if ($href === '') return '';
    if (preg_match('~^https?://~i', $href)) return $href;
    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $href;
    $scheme = $bp['scheme'];
    $host = $bp['host'];
    $port = isset($bp['port']) ? (':' . $bp['port']) : '';
    $path = $bp['path'] ?? '/';
    if (substr($href, 0, 1) === '/') {
        return $scheme . '://' . $host . $port . $href;
    }
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    $segments = array_filter(explode('/', $dir));
    foreach (explode('/', $href) as $seg) {
        if ($seg === '.' || $seg === '') continue;
        if ($seg === '..') { array_pop($segments); continue; }
        $segments[] = $seg;
    }
    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}}

if (!function_exists('pp_normalize_text_content')) {
function pp_normalize_text_content(string $text): string {
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = preg_replace('~\s+~u', ' ', $decoded);
    $decoded = trim((string)$decoded);
    if ($decoded === '') { return ''; }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($decoded, 'UTF-8');
    }
    return strtolower($decoded);
}}

if (!function_exists('pp_plain_text_from_html')) {
function pp_plain_text_from_html(string $html): string {
    $doc = pp_html_dom($html);
    if ($doc) {
        $text = $doc->textContent ?? '';
    } else {
        $text = strip_tags($html);
    }
    return pp_normalize_text_content($text);
}}

if (!function_exists('pp_normalize_url_compare')) {
function pp_normalize_url_compare(string $url): string {
    $url = trim((string)$url);
    if ($url === '') { return ''; }
    $lower = strtolower($url);
    if (!preg_match('~^https?://~', $lower)) { return $lower; }
    $parts = @parse_url($lower);
    if (!$parts || empty($parts['host'])) { return $lower; }
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'];
    if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
    $path = $parts['path'] ?? '/';
    $path = $path === '' ? '/' : $path;
    $path = rtrim($path, '/');
    if ($path === '') { $path = '/'; }
    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    return $scheme . '://' . $host . $path . $query;
}}

if (!function_exists('pp_verify_published_content')) {
function pp_verify_published_content(string $publishedUrl, ?array $verification, ?array $job = null): array {
    $publishedUrl = trim($publishedUrl);
    $verification = is_array($verification) ? $verification : [];
    $supportsLink = array_key_exists('supportsLinkCheck', $verification) ? (bool)$verification['supportsLinkCheck'] : true;
    $supportsText = array_key_exists('supportsTextCheck', $verification) ? (bool)$verification['supportsTextCheck'] : null;
    $linkUrl = trim((string)($verification['linkUrl'] ?? ''));
    if ($linkUrl === '' && isset($job['url'])) {
        $linkUrl = trim((string)$job['url']);
    }
    $textSample = trim((string)($verification['textSample'] ?? ''));
    if ($supportsText === null) {
        $supportsText = ($textSample !== '');
    }

    $result = [
        'status' => 'skipped',
        'supports_link' => $supportsLink,
        'supports_text' => $supportsText,
        'link_found' => false,
        'text_found' => false,
        'http_status' => null,
        'final_url' => null,
        'content_type' => null,
        'reason' => null,
    ];

    if ($publishedUrl === '' || (!$supportsLink && !$supportsText)) {
        return $result;
    }

    $fetch = pp_http_fetch($publishedUrl, 18);
    $status = (int)($fetch['status'] ?? 0);
    $finalUrl = (string)($fetch['final_url'] ?? $publishedUrl);
    $headers = $fetch['headers'] ?? [];
    $body = (string)($fetch['body'] ?? '');
    $contentType = strtolower((string)($headers['content-type'] ?? ''));

    $result['http_status'] = $status;
    $result['final_url'] = $finalUrl;
    $result['content_type'] = $contentType;

    if ($status >= 400 || $body === '') {
        $result['status'] = 'error';
        $result['reason'] = 'FETCH_FAILED';
        return $result;
    }

    $doc = null;
    if ($contentType === '' || strpos($contentType, 'text/') === 0 || strpos($contentType, 'html') !== false || strpos($contentType, 'xml') !== false) {
        $doc = pp_html_dom($body);
    }

    if ($supportsLink && $linkUrl !== '') {
        $targetNorm = pp_normalize_url_compare($linkUrl);
        if ($doc) {
            $xp = new DOMXPath($doc);
            foreach ($xp->query('//a[@href]') as $node) {
                if (!($node instanceof DOMElement)) { continue; }
                $href = trim((string)$node->getAttribute('href'));
                if ($href === '') { continue; }
                $abs = pp_abs_url($href, $finalUrl);
                $absNorm = pp_normalize_url_compare($abs);
                if ($absNorm === $targetNorm) {
                    $result['link_found'] = true;
                    break;
                }
            }
        }
        if (!$result['link_found']) {
            $haystack = strtolower($body);
            $direct = strtolower($linkUrl);
            if ($direct !== '' && strpos($haystack, $direct) !== false) {
                $result['link_found'] = true;
            } else {
                $noScheme = preg_replace('~^https?://~i', '', $direct);
                if ($noScheme && strpos($haystack, $noScheme) !== false) {
                    $result['link_found'] = true;
                }
            }
        }
    } elseif ($supportsLink) {
        $result['supports_link'] = false;
    }

    if ($supportsText) {
        if ($textSample === '') {
            $result['supports_text'] = false;
        } else {
            $bodyPlain = $doc ? pp_normalize_text_content($doc->textContent ?? '') : pp_plain_text_from_html($body);
            $sampleNorm = pp_normalize_text_content($textSample);
            $matchFragment = '';
            if ($sampleNorm !== '' && strpos($bodyPlain, $sampleNorm) !== false) {
                $result['text_found'] = true;
                $matchFragment = $sampleNorm;
            } elseif ($sampleNorm !== '') {
                if (function_exists('mb_strlen')) {
                    $strlen = static function($str) { return mb_strlen($str, 'UTF-8'); };
                } else {
                    $strlen = static function($str) { return strlen($str); };
                }
                if (function_exists('mb_substr')) {
                    $substr = static function($str, $start, $length) { return mb_substr($str, $start, $length, 'UTF-8'); };
                } else {
                    $substr = static function($str, $start, $length) { return substr($str, $start, $length); };
                }
                $len = $strlen($sampleNorm);
                $short = $len > 120 ? $substr($sampleNorm, 0, 120) : $sampleNorm;
                if ($short !== '' && strpos($bodyPlain, $short) !== false) {
                    $result['text_found'] = true;
                    $matchFragment = $short;
                }
                if (!$result['text_found'] && $len > 0) {
                    $window = min(220, max(80, (int)ceil($len * 0.4)));
                    $step = max(40, (int)floor($window / 2));
                    for ($offset = 0; $offset < $len; $offset += $step) {
                        if ($offset + $window > $len) {
                            $offset = max(0, $len - $window);
                        }
                        $fragment = trim($substr($sampleNorm, $offset, $window));
                        if ($fragment === '' || $strlen($fragment) < 40) {
                            if ($offset + $window >= $len) { break; }
                            continue;
                        }
                        if (strpos($bodyPlain, $fragment) !== false) {
                            $result['text_found'] = true;
                            $matchFragment = $fragment;
                            break;
                        }
                        if ($offset + $window >= $len) { break; }
                    }
                }
                if (!$result['text_found']) {
                    $sentences = preg_split('~[.!?…]+\s*~u', $sampleNorm) ?: [];
                    $foundParts = [];
                    foreach ($sentences as $sentence) {
                        $sentence = trim($sentence);
                        if ($sentence === '') { continue; }
                        if ($strlen($sentence) < 40) { continue; }
                        if (strpos($bodyPlain, $sentence) !== false) {
                            $foundParts[] = $sentence;
                            if (count($foundParts) >= 2) {
                                $result['text_found'] = true;
                                $matchFragment = implode(' ', array_slice($foundParts, 0, 2));
                                break;
                            }
                        }
                    }
                }
            }
            if ($result['text_found'] && $matchFragment !== '') {
                $result['matched_fragment'] = $strlen($matchFragment) > 220 ? $substr($matchFragment, 0, 220) : $matchFragment;
            }
        }
    }

    if ($supportsLink && !$result['link_found']) {
        $result['status'] = 'failed';
        $result['reason'] = 'LINK_MISSING';
    } elseif ($supportsText && !$result['text_found']) {
        if ($supportsLink && $result['link_found']) {
            $result['status'] = 'partial';
            $result['reason'] = 'TEXT_MISSING';
        } else {
            $result['status'] = 'failed';
            $result['reason'] = 'TEXT_MISSING';
        }
    } else {
        $result['status'] = 'success';
    }

    return $result;
}}

if (!function_exists('pp_analyze_url_data')) {
function pp_analyze_url_data(string $url): ?array {
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) { return null; }
    $fetch = pp_http_fetch($url, 12);
    if (($fetch['status'] ?? 0) >= 400 || ($fetch['body'] ?? '') === '') {
        return null;
    }
    $finalUrl = $fetch['final_url'] ?: $url;
    $headers = $fetch['headers'] ?? [];
    $body = (string)$fetch['body'];
    $doc = pp_html_dom($body);
    if (!$doc) { return null; }
    $xp = pp_xpath($doc);

    $baseHref = '';
    $baseEl = $xp->query('//base[@href]')->item(0);
    if ($baseEl instanceof DOMElement) { $baseHref = pp_attr($baseEl, 'href'); }
    $base = $baseHref !== '' ? $baseHref : $finalUrl;

    $title = '';
    $titleEl = $xp->query('//title')->item(0);
    if ($titleEl) { $title = pp_text($titleEl); }
    $ogTitle = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content | //meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content')->item(0);
    if ($ogTitle && !$title) { $title = trim($ogTitle->nodeValue ?? ''); }

    $desc = '';
    $metaDesc = $xp->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0);
    if ($metaDesc) { $desc = trim($metaDesc->nodeValue ?? ''); }
    if ($desc === '') {
        $ogDesc = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content')->item(0);
        if ($ogDesc) { $desc = trim($ogDesc->nodeValue ?? ''); }
    }

    $canonical = '';
    $canonEl = $xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href')->item(0);
    if ($canonEl) { $canonical = pp_abs_url(trim($canonEl->nodeValue ?? ''), $base); }

    $lang = '';
    $region = '';
    $htmlEl = $xp->query('//html')->item(0);
    if ($htmlEl instanceof DOMElement) {
        $langAttr = trim($htmlEl->getAttribute('lang'));
        if ($langAttr) {
            $parts = preg_split('~[-_]~', $langAttr);
            $lang = strtolower($parts[0] ?? '');
            if (isset($parts[1])) { $region = strtoupper($parts[1]); }
        }
    }

    $hreflangs = [];
    foreach ($xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang and @href]') as $lnk) {
        if (!($lnk instanceof DOMElement)) continue;
        $hl = trim($lnk->getAttribute('hreflang'));
        $href = pp_abs_url(trim($lnk->getAttribute('href')), $base);
        if ($hl && $href) { $hreflangs[] = ['hreflang' => $hl, 'href' => $href]; }
    }
    if (!$lang && !empty($hreflangs)) {
        $hl0 = $hreflangs[0]['hreflang'];
        $parts = preg_split('~[-_]~', $hl0);
        $lang = strtolower($parts[0] ?? '');
        if (isset($parts[1])) { $region = strtoupper($parts[1]); }
    }

    if (!$lang) {
        $contentLang = $xp->query('//meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-language"]/@content')->item(0);
        if ($contentLang) {
            $v = trim($contentLang->nodeValue ?? '');
            if ($v) {
                $parts = preg_split('~[,;\s]+~', $v);
                $p0 = $parts[0] ?? '';
                $pp = preg_split('~[-_]~', $p0);
                $lang = strtolower($pp[0] ?? '');
                if (isset($pp[1])) { $region = strtoupper($pp[1]); }
            }
        }
    }

    $published = '';
    $modified = '';
    $q = function(string $xpath) use ($xp): ?string { $n = $xp->query($xpath)->item(0); return $n ? trim($n->nodeValue ?? '') : null; };
    $published = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:published_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="datePublished"]/@content') ?: $q('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="pubdate"]/@content');
    $modified = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:modified_time"]/@content') ?: $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:updated_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dateModified"]/@content');

    foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
        $json = trim($script->textContent ?? '');
        if ($json === '') continue;
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json2 = preg_replace('/,\s*([}\]])/', '$1', $json);
            $data = json_decode($json2, true);
        }
        if (is_array($data)) {
            $stack = [$data];
            while ($stack) {
                $cur = array_pop($stack);
                if (isset($cur['datePublished']) && !$published) { $published = (string)$cur['datePublished']; }
                if (isset($cur['dateModified']) && !$modified) { $modified = (string)$cur['dateModified']; }
                foreach ($cur as $v) { if (is_array($v)) $stack[] = $v; }
            }
        }
        if ($published && $modified) break;
    }

    if (!$modified && !empty($headers['last-modified'])) { $modified = $headers['last-modified']; }

    return [
        'final_url' => $finalUrl,
        'lang' => $lang,
        'region' => $region,
        'title' => $title,
        'description' => $desc,
        'canonical' => $canonical,
        'published_time' => $published,
        'modified_time' => $modified,
        'hreflang' => $hreflangs,
    ];
}}

// Publication queue helpers moved to includes/publication_queue.php

// -------- Network diagnostics (batch publishing check) --------
// All orchestration helpers now live in includes/network_check.php
