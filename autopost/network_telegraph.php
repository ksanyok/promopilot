<?php
// Network plugin: Telegraph (simplified, Puppeteer-only with optional OpenAI generation)
// slug: telegraph
// name: Telegraph

if (!defined('PP_ROOT_PATH')) { define('PP_ROOT_PATH', realpath(__DIR__ . '/..')); }
require_once PP_ROOT_PATH . '/includes/functions.php';

// Try to include Composer autoload if present (not required here but harmless)
$autoloadPath = PP_ROOT_PATH . '/vendor/autoload.php';
if (file_exists($autoloadPath)) { require_once $autoloadPath; }

return [
    'slug' => 'telegraph',
    'name' => 'Telegraph',
    'description' => 'Publishes to telegra.ph using Puppeteer. Content can be generated via OpenAI or simple fallback.',
    'publish' => function(array $ctx) {
        $pageUrl = (string)($ctx['page_url'] ?? '');
        if (!filter_var($pageUrl, FILTER_VALIDATE_URL)) { return null; }
        $anchor = trim((string)($ctx['anchor'] ?? ''));
        $language = preg_replace('~[^a-zA-Z\-]~','', (string)($ctx['language'] ?? 'en')) ?: 'en';

        // Generation mode and OpenAI key
        $mode = function_exists('get_generation_mode') ? get_generation_mode() : 'local';
        $openaiKey = function_exists('get_openai_api_key') ? get_openai_api_key() : '';
        $useOpenAI = ($mode === 'openai' && $openaiKey !== '');

        // Ensure node runtime dependencies (puppeteer) are installed
        if (function_exists('pp_ensure_node_runtime_installed')) {
            @pp_ensure_node_runtime_installed();
        }

        // Prepare fallback content (used when OpenAI disabled or not configured)
        $fallback = function(string $url, string $anch, string $lang): array {
            $host = parse_url($url, PHP_URL_HOST) ?: 'source';
            $title = 'Overview of ' . $host;
            $authorPool1 = ['Global','Digital','Open','Bright','Creative','Insight'];
            $authorPool2 = ['Studio','Media','Press','Source','Hub','Works'];
            $author = $authorPool1[array_rand($authorPool1)] . ' ' . $authorPool2[array_rand($authorPool2)];
            $anchorText = $anch !== '' ? $anch : 'source';
            $linkHtml = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($anchorText, ENT_QUOTES) . '</a>';
            $paras = [];
            $paras[] = 'This article in ' . $lang . ' presents an accessible overview related to ' . $host . '.';
            $paras[] = 'Key reference: ' . $linkHtml . ' (included once, naturally in the flow).';
            $paras[] = 'Overview: context, core ideas, practical aspects, and implications.';
            $paras[] = 'Background: how the topic emerged and why it matters.';
            $paras[] = 'Best Practices: credibility, structure, relevance, and clear linking.';
            $paras[] = 'Insights: examples, implementation notes, pitfalls, outlook.';
            $paras[] = 'Conclusion: starting point for deeper exploration and adaptation.';
            $content = '<h2>Introduction</h2>';
            while (mb_strlen($content) < 3000) {
                foreach ($paras as $p) { $content .= '<p>' . $p . '</p>'; if (mb_strlen($content) >= 3000) break; }
                if (mb_strlen($content) < 3000) { $paras[] = 'Additional note: iterative refinement of informational resources improves clarity and trust.'; }
            }
            $content = preg_replace('~(<p>Overview:)~','<h2>Overview</h2><p>$1',$content,1);
            $content = preg_replace('~(<p>Background:)~','<h2>Background</h2><p>$1',$content,1);
            $content = preg_replace('~(<p>Best Practices:)~','<h2>Best Practices</h2><p>$1',$content,1);
            $content = preg_replace('~(<p>Insights:)~','<h2>Insights</h2><p>$1',$content,1);
            $content = preg_replace('~(<p>Conclusion:)~','<h2>Conclusion</h2><p>$1',$content,1);
            return [$title, $author, $content];
        };
        [$fallbackTitle, $fallbackAuthor, $fallbackContent] = $fallback($pageUrl, $anchor, $language);

        // Prepare per-run log directories
        $pubId = (string)($ctx['publication_id'] ?? ($ctx['id'] ?? 'na'));
        $logBase = PP_ROOT_PATH . '/logs/telegraph';
        @mkdir(PP_ROOT_PATH . '/logs', 0777, true);
        @mkdir($logBase, 0777, true);
        $runStamp = date('Ymd_His');
        $runDir = $logBase . '/' . $runStamp . '_pub' . $pubId;
        $screenDir = $runDir . '/screens';
        @mkdir($runDir, 0777, true);
        @mkdir($screenDir, 0777, true);

        // Write meta for debugging
        $meta = [
            'page_url' => $pageUrl,
            'anchor' => $anchor,
            'language' => $language,
            'generation_mode' => $mode,
            'use_openai' => $useOpenAI,
            'ts' => date('c'),
        ];
        @file_put_contents($runDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        // Compose Node.js script (OpenAI + Puppeteer) with logging & screenshots
        $js = <<<'JS'
(async () => {
  const fs = require('fs');
  const path = require('path');
  const stamp = () => new Date().toISOString();
  const LOG_DIR = process.env.LOG_DIR || '';
  const SCREEN_DIR = process.env.SCREEN_DIR || '';
  const logFile = LOG_DIR ? path.join(LOG_DIR, 'run.log') : '';
  const log = (...args) => { try { const line = `[${stamp()}] ` + args.join(' ') + '\n'; if (logFile) fs.appendFileSync(logFile, line); } catch(e){} };
  const saveShot = async (page, name) => { try { if (!SCREEN_DIR) return; const p = path.join(SCREEN_DIR, name); await page.screenshot({ path: p, fullPage: true }); log('screenshot', name); } catch(e){ log('screenshot error', name, e && e.message ? e.message : String(e)); } };

  const decode = (b64) => Buffer.from(b64 || '', 'base64').toString('utf8');
  const PAGE_URL = process.env.PAGE_URL || '';
  const ANCHOR = process.env.ANCHOR || '';
  const LANGUAGE = process.env.LANGUAGE || 'en';
  const USE_OPENAI = process.env.USE_OPENAI === '1';
  const OPENAI_API_KEY = process.env.OPENAI_API_KEY || '';
  const FALLBACK_TITLE = process.env.FALLBACK_TITLE || '';
  const FALLBACK_AUTHOR = process.env.FALLBACK_AUTHOR || '';
  const FALLBACK_CONTENT = decode(process.env.FALLBACK_CONTENT_B64 || '');
  const CHROME_BIN = process.env.CHROME_BIN || process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const NM_DIR = process.env.PP_NODE_MODULES || '';

  log('start', 'use_openai=' + USE_OPENAI, 'nm_dir=' + (NM_DIR||'n/a'), 'chrome_bin=' + (CHROME_BIN||'auto'));

  // prefer built-in fetch in Node >=18
  const httpPostJson = async (url, body, headers = {}) => {
    const started = Date.now();
    let status = 0; let ok = false; let err = '';
    try {
      const res = await fetch(url, {
        method: 'POST', headers: Object.assign({'Content-Type': 'application/json'}, headers),
        body: JSON.stringify(body)
      });
      status = res.status; ok = res.ok;
      if (!res.ok) throw new Error('HTTP ' + res.status + ' ' + res.statusText);
      const json = await res.json();
      const ms = Date.now() - started;
      log('http ok', url, 'status=' + status, 'ms=' + ms);
      return json;
    } catch (e) {
      err = e && e.message ? e.message : String(e);
      const ms = Date.now() - started;
      log('http error', url, 'status=' + status, 'ms=' + ms, err);
      throw e;
    }
  };

  async function generateWithOpenAI(pageUrl, anchorText, language, apiKey) {
    const auth = { 'Authorization': `Bearer ${apiKey}` };
    const ask = async (prompt, tag) => {
      log('openai ask', tag, 'len=' + prompt.length);
      const data = await httpPostJson('https://api.openai.com/v1/chat/completions', {
        model: 'gpt-3.5-turbo',
        messages: [{ role: 'user', content: prompt }]
      }, auth);
      const txt = (data && data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content) || '';
      const out = String(txt).trim();
      log('openai answer', tag, 'len=' + out.length, out.slice(0, 160).replace(/\s+/g,' '));
      return out;
    };
    const prompts = {
      title: `What would be a good title for an article about this link without using quotes? ${pageUrl}`,
      author: `What is a suitable author's name for an article in ${language}? Avoid using region-specific names.`,
      content: `Please write a text in ${language} with at least 3000 characters based on the following link: ${pageUrl}. The article must include the anchor text "${anchorText}" as part of a single active link in the format <a href="${pageUrl}">${anchorText}</a>. This link should be naturally integrated into the content, ideally in the first half of the article. The content should be informative, cover the topic comprehensively, and include headings. Use <h2></h2> tags for subheadings. Please ensure the article contains only this one link and focuses on integrating the anchor text naturally within the content’s flow.`
    };
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    const title = (await ask(prompts.title, 'title')).replace(/["']+/g, '');
    await sleep(800);
    const author = await ask(prompts.author, 'author');
    await sleep(800);
    const content = await ask(prompts.content, 'content');
    return { title, author, content };
  }

  // Load puppeteer with fallbacks
  let puppeteer;
  try { puppeteer = require('puppeteer'); }
  catch (e1) {
    try { puppeteer = require('puppeteer-core'); } catch (e2) {
      if (NM_DIR) {
        try { puppeteer = require(path.join(NM_DIR, 'puppeteer')); }
        catch (e3) { puppeteer = require(path.join(NM_DIR, 'puppeteer-core')); }
      }
    }
  }
  if (!puppeteer) { log('fatal', 'puppeteer not available'); throw new Error('puppeteer not available'); }
  try { const ver = require('puppeteer/package.json').version; log('puppeteer version', ver); } catch(e){ try { const ver = require(path.join(NM_DIR,'puppeteer/package.json')); if (ver && ver.version) log('puppeteer version', ver.version); } catch(e2){} }

  let data = { title: FALLBACK_TITLE, author: FALLBACK_AUTHOR, content: FALLBACK_CONTENT };
  if (USE_OPENAI && OPENAI_API_KEY) {
    try { data = await generateWithOpenAI(PAGE_URL, ANCHOR || 'source', LANGUAGE, OPENAI_API_KEY); }
    catch (e) { log('openai generation failed', e && e.message ? e.message : String(e)); }
  }
  const cleanTitle = String(data.title || '').replace(/["']+/g, '');
  const articleContent = String(data.content || FALLBACK_CONTENT || '');

  const launchOpts = { headless: 'new', args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-default-browser-check'] };
  if (CHROME_BIN) { launchOpts.executablePath = CHROME_BIN; }

  log('launch puppeteer', 'have_exec=' + (!!launchOpts.executablePath));
  let browser;
  try { browser = await puppeteer.launch(launchOpts); }
  catch (e) {
    log('launch error', e && e.message ? e.message : String(e));
    if (!launchOpts.executablePath && /executablePath|channel must be specified/i.test(String(e && e.message || ''))) {
      if (!CHROME_BIN) throw e;
      launchOpts.executablePath = CHROME_BIN;
      log('retry launch with executablePath');
      browser = await puppeteer.launch(launchOpts);
    } else { throw e; }
  }

  try {
    const page = await browser.newPage();
    log('goto telegra.ph');
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 60000 });
    await saveShot(page, 's1_goto.png');

    log('fill title');
    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 20000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(cleanTitle, { delay: 10 });
    await saveShot(page, 's2_title.png');

    log('fill author');
    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 20000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(String(data.author || FALLBACK_AUTHOR || 'Author'), { delay: 10 });
    await saveShot(page, 's3_author.png');

    log('insert content');
    await page.evaluate((html) => {
      const el = document.querySelector('p[data-placeholder="Your story..."]');
      if (el) el.innerHTML = html;
    }, articleContent);
    await saveShot(page, 's4_content.png');

    log('publish');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }),
      page.click('button.publish_button')
    ]);

    const url = page.url();
    await saveShot(page, 's5_published.png');
    log('published', url);
    process.stdout.write(JSON.stringify({ url, title: cleanTitle, author: String(data.author || FALLBACK_AUTHOR || 'Author') }) + '\n');
  } catch (e) {
    try { if (browser && browser.pages) { const pages = await browser.pages(); if (pages && pages[0]) await saveShot(pages[0], 'error.png'); } } catch(_){}
    log('fatal during publish', e && e.message ? e.message : String(e));
    throw e;
  } finally {
    if (browser) await browser.close();
    log('done');
  }
})().catch(err => {
  console.error('ERR:', err && err.message ? err.message : String(err));
  process.exit(1);
});
JS;

        // Resolve helpers and env
        $nodePath = getenv('NODE_PATH') ?: '';
        $localNodeModules = PP_ROOT_PATH . '/node_runtime/node_modules';
        if (is_dir($localNodeModules)) {
            $nodePath = $localNodeModules . ($nodePath ? PATH_SEPARATOR . $nodePath : '');
        }
        $chromeBinary = function_exists('pp_resolve_chrome_binary') ? pp_resolve_chrome_binary() : '';

        if (!function_exists('pp_run_puppeteer')) { return null; }
        [$code, $stdout, $stderr] = pp_run_puppeteer($js, [
            'PAGE_URL' => $pageUrl,
            'ANCHOR' => $anchor,
            'LANGUAGE' => $language,
            'USE_OPENAI' => $useOpenAI ? '1' : '0',
            'OPENAI_API_KEY' => $useOpenAI ? $openaiKey : '',
            'FALLBACK_TITLE' => $fallbackTitle,
            'FALLBACK_AUTHOR' => $fallbackAuthor,
            'FALLBACK_CONTENT_B64' => base64_encode($fallbackContent),
            'PUPPETEER_EXECUTABLE_PATH' => $chromeBinary,
            'CHROME_BIN' => $chromeBinary,
            'NODE_PATH' => $nodePath,
            'PP_NODE_MODULES' => $localNodeModules,
            'LOG_DIR' => $runDir,
            'SCREEN_DIR' => $screenDir,
        ], 240);

        // Persist raw outputs for diagnostics
        @file_put_contents($runDir . '/node_stdout.log', (string)$stdout);
        @file_put_contents($runDir . '/node_stderr.log', (string)$stderr);
        @file_put_contents($runDir . '/exit_code.txt', is_null($code) ? 'null' : (string)$code);

        // Parse Node output
        $url = null; $title = $fallbackTitle; $author = $fallbackAuthor;
        if ($stdout) {
            // find JSON line
            if (preg_match('~\{\s*"url"\s*:\s*"https?:\\/\\/telegra\\.ph\\/[^\"]+".*\}~', $stdout, $m)) {
                $json = json_decode($m[0], true);
                if (is_array($json) && isset($json['url'])) {
                    $url = $json['url'];
                    $title = isset($json['title']) ? (string)$json['title'] : $title;
                    $author = isset($json['author']) ? (string)$json['author'] : $author;
                }
            } elseif (preg_match('~https?://telegra\.ph/[^\s\"]+~', $stdout, $m2)) {
                $url = trim($m2[0]);
            }
        }

        if (!filter_var($url ?? '', FILTER_VALIDATE_URL)) { return null; }
        return [ 'post_url' => $url, 'author' => $author, 'title' => $title ];
    }
];
