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
        $usePhpChrome = class_exists($browserFactoryClass);
        if (!$usePhpChrome && function_exists('autopost_log')) autopost_log('telegraph: BrowserFactory class missing');

        // Helper: Node.js Puppeteer fallback publisher
        $publishViaPuppeteer = function(string $title, string $author, string $htmlContent) use ($pageUrl, $anchor, $language) {
            $log = function($msg){ if (function_exists('autopost_log')) autopost_log($msg); };

            // Ensure shared node runtime is installed
            if (function_exists('pp_ensure_node_runtime_installed')) {
                $ok = pp_ensure_node_runtime_installed();
                $log('telegraph: ensure node runtime installed => ' . ($ok ? 'ok' : 'failed'));
            }

            $nodeBin = function_exists('pp_resolve_node_binary') ? pp_resolve_node_binary() : '';
            $chromeBinary = function_exists('pp_resolve_chrome_binary') ? pp_resolve_chrome_binary() : '';
            $nodePath = getenv('NODE_PATH') ?: '';
            $localNodeModules = defined('PP_ROOT_PATH') ? (PP_ROOT_PATH . '/node_runtime/node_modules') : __DIR__ . '/../node_runtime/node_modules';
            if (is_dir($localNodeModules)) {
                $nodePath = $localNodeModules . ($nodePath ? PATH_SEPARATOR . $nodePath : '');
            }

            $log('telegraph: puppeteer fallback start, nodeBin=' . ($nodeBin ?: 'not-found') . ' chromeBinary=' . ($chromeBinary ?: 'auto'));

            // Abort early if no node binary found
            if ($nodeBin === '') { $log('telegraph: node binary not found'); return null; }

            $contentB64 = base64_encode($htmlContent);
            $js = <<<'JS'
(async () => {
  const decode = (b64) => Buffer.from(b64, 'base64').toString('utf8');
  const TITLE = process.env.TITLE || '';
  const AUTHOR = process.env.AUTHOR || '';
  const CONTENT_HTML = decode(process.env.CONTENT_B64 || '');
  const CHROME_BIN = process.env.CHROME_BIN || process.env.PUPPETEER_EXECUTABLE_PATH || '';

  let puppeteer;
  try { puppeteer = require('puppeteer'); }
  catch (e1) {
    try { puppeteer = require('puppeteer-core'); } catch (e2) {
      console.error('ERR: puppeteer not installed');
      process.exit(2);
    }
  }

  const launchOpts = { headless: 'new' };
  if (CHROME_BIN) { launchOpts.executablePath = CHROME_BIN; }

  const browser = await puppeteer.launch(launchOpts);
  try {
    const page = await browser.newPage();
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 60000 });

    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 20000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(TITLE, { delay: 10 });

    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 20000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(AUTHOR, { delay: 10 });

    await page.evaluate((html) => {
      const el = document.querySelector('p[data-placeholder="Your story..."]');
      if (el) el.innerHTML = html;
    }, CONTENT_HTML);

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }),
      page.click('button.publish_button')
    ]);

    const url = page.url();
    process.stdout.write(url + '\n');
  } finally {
    await browser.close();
  }
})().catch(err => {
  console.error('ERR:', err && err.message ? err.message : String(err));
  process.exit(1);
});
JS;

            if (function_exists('pp_run_puppeteer')) {
                [$code, $stdout, $stderr] = pp_run_puppeteer($js, [
                    'TITLE' => $title,
                    'AUTHOR' => $author,
                    'CONTENT_B64' => $contentB64,
                    'PUPPETEER_EXECUTABLE_PATH' => $chromeBinary,
                    'CHROME_BIN' => $chromeBinary,
                    'NODE_PATH' => $nodePath,
                ], 120);
                if ($stderr) { $log('telegraph puppeteer stderr: ' . trim($stderr)); }
                if (preg_match('~https?://telegra\.ph/[^\s\"]+~', $stdout, $m)) {
                    $url = trim($m[0]);
                    $log('telegraph: puppeteer published => ' . $url);
                    return $url;
                }
                $log('telegraph: puppeteer stdout (no url): ' . trim($stdout));
                return null;
            }

            // Fallback if pp_run_puppeteer helper is unavailable (should not happen)
            return null;
        };

        // Extended environment logging (once per request)
        if (function_exists('autopost_log')) {
            static $envLogged = false;
            if (!$envLogged) {
                $envLogged = true;
                $php = PHP_VERSION; $sapi = php_sapi_name();
                $os = function_exists('php_uname') ? @php_uname() : PHP_OS;
                $disabled = (string)ini_get('disable_functions');
                $pathEnv = (string)getenv('PATH');
                $chromeEnv = (string)getenv('CHROME_PATH');
                $libVer = 'unknown';
                if (class_exists('Composer\\InstalledVersions')) {
                    try { $libVer = (string)Composer\InstalledVersions::getPrettyVersion('chrome-php/chrome'); } catch (\Throwable $e) { $libVer = 'unknown'; }
                }
                autopost_log('env: php=' . $php . ' sapi=' . $sapi . ' os=' . $os);
                autopost_log('env: chrome-php/chrome=' . $libVer);
                autopost_log('env: PATH=' . $pathEnv);
                autopost_log('env: CHROME_PATH=' . $chromeEnv);
                autopost_log('env: disable_functions=' . $disabled);
            }
        }

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

        $finalUrl = null;

        // Launch headless browser and publish (PHP chrome-php)
        if ($usePhpChrome) {
            try {
                $browserFactory = new $browserFactoryClass();
                // Resolve Chrome binary automatically (no admin setting)
                $chromeBinary = function_exists('pp_resolve_chrome_binary') ? pp_resolve_chrome_binary() : '';
                if ($chromeBinary === '') { $chromeBinary = getenv('CHROME_PATH') ?: getenv('CHROME_BIN') ?: ''; }
                // Try common paths if env not set
                if (!$chromeBinary) {
                    $candidates = [
                        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', // macOS Chrome
                        '/Applications/Chromium.app/Contents/MacOS/Chromium',          // macOS Chromium
                        '/opt/homebrew/bin/chromium',                                   // macOS Homebrew ARM
                        '/opt/homebrew/bin/google-chrome',                              // macOS Homebrew
                        '/usr/local/bin/chromium', '/usr/local/bin/chromium-browser',
                        '/usr/local/bin/google-chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'
                    ];
                    foreach ($candidates as $p) { if (is_file($p) && is_executable($p)) { $chromeBinary = $p; break; } }
                    if (!$chromeBinary && function_exists('autopost_log')) autopost_log('telegraph: Chrome binary not found, relying on default lookup');
                }

                // Detailed binary diagnostics
                if (function_exists('autopost_log')) {
                    if ($chromeBinary) {
                        $exists = is_file($chromeBinary) ? '1' : '0';
                        $exec = is_executable($chromeBinary) ? '1' : '0';
                        $perm = function_exists('fileperms') ? decoct(@fileperms($chromeBinary) & 0777) : 'n/a';
                        autopost_log('telegraph: chromeBinary=' . $chromeBinary . ' exists=' . $exists . ' exec=' . $exec . ' perms=' . $perm);
                        // Try to get browser version if functions allowed
                        $disabled = strtolower((string)ini_get('disable_functions'));
                        $canExec = (strpos($disabled,'shell_exec')===false && strpos($disabled,'proc_open')===false && strpos($disabled,'exec')===false);
                        if ($canExec) {
                            $cmd = escapeshellarg($chromeBinary) . ' --version 2>&1';
                            $out = @shell_exec($cmd);
                            if (is_string($out) && $out!=='') { autopost_log('telegraph: chrome --version => ' . trim($out)); }
                            else { autopost_log('telegraph: unable to read chrome --version (empty output)'); }
                        } else {
                            autopost_log('telegraph: exec functions disabled, skip chrome --version');
                        }
                    } else {
                        autopost_log('telegraph: chromeBinary unresolved (will rely on library auto-detect)');
                    }
                }

                $options = array_filter([
                    'headless' => true,
                    'noSandbox' => true,
                    'enableImages' => false,
                    'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                    'customFlags' => ['--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-software-rasterizer','--no-zygote','--single-process','--disable-setuid-sandbox'],
                    'startupTimeout' => 30000, // ms
                    'chromeBinary' => $chromeBinary ?: null,
                ]);
                if (function_exists('autopost_log')) autopost_log('telegraph: launching Chrome ' . ($options['chromeBinary'] ?? 'auto'));
                $browser = $browserFactory->createBrowser($options);
                $page = $browser->createPage();
                $page->navigate('https://telegra.ph/')->waitForNavigation();

                // Wait for form + csrf (token might appear after short delay)
                $csrf = null; $attempts=0;
                while ($attempts < 20) { // up to ~5s
                    $csrf = $page->evaluate('var i=document.querySelector("input[name=csrf]"); return i? i.value : null;')->getReturnValue();
                    if ($csrf) break; usleep(250000); $attempts++;
                }

                // Title
                $page->evaluate('const el=document.querySelector("h1[data-placeholder=\\"Title\\"]"); if(el){el.innerText=""; el.focus();}');
                $page->keyboard()->typeText($title);
                usleep(200000);

                // Author
                $page->evaluate('const a=document.querySelector("address[data-placeholder=\\"Your name\\"]"); if(a){a.textContent=""; a.focus();}');
                $page->keyboard()->typeText($author);
                usleep(200000);

                // Content insertion strategy
                $fast = getenv('TELEGRAPH_FAST') === '1';
                if ($fast) {
                    $escaped = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    $page->evaluate('(function(html){ var t=document.querySelector("p[data-placeholder=\\"Your story...\\"]"); if(!t) return; t.focus(); try{document.execCommand("selectAll",false,null);}catch(e){}; try{document.execCommand("insertHTML",false,html);}catch(e){t.innerHTML=html;} ["input","keyup","change"].forEach(e=>t.dispatchEvent(new Event(e,{bubbles:true}))); })( ' . $escaped . ' );');
                } else {
                    // Slow typing to trigger all events and avoid CSRF issues
                    $plain = $content;
                    // Replace headings with uppercase lines + blank line
                    $plain = preg_replace_callback('~<h2>(.*?)</h2>~i', function($m){ return "\n".strtoupper(trim(strip_tags($m[1])))."\n\n"; }, $plain);
                    // Strip remaining tags except <a>
                    $plain = strip_tags($plain, '<a>');
                    // Convert links to text anchor (Telegraph will preserve clickable link when typed fully) -> keep HTML anchor? We'll just keep plain anchor text + URL
                    $plain = preg_replace_callback('~<a[^>]+href=\"([^\"]+)\"[^>]*>(.*?)</a>~i', function($m){ return $m[2].' ('.$m[1].')'; }, $plain);
                    $paras = preg_split('~\n{2,}~', $plain);
                    $page->evaluate('var c=document.querySelector("p[data-placeholder=\\"Your story...\\"]"); if(c){c.focus();}');
                    foreach ($paras as $idx=>$p) {
                        $line = trim($p);
                        if ($line==='') continue;
                        $page->keyboard()->typeText($line);
                        usleep(80000);
                        if ($idx < count($paras)-1) { $page->keyboard()->typeRawKey('\n'); usleep(80000); $page->keyboard()->typeRawKey('\n'); }
                    }
                }

                // Poll again for csrf (typing often triggers its creation)
                $csrf = null; $attempts=0;
                while ($attempts < 40) { // up to ~10s total
                    $csrf = $page->evaluate('var i=document.querySelector("input[name=csrf]"); return i? i.value : null;')->getReturnValue();
                    if ($csrf) break; usleep(250000); $attempts++;
                }
                if (!$csrf) { if (function_exists('autopost_log')) autopost_log('telegraph: csrf not found'); $browser->close(); throw new \RuntimeException('csrf not found'); }

                usleep(400000);
                $page->evaluate('(function(){ const b=document.querySelector("button.publish_button"); if(b) b.click(); })();');
                $page->waitForNavigation();
                $finalUrl = $page->getCurrentUrl();
                $browser->close();
            } catch (\Throwable $e) {
                if (isset($browser)) { try { $browser->close(); } catch (\Throwable $e2) {} }
                if (function_exists('autopost_log')) {
                    autopost_log('telegraph exception: ' . $e->getMessage());
                    if (method_exists($e,'getFile')) { autopost_log('telegraph exception at ' . $e->getFile() . ':' . $e->getLine()); }
                    $prev = $e->getPrevious();
                    if ($prev) { autopost_log('telegraph previous: ' . get_class($prev) . ' ' . $prev->getMessage()); }
                }
                $finalUrl = null; // will try puppeteer fallback below
            }
        }

        // Fallback to Node.js Puppeteer if needed
        if (!filter_var($finalUrl ?? '', FILTER_VALIDATE_URL)) {
            if (function_exists('autopost_log')) autopost_log('telegraph: trying puppeteer fallback');
            $finalUrl = $publishViaPuppeteer($title, $author, $content);
        }

        if (!filter_var($finalUrl ?? '', FILTER_VALIDATE_URL)) { if (function_exists('autopost_log')) autopost_log('telegraph: finalUrl invalid'); return null; }

        return [
            'post_url' => $finalUrl,
            'author' => $author,
            'title' => $title,
        ];
    }
];
