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
        $current = get_version();
        $latest = $current;
        $publishedAt = '';
        $source = '';
        $error = '';

        $ua = 'PromoPilot/UpdateChecker (+https://github.com/ksanyok/promopilot)';
        $fetchRemote = static function (string $url, array $headers = []) use ($ua): array {
            $headers = array_merge(['User-Agent: ' . $ua], $headers);
            $timeout = 8;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => $headers,
                ]);
                $body = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);
                $ok = $body !== false && $code >= 200 && $code < 300;
                return [
                    'ok' => $ok,
                    'body' => $body !== false ? (string)$body : '',
                    'status' => $code,
                    'error' => $ok ? '' : ($curlErr !== '' ? $curlErr : ('HTTP ' . $code)),
                ];
            }

            $headerString = implode("\r\n", $headers);
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'header' => $headerString,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            global $http_response_header;
            $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
            $status = 0;
            foreach ($responseHeaders as $headerLine) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~i', $headerLine, $m)) {
                    $status = (int)$m[1];
                    break;
                }
            }
            $ok = $body !== false && $body !== '' && ($status === 0 || ($status >= 200 && $status < 300));
            return [
                'ok' => $ok,
                'body' => $body !== false ? (string)$body : '',
                'status' => $status,
                'error' => $ok ? '' : ($status > 0 ? 'HTTP ' . $status : 'network error'),
            ];
        };

        $releaseResp = $fetchRemote('https://api.github.com/repos/ksanyok/promopilot/releases/latest', ['Accept: application/vnd.github+json']);
        if ($releaseResp['ok']) {
            $json = json_decode($releaseResp['body'], true);
            if (is_array($json)) {
                $tag = ltrim((string)($json['tag_name'] ?? ''), 'vV');
                $name = ltrim((string)($json['name'] ?? ''), 'vV');
                $candidate = $tag !== '' ? $tag : $name;
                if ($candidate !== '') {
                    $latest = $candidate;
                    $source = 'releases';
                    $publishedAt = (string)($json['published_at'] ?? '');
                    if ($publishedAt !== '') {
                        try {
                            $publishedAt = (new DateTimeImmutable($publishedAt))->format('Y-m-d');
                        } catch (Throwable $e) {
                            // leave as-is if parsing fails
                        }
                    }
                }
            }
        } else {
            $error = $releaseResp['error'];
        }

        if (!version_compare($latest, $current, '>')) {
            $rawResp = $fetchRemote('https://raw.githubusercontent.com/ksanyok/promopilot/main/config/version.php', ['Accept: text/plain']);
            if ($rawResp['ok']) {
                if (preg_match('~\$version\s*=\s*([\'\"])([^\'\"]+)\\1\s*;~', $rawResp['body'], $m)) {
                    $remoteVer = trim($m[2]);
                    if ($remoteVer !== '' && version_compare($remoteVer, $latest, '>')) {
                        $latest = $remoteVer;
                        $source = 'raw';
                        $publishedAt = '';
                        $error = '';
                    }
                }
            } elseif ($error === '') {
                $error = $rawResp['error'];
            }
        }

        $isNew = version_compare($latest, $current, '>');
        return [
            'current' => $current,
            'latest' => $latest,
            'published_at' => $publishedAt,
            'is_new' => $isNew,
            'source' => $source !== '' ? $source : ($releaseResp['ok'] ? 'releases' : 'local'),
            'error' => $error,
        ];
    }
}

?>
