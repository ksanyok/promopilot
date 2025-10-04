<?php
require_once __DIR__ . '/../includes/init.php';
$pp_container = true;
$pp_container_class = 'container-wide static-page';
$pageTitle = __('Предупреждение о рисках PromoPilot');
include '../includes/header.php';
?>

<section class="static-page__section fade-in">
    <div class="section-head">
        <h1 class="h3 fw-bold mb-2"><?php echo __('Предупреждение о рисках PromoPilot'); ?></h1>
        <p class="text-muted mb-0"><?php echo __('Любое продвижение связано с неопределенностью и внешними факторами.'); ?></p>
    </div>
    <div class="static-page__content">
        <h2 class="h5"><?php echo __('Маркетинговые риски'); ?></h2>
        <p><?php echo __('Результаты зависят от конкуренции, бюджетов и качества исходного сайта.'); ?></p>

        <h2 class="h5 mt-4"><?php echo __('Технические риски'); ?></h2>
        <p><?php echo __('Возможны сбои сетей, API и сторонних сервисов.'); ?></p>

        <h2 class="h5 mt-4"><?php echo __('Финансовые риски'); ?></h2>
        <p><?php echo __('Стоимость услуг может изменяться при колебании тарифов поставщиков и курсов валют.'); ?></p>

        <h2 class="h5 mt-4"><?php echo __('Рекомендации по снижению рисков'); ?></h2>
        <p><?php echo __('Используйте отсроченный запуск, следите за уведомлениями и сохраняйте резервные копии контента.'); ?></p>

        <div class="static-page__callout mt-4">
            <h3 class="h6 mb-2"><?php echo __('Связь с поддержкой'); ?></h3>
            <p class="mb-0"><?php echo __('При возникновении вопросов свяжитесь с командой BuyReadySite через форму обратной связи.'); ?></p>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
