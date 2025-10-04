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
$creationTips = [
    __('Создаем проект и готовим рабочее пространство…'),
    __('Анализируем первую ссылку и домен…'),
    __('Генерируем визуальный предпросмотр…'),
    __('Сохраняем пожелания и настройки проекта…'),
];
$creationTipsAttr = htmlspecialchars(implode('|', $creationTips), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$creationTipInitial = htmlspecialchars($creationTips[0] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$project_id = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? ''); // теперь необязательное
    $first_url = trim($_POST['first_url'] ?? '');
    $language = strtolower(trim((string)($_POST['language'] ?? 'ru')));
    if ($language === '' || !preg_match('~^[a-z]{2,5}$~', $language)) { $language = 'ru'; }
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
            $stmt = $conn->prepare("INSERT INTO projects (user_id, name, description, language, region, topic) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $user_id, $name, $description, $language, $region, $topic);
            if ($stmt->execute()) {
                $project_id = $stmt->insert_id;
                // Подготовим данные по домену и сохраним пожелания
                $host = '';
                if ($first_url) {
                    // Нормализуем и сохраним домен проекта (только хост, без www.)
                    $host = strtolower((string)parse_url($first_url, PHP_URL_HOST));
                    if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }

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

            if ($first_url && function_exists('pp_capture_project_preview')) {
                try {
                    $projectStub = [
                        'id' => $project_id,
                        'user_id' => $user_id,
                        'name' => $name,
                        'language' => $language,
                        'domain_host' => $host,
                        'primary_url' => $first_url,
                    ];
                    $capture = pp_capture_project_preview($projectStub, [
                        'fallback_url' => $first_url,
                        'timeout_seconds' => 90,
                    ]);
                    if (empty($capture['ok']) && !empty($capture['error'])) {
                        @error_log('[PromoPilot] Preview auto-generation failed for project #' . $project_id . ': ' . $capture['error']);
                    }
                } catch (Throwable $e) {
                    @error_log('[PromoPilot] Preview auto-generation threw for project #' . $project_id . ': ' . $e->getMessage());
                }
            }
        }
    }
}

