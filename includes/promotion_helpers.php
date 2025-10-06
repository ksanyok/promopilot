<?php
// Helper functions extracted from promotion.php to keep main module lean

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
            $normalized = trim($raw, " \t\-_:;.,!?" . ")" . '\"');
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
