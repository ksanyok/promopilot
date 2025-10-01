<?php
// Runtime helpers: Node.js and Chrome/Puppeteer resolution and script execution

// -------- PHP CLI resolution (for background workers) --------
if (!function_exists('pp_check_php_cli')) {
    function pp_check_php_cli(string $bin, int $timeoutSeconds = 3): array {
        $descriptor = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $cmd = escapeshellarg($bin) . ' -v';
        $proc = @proc_open($cmd, $descriptor, $pipes);
        if (!is_resource($proc)) { return ['ok'=>false, 'error'=>'PROC_OPEN_FAILED']; }
        if (isset($pipes[0]) && is_resource($pipes[0])) { @fclose($pipes[0]); }
        $stdout = '';
        $stderr = '';
        $start = time();
        if (isset($pipes[1]) && is_resource($pipes[1])) { @stream_set_blocking($pipes[1], false); }
        if (isset($pipes[2]) && is_resource($pipes[2])) { @stream_set_blocking($pipes[2], false); }
        while (true) {
            $status = @proc_get_status($proc);
            if (!$status || !$status['running']) { break; }
            if ((time() - $start) >= $timeoutSeconds) { @proc_terminate($proc, 9); break; }
            usleep(100000);
        }
        if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); @fclose($pipes[1]); }
        if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); @fclose($pipes[2]); }
        @proc_close($proc);
        $out = strtolower(trim($stdout . ' ' . $stderr));
        // consider ok only if CLI is detected and not cgi/fpm/lsapi
        $ok = (strpos($out, 'cli') !== false) && (strpos($out, 'cgi') === false) && (strpos($out, 'fpm') === false) && (strpos($out, 'lsapi') === false);
        return ['ok' => (bool)$ok, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
    }
}

if (!function_exists('pp_collect_php_cli_candidates')) {
    function pp_collect_php_cli_candidates(): array {
        $candidates = [];
        $setting = trim((string)get_setting('php_cli_binary', ''));
        if ($setting !== '') { $candidates[] = $setting; }
        $env = getenv('PP_PHP_CLI') ?: getenv('PHP_CLI');
        if ($env && trim($env) !== '') { $candidates[] = trim($env); }

        // PHP_BINARY can be cli or lsphp; include but verify
        if (defined('PHP_BINARY') && PHP_BINARY) { $candidates[] = PHP_BINARY; }

        // Common paths (cpanel/easyapache and system)
        foreach ([
            '/usr/local/bin/php','/usr/bin/php','/bin/php','/opt/cpanel/ea-php82/root/usr/bin/php','/opt/cpanel/ea-php81/root/usr/bin/php','/opt/cpanel/ea-php80/root/usr/bin/php','/opt/alt/php82/usr/bin/php','/opt/alt/php81/usr/bin/php','/opt/alt/php80/usr/bin/php','/opt/alt/php74/usr/bin/php','/opt/alt/php73/usr/bin/php','/opt/alt/php72/usr/bin/php'
        ] as $p) { $candidates[] = $p; }

        // PATH scan
        $pathEnv = (string)($_SERVER['PATH'] ?? getenv('PATH') ?? '');
        if ($pathEnv !== '') {
            foreach (preg_split('~' . preg_quote(PATH_SEPARATOR, '~') . '~', $pathEnv) as $dir) {
                $dir = rtrim(trim((string)$dir), '/'); if ($dir === '') continue;
                $candidates[] = $dir . '/php';
            }
        }

        // Deduplicate
        $map = [];
        foreach ($candidates as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            if (!isset($map[$cand])) $map[$cand] = $cand;
        }
        return array_values($map);
    }
}

if (!function_exists('pp_resolve_php_cli')) {
    function pp_resolve_php_cli(int $timeoutSeconds = 3, bool $persist = true): ?string {
        $candidates = pp_collect_php_cli_candidates();
        foreach ($candidates as $cand) {
            // Skip obviously wrong wrappers unless verified
            $check = pp_check_php_cli($cand, $timeoutSeconds);
            if ($check['ok']) {
                if ($persist) {
                    $current = trim((string)get_setting('php_cli_binary', ''));
                    if ($current !== $cand) { set_setting('php_cli_binary', $cand); }
                }
                return $cand;
            }
        }
        return null;
    }
}

if (!function_exists('pp_get_php_cli')) {
    function pp_get_php_cli(): string {
        $resolved = pp_resolve_php_cli(3, true);
        if ($resolved) { return $resolved; }
        return 'php';
    }
}

