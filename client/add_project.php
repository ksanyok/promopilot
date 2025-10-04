<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect('auth/login.php');
}

$message = '';

// Build taxonomy (regions/topics) from enabled networks
$taxonomy = pp_get_network_taxonomy(true);
$availableRegions = $taxonomy['regions'] ?? [];
$availableTopics  = $taxonomy['topics'] ?? [];
if (empty($availableRegions)) { $availableRegions = ['Global']; }
if (empty($availableTopics)) { $availableTopics = ['Paste/Text','Pages/Markdown','Blogging/Pages (micro-articles)']; }

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
        $region = trim((string)($_POST['region'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? ''));
        // Validate region/topic against available lists
        if (!in_array($region, $availableRegions, true)) { $region = $availableRegions[0] ?? ''; }
        if (!in_array($topic, $availableTopics, true))   { $topic = $availableTopics[0] ?? ''; }
        $user_id = (int)$_SESSION['user_id'];

        if (!$name || !$first_url || !filter_var($first_url, FILTER_VALIDATE_URL)) {
            $message = __('Проверьте обязательные поля: название и корректный URL.');
        } else {
            $conn = connect_db();
            // Insert project with region/topic
            $stmt = $conn->prepare("INSERT INTO projects (user_id, name, description, region, topic) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $user_id, $name, $description, $region, $topic);
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
$GLOBALS['pp_layout_has_sidebar'] = true;
?>

<?php include '../includes/header.php'; ?>

<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content fade-in">
    <?php if ($message): ?>
        <div class="alert alert-success mb-4"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="add-project-shell">
        <div class="card add-project-hero">
            <div class="card-body d-flex flex-column flex-lg-row align-items-start align-items-lg-center gap-4">
                <div class="add-project-hero__icon">
                    <span class="badge bg-warning text-dark"><i class="bi bi-stars"></i></span>
                </div>
                <div class="flex-grow-1">
                    <h2 class="mb-2 d-flex align-items-center gap-2">
                        <?php echo __('Добавить новый проект'); ?>
                        <i class="bi bi-info-circle" data-bs-toggle="tooltip" data-bs-placement="right" title="<?php echo __('Создайте карточку проекта, чтобы подключить ссылки и запустить каскады продвижения.'); ?>"></i>
                    </h2>
                    <p class="text-muted mb-0">
                        <?php echo __('Мы автоматически проанализируем первую ссылку, сформируем визуальный предпросмотр и подскажем лучшие площадки для размещения. Заполните обязательные поля, а дополнительные помогут команде сделать продвижение точнее.'); ?>
                    </p>
                </div>
                <div class="add-project-hero__meta text-start text-lg-end">
                    <div class="small text-uppercase text-muted mb-1 fw-semibold"><?php echo __('Советы'); ?></div>
                    <ul class="list-unstyled small text-muted add-project-hero__list mb-0">
                        <li><i class="bi bi-check-circle-fill text-success me-1"></i><?php echo __('Используйте основную страницу или лендинг.'); ?></li>
                        <li><i class="bi bi-check-circle-fill text-success me-1"></i><?php echo __('Уточните тематику и регион: это ускорит подбор площадок.'); ?></li>
                        <li><i class="bi bi-check-circle-fill text-success me-1"></i><?php echo __('Запишите пожелания для текстов и ссылок — их увидят редакторы.'); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="add-project-body">
            <div class="card add-project-card">
                <div class="card-body">
                    <form method="post" class="add-project-form">
                        <?php echo csrf_field(); ?>

                        <div class="form-section">
                            <div class="section-heading">
                                <span class="section-icon"><i class="bi bi-journal-text"></i></span>
                                <div>
                                    <h3><?php echo __('Основная информация'); ?></h3>
                                    <p class="text-muted mb-0"><?php echo __('Опишите проект и укажите базовую ссылку, с которой мы начнём продвижение.'); ?></p>
                                </div>
                            </div>
                            <div class="section-grid">
                                <div class="form-floating">
                                    <input type="text" name="name" id="project-name" class="form-control" placeholder="<?php echo __('Название'); ?>" required>
                                    <label for="project-name"><?php echo __('Название проекта'); ?> *</label>
                                    <div class="form-helper"><?php echo __('Например, «PromoPilot AI Tools» или «Тестовый лендинг продукта».'); ?></div>
                                </div>
                                <div class="form-floating">
                                    <input type="url" name="first_url" id="project-url" class="form-control" placeholder="https://example.com" required>
                                    <label for="project-url"><?php echo __('Главная целевая страница (URL)'); ?> *</label>
                                    <div class="form-helper"><?php echo __('Убедитесь, что страница открывается без авторизации.'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-heading">
                                <span class="section-icon"><i class="bi bi-link-45deg"></i></span>
                                <div>
                                    <h3><?php echo __('Первая ссылка и язык'); ?></h3>
                                    <p class="text-muted mb-0"><?php echo __('Заполните данные, которые будут использоваться для стартовой публикации и анализа анкоров.'); ?></p>
                                </div>
                            </div>
                            <div class="section-grid">
                                <div class="form-floating">
                                    <input type="text" name="first_anchor" id="project-anchor" class="form-control" placeholder="<?php echo __('Анкор'); ?>">
                                    <label for="project-anchor"><?php echo __('Анкор первой ссылки'); ?></label>
                                    <div class="form-helper"><?php echo __('Оставьте пустым, если хотите подобрать анкор позже.'); ?></div>
                                </div>
                                <div class="form-floating">
                                    <select name="first_language" id="project-language" class="form-select">
                                        <option value="ru">Русский</option>
                                        <option value="en">English</option>
                                        <option value="es">Español</option>
                                        <option value="fr">Français</option>
                                        <option value="de">Deutsch</option>
                                    </select>
                                    <label for="project-language"><?php echo __('Язык первой ссылки'); ?></label>
                                    <div class="form-helper"><?php echo __('Выберите язык контента, на котором должна появиться публикация.'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-heading">
                                <span class="section-icon"><i class="bi bi-globe"></i></span>
                                <div>
                                    <h3><?php echo __('Таргетинг проекта'); ?></h3>
                                    <p class="text-muted mb-0"><?php echo __('Тематика и регион помогают алгоритму точнее подобрать площадки.'); ?></p>
                                </div>
                            </div>
                            <div class="section-grid">
                                <div class="form-floating">
                                    <select name="region" id="project-region" class="form-select">
                                        <?php foreach ($availableRegions as $r): ?>
                                            <option value="<?php echo htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="project-region"><?php echo __('Регион проекта'); ?></label>
                                    <div class="form-helper"><?php echo __('Где находится ваша аудитория или бизнес.'); ?></div>
                                </div>
                                <div class="form-floating">
                                    <select name="topic" id="project-topic" class="form-select">
                                        <?php foreach ($availableTopics as $t): ?>
                                            <option value="<?php echo htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="project-topic"><?php echo __('Тематика проекта'); ?></label>
                                    <div class="form-helper"><?php echo __('Помогает отфильтровать площадки по релевантным нишам.'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-heading">
                                <span class="section-icon"><i class="bi bi-chat-dots"></i></span>
                                <div>
                                    <h3><?php echo __('Пожелания и заметки'); ?></h3>
                                    <p class="text-muted mb-0"><?php echo __('Передайте инструкции авторам: стиль, ограничения, ключевые сообщения.'); ?></p>
                                </div>
                            </div>
                            <div class="section-grid section-grid--single">
                                <div class="form-floating">
                                    <textarea name="wishes" id="project-wishes" class="form-control" style="height: 140px" placeholder="<?php echo __('Стиль, тематика, ограничения по бренду, типы анкоров...'); ?>"></textarea>
                                    <label for="project-wishes"><?php echo __('Глобальные пожелания (опционально)'); ?></label>
                                    <div class="form-helper"><?php echo __('Эти заметки появятся при добавлении каждой новой ссылки.'); ?></div>
                                </div>
                                <div class="form-floating">
                                    <textarea name="description" id="project-description" class="form-control" style="height: 120px" placeholder="<?php echo __('Краткий контекст проекта'); ?>"></textarea>
                                    <label for="project-description"><?php echo __('Описание (опционально)'); ?></label>
                                    <div class="form-helper"><?php echo __('Для внутреннего пользования: подскажите команде, что важно знать.'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-submit">
                            <button type="submit" class="btn btn-gradient btn-lg w-100">
                                <i class="bi bi-magic me-2"></i>
                                <?php echo __('Создать проект и перейти к ссылкам'); ?>
                            </button>
                            <div class="text-muted small mt-2 d-flex align-items-center gap-2">
                                <i class="bi bi-shield-check text-success"></i>
                                <span><?php echo __('Мы автоматически сохраним историю изменений и подготовим превью для проверки.'); ?></span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <aside class="card add-project-aside">
                <div class="card-body">
                    <h4 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-lightbulb"></i><?php echo __('Памятка для старта'); ?></h4>
                    <ul class="add-project-aside__list">
                        <li>
                            <div class="title"><?php echo __('Соберите базовые ссылки'); ?></div>
                            <p><?php echo __('Если у вас несколько страниц, добавьте их в проект позже и запланируйте каскады по этапам.'); ?></p>
                        </li>
                        <li>
                            <div class="title"><?php echo __('Проверьте индексацию'); ?></div>
                            <p><?php echo __('Страница должна быть открыта поисковым ботам, иначе публикации не дадут эффект.'); ?></p>
                        </li>
                        <li>
                            <div class="title"><?php echo __('Делитесь результатами'); ?></div>
                            <p><?php echo __('История запусков и отчёты будут доступны в проекте — возвращайтесь проверять метрики.'); ?></p>
                        </li>
                    </ul>
                    <div class="add-project-aside__footer">
                        <div class="small text-muted mb-2"><?php echo __('Нужна помощь?'); ?></div>
                        <a href="mailto:support@promopilot.app" class="btn btn-outline-light w-100"><i class="bi bi-envelope me-2"></i><?php echo __('Связаться с поддержкой'); ?></a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>