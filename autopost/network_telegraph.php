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
        $mainLog = $runDir . '/telegraph.log';

        // Single-file log: meta header
        $meta = [
            'page_url' => $pageUrl,
            'anchor' => $anchor,
            'language' => $language,
            'generation_mode' => $mode,
            'use_openai' => $useOpenAI,
            'ts' => date('c'),
        ];
        @file_put_contents($mainLog, "[" . date('c') . "] META " . json_encode($meta, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        // Try to ensure Chromium is available (for puppeteer-core case) and log outcome
        if (function_exists('pp_ensure_chromium_available')) {
            $ens = @pp_ensure_chromium_available();
            @file_put_contents($mainLog, "[" . date('c') . "] ensure_chromium=" . ($ens ? 'ok' : 'skip') . "\n", FILE_APPEND);
        }

        // Compose Node.js script (OpenAI + Puppeteer) with logging & screenshots
        $js = <<<'JS'
(async () => {
  const fs = require('fs');
  const path = require('path');
  const stamp = () => new Date().toISOString();
  const LOG_FILE = process.env.LOG_FILE || '';
  const SCREEN_DIR = process.env.SCREEN_DIR || '';
  const ROOT_DIR = process.env.ROOT_DIR || '';
  const logFile = LOG_FILE;
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
  const CHROME_BIN_ENV = process.env.CHROME_BIN || process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const NM_DIR = process.env.PP_NODE_MODULES || '';

  // Load puppeteer with fallbacks and log attempts
  let puppeteer; const loadErrs = [];
  try { puppeteer = require('puppeteer'); log('puppeteer load', 'puppeteer'); }
  catch (e1) {
    loadErrs.push(e1 && e1.message ? e1.message : String(e1));
    try { puppeteer = require('puppeteer-core'); log('puppeteer load', 'puppeteer-core'); }
    catch (e2) {
      loadErrs.push(e2 && e2.message ? e2.message : String(e2));
      if (NM_DIR) {
        try { puppeteer = require(path.join(NM_DIR, 'puppeteer')); log('puppeteer load', 'nm puppeteer'); }
        catch (e3) {
          loadErrs.push(e3 && e3.message ? e3.message : String(e3));
          try { puppeteer = require(path.join(NM_DIR, 'puppeteer-core')); log('puppeteer load', 'nm puppeteer-core'); }
          catch (e4) { loadErrs.push(e4 && e4.message ? e4.message : String(e4)); }
        }
      }
    }
  }
  if (!puppeteer) { log('fatal', 'puppeteer not available', loadErrs.join(' | ')); throw new Error('puppeteer not available'); }
  try {
    let ver = '';
    try { ver = require('puppeteer/package.json').version; }
    catch(_) { try { ver = require(path.join(NM_DIR, 'puppeteer', 'package.json')).version; } catch(__){} }
    if (ver) log('puppeteer version', ver);
  } catch(_){ }

  const findChromeBin = () => {
    const cands = [];
    if (CHROME_BIN_ENV) cands.push(CHROME_BIN_ENV);
    // Puppeteer caches
    if (NM_DIR) {
      cands.push(path.join(NM_DIR, 'puppeteer', '.cache', 'puppeteer', 'chrome'));
      try {
        const cacheRoot = path.join(NM_DIR, 'puppeteer', '.cache', 'puppeteer');
        if (fs.existsSync(cacheRoot)) {
          const products = fs.readdirSync(cacheRoot);
          for (const prod of products) {
            const prodDir = path.join(cacheRoot, prod);
            if (!fs.statSync(prodDir).isDirectory()) continue;
            const vers = fs.readdirSync(prodDir);
            for (const v of vers) {
              const b = path.join(prodDir, v, 'chrome-linux', 'chrome');
              const b64 = path.join(prodDir, v, 'chrome-linux64', 'chrome');
              if (fs.existsSync(b)) cands.push(b);
              if (fs.existsSync(b64)) cands.push(b64);
            }
          }
        }
      } catch(_){}
      try {
        const lcRoot = path.join(NM_DIR, 'puppeteer', '.local-chromium');
        if (fs.existsSync(lcRoot)) {
          const plats = fs.readdirSync(lcRoot);
          for (const p of plats) {
            const platDir = path.join(lcRoot, p);
            if (!fs.statSync(platDir).isDirectory()) continue;
            const vers = fs.readdirSync(platDir);
            for (const v of vers) {
              const b = path.join(platDir, v, 'chrome-linux', 'chrome');
              const b64 = path.join(platDir, v, 'chrome-linux64', 'chrome');
              if (fs.existsSync(b)) cands.push(b);
              if (fs.existsSync(b64)) cands.push(b64);
            }
          }
        }
      } catch(_){ }
    }
    // Our own downloaded Chrome for Testing
    if (ROOT_DIR) {
      const our1 = path.join(ROOT_DIR, 'node_runtime', 'chrome', 'chrome-linux64', 'chrome');
      if (fs.existsSync(our1)) cands.push(our1);
      try {
        const walk = (dir) => {
          if (!fs.existsSync(dir)) return;
          for (const f of fs.readdirSync(dir)) {
            const p = path.join(dir, f);
            if (fs.statSync(p).isDirectory()) walk(p);
            else if (f === 'chrome') cands.push(p);
          }
        };
        walk(path.join(ROOT_DIR, 'node_runtime', 'chromium'));
      } catch(_){}}
    // Common system paths
    cands.push('/usr/bin/google-chrome','/usr/local/bin/google-chrome','/usr/bin/chromium','/usr/local/bin/chromium','/usr/bin/chromium-browser','/usr/local/bin/chromium-browser');
    // Dedup and return first existing
    const seen = new Set();
    for (const p of cands) {
      const s = String(p || '');
      if (!s || seen.has(s)) continue; seen.add(s);
      try { if (fs.existsSync(s)) return s; } catch(_){ }
    }
    return '';
  };

  log('start', 'use_openai=' + USE_OPENAI, 'nm_dir=' + (NM_DIR||'n/a'), 'chrome_bin=auto');
  // Fill in generation utilities and helpers
  async function generateContent() {
    if (USE_OPENAI && OPENAI_API_KEY) {
      try {
        const sys = [
          'You write concise, clear articles in strictly valid HTML.',
          'Output only HTML without surrounding <html> or <body>.',
          'Allowed tags: h2,p,a,ul,ol,li,strong,em,blockquote,code,pre.',
          'Include exactly one hyperlink to the provided URL using the given anchor text. Do not add UTM.'
        ].join(' ');
        const prompt = `Language: ${LANGUAGE}\nURL: ${PAGE_URL}\nAnchor: ${ANCHOR || 'source'}\nWrite ~6-10 short sections with headings and paragraphs; keep it neutral and informative.`;
        const body = {
          model: 'gpt-4o-mini',
          messages: [
            { role: 'system', content: sys },
            { role: 'user', content: prompt }
          ],
          temperature: 0.7,
          max_tokens: 1000
        };
        const resp = await fetch('https://api.openai.com/v1/chat/completions', {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${OPENAI_API_KEY}`, 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        });
        if (resp.ok) {
          const data = await resp.json();
          const c = data && data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content;
          if (c && typeof c === 'string') return c.trim();
          log('openai warn', 'no content');
        } else {
          log('openai http', String(resp.status));
        }
      } catch (e) {
        log('openai error', e && e.message ? e.message : String(e));
      }
    }
    return FALLBACK_CONTENT;
  }

  const launchOpts = { headless: 'new', args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-default-browser-check'] };
  let chromePath = CHROME_BIN_ENV;
  if (!chromePath) { chromePath = findChromeBin(); }
  if (chromePath) { launchOpts.executablePath = chromePath; }

  log('launch puppeteer', 'have_exec=' + (!!launchOpts.executablePath), 'path=' + (launchOpts.executablePath || ''));
  let browser;
  try { browser = await puppeteer.launch(launchOpts); }
  catch (e) {
    log('launch error', e && e.message ? e.message : String(e));
    if (!launchOpts.executablePath) {
      chromePath = findChromeBin();
      if (chromePath) {
        launchOpts.executablePath = chromePath;
        log('retry launch with resolved executablePath', chromePath);
        browser = await puppeteer.launch(launchOpts);
      } else {
        throw e;
      }
    } else { throw e; }
  }

  // Drive the editor: fill and publish
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
    await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36');

    await page.goto('https://telegra.ph/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await saveShot(page, '01_editor.png');

    // Wait for at least one contenteditable to appear
    await page.waitForSelector('[data-placeholder], [contenteditable="true"]', { timeout: 45000 });

    // Fill title and author
    await page.evaluate(({ title, author }) => {
      const findByPH = (arr) => {
        for (const ph of arr) {
          const el = document.querySelector(`[data-placeholder="${ph}"]`);
          if (el) return el;
        }
        return null;
      };
      const allCE = Array.from(document.querySelectorAll('[contenteditable="true"]'));
      const titleEl = findByPH(['Title','Заголовок']) || allCE[0] || null;
      const authorEl = findByPH(['Your name','Ваше имя','Автор']) || allCE[1] || null;
      if (titleEl) { titleEl.focus(); try { titleEl.innerHTML = ''; document.execCommand('insertText', false, title); } catch(_) { titleEl.textContent = title; } }
      if (authorEl) { authorEl.focus(); try { authorEl.innerHTML = ''; document.execCommand('insertText', false, author); } catch(_) { authorEl.textContent = author; } }
    }, { title: FALLBACK_TITLE, author: FALLBACK_AUTHOR });

    const html = await generateContent();

    // Insert content HTML
    await page.evaluate((html) => {
      const findByPH = (arr) => {
        for (const ph of arr) {
          const el = document.querySelector(`[data-placeholder="${ph}"]`);
          if (el) return el;
        }
        return null;
      };
      const allCE = Array.from(document.querySelectorAll('[contenteditable="true"]'));
      let contentEl = findByPH(['Your story','Ваша история','Текст']) || allCE[2] || allCE[allCE.length - 1] || null;
      if (contentEl) {
        contentEl.focus();
        try {
          // Prefer HTML insertion
          contentEl.innerHTML = html;
        } catch (e) {
          // Fallback: insert as plain text
          const tmp = document.createElement('div'); tmp.innerHTML = html;
          const text = tmp.textContent || tmp.innerText || '';
          try { document.execCommand('insertText', false, text); } catch (_) { contentEl.textContent = text; }
        }
      }
    }, html);

    await page.waitForTimeout(800);
    await saveShot(page, '02_filled.png');

    // Try to publish: click button if present, otherwise Ctrl+Enter
    let published = false;
    try {
      const btns = await page.$$eval('button', els => els.map(e => (e.innerText || e.textContent || '').trim()));
      const idx = btns.findIndex(t => /publish/i.test(t));
      if (idx >= 0) {
        const btn = (await page.$$('button'))[idx];
        await btn.click();
        published = true;
      }
    } catch(_) {}

    if (!published) {
      try {
        await page.keyboard.down('Control');
        await page.keyboard.press('Enter');
        await page.keyboard.up('Control');
        published = true;
      } catch(_) {}
    }

    // Wait for navigation to published page (URL changes to /<slug>)
    try {
      await page.waitForNavigation({ timeout: 45000, waitUntil: 'domcontentloaded' });
    } catch(_) { /* continue even if not detected */ }

    await saveShot(page, '03_published.png');

    const outUrl = page.url();
    log('published url', outUrl);

    if (/^https?:\/\/telegra\.ph\//i.test(outUrl)) {
      console.log(JSON.stringify({ url: outUrl, title: FALLBACK_TITLE, author: FALLBACK_AUTHOR }));
    } else {
      // Some deployments use the editor path; try to extract link from DOM
      try {
        const maybeLink = await page.evaluate(() => {
          const a = document.querySelector('a[href^="https://telegra.ph/"]');
          return a ? a.getAttribute('href') : '';
        });
        if (maybeLink) {
          console.log(JSON.stringify({ url: maybeLink, title: FALLBACK_TITLE, author: FALLBACK_AUTHOR }));
        }
      } catch(_){ }
    }
  } finally {
    try { await browser.close(); } catch(_){ }
  }
})();
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
            'SCREEN_DIR' => $screenDir,
            'ROOT_DIR' => PP_ROOT_PATH,
            'LOG_FILE' => $mainLog,
        ], 240);

        // Single-file log: append Node outputs and exit code
        @file_put_contents($mainLog, "[" . date('c') . "] EXIT_CODE " . (is_null($code) ? 'null' : (string)$code) . "\n", FILE_APPEND);
        if ((string)$stdout !== '') { @file_put_contents($mainLog, "[" . date('c') . "] STDOUT\n" . $stdout . "\n", FILE_APPEND); }
        if ((string)$stderr !== '') { @file_put_contents($mainLog, "[" . date('c') . "] STDERR\n" . $stderr . "\n", FILE_APPEND); }

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
        // Log the final URL
        @file_put_contents($mainLog, "[" . date('c') . "] RESULT_URL " . $url . "\n", FILE_APPEND);
        return [ 'post_url' => $url, 'author' => $author, 'title' => $title ];
    }
];
