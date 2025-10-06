<?php
/** @var bool $hasRun */
/** @var array $crowdCurrentRun */
/** @var bool $runInProgress */
/** @var array $crowdScopeOptions */
/** @var string $runStatusLabel */
/** @var string $runScopeLabel */
/** @var int $runProgress */
/** @var int $runProcessed */
/** @var int $runTotal */
/** @var int $runOk */
/** @var int $runErrors */
/** @var string $runStartedAt */
/** @var string $runFinishedAt */
/** @var string $runNotes */
/** @var array $crowdDeepScopeOptions */
/** @var bool $deepRunInProgress */
/** @var int $deepRunId */
/** @var string $deepRunStatusLabel */
/** @var string $deepRunScopeLabel */
/** @var int $deepRunProgress */
/** @var int $deepRunProcessed */
/** @var int $deepRunTotal */
/** @var int $deepRunSuccess */
/** @var int $deepRunPartial */
/** @var int $deepRunFailed */
/** @var int $deepRunSkipped */
/** @var string $deepRunStartedAt */
/** @var string $deepRunFinishedAt */
/** @var string $deepRunNotes */
/** @var array $crowdDeepStatusMeta */
/** @var array $deepRecentItems */
/** @var string $deepFormTokenPrefix */
/** @var string $deepFormLink */
/** @var string $deepFormName */
/** @var string $deepFormCompany */
/** @var string $deepFormEmailUser */
/** @var string $deepFormEmailDomain */
/** @var string $deepFormPhone */
/** @var string $deepFormTemplate */
/** @var string|null $crowdDeepStatusError */
/** @var callable $pp_truncate */
/** @var array $crowdDeepStatusClasses */
/** @var array $crowdDeepStatusLabels */
/** @var array $crowdDeepScopeLabels */
/** @var array $crowdDeepStatusMeta */
/** @var int $deepRunTotal */
?>
<div class="mb-4">
    <ul class="nav nav-tabs" id="crowdTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-crowd-tab="simple" type="button" role="tab"><?php echo __('Простая проверка'); ?></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-crowd-tab="deep" type="button" role="tab"><?php echo __('Глубокая проверка'); ?></button>
        </li>
    </ul>
    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" data-crowd-tab-panel="simple" role="tabpanel">
            <div class="card crowd-panel crowd-panel--status h-100" id="crowdCheckCard" data-run-id="<?php echo $hasRun ? (int)$crowdCurrentRun['id'] : ''; ?>" data-run-active="<?php echo $runInProgress ? '1' : '0'; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="card-title mb-0"><?php echo __('Проверка ссылок'); ?></h5>
                            <p class="text-muted small mb-0"><?php echo __('Фоновая проверка HTTP статусов и hreflang.'); ?></p>
                        </div>
                        <i class="bi bi-robot text-success fs-4"></i>
                    </div>
                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-md-6">
                            <label for="crowdCheckScope" class="form-label"><?php echo __('Диапазон проверки'); ?></label>
                            <select class="form-select" id="crowdCheckScope">
                                <?php foreach ($crowdScopeOptions as $scopeKey => $scopeLabel): ?>
                                    <option value="<?php echo htmlspecialchars($scopeKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-success flex-grow-1" id="crowdCheckStart">
                                    <span class="label-text"><i class="bi bi-play-circle me-1"></i><?php echo __('Запустить проверку'); ?></span>
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="crowdCheckCancel" <?php echo $runInProgress ? '' : 'disabled'; ?>>
                                    <span class="label-text"><i class="bi bi-stop-circle me-1"></i><?php echo __('Остановить'); ?></span>
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="crowdCheckMessage" class="small text-muted crowd-check-message mb-3" role="status"></div>
                    <div class="crowd-status-card rounded-3 p-3" id="crowdCheckStatusCard">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <div class="text-muted small"><?php echo __('Статус'); ?></div>
                                <div class="fw-semibold" data-crowd-status><?php echo htmlspecialchars($runStatusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="text-end">
                                <div class="text-muted small"><?php echo __('Диапазон'); ?></div>
                                <div class="fw-semibold" data-crowd-scope><?php echo htmlspecialchars($runScopeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                        <div class="progress mb-2<?php echo $runInProgress ? '' : ' d-none'; ?>" id="crowdCheckProgressContainer" style="height: 10px;">
                            <div class="progress-bar bg-success" id="crowdCheckProgressBar" role="progressbar" style="width: <?php echo $runProgress; ?>%;" aria-valuenow="<?php echo $runProgress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="d-flex justify-content-between small<?php echo $runInProgress ? '' : ' d-none'; ?>" id="crowdCheckCountsRow">
                            <div><span data-crowd-processed><?php echo $runProcessed; ?></span>/<span data-crowd-total><?php echo $runTotal; ?></span></div>
                            <div class="text-success"><i class="bi bi-check-circle me-1"></i><span data-crowd-ok><?php echo $runOk; ?></span></div>
                            <div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><span data-crowd-errors><?php echo $runErrors; ?></span></div>
                        </div>
                        <hr>
                        <div class="row small g-2">
                            <div class="col-sm-6">
                                <div class="text-muted"><?php echo __('Запущено'); ?>:</div>
                                <div data-crowd-started><?php echo htmlspecialchars($runStartedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="col-sm-6">
                                <div class="text-muted"><?php echo __('Завершено'); ?>:</div>
                                <div data-crowd-finished><?php echo htmlspecialchars($runFinishedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="col-sm-12">
                                <div class="text-muted"><?php echo __('Комментарий'); ?>:</div>
                                <div data-crowd-notes><?php echo htmlspecialchars($runNotes, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" data-crowd-tab-panel="deep" role="tabpanel">
            <div class="card crowd-panel crowd-panel--deep shadow-sm border-0 mb-4" id="crowdDeepCard"
                 data-run-id="<?php echo $deepRunId ?: ''; ?>"
                 data-run-active="<?php echo $deepRunInProgress ? '1' : '0'; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-search-heart text-danger"></i>
                                <span><?php echo __('Глубокая проверка публикаций'); ?></span>
                            </h5>
                            <p class="text-muted small mb-0"><?php echo __('Автоматически отправляет тестовое сообщение, ищет опубликованный текст и фиксирует результат.'); ?></p>
                        </div>
                        <i class="bi bi-bullseye text-danger fs-4"></i>
                    </div>

                    <?php if (!empty($crowdDeepStatusError)): ?>
                        <div class="alert alert-warning mb-3"><?php echo __('Не удалось загрузить статус глубокой проверки.'); ?> (<?php echo htmlspecialchars((string)$crowdDeepStatusError, ENT_QUOTES, 'UTF-8'); ?>)</div>
                    <?php endif; ?>

                    <div id="crowdDeepMessage" class="small text-muted mb-3" role="status"></div>

                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="crowdDeepScope" class="form-label"><?php echo __('Диапазон'); ?></label>
                                    <select class="form-select" id="crowdDeepScope">
                                        <?php foreach ($crowdDeepScopeOptions as $scopeKey => $scopeLabel): ?>
                                            <option value="<?php echo htmlspecialchars($scopeKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="crowdDeepTokenPrefix" class="form-label"><?php echo __('Префикс токена'); ?></label>
                                    <input type="text" class="form-control" id="crowdDeepTokenPrefix" value="<?php echo htmlspecialchars($deepFormTokenPrefix, ENT_QUOTES, 'UTF-8'); ?>" maxlength="12" autocomplete="off">
                                    <div class="form-text"><?php echo __('Используется для идентификации отправленного сообщения.'); ?></div>
                                </div>
                                <div class="col-md-12">
                                    <label for="crowdDeepMessageLink" class="form-label"><?php echo __('Ссылка в сообщении'); ?></label>
                                    <input type="url" class="form-control" id="crowdDeepMessageLink" value="<?php echo htmlspecialchars($deepFormLink, ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/">
                                </div>
                                <div class="col-md-6">
                                    <label for="crowdDeepName" class="form-label"><?php echo __('Имя отправителя'); ?></label>
                                    <input type="text" class="form-control" id="crowdDeepName" value="<?php echo htmlspecialchars($deepFormName, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="crowdDeepCompany" class="form-label"><?php echo __('Компания'); ?></label>
                                    <input type="text" class="form-control" id="crowdDeepCompany" value="<?php echo htmlspecialchars($deepFormCompany, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="crowdDeepEmailUser" class="form-label"><?php echo __('Email (логин)'); ?></label>
                                    <input type="text" class="form-control" id="crowdDeepEmailUser" value="<?php echo htmlspecialchars($deepFormEmailUser, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label for="crowdDeepEmailDomain" class="form-label"><?php echo __('Email (домен)'); ?></label>
                                    <input type="text" class="form-control" id="crowdDeepEmailDomain" value="<?php echo htmlspecialchars($deepFormEmailDomain, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label for="crowdDeepPhone" class="form-label"><?php echo __('Телефон'); ?></label>
                                    <input type="text" class="form-control" id="crowdDeepPhone" value="<?php echo htmlspecialchars($deepFormPhone, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-12">
                                    <label for="crowdDeepTemplate" class="form-label"><?php echo __('Шаблон сообщения'); ?></label>
                                    <textarea class="form-control" id="crowdDeepTemplate" rows="4" placeholder="{{token}} {{link}}"><?php echo htmlspecialchars($deepFormTemplate, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <div class="form-text"><?php echo __('Поддерживаются плейсхолдеры {{token}} и {{link}}.'); ?></div>
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button type="button" class="btn btn-danger flex-grow-1" id="crowdDeepStart">
                                        <span class="label-text"><i class="bi bi-broadcast-pin me-1"></i><?php echo __('Запустить глубокую проверку'); ?></span>
                                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="crowdDeepCancel" <?php echo $deepRunInProgress ? '' : 'disabled'; ?>>
                                        <span class="label-text"><i class="bi bi-stop-circle me-1"></i><?php echo __('Остановить'); ?></span>
                                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="crowd-status-card rounded-3 p-3" id="crowdDeepStatusCard">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <div class="text-muted small"><?php echo __('Статус'); ?></div>
                                        <div class="fw-semibold" data-deep-status><?php echo htmlspecialchars($deepRunStatusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="text-muted small"><?php echo __('Диапазон'); ?></div>
                                        <div class="fw-semibold" data-deep-scope><?php echo htmlspecialchars($deepRunScopeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                                <div class="progress mb-2" style="height: 10px;">
                                    <div class="progress-bar bg-danger" id="crowdDeepProgressBar" role="progressbar" style="width: <?php echo (int)$deepRunProgress; ?>%;" aria-valuenow="<?php echo (int)$deepRunProgress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="d-flex justify-content-between small mb-2">
                                    <div><span data-deep-processed><?php echo $deepRunProcessed; ?></span>/<span data-deep-total><?php echo $deepRunTotal; ?></span></div>
                                    <div class="text-success"><i class="bi bi-check-circle me-1"></i><span data-deep-success><?php echo $deepRunSuccess; ?></span></div>
                                    <div class="text-warning"><i class="bi bi-question-circle me-1"></i><span data-deep-partial><?php echo $deepRunPartial; ?></span></div>
                                    <div class="text-danger"><i class="bi bi-x-circle me-1"></i><span data-deep-failed><?php echo $deepRunFailed; ?></span></div>
                                    <div class="text-muted"><i class="bi bi-skip-forward-circle me-1"></i><span data-deep-skipped><?php echo $deepRunSkipped; ?></span></div>
                                </div>
                                <hr>
                                <div class="row small g-2">
                                    <div class="col-sm-6">
                                        <div class="text-muted"><?php echo __('Запущено'); ?>:</div>
                                        <div data-deep-started><?php echo htmlspecialchars($deepRunStartedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="text-muted"><?php echo __('Завершено'); ?>:</div>
                                        <div data-deep-finished><?php echo htmlspecialchars($deepRunFinishedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-muted"><?php echo __('Комментарий'); ?>:</div>
                                        <div data-deep-notes><?php echo htmlspecialchars($deepRunNotes, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-0" id="crowdDeepResultsCard" data-run-id="<?php echo $deepRunId ?: ''; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-clipboard2-data text-danger"></i>
                            <span><?php echo __('Результаты глубокой проверки'); ?></span>
                        </h5>
                        <div class="small text-muted" data-deep-results-meta><?php echo $deepRunTotal > 0 ? sprintf(__('Последний запуск: %s записей'), number_format($deepRunTotal, 0, '.', ' ')) : __('Пока нет результатов'); ?></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle" id="crowdDeepResultsTable">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('Время'); ?></th>
                                    <th><?php echo __('Статус'); ?></th>
                                    <th><?php echo __('URL'); ?></th>
                                    <th><?php echo __('Фрагмент'); ?></th>
                                    <th><?php echo __('Ошибка/Комментарий'); ?></th>
                                    <th><?php echo __('HTTP'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($deepRecentItems)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3" data-deep-empty><?php echo __('Результатов пока нет.'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deepRecentItems as $item): ?>
                                        <?php
                                            $deepStatus = (string)($item['status'] ?? 'pending');
                                            $deepMeta = $crowdDeepStatusMeta[$deepStatus] ?? null;
                                            $deepBadgeClass = $deepMeta['class'] ?? 'badge bg-secondary';
                                            $deepBadgeLabel = $deepMeta['label'] ?? $deepStatus;
                                            $deepUrl = (string)($item['url'] ?? '');
                                            $evidenceUrl = (string)($item['evidence_url'] ?? '');
                                            $messageExcerpt = (string)($item['message_excerpt'] ?? '');
                                            $responseExcerpt = (string)($item['response_excerpt'] ?? '');
                                            $errorText = (string)($item['error'] ?? '');
                                            $createdAt = (string)($item['created_at'] ?? '');
                                        ?>
                                        <tr>
                                            <td><?php echo $createdAt !== '' ? htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td><span class="badge <?php echo htmlspecialchars($deepBadgeClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($deepBadgeLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td class="text-break">
                                                <?php if ($deepUrl !== ''): ?>
                                                    <?php $deepUrlShort = $pp_truncate($deepUrl, 30); ?>
                                                    <a href="<?php echo htmlspecialchars($deepUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($deepUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($deepUrlShort, ENT_QUOTES, 'UTF-8'); ?></a>
                                                    <?php if ($evidenceUrl !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($evidenceUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="ms-1" title="<?php echo __('Открыть ответ'); ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-break"><?php echo $messageExcerpt !== '' ? htmlspecialchars($messageExcerpt, ENT_QUOTES, 'UTF-8') : ($responseExcerpt !== '' ? htmlspecialchars($responseExcerpt, ENT_QUOTES, 'UTF-8') : '—'); ?></td>
                                            <td class="small text-break"><?php echo $errorText !== '' ? htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td><?php echo isset($item['http_status']) && $item['http_status'] ? (int)$item['http_status'] : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
