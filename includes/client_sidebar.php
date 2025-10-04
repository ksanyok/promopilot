<?php
// Reusable client sidebar
// Expects optional $pp_current_project = ['id' => int, 'name' => string]
if (!function_exists('pp_url')) { require_once __DIR__ . '/init.php'; }

$GLOBALS['pp_layout_has_sidebar'] = true;

$currentProject = $pp_current_project ?? null;

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
        <ul class="menu-list">
            <li>
                <a href="<?php echo pp_url('client/client.php'); ?>" class="menu-item">
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
            <li>
                <a href="<?php echo pp_url('client/settings.php'); ?>" class="menu-item">
                    <i class="bi bi-sliders me-2"></i>
                    <span class="menu-item__text"><?php echo __('Настройки'); ?></span>
                </a>
            </li>
        </ul>
    </div>

    <?php if ($projectsCount > 0): ?>
    <div class="menu-block sidebar-panel sidebar-panel--projects">
        <div class="sidebar-panel__header">
            <span class="sidebar-panel__icon" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
            <div class="sidebar-panel__title"><?php echo __('Проекты'); ?> <span class="sidebar-panel__counter"><?php echo $projectsCount; ?></span></div>
        </div>
        <div class="sidebar-panel__body sidebar-panel__body--scroll">
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
            <div class="sidebar-panel__title"><?php echo __('Проект'); ?></div>
        </div>
        <ul class="menu-list">
            <li>
                <a href="<?php echo pp_url('client/client.php'); ?>" class="menu-item">
                    <i class="bi bi-arrow-left me-2"></i>
                    <span class="menu-item__text"><?php echo __('Назад к проектам'); ?></span>
                </a>
            </li>
            <li>
                <span class="menu-item" title="<?php echo $currentProjectTitle; ?>">
                    <i class="bi bi-folder2-open me-2"></i>
                    <span class="menu-item__text"><?php echo $currentProjectDisplay; ?></span>
                </span>
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
    <?php endif; ?>
    </div>
</div>
