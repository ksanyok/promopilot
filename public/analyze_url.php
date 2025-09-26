<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

$response = function(array $data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!is_logged_in()) {
    $response(['ok' => false, 'error' => 'FORBIDDEN']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    $response(['ok' => false, 'error' => 'BAD_REQUEST']);
}

$projectId = (int)($_POST['project_id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));
if (!$projectId || !$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    $response(['ok' => false, 'error' => 'INVALID_INPUT']);
}

// Fetch project and check permissions
$conn = connect_db();
$stmt = $conn->prepare('SELECT id, user_id, domain_host FROM projects WHERE id = ?');
$stmt->bind_param('i', $projectId);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$project) { $conn->close(); $response(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']); }
if (!is_admin() && (int)$project['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
    $conn->close();
    $response(['ok' => false, 'error' => 'FORBIDDEN']);
}

// Enforce same-domain restriction
$normHost = function($h) { $h = strtolower((string)$h); return (strpos($h, 'www.') === 0) ? substr($h, 4) : $h; };
$projectHost = $normHost($project['domain_host'] ?? '');
$targetHost = $normHost(parse_url($url, PHP_URL_HOST) ?: '');
if ($projectHost && $targetHost && $projectHost !== $targetHost) {
    $conn->close();
    $response(['ok' => false, 'error' => 'DOMAIN_MISMATCH']);
}

$conn->close();

function pp_http_fetch(string $url, int $timeout = 12): array {
    $headers = [];
    $status = 0; $body = ''; $finalUrl = $url;
    $ua = 'PromoPilotBot/1.0 (+https://github.com/ksanyok/promopilot)';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_USERAGENT => $ua,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ru,en;q=0.8'
            ],
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($resp, 0, $headerSize);
            $body = substr($resp, $headerSize);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            // Parse headers (multiple response headers possible on redirects; take the last block)
            $blocks = preg_split("/\r?\n\r?\n/", trim($rawHeaders));
            $last = end($blocks);
            foreach (preg_split("/\r?\n/", (string)$last) as $line) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
        curl_close($ch);
    } else {
        // Fallback: file_get_contents
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 6,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: ' . $ua,
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: ru,en;q=0.8',
                ],
            ],
            'ssl' => [ 'verify_peer' => true, 'verify_peer_name' => true ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $body = $resp !== false ? (string)$resp : '';
        $status = 0;
        $finalUrl = $url; // no easy way to get effective URL here
        global $http_response_header;
        if (is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('~^HTTP/\d\.\d\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; }
                elseif (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'final_url' => $finalUrl];
}

function pp_html_dom(string $html): ?DOMDocument {
    if ($html === '') return null;
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // Try to preserve UTF-8 characters
    if (stripos($html, '<meta') === false) {
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }
    $loaded = @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$loaded) return null;
    return $doc;
}

function pp_xpath(DOMDocument $doc): DOMXPath { return new DOMXPath($doc); }
function pp_text(?DOMNode $n): string { return trim($n ? $n->textContent : ''); }
function pp_attr(?DOMElement $n, string $name): string { return trim($n ? (string)$n->getAttribute($name) : ''); }

function pp_abs_url(string $href, string $base): string {
    if ($href === '') return '';
    if (preg_match('~^https?://~i', $href)) return $href;
    // build absolute from base
    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $href;
    $scheme = $bp['scheme'];
    $host = $bp['host'];
    $port = isset($bp['port']) ? (':' . $bp['port']) : '';
    $path = $bp['path'] ?? '/';
    // If href starts with '/'
    if (substr($href, 0, 1) === '/') {
        return $scheme . '://' . $host . $port . $href;
    }
    // Resolve relative path
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    $segments = array_filter(explode('/', $dir));
    foreach (explode('/', $href) as $seg) {
        if ($seg === '.' || $seg === '') continue;
        if ($seg === '..') { array_pop($segments); continue; }
        $segments[] = $seg;
    }
    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}

try {
    $fetch = pp_http_fetch($url, 12);
    if (($fetch['status'] ?? 0) >= 400 || ($fetch['body'] ?? '') === '') {
        $response(['ok' => false, 'error' => 'FETCH_FAILED', 'status' => $fetch['status'] ?? 0]);
    }

    $finalUrl = $fetch['final_url'] ?: $url;
    $headers = $fetch['headers'] ?? [];
    $body = (string)$fetch['body'];
    $doc = pp_html_dom($body);
    if (!$doc) { $response(['ok' => false, 'error' => 'PARSE_FAILED']); }
    $xp = pp_xpath($doc);

    // Base URL for resolving
    $baseHref = '';
    $baseEl = $xp->query('//base[@href]')->item(0);
    if ($baseEl instanceof DOMElement) { $baseHref = pp_attr($baseEl, 'href'); }
    $base = $baseHref !== '' ? $baseHref : $finalUrl;

    // Title
    $title = '';
    $titleEl = $xp->query('//title')->item(0);
    if ($titleEl) { $title = pp_text($titleEl); }
    $ogTitle = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content | //meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content')->item(0);
    if ($ogTitle && !$title) { $title = trim($ogTitle->nodeValue ?? ''); }

    // Description
    $desc = '';
    $metaDesc = $xp->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0);
    if ($metaDesc) { $desc = trim($metaDesc->nodeValue ?? ''); }
    if ($desc === '') {
        $ogDesc = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content')->item(0);
        if ($ogDesc) { $desc = trim($ogDesc->nodeValue ?? ''); }
    }

    // Canonical
    $canonical = '';
    $canonEl = $xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href')->item(0);
    if ($canonEl) { $canonical = pp_abs_url(trim($canonEl->nodeValue ?? ''), $base); }

    // Language and region
    $lang = '';
    $region = '';
    $htmlEl = $xp->query('//html')->item(0);
    if ($htmlEl instanceof DOMElement) {
        $langAttr = trim($htmlEl->getAttribute('lang'));
        if ($langAttr) {
            $parts = preg_split('~[-_]~', $langAttr);
            $lang = strtolower($parts[0] ?? '');
            if (isset($parts[1])) { $region = strtoupper($parts[1]); }
        }
    }

    // Hreflangs
    $hreflangs = [];
    foreach ($xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang and @href]') as $lnk) {
        if (!($lnk instanceof DOMElement)) continue;
        $hl = trim($lnk->getAttribute('hreflang'));
        $href = pp_abs_url(trim($lnk->getAttribute('href')), $base);
        if ($hl && $href) { $hreflangs[] = ['hreflang' => $hl, 'href' => $href]; }
    }
    if (!$lang && !empty($hreflangs)) {
        $hl0 = $hreflangs[0]['hreflang'];
        $parts = preg_split('~[-_]~', $hl0);
        $lang = strtolower($parts[0] ?? '');
        if (isset($parts[1])) { $region = strtoupper($parts[1]); }
    }

    // Meta http-equiv Content-Language
    if (!$lang) {
        $contentLang = $xp->query('//meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-language"]/@content')->item(0);
        if ($contentLang) {
            $v = trim($contentLang->nodeValue ?? '');
            if ($v) {
                $parts = preg_split('~[,;\s]+~', $v);
                $p0 = $parts[0] ?? '';
                $pp = preg_split('~[-_]~', $p0);
                $lang = strtolower($pp[0] ?? '');
                if (isset($pp[1])) { $region = strtoupper($pp[1]); }
            }
        }
    }

    // Dates: published/modified from meta tags
    $published = '';
    $modified = '';
    $q = function(string $xpath) use ($xp): ?string { $n = $xp->query($xpath)->item(0); return $n ? trim($n->nodeValue ?? '') : null; };
    $published = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:published_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="datePublished"]/@content') ?: $q('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="pubdate"]/@content');
    $modified = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:modified_time"]/@content') ?: $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:updated_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dateModified"]/@content');

    // Try JSON-LD
    foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
        $json = trim($script->textContent ?? '');
        if ($json === '') continue;
        // Some sites have multiple JSON objects; try to decode best-effort
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to wrap or fix common issues (remove trailing commas)
            $json2 = preg_replace('/,\s*([}\]])/', '$1', $json);
            $data = json_decode($json2, true);
        }
        if (is_array($data)) {
            $stack = [$data];
            while ($stack) {
                $cur = array_pop($stack);
                if (isset($cur['datePublished']) && !$published) { $published = (string)$cur['datePublished']; }
                if (isset($cur['dateModified']) && !$modified) { $modified = (string)$cur['dateModified']; }
                foreach ($cur as $v) { if (is_array($v)) $stack[] = $v; }
            }
        }
        if ($published && $modified) break;
    }

    // Fallback to Last-Modified header for modified
    if (!$modified && !empty($headers['last-modified'])) { $modified = $headers['last-modified']; }

    $data = [
        'final_url' => $finalUrl,
        'lang' => $lang,
        'region' => $region,
        'title' => $title,
        'description' => $desc,
        'canonical' => $canonical,
        'published_time' => $published,
        'modified_time' => $modified,
        'hreflang' => $hreflangs,
    ];

    $response(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
    $response(['ok' => false, 'error' => 'EXCEPTION', 'details' => $e->getMessage()]);
}