if (!function_exists('pp_check_node_binary')) {
    function pp_check_node_binary(string $bin, int $timeoutSeconds = 3): array {
        $descriptor = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $cmd = escapeshellarg($bin) . ' -v';
        $proc = @proc_open($cmd, $descriptor, $pipes);
        if (!is_resource($proc)) { return ['ok'=>false, 'error'=>'PROC_OPEN_FAILED']; }
        if (isset($pipes[0]) && is_resource($pipes[0])) { @fclose($pipes[0]); }
        $stdout = '';
        $stderr = '';
        $start = time();
        if (isset($pipes[1]) && is_resource($pipes[1])) { @stream_set_blocking($pipes[1], false); }
        if (isset($pipes[2]) && is_resource($pipes[2])) { @stream_set_blocking($pipes[2], false); }
        while (true) {
            $status = @proc_get_status($proc);
            if (!$status || !$status['running']) { break; }
            if ((time() - $start) >= $timeoutSeconds) { @proc_terminate($proc, 9); break; }
            usleep(100000);
        }
        if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); @fclose($pipes[1]); }
        if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); @fclose($pipes[2]); }
        $exit = @proc_close($proc);
        $ver = trim($stdout);
        $ok = ($exit === 0) && preg_match('~^v?(\d+\.\d+\.\d+)~', $ver);
        return ['ok' => (bool)$ok, 'version' => $ver, 'exit_code' => $exit, 'stderr' => trim($stderr)];
    }
}

if (!function_exists('pp_collect_node_candidates')) {
    function pp_collect_node_candidates(): array {
        $candidates = [];
        $setting = trim((string)get_setting('node_binary', ''));
        if ($setting !== '') { $candidates[] = $setting; }
        $env = getenv('NODE_BINARY');
        if ($env && trim($env) !== '') { $candidates[] = trim($env); }

        if (function_exists('shell_exec')) {
            $whichNode = trim((string)@shell_exec('command -v node 2>/dev/null'));
            if ($whichNode !== '') { $candidates[] = $whichNode; }
            $whichNodeJs = trim((string)@shell_exec('command -v nodejs 2>/dev/null'));
            if ($whichNodeJs !== '') { $candidates[] = $whichNodeJs; }

            $bashPaths = [
                "/bin/bash -lc 'command -v node' 2>/dev/null",
                "/bin/bash -lc 'which node' 2>/dev/null",
                "/bin/bash -lc 'command -v nodejs' 2>/dev/null",
                "/bin/bash -lc 'which nodejs' 2>/dev/null",
                "/bin/bash -lc 'whereis -b node' 2>/dev/null",
            ];
            foreach ($bashPaths as $cmd) {
                $out = trim((string)@shell_exec($cmd));
                if ($out === '') { continue; }
                $parts = preg_split('~[\s]+~', $out);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '' || strpos($part, '/') === false) { continue; }
                    $candidates[] = $part;
                }
            }

            $bashLists = [
                "/bin/bash -lc 'ls -1 /opt/alt/nodejs*/bin/node 2>/dev/null'",
                "/bin/bash -lc 'ls -1 /opt/alt/nodejs*/usr/bin/node 2>/dev/null'",
                "/bin/bash -lc 'ls -1 /opt/alt/nodejs*/root/usr/bin/node 2>/dev/null'",
                "/bin/bash -lc 'ls -1 /opt/nodejs*/bin/node 2>/dev/null'",
                "/bin/bash -lc 'ls -1 \$HOME/.nodebrew/current/bin/node 2>/dev/null'",
                "/bin/bash -lc 'ls -1 \$HOME/.nvm/versions/node/*/bin/node 2>/dev/null'",
            ];
            foreach ($bashLists as $cmd) {
                $out = trim((string)@shell_exec($cmd));
                if ($out !== '') {
                    foreach (preg_split('~[\r\n]+~', $out) as $line) {
                        $line = trim($line);
                        if ($line !== '') { $candidates[] = $line; }
                    }
                }
            }
        }

        $pathEnv = (string)($_SERVER['PATH'] ?? getenv('PATH') ?? '');
        if ($pathEnv !== '') {
            $parts = preg_split('~' . preg_quote(PATH_SEPARATOR, '~') . '~', $pathEnv);
            foreach ($parts as $dir) {
                $dir = trim($dir);
                if ($dir === '') { continue; }
                $dir = rtrim($dir, '/\\');
                $candidates[] = $dir . '/node';
                $candidates[] = $dir . '/nodejs';
            }
        }

        $home = getenv('HOME') ?: ((isset($_SERVER['HOME']) && $_SERVER['HOME']) ? $_SERVER['HOME'] : '');
        if ($home) {
            $home = rtrim($home, '/');
            $candidates[] = $home . '/.local/bin/node';
            $candidates[] = $home . '/bin/node';
            foreach (@glob($home . '/.nvm/versions/node/*/bin/node') ?: [] as $path) { $candidates[] = $path; }
            foreach (@glob($home . '/.asdf/installs/nodejs/*/bin/node') ?: [] as $path) { $candidates[] = $path; }
        }

        $globPaths = [
            '/usr/local/bin/node','/usr/bin/node','/bin/node','/usr/local/node/bin/node','/usr/local/share/node/bin/node','/opt/homebrew/bin/node','/opt/local/bin/node','/snap/bin/node',
        ];
        foreach (['/opt/node*/bin/node','/opt/nodejs*/bin/node','/opt/alt/*/bin/node','/opt/alt/*/usr/bin/node','/opt/alt/*/root/usr/bin/node'] as $pattern) {
            foreach (@glob($pattern) ?: [] as $path) { $globPaths[] = $path; }
        }
        foreach ($globPaths as $path) { $candidates[] = $path; }

        $candidates[] = 'node';
        $candidates[] = 'nodejs';

        $result = [];
        foreach ($candidates as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') { continue; }
            if (!isset($result[$cand])) { $result[$cand] = $cand; }
        }
        return array_values($result);
    }
}

