<?php
// Mail settings section partial
$mailEnabled = !in_array(strtolower((string)($settings['mail_enabled'] ?? '1')), ['0','false','off','no',''], true);
$notificationsEnabled = !in_array(strtolower((string)($settings['notifications_email_enabled'] ?? '1')), ['0','false','off','no',''], true);
$mailDisabledAll = in_array(strtolower((string)($settings['mail_disable_all'] ?? '0')), ['1','true','yes','on','all','disable','disabled'], true);
$mailTransport = strtolower((string)($settings['mail_transport'] ?? 'native'));
if (!in_array($mailTransport, ['native','smtp'], true)) { $mailTransport = 'native'; }
$mailEncryption = strtolower((string)($settings['mail_smtp_encryption'] ?? 'tls'));
if (!in_array($mailEncryption, ['none','ssl','tls'], true)) { $mailEncryption = 'tls'; }
$encryptionLabels = [
    'none' => __('Без шифрования'),
    'tls' => 'TLS',
    'ssl' => 'SSL',
];
?>
<div id="mail-section" style="display:none;">
    <h3><?php echo __('Почтовые уведомления'); ?></h3>
    <?php if (!empty($mailMsg)): ?>
        <?php $mailMsgSuccess = ($mailMsg === __('Настройки почты сохранены.')); ?>
        <div class="alert <?php echo $mailMsgSuccess ? 'alert-success' : 'alert-danger'; ?> fade-in"><?php echo htmlspecialchars($mailMsg); ?></div>
    <?php endif; ?>
    <form method="post" class="card settings-card p-3" autocomplete="off">
        <?php echo csrf_field(); ?>

        <div class="settings-group">
            <div class="settings-group__header">
                <h4 class="settings-group__title"><?php echo __('Общие параметры'); ?></h4>
                <p class="settings-group__meta"><?php echo __('Управляйте отправкой писем и глобальными уведомлениями.'); ?></p>
            </div>
            <div class="settings-grid">
                <div class="settings-field">
                    <div class="settings-field__header">
                        <span class="settings-field__title"><?php echo __('Отправка писем'); ?></span>
                        <div class="pp-switch">
                            <input type="checkbox" name="mail_enabled" id="mailEnabledToggle" value="1" <?php echo $mailEnabled ? 'checked' : ''; ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="mailEnabledToggle" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="form-text"><?php echo __('Разрешить PromoPilot отправлять системные письма.'); ?></div>
                </div>
                <div class="settings-field">
                    <div class="settings-field__header">
                        <span class="settings-field__title"><?php echo __('Уведомления баланса'); ?></span>
                        <div class="pp-switch">
                            <input type="checkbox" name="notifications_email_enabled" id="notificationsEmailToggle" value="1" <?php echo $notificationsEnabled ? 'checked' : ''; ?>>
                            <span class="track"><span class="thumb"></span></span>
                            <label for="notificationsEmailToggle" class="pp-switch__label"><?php echo __('Включить'); ?></label>
                        </div>
                    </div>
                    <div class="form-text"><?php echo __('Отправлять клиентам письма об изменении баланса и транзакциях.'); ?></div>
                </div>
                <div class="settings-field settings-field--wide">
                    <label class="form-label" for="mailDisableAllToggle"><?php echo __('Экстренная блокировка писем'); ?></label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="mailDisableAllToggle" name="mail_disable_all" value="1" <?php echo $mailDisabledAll ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="mailDisableAllToggle"><?php echo __('Остановить отправку всех писем'); ?></label>
                    </div>
                    <div class="form-text text-muted"><?php echo __('Включите для полной приостановки писем, включая системные уведомления.'); ?></div>
                </div>
            </div>
        </div>

        <div class="settings-group">
            <div class="settings-group__header">
                <h4 class="settings-group__title"><?php echo __('Отправитель'); ?></h4>
                <p class="settings-group__meta"><?php echo __('Настройте имя и email, которые будут видеть получатели.'); ?></p>
            </div>
            <div class="settings-grid">
                <div class="settings-field">
                    <label class="form-label" for="mailFromNameInput"><?php echo __('Имя отправителя'); ?></label>
                    <input type="text" name="mail_from_name" id="mailFromNameInput" class="form-control" value="<?php echo htmlspecialchars($settings['mail_from_name'] ?? '', ENT_QUOTES); ?>" placeholder="PromoPilot">
                </div>
                <div class="settings-field">
                    <label class="form-label" for="mailFromEmailInput"><?php echo __('Email отправителя'); ?></label>
                    <input type="email" name="mail_from_email" id="mailFromEmailInput" class="form-control" value="<?php echo htmlspecialchars($settings['mail_from_email'] ?? '', ENT_QUOTES); ?>" placeholder="noreply@example.com">
                    <div class="form-text"><?php echo __('Используйте подтверждённый адрес на своем домене.'); ?></div>
                </div>
                <div class="settings-field settings-field--wide">
                    <label class="form-label" for="mailReplyToInput"><?php echo __('Адрес для ответа'); ?></label>
                    <input type="email" name="mail_reply_to" id="mailReplyToInput" class="form-control" value="<?php echo htmlspecialchars($settings['mail_reply_to'] ?? '', ENT_QUOTES); ?>" placeholder="support@example.com">
                    <div class="form-text"><?php echo __('Оставьте пустым, чтобы использовать адрес отправителя.'); ?></div>
                </div>
            </div>
        </div>

        <div class="settings-group">
            <div class="settings-group__header">
                <h4 class="settings-group__title"><?php echo __('Способ доставки'); ?></h4>
                <p class="settings-group__meta"><?php echo __('Выберите механизм отправки и при необходимости настройте SMTP.'); ?></p>
            </div>
            <div class="settings-grid settings-grid--single">
                <div class="settings-field">
                    <label class="form-label" for="mailTransportSelect"><?php echo __('Метод отправки'); ?></label>
                    <select name="mail_transport" id="mailTransportSelect" class="form-select">
                        <option value="native" <?php echo $mailTransport === 'native' ? 'selected' : ''; ?>><?php echo __('PHP mail()'); ?></option>
                        <option value="smtp" <?php echo $mailTransport === 'smtp' ? 'selected' : ''; ?>>SMTP</option>
                    </select>
                    <div class="form-text"><?php echo __('При SMTP обязательны корректные данные сервера.'); ?></div>
                </div>
            </div>
            <div id="smtpSettings" class="settings-grid mt-3 <?php echo $mailTransport === 'smtp' ? '' : 'd-none'; ?>">
                <div class="settings-field">
                    <label class="form-label" for="mailSmtpHostInput"><?php echo __('SMTP сервер'); ?></label>
                    <input type="text" name="mail_smtp_host" id="mailSmtpHostInput" class="form-control" value="<?php echo htmlspecialchars($settings['mail_smtp_host'] ?? '', ENT_QUOTES); ?>" placeholder="smtp.example.com">
                </div>
                <div class="settings-field">
                    <label class="form-label" for="mailSmtpPortInput"><?php echo __('Порт'); ?></label>
                    <input type="number" min="1" max="65535" name="mail_smtp_port" id="mailSmtpPortInput" class="form-control" value="<?php echo htmlspecialchars($settings['mail_smtp_port'] ?? '587', ENT_QUOTES); ?>">
                    <div class="form-text"><?php echo __('Обычно 587 для TLS или 465 для SSL.'); ?></div>
                </div>
                <div class="settings-field">
                    <label class="form-label" for="mailSmtpUserInput"><?php echo __('Имя пользователя SMTP'); ?></label>
                    <input type="text" name="mail_smtp_username" id="mailSmtpUserInput" class="form-control" value="<?php echo htmlspecialchars($settings['mail_smtp_username'] ?? '', ENT_QUOTES); ?>">
                </div>
                <div class="settings-field">
                    <label class="form-label" for="mailSmtpPasswordInput"><?php echo __('Пароль SMTP'); ?></label>
                    <input type="password" name="mail_smtp_password" id="mailSmtpPasswordInput" class="form-control" value="<?php echo htmlspecialchars($settings['mail_smtp_password'] ?? '', ENT_QUOTES); ?>" autocomplete="new-password">
                </div>
                <div class="settings-field">
                    <label class="form-label" for="mailSmtpEncryptionSelect"><?php echo __('Шифрование SMTP'); ?></label>
                    <select name="mail_smtp_encryption" id="mailSmtpEncryptionSelect" class="form-select">
                        <?php foreach ($encryptionLabels as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" <?php echo $mailEncryption === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" name="mail_submit" value="1" class="btn btn-primary">
                <i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?>
            </button>
        </div>
    </form>
</div>

<script>
(function(){
    const transportSelect = document.getElementById('mailTransportSelect');
    const smtpSettings = document.getElementById('smtpSettings');
    if (!transportSelect || !smtpSettings) { return; }
    function toggleSmtp(){
        const val = (transportSelect.value || '').toLowerCase();
        if (val === 'smtp') {
            smtpSettings.classList.remove('d-none');
        } else {
            smtpSettings.classList.add('d-none');
        }
    }
    transportSelect.addEventListener('change', toggleSmtp);
    toggleSmtp();
})();
</script>
