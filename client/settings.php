<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) { redirect('auth/login.php'); }

$uid = (int)($_SESSION['user_id'] ?? 0);
$conn = connect_db();

// Fetch current user data
$stmt = $conn->prepare("SELECT id, username, full_name, email, phone, avatar, newsletter_opt_in, password FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { $stmt->close(); $conn->close(); redirect('auth/login.php'); }
$user = $res->fetch_assoc();
$stmt->close();

$notificationPrefs = pp_notification_get_user_settings($uid);
$notificationCatalog = pp_notification_event_catalog();
$notificationCategories = pp_notification_event_categories();

$errors = [];
$success = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'profile') {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));
        $phone     = trim((string)($_POST['phone'] ?? ''));
        $news_opt  = isset($_POST['newsletter_opt_in']) ? 1 : 0;

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Некорректный e-mail.');
        }
        if ($phone !== '') {
            $normPhone = preg_replace('~[\s\-()]+~', '', $phone);
            if (!preg_match('~^\+?[0-9]{7,15}$~', $normPhone)) {
                $errors[] = __('Некорректный номер телефона.');
            } else {
                $phone = $normPhone; // store normalized
            }
        }

        // Handle avatar upload
        $avatarPath = (string)$user['avatar'];
        if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)($_FILES['avatar']['error'] ?? UPLOAD_ERR_OK);
            if ($err === UPLOAD_ERR_OK) {
                $tmp = $_FILES['avatar']['tmp_name'];
                $name = (string)($_FILES['avatar']['name'] ?? '');
                $size = (int)($_FILES['avatar']['size'] ?? 0);
                if ($size > 2 * 1024 * 1024) { // 2MB limit
                    $errors[] = __('Слишком большой файл аватара (макс. 2 МБ).');
                } else {
                    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? @finfo_file($finfo, $tmp) : '';
                    if ($finfo) { @finfo_close($finfo); }
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!isset($allowed[$mime])) {
                        $errors[] = __('Недопустимый формат аватара. Разрешены: JPG, PNG, WEBP.');
                    } else {
                        $ext = $allowed[$mime];
                        $dir = PP_ROOT_PATH . '/uploads/avatars';
                        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                        $basename = 'u' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $dest = $dir . '/' . $basename;
                        if (@move_uploaded_file($tmp, $dest)) {
                            // Remove old file if inside avatars dir
                            if ($avatarPath && substr((string)$avatarPath, 0, 16) === 'uploads/avatars/') {
                                $old = PP_ROOT_PATH . '/' . $avatarPath;
                                if (is_file($old)) { @unlink($old); }
                            }
                            $avatarPath = 'uploads/avatars/' . $basename;
                        } else {
                            $errors[] = __('Не удалось сохранить аватар.');
                        }
                    }
                }
            } else {
                $errors[] = __('Ошибка загрузки файла.');
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, avatar = ?, newsletter_opt_in = ? WHERE id = ?");
            $stmt->bind_param('ssssii', $full_name, $email, $phone, $avatarPath, $news_opt, $uid);
            if ($stmt->execute()) {
                $success = __('Профиль обновлён.');
                $user['full_name'] = $full_name;
                $user['email'] = $email;
                $user['phone'] = $phone;
                $user['avatar'] = $avatarPath;
                $user['newsletter_opt_in'] = $news_opt;
            } else {
                $errors[] = __('Ошибка обновления профиля.');
            }
            $stmt->close();
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($new === '' || strlen($new) < 6) {
            $errors[] = __('Новый пароль слишком короткий (мин. 6 символов).');
        }
        if ($new !== $confirm) {
            $errors[] = __('Пароли не совпадают.');
        }
        if (!password_verify($current, (string)$user['password'])) {
            $errors[] = __('Текущий пароль неверный.');
        }
        if (empty($errors)) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $uid);
            if ($stmt->execute()) {
                $success = __('Пароль обновлён.');
            } else {
                $errors[] = __('Не удалось обновить пароль.');
            }
            $stmt->close();
        }
  } elseif ($action === 'notifications') {
    $selected = $_POST['notifications'] ?? [];
    if (!is_array($selected)) {
      $selected = [];
    }
    $selected = array_map(static fn($value) => trim((string)$value), $selected);
    $selected = array_values(array_filter($selected, static fn($value) => $value !== ''));
    $payload = [];
    foreach ($notificationCatalog as $key => $info) {
      $payload[$key] = in_array($key, $selected, true);
    }
    if (!pp_notification_update_user_settings($uid, $payload)) {
      $errors[] = __('Не удалось обновить настройки уведомлений. Попробуйте позже.');
    } else {
      $success = __('Настройки уведомлений обновлены.');
      $notificationPrefs = pp_notification_get_user_settings($uid);
    }
    }
}

$conn->close();

// UI flags
$pp_container = false;
$GLOBALS['pp_layout_has_sidebar'] = true;

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/client_sidebar.php';
?>

