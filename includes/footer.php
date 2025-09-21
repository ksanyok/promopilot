<?php
$version = '0.0.0';
$verPath = dirname(__DIR__) . '/version.txt';
if (file_exists($verPath)) {
    $v = trim((string)@file_get_contents($verPath));
    if ($v !== '') $version = $v;
}

// Проверка обновления (легкая логика, с таймаутами)
$updateAvailable = false;
$remoteVersion = null;
$repoOwner = 'ksanyok';
$repoName  = 'promopilot';
$branch    = 'main';
$remoteUrl = "https://raw.githubusercontent.com/$repoOwner/$repoName/$branch/version.txt";

$fetch = function($url) {
    // Пытаемся через cURL
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
    // Фолбэк на file_get_contents
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

$remoteData = $fetch($remoteUrl);
if ($remoteData !== false) {
    $remoteVersion = trim($remoteData);
    if ($remoteVersion && $cmp($remoteVersion, $version) > 0) {
        $updateAvailable = true;
    }
}
?>
<footer style="margin-top:24px; padding:16px 24px; background:#fafafa; border-top:1px solid #eee; color:#444; font-size:13px; display:flex; justify-content:space-between; align-items:center;">
    <div>
        Разработчик: <a href="https://buyreadysite.com" target="_blank" rel="noopener" style="color:#0f62fe; text-decoration:none;">Buyreadysite.com</a>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        <span>Версия: <strong><?php echo htmlspecialchars($version); ?></strong></span>
        <?php if ($remoteVersion): ?>
            <span style="color:#666">Репозиторий: <?php echo htmlspecialchars($remoteVersion); ?></span>
        <?php endif; ?>
        <?php if ($updateAvailable): ?>
            <a href="/installer.php?action=update" style="background:#0f62fe; color:#fff; padding:6px 10px; border-radius:6px; font-weight:600; text-decoration:none;">Обновить</a>
        <?php endif; ?>
    </div>
</footer>