if (!function_exists('pp_resolve_node_binary')) {
    function pp_resolve_node_binary(int $timeoutSeconds = 3, bool $persist = true): ?array {
        $candidates = pp_collect_node_candidates();
        foreach ($candidates as $cand) {
            $check = pp_check_node_binary($cand, $timeoutSeconds);
            if ($check['ok']) {
                if ($persist) {
                    $current = trim((string)get_setting('node_binary', ''));
                    if ($current !== $cand) {
                        set_setting('node_binary', $cand);
                    }
                }
                return ['path' => $cand, 'version' => $check['version'], 'diagnostics' => $check];
            }
        }
        return null;
    }
}

if (!function_exists('pp_get_node_binary')) {
    function pp_get_node_binary(): string {
        $resolved = pp_resolve_node_binary(3, true);
        if ($resolved) { return $resolved['path']; }
        return 'node';
    }
}

if (!function_exists('pp_collect_chrome_candidates')) {
    function pp_collect_chrome_candidates(): array {
        $candidates = [];

        $setting = trim((string)get_setting('puppeteer_executable_path', ''));
        if ($setting !== '') { $candidates[] = $setting; }
        $envVars = ['PUPPETEER_EXECUTABLE_PATH','PP_CHROME_PATH','GOOGLE_CHROME_BIN','CHROME_PATH','CHROME_BIN'];
        foreach ($envVars as $k) {
            $v = getenv($k);
            if ($v && trim($v) !== '') { $candidates[] = trim($v); }
        }

        $common = [
            '/usr/local/bin/google-chrome','/usr/local/bin/google-chrome-stable','/usr/bin/google-chrome','/usr/bin/google-chrome-stable','/usr/bin/chromium-browser','/usr/bin/chromium','/bin/google-chrome','/bin/chromium','/opt/google/chrome/google-chrome','/opt/google/chrome/chrome','/opt/chrome/chrome','/snap/bin/chromium',
            // macOS
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
        ];
        foreach ($common as $p) { $candidates[] = $p; }

        $home = getenv('HOME') ?: ((isset($_SERVER['HOME']) && $_SERVER['HOME']) ? $_SERVER['HOME'] : '');
        if ($home) {
            foreach ([
                $home . '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                $home . '/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary',
                $home . '/Applications/Chromium.app/Contents/MacOS/Chromium',
                $home . '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
                $home . '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
            ] as $p) { $candidates[] = $p; }
        }

        $base = rtrim(PP_ROOT_PATH, '/');
        $projectLocal = [
            $base . '/node_runtime/chrome/chrome',
            $base . '/node_runtime/chrome/chrome-linux64/chrome',
        ];
        foreach ($projectLocal as $p) { $candidates[] = $p; }
        foreach (@glob($base . '/node_runtime/chrome/*/chrome-linux64/chrome') ?: [] as $p) { $candidates[] = $p; }

        if (function_exists('shell_exec')) {
            $cmds = [
                "command -v google-chrome 2>/dev/null",
                "command -v google-chrome-stable 2>/dev/null",
                "command -v chromium 2>/dev/null",
                "command -v chromium-browser 2>/dev/null",
            ];
            foreach ($cmds as $cmd) {
                $out = trim((string)@shell_exec($cmd));
                if ($out !== '' && strpos($out, '/') !== false) { $candidates[] = $out; }
            }
            // macOS Spotlight searches
            $mdfinds = [
                "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.google.Chrome' 2>/dev/null",
                "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.google.Chrome.canary' 2>/dev/null",
                "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==org.chromium.Chromium' 2>/dev/null",
                "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.microsoft.edgemac' 2>/dev/null",
                "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.microsoft.Edgemac' 2>/dev/null",
                "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.brave.Browser' 2>/dev/null",
            ];
            foreach ($mdfinds as $cmd) {
                $out = trim((string)@shell_exec($cmd));
                if ($out !== '') {
                    foreach (preg_split('~[\r\n]+~', $out) as $appPath) {
                        $appPath = trim($appPath);
                        if ($appPath === '' || strpos($appPath, '.app') === false) continue;
                        $bin = $appPath . '/Contents/MacOS/'
                            . (stripos($appPath, 'Edge') !== false ? 'Microsoft Edge'
                            : (stripos($appPath, 'Chromium') !== false ? 'Chromium'
                            : (stripos($appPath, 'Canary') !== false ? 'Google Chrome Canary'
                            : (stripos($appPath, 'Brave') !== false ? 'Brave Browser'
                            : 'Google Chrome'))));
                        $candidates[] = $bin;
                    }
                }
            }
        }

        $map = [];
        foreach ($candidates as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            if (!isset($map[$cand])) $map[$cand] = $cand;
        }
        return array_values($map);
    }
}

