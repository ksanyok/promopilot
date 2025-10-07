<?php
// Users section partial
?>
<?php $focusUserAttr = isset($focusUserId) && $focusUserId > 0 ? (string)(int)$focusUserId : ''; ?>
<div id="users-section" data-focus-user="<?php echo htmlspecialchars($focusUserAttr, ENT_QUOTES, 'UTF-8'); ?>">
<h3><?php echo __('Пользователи'); ?></h3>
<?php include __DIR__ . '/activity_chart.php'; ?>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead>
        <tr>
            <th class="text-nowrap">ID</th>
            <th><?php echo __('Пользователь'); ?></th>
            <th class="d-none d-md-table-cell text-center"><?php echo __('Проекты'); ?></th>
            <th class="d-none d-sm-table-cell"><?php echo __('Роль'); ?></th>
            <th class="d-none d-lg-table-cell"><?php echo __('Баланс'); ?></th>
            <th class="d-none d-lg-table-cell text-center"><?php echo __('Скидка'); ?></th>
            <th class="text-nowrap"><?php echo __('Дата регистрации'); ?></th>
            <th class="text-end"><?php echo __('Действия'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php $defaultRefPercent = (float)str_replace(',', '.', (string)get_setting('referral_default_percent', '0')); ?>
        <?php while ($user = $users->fetch_assoc()): ?>
            <?php $rowPctRaw = (float)($user['referral_commission_percent'] ?? 0); $rowPctEff = $rowPctRaw > 0 ? $rowPctRaw : $defaultRefPercent; ?>
            <tr data-user-id="<?php echo (int)$user['id']; ?>" data-ref-code="<?php echo htmlspecialchars((string)($user['referral_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-ref-percent="<?php echo htmlspecialchars(number_format($rowPctEff, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                <td class="text-muted">#<?php echo (int)$user['id']; ?></td>
                <td>
                    <div class="fw-semibold"><?php echo htmlspecialchars($user['username']); ?></div>
                    <?php if (!empty($user['email'])): ?>
                        <div class="text-muted small"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($user['email']); ?></div>
                    <?php endif; ?>
                    <div class="small mt-1">
                        <span class="badge bg-info text-dark" title="<?php echo __('Реферальная комиссия'); ?>">
                            <i class="bi bi-percent"></i> <?php echo number_format($rowPctEff, 2); ?>%
                        </span>
                        <button type="button" class="btn btn-link btn-sm text-decoration-none" data-user-info="<?php echo (int)$user['id']; ?>">
                            <i class="bi bi-info-circle"></i>
                        </button>
                    </div>
                </td>
                <td class="d-none d-md-table-cell text-center">
                    <span class="badge bg-secondary"><?php echo (int)$user['projects_count']; ?></span>
                </td>
                <td class="d-none d-sm-table-cell text-muted"><?php echo htmlspecialchars($user['role']); ?></td>
                <td class="d-none d-lg-table-cell"><?php echo htmlspecialchars(format_currency($user['balance'])); ?></td>
                <td class="d-none d-lg-table-cell text-center">
                    <span class="badge bg-light text-dark"><?php echo number_format((float)($user['promotion_discount'] ?? 0), 2); ?>%</span>
                </td>
                <td class="text-muted">
                    <?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$user['created_at']))); ?>
                </td>
                <td class="text-end">
                    <?php $t = action_token('login_as', (string)$user['id']); ?>
                    <a href="admin_login_as.php?user_id=<?php echo (int)$user['id']; ?>&t=<?php echo urlencode($t); ?>" class="btn btn-warning btn-sm"><?php echo __('Войти как'); ?></a>
                    <a href="edit_balance.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-info btn-sm"><?php echo __('Изменить баланс'); ?></a>
                    <a href="edit_discount.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-outline-primary btn-sm"><?php echo __('Изменить скидку'); ?></a>
                    <a href="edit_referral.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-outline-info btn-sm"><?php echo __('Реферальная комиссия'); ?></a>
                </td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>

