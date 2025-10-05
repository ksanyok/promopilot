<?php
$metrics = is_array($overviewMetrics ?? null) ? $overviewMetrics : [];
$topUsers = is_array($overviewTopUsers ?? null) ? $overviewTopUsers : [];
$topUsers += ['spenders' => [], 'depositors' => []];
$topProjects = is_array($overviewTopProjects ?? null) ? $overviewTopProjects : [];
$recentTransactions = is_array($overviewRecentTransactions ?? null) ? $overviewRecentTransactions : [];
$chartActivity = is_array($overviewChartData['activity'] ?? null) ? $overviewChartData['activity'] : ['labels' => [], 'projects' => [], 'promotions' => []];
$chartActivity += ['labels' => [], 'projects' => [], 'promotions' => []];
$chartFinance = is_array($overviewChartData['finance'] ?? null) ? $overviewChartData['finance'] : ['labels' => [], 'topups' => [], 'spend' => []];
$chartFinance += ['labels' => [], 'topups' => [], 'spend' => []];
$currencyCode = function_exists('get_currency_code') ? get_currency_code() : 'RUB';
$formatAmount = static function ($value) use ($currencyCode) {
    if (function_exists('format_currency')) {
        return format_currency($value);
    }
    $num = is_numeric($value) ? number_format((float)$value, 2, '.', ' ') : (string)$value;
    return $num . ' ' . $currencyCode;
};
$formatNumber = static function ($value) {
    return number_format((int)$value, 0, '.', ' ');
};
$formatDate = static function ($value, bool $withTime = false) {
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime((string)$value);
    if (!$ts) {
        return '—';
    }
    return $withTime ? date('Y-m-d H:i', $ts) : date('Y-m-d', $ts);
};
$formatTxnAmount = static function (array $txn): string {
    $amount = isset($txn['amount']) ? (float)$txn['amount'] : 0.0;
    $currency = strtoupper(trim((string)($txn['currency'] ?? '')));
    $num = number_format($amount, 2, '.', ' ');
    return $currency !== '' ? ($num . ' ' . $currency) : $num;
};
$avgTicket = (($metrics['completed_runs'] ?? 0) > 0 && ($metrics['spend_total'] ?? 0) > 0)
    ? ($metrics['spend_total'] / max(1, (int)$metrics['completed_runs']))
    : 0.0;
