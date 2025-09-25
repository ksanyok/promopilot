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

let puppeteer;
function loadPuppeteerOrExit() {
  try {
    if (!puppeteer) puppeteer = require('puppeteer');
    return puppeteer;
  } catch (error) {
    logLine('Puppeteer load failed', { error: String(error) });
    console.log(JSON.stringify({ ok: false, error: 'PUPPETEER_LOAD_FAILED', details: String(error) }));
    process.exit(1);
  }
}

let fetch;
try {
  // node-fetch v2 (CommonJS)
  fetch = require('node-fetch');
} catch (error) {
  if (typeof global.fetch === 'function') {
    fetch = global.fetch;
  } else {
    logLine('node-fetch load failed', { error: String(error) });
    console.log(JSON.stringify({ ok: false, error: 'FETCH_LOAD_FAILED', details: String(error) }));
    process.exit(1);
  }
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
  const envVars = ['PUPPETEER_EXECUTABLE_PATH', 'PP_CHROME_PATH', 'CHROME_PATH', 'GOOGLE_CHROME_BIN'];
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
    `${process.env.HOME || ''}/.local/bin/google-chrome`,
    `${process.env.HOME || ''}/bin/google-chrome`,
  ];

  const patterns = [
    '/opt/alt/nodejs*/usr/bin/google-chrome',
    '/opt/alt/nodejs*/usr/bin/chromium',
    '/opt/alt/nodejs*/bin/google-chrome',
    '/opt/alt/chrome*/bin/google-chrome',
    '/opt/chrome*/bin/google-chrome',
    '/opt/google/chrome*/chrome',
    `${process.env.HOME || ''}/.nix-profile/bin/google-chrome`,
    `${process.env.HOME || ''}/.cache/puppeteer/chrome/linux-*/chrome-linux64/chrome`,
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

async function publishToTelegraph(job) {
  logLine('Job received', job);
  // Ensure puppeteer is available (and report cleanly if not)
  const puppeteerLib = loadPuppeteerOrExit();
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

  const prompts = {
    title: `What would be a good title for an article about this link without using quotes? ${pageUrl}`,
    author: `What is a suitable author's name for an article in ${language}? Avoid using region-specific names.`,
    content: `Please write a text in ${language} with at least 3000 characters based on the following link: ${pageUrl}. The article must include the anchor text "${anchorText}" as part of a single active link in the format <a href="${pageUrl}">${anchorText}</a>. This link should be naturally integrated into the content, ideally in the first half of the article. The content should be informative, cover the topic comprehensively, and include headings. Use <h2></h2> tags for subheadings. Please ensure the article contains only this one link and focuses on integrating the anchor text naturally within the content’s flow.${wish ? ` Additional context for the article: ${wish}` : ''}${projectName ? ` This article is part of the project ${projectName}.` : ''}`,
  };
  logLine('Prompts prepared', { titlePromptPreview: prompts.title, authorPromptPreview: prompts.author, contentPromptPreview: prompts.content.slice(0, 160) + '...' });

  const wait = Number.isFinite(job.waitBetweenCallsMs) ? Number(job.waitBetweenCallsMs) : 5000;

  logLine('Generating title...');
  const title = (await generateTextWithChat(prompts.title, openaiApiKey)) || 'Untitled';
  await sleep(wait);

  logLine('Generating author...');
  const author = (await generateTextWithChat(prompts.author, openaiApiKey)) || 'PromoPilot';
  await sleep(wait);

  logLine('Generating content...');
  const content = await generateTextWithChat(prompts.content, openaiApiKey);
  logLine('Content generated', { length: content.length });

  const cleanTitle = title.replace(/["']+/g, '').trim() || 'PromoPilot Article';
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
    headless: 'new',
    executablePath: chromeInfo.path,
    args: uniqueArgs,
  });
  let page;
  try {
    page = await browser.newPage();
    logLine('Navigating to Telegraph');
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 120000 });

    logLine('Filling title');
    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 60000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(cleanTitle, { delay: 30 });

    logLine('Filling author');
    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 60000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author, { delay: 30 });

    logLine('Injecting content into editor');
    await page.evaluate((articleHtml) => {
      const editable = document.querySelector('p[data-placeholder="Your story..."]');
      if (!editable) throw new Error('Telegraph editor not ready');
      editable.innerHTML = articleHtml;
    }, content);

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
      logFile: LOG_FILE,
    };
    logLine('Success result', result);
    return result;
  } finally {
    if (page) {
      try { await page.close(); logLine('Page closed'); } catch (_) { logLine('Page close error (ignored)'); }
    }
    try { await browser.close(); logLine('Browser closed'); } catch (_) { logLine('Browser close error (ignored)'); }
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
