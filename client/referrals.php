<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect(is_admin() ? 'admin/admin.php' : 'auth/login.php');
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$conn = connect_db();

// Ensure this user has a referral code
$code = '';
try {
    if (function_exists('pp_referral_get_or_create_user_code')) {
        $code = pp_referral_get_or_create_user_code($conn, $uid);
    } else {
        $st = $conn->prepare("SELECT referral_code FROM users WHERE id = ? LIMIT 1");
        if ($st) { $st->bind_param('i', $uid); $st->execute(); $r = $st->get_result(); if ($r) { $row = $r->fetch_assoc(); $code = (string)($row['referral_code'] ?? ''); $r->free(); } $st->close(); }
    }
} catch (Throwable $e) { /* ignore */ }

// Build referral link (root URL with ?ref=code)
$refLink = pp_url('') . '/?ref=' . rawurlencode($code);

// Stats: total referred users and earnings
$totalUsers = 0; $totalEarnings = 0.0;
$cookieDays = (int)get_setting('referral_cookie_days', '30');
$defaultPercent = (float)str_replace(',', '.', (string)get_setting('referral_default_percent', '5.0'));
try {
    // Count referrals
    if ($st = $conn->prepare('SELECT COUNT(*) AS c FROM users WHERE referred_by = ?')) {
        $st->bind_param('i', $uid);
        $st->execute();
        if ($res = $st->get_result()) { $totalUsers = (int)($res->fetch_assoc()['c'] ?? 0); }
        $st->close();
    }
    // Sum earnings from referral events
    if ($st = $conn->prepare("SELECT SUM(delta) AS s FROM balance_history WHERE user_id = ? AND source = 'referral'")) {
        $st->bind_param('i', $uid);
        $st->execute();
        if ($res = $st->get_result()) { $totalEarnings = (float)($res->fetch_assoc()['s'] ?? 0); }
        $st->close();
    }
} catch (Throwable $e) { /* ignore */ }

// Recent referral transactions (earnings)
$recentEvents = [];
if ($st = $conn->prepare("SELECT id, delta, meta_json, created_at FROM balance_history WHERE user_id = ? AND source = 'referral' ORDER BY id DESC LIMIT 25")) {
    $st->bind_param('i', $uid);
    $st->execute();
    if ($res = $st->get_result()) {
        while ($row = $res->fetch_assoc()) {
            $row['delta'] = (float)$row['delta'];
            $meta = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
            $row['from_user_id'] = (int)($meta['from_user_id'] ?? 0);
            $row['percent'] = (float)($meta['percent'] ?? 0);
            $row['transaction_id'] = (int)($meta['transaction_id'] ?? 0);
            $recentEvents[] = $row;
        }
        $res->free();
    }
    $st->close();
}

// Referred users list (last 25)
$referredUsers = [];
if ($st = $conn->prepare('SELECT id, username, created_at FROM users WHERE referred_by = ? ORDER BY id DESC LIMIT 25')) {
    $st->bind_param('i', $uid);
    $st->execute();
    if ($res = $st->get_result()) {
        while ($row = $res->fetch_assoc()) { $referredUsers[] = $row; }
        $res->free();
    }
    $st->close();
}

$conn->close();
$refEnabled = get_setting('referral_enabled', '0') === '1';
?>

<?php include '../includes/header.php'; ?>
<div class="main-content fade-in">
    <div class="card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h4 mb-1"><?php echo __('Партнёрская программа'); ?></h1>
                <p class="text-muted mb-0 small"><?php echo __('Делитесь ссылкой, привлекайте друзей и получайте процент от их пополнений.'); ?></p>
            </div>
            <?php if (!$refEnabled): ?>
                <span class="badge bg-secondary"><?php echo __('Отключено администратором'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-2"><?php echo __('Ваша реферальная ссылка'); ?></h2>
                    <div class="input-group">
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($refLink); ?>" id="refLink" readonly>
                        <button class="btn btn-outline-primary" type="button" id="copyRefLink"><i class="bi bi-clipboard"></i> <?php echo __('Копировать'); ?></button>
                    </div>
                    <div class="form-text mt-2"><?php echo sprintf(__('Срок действия метки: %d дней. Базовый процент: %s%% (может быть повышен индивидуально).'), $cookieDays, number_format($defaultPercent, 2)); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-2"><?php echo __('Статистика'); ?></h2>
                    <div class="d-flex gap-4">
                        <div>
                            <div class="text-muted small"><?php echo __('Привлечено пользователей'); ?></div>
                            <div class="fs-4 fw-semibold"><?php echo (int)$totalUsers; ?></div>
                        </div>
                        <div>
                            <div class="text-muted small"><?php echo __('Заработано'); ?></div>
                            <div class="fs-4 fw-semibold"><?php echo htmlspecialchars(format_currency($totalEarnings)); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><?php echo __('Недавние начисления'); ?></h2>
                </div>
                <div class="card-body">
                    <?php if (empty($recentEvents)): ?>
                        <p class="text-muted mb-0"><?php echo __('Пока нет начислений.'); ?></p>
                    <?php else: ?>
                        <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th><?php echo __('Дата'); ?></th>
                                    <th class="text-end"><?php echo __('Сумма'); ?></th>
                                    <th class="text-end"><?php echo __('Процент'); ?></th>
                                    <th class="text-end"><?php echo __('Пользователь'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEvents as $e): ?>
                                    <tr>
                                        <td class="text-muted"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)$e['created_at']))); ?></td>
                                        <td class="text-end text-success"><?php echo htmlspecialchars(format_currency($e['delta'])); ?></td>
                                        <td class="text-end"><?php echo number_format($e['percent'], 2); ?>%</td>
                                        <td class="text-end">#<?php echo (int)$e['from_user_id']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><?php echo __('Новые рефералы'); ?></h2>
                </div>
                <div class="card-body">
                    <?php if (empty($referredUsers)): ?>
                        <p class="text-muted mb-0"><?php echo __('Пока нет рефералов.'); ?></p>
                    <?php else: ?>
                        <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th><?php echo __('ID'); ?></th>
                                    <th><?php echo __('Логин'); ?></th>
                                    <th><?php echo __('Дата'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($referredUsers as $ru): ?>
                                    <tr>
                                        <td>#<?php echo (int)$ru['id']; ?></td>
                                        <td><?php echo htmlspecialchars($ru['username']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$ru['created_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('copyRefLink');
    const input = document.getElementById('refLink');
    if (btn && input) {
        btn.addEventListener('click', async function(){
            input.select();
            input.setSelectionRange(0, 99999);
            try { await navigator.clipboard.writeText(input.value); } catch(e) {}
        });
    }
});
</script>
<?php include '../includes/footer.php'; ?>
