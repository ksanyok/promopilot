<?php
$version = '0.0.0';
$verPhpPath = dirname(__DIR__) . '/version.php';
if (file_exists($verPhpPath)) {
    $v = @include $verPhpPath; // version.php должен return 'x.y.z'
    if (is_string($v) && $v !== '') { $version = trim($v); }
}

$updateAvailable = false;
$remoteVersion = null;
$repoOwner = 'ksanyok';
$repoName  = 'promopilot';
$branch    = 'main';

$fetch = function($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'PromoPilot-Footer'
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $data !== false) return $data;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: PromoPilot-Footer\r\n"]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data !== false) return $data;
    }
    return false;
};

$cmp = function($a, $b) {
    $aParts = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string)$a)));
    $bParts = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string)$b)));
    $max = max(count($aParts), count($bParts));
    for ($i = 0; $i < $max; $i++) {
        $ai = $aParts[$i] ?? 0;
        $bi = $bParts[$i] ?? 0;
        if ($ai < $bi) return -1;
        if ($ai > $bi) return 1;
    }
    return 0;
};

// Получаем удаленную версию только из version.php
$remotePhp = $fetch("https://raw.githubusercontent.com/$repoOwner/$repoName/$branch/version.php");
if ($remotePhp !== false && preg_match('/return\s*[\'\"]([^\'\"]+)[\'\"];?/m', $remotePhp, $m)) {
    $remoteVersion = trim($m[1]);
}

if ($remoteVersion && $cmp($remoteVersion, $version) > 0) {
    $updateAvailable = true;
}
?>
<footer class="app-footer">
    <div>
        Разработчик: <a href="https://buyreadysite.com" target="_blank" rel="noopener" style="color:#0f62fe; text-decoration:none;">Buyreadysite.com</a>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        <span>Версия: <strong><?php echo htmlspecialchars($version); ?></strong></span>
        <?php if ($remoteVersion): ?>
            <span style="color:#666">Репозиторий: <?php echo htmlspecialchars($remoteVersion); ?></span>
        <?php endif; ?>
        <?php if ($updateAvailable): ?>
            <a href="/installer.php?action=update" class="btn btn-update">Обновить</a>
        <?php endif; ?>
    </div>
</footer>
