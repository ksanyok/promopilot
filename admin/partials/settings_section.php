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
                            <div class="promotion-level-card__hint"><?php echo __('Дополнительный уровень для сложных кампаний.'); ?></div>
                        </div>
                        <div class="pp-switch">
                            <input type="checkbox" name="promotion_level3_enabled" id="promotionLevel3" value="1" <?php echo ($settings['promotion_level3_enabled']==='1' ? 'checked' : ''); ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="promotionLevel3" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="promotion-level-card__body">
                        <p class="promotion-level-card__note"><?php echo __('Настройки уровня 3 пока используются по умолчанию.'); ?></p>
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
                        <div class="form-text"><?php echo __('Сколько крауд-ссылок планируется на каждую ссылку уровня 1.'); ?></div>
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
