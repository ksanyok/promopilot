<?php
/** @var array $items */
/** @var array $crowdSelectedSet */
/** @var array $crowdStatusMeta */
/** @var array $crowdDeepStatusMeta */
/** @var callable $pp_truncate */
?>
<form method="post" id="crowdSelectionForm">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="crowd_delete_selected" value="1">
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle" id="crowdLinksTable">
            <thead class="table-light">
                <tr>
                    <th style="width:32px;">
                        <input type="checkbox" class="form-check-input" data-crowd-select="toggle"
                               aria-label="<?php echo __('Выделить всё на странице'); ?>">
                    </th>
                    <th><?php echo __('URL'); ?></th>
                    <th class="text-nowrap"><?php echo __('Статус'); ?></th>
                    <th class="text-nowrap"><?php echo __('Код / Проверка'); ?></th>
                    <th class="text-nowrap"><?php echo __('Глубокая проверка'); ?></th>
                    <th><?php echo __('Ответ / Ошибка'); ?></th>
                    <th class="text-nowrap"><?php echo __('Язык'); ?></th>
                    <th class="text-nowrap"><?php echo __('Регион'); ?></th>
                    <th><?php echo __('Домен'); ?></th>
                    <th><?php echo __('HTTP комментарий'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4"><?php echo __('Записей не найдено.'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $row): ?>
                        <?php
                            $linkId = (int)($row['id'] ?? 0);
                            $status = (string)($row['status'] ?? 'pending');
                            $meta = $crowdStatusMeta[$status] ?? null;
                            $badgeClass = $meta['class'] ?? 'badge bg-secondary';
                            $badgeLabel = $meta['label'] ?? $status;
                            $checked = isset($crowdSelectedSet[$linkId]);
                            $statusCode = (int)($row['status_code'] ?? 0);
                            $processing = !empty($row['processing_run_id']);
                            $deepStatus = (string)($row['deep_status'] ?? 'pending');
                            $deepMeta = $crowdDeepStatusMeta[$deepStatus] ?? null;
                            $deepBadgeClass = $deepMeta['class'] ?? 'badge bg-secondary';
                            $deepBadgeLabel = $deepMeta['label'] ?? $deepStatus;
                            $deepProcessing = !empty($row['deep_processing_run_id']);
                            $formRequired = (string)($row['form_required'] ?? '');
                            $deepError = (string)($row['deep_error'] ?? '');
                            $deepMessage = (string)($row['deep_message_excerpt'] ?? '');
                            $deepEvidenceUrl = (string)($row['deep_evidence_url'] ?? '');
                            $deepCheckedAt = (string)($row['deep_checked_at'] ?? '');
                            $deepCheckedDisplay = '—';
                            if ($deepCheckedAt !== '' && $deepCheckedAt !== '0000-00-00 00:00:00') {
                                $ts = strtotime($deepCheckedAt);
                                if ($ts) {
                                    $deepCheckedDisplay = date('Y-m-d H:i', $ts);
                                } else {
                                    $deepCheckedDisplay = $deepCheckedAt;
                                }
                            }
                        ?>
                        <tr data-link-id="<?php echo $linkId; ?>">
                            <td>
                                <input type="checkbox" class="form-check-input" name="crowd_selected[]" value="<?php echo $linkId; ?>" data-crowd-checkbox <?php echo $checked ? 'checked' : ''; ?> aria-label="<?php echo __('Выбрать ссылку'); ?>">
                            </td>
                            <td>
                                <?php $rowUrl = (string)($row['url'] ?? ''); $rowUrlShort = $pp_truncate($rowUrl, 30); ?>
                                <div class="fw-semibold text-break">
                                    <a href="<?php echo htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="link-primary" title="<?php echo htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($rowUrlShort, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </div>
                                <div class="small text-muted">ID <?php echo $linkId; ?></div>
                            </td>
                            <td>
                                <span class="badge <?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>" data-crowd-status-badge title="<?php echo htmlspecialchars($formRequired !== '' ? ('Textarea: есть; Обязательные: ' . $formRequired) : '—', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($processing): ?>
                                    <span class="spinner-border spinner-border-sm text-info ms-1" role="status" aria-hidden="true"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo $statusCode > 0 ? $statusCode : '—'; ?></div>
                                <div class="small text-muted"><?php echo !empty($row['last_checked_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$row['last_checked_at'])), ENT_QUOTES, 'UTF-8') : '—'; ?></div>
                            </td>
                            <td>
                                <span class="badge <?php echo htmlspecialchars($deepBadgeClass, ENT_QUOTES, 'UTF-8'); ?>" data-deep-status-badge><?php echo htmlspecialchars($deepStatus === 'pending' ? __('Не запускалась') : $deepBadgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($deepProcessing): ?>
                                    <span class="spinner-border spinner-border-sm text-danger ms-1" role="status" aria-hidden="true"></span>
                                <?php endif; ?>
                                <div class="small text-muted"><?php echo htmlspecialchars($deepCheckedDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td class="small text-break">
                                <?php if ($deepMessage !== ''): ?>
                                    <div class="text-success fw-semibold mb-1"><?php echo htmlspecialchars($deepMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($deepError !== ''): ?>
                                    <div class="text-danger"><?php echo htmlspecialchars($deepError, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($deepEvidenceUrl !== ''): ?>
                                    <a href="<?php echo htmlspecialchars($deepEvidenceUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="small text-decoration-none"><i class="bi bi-box-arrow-up-right me-1"></i><?php echo __('Ответ'); ?></a>
                                <?php endif; ?>
                                <?php if ($deepMessage === '' && $deepError === '' && $deepEvidenceUrl === ''): ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-uppercase"><?php echo $row['language'] ? htmlspecialchars((string)$row['language'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td class="text-uppercase"><?php echo $row['region'] ? htmlspecialchars((string)$row['region'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td>
                                <?php $dom = (string)($row['domain'] ?? ''); $domShort = $pp_truncate($dom, 20); echo htmlspecialchars($domShort, ENT_QUOTES, 'UTF-8'); if ($dom !== '' && $domShort !== $dom) { echo ' <span class="text-muted" title="' . htmlspecialchars($dom, ENT_QUOTES, 'UTF-8') . '">…</span>'; } ?>
                            </td>
                            <td class="text-break small">&nbsp;<?php echo $row['error'] ? htmlspecialchars((string)$row['error'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
        <button type="submit" class="btn btn-outline-danger btn-sm" id="crowdDeleteSelected" <?php echo empty($items) ? 'disabled' : ''; ?>>
            <i class="bi bi-trash me-1"></i><?php echo __('Удалить выбранные'); ?>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-crowd-select="all"><?php echo __('Выбрать всё'); ?></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-crowd-select="none"><?php echo __('Снять выделение'); ?></button>
        <div class="small text-muted ms-auto" id="crowdSelectionCounter"><?php echo __('Выбрано ссылок'); ?>: <span data-crowd-selected-count>0</span></div>
    </div>
</form>
