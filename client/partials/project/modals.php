<?php /* Project page modals extracted from client/project.php */ ?>
<?php if ($canDeleteProject): ?>
<div class="modal fade modal-fixed-center" id="deleteProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="delete_project" value="1" />
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i><?php echo __('Удалить проект'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3"><?php echo __('Проект и все связанные данные будут удалены без возможности восстановления.'); ?></p>
                    <div class="note note--warning small d-flex align-items-start gap-2">
                        <i class="bi bi-exclamation-triangle text-warning"></i>
                        <span><?php echo __('Удаление доступно, потому что в проекте нет ссылок в продвижении или работе.'); ?></span>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Отмена'); ?></button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i><?php echo __('Удалить'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade modal-fixed-center" id="analyzeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-search me-2"></i><?php echo __('Анализ страницы'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="analyze-loading" class="text-center py-3 d-none">
          <div class="spinner-border" role="status"></div>
          <div class="mt-2 small text-muted"><?php echo __('Идет анализ...'); ?></div>
        </div>
        <div id="analyze-result" class="d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade modal-fixed-center" id="wishModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-journal-text me-2"></i><?php echo __('Пожелание'); ?></h5>
        <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="wishCopyBtn"><i class="bi bi-clipboard"></i></button>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="wishContent" class="small"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="promotionReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-graph-up-arrow me-2"></i><?php echo __('Отчет по продвижению'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="promotionReportContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade modal-fixed-center" id="promotionConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-rocket-takeoff me-2"></i><?php echo __('Запуск продвижения'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div>
                        <p class="mb-2 fw-semibold"><?php echo __('Вы собираетесь запустить продвижение для ссылки:'); ?></p>
                        <a href="#" target="_blank" rel="noopener" class="text-break" data-promotion-link><?php echo __('Ваша ссылка'); ?></a>
                    </div>
                </div>
                <p class="mb-2 text-muted"><?php echo __('Перед подтверждением обратите внимание:'); ?></p>
                <ul class="mb-4 ps-3">
                    <li><?php echo __('Процесс запускается сразу после подтверждения и его невозможно отменить.'); ?></li>
                    <li><?php echo __('Несмотря на защиту сценариями сервиса, продвижение может влиять на поисковые позиции — ответственность за запуск несёт владелец проекта.'); ?></li>
                </ul>
                <div class="promotion-confirm-amount card bg-dark border-secondary-subtle mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
                            <div>
                                <div class="text-muted small text-uppercase fw-semibold mb-1"><?php echo __('К списанию'); ?></div>
                                <div class="fs-4 fw-semibold" data-promotion-charge-amount><?php echo htmlspecialchars($promotionChargeFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            </div>
                            <div class="text-muted small<?php echo ($userPromotionDiscount > 0) ? '' : ' d-none'; ?>" data-promotion-discount-block>
                                <span><?php echo __('Включена скидка'); ?>: <span data-promotion-discount-value><?php echo htmlspecialchars(number_format($userPromotionDiscount, ($userPromotionDiscount > 0 && $userPromotionDiscount < 1) ? 2 : 0, ',', '')); ?></span>%</span><br>
                                <span><?php echo __('Базовая стоимость'); ?>: <span data-promotion-charge-base><?php echo htmlspecialchars($promotionBaseFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></span>
                            </div>
                            <div class="text-success small<?php echo ($promotionChargeSavings > 0) ? '' : ' d-none'; ?>" data-promotion-savings-block>
                                <?php echo __('Экономия'); ?>: <span data-promotion-charge-savings><?php echo htmlspecialchars($promotionChargeSavingsFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="small text-muted mb-0">
                    <i class="bi bi-wallet2 me-1"></i><?php echo __('Текущий баланс'); ?>: <span data-current-balance-display data-balance-raw="<?php echo htmlspecialchars(number_format($currentUserBalance, 2, '.', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($currentUserBalanceFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span><br>
                    <i class="bi bi-check-circle me-1"></i><?php echo __('Сумма будет списана немедленно, возврат средств невозможен.'); ?>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Отмена'); ?></button>
                <button type="button" class="btn btn-gradient" id="promotionConfirmAccept">
                    <i class="bi bi-rocket-takeoff me-2"></i><?php echo __('Подтверждаю и запускаю'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade modal-fixed-center" id="insufficientFundsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title"><i class="bi bi-wallet2 me-2"></i><?php echo __('Недостаточно средств'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3"><?php echo __('Для запуска продвижения не хватает средств.'); ?></p>
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted"><?php echo __('Не хватает'); ?>:</span>
                            <strong class="fs-5" data-insufficient-amount>—</strong>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span><?php echo __('Стоимость запуска'); ?>:</span>
                            <span data-insufficient-required>—</span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted">
                            <span><?php echo __('Текущий баланс'); ?>:</span>
                            <span data-insufficient-balance>—</span>
                        </div>
                    </div>
                </div>
                <p class="mb-0 text-muted"><?php echo __('Пополните баланс, чтобы продолжить продвижение.'); ?></p>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
                <a href="<?php echo pp_url('client/balance.php'); ?>" class="btn btn-primary">
                    <i class="bi bi-credit-card me-2"></i><?php echo __('Пополнить баланс'); ?>
                </a>
            </div>
        </div>
    </div>
</div>