if (!function_exists('pp_resolve_chrome_path')) {
    function pp_resolve_chrome_path(): ?string {
        $cands = pp_collect_chrome_candidates();
        foreach ($cands as $cand) {
            if (@is_file($cand) && @is_executable($cand)) {
                return $cand;
            }
        }
        return null;
    }
}

if (!function_exists('pp_run_node_script')) {
    function pp_run_node_script(string $script, array $job, int $timeoutSeconds = 480): array {
        if (!is_file($script) || !is_readable($script)) {
            return ['ok' => false, 'error' => 'SCRIPT_NOT_FOUND'];
        }
        $resolved = pp_resolve_node_binary(3, true);
        $nodeCandidates = pp_collect_node_candidates();
        if (!$resolved) {
            return [
                'ok' => false,
                'error' => 'NODE_BINARY_NOT_FOUND',
                'details' => 'Node.js is not available for the PHP process. Настройте путь в админке или переменную NODE_BINARY.',
                'candidates' => $nodeCandidates,
            ];
        }
        $node = $resolved['path'];
        $nodeVer = $resolved['version'] ?? '';

        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $logDir = PP_ROOT_PATH . '/logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
        if (!is_writable($logDir)) { @chmod($logDir, 0775); }
        $homeDir = PP_ROOT_PATH . '/.cache';
        if (!is_dir($homeDir)) { @mkdir($homeDir, 0775, true); }

        $puppeteerExec = trim((string)get_setting('puppeteer_executable_path', ''));
        if ($puppeteerExec === '') {
            $autoChrome = pp_resolve_chrome_path();
            if ($autoChrome) { $puppeteerExec = $autoChrome; }
        }
        $puppeteerArgs = trim((string)get_setting('puppeteer_args', ''));

        $env = array_merge($_ENV, $_SERVER, [
            'PP_JOB' => json_encode($job, JSON_UNESCAPED_UNICODE),
            'NODE_NO_WARNINGS' => '1',
            'PP_LOG_DIR' => $logDir,
            'PP_LOG_FILE' => $logDir . '/network-' . basename($script, '.js') . '-' . date('Ymd-His') . '-' . getmypid() . '.log',
            'HOME' => $homeDir,
        ]);
        if ($puppeteerExec !== '') {
            $env['PUPPETEER_EXECUTABLE_PATH'] = $puppeteerExec;
            $env['PP_CHROME_PATH'] = $puppeteerExec;
            $env['GOOGLE_CHROME_BIN'] = $puppeteerExec;
            $env['CHROME_PATH'] = $puppeteerExec;
            $env['CHROME_BIN'] = $puppeteerExec;
        }
        if ($puppeteerArgs !== '') { $env['PUPPETEER_ARGS'] = $puppeteerArgs; }

        $cmd = $node . ' ' . escapeshellarg($script);
        $process = @proc_open($cmd, $descriptorSpec, $pipes, PP_ROOT_PATH, $env);
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'PROC_OPEN_FAILED'];
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) { @fclose($pipes[0]); $pipes[0] = null; }
        if (isset($pipes[1]) && is_resource($pipes[1])) { @stream_set_blocking($pipes[1], false); }
        if (isset($pipes[2]) && is_resource($pipes[2])) { @stream_set_blocking($pipes[2], false); }

        $stdout = '';
        $stderr = '';
        $start = time();

        $stInfo = @proc_get_status($process);
        $childPid = is_array($stInfo) && !empty($stInfo['pid']) ? (int)$stInfo['pid'] : 0;
        if ($childPid > 0 && isset($job['pubId'])) {
            try { $c = @connect_db(); if ($c) { $st = $c->prepare('UPDATE publications SET pid = ? WHERE id = ? LIMIT 1'); if ($st) { $st->bind_param('ii', $childPid, $job['pubId']); @$st->execute(); $st->close(); } $c->close(); } } catch (Throwable $e) { }
        }

        while (true) {
            $status = @proc_get_status($process);
            if (!$status || !$status['running']) {
                if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); }
                if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); }
                break;
            }
            $read = [];
            if (isset($pipes[1]) && is_resource($pipes[1]) && !@feof($pipes[1])) { $read[] = $pipes[1]; }
            if (isset($pipes[2]) && is_resource($pipes[2]) && !@feof($pipes[2])) { $read[] = $pipes[2]; }
            if (!$read) { break; }
            $write = null; $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) { break; }
            foreach ($read as $stream) {
                if (is_resource($stream)) {
                    $chunk = @stream_get_contents($stream);
                    if ($stream === ($pipes[1] ?? null)) { $stdout .= (string)$chunk; }
                    else { $stderr .= (string)$chunk; }
                }
            }
            if ((time() - $start) >= $timeoutSeconds) {
                @proc_terminate($process, 9);
                if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); }
                if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); }
                if (isset($pipes) && is_array($pipes)) { foreach ($pipes as &$p) { if (is_resource($p)) { @fclose($p); } $p = null; } unset($p); }
                @proc_close($process);
                return ['ok' => false, 'error' => 'NODE_TIMEOUT', 'stderr' => trim($stderr)];
            }
            if (isset($job['pubId'])) {
                try { $c = @connect_db(); if ($c) { $q = $c->prepare('SELECT cancel_requested FROM publications WHERE id = ?'); if ($q) { $q->bind_param('i', $job['pubId']); $q->execute(); $q->bind_result($cr); if ($q->fetch() && (int)$cr === 1) { @proc_terminate($process, 9); $q->close(); $c->close(); break; } $q->close(); } $c->close(); } } catch (Throwable $e) { }
            }
        }

        if (isset($pipes) && is_array($pipes)) { foreach ($pipes as &$p) { if (is_resource($p)) { @fclose($p); } $p = null; } unset($p); }
        $exitCode = @proc_close($process);

        $response = ['ok' => false, 'error' => 'NODE_RETURN_EMPTY'];
        $stdoutTrim = trim($stdout);
        if ($stdoutTrim !== '') {
            $pos = strrpos($stdoutTrim, "\n");
            $lastLine = trim($pos === false ? $stdoutTrim : substr($stdoutTrim, $pos + 1));
            $decoded = json_decode($lastLine, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $response = $decoded;
            } else {
                $response = ['ok' => false, 'error' => 'INVALID_JSON', 'raw' => $stdoutTrim];
            }
        }
        if (strlen($stderr) > 0) { $response['stderr'] = trim($stderr); }
        $response['exit_code'] = $exitCode;
        if (($response['error'] ?? '') === 'NODE_RETURN_EMPTY' && (int)$exitCode === 127) {
            $response['error'] = 'NODE_BINARY_NOT_FOUND';
            $response['details'] = 'Node.js command not found by PHP. Set settings.node_binary or NODE_BINARY env to full path (e.g. /opt/homebrew/bin/node).';
        }
        if (!empty($nodeVer)) { $response['node_version'] = $nodeVer; }
        return $response;
    }
}

if (!function_exists('pp_publish_via_network')) {
    function pp_publish_via_network(array $network, array $job, int $timeoutSeconds = 480): array {
        $type = strtolower((string)($network['handler_type'] ?? ''));
        if ($type !== 'node') {
            return ['ok' => false, 'error' => 'UNSUPPORTED_HANDLER'];
        }
        return pp_run_node_script($network['handler_abs'], $job, $timeoutSeconds);
    }
}

?>
