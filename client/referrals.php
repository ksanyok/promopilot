<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect(is_admin() ? 'admin/admin.php' : 'auth/login.php');
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$conn = connect_db();

// Ensure this user has a referral code
$code = '';
try {
    if (function_exists('pp_referral_get_or_create_user_code')) {
        $code = pp_referral_get_or_create_user_code($conn, $uid);
    } else {
        $st = $conn->prepare("SELECT referral_code FROM users WHERE id = ? LIMIT 1");
        if ($st) { $st->bind_param('i', $uid); $st->execute(); $r = $st->get_result(); if ($r) { $row = $r->fetch_assoc(); $code = (string)($row['referral_code'] ?? ''); $r->free(); } $st->close(); }
    }
} catch (Throwable $e) { /* ignore */ }

// Build referral link (root URL with ?ref=code)
$refLink = pp_url('') . '/?ref=' . rawurlencode($code);

// Stats: total referred users and earnings
$totalUsers = 0; $totalEarnings = 0.0; $personalPercent = 0.0;
$cookieDays = (int)get_setting('referral_cookie_days', '30');
$defaultPercent = (float)str_replace(',', '.', (string)get_setting('referral_default_percent', '5.0'));
$statsDaysSetting = (int)get_setting('referral_stats_days', '30');
$periodDays = $statsDaysSetting > 0 ? max(7, min(365, $statsDaysSetting)) : 30;
// Optional period override via query (?days=7|30|90|180|365)
$userDays = isset($_GET['days']) ? (int)$_GET['days'] : 0;
if ($userDays > 0) { $periodDays = max(7, min(365, $userDays)); }
$periodCutoff = date('Y-m-d 00:00:00', strtotime('-' . ($periodDays - 1) . ' days'));
$periodLabels = [];
for ($i = $periodDays - 1; $i >= 0; $i--) {
    $periodLabels[] = date('Y-m-d', strtotime('-' . $i . ' days'));
}
$labelIndex = array_flip($periodLabels);
// Prepare activity structures
$activitySeries = [
    'labels' => $periodLabels,
    'click' => array_fill(0, $periodDays, 0),
    'signup' => array_fill(0, $periodDays, 0),
    'payout' => array_fill(0, $periodDays, 0),
];
$activityTotals = ['click' => 0, 'signup' => 0, 'payout' => 0];
$periodTotals = $activityTotals;

// Basic stats: personal percent, total referred users, total referral earnings, recent referral payout events
try {
    // Personal commission percent
    if ($st = $conn->prepare('SELECT referral_commission_percent FROM users WHERE id = ? LIMIT 1')) {
        $st->bind_param('i', $uid);
        $st->execute();
        if ($res = $st->get_result()) {
            if ($row = $res->fetch_assoc()) {
                $pp = (float)($row['referral_commission_percent'] ?? 0);
                if ($pp > 0) { $personalPercent = $pp; }
            }
            $res->free();
        }
        $st->close();
    }

    // Total referred users (lifetime)
    if ($st = $conn->prepare('SELECT COUNT(*) AS c FROM users WHERE referred_by = ?')) {
        $st->bind_param('i', $uid);
        $st->execute();
        $st->bind_result($c);
        if ($st->fetch()) { $totalUsers = (int)$c; }
        $st->close();
    }

    // Total referral earnings (sum of deltas)
    if ($st = $conn->prepare("SELECT COALESCE(SUM(delta), 0) AS s FROM balance_history WHERE user_id = ? AND source = 'referral'")) {
        $st->bind_param('i', $uid);
        $st->execute();
        $st->bind_result($s);
        if ($st->fetch()) { $totalEarnings = (float)$s; }
        $st->close();
    }

    // Recent referral payout events
    $recentEvents = [];
    if ($st = $conn->prepare("SELECT delta, meta_json, created_at FROM balance_history WHERE user_id = ? AND source = 'referral' ORDER BY created_at DESC LIMIT 10")) {
        $st->bind_param('i', $uid);
        $st->execute();
        if ($res = $st->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $meta = [];
                $mj = (string)($row['meta_json'] ?? '');
                if ($mj !== '') { $dec = json_decode($mj, true); if (is_array($dec)) { $meta = $dec; } }
                $recentEvents[] = [
                    'created_at' => (string)$row['created_at'],
                    'delta' => (float)$row['delta'],
                    'percent' => isset($meta['percent']) ? (float)$meta['percent'] : 0.0,
                    'from_user_id' => isset($meta['from_user_id']) ? (int)$meta['from_user_id'] : 0,
                ];
            }
            $res->free();
        }
        $st->close();
    }
} catch (Throwable $e) {
    // leave defaults on failure
}

