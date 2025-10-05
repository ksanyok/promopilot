<?php
// Multi-level promotion (cascade publications with reporting)

if (!function_exists('pp_promotion_log')) {
    function pp_promotion_log(string $message, array $context = []): void {
        try {
            $dir = PP_ROOT_PATH . '/logs';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (!is_dir($dir) || !is_writable($dir)) { return; }
            $file = $dir . '/promotion.log';
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
            if (!empty($context)) {
                $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($json !== false) {
                    $line .= ' ' . $json;
                }
            }
            $line .= "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // swallow logging errors
        }
    }
}

if (!function_exists('pp_promotion_ensure_crowd_payload_column')) {
    function pp_promotion_ensure_crowd_payload_column(\mysqli $conn): bool {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $available = false;

        try {
            if ($res = @$conn->query("SHOW COLUMNS FROM `promotion_crowd_tasks` LIKE 'payload_json'")) {
                $available = ($res->num_rows > 0);
                $res->free();
                if ($available) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            pp_promotion_log('promotion.schema_check_failed', [
                'table' => 'promotion_crowd_tasks',
                'column' => 'payload_json',
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $alterSql = "ALTER TABLE `promotion_crowd_tasks` ADD COLUMN `payload_json` LONGTEXT NULL AFTER `result_url`";
            $alterOk = @$conn->query($alterSql);
            if ($alterOk) {
                $available = true;
            } else {
                $errno = $conn->errno;
                if ($errno === 1060) { // duplicate column
                    $available = true;
                } else {
                    pp_promotion_log('promotion.schema_alter_failed', [
                        'table' => 'promotion_crowd_tasks',
                        'column' => 'payload_json',
                        'errno' => $errno,
                        'error' => $conn->error,
                    ]);
                }
            }
        } catch (Throwable $e) {
            pp_promotion_log('promotion.schema_alter_exception', [
                'table' => 'promotion_crowd_tasks',
                'column' => 'payload_json',
                'error' => $e->getMessage(),
            ]);
        }

        if (!$available) {
            pp_promotion_log('promotion.schema_column_unavailable', [
                'table' => 'promotion_crowd_tasks',
                'column' => 'payload_json',
            ]);
        }

        return $available;
    }
}

if (!function_exists('pp_promotion_fetch_article_html')) {
    function pp_promotion_fetch_article_html(string $url, int $timeoutSeconds = 12, int $maxBytes = 262144): ?string {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $maxBytes = max(10240, $maxBytes);
        $timeoutSeconds = max(3, min(30, $timeoutSeconds));
        $buffer = '';
        $fetchWithCurl = static function() use ($url, $timeoutSeconds, $maxBytes, &$buffer): ?string {
            if (!function_exists('curl_init')) { return null; }
            $ch = @curl_init($url);
            if (!$ch) { return null; }
            $userAgent = 'PromoPilotBot/1.0 (+https://promopilot.ai)';
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(6, $timeoutSeconds),
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_ENCODING => 'gzip,deflate',
                CURLOPT_WRITEFUNCTION => static function($ch, $chunk) use (&$buffer, $maxBytes) {
                    if (!is_string($chunk) || $chunk === '') { return 0; }
                    $remaining = $maxBytes - strlen($buffer);
                    if ($remaining <= 0) { return 0; }
                    $buffer .= substr($chunk, 0, $remaining);
                    return strlen($chunk);
                },
            ]);
            $ok = @curl_exec($ch);
            $status = (int)@curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            @curl_close($ch);
            if ($ok === false || $status >= 400 || $status === 0) {
                return null;
            }
            return $buffer !== '' ? $buffer : null;
        };
        $html = $fetchWithCurl();
        if ($html !== null) { return $html; }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: PromoPilotBot/1.0 (+https://promopilot.ai)\r\nAccept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n",
                'timeout' => $timeoutSeconds,
            ],
            'https' => [
                'method' => 'GET',
                'header' => "User-Agent: PromoPilotBot/1.0 (+https://promopilot.ai)\r\nAccept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n",
                'timeout' => $timeoutSeconds,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $handle = @fopen($url, 'rb', false, $context);
        if (!$handle) { return null; }
        $data = '';
        while (!feof($handle) && strlen($data) < $maxBytes) {
            $chunk = @fread($handle, min(8192, $maxBytes - strlen($data)));
            if ($chunk === false) { break; }
            $data .= $chunk;
        }
        @fclose($handle);
        return $data !== '' ? $data : null;
    }
}

if (!function_exists('pp_promotion_clean_text')) {
    function pp_promotion_clean_text(string $text): string {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('~\s+~u', ' ', $decoded ?? '');
        return trim($collapsed ?? '');
    }
}

if (!function_exists('pp_promotion_extract_keywords')) {
    function pp_promotion_extract_keywords(string $text, int $limit = 8): array {
        $limit = max(1, min(16, $limit));
        $normalized = mb_strtolower($text, 'UTF-8');
        $words = preg_split('~[^\p{L}\p{N}]+~u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || empty($words)) { return []; }
        $stopwords = [
            'ru' => ['ещё','ещё','это','оно','она','они','они','который','где','когда','как','для','или','если','только','также','теперь','по','при','без','над','под','через','к','от','до','из','что','чтобы','про','эти','подобно','между','между','есть','был','будет','так','его','её','могут','может'],
            'en' => ['the','and','that','with','from','this','there','their','which','about','into','after','before','where','when','will','would','should','could','have','been','being','just','than','then','them','they','your','yours','ours','over','such','only','some','most','more','very','also'],
        ];
        $genericStop = array_merge($stopwords['ru'], $stopwords['en'], ['http','https','www','com','net','org']);
        $counts = [];
        foreach ($words as $word) {
            if ($word === '' || mb_strlen($word, 'UTF-8') < 4) { continue; }
            if (in_array($word, $genericStop, true)) { continue; }
            if (!isset($counts[$word])) { $counts[$word] = 0; }
            $counts[$word]++;
        }
        if (empty($counts)) { return []; }
        arsort($counts);
        return array_slice(array_keys($counts), 0, $limit);
    }
}

if (!function_exists('pp_promotion_extract_theme_phrases')) {
    function pp_promotion_extract_theme_phrases(string $text, int $limit = 6): array {
        $limit = max(1, min(10, $limit));
        $clean = trim(preg_replace('~[\r\n]+~u', ' ', $text));
        if ($clean === '') { return []; }
        $tokensRaw = preg_split('~\s+~u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokensRaw) || empty($tokensRaw)) { return []; }

        $stopwords = [
            'ru' => ['ещё','это','оно','она','они','который','где','когда','как','для','или','если','только','также','теперь','по','при','без','над','под','через','к','от','до','из','что','чтобы','про','эти','между','есть','был','будет','так','его','её','могут','может','над','под','того','этом','эту','эта','этих','там','здесь','тем','ним','ней','нам','вам','кто','кого','чем','ним','наш','ваш','их','его','ее'],
            'en' => ['the','and','that','with','from','this','there','their','which','about','into','after','before','where','when','will','would','should','could','have','been','being','just','than','then','them','they','your','yours','ours','over','such','only','some','most','more','very','also','another','other','others','many','much','each','every','any','lot','lots','make','made','make','made'],
        ];
        $genericStop = array_merge($stopwords['ru'], $stopwords['en'], ['http','https','www','com','net','org','html','href']);

        $tokens = [];
        foreach ($tokensRaw as $raw) {
            $normalized = trim($raw, " \t\-_:;.,!?".")" . '\"');
            if ($normalized === '') { continue; }
            $lower = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
            $lenCheck = function_exists('mb_strlen') ? mb_strlen($lower, 'UTF-8') : strlen($lower);
            if ($lenCheck < 3) { continue; }
            if (in_array($lower, $genericStop, true)) { continue; }
            $tokens[] = ['original' => $normalized, 'lower' => $lower];
        }
        if (empty($tokens)) { return []; }

        $candidates = [];
        $windowSizes = [3, 2];
        $tokenCount = count($tokens);
        foreach ($windowSizes as $size) {
            if ($tokenCount < $size) { continue; }
            for ($i = 0; $i <= $tokenCount - $size; $i++) {
                $slice = array_slice($tokens, $i, $size);
                $phraseLowerParts = array_column($slice, 'lower');
                if (count(array_unique($phraseLowerParts)) < $size) { continue; }
                $phraseLower = implode(' ', $phraseLowerParts);
                if (array_intersect($phraseLowerParts, $genericStop)) { continue; }
                if (!isset($candidates[$phraseLower])) {
                    $candidates[$phraseLower] = ['score' => 0, 'samples' => []];
                }
                $candidates[$phraseLower]['score']++;
                $originalPhrase = implode(' ', array_column($slice, 'original'));
                $candidates[$phraseLower]['samples'][$originalPhrase] = true;
            }
        }

        if (empty($candidates)) {
            $topSingles = array_slice(array_unique(array_column($tokens, 'original')), 0, $limit);
            return $topSingles;
        }

        uasort($candidates, static function(array $a, array $b) {
            if ($a['score'] === $b['score']) { return 0; }
            return ($a['score'] > $b['score']) ? -1 : 1;
        });

        $phrases = [];
        foreach ($candidates as $info) {
            foreach (array_keys($info['samples']) as $sample) {
                $phrases[] = $sample;
                if (count($phrases) >= $limit) { break 2; }
            }
        }

        return $phrases;
    }
}

if (!function_exists('pp_promotion_compact_context')) {
    function pp_promotion_compact_context($context): ?array {
        if (!is_array($context)) { return null; }
        $summary = isset($context['summary']) ? (string)$context['summary'] : '';
        if ($summary !== '') {
            if (function_exists('mb_strlen')) {
                if (mb_strlen($summary, 'UTF-8') > 600) {
                    $summary = rtrim(mb_substr($summary, 0, 600, 'UTF-8')) . '…';
                }
            } elseif (strlen($summary) > 600) {
                $summary = rtrim(substr($summary, 0, 600)) . '…';
            }
        }
        $headings = [];
        if (!empty($context['headings']) && is_array($context['headings'])) {
            foreach ($context['headings'] as $heading) {
                $headingText = trim((string)$heading);
                if ($headingText === '') { continue; }
                $headings[] = $headingText;
                if (count($headings) >= 5) { break; }
            }
        }
        $keywords = [];
        if (!empty($context['keywords']) && is_array($context['keywords'])) {
            foreach ($context['keywords'] as $word) {
                $wordText = trim((string)$word);
                if ($wordText === '') { continue; }
                $keywords[] = $wordText;
                if (count($keywords) >= 8) { break; }
            }
        }
        $excerpt = '';
        if (!empty($context['excerpt'])) {
            $excerpt = trim((string)$context['excerpt']);
            if ($excerpt !== '') {
                if (function_exists('mb_substr')) {
                    if (mb_strlen($excerpt, 'UTF-8') > 900) {
                        $excerpt = rtrim(mb_substr($excerpt, 0, 900, 'UTF-8')) . '…';
                    }
                } elseif (strlen($excerpt) > 900) {
                    $excerpt = rtrim(substr($excerpt, 0, 900)) . '…';
                }
            }
        }

        return [
            'url' => (string)($context['url'] ?? ''),
            'title' => trim((string)($context['title'] ?? '')),
            'description' => trim((string)($context['description'] ?? '')),
            'summary' => $summary,
            'headings' => $headings,
            'keywords' => $keywords,
            'language' => trim((string)($context['language'] ?? '')),
            'excerpt' => $excerpt,
        ];
    }
}

if (!function_exists('pp_promotion_get_article_cache_dir')) {
    function pp_promotion_get_article_cache_dir(): ?string {
        if (!defined('PP_ROOT_PATH')) { return null; }
        $dir = rtrim(PP_ROOT_PATH, '\\/') . '/.cache/promotion_articles';
        if (!@is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !@is_dir($dir)) {
                pp_promotion_log('promotion.cache.create_failed', ['dir' => $dir]);
                return null;
            }
        }
        return $dir;
    }
}

if (!function_exists('pp_promotion_cache_path_for_node')) {
    function pp_promotion_cache_path_for_node(int $nodeId): ?string {
        if ($nodeId <= 0) { return null; }
        $dir = pp_promotion_get_article_cache_dir();
        if ($dir === null) { return null; }
        return $dir . '/node-' . $nodeId . '.json';
    }
}

if (!function_exists('pp_promotion_store_cached_article')) {
    function pp_promotion_store_cached_article(int $nodeId, array $article, array $meta = []): ?string {
        if ($nodeId <= 0) { return null; }
        $path = pp_promotion_cache_path_for_node($nodeId);
        if ($path === null) { return null; }
        $payload = [
            'node_id' => $nodeId,
            'title' => (string)($article['title'] ?? ''),
            'htmlContent' => (string)($article['htmlContent'] ?? ''),
            'language' => (string)($article['language'] ?? ''),
            'linkStats' => $article['linkStats'] ?? null,
            'plainText' => (string)($article['plainText'] ?? ''),
            'stored_at' => gmdate('c'),
        ];
        foreach ($meta as $key => $value) {
            if (is_string($key) && $key !== '') {
                $payload[$key] = $value;
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { return null; }
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            pp_promotion_log('promotion.cache.write_failed', ['node_id' => $nodeId, 'path' => $path]);
            return null;
        }
        return $path;
    }
}

if (!function_exists('pp_promotion_load_cached_article')) {
    function pp_promotion_load_cached_article(int $nodeId): ?array {
        if ($nodeId <= 0) { return null; }
        $path = pp_promotion_cache_path_for_node($nodeId);
        if ($path === null || !@is_file($path)) { return null; }
        $json = @file_get_contents($path);
        if (!is_string($json) || $json === '') { return null; }
        $data = json_decode($json, true);
        if (!is_array($data)) { return null; }
        return $data;
    }
}

if (!function_exists('pp_promotion_generate_child_anchor')) {
    function pp_promotion_generate_child_anchor(?array $article, string $language, string $fallback = ''): string {
        $lang = strtolower(substr(trim($language), 0, 2));
        $title = trim((string)($article['title'] ?? ''));
        if ($title === '') {
            $title = trim($fallback) !== '' ? trim($fallback) : __('Материал');
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($title, 'UTF-8') > 55) {
                $title = rtrim(mb_substr($title, 0, 55, 'UTF-8')) . '…';
            }
        } elseif (strlen($title) > 55) {
            $title = rtrim(substr($title, 0, 55)) . '…';
        }
        $templatesRu = ['Обзор: %s', 'Разбор темы %s', 'Подборка по %s', 'Что важно о %s', 'Инсайты по %s'];
        $templatesEn = ['Deep dive: %s', 'Insights on %s', 'Guide to %s', 'Key takeaways on %s', 'Highlights about %s'];
        $pool = $lang === 'en' ? $templatesEn : $templatesRu;
        try {
            $template = $pool[random_int(0, count($pool) - 1)];
        } catch (Throwable $e) {
            $template = $pool[0];
        }
        $anchor = sprintf($template, $title);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($anchor, 'UTF-8') > 64) {
                $anchor = rtrim(mb_substr($anchor, 0, 64, 'UTF-8')) . '…';
            }
        } elseif (strlen($anchor) > 64) {
            $anchor = rtrim(substr($anchor, 0, 64)) . '…';
        }
        return $anchor !== '' ? $anchor : ($fallback !== '' ? $fallback : __('Подробнее'));
    }
}

if (!function_exists('pp_promotion_prepare_child_article')) {
    function pp_promotion_prepare_child_article(array $parentArticle, string $newLinkUrl, string $sourceUrl, string $language, string $anchorText): ?array {
        $html = (string)($parentArticle['htmlContent'] ?? '');
        if ($html === '') { return null; }
        $anchorText = trim($anchorText);
        if ($anchorText === '') { $anchorText = __('Подробнее'); }
        $sourceUrl = trim($sourceUrl);
        $newLinkUrl = trim($newLinkUrl);
        $anchorSafe = htmlspecialchars($anchorText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $newUrlSafe = htmlspecialchars($newLinkUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $updatedHtml = $html;
        $replaced = 0;
        if ($sourceUrl !== '') {
            $pattern = '~<a\s+[^>]*href=["\']' . preg_quote($sourceUrl, '~') . '["\'][^>]*>(.*?)</a>~iu';
            $replacement = '<a href="' . $newUrlSafe . '">' . $anchorSafe . '</a>';
            $updatedHtml = preg_replace($pattern, $replacement, $updatedHtml, 1, $replaced);
        }
        if ($replaced === 0 && $newLinkUrl !== '') {
            $prepend = '<p><a href="' . $newUrlSafe . '">' . $anchorSafe . '</a></p>';
            $updatedHtml = $prepend . $updatedHtml;
        }
        $title = trim((string)($parentArticle['title'] ?? ''));
        if ($title !== '') {
            $suffix = (stripos($language, 'en') === 0) ? ' — repost' : ' — обзор';
            if (function_exists('mb_stripos')) {
                if (mb_stripos($title, trim($suffix, ' —'), 0, 'UTF-8') === false) {
                    $title .= $suffix;
                }
            } elseif (stripos($title, trim($suffix, ' —')) === false) {
                $title .= $suffix;
            }
        }
        $plain = trim(strip_tags($updatedHtml));
        return [
            'title' => $title !== '' ? $title : $anchorText,
            'htmlContent' => $updatedHtml,
            'language' => $language,
            'plainText' => $plain,
        ];
    }
}

if (!function_exists('pp_promotion_get_article_context')) {
    function pp_promotion_get_article_context(string $url): ?array {
        static $cache = [];
        $normalizedUrl = trim($url);
        if ($normalizedUrl === '' || !filter_var($normalizedUrl, FILTER_VALIDATE_URL)) {
            return null;
        }
        if (array_key_exists($normalizedUrl, $cache)) {
            return $cache[$normalizedUrl];
        }
        $html = pp_promotion_fetch_article_html($normalizedUrl);
        if ($html === null) {
            $cache[$normalizedUrl] = null;
            return null;
        }
        if (!function_exists('mb_detect_encoding')) {
            $encoding = null;
        } else {
            $encoding = @mb_detect_encoding($html, ['UTF-8', 'Windows-1251', 'ISO-8859-1', 'ISO-8859-15', 'CP1252'], true) ?: null;
        }
        if ($encoding && strtoupper($encoding) !== 'UTF-8') {
            $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
            if (is_string($converted) && $converted !== '') {
                $html = $converted;
            }
        }
        $fullContext = ['url' => $normalizedUrl, 'title' => '', 'description' => '', 'summary' => '', 'keywords' => [], 'headings' => [], 'language' => ''];
        if (trim($html) === '') {
            $cache[$normalizedUrl] = pp_promotion_compact_context($fullContext);
            return $cache[$normalizedUrl];
        }
        if (!class_exists('DOMDocument')) {
            $text = strip_tags($html);
            $text = pp_promotion_clean_text($text);
            $fullContext['summary'] = function_exists('mb_substr') ? mb_substr($text, 0, 480, 'UTF-8') : substr($text, 0, 480);
            $fullContext['keywords'] = pp_promotion_extract_keywords($text);
            $cache[$normalizedUrl] = pp_promotion_compact_context($fullContext);
            return $cache[$normalizedUrl];
        }
        $htmlWrapper = '<!DOCTYPE html><html>' . $html . '</html>';
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($htmlWrapper);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $lang = '';
        $langNode = $xpath->query('//html/@lang')->item(0);
        if ($langNode) { $lang = trim((string)$langNode->nodeValue); }
        if ($lang === '') {
            $metaLang = $xpath->query('//meta[@http-equiv="content-language" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="language"]/@content')->item(0);
            if ($metaLang) { $lang = trim((string)$metaLang->nodeValue); }
        }

        $title = '';
        $ogTitle = $xpath->query('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="title"]/@content')->item(0);
        if ($ogTitle) { $title = pp_promotion_clean_text($ogTitle->nodeValue ?? ''); }
        if ($title === '') {
            $titleNode = $xpath->query('//title')->item(0);
            if ($titleNode) { $title = pp_promotion_clean_text($titleNode->textContent ?? ''); }
        }
        if ($title === '') {
            $h1 = $xpath->query('//h1')->item(0);
            if ($h1) { $title = pp_promotion_clean_text($h1->textContent ?? ''); }
        }

        $description = '';
        $metaDesc = $xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description" or translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content')->item(0);
        if ($metaDesc) { $description = pp_promotion_clean_text($metaDesc->nodeValue ?? ''); }

        $paragraphs = [];
        $nodes = $xpath->query('//p | //li[(normalize-space(text()) != "") and not(ancestor::nav)]');
        foreach ($nodes as $node) {
            $text = pp_promotion_clean_text($node->textContent ?? '');
            if ($text === '') { continue; }
            $paragraphs[] = $text;
            if (count($paragraphs) >= 10) { break; }
        }
        if (empty($paragraphs)) {
            $fallbackNodes = $xpath->query('//div[contains(@class,"article") or contains(@class,"content")]//text()');
            $buffer = [];
            foreach ($fallbackNodes as $node) {
                $text = pp_promotion_clean_text($node->textContent ?? '');
                if ($text === '') { continue; }
                $buffer[] = $text;
                if (count($buffer) >= 10) { break; }
            }
            $paragraphs = $buffer;
        }

        $summaryPieces = array_slice($paragraphs, 0, 3);
        $summary = trim(implode(' ', $summaryPieces));
        if ($summary === '' && !empty($paragraphs)) {
            $summary = trim($paragraphs[0]);
        }

        $excerptPieces = array_slice($paragraphs, 0, 5);
        $excerpt = trim(implode("\n", $excerptPieces));

        $headingNodes = $xpath->query('//h1 | //h2 | //h3');
        $headings = [];
        foreach ($headingNodes as $hNode) {
            $text = pp_promotion_clean_text($hNode->textContent ?? '');
            if ($text === '') { continue; }
            $headings[] = $text;
            if (count($headings) >= 6) { break; }
        }

        $fullText = implode(' ', array_slice($paragraphs, 0, 12));
        $keywords = pp_promotion_extract_keywords($fullText);

        $fullContext['title'] = $title;
        $fullContext['description'] = $description;
        $fullContext['summary'] = $summary;
        $fullContext['keywords'] = $keywords;
        $fullContext['headings'] = $headings;
        $fullContext['language'] = $lang;
        $fullContext['excerpt'] = $excerpt !== '' ? $excerpt : $summary;

        $compact = pp_promotion_compact_context($fullContext);
        $cache[$normalizedUrl] = $compact;
        return $compact;
    }
}

if (!function_exists('pp_promotion_settings')) {
    function pp_promotion_settings(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $defaults = [
            'level1_enabled' => true,
            'level2_enabled' => true,
            'level3_enabled' => false,
            'crowd_enabled' => true,
            'level1_count' => 5,
            'level2_per_level1' => 10,
            'level3_per_level2' => 5,
            'level1_min_len' => 2800,
            'level1_max_len' => 3400,
            'level2_min_len' => 1400,
            'level2_max_len' => 2100,
            'level3_min_len' => 900,
            'level3_max_len' => 1400,
            'crowd_per_article' => 100,
            'network_repeat_limit' => 2,
            'price_per_link' => max(0, (float)str_replace(',', '.', (string)get_setting('promotion_price_per_link', '0'))),
        ];
        $map = [
            'promotion_level1_enabled' => 'level1_enabled',
            'promotion_level2_enabled' => 'level2_enabled',
            'promotion_level3_enabled' => 'level3_enabled',
            'promotion_crowd_enabled' => 'crowd_enabled',
        ];
        foreach ($map as $settingKey => $localKey) {
            $raw = get_setting($settingKey, $defaults[$localKey] ? '1' : '0');
            $cacheBool = !in_array(strtolower((string)$raw), ['0', 'false', 'no', 'off', ''], true);
            $defaults[$localKey] = $cacheBool;
        }
        $level1CountSetting = (int)get_setting('promotion_level1_count', (string)$defaults['level1_count']);
        if ($level1CountSetting > 0) {
            $defaults['level1_count'] = max(1, min(500, $level1CountSetting));
        }
        $level2PerSetting = (int)get_setting('promotion_level2_per_level1', (string)$defaults['level2_per_level1']);
        if ($level2PerSetting > 0) {
            $defaults['level2_per_level1'] = max(1, min(500, $level2PerSetting));
        }
        $level3PerSetting = (int)get_setting('promotion_level3_per_level2', (string)$defaults['level3_per_level2']);
        if ($level3PerSetting > 0) {
            $defaults['level3_per_level2'] = max(1, min(500, $level3PerSetting));
        }
        $crowdPerSetting = (int)get_setting('promotion_crowd_per_article', (string)$defaults['crowd_per_article']);
        if ($crowdPerSetting >= 0) {
            $defaults['crowd_per_article'] = max(0, min(10000, $crowdPerSetting));
        }
        // cache
        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('pp_promotion_is_level_enabled')) {
    function pp_promotion_is_level_enabled(int $level): bool {
        $settings = pp_promotion_settings();
        if ($level === 1) { return !empty($settings['level1_enabled']); }
        if ($level === 2) { return !empty($settings['level1_enabled']) && !empty($settings['level2_enabled']); }
        if ($level === 3) { return !empty($settings['level1_enabled']) && !empty($settings['level2_enabled']) && !empty($settings['level3_enabled']); }
        return false;
    }
}

if (!function_exists('pp_promotion_is_crowd_enabled')) {
    function pp_promotion_is_crowd_enabled(): bool {
        $settings = pp_promotion_settings();
        return !empty($settings['crowd_enabled']);
    }
}

if (!function_exists('pp_promotion_fetch_link_row')) {
    function pp_promotion_fetch_link_row(mysqli $conn, int $projectId, string $url): ?array {
        $stmt = $conn->prepare('SELECT id, url, anchor, language, wish FROM project_links WHERE project_id = ? AND url = ? LIMIT 1');
        if (!$stmt) { return null; }
        $stmt->bind_param('is', $projectId, $url);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('pp_promotion_get_active_run')) {
    function pp_promotion_get_active_run(mysqli $conn, int $projectId, string $url): ?array {
    $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE project_id = ? AND target_url = ? AND status IN (\'queued\',\'running\',\'pending_level1\',\'level1_active\',\'pending_level2\',\'level2_active\',\'pending_level3\',\'level3_active\',\'pending_crowd\',\'crowd_ready\') ORDER BY id DESC LIMIT 1');
        if (!$stmt) { return null; }
        $stmt->bind_param('is', $projectId, $url);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('pp_promotion_start_run')) {
    function pp_promotion_start_run(int $projectId, string $url, int $userId): array {
        if (!pp_promotion_is_level_enabled(1)) {
            return ['ok' => false, 'error' => 'LEVEL1_DISABLED'];
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB'];
        }
        $conn->set_charset('utf8mb4');
        $linkRow = pp_promotion_fetch_link_row($conn, $projectId, $url);
        if (!$linkRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'URL_NOT_FOUND'];
        }
        $active = pp_promotion_get_active_run($conn, $projectId, $url);
        if ($active) {
            pp_promotion_log('promotion.run_reused', [
                'project_id' => $projectId,
                'target_url' => $url,
                'run_id' => (int)$active['id'],
                'status' => (string)($active['status'] ?? ''),
            ]);
            $conn->close();
            return ['ok' => true, 'status' => 'running', 'run_id' => (int)$active['id']];
        }
        $settings = pp_promotion_settings();
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
        $basePrice = max(0, (float)($settings['price_per_link'] ?? 0));

        $runId = 0;
        $chargedAmount = 0.0;
        $discountPercent = 0.0;
        try {
            $conn->begin_transaction();
            $userStmt = $conn->prepare('SELECT balance, promotion_discount FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$userStmt) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $userStmt->bind_param('i', $userId);
            if (!$userStmt->execute()) {
                $userStmt->close();
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $userRow = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if (!$userRow) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'USER_NOT_FOUND'];
            }

            $balance = (float)$userRow['balance'];
            $discountPercent = max(0.0, min(100.0, (float)($userRow['promotion_discount'] ?? 0)));
            $chargedAmount = max(0.0, round($basePrice * (1 - $discountPercent / 100), 2));
            $balanceAfter = $balance;

            if ($chargedAmount > 0 && ($balance + 1e-6) < $chargedAmount) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'INSUFFICIENT_FUNDS'];
            }

            if ($chargedAmount > 0) {
                $upd = $conn->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
                if (!$upd) {
                    $conn->rollback();
                    $conn->close();
                    return ['ok' => false, 'error' => 'DB'];
                }
                $upd->bind_param('di', $chargedAmount, $userId);
                if (!$upd->execute()) {
                    $upd->close();
                    $conn->rollback();
                    $conn->close();
                    return ['ok' => false, 'error' => 'DB'];
                }
                $upd->close();
                $balanceAfter = max(0.0, round($balance - $chargedAmount, 2));
            }

            $stmt = $conn->prepare('INSERT INTO promotion_runs (project_id, link_id, target_url, status, stage, initiated_by, settings_snapshot, charged_amount, discount_percent) VALUES (?, ?, ?, \'queued\', \'pending_level1\', ?, ?, ?, ?)');
            if (!$stmt) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $linkId = (int)$linkRow['id'];
            $stmt->bind_param('iisisdd', $projectId, $linkId, $url, $userId, $settingsJson, $chargedAmount, $discountPercent);
            if (!$stmt->execute()) {
                $stmt->close();
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $runId = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $rollbackIgnored) {}
            $conn->close();
            return ['ok' => false, 'error' => 'DB'];
        }
        $conn->close();
        if ($runId > 0) {
            pp_promotion_log('promotion.run_created', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'link_id' => (int)$linkRow['id'],
                'target_url' => $url,
                'settings' => [
                    'level1_count' => (int)($settings['level1_count'] ?? 0),
                    'network_repeat_limit' => (int)($settings['network_repeat_limit'] ?? 0),
                    'level2_enabled' => !empty($settings['level2_enabled']),
                    'crowd_enabled' => !empty($settings['crowd_enabled']),
                ],
                'charged' => $chargedAmount,
                'discount_percent' => $discountPercent,
            ]);
        }
        $launched = pp_promotion_launch_worker($runId);
        if (!$launched) {
            pp_promotion_log('promotion.worker.launch_failed', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'target_url' => $url,
            ]);
        }
        // Ensure immediate processing even if background launch is not supported in the environment
        try {
            pp_promotion_worker($runId, 10);
        } catch (Throwable $e) {
            pp_promotion_log('promotion.worker.inline_error', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
        $balanceAfterFormatted = function_exists('format_currency') ? format_currency($balanceAfter) : number_format($balanceAfter, 2, '.', '');
        return [
            'ok' => true,
            'status' => 'queued',
            'run_id' => $runId,
            'charged' => $chargedAmount,
            'discount' => $discountPercent,
            'balance_after' => $balanceAfter,
            'balance_after_formatted' => $balanceAfterFormatted,
        ];
    }
}

if (!function_exists('pp_promotion_cancel_run')) {
    function pp_promotion_cancel_run(int $projectId, string $url, int $userId): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }
        $run = null;
        $stmt = $conn->prepare('SELECT id, status FROM promotion_runs WHERE project_id = ? AND target_url = ? AND status NOT IN (\'completed\',\'failed\',\'cancelled\') ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('is', $projectId, $url);
            if ($stmt->execute()) {
                $run = $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();
        }
        if (!$run) {
            $conn->close();
            return ['ok' => false, 'error' => 'NOT_FOUND'];
        }
        $runId = (int)$run['id'];
        @$conn->query("UPDATE promotion_runs SET status='cancelled', stage='cancelled', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
        @$conn->query("UPDATE promotion_nodes SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id=" . $runId . " AND status IN ('queued','running')");
        // Request cancellation for publications
        @$conn->query("UPDATE publications p JOIN promotion_nodes pn ON pn.publication_id = p.id SET p.cancel_requested = 1 WHERE pn.run_id=" . $runId . " AND p.status IN ('queued','running')");
        $conn->close();
        return ['ok' => true, 'status' => 'cancelled'];
    }
}

if (!function_exists('pp_promotion_launch_worker')) {
    function pp_promotion_launch_worker(?int $runId = null): bool {
        $script = PP_ROOT_PATH . '/scripts/promotion_worker.php';
        if (!is_file($script)) { return false; }
        $phpBinary = PHP_BINARY ?: 'php';
        $args = $runId ? ' ' . (int)$runId : '';
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $success = false;
        if ($isWindows) {
            $cmd = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . $args;
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                $success = true;
            }
            return $success;
        }
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . $args . ' > /dev/null 2>&1 &';
        if (function_exists('popen')) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                $success = true;
            }
        }
        if (!$success) {
            $execResult = @exec($cmd, $output, $status);
            if ($status === 0) {
                $success = true;
            }
        }
        return $success;
    }
}

