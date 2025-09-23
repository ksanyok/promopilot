<?php
// Reusable client sidebar
// Expects optional $pp_current_project = ['id' => int, 'name' => string]
if (!function_exists('pp_url')) { require_once __DIR__ . '/init.php'; }

$balanceText = '';
if (is_logged_in() && !is_admin()) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $conn = connect_db();
        if ($conn) {
            $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $balanceText = htmlspecialchars(format_currency($row['balance']));
                }
                $stmt->close();
            }
            $conn->close();
        }
    }
}
$currentProject = $pp_current_project ?? null;

$projectsList = [];
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
                $stmt->close();
            }
            $conn->close();
        }
    }
}
?>

<div class="sidebar">
    <div class="menu-block">
        <div class="menu-title"><?php echo __('Меню'); ?></div>
        <ul class="menu-list">
            <li>
                <a href="<?php echo pp_url('client/client.php'); ?>" class="menu-item">
                    <i class="bi bi-grid me-2"></i>
                    <?php echo __('Дашборд'); ?>
                </a>
            </li>
            <li>
                <a href="<?php echo pp_url('client/add_project.php'); ?>" class="menu-item">
                    <i class="bi bi-plus-circle me-2"></i>
                    <?php echo __('Добавить проект'); ?>
                </a>
            </li>
            <?php if ($balanceText !== ''): ?>
            <li>
                <span class="menu-item">
                    <i class="bi bi-coin me-2"></i>
                    <?php echo __('Баланс'); ?>: <?php echo $balanceText; ?>
                </span>
            </li>
            <?php endif; ?>
            <li>
                <form method="post" action="<?php echo pp_url('auth/logout.php'); ?>" class="d-inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="menu-item btn btn-link p-0 w-100 text-start">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        <?php echo __('Выход'); ?>
                    </button>
                </form>
            </li>
        </ul>
    </div>

    <?php if (!empty($projectsList)): ?>
    <div class="menu-block">
        <div class="menu-title"><?php echo __('Проекты'); ?></div>
        <ul class="menu-list">
            <?php foreach ($projectsList as $p): $active = ($currentProject && (int)$currentProject['id'] === (int)$p['id']); ?>
                <li>
                    <a class="menu-item<?php echo $active ? ' active' : ''; ?>" href="<?php echo pp_url('client/project.php?id=' . (int)$p['id']); ?>">
                        <i class="bi bi-folder2-open me-2"></i>
                        <?php echo htmlspecialchars($p['name'] ?: ('ID ' . (int)$p['id'])); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($currentProject && !empty($currentProject['id'])): ?>
    <hr class="menu-separator" />
    <div class="menu-block">
        <div class="menu-title"><?php echo __('Проект'); ?></div>
        <ul class="menu-list">
            <li>
                <a href="<?php echo pp_url('client/client.php'); ?>" class="menu-item">
                    <i class="bi bi-arrow-left me-2"></i>
                    <?php echo __('Назад к проектам'); ?>
                </a>
            </li>
            <li>
                <span class="menu-item">
                    <i class="bi bi-folder2-open me-2"></i>
                    <?php echo htmlspecialchars($currentProject['name'] ?? ('ID ' . (int)$currentProject['id'])); ?>
                </span>
            </li>
            <li>
                <a href="<?php echo pp_url('client/project.php?id=' . (int)$currentProject['id']); ?>#links-section" class="menu-item" id="sidebar-add-link-btn">
                    <i class="bi bi-plus-circle me-2"></i>
                    <?php echo __('Добавить ссылку'); ?>
                </a>
            </li>
            <li>
                <a href="<?php echo pp_url('client/history.php?id=' . (int)$currentProject['id']); ?>" class="menu-item">
                    <i class="bi bi-clock-history me-2"></i>
                    <?php echo __('История'); ?>
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