$activeRuns = (int)($metrics['active_runs'] ?? 0);
$totalUsers = (int)($metrics['total_users'] ?? 0);
$totalProjects = (int)($metrics['total_projects'] ?? 0);
$recentRuns = (int)($metrics['promotion_runs_30d'] ?? 0);
$spendTotal = (float)($metrics['spend_total'] ?? 0);
$spend30d = (float)($metrics['spend_30d'] ?? 0);
$topupsTotal = (float)($metrics['topups_total'] ?? 0);
$topups30d = (float)($metrics['topups_30d'] ?? 0);
$topupsCount30d = (int)($metrics['topups_count_30d'] ?? 0);
$newUsers30d = (int)($metrics['new_users_30d'] ?? 0);
$newProjects30d = (int)($metrics['new_projects_30d'] ?? 0);
$topupsTotalCount = (int)($metrics['topups_total_count'] ?? 0);
$activityPayload = [
    'labels' => array_values($chartActivity['labels']),
    'projects' => array_map('intval', $chartActivity['projects']),
    'promotions' => array_map('intval', $chartActivity['promotions']),
];
$financePayload = [
    'labels' => array_values($chartFinance['labels']),
    'topups' => array_map('floatval', $chartFinance['topups']),
    'spend' => array_map('floatval', $chartFinance['spend']),
];
$statusClassMap = [
    'confirmed' => 'badge bg-success-subtle text-success-emphasis',
    'pending' => 'badge bg-warning-subtle text-warning-emphasis',
    'awaiting_confirmation' => 'badge bg-warning-subtle text-warning-emphasis',
    'failed' => 'badge bg-danger-subtle text-danger-emphasis',
    'cancelled' => 'badge bg-secondary-subtle text-secondary-emphasis',
    'canceled' => 'badge bg-secondary-subtle text-secondary-emphasis',
    'expired' => 'badge bg-secondary-subtle text-secondary-emphasis',
];
?>
<div id="overview-section">
    <div class="overview-hero mb-4">
        <div class="overview-hero__content d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
            <div>
                <h3 class="overview-hero__title mb-2"><?php echo htmlspecialchars(__('Обзор платформы'), ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="overview-hero__subtitle mb-0 text-muted">
                    <?php echo htmlspecialchars(sprintf(__('Активных кампаний: %s • Пользователей: %s'), $formatNumber($activeRuns), $formatNumber($totalUsers)), ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <div class="overview-hero__metrics text-md-end">
                <div class="overview-hero__amount"><?php echo htmlspecialchars($formatAmount($spendTotal), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="overview-hero__hint text-muted small"><?php echo htmlspecialchars(__('Общий расход по продвижению'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
        <div class="overview-hero__chips mt-3 d-flex flex-wrap gap-2">
            <span class="overview-chip"><?php echo htmlspecialchars(sprintf(__('Пополнений всего: %s'), $formatNumber($topupsTotalCount)), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="overview-chip"><?php echo htmlspecialchars(sprintf(__('Средний чек: %s'), $formatAmount($avgTicket)), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>

    <div class="row g-3 overview-metrics-row mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="overview-metric-card overview-metric-card--users h-100">
                <div class="metric-label"><?php echo htmlspecialchars(__('Пользователи'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-value"><?php echo htmlspecialchars($formatNumber($totalUsers), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-change text-muted small"><?php echo htmlspecialchars(sprintf(__('Новые за 30 дней: %s'), $formatNumber($newUsers30d)), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="overview-metric-card overview-metric-card--projects h-100">
                <div class="metric-label"><?php echo htmlspecialchars(__('Проекты'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-value"><?php echo htmlspecialchars($formatNumber($totalProjects), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-change text-muted small"><?php echo htmlspecialchars(sprintf(__('Новых за 30 дней: %s'), $formatNumber($newProjects30d)), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="overview-metric-card overview-metric-card--spend h-100">
                <div class="metric-label"><?php echo htmlspecialchars(__('Расходы (30 дней)'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-value"><?php echo htmlspecialchars($formatAmount($spend30d), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-change text-muted small"><?php echo htmlspecialchars(sprintf(__('Запусков: %s'), $formatNumber($recentRuns)), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="overview-metric-card overview-metric-card--topups h-100">
                <div class="metric-label"><?php echo htmlspecialchars(__('Пополнения (30 дней)'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-value"><?php echo htmlspecialchars($formatAmount($topups30d), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="metric-change text-muted small"><?php echo htmlspecialchars(sprintf(__('Транзакций: %s'), $formatNumber($topupsCount30d)), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card overview-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="card-title mb-0"><?php echo htmlspecialchars(__('Дневная активность'), ENT_QUOTES, 'UTF-8'); ?></h5>
                        <span class="text-muted small"><?php echo htmlspecialchars(__('Последние 30 дней'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="overviewActivityChart" height="260"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card overview-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="card-title mb-0"><?php echo htmlspecialchars(__('Денежный поток'), ENT_QUOTES, 'UTF-8'); ?></h5>
                        <span class="text-muted small"><?php echo htmlspecialchars(__('Последние 30 дней'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="overviewFinanceChart" height="260"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card overview-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><?php echo htmlspecialchars(__('Топ клиентов по расходам'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <?php if (!empty($topUsers['spenders'])): ?>
                        <ol class="overview-list">
                            <?php foreach ($topUsers['spenders'] as $index => $user): ?>
                                <li>
                                    <div class="overview-list__item">
                                        <span class="overview-list__index"><?php echo (int)$index + 1; ?></span>
                                        <div>
                                            <div class="overview-list__title"><?php echo htmlspecialchars($user['username'] ?: __('Без имени'), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="overview-list__meta text-muted small">
                                                <?php echo htmlspecialchars(sprintf(__('Запусков: %d'), (int)($user['runs'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if (!empty($user['last'])): ?>
                                                    • <?php echo htmlspecialchars($formatDate($user['last']), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="overview-list__value">
                                        <?php echo htmlspecialchars($formatAmount($user['total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars(__('Данные отсутствуют'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card overview-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><?php echo htmlspecialchars(__('Топ пополнений'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <?php if (!empty($topUsers['depositors'])): ?>
                        <ol class="overview-list">
                            <?php foreach ($topUsers['depositors'] as $index => $user): ?>
                                <li>
                                    <div class="overview-list__item">
                                        <span class="overview-list__index"><?php echo (int)$index + 1; ?></span>
                                        <div>
                                            <div class="overview-list__title"><?php echo htmlspecialchars($user['username'] ?: __('Без имени'), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="overview-list__meta text-muted small">
                                                <?php echo htmlspecialchars(sprintf(__('Транзакций: %d'), (int)($user['txns'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if (!empty($user['last'])): ?>
                                                    • <?php echo htmlspecialchars($formatDate($user['last']), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="overview-list__value">
                                        <?php echo htmlspecialchars($formatAmount($user['total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars(__('Данные отсутствуют'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card overview-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><?php echo htmlspecialchars(__('Проекты с наибольшими затратами'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <?php if (!empty($topProjects)): ?>
                        <ul class="overview-list overview-list--plain">
                            <?php foreach ($topProjects as $project): ?>
                                <li>
                                    <div>
                                        <div class="overview-list__title"><?php echo htmlspecialchars($project['name'] ?: __('Без названия'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="overview-list__meta text-muted small">
                                            <?php echo htmlspecialchars(sprintf(__('Клиент: %s'), $project['owner'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($project['runs'])): ?>
                                                • <?php echo htmlspecialchars(sprintf(__('Запусков: %d'), (int)$project['runs']), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($project['last'])): ?>
                                                • <?php echo htmlspecialchars($formatDate($project['last']), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="overview-list__value">
                                        <?php echo htmlspecialchars($formatAmount($project['total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars(__('Данные отсутствуют'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card overview-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><?php echo htmlspecialchars(__('Последние транзакции'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <?php if (!empty($recentTransactions)): ?>
                        <ul class="overview-list overview-list--plain">
                            <?php foreach ($recentTransactions as $txn): ?>
                                <li>
                                    <div>
                                        <div class="overview-list__title"><?php echo htmlspecialchars($txn['username'] ?: __('Неизвестный пользователь'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="overview-list__meta text-muted small">
                                            <?php echo htmlspecialchars($formatDate($txn['confirmed_at'] ?: $txn['created_at'], true), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($txn['gateway'])): ?>
                                                • <?php echo htmlspecialchars(strtoupper((string)$txn['gateway']), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="overview-list__value text-end">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($formatTxnAmount($txn), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php
                                            $statusKey = strtolower((string)($txn['status'] ?? ''));
                                            $statusClass = $statusClassMap[$statusKey] ?? 'badge bg-secondary';
                                            $statusLabel = function_exists('pp_payment_transaction_status_label')
                                                ? pp_payment_transaction_status_label((string)($txn['status'] ?? ''))
                                                : (string)($txn['status'] ?? '');
                                        ?>
                                        <span class="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars(__('Данные отсутствуют'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if (!defined('PP_CHART_JS_INCLUDED')): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <?php define('PP_CHART_JS_INCLUDED', true); ?>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const activityData = <?php echo json_encode($activityPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const financeData = <?php echo json_encode($financePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const currencyCode = <?php echo json_encode($currencyCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const numberFormatter = new Intl.NumberFormat('ru-RU');
    const currencyFormatter = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (typeof Chart === 'undefined') {
        return;
    }

    const activityCanvas = document.getElementById('overviewActivityChart');
    if (activityCanvas && activityData.labels && activityData.labels.length) {
        new Chart(activityCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: activityData.labels,
                datasets: [
                    {
                        label: <?php echo json_encode(__('Новые проекты'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                        data: activityData.projects,
                        borderColor: 'rgba(77, 163, 255, 0.9)',
                        backgroundColor: 'rgba(77, 163, 255, 0.20)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 2
                    },
                    {
                        label: <?php echo json_encode(__('Запуски продвижения'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                        data: activityData.promotions,
                        borderColor: 'rgba(61, 220, 151, 0.9)',
                        backgroundColor: 'rgba(61, 220, 151, 0.18)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        labels: { color: 'rgba(214,223,241,0.85)' }
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const value = context.parsed.y ?? 0;
                                return `${context.dataset.label}: ${numberFormatter.format(value)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: 'rgba(198,208,231,0.72)' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: 'rgba(198,208,231,0.72)', precision: 0 },
                        grid: { color: 'rgba(255,255,255,0.08)' }
                    }
                }
            }
        });
    }

    const financeCanvas = document.getElementById('overviewFinanceChart');
    if (financeCanvas && financeData.labels && financeData.labels.length) {
        new Chart(financeCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: financeData.labels,
                datasets: [
                    {
                        label: <?php echo json_encode(__('Пополнения'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                        data: financeData.topups,
                        borderColor: 'rgba(124, 77, 255, 0.95)',
                        backgroundColor: 'rgba(124, 77, 255, 0.18)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 2
                    },
                    {
                        label: <?php echo json_encode(__('Расходы'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                        data: financeData.spend,
                        borderColor: 'rgba(255, 176, 32, 0.9)',
                        backgroundColor: 'rgba(255, 176, 32, 0.16)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        labels: { color: 'rgba(214,223,241,0.85)' }
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const value = context.parsed.y ?? 0;
                                return `${context.dataset.label}: ${currencyFormatter.format(value)} ${currencyCode}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: 'rgba(198,208,231,0.72)' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: 'rgba(198,208,231,0.72)',
                            callback(value) {
                                return currencyFormatter.format(value);
                            }
                        },
                        grid: { color: 'rgba(255,255,255,0.08)' }
                    }
                }
            }
        });
    }
});
</script>