if (!function_exists('pp_promotion_get_level_requirements')) {
    function pp_promotion_get_level_requirements(): array {
        $settings = pp_promotion_settings();
        return [
            1 => ['count' => max(1, (int)$settings['level1_count']), 'min_len' => (int)$settings['level1_min_len'], 'max_len' => (int)$settings['level1_max_len']],
            2 => ['per_parent' => max(1, (int)$settings['level2_per_level1']), 'min_len' => (int)$settings['level2_min_len'], 'max_len' => (int)$settings['level2_max_len']],
            3 => ['per_parent' => max(1, (int)$settings['level3_per_level2']), 'min_len' => (int)$settings['level3_min_len'], 'max_len' => (int)$settings['level3_max_len']],
        ];
    }
}

if (!function_exists('pp_promotion_fetch_project')) {
    function pp_promotion_fetch_project(mysqli $conn, int $projectId): ?array {
        $stmt = $conn->prepare('SELECT id, name, language, wishes, region, topic FROM projects WHERE id = ? LIMIT 1');
        if (!$stmt) { return null; }
        $stmt->bind_param('i', $projectId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('pp_promotion_pick_networks')) {
    function pp_promotion_pick_networks(int $level, int $count, array $project, array &$usage): array {
        $count = (int)$count;
        if ($count <= 0) { return []; }

        $networks = pp_get_networks(true, false);
        $region = strtolower(trim((string)($project['region'] ?? '')));
        $topic = strtolower(trim((string)($project['topic'] ?? '')));
        $levelStr = (string)$level;
        $usageLimit = (int)(pp_promotion_settings()['network_repeat_limit'] ?? 0);
        if ($usageLimit < 0) { $usageLimit = 0; }

        $catalog = [];
        foreach ($networks as $net) {
            $levelsRaw = (string)($net['level'] ?? '');
            if ($levelsRaw !== '') {
                $levelsList = array_filter(array_map('trim', explode(',', $levelsRaw)));
                if (!in_array($levelStr, $levelsList, true)) { continue; }
            } elseif ($level !== 1) {
                // networks without explicit level only suitable for level 1 by default
                continue;
            }
            $slug = (string)($net['slug'] ?? '');
            if ($slug === '') { continue; }
            if (!isset($usage[$slug])) { $usage[$slug] = 0; }
            $baseScore = (int)($net['priority'] ?? 0);
            $metaRegions = [];
            if (!empty($net['regions']) && is_array($net['regions'])) {
                $metaRegions = array_map(static function($item) { return strtolower(trim((string)$item)); }, $net['regions']);
            }
            $metaTopics = [];
            if (!empty($net['topics']) && is_array($net['topics'])) {
                $metaTopics = array_map(static function($item) { return strtolower(trim((string)$item)); }, $net['topics']);
            }
            if ($region !== '') {
                if (in_array($region, $metaRegions, true)) { $baseScore += 2000; }
                elseif (in_array('global', $metaRegions, true)) { $baseScore += 500; }
            }
            if ($topic !== '') {
                if (in_array($topic, $metaTopics, true)) { $baseScore += 1200; }
            }
            $catalog[$slug] = [
                'network' => $net,
                'baseScore' => $baseScore,
                'regions' => $metaRegions,
                'topics' => $metaTopics,
            ];
        }

        $selected = [];
        $allowRepeats = false;
        for ($i = 0; $i < $count; $i++) {
            $candidates = [];
            foreach ($catalog as $slug => $meta) {
                $used = (int)($usage[$slug] ?? 0);
                if (!$allowRepeats && $usageLimit > 0 && $used >= $usageLimit) { continue; }
                $score = $meta['baseScore'];
                if ($used > 0) { $score -= $used * 250; }
                if ($allowRepeats && $usageLimit > 0 && $used >= $usageLimit) {
                    $score -= 1200 + ($used * 150);
                }
                try {
                    $score += random_int(0, 250);
                } catch (Throwable $e) {
                    $score += mt_rand(0, 250);
                }
                $candidates[] = ['slug' => $slug, 'score' => $score, 'network' => $meta['network']];
            }
            if (empty($candidates)) {
                if (!$allowRepeats && $usageLimit > 0) {
                    $allowRepeats = true;
                    $i--;
                    continue;
                }
                break;
            }
            usort($candidates, static function(array $a, array $b) {
                if ($a['score'] === $b['score']) { return strcmp($a['slug'], $b['slug']); }
                return $a['score'] < $b['score'] ? 1 : -1;
            });
            $choice = $candidates[0];
            $selected[] = $choice['network'];
            $usage[$choice['slug']] = (int)($usage[$choice['slug']] ?? 0) + 1;
        }

        return $selected;
    }
}

if (!function_exists('pp_promotion_update_progress')) {
    function pp_promotion_update_progress(mysqli $conn, int $runId): void {
        $levelCounters = [];
        if ($res = @$conn->query('SELECT level, status, COUNT(*) AS c FROM promotion_nodes WHERE run_id = ' . (int)$runId . ' GROUP BY level, status')) {
            while ($row = $res->fetch_assoc()) {
                $level = (int)($row['level'] ?? 0);
                $status = (string)($row['status'] ?? '');
                $count = (int)($row['c'] ?? 0);
                if (!isset($levelCounters[$level])) {
                    $levelCounters[$level] = ['attempted' => 0, 'success' => 0, 'failed' => 0];
                }
                $levelCounters[$level]['attempted'] += $count;
                if (in_array($status, ['success','completed'], true)) {
                    $levelCounters[$level]['success'] += $count;
                } elseif (in_array($status, ['failed','cancelled'], true)) {
                    $levelCounters[$level]['failed'] += $count;
                }
            }
            $res->free();
        }

        $level1Success = (int)($levelCounters[1]['success'] ?? 0);
        $level1Required = null;

        if ($resSettings = @$conn->query('SELECT settings_snapshot FROM promotion_runs WHERE id = ' . (int)$runId . ' LIMIT 1')) {
            if ($row = $resSettings->fetch_assoc()) {
                $snapshot = [];
                if (!empty($row['settings_snapshot'])) {
                    $decoded = json_decode((string)$row['settings_snapshot'], true);
                    if (is_array($decoded)) { $snapshot = $decoded; }
                }
                $level1Required = isset($snapshot['level1_count']) ? (int)$snapshot['level1_count'] : null;
            }
            $resSettings->free();
        }

        if ($level1Required === null || $level1Required <= 0) {
            $defaults = pp_promotion_get_level_requirements();
            $level1Required = (int)($defaults[1]['count'] ?? 5);
        }

        $progressDone = min($level1Success, $level1Required);
        $progressTotal = $level1Required;

        @$conn->query('UPDATE promotion_runs SET progress_total=' . (int)$progressTotal . ', progress_done=' . (int)$progressDone . ', updated_at=CURRENT_TIMESTAMP WHERE id=' . (int)$runId . ' LIMIT 1');
    }
}

if (!function_exists('pp_promotion_enqueue_publication')) {
    function pp_promotion_enqueue_publication(mysqli $conn, array $node, array $project, array $linkRow, array $requirements): bool {
        $runId = (int)$node['run_id'];
        $projectId = (int)$project['id'];
        $targetUrl = (string)$node['target_url'];
        $networkSlug = (string)$node['network_slug'];
        $anchor = (string)$node['anchor_text'];
        $language = (string)($linkRow['language'] ?? $project['language'] ?? 'ru');
        $wish = (string)($linkRow['wish'] ?? $project['wishes'] ?? '');
        $nodeId = isset($node['id']) ? (int)$node['id'] : 0;
        $jobPayload = [
            'article' => [
                'minLength' => $requirements['min_len'] ?? 2000,
                'maxLength' => $requirements['max_len'] ?? 3200,
                'level' => (int)$node['level'],
                'parentUrl' => $requirements['parent_url'] ?? ($node['parent_url'] ?? null),
                'projectName' => (string)($project['name'] ?? ''),
            ],
            'target' => [
                'url' => $targetUrl,
                'anchor' => $anchor,
                'language' => $language,
                'wish' => $wish,
            ],
            'project' => [
                'id' => $projectId,
                'region' => $project['region'] ?? null,
                'topic' => $project['topic'] ?? null,
                'language' => $project['language'] ?? null,
            ],
            'network' => [
                'slug' => $networkSlug,
                'level' => (int)$node['level'],
            ],
        ];
        if ($nodeId > 0) {
            $jobPayload['article']['nodeId'] = $nodeId;
        }
        if (empty($jobPayload['article']['language'])) {
            $jobPayload['article']['language'] = $language;
        }
        $parentContext = $requirements['parent_context'] ?? null;
        if ($parentContext) {
            $parentContextCompact = pp_promotion_compact_context($parentContext);
            if ($parentContextCompact) {
                $jobPayload['article']['parentContext'] = $parentContextCompact;
            }
        }
        $ancestorTrailRaw = $requirements['ancestor_trail'] ?? [];
        if (is_array($ancestorTrailRaw) && !empty($ancestorTrailRaw)) {
            $trail = [];
            foreach ($ancestorTrailRaw as $item) {
                $compact = pp_promotion_compact_context($item);
                if ($compact) { $trail[] = $compact; }
                if (count($trail) >= 6) { break; }
            }
            if (!empty($trail)) {
                $jobPayload['article']['ancestorTrail'] = $trail;
            }
        }
        if (!empty($requirements['article_meta']) && is_array($requirements['article_meta'])) {
            foreach ($requirements['article_meta'] as $metaKey => $metaValue) {
                if (is_string($metaKey) && $metaKey !== '') {
                    $jobPayload['article'][$metaKey] = $metaValue;
                }
            }
        }
        if (!empty($requirements['prepared_language'])) {
            $preparedLanguage = (string)$requirements['prepared_language'];
            if ($preparedLanguage !== '') {
                $jobPayload['target']['language'] = $preparedLanguage;
                if (empty($jobPayload['article']['language'])) {
                    $jobPayload['article']['language'] = $preparedLanguage;
                }
            }
        }
        if (!empty($requirements['prepared_article']) && is_array($requirements['prepared_article'])) {
            $prepared = $requirements['prepared_article'];
            $preparedPayload = [
                'title' => (string)($prepared['title'] ?? ''),
                'htmlContent' => (string)($prepared['htmlContent'] ?? ''),
                'language' => (string)($prepared['language'] ?? ($jobPayload['target']['language'] ?? $language)),
            ];
            if (!empty($prepared['plainText'])) { $preparedPayload['plainText'] = (string)$prepared['plainText']; }
            if (!empty($prepared['linkStats']) && is_array($prepared['linkStats'])) { $preparedPayload['linkStats'] = $prepared['linkStats']; }
            if (!empty($prepared['author'])) { $preparedPayload['author'] = (string)$prepared['author']; }
            if (!empty($prepared['verificationSample'])) { $preparedPayload['verificationSample'] = (string)$prepared['verificationSample']; }
            $jobPayload['preparedArticle'] = $preparedPayload;
        }
        $payloadJson = json_encode($jobPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($payloadJson === false) { $payloadJson = '{}'; }
        static $hasJobPayloadColumn = null;
        if ($hasJobPayloadColumn === null) {
            $hasJobPayloadColumn = false;
            if ($res = @$conn->query("SHOW COLUMNS FROM publications LIKE 'job_payload'")) {
                if ($res->num_rows > 0) { $hasJobPayloadColumn = true; }
                $res->free();
            }
            pp_promotion_log('promotion.publications.job_payload_column', ['present' => $hasJobPayloadColumn]);
        }
        if ($hasJobPayloadColumn) {
            $stmt = $conn->prepare('INSERT INTO publications (project_id, page_url, anchor, network, status, enqueued_by_user_id, job_payload) VALUES (?, ?, ?, ?, \'queued\', ?, ?)');
        } else {
            $stmt = $conn->prepare('INSERT INTO publications (project_id, page_url, anchor, network, status, enqueued_by_user_id) VALUES (?, ?, ?, ?, \'queued\', ?)');
        }
        if (!$stmt) { return false; }
        $userId = (int)$node['initiated_by'];
        if ($hasJobPayloadColumn) {
            $stmt->bind_param('isssis', $projectId, $targetUrl, $anchor, $networkSlug, $userId, $payloadJson);
        } else {
            $stmt->bind_param('isssi', $projectId, $targetUrl, $anchor, $networkSlug, $userId);
        }
        if (!$stmt->execute()) {
            pp_promotion_log('promotion.publication_queue_failed', [
                'run_id' => $runId,
                'node_id' => (int)$node['id'],
                'level' => (int)$node['level'],
                'network' => $networkSlug,
                'target_url' => $targetUrl,
                'error' => 'DB_INSERT_FAILED',
            ]);
            $stmt->close();
            return false;
        }
        $publicationId = (int)$conn->insert_id;
        $stmt->close();
        $update = $conn->prepare('UPDATE promotion_nodes SET publication_id=?, status=\'queued\', queued_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
        if ($update) {
            $nodeId = (int)$node['id'];
            $update->bind_param('ii', $publicationId, $nodeId);
            $update->execute();
            $update->close();
        }
        pp_promotion_log('promotion.publication_queued', [
            'run_id' => $runId,
            'project_id' => $projectId,
            'node_id' => (int)$node['id'],
            'publication_id' => $publicationId,
            'level' => (int)$node['level'],
            'network' => $networkSlug,
            'target_url' => $targetUrl,
            'anchor' => $anchor,
            'language' => $language,
            'requirements' => [
                'min_length' => $requirements['min_len'] ?? null,
                'max_length' => $requirements['max_len'] ?? null,
                'prepared_article' => !empty($requirements['prepared_article']),
            ],
        ]);
        if (function_exists('pp_run_queue_worker')) {
            try {
                @pp_run_queue_worker(1);
            } catch (Throwable $e) {
                pp_promotion_log('promotion.queue_worker_error', [
                    'run_id' => $runId,
                    'publication_id' => $publicationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        try {
            $conn2 = @connect_db();
            if ($conn2) {
                $insQ = $conn2->prepare('INSERT INTO publication_queue (publication_id, project_id, user_id, page_url, status) VALUES (?, ?, ?, ?, \'queued\')');
                if ($insQ) {
                    $insQ->bind_param('iiis', $publicationId, $projectId, $userId, $targetUrl);
                    @$insQ->execute();
                    $insQ->close();
                }
                $conn2->close();
            }
        } catch (Throwable $e) {
            // ignore queue errors
        }
        return true;
    }
}

if (!function_exists('pp_promotion_generate_contextual_anchor')) {
    function pp_promotion_generate_contextual_anchor(?array $context, string $fallbackAnchor): string {
        $candidates = [];
        if (is_array($context)) {
            $headings = $context['headings'] ?? [];
            if (is_array($headings)) {
                foreach ($headings as $heading) {
                    $title = trim((string)$heading);
                    if ($title === '') { continue; }
                    if (function_exists('mb_substr') && mb_strlen($title, 'UTF-8') > 55) {
                        $title = rtrim(mb_substr($title, 0, 55, 'UTF-8')) . '…';
                    } elseif (strlen($title) > 55) {
                        $title = rtrim(substr($title, 0, 55)) . '…';
                    }
                    if ($title !== '') { $candidates[] = $title; }
                }
            }
            $keywords = $context['keywords'] ?? [];
            if (is_array($keywords)) {
                foreach (array_slice($keywords, 0, 6) as $keyword) {
                    $kw = trim((string)$keyword);
                    if ($kw === '') { continue; }
                    if (function_exists('mb_strlen')) {
                        if (mb_strlen($kw, 'UTF-8') <= 18) {
                            if (function_exists('mb_convert_case')) {
                                $candidates[] = mb_convert_case($kw, MB_CASE_TITLE, 'UTF-8');
                            } else {
                                $candidates[] = strtoupper(substr($kw, 0, 1)) . substr($kw, 1);
                            }
                            $candidates[] = __('Разбор') . ' ' . $kw;
                        } else {
                            $candidates[] = __('Тема') . ': ' . $kw;
                        }
                    } else {
                        $candidates[] = strtoupper(substr($kw, 0, 1)) . substr($kw, 1);
                    }
                }
            }
            $summary = trim((string)($context['summary'] ?? ''));
            if ($summary !== '') {
                $sentences = preg_split('~(?<=[.!?])\s+~u', $summary, -1, PREG_SPLIT_NO_EMPTY);
                if (is_array($sentences)) {
                    foreach ($sentences as $sentence) {
                        $sent = trim($sentence);
                        if ($sent === '') { continue; }
                        if (function_exists('mb_substr') && mb_strlen($sent, 'UTF-8') > 60) {
                            $sent = rtrim(mb_substr($sent, 0, 60, 'UTF-8')) . '…';
                        } elseif (strlen($sent) > 60) {
                            $sent = rtrim(substr($sent, 0, 60)) . '…';
                        }
                        if ($sent !== '') { $candidates[] = $sent; }
                    }
                }
            }
            $excerpt = trim((string)($context['excerpt'] ?? ''));
            if ($excerpt !== '') {
                $phrases = pp_promotion_extract_theme_phrases($excerpt, 6);
                foreach ($phrases as $phrase) {
                    $cleanPhrase = trim($phrase);
                    if ($cleanPhrase === '') { continue; }
                    $candidates[] = $cleanPhrase;
                }
            }
        }

        $fallbackAnchor = trim($fallbackAnchor);
        if ($fallbackAnchor !== '') { $candidates[] = $fallbackAnchor; }

        $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates))));
        if (empty($candidates)) {
            return __('Подробнее');
        }
        $choice = pp_promotion_random_choice($candidates, __('Подробнее'));
        if (function_exists('mb_strlen')) {
            if (mb_strlen($choice, 'UTF-8') > 60) {
                $choice = rtrim(mb_substr($choice, 0, 60, 'UTF-8')) . '…';
            }
        } elseif (strlen($choice) > 60) {
            $choice = rtrim(substr($choice, 0, 60)) . '…';
        }
        return $choice !== '' ? $choice : __('Подробнее');
    }
}

if (!function_exists('pp_promotion_generate_anchor')) {
    function pp_promotion_generate_anchor(string $baseAnchor): string {
        $base = trim($baseAnchor);
        if ($base === '') { return __('Подробнее'); }
        $suffixes = ['обзор', 'подробнее', 'инструкция', 'руководство', 'разбор'];
        try { $suffix = $suffixes[random_int(0, count($suffixes)-1)]; } catch (Throwable $e) { $suffix = $suffixes[0]; }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($base, 'UTF-8') > 40) {
                $base = trim(mb_substr($base, 0, 35, 'UTF-8'));
            }
        } elseif (strlen($base) > 40) {
            $base = trim(substr($base, 0, 35));
        }
        return $base . ' — ' . $suffix;
    }
}

if (!function_exists('pp_promotion_random_choice')) {
    function pp_promotion_random_choice(array $items, $default = null) {
        if (empty($items)) {
            return $default;
        }
        $maxIndex = count($items) - 1;
        if ($maxIndex <= 0) {
            return reset($items);
        }
        try {
            $idx = random_int(0, $maxIndex);
        } catch (Throwable $e) {
            $idx = array_rand($items);
        }
        return $items[$idx] ?? $default;
    }
}

if (!function_exists('pp_promotion_make_email_slug')) {
    function pp_promotion_make_email_slug(string $name): string {
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($slug === false) {
            $slug = $name;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('~[^a-z0-9]+~', '.', $slug ?? '');
        $slug = trim((string)$slug, '.');
        if ($slug === '') {
            $slug = 'promo';
        }
        return preg_replace('~\.+~', '.', $slug);
    }
}

if (!function_exists('pp_promotion_generate_crowd_payloads')) {
    function pp_promotion_generate_crowd_payloads(string $targetUrl, array $project, array $linkRow, int $uniqueCount, ?array $articleContext = null): array {
        $uniqueCount = max(1, $uniqueCount);
        $articleTitle = trim((string)($articleContext['title'] ?? $linkRow['anchor'] ?? $project['name'] ?? __('Материал')));
        if ($articleTitle === '') { $articleTitle = __('Материал'); }
        $summary = trim((string)($articleContext['summary'] ?? $articleContext['excerpt'] ?? ''));
        $keywords = [];
        if (!empty($articleContext['keywords']) && is_array($articleContext['keywords'])) {
            foreach ($articleContext['keywords'] as $kw) {
                $kwClean = trim((string)$kw);
                if ($kwClean !== '') { $keywords[] = $kwClean; }
            }
        }
        $headings = [];
        if (!empty($articleContext['headings']) && is_array($articleContext['headings'])) {
            foreach ($articleContext['headings'] as $heading) {
                $headingClean = trim((string)$heading);
                if ($headingClean !== '') { $headings[] = $headingClean; }
            }
        }

        $points = [];
        if ($summary !== '') {
            $sentences = preg_split('~(?<=[.!?])\s+~u', $summary, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($sentences)) {
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if ($sentence === '') { continue; }
                    if (function_exists('mb_strlen') && mb_strlen($sentence, 'UTF-8') > 160) {
                        $sentence = rtrim(mb_substr($sentence, 0, 160, 'UTF-8')) . '…';
                    } elseif (strlen($sentence) > 160) {
                        $sentence = rtrim(substr($sentence, 0, 160)) . '…';
                    }
                    $points[] = $sentence;
                    if (count($points) >= 3) { break; }
                }
            }
        }
        if (empty($points) && !empty($headings)) {
            foreach ($headings as $heading) {
                $points[] = $heading;
                if (count($points) >= 3) { break; }
            }
        }
        if (empty($points) && !empty($keywords)) {
            $points[] = __('Ключевые темы') . ': ' . implode(', ', array_slice($keywords, 0, 5));
        }
        if (empty($points)) {
            $points[] = __('Стоит посмотреть статью, там подробности по теме.');
        }

        $describePoints = function(array $pointsList): string {
            if (empty($pointsList)) { return ''; }
            if (count($pointsList) === 1) { return $pointsList[0]; }
            $last = array_pop($pointsList);
            return implode('; ', $pointsList) . ' — ' . $last;
        };

        $commentTemplates = [
            __('Коллеги, прочитайте статью «{{title}}»: {{link}}. Автор разбирает {{points}}.'),
            __('Нашёл полезный материал «{{title}}». Коротко: {{points}}. Делюсь ссылкой без анкоров — {{link}}.'),
            __('Для обсуждения: в «{{title}}» ({{link}}) описаны {{points}}. Как вам подход?'),
            __('В статье «{{title}}» собраны {{points}}. Если есть время, загляните: {{link}}.'),
            __('Скидываю ссылку {{link}} — материал «{{title}}» с акцентом на {{points}}. Нужна обратная связь.'),
        ];

        $subjectTemplates = [
            __('Комментарий к статье «{{title}}»'),
            __('Что думаете про «{{title}}»?'),
            __('Стоит обсудить: «{{title}}»'),
            __('К обсуждению статья «{{title}}»'),
        ];

        $firstNames = ['Алексей','Мария','Иван','Дарья','Егор','Светлана','Максим','Анна','Дмитрий','Виктория','Павел','Ольга','Роман','Екатерина','Кирилл','Юлия'];
        $lastNames = ['Смирнов','Иванова','Кузнецов','Павлова','Андреев','Федорова','Максимов','Алексеева','Морозов','Васильева','Соколов','Никитина','Громов','Орлова','Яковлев','Сергеева'];
        $domains = ['gmail.com','yandex.ru','mail.ru','outlook.com'];
        $projectDomain = trim((string)($project['domain_host'] ?? ''));
        if ($projectDomain !== '') {
            $domains[] = preg_replace('~[^a-z0-9.-]+~i', '', strtolower($projectDomain));
        }
        $domains = array_values(array_unique(array_filter($domains)));

        $payloads = [];
        $usedHashes = [];
        $attempts = 0;
        $maxAttempts = $uniqueCount * 7;
        while (count($payloads) < $uniqueCount && $attempts < $maxAttempts) {
            $attempts++;
            $pointsSample = [];
            if (!empty($points)) {
                $shuffled = $points;
                if (count($shuffled) > 1) { shuffle($shuffled); }
                $pointsSample = array_slice($shuffled, 0, min(3, count($shuffled)));
            }
            $pointsText = $describePoints($pointsSample);
            $commentTemplate = (string)pp_promotion_random_choice($commentTemplates, __('Коллеги, обратите внимание на {{title}}: {{link}}.'));
            $body = strtr($commentTemplate, [
                '{{title}}' => $articleTitle,
                '{{points}}' => $pointsText,
                '{{link}}' => $targetUrl,
            ]);
            $subjectTemplate = (string)pp_promotion_random_choice($subjectTemplates, __('К обсуждению статья «{{title}}»'));
            $subject = strtr($subjectTemplate, ['{{title}}' => $articleTitle]);
            $first = (string)pp_promotion_random_choice($firstNames, 'Иван');
            $last = (string)pp_promotion_random_choice($lastNames, 'Иванов');
            $fullName = trim($first . ' ' . $last);
            try {
                $token = substr(bin2hex(random_bytes(6)), 0, 10);
            } catch (Throwable $e) {
                $token = substr(sha1($fullName . microtime(true)), 0, 10);
            }
            $emailSlug = pp_promotion_make_email_slug($fullName);
            $domain = (string)pp_promotion_random_choice($domains, 'example.com');
            $email = $emailSlug;
            if ($domain !== '') {
                $email .= '+' . strtolower($token) . '@' . $domain;
            } else {
                $email .= '+' . strtolower($token) . '@example.com';
            }
            $bodyFull = trim($body);
            $hash = md5($subject . '|' . $bodyFull . '|' . $email);
            if (isset($usedHashes[$hash])) {
                continue;
            }
            $usedHashes[$hash] = true;
            $payloads[] = [
                'anchor' => '',
                'subject' => $subject,
                'body' => $bodyFull,
                'author_name' => $fullName,
                'author_email' => $email,
                'token' => $token,
                'target_url' => $targetUrl,
            ];
        }

        if (count($payloads) < $uniqueCount) {
            while (count($payloads) < $uniqueCount) {
                try {
                    $token = substr(bin2hex(random_bytes(5)), 0, 8);
                } catch (Throwable $e) {
                    $token = (string)mt_rand(100000, 999999);
                }
                $fallbackBody = __('Поделюсь ссылкой без анкоров') . ': ' . $targetUrl;
                $payloads[] = [
                    'anchor' => '',
                    'subject' => __('Комментарий к статье'),
                    'body' => $fallbackBody,
                    'author_name' => 'PromoPilot Bot',
                    'author_email' => 'promopilot+' . strtolower($token) . '@example.com',
                    'token' => $token,
                    'target_url' => $targetUrl,
                ];
            }
        }

        return $payloads;
    }
}

if (!function_exists('pp_promotion_process_run')) {
    function pp_promotion_process_run(mysqli $conn, array $run): void {
        $runId = (int)$run['id'];
        $projectId = (int)$run['project_id'];
        $stage = (string)$run['stage'];
        $status = (string)$run['status'];
        if ($status === 'cancelled' || $status === 'failed' || $status === 'completed') { return; }
        $project = pp_promotion_fetch_project($conn, $projectId);
        if (!$project) {
            @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='PROJECT_MISSING', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        $requirements = pp_promotion_get_level_requirements();
        // Load link row
        $linkRow = null;
        $stmt = $conn->prepare('SELECT * FROM project_links WHERE id = ? LIMIT 1');
        if ($stmt) {
            $linkId = (int)$run['link_id'];
            $stmt->bind_param('i', $linkId);
            if ($stmt->execute()) { $linkRow = $stmt->get_result()->fetch_assoc(); }
            $stmt->close();
        }
        if (!$linkRow) {
            @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LINK_MISSING', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_level1') {
            $req = $requirements[1];
            $count = (int)$req['count'];
            $usage = [];
            $nets = pp_promotion_pick_networks(1, $count, $project, $usage);
            if (empty($nets)) {
                pp_promotion_log('promotion.level1.networks_missing', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'target_url' => $run['target_url'],
                    'requested' => $count,
                    'region' => $project['region'] ?? null,
                    'topic' => $project['topic'] ?? null,
                ]);
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='NO_NETWORKS_L1', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $selectedSlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
            $usageSnapshot = [];
            foreach ($selectedSlugs as $slug) {
                if ($slug === '') { continue; }
                $usageSnapshot[$slug] = (int)($usage[$slug] ?? 0);
            }
            pp_promotion_log('promotion.level1.networks_selected', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'target_url' => $run['target_url'],
                'requested' => $count,
                'selected' => $selectedSlugs,
                'usage' => $usageSnapshot,
                'region' => $project['region'] ?? null,
                'topic' => $project['topic'] ?? null,
            ]);
            $created = 0;
            foreach ($nets as $net) {
                $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 1, ?, ?, ?, \'pending\', ?)');
                if ($stmt) {
                    $anchor = (string)($linkRow['anchor'] ?? '');
                    if ($anchor === '') { $anchor = $project['name'] ?? __('Материал'); }
                    $initiated = (int)$run['initiated_by'];
                    $stmt->bind_param('isssi', $runId, $run['target_url'], $net['slug'], $anchor, $initiated);
                    if ($stmt->execute()) { $created++; }
                    $stmt->close();
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level1_active', status='level1_active', started_at=COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id=" . $runId . " LIMIT 1");
            $res = @$conn->query('SELECT * FROM promotion_nodes WHERE run_id = ' . $runId . ' AND level = 1 AND status = \'pending\'');
            if ($res) {
                while ($node = $res->fetch_assoc()) {
                    $node['parent_url'] = $run['target_url'];
                    $node['initiated_by'] = $run['initiated_by'];
                    $node['level'] = 1;
                    $node['target_url'] = $run['target_url'];
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, ['min_len' => $requirements[1]['min_len'], 'max_len' => $requirements[1]['max_len'], 'level' => 1]);
                }
                $res->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level1_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }
            $requiredLevel1 = max(1, (int)($requirements[1]['count'] ?? 1));
            if ($success < $requiredLevel1) {
                $needed = $requiredLevel1 - $success;
                $usage = [];
                if ($usageRes = @$conn->query('SELECT network_slug, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 GROUP BY network_slug')) {
                    while ($u = $usageRes->fetch_assoc()) {
                        $slug = (string)($u['network_slug'] ?? '');
                        if ($slug === '') { continue; }
                        $usage[$slug] = (int)($u['c'] ?? 0);
                    }
                    $usageRes->free();
                }
                $netsRetry = pp_promotion_pick_networks(1, $needed, $project, $usage);
                if (empty($netsRetry)) {
                    pp_promotion_log('promotion.level1.retry_exhausted', [
                        'run_id' => $runId,
                        'project_id' => $projectId,
                        'target_url' => $run['target_url'],
                        'needed' => $needed,
                        'success' => $success,
                        'failed' => $failed,
                    ]);
                    @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_INSUFFICIENT_SUCCESS', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                    return;
                }
                $retrySlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $netsRetry);
                pp_promotion_log('promotion.level1.retry_scheduled', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'target_url' => $run['target_url'],
                    'needed' => $needed,
                    'selected' => $retrySlugs,
                ]);
                $newNodeIds = [];
                foreach ($netsRetry as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 1, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = (string)($linkRow['anchor'] ?? '');
                        if ($anchor === '') { $anchor = $project['name'] ?? __('Материал'); }
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('isssi', $runId, $run['target_url'], $net['slug'], $anchor, $initiated);
                        if ($stmt->execute()) {
                            $newNodeIds[] = (int)$conn->insert_id;
                        }
                        $stmt->close();
                    }
                }
                if (!empty($newNodeIds)) {
                    $idsList = implode(',', array_map('intval', $newNodeIds));
                    if ($idsList !== '') {
                        $sql = 'SELECT * FROM promotion_nodes WHERE id IN (' . $idsList . ')';
                        if ($resNew = @$conn->query($sql)) {
                            while ($node = $resNew->fetch_assoc()) {
                                $node['parent_url'] = $run['target_url'];
                                $node['initiated_by'] = $run['initiated_by'];
                                $node['level'] = 1;
                                $node['target_url'] = $run['target_url'];
                                pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, ['min_len' => $requirements[1]['min_len'], 'max_len' => $requirements[1]['max_len'], 'level' => 1]);
                            }
                            $resNew->free();
                        }
                    }
                    pp_promotion_update_progress($conn, $runId);
                    return;
                }
                // fallback if no nodes were created
                pp_promotion_log('promotion.level1.retry_insert_failed', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'needed' => $needed,
                ]);
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_INSERT_FAILED', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_FAILED', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if (!pp_promotion_is_level_enabled(2)) {
                @$conn->query("UPDATE promotion_runs SET stage='pending_crowd', status='pending_crowd' WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            @$conn->query("UPDATE promotion_runs SET stage='pending_level2', status='pending_level2' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_level2') {
            $perParent = (int)$requirements[2]['per_parent'];
            $nodesL1 = [];
            $res = @$conn->query('SELECT id, result_url, anchor_text FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 AND status IN (\'success\',\'completed\')');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $url = trim((string)$row['result_url']);
                    if ($url !== '') { $nodesL1[] = $row; }
                }
                $res->free();
            }
            if (empty($nodesL1)) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_NO_URL', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $usage = [];
            $level1Contexts = [];
            $cachedArticlesL1 = [];
            foreach ($nodesL1 as $parentNode) {
                $ctx = pp_promotion_get_article_context((string)$parentNode['result_url']);
                if ($ctx) {
                    $level1Contexts[(int)$parentNode['id']] = $ctx;
                }
                $cached = pp_promotion_load_cached_article((int)$parentNode['id']);
                if (is_array($cached) && !empty($cached['htmlContent'])) {
                    $cachedArticlesL1[(int)$parentNode['id']] = $cached;
                }
            }
            $created = 0;
            foreach ($nodesL1 as $parentNode) {
                $nets = pp_promotion_pick_networks(2, $perParent, $project, $usage);
                if (empty($nets)) { continue; }
                $selectedSlugsL2 = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
                $usageSnapshotL2 = [];
                foreach ($selectedSlugsL2 as $slug) {
                    if ($slug === '') { continue; }
                    $usageSnapshotL2[$slug] = (int)($usage[$slug] ?? 0);
                }
                pp_promotion_log('promotion.level2.networks_selected', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'parent_node_id' => (int)$parentNode['id'],
                    'target_url' => $parentNode['result_url'],
                    'requested' => $perParent,
                    'selected' => $selectedSlugsL2,
                    'usage' => $usageSnapshotL2,
                    'region' => $project['region'] ?? null,
                    'topic' => $project['topic'] ?? null,
                ]);
                $parentContext = $level1Contexts[(int)$parentNode['id']] ?? null;
                foreach ($nets as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, parent_id, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 2, ?, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = pp_promotion_generate_contextual_anchor($parentContext, (string)$linkRow['anchor']);
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('iisssi', $runId, $parentNode['id'], $parentNode['result_url'], $net['slug'], $anchor, $initiated);
                        if ($stmt->execute()) { $created++; }
                        $stmt->close();
                    }
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level2_active', status='level2_active' WHERE id=" . $runId . " LIMIT 1");
            $res2 = @$conn->query('SELECT n.*, p.result_url AS parent_url, p.target_url AS parent_target_url, p.level AS parent_level FROM promotion_nodes n LEFT JOIN promotion_nodes p ON p.id = n.parent_id WHERE n.run_id=' . $runId . ' AND n.level=2 AND n.status=\'pending\'');
            if ($res2) {
                while ($node = $res2->fetch_assoc()) {
                    $node['initiated_by'] = $run['initiated_by'];
                    $nodeId = isset($node['id']) ? (int)$node['id'] : 0;
                    $parentNodeId = (int)($node['parent_id'] ?? 0);
                    $parentCtx = $level1Contexts[$parentNodeId] ?? null;
                    $trail = [];
                    if ($parentCtx) { $trail[] = $parentCtx; }
                    $preparedArticle = null;
                    $preparedLanguage = null;
                    $articleMeta = [];
                    $cachedParent = $cachedArticlesL1[$parentNodeId] ?? null;
                    if (is_array($cachedParent) && !empty($cachedParent['htmlContent'])) {
                        $preparedLanguage = (string)($cachedParent['language'] ?? ($linkRow['language'] ?? ($project['language'] ?? '')));
                        if ($preparedLanguage === '') { $preparedLanguage = 'ru'; }
                        $fallbackAnchor = (string)($node['anchor_text'] ?? '');
                        $childAnchor = pp_promotion_generate_child_anchor($cachedParent, $preparedLanguage, $fallbackAnchor);
                        if ($childAnchor !== '') {
                            if ($childAnchor !== $fallbackAnchor && $nodeId > 0) {
                                $updateAnchor = $conn->prepare('UPDATE promotion_nodes SET anchor_text=? WHERE id=? LIMIT 1');
                                if ($updateAnchor) {
                                    $updateAnchor->bind_param('si', $childAnchor, $nodeId);
                                    $updateAnchor->execute();
                                    $updateAnchor->close();
                                }
                            }
                            $node['anchor_text'] = $childAnchor;
                        }
                        $parentTargetUrl = (string)($node['parent_target_url'] ?? '');
                        if ($parentTargetUrl === '') { $parentTargetUrl = (string)$run['target_url']; }
                        $preparedArticle = pp_promotion_prepare_child_article(
                            $cachedParent,
                            (string)$node['target_url'],
                            $parentTargetUrl,
                            $preparedLanguage,
                            (string)$node['anchor_text']
                        );
                        if (is_array($preparedArticle) && !empty($preparedArticle['htmlContent'])) {
                            if (empty($preparedArticle['language'])) { $preparedArticle['language'] = $preparedLanguage; }
                            if (empty($preparedArticle['plainText'])) {
                                $plain = trim(strip_tags((string)$preparedArticle['htmlContent']));
                                if ($plain !== '') { $preparedArticle['plainText'] = $plain; }
                            }
                            $preparedArticle['sourceUrl'] = $parentTargetUrl;
                            $preparedArticle['sourceNodeId'] = $parentNodeId;
                            $articleMeta = [
                                'source_node_id' => $parentNodeId,
                                'source_target_url' => $parentTargetUrl,
                                'source_level' => (int)($node['parent_level'] ?? 1),
                                'parent_result_url' => (string)($node['parent_url'] ?? ''),
                                'reuse_mode' => 'cached_parent',
                            ];
                            pp_promotion_log('promotion.level2.article_reuse', [
                                'run_id' => $runId,
                                'node_id' => $nodeId,
                                'parent_node_id' => $parentNodeId,
                                'prepared_language' => $preparedLanguage,
                                'target_url' => (string)$node['target_url'],
                                'parent_target_url' => $parentTargetUrl,
                            ]);
                        } else {
                            $preparedArticle = null;
                        }
                    }
                    $requirementsPayload = [
                        'min_len' => $requirements[2]['min_len'],
                        'max_len' => $requirements[2]['max_len'],
                        'level' => 2,
                        'parent_url' => $node['parent_url'],
                        'parent_context' => $parentCtx,
                        'ancestor_trail' => $trail,
                    ];
                    if ($preparedArticle) {
                        $requirementsPayload['prepared_article'] = $preparedArticle;
                        $requirementsPayload['prepared_language'] = $preparedLanguage;
                        if (!empty($articleMeta)) {
                            $requirementsPayload['article_meta'] = $articleMeta;
                        }
                    }
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, $requirementsPayload);
                }
                $res2->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level2_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }

            $perParentRequired = max(1, (int)($requirements[2]['per_parent'] ?? 1));
            $usageLevel2 = [];
            if ($usageRes = @$conn->query('SELECT network_slug, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 GROUP BY network_slug')) {
                while ($u = $usageRes->fetch_assoc()) {
                    $slugUsage = (string)($u['network_slug'] ?? '');
                    if ($slugUsage === '') { continue; }
                    $usageLevel2[$slugUsage] = (int)($u['c'] ?? 0);
                }
                $usageRes->free();
            }

            $parentStats = [];
            $parentInfo = [];
            if ($detailRes = @$conn->query('SELECT n.id, n.parent_id, n.status, n.network_slug, n.target_url, p.result_url AS parent_result_url, p.target_url AS parent_target_url, p.level AS parent_level FROM promotion_nodes n LEFT JOIN promotion_nodes p ON p.id = n.parent_id WHERE n.run_id=' . $runId . ' AND n.level=2')) {
                while ($row = $detailRes->fetch_assoc()) {
                    $parentId = isset($row['parent_id']) ? (int)$row['parent_id'] : 0;
                    if ($parentId <= 0) { continue; }
                    if (!isset($parentStats[$parentId])) {
                        $parentStats[$parentId] = ['success' => 0];
                    }
                    $statusNode = (string)($row['status'] ?? '');
                    if (in_array($statusNode, ['success','completed'], true)) {
                        $parentStats[$parentId]['success']++;
                    }
                    $slugUsage = (string)($row['network_slug'] ?? '');
                    if ($slugUsage !== '' && !isset($usageLevel2[$slugUsage])) {
                        $usageLevel2[$slugUsage] = 1;
                    }
                    $parentInfo[$parentId] = [
                        'result_url' => (string)($row['parent_result_url'] ?? ''),
                        'target_url' => (string)($row['parent_target_url'] ?? ''),
                        'level' => isset($row['parent_level']) ? (int)$row['parent_level'] : 1,
                    ];
                }
                $detailRes->free();
            }

            $parentsNeeding = [];
            foreach ($parentStats as $parentId => $stats) {
                $completed = (int)($stats['success'] ?? 0);
                if ($completed < $perParentRequired) {
                    $parentsNeeding[$parentId] = $perParentRequired - $completed;
                }
            }

            if (!empty($parentsNeeding)) {
                $parentContexts = [];
                $parentCachedArticles = [];
                $createdAny = false;
                foreach ($parentsNeeding as $parentId => $deficit) {
                    $parentResultUrl = (string)($parentInfo[$parentId]['result_url'] ?? '');
                    if ($parentResultUrl === '') { continue; }
                    if (!array_key_exists($parentId, $parentContexts)) {
                        $parentContexts[$parentId] = pp_promotion_get_article_context($parentResultUrl);
                    }
                    if (!array_key_exists($parentId, $parentCachedArticles)) {
                        $parentCachedArticles[$parentId] = pp_promotion_load_cached_article($parentId);
                    }
                    $parentContext = $parentContexts[$parentId] ?? null;
                    $cachedParent = $parentCachedArticles[$parentId] ?? null;
                    $anchorBase = pp_promotion_generate_contextual_anchor($parentContext, (string)$linkRow['anchor']);
                    $parentTargetUrl = (string)($parentInfo[$parentId]['target_url'] ?? '');
                    if ($parentTargetUrl === '') { $parentTargetUrl = (string)$run['target_url']; }
                    $preparedLanguage = null;
                    $preparedArticle = null;
                    $articleMeta = [];
                    $anchorFinal = $anchorBase;
                    if (is_array($cachedParent) && !empty($cachedParent['htmlContent'])) {
                        $preparedLanguage = (string)($cachedParent['language'] ?? ($linkRow['language'] ?? ($project['language'] ?? 'ru')));
                        if ($preparedLanguage === '') { $preparedLanguage = 'ru'; }
                        $childAnchor = pp_promotion_generate_child_anchor($cachedParent, $preparedLanguage, $anchorBase);
                        if ($childAnchor !== '') { $anchorFinal = $childAnchor; }
                        $preparedArticleCandidate = pp_promotion_prepare_child_article(
                            $cachedParent,
                            $parentResultUrl,
                            $parentTargetUrl,
                            $preparedLanguage,
                            $anchorFinal
                        );
                        if (is_array($preparedArticleCandidate) && !empty($preparedArticleCandidate['htmlContent'])) {
                            if (empty($preparedArticleCandidate['language'])) { $preparedArticleCandidate['language'] = $preparedLanguage; }
                            if (empty($preparedArticleCandidate['plainText'])) {
                                $plainCandidate = trim(strip_tags((string)$preparedArticleCandidate['htmlContent']));
                                if ($plainCandidate !== '') { $preparedArticleCandidate['plainText'] = $plainCandidate; }
                            }
                            $preparedArticleCandidate['sourceUrl'] = $parentTargetUrl;
                            $preparedArticleCandidate['sourceNodeId'] = $parentId;
                            $preparedArticle = $preparedArticleCandidate;
                            $articleMeta = [
                                'source_node_id' => $parentId,
                                'source_target_url' => $parentTargetUrl,
                                'source_level' => (int)($parentInfo[$parentId]['level'] ?? 1),
                                'parent_result_url' => $parentResultUrl,
                                'reuse_mode' => 'cached_parent',
                            ];
                        }
                    }

                    $netsRetry = pp_promotion_pick_networks(2, $deficit, $project, $usageLevel2);
                    if (empty($netsRetry)) { continue; }
                    $retrySlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $netsRetry);
                    pp_promotion_log('promotion.level2.retry_scheduled', [
                        'run_id' => $runId,
                        'project_id' => $projectId,
                        'parent_node_id' => $parentId,
                        'needed' => $deficit,
                        'selected' => $retrySlugs,
                    ]);
                    foreach ($netsRetry as $netRetry) {
                        $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, parent_id, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 2, ?, ?, ?, ?, \'pending\', ?)');
                        if (!$stmt) { continue; }
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('iisssi', $runId, $parentId, $parentResultUrl, $netRetry['slug'], $anchorFinal, $initiated);
                        if ($stmt->execute()) {
                            $createdAny = true;
                            $newNodeId = (int)$conn->insert_id;
                            $stmt->close();
                            $nodeRow = [
                                'id' => $newNodeId,
                                'run_id' => $runId,
                                'parent_id' => $parentId,
                                'level' => 2,
                                'target_url' => $parentResultUrl,
                                'network_slug' => (string)$netRetry['slug'],
                                'anchor_text' => $anchorFinal,
                                'parent_url' => $parentResultUrl,
                                'parent_target_url' => $parentTargetUrl,
                                'parent_level' => (int)($parentInfo[$parentId]['level'] ?? 1),
                                'initiated_by' => $run['initiated_by'],
                            ];
                            $trail = [];
                            if ($parentContext) { $trail[] = $parentContext; }
                            $requirementsPayload = [
                                'min_len' => $requirements[2]['min_len'],
                                'max_len' => $requirements[2]['max_len'],
                                'level' => 2,
                                'parent_url' => $parentResultUrl,
                                'parent_context' => $parentContext,
                                'ancestor_trail' => $trail,
                            ];
                            if ($preparedArticle) {
                                $requirementsPayload['prepared_article'] = $preparedArticle;
                                $requirementsPayload['prepared_language'] = $preparedLanguage;
                                if (!empty($articleMeta)) {
                                    $requirementsPayload['article_meta'] = $articleMeta;
                                }
                            }
                            pp_promotion_enqueue_publication($conn, $nodeRow, $project, $linkRow, $requirementsPayload);
                        } else {
                            $stmt->close();
                        }
                    }
                }

                if ($createdAny) {
                    pp_promotion_update_progress($conn, $runId);
                    return;
                }

                if (!empty($parentsNeeding)) {
                    pp_promotion_log('promotion.level2.retry_exhausted', [
                        'run_id' => $runId,
                        'project_id' => $projectId,
                        'deficit' => $parentsNeeding,
                    ]);
                    @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL2_INSUFFICIENT_SUCCESS', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                    return;
                }
            }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL2_FAILED', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if (pp_promotion_is_level_enabled(3)) {
                @$conn->query("UPDATE promotion_runs SET stage='pending_level3', status='pending_level3' WHERE id=" . $runId . " LIMIT 1");
            } else {
                @$conn->query("UPDATE promotion_runs SET stage='pending_crowd', status='pending_crowd' WHERE id=" . $runId . " LIMIT 1");
            }
            return;
        }
        if ($stage === 'pending_level3') {
            $perParent = (int)($requirements[3]['per_parent'] ?? 0);
            $level2Nodes = [];
            $res = @$conn->query('SELECT id, parent_id, result_url FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 AND status IN (\'success\',\'completed\')');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $url = trim((string)$row['result_url']);
                    if ($url !== '') { $level2Nodes[] = $row; }
                }
                $res->free();
            }
            if (empty($level2Nodes)) {
                @$conn->query("UPDATE promotion_runs SET stage='failed', status='failed', error='LEVEL2_NO_URL', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $usage = [];
            $level2Contexts = [];
            $cachedArticlesL2 = [];
            $level1IdsNeeded = [];
            $level2ParentMap = [];
            foreach ($level2Nodes as $parentNode) {
                $ctx = pp_promotion_get_article_context((string)$parentNode['result_url']);
                if ($ctx) { $level2Contexts[(int)$parentNode['id']] = $ctx; }
                $pid = (int)($parentNode['parent_id'] ?? 0);
                if ($pid > 0) {
                    $level1IdsNeeded[$pid] = $pid;
                    $level2ParentMap[(int)$parentNode['id']] = $pid;
                }
                $cached = pp_promotion_load_cached_article((int)$parentNode['id']);
                if (is_array($cached) && !empty($cached['htmlContent'])) {
                    $cachedArticlesL2[(int)$parentNode['id']] = $cached;
                }
            }
            $level1Contexts = [];
            if (!empty($level1IdsNeeded)) {
                $idsList = implode(',', array_map('intval', array_values($level1IdsNeeded)));
                if ($idsList !== '') {
                    $resLvl1 = @$conn->query('SELECT id, result_url FROM promotion_nodes WHERE id IN (' . $idsList . ')');
                    if ($resLvl1) {
                        while ($row = $resLvl1->fetch_assoc()) {
                            $ctx = pp_promotion_get_article_context((string)$row['result_url']);
                            if ($ctx) {
                                $level1Contexts[(int)$row['id']] = $ctx;
                            }
                        }
                        $resLvl1->free();
                    }
                }
            }
            foreach ($level2Nodes as $parentNode) {
                $nets = pp_promotion_pick_networks(3, $perParent, $project, $usage);
                if (empty($nets)) { continue; }
                $selectedSlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
                $usageSnapshot = [];
                foreach ($selectedSlugs as $slug) {
                    if ($slug === '') { continue; }
                    $usageSnapshot[$slug] = (int)($usage[$slug] ?? 0);
                }
                pp_promotion_log('promotion.level3.networks_selected', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'parent_node_id' => (int)$parentNode['id'],
                    'target_url' => $parentNode['result_url'],
                    'requested' => $perParent,
                    'selected' => $selectedSlugs,
                    'usage' => $usageSnapshot,
                ]);
                $parentCtx = $level2Contexts[(int)$parentNode['id']] ?? null;
                foreach ($nets as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, parent_id, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 3, ?, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = pp_promotion_generate_contextual_anchor($parentCtx, (string)$linkRow['anchor']);
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('iisssi', $runId, $parentNode['id'], $parentNode['result_url'], $net['slug'], $anchor, $initiated);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level3_active', status='level3_active' WHERE id=" . $runId . " LIMIT 1");
            $res3 = @$conn->query('SELECT n.*, p.result_url AS parent_url, p.target_url AS parent_target_url, p.level AS parent_level FROM promotion_nodes n LEFT JOIN promotion_nodes p ON p.id = n.parent_id WHERE n.run_id=' . $runId . ' AND n.level=3 AND n.status=\'pending\'');
            if ($res3) {
                while ($node = $res3->fetch_assoc()) {
                    $node['initiated_by'] = $run['initiated_by'];
                    $nodeId = isset($node['id']) ? (int)$node['id'] : 0;
                    $parentId = (int)($node['parent_id'] ?? 0);
                    $parentCtx = $level2Contexts[$parentId] ?? null;
                    $trail = [];
                    $level1ParentId = $level2ParentMap[$parentId] ?? null;
                    if ($level1ParentId && isset($level1Contexts[$level1ParentId])) {
                        $trail[] = $level1Contexts[$level1ParentId];
                    }
                    if ($parentCtx) { $trail[] = $parentCtx; }
                    $preparedArticle = null;
                    $preparedLanguage = null;
                    $articleMeta = [];
                    $cachedParent = $cachedArticlesL2[$parentId] ?? null;
                    if (is_array($cachedParent) && !empty($cachedParent['htmlContent'])) {
                        $preparedLanguage = (string)($cachedParent['language'] ?? ($linkRow['language'] ?? ($project['language'] ?? '')));
                        if ($preparedLanguage === '') { $preparedLanguage = 'ru'; }
                        $fallbackAnchor = (string)($node['anchor_text'] ?? '');
                        $childAnchor = pp_promotion_generate_child_anchor($cachedParent, $preparedLanguage, $fallbackAnchor);
                        if ($childAnchor !== '') {
                            if ($childAnchor !== $fallbackAnchor && $nodeId > 0) {
                                $updateAnchor = $conn->prepare('UPDATE promotion_nodes SET anchor_text=? WHERE id=? LIMIT 1');
                                if ($updateAnchor) {
                                    $updateAnchor->bind_param('si', $childAnchor, $nodeId);
                                    $updateAnchor->execute();
                                    $updateAnchor->close();
                                }
                            }
                            $node['anchor_text'] = $childAnchor;
                        }
                        $parentTargetUrl = (string)($node['parent_target_url'] ?? '');
                        if ($parentTargetUrl === '') { $parentTargetUrl = (string)$node['parent_url']; }
                        $preparedArticle = pp_promotion_prepare_child_article(
                            $cachedParent,
                            (string)$node['target_url'],
                            $parentTargetUrl,
                            $preparedLanguage,
                            (string)$node['anchor_text']
                        );
                        if (is_array($preparedArticle) && !empty($preparedArticle['htmlContent'])) {
                            if (empty($preparedArticle['language'])) { $preparedArticle['language'] = $preparedLanguage; }
                            if (empty($preparedArticle['plainText'])) {
                                $plain = trim(strip_tags((string)$preparedArticle['htmlContent']));
                                if ($plain !== '') { $preparedArticle['plainText'] = $plain; }
                            }
                            $preparedArticle['sourceUrl'] = $parentTargetUrl;
                            $preparedArticle['sourceNodeId'] = $parentId;
                            $articleMeta = [
                                'source_node_id' => $parentId,
                                'source_target_url' => $parentTargetUrl,
                                'source_level' => (int)($node['parent_level'] ?? 2),
                                'parent_result_url' => (string)($node['parent_url'] ?? ''),
                                'ancestor_source_node_id' => $level1ParentId,
                                'reuse_mode' => 'cached_parent',
                            ];
                            pp_promotion_log('promotion.level3.article_reuse', [
                                'run_id' => $runId,
                                'node_id' => $nodeId,
                                'parent_node_id' => $parentId,
                                'prepared_language' => $preparedLanguage,
                                'target_url' => (string)$node['target_url'],
                                'parent_target_url' => $parentTargetUrl,
                                'level1_parent_id' => $level1ParentId,
                            ]);
                        } else {
                            $preparedArticle = null;
                        }
                    }
                    $requirementsPayload = [
                        'min_len' => $requirements[3]['min_len'],
                        'max_len' => $requirements[3]['max_len'],
                        'level' => 3,
                        'parent_url' => $node['parent_url'],
                        'parent_context' => $parentCtx,
                        'ancestor_trail' => $trail,
                    ];
                    if ($preparedArticle) {
                        $requirementsPayload['prepared_article'] = $preparedArticle;
                        $requirementsPayload['prepared_language'] = $preparedLanguage;
                        if (!empty($articleMeta)) {
                            $requirementsPayload['article_meta'] = $articleMeta;
                        }
                    }
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, $requirementsPayload);
                }
                $res3->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level3_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=3 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL3_FAILED', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            @$conn->query("UPDATE promotion_runs SET stage='pending_crowd', status='pending_crowd' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_crowd') {
            if (!pp_promotion_is_crowd_enabled()) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready' WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $crowdPerArticle = (int)pp_promotion_settings()['crowd_per_article'];
            if ($crowdPerArticle <= 0) { $crowdPerArticle = 100; }
            $finalNodes = [];
            $finalLevel = 1;
            foreach ([3, 2, 1] as $candidateLevel) {
                $resLevel = @$conn->query('SELECT id, result_url FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=' . $candidateLevel . " AND status IN ('success','completed')");
                if ($resLevel) {
                    $candidateNodes = [];
                    while ($row = $resLevel->fetch_assoc()) {
                        $url = trim((string)$row['result_url']);
                        if ($url !== '') { $candidateNodes[] = $row; }
                    }
                    $resLevel->free();
                    if (!empty($candidateNodes)) {
                        $finalNodes = $candidateNodes;
                        $finalLevel = $candidateLevel;
                        break;
                    }
                }
            }
            if (empty($finalNodes)) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready' WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            try {
                $connCrowd = @connect_db();
            } catch (Throwable $e) { $connCrowd = null; }
            $crowdIds = [];
            if ($connCrowd) {
                $limit = max(1000, $crowdPerArticle * max(1, count($finalNodes)));
                $sql = "SELECT id, url FROM crowd_links WHERE deep_status='success' AND COALESCE(NULLIF(deep_message_excerpt,''), '') <> '' ORDER BY RAND() LIMIT " . $limit;
                if ($res = @$connCrowd->query($sql)) {
                    while ($row = $res->fetch_assoc()) {
                        $crowdIds[] = [
                            'id' => (int)($row['id'] ?? 0),
                            'url' => (string)($row['url'] ?? ''),
                        ];
                    }
                    $res->free();
                }
                $connCrowd->close();
            }
            $index = 0;
            $totalLinks = count($crowdIds);
            if ($totalLinks > 1) { shuffle($crowdIds); }
            $hasCrowdPayloadColumn = pp_promotion_ensure_crowd_payload_column($conn);
            $crowdContextCache = [];
            foreach ($finalNodes as $node) {
                $targetUrl = (string)($node['result_url'] ?? '');
                $nodeId = (int)($node['id'] ?? 0);
                if ($targetUrl === '' || $nodeId <= 0) { continue; }
                $uniqueMessages = max(1, (int)ceil($crowdPerArticle * 0.1));
                if (!array_key_exists($targetUrl, $crowdContextCache)) {
                    $crowdContextCache[$targetUrl] = pp_promotion_get_article_context($targetUrl);
                }
                $payloadArticleContext = $crowdContextCache[$targetUrl] ?? null;
                $payloadVariants = pp_promotion_generate_crowd_payloads($targetUrl, $project, $linkRow, $uniqueMessages, $payloadArticleContext);
                $variantsCount = max(1, count($payloadVariants));
                for ($i = 0; $i < $crowdPerArticle; $i++) {
                    if ($totalLinks === 0) { break 2; }
                    $chosen = $crowdIds[$index % $totalLinks];
                    $index++;
                    $variant = $payloadVariants[$i % $variantsCount];
                    $variant['crowd_link_id'] = $chosen['id'];
                    $variant['crowd_link_url'] = $chosen['url'];
                    $variant['target_url'] = $targetUrl;
                    if ($hasCrowdPayloadColumn) {
                        $payloadJson = json_encode($variant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                        if ($payloadJson === false) { $payloadJson = '{}'; }
                        $stmt = $conn->prepare('INSERT INTO promotion_crowd_tasks (run_id, node_id, crowd_link_id, target_url, status, payload_json) VALUES (?, ?, ?, ?, \'planned\', ?)');
                        if ($stmt) {
                            $cid = (int)$chosen['id'];
                            $stmt->bind_param('iiiss', $runId, $nodeId, $cid, $targetUrl, $payloadJson);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            pp_promotion_log('promotion.crowd_task_insert_failed', [
                                'run_id' => $runId,
                                'node_id' => $nodeId,
                                'crowd_link_id' => (int)$chosen['id'],
                                'with_payload' => true,
                                'error' => $conn->error,
                                'errno' => $conn->errno,
                            ]);
                        }
                    } else {
                        $stmt = $conn->prepare('INSERT INTO promotion_crowd_tasks (run_id, node_id, crowd_link_id, target_url, status) VALUES (?, ?, ?, ?, \'planned\')');
                        if ($stmt) {
                            $cid = (int)$chosen['id'];
                            $stmt->bind_param('iiis', $runId, $nodeId, $cid, $targetUrl);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            pp_promotion_log('promotion.crowd_task_insert_failed', [
                                'run_id' => $runId,
                                'node_id' => $nodeId,
                                'crowd_link_id' => (int)$chosen['id'],
                                'with_payload' => false,
                                'error' => $conn->error,
                                'errno' => $conn->errno,
                            ]);
                        }
                    }
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='crowd_ready', status='crowd_ready' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'crowd_ready') {
            @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'report_ready') {
            $report = pp_promotion_build_report($conn, $runId);
            $reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($reportJson === false) { $reportJson = '{}'; }
            @$conn->query("UPDATE promotion_runs SET status='completed', stage='completed', report_json='" . $conn->real_escape_string($reportJson) . "', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
    }
}

if (!function_exists('pp_promotion_worker')) {
    function pp_promotion_worker(?int $specificRunId = null, int $maxIterations = 20): void {
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);
        pp_promotion_log('promotion.worker.start', [
            'specific_run_id' => $specificRunId,
            'max_iterations' => $maxIterations,
        ]);
        try { $conn = @connect_db(); } catch (Throwable $e) {
            $message = $e->getMessage();
            pp_promotion_log('promotion.worker.db_error', ['error' => $message]);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Promotion worker: unable to connect to database ({$message})." . PHP_EOL);
            }
            return;
        }
        if (!$conn) {
            pp_promotion_log('promotion.worker.db_unavailable', []);
            return;
        }
        for ($i = 0; $i < $maxIterations; $i++) {
            $run = null;
            if ($specificRunId) {
                $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $specificRunId);
                    if ($stmt->execute()) { $run = $stmt->get_result()->fetch_assoc(); }
                    $stmt->close();
                }
                $specificRunId = null;
            } else {
                $sql = "SELECT * FROM promotion_runs WHERE status IN ('queued','running','pending_level1','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready') ORDER BY id ASC LIMIT 1";
                if ($res = @$conn->query($sql)) {
                    $run = $res->fetch_assoc();
                    $res->free();
                }
            }
            if (!$run) { break; }
            pp_promotion_process_run($conn, $run);
            pp_promotion_update_progress($conn, (int)$run['id']);
            usleep(200000);
        }
        $conn->close();
    }
}

