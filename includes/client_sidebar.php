<?php
// Reusable client sidebar
// Expects optional $pp_current_project = ['id' => int, 'name' => string]
if (!function_exists('pp_url')) { require_once __DIR__ . '/init.php'; }

$GLOBALS['pp_layout_has_sidebar'] = true;

$currentProject = $pp_current_project ?? null;
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isDashboardPage = ($currentScript === 'client.php');

$projectsList = [];
$projectsCount = 0;
if (is_logged_in() && !is_admin()) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $conn = connect_db();
        if ($conn) {
            $stmt = $conn->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY id DESC LIMIT 100");
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) { $projectsList[] = $row; }
                $projectsCount = count($projectsList);
                $stmt->close();
            }
            $conn->close();
        }
    }
}
?>

<div class="sidebar">
    <div class="sidebar-main">
        <div class="menu-block sidebar-panel sidebar-panel--menu">
            <div class="sidebar-panel__header">
                <span class="sidebar-panel__icon" aria-hidden="true"><i class="bi bi-stars"></i></span>
                <div class="sidebar-panel__title"><?php echo __('Меню'); ?></div>
            </div>
            <div class="sidebar-panel__body">
                <div class="sidebar-panel__content sidebar-panel__scroller sidebar-panel__body--scroll">
                    <ul class="menu-list">
                        <li>
                            <a href="<?php echo pp_url('client/client.php'); ?>" class="menu-item<?php echo $isDashboardPage ? ' active' : ''; ?>">
                                <i class="bi bi-grid me-2"></i>
                                <span class="menu-item__text"><?php echo __('Дашборд'); ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo pp_url('client/add_project.php'); ?>" class="menu-item">
                                <i class="bi bi-plus-circle me-2"></i>
                                <span class="menu-item__text"><?php echo __('Добавить проект'); ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    <?php if ($projectsCount > 0): ?>
        <div class="menu-block sidebar-panel sidebar-panel--projects">
            <div class="sidebar-panel__header">
                <span class="sidebar-panel__icon" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                <div class="sidebar-panel__title"><?php echo __('Проекты'); ?> <span class="sidebar-panel__counter"><?php echo $projectsCount; ?></span></div>
            </div>
            <div class="sidebar-panel__body">
                <div class="sidebar-panel__scroller sidebar-panel__body--scroll">
                    <ul class="menu-list menu-list--projects">
                    <?php foreach ($projectsList as $p): 
                        $active = ($currentProject && (int)$currentProject['id'] === (int)$p['id']);
                        $projectNameRaw = $p['name'] ?: ('ID ' . (int)$p['id']);
                        if (function_exists('mb_strlen')) {
                            $limited = mb_strlen($projectNameRaw, 'UTF-8') > 20 ? (mb_substr($projectNameRaw, 0, 20, 'UTF-8') . '…') : $projectNameRaw;
                        } else {
                            $limited = strlen($projectNameRaw) > 20 ? (substr($projectNameRaw, 0, 20) . '…') : $projectNameRaw;
                        }
                        $projectNameDisplay = htmlspecialchars($limited);
                        $projectTitle = htmlspecialchars($projectNameRaw);
                    ?>
                        <li>
                            <a class="menu-item<?php echo $active ? ' active' : ''; ?>" href="<?php echo pp_url('client/project.php?id=' . (int)$p['id']); ?>" title="<?php echo $projectTitle; ?>">
                                <i class="bi bi-folder2-open me-2"></i>
                                <span class="menu-item__text"><?php echo $projectNameDisplay; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // Referral mini-widget in sidebar
    $refEnabled = get_setting('referral_enabled', '0') === '1';
    if ($refEnabled && is_logged_in() && !is_admin()):
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $code = '';
        try {
            if (function_exists('pp_referral_get_or_create_user_code')) {
                $conn = connect_db();
                $code = pp_referral_get_or_create_user_code($conn, $uid);
                $conn->close();
            }
        } catch (Throwable $e) { /* ignore */ }
        $refLink = pp_url('?ref=' . rawurlencode($code));
    ?>
    <div class="menu-block sidebar-panel sidebar-panel--referral">
        <div class="sidebar-panel__header">
            <span class="sidebar-panel__icon" aria-hidden="true"><i class="bi bi-people"></i></span>
            <div class="sidebar-panel__title"><?php echo __('Партнёрка'); ?></div>
        </div>
        <div class="sidebar-panel__body">
            <div class="sidebar-panel__content">
                <div class="small text-muted mb-2"><?php echo __('Делитесь ссылкой и зарабатывайте на активности друзей.'); ?></div>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($refLink); ?>" readonly>
                    <button class="btn btn-outline-primary" type="button" id="copyRefLinkSide"><i class="bi bi-clipboard"></i></button>
                </div>
                <div class="d-grid mt-2">
                    <a href="<?php echo pp_url('client/referrals.php'); ?>" class="btn btn-sm btn-primary"><i class="bi bi-graph-up-arrow me-1"></i><?php echo __('Статистика'); ?></a>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const btn = document.getElementById('copyRefLinkSide');
        if (btn) {
            btn.addEventListener('click', function(){
                const inp = btn.closest('.input-group')?.querySelector('input');
                if (inp) { inp.select(); document.execCommand('copy'); btn.innerHTML = '<i class="bi bi-check2"></i>'; setTimeout(()=>{ btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1200); }
            });
        }
    });
    </script>
    <?php endif; ?>

    <?php if ($currentProject && !empty($currentProject['id'])): ?>
    <?php
        $currentProjectRaw = $currentProject['name'] ?? ('ID ' . (int)$currentProject['id']);
        if (function_exists('mb_strlen')) {
            $currentProjectLimited = mb_strlen($currentProjectRaw, 'UTF-8') > 20 ? (mb_substr($currentProjectRaw, 0, 20, 'UTF-8') . '…') : $currentProjectRaw;
        } else {
            $currentProjectLimited = strlen($currentProjectRaw) > 20 ? (substr($currentProjectRaw, 0, 20) . '…') : $currentProjectRaw;
        }
        $currentProjectDisplay = htmlspecialchars($currentProjectLimited);
        $currentProjectTitle = htmlspecialchars($currentProjectRaw);
    ?>
    <div class="menu-block sidebar-panel sidebar-panel--current">
        <div class="sidebar-panel__header">
            <span class="sidebar-panel__icon" aria-hidden="true"><i class="bi bi-pin-map"></i></span>
            <div class="sidebar-panel__title" title="<?php echo $currentProjectTitle; ?>"><?php echo $currentProjectDisplay; ?></div>
        </div>
        <div class="sidebar-panel__body">
            <div class="sidebar-panel__content sidebar-panel__body--scroll">
                <ul class="menu-list">
                    <li>
                        <a href="<?php echo pp_url('client/client.php'); ?>" class="menu-item">
                            <i class="bi bi-arrow-left me-2"></i>
                            <span class="menu-item__text"><?php echo __('Назад к проектам'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo pp_url('client/project.php?id=' . (int)$currentProject['id']); ?>#links-section" class="menu-item" id="sidebar-add-link-btn">
                            <i class="bi bi-plus-circle me-2"></i>
                            <span class="menu-item__text"><?php echo __('Добавить ссылку'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo pp_url('client/history.php?id=' . (int)$currentProject['id']); ?>" class="menu-item">
                            <i class="bi bi-clock-history me-2"></i>
                            <span class="menu-item__text"><?php echo __('История'); ?></span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div>
</div>
