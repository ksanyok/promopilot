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
// Optional scheduling (ISO datetime or UNIX timestamp)
$scheduleAtRaw = isset($_POST['schedule_at']) ? trim((string)$_POST['schedule_at']) : '';
$scheduleTs = 0;
if ($scheduleAtRaw !== '') {
    if (ctype_digit($scheduleAtRaw)) { $scheduleTs = (int)$scheduleAtRaw; }
    else {
        $ts = strtotime($scheduleAtRaw);
        if ($ts !== false) { $scheduleTs = (int)$ts; }
    }
}

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

// Release session lock so other requests (navigation) don't block while we enqueue/run background work
if (function_exists('session_write_close')) { @session_write_close(); }

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
    // Уже есть запись? (учитываем статус)
    $stmt = $conn->prepare("SELECT id, uuid, post_url, status, network FROM publications WHERE project_id = ? AND page_url = ? LIMIT 1");
    $stmt->bind_param('is', $project_id, $url);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $publicationUuid = trim((string)($row['uuid'] ?? ''));
        if ($publicationUuid === '') {
            $publicationUuid = pp_generate_uuid_v4();
            try {
                $fixUuid = $conn->prepare('UPDATE publications SET uuid = ? WHERE id = ? LIMIT 1');
                if ($fixUuid) { $fixUuid->bind_param('si', $publicationUuid, $row['id']); @$fixUuid->execute(); $fixUuid->close(); }
            } catch (Throwable $e) { /* ignore */ }
        }
        $pubId = (int)$row['id'];
        $postUrl = trim((string)($row['post_url'] ?? ''));
        $status = trim((string)($row['status'] ?? '')) ?: ($postUrl !== '' ? 'success' : 'queued');
        if ($postUrl !== '' || $status === 'success') {
            echo json_encode(['ok'=>false,'error'=>'ALREADY_PUBLISHED']);
            $conn->close(); exit;
        }
        if (in_array($status, ['queued','running'], true)) {
            // respond immediately then close connection before background processing
            $resp = json_encode(['ok'=>true,'status'=>'pending', 'network' => (string)($row['network'] ?? '')]);
            // Close DB before returning response
            $conn->close();
            // Send response now and close client connection
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Connection: close');
                header('Content-Length: ' . strlen($resp));
            }
            echo $resp;
            // Flush all buffers
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            else {
                @flush(); @ob_flush();
            }
            // schedule or run worker after closing connection
            if ($scheduleTs > 0 && $scheduleTs > time()) {
                try { $conn2 = @connect_db(); if ($conn2) { $up = $conn2->prepare('UPDATE publications SET scheduled_at = FROM_UNIXTIME(?) WHERE id = ? LIMIT 1'); if ($up) { $up->bind_param('ii', $scheduleTs, $pubId); @$up->execute(); $up->close(); } $conn2->close(); } } catch (Throwable $e) { }
            } else {
                // Drain multiple jobs to ensure queued links continue after the first finishes
                if (function_exists('pp_run_queue_worker')) { @pp_run_queue_worker(25); }
            }
            // hard-exit to ensure no more output
            exit;
        }
        // failed/cancelled → requeue
        $networkSlugExisting = trim((string)($row['network'] ?? ''));
        $network = $networkSlugExisting !== '' ? pp_get_network($networkSlugExisting) : pp_pick_network();
        if (!$network) {
            $conn->close(); echo json_encode(['ok'=>false,'error'=>'NO_ENABLED_NETWORKS']); exit;
        }
        $networkSlug = (string)$network['slug'];
        $upSql = "UPDATE publications SET status='queued', network=?, error=NULL, scheduled_at=?, started_at=NULL, finished_at=NULL, enqueued_by_user_id=? WHERE id = ? LIMIT 1";
        $up = $conn->prepare($upSql);
        if ($up) {
            if ($scheduleTs > 0 && $scheduleTs > time()) {
                $dt = date('Y-m-d H:i:s', $scheduleTs);
                $up->bind_param('ssii', $networkSlug, $dt, $_SESSION['user_id'], $pubId);
            } else {
                $null = NULL;
                $up->bind_param('ssii', $networkSlug, $null, $_SESSION['user_id'], $pubId);
            }
            $up->execute();
            $up->close();
        }
        // Mirror/update in publication_queue
        try {
            $conn2 = @connect_db();
            if ($conn2) {
                $upq = $conn2->prepare("UPDATE publication_queue SET status='queued', scheduled_at=? WHERE publication_id = ?");
                if ($upq) {
                    if ($scheduleTs > 0 && $scheduleTs > time()) {
                        $dt = date('Y-m-d H:i:s', $scheduleTs);
                        $upq->bind_param('si', $dt, $pubId);
                    } else {
                        $null = NULL;
                        $upq->bind_param('si', $null, $pubId);
                    }
                    @$upq->execute();
                    $upq->close();
                } else {
                    // If not exists, insert
                    $insQ = $conn2->prepare("INSERT INTO publication_queue (job_uuid, publication_id, project_id, user_id, page_url, status, scheduled_at) VALUES (?, ?, ?, ?, ?, 'queued', ?)");
                    if ($insQ) {
                        if ($scheduleTs > 0 && $scheduleTs > time()) {
                            $dt = date('Y-m-d H:i:s', $scheduleTs);
                            $insQ->bind_param('siiiss', $publicationUuid, $pubId, $project_id, $_SESSION['user_id'], $url, $dt);
                        } else {
                            $null = NULL;
                            $insQ->bind_param('siiiss', $publicationUuid, $pubId, $project_id, $_SESSION['user_id'], $url, $null);
                        }
                        @$insQ->execute();
                        $insQ->close();
                    }
                }
                $conn2->close();
            }
        } catch (Throwable $e) { /* ignore */ }
        echo json_encode(['ok'=>true,'status'=>'pending', 'network' => $networkSlug]);
        $conn->close();
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    if (!($scheduleTs > 0 && $scheduleTs > time())) { if (function_exists('pp_run_queue_worker')) { @pp_run_queue_worker(25); } }
        exit;
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

    $publicationUuid = pp_generate_uuid_v4();
    $stmt = $conn->prepare("INSERT INTO publications (uuid, project_id, page_url, anchor, network, status, scheduled_at, enqueued_by_user_id) VALUES (?, ?, ?, ?, ?, 'queued', ?, ?)");
    $networkSlug = $network['slug'];
    if ($scheduleTs > 0 && $scheduleTs > time()) {
        $dt = date('Y-m-d H:i:s', $scheduleTs);
        $stmt->bind_param('sissssi', $publicationUuid, $project_id, $url, $anchor, $networkSlug, $dt, $_SESSION['user_id']);
    } else {
        $null = NULL;
        $stmt->bind_param('sissssi', $publicationUuid, $project_id, $url, $anchor, $networkSlug, $null, $_SESSION['user_id']);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        echo json_encode(['ok'=>false,'error'=>'DB_ERROR']);
        exit;
    }
    $pubId = (int)$conn->insert_id;
    $stmt->close();
    // Mirror into publication_queue for visibility/ordering by user
    try {
        $conn2 = @connect_db();
        if ($conn2) {
            $insQ = $conn2->prepare("INSERT INTO publication_queue (job_uuid, publication_id, project_id, user_id, page_url, status, scheduled_at) VALUES (?, ?, ?, ?, ?, 'queued', ?)");
            if ($insQ) {
                if ($scheduleTs > 0 && $scheduleTs > time()) {
                    $dt = date('Y-m-d H:i:s', $scheduleTs);
                    $insQ->bind_param('siiiss', $publicationUuid, $pubId, $project_id, $_SESSION['user_id'], $url, $dt);
                } else {
                    $null = NULL;
                    $insQ->bind_param('siiiss', $publicationUuid, $pubId, $project_id, $_SESSION['user_id'], $url, $null);
                }
                @$insQ->execute();
                $insQ->close();
            }
            $conn2->close();
        }
    } catch (Throwable $e) { /* ignore queue mirror errors */ }
    // Ответ сразу: queued/pending
    $conn->close();
    $response = [
        'ok' => true,
        'status' => 'pending',
        'network' => $networkSlug,
        'network_title' => $network['title'] ?? $networkSlug,
    ];
    $json = json_encode($response);
    // Immediately return response, then continue in background
    if (!headers_sent()) {
        header('Connection: close');
        header('Content-Length: ' . strlen($json));
    }
    echo $json;
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    else {
        if (function_exists('session_write_close')) { @session_write_close(); }
        @flush(); @ob_flush();
    }
    // Неблокирующий запуск одного задания из очереди (только если не отложено)
    if (!($scheduleTs > 0 && $scheduleTs > time())) {
        if (function_exists('pp_run_queue_worker')) { @pp_run_queue_worker(25); }
    }
    exit;
} elseif ($action === 'cancel') {
    // Можно отменить только если не опубликована (нет post_url) и не выполняется
    $stmt = $conn->prepare("SELECT id, uuid, post_url, status FROM publications WHERE project_id = ? AND page_url = ? LIMIT 1");
    $stmt->bind_param('is', $project_id, $url);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(['ok'=>false,'error'=>'NOT_PENDING']);
        $conn->close(); exit;
    }
    if (!empty($row['post_url']) || (($row['status'] ?? '') === 'success')) {
        echo json_encode(['ok'=>false,'error'=>'ALREADY_PUBLISHED']);
        $conn->close(); exit;
    }
    if ((string)($row['status'] ?? '') === 'running') {
        // request cancellation for a running job
        $stmt = $conn->prepare("UPDATE publications SET cancel_requested = 1 WHERE id = ? LIMIT 1");
        if ($stmt) { $stmt->bind_param('i', $row['id']); $stmt->execute(); $stmt->close(); }
        echo json_encode(['ok'=>true,'status'=>'pending']);
        $conn->close(); exit;
    }
    // Not running: mark as cancelled immediately
    $stmt = $conn->prepare("UPDATE publications SET status='cancelled', finished_at=CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $row['id']);
        if ($stmt->execute()) {
            echo json_encode(['ok'=>true,'status'=>'not_published']);
        } else {
            echo json_encode(['ok'=>false,'error'=>'DB_ERROR']);
        }
        $stmt->close();
    } else {
        echo json_encode(['ok'=>false,'error'=>'DB_ERROR']);
    }
    $conn->close();
    exit;
} else {
    echo json_encode(['ok'=>false,'error'=>'BAD_ACTION']);
    $conn->close();
    exit;
}
