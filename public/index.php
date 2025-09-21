<?php include '../includes/header.php'; ?>
<div class="row justify-content-center align-items-center" style="min-height:60vh;">
    <div class="col-lg-8">
        <div class="card text-center p-4">
            <div class="card-body">
                <img src="<?php echo asset_url('img/logo.png'); ?>" alt="PromoPilot" width="72" height="72" class="mb-3 rounded-2">
                <h1 class="mb-3"><?php echo __('Добро пожаловать в PromoPilot'); ?></h1>
                <p class="lead mb-4"><?php echo __('Платформа для управления проектами и балансом.'); ?></p>
                <div class="d-flex justify-content-center gap-3">
                    <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-right me-1"></i><?php echo __('Войти'); ?></a>
                    <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-success btn-lg"><i class="bi bi-person-plus me-1"></i><?php echo __('Зарегистрироваться'); ?></a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>