// Referred users list (last 25)
$referredUsers = [];
if ($st = $conn->prepare('SELECT id, username, created_at FROM users WHERE referred_by = ? ORDER BY id DESC LIMIT 25')) {
    $st->bind_param('i', $uid);
    $st->execute();
    if ($res = $st->get_result()) {
        while ($row = $res->fetch_assoc()) { $referredUsers[] = $row; }
        $res->free();
    }
    $st->close();
}

$clicksPerPage = 20;
$clickPage = max(1, (int)($_GET['clicks_page'] ?? $_GET['page'] ?? 1));
$clickEvents = [];
$clickPagination = ['page' => $clickPage, 'pages' => 1, 'total' => 0];

try {
    // Trim old click events beyond the analytics window
    if ($del = $conn->prepare("DELETE FROM referral_events WHERE referrer_user_id = ? AND type = 'click' AND created_at < ?")) {
        $del->bind_param('is', $uid, $periodCutoff);
        $del->execute();
        $del->close();
    }

    // Aggregate clicks into activity series using timestamp buckets (avoid DATE/timezone mismatch)
    $startTs = strtotime($periodCutoff);
    if ($st = $conn->prepare('SELECT FLOOR((UNIX_TIMESTAMP(created_at) - ?) / 86400) AS idx, COUNT(*) AS c FROM referral_events WHERE referrer_user_id = ? AND type = \"click\" AND created_at >= ? GROUP BY idx')) {
        $st->bind_param('iis', $startTs, $uid, $periodCutoff);
        $st->execute();
        if ($res = $st->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $idx = isset($row['idx']) ? (int)$row['idx'] : -1;
                $count = (int)($row['c'] ?? 0);
                if ($count <= 0 || $idx < 0 || $idx >= $periodDays) { continue; }
                $activitySeries['click'][$idx] = $count;
            }
            $res->free();
        }
        $st->close();
    }

    // Aggregate payout events from balance history using timestamp buckets
    if ($st = $conn->prepare("SELECT FLOOR((UNIX_TIMESTAMP(created_at) - ?) / 86400) AS idx, COUNT(*) AS c FROM balance_history WHERE user_id = ? AND source = 'referral' AND created_at >= ? GROUP BY idx")) {
        $st->bind_param('iis', $startTs, $uid, $periodCutoff);
        $st->execute();
        if ($res = $st->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $idx = isset($row['idx']) ? (int)$row['idx'] : -1;
                $count = (int)($row['c'] ?? 0);
                if ($count <= 0 || $idx < 0 || $idx >= $periodDays) { continue; }
                $activitySeries['payout'][$idx] = $count;
            }
            $res->free();
        }
        $st->close();
    }

    // Aggregate signups from both sources with per-day unique user de-duplication
    // We'll bucket by day index using start timestamp to avoid date-string/zone mismatches
    // $startTs already computed above
    $signupUsersByBucket = []; // bucketIdx => [userId => true]
    if ($st = $conn->prepare('SELECT id, UNIX_TIMESTAMP(created_at) AS ts FROM users WHERE referred_by = ? AND created_at >= ?')) {
        $st->bind_param('is', $uid, $periodCutoff);
        $st->execute();
        if ($res = $st->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $ts = isset($row['ts']) ? (int)$row['ts'] : 0;
                $userId = (int)($row['id'] ?? 0);
                if ($ts <= 0 || $userId <= 0) { continue; }
                $idx = (int)floor(($ts - $startTs) / 86400);
                if ($idx < 0 || $idx >= $periodDays) { continue; }
                if (!isset($signupUsersByBucket[$idx])) { $signupUsersByBucket[$idx] = []; }
                $signupUsersByBucket[$idx][$userId] = true;
            }
            $res->free();
        }
        $st->close();
    }

    // 2) Also collect from referral_events signup events (covers cases where assignment happened on event level)
    if ($st = $conn->prepare("SELECT user_id, UNIX_TIMESTAMP(created_at) AS ts FROM referral_events WHERE referrer_user_id = ? AND type = 'signup' AND created_at >= ?")) {
        $st->bind_param('is', $uid, $periodCutoff);
        $st->execute();
        if ($res = $st->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $ts = isset($row['ts']) ? (int)$row['ts'] : 0;
                $userId = (int)($row['user_id'] ?? 0);
                if ($ts <= 0 || $userId <= 0) { continue; }
                $idx = (int)floor(($ts - $startTs) / 86400);
                if ($idx < 0 || $idx >= $periodDays) { continue; }
                if (!isset($signupUsersByBucket[$idx])) { $signupUsersByBucket[$idx] = []; }
                $signupUsersByBucket[$idx][$userId] = true; // de-dupe across sources
            }
            $res->free();
        }
        $st->close();
    }

    // 3) Build signup series as counts of unique users per day
    $signupSeries = array_fill(0, $periodDays, 0);
    foreach ($signupUsersByBucket as $idx => $usersSet) {
        $idx = (int)$idx;
        if ($idx < 0 || $idx >= $periodDays) { continue; }
        $signupSeries[$idx] = count($usersSet);
    }
    // Combine with raw referral_events signup counts as a fallback to avoid showing empty series
    // Take the maximum per day between deduped user series and pre-aggregated event counts
    $combinedSignup = $signupSeries;
    if (isset($activitySeries['signup']) && is_array($activitySeries['signup'])) {
        for ($i = 0; $i < $periodDays; $i++) {
            $existing = isset($activitySeries['signup'][$i]) ? (int)$activitySeries['signup'][$i] : 0;
            if ($existing > $combinedSignup[$i]) { $combinedSignup[$i] = $existing; }
        }
    }
    $activitySeries['signup'] = $combinedSignup;

    // Paginated list of recent click events within the window
    if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM referral_events WHERE referrer_user_id = ? AND type = 'click' AND created_at >= ?")) {
        $st->bind_param('is', $uid, $periodCutoff);
        $st->execute();
        $st->bind_result($totalClicks);
        if ($st->fetch()) {
            $totalClicks = (int)$totalClicks;
            $clickPagination['total'] = $totalClicks;
            $clickPagination['pages'] = max(1, (int)ceil($totalClicks / $clicksPerPage));
            if ($clickPage > $clickPagination['pages']) {
                $clickPage = $clickPagination['pages'];
                $clickPagination['page'] = $clickPage;
            }
        }
        $st->close();
    }

    $offset = ($clickPage - 1) * $clicksPerPage;
    if ($clickPagination['total'] > 0) {
        if ($st = $conn->prepare("SELECT id, code, meta_json, created_at FROM referral_events WHERE referrer_user_id = ? AND type = 'click' AND created_at >= ? ORDER BY created_at DESC LIMIT ? OFFSET ?")) {
            $st->bind_param('isii', $uid, $periodCutoff, $clicksPerPage, $offset);
            $st->execute();
            if ($res = $st->get_result()) {
                while ($row = $res->fetch_assoc()) {
                    $meta = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
                    $row['ip'] = (string)($meta['ip'] ?? '');
                    $uaFull = (string)($meta['ua'] ?? '');
                    $row['ua'] = $uaFull;
                    if ($uaFull !== '') {
                        if (function_exists('mb_strimwidth')) {
                            $row['ua_short'] = mb_strimwidth($uaFull, 0, 78, '…', 'UTF-8');
                        } else {
                            $row['ua_short'] = strlen($uaFull) > 78 ? substr($uaFull, 0, 75) . '...' : $uaFull;
                        }
                    } else {
                        $row['ua_short'] = '';
                    }
                    $refererFull = (string)($meta['referer'] ?? '');
                    $row['referer'] = $refererFull;
                    if ($refererFull !== '') {
                        $row['referer_short'] = $refererFull;
                        if (function_exists('mb_strimwidth')) {
                            $row['referer_short'] = mb_strimwidth($refererFull, 0, 78, '…', 'UTF-8');
                        } else {
                            $row['referer_short'] = strlen($refererFull) > 78 ? substr($refererFull, 0, 75) . '...' : $refererFull;
                        }
                        $parsed = @parse_url($refererFull);
                        $row['referer_host'] = isset($parsed['host']) ? (string)$parsed['host'] : '';
                    } else {
                        $row['referer_short'] = '';
                        $row['referer_host'] = '';
                    }
                    $clickEvents[] = $row;
                }
                $res->free();
            }
            $st->close();
        }
    }

    $activityTotals = [
        'click' => array_sum($activitySeries['click']),
        'signup' => array_sum($activitySeries['signup']),
        'payout' => array_sum($activitySeries['payout']),
    ];
    $periodTotals = $activityTotals;
} catch (Throwable $e) {
    // leave defaults on failure
}


