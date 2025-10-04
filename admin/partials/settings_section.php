<?php
// Settings section partial
?>
<div id="settings-section" style="display:none;">
    <h3><?php echo __('Основные настройки'); ?></h3>
    <?php if ($settingsMsg): ?>
        <div class="alert alert-success fade-in"><?php echo htmlspecialchars($settingsMsg); ?></div>
    <?php endif; ?>
    <form method="post" class="card settings-card p-3" autocomplete="off">
        <?php echo csrf_field(); ?>

        <div class="settings-group">
            <div class="settings-grid">
                <div class="settings-field">
                    <label class="form-label" for="settingsCurrency"><?php echo __('Валюта'); ?></label>
                    <select name="currency" id="settingsCurrency" class="form-select" required>
                        <?php foreach ($allowedCurrencies as $cur): ?>
                            <option value="<?php echo $cur; ?>" <?php echo ($settings['currency'] === $cur ? 'selected' : ''); ?>><?php echo $cur; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?php echo __('Используется в биллинге и отчетах.'); ?></div>
                </div>

                <div class="settings-field">
                    <label class="form-label"><?php echo __('Провайдер ИИ'); ?></label>
                    <div class="pp-segmented" role="group" aria-label="AI provider">
                        <input type="radio" id="provOpenAI" name="ai_provider" value="openai" <?php echo ($settings['ai_provider']==='openai'?'checked':''); ?>>
                        <label for="provOpenAI">OpenAI</label>
                        <input type="radio" id="provByoa" name="ai_provider" value="byoa" <?php echo ($settings['ai_provider']==='byoa'?'checked':''); ?>>
                        <label for="provByoa"><?php echo __('Свой ИИ'); ?></label>
                    </div>
                    <div class="form-text"><?php echo __('Выберите источник генерации. Для OpenAI укажите API ключ ниже.'); ?></div>
                </div>

                <div class="settings-field settings-field--wide" id="openaiFields">
                    <label class="form-label" for="openaiApiKeyInput">OpenAI API Key</label>
                    <div class="input-group mb-2">
                        <input type="text" name="openai_api_key" class="form-control" id="openaiApiKeyInput" value="<?php echo htmlspecialchars($settings['openai_api_key']); ?>" placeholder="sk-...">
                        <button type="button" class="btn btn-outline-secondary" id="checkOpenAiKey" data-check-url="<?php echo pp_url('public/check_openai.php'); ?>">
                            <i class="bi bi-shield-check me-1"></i><?php echo __('Проверить'); ?>
                        </button>
                    </div>
                    <label class="form-label" for="openaiModelSelect"><?php echo __('Модель OpenAI'); ?></label>
                    <select name="openai_model" class="form-select" id="openaiModelSelect">
                        <?php
                        $suggested = [
                            'gpt-3.5-turbo' => 'gpt-3.5-turbo',
                            'gpt-4o-mini' => 'gpt-4o-mini',
                            'gpt-4o-mini-translate' => 'gpt-4o-mini-translate',
                            'gpt-5-mini' => 'gpt-5-mini',
                            'gpt-5-nano' => 'gpt-5-nano',
                        ];
                        $selModel = $settings['openai_model'] ?? 'gpt-3.5-turbo';
                        foreach ($suggested as $val => $label) {
                            $sel = ($selModel === $val) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($val) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                        }
                        if (!isset($suggested[$selModel])) {
                            echo '<option value="' . htmlspecialchars($selModel) . '" selected>' . htmlspecialchars($selModel) . '</option>';
                        }
                        ?>
                    </select>
                    <div class="form-text"><?php echo __('Выберите недорогую модель. Можно указать произвольную строку модели.'); ?></div>
                </div>

                <div class="settings-field settings-field--wide">
                    <div class="settings-field__header">
                        <span class="settings-field__title"><?php echo __('Google OAuth'); ?></span>
                        <div class="pp-switch">
                            <input type="checkbox" name="google_oauth_enabled" id="googleEnabled" <?php echo ($settings['google_oauth_enabled']==='1'?'checked':''); ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="googleEnabled" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="settings-field__body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" name="google_client_id" class="form-control" value="<?php echo htmlspecialchars($settings['google_client_id']); ?>" placeholder="Google Client ID">
                            </div>
                            <div class="col-md-6">
                                <input type="text" name="google_client_secret" class="form-control" value="<?php echo htmlspecialchars($settings['google_client_secret']); ?>" placeholder="Google Client Secret">
                            </div>
                        </div>
                        <div class="form-text">
                            <?php echo __('Redirect URI'); ?>: <code><?php echo htmlspecialchars(pp_google_redirect_url()); ?></code>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-2" id="openGoogleHelp"><?php echo __('Как настроить?'); ?></button>
                        </div>
                    </div>
                </div>

                <div class="settings-field settings-field--wide">
                    <div class="settings-field__header">
                        <span class="settings-field__title"><?php echo __('Антикапча'); ?></span>
                    </div>
                    <div class="settings-field__body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <select name="captcha_provider" class="form-select">
                                    <?php $cp = $settings['captcha_provider'] ?? 'none'; ?>
                                    <option value="none" <?php echo ($cp==='none'?'selected':''); ?>><?php echo __('Выключено'); ?></option>
                                    <option value="2captcha" <?php echo ($cp==='2captcha'?'selected':''); ?>>2Captcha</option>
                                    <option value="anti-captcha" <?php echo ($cp==='anti-captcha'?'selected':''); ?>>Anti-Captcha</option>
                                    <option value="capsolver" <?php echo ($cp==='capsolver'?'selected':''); ?>>CapSolver</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input type="text" name="captcha_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['captcha_api_key']); ?>" placeholder="API key">
                            </div>
                        </div>
                        <div class="form-text"><?php echo __('Будет использоваться для автоматического решения reCAPTCHA/hCaptcha при публикации (например, JustPaste.it).'); ?></div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <select name="captcha_fallback_provider" class="form-select">
                                    <?php $cfp = $settings['captcha_fallback_provider'] ?? 'none'; ?>
                                    <option value="none" <?php echo ($cfp==='none'?'selected':''); ?>><?php echo __('Резервный: выключено'); ?></option>
                                    <option value="2captcha" <?php echo ($cfp==='2captcha'?'selected':''); ?>>2Captcha</option>
                                    <option value="anti-captcha" <?php echo ($cfp==='anti-captcha'?'selected':''); ?>>Anti-Captcha</option>
                                    <option value="capsolver" <?php echo ($cfp==='capsolver'?'selected':''); ?>>CapSolver</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input type="text" name="captcha_fallback_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['captcha_fallback_api_key'] ?? ''); ?>" placeholder="<?php echo __('Резервный API key (необязательно)'); ?>">
                            </div>
                        </div>
                        <div class="form-text"><?php echo __('Если основной провайдер не справится или будет недоступен, будет выполнена попытка через резервного.'); ?></div>
                    </div>
                </div>

                <div class="settings-field">
                    <label class="form-label" for="telegramTokenInput"><?php echo __('Telegram токен'); ?></label>
                    <input type="text" name="telegram_token" id="telegramTokenInput" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_token']); ?>" placeholder="1234567890:ABCDEF...">
                </div>
                <div class="settings-field">
                    <label class="form-label" for="telegramChannelInput"><?php echo __('Telegram канал'); ?></label>
                    <input type="text" name="telegram_channel" id="telegramChannelInput" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_channel']); ?>" placeholder="@your_channel или chat_id">
                </div>
            </div>
        </div>

        <div class="settings-group">
            <div class="settings-group__header">
                <h4 class="settings-group__title"><?php echo __('Настройки продвижения'); ?></h4>
                <p class="settings-group__meta"><?php echo __('Укажите стоимость и объём ссылок для каждого уровня каскада.'); ?></p>
            </div>
            <div class="settings-grid settings-grid--single">
                <div class="settings-field">
                    <label class="form-label" for="promotionPricePerLink"><?php echo __('Стоимость продвижения за ссылку'); ?></label>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" name="promotion_price_per_link" id="promotionPricePerLink" class="form-control" value="<?php echo htmlspecialchars($settings['promotion_price_per_link']); ?>" required>
                        <span class="input-group-text"><?php echo htmlspecialchars(strtoupper($settings['currency'] ?? 'RUB')); ?></span>
                    </div>
                    <div class="form-text"><?php echo __('Используется при запуске продвижения и списывается с баланса с учетом скидки.'); ?></div>
                </div>
            </div>

            <div class="promotion-levels-grid">
                <div class="promotion-level-card">
                    <div class="promotion-level-card__header">
                        <div>
                            <div class="promotion-level-card__title"><?php echo __('Уровень 1'); ?></div>
                            <div class="promotion-level-card__hint"><?php echo __('Основные публикации первого уровня каскада.'); ?></div>
                        </div>
                        <div class="pp-switch">
                            <input type="checkbox" name="promotion_level1_enabled" id="promotionLevel1" value="1" <?php echo ($settings['promotion_level1_enabled']==='1' ? 'checked' : ''); ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="promotionLevel1" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="promotion-level-card__body">
                        <label class="form-label" for="promotionLevel1Count"><?php echo __('Количество ссылок'); ?></label>
                        <input type="number" min="1" max="500" step="1" name="promotion_level1_count" id="promotionLevel1Count" class="form-control" value="<?php echo htmlspecialchars($settings['promotion_level1_count']); ?>" required>
                        <div class="form-text"><?php echo __('Сколько публикаций создаём на первом уровне каскада.'); ?></div>
                    </div>
                </div>

                <div class="promotion-level-card">
                    <div class="promotion-level-card__header">
                        <div>
                            <div class="promotion-level-card__title"><?php echo __('Уровень 2'); ?></div>
                            <div class="promotion-level-card__hint"><?php echo __('Ссылки второго уровня поддерживают каждую ссылку уровня 1.'); ?></div>
                        </div>
                        <div class="pp-switch">
                            <input type="checkbox" name="promotion_level2_enabled" id="promotionLevel2" value="1" <?php echo ($settings['promotion_level2_enabled']==='1' ? 'checked' : ''); ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="promotionLevel2" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="promotion-level-card__body">
                        <label class="form-label" for="promotionLevel2Per"><?php echo __('Количество ссылок'); ?></label>
                        <input type="number" min="1" max="500" step="1" name="promotion_level2_per_level1" id="promotionLevel2Per" class="form-control" value="<?php echo htmlspecialchars($settings['promotion_level2_per_level1']); ?>" required>
                        <div class="form-text"><?php echo __('Сколько ссылок уровня 2 создаётся на каждую ссылку уровня 1.'); ?></div>
                    </div>
                </div>

                <div class="promotion-level-card">
                    <div class="promotion-level-card__header">
                        <div>
                            <div class="promotion-level-card__title"><?php echo __('Уровень 3'); ?></div>
                            <div class="promotion-level-card__hint"><?php echo __('Поддерживает каждую публикацию уровня 2 дополнительными ссылками.'); ?></div>
                        </div>
                        <div class="pp-switch">
                            <input type="checkbox" name="promotion_level3_enabled" id="promotionLevel3" value="1" <?php echo ($settings['promotion_level3_enabled']==='1' ? 'checked' : ''); ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="promotionLevel3" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="promotion-level-card__body">
                        <label class="form-label" for="promotionLevel3Per"><?php echo __('Количество ссылок'); ?></label>
                        <input type="number" min="1" max="500" step="1" name="promotion_level3_per_level2" id="promotionLevel3Per" class="form-control" value="<?php echo htmlspecialchars($settings['promotion_level3_per_level2']); ?>" required>
                        <div class="form-text"><?php echo __('Сколько ссылок уровня 3 создаётся на каждую публикацию уровня 2.'); ?></div>
                    </div>
                </div>

                <div class="promotion-level-card">
                    <div class="promotion-level-card__header">
                        <div>
                            <div class="promotion-level-card__title"><?php echo __('Крауд'); ?></div>
                            <div class="promotion-level-card__hint"><?php echo __('Закрепляет результат естественными упоминаниями.'); ?></div>
                        </div>
                        <div class="pp-switch">
                            <input type="checkbox" name="promotion_crowd_enabled" id="promotionCrowd" value="1" <?php echo ($settings['promotion_crowd_enabled']==='1' ? 'checked' : ''); ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="promotionCrowd" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="promotion-level-card__body">
                        <label class="form-label" for="promotionCrowdCount"><?php echo __('Количество ссылок'); ?></label>
                        <input type="number" min="0" max="5000" step="1" name="promotion_crowd_per_article" id="promotionCrowdCount" class="form-control" value="<?php echo htmlspecialchars($settings['promotion_crowd_per_article']); ?>">
                        <div class="form-text"><?php echo __('Сколько крауд-ссылок добавляем на каждую публикацию последнего уровня.'); ?></div>
                    </div>
                </div>
            </div>

            <div class="cascade-summary card mt-3" id="promotionCascadeSummary" aria-live="polite">
                <div class="card-body">
                    <div class="cascade-summary__title d-flex align-items-center gap-2">
                        <i class="bi bi-diagram-3"></i>
                        <div>
                            <div class="fw-semibold"><?php echo __('Каскад ссылок'); ?></div>
                            <div class="text-muted small"><?php echo __('Автоматический перерасчет объема ссылочного веса по уровням.'); ?></div>
                        </div>
                    </div>
                    <div class="cascade-summary__grid mt-3">
                        <div class="cascade-summary__item" data-level="1">
                            <div class="cascade-summary__label">
                                <span><?php echo __('Уровень 1 → продвигаемая страница'); ?></span>
                                <button type="button" class="cascade-summary__hint" data-bs-toggle="tooltip" title="<?php echo __('Количество прямых ссылок на целевую страницу.'); ?>">
                                    <i class="bi bi-info-circle"></i>
                                </button>
                            </div>
                            <div class="cascade-summary__value"><span id="cascadeLevel1Total">0</span></div>
                        </div>
                        <div class="cascade-summary__item" data-level="2">
                            <div class="cascade-summary__label">
                                <span><?php echo __('Уровень 2 → поддержка уровня 1'); ?></span>
                                <button type="button" class="cascade-summary__hint" data-bs-toggle="tooltip" title="<?php echo __('Сколько ссылок уровня 2 уходит на каждую успешную публикацию уровня 1.'); ?>">
                                    <i class="bi bi-info-circle"></i>
                                </button>
                            </div>
                            <div class="cascade-summary__value"><span id="cascadeLevel2Total">0</span></div>
                        </div>
                        <div class="cascade-summary__item" data-level="3">
                            <div class="cascade-summary__label">
                                <span><?php echo __('Уровень 3 → поддержка уровня 2'); ?></span>
                                <button type="button" class="cascade-summary__hint" data-bs-toggle="tooltip" title="<?php echo __('Количество ссылок уровня 3, которые подпитывают страницы уровня 2.'); ?>">
                                    <i class="bi bi-info-circle"></i>
                                </button>
                            </div>
                            <div class="cascade-summary__value"><span id="cascadeLevel3Total">0</span></div>
                        </div>
                        <div class="cascade-summary__item" data-level="crowd">
                            <div class="cascade-summary__label">
                                <span><?php echo __('Крауд → ускорение индексации'); ?></span>
                                <button type="button" class="cascade-summary__hint" data-bs-toggle="tooltip" title="<?php echo __('Сколько крауд-ссылок направим на материалы финального уровня.'); ?>">
                                    <i class="bi bi-info-circle"></i>
                                </button>
                            </div>
                            <div class="cascade-summary__value"><span id="cascadeCrowdTotal">0</span></div>
                        </div>
                    </div>
                    <div class="cascade-summary__footer mt-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="cascade-summary__footer-label text-muted"><?php echo __('Всего материалов в каскаде'); ?>:</span>
                            <span class="cascade-summary__footer-value fw-semibold" id="cascadeTotalMaterials">0</span>
                        </div>
                        <div class="text-muted small" id="cascadeSummaryHint"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" name="settings_submit" value="1" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
        </div>
    </form>

    <!-- Google Help Modal (scrollable, high-contrast) -->
    <div id="googleHelpModal" class="pp-modal" aria-hidden="true" role="dialog" aria-labelledby="googleHelpTitle">
        <div class="pp-modal-dialog">
            <div class="pp-modal-header">
                <div class="pp-modal-title" id="googleHelpTitle"><?php echo __('Настройка входа через Google'); ?></div>
                <button type="button" class="pp-close" data-pp-close>&times;</button>
            </div>
            <div class="pp-modal-body">
                <p><?php echo __('Эта инструкция поможет подключить вход через Google в несколько шагов. Выполняйте в Google Cloud Console.'); ?></p>
                <ol>
                    <li><?php echo __('Откройте'); ?> <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">APIs &amp; Services → Credentials</a> (<?php echo __('создайте проект при необходимости'); ?>).</li>
                    <li><?php echo __('На вкладке OAuth consent screen задайте тип (External), название приложения и email поддержки. Сохраните.'); ?></li>
                    <li><?php echo __('Перейдите в Credentials → Create Credentials → OAuth client ID и выберите Web application.'); ?></li>
                    <li><?php echo __('В Authorized redirect URIs добавьте'); ?>: <code><?php echo htmlspecialchars(pp_google_redirect_url()); ?></code></li>
                    <li><?php echo __('Сохраните и скопируйте Client ID и Client Secret. Вставьте их в поля настроек на этой странице.'); ?></li>
                    <li><?php echo __('Включите переключатель «Разрешить вход через Google» и нажмите «Сохранить».'); ?></li>
                </ol>
                <h6 class="mt-3"><?php echo __('Проверка'); ?></h6>
                <p><?php echo __('Откройте страницу входа. Появится кнопка «Войти через Google». Авторизуйтесь и подтвердите права.'); ?></p>
                <h6 class="mt-3"><?php echo __('Советы и устранение неполадок'); ?></h6>
                <ul>
                    <li><?php echo __('Если видите ошибку redirect_uri_mismatch — проверьте точное совпадение Redirect URI.'); ?></li>
                    <li><?php echo __('Для публикации за пределами тестовых пользователей переведите приложение в статус In production на странице OAuth consent screen.'); ?></li>
                    <li><?php echo __('Проверьте, что время на сервере синхронизировано (NTP), иначе подпись токена может считаться просроченной.'); ?></li>
                    <li><?php echo __('Ограничьте доступ при необходимости, проверяя домен email в обработчике после входа.'); ?></li>
                </ul>
            </div>
            <div class="pp-modal-footer">
                <button type="button" class="btn btn-outline-primary" data-pp-close><?php echo __('Готово'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
  const modal = document.getElementById('googleHelpModal');
  const openBtn = document.getElementById('openGoogleHelp');
  function close(){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); }
  function open(){ modal.classList.add('show'); modal.removeAttribute('aria-hidden'); }
  if (openBtn) openBtn.addEventListener('click', open);
  modal?.addEventListener('click', function(e){ if (e.target === modal || e.target.closest('[data-pp-close]')) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.classList.contains('show')) close(); });
})();
</script>

