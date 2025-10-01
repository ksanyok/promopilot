<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !is_admin()) {
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : '';
if ($action === '') {
    echo json_encode(['ok' => false, 'error' => 'NO_ACTION'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pp_crowd_api_response(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function pp_crowd_api_filters_from_query(): array {
    $status = isset($_GET['crowd_status']) ? strtolower(trim((string)$_GET['crowd_status'])) : '';
    if ($status === '') {
        $status = 'all';
    }
    $search = isset($_GET['crowd_search']) ? trim((string)$_GET['crowd_search']) : '';
    return [
        'status' => $status,
        'search' => $search,
    ];
}

function pp_crowd_api_page_from_query(): int {
    $page = isset($_GET['crowd_page']) ? (int)$_GET['crowd_page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    return $page;
}

    function pp_crowd_api_render_rows(array $links): string {
    $statusOptions = [
        'all' => __('Все'),
        'pending' => __('Не проверено'),
        'checking' => __('В процессе'),
        'success' => __('Рабочие'),
        'needs_review' => __('Нужна проверка'),
        'failed' => __('Ошибки'),
        'cancelled' => __('Отменено'),
    ];
    $statusBadges = [
        'pending' => 'badge-secondary',
        'checking' => 'badge-primary',
        'success' => 'badge-success',
        'needs_review' => 'badge-warning text-dark',
        'failed' => 'badge-danger',
        'cancelled' => 'badge-secondary',
    ];
    $followLabels = [
        'follow' => __('DoFollow'),
        'nofollow' => __('NoFollow'),
        'unknown' => __('Неизвестно'),
    ];
    $indexLabels = [
        'index' => __('Индексируется'),
        'noindex' => __('NoIndex'),
        'unknown' => __('Неизвестно'),
    ];
    $formatTs = static function (?string $ts): string {
        if (!$ts || $ts === '0000-00-00 00:00:00') {
            return '—';
        }
        $time = strtotime($ts);
        if ($time === false) {
            return htmlspecialchars($ts, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return htmlspecialchars(date('Y-m-d H:i', $time), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    ob_start();
    if (empty($links)) {
        ?>
        <tr>
            <td colspan="10" class="text-center text-muted py-4"><?php echo __('Ссылок нет. Импортируйте базу, чтобы начать.'); ?></td>
        </tr>
        <?php
    } else {
        foreach ($links as $link) {
            $status = (string)($link['status'] ?? 'pending');
            $statusClass = $statusBadges[$status] ?? 'badge-secondary';
            $statusLabel = $statusOptions[$status] ?? __('Неизвестно');
            $linkId = (int)($link['id'] ?? 0);
            $follow = (string)($link['follow_type'] ?? 'unknown');
            $index = (string)($link['is_indexed'] ?? 'unknown');
            $http = isset($link['http_status']) && $link['http_status'] !== null ? (int)$link['http_status'] : null;
            $lastCheck = $formatTs($link['last_checked_at'] ?? null);
            $statusDetail = trim((string)($link['status_detail'] ?? ''));
            $tooltip = $statusDetail !== '' ? htmlspecialchars($statusDetail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(__('Подробности отсутствуют'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ?>
            <tr data-link-id="<?php echo $linkId; ?>" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <td class="text-center">
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input crowd-select" aria-label="<?php echo __('Выбрать ссылку'); ?>">
                    </div>
                </td>
                <td>
                    <a href="<?php echo htmlspecialchars((string)$link['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener" class="crowd-link-url">
                        <?php echo htmlspecialchars(mb_strimwidth((string)$link['url'], 0, 90, '…'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </a>
                </td>
                <td class="text-center"><span class="badge <?php echo $statusClass; ?>" data-status-label data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="<?php echo $tooltip; ?>"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></td>
                <td class="text-center" data-region><?php echo !empty($link['region']) ? htmlspecialchars((string)$link['region'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—'; ?></td>
                <td class="text-center" data-language><?php echo !empty($link['language']) ? htmlspecialchars((string)$link['language'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—'; ?></td>
                <td class="text-center" data-follow><?php echo htmlspecialchars($followLabels[$follow] ?? __('Неизвестно'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                <td class="text-center" data-index><?php echo htmlspecialchars($indexLabels[$index] ?? __('Неизвестно'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                <td class="text-center" data-http><?php echo $http !== null ? $http : '—'; ?></td>
                <td class="text-center" data-last-check><?php echo $lastCheck; ?></td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary crowd-check-single" data-link-id="<?php echo $linkId; ?>"><i class="bi bi-play-circle me-1"></i><?php echo __('Проверить'); ?></button>
                </td>
            </tr>
            <?php
        }
    }
    return trim(ob_get_clean());
}

switch ($action) {
    case 'dedupe': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
            pp_crowd_api_response(['ok' => false, 'error' => 'CSRF']);
        }
        try { $conn = @connect_db(); } catch (Throwable $e) { $conn = null; }
        if (!$conn) { pp_crowd_api_response(['ok' => false, 'error' => 'DB']); }
        $deleted = 0;
        // Remove exact duplicates by keeping lowest id for each url_hash
        $sql = "DELETE l1 FROM crowd_links l1 JOIN crowd_links l2 ON l1.url_hash = l2.url_hash AND l1.id > l2.id";
        if ($conn->query($sql)) { $deleted = $conn->affected_rows; }
        $total = 0;
        if ($res = $conn->query('SELECT COUNT(*) AS c FROM crowd_links')) { if ($row = $res->fetch_assoc()) { $total = (int)$row['c']; } $res->free(); }
        $conn->close();
        pp_crowd_api_response(['ok' => true, 'deleted' => $deleted, 'total' => $total]);
    }
    case 'count': {
        try { $conn = @connect_db(); } catch (Throwable $e) { $conn = null; }
        $total = 0;
        if ($conn) { if ($res = $conn->query('SELECT COUNT(*) AS c FROM crowd_links')) { if ($row = $res->fetch_assoc()) { $total = (int)$row['c']; } $res->free(); $conn->close(); } }
        pp_crowd_api_response(['ok' => true, 'total' => $total]);
    }
    case 'status': {
        $filters = pp_crowd_api_filters_from_query();
        $page = pp_crowd_api_page_from_query();
        $perPage = 25;
        // Trigger cooperative tick first so list reflects the latest state
        $status = pp_crowd_links_get_status(null, 30);
        // Now fetch the list after tick
        $list = pp_crowd_links_fetch_links($page, $perPage, $filters);
        $stats = pp_crowd_links_get_stats();
        if (!$status['ok']) {
            pp_crowd_api_response(['ok' => false, 'error' => $status['error'] ?? 'UNKNOWN']);
        }
        $tableHtml = pp_crowd_api_render_rows($list['rows'] ?? []);
        pp_crowd_api_response([
            'ok' => true,
            'run' => $status['run'] ?? null,
            'results' => $status['results'] ?? [],
            'stats' => $stats,
            'tableHtml' => $tableHtml,
            'page' => $page,
            'total' => (int)($list['total'] ?? 0),
            'totalPages' => max(1, (int)ceil(((int)($list['total'] ?? 0)) / $perPage)),
        ]);
    }
    case 'list': {
        $filters = pp_crowd_api_filters_from_query();
        $page = pp_crowd_api_page_from_query();
        $perPage = 25;
        $list = pp_crowd_links_fetch_links($page, $perPage, $filters);
        $tableHtml = pp_crowd_api_render_rows($list['rows'] ?? []);
        $stats = pp_crowd_links_get_stats();
        $status = pp_crowd_links_get_status(null, 30);
        pp_crowd_api_response([
            'ok' => true,
            'table' => $tableHtml,
            'stats' => $stats,
            'run' => $status['ok'] ? ($status['run'] ?? null) : null,
            'page' => $page,
            'total' => (int)($list['total'] ?? 0),
            'totalPages' => max(1, (int)ceil(((int)($list['total'] ?? 0)) / $perPage)),
        ]);
    }
    case 'start': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
            pp_crowd_api_response(['ok' => false, 'error' => 'CSRF']);
        }
        $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'all';
        $ids = [];
        if (isset($_POST['ids']) && is_array($_POST['ids'])) {
            foreach ($_POST['ids'] as $value) {
                $id = (int)$value;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $linkId = isset($_POST['link_id']) ? (int)$_POST['link_id'] : null;
        $filters = [];
        if (!empty($_POST['status'])) {
            $filters['status'] = strtolower(trim((string)$_POST['status']));
        }
        if (!empty($_POST['search'])) {
            $filters['search'] = trim((string)$_POST['search']);
        }
        $options = [
            'filters' => $filters,
        ];
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $result = pp_crowd_links_start_run($userId, $mode, $ids, $linkId, $options);
        if (!$result['ok']) {
            pp_crowd_api_response(['ok' => false, 'error' => $result['error'] ?? 'UNKNOWN']);
        }
        $runStatus = pp_crowd_links_get_status($result['runId'] ?? null, 30);
        $stats = pp_crowd_links_get_stats();
        pp_crowd_api_response([
            'ok' => true,
            'runId' => $result['runId'] ?? null,
            'alreadyRunning' => !empty($result['alreadyRunning']),
            'run' => $runStatus['ok'] ? ($runStatus['run'] ?? null) : null,
            'stats' => $stats,
        ]);
    }
    case 'cancel': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
            pp_crowd_api_response(['ok' => false, 'error' => 'CSRF']);
        }
        $runId = isset($_POST['run_id']) ? (int)$_POST['run_id'] : null;
        $result = pp_crowd_links_cancel($runId); 
        if (!$result['ok']) {
            pp_crowd_api_response(['ok' => false, 'error' => $result['error'] ?? 'UNKNOWN']);
        }
        pp_crowd_api_response([
            'ok' => true,
            'status' => $result['status'] ?? null,
            'runId' => $result['runId'] ?? null,
            'cancelRequested' => $result['cancelRequested'] ?? false,
        ]);
    }
    default:
        pp_crowd_api_response(['ok' => false, 'error' => 'UNKNOWN_ACTION']);
}
