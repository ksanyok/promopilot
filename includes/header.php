<?php
require_once __DIR__ . '/init.php';
// Fetch current client user short info for navbar avatar/name
$pp_nav_user = null;
$pp_nav_balance = null;
$pp_nav_balance_raw = null;
$pp_nav_balance_locale = null;
$pp_nav_balance_currency = null;
$pp_nav_notifications_unread = 0;
if (is_logged_in()) {
    try {
        $conn = connect_db();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $st = $conn->prepare("SELECT username, full_name, avatar, google_picture, balance FROM users WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $uid);
                $st->execute();
                $r = $st->get_result();
                $pp_nav_user = $r->fetch_assoc() ?: null;
                if ($pp_nav_user && !is_admin()) {
                    $pp_nav_balance_raw = (float)($pp_nav_user['balance'] ?? 0);
                    $pp_nav_balance = format_currency($pp_nav_balance_raw);
                }
                $st->close();
            }

            // Client mini-stats: projects count, active promotion links, published links
            if (!is_admin()) {
                $pp_nav_stats = [ 'projects' => 0, 'active_links' => 0, 'published_links' => 0 ];
                // Total projects
                if ($stp = $conn->prepare('SELECT COUNT(*) AS cnt FROM projects WHERE user_id = ?')) {
                    $stp->bind_param('i', $uid);
                    $stp->execute();
                    if ($res = $stp->get_result()) { $pp_nav_stats['projects'] = (int)($res->fetch_assoc()['cnt'] ?? 0); }
                    $stp->close();
                }
                // Active promotion runs (links in promotion stage)
                $sqlActive = "SELECT COUNT(*) AS cnt FROM promotion_runs pr INNER JOIN projects p ON p.id = pr.project_id WHERE p.user_id = ? AND pr.status IN ('queued','pending_level1','running','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready')";
                if ($sta = $conn->prepare($sqlActive)) {
                    $sta->bind_param('i', $uid);
                    $sta->execute();
                    if ($res = $sta->get_result()) { $pp_nav_stats['active_links'] = (int)($res->fetch_assoc()['cnt'] ?? 0); }
                    $sta->close();
                }
                // Promoted links (unique links with at least one promotion run)
                $sqlPromoted = "SELECT COUNT(DISTINCT pr.link_id) AS cnt FROM promotion_runs pr INNER JOIN projects p ON p.id = pr.project_id WHERE p.user_id = ?";
                if ($stPromoted = $conn->prepare($sqlPromoted)) {
                    $stPromoted->bind_param('i', $uid);
                    $stPromoted->execute();
                    if ($res = $stPromoted->get_result()) { $pp_nav_stats['published_links'] = (int)($res->fetch_assoc()['cnt'] ?? 0); }
                    $stPromoted->close();
                }

                if ($conn) {
                    if ($stNotif = $conn->prepare('SELECT COUNT(*) AS cnt FROM user_notifications WHERE user_id = ? AND is_read = 0')) {
                        $stNotif->bind_param('i', $uid);
                        $stNotif->execute();
                        if ($res = $stNotif->get_result()) {
                            $pp_nav_notifications_unread = (int)($res->fetch_assoc()['cnt'] ?? 0);
                        }
                        $stNotif->close();
                    }
                }
            }
        }
        $conn->close();
    } catch (Throwable $e) { /* ignore */ }
}

