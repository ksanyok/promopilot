<?php
// Page metadata storage and URL analysis utilities

if (!function_exists('pp_url_hash')) {
    function pp_url_hash(string $url): string { return hash('sha256', strtolower(trim($url))); }
}

if (!function_exists('pp_save_page_meta')) {
    function pp_save_page_meta(int $projectId, string $pageUrl, array $data): bool {
        try { $conn = @connect_db(); } catch (Throwable $e) { return false; }
        if (!$conn) return false;
        $urlHash = pp_url_hash($pageUrl);
        $finalUrl = (string)($data['final_url'] ?? ''); $lang = (string)($data['lang'] ?? ''); $region = (string)($data['region'] ?? '');
        $title = (string)($data['title'] ?? ''); $description = (string)($data['description'] ?? ''); $canonical = (string)($data['canonical'] ?? '');
        $published = (string)($data['published_time'] ?? ''); $modified = (string)($data['modified_time'] ?? '');
        $hreflang = $data['hreflang'] ?? null; $hreflangJson = is_string($hreflang) ? $hreflang : json_encode($hreflang, JSON_UNESCAPED_UNICODE);
        $sql = 'INSERT INTO page_meta (project_id, url_hash, page_url, final_url, lang, region, title, description, canonical, published_time, modified_time, hreflang_json, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE final_url=VALUES(final_url), lang=VALUES(lang), region=VALUES(region), title=VALUES(title), description=VALUES(description), canonical=VALUES(canonical), published_time=VALUES(published_time), modified_time=VALUES(modified_time), hreflang_json=VALUES(hreflang_json), updated_at=CURRENT_TIMESTAMP';
        $stmt = $conn->prepare($sql); if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('isssssssssss', $projectId, $urlHash, $pageUrl, $finalUrl, $lang, $region, $title, $description, $canonical, $published, $modified, $hreflangJson);
        $ok = $stmt->execute(); $stmt->close(); $conn->close(); return (bool)$ok;
    }
}

if (!function_exists('pp_get_page_meta')) {
    function pp_get_page_meta(int $projectId, string $pageUrl): ?array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return null; }
        if (!$conn) return null; $hash = pp_url_hash($pageUrl);
        $stmt = $conn->prepare('SELECT page_url, final_url, lang, region, title, description, canonical, published_time, modified_time, hreflang_json FROM page_meta WHERE project_id = ? AND url_hash = ? LIMIT 1');
        if (!$stmt) { $conn->close(); return null; }
        $stmt->bind_param('is', $projectId, $hash); $stmt->execute(); $stmt->bind_result($page_url, $final_url, $lang, $region, $title, $description, $canonical, $published, $modified, $hreflang_json);
        $data = null; if ($stmt->fetch()) { $data = ['page_url' => (string)$page_url, 'final_url' => (string)$final_url, 'lang' => (string)$lang, 'region' => (string)$region, 'title' => (string)$title, 'description' => (string)$description, 'canonical' => (string)$canonical, 'published_time' => (string)$published, 'modified_time' => (string)$modified, 'hreflang' => json_decode((string)$hreflang_json, true) ?: [],]; }
        $stmt->close(); $conn->close(); return $data;
    }
}

