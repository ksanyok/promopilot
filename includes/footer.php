<?php 
    $useContainer = isset($pp_container) ? (bool)$pp_container : !is_admin();
    if ($useContainer): ?>
    </div>
<?php endif; ?>
    </main>
    <footer class="footer text-center">
        <div class="container">
            <img src="<?php echo asset_url('img/logo.png'); ?>" alt="PromoPilot Logo" class="footer-logo">
            <p>&copy; 2025 PromoPilot. <?php echo __('Все права защищены.'); ?> | <?php echo __('Версия'); ?>: <?php echo htmlspecialchars(get_version()); ?></p>
            <?php if (is_admin() && check_version(true)): ?>
                <a href="<?php echo pp_url('public/update.php'); ?>" class="btn btn-warning"><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить до новой версии'); ?></a>
            <?php endif; ?>
        </div>
    </footer>
    <script src="<?php echo asset_url('js/script.js'); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>