$conn->close();
$refEnabled = get_setting('referral_enabled', '0') === '1';
$accrualBasis = get_setting('referral_accrual_basis', 'spend');
$periodStartLabel = $activitySeries['labels'][0] ?? date('Y-m-d');
$periodEndLabel = $activitySeries['labels'] ? $activitySeries['labels'][count($activitySeries['labels']) - 1] : $periodStartLabel;
$chartPayload = [
    'labels' => $activitySeries['labels'],
    'click' => $activitySeries['click'],
    'signup' => $activitySeries['signup'],
    'payout' => $activitySeries['payout'],
];
$chartPayloadJson = json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($chartPayloadJson === false) { $chartPayloadJson = '{}'; }
$paginationBasePath = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
$clicksQuery = $_GET;
unset($clicksQuery['page']);
$buildClicksPageUrl = function(int $page) use ($paginationBasePath, $clicksQuery): string {
    $query = $clicksQuery;
    if ($page > 1) {
        $query['clicks_page'] = $page;
    } else {
        unset($query['clicks_page']);
    }
    $qs = $query ? ('?' . http_build_query($query)) : '';
    return htmlspecialchars($paginationBasePath . $qs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>

<?php
$pp_container = false;
$GLOBALS['pp_layout_has_sidebar'] = true;
include '../includes/header.php';
include __DIR__ . '/../includes/client_sidebar.php';
?>
<div class="main-content fade-in referral-dashboard">
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h4 mb-1"><?php echo __('Партнёрская программа'); ?></h1>
                <p class="text-muted mb-0 small">
                    <?php echo $accrualBasis === 'spend'
                        ? __('Делитесь ссылкой, привлекайте друзей и получайте процент от их трат в сервисе.')
                        : __('Делитесь ссылкой, привлекайте друзей и получайте процент от их пополнений.'); ?>
                </p>
            </div>
            <?php if (!$refEnabled): ?>
                <span class="badge bg-secondary"><?php echo __('Отключено администратором'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-0 ref-chart-card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
                <div>
                    <h2 class="h5 mb-1"><?php echo __('Диаграмма активности'); ?></h2>
                    <p class="text-muted small mb-0"><?php echo sprintf(__('Количество переходов, регистраций и начислений за последние %d дней.'), $periodDays); ?></p>
                </div>
                <div class="text-muted small">
                    <?php echo sprintf(__('Период: %s — %s'), htmlspecialchars(date('d.m.Y', strtotime($periodStartLabel))), htmlspecialchars(date('d.m.Y', strtotime($periodEndLabel)))); ?>
                </div>
            </div>
            <div class="ref-chart-wrapper mt-3">
                <canvas id="referralActivityChart"></canvas>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card ref-metric-card ref-metric-click h-100 text-white">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <p class="text-uppercase small fw-semibold mb-1 opacity-75"><?php echo __('Переходы за период'); ?></p>
                            <div class="display-6 fw-bold mb-0"><?php echo number_format((int)$periodTotals['click'], 0, '.', ' '); ?></div>
                        </div>
                        <div class="ref-metric-icon"><i class="bi bi-graph-up"></i></div>
                    </div>
                    <p class="small mt-3 mb-0 opacity-75"><?php echo sprintf(__('за последние %d дней'), $periodDays); ?></p>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card ref-metric-card ref-metric-signup h-100 text-white">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <p class="text-uppercase small fw-semibold mb-1 opacity-75"><?php echo __('Всего регистраций'); ?></p>
                            <div class="display-6 fw-bold mb-0"><?php echo number_format((int)$totalUsers, 0, '.', ' '); ?></div>
                        </div>
                        <div class="ref-metric-icon"><i class="bi bi-person-check"></i></div>
                    </div>
                    <p class="small mt-3 mb-0 opacity-75"><?php echo __('общее количество'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card ref-metric-card ref-metric-payout h-100 text-white">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <p class="text-uppercase small fw-semibold mb-1 opacity-75"><?php echo __('Начисления за период'); ?></p>
                            <div class="display-6 fw-bold mb-0"><?php echo number_format((int)$periodTotals['payout'], 0, '.', ' '); ?></div>
                        </div>
                        <div class="ref-metric-icon"><i class="bi bi-cash-stack"></i></div>
                    </div>
                    <p class="small mt-3 mb-0 opacity-75"><?php echo sprintf(__('за последние %d дней'), $periodDays); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card ref-link-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <h2 class="h6 text-uppercase fw-semibold mb-2"><?php echo __('Ваша реферальная ссылка'); ?></h2>
                            <p class="text-muted small mb-0"><?php echo __('Делитесь ссылкой, чтобы получать вознаграждение за каждую активность приглашённых пользователей.'); ?></p>
                        </div>
                        <span class="badge bg-primary-subtle text-primary"><?php echo sprintf(__('Cookie %d дней'), $cookieDays); ?></span>
                    </div>
                    <div class="input-group shadow-sm">
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($refLink); ?>" id="refLink" readonly>
                        <button class="btn btn-primary" type="button" id="copyRefLink"><i class="bi bi-clipboard"></i> <?php echo __('Копировать'); ?></button>
                    </div>
                    <ul class="list-unstyled small text-muted mt-3 mb-0">
                        <li><i class="bi bi-check-circle me-2 text-success"></i><?php echo sprintf(__('Базовая ставка: %s%%'), number_format($defaultPercent, 2)); ?></li>
                        <li><i class="bi bi-check-circle me-2 text-success"></i><?php echo $accrualBasis === 'spend' ? __('Начисления рассчитываются от трат рефералов.') : __('Начисления рассчитываются от пополнений рефералов.'); ?></li>
                        <?php if ($personalPercent > 0 && abs($personalPercent - $defaultPercent) > 0.001): ?>
                            <li><i class="bi bi-star-fill me-2 text-warning"></i><?php echo __('Ваша индивидуальная ставка'); ?>: <strong><?php echo number_format($personalPercent, 2); ?>%</strong></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card ref-stats-card h-100">
                <div class="card-body">
                    <h2 class="h6 text-uppercase fw-semibold mb-3"><?php echo __('Итоги программы'); ?></h2>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="stat-tile shadow-sm">
                                <div class="stat-label"><?php echo __('Привлечено пользователей'); ?></div>
                                <div class="stat-value"><?php echo number_format((int)$totalUsers, 0, '.', ' '); ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="stat-tile shadow-sm">
                                <div class="stat-label"><?php echo __('Заработано всего'); ?></div>
                                <div class="stat-value"><?php echo htmlspecialchars(format_currency($totalEarnings)); ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="stat-tile shadow-sm">
                                <div class="stat-label"><?php echo __('Текущая ставка'); ?></div>
                                <div class="stat-value"><?php echo number_format(($personalPercent > 0 ? $personalPercent : $defaultPercent), 2); ?>%</div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="stat-tile shadow-sm">
                                <div class="stat-label"><?php echo __('Начислений за период'); ?></div>
                                <div class="stat-value"><?php echo number_format((int)$periodTotals['payout'], 0, '.', ' '); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><i class="bi bi-cash-stack me-2 text-success"></i><?php echo __('Недавние начисления'); ?></h2>
                </div>
                <div class="card-body">
                    <?php if (empty($recentEvents)): ?>
                        <p class="text-muted mb-0"><?php echo __('Пока нет начислений.'); ?></p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo __('Дата'); ?></th>
                                        <th class="text-end"><?php echo __('Сумма'); ?></th>
                                        <th class="text-end"><?php echo __('Процент'); ?></th>
                                        <th class="text-end"><?php echo __('Пользователь'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentEvents as $e): ?>
                                        <tr>
                                            <td class="text-muted"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)$e['created_at']))); ?></td>
                                            <td class="text-end text-success"><?php echo htmlspecialchars(format_currency($e['delta'])); ?></td>
                                            <td class="text-end"><?php echo number_format($e['percent'], 2); ?>%</td>
                                            <td class="text-end">#<?php echo (int)$e['from_user_id']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><i class="bi bi-people me-2 text-primary"></i><?php echo __('Новые рефералы'); ?></h2>
                </div>
                <div class="card-body">
                    <?php if (empty($referredUsers)): ?>
                        <p class="text-muted mb-0"><?php echo __('Пока нет рефералов.'); ?></p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo __('ID'); ?></th>
                                        <th><?php echo __('Логин'); ?></th>
                                        <th><?php echo __('Дата'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($referredUsers as $ru): ?>
                                        <tr>
                                            <td>#<?php echo (int)$ru['id']; ?></td>
                                            <td><?php echo htmlspecialchars($ru['username']); ?></td>
                                            <td class="text-muted"><?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$ru['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mt-4 ref-click-table">
        <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0"><i class="bi bi-mouse me-2 text-info"></i><?php echo __('Переходы по реферальной ссылке'); ?></h2>
            <span class="text-muted small"><?php echo sprintf(__('Всего за период: %d'), (int)$clickPagination['total']); ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($clickEvents)): ?>
                <p class="text-muted mb-0"><?php echo __('Пока нет переходов за выбранный период.'); ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th><?php echo __('Дата'); ?></th>
                                <th><?php echo __('IP'); ?></th>
                                <th><?php echo __('Источник'); ?></th>
                                <th><?php echo __('User-Agent'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clickEvents as $click): ?>
                                <tr>
                                    <td class="text-muted"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)$click['created_at']))); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($click['ip'] ?: __('Неизвестно')); ?></span></td>
                                    <td>
                                        <?php if (!empty($click['referer'])): ?>
                                            <a href="<?php echo htmlspecialchars($click['referer']); ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                                <?php echo htmlspecialchars($click['referer_host'] ?: $click['referer_short']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ua-cell text-muted" title="<?php echo htmlspecialchars($click['ua']); ?>"><?php echo htmlspecialchars($click['ua_short']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($clickPagination['pages'] > 1): ?>
                    <?php
                        $currentPage = (int)$clickPagination['page'];
                        $totalPages = (int)$clickPagination['pages'];
                        $rangeStart = max(1, $currentPage - 2);
                        $rangeEnd = min($totalPages, $currentPage + 2);
                    ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center pt-2">
                        <span class="text-muted small"><?php echo sprintf(__('Страница %d из %d'), $currentPage, $totalPages); ?></span>
                        <nav aria-label="<?php echo __('Навигация по страницам'); ?>">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $currentPage <= 1 ? '#' : $buildClicksPageUrl($currentPage - 1); ?>" aria-label="<?php echo __('Назад'); ?>" tabindex="<?php echo $currentPage <= 1 ? '-1' : '0'; ?>">&laquo;</a>
                                </li>
                                <?php for ($p = $rangeStart; $p <= $rangeEnd; $p++): ?>
                                    <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo $p === $currentPage ? '#' : $buildClicksPageUrl($p); ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $currentPage >= $totalPages ? '#' : $buildClicksPageUrl($currentPage + 1); ?>" aria-label="<?php echo __('Вперёд'); ?>" tabindex="<?php echo $currentPage >= $totalPages ? '-1' : '0'; ?>">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<style>
.referral-dashboard {
    color: #e2e8f0;
}
.referral-dashboard .text-muted {
    color: rgba(148, 163, 184, 0.85) !important;
}
.referral-dashboard .card:not(.ref-metric-card) {
    background-color: rgba(15, 23, 42, 0.85);
    border: 1px solid rgba(148, 163, 184, 0.12);
    color: #e2e8f0;
}
.referral-dashboard .card:not(.ref-metric-card) .card-header {
    border-color: rgba(148, 163, 184, 0.12);
    color: rgba(226, 232, 240, 0.9);
}
.referral-dashboard .card.ref-link-card,
.referral-dashboard .card.ref-stats-card,
.referral-dashboard .ref-click-table {
    backdrop-filter: blur(4px);
}
.referral-dashboard .form-control {
    background-color: rgba(15, 23, 42, 0.65);
    border-color: rgba(148, 163, 184, 0.35);
    color: #f8fafc;
}
.referral-dashboard .form-control:focus {
    background-color: rgba(15, 23, 42, 0.8);
    border-color: rgba(96, 165, 250, 0.6);
    color: #f8fafc;
    box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
}
.referral-dashboard .btn-primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    border: none;
    box-shadow: 0 0.75rem 1.8rem rgba(37, 99, 235, 0.25);
}
.ref-chart-wrapper {
    position: relative;
    min-height: 320px;
}
.ref-chart-card {
    border-radius: 1rem;
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.12), rgba(32, 201, 151, 0.1)), rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(96, 165, 250, 0.35);
}
.ref-metric-card {
    border: 0;
    border-radius: 1rem;
    box-shadow: var(--bs-box-shadow, 0 1rem 3rem rgba(0,0,0,0.08));
}
.ref-metric-icon {
    font-size: 2.5rem;
    line-height: 1;
}
.ref-metric-click {
    background: linear-gradient(135deg, #2563eb, #38bdf8);
}
.ref-metric-signup {
    background: linear-gradient(135deg, #22c55e, #4ade80);
}
.ref-metric-payout {
    background: linear-gradient(135deg, #9333ea, #c084fc);
}
.badge.bg-primary-subtle {
    background-color: rgba(13, 110, 253, 0.12);
    color: #0d6efd;
}
.ref-link-card,
.ref-stats-card {
    border: 0;
    border-radius: 1rem;
    box-shadow: var(--bs-box-shadow-sm, 0 0.5rem 1.5rem rgba(0,0,0,0.05));
}
.stat-tile {
    border-radius: 0.75rem;
    padding: 1rem 1.25rem;
    background-color: rgba(148, 163, 184, 0.16);
    color: #f8fafc;
}
.stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: rgba(148, 163, 184, 0.85);
    margin-bottom: 0.35rem;
}
.stat-value {
    font-size: 1.5rem;
    font-weight: 600;
    color: #f8fafc;
}
.ref-click-table .ua-cell {
    max-width: 260px;
    word-break: break-word;
}
.ref-click-table .table thead th {
    white-space: nowrap;
    color: rgba(226, 232, 240, 0.85);
}
.ref-click-table .table {
    color: #e2e8f0;
}
.ref-click-table .table tbody tr {
    border-color: rgba(148, 163, 184, 0.12);
}
.ref-click-table .table a {
    color: #93c5fd;
}
.referral-dashboard .pagination .page-link {
    background-color: rgba(15, 23, 42, 0.65);
    border-color: rgba(148, 163, 184, 0.3);
    color: #e2e8f0;
}
.referral-dashboard .pagination .page-item.active .page-link {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
.referral-dashboard .pagination .page-item.disabled .page-link {
    opacity: 0.45;
}
@media (max-width: 767.98px) {
    .ref-chart-wrapper {
        min-height: 260px;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const chartElement = document.getElementById('referralActivityChart');
    const chartPayload = <?php echo $chartPayloadJson; ?> || {};
    if (chartElement && window.Chart && Array.isArray(chartPayload.labels)) {
        const labels = chartPayload.labels ?? [];
        const clickData = chartPayload.click ?? [];
        const signupData = chartPayload.signup ?? [];
        const payoutData = chartPayload.payout ?? [];
        console.log('Chart data:', {labels, clickData, signupData, payoutData});
        const ctx = chartElement.getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: '<?php echo __('Переходы'); ?>',
                        data: clickData,
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13, 110, 253, 0.15)',
                        pointBackgroundColor: '#0d6efd',
                        tension: 0.35,
                        fill: true,
                    },
                    {
                        label: '<?php echo __('Регистрации'); ?>',
                        data: signupData,
                        borderColor: '#20c997',
                        backgroundColor: 'rgba(32, 201, 151, 0.15)',
                        pointBackgroundColor: '#20c997',
                        tension: 0.35,
                        fill: true,
                    },
                    {
                        label: '<?php echo __('Начисления'); ?>',
                        data: payoutData,
                        borderColor: '#6f42c1',
                        backgroundColor: 'rgba(111, 66, 193, 0.15)',
                        pointBackgroundColor: '#6f42c1',
                        tension: 0.35,
                        fill: true,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: '#e2e8f0',
                            usePointStyle: true,
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.92)',
                        titleColor: '#f8fafc',
                        bodyColor: '#f8fafc',
                        callbacks: {
                            label: function(context) {
                                const label = context.dataset.label || '';
                                const value = Number(context.raw || 0);
                                return `${label}: ${value}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(148, 163, 184, 0.15)'
                        },
                        ticks: {
                            color: '#cbd5f5'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            stepSize: 1,
                            color: '#cbd5f5'
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.18)'
                        }
                    }
                }
            }
        });
    }

    const btn = document.getElementById('copyRefLink');
    const input = document.getElementById('refLink');
    if (btn && input) {
        btn.addEventListener('click', async function(){
            input.select();
            input.setSelectionRange(0, 99999);
            try { await navigator.clipboard.writeText(input.value); } catch (e) { /* ignore */ }
        });
    }
});
</script>
<?php include '../includes/footer.php'; ?>