if (!function_exists('pp_promotion_handle_publication_update')) {
    function pp_promotion_handle_publication_update(int $publicationId, string $status, ?string $postUrl, ?string $error, ?array $jobResult = null): void {
        try { $conn = @connect_db(); } catch (Throwable $e) { return; }
        if (!$conn) { return; }
    $stmt = $conn->prepare('SELECT run_id, id, level, target_url, parent_id, network_slug FROM promotion_nodes WHERE publication_id = ? LIMIT 1');
        if (!$stmt) { $conn->close(); return; }
        $stmt->bind_param('i', $publicationId);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return; }
        $node = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$node) { $conn->close(); return; }
    $nodeId = (int)$node['id'];
    $runId = (int)$node['run_id'];
    $nodeLevel = isset($node['level']) ? (int)$node['level'] : null;
    $nodeTargetUrl = isset($node['target_url']) ? (string)$node['target_url'] : '';
    $parentNodeId = isset($node['parent_id']) ? (int)$node['parent_id'] : 0;
        $now = date('Y-m-d H:i:s');
        $statusUpdate = in_array($status, ['success','partial'], true) ? 'success' : ($status === 'failed' ? 'failed' : $status);
        $stmt2 = $conn->prepare('UPDATE promotion_nodes SET status=?, result_url=?, error=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
        if ($stmt2) {
            $url = $postUrl ?: '';
            $err = $error ?: null;
            $stmt2->bind_param('sssi', $statusUpdate, $url, $err, $nodeId);
            $stmt2->execute();
            $stmt2->close();
        }
        pp_promotion_update_progress($conn, $runId);
        pp_promotion_log('promotion.publication_update', [
            'run_id' => $runId,
            'node_id' => $nodeId,
            'publication_id' => $publicationId,
            'new_status' => $statusUpdate,
            'original_status' => $status,
            'post_url' => $postUrl,
            'error' => $error,
        ]);
        if (in_array($statusUpdate, ['success','completed','partial'], true) && is_array($jobResult)) {
            $article = $jobResult['article'] ?? null;
            if (is_array($article) && !empty($article['htmlContent'])) {
                $meta = [
                    'level' => $nodeLevel,
                    'target_url' => $nodeTargetUrl,
                    'result_url' => $postUrl,
                    'status' => $statusUpdate,
                    'network' => (string)($node['network_slug'] ?? ''),
                    'stored_from' => 'publication_update',
                ];
                if ($parentNodeId > 0) { $meta['parent_node_id'] = $parentNodeId; }
                if (!empty($jobResult['articleMeta']) && is_array($jobResult['articleMeta'])) {
                    foreach ($jobResult['articleMeta'] as $metaKey => $metaValue) {
                        if (is_string($metaKey) && $metaKey !== '') {
                            $meta[$metaKey] = $metaValue;
                        }
                    }
                }
                $storedPath = pp_promotion_store_cached_article($nodeId, $article, $meta);
                if ($storedPath) {
                    pp_promotion_log('promotion.article_cached', [
                        'run_id' => $runId,
                        'node_id' => $nodeId,
                        'level' => $nodeLevel,
                        'path' => $storedPath,
                    ]);
                } else {
                    pp_promotion_log('promotion.article_cache_failed', [
                        'run_id' => $runId,
                        'node_id' => $nodeId,
                        'level' => $nodeLevel,
                    ]);
                }
            }
        }
        $conn->close();
    }
}

