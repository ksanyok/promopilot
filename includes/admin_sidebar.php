<?php
// Shared admin sidebar navigation
if (!function_exists('pp_url')) {
    require_once __DIR__ . '/init.php';
}

$GLOBALS['pp_layout_has_sidebar'] = true;

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isDashboard = ($currentScript === 'admin.php');
$isPaymentSettings = ($currentScript === 'payment_systems.php');
$isTransactions = ($currentScript === 'payment_transactions.php');

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
                        <li>
                            <a href="<?php echo pp_url('admin/admin.php'); ?>" class="menu-item<?php echo $isDashboard ? ' active' : ''; ?>">
                                <i class="bi bi-grid me-2" aria-hidden="true"></i>
                                <span class="menu-item__text"><?php echo __('Обзор'); ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo pp_url('admin/payment_systems.php'); ?>" class="menu-item<?php echo $isPaymentSettings ? ' active' : ''; ?>">
                                <i class="bi bi-credit-card me-2" aria-hidden="true"></i>
                                <span class="menu-item__text"><?php echo __('Платёжные системы'); ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo pp_url('admin/payment_transactions.php'); ?>" class="menu-item<?php echo $isTransactions ? ' active' : ''; ?>">
                                <i class="bi bi-clock-history me-2" aria-hidden="true"></i>
                                <span class="menu-item__text"><?php echo __('Транзакции'); ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
