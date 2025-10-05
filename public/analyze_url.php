<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

$response = function(array $data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!is_logged_in()) {
    $response(['ok' => false, 'error' => 'FORBIDDEN']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    $response(['ok' => false, 'error' => 'BAD_REQUEST']);
}

$projectId = (int)($_POST['project_id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));
$shouldSave = isset($_POST['save']) && (string)$_POST['save'] !== '0' && (string)$_POST['save'] !== '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    $response(['ok' => false, 'error' => 'INVALID_INPUT']);
}

$shouldSave = $shouldSave && $projectId > 0;
$project = null;
$projectHost = '';

if ($projectId > 0) {
    // Fetch project and check permissions
    $conn = connect_db();
    $stmt = $conn->prepare('SELECT id, user_id, domain_host FROM projects WHERE id = ?');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$project) { $conn->close(); $response(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']); }
    if (!is_admin() && (int)$project['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
        $conn->close();
        $response(['ok' => false, 'error' => 'FORBIDDEN']);
    }

    // Enforce same-domain restriction
    $normHost = function($h) { $h = strtolower((string)$h); return (strpos($h, 'www.') === 0) ? substr($h, 4) : $h; };
    $projectHost = $normHost($project['domain_host'] ?? '');
    $targetHost = $normHost(parse_url($url, PHP_URL_HOST) ?: '');
    if ($projectHost && $targetHost && $projectHost !== $targetHost) {
        $conn->close();
        $response(['ok' => false, 'error' => 'DOMAIN_MISMATCH']);
    }

    $conn->close();
}

try {
    $meta = null;
    $brief = null;

    if (function_exists('pp_project_brief_prepare_initial')) {
        try {
            $brief = pp_project_brief_prepare_initial($url);
            if (is_array($brief)) {
                $metaCandidate = $brief['meta'] ?? null;
                if (is_array($metaCandidate)) {
                    $meta = $metaCandidate;
                }
            }
        } catch (Throwable $e) {
            $brief = null;
        }
    }

    if ($meta === null) {
        if (!function_exists('pp_analyze_url_data')) {
            $response(['ok' => false, 'error' => 'ANALYZER_MISSING']);
        }
        $meta = pp_analyze_url_data($url);
    }

    if (!$meta) {
        $response(['ok' => false, 'error' => 'FETCH_OR_PARSE_FAILED']);
    }

    $suggestedName = '';
    $suggestedDescription = '';
    $suggestedLanguage = '';
    if (is_array($brief)) {
        $suggestedName = trim((string)($brief['name'] ?? ''));
        $suggestedDescription = trim((string)($brief['description'] ?? ''));
        $suggestedLanguage = trim((string)($brief['language'] ?? ''));
    }

    if ($suggestedName === '' && isset($meta['title'])) {
        $suggestedName = trim((string)$meta['title']);
    }
    if ($suggestedDescription === '' && isset($meta['description'])) {
        $suggestedDescription = trim((string)$meta['description']);
    }
    if ($suggestedLanguage === '' && isset($meta['lang'])) {
        $suggestedLanguage = trim((string)$meta['lang']);
    }

    $payload = is_array($meta) ? $meta : [];
    $payload['meta'] = $meta;
    $payload['name'] = $suggestedName;
    $payload['description'] = $suggestedDescription;
    $payload['language'] = $suggestedLanguage;
    if ($suggestedName !== '') { $payload['suggested_name'] = $suggestedName; }
    if ($suggestedDescription !== '') { $payload['suggested_description'] = $suggestedDescription; }
    if ($suggestedLanguage !== '') { $payload['suggested_language'] = $suggestedLanguage; }
    $payload['name_suggested_by_ai'] = $suggestedName;
    $payload['description_suggested_by_ai'] = $suggestedDescription;
    $payload['suggested_language_by_ai'] = $suggestedLanguage;
    $usedAi = false;
    $aiError = null;
    if (is_array($brief)) {
        $usedAi = !empty($brief['used_ai']) || (!empty($brief['ai']['used_ai'] ?? null));
        $aiError = $brief['ai']['error'] ?? $brief['error'] ?? null;
        if ($aiError && !is_string($aiError)) {
            $aiError = (string)$aiError;
        }
        $briefAi = $brief['ai'] ?? null;
        if (is_array($briefAi) && array_key_exists('raw', $briefAi)) {
            unset($briefAi['raw']);
        }
        $payload['brief'] = [
            'name' => $brief['name'] ?? '',
            'description' => $brief['description'] ?? '',
            'language' => $brief['language'] ?? '',
            'used_ai' => $usedAi,
            'ai' => $briefAi,
        ];
        if ($aiError) {
            $payload['brief']['ai_error'] = $aiError;
        }
    }

    $payload['ai_used'] = $usedAi;
    if ($aiError && !$usedAi) {
        $payload['ai_error'] = $aiError;
    }
    $payload['name_source'] = $usedAi ? 'ai' : 'meta';
    $payload['description_source'] = $usedAi ? 'ai' : 'meta';

    if ($shouldSave && function_exists('pp_save_page_meta')) {
        @pp_save_page_meta($projectId, $url, $meta);
    }

    $response(['ok' => true, 'data' => $payload]);
} catch (Throwable $e) {
    $response(['ok' => false, 'error' => 'EXCEPTION', 'details' => $e->getMessage()]);
}