if (!function_exists('pp_promotion_get_status')) {
    function pp_promotion_get_status(int $projectId, string $url): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }
        $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE project_id = ? AND target_url = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $stmt->bind_param('is', $projectId, $url);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $run = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$run) { $conn->close(); return ['ok' => true, 'status' => 'idle']; }
        $runId = (int)$run['id'];

        $settingsSnapshot = [];
        if (!empty($run['settings_snapshot'])) {
            $decoded = json_decode((string)$run['settings_snapshot'], true);
            if (is_array($decoded)) { $settingsSnapshot = $decoded; }
        }
        $requirements = pp_promotion_get_level_requirements();
        $level1Required = isset($settingsSnapshot['level1_count']) ? (int)$settingsSnapshot['level1_count'] : (int)($requirements[1]['count'] ?? 5);
        if ($level1Required <= 0) { $level1Required = (int)($requirements[1]['count'] ?? 5); }
        $level2PerParent = isset($settingsSnapshot['level2_per_level1']) ? (int)$settingsSnapshot['level2_per_level1'] : (int)($requirements[2]['per_parent'] ?? 0);
        if ($level2PerParent < 0) { $level2PerParent = 0; }
        $level3PerParent = isset($settingsSnapshot['level3_per_level2']) ? (int)$settingsSnapshot['level3_per_level2'] : (int)($requirements[3]['per_parent'] ?? 0);
        if ($level3PerParent < 0) { $level3PerParent = 0; }
        $level3EnabledSnapshot = isset($settingsSnapshot['level3_enabled']) ? (bool)$settingsSnapshot['level3_enabled'] : pp_promotion_is_level_enabled(3);

        $levels = [
            1 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $level1Required],
            2 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0],
            3 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0],
        ];
        if ($res = @$conn->query('SELECT level, status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' GROUP BY level, status')) {
            while ($row = $res->fetch_assoc()) {
                $lvl = (int)$row['level'];
                if (!isset($levels[$lvl])) {
                    $levels[$lvl] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0];
                }
                $count = (int)$row['c'];
                $levels[$lvl]['attempted'] += $count;
                $statusNode = (string)$row['status'];
                if (in_array($statusNode, ['success','completed'], true)) { $levels[$lvl]['success'] += $count; }
                elseif (in_array($statusNode, ['failed','cancelled'], true)) { $levels[$lvl]['failed'] += $count; }
            }
            $res->free();
        }
        $level1Success = (int)($levels[1]['success'] ?? 0);
        $levels[1]['total'] = $level1Success;
        if (!isset($levels[1]['required']) || $levels[1]['required'] <= 0) {
            $levels[1]['required'] = $level1Required;
        }
        $expectedLevel2 = 0;
        if ($level2PerParent > 0 && $level1Success > 0) {
            $expectedLevel2 = $level2PerParent * $level1Success;
        }
        if (!isset($levels[2])) {
            $levels[2] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $expectedLevel2];
        }
        $levels[2]['total'] = (int)($levels[2]['success'] ?? 0);
        $levels[2]['required'] = $expectedLevel2;
        $level2Success = (int)($levels[2]['success'] ?? 0);
        $expectedLevel3 = 0;
        if ($level3EnabledSnapshot && $level3PerParent > 0 && $level2Success > 0) {
            $expectedLevel3 = $level3PerParent * $level2Success;
        }
        if (!isset($levels[3])) {
            $levels[3] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $expectedLevel3];
        }
        $levels[3]['total'] = (int)($levels[3]['success'] ?? 0);
        $levels[3]['required'] = $expectedLevel3;
        foreach ($levels as $lvl => &$info) {
            if (!isset($info['attempted'])) { $info['attempted'] = $info['success'] + $info['failed']; }
            if (!isset($info['required']) || $info['required'] < 0) { $info['required'] = 0; }
            $info['failed'] = (int)$info['failed'];
            $info['success'] = (int)$info['success'];
            $info['total'] = (int)$info['total'];
            $info['attempted'] = (int)$info['attempted'];
        }
        unset($info);
        $crowdStats = [
            'total' => 0,
            'planned' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'remaining' => 0,
            'percent' => 0.0,
            'completed_links' => 0,
            'items' => [],
        ];
        if ($res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' GROUP BY status')) {
            while ($row = $res->fetch_assoc()) {
                $status = strtolower((string)($row['status'] ?? ''));
                $count = (int)($row['c'] ?? 0);
                $crowdStats['total'] += $count;
                if (isset($crowdStats[$status])) {
                    $crowdStats[$status] += $count;
                } elseif ($status === 'posted' || $status === 'success' || $status === 'done') {
                    $crowdStats['completed'] += $count;
                } elseif ($status === 'error') {
                    $crowdStats['failed'] += $count;
                }
            }
            $res->free();
        }
        if ($crowdStats['completed'] === 0 && $crowdStats['total'] > 0) {
            $crowdStats['completed'] = $crowdStats['queued'] === 0 && $crowdStats['running'] === 0 ? $crowdStats['total'] - ($crowdStats['failed'] ?? 0) - $crowdStats['planned'] : $crowdStats['completed'];
            if ($crowdStats['completed'] < 0) { $crowdStats['completed'] = 0; }
        }
        $crowdStats['remaining'] = max(0, $crowdStats['total'] - $crowdStats['completed'] - $crowdStats['failed']);
        if ($crowdStats['total'] > 0) {
            $crowdStats['percent'] = (float)round(($crowdStats['completed'] / $crowdStats['total']) * 100, 1);
        }
        $crowdLinkCache = [];
        $taskSql = 'SELECT id, status, crowd_link_id, result_url, payload_json, target_url, updated_at FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' ORDER BY updated_at DESC, id DESC LIMIT 80';
        if ($res = @$conn->query($taskSql)) {
            while ($row = $res->fetch_assoc()) {
                $statusRaw = (string)($row['status'] ?? '');
                $status = strtolower($statusRaw);
                $payloadLink = null;
                $messageBody = null;
                $messagePreview = null;
                $messageSubject = null;
                $messageAuthor = null;
                $messageEmail = null;
                if (!empty($row['payload_json'])) {
                    $payload = json_decode((string)$row['payload_json'], true);
                    if (is_array($payload) && !empty($payload['crowd_link_url'])) {
                        $payloadLink = (string)$payload['crowd_link_url'];
                    }
                    if (is_array($payload)) {
                        if (!empty($payload['body'])) {
                            $messageBody = trim((string)$payload['body']);
                            if ($messageBody !== '') {
                                $messagePreview = $messageBody;
                                if (function_exists('mb_strlen')) {
                                    if (mb_strlen($messagePreview, 'UTF-8') > 220) {
                                        $messagePreview = rtrim(mb_substr($messagePreview, 0, 200, 'UTF-8')) . '…';
                                    }
                                } elseif (strlen($messagePreview) > 220) {
                                    $messagePreview = rtrim(substr($messagePreview, 0, 200)) . '…';
                                }
                            } else {
                                $messageBody = null;
                            }
                        }
                        if (!empty($payload['subject'])) {
                            $messageSubject = trim((string)$payload['subject']);
                        }
                        if (!empty($payload['author_name'])) {
                            $messageAuthor = trim((string)$payload['author_name']);
                        }
                        if (!empty($payload['author_email'])) {
                            $messageEmail = trim((string)$payload['author_email']);
                        }
                    }
                }
                $crowdLinkId = isset($row['crowd_link_id']) ? (int)$row['crowd_link_id'] : 0;
                if (!$payloadLink && $crowdLinkId > 0) {
                    if (array_key_exists($crowdLinkId, $crowdLinkCache)) {
                        $payloadLink = $crowdLinkCache[$crowdLinkId];
                    } else {
                        if ($resLink = @$conn->query('SELECT url FROM crowd_links WHERE id=' . $crowdLinkId . ' LIMIT 1')) {
                            if ($rowLink = $resLink->fetch_assoc()) {
                                $payloadLink = (string)($rowLink['url'] ?? '');
                            }
                            $resLink->free();
                        }
                        $crowdLinkCache[$crowdLinkId] = $payloadLink;
                    }
                }
                $resultUrl = trim((string)($row['result_url'] ?? ''));
                if ($resultUrl !== '') { $crowdStats['completed_links']++; }
                $articleUrl = (string)($row['target_url'] ?? '');
                $crowdStats['items'][] = [
                    'id' => (int)$row['id'],
                    'status' => $statusRaw,
                    'status_normalized' => $status,
                    'result_url' => $resultUrl !== '' ? $resultUrl : null,
                    'link_url' => $payloadLink ? (string)$payloadLink : null,
                    'crowd_url' => $payloadLink ? (string)$payloadLink : null,
                    'target_url' => $articleUrl,
                    'article_url' => $articleUrl,
                    'message' => $messageBody,
                    'message_preview' => $messagePreview,
                    'subject' => $messageSubject,
                    'author_name' => $messageAuthor,
                    'author_email' => $messageEmail,
                    'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
                ];
            }
            $res->free();
        }
        $conn->close();
        return [
            'ok' => true,
            'status' => (string)$run['status'],
            'stage' => (string)$run['stage'],
            'progress' => ['done' => (int)$run['progress_done'], 'total' => (int)$run['progress_total'], 'target' => $level1Required],
            'levels' => $levels,
            'crowd' => $crowdStats,
            'run_id' => $runId,
            'report_ready' => !empty($run['report_json']) || $run['status'] === 'completed',
            'charge' => [
                'amount' => (float)$run['charged_amount'],
                'discount_percent' => (float)$run['discount_percent'],
            ],
            'charged_amount' => (float)$run['charged_amount'],
            'discount_percent' => (float)$run['discount_percent'],
        ];
    }
}

