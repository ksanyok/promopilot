<?php
// Begin with strict JSON-safety guards BEFORE any includes
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok'=>false,'error'=>'FATAL','details'=>$e['message']]);
    }
});

require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Connection: close');

ignore_user_abort(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED']);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'UNAUTHORIZED']);
    exit;
}

if (!verify_csrf()) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CSRF']);
    exit;
}

$project_id = (int)($_POST['project_id'] ?? 0);
$url = trim($_POST['url'] ?? '');
$action = trim($_POST['action'] ?? '');

if (!$project_id || !$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['ok'=>false,'error'=>'BAD_INPUT']);
    exit;
}

$conn = connect_db();
// Проверка прав
$stmt = $conn->prepare("SELECT user_id, links, language, wishes, name FROM projects WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $project_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo json_encode(['ok'=>false,'error'=>'PROJECT_NOT_FOUND']);
    $stmt->close(); $conn->close(); exit;
}
$proj = $res->fetch_assoc();
$stmt->close();
if (!is_admin() && (int)$proj['user_id'] !== (int)$_SESSION['user_id']) {
    echo json_encode(['ok'=>false,'error'=>'FORBIDDEN']);
    $conn->close(); exit;
}

// Ищем анкор, язык, пожелание из структуры links
$anchor='';
$links = json_decode($proj['links'] ?? '[]', true) ?: [];
$projectLanguage = trim((string)($proj['language'] ?? 'ru')) ?: 'ru';
$projectWish = trim((string)($proj['wishes'] ?? ''));
$projectName = trim((string)($proj['name'] ?? ''));

// Initialize with project-level defaults to avoid undefined variable notices
$linkLanguage = $projectLanguage;
$linkWish = $projectWish;

// New: prefer normalized table project_links if present
$urlBelongs = false;
try {
    if ($stmtPL = $conn->prepare('SELECT anchor, language, wish FROM project_links WHERE project_id = ? AND url = ? LIMIT 1')) {
        $stmtPL->bind_param('is', $project_id, $url);
        if ($stmtPL->execute()) {
            $resPL = $stmtPL->get_result();
            if ($rowPL = $resPL->fetch_assoc()) {
                $urlBelongs = true;
                $anc = trim((string)($rowPL['anchor'] ?? ''));
                $lng = trim((string)($rowPL['language'] ?? ''));
                $wsh = trim((string)($rowPL['wish'] ?? ''));
                if ($anc !== '') { $anchor = $anc; }
                if ($lng !== '') { $linkLanguage = $lng; }
                if ($wsh !== '') { $linkWish = $wsh; }
            }
        }
        $stmtPL->close();
    }
} catch (Throwable $e) {
    // fallback to legacy JSON below
}

if (is_array($links) && !$urlBelongs) {
    foreach ($links as $lnk) {
        if (is_array($lnk) && isset($lnk['url']) && trim($lnk['url']) === $url) {
            $urlBelongs = true;
            $anchor = $anchor !== '' ? $anchor : trim($lnk['anchor'] ?? '');
            $linkLanguage = trim((string)($lnk['language'] ?? '')) ?: $linkLanguage;
            $linkWish = trim((string)($lnk['wish'] ?? '')) ?: $linkWish;
            break;
        }
        if (is_string($lnk) && $lnk === $url) { $urlBelongs = true; $anchor = $anchor !== '' ? $anchor : ''; break; }
    }
}
$linkLanguage = $linkLanguage ?? $projectLanguage;
$linkWish = $linkWish ?? $projectWish;

