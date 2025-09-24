<?php
require_once __DIR__ . '/../includes/init.php';
if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }

$updateStatus = get_update_status();
$msg = '';

// Collect diagnostics
function pp_collect_diagnostics(): array {
    $disabled = strtolower((string)ini_get('disable_functions'));
    $isDisabled = function(string $fn) use ($disabled): bool {
        return (strpos($disabled, $fn) !== false) || !function_exists($fn);
    };

    $canShell = !$isDisabled('shell_exec');
    $canProc = !$isDisabled('proc_open');

    $php = [
        'version' => PHP_VERSION,
        'os' => PHP_OS,
        'sapi' => php_sapi_name(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'disable_functions' => $disabled,
        'extensions' => [
            'mysqli' => extension_loaded('mysqli'),
            'mbstring' => extension_loaded('mbstring'),
            'json' => extension_loaded('json'),
            'curl' => extension_loaded('curl'),
            'openssl' => extension_loaded('openssl'),
            'zip' => extension_loaded('zip'),
        ],
        'can_shell_exec' => $canShell,
        'can_proc_open' => $canProc,
    ];

    $paths = [
        'root' => PP_ROOT_PATH,
        'vendor' => PP_ROOT_PATH . '/vendor',
        'node_runtime' => PP_ROOT_PATH . '/node_runtime',
        'node_modules' => PP_ROOT_PATH . '/node_runtime/node_modules',
        'logs' => PP_ROOT_PATH . '/logs',
    ];

    $perms = [
        'root_writable' => is_writable(PP_ROOT_PATH),
        'node_runtime_exists' => is_dir($paths['node_runtime']),
        'node_modules_exists' => is_dir($paths['node_modules']),
        'vendor_exists' => is_dir($paths['vendor']),
        'logs_exists' => is_dir($paths['logs']),
        'logs_writable' => is_dir($paths['logs']) ? is_writable($paths['logs']) : is_writable(PP_ROOT_PATH),
    ];

    $nodeBin = function_exists('pp_resolve_node_binary') ? pp_resolve_node_binary() : '';
    $npmBin = function_exists('pp_resolve_npm_binary') ? pp_resolve_npm_binary() : '';
    $chromeBin = function_exists('pp_resolve_chrome_binary') ? pp_resolve_chrome_binary() : '';

    $nodeVersion = '';
    $npmVersion = '';
    $chromeVersion = '';

    if ($canShell && $nodeBin) { $nodeVersion = trim((string)@shell_exec(escapeshellarg($nodeBin) . ' --version 2>&1')); }
    if ($canShell && $npmBin) { $npmVersion = trim((string)@shell_exec(escapeshellarg($npmBin) . ' --version 2>&1')); }
    if ($canShell && $chromeBin) { $chromeVersion = trim((string)@shell_exec(escapeshellarg($chromeBin) . ' --version 2>&1')); }

    // Puppeteer presence in node_modules
    $puppeteerInstalled = is_dir($paths['node_modules'] . '/puppeteer') || is_dir($paths['node_modules'] . '/puppeteer-core');

    // Try require('puppeteer') with Node
    $puppeteerRequire = [ 'code' => null, 'stdout' => '', 'stderr' => '' ];
    if (function_exists('pp_run_puppeteer')) {
        $js = "try { require('puppeteer'); console.log('PUPPETEER_OK'); process.exit(0); } catch (e) { console.error('PUPPETEER_ERR:' + (e && e.message ? e.message : e)); process.exit(2); }";
        [$code, $out, $err] = pp_run_puppeteer($js, [], 20);
        $puppeteerRequire = ['code' => $code, 'stdout' => trim((string)$out), 'stderr' => trim((string)$err)];
    }

    return [
        'timestamp' => date('c'),
        'php' => $php,
        'paths' => $paths,
        'permissions' => $perms,
        'node' => [ 'bin' => $nodeBin, 'version' => $nodeVersion ],
        'npm' => [ 'bin' => $npmBin, 'version' => $npmVersion ],
        'chrome' => [ 'bin' => $chromeBin, 'version' => $chromeVersion ],
        'puppeteer' => [ 'installed' => $puppeteerInstalled, 'require_check' => $puppeteerRequire ],
    ];
}

$diag = pp_collect_diagnostics();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    if (!verify_csrf()) {
        $msg = __('Ошибка сохранения отчёта.') . ' (CSRF)';
    } else {
        $logsDir = PP_ROOT_PATH . '/logs';
        if (!is_dir($logsDir)) { @mkdir($logsDir, 0777, true); }
        $fname = $logsDir . '/system_diag_' . date('Ymd_His') . '.txt';
        $jsonName = $logsDir . '/system_diag_' . date('Ymd_His') . '.json';
        $text = [];
        $text[] = 'PromoPilot System Diagnostics';
        $text[] = 'Time: ' . $diag['timestamp'];
        $text[] = '';
        $text[] = 'PHP';
        $text[] = '  Version: ' . $diag['php']['version'];
        $text[] = '  OS: ' . $diag['php']['os'] . ' (' . $diag['php']['sapi'] . ')';
        $text[] = '  memory_limit: ' . $diag['php']['memory_limit'];
        $text[] = '  max_execution_time: ' . $diag['php']['max_execution_time'];
        $text[] = '  disable_functions: ' . $diag['php']['disable_functions'];
        $text[] = '  Extensions:';
        foreach ($diag['php']['extensions'] as $k => $v) { $text[] = '    - ' . $k . ': ' . ($v ? 'OK' : 'MISS'); }
        $text[] = '';
        $text[] = 'Paths';
        foreach ($diag['paths'] as $k => $v) { $text[] = '  ' . $k . ': ' . $v; }
        $text[] = '';
        $text[] = 'Permissions';
        foreach ($diag['permissions'] as $k => $v) { $text[] = '  ' . $k . ': ' . ($v ? 'YES' : 'NO'); }
        $text[] = '';
        $text[] = 'Node.js';
        $text[] = '  bin: ' . ($diag['node']['bin'] ?: '(not found)');
        $text[] = '  version: ' . ($diag['node']['version'] ?: '(n/a)');
        $text[] = 'npm';
        $text[] = '  bin: ' . ($diag['npm']['bin'] ?: '(not found)');
        $text[] = '  version: ' . ($diag['npm']['version'] ?: '(n/a)');
        $text[] = 'Chrome/Chromium';
        $text[] = '  bin: ' . ($diag['chrome']['bin'] ?: '(not found)');
        $text[] = '  version: ' . ($diag['chrome']['version'] ?: '(n/a)');
        $text[] = 'Puppeteer';
        $text[] = '  installed: ' . ($diag['puppeteer']['installed'] ? 'YES' : 'NO');
        $text[] = '  require_check: code=' . var_export($diag['puppeteer']['require_check']['code'], true) . ' stdout=' . $diag['puppeteer']['require_check']['stdout'] . ' stderr=' . $diag['puppeteer']['require_check']['stderr'];
        $okTxt = @file_put_contents($fname, implode("\n", $text));
        $okJson = @file_put_contents($jsonName, json_encode($diag, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        if ($okTxt !== false) { $msg = __('Отчёт сохранён: ') . basename($fname); }
        else { $msg = __('Не удалось сохранить отчёт.'); }
    }
}

include '../includes/header.php';
?>
<div class="sidebar">
  <div class="menu-block">
    <div class="menu-title"><?php echo __('Меню'); ?></div>
    <ul class="menu-list">
      <li><a href="<?php echo pp_url('admin/admin.php'); ?>" class="menu-item"><?php echo __('Обзор'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/users.php'); ?>" class="menu-item"><?php echo __('Пользователи'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/projects.php'); ?>" class="menu-item"><?php echo __('Проекты'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/settings.php'); ?>" class="menu-item"><?php echo __('Основные настройки'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/networks.php'); ?>" class="menu-item"><?php echo __('Сети автопостинга'); ?></a></li>
      <?php if ($updateStatus['is_new']): ?><li><a href="<?php echo pp_url('public/update.php'); ?>" class="menu-item"><?php echo __('Обновление'); ?></a></li><?php endif; ?>
      <li><a href="<?php echo pp_url('admin/diagnostics.php'); ?>" class="menu-item active"><?php echo __('Диагностика систем'); ?></a></li>
    </ul>
  </div>
</div>
<div class="main-content">
  <h2><?php echo __('Диагностика систем'); ?></h2>
  <?php if ($msg): ?><div class="alert alert-info fade-in"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

  <div class="card p-3 mb-3">
    <div class="h5 mb-2"><?php echo __('Сводка'); ?></div>
    <div class="row g-2">
      <div class="col-md-3"><div class="p-2 border rounded">PHP: <span class="badge bg-<?php echo version_compare(PHP_VERSION,'8.0.0','>=')?'success':'warning'; ?>"><?php echo htmlspecialchars(PHP_VERSION); ?></span></div></div>
      <div class="col-md-3"><div class="p-2 border rounded">Node: <span class="badge bg-<?php echo ($diag['node']['version']?'success':'secondary'); ?>"><?php echo htmlspecialchars($diag['node']['version'] ?: 'n/a'); ?></span></div></div>
      <div class="col-md-3"><div class="p-2 border rounded">npm: <span class="badge bg-<?php echo ($diag['npm']['version']?'success':'secondary'); ?>"><?php echo htmlspecialchars($diag['npm']['version'] ?: 'n/a'); ?></span></div></div>
      <div class="col-md-3"><div class="p-2 border rounded">Chrome: <span class="badge bg-<?php echo ($diag['chrome']['version']?'success':'secondary'); ?>"><?php echo htmlspecialchars($diag['chrome']['version'] ?: 'n/a'); ?></span></div></div>
    </div>
  </div>

  <form method="post" class="mb-3">
    <?php echo csrf_field(); ?>
    <button type="submit" name="save_report" value="1" class="btn btn-sm btn-outline-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить отчёт в logs'); ?></button>
    <span class="text-muted ms-2 small"><?php echo __('Путь'); ?>: <?php echo htmlspecialchars(PP_ROOT_PATH . '/logs'); ?></span>
  </form>

  <div class="card p-3 mb-3">
    <div class="h5 mb-2">PHP</div>
    <div class="row g-2">
      <div class="col-md-4"><div class="small text-muted">Version</div><div><?php echo htmlspecialchars($diag['php']['version']); ?></div></div>
      <div class="col-md-4"><div class="small text-muted">SAPI</div><div><?php echo htmlspecialchars($diag['php']['sapi']); ?></div></div>
      <div class="col-md-4"><div class="small text-muted">OS</div><div><?php echo htmlspecialchars($diag['php']['os']); ?></div></div>
      <div class="col-md-4"><div class="small text-muted">memory_limit</div><div><?php echo htmlspecialchars($diag['php']['memory_limit']); ?></div></div>
      <div class="col-md-4"><div class="small text-muted">max_execution_time</div><div><?php echo htmlspecialchars($diag['php']['max_execution_time']); ?></div></div>
      <div class="col-12"><div class="small text-muted">disable_functions</div><code class="d-block"><?php echo htmlspecialchars($diag['php']['disable_functions']); ?></code></div>
    </div>
    <div class="mt-2">
      <div class="small text-muted mb-1"><?php echo __('Расширения'); ?></div>
      <ul class="list-inline m-0">
        <?php foreach ($diag['php']['extensions'] as $k=>$v): ?>
          <li class="list-inline-item mb-1"><span class="badge bg-<?php echo $v?'success':'secondary'; ?>"><?php echo htmlspecialchars($k); ?></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="small text-muted mt-1">shell_exec: <span class="badge bg-<?php echo $diag['php']['can_shell_exec']?'success':'secondary'; ?>"><?php echo $diag['php']['can_shell_exec']?'YES':'NO'; ?></span> · proc_open: <span class="badge bg-<?php echo $diag['php']['can_proc_open']?'success':'secondary'; ?>"><?php echo $diag['php']['can_proc_open']?'YES':'NO'; ?></span></div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <div class="h5 mb-2">Node & npm</div>
    <div class="row g-2">
      <div class="col-md-6"><div class="small text-muted">Node bin</div><code class="d-block text-break"><?php echo htmlspecialchars($diag['node']['bin'] ?: ''); ?></code></div>
      <div class="col-md-6"><div class="small text-muted">Node version</div><div><?php echo htmlspecialchars($diag['node']['version'] ?: ''); ?></div></div>
      <div class="col-md-6"><div class="small text-muted">npm bin</div><code class="d-block text-break"><?php echo htmlspecialchars($diag['npm']['bin'] ?: ''); ?></code></div>
      <div class="col-md-6"><div class="small text-muted">npm version</div><div><?php echo htmlspecialchars($diag['npm']['version'] ?: ''); ?></div></div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <div class="h5 mb-2">Chrome/Chromium</div>
    <div class="row g-2">
      <div class="col-md-8"><div class="small text-muted">Binary</div><code class="d-block text-break"><?php echo htmlspecialchars($diag['chrome']['bin'] ?: ''); ?></code></div>
      <div class="col-md-4"><div class="small text-muted">Version</div><div><?php echo htmlspecialchars($diag['chrome']['version'] ?: ''); ?></div></div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <div class="h5 mb-2">Puppeteer</div>
    <div class="mb-2">Installed in node_modules: <span class="badge bg-<?php echo $diag['puppeteer']['installed']?'success':'secondary'; ?>"><?php echo $diag['puppeteer']['installed']?'YES':'NO'; ?></span></div>
    <div class="small text-muted">require('puppeteer') check</div>
    <div class="row g-2">
      <div class="col-md-2"><div class="small text-muted">exit code</div><div><?php echo htmlspecialchars((string)$diag['puppeteer']['require_check']['code']); ?></div></div>
      <div class="col-md-5"><div class="small text-muted">stdout</div><pre class="p-2 bg-light border rounded" style="white-space:pre-wrap;"><?php echo htmlspecialchars($diag['puppeteer']['require_check']['stdout']); ?></pre></div>
      <div class="col-md-5"><div class="small text-muted">stderr</div><pre class="p-2 bg-light border rounded" style="white-space:pre-wrap;"><?php echo htmlspecialchars($diag['puppeteer']['require_check']['stderr']); ?></pre></div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <div class="h5 mb-2"><?php echo __('Пути и права'); ?></div>
    <div class="row g-2">
      <?php foreach ($diag['paths'] as $k=>$v): ?>
        <div class="col-md-6">
          <div class="small text-muted"><?php echo htmlspecialchars($k); ?></div>
          <code class="d-block text-break"><?php echo htmlspecialchars($v); ?></code>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-2 small text-muted">
      logs writable: <span class="badge bg-<?php echo $diag['permissions']['logs_writable']?'success':'secondary'; ?>"><?php echo $diag['permissions']['logs_writable']?'YES':'NO'; ?></span>
    </div>
  </div>

  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="var d=document.getElementById('diag-raw'); d.style.display=d.style.display==='none'?'block':'none';">Raw JSON</button>
  <div id="diag-raw" style="display:none" class="mt-2">
    <pre class="p-2 bg-light border rounded" style="white-space:pre-wrap;">
<?php echo htmlspecialchars(json_encode($diag, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?>
    </pre>
  </div>
</div>
<?php include '../includes/footer.php'; ?>
