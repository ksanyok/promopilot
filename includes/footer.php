<?php if (!is_admin()): ?>
    </div>
<?php endif; ?>
    <footer class="footer text-center">
        <div class="container">
            <img src="assets/img/logo.png" alt="PromoPilot Logo" class="footer-logo">
            <p>&copy; 2025 PromoPilot. <?php echo __('Все права защищены.'); ?> | <?php echo __('Версия'); ?>: <?php $version = include 'config/version.php'; echo $version; ?></p>
            <?php if (is_admin() && check_version()): ?>
                <a href="update.php" class="btn btn-warning">Обновить до новой версии</a>
            <?php endif; ?>
        </div>
    </footer>
    <script src="assets/js/script.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>