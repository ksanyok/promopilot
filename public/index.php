<?php
// Устанавливаем расширенный контейнер и класс для лендинга до подключения header
$pp_container = true;
$pp_container_class = 'container-wide landing-container';
include '../includes/header.php'; ?>

<section class="landing-hero" id="hero">
  <div class="row align-items-center g-5 flex-column-reverse flex-lg-row">
    <div class="col-lg-6">
      <div class="hero-intro fade-in">
        <h1 class="hero-title fw-bold mb-3"><?php echo __('PromoPilot — многоуровневое контентное и ссылочное продвижение'); ?></h1>
        <p class="lead mb-4 hero-lead"><?php echo __('Автоматизированная каскадная структура публикаций (5 → 100 → 300) с ускоренной индексацией, естественным распределением ссылочного веса и прозрачным мониторингом прогресса.'); ?></p>
        <ul class="hero-points list-unstyled mb-4">
          <li><i class="bi bi-diagram-3-fill"></i><span><?php echo __('3 уровня усиления: база → контекст → покрытие'); ?></span></li>
          <li><i class="bi bi-broadcast-pin"></i><span><?php echo __('Автопинг и распределённый тайминг размещений'); ?></span></li>
          <li><i class="bi bi-shield-check"></i><span><?php echo __('Минимизация рисков переоптимизации профиля'); ?></span></li>
          <li><i class="bi bi-graph-up-arrow"></i><span><?php echo __('Рост видимости за счёт тематической глубины'); ?></span></li>
        </ul>
        <div class="d-flex flex-wrap gap-3 mb-4">
          <a href="#cta" class="btn btn-gradient btn-lg"><i class="bi bi-rocket-takeoff me-2"></i><?php echo __('Запустить проект'); ?></a>
          <a href="#levels" class="btn btn-outline-light btn-lg"><i class="bi bi-info-circle me-2"></i><?php echo __('Как это работает'); ?></a>
        </div>
        <div class="hero-metrics d-flex flex-wrap gap-3">
          <div class="metric-badge"><strong>405</strong><span><?php echo __('публикаций в пакете'); ?></span></div>
          <div class="metric-badge"><strong>3</strong><span><?php echo __('уровня каскада'); ?></span></div>
          <div class="metric-badge"><strong>+индекс</strong><span><?php echo __('ускоренная подача'); ?></span></div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="hero-media position-relative text-center">
        <picture>
          <!-- NOTE: hero_main.jpg (рекомендуемо 1600x900, webp дубликат: hero_main.webp); hero_main_mobile.jpg (900x1100) -->
          <source srcset="<?php echo asset_url('img/hero_main.jpg'); ?>" type="image/webp">
          <img src="<?php echo asset_url('img/hero_main.jpg'); ?>" alt="<?php echo __('Схема многоуровневого продвижения'); ?>" class="img-fluid rounded-4 shadow hero-image mb-3" loading="lazy" width="1600" height="900">
        </picture>
        <figcaption class="small text-muted"><?php echo __('Визуализация структуры 5 → 100 → 300'); ?></figcaption>
        <?php if (!is_logged_in()): ?>
        <div class="auth-panel card mt-4 text-start">
          <div class="card-body p-4">
            <ul class="nav nav-pills mb-3 gap-2 auth-tabs" id="pills-auth" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-login" data-bs-toggle="pill" data-bs-target="#pane-login" type="button" role="tab" aria-controls="pane-login" aria-selected="true"><?php echo __('Вход'); ?></button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-register" data-bs-toggle="pill" data-bs-target="#pane-register" type="button" role="tab" aria-controls="pane-register" aria-selected="false"><?php echo __('Регистрация'); ?></button>
              </li>
            </ul>
            <div class="tab-content" id="pills-authContent">
              <div class="tab-pane fade show active" id="pane-login" role="tabpanel" aria-labelledby="tab-login">
                <form method="post" action="<?php echo pp_url('auth/login.php'); ?>" class="auth-form" novalidate>
                  <?php echo csrf_field(); ?>
                  <div class="mb-3">
                    <label class="form-label"><?php echo __('Email или логин'); ?></label>
                    <input type="text" name="login" class="form-control form-control-lg" required autocomplete="username">
                  </div>
                  <div class="mb-3">
                    <label class="form-label d-flex justify-content-between align-items-center"> <span><?php echo __('Пароль'); ?></span> <a href="<?php echo pp_url('auth/login.php'); ?>#recover" class="small text-decoration-none"><?php echo __('Забыли?'); ?></a></label>
                    <input type="password" name="password" class="form-control form-control-lg" required autocomplete="current-password">
                  </div>
                  <div class="d-grid gap-2">
                    <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i><?php echo __('Войти'); ?></button>
                    <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-outline-light btn-sm"><?php echo __('Создать аккаунт отдельно'); ?></a>
                  </div>
                </form>
              </div>
              <div class="tab-pane fade" id="pane-register" role="tabpanel" aria-labelledby="tab-register">
                <form method="post" action="<?php echo pp_url('auth/register.php'); ?>" class="auth-form" novalidate>
                  <?php echo csrf_field(); ?>
                  <div class="mb-3">
                    <label class="form-label"><?php echo __('Email'); ?></label>
                    <input type="email" name="email" class="form-control form-control-lg" required autocomplete="email">
                  </div>
                  <div class="mb-3">
                    <label class="form-label"><?php echo __('Логин'); ?></label>
                    <input type="text" name="username" class="form-control form-control-lg" required autocomplete="username">
                  </div>
                  <div class="mb-3">
                    <label class="form-label"><?php echo __('Пароль'); ?></label>
                    <input type="password" name="password" class="form-control form-control-lg" required autocomplete="new-password">
                  </div>
                  <div class="d-grid gap-2">
                    <button class="btn btn-success btn-lg" type="submit"><i class="bi bi-person-plus me-2"></i><?php echo __('Зарегистрироваться'); ?></button>
                    <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-outline-light btn-sm"><?php echo __('У меня уже есть аккаунт'); ?></a>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="landing-section" id="levels">
  <div class="section-head">
    <h2 class="h3 fw-bold mb-2"><?php echo __('3 уровня усиления и распределения'); ?></h2>
    <p class="text-muted mb-0"><?php echo __('Ступенчатое наращивание контента формирует устойчивый ссылочный профиль.'); ?></p>
  </div>
  <div class="row g-4 align-items-start mt-1">
    <div class="col-xl-5">
      <div class="levels-desc pe-xl-3">
        <div class="mb-3 level-block">
          <h6 class="text-primary mb-1"><span class="badge bg-primary-subtle text-primary-emphasis">1</span> <?php echo __('Уровень 1 — базовые активы'); ?></h6>
          <p class="mb-2 small"><?php echo __('5 фундаментальных Web 2.0 страниц со ссылками на продвигаемый ресурс. Высокое качество и тематическая релевантность.'); ?></p>
        </div>
        <div class="mb-3 level-block">
          <h6 class="text-primary mb-1"><span class="badge bg-primary-subtle text-primary-emphasis">2</span> <?php echo __('Уровень 2 — контекстуальное усиление'); ?></h6>
          <p class="mb-2 small"><?php echo __('По 20 статей на каждую базовую (100). Раскрывают подтемы, углубляя семантику вокруг ключевых объектов.'); ?></p>
        </div>
        <div class="mb-3 level-block">
          <h6 class="text-primary mb-1"><span class="badge bg-primary-subtle text-primary-emphasis">3</span> <?php echo __('Уровень 3 — широкое покрытие'); ?></h6>
          <p class="mb-2 small"><?php echo __('По 3 публикации на каждую статью уровня 2 (300). Создают распределённую сеть сигналов для ускорения индексации.'); ?></p>
        </div>
        <div class="ratio ratio-16x9 rounded bg-gradient position-relative overflow-hidden mb-3 border border-1 border-opacity-25 border-primary-subtle">
          <!-- NOTE: diagram_levels.svg (адаптивный SVG до ~1200x650) -->
          <img src="<?php echo asset_url('img/diagram_levels.svg'); ?>" alt="<?php echo __('Диаграмма уровней'); ?>" class="w-100 h-100 object-fit-contain p-3" loading="lazy">
        </div>
        <p class="small text-muted mb-0"><i class="bi bi-lightning-charge me-1"></i><?php echo __('Каждая URL пингуется и распределяется по таймингу для естественной динамики.'); ?></p>
      </div>
    </div>
    <div class="col-xl-7">
      <div class="table-responsive shadow-sm rounded overflow-hidden level-table-wrapper mb-4">
        <table class="table table-dark table-striped table-sm align-middle mb-0">
          <thead>
            <tr>
              <th><?php echo __('Уровень'); ?></th>
              <th><?php echo __('Описание'); ?></th>
              <th><?php echo __('Публикаций на уровне'); ?></th>
              <th><?php echo __('Всего накопительно'); ?></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="fw-bold">1</td>
              <td><?php echo __('Базовые Web 2.0 страницы (основа каскада)'); ?></td>
              <td>5</td>
              <td>5</td>
            </tr>
            <tr>
              <td class="fw-bold">2</td>
              <td><?php echo __('Контекстуальные материалы, усиливающие фундамент'); ?></td>
              <td>100</td>
              <td>105</td>
            </tr>
            <tr>
              <td class="fw-bold">3</td>
              <td><?php echo __('Широкое покрытие для ускоренной индексации'); ?></td>
              <td>300</td>
              <td>405</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="table-secondary text-dark">
              <th colspan="2" class="text-end"><?php echo __('Итого'); ?></th>
              <th>405</th>
              <th>405</th>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="row g-3 level-cards">
        <div class="col-md-4">
          <div class="card h-100 text-center level-card">
            <div class="card-body p-3">
              <!-- NOTE: level1.png (рекомендуемо 480x360 или квадрат 512x512 / webp) -->
              <img src="<?php echo asset_url('img/level1.png'); ?>" alt="L1" class="img-fluid rounded level-image mb-2" loading="lazy">
              <h6 class="fw-bold mb-1">L1</h6>
              <p class="small mb-0 text-muted"><?php echo __('Точка входа — качественные фундаментальные ссылки.'); ?></p>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card h-100 text-center level-card">
            <div class="card-body p-3">
              <!-- NOTE: level2.png -->
              <img src="<?php echo asset_url('img/level2.png'); ?>" alt="L2" class="img-fluid rounded level-image mb-2" loading="lazy">
              <h6 class="fw-bold mb-1">L2</h6>
              <p class="small mb-0 text-muted"><?php echo __('Глубина и тематическое окружение первого слоя.'); ?></p>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card h-100 text-center level-card">
            <div class="card-body p-3">
              <!-- NOTE: level3.png -->
              <img src="<?php echo asset_url('img/level3.png'); ?>" alt="L3" class="img-fluid rounded level-image mb-2" loading="lazy">
              <h6 class="fw-bold mb-1">L3</h6>
              <p class="small mb-0 text-muted"><?php echo __('Ширина покрытия и ускорение индексации.'); ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="landing-section" id="benefits">
  <div class="row g-5 align-items-center">
    <div class="col-lg-6 order-2 order-lg-1">
      <h2 class="h3 fw-bold mb-3"><?php echo __('Почему структура работает'); ?></h2>
      <p><?php echo __('Постепенное горизонтально-вертикальное расширение ссылочного поля формирует естественные поведенческие и индексные сигналы: мы контролируем тематику, интервалы и пропорции.'); ?></p>
      <ul class="list-unstyled benefit-list mb-4">
        <li><i class="bi bi-check-circle"></i><?php echo __(' Естественная динамика без резких всплесков'); ?></li>
        <li><i class="bi bi-check-circle"></i><?php echo __(' Семантическая глубина увеличивает релевантность'); ?></li>
        <li><i class="bi bi-check-circle"></i><?php echo __(' Распределённые точки входа ускоряют индексацию'); ?></li>
        <li><i class="bi bi-check-circle"></i><?php echo __(' Управляемая вариативность анкор-листа'); ?></li>
      </ul>
      <div class="d-flex gap-3 flex-wrap">
        <a href="#cta" class="btn btn-primary btn-lg"><i class="bi bi-lightning-charge me-2"></i><?php echo __('Начать сейчас'); ?></a>
        <a href="#process" class="btn btn-outline-light btn-lg"><i class="bi bi-clock-history me-2"></i><?php echo __('Этапы'); ?></a>
      </div>
    </div>
    <div class="col-lg-6 order-1 order-lg-2 text-center">
      <!-- NOTE: benefits.png (рекомендуемо 1100x740 / webp) -->
      <picture>
        <source srcset="<?php echo asset_url('img/benefits.png'); ?>" type="image/webp">
        <img src="<?php echo asset_url('img/benefits.png'); ?>" alt="<?php echo __('Преимущества структуры'); ?>" class="img-fluid rounded-4 shadow benefits-image" loading="lazy" width="1100" height="740">
      </picture>
      <div class="small text-muted mt-2"><?php echo __('Схематичное отражение распределения веса и индексации'); ?></div>
    </div>
  </div>
