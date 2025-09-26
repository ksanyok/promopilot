// Network: Telegraph Publication
// Description: Publishes rich articles to https://telegra.ph/ using Puppeteer automation.

// Reorder requires so logging is initialized before loading optional deps
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const fsp = fs.promises;

function ensureDirSync(dir) {
  try { fs.mkdirSync(dir, { recursive: true }); } catch (_) {}
}

function ts() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return (
    d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
    pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
  );
}

// Map language code to readable name for prompts (helps LLM stick to requested language)
function languageLabel(lang) {
  const L = String(lang || '').trim().toLowerCase();
  const map = {
    ru: 'Russian',
    en: 'English',
    uk: 'Ukrainian', ua: 'Ukrainian',
    de: 'German',
    fr: 'French',
    es: 'Spanish',
    it: 'Italian',
    pt: 'Portuguese',
    pl: 'Polish',
    tr: 'Turkish',
    kk: 'Kazakh',
    zh: 'Chinese',
    ja: 'Japanese',
    ar: 'Arabic'
  };
  return map[L] || (L ? L : 'English');
}

function redactSecrets(obj) {
  const mask = (v) => (typeof v === 'string' && v.length > 6 ? v.slice(0, 3) + '***' + v.slice(-3) : '***');
  const walk = (v) => {
    if (v == null) return v;
    if (Array.isArray(v)) return v.map(walk);
    if (typeof v === 'object') {
      const out = {};
      for (const [k, val] of Object.entries(v)) {
        if (/key|token|authorization|password|secret/i.test(k)) out[k] = mask(String(val || ''));
        else out[k] = walk(val);
      }
      return out;
    }
    if (typeof v === 'string') return v;
    return v;
  };
  return walk(obj);
}

const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
ensureDirSync(LOG_DIR);
const LOG_FILE = process.env.PP_LOG_FILE || path.join(
  LOG_DIR,
  `telegraph-${new Date().toISOString().replace(/[:.]/g, '-')}--${process.pid}.log`
);

function logLine(message, data) {
  try {
    const line = `[${ts()}] ${message}` + (data !== undefined ? ` | ${JSON.stringify(redactSecrets(data))}` : '') + '\n';
    fs.appendFileSync(LOG_FILE, line);
  } catch (_) {}
}

// Global guards to always emit JSON on unexpected failures
process.on('uncaughtException', (err) => {
  logLine('uncaughtException', { error: String(err), stack: err && err.stack });
  try { console.log(JSON.stringify({ ok: false, error: 'UNCAUGHT', details: String(err) })); } catch (_) {}
  process.exit(1);
});
process.on('unhandledRejection', (reason) => {
  logLine('unhandledRejection', { reason: String(reason) });
  try { console.log(JSON.stringify({ ok: false, error: 'UNHANDLED_REJECTION', details: String(reason) })); } catch (_) {}
  process.exit(1);
});

function parseSemver(v) {
  const m = String(v || '').trim().match(/^v?(\d+)\.(\d+)\.(\d+)/);
  if (!m) return { major: 0, minor: 0, patch: 0 };
  return { major: +m[1], minor: +m[2], patch: +m[3] };
}
function cmpSemver(a, b) {
  const A = parseSemver(a), B = parseSemver(b);
  if (A.major !== B.major) return A.major - B.major;
  if (A.minor !== B.minor) return A.minor - B.minor;
  return A.patch - B.patch;
}
function getPkgInfo(pkgName) {
  try {
    const pkgJsonPath = require.resolve(`${pkgName}/package.json`);
    const json = JSON.parse(fs.readFileSync(pkgJsonPath, 'utf8'));
    return { version: json.version || '', pkgJsonPath };
  } catch (e) {
    return { version: '', pkgJsonPath: null };
  }
}

const MIN_PPTR_VERSION = process.env.PP_MIN_PPTR_VERSION || '24.10.2';

