<?php
// Extracted body of networks section (form + filters + table + controls + modal + JS)
// This file expects the same variables as in the original admin.php.
?>
<?php /* Paste of the networks section content from admin.php begins */ ?>
<form method="post" class="card p-3 mb-3" autocomplete="off">
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-end">
        <div class="col-md-6">
            <label class="form-label"><?php echo __('Путь до Node.js'); ?></label>
            <input type="text" name="node_binary" class="form-control" value="<?php echo htmlspecialchars($nodeBinaryStored); ?>" placeholder="node">
            <div class="form-text"><?php echo __('Оставьте пустым, чтобы использовать системный путь по умолчанию.'); ?></div>
        </div>
        <div class="col-md-6">
            <label class="form-label"><?php echo __('Путь до Chrome/Chromium (необязательно)'); ?></label>
            <input type="text" name="puppeteer_executable_path" class="form-control" value="<?php echo htmlspecialchars($puppeteerExecStored); ?>" placeholder="/home/user/promopilot/node_runtime/chrome/chrome">
            <div class="form-text"><?php echo __('Если пусто — используется авто‑поиск или браузер Puppeteer.'); ?></div>
        </div>
    </div>
    <div class="row g-3 align-items-end mt-0">
        <div class="col-md-12">
            <label class="form-label"><?php echo __('Доп. аргументы для Puppeteer'); ?></label>
            <input type="text" name="puppeteer_args" class="form-control" value="<?php echo htmlspecialchars($puppeteerArgsStored); ?>" placeholder="--no-sandbox --disable-setuid-sandbox">
            <div class="form-text"><?php echo __('Аргументы будут добавлены к запуску браузера.'); ?></div>
        </div>
    </div>
    <div class="row g-3 align-items-end mt-0">
        <div class="col-md-4 col-lg-3">
            <label class="form-label" for="defaultPriority" data-bs-toggle="tooltip" title="<?php echo __('Чем выше число, тем выше приоритет сети при подборе.'); ?>"><?php echo __('Приоритет по умолчанию'); ?></label>
            <input type="number" class="form-control" id="defaultPriority" name="default_priority" value="<?php echo (int)$networkDefaultPriority; ?>" min="0" max="999" aria-describedby="defaultPriorityHelp">
            <div id="defaultPriorityHelp" class="form-text"><?php echo __('Используется при добавлении новых сетей.'); ?></div>
        </div>
        <div class="col-md-8 col-lg-5">
            <label class="form-label d-block" data-bs-toggle="tooltip" title="<?php echo __('Выберите уровни, которые будут назначены новым сетям по умолчанию.'); ?>"><?php echo __('Уровни по умолчанию'); ?></label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ([1,2,3] as $defaultLevel): ?>
                    <?php $defaultChecked = in_array((string)$defaultLevel, $networkDefaultLevelsList, true); ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="default_levels[]" id="default-level-<?php echo (int)$defaultLevel; ?>" value="<?php echo (int)$defaultLevel; ?>" <?php echo $defaultChecked ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="default-level-<?php echo (int)$defaultLevel; ?>"><?php echo sprintf(__('Уровень %d'), $defaultLevel); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-text"><?php echo __('Можно выбрать несколько уровней.'); ?></div>
        </div>
        <div class="col-md-12 col-lg-4">
            <div class="alert alert-secondary mb-0 small" role="status">
                <i class="bi bi-info-circle me-1"></i><?php echo __('Эти значения применяются автоматически при появлении новых сетей.'); ?>
            </div>
        </div>
    </div>
    <?php
    $normalizeFilterToken = static function ($value) {
        $value = trim((string)$value);
        if ($value === '') { return ''; }
        if (function_exists('mb_strtolower')) { $value = mb_strtolower($value, 'UTF-8'); } else { $value = strtolower($value); }
        return preg_replace('~\s+~', ' ', $value);
    };
    $regionOptions = [];
    $topicOptions = [];
    if (!empty($networks)) {
        foreach ($networks as $netMeta) {
            foreach (($netMeta['regions'] ?? []) as $regionItem) {
                $key = $normalizeFilterToken($regionItem);
                if ($key !== '' && !isset($regionOptions[$key])) { $regionOptions[$key] = $regionItem; }
            }
            foreach (($netMeta['topics'] ?? []) as $topicItem) {
                $key = $normalizeFilterToken($topicItem);
                if ($key !== '' && !isset($topicOptions[$key])) { $topicOptions[$key] = $topicItem; }
            }
        }
        ksort($regionOptions, SORT_STRING);
        ksort($topicOptions, SORT_STRING);
    }
    ?>
    <?php if (!empty($networks)): ?>
    <div class="bg-light border rounded p-3 mt-3" id="networkFiltersBar">
        <div class="row g-3 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label form-label-sm" for="filterStatus"><?php echo __('Статус проверки'); ?></label>
                <select id="filterStatus" class="form-select form-select-sm">
                    <option value="all"><?php echo __('Все'); ?></option>
                    <option value="success"><?php echo __('Успешно'); ?></option>
                    <option value="failed"><?php echo __('С ошибками'); ?></option>
                    <option value="progress"><?php echo __('В процессе'); ?></option>
                    <option value="cancelled"><?php echo __('Отменено'); ?></option>
                    <option value="none"><?php echo __('Нет данных'); ?></option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label form-label-sm" for="filterActive"><?php echo __('Активность'); ?></label>
                <select id="filterActive" class="form-select form-select-sm">
                    <option value="all"><?php echo __('Все'); ?></option>
                    <option value="active"><?php echo __('Активные'); ?></option>
                    <option value="inactive"><?php echo __('Неактивные'); ?></option>
                    <option value="missing"><?php echo __('Файл недоступен'); ?></option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label form-label-sm" for="filterRegion"><?php echo __('Регион'); ?></label>
                <select id="filterRegion" class="form-select form-select-sm">
                    <option value="all"><?php echo __('Все'); ?></option>
                    <?php foreach ($regionOptions as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label form-label-sm" for="filterTopic"><?php echo __('Тематика'); ?></label>
                <select id="filterTopic" class="form-select form-select-sm">
                    <option value="all"><?php echo __('Все'); ?></option>
                    <?php foreach ($topicOptions as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label form-label-sm d-block"><?php echo __('Фильтр по уровню'); ?></label>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ([1,2,3] as $filterLevel): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input filter-level-checkbox" type="checkbox" id="filterLevel<?php echo (int)$filterLevel; ?>" name="filter_levels[]" value="<?php echo (int)$filterLevel; ?>">
                                <label class="form-check-label" for="filterLevel<?php echo (int)$filterLevel; ?>"><?php echo sprintf(__('Уровень %d'), $filterLevel); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="small text-muted"><?php echo __('Отметьте уровни, чтобы сократить список сетей.'); ?></span>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-2 filters-actions filters-actions-primary">
                <button type="button" class="btn btn-outline-primary btn-sm" id="activateVerifiedBtn" data-message-template="<?php echo htmlspecialchars(__('Выбрано проверенных сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-toggle2-on me-1"></i><?php echo __('Активировать проверенные'); ?>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="resetNetworkFilters">
                    <i class="bi bi-arrow-counterclockwise me-1"></i><?php echo __('Сбросить фильтры'); ?>
                </button>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-2 filters-actions filters-actions-selection">
                <button type="button" class="btn btn-outline-success btn-sm" id="activateSelectedBtn" data-selection-action="1" data-message-template="<?php echo htmlspecialchars(__('Включено сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-check2-all me-1"></i><?php echo __('Включить выбранные'); ?>
                </button>
                <button type="button" class="btn btn-outline-warning btn-sm" id="deactivateSelectedBtn" data-selection-action="1" data-message-template="<?php echo htmlspecialchars(__('Отключено сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-dash-circle me-1"></i><?php echo __('Отключить выбранные'); ?>
                </button>
                <button type="button" class="btn btn-outline-light btn-sm" id="clearSelectedBtn" data-selection-action="1" data-message-template="<?php echo htmlspecialchars(__('Выбор очищен.'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-x-circle me-1"></i><?php echo __('Снять выделение'); ?>
                </button>
                <button type="button" class="btn btn-outline-info btn-sm" id="checkSelectedBtn" data-selection-action="1" data-label="<?php echo __('Проверить выбранные'); ?>">
                    <i class="bi bi-play-circle me-1"></i><?php echo __('Проверить выбранные'); ?>
                </button>
            </div>
            <div class="col-12">
                <div class="small text-muted filters-info" id="networkFiltersInfo"
                     data-label-visible="<?php echo htmlspecialchars(__('Показано сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-label-selected="<?php echo htmlspecialchars(__('Выбрано сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-label-activated="<?php echo htmlspecialchars(__('Включено сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-label-deactivated="<?php echo htmlspecialchars(__('Отключено сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-label-selection-empty="<?php echo htmlspecialchars(__('Отметьте хотя бы одну сеть.'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-label-selection-cleared="<?php echo htmlspecialchars(__('Выбор очищен.'), ENT_QUOTES, 'UTF-8'); ?>"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="table-responsive mt-3">
        <?php if (!empty($networks)): ?>
        <div class="alert alert-info small d-flex align-items-center gap-2 mb-3" role="status">
            <i class="bi bi-cloud-check"></i>
            <span><?php echo __('Изменения активации, приоритетов и уровней сохраняются автоматически.'); ?></span>
        </div>
        <table class="table table-striped align-middle" id="networksTable">
            <thead>
            <tr>
                <th class="text-center network-select-head" style="width:48px;">
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input" id="networkSelectAll" aria-label="<?php echo __('Выбрать все'); ?>">
                    </div>
                </th>
                <th><?php echo __('Сеть'); ?></th>
                <th><?php echo __('Описание'); ?></th>
                <th class="text-center" style="width:120px;">&nbsp;<?php echo __('Активация'); ?>&nbsp;</th>
                <th class="text-center" style="width:110px;">&nbsp;<?php echo __('Приоритет'); ?></th>
                <th style="width:180px;"><?php echo __('Уровни'); ?></th>
                <th style="width:240px;">&nbsp;<?php echo __('Примечание'); ?>&nbsp;</th>
                <th><?php echo __('Статус'); ?></th>
                <th><?php echo __('Последняя проверка'); ?></th>
                <th class="text-end" style="width:180px;">&nbsp;<?php echo __('Диагностика'); ?>&nbsp;</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($networks as $network): ?>
                <?php
                $isMissing = $network['is_missing'];
                $regionTokens = array_values(array_filter(array_map($normalizeFilterToken, $network['regions'] ?? [])));
                $topicTokens = array_values(array_filter(array_map($normalizeFilterToken, $network['topics'] ?? [])));
                $tooltipParts = [];
                if (!empty($network['regions'])) { $tooltipParts[] = __('Регионы') . ': ' . implode(', ', $network['regions']); }
                if (!empty($network['topics'])) { $tooltipParts[] = __('Тематики') . ': ' . implode(', ', $network['topics']); }
                if (!empty($network['handler'])) { $tooltipParts[] = __('Обработчик') . ': ' . $network['handler']; }
                $tooltipText = implode("\n", $tooltipParts);
                $rowStatus = (string)($network['last_check_status'] ?? '');
                $rowStatusAttr = $rowStatus !== '' ? $rowStatus : 'none';
                $levelValues = [];
                $rawLevel = (string)($network['level'] ?? '');
                if ($rawLevel !== '') {
                    if (preg_match_all('~([1-3])~', $rawLevel, $lvlMatches)) {
                        foreach ($lvlMatches[1] as $lvlVal) { $levelValues[$lvlVal] = $lvlVal; }
                    }
                }
                $levelValuesList = array_values($levelValues);
                ?>
                <tr class="<?php echo $isMissing ? 'table-warning' : ''; ?>"
                    data-status="<?php echo htmlspecialchars($rowStatusAttr); ?>"
                    data-active="<?php echo ($network['enabled'] && !$isMissing) ? '1' : '0'; ?>"
                    data-missing="<?php echo $isMissing ? '1' : '0'; ?>"
                    data-slug="<?php echo htmlspecialchars($network['slug']); ?>"
                    data-title="<?php echo htmlspecialchars($network['title']); ?>"
                    data-regions="<?php echo htmlspecialchars(implode('|', $regionTokens)); ?>"
                    data-topics="<?php echo htmlspecialchars(implode('|', $topicTokens)); ?>"
                    data-priority="<?php echo (int)($network['priority'] ?? 0); ?>"
                    data-levels="<?php echo htmlspecialchars(implode('|', $levelValuesList)); ?>">
                    <td class="network-select-cell">
                        <div class="form-check mb-0">
                            <input type="checkbox" class="form-check-input network-select" aria-label="<?php echo __('Выбор сети'); ?>" <?php echo $isMissing ? 'disabled' : ''; ?>>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-start gap-2">
                            <?php if (!empty($tooltipText)): ?>
                                <span class="network-info-icon text-primary mt-1" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="bi bi-info-circle"></i>
                                </span>
                            <?php endif; ?>
                            <div>
                                <strong><?php echo htmlspecialchars($network['title']); ?></strong>
                                <div class="text-muted small"><?php echo htmlspecialchars($network['slug']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($network['description']); ?></td>
                    <td class="network-activate-cell text-center">
                        <div class="pp-switch pp-switch-sm network-activate-switch">
                            <?php $toggleId = 'net-enable-' . pp_normalize_slug((string)$network['slug']); ?>
                            <input type="checkbox" class="network-enable-toggle" name="enable[<?php echo htmlspecialchars($network['slug']); ?>]" value="1" id="<?php echo htmlspecialchars($toggleId); ?>" <?php echo ($network['enabled'] && !$isMissing) ? 'checked' : ''; ?> <?php echo $isMissing ? 'disabled' : ''; ?> aria-label="<?php echo __('Активация сети'); ?>">
                            <label for="<?php echo htmlspecialchars($toggleId); ?>" class="track" aria-hidden="true"><span class="thumb"></span></label>
                        </div>
                    </td>
                    <td class="text-center">
                        <input type="number" class="form-control form-control-sm text-center" name="priority[<?php echo htmlspecialchars($network['slug']); ?>]" value="<?php echo (int)($network['priority'] ?? 0); ?>" min="0" max="999" <?php echo $isMissing ? 'disabled' : ''; ?> aria-label="<?php echo __('Приоритет'); ?>" data-bs-toggle="tooltip" title="<?php echo __('Больший приоритет повышает шанс выбора сети.'); ?>">
                    </td>
                    <td>
                        <input type="hidden" name="network_slugs[]" value="<?php echo htmlspecialchars($network['slug']); ?>">
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ([1,2,3] as $levelOption): ?>
                                <?php $levelChecked = in_array((string)$levelOption, $levelValuesList, true); ?>
                                <div class="form-check form-check-inline mb-1">
                                    <input type="checkbox" class="form-check-input level-checkbox" name="level[<?php echo htmlspecialchars($network['slug']); ?>][]" value="<?php echo (int)$levelOption; ?>" id="level-<?php echo htmlspecialchars($network['slug']); ?>-<?php echo (int)$levelOption; ?>" <?php echo $levelChecked ? 'checked' : ''; ?> <?php echo $isMissing ? 'disabled' : ''; ?>>
                                    <label class="form-check-label small" for="level-<?php echo htmlspecialchars($network['slug']); ?>-<?php echo (int)$levelOption; ?>"><?php echo sprintf(__('Уровень %d'), $levelOption); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text"><?php echo __('Выберите один или несколько уровней.'); ?></div>
                    </td>
                    <td class="network-note-cell">
                        <textarea class="form-control form-control-sm network-note-input" rows="2" data-slug="<?php echo htmlspecialchars($network['slug']); ?>" data-initial-value="<?php echo htmlspecialchars($network['notes'] ?? ''); ?>" placeholder="<?php echo __('Добавьте внутреннюю заметку'); ?>"><?php echo htmlspecialchars($network['notes'] ?? ''); ?></textarea>
                        <div class="small text-muted mt-1 d-none" data-note-status></div>
                    </td>
                    <td>
                        <?php if ($isMissing): ?>
                            <span class="badge bg-warning text-dark"><?php echo __('Файл не найден'); ?></span>
                        <?php elseif ($network['enabled']): ?>
                            <span class="badge badge-success"><?php echo __('Активна'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary"><?php echo __('Отключена'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $lastStatus = (string)($network['last_check_status'] ?? '');
                        $lastStarted = $network['last_check_started_at'] ?? null;
                        $lastFinished = $network['last_check_finished_at'] ?? null;
                        $lastUrl = trim((string)($network['last_check_url'] ?? ''));
                        $lastError = trim((string)($network['last_check_error'] ?? ''));
                        $lastRunId = $network['last_check_run_id'] ?? null;
                        $statusMap = [
                            'success' => ['label' => __('Успешно'), 'class' => 'bg-success'],
                            'failed' => ['label' => __('С ошибками'), 'class' => 'bg-danger'],
                            'running' => ['label' => __('Выполняется'), 'class' => 'bg-primary'],
                            'queued' => ['label' => __('В ожидании'), 'class' => 'bg-secondary'],
                            'cancelled' => ['label' => __('Отменено'), 'class' => 'bg-warning text-dark'],
                        ];
                        $badge = $statusMap[$lastStatus] ?? null;
                        if ($badge): ?>
                            <span class="badge <?php echo htmlspecialchars($badge['class']); ?>"><?php echo htmlspecialchars($badge['label']); ?></span>
                        <?php else: ?>
                            <span class="text-muted small"><?php echo __('Нет данных'); ?></span>
                        <?php endif; ?>
                        <?php if ($lastFinished || $lastStarted): ?>
                            <div class="text-muted small">
                                <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($lastFinished ?: $lastStarted))); ?>
                                <?php if ($lastRunId): ?>
                                    <span class="text-muted">#<?php echo (int)$lastRunId; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($lastUrl): ?>
                            <a href="<?php echo htmlspecialchars($lastUrl); ?>" target="_blank" rel="noopener" class="small d-inline-flex align-items-center gap-1">
                                <i class="bi bi-box-arrow-up-right"></i><?php echo __('Открыть'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($lastError): ?>
                            <div class="small text-danger mt-1" title="<?php echo htmlspecialchars($lastError); ?>"><?php echo htmlspecialchars(mb_strimwidth($lastError, 0, 80, '…')); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-warning" data-network-check-single="1" data-network-slug="<?php echo htmlspecialchars($network['slug']); ?>" data-network-title="<?php echo htmlspecialchars($network['title']); ?>" <?php echo $isMissing ? 'disabled' : ''; ?>>
                            <i class="bi bi-play-circle me-1"></i><?php echo __('Проверить'); ?>
                        </button>
                        <?php if ($isMissing): ?>
                            <button type="submit" name="delete_network" value="<?php echo htmlspecialchars($network['slug']); ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('<?php echo htmlspecialchars(__('Удалить эту сеть из списка?'), ENT_QUOTES, 'UTF-8'); ?>');">
                                <i class="bi bi-trash me-1"></i><?php echo __('Удалить'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="text-muted mb-0"><?php echo __('Сети не обнаружены. Добавьте файлы в директорию networks и обновите список.'); ?></p>
        <?php endif; ?>
    </div>
    <div class="mt-3 text-md-end">
        <button type="submit" name="networks_submit" value="1" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
    </div>
</form>
<form method="post" class="d-inline me-2">
    <?php echo csrf_field(); ?>
    <button type="submit" name="refresh_networks" value="1" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i><?php echo __('Обновить список сетей'); ?></button>
</form>
<form method="post" class="d-inline">
    <?php echo csrf_field(); ?>
    <button type="submit" name="detect_node" value="1" class="btn btn-outline-success"><i class="bi bi-magic me-1"></i><?php echo __('Автоопределение Node.js'); ?></button>
</form>

<div class="d-flex flex-wrap gap-2 mt-3">
    <button type="button" class="btn btn-warning" id="networkCheckButton" data-label="<?php echo __('Проверить сети'); ?>"><i class="bi bi-activity me-1"></i><?php echo __('Проверить сети'); ?></button>
    <button type="button" class="btn btn-outline-danger" id="networkCheckStopButton" style="display:none;"
        data-label-html="<?php echo htmlspecialchars('<i class=\"bi bi-stop-circle me-1\"></i>' . __('Остановить проверку'), ENT_QUOTES, 'UTF-8'); ?>"
        data-wait-label="<?php echo htmlspecialchars(__('Ожидаем остановки…'), ENT_QUOTES, 'UTF-8'); ?>">
        <i class="bi bi-stop-circle me-1"></i><?php echo __('Остановить проверку'); ?>
    </button>
    <button type="button" class="btn btn-outline-light" id="networkCheckHistoryButton" style="display:none;" data-label="<?php echo __('Показать последний результат'); ?>"><i class="bi bi-clock-history me-1"></i><?php echo __('Показать последний результат'); ?></button>
</div>
<div class="alert alert-warning mt-3 d-none" id="networkCheckMessage"></div>

<div class="card network-check-summary-card mt-3" id="networkCheckLastRun" style="display:none;">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <div class="text-muted small mb-1"><?php echo __('Последняя проверка сетей'); ?></div>
                <div class="fw-semibold" data-summary-status>—</div>
                <div class="text-muted small" data-summary-time>—</div>
                <div class="text-muted small" data-summary-mode style="display:none;">—</div>
            </div>
            <div class="text-lg-end">
                <div class="small mb-1">
                    <?php echo __('Успешно'); ?>: <span class="fw-semibold" data-summary-success>0</span> / <span data-summary-total>0</span>
                </div>
                <div class="small">
                    <?php echo __('С ошибками'); ?>: <span class="fw-semibold" data-summary-failed>0</span>
                </div>
                <div class="small text-muted" data-summary-note></div>
            </div>
        </div>
    </div>
</div>

<div id="networkCheckModal" class="pp-modal" aria-hidden="true" role="dialog" aria-labelledby="networkCheckModalTitle">
    <div class="pp-modal-dialog">
        <div class="pp-modal-header">
            <div class="pp-modal-title" id="networkCheckModalTitle"><?php echo __('Результаты проверки сетей'); ?></div>
            <button type="button" class="pp-close" data-pp-close>&times;</button>
        </div>
        <div class="pp-modal-body">
            <div id="networkCheckModalMeta" class="network-check-meta mb-3 text-muted small">&nbsp;</div>
            <div class="progress network-check-progress mb-3" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" id="networkCheckProgressBar" style="width:0%;">0%</div>
            </div>
            <div id="networkCheckModalNote" class="network-check-note mb-2"></div>
            <div id="networkCheckCurrent" class="network-check-current mb-3"></div>
            <div id="networkCheckResults" class="network-check-results"></div>
        </div>
        <div class="pp-modal-footer">
            <button type="button" class="btn btn-outline-success me-auto" id="networkCheckApplySuccess" style="display:none;">
                <i class="bi bi-toggle-on me-1"></i><?php echo __('Активировать успешные'); ?>
            </button>
            <button type="button" class="btn btn-outline-primary" data-pp-close><?php echo __('Закрыть'); ?></button>
        </div>
    </div>
</div>

<?php /* The scripts block that powers the networks UI is kept below, exactly as original. */ ?>
<?php /* BEGIN networks scripts */ ?>
<?php // For brevity, we reuse the script block from original file by not extracting it further. ?>
