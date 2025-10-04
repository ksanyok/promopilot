<?php
$pp_container = true;
$pp_container_class = 'container-wide static-page';
$pageTitle = __('Условия соглашения PromoPilot');
include '../includes/header.php';
?>

<section class="static-page__section fade-in">
    <div class="section-head">
        <h1 class="h3 fw-bold mb-2"><?php echo __('Условия соглашения PromoPilot'); ?></h1>
        <p class="text-muted mb-0"><?php echo __('Настоящие условия регулируют использование платформы PromoPilot.'); ?></p>
    </div>
    <div class="static-page__content">
        <h2 class="h5"><?php echo __('1. Общие положения'); ?></h2>
        <p><?php echo __('Платформа PromoPilot предоставляется компанией BuyReadySite.com для автоматизации и контроля публикаций.'); ?></p>
        <p><?php echo __('Используя сервис, вы подтверждаете, что обладаете необходимыми правами на продвигаемые проекты.'); ?></p>

        <h2 class="h5 mt-4"><?php echo __('2. Обязанности пользователя'); ?></h2>
        <p><?php echo __('Сохраняйте актуальность контактных данных и соблюдайте требования законодательства страны вашего размещения.'); ?></p>
        <p><?php echo __('Не допускайте размещения запрещенного или вредоносного контента.'); ?></p>

        <h2 class="h5 mt-4"><?php echo __('3. Платежи и возвраты'); ?></h2>
        <p><?php echo __('Пополнение баланса осуществляется через подключенные платёжные системы.'); ?></p>
        <p><?php echo __('Возврат возможен по запросу в течение 14 дней при отсутствии запуска продвижения по средствам.'); ?></p>

        <h2 class="h5 mt-4"><?php echo __('4. Интеллектуальная собственность'); ?></h2>
        <p><?php echo __('Все материалы платформы являются собственностью BuyReadySite.com.'); ?></p>

        <h2 class="h5 mt-4"><?php echo __('5. Ограничение ответственности'); ?></h2>
        <p><?php echo __('PromoPilot предоставляет инструменты для продвижения, но не гарантирует конкретные позиции в поисковых системах.'); ?></p>

        <div class="static-page__callout mt-4">
            <h3 class="h6 mb-2"><?php echo __('Связь с поддержкой'); ?></h3>
            <p class="mb-0"><?php echo __('За технической поддержкой обращайтесь в службу BuyReadySite.'); ?></p>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