if ($action === 'publish') {
    // Уже есть?
    $stmt = $conn->prepare("SELECT id, post_url FROM publications WHERE project_id = ? AND page_url = ? LIMIT 1");
    $stmt->bind_param('is', $project_id, $url);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        if (!empty($row['post_url'])) {
            echo json_encode(['ok'=>false,'error'=>'ALREADY_PUBLISHED']);
            $conn->close(); exit;
        }
        // уже pending
        echo json_encode(['ok'=>true,'status'=>'pending']);
        $conn->close(); exit;
    }
    // New: ensure the URL belongs to the project before creating a publication
    if (!$urlBelongs) {
        $conn->close();
        echo json_encode(['ok'=>false,'error'=>'URL_NOT_IN_PROJECT']);
        exit;
    }
    $network = pp_pick_network();
    if (!$network) {
        $conn->close();
        echo json_encode(['ok'=>false,'error'=>'NO_ENABLED_NETWORKS']);
        exit;
    }

    // Determine AI provider from settings
    $aiProvider = strtolower((string)get_setting('ai_provider', 'openai')) === 'byoa' ? 'byoa' : 'openai';

    $openaiKey = trim((string)get_setting('openai_api_key', ''));
    $openaiModel = trim((string)get_setting('openai_model', 'gpt-3.5-turbo')) ?: 'gpt-3.5-turbo';
    if ($aiProvider === 'openai' && $openaiKey === '') {
        $conn->close();
        echo json_encode(['ok'=>false,'error'=>'MISSING_OPENAI_KEY']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO publications (project_id, page_url, anchor, network) VALUES (?,?,?,?)");
    $networkSlug = $network['slug'];
    $stmt->bind_param('isss', $project_id, $url, $anchor, $networkSlug);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        echo json_encode(['ok'=>false,'error'=>'DB_ERROR']);
        exit;
    }
    $pubId = (int)$conn->insert_id;
    $stmt->close();

    $job = [
        'url' => $url,
        'anchor' => $anchor,
        'language' => $linkLanguage,
        'wish' => $linkWish,
        'projectId' => $project_id,
        'projectName' => $projectName,
        'openaiApiKey' => $openaiKey,
        'openaiModel' => $openaiModel,
        'aiProvider' => $aiProvider,
        'waitBetweenCallsMs' => 5000,
    ];

    $result = pp_publish_via_network($network, $job, 300);
    if (!is_array($result) || empty($result['ok']) || empty($result['publishedUrl'])) {
        $del = $conn->prepare("DELETE FROM publications WHERE id = ? LIMIT 1");
        if ($del) {
            $del->bind_param('i', $pubId);
            $del->execute();
            $del->close();
        }
        $conn->close();
        $errCode = 'NETWORK_ERROR';
        $details = 'NO_RESPONSE';
        $payload = ['ok'=>false,'error'=>$errCode];
        if (is_array($result)) {
            if (!empty($result['details'])) { $details = (string)$result['details']; }
            elseif (!empty($result['error'])) { $details = (string)$result['error']; }
            elseif (!empty($result['stderr'])) { $details = (string)$result['stderr']; }
            $payload['details'] = $details;
            if (!empty($result['stderr'])) { $payload['stderr'] = (string)$result['stderr']; }
            if (!empty($result['raw'])) { $payload['raw'] = $result['raw']; }
            if (!empty($result['node_version'])) { $payload['node_version'] = $result['node_version']; }
            if (!empty($result['candidates']) && is_array($result['candidates'])) {
                $payload['candidates'] = $result['candidates'];
            }
            if (isset($result['exit_code'])) { $payload['exit_code'] = (int)$result['exit_code']; }
        } else {
            $payload['details'] = $details;
        }
        $json = json_encode($payload);
        header('Content-Length: ' . strlen($json));
        echo $json;
        flush();
        exit;
    }

    $publishedUrl = trim((string)$result['publishedUrl']);
    $publishedBy = 'system';
    $userStmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    if ($userStmt) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $userStmt->bind_param('i', $uid);
        $userStmt->execute();
        $userStmt->bind_result($uName);
        if ($userStmt->fetch()) {
            $publishedBy = (string)$uName;
        }
        $userStmt->close();
    }

    $update = $conn->prepare("UPDATE publications SET post_url = ?, network = ?, published_by = ? WHERE id = ? LIMIT 1");
    if ($update) {
        $update->bind_param('sssi', $publishedUrl, $networkSlug, $publishedBy, $pubId);
        $update->execute();
        $update->close();
    }

    $conn->close();
    $response = [
        'ok' => true,
        'status' => 'published',
        'post_url' => $publishedUrl,
        'network' => $networkSlug,
        'network_title' => $network['title'] ?? $networkSlug,
        'title' => $result['title'] ?? '',
        'author' => $result['author'] ?? '',
    ];
    $json = json_encode($response);
    header('Content-Length: ' . strlen($json));
    echo $json;
    flush();
    exit;
} elseif ($action === 'cancel') {
    // Можно отменить только если не опубликована (нет post_url)
    $stmt = $conn->prepare("SELECT id, post_url FROM publications WHERE project_id = ? AND page_url = ? LIMIT 1");
    $stmt->bind_param('is', $project_id, $url);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(['ok'=>false,'error'=>'NOT_PENDING']);
        $conn->close(); exit;
    }
    if (!empty($row['post_url'])) {
        echo json_encode(['ok'=>false,'error'=>'ALREADY_PUBLISHED']);
        $conn->close(); exit;
    }
    $stmt = $conn->prepare("DELETE FROM publications WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $row['id']);
    if ($stmt->execute()) {
        echo json_encode(['ok'=>true,'status'=>'not_published']);
    } else {
        echo json_encode(['ok'=>false,'error'=>'DB_ERROR']);
    }
    $stmt->close();
    $conn->close();
    exit;
} else {
    echo json_encode(['ok'=>false,'error'=>'BAD_ACTION']);
    $conn->close();
    exit;
}
