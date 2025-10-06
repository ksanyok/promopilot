<?php
/** @var array $items */
?>
<div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th><?php echo __('Домен'); ?></th>
                <th class="text-center"><?php echo __('Всего'); ?></th>
                <th class="text-center text-success"><?php echo __('OK'); ?></th>
                <th class="text-center text-warning"><?php echo __('В ожидании'); ?></th>
                <th class="text-center text-info"><?php echo __('В процессе'); ?></th>
                <th class="text-center text-danger"><?php echo __('Ошибки'); ?></th>
                <th><?php echo __('Последняя проверка'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4"><?php echo __('Записей не найдено.'); ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)($row['domain'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td class="text-center"><span class="badge bg-secondary-subtle text-dark"><?php echo (int)($row['total_links'] ?? 0); ?></span></td>
                        <td class="text-center text-success"><?php echo (int)($row['ok_links'] ?? 0); ?></td>
                        <td class="text-center text-warning"><?php echo (int)($row['pending_links'] ?? 0); ?></td>
                        <td class="text-center text-info"><?php echo (int)($row['checking_links'] ?? 0); ?></td>
                        <td class="text-center text-danger"><?php echo (int)($row['error_links'] ?? 0); ?></td>
                        <td><?php echo !empty($row['last_checked_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$row['last_checked_at'])), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
