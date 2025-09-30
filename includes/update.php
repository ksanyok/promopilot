<?php
// Version and update helpers

if (!function_exists('get_version')) {
    function get_version(): string {
        static $v = null; if ($v !== null) return $v; $v = '0.0.0';
        $file = PP_ROOT_PATH . '/config/version.php';
        if (is_file($file) && is_readable($file)) {
            try { $version = null; include $file; if (isset($version) && is_string($version) && $version !== '') { $v = trim($version); } } catch (Throwable $e) { /* ignore */ }
        }
        return $v;
    }
}

if (!function_exists('get_update_status')) {
    function get_update_status(): array {
        $current = get_version(); $cacheDir = PP_ROOT_PATH . '/.cache'; if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
        $cacheFile = $cacheDir . '/update_status.json'; $now = time(); $cacheTtl = 6 * 3600;
        $cached = null; if (is_file($cacheFile) && is_readable($cacheFile)) { $raw = @file_get_contents($cacheFile); $data = $raw ? json_decode($raw, true) : null; if (is_array($data) && isset($data['fetched_at']) && ($now - (int)$data['fetched_at'] < $cacheTtl)) { $cached = $data; } }
        if ($cached) { $latest = (string)($cached['latest'] ?? $current); $publishedAt = (string)($cached['published_at'] ?? ''); $isNew = version_compare($latest, $current, '>'); return ['current' => $current, 'latest' => $latest, 'published_at' => $publishedAt, 'is_new' => $isNew, 'source' => 'cache']; }
        $latest = $current; $publishedAt = ''; $ok = false; $err = ''; $source = ''; $url = 'https://api.github.com/repos/ksanyok/promopilot/releases/latest'; $ua = 'PromoPilot/UpdateChecker (+https://github.com/ksanyok/promopilot)'; $resp = '';
        if (function_exists('curl_init')) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],]); $resp = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if ($resp !== '' && $code >= 200 && $code < 300) { $ok = true; } else { $err = 'HTTP ' . $code; } curl_close($ch); }
        else { $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true, 'header' => ['User-Agent: ' . $ua, 'Accept: application/vnd.github+json',],], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],]); $resp = @file_get_contents($url, false, $ctx); $ok = $resp !== false && $resp !== ''; }
        if ($ok) { $json = json_decode($resp, true); if (is_array($json)) { $tag = (string)($json['tag_name'] ?? ''); $name = (string)($json['name'] ?? ''); $publishedAt = (string)($json['published_at'] ?? ''); $tag = ltrim($tag, 'vV'); $name = ltrim($name, 'vV'); $candidate = $tag ?: $name; if ($candidate !== '') { $latest = $candidate; $source = 'releases'; } } }
        if (!$ok || !version_compare($latest, $current, '>')) {
            $rawUrl = 'https://raw.githubusercontent.com/ksanyok/promopilot/main/config/version.php'; $rawResp = ''; $rawOk = false; $rawErr = '';
            if (function_exists('curl_init')) { $ch = curl_init($rawUrl); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: text/plain'],]); $rawResp = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if ($rawResp !== '' && $code >= 200 && $code < 300) { $rawOk = true; } else { $rawErr = 'HTTP ' . $code; } curl_close($ch); }
            else { $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true, 'header' => ['User-Agent: ' . $ua, 'Accept: text/plain',],], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],]); $rawResp = @file_get_contents($rawUrl, false, $ctx); $rawOk = $rawResp !== false && $rawResp !== ''; }
            if ($rawOk) { if (preg_match('~\\$version\\s*=\\s*([\'\"][^\'\"]+)\1\\s*;~', (string)$rawResp, $m)) { $remoteVer = trim($m[2]); if ($remoteVer !== '' && version_compare($remoteVer, $latest, '>')) { $latest = $remoteVer; $source = 'raw'; } } }
            else { if ($err === '' && $rawErr !== '') { $err = $rawErr; } }
        }
        $payload = ['fetched_at' => $now, 'latest' => $latest, 'published_at' => $publishedAt, 'ok' => ($latest !== $current), 'error' => ($latest !== $current) ? '' : $err, 'source' => $source ?: 'remote',];
        @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return ['current' => $current, 'latest' => $latest, 'published_at' => $publishedAt, 'is_new' => version_compare($latest, $current, '>'), 'source' => $source ?: 'remote', ];
    }
}

?>
