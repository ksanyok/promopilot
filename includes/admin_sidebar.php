<?php
// Shared admin sidebar navigation
if (!function_exists('pp_url')) {
    require_once __DIR__ . '/init.php';
}

$GLOBALS['pp_layout_has_sidebar'] = true;

$pp_admin_sidebar_active = $pp_admin_sidebar_active ?? '';
$pp_admin_sidebar_section_mode = !empty($pp_admin_sidebar_section_mode);
$pp_admin_sidebar_tools = isset($pp_admin_sidebar_tools) && is_array($pp_admin_sidebar_tools)
    ? array_values(array_filter($pp_admin_sidebar_tools, static function ($item) {
        return !empty($item['label']) && !empty($item['href']);
    }))
    : [];

$adminBaseUrl = pp_url('admin/admin.php');
$generalMenu = [
    [
        'key' => 'overview',
        'label' => __('Обзор'),
        'icon' => 'bi-speedometer2',
        'href' => $adminBaseUrl,
    ],
    [
        'key' => 'users',
        'label' => __('Пользователи'),
        'icon' => 'bi-people',
        'href' => $adminBaseUrl . '#users',
        'section' => 'users',
    ],
    [
        'key' => 'projects',
        'label' => __('Проекты'),
        'icon' => 'bi-kanban',
        'href' => $adminBaseUrl . '#projects',
        'section' => 'projects',
    ],
    [
        'key' => 'settings',
        'label' => __('Основные настройки'),
        'icon' => 'bi-gear',
        'href' => $adminBaseUrl . '#settings',
        'section' => 'settings',
    ],
    [
        'key' => 'networks',
        'label' => __('Сети'),
        'icon' => 'bi-diagram-3',
        'href' => $adminBaseUrl . '#networks',
        'section' => 'networks',
    ],
    [
        'key' => 'crowd',
        'label' => __('Крауд маркетинг'),
        'icon' => 'bi-megaphone',
        'href' => $adminBaseUrl . '#crowd',
        'section' => 'crowd',
    ],
    [
        'key' => 'diagnostics',
        'label' => __('Диагностика'),
        'icon' => 'bi-activity',
        'href' => $adminBaseUrl . '#diagnostics',
        'section' => 'diagnostics',
    ],
];

$paymentsMenu = [
    [
        'key' => 'payment_systems',
        'label' => __('Платёжные системы'),
        'icon' => 'bi-credit-card',
        'href' => pp_url('admin/payment_systems.php'),
    ],
    [
        'key' => 'payment_transactions',
        'label' => __('Транзакции'),
        'icon' => 'bi-clock-history',
        'href' => pp_url('admin/payment_transactions.php'),
    ],
];

?>
<div class="sidebar">
    <div class="sidebar-main">
        <div class="menu-block sidebar-panel sidebar-panel--menu">
            <div class="sidebar-panel__header">
                <span class="sidebar-panel__icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span>
                <div class="sidebar-panel__title"><?php echo __('Админ-меню'); ?></div>
            </div>
            <div class="sidebar-panel__body">
                <div class="sidebar-panel__content sidebar-panel__scroller sidebar-panel__body--scroll">
                    <ul class="menu-list">
                        <?php foreach ($generalMenu as $item): ?>
                            <?php
                                $isActive = ($pp_admin_sidebar_active === $item['key']);
                                $sectionAttr = isset($item['section']) ? ' data-admin-section="' . htmlspecialchars($item['section'], ENT_QUOTES, 'UTF-8') . '"' : '';
                            ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="menu-item<?php echo $isActive ? ' active' : ''; ?>"<?php echo $sectionAttr; ?>>
                                    <i class="bi <?php echo htmlspecialchars($item['icon']); ?> me-2" aria-hidden="true"></i>
                                    <span class="menu-item__text"><?php echo htmlspecialchars($item['label']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <li class="mt-3 px-2 text-uppercase small fw-semibold text-muted"><?php echo __('Платежи'); ?></li>
                        <?php foreach ($paymentsMenu as $item): ?>
                            <?php $isActive = ($pp_admin_sidebar_active === $item['key']); ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="menu-item<?php echo $isActive ? ' active' : ''; ?>">
                                    <i class="bi <?php echo htmlspecialchars($item['icon']); ?> me-2" aria-hidden="true"></i>
                                    <span class="menu-item__text"><?php echo htmlspecialchars($item['label']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!empty($pp_admin_sidebar_tools)): ?>
                            <li class="mt-3 px-2 text-uppercase small fw-semibold text-muted"><?php echo __('Инструменты'); ?></li>
                            <?php foreach ($pp_admin_sidebar_tools as $item): ?>
                                <li>
                                    <a href="<?php echo htmlspecialchars($item['href']); ?>" class="menu-item"<?php echo !empty($item['target']) ? ' target="' . htmlspecialchars($item['target']) . '"' : ''; ?>>
                                        <?php if (!empty($item['icon'])): ?>
                                            <i class="bi <?php echo htmlspecialchars($item['icon']); ?> me-2" aria-hidden="true"></i>
                                        <?php endif; ?>
                                        <span class="menu-item__text"><?php echo htmlspecialchars($item['label']); ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
