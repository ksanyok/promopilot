<?php 
    $useContainer = isset($pp_container) ? (bool)$pp_container : !is_admin();
    if ($useContainer): ?>
    </div>
<?php endif; ?>
    </main>
<?php
    $currentYear = (int)date('Y');
    $updateStatus = get_update_status();
    $currentVersion = htmlspecialchars(get_version(), ENT_QUOTES, 'UTF-8');
    $latestVersion = htmlspecialchars($updateStatus['latest'] ?? get_version(), ENT_QUOTES, 'UTF-8');
    $updateAvailable = !empty($updateStatus['is_new']);
    $releaseDate = '—';
    if (!empty($updateStatus['published_at'])) {
        try {
            $dt = new DateTimeImmutable($updateStatus['published_at']);
            $releaseDate = htmlspecialchars($dt->format('d.m.Y'), ENT_QUOTES, 'UTF-8');
        } catch (Throwable $e) {
            $releaseDate = htmlspecialchars((string)$updateStatus['published_at'], ENT_QUOTES, 'UTF-8');
        }
    }

    $productLinks = [
        [
            'label' => 'BuyReadySite',
            'href' => 'https://buyreadysite.com/',
            'icon' => 'bi-rocket-takeoff',
        ],
        [
            'label' => 'AI Content Wizard',
            'href' => 'https://aiwizard.buyreadysite.com/',
            'icon' => 'bi-stars',
        ],
        [
            'label' => 'AI SEO Pro',
            'href' => 'https://aiseo.buyreadysite.com/',
            'icon' => 'bi-graph-up-arrow',
        ],
    ];

    $legalLinks = [
        [
            'label' => __('Условия соглашения'),
            'href' => pp_url('public/terms.php'),
        ],
        [
            'label' => __('Риски использования'),
            'href' => pp_url('public/risk.php'),
        ],
        [
            'label' => __('Связаться с поддержкой'),
            'href' => 'https://buyreadysite.com/contact',
            'external' => true,
        ],
    ];
    $isAdmin = is_admin();
    $showUpdateButton = $isAdmin || isset($_SESSION['admin_user_id']);
    $footerClasses = ['footer', 'pp-footer'];
    if (!empty($pp_container_class) && strpos((string)$pp_container_class, 'static-page') !== false) {
        $footerClasses[] = 'static-page-footer';
    }
?>
    <footer class="<?php echo implode(' ', $footerClasses); ?>" id="app-footer">
        <div class="footer__inner pp-footer__inner">
            <div class="pp-footer__column pp-footer__column--primary">
                <div class="pp-footer__logo-row">
                    <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="PromoPilot" class="footer-logo" loading="lazy" width="124" height="32">
                    <span class="pp-footer__version-pill">v<?php echo $currentVersion; ?></span>
                </div>
                <p class="pp-footer__meta-line"><?php echo __('Последний релиз'); ?>: <strong><?php echo $releaseDate; ?></strong></p>
                <?php if ($showUpdateButton): ?>
                    <?php
                        $updateBtnClass = $updateAvailable ? 'btn-warning' : 'btn-outline-light';
                        $updateLabel = $updateAvailable ? __('Обновить до новой версии') : __('Проверить обновления');
                        $updateSuffix = $updateAvailable ? ' (v' . $latestVersion . ')' : '';
                    ?>
                    <a href="<?php echo pp_url('public/update.php'); ?>" class="btn <?php echo $updateBtnClass; ?> btn-sm pp-footer__update-btn">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                        <span><?php echo $updateLabel; ?><?php echo $updateSuffix; ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="pp-footer__column pp-footer__column--developer">
                <div class="pp-footer__developer">
                    <span class="pp-footer__spark" aria-hidden="true"></span>
                    <span class="pp-footer__brand" data-brand-scramble="true" data-brand-text="BuyReadySite" data-brand-glyphs="▮░▒▓█BRSDUYAEIOT1234567890" tabindex="0">BuyReadySite</span>
                </div>
                <p class="pp-footer__tagline"><?php echo __('Разработано компанией'); ?> <a href="https://buyreadysite.com/" target="_blank" rel="noopener">BuyReadySite.com</a></p>
            </div>

            <div class="pp-footer__column pp-footer__column--products">
                <h6 class="pp-footer__links-title mb-1"><?php echo __('Продукты BuyReadySite'); ?></h6>
                <ul class="pp-footer__products">
                    <?php foreach ($productLinks as $product): ?>
                        <li>
                            <a class="pp-footer__product-link" href="<?php echo htmlspecialchars($product['href'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                <i class="bi <?php echo htmlspecialchars($product['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars($product['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="pp-footer__bottom">
            <div class="pp-footer__bottom-inner">
                <span class="pp-footer__bottom-copy">&copy; <?php echo $currentYear; ?> PromoPilot • <?php echo __('Все права защищены.'); ?></span>
                <nav class="pp-footer__legal" aria-label="<?php echo htmlspecialchars(__('Полезные ссылки'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php foreach ($legalLinks as $index => $link): ?>
                        <a href="<?php echo htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo !empty($link['external']) ? ' target="_blank" rel="noopener"' : ''; ?>>
                            <?php echo htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <span class="pp-footer__bottom-brand">BuyReadySite</span>
            </div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Перенесено: подключаем наш скрипт после Bootstrap, чтобы работали tooltips -->
    <script src="<?php echo asset_url('js/script.js?v=' . rawurlencode(get_version())); ?>"></script>
</body>
</html>