if ($pp_nav_balance !== null) {
    $rawLocale = isset($current_lang) && $current_lang === 'en' ? 'en-US' : 'ru-RU';
    $pp_nav_balance_locale = $rawLocale;
    $pp_nav_balance_currency = 'RUB';
    $symbolCandidate = trim(preg_replace('/[0-9\s.,-]/u', '', (string)$pp_nav_balance));
    if ($symbolCandidate === '$') { $pp_nav_balance_currency = 'USD'; }
    elseif ($symbolCandidate === '€') { $pp_nav_balance_currency = 'EUR'; }
    elseif (strcasecmp($symbolCandidate, '₴') === 0) { $pp_nav_balance_currency = 'UAH'; }
    elseif (strcasecmp($symbolCandidate, '₸') === 0) { $pp_nav_balance_currency = 'KZT'; }
    elseif ($symbolCandidate === '₽' || $symbolCandidate === 'р' || $symbolCandidate === 'руб') { $pp_nav_balance_currency = 'RUB'; }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <?php
    $pp_meta = isset($pp_meta) && is_array($pp_meta) ? $pp_meta : [];
    $ppBase = function_exists('pp_guess_base_url') ? pp_guess_base_url() : '';
    $metaTitle = trim((string)($pp_meta['title'] ?? 'PromoPilot')) ?: 'PromoPilot';
    $metaDescription = trim((string)($pp_meta['description'] ?? __('Каскадное продвижение с crowd-слоями и AI-контентом.')));
    $metaUrl = trim((string)($pp_meta['url'] ?? ''));
    $metaImage = trim((string)($pp_meta['image'] ?? asset_url('img/hero_main.jpg')));
    if ($metaImage && !preg_match('~^https?://~i', $metaImage)) {
        $metaImage = rtrim($ppBase, '/') . '/' . ltrim($metaImage, '/');
    }
    $metaSiteName = trim((string)($pp_meta['site_name'] ?? 'PromoPilot')) ?: 'PromoPilot';
    $metaAuthor = trim((string)($pp_meta['author'] ?? 'PromoPilot Team')) ?: 'PromoPilot Team';
    $metaDeveloper = trim((string)($pp_meta['developer'] ?? 'BuyReadySite')) ?: 'BuyReadySite';
    $metaPriceAmount = isset($pp_meta['price_amount']) ? trim((string)$pp_meta['price_amount']) : '';
    $metaPriceCurrency = trim((string)($pp_meta['price_currency'] ?? 'USD')) ?: 'USD';
    $metaLocale = (isset($current_lang) && $current_lang === 'en') ? 'en_US' : 'ru_RU';
    $metaTwitterSite = trim((string)($pp_meta['twitter_site'] ?? ''));
    $metaTwitterCreator = trim((string)($pp_meta['twitter_creator'] ?? $metaAuthor));
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php if ($metaUrl): ?><link rel="canonical" href="<?php echo htmlspecialchars($metaUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <meta name="author" content="<?php echo htmlspecialchars($metaAuthor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta name="developer" content="<?php echo htmlspecialchars($metaDeveloper, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta name="creator" content="<?php echo htmlspecialchars($metaDeveloper, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta name="publisher" content="<?php echo htmlspecialchars($metaDeveloper, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta name="copyright" content="<?php echo htmlspecialchars($metaDeveloper, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta itemprop="name" content="<?php echo htmlspecialchars($metaTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta itemprop="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php if ($metaImage): ?><meta itemprop="image" content="<?php echo htmlspecialchars($metaImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($metaTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php if ($metaUrl): ?><meta property="og:url" content="<?php echo htmlspecialchars($metaUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <meta property="og:site_name" content="<?php echo htmlspecialchars($metaSiteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:locale" content="<?php echo htmlspecialchars($metaLocale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php if ($metaImage): ?><meta property="og:image" content="<?php echo htmlspecialchars($metaImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <?php if ($metaImage): ?><meta property="og:image:alt" content="<?php echo htmlspecialchars($metaTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <meta property="article:author" content="<?php echo htmlspecialchars($metaAuthor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="article:publisher" content="<?php echo htmlspecialchars($metaDeveloper, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php if ($metaPriceAmount !== '' && is_numeric(str_replace([' ', ','], '', $metaPriceAmount))): ?>
        <meta property="product:price:amount" content="<?php echo htmlspecialchars($metaPriceAmount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <meta property="product:price:currency" content="<?php echo htmlspecialchars($metaPriceCurrency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <meta property="og:price:amount" content="<?php echo htmlspecialchars($metaPriceAmount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <meta property="og:price:currency" content="<?php echo htmlspecialchars($metaPriceCurrency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <meta name="price" content="<?php echo htmlspecialchars($metaPriceAmount . ' ' . $metaPriceCurrency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <meta itemprop="price" content="<?php echo htmlspecialchars($metaPriceAmount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <meta itemprop="priceCurrency" content="<?php echo htmlspecialchars($metaPriceCurrency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($metaTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php if ($metaImage): ?><meta name="twitter:image" content="<?php echo htmlspecialchars($metaImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <?php if ($metaTwitterCreator !== ''): ?><meta name="twitter:creator" content="<?php echo htmlspecialchars($metaTwitterCreator, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <?php if ($metaTwitterSite !== ''): ?><meta name="twitter:site" content="<?php echo htmlspecialchars($metaTwitterSite, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css?v=' . rawurlencode(get_version())); ?>" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?php echo asset_url('img/favicon.png'); ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(get_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <script>window.CSRF_TOKEN = '<?php echo htmlspecialchars(get_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?>';</script>
    <script>window.PP_BASE_URL = <?php echo json_encode(defined('PP_BASE_URL') ? PP_BASE_URL : pp_guess_base_url(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php if (!is_admin() && $pp_nav_balance_raw !== null): ?>
    <script>window.PP_BALANCE = <?php echo json_encode((float)$pp_nav_balance_raw); ?>;</script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim($ppBase, '/') . '/assets/css/admin.css'); ?>">
</head>
<body>
    <!-- Futuristic neutral background canvas -->
    <div id="bgfx" aria-hidden="true"><canvas id="bgfx-canvas"></canvas></div>

    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <?php if (empty($pp_hide_brand_logo)): ?>
            <a class="navbar-brand d-flex align-items-center" href="<?php echo pp_url(''); ?>" title="PromoPilot" aria-label="PromoPilot">
                <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="Logo" class="brand-logo">
            </a>
            <?php endif; ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <?php if (is_logged_in()): ?>
                        <?php if (is_admin()): ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('admin/admin.php'); ?>"><i class="bi bi-speedometer2 me-1"></i><?php echo __('Админка'); ?></a></li>
                        <?php endif; ?>
                        <?php if (!is_admin() && isset($pp_nav_stats) && is_array($pp_nav_stats)): ?>
                            <li class="nav-item d-none d-md-block">
                                <div class="nav-stats-group" role="group" aria-label="<?php echo htmlspecialchars(__('Сводка по проектам')); ?>">
                                    <span class="nav-stat-chip nav-stat-chip--projects" role="button" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php echo htmlspecialchars(__('Проекты')); ?>" aria-label="<?php echo htmlspecialchars(__('Проекты')); ?>">
                                        <i class="bi bi-folder2" aria-hidden="true"></i>
                                        <span class="value"><?php echo (int)$pp_nav_stats['projects']; ?></span>
                                    </span>
                                    <span class="nav-stat-chip nav-stat-chip--active" role="button" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php echo htmlspecialchars(__('В продвижении')); ?>" aria-label="<?php echo htmlspecialchars(__('В продвижении')); ?>">
                                        <i class="bi bi-rocket-takeoff" aria-hidden="true"></i>
                                        <span class="value"><?php echo (int)$pp_nav_stats['active_links']; ?></span>
                                    </span>
                                    <span class="nav-stat-chip nav-stat-chip--published" role="button" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php echo htmlspecialchars(__('Продвинутых ссылок')); ?>" aria-label="<?php echo htmlspecialchars(__('Продвинутых ссылок')); ?>">
                                        <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                        <span class="value"><?php echo (int)$pp_nav_stats['published_links']; ?></span>
                                    </span>
                                </div>
                            </li>
                        <?php endif; ?>
                        <?php if (!is_admin()): ?>
                            <?php
                                $pp_nav_notifications_count = (int)$pp_nav_notifications_unread;
                                $pp_nav_notifications_label = $pp_nav_notifications_count > 99 ? '99+' : (string)$pp_nav_notifications_count;
                            ?>
                            <li class="nav-item dropdown nav-notifications" data-pp-notifications>
                                <a class="nav-link nav-link-icon" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" data-pp-notifications-toggle>
                                    <span class="nav-notifications__icon" aria-hidden="true">
                                        <i class="bi bi-bell"></i>
                                        <span class="nav-notifications__badge<?php echo ($pp_nav_notifications_count > 0) ? '' : ' d-none'; ?>" data-pp-notifications-badge><?php echo htmlspecialchars($pp_nav_notifications_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    </span>
                                    <span class="visually-hidden"><?php echo __('Открыть уведомления'); ?></span>
                                </a>
                                <div class="dropdown-menu dropdown-menu-end notifications-dropdown" aria-labelledby="notificationsDropdown">
                                    <div class="notifications-dropdown__header">
                                        <span><?php echo __('Уведомления'); ?></span>
                                        <button type="button" class="btn btn-link btn-sm notifications-dropdown__mark-read" data-pp-notifications-mark-all><?php echo __('Отметить прочитанными'); ?></button>
                                    </div>
                                    <div class="notifications-dropdown__content" data-pp-notifications-list>
                                        <div class="notifications-dropdown__empty text-center text-muted small py-3"
                                             data-pp-notifications-empty
                                             data-empty-text="<?php echo htmlspecialchars(__('Пока уведомлений нет.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                             data-error-text="<?php echo htmlspecialchars(__('Не удалось загрузить уведомления.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <?php echo __('Пока уведомлений нет.'); ?>
                                        </div>
                                    </div>
                                    <div class="notifications-dropdown__footer">
                                        <a href="<?php echo pp_url('client/settings.php#notifications-settings'); ?>" class="btn btn-link btn-sm">
                                            <?php echo __('Настройки уведомлений'); ?>
                                        </a>
                                    </div>
                                </div>
                            </li>
                        <?php endif; ?>
                        <?php if ($pp_nav_balance !== null): ?>
                            <li class="nav-item">
                                <a class="nav-balance-chip" href="<?php echo pp_url('client/balance.php'); ?>" title="<?php echo __('Баланс'); ?>">
                                    <i class="bi bi-lightning-charge"></i>
                                    <span class="nav-balance-chip__label"><?php echo __('Баланс'); ?></span>
                                                                        <span class="nav-balance-chip__value"
                                                                                    data-balance-target
                                                                                    data-balance-raw="<?php echo htmlspecialchars(number_format((float)$pp_nav_balance_raw, 2, '.', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                    data-balance-locale="<?php echo htmlspecialchars($pp_nav_balance_locale ?? 'ru-RU', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                    data-balance-currency="<?php echo htmlspecialchars($pp_nav_balance_currency ?? 'RUB', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                                                <?php echo htmlspecialchars($pp_nav_balance); ?>
                                                                        </span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php 
                            $dispName = '';
                            $avatarUrl = asset_url('img/logo.png');
                            if ($pp_nav_user) {
                                $dispName = trim((string)($pp_nav_user['full_name'] ?: $pp_nav_user['username']));
                                $rawAvatar = trim((string)($pp_nav_user['avatar'] ?? ''));
                                $googlePic = trim((string)($pp_nav_user['google_picture'] ?? ''));
                                if ($rawAvatar !== '') {
                                    // Assume local path
                                    $avatarUrl = pp_url($rawAvatar);
                                } elseif ($googlePic !== '') {
                                    // Use Google photo URL directly
                                    $avatarUrl = $googlePic;
                                }
                            }
                            if ($dispName === '') {
                                $dispName = is_admin() ? __('Администратор') : __('Профиль');
                            }
                        ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="avatar" class="nav-avatar">
                                <span class="d-none d-sm-inline"><?php echo htmlspecialchars($dispName); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                                <?php if (is_admin()): ?>
                                    <li><a class="dropdown-item" href="<?php echo pp_url('admin/admin.php'); ?>"><i class="bi bi-speedometer2 me-2"></i><?php echo __('Админка'); ?></a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="<?php echo pp_url('client/client.php'); ?>"><i class="bi bi-grid me-2"></i><?php echo __('Дашборд'); ?></a></li>
                                    <li><a class="dropdown-item" href="<?php echo pp_url('client/referrals.php'); ?>"><i class="bi bi-people me-2"></i><?php echo __('Рефералы'); ?></a></li>
                                    <li><a class="dropdown-item" href="<?php echo pp_url('client/settings.php'); ?>"><i class="bi bi-gear me-2"></i><?php echo __('Настройки'); ?></a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="<?php echo pp_url('auth/logout.php'); ?>" class="px-3 py-1">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-link p-0 text-start"><i class="bi bi-box-arrow-right me-2"></i><?php echo __('Выход'); ?></button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                        <?php if (isset($_SESSION['admin_user_id'])): ?>
                            <?php $retToken = action_token('admin_return', (string)$_SESSION['admin_user_id']); ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('admin/admin_return.php?t=' . urlencode($retToken)); ?>"><i class="bi bi-arrow-return-left me-1"></i><?php echo __('Вернуться в админку'); ?></a></li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('auth/login.php'); ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?php echo __('Вход'); ?></a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('auth/register.php'); ?>"><i class="bi bi-person-plus me-1"></i><?php echo __('Регистрация'); ?></a></li>
                    <?php endif; ?>
                    <li class="nav-item ms-lg-2">
                        <div class="btn-group" role="group">
                            <a href="<?php echo pp_url('public/set_lang.php?lang=ru'); ?>" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'ru') ? 'active' : ''; ?>" title="Русский" <?php echo ($current_lang == 'ru') ? 'aria-current="true"' : ''; ?>>RU</a>
                            <a href="<?php echo pp_url('public/set_lang.php?lang=en'); ?>" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'en') ? 'active' : ''; ?>" title="English" <?php echo ($current_lang == 'en') ? 'aria-current="true"' : ''; ?>>EN</a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php
    $pp_page_wrap_classes = ['page-wrap'];
    if (!empty($GLOBALS['pp_layout_has_sidebar'])) {
        $pp_page_wrap_classes[] = 'layout-has-sidebar';
    }
    if (isset($pp_container_class) && is_string($pp_container_class) && strpos($pp_container_class, 'static-page') !== false) {
        $pp_page_wrap_classes[] = 'layout-static';
    }
    ?>
    <main class="<?php echo implode(' ', $pp_page_wrap_classes); ?>">
    <?php 
    $useContainer = isset($pp_container) ? (bool)$pp_container : !is_admin();
    $pp_container_class = isset($pp_container_class) && is_string($pp_container_class) ? trim($pp_container_class) : 'container';
    if ($useContainer): ?>
    <div class="<?php echo htmlspecialchars($pp_container_class); ?> mt-4">
    <?php endif; ?>
