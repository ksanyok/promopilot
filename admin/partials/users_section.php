<?php
// Users section partial
?>
<div id="users-section">
<h3><?php echo __('Пользователи'); ?></h3>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead>
        <tr>
            <th class="text-nowrap">ID</th>
            <th><?php echo __('Пользователь'); ?></th>
            <th class="d-none d-md-table-cell text-center"><?php echo __('Проекты'); ?></th>
            <th class="d-none d-sm-table-cell"><?php echo __('Роль'); ?></th>
            <th class="d-none d-lg-table-cell"><?php echo __('Баланс'); ?></th>
            <th class="text-nowrap"><?php echo __('Дата регистрации'); ?></th>
            <th class="text-end"><?php echo __('Действия'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($user = $users->fetch_assoc()): ?>
            <tr>
                <td class="text-muted">#<?php echo (int)$user['id']; ?></td>
                <td>
                    <div class="fw-semibold"><?php echo htmlspecialchars($user['username']); ?></div>
                    <?php if (!empty($user['email'])): ?>
                        <div class="text-muted small"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($user['email']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="d-none d-md-table-cell text-center">
                    <span class="badge bg-secondary"><?php echo (int)$user['projects_count']; ?></span>
                </td>
                <td class="d-none d-sm-table-cell text-muted"><?php echo htmlspecialchars($user['role']); ?></td>
                <td class="d-none d-lg-table-cell"><?php echo htmlspecialchars(format_currency($user['balance'])); ?></td>
                <td class="text-muted">
                    <?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$user['created_at']))); ?>
                </td>
                <td class="text-end">
                    <?php $t = action_token('login_as', (string)$user['id']); ?>
                    <a href="admin_login_as.php?user_id=<?php echo (int)$user['id']; ?>&t=<?php echo urlencode($t); ?>" class="btn btn-warning btn-sm"><?php echo __('Войти как'); ?></a>
                    <a href="edit_balance.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-info btn-sm"><?php echo __('Изменить баланс'); ?></a>
                </td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>