// HTTP and HTML helpers
if (!function_exists('pp_http_fetch')) {
    function pp_http_fetch(string $url, int $timeout = 12): array {
        $headers = []; $status = 0; $body = ''; $finalUrl = $url; $ua = 'PromoPilotBot/1.0 (+https://github.com/ksanyok/promopilot)';
        if (function_exists('curl_init')) {
            $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(6, $timeout), CURLOPT_USERAGENT => $ua, CURLOPT_ACCEPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: ru,en;q=0.8'],]);
            $resp = curl_exec($ch);
            if ($resp !== false) {
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $rawHeaders = substr($resp, 0, $headerSize); $body = substr($resp, $headerSize);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
                $blocks = preg_split("/\r?\n\r?\n/", trim($rawHeaders)); $last = end($blocks);
                foreach (preg_split("/\r?\n/", (string)$last) as $line) { if (strpos($line, ':') !== false) { [$k, $v] = array_map('trim', explode(':', $line, 2)); $headers[strtolower($k)] = $v; } }
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'follow_location' => 1, 'max_redirects' => 6, 'ignore_errors' => true, 'header' => ['User-Agent: ' . $ua, 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: ru,en;q=0.8',],], 'ssl' => [ 'verify_peer' => true, 'verify_peer_name' => true ],]);
            $resp = @file_get_contents($url, false, $ctx); $body = $resp !== false ? (string)$resp : ''; $status = 0; $finalUrl = $url; global $http_response_header;
            if (is_array($http_response_header)) { foreach ($http_response_header as $line) { if (preg_match('~^HTTP/\d\.\d\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; } elseif (strpos($line, ':') !== false) { [$k, $v] = array_map('trim', explode(':', $line, 2)); $headers[strtolower($k)] = $v; } } }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'final_url' => $finalUrl];
    }
}

if (!function_exists('pp_html_dom')) {
    function pp_html_dom(string $html): ?DOMDocument {
        if ($html === '') return null; $doc = new DOMDocument(); libxml_use_internal_errors(true);
        if (stripos($html, '<meta') === false) { $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html; }
        $loaded = @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR); libxml_clear_errors(); if (!$loaded) return null; return $doc;
    }
}
if (!function_exists('pp_xpath')) { function pp_xpath(DOMDocument $doc): DOMXPath { return new DOMXPath($doc); } }
if (!function_exists('pp_text')) { function pp_text(?DOMNode $n): string { return trim($n ? $n->textContent : ''); } }
if (!function_exists('pp_attr')) { function pp_attr(?DOMElement $n, string $name): string { return trim($n ? (string)$n->getAttribute($name) : ''); } }

if (!function_exists('pp_abs_url')) {
    function pp_abs_url(string $href, string $base): string {
        if ($href === '') return '';
        if (preg_match('~^https?://~i', $href)) return $href;
        $bp = parse_url($base); if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $href;
        $scheme = $bp['scheme']; $host = $bp['host']; $port = isset($bp['port']) ? (':' . $bp['port']) : ''; $path = $bp['path'] ?? '/';
        if (substr($href, 0, 1) === '/') { return $scheme . '://' . $host . $port . $href; }
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/'); $segments = array_filter(explode('/', $dir));
        foreach (explode('/', $href) as $seg) { if ($seg === '.' || $seg === '') continue; if ($seg === '..') { array_pop($segments); continue; } $segments[] = $seg; }
        return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
    }
}

if (!function_exists('pp_normalize_text_content')) {
    function pp_normalize_text_content(string $text): string {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); $decoded = preg_replace('~\s+~u', ' ', $decoded); $decoded = trim((string)$decoded);
        if ($decoded === '') { return ''; } if (function_exists('mb_strtolower')) { return mb_strtolower($decoded, 'UTF-8'); } return strtolower($decoded);
    }
}
if (!function_exists('pp_plain_text_from_html')) {
    function pp_plain_text_from_html(string $html): string { $doc = pp_html_dom($html); $text = $doc ? ($doc->textContent ?? '') : strip_tags($html); return pp_normalize_text_content($text); }
}

if (!function_exists('pp_normalize_url_compare')) {
    function pp_normalize_url_compare(string $url): string {
        $url = trim((string)$url); if ($url === '') { return ''; }
        $lower = strtolower($url); if (!preg_match('~^https?://~', $lower)) { return $lower; }
        $parts = @parse_url($lower); if (!$parts || empty($parts['host'])) { return $lower; }
        $scheme = $parts['scheme'] ?? 'http'; $host = $parts['host']; if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
        $path = $parts['path'] ?? '/'; $path = $path === '' ? '/' : $path; $path = rtrim($path, '/'); if ($path === '') { $path = '/'; }
        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        return $scheme . '://' . $host . $path . $query;
    }
}

if (!function_exists('pp_verify_published_content')) {
    function pp_verify_published_content(string $publishedUrl, ?array $verification, ?array $job = null): array {
        $publishedUrl = trim($publishedUrl); $verification = is_array($verification) ? $verification : [];
        $supportsLink = array_key_exists('supportsLinkCheck', $verification) ? (bool)$verification['supportsLinkCheck'] : true;
        $supportsText = array_key_exists('supportsTextCheck', $verification) ? (bool)$verification['supportsTextCheck'] : null;
        $linkUrl = trim((string)($verification['linkUrl'] ?? '')); if ($linkUrl === '' && isset($job['url'])) { $linkUrl = trim((string)$job['url']); }
        $textSample = trim((string)($verification['textSample'] ?? '')); if ($supportsText === null) { $supportsText = ($textSample !== ''); }
        $result = ['status' => 'skipped', 'supports_link' => $supportsLink, 'supports_text' => $supportsText, 'link_found' => false, 'text_found' => false, 'http_status' => null, 'final_url' => null, 'content_type' => null, 'reason' => null, ];
        if ($publishedUrl === '' || (!$supportsLink && !$supportsText)) { return $result; }
        $fetch = pp_http_fetch($publishedUrl, 18); $status = (int)($fetch['status'] ?? 0); $finalUrl = (string)($fetch['final_url'] ?? $publishedUrl); $headers = $fetch['headers'] ?? []; $body = (string)($fetch['body'] ?? ''); $contentType = strtolower((string)($headers['content-type'] ?? ''));
        $result['http_status'] = $status; $result['final_url'] = $finalUrl; $result['content_type'] = $contentType;
        if ($status >= 400 || $body === '') { $result['status'] = 'error'; $result['reason'] = 'FETCH_FAILED'; return $result; }
        $doc = null; if ($contentType === '' || strpos($contentType, 'text/') === 0 || strpos($contentType, 'html') !== false || strpos($contentType, 'xml') !== false) { $doc = pp_html_dom($body); }
        if ($supportsLink && $linkUrl !== '') {
            $targetNorm = pp_normalize_url_compare($linkUrl);
            if ($doc) {
                $xp = new DOMXPath($doc); foreach ($xp->query('//a[@href]') as $node) { if (!($node instanceof DOMElement)) { continue; } $href = trim((string)$node->getAttribute('href')); if ($href === '') { continue; } $abs = pp_abs_url($href, $finalUrl); $absNorm = pp_normalize_url_compare($abs); if ($absNorm === $targetNorm) { $result['link_found'] = true; break; } }
            }
            if (!$result['link_found']) { $haystack = strtolower($body); $direct = strtolower($linkUrl); if ($direct !== '' && strpos($haystack, $direct) !== false) { $result['link_found'] = true; } else { $noScheme = preg_replace('~^https?://~i', '', $direct); if ($noScheme && strpos($haystack, $noScheme) !== false) { $result['link_found'] = true; } } }
        } elseif ($supportsLink) { $result['supports_link'] = false; }
        if ($supportsText) {
            if ($textSample === '') { $result['supports_text'] = false; }
            else {
                $bodyPlain = $doc ? pp_normalize_text_content($doc->textContent ?? '') : pp_plain_text_from_html($body); $sampleNorm = pp_normalize_text_content($textSample); $matchFragment = '';
                if ($sampleNorm !== '' && strpos($bodyPlain, $sampleNorm) !== false) { $result['text_found'] = true; $matchFragment = $sampleNorm; }
                elseif ($sampleNorm !== '') {
                    $strlen = function_exists('mb_strlen') ? static function($s){ return mb_strlen($s, 'UTF-8'); } : static function($s){ return strlen($s); };
                    $substr = function_exists('mb_substr') ? static function($s,$st,$l){ return mb_substr($s,$st,$l,'UTF-8'); } : static function($s,$st,$l){ return substr($s,$st,$l); };
                    $len = $strlen($sampleNorm); $short = $len > 120 ? $substr($sampleNorm, 0, 120) : $sampleNorm;
                    if ($short !== '' && strpos($bodyPlain, $short) !== false) { $result['text_found'] = true; $matchFragment = $short; }
                    if (!$result['text_found'] && $len > 0) { $window = min(220, max(80, (int)ceil($len * 0.4))); $step = max(40, (int)floor($window / 2));
                        for ($offset = 0; $offset < $len; $offset += $step) { if ($offset + $window > $len) { $offset = max(0, $len - $window); }
                            $fragment = trim($substr($sampleNorm, $offset, $window)); if ($fragment === '' || $strlen($fragment) < 40) { if ($offset + $window >= $len) { break; } continue; }
                            if (strpos($bodyPlain, $fragment) !== false) { $result['text_found'] = true; $matchFragment = $fragment; break; }
                            if ($offset + $window >= $len) { break; }
                        }
                    }
                    if (!$result['text_found']) { $sentences = preg_split('~[.!?…]+\s*~u', $sampleNorm) ?: []; $foundParts = []; foreach ($sentences as $sentence) { $sentence = trim($sentence); if ($sentence === '') { continue; } if ($strlen($sentence) < 40) { continue; } if (strpos($bodyPlain, $sentence) !== false) { $foundParts[] = $sentence; if (count($foundParts) >= 2) { $result['text_found'] = true; $matchFragment = implode(' ', array_slice($foundParts, 0, 2)); break; } } } }
                }
                if ($result['text_found'] && $matchFragment !== '') { $result['matched_fragment'] = (function_exists('mb_strlen') ? mb_strlen($matchFragment, 'UTF-8') : strlen($matchFragment)) > 220 ? (function_exists('mb_substr') ? mb_substr($matchFragment, 0, 220, 'UTF-8') : substr($matchFragment, 0, 220)) : $matchFragment; }
            }
        }
        if ($supportsLink && !$result['link_found']) { $result['status'] = 'failed'; $result['reason'] = 'LINK_MISSING'; }
        elseif ($supportsText && !$result['text_found']) { if ($supportsLink && $result['link_found']) { $result['status'] = 'partial'; $result['reason'] = 'TEXT_MISSING'; } else { $result['status'] = 'failed'; $result['reason'] = 'TEXT_MISSING'; } }
        else { $result['status'] = 'success'; }
        return $result;
    }
}

if (!function_exists('pp_analyze_url_data')) {
    function pp_analyze_url_data(string $url): ?array {
        $fetch = pp_http_fetch($url, 12); if (($fetch['status'] ?? 0) >= 400 || ($fetch['body'] ?? '') === '') { return null; }
        $finalUrl = $fetch['final_url'] ?: $url; $headers = $fetch['headers'] ?? []; $body = (string)$fetch['body']; $doc = pp_html_dom($body); if (!$doc) { return null; }
        $xp = pp_xpath($doc);
        $baseHref = ''; $baseEl = $xp->query('//base[@href]')->item(0); if ($baseEl instanceof DOMElement) { $baseHref = pp_attr($baseEl, 'href'); }
        $base = $baseHref !== '' ? $baseHref : $finalUrl;
        $title = ''; $titleEl = $xp->query('//title')->item(0); if ($titleEl) { $title = pp_text($titleEl); }
        $ogTitle = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content | //meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content')->item(0);
        if ($ogTitle && !$title) { $title = trim($ogTitle->nodeValue ?? ''); }
        $desc = ''; $metaDesc = $xp->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0);
        if ($metaDesc) { $desc = trim($metaDesc->nodeValue ?? ''); }
        if ($desc === '') { $ogDesc = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content')->item(0); if ($ogDesc) { $desc = trim($ogDesc->nodeValue ?? ''); } }
        $canonical = ''; $canonEl = $xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href')->item(0);
        if ($canonEl) { $canonical = pp_abs_url(trim($canonEl->nodeValue ?? ''), $base); }
        $lang = ''; $region = '';
        $htmlEl = $xp->query('//html')->item(0); if ($htmlEl instanceof DOMElement) { $langAttr = trim($htmlEl->getAttribute('lang')); if ($langAttr) { $parts = preg_split('~[-_]~', $langAttr); $lang = strtolower($parts[0] ?? ''); if (isset($parts[1])) { $region = strtoupper($parts[1]); } } }
        $hreflangs = []; foreach ($xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang and @href]') as $lnk) { if (!($lnk instanceof DOMElement)) continue; $hl = trim($lnk->getAttribute('hreflang')); $href = pp_abs_url(trim($lnk->getAttribute('href')), $base); if ($hl && $href) { $hreflangs[] = ['hreflang' => $hl, 'href' => $href]; } }
        if (!$lang && !empty($hreflangs)) { $hl0 = $hreflangs[0]['hreflang']; $parts = preg_split('~[-_]~', $hl0); $lang = strtolower($parts[0] ?? ''); if (isset($parts[1])) { $region = strtoupper($parts[1]); } }
        if (!$lang) { $contentLang = $xp->query('//meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-language"]/@content')->item(0); if ($contentLang) { $v = trim($contentLang->nodeValue ?? ''); if ($v) { $parts = preg_split('~[,;\s]+~', $v); $p0 = $parts[0] ?? ''; $pp = preg_split('~[-_]~', $p0); $lang = strtolower($pp[0] ?? ''); if (isset($pp[1])) { $region = strtoupper($pp[1]); } } } }
        $q = function(string $xpath) use ($xp): ?string { $n = $xp->query($xpath)->item(0); return $n ? trim($n->nodeValue ?? '') : null; };
        $published = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:published_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="datePublished"]/@content') ?: $q('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="pubdate"]/@content');
        $modified = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:modified_time"]/@content') ?: $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:updated_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dateModified"]/@content');
        foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
            $json = trim($script->textContent ?? ''); if ($json === '') continue; $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) { $json2 = preg_replace('/,\s*([}\]])/', '$1', $json); $data = json_decode($json2, true); }
            if (is_array($data)) { $stack = [$data]; while ($stack) { $cur = array_pop($stack); if (isset($cur['datePublished']) && !$published) { $published = (string)$cur['datePublished']; } if (isset($cur['dateModified']) && !$modified) { $modified = (string)$cur['dateModified']; } foreach ($cur as $v) { if (is_array($v)) $stack[] = $v; } } }
            if ($published && $modified) break;
        }
        if (!$modified && !empty($headers['last-modified'])) { $modified = $headers['last-modified']; }
        return ['final_url' => $finalUrl, 'lang' => $lang, 'region' => $region, 'title' => $title, 'description' => $desc, 'canonical' => $canonical, 'published_time' => $published, 'modified_time' => $modified, 'hreflang' => $hreflangs, ];
    }
}

?>
