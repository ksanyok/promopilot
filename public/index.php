<?php include '../includes/header.php'; ?>
<div class="row justify-content-center align-items-center" style="min-height:60vh;">
    <div class="col-lg-8">
        <div class="card text-center p-4 mb-5">
            <div class="card-body">
                <img src="<?php echo asset_url('img/logo.png'); ?>" alt="PromoPilot" width="72" height="72" class="mb-3 rounded-2">
                <h1 class="mb-3"><?php echo __('Добро пожаловать в PromoPilot'); ?></h1>
                <p class="lead mb-4"><?php echo __('Платформа многоуровневого контентного и ссылочного продвижения.'); ?></p>
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-right me-1"></i><?php echo __('Войти'); ?></a>
                    <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-success btn-lg"><i class="bi bi-person-plus me-1"></i><?php echo __('Зарегистрироваться'); ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hero / Value Proposition -->
<section class="mb-5" id="hero-structure">
    <div class="row align-items-center g-4">
        <div class="col-md-6 order-md-1 order-2">
            <h2 class="mb-3 fw-bold"><?php echo __('Многоуровневое продвижение: 405 публикаций = усиление видимости'); ?></h2>
            <p class="lead"><?php echo __('Мы создаём каскад из 3 уровней контента на Web 2.0 и смежных площадках. Каждый следующий уровень усиливает предыдущий, ускоряя индексацию и распределяя ссылочный вес естественно.'); ?></p>
            <ul class="list-icon mb-4">
                <li><i class="bi bi-diagram-3 text-primary me-2"></i><?php echo __('Структура: 5 → 100 → 300 публикаций'); ?></li>
                <li><i class="bi bi-broadcast-pin text-primary me-2"></i><?php echo __('Автопинг: ускоренная индексация каждого URL'); ?></li>
                <li><i class="bi bi-shield-check text-primary me-2"></i><?php echo __('Естественный профиль без переоптимизации'); ?></li>
                <li><i class="bi bi-graph-up-arrow text-primary me-2"></i><?php echo __('Рост тематического покрытия и глубины'); ?></li>
            </ul>
            <div class="d-flex flex-wrap gap-3">
                <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-gradient btn-lg"><i class="bi bi-rocket-takeoff me-2"></i><?php echo __('Запустить продвижение'); ?></a>
                <a href="#levels" class="btn btn-outline-light btn-lg"><i class="bi bi-info-circle me-2"></i><?php echo __('Подробнее об уровнях'); ?></a>
            </div>
        </div>
        <div class="col-md-6 order-md-2 order-1 text-center">
            <!-- Hero image placeholder -->
            <figure class="mb-0">
                <img src="<?php echo asset_url('img/hero_main.jpg'); ?>" alt="<?php echo __('Главная схема многоуровневого продвижения'); ?>" class="img-fluid rounded shadow-sm d-none d-md-block marketing-hero" loading="lazy">
                <img src="<?php echo asset_url('img/hero_main_mobile.jpg'); ?>" alt="<?php echo __('Схема продвижения (мобил.)'); ?>" class="img-fluid rounded shadow-sm d-md-none marketing-hero-mobile" loading="lazy">
                <figcaption class="small text-muted mt-2"><?php echo __('Изображение: визуальная пирамида 5 / 100 / 300'); ?></figcaption>
            </figure>
            <!-- NOTE: hero_main.jpg (рекомендуемо 1600x900), hero_main_mobile.jpg (900x1100) -->
        </div>
    </div>
</section>

