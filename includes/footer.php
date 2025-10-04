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
    $publishedAtRaw = trim((string)($updateStatus['published_at'] ?? ''));
    $publishedAt = $publishedAtRaw !== '' ? htmlspecialchars($publishedAtRaw, ENT_QUOTES, 'UTF-8') : '—';

    $dataDir = PP_ROOT_PATH . '/config/data';
    if (!is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
    $lastUpdateFile = $dataDir . '/last_update.txt';
    $lastUpdateDate = '—';
    if (is_file($lastUpdateFile)) {
        $raw = trim((string)@file_get_contents($lastUpdateFile));
        if ($raw !== '') {
            $lastUpdateDate = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        }
    }
    if ($lastUpdateDate === '—' && $publishedAt !== '—') {
        $lastUpdateDate = $publishedAt;
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
    ];
?>
    <footer class="footer pp-footer" id="app-footer">
        <div class="footer__inner pp-footer__inner">
            <div class="pp-footer__column pp-footer__column--meta">
                <div class="pp-footer__logo-block">
                    <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="PromoPilot" class="footer-logo" loading="lazy" width="128" height="32">
                    <span class="pp-footer__version-badge">v<?php echo $currentVersion; ?></span>
                </div>
                <dl class="pp-footer__meta">
                    <div class="pp-footer__meta-row">
                        <dt><?php echo __('Текущая версия'); ?></dt>
                        <dd>v<?php echo $currentVersion; ?></dd>
                    </div>
                    <div class="pp-footer__meta-row">
                        <dt><?php echo __('Доступный релиз'); ?></dt>
                        <dd>v<?php echo $latestVersion; ?></dd>
                    </div>
                    <div class="pp-footer__meta-row">
                        <dt><?php echo __('Последняя проверка'); ?></dt>
                        <dd><?php echo $lastUpdateDate; ?></dd>
                    </div>
                    <div class="pp-footer__meta-row">
                        <dt><?php echo __('Последний релиз'); ?></dt>
                        <dd><?php echo $publishedAt; ?></dd>
                    </div>
                </dl>
                <?php if ($updateAvailable): ?>
                    <div class="pp-footer__update">
                        <span class="badge bg-warning text-dark align-self-start"><?php echo __('Доступно обновление'); ?></span>
                        <?php if (is_admin()): ?>
                            <a href="<?php echo pp_url('public/update.php'); ?>" class="btn btn-warning btn-sm mt-2"><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить до новой версии'); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pp-footer__column pp-footer__column--brand">
                <div class="pp-footer__developer">
                    <span class="pp-footer__spark" aria-hidden="true"></span>
                    <span class="pp-footer__brand" data-brand-animate="true" data-brand-text="BuyReadySite" tabindex="0">BuyReadySite</span>
                </div>
                <p class="pp-footer__tagline"><?php echo __('Разработано компанией'); ?> <strong>BuyReadySite.com</strong></p>
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

            <div class="pp-footer__column pp-footer__column--links">
                <div class="pp-footer__links-group">
                    <h6 class="pp-footer__links-title"><?php echo __('Полезные ссылки'); ?></h6>
                    <ul>
                        <?php foreach ($legalLinks as $link): ?>
                            <li><a href="<?php echo htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8'); ?></a></li>
                        <?php endforeach; ?>
                        <li><a href="https://buyreadysite.com/contact" target="_blank" rel="noopener"><?php echo __('Связаться с поддержкой'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="pp-footer__bottom">
            <span>&copy; <?php echo $currentYear; ?> PromoPilot • <?php echo __('Все права защищены.'); ?></span>
            <span class="pp-footer__bottom-brand">BuyReadySite</span>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Перенесено: подключаем наш скрипт после Bootstrap, чтобы работали tooltips -->
    <script src="<?php echo asset_url('js/script.js?v=' . rawurlencode(get_version())); ?>"></script>
</body>
</html>