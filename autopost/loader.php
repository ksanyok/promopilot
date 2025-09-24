<?php
// Autopost loader: discovers network plugins and runs publications
// Each network plugin file: network_<slug>.php returns array: ['slug'=>'telegraph','name'=>'Telegraph','publish'=>callable]

if (!defined('PP_ROOT_PATH')) { define('PP_ROOT_PATH', realpath(__DIR__ . '/..')); }
require_once PP_ROOT_PATH . '/includes/functions.php';

function autopost_load_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $pattern = PP_ROOT_PATH . '/autopost/network_*.php';
    foreach (glob($pattern) as $file) {
        try {
            $net = include $file;
            if (is_array($net) && isset($net['slug'],$net['name'],$net['publish']) && is_callable($net['publish'])) {
                $slug = strtolower(preg_replace('~[^a-z0-9_\-]+~','',$net['slug']));
                if ($slug !== '') { $net['slug']=$slug; $cache[$slug] = $net; }
            }
        } catch (Throwable $e) { /* ignore bad plugin */ }
    }
    return $cache;
}

function autopost_list_networks(): array {
    return array_values(autopost_load_all());
}

function autopost_attempt_publication(int $publicationId, int $userId): ?array {
    $conn = connect_db();
    if (!$conn) return null;
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
    if (empty($active)) { $conn->close(); return null; }
    $networks = autopost_load_all();
    $candidates = [];
    foreach ($active as $slug) { if (isset($networks[$slug])) { $candidates[$slug] = $networks[$slug]; } }
    if (empty($candidates)) { $conn->close(); return null; }
    $slugs = array_keys($candidates);
    shuffle($slugs);

    $result = null;
    foreach ($slugs as $slug) {
        $net = $candidates[$slug];
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
                $result = [
                    'post_url' => $postUrl,
                    'network' => $slug,
                    'author' => $author,
                    'title' => $title,
                ];
                break;
            }
        } catch (Throwable $e) {
            // try next
            continue;
        }
    }

    $conn->close();
    return $result; // null if not published
}

?>