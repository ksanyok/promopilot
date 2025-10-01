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
        static $status = null;
        if (is_array($status)) {
            return $status;
        }
        $current = get_version();
        $latest = $current;
        $error = '';
        $url = 'https://raw.githubusercontent.com/ksanyok/promopilot/main/config/version.php';
        $ua = 'PromoPilot/UpdateChecker (+https://github.com/ksanyok/promopilot)';
        $response = '';

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_USERAGENT => $ua,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => ['Accept: text/plain'],
                ]);
                $response = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($code < 200 || $code >= 300) {
                    $response = '';
                    $error = 'HTTP ' . $code;
                }
                curl_close($ch);
            } else {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 8,
                        'ignore_errors' => true,
                        'header' => [
                            'User-Agent: ' . $ua,
                            'Accept: text/plain',
                        ],
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ]);
                $response = @file_get_contents($url, false, $context);
                if ($response === false) {
                    $response = '';
                    $error = 'FETCH_FAILED';
                }
            }
        } catch (Throwable $e) {
            $response = '';
            $error = $e->getMessage();
        }

        if ($response !== '' && preg_match('~\$version\s*=\s*([\'\"])([^\'\"]+)\1\s*;~', (string)$response, $m)) {
            $remote = trim($m[2]);
            if ($remote !== '') {
                $latest = $remote;
            }
        }

        $isNew = version_compare($latest, $current, '>');

        $status = [
            'current' => $current,
            'latest' => $latest,
            'is_new' => $isNew,
            'error' => $error,
        ];
        return $status;
    }
}

?>
