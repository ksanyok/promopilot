<?php
// Network plugin: Telegraph
// slug: telegraph
// name: Telegraph
// Updated: now uses headless browser automation (chrome-php/chrome) instead of Telegraph & OpenAI APIs.

if (!defined('PP_ROOT_PATH')) { define('PP_ROOT_PATH', realpath(__DIR__ . '/..')); }
require_once PP_ROOT_PATH . '/includes/functions.php';

// Try to include Composer autoload if present
$autoloadPath = PP_ROOT_PATH . '/vendor/autoload.php';
if (file_exists($autoloadPath)) { require_once $autoloadPath; }

return [
    'slug' => 'telegraph',
    'name' => 'Telegraph',
    'description' => 'Telegraph article publication via headless browser (no external APIs)',
    'publish' => function(array $ctx) {
        // Validate required context
        $pageUrl = (string)($ctx['page_url'] ?? '');
        if (!filter_var($pageUrl, FILTER_VALIDATE_URL)) { return null; }
        $anchor = trim((string)($ctx['anchor'] ?? ''));
        $language = preg_replace('~[^a-zA-Z\-]~','', (string)($ctx['language'] ?? 'en'));
        if ($language === '') { $language = 'en'; }

        // Dynamic dependency class name
        $browserFactoryClass = 'HeadlessChromium\\BrowserFactory';
        if (!class_exists($browserFactoryClass)) { return null; }

        // Fetch source page meta to build content heuristically
        $fetch_html = function(string $url): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => 'PromopilotBot/1.0'
            ]);
            $html = curl_exec($ch);
            curl_close($ch);
            return is_string($html) ? $html : '';
        };

        $html = $fetch_html($pageUrl);
        $metaTitle = '';
        $metaDesc = '';
        if ($html) {
            if (preg_match('~<title>(.*?)</title>~is', $html, $m)) { $metaTitle = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
            if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) { $metaDesc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
        }

        // Generate title
        $title = $metaTitle ?: ('Overview of ' . parse_url($pageUrl, PHP_URL_HOST));
        $title = preg_replace('~["\']~','', $title); // remove quotes
        if (mb_strlen($title) > 120) { $title = mb_substr($title, 0, 117) . '...'; }

        // Generate author (two-word neutral)
        $authorPool1 = ['Global','Digital','Open','Bright','Creative','Insight'];
        $authorPool2 = ['Studio','Media','Press','Source','Hub','Works'];
        $author = $authorPool1[array_rand($authorPool1)] . ' ' . $authorPool2[array_rand($authorPool2)];

        // Basic content synthesis (no AI). Build paragraphs using meta description + structured expansion.
        $domain = parse_url($pageUrl, PHP_URL_HOST);
        $baseIntro = $metaDesc ?: ("This article provides an accessible overview of resources available at $domain.");
        $anchorText = $anchor !== '' ? $anchor : ($metaTitle ? preg_replace('~\s+~',' ', trim(mb_substr($metaTitle,0,60))) : 'source');
        $linkHtml = '<a href="' . htmlspecialchars($pageUrl, ENT_QUOTES) . '">' . htmlspecialchars($anchorText, ENT_QUOTES) . '</a>';

        $para = [];
        $para[] = $baseIntro . ' Below you will find a structured summary prepared in ' . $language . ' language.';
        $para[] = 'Key reference: ' . $linkHtml . '. This link is included once and integrated naturally into the context of the discussion.';
        $para[] = 'Overview: We examine background, core ideas, practical aspects, and implications. Each section focuses on clarity and utility for readers seeking a concise yet useful understanding.';
        $para[] = 'Background & Context: The topic associated with the referenced resource has evolved due to broader digital adoption, shifts in user expectations, and the need for reliable knowledge presentation. Readers benefit from distilled highlights.';
        $para[] = 'Practical Considerations: When exploring related materials from ' . $domain . ' it is helpful to evaluate credibility, structural organization, and relevance. Consistent formatting, semantic headings, and clean linking improve retention.';
        $para[] = 'Structured Insights: 1) Core concept explanation. 2) Supporting evidence or examples. 3) Implementation notes. 4) Common pitfalls and how to mitigate them. 5) Forward-looking perspective on adaptation and scaling.';
        $para[] = 'Further Reflection: By synthesizing descriptive data with functional interpretation, content remains approachable. Emphasis on value, precision, and minimal redundancy helps maintain engagement and trust.';
        $para[] = 'Conclusion: Readers can leverage the referenced material as a starting point for deeper exploration, adaptation in projects, or educational purposes while maintaining mindful evaluation of updates.';

        // Expand to reach ~2500+ chars
        $content = '';
        while (mb_strlen($content) < 2600) {
            foreach ($para as $p) { $content .= '<p>' . $p . '</p>'; if (mb_strlen($content) > 2600) break; }
            if (mb_strlen($content) < 2600) { $para[] = 'Supplemental Note: Iterative refinement of informational assets encourages incremental quality improvements and fosters better knowledge ecosystems.'; }
        }

        // Insert simple H2 headings
        $content = '<h2>Introduction</h2>' . $content;
        $content = preg_replace('~(<p>Overview:)~','<h2>Overview</h2><p>$1',$content,1);
        $content = preg_replace('~(<p>Background & Context:)~','<h2>Background</h2><p>$1',$content,1);
        $content = preg_replace('~(<p>Practical Considerations:)~','<h2>Practical Considerations</h2><p>$1',$content,1);
        $content = preg_replace('~(<p>Structured Insights:)~','<h2>Structured Insights</h2><p>$1',$content,1);
        $content = preg_replace('~(<p>Conclusion:)~','<h2>Conclusion</h2><p>$1',$content,1);

        // Ensure only single link
        // Remove accidental duplicate raw urls
        $content = preg_replace('~https?://[^\s<>]+~','',$content);
        // Remove additional <a> tags except the first occurrence of our $linkHtml
        if (substr_count($content, '<a ') > 1) {
            $firstPos = strpos($content, '<a ');
            $after = substr($content, $firstPos + 3);
            $after = preg_replace('~<a [^>]+>.*?</a>~is','', $after);
            $content = substr($content,0,$firstPos+3).$after; // crude but acceptable for heuristic
        }

        // Launch headless browser and publish
        try {
            $browserFactory = new $browserFactoryClass();
            $browser = $browserFactory->createBrowser([
                'headless' => true,
                'noSandbox' => true,
                'enableImages' => false,
                'customFlags' => ['--disable-dev-shm-usage','--disable-gpu']
            ]);
            $page = $browser->createPage();
            $page->navigate('https://telegra.ph/')->waitForNavigation();

            // Title
            $page->evaluate('document.querySelector(\'h1[data-placeholder="Title"]\').innerText = "";');
            $page->evaluate('document.querySelector(\'h1[data-placeholder="Title"]\').focus();');
            $page->keyboard()->typeText($title);

            // Author
            $page->evaluate('document.querySelector(\'address[data-placeholder="Your name"]\').focus();');
            $page->keyboard()->typeText($author);

            // Content
            $escaped = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            $page->evaluate('document.querySelector(\'p[data-placeholder="Your story..."]\').innerHTML = ' . $escaped . ';');

            // Publish
            $page->evaluate('document.querySelector(\'button.publish_button\').click();');
            $page->waitForNavigation();
            $finalUrl = $page->getCurrentUrl();
            $browser->close();
        } catch (\Throwable $e) {
            if (isset($browser)) { try { $browser->close(); } catch (\Throwable $e2) {} }
            return null;
        }

        if (!filter_var($finalUrl ?? '', FILTER_VALIDATE_URL)) { return null; }

        return [
            'post_url' => $finalUrl,
            'author' => $author,
            'title' => $title,
        ];
    }
];
