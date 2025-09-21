<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

// Directories to ignore during scan
$ignoreDirs = ['.git', 'vendor', 'node_modules', 'assets', 'lang', 'config'];

function pp_should_ignore(string $path, array $ignoreDirs): bool {
    foreach ($ignoreDirs as $d) {
        if (preg_match('~/' . preg_quote($d, '~') . '(/|$)~', $path)) return true;
    }
    return false;
}

function scan_for_i18n(string $root, array $ignoreDirs): array {
    $strings = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') continue;
        $path = str_replace('\\', '/', $file->getPathname());
        if (pp_should_ignore($path, $ignoreDirs)) continue;
        $content = @file_get_contents($path);
        if ($content === false) continue;
        // Match __("...") or __('...')
        if (preg_match_all('/__\(\s*([\"\'])((?:\\.|(?!\1).)*)\1\s*\)/u', $content, $m)) {
            foreach ($m[2] as $raw) {
                $str = stripcslashes($raw);
                if ($str !== '') $strings[$str] = true;
            }
        }
    }
    return array_keys($strings);
}

// Load existing English translations (if any) without affecting current translation state
$existing = [];
$existingLangPath = PP_ROOT_PATH . '/lang/en.php';
$_lang_backup = $lang ?? null;
if (file_exists($existingLangPath)) {
    include $existingLangPath; // defines $lang
    if (isset($lang) && is_array($lang)) {
        $existing = $lang;
    }
}
if (array_key_exists('lang', get_defined_vars())) {
    $lang = $_lang_backup; // restore
}

$found = scan_for_i18n(PP_ROOT_PATH, $ignoreDirs);
sort($found, SORT_NATURAL | SORT_FLAG_CASE);

$existingKeys = array_keys($existing);
$newKeys = array_values(array_diff($found, $existingKeys));
$unusedKeys = array_values(array_diff($existingKeys, $found));

// Build full regenerated content for en.php
$build_full = function(array $foundKeys, array $existingMap): string {
    ob_start();
    echo "<?php\n$" . "lang = [\n";
    foreach ($foundKeys as $key) {
        $val = $existingMap[$key] ?? $key;
        echo "    '" . addslashes($key) . "' => '" . addslashes($val) . "',\n";
    }
    echo "];\n?>";
    return ob_get_clean();
};

// Build append-only (preserve existing order) content
$build_append_only = function(array $existingMap, array $newKeys): string {
    ob_start();
    echo "<?php\n$" . "lang = [\n";
    foreach ($existingMap as $k => $v) {
        echo "    '" . addslashes($k) . "' => '" . addslashes($v) . "',\n";
    }
    foreach ($newKeys as $k) {
        echo "    '" . addslashes($k) . "' => '" . addslashes($k) . "',\n";
    }
    echo "];\n?>";
    return ob_get_clean();
};

$generated_full = $build_full($found, $existing);
$generated_append = $build_append_only($existing, $newKeys);

$message = '';
$messageClass = 'info';
$useAppendOnly = (($_POST['mode'] ?? '') === 'append');

// Handle actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && verify_csrf()) {
    $payload = $useAppendOnly ? $generated_append : $generated_full;
    if (isset($_POST['download'])) {
        header('Content-Type: application/x-php');
        header('Content-Disposition: attachment; filename="en.php"');
        header('Content-Length: ' . strlen($payload));
        echo $payload;
        exit;
    }
    if (isset($_POST['save'])) {
        $target = $existingLangPath;
        $dir = dirname($target);
        if (!is_dir($dir) || !is_writable($dir)) {
            $message = __('Недостаточно прав для записи.');
            $messageClass = 'danger';
        } else {
            $backup = '';
            if (file_exists($target) && is_writable($dir)) {
                $backup = $target . '.bak.' . date('YmdHis');
                @copy($target, $backup);
            }
            $ok = @file_put_contents($target, $payload, LOCK_EX) !== false;
            if ($ok) {
                $message = __('Файл сохранен.');
                if ($backup) {
                    $message .= ' ' . __('Создана резервная копия:') . ' ' . basename($backup);
                }
                $messageClass = 'success';
            } else {
                $message = __('Не удалось сохранить файл.');
                $messageClass = 'danger';
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><?php echo __('Сканер локализации'); ?></h4>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-secondary"><?php echo __('Найдено строк'); ?>: <?php echo count($found); ?></span>
                    <span class="badge bg-success"><?php echo __('Новые'); ?>: <?php echo count($newKeys); ?></span>
                    <span class="badge bg-warning text-dark"><?php echo __('Неиспользуемые'); ?>: <?php echo count($unusedKeys); ?></span>
                </div>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($messageClass); ?> mb-3"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <details class="mb-3">
                    <summary class="fw-semibold"><?php echo __('Новые ключи'); ?> (<?php echo count($newKeys); ?>)</summary>
                    <?php if ($newKeys): ?>
                        <ul class="mt-2">
                            <?php foreach ($newKeys as $k): ?>
                                <li><code><?php echo htmlspecialchars($k); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-muted mt-2"><?php echo __('Нет новых ключей.'); ?></div>
                    <?php endif; ?>
                </details>

                <details class="mb-3">
                    <summary class="fw-semibold"><?php echo __('Неиспользуемые ключи'); ?> (<?php echo count($unusedKeys); ?>)</summary>
                    <?php if ($unusedKeys): ?>
                        <ul class="mt-2">
                            <?php foreach ($unusedKeys as $k): ?>
                                <li><code><?php echo htmlspecialchars($k); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-muted mt-2"><?php echo __('Нет неиспользуемых ключей.'); ?></div>
                    <?php endif; ?>
                </details>

                <p class="text-muted mb-2"><?php echo __('Сгенерированные строки локализации'); ?> (en.php):</p>
                <form method="post" class="mb-3 d-flex gap-2 flex-wrap align-items-center">
                    <?php echo csrf_field(); ?>
                    <div class="form-check me-2">
                        <input class="form-check-input" type="radio" name="mode" id="modeAppend" value="append" <?php echo $useAppendOnly ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="modeAppend"><?php echo __('Только добавление (сохранить порядок)'); ?></label>
                    </div>
                    <div class="form-check me-2">
                        <input class="form-check-input" type="radio" name="mode" id="modeFull" value="full" <?php echo !$useAppendOnly ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="modeFull"><?php echo __('Полная регенерация (перестроить список)'); ?></label>
                    </div>
                    <span class="text-muted small ms-auto"><?php echo __('Целевой файл'); ?>: <code>lang/en.php</code></span>
                </form>

                <div class="mb-3">
                    <label class="form-label"><?php echo __('Предпросмотр'); ?></label>
                    <textarea class="form-control" rows="18" readonly><?php echo htmlspecialchars($useAppendOnly ? $generated_append : $generated_full); ?></textarea>
                </div>

                <form method="post" class="d-flex gap-2 flex-wrap">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="mode" value="<?php echo $useAppendOnly ? 'append' : 'full'; ?>">
                    <button type="submit" name="save" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить файл'); ?></button>
                    <button type="submit" name="download" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i><?php echo __('Скачать'); ?></button>
                    <span class="text-muted small ms-auto"><?php echo __('Путь'); ?>: <code><?php echo htmlspecialchars($existingLangPath); ?></code></span>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>