<!-- Level Explanation -->
<section id="levels" class="mb-5">
    <div class="row g-4 align-items-start">
        <div class="col-lg-5">
            <h3 class="fw-semibold mb-3"><?php echo __('Как устроена структура: 3 уровня усиления'); ?></h3>
            <div class="mb-4">
                <div class="mb-3">
                    <strong><?php echo __('Уровень 1'); ?>:</strong>
                    <span><?php echo __('5 уникальных статей на авторитетных Web 2.0 площадках → прямые ссылки на ваш сайт.'); ?></span>
                </div>
                <div class="mb-3">
                    <strong><?php echo __('Уровень 2'); ?>:</strong>
                    <span><?php echo __('По 20 статей на каждую из 5 базовых (100 шт.) → усиливают первичный слой и увеличивают глубину.'); ?></span>
                </div>
                <div class="mb-3">
                    <strong><?php echo __('Уровень 3'); ?>:</strong>
                    <span><?php echo __('По 3 публикации на каждую статью уровня 2 (300 шт.) → создают широкое покрытие для индексации.'); ?></span>
                </div>
            </div>
            <div class="ratio ratio-16x9 bg-dark rounded d-flex align-items-center justify-content-center position-relative overflow-hidden mb-4">
                <img src="<?php echo asset_url('img/diagram_levels.svg'); ?>" alt="<?php echo __('Диаграмма уровней'); ?>" class="w-100 h-100 object-fit-contain p-3" loading="lazy">
                <!-- NOTE: diagram_levels.svg (рекомендуемо до ~1200x650, адаптивный SVG) -->
            </div>
            <p class="small text-muted mb-0"><?php echo __('Каждая публикация проходит пингование для ускорения индексации.'); ?></p>
        </div>
        <div class="col-lg-7">
            <div class="table-responsive shadow-sm rounded overflow-hidden">
                <table class="table table-dark table-striped table-bordered align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?php echo __('Уровень'); ?></th>
                            <th><?php echo __('Описание'); ?></th>
                            <th><?php echo __('Статей на уровне'); ?></th>
                            <th><?php echo __('Суммарно публикаций'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-bold">1</td>
                            <td><?php echo __('Базовые Web 2.0 страницы со ссылкой на ваш сайт'); ?></td>
                            <td>5</td>
                            <td>5</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">2</td>
                            <td><?php echo __('Усиление: 20 тематических материалов на каждую основную'); ?></td>
                            <td>100</td>
                            <td>105</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">3</td>
                            <td><?php echo __('Широкое покрытие: 3 поддерживающих публикации на каждую из уровня 2'); ?></td>
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
            <div class="row mt-4 g-3">
                <div class="col-md-4">
                    <div class="card h-100 level-card text-center">
                        <div class="card-body p-3">
                            <img src="<?php echo asset_url('img/level1.png'); ?>" alt="L1" class="img-fluid mb-2 level-image" loading="lazy">
                            <!-- NOTE: level1.png (рекомендуемо 420x300 или квадрат 512x512) -->
                            <h6 class="fw-bold mb-2">L1</h6>
                            <p class="small mb-0"><?php echo __('Точка входа — прямые ссылочные активы.'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 level-card text-center">
                        <div class="card-body p-3">
                            <img src="<?php echo asset_url('img/level2.png'); ?>" alt="L2" class="img-fluid mb-2 level-image" loading="lazy">
                            <!-- NOTE: level2.png -->
                            <h6 class="fw-bold mb-2">L2</h6>
                            <p class="small mb-0"><?php echo __('Контекстуальное усиление первого слоя.'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 level-card text-center">
                        <div class="card-body p-3">
                            <img src="<?php echo asset_url('img/level3.png'); ?>" alt="L3" class="img-fluid mb-2 level-image" loading="lazy">
                            <!-- NOTE: level3.png -->
                            <h6 class="fw-bold mb-2">L3</h6>
                            <p class="small mb-0"><?php echo __('Ширина и скорость индексации.'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Why It Works / Benefits -->
<section class="mb-5" id="benefits">
    <div class="row g-4 align-items-center">
        <div class="col-md-7">
            <h3 class="fw-semibold mb-3"><?php echo __('Почему это работает'); ?></h3>
            <p><?php echo __('Каскадные уровни распределяют ссылочный вес постепенно, избегая резких всплесков и создавая естественный профиль. Мы контролируем тематику, анкор-лист, частоту и пропорции типов ссылок.'); ?></p>
            <ul class="mb-4 list-unstyled ms-0">
                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i><?php echo __('Естественная динамика без переспама.'); ?></li>
                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i><?php echo __('Диверсификация доменов и площадок Web 2.0.'); ?></li>
                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i><?php echo __('Поддерживающие уровни ускоряют попадание в индекс.'); ?></li>
                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i><?php echo __('Гибкая адаптация под нишу и язык.'); ?></li>
            </ul>
            <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-primary btn-lg"><i class="bi bi-lightning-charge me-2"></i><?php echo __('Начать сейчас'); ?></a>
        </div>
        <div class="col-md-5 text-center">
            <figure class="mb-0">
                <img src="<?php echo asset_url('img/benefits.png'); ?>" alt="<?php echo __('Преимущества структуры'); ?>" class="img-fluid rounded shadow-sm benefits-image" loading="lazy">
                <!-- NOTE: benefits.png (рекомендуемо 900x650, иллюстрация выгод) -->
                <figcaption class="small text-muted mt-2"><?php echo __('Иллюстрация преимуществ многоуровневой модели'); ?></figcaption>
            </figure>
        </div>
    </div>
</section>

<!-- Summary Note -->
<section class="mb-5">
    <div class="p-4 rounded border bg-dark-subtle text-dark">
        <p class="small mb-1"><strong><?php echo __('Примечание'); ?>:</strong> <?php echo __('Структура и объём публикаций могут адаптироваться под нишу, язык и конкурентность.'); ?></p>
        <p class="small mb-0 text-muted"><?php echo __('Прогресс по уровням станет доступен после старта проекта внутри личного кабинета.'); ?></p>
    </div>
</section>

<?php include '../includes/footer.php'; ?>