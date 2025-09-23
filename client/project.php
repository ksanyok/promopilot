<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in()) {
    redirect('auth/login.php');
}

$id = (int)($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

$conn = connect_db();
$stmt = $conn->prepare("SELECT p.*, u.username FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    include '../includes/header.php';
    echo '<div class="alert alert-warning">' . __('Проект не найден.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

$project = $result->fetch_assoc();
$conn->close();

// Проверить доступ: админ или владелец
if (!is_admin() && $project['user_id'] != $user_id) {
    include '../includes/header.php';
    echo '<div class="alert alert-danger">' . __('Доступ запрещен.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

// Обработка формы
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project'])) {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $links = json_decode($project['links'] ?? '[]', true) ?: [];
        $new_link = trim($_POST['new_link'] ?? '');
        if ($new_link && filter_var($new_link, FILTER_VALIDATE_URL)) {
            $links[] = $new_link;
        }
        $remove_links = $_POST['remove_links'] ?? [];
        $links = array_filter($links, function($link) use ($remove_links) {
            return !in_array($link, $remove_links);
        });
        $language = trim($_POST['language'] ?? 'ru');
        $wishes = trim($_POST['wishes'] ?? '');

        $conn = connect_db();
        $stmt = $conn->prepare("UPDATE projects SET links = ?, language = ?, wishes = ? WHERE id = ?");
        $links_json = json_encode(array_values($links));
        $stmt->bind_param('sssi', $links_json, $language, $wishes, $id);
        if ($stmt->execute()) {
            $message = __('Проект обновлен.');
            $project['links'] = $links_json;
            $project['language'] = $language;
            $project['wishes'] = $wishes;
        } else {
            $message = __('Ошибка обновления проекта.');
        }
        $stmt->close();
        $conn->close();
    }
}

$links = json_decode($project['links'] ?? '[]', true) ?: [];

$pp_container = false;
$pp_current_project = ['id' => (int)$project['id'], 'name' => $project['name'] ?? ('ID ' . (int)$project['id'])];
?>

<?php include '../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h4><?php echo htmlspecialchars($project['name']); ?></h4>
                </div>
                <div class="card-body">
                    <p><strong><?php echo __('Пользователь'); ?>:</strong> <?php echo htmlspecialchars($project['username']); ?></p>
                    <p><strong><?php echo __('Описание'); ?>:</strong></p>
                    <p><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                    <p><strong><?php echo __('Дата создания'); ?>:</strong> <?php echo htmlspecialchars($project['created_at']); ?></p>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5><?php echo __('Информация о проекте'); ?></h5>
                </div>
                <div class="card-body">
                    <h6><?php echo __('Ссылки'); ?>:</h6>
                    <?php if (!empty($links)): ?>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th><?php echo __('№'); ?></th>
                                    <th><?php echo __('Ссылка'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($links as $index => $link): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><a href="<?php echo htmlspecialchars($link); ?>" target="_blank"><?php echo htmlspecialchars($link); ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p><?php echo __('Ссылок нет.'); ?></p>
                    <?php endif; ?>
                    <p><strong><?php echo __('Язык страницы'); ?>:</strong> <?php echo htmlspecialchars($project['language'] ?? 'ru'); ?></p>
                    <p><strong><?php echo __('Пожелания'); ?>:</strong></p>
                    <p><?php echo nl2br(htmlspecialchars($project['wishes'] ?? '')); ?></p>
                </div>
            </div>

            <div class="card mt-4" id="links-section">
                <div class="card-header bg-primary text-white">
                    <h5><?php echo __('Настройки проекта'); ?></h5>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('Ссылки'); ?>:</label>
                            <div id="links-list">
                                <?php foreach ($links as $link): ?>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($link); ?>" readonly>
                                        <button type="button" class="btn btn-outline-danger remove-link" data-link="<?php echo htmlspecialchars($link); ?>"><?php echo __('Удалить'); ?></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="input-group">
                                <input type="url" name="new_link" class="form-control" placeholder="<?php echo __('Добавить новую ссылку'); ?>">
                                <button type="button" class="btn btn-outline-success" id="add-link"><?php echo __('Добавить'); ?></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('Язык страницы'); ?>:</label>
                            <select name="language" class="form-select">
                                <option value="ru" <?php echo ($project['language'] == 'ru' ? 'selected' : ''); ?>>Русский</option>
                                <option value="en" <?php echo ($project['language'] == 'en' ? 'selected' : ''); ?>>English</option>
                                <option value="es" <?php echo ($project['language'] == 'es' ? 'selected' : ''); ?>>Español</option>
                                <option value="fr" <?php echo ($project['language'] == 'fr' ? 'selected' : ''); ?>>Français</option>
                                <option value="de" <?php echo ($project['language'] == 'de' ? 'selected' : ''); ?>>Deutsch</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('Пожелания'); ?>:</label>
                            <textarea name="wishes" class="form-control" rows="4" placeholder="<?php echo __('Укажите ваши пожелания'); ?>"><?php echo htmlspecialchars($project['wishes'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" name="update_project" class="btn btn-primary"><?php echo __('Сохранить изменения'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const linksList = document.getElementById('links-list');
    const addLinkBtn = document.getElementById('add-link');
    const newLinkInput = document.querySelector('input[name="new_link"]');

    // Add link
    addLinkBtn.addEventListener('click', function() {
        const url = newLinkInput.value.trim();
        if (url && isValidUrl(url)) {
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <input type="text" class="form-control" value="${url}" readonly>
                <button type="button" class="btn btn-outline-danger remove-link" data-link="${url}"><?php echo __('Удалить'); ?></button>
            `;
            linksList.appendChild(div);
            newLinkInput.value = '';
        } else {
            alert('<?php echo __('Введите корректный URL'); ?>');
        }
    });

    // Remove link
    linksList.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-link')) {
            const link = e.target.getAttribute('data-link');
            e.target.closest('.input-group').remove();
            // Add hidden input for removal
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'remove_links[]';
            hidden.value = link;
            e.target.form.appendChild(hidden);
        }
    });

    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>