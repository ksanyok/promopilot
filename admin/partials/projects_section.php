<?php
// Projects section partial
?>
<div id="projects-section" style="display:none;">
<h3><?php echo __('Проекты'); ?></h3>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead>
        <tr>
            <th class="text-nowrap">ID</th>
            <th><?php echo __('Название'); ?></th>
            <th class="d-none d-sm-table-cell"><?php echo __('Владелец'); ?></th>
            <th class="text-center"><?php echo __('Ссылки'); ?></th>
            <th style="min-width:220px;">&percnt; <?php echo __('публикаций'); ?></th>
            <th class="text-nowrap d-none d-md-table-cell"><?php echo __('Дата создания'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($project = $projects->fetch_assoc()): ?>
            <?php $linksArr = json_decode($project['links'] ?? '[]', true) ?: []; $total = count($linksArr); $pub = (int)$project['published_count']; $pub = max(0, min($pub, $total)); $pct = $total > 0 ? (int)round(100 * $pub / $total) : 0; ?>
            <tr>
                <td class="text-muted">#<?php echo (int)$project['id']; ?></td>
                <td>
                    <div class="fw-semibold"><?php echo htmlspecialchars($project['name']); ?></div>
                    <?php if (!empty($project['description'])): ?>
                        <div class="text-muted small d-none d-lg-block"><?php echo htmlspecialchars($project['description']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="d-none d-sm-table-cell">
                    <span class="badge bg-dark-subtle text-dark"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($project['username']); ?></span>
                </td>
                <td class="text-center">
                    <span class="badge bg-secondary"><?php echo (int)$total; ?></span>
                </td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo $pct; ?>%;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="text-nowrap small text-muted"><?php echo $pub; ?>/<?php echo $total; ?> (<?php echo $pct; ?>%)</div>
                    </div>
                </td>
                <td class="text-muted d-none d-md-table-cell"><?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$project['created_at']))); ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>
