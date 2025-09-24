<?php
// Network plugin: Telegraph
// slug: telegraph
// name: Telegraph
// This uses OpenAI API (requires setting openai_api_key) and Telegraph public API.

if (!defined('PP_ROOT_PATH')) { define('PP_ROOT_PATH', realpath(__DIR__ . '/..')); }
require_once PP_ROOT_PATH . '/includes/functions.php';

return [
    'slug' => 'telegraph',
    'name' => 'Telegraph',
    'publish' => function(array $ctx) {
        $openaiKey = get_openai_api_key();
        if ($openaiKey === '') { return null; }
        $pageUrl = (string)($ctx['page_url'] ?? '');
        if (!filter_var($pageUrl, FILTER_VALIDATE_URL)) { return null; }
        $anchor = trim((string)($ctx['anchor'] ?? ''));
        $language = preg_replace('~[^a-zA-Z\-]~','', (string)($ctx['language'] ?? 'en'));
        if ($language === '') { $language = 'en'; }

        // Simple HTTP helper
        $http_json = function(string $url, array $payload, int $timeout=40) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($res === false) return null;
            $data = json_decode($res, true);
            return $data ?: null;
        };

        $chat = function(string $prompt) use ($openaiKey) {
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            $body = [
                'model' => 'gpt-4o-mini',
                'messages' => [ ['role'=>'user','content'=>$prompt] ],
                'temperature' => 0.7,
                'max_tokens' => 800,
            ];
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $openaiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
            ]);
            $res = curl_exec($ch);
            if ($res === false) { curl_close($ch); return null; }
            $data = json_decode($res, true);
            curl_close($ch);
            $txt = $data['choices'][0]['message']['content'] ?? null;
            if (!is_string($txt)) return null;
            return trim($txt);
        };

        $titlePrompt = "Give a concise engaging article title (no quotes) for: {$pageUrl}";
        $authorPrompt = "Provide a neutral author name (2 words) suitable for {$language} audience.";
        $contentPrompt = "Write an informative article in {$language} (min 2500 characters) about the topic of {$pageUrl}. Include exactly one hyperlink using anchor text '{$anchor}' if anchor not empty else derive a natural anchor. The link HTML must appear once as <a href=\"{$pageUrl}\">{$anchor}\n</a>. Structure with H2 subheadings.";

        $title = $chat($titlePrompt) ?: 'Article';
        $author = $chat($authorPrompt) ?: 'Author';
        $content = $chat($contentPrompt) ?: ('Link: <a href="'.$pageUrl.'">'.$anchor.'</a>');

        // Ensure single link formatting
        $content = preg_replace('~https?://[^\s<>]+~', '', $content); // remove raw urls
        if ($anchor !== '') {
            if (strpos($content, '<a ') === false) {
                $insertion = '<a href="'.htmlspecialchars($pageUrl, ENT_QUOTES).'">'.htmlspecialchars($anchor, ENT_QUOTES).'</a>';
                $content = $insertion."\n\n".$content;
            }
        }

        // Telegraph API
        $shortName = 'pp'.substr(sha1($author),0,6);
        $accCh = curl_init('https://api.telegra.ph/createAccount');
        $accFields = http_build_query(['short_name'=>$shortName,'author_name'=>$author]);
        curl_setopt_array($accCh, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => $accFields,
        ]);
        $accRes = curl_exec($accCh);
        curl_close($accCh);
        $accData = json_decode($accRes, true);
        $token = $accData['result']['access_token'] ?? null;
        if (!$token) { return null; }

        // Basic HTML -> node conversion (very naive): wrap as paragraph
        $safeHtml = strip_tags($content, '<p><h2><a><strong><em><ul><ol><li><br><b><i>');
        // Telegraph expects nodes in JSON
        $nodes = json_encode([[ 'tag'=>'p', 'children'=>[$safeHtml] ]], JSON_UNESCAPED_UNICODE);

        $pageCh = curl_init('https://api.telegra.ph/createPage');
        $pageFields = [
            'access_token' => $token,
            'title' => mb_substr($title,0,128),
            'content' => $nodes,
            'author_name' => $author,
        ];
        curl_setopt_array($pageCh, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => http_build_query($pageFields),
        ]);
        $pageRes = curl_exec($pageCh);
        curl_close($pageCh);
        $pageData = json_decode($pageRes, true);
        $url = $pageData['result']['url'] ?? null;
        if (!$url) { return null; }

        return [
            'post_url' => $url,
            'author' => $author,
            'title' => $title,
        ];
    }
];
