<?php
// Autopost loader: discovers network plugins and runs publications
// Each network plugin file: network_<slug>.php returns array: ['slug'=>'telegraph','name'=>'Telegraph','publish'=>callable]

if (!defined('PP_ROOT_PATH')) { define('PP_ROOT_PATH', realpath(__DIR__ . '/..')); }
// Simple logger
if (!function_exists('autopost_log')) {
    function autopost_log(string $msg): void {
        $dir = PP_ROOT_PATH . '/logs'; // moved from config/logs to logs
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . '/autopost.log';
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        @file_put_contents($file, $line, FILE_APPEND);
    }
}
require_once PP_ROOT_PATH . '/includes/functions.php';

// Cached metadata helpers (store only slug,name,description)
function autopost_scan_plugins_metadata(): array {
    $meta = [];
    $dir = PP_ROOT_PATH . '/autopost';
    if (!is_dir($dir)) return $meta;
    clearstatcache(true, $dir);
    foreach (glob($dir . '/network_*.php') ?: [] as $file) {
        try {
            $data = include $file;
            if (is_array($data) && isset($data['slug'],$data['name'])) {
                $slug = strtolower(preg_replace('~[^a-z0-9_\-]+~','',$data['slug']));
                if ($slug==='') continue;
                $meta[$slug] = [
                    'slug' => $slug,
                    'name' => (string)$data['name'],
                    'description' => (string)($data['description'] ?? ''),
                ];
            }
        } catch (Throwable $e) { /* ignore */ }
    }
    return array_values($meta);
}
function autopost_refresh_network_cache(): array {
    $items = autopost_scan_plugins_metadata();
    set_setting('autopost_networks_cache', json_encode(['refreshed_at'=>time(),'items'=>$items], JSON_UNESCAPED_UNICODE));
    return $items;
}
function autopost_get_cached_network_metadata(bool $autoInit=true): array {
    $raw = get_setting('autopost_networks_cache','');
    if ($raw) {
        $obj = json_decode($raw,true);
        if (is_array($obj) && isset($obj['items']) && is_array($obj['items'])) return $obj;
    }
    if ($autoInit) {
        $items = autopost_refresh_network_cache();
        return ['refreshed_at'=>time(),'items'=>$items];
    }
    return ['refreshed_at'=>null,'items'=>[]];
}

function autopost_load_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $dir = PP_ROOT_PATH . '/autopost';
    if (!is_dir($dir)) {
        autopost_log('Directory missing: ' . $dir);
        return $cache;
    }
    clearstatcache(true, $dir);
    $pattern = $dir . '/network_*.php';
    $files = glob($pattern) ?: [];
    if (empty($files)) { autopost_log('No plugin files found by pattern ' . $pattern); }
    foreach ($files as $file) {
        try {
            $net = include $file;
            if (!is_array($net)) { autopost_log('Plugin did not return array: ' . $file); continue; }
            if (!isset($net['slug'],$net['name'],$net['publish']) || !is_callable($net['publish'])) {
                autopost_log('Plugin missing required keys: ' . $file);
                continue;
            }
            $slug = strtolower(preg_replace('~[^a-z0-9_\-]+~','',$net['slug']));
            if ($slug === '') { autopost_log('Empty slug after sanitize: ' . $file); continue; }
            $net['slug'] = $slug;
            $cache[$slug] = $net;
        } catch (Throwable $e) {
            autopost_log('Exception loading ' . $file . ': ' . $e->getMessage());
        }
    }
    if (empty($cache)) { autopost_log('No valid plugins loaded.'); }
    return $cache;
}

function autopost_list_networks(): array {
    return array_values(autopost_load_all());
}

function autopost_attempt_publication(int $publicationId, int $userId): ?array {
    autopost_log('Attempt publication id=' . $publicationId . ' by user=' . $userId);
    $conn = connect_db();
    if (!$conn) { autopost_log('DB connect failed'); return null; }
    // Fetch publication & project ensuring ownership
    $stmt = $conn->prepare("SELECT p.id, p.project_id, p.page_url, p.anchor, p.post_url, p.network, pr.user_id, pr.language FROM publications p JOIN projects pr ON pr.id = p.project_id WHERE p.id = ? LIMIT 1");
    if (!$stmt) { $conn->close(); return null; }
    $stmt->bind_param('i', $publicationId);
    $stmt->execute();
    $res = $stmt->get_result();
    $pub = $res->fetch_assoc();
    $stmt->close();
    if (!$pub) { $conn->close(); return null; }
    if ((int)$pub['user_id'] !== $userId && !is_admin()) { $conn->close(); return null; }
    if (!empty($pub['post_url'])) { $conn->close(); return null; }

    $active = get_active_network_slugs_for_user($userId);
    if (empty($active)) { // fallback to global
        if (function_exists('get_global_active_network_slugs')) {
            $active = get_global_active_network_slugs();
        }
    }
    if (empty($active)) { autopost_log('No active networks for user/global'); $conn->close(); return null; }
    $networks = autopost_load_all();
    $candidates = [];
    foreach ($active as $slug) { if (isset($networks[$slug])) { $candidates[$slug] = $networks[$slug]; } }
    if (empty($candidates)) { $conn->close(); return null; }
    $slugs = array_keys($candidates);
    shuffle($slugs);

    $result = null;
    foreach ($slugs as $slug) {
        $net = $candidates[$slug];
        autopost_log('Trying network=' . $slug . ' publication=' . $publicationId);
        try {
            $publishFn = $net['publish'];
            $ctx = [
                'page_url' => $pub['page_url'],
                'anchor' => $pub['anchor'],
                'language' => $pub['language'] ?? 'ru',
                'publication_id' => $pub['id'],
                'project_id' => $pub['project_id'],
                'user_id' => $userId,
            ];
            $r = call_user_func($publishFn, $ctx);
            if (is_array($r) && !empty($r['post_url'])) {
                $author = $r['author'] ?? '';
                $title = $r['title'] ?? '';
                $postUrl = $r['post_url'];
                $stmt2 = $conn->prepare("UPDATE publications SET post_url = ?, network = ?, published_by = ? WHERE id = ? LIMIT 1");
                if ($stmt2) {
                    $stmt2->bind_param('sssi', $postUrl, $slug, $author, $publicationId);
                    $stmt2->execute();
                    $stmt2->close();
                }
                autopost_log('SUCCESS network=' . $slug . ' publication=' . $publicationId . ' url=' . $postUrl);
                $result = [
                    'post_url' => $postUrl,
                    'network' => $slug,
                    'author' => $author,
                    'title' => $title,
                ];
                break;
            } else {
                autopost_log('FAIL (no post_url) network=' . $slug . ' publication=' . $publicationId);
            }
        } catch (Throwable $e) {
            autopost_log('EXCEPTION network=' . $slug . ' publication=' . $publicationId . ' msg=' . $e->getMessage());
            continue;
        }
    }
    if (!$result) { autopost_log('All networks failed publication=' . $publicationId); }

    $conn->close();
    return $result; // null if not published
}

function autopost_debug_info(): array {
    $dir = PP_ROOT_PATH . '/autopost';
    $pattern = $dir . '/network_*.php';
    return [
        'dir_exists' => is_dir($dir),
        'pattern' => $pattern,
        'files' => glob($pattern) ?: [],
        'loaded_slugs' => array_keys(autopost_load_all()),
    ];
}

?>