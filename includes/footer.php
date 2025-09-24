<?php 
    $useContainer = isset($pp_container) ? (bool)$pp_container : !is_admin();
    if ($useContainer): ?>
    </div>
<?php endif; ?>
    </main>
    <footer class="footer text-center">
        <div class="container">
            <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="PromoPilot Logo" class="footer-logo">
            <?php $upd = get_update_status(); ?>
            <p>&copy; 2025 PromoPilot. <?php echo __('Все права защищены.'); ?> | <?php echo __('Версия'); ?>: <?php echo htmlspecialchars(get_version()); ?><?php if (!empty($upd['latest'])): ?> | <?php echo __('Последний релиз'); ?>: v<?php echo htmlspecialchars($upd['latest']); ?><?php if (!empty($upd['published_at'])): ?> (<?php echo __('от'); ?> <?php echo htmlspecialchars($upd['published_at']); ?>)<?php endif; ?><?php endif; ?></p>
            <?php if (is_admin() && !empty($upd['is_new'])): ?>
                <a href="<?php echo pp_url('public/update.php'); ?>" class="btn btn-warning"><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить до новой версии'); ?></a>
            <?php endif; ?>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Перенесено: подключаем наш скрипт после Bootstrap, чтобы работали tooltips -->
    <script src="<?php echo asset_url('js/script.js?v=' . rawurlencode(get_version())); ?>"></script>
</body>
</html>