if (!function_exists('pp_promotion_build_report')) {
    function pp_promotion_build_report(mysqli $conn, int $runId): array {
        $report = ['level1' => [], 'level2' => [], 'level3' => [], 'crowd' => []];
        if ($res = @$conn->query('SELECT id, parent_id, level, network_slug, result_url, status, anchor_text, target_url FROM promotion_nodes WHERE run_id=' . $runId . ' ORDER BY level ASC, id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $status = (string)($row['status'] ?? '');
                if (!in_array($status, ['success', 'completed'], true)) {
                    continue;
                }
                $entry = [
                    'id' => (int)$row['id'],
                    'parent_id' => isset($row['parent_id']) ? (int)$row['parent_id'] : null,
                    'network' => (string)$row['network_slug'],
                    'url' => (string)$row['result_url'],
                    'status' => $status,
                    'anchor' => (string)$row['anchor_text'],
                    'target_url' => (string)$row['target_url'],
                ];
                if ((int)$row['level'] === 1) { $report['level1'][] = $entry; }
                elseif ((int)$row['level'] === 2) { $report['level2'][] = $entry; }
                elseif ((int)$row['level'] === 3) { $report['level3'][] = $entry; }
            }
            $res->free();
        }
        $crowdLinkCache = [];
        if ($res = @$conn->query('SELECT id, status, crowd_link_id, target_url, result_url, payload_json, updated_at FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' ORDER BY id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $linkUrl = null;
                $messageBody = null;
                $messageSubject = null;
                $messageAuthor = null;
                $messageEmail = null;
                if (!empty($row['payload_json'])) {
                    $payload = json_decode((string)$row['payload_json'], true);
                    if (is_array($payload)) {
                        if (!empty($payload['crowd_link_url'])) {
                            $linkUrl = (string)$payload['crowd_link_url'];
                        }
                        if (!empty($payload['body'])) {
                            $body = trim((string)$payload['body']);
                            if ($body !== '') { $messageBody = $body; }
                        }
                        if (!empty($payload['subject'])) {
                            $messageSubject = trim((string)$payload['subject']);
                        }
                        if (!empty($payload['author_name'])) {
                            $messageAuthor = trim((string)$payload['author_name']);
                        }
                        if (!empty($payload['author_email'])) {
                            $messageEmail = trim((string)$payload['author_email']);
                        }
                    }
                }
                if ($linkUrl === null && isset($row['crowd_link_id'])) {
                    $cid = (int)$row['crowd_link_id'];
                    if ($cid > 0) {
                        if (isset($crowdLinkCache[$cid])) {
                            $linkUrl = $crowdLinkCache[$cid];
                        } else {
                            if ($resLink = @$conn->query('SELECT url FROM crowd_links WHERE id=' . $cid . ' LIMIT 1')) {
                                if ($rowLink = $resLink->fetch_assoc()) {
                                    $linkUrl = (string)($rowLink['url'] ?? '');
                                }
                                $resLink->free();
                            }
                            $crowdLinkCache[$cid] = $linkUrl;
                        }
                    }
                }
                $report['crowd'][] = [
                    'task_id' => isset($row['id']) ? (int)$row['id'] : null,
                    'crowd_link_id' => (int)$row['crowd_link_id'],
                    'link_url' => $linkUrl ? (string)$linkUrl : null,
                    'crowd_url' => $linkUrl ? (string)$linkUrl : null,
                    'target_url' => (string)$row['target_url'],
                    'article_url' => (string)$row['target_url'],
                    'message' => $messageBody,
                    'subject' => $messageSubject,
                    'author_name' => $messageAuthor,
                    'author_email' => $messageEmail,
                    'status' => (string)($row['status'] ?? ''),
                    'result_url' => isset($row['result_url']) && $row['result_url'] !== null ? (string)$row['result_url'] : null,
                    'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
                ];
            }
            $res->free();
        }
        return $report;
    }
}

if (!function_exists('pp_promotion_get_report')) {
    function pp_promotion_get_report(int $runId): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }
        $stmt = $conn->prepare('SELECT project_id, target_url, status, report_json FROM promotion_runs WHERE id = ? LIMIT 1');
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $stmt->bind_param('i', $runId);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) { $conn->close(); return ['ok' => false, 'error' => 'NOT_FOUND']; }
        $report = [];
        if (!empty($row['report_json'])) {
            $decoded = json_decode((string)$row['report_json'], true);
            if (is_array($decoded)) { $report = $decoded; }
        }
        if (empty($report)) {
            $report = pp_promotion_build_report($conn, $runId);
        }
        $conn->close();
        return [
            'ok' => true,
            'status' => (string)$row['status'],
            'project_id' => (int)$row['project_id'],
            'target_url' => (string)$row['target_url'],
            'report' => $report,
        ];
    }
}

?>