if (!empty($project_id)) {
    $_SESSION['pp_client_flash'] = [
        'type' => 'success',
        'text' => sprintf(__('Проект «%s» создан. Переходим к настройке ссылок.'), $name)
    ];
    redirect('client/project.php?id=' . (int)$project_id);
    exit;
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
                        <i class="bi bi-info-circle" data-bs-toggle="tooltip" data-bs-placement="right" title="<?php echo __('Проект создаётся на основе главной страницы: мы проверим переадресации, подтянем метаданные и попросим ИИ подготовить краткое описание. Настройки можно отредактировать перед сохранением.'); ?>"></i>
                    </h2>
                    <p class="text-muted mb-0">
                        <?php echo __('Укажите главную страницу — мы соберём данные, подберём название и подготовим карточку проекта.'); ?>
                    </p>
                </div>
                <div class="add-project-hero__meta text-start text-lg-end">
                    <div class="small text-uppercase text-muted mb-1 fw-semibold"><?php echo __('Советы'); ?></div>
                    <ul class="list-unstyled small text-muted add-project-hero__list mb-0">
                        <li><i class="bi bi-check-circle-fill text-success me-1"></i><?php echo __('Используйте главную или посадочную страницу, доступную без авторизации.'); ?></li>
                        <li><i class="bi bi-check-circle-fill text-success me-1"></i><?php echo __('Проверьте, что выбранная страница открывается быстро и корректно.'); ?></li>
                        <li><i class="bi bi-check-circle-fill text-success me-1"></i><?php echo __('Подготовьте пожелания по стилю и тону — они помогут авторам на всех уровнях.'); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="add-project-body">
            <div class="card add-project-card">
                <div class="card-body">
                    <form method="post" class="add-project-form" novalidate>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="wizard_step" value="1" data-step-state>
                        <input type="hidden" name="brief_payload" id="project-brief-payload" value="">

                        <div class="project-stepper" data-stepper data-current-step="1">
                            <div class="project-stepper__item is-active" data-step="1" aria-current="step">
                                <div class="project-stepper__circle">1</div>
                                <div class="project-stepper__meta">
                                    <div class="project-stepper__title"><?php echo __('Шаг 1'); ?></div>
                                    <div class="project-stepper__subtitle"><?php echo __('Анализ сайта'); ?></div>
                                </div>
                            </div>
                            <div class="project-stepper__connector" aria-hidden="true"></div>
                            <div class="project-stepper__item is-locked" data-step="2">
                                <div class="project-stepper__circle">2</div>
                                <div class="project-stepper__meta">
                                    <div class="project-stepper__title"><?php echo __('Шаг 2'); ?></div>
                                    <div class="project-stepper__subtitle"><?php echo __('Редактирование проекта'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="step-panels">
                            <div class="step-panel is-active" data-step-panel="1">
                                <div class="step-panel__header">
                                    <div>
                                        <div class="small text-uppercase text-info fw-semibold mb-1"><?php echo __('Шаг 1'); ?></div>
                                        <h3 class="mb-1"><?php echo __('Анализ сайта'); ?></h3>
                                        <p class="step-panel__intro mb-0"><?php echo __('Укажите главный URL — мы проверим доступность, соберём метаданные и сформируем краткий бриф.'); ?></p>
                                    </div>
                                </div>
                                <div class="row g-4 align-items-start">
                                    <div class="col-lg-7">
                                        <div class="form-floating">
                                            <input type="url" name="first_url" id="project-homepage" class="form-control" placeholder="https://example.com" required>
                                            <label for="project-homepage"><?php echo __('Главная страница (URL)'); ?> *</label>
                                            <div class="form-helper"><?php echo __('Используйте главную или посадочную страницу, доступную без авторизации.'); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-lg-5">
                                        <div class="analysis-actions">
                                            <button type="button"
                                                    class="btn btn-gradient btn-lg w-100"
                                                    data-action="fetch-project-brief"
                                                    data-endpoint="<?php echo htmlspecialchars(pp_url('public/analyze_url.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    data-label-default="<?php echo htmlspecialchars(__('Проанализировать сайт'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    data-label-loading="<?php echo htmlspecialchars(__('Анализируем…'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                <span class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true" data-loading-spinner></span>
                                                <span data-label-text><?php echo __('Проанализировать сайт'); ?></span>
                                            </button>
                                            <div class="analysis-actions__hint"><?php echo __('Название, описание и язык заполнятся автоматически. Вы сможете отредактировать данные перед сохранением.'); ?></div>
                                            <button type="button" class="btn-link-light" data-action="step-proceed-manual">
                                                <i class="bi bi-pencil-square"></i>
                                                <?php echo __('Перейти к редактированию вручную'); ?>
                                            </button>
                                            <div class="analysis-actions__hint"><?php echo __('Если сайт закрыт от ботов, заполните данные вручную.'); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="analysis-feedback mt-4"
                                     role="status"
                                     aria-live="polite"
                                     data-analysis-feedback
                                     data-text-idle="<?php echo htmlspecialchars(__('Укажите адрес и запустите анализ.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     data-text-loading="<?php echo htmlspecialchars(__('Анализируем страницу, это может занять до минуты…'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     data-text-success="<?php echo htmlspecialchars(__('Анализ завершён. Переходим ко второму шагу.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     data-text-error="<?php echo htmlspecialchars(__('Не удалось провести анализ. Проверьте URL и попробуйте ещё раз.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     data-text-manual="<?php echo htmlspecialchars(__('Вы перешли к редактированию без анализа. Заполните данные вручную.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <?php echo __('Укажите адрес и запустите анализ.'); ?>
                                </div>
                            </div>

                            <div class="step-panel d-none" data-step-panel="2">
                                <div class="step-panel__header">
                                    <div>
                                        <div class="small text-uppercase text-info fw-semibold mb-1"><?php echo __('Шаг 2'); ?></div>
                                        <h3 class="mb-1"><?php echo __('Редактирование проекта'); ?></h3>
                                        <p class="step-panel__intro mb-0"><?php echo __('Проверьте данные и скорректируйте их перед созданием проекта.'); ?></p>
                                    </div>
                                    <button type="button" class="btn btn-outline-light" data-action="step-back">
                                        <i class="bi bi-arrow-left-short me-1"></i>
                                        <?php echo __('Назад к анализу'); ?>
                                    </button>
                                </div>

                                <div class="project-brief-card d-none" data-brief-result>
                                    <div class="card-body">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                            <span class="badge bg-secondary"
                                                  data-brief-status
                                                  data-status-default="<?php echo htmlspecialchars(__('Результаты анализа'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                  data-status-loading="<?php echo htmlspecialchars(__('Проводим анализ…'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                  data-status-success="<?php echo htmlspecialchars(__('Анализ завершён: данные обновлены.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                  data-status-error="<?php echo htmlspecialchars(__('Не удалось получить данные. Попробуйте ещё раз или заполните поля вручную.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                <?php echo __('Результаты анализа'); ?>
                                            </span>
                                        </div>
                                        <div class="project-brief-meta">
                                            <div>
                                                <div class="project-brief-meta__label"><?php echo __('Заголовок страницы'); ?></div>
                                                <div class="project-brief-meta__value" data-brief-meta-title>—</div>
                                            </div>
                                            <div>
                                                <div class="project-brief-meta__label"><?php echo __('Описание страницы'); ?></div>
                                                <div class="project-brief-meta__value" data-brief-meta-description>—</div>
                                            </div>
                                            <div>
                                                <div class="project-brief-meta__label"><?php echo __('Определённый язык'); ?></div>
                                                <div class="project-brief-meta__value" data-brief-meta-lang>—</div>
                                            </div>
                                            <div>
                                                <div class="project-brief-meta__label"><?php echo __('Hreflang варианты'); ?></div>
                                                <div class="project-brief-meta__value" data-brief-meta-hreflang>—</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="section-heading">
                                        <span class="section-icon"><i class="bi bi-journal-text"></i></span>
                                        <div>
                                            <h3><?php echo __('Название проекта'); ?></h3>
                                            <p class="text-muted mb-0"><?php echo __('Название и описание проекта можно скорректировать после автоматического анализа.'); ?></p>
                                        </div>
                                    </div>
                                    <div class="section-grid section-grid--single">
                                        <div class="form-floating">
                                            <input type="text" name="name" id="project-name" class="form-control" placeholder="<?php echo __('Название проекта'); ?>" required>
                                            <label for="project-name"><?php echo __('Название проекта'); ?> *</label>
                                            <div class="form-helper"><?php echo __('Автоматически подставим найденный заголовок страницы или предложим вариант через ИИ. Название не должно превышать 20 символов.'); ?></div>
                                        </div>
                                    </div>
                                    <div class="form-floating">
                                        <textarea name="description" id="project-description" class="form-control" style="height: 140px" placeholder="<?php echo __('Описание'); ?>"></textarea>
                                        <label for="project-description"><?php echo __('Описание (опционально)'); ?></label>
                                        <div class="form-helper"><?php echo __('Короткое резюме поможет команде быстрее понять задачи проекта.'); ?></div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="section-heading">
                                        <span class="section-icon"><i class="bi bi-translate"></i></span>
                                        <div>
                                            <h3><?php echo __('Язык и таргетинг'); ?></h3>
                                            <p class="text-muted mb-0"><?php echo __('Определим язык автоматически, но вы можете выбрать другой. Регион и тематика помогают подобрать площадки.'); ?></p>
                                        </div>
                                    </div>
                                    <div class="section-grid">
                                        <div class="form-floating">
                                            <select name="language" id="project-language" class="form-select">
                                                <option value="ru">Русский</option>
                                                <option value="en">English</option>
                                                <option value="es">Español</option>
                                                <option value="fr">Français</option>
                                                <option value="de">Deutsch</option>
                                            </select>
                                            <label for="project-language"><?php echo __('Основной язык проекта'); ?></label>
                                            <div class="form-helper"><?php echo __('Используется по умолчанию при подготовке материалов.'); ?></div>
                                        </div>
                                        <div class="form-floating">
                                            <select name="region" id="project-region" class="form-select">
                                                <?php foreach ($availableRegions as $r): ?>
                                                    <option value="<?php echo htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="project-region"><?php echo __('Регион проекта'); ?></label>
                                            <div class="form-helper"><?php echo __('Где находится ваша ключевая аудитория.'); ?></div>
                                        </div>
                                        <div class="form-floating">
                                            <select name="topic" id="project-topic" class="form-select">
                                                <?php foreach ($availableTopics as $t): ?>
                                                    <option value="<?php echo htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="project-topic"><?php echo __('Тематика проекта'); ?></label>
                                            <div class="form-helper"><?php echo __('Используется для приоритизации подходящих сетей и сценариев.'); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="section-heading">
                                        <span class="section-icon"><i class="bi bi-chat-dots"></i></span>
                                        <div>
                                            <h3><?php echo __('Пожелания и заметки'); ?></h3>
                                            <p class="text-muted mb-0"><?php echo __('Поделитесь инструкциями по стилю, ограничениям и ключевым сообщениям — они попадут к авторам на всех уровнях.'); ?></p>
                                        </div>
                                    </div>
                                    <div class="section-grid section-grid--single">
                                        <div class="form-floating">
                                            <textarea name="wishes" id="project-wishes" class="form-control" style="height: 140px" placeholder="<?php echo __('Стиль, тематика, ограничения по бренду, ключевые сообщения...'); ?>"></textarea>
                                            <label for="project-wishes"><?php echo __('Глобальные пожелания (опционально)'); ?></label>
                                            <div class="form-helper"><?php echo __('Эти заметки появятся при добавлении новых ссылок и запуске каскадов.'); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-submit">
                                    <button type="submit" class="btn btn-gradient btn-lg w-100">
                                        <i class="bi bi-magic me-2"></i>
                                        <?php echo __('Создать проект и перейти к настройкам'); ?>
                                    </button>
                                    <div class="text-muted small mt-2 d-flex align-items-center gap-2">
                                        <i class="bi bi-shield-check text-success"></i>
                                        <span><?php echo __('Мы сохраним анализ страницы, сформируем превью и подготовим рабочее пространство.'); ?></span>
                                    </div>
                                </div>
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
                            <div class="title"><?php echo __('Подготовьте структуру ссылок'); ?></div>
                            <p><?php echo __('После создания проекта добавьте посадочные страницы по приоритету и разбейте их на этапы продвижения.'); ?></p>
                        </li>
                        <li>
                            <div class="title"><?php echo __('Проверьте индексацию'); ?></div>
                            <p><?php echo __('Главная страница и ключевые разделы должны быть доступны поисковым ботам и без авторизации.'); ?></p>
                        </li>
                        <li>
                            <div class="title"><?php echo __('Обновляйте пожелания'); ?></div>
                            <p><?php echo __('Актуализируйте бриф перед запуском новых каскадов, чтобы команда учитывала свежие акценты.'); ?></p>
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

<div id="project-create-overlay"
     class="project-create-overlay d-none"
     data-tips="<?php echo $creationTipsAttr; ?>"
     role="alertdialog"
     aria-hidden="true"
     aria-live="polite"
     aria-label="<?php echo htmlspecialchars(__('Создаем проект'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
     tabindex="-1">
    <div class="project-create-overlay__panel">
        <div class="project-create-overlay__spinner" aria-hidden="true">
            <div class="spinner-border text-info spinner-border-lg"></div>
        </div>
        <div class="project-create-overlay__content">
            <div class="project-create-overlay__headline">
                <span class="badge bg-info text-dark"><i class="bi bi-stars me-1"></i><?php echo __('Создаем проект'); ?></span>
                <h4 class="mb-1"><?php echo __('Подготавливаем рабочее пространство'); ?></h4>
            </div>
            <p class="project-create-overlay__tip" data-tip-text><?php echo $creationTipInitial; ?></p>
            <div class="project-create-overlay__progress" role="status">
                <span class="project-create-overlay__progress-bar" data-progress-bar></span>
            </div>
        </div>
        <div class="project-create-overlay__footer text-muted small">
            <i class="bi bi-shield-check me-2"></i><?php echo __('Это может занять до минуты — не закрывайте вкладку.'); ?>
        </div>
    </div>
</div>