</section>

<section class="landing-section" id="process">
  <div class="section-head">
    <h2 class="h4 fw-bold mb-2"><?php echo __('Основные этапы запуска'); ?></h2>
    <p class="text-muted mb-0"><?php echo __('От заявки до первых индексаций.'); ?></p>
  </div>
  <div class="row g-4 process-steps">
    <div class="col-md-4">
      <div class="step-item h-100 p-4 rounded border position-relative">
        <div class="step-number">1</div>
        <h6 class="fw-bold mb-2"><?php echo __('Старт & семантика'); ?></h6>
        <p class="small mb-0"><?php echo __('Сбор тематики, уточнение целевых страниц, определение пропорций анкорных типов.'); ?></p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="step-item h-100 p-4 rounded border position-relative">
        <div class="step-number">2</div>
        <h6 class="fw-bold mb-2"><?php echo __('Генерация & публикация'); ?></h6>
        <p class="small mb-0"><?php echo __('Создание каскада контента и постепенное размещение с распределённым таймингом.'); ?></p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="step-item h-100 p-4 rounded border position-relative">
        <div class="step-number">3</div>
        <h6 class="fw-bold mb-2"><?php echo __('Индекс & анализ'); ?></h6>
        <p class="small mb-0"><?php echo __('Пингование, проверка появления, корректировки для ускорения охвата.'); ?></p>
      </div>
    </div>
  </div>
  <!-- NOTE: process_flow.svg (рекомендуемо до 1400x500) -->
  <div class="mt-4 text-center small text-muted">process_flow.svg — <?php echo __('дополнительная визуализация этапов (опционально)'); ?></div>
</section>

<section class="landing-section cta-final" id="cta">
  <div class="card cta-card overflow-hidden">
    <div class="card-body p-5 p-md-5 text-center text-md-start position-relative">
      <div class="row align-items-center g-4">
        <div class="col-md-7">
          <h2 class="h3 fw-bold mb-3"><?php echo __('Готовы запустить каскад?'); ?></h2>
          <p class="mb-4 lead mb-md-3"><?php echo __('Создайте аккаунт и получите доступ к панели прогресса и детальным метрикам публикаций.'); ?></p>
          <div class="d-flex flex-wrap gap-3">
            <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-gradient btn-lg"><i class="bi bi-person-plus me-2"></i><?php echo __('Зарегистрироваться'); ?></a>
            <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-outline-light btn-lg"><i class="bi bi-box-arrow-in-right me-2"></i><?php echo __('Войти'); ?></a>
          </div>
          <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i><?php echo __('Без спама. Данные защищены.'); ?></p>
        </div>
        <div class="col-md-5 text-center text-md-end">
          <!-- NOTE: dashboard_preview.png (рекомендуемо 900x640 / webp) -->
          <img src="<?php echo asset_url('img/dashboard_preview.png'); ?>" alt="<?php echo __('Превью панели'); ?>" class="img-fluid rounded-4 shadow-lg dashboard-preview" loading="lazy" width="900" height="640">
        </div>
      </div>
    </div>
  </div>
</section>

<section class="landing-section note-section">
  <div class="p-4 rounded border bg-dark-subtle text-dark">
    <p class="small mb-1"><strong><?php echo __('Примечание'); ?>:</strong> <?php echo __('Объём и конфигурация структуры могут адаптироваться под нишу, язык и уровень конкуренции.'); ?></p>
    <p class="small mb-0 text-muted"><?php echo __('Мониторинг прогресса и статусы индексации доступны после старта проекта в личном кабинете.'); ?></p>
  </div>
</section>

<?php include '../includes/footer.php'; ?>