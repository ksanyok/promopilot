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
        <div class="row g-4">
            <div class="col-md-4">
                <label class="form-label"><?php echo __('Валюта'); ?></label>
                <select name="currency" class="form-select form-control" required>
                    <?php foreach ($allowedCurrencies as $cur): ?>
                        <option value="<?php echo $cur; ?>" <?php echo ($settings['currency'] === $cur ? 'selected' : ''); ?>><?php echo $cur; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?php echo __('Используется в биллинге и отчетах.'); ?></div>
            </div>
            <div class="col-md-8"></div>

            <div class="col-md-6">
                <label class="form-label"><?php echo __('Провайдер ИИ'); ?></label>
                <div class="pp-segmented" role="group" aria-label="AI provider">
                    <input type="radio" id="provOpenAI" name="ai_provider" value="openai" <?php echo ($settings['ai_provider']==='openai'?'checked':''); ?>>
                    <label for="provOpenAI">OpenAI</label>
                    <input type="radio" id="provByoa" name="ai_provider" value="byoa" <?php echo ($settings['ai_provider']==='byoa'?'checked':''); ?>>
                    <label for="provByoa"><?php echo __('Свой ИИ'); ?></label>
                </div>
                <div class="form-text"><?php echo __('Выберите источник генерации. Для OpenAI укажите API ключ ниже.'); ?></div>
            </div>
            <div class="col-md-6" id="openaiFields">
                <label class="form-label">OpenAI API Key</label>
                <div class="input-group mb-2">
                    <input type="text" name="openai_api_key" class="form-control" id="openaiApiKeyInput" value="<?php echo htmlspecialchars($settings['openai_api_key']); ?>" placeholder="sk-...">
                    <button type="button" class="btn btn-outline-secondary" id="checkOpenAiKey" data-check-url="<?php echo pp_url('public/check_openai.php'); ?>">
                        <i class="bi bi-shield-check me-1"></i><?php echo __('Проверить'); ?>
                    </button>
                </div>
                <label class="form-label mt-3"><?php echo __('Модель OpenAI'); ?></label>
                <select name="openai_model" class="form-select form-control" id="openaiModelSelect">
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

            <div class="col-md-6">
                <label class="form-label"><?php echo __('Google OAuth'); ?></label>
                <div class="pp-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="google_oauth_enabled" id="googleEnabled" <?php echo ($settings['google_oauth_enabled']==='1'?'checked':''); ?>>
                    <span class="track"><span class="thumb"></span></span>
                    <label for="googleEnabled" class="ms-1"><?php echo __('Разрешить вход через Google'); ?></label>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <input type="text" name="google_client_id" class="form-control" value="<?php echo htmlspecialchars($settings['google_client_id']); ?>" placeholder="Google Client ID">
                    </div>
                    <div class="col-md-6">
                        <input type="text" name="google_client_secret" class="form-control" value="<?php echo htmlspecialchars($settings['google_client_secret']); ?>" placeholder="Google Client Secret">
                    </div>
                </div>
                <div class="form-text mt-1">
                    <?php echo __('Redirect URI'); ?>: <code><?php echo htmlspecialchars(pp_google_redirect_url()); ?></code>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2" id="openGoogleHelp"><?php echo __('Как настроить?'); ?></button>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Антикапча'); ?></label>
                <div class="row g-2">
                    <div class="col-md-6">
                        <select name="captcha_provider" class="form-select form-control">
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

                <div class="row g-2 mt-2">
                    <div class="col-md-6">
                        <select name="captcha_fallback_provider" class="form-select form-control">
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

            <div class="col-md-6">
                <label class="form-label"><?php echo __('Telegram токен'); ?></label>
                <input type="text" name="telegram_token" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_token']); ?>" placeholder="1234567890:ABCDEF...">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Telegram канал'); ?></label>
                <input type="text" name="telegram_channel" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_channel']); ?>" placeholder="@your_channel или chat_id">
            </div>

            <div class="col-12"><hr></div>
            <div class="col-12">
                <h4 class="mt-2 mb-3"><?php echo __('Настройки продвижения'); ?></h4>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo __('Стоимость продвижения за ссылку'); ?></label>
                <div class="input-group">
                    <input type="number" step="0.01" min="0" name="promotion_price_per_link" class="form-control" value="<?php echo htmlspecialchars($settings['promotion_price_per_link']); ?>" required>
                    <span class="input-group-text"><?php echo htmlspecialchars(strtoupper($settings['currency'] ?? 'RUB')); ?></span>
                </div>
                <div class="form-text"><?php echo __('Используется при запуске продвижения и списывается с баланса с учетом скидки.'); ?></div>
            </div>
            <div class="col-md-8">
                <label class="form-label"><?php echo __('Включенные уровни'); ?></label>
                <div class="d-flex flex-wrap gap-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="promotionLevel1" name="promotion_level1_enabled" value="1" <?php echo ($settings['promotion_level1_enabled']==='1' ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="promotionLevel1"><?php echo __('Уровень 1'); ?></label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="promotionLevel2" name="promotion_level2_enabled" value="1" <?php echo ($settings['promotion_level2_enabled']==='1' ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="promotionLevel2"><?php echo __('Уровень 2'); ?></label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="promotionLevel3" name="promotion_level3_enabled" value="1" <?php echo ($settings['promotion_level3_enabled']==='1' ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="promotionLevel3"><?php echo __('Уровень 3'); ?></label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="promotionCrowd" name="promotion_crowd_enabled" value="1" <?php echo ($settings['promotion_crowd_enabled']==='1' ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="promotionCrowd"><?php echo __('Крауд'); ?></label>
                    </div>
                </div>
                <div class="form-text"><?php echo __('Отключенные уровни не будут запускаться для новых продвижений.'); ?></div>
            </div>
        </div>
        <div class="mt-3">
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
