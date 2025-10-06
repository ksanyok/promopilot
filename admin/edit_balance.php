<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$user_id = (int)($_GET['user_id'] ?? 0);
$message = '';
$messageType = 'success';
$commentValue = '';

$user = pp_balance_user_info($user_id);
if (!$user) {
    redirect('admin/admin.php');
}

$currentBalance = (float)($user['balance'] ?? 0);
$username = (string)($user['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
        $messageType = 'danger';
    } else {
        $new_balance = (float)$_POST['balance'];
        $commentValue = trim((string)($_POST['comment'] ?? ''));
        $balanceEvent = null;
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            $conn = null;
        }
        if ($conn) {
            try {
                $conn->begin_transaction();
                $lockStmt = $conn->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
                if (!$lockStmt) {
                    throw new RuntimeException('LOCK statement failed');
                }
                $lockStmt->bind_param('i', $user_id);
                if (!$lockStmt->execute()) {
                    $lockStmt->close();
                    throw new RuntimeException('LOCK execution failed');
                }
                $lockRes = $lockStmt->get_result();
                $lockedUser = $lockRes ? $lockRes->fetch_assoc() : null;
                if ($lockRes) { $lockRes->free(); }
                $lockStmt->close();
                if (!$lockedUser) {
                    throw new RuntimeException('User not found while locking');
                }
                $oldBalance = (float)$lockedUser['balance'];
                $delta = round($new_balance - $oldBalance, 2);
                if (abs($delta) < 0.00001) {
                    $conn->rollback();
                    $message = __('Баланс не изменился.');
                    $messageType = 'info';
                } else {
                    $updStmt = $conn->prepare('UPDATE users SET balance = ? WHERE id = ?');
                    if (!$updStmt) {
                        throw new RuntimeException('UPDATE statement failed');
                    }
                    $updStmt->bind_param('di', $new_balance, $user_id);
                    if (!$updStmt->execute()) {
                        $updStmt->close();
                        throw new RuntimeException('UPDATE execution failed');
                    }
                    $updStmt->close();
                    $adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                    $adminUsername = trim((string)($_SESSION['username'] ?? ''));
                    $adminFullName = trim((string)($_SESSION['full_name'] ?? ''));
                    $balanceEvent = pp_balance_record_event($conn, [
                        'user_id' => $user_id,
                        'delta' => $delta,
                        'balance_before' => $oldBalance,
                        'balance_after' => $new_balance,
                        'source' => 'manual',
                        'admin_id' => $adminId,
                        'meta' => [
                            'comment' => $commentValue,
                            'admin_username' => $adminUsername,
                            'admin_full_name' => $adminFullName,
                        ],
                    ]);
                    $conn->commit();
                    $message = __('Изменение баланса сохранено.');
                    $messageType = 'success';
                    $currentBalance = $new_balance;
                }
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                if ($message === '') {
                    $message = __('Ошибка обновления.');
                    $messageType = 'danger';
                }
            }
            $conn->close();
        } else {
            $message = __('Ошибка обновления.');
            $messageType = 'danger';
        }
        if ($balanceEvent) {
            $notificationSent = pp_balance_send_event_notification($balanceEvent);
            if (!$notificationSent) {
                if ($messageType === 'success') {
                    $messageType = 'warning';
                }
                $message .= ' ' . __('Письмо с уведомлением не было отправлено.');
                $message .= ' ' . __('Проверьте настройки почты и журнал отправки.');
            }
        }
    }
}

$latestUser = pp_balance_user_info($user_id);
if ($latestUser) {
    $user = $latestUser;
    $currentBalance = (float)$latestUser['balance'];
    $username = (string)$latestUser['username'];
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h4><?php echo __('Изменить баланс'); ?> - <?php echo htmlspecialchars($user['username']); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label><?php echo __('Новый баланс'); ?>:</label>
                        <input type="number" step="0.01" name="balance" class="form-control" value="<?php echo htmlspecialchars(number_format($currentBalance, 2, '.', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                        <div class="form-text text-muted"><?php echo __('Текущий баланс'); ?>: <?php echo htmlspecialchars(format_currency($currentBalance), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    </div>
                    <div class="mb-3">
                        <label><?php echo __('Комментарий (необязательно)'); ?>:</label>
                        <textarea name="comment" class="form-control" rows="3" placeholder="<?php echo __('Укажите причину изменения баланса'); ?>"><?php echo htmlspecialchars($commentValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                        <div class="form-text text-muted"><?php echo __('Комментарий увидит клиент в письме и истории.'); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo __('Обновить баланс и уведомить'); ?></button>
                    <a href="<?php echo pp_url('admin/admin.php'); ?>" class="btn btn-secondary"><?php echo __('Вернуться'); ?></a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>