<script>
(function(){
  // Toggle OpenAI fields by provider selection
  const fields = document.getElementById('openaiFields');
  const radios = document.querySelectorAll('input[name="ai_provider"]');
  function apply(){
    const val = document.querySelector('input[name="ai_provider"]:checked')?.value || 'openai';
    if (val === 'openai') {
      fields?.classList.remove('d-none');
    } else {
      fields?.classList.add('d-none');
    }
  }
  radios.forEach(r => r.addEventListener('change', apply));
  apply();
})();
</script>

<script>
(function(){
    const level1Toggle = document.getElementById('promotionLevel1');
    const level2Toggle = document.getElementById('promotionLevel2');
    const level3Toggle = document.getElementById('promotionLevel3');
    const crowdToggle = document.getElementById('promotionCrowd');
    const level1Input = document.getElementById('promotionLevel1Count');
    const level2Input = document.getElementById('promotionLevel2Per');
    const level3Input = document.getElementById('promotionLevel3Per');
    const crowdInput = document.getElementById('promotionCrowdCount');
    const summaryEl = document.getElementById('promotionCascadeSummary');
    const level1TotalEl = document.getElementById('cascadeLevel1Total');
    const level2TotalEl = document.getElementById('cascadeLevel2Total');
    const level3TotalEl = document.getElementById('cascadeLevel3Total');
    const crowdTotalEl = document.getElementById('cascadeCrowdTotal');
    const totalMaterialsEl = document.getElementById('cascadeTotalMaterials');
    const summaryHintEl = document.getElementById('cascadeSummaryHint');
    const CASCADE_STRINGS = {
        final: <?php echo json_encode(__('Финальный уровень: %s (%s публикаций).')); ?>,
        crowd: <?php echo json_encode(__('Крауд: %s × %s = %s упоминаний.')); ?>,
        crowdOff: <?php echo json_encode(__('Крауд отключен.')); ?>,
        disabled: <?php echo json_encode(__('Уровень отключен.')); ?>,
        levelLabels: {
            1: <?php echo json_encode(__('Уровень 1')); ?>,
            2: <?php echo json_encode(__('Уровень 2')); ?>,
            3: <?php echo json_encode(__('Уровень 3')); ?>,
            crowd: <?php echo json_encode(__('Крауд')); ?>
        }
    };

    function formatNumber(value){
        const number = Number(value) || 0;
        if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
            try { return new Intl.NumberFormat('ru-RU').format(number); } catch (e) {}
        }
        return String(number);
    }

    function ensureTooltip(root){
        if (!root) return;
        const items = root.querySelectorAll('[data-bs-toggle="tooltip"]');
        if (!items.length) return;
        if (window.bootstrap && typeof bootstrap.Tooltip === 'function') {
            items.forEach(el => {
                try { bootstrap.Tooltip.getOrCreateInstance(el); } catch (e) {}
            });
        }
    }

    function setInputDisabled(input, disabled) {
        if (!input) return;
            input.toggleAttribute('readonly', disabled);
            input.classList.toggle('disabled', disabled);
            input.classList.toggle('is-readonly', disabled);
            if (disabled) {
                input.setAttribute('tabindex', '-1');
            } else {
                input.removeAttribute('tabindex');
            }
    }

    function parseIntValue(input, fallback) {
        if (!input) return fallback;
        const value = parseInt(input.value, 10);
        return Number.isFinite(value) && value > 0 ? value : fallback;
    }

    function parseCrowdValue(input) {
        if (!input) return 0;
        const value = parseInt(input.value, 10);
        return Number.isFinite(value) && value >= 0 ? value : 0;
    }

    function updateCascade() {
        if (!summaryEl) return;

        const level1Enabled = level1Toggle ? level1Toggle.checked : true;
        const level1Count = level1Enabled ? Math.max(0, parseIntValue(level1Input, 0)) : 0;

        const level2EnabledRaw = level2Toggle ? level2Toggle.checked : false;
        const level2Enabled = level2EnabledRaw && level1Count > 0;
        setInputDisabled(level2Input, !level2Enabled);
        if (level2Toggle) {
            level2Toggle.toggleAttribute('disabled', level1Count === 0);
            level2Toggle.closest('.pp-switch')?.classList.toggle('pp-switch--disabled', level1Count === 0);
            if (level1Count === 0 && level2Toggle.checked) {
                level2Toggle.checked = false;
            }
        }
        const level2Per = level2Enabled ? parseIntValue(level2Input, 0) : 0;
        const level2Total = level2Enabled ? level1Count * level2Per : 0;

        const level3EnabledRaw = level3Toggle ? level3Toggle.checked : false;
        const level3Eligible = level2Enabled && level2Total > 0;
        if (level3Toggle) {
            level3Toggle.toggleAttribute('disabled', !level3Eligible);
            level3Toggle.closest('.pp-switch')?.classList.toggle('pp-switch--disabled', !level3Eligible);
            if (!level3Eligible && level3Toggle.checked) {
                level3Toggle.checked = false;
            }
        }
        const level3Enabled = level3EnabledRaw && level3Eligible;
        setInputDisabled(level3Input, !level3Enabled);
        const level3Per = level3Enabled ? parseIntValue(level3Input, 0) : 0;
        const level3Total = level3Enabled ? level2Total * level3Per : 0;

        const finalLevel = level3Enabled ? 3 : (level2Enabled ? 2 : 1);
        const finalTotal = finalLevel === 3 ? level3Total : (finalLevel === 2 ? level2Total : level1Count);

        const crowdEnabledRaw = crowdToggle ? crowdToggle.checked : false;
        const crowdEligible = finalTotal > 0;
        const crowdEnabled = crowdEnabledRaw && crowdEligible;
        if (crowdToggle) {
            crowdToggle.closest('.pp-switch')?.classList.toggle('pp-switch--disabled', !crowdEligible);
            if (!crowdEligible) { crowdToggle.checked = false; }
        }
        setInputDisabled(crowdInput, !crowdEnabled);
        const crowdPer = crowdEnabled ? parseCrowdValue(crowdInput) : 0;
        const crowdTotal = crowdEnabled ? finalTotal * crowdPer : 0;

        if (level1TotalEl) level1TotalEl.textContent = formatNumber(level1Count);
        if (level2TotalEl) level2TotalEl.textContent = level2Enabled ? formatNumber(level2Total) : CASCADE_STRINGS.disabled;
        if (level3TotalEl) level3TotalEl.textContent = level3Enabled ? formatNumber(level3Total) : CASCADE_STRINGS.disabled;
        if (crowdTotalEl) crowdTotalEl.textContent = crowdEnabled ? formatNumber(crowdTotal) : CASCADE_STRINGS.crowdOff;

        const totalMaterials = level1Count + level2Total + level3Total + crowdTotal;
        if (totalMaterialsEl) totalMaterialsEl.textContent = formatNumber(totalMaterials);

        if (summaryHintEl) {
            const levelLabel = CASCADE_STRINGS.levelLabels[finalLevel] || '';
            const parts = [];
            parts.push(CASCADE_STRINGS.final.replace('%s', levelLabel).replace('%s', formatNumber(finalTotal)));
            if (crowdEnabled) {
                parts.push(
                    CASCADE_STRINGS.crowd
                        .replace('%s', formatNumber(finalTotal))
                        .replace('%s', formatNumber(crowdPer))
                        .replace('%s', formatNumber(crowdTotal))
                );
            } else {
                parts.push(CASCADE_STRINGS.crowdOff);
            }
            summaryHintEl.textContent = parts.join(' ');
        }

        ensureTooltip(summaryEl);
    }

    const listeners = [level1Toggle, level2Toggle, level3Toggle, crowdToggle];
    listeners.forEach(el => el?.addEventListener('change', updateCascade));
    [level1Input, level2Input, level3Input, crowdInput].forEach(el => el?.addEventListener('input', updateCascade));

    ensureTooltip(summaryEl);
    updateCascade();
})();
</script>