let puppeteer;
async function loadPuppeteerOrExit() {
  try {
    if (!puppeteer) {
      // Diagnostics: where Node would resolve these packages from
      try {
        const pPath = require.resolve('puppeteer');
        logLine('puppeteer resolve', { path: pPath });
      } catch (_) {}
      try {
        const pcPath = require.resolve('puppeteer-core');
        logLine('puppeteer-core resolve', { path: pcPath });
      } catch (_) {}

      const pInfo = getPkgInfo('puppeteer');
      const pcInfo = getPkgInfo('puppeteer-core');
      if (pInfo.version) logLine('puppeteer version', pInfo);
      if (pcInfo.version) logLine('puppeteer-core version', pcInfo);

      const tryList = [];
      if (pInfo.version && cmpSemver(pInfo.version, MIN_PPTR_VERSION) >= 0) tryList.push({ type: 'import', name: 'puppeteer' });
      if (pcInfo.version && cmpSemver(pcInfo.version, MIN_PPTR_VERSION) >= 0) tryList.push({ type: 'import', name: 'puppeteer-core' });

      // If none meet minimum, still attempt modern import order but will emit helpful error
      if (tryList.length === 0) {
        logLine('puppeteer min version unmet', { min: MIN_PPTR_VERSION, puppeteer: pInfo, puppeteerCore: pcInfo });
        // Prefer puppeteer-core first when both are old to avoid bundled-browser issues
        tryList.push({ type: 'import', name: 'puppeteer-core' });
        tryList.push({ type: 'import', name: 'puppeteer' });
      }

      let lastErr;
      for (const attempt of tryList) {
        try {
          if (attempt.type === 'import') {
            const mod = await import(attempt.name);
            puppeteer = mod.default || mod;
          } else if (attempt.type === 'require') {
            const mod = require(attempt.name);
            puppeteer = mod && mod.default ? mod.default : mod;
          }
          // Basic sanity check: launch function exists
          if (puppeteer && typeof puppeteer.launch === 'function') {
            break;
          }
          lastErr = new Error('Loaded module lacks launch()');
          puppeteer = undefined;
        } catch (e) {
          lastErr = e;
          puppeteer = undefined;
        }
      }

      if (!puppeteer) {
        const payload = {
          ok: false,
          error: 'PUPPETEER_VERSION_UNSUPPORTED',
          details: String(lastErr || 'Unknown error'),
          node: process.version,
          minRequired: MIN_PPTR_VERSION,
          detected: { puppeteer: pInfo, puppeteerCore: pcInfo },
          hint: 'Update on server: npm i puppeteer@^24.10.2 puppeteer-core@^24.10.2',
        };
        logLine('Puppeteer load failed', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
    }
    // Best-effort log of package version
    try {
      const ver = (puppeteer && typeof puppeteer.version === 'function') ? puppeteer.version() : undefined;
      if (ver) logLine('Puppeteer loaded', { version: ver });
    } catch (_) {}
    return puppeteer;
  } catch (error) {
    logLine('Puppeteer load failed', { error: String(error), node: process.version });
    console.log(JSON.stringify({ ok: false, error: 'PUPPETEER_LOAD_FAILED', details: String(error), node: process.version }));
    process.exit(1);
  }
}

// Prefer built-in fetch (Node.js 18+). No external node-fetch.
const fetch = typeof global.fetch === 'function' ? global.fetch : null;
if (!fetch) {
  logLine('fetch missing', { hint: 'Run on Node.js 18+ where fetch/FormData/Blob are built-in' });
  console.log(JSON.stringify({ ok: false, error: 'FETCH_UNAVAILABLE', details: 'Global fetch not available. Use Node 18+.' }));
  process.exit(1);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function pathExists(filePath) {
  if (!filePath) return false;
  try {
    await fsp.access(filePath, fs.constants.X_OK);
    return true;
  } catch (_) {
    return false;
  }
}

function collectChromeCandidates() {
  const candidates = [];
  const envVars = ['PUPPETEER_EXECUTABLE_PATH', 'PP_CHROME_PATH', 'CHROME_PATH', 'GOOGLE_CHROME_BIN', 'CHROME_BIN'];
  envVars.forEach((key) => {
    const val = process.env[key];
    if (val && val.trim()) { candidates.push(val.trim()); }
  });

  const commands = [
    'command -v google-chrome',
    'command -v google-chrome-stable',
    'command -v chrome',
    'command -v chromium',
    'command -v chromium-browser',
    'command -v headless-shell',
    'which google-chrome',
    'which chromium',
  ];
  commands.forEach((cmd) => {
    try {
      const out = execSync(`/bin/bash -lc "${cmd}"`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
      if (out) {
        out.split(/\s+/).forEach((entry) => {
          if (entry && entry.includes('/')) { candidates.push(entry.trim()); }
        });
      }
    } catch (_) { /* ignore */ }
  });

  const home = process.env.HOME || '';
  const globCandidates = [
    '/usr/local/bin/google-chrome',
    '/usr/local/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
    '/bin/google-chrome',
    '/bin/chromium',
    '/opt/google/chrome/google-chrome',
    '/opt/google/chrome/chrome',
    '/opt/chrome/chrome',
    '/snap/bin/chromium',
    '/usr/local/sbin/google-chrome',
    `${home}/.local/bin/google-chrome`,
    `${home}/bin/google-chrome`,
    // macOS app bundle executables
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
    // Per-user Applications (macOS)
    `${home}/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`,
    `${home}/Applications/Chromium.app/Contents/MacOS/Chromium`,
    `${home}/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge`,
    // Project-local portable Chrome
    path.join(process.cwd(), 'node_runtime', 'chrome', 'chrome'),
    path.join(process.cwd(), 'node_runtime', 'chrome', 'chrome-linux64', 'chrome'),
    path.join(process.cwd(), 'node_runtime', 'chrome', 'headless_shell'),
  ];

  const patterns = [
    '/opt/alt/nodejs*/usr/bin/google-chrome',
    '/opt/alt/nodejs*/usr/bin/chromium',
    '/opt/alt/nodejs*/bin/google-chrome',
    '/opt/alt/chrome*/bin/google-chrome',
    '/opt/chrome*/bin/google-chrome',
    '/opt/google/chrome*/chrome',
    `${home}/.nix-profile/bin/google-chrome`,
    `${home}/.cache/puppeteer/chrome/linux-*/chrome-linux64/chrome`,
    // Project-local portable Chrome (globbed)
    `${process.cwd()}/node_runtime/chrome/*/chrome`,
    `${process.cwd()}/node_runtime/chrome/*/chrome-linux64/chrome`,
  ];

  patterns.forEach((pattern) => {
    try {
      const out = execSync(`/bin/bash -lc "ls -1 ${pattern} 2>/dev/null"`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
      if (out) {
        out.split(/\r?\n/).forEach((entry) => {
          if (entry) { candidates.push(entry.trim()); }
        });
      }
    } catch (_) { /* ignore */ }
  });

  // macOS Spotlight: locate app bundles and derive binary path
  try {
    const apps = execSync(`/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.google.Chrome || kMDItemCFBundleIdentifier==org.chromium.Chromium || kMDItemCFBundleIdentifier==com.microsoft.Edgemac' 2>/dev/null | sed -n '1,20p'`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
    if (apps) {
      apps.split(/\r?\n/).forEach((appPath) => {
        appPath = appPath.trim();
        if (!appPath || !appPath.endsWith('.app')) return;
        let bin;
        if (/Chromium\.app/i.test(appPath)) bin = path.join(appPath, 'Contents', 'MacOS', 'Chromium');
        else if (/Edge\.app/i.test(appPath)) bin = path.join(appPath, 'Contents', 'MacOS', 'Microsoft Edge');
        else bin = path.join(appPath, 'Contents', 'MacOS', 'Google Chrome');
        candidates.push(bin);
      });
    }
  } catch (_) { /* ignore */ }

  return Array.from(new Set(candidates.filter(Boolean)));
}

async function resolveChromeExecutable(puppeteerLib) {
  const envPath = process.env.PUPPETEER_EXECUTABLE_PATH || process.env.PP_CHROME_PATH || process.env.CHROME_PATH || process.env.GOOGLE_CHROME_BIN;
  if (envPath && await pathExists(envPath)) {
    return { path: envPath, source: 'env', candidates: [envPath] };
  }

  if (puppeteerLib && typeof puppeteerLib.executablePath === 'function') {
    const bundled = puppeteerLib.executablePath();
    if (await pathExists(bundled)) {
      return { path: bundled, source: 'bundled', candidates: [bundled] };
    }
  }

  const candidates = collectChromeCandidates();
  for (const cand of candidates) {
    if (await pathExists(cand)) {
      return { path: cand, source: 'detected', candidates };
    }
  }

  // Attempt one-time auto-install via CLI or programmatically if allowed
  if (process.env.PP_AUTO_INSTALL_CHROME !== '0') {
    try {
      const cacheDir = process.env.PUPPETEER_CACHE_DIR || path.join(process.cwd(), 'node_runtime');
      ensureDirSync(cacheDir);
      logLine('Attempting Chrome auto-install', { cacheDir });
      const env = Object.assign({}, process.env, {
        PUPPETEER_CACHE_DIR: cacheDir,
        PUPPETEER_PRODUCT: 'chrome',
      });
      let installed = false;
      // 1) Try npx puppeteer browsers install chrome
      try {
        const hasNpx = execSync('/bin/bash -lc "command -v npx || true"', { encoding: 'utf8' }).trim();
        if (hasNpx) {
          execSync('/bin/bash -lc "npx --yes puppeteer browsers install chrome"', { stdio: ['ignore', 'pipe', 'pipe'], env, encoding: 'utf8' });
          installed = true;
        }
      } catch (e1) {
        logLine('Chrome auto-install via npx failed', { error: String(e1) });
      }
      // 2) Try npm exec (some hosts have npm but no npx)
      if (!installed) {
        try {
          const hasNpm = execSync('/bin/bash -lc "command -v npm || true"', { encoding: 'utf8' }).trim();
          if (hasNpm) {
            execSync('/bin/bash -lc "npm exec puppeteer browsers install chrome"', { stdio: ['ignore', 'pipe', 'pipe'], env, encoding: 'utf8' });
            installed = true;
          }
        } catch (e2) {
          logLine('Chrome auto-install via npm exec failed', { error: String(e2) });
        }
      }
      // 3) Programmatic install via @puppeteer/browsers
      if (!installed) {
        try {
          const mod = await import('@puppeteer/browsers');
          const Browser = mod.Browser || { CHROME: 'chrome' };
          const buildId = 'stable';
          const res = await mod.install({ browser: Browser.CHROME, cacheDir, buildId });
          logLine('Chrome installed programmatically', { path: res.executablePath || res.path || '' });
          installed = true;
        } catch (e3) {
          logLine('Chrome programmatic install failed', { error: String(e3) });
        }
      }

      if (installed) {
        const after = collectChromeCandidates();
        for (const cand of after) {
          if (await pathExists(cand)) {
            return { path: cand, source: 'auto-installed', candidates: after };
          }
        }
      }
    } catch (e) {
      logLine('Chrome auto-install failed', { error: String(e) });
    }
  }

  return { path: null, source: 'missing', candidates };
}

async function generateTextWithChat(prompt, openaiApiKey) {
  const started = Date.now();
  const payload = {
    model: 'gpt-3.5-turbo',
    messages: [{ role: 'user', content: prompt }],
    temperature: 0.8,
  };
  logLine('OpenAI request start', { url: 'https://api.openai.com/v1/chat/completions', payload });
  const response = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${openaiApiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  const ms = Date.now() - started;
  if (!response.ok) {
    const text = await response.text();
    logLine('OpenAI request failed', { status: response.status, statusText: response.statusText, durationMs: ms, body: text.slice(0, 4000) });
    throw new Error(`OpenAI request failed: ${response.status} ${response.statusText} -> ${text}`);
  }

  const data = await response.json();
  const content = data.choices?.[0]?.message?.content?.trim() || '';
  logLine('OpenAI request success', { durationMs: ms, contentPreview: content.slice(0, 200), length: content.length });
  return content;
}

// New: Generate hero image with DALL·E 3 and upload to telegra.ph
async function generateImageWithDalle(prompt, openaiApiKey, opts = {}) {
  const size = opts.size || '1024x1024';
  const quality = opts.quality || 'hd';
  const started = Date.now();
  logLine('OpenAI image start', { model: 'dall-e-3', size, quality, promptPreview: String(prompt || '').slice(0, 200) });
  const resp = await fetch('https://api.openai.com/v1/images/generations', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${openaiApiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model: 'dall-e-3',
      prompt: String(prompt || '').slice(0, 4000),
      n: 1,
      size,
      response_format: 'b64_json',
      quality,
    }),
  });
  const ms = Date.now() - started;
  if (!resp.ok) {
    const txt = await resp.text().catch(()=> '');
    logLine('OpenAI image failed', { status: resp.status, statusText: resp.statusText, durationMs: ms, body: txt.slice(0, 1000) });
    throw new Error(`OpenAI image failed: ${resp.status} ${resp.statusText}`);
  }
  const data = await resp.json();
  const b64 = data && data.data && data.data[0] && (data.data[0].b64_json || '');
  if (!b64) {
    logLine('OpenAI image empty');
    throw new Error('Empty image from OpenAI');
  }
  const buf = Buffer.from(b64, 'base64');
  logLine('OpenAI image ok', { durationMs: ms, bytes: buf.length });
  return { buffer: buf, mime: 'image/png', filename: `cover-${Date.now()}.png` };
}

async function uploadImageToTelegraph(fileBuffer, filename = 'image.png', mime = 'image/png') {
  if (typeof FormData === 'undefined' || typeof Blob === 'undefined') {
    throw new Error('FormData/Blob not available (require Node.js 18+)');
  }
  const form = new FormData();
  const blob = new Blob([fileBuffer], { type: mime });
  form.append('file', blob, filename);
  const started = Date.now();
  const resp = await fetch('https://telegra.ph/upload', {
    method: 'POST',
    // Let fetch set content-type boundary automatically
    headers: {
      'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
      'Accept': 'application/json, text/plain, */*',
      'Origin': 'https://telegra.ph',
      'Referer': 'https://telegra.ph/',
    },
    body: form,
  });
  const ms = Date.now() - started;
  if (!resp.ok) {
    const txt = await resp.text().catch(()=> '');
    logLine('Telegraph upload HTTP error', { status: resp.status, statusText: resp.statusText, durationMs: ms, body: txt.slice(0, 400) });
    throw new Error(`Telegraph upload failed: ${resp.status} ${resp.statusText}`);
  }
  const json = await resp.json().catch(()=>null);
  if (!Array.isArray(json) || !json[0] || !json[0].src) {
    logLine('Telegraph upload bad JSON', { json });
    throw new Error('Telegraph upload returned unexpected response');
  }
  const src = String(json[0].src);
  const url = src.startsWith('http') ? src : `https://telegra.ph${src}`;
  logLine('Telegraph upload ok', { url });
  return url;
}

// New: fallback upload inside Telegraph origin to bypass server-side 400s
async function uploadImageToTelegraphViaBrowser(page, fileBase64, filename = 'image.png', mime = 'image/png') {
  if (!page) throw new Error('No page for browser-side upload');
  const res = await page.evaluate(async ({ b64, name, type }) => {
    function b64ToUint8Array(b64) {
      const binary = atob(b64);
      const len = binary.length;
      const bytes = new Uint8Array(len);
      for (let i = 0; i < len; i++) bytes[i] = binary.charCodeAt(i);
      return bytes;
    }
    const form = new FormData();
    const bytes = b64ToUint8Array(b64);
    const blob = new Blob([bytes], { type });
    form.append('file', blob, name);
    const r = await fetch('https://telegra.ph/upload', { method: 'POST', body: form });
    const text = await r.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}
    return { ok: r.ok, status: r.status, json, text };
  }, { b64: fileBase64, name: filename, type: mime });

  if (!res || !res.ok || !Array.isArray(res.json) || !res.json[0] || !res.json[0].src) {
    throw new Error(`Browser upload failed: ${res && res.status}`);
  }
  const src = String(res.json[0].src);
  return src.startsWith('http') ? src : `https://telegra.ph${src}`;
}

function escapeHtmlAttr(str) {
  return String(str || '').replace(/["'&<>]/g, (c)=> ({'"':'&quot;', "'":'&#39;', '&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
}

function escapeRegExp(s) { return String(s || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

function headingTag() { return 'h3'; }

function headingLabelForLang(lang) {
  const L = String(lang || '').toLowerCase();
  if (L.startsWith('ru')) return 'Основные разделы';
  if (L.startsWith('uk') || L.startsWith('ua')) return 'Основні розділи';
  if (L.startsWith('de')) return 'Schlüsselthemen';
  if (L.startsWith('es')) return 'Puntos clave';
  if (L.startsWith('fr')) return 'Points clés';
  return 'Key Points';
}

// New: basic HTML helpers for SEO parsing
function extractAttr(tagHtml, attr) {
  const m = String(tagHtml || '').match(new RegExp(attr + '\\s*=\\s*([\"\'])(.*?)\\1', 'i'));
  return m ? m[2] : '';
}
function stripTags(html) { return String(html || '').replace(/<[^>]+>/g, '').trim(); }

// New: fetch page and extract SEO title/description from meta/OG/JSON-LD/H1
async function fetchSeoData(targetUrl) {
  try {
    const resp = await fetch(targetUrl, {
      method: 'GET',
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; PromoPilotBot/1.0; +https://example.com/bot) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9,ru;q=0.8'
      },
      redirect: 'follow',
    });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const html = await resp.text();

    const out = { title: '', description: '' };

    // <title>
    const tMatch = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
    const htmlTitle = tMatch ? stripTags(tMatch[1]) : '';

    // <meta name="description" content="...">
    const metaDescMatch = html.match(/<meta[^>]+name=[\"\']description[\"\'][^>]*>/i);
    const metaDesc = metaDescMatch ? extractAttr(metaDescMatch[0], 'content') : '';

    // OpenGraph
    const ogTitleMatch = html.match(/<meta[^>]+property=[\"\']og:title[\"\'][^>]*>/i);
    const ogTitle = ogTitleMatch ? extractAttr(ogTitleMatch[0], 'content') : '';
    const ogDescMatch = html.match(/<meta[^>]+property=[\"\']og:description[\"\'][^>]*>/i);
    const ogDesc = ogDescMatch ? extractAttr(ogDescMatch[0], 'content') : '';

    // Twitter
    const twTitleMatch = html.match(/<meta[^>]+name=[\"\']twitter:title[\"\'][^>]*>/i);
    const twTitle = twTitleMatch ? extractAttr(twTitleMatch[0], 'content') : '';
    const twDescMatch = html.match(/<meta[^>]+name=[\"\']twitter:description[\"\'][^>]*>/i);
    const twDesc = twDescMatch ? extractAttr(twDescMatch[0], 'content') : '';

    // H1
    const h1Match = html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);
    const h1 = h1Match ? stripTags(h1Match[1]) : '';

    // JSON-LD
    let ldTitle = '', ldDesc = '';
    const ldBlocks = html.match(/<script[^>]+type=[\"\']application\/ld\+json[\"\'][^>]*>[\s\S]*?<\/script>/gi) || [];
    for (const block of ldBlocks) {
      const jsonText = (block.match(/>([\s\S]*?)<\/script>/i) || [,''])[1];
      try {
        const data = JSON.parse(jsonText);
        const arr = Array.isArray(data) ? data : [data];
        for (const item of arr) {
          const candTitle = item.headline || item.name || (item.article && item.article.headline) || '';
          const candDesc = item.description || (item.article && item.article.description) || '';
          if (candTitle && !ldTitle) ldTitle = String(candTitle);
          if (candDesc && !ldDesc) ldDesc = String(candDesc);
        }
      } catch (_) { /* ignore bad JSON */ }
      if (ldTitle && ldDesc) break;
    }

    // Priority selection
    out.title = (ldTitle || ogTitle || twTitle || h1 || htmlTitle || '').trim();
    out.description = (ldDesc || ogDesc || twDesc || metaDesc || '').trim();

    // Sanitize
    out.title = out.title.replace(/[\n\r\t]+/g, ' ').trim();
    out.description = out.description.replace(/[\n\r\t]+/g, ' ').trim();

    logLine('SEO extracted', { title: out.title.slice(0, 160), description: out.description.slice(0, 200) });
    return out;
  } catch (e) {
    logLine('SEO extract failed', { error: String(e) });
    return { title: '', description: '' };
  }
}

// Convert simple Markdown-ish content to clean HTML and enforce structure
function normalizeContent(inputHtmlOrMd, lang, pageUrl, anchorText) {
  let s = String(inputHtmlOrMd || '').trim();
  const H = headingTag();

  // Normalize Windows line breaks
  s = s.replace(/\r\n/g, '\n');

  // Convert Markdown headings (#, ##, ###) to <h3>
  s = s.replace(/^[\t ]*#{1,6}[\t ]+(.+)$/gmi, `<${H}>$1</${H}>`);

  // Convert lines that are bold-only or end with colon to headings
  s = s.replace(/^\s*\*\*(.+?)\*\*\s*$/gmi, `<${H}>$1</${H}>`);
  s = s.replace(/^\s*__([^_].+?)__\s*$/gmi, `<${H}>$1</${H}>`);
  s = s.replace(/^\s*([^\n<]{3,120}):\s*$/gmi, `<${H}>$1</${H}>`);

  // Convert simple bullet-like lines into <ul><li>
  const lines = s.split(/\n/);
  const out = [];
  let inList = false;
  const openList = () => { if (!inList) { out.push('<ul>'); inList = true; } };
  const closeList = () => { if (inList) { out.push('</ul>'); inList = false; } };
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const m = line.match(/^\s*(?:[-*•·–—])\s+(.+)/); // -, *, •, ·, –, —
    if (m) {
      openList();
      out.push('<li>' + m[1].trim() + '</li>');
    } else if (/^\s*\d+[.)]\s+/.test(line)) { // ordered-like, still map to ul for simplicity
      openList();
      out.push('<li>' + line.replace(/^\s*\d+[.)]\s+/, '').trim() + '</li>');
    } else {
      closeList();
      out.push(line);
    }
  }
  closeList();
  s = out.join('\n');

  // If there are no block tags, wrap paragraphs by blank lines
  if (!/<\s*(p|h1|h2|h3|ul|ol|blockquote|figure|img)\b/i.test(s)) {
    const paras = s.split(/\n{2,}/).map(p => p.trim()).filter(Boolean).map(p => '<p>' + p + '</p>');
    s = paras.join('\n');
  } else {
    // Ensure standalone non-tag lines become paragraphs
    s = s.replace(/^(?!<)(.+)$/gmi, '<p>$1</p>');
    // Avoid wrapping lines already inside tags
    s = s.replace(new RegExp(`<p>\\s*<(${H}|ul|ol|blockquote|figure|img)\\b`, 'gi'), '<$1');
  }

  // Convert short, title-like paragraphs (including strong/em) to headings
  s = s.replace(new RegExp(`<p>\\s*(?:<strong>|<em>)?([^<]{5,140})(?:<\\/strong>|<\\/em>)?\\s*<\\/p>`, 'g'), `<${H}>$1</${H}>`);

  // Ensure at least one subheading exists
  const hRe = new RegExp(`<${H}[\\s>]`, 'i');
  if (!hRe.test(s)) {
    const label = headingLabelForLang(lang);
    const firstP = s.match(/<p[\s>][\s\S]*?<\/p>/i);
    if (firstP) s = s.replace(firstP[0], firstP[0] + `\n<${H}>${label}</${H}>`);
    else s = `<${H}>${label}</${H}>\n` + s;
  }

  // Allow only a safe subset of tags
  s = s.replace(/<(?!\/?(p|h1|h2|h3|ul|li|a|strong|em|blockquote|figure|img)\b)[^>]*>/gi, '');

  // Collapse empty paragraphs and stray bullets
  s = s.replace(/<p>(?:\s|&nbsp;|<br\s*\/>)*<\/p>/gi, '');
  s = s.replace(/<p>\s*[•·–—-]\s*<\/p>/gi, '');

  // Merge consecutive paragraphs that are just bullets into proper list (safety pass)
  s = s.replace(/<p>\s*[•·–—-]\s*([^<]+)<\/p>(?:\s*<p>\s*[•·–—-]\s*([^<]+)<\/p>)+/gi, (full) => {
    const items = Array.from(full.matchAll(/<p>\s*[•·–—-]\s*([^<]+)<\/p>/gi)).map(m => `<li>${m[1].trim()}</li>`);
    return `<ul>${items.join('')}</ul>`;
  });

  // Enforce a single allowed link to the target URL: strip other anchors, then inject inline if missing
  s = s.replace(/<a\s+([^>]*?)>([\s\S]*?)<\/a>/gi, (full, attrs, text) => {
    const m = String(attrs || '').match(/href=[\"\']([^\"\']+)[\"\']/i);
    const href = m && m[1] ? m[1] : '';
    if (!href) return text;
    if (href.replace(/\/$/, '') === String(pageUrl).replace(/\/$/, '')) return full; // keep target link
    return text; // drop other links
  });

  // Ensure exactly one target link exists (insert inline into a long paragraph)
  s = injectTargetLinkInline(s, pageUrl, anchorText);

  // Trim excessive newlines created by replacements
  s = s.replace(/\n{3,}/g, '\n\n');

  return s.trim();
}

function isGenericTitle(t, lang) {
  const s = String(t || '').trim().replace(/["'«»“”„]+/g, '').replace(/[.:!?]+$/g, '').toLowerCase();
  if (s.length < 4) return true;
  const common = [
    'introduction','intro','overview','summary','conclusion','contents','table of contents','outline','guide','basics','getting started',
    'введение','вступление','обзор','итоги','заключение','содержание','оглавление','резюме','описание','интро','основы','начало','старт','вступ',
    'introduction to','about','about us'
  ];
  if (common.includes(s)) return true;
  // overly short and generic
  if (s.length < 8) return true;
  return false;
}

function titleFromUrl(url) {
  try {
    const u = new URL(String(url));
    let seg = u.pathname.split('/').filter(Boolean).pop() || u.hostname;
    seg = seg.replace(/[-_]+/g, ' ').trim();
    if (!seg) seg = u.hostname.replace(/^www\./, '');
    // Capitalize first letter of each word
    seg = seg.split(/\s+/).map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
    return seg || 'PromoPilot Article';
  } catch (_) {
    return 'PromoPilot Article';
  }
}

// New: enforce non-generic title fallback using SEO data/url
function makeNonGenericTitle(primary, seo, url, lang) {
  let t = String(primary || '').trim();
  if (!t || isGenericTitle(t, lang)) {
    const alt = String(seo && seo.title || '').trim();
    if (alt && !isGenericTitle(alt, lang)) t = alt;
  }
  if (!t || isGenericTitle(t, lang)) {
    t = titleFromUrl(url);
  }
  // Final cleanup and clip
  t = t.replace(/["'«»“”„]+/g, '').replace(/[.]+$/g, '').trim();
  if (t.length > 140) t = t.slice(0, 140).replace(/\s+\S*$/, '');
  return t || 'PromoPilot Article';
}

// New: Inject target link organically into a paragraph (inline), not as a standalone paragraph
function injectTargetLinkInline(html, pageUrl, anchorText) {
  const H = headingTag();
  let s = String(html || '');
  const hrefRe = new RegExp('<a\\s+[^>]*href=[\\"\']' + escapeRegExp(pageUrl.replace(/\/$/, '')) + '[\\"\']', 'ig');
  const hasLink = (s.match(hrefRe) || []).length > 0;
  if (hasLink) return s; // already present (kept by earlier sanitation)

  const paras = s.match(/<p[\s>][\s\S]*?<\/p>/gi) || [];
  if (paras.length) {
    for (let i = 0; i < paras.length; i++) {
      const p = paras[i];
      const plain = stripTags(p);
      if (plain.length < 120) continue; // prefer a longer paragraph
      const anchor = escapeHtmlAttr(anchorText || pageUrl);
      // Try to wrap existing anchorText occurrence
      const idx = p.toLowerCase().indexOf(String(anchorText || '').toLowerCase());
      let newP;
      if (anchorText && idx !== -1) {
        // Replace first occurrence only
        newP = p.replace(new RegExp('(' + escapeRegExp(anchorText) + ')','i'), '<a href="' + escapeHtmlAttr(pageUrl) + '">$1</a>');
      } else {
        // Append inline link near the end, before closing tag
        newP = p.replace(/<\/p>\s*$/i, ' <a href="' + escapeHtmlAttr(pageUrl) + '">' + anchor + '</a></p>');
      }
      s = s.replace(p, newP);
      return s;
    }
  }
  // Fallback: insert after first heading
  const linkHtml = '<p><a href="' + escapeHtmlAttr(pageUrl) + '">' + escapeHtmlAttr(anchorText || pageUrl) + '</a></p>';
  const firstHeading = s.match(new RegExp(`<${H}[^>]*>[\\s\\S]*?<\/${H}>`, 'i'));
  if (firstHeading) return s.replace(firstHeading[0], firstHeading[0] + '\n' + linkHtml);
  return linkHtml + '\n' + s;
}

// New: Insert hero image link between paragraphs (after the second paragraph when possible)
function insertHeroLinkBetweenParagraphs(html, heroUrl, label) {
  if (!heroUrl) return html;
  let s = String(html || '');
  const linkHtml = '<p><a href="' + escapeHtmlAttr(heroUrl) + '" target="_blank" rel="noopener nofollow">' + escapeHtmlAttr(label || 'View illustration') + '</a></p>';
  const paras = s.match(/<p[\s>][\s\S]*?<\/p>/gi) || [];
  if (paras.length >= 2) {
    const target = paras[1]; // after second paragraph
    return s.replace(target, target + '\n' + linkHtml);
  }
  return s + '\n' + linkHtml;
}

function normalizeAuthorName(name, lang) {
  let s = String(name || '').trim();
  // keep only the first line
  s = s.split(/\r?\n/)[0];
  // strip surrounding quotes and common quote chars
  s = s.replace(/^["'«»“”„]+|["'«»“”„]+$/g, '');
  // collapse spaces
  s = s.replace(/\s+/g, ' ').trim();
  // remove trailing punctuation
  s = s.replace(/[.,;:!?]+$/g, '');
  // limit to max 3 words (expect 1–2)
  const words = s.split(' ').filter(Boolean).slice(0, 3);
  s = words.join(' ');
  // fallbacks
  if (!s) s = 'PromoPilot';
  return s;
}

function imageLinkLabel(lang, title) {
  const L = String(lang || '').toLowerCase();
  if (L.startsWith('ru')) return 'Смотреть иллюстрацию';
  if (L.startsWith('uk') || L.startsWith('ua')) return 'Переглянути ілюстрацію';
  if (L.startsWith('de')) return 'Abbildung ansehen';
  if (L.startsWith('es')) return 'Ver ilustración';
  if (L.startsWith('fr')) return "Voir l'illustration";
  return 'View illustration';
}

async function publishToTelegraph(job) {
  logLine('Job received', job);
  // Ensure puppeteer is available (and report cleanly if not)
  const puppeteerLib = await loadPuppeteerOrExit();
  const {
    url: pageUrl,
    anchor = '',
    language = 'ru',
    openaiApiKey,
    projectName = '',
    wish = '',
  } = job;

  if (!pageUrl) {
    logLine('Validation error: missing target url');
    throw new Error('Missing target url');
  }
  if (!openaiApiKey) {
    logLine('Validation error: missing OpenAI API key');
    throw new Error('Missing OpenAI API key');
  }

  const anchorText = anchor || pageUrl;

  // Language-aware labels for prompts
  const langLabel = languageLabel(language);
  const titleLangLabel = langLabel;
  const authorLangLabel = langLabel;
  const contentLangLabel = langLabel;

  // New: fetch SEO data from the target URL to anchor prompts to the correct topic
  const seo = await fetchSeoData(pageUrl);

  const prompts = {
    title: `Using the page SEO data below, write a concise, catchy ${titleLangLabel} title that reflects the same topic. No quotes, no trailing dots. Avoid generic placeholders like: Introduction, Overview, Summary, Введение, Обзор, Итоги. Prefer a concrete, specific phrase.
SEO title: "${seo.title || ''}"
SEO description: "${seo.description || ''}"
URL: ${pageUrl}` + (wish ? ` | Context: ${wish}` : ''),
    author: `Propose a neutral author's name for an article in ${authorLangLabel}. Use the ${authorLangLabel} language and its alphabet. Avoid region-specific or celebrity names. One or two words only. Respond with the name only, without quotes or extra text.`,
    content: `Write an article in ${contentLangLabel} of at least 7000 characters based on the page: ${pageUrl}. Use the following page SEO data and stay strictly on-topic: title: "${seo.title || ''}", description: "${seo.description || ''}". Requirements:\n- Include naturally one link to <a href=\"${pageUrl}\">${anchorText}</a> inline in the first half (inside a paragraph).\n- Clear structure: short introduction, 3–5 sections with <h3> subheadings, a bulleted list where relevant, and a brief conclusion.\n- Clean HTML only: use <p>, <h3>, <ul>, <li>, <a>, <strong>, <em>, <blockquote>. No external images, scripts or inline styles.\n- If you output Markdown headings like ## Section, that's acceptable—they will be converted.\n- Do not include off-topic content.\n${wish ? `Additional context to reflect: ${wish}.` : ''}${projectName ? ` This article is part of the project ${projectName}.` : ''}`,
  };
  logLine('Prompts prepared', { titlePromptPreview: prompts.title, authorPromptPreview: prompts.author, contentPromptPreview: prompts.content.slice(0, 160) + '...' });

  const wait = Number.isFinite(job.waitBetweenCallsMs) ? Number(job.waitBetweenCallsMs) : 5000;

  logLine('Generating title...');
  const rawTitle = (await generateTextWithChat(prompts.title, openaiApiKey)) || 'Untitled';
  let title = makeNonGenericTitle(rawTitle, seo, pageUrl, language);
  await sleep(wait);

  logLine('Generating author...');
  let author = (await generateTextWithChat(prompts.author, openaiApiKey)) || 'PromoPilot';
  author = normalizeAuthorName(author, language);
  if (!author || author.length < 2) author = 'PromoPilot';
  await sleep(wait);

  logLine('Generating content...');
  let content = await generateTextWithChat(prompts.content, openaiApiKey);
  logLine('Content generated', { length: content.length });

  // Normalize/structure content, add headings and ensure link inline
  content = normalizeContent(content, language, pageUrl, anchorText);
  // Reduce excessive blank lines
  content = content.replace(/\n{3,}/g, '\n\n').trim();

  // Generate hero image (best-effort, prefer wide format) and save locally before uploading to Telegraph
  let heroUrl = '';
  let heroImg = null;
  let localHeroPath = '';
  try {
    const topicForImage = (seo.title || seo.description || title || anchorText || '').slice(0, 140) || anchorText;
    const imagePrompt = `High-quality ${titleLangLabel} illustration representing: "${topicForImage}". Style: modern editorial, clean composition, no text overlay, high contrast, works well as article hero. Topic source: ${pageUrl}.` + (wish ? ` Context: ${wish}.` : '');
    heroImg = await generateImageWithDalle(imagePrompt, openaiApiKey, { size: '1792x1024', quality: 'hd' });
    // Save locally
    try {
      const localDir = path.join(process.cwd(), 'uploads', 'generated');
      ensureDirSync(localDir);
      localHeroPath = path.join(localDir, heroImg.filename.replace(/\.png$/, '') + '-wide.png');
      await fsp.writeFile(localHeroPath, heroImg.buffer);
      logLine('Hero image saved locally', { localHeroPath });
    } catch (eSave) {
      logLine('Hero local save failed', { error: String(eSave) });
    }
    await sleep(1200);
    try {
      heroUrl = await uploadImageToTelegraph(heroImg.buffer, heroImg.filename.replace(/\.png$/, '') + '-wide.png', heroImg.mime);
      logLine('Hero image uploaded', { heroUrl });
    } catch (e1) {
      logLine('Telegraph upload HTTP error (server-side)', { error: String(e1) });
    }
  } catch (e) {
    logLine('Hero image generation skipped', { error: String(e) });
  }

  // Prepare clean and robust title
  let cleanTitle = title;

  logLine('Launching browser');

  const chromeInfo = await resolveChromeExecutable(puppeteerLib);
  if (!chromeInfo.path) {
    logLine('Chrome resolve failed', { candidates: chromeInfo.candidates });
    throw new Error(`Chrome executable not found. Checked: ${chromeInfo.candidates && chromeInfo.candidates.length ? chromeInfo.candidates.join(', ') : 'none'}`);
  }
  logLine('Chrome selected', { path: chromeInfo.path, source: chromeInfo.source });

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...process.env.PUPPETEER_ARGS.split(/\s+/).filter(Boolean));
  }
  if (Array.isArray(job.launchArgs)) {
    launchArgs.push(...job.launchArgs.filter(Boolean));
  }
  const uniqueArgs = Array.from(new Set(launchArgs));

  const browser = await puppeteerLib.launch({
    headless: true,
    executablePath: chromeInfo.path,
    args: uniqueArgs,
  });
  let page;
  try {
    page = await browser.newPage();
    logLine('Navigating to Telegraph');
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 120000 });

    // If server upload failed but we have the image, try upload in-browser now
    if (!heroUrl && heroImg && heroImg.buffer) {
      try {
        const b64 = heroImg.buffer.toString('base64');
        heroUrl = await uploadImageToTelegraphViaBrowser(page, b64, heroImg.filename.replace(/\.png$/, '') + '-wide.png', heroImg.mime);
        logLine('Hero image uploaded via browser', { heroUrl });
      } catch (e2) {
        logLine('Hero image skipped (browser upload failed)', { error: String(e2) });
      }
    }

    logLine('Filling title');
    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 60000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(cleanTitle, { delay: 30 });

    logLine('Filling author');
    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 60000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author, { delay: 30 });

    logLine('Injecting content into editor');
    // Insert hero image as a hyperlink between paragraphs (after the second paragraph when possible)
    if (heroUrl) {
      const label = imageLinkLabel(language, cleanTitle);
      content = insertHeroLinkBetweenParagraphs(content, heroUrl, label);
    }

    const finalHtml = content;
    await page.evaluate((articleHtml) => {
      const root = document.querySelector('.tl_article .tl_article_content .ql-editor') || document.querySelector('div.ql-editor') || document.querySelector('p[data-placeholder="Your story..."]').parentElement;
      if (!root) throw new Error('Telegraph editor not ready');
      root.innerHTML = articleHtml;
    }, finalHtml);

    logLine('Publishing...');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 120000 }),
      page.click('button.publish_button'),
    ]);

    const publishedUrl = page.url();
    logLine('Published', { publishedUrl });
    if (!publishedUrl || !publishedUrl.includes('https://telegra.ph/')) {
      throw new Error(`Unexpected Telegraph URL: ${publishedUrl}`);
    }

    const result = {
      ok: true,
      network: 'telegraph',
      publishedUrl,
      title: cleanTitle,
      author,
      heroUrl,
      logFile: LOG_FILE,
    };
    logLine('Success result', result);
    return result;
  } finally {
    if (page) {
      try { await page.close(); logLine('Page closed'); } catch (_) { logLine('Page close error (ignored)'); }
    }
    try { await browser.close(); logLine('Browser closed'); } catch (_) { logLine('Browser close error (ignored)'); }
    // Cleanup local hero image
    if (localHeroPath) {
      try { await fsp.unlink(localHeroPath); logLine('Local hero image deleted', { localHeroPath }); } catch (eDel) { logLine('Local hero delete failed', { error: String(eDel) }); }
    }
  }
}

function readJob() {
  const raw = process.env.PP_JOB;
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    logLine('PP_JOB parsed');
    return parsed;
  } catch (error) {
    logLine('PP_JOB parse error', { error: String(error) });
    return {};
  }
}

(async () => {
  const job = readJob();
  try {
    const result = await publishToTelegraph(job);
    console.log(JSON.stringify(result));
  } catch (error) {
    console.error(error);
    const payload = {
      ok: false,
      error: error.message || 'UNEXPECTED_ERROR',
      network: 'telegraph',
      logFile: LOG_FILE,
    };
    logLine('Run failed', { error: String(error), stack: error && error.stack });
    console.log(JSON.stringify(payload));
    process.exit(1);
  }
})();
