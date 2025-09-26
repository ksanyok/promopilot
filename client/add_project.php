<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect('auth/login.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? ''); // теперь необязательное
        $first_url = trim($_POST['first_url'] ?? '');
        $first_anchor = trim($_POST['first_anchor'] ?? '');
        $first_language = trim($_POST['first_language'] ?? 'ru');
        $global_wishes = trim($_POST['wishes'] ?? '');
        $user_id = (int)$_SESSION['user_id'];

        if (!$name || !$first_url || !filter_var($first_url, FILTER_VALIDATE_URL)) {
            $message = __('Проверьте обязательные поля: название и корректный URL.');
        } else {
            $conn = connect_db();
            $stmt = $conn->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $name, $description);
            if ($stmt->execute()) {
                $project_id = $stmt->insert_id;
                $message = __('Проект добавлен!') . ' <a href="' . pp_url('client/client.php') . '">' . __('Вернуться к дашборду') . '</a>';
                // Добавим первую ссылку и глобальные пожелания в отдельную таблицу project_links
                $host = '';
                if ($first_url) {
                    // Нормализуем и сохраним домен проекта (только хост, без www.)
                    $host = strtolower((string)parse_url($first_url, PHP_URL_HOST));
                    if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }

                    // Вставка ссылки
                    $ins = $conn->prepare('INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)');
                    if ($ins) {
                        $emptyWish = '';
                        $ins->bind_param('issss', $project_id, $first_url, $first_anchor, $first_language, $emptyWish);
                        $ins->execute();
                        $ins->close();
                    }

                    // Анализ микроразметки и сохранение в page_meta (best-effort)
                    try {
                        if (function_exists('pp_analyze_url_data') && function_exists('pp_save_page_meta')) {
                            $meta = pp_analyze_url_data($first_url);
                            if (is_array($meta)) { @pp_save_page_meta($project_id, $first_url, $meta); }
                        }
                    } catch (Throwable $e) { /* ignore */ }
                }
                // Сохраним пожелания и домен
                $upd = $conn->prepare('UPDATE projects SET wishes = ?, domain_host = ? WHERE id = ?');
                if ($upd) { $upd->bind_param('ssi', $global_wishes, $host, $project_id); $upd->execute(); $upd->close(); }
            } else {
                $message = __('Ошибка добавления проекта.');
            }
            $conn->close();
        }
    }
}

$pp_container = false;
?>

<?php include '../includes/header.php'; ?>

<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content">
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h4><?php echo __('Добавить новый проект'); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('Название проекта'); ?> *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('Главная целевая страница (URL)'); ?> *</label>
                        <input type="url" name="first_url" class="form-control" required placeholder="https://example.com/page">
                    </div>
                    <div class="row g-3 mb-3">
                      <div class="col-md-6">
                        <label class="form-label"><?php echo __('Анкор первой ссылки'); ?></label>
                        <input type="text" name="first_anchor" class="form-control" placeholder="<?php echo __('Анкор'); ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label"><?php echo __('Язык первой ссылки'); ?></label>
                        <select name="first_language" class="form-select">
                          <option value="ru">Русский</option>
                          <option value="en">English</option>
                          <option value="es">Español</option>
                          <option value="fr">Français</option>
                          <option value="de">Deutsch</option>
                        </select>
                      </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('Глобальные пожелания (опционально)'); ?></label>
                        <textarea name="wishes" class="form-control" rows="4" placeholder="<?php echo __('Стиль, тематика, ограничения по бренду, типы анкоров...'); ?>"></textarea>
                        <div class="form-text"><?php echo __('Можно использовать при добавлении последующих ссылок.'); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('Описание (опционально)'); ?></label>
                        <textarea name="description" class="form-control" rows="3" placeholder="<?php echo __('Краткий контекст проекта'); ?>"></textarea>
                    </div>
                    <button type="submit" class="btn btn-warning w-100 py-2 fw-semibold"><i class="bi bi-plus-lg me-1"></i><?php echo __('Создать проект'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
</div>

<?php include '../includes/footer.php'; ?>