<!-- User info modal -->
<div class="modal fade" id="userInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="userInfoTitle">—</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="userInfoBody">—</div>
      </div>
      <div class="modal-footer">
        <a href="#" id="userInfoReferralEdit" class="btn btn-outline-info" target="_blank"><?php echo __('Редактировать рефералку'); ?></a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
      </div>
    </div>
  </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const usersSection = document.getElementById('users-section');
    if (!usersSection) { return; }
    const focusUser = usersSection.getAttribute('data-focus-user');
    if (focusUser) {
        const targetRow = usersSection.querySelector('[data-user-id="' + focusUser + '"]');
        if (targetRow) {
            targetRow.classList.add('table-warning');
            try { targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(e) {}
            window.setTimeout(function(){ targetRow.classList.remove('table-warning'); }, 4000);
        }
    }

    // Info modal
    const infoButtons = Array.from(usersSection.querySelectorAll('[data-user-info]'));
    const modalEl = document.getElementById('userInfoModal');
    // Ensure modal is attached to <body> to avoid z-index/stacking issues under overlays
    if (modalEl && modalEl.parentElement !== document.body) {
        document.body.appendChild(modalEl);
    }
    let modalInstance = null;
    function ensureModal(){ if (!modalInstance) { modalInstance = new bootstrap.Modal(modalEl); } return modalInstance; }
    function fmtCurrency(v){ return new Intl.NumberFormat(undefined, { style: 'currency', currency: '<?php echo get_currency_code(); ?>'}).format(v); }
    infoButtons.forEach(function(btn){
        btn.addEventListener('click', function(){
            const row = btn.closest('tr');
            const id = row ? row.getAttribute('data-user-id') : btn.getAttribute('data-user-info');
            const username = row ? row.querySelector('.fw-semibold')?.textContent?.trim() : ('#'+id);
            const pct = row ? (row.dataset.refPercent || '0') : '0';
            // Build minimal body; enrich via fetch later if needed
            const body = document.getElementById('userInfoBody');
            const title = document.getElementById('userInfoTitle');
            const editLink = document.getElementById('userInfoReferralEdit');
            title.textContent = username + ' (#' + id + ')';
            const refLink = <?php echo json_encode(PP_BASE_URL, JSON_UNESCAPED_UNICODE); ?> + '/?ref=' + (row?.dataset.refCode || '');
            body.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card h-100"><div class="card-body">
                            <div class="text-muted small"><?php echo __('Реферальная комиссия'); ?></div>
                            <div class="fs-4 fw-semibold">${pct}%</div>
                            <div class="text-muted small mt-2"><?php echo __('Реферальная ссылка'); ?></div>
                            <div class="input-group"><input class="form-control" value="${refLink}" readonly><button class="btn btn-outline-secondary" type="button" data-copy="ref"><?php echo __('Копировать'); ?></button></div>
                        </div></div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100"><div class="card-body">
                            <div class="text-muted small"><?php echo __('Баланс'); ?></div>
                            <div class="fs-4 fw-semibold">${row?.querySelector('td:nth-child(5)')?.textContent?.trim() || ''}</div>
                            <div class="text-muted small mt-2"><?php echo __('Проекты'); ?></div>
                            <div class="fs-6">${row?.querySelector('.badge.bg-secondary')?.textContent?.trim() || '0'}</div>
                        </div></div>
                    </div>
                </div>
            `;
            editLink.href = 'edit_referral.php?user_id=' + encodeURIComponent(id);
            ensureModal().show();
            const copyBtn = body.querySelector('[data-copy="ref"]');
            if (copyBtn) {
                copyBtn.addEventListener('click', function(){
                    const input = copyBtn.closest('.input-group').querySelector('input');
                    input.select(); input.setSelectionRange(0, 99999); try { navigator.clipboard.writeText(input.value); } catch(e) {}
                });
            }
        });
    });
});
</script>