<div class="main-content fade-in">
  <!-- Header -->
  <div class="card section project-hero mb-3">
    <div class="card-body d-flex align-items-center justify-content-between gap-3">
      <div>
        <div class="title mb-1"><?php echo __('Настройки аккаунта'); ?></div>
        <div class="help"><?php echo htmlspecialchars($user['username']); ?></div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="<?php echo pp_url('client/client.php'); ?>" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i><span class="btn-text"><?php echo __('К дашборду'); ?></span></a>
      </div>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <!-- Profile section -->
  <div class="card section mb-3">
    <div class="section-header">
      <div class="label"><i class="bi bi-person-circle"></i><span><?php echo __('Профиль'); ?></span></div>
      <div class="toolbar"></div>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="action" value="profile">
        <div class="col-12 col-md-4">
          <label class="form-label"><?php echo __('ФИО'); ?></label>
          <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars((string)$user['full_name']); ?>" placeholder="<?php echo __('Иванов Иван Иванович'); ?>">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars((string)$user['email']); ?>" placeholder="you@example.com">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label"><?php echo __('Телефон'); ?></label>
          <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars((string)$user['phone']); ?>" placeholder="+380...">
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label"><?php echo __('Аватар'); ?></label>
          <div class="d-flex align-items-center gap-3">
            <?php $avatarUrl = !empty($user['avatar']) ? pp_url($user['avatar']) : asset_url('img/logo.png'); ?>
            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="avatar" class="avatar-preview">
            <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="form-control">
          </div>
          <div class="form-text"><?php echo __('JPG/PNG/WEBP, до 2 МБ'); ?></div>
        </div>
        <div class="col-12 col-md-6 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="newsletter" name="newsletter_opt_in" <?php echo ((int)$user['newsletter_opt_in'] === 1 ? 'checked' : ''); ?>>
            <label class="form-check-label" for="newsletter"><?php echo __('Получать рассылку и обновления'); ?></label>
          </div>
        </div>

        <div class="col-12 text-end">
          <button type="submit" class="btn btn-gradient"><i class="bi bi-save me-1"></i><span class="btn-text"><?php echo __('Сохранить'); ?></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Notifications section -->
  <?php
    $groupedNotifications = [];
    foreach ($notificationCatalog as $eventKey => $info) {
        $category = $info['category'] ?? 'other';
        $groupedNotifications[$category][$eventKey] = $info;
    }
  ?>
  <div class="card section mb-3" id="notifications-settings">
    <div class="section-header">
      <div class="label"><i class="bi bi-bell"></i><span><?php echo __('Уведомления'); ?></span></div>
      <div class="toolbar"></div>
    </div>
    <div class="card-body">
      <form method="post" class="d-flex flex-column gap-4">
        <input type="hidden" name="action" value="notifications">
        <?php foreach ($groupedNotifications as $categoryKey => $notices): ?>
          <?php if (empty($notices)) { continue; } ?>
          <?php $categoryLabel = $notificationCategories[$categoryKey] ?? ($notificationCategories['other'] ?? __('Прочее')); ?>
          <div>
            <div class="text-uppercase text-muted small fw-semibold mb-2"><?php echo htmlspecialchars($categoryLabel); ?></div>
            <div class="row g-3">
              <?php foreach ($notices as $eventKey => $info): ?>
                <?php $inputId = 'notif-' . preg_replace('~[^a-z0-9_-]+~i', '-', $eventKey); ?>
                <?php $isEnabled = !empty($notificationPrefs[$eventKey]); ?>
                <div class="col-12 col-md-6">
                  <div class="form-check form-switch bg-dark-subtle bg-opacity-50 border border-dark-subtle rounded-4 h-100 p-3 shadow-sm">
                    <input class="form-check-input" type="checkbox" role="switch" id="<?php echo htmlspecialchars($inputId); ?>" name="notifications[]" value="<?php echo htmlspecialchars($eventKey); ?>" <?php echo $isEnabled ? 'checked' : ''; ?>>
                    <label class="form-check-label ms-2" for="<?php echo htmlspecialchars($inputId); ?>">
                      <span class="fw-semibold d-block mb-1"><?php echo htmlspecialchars((string)($info['label'] ?? '')); ?></span>
                      <?php if (!empty($info['description'])): ?>
                        <span class="text-muted small d-block"><?php echo htmlspecialchars((string)$info['description']); ?></span>
                      <?php endif; ?>
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="text-end">
          <button type="submit" class="btn btn-outline-light"><i class="bi bi-save me-1"></i><span class="btn-text"><?php echo __('Сохранить уведомления'); ?></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Security section -->
  <div class="card section">
    <div class="section-header">
      <div class="label"><i class="bi bi-shield-lock"></i><span><?php echo __('Безопасность'); ?></span></div>
      <div class="toolbar"></div>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="password">
        <div class="col-12 col-md-4">
          <label class="form-label"><?php echo __('Текущий пароль'); ?></label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label"><?php echo __('Новый пароль'); ?></label>
          <input type="password" name="new_password" class="form-control" required>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label"><?php echo __('Подтверждение'); ?></label>
          <input type="password" name="confirm_password" class="form-control" required>
        </div>
        <div class="col-12 text-end">
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-key me-1"></i><span class="btn-text"><?php echo __('Обновить пароль'); ?></span></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>