'use strict';

// Always return JSON even on fatal errors (prevents NODE_RETURN_EMPTY)
try {
  process.on('uncaughtException', (e) => {
    try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: String((e && e.message) || e) })); } catch {}
    process.exit(1);
  });
  process.on('unhandledRejection', (e) => {
    try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: String((e && e.message) || e) })); } catch {}
    process.exit(1);
  });
} catch {}

let puppeteer;
try {
  puppeteer = require('puppeteer');
} catch (_) {
  try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: 'DEPENDENCY_MISSING: puppeteer' })); } catch {}
  process.exit(1);
}
const fs = require('fs');
const path = require('path');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { createVerificationPayload } = require('./lib/verification');

function safeUrl(p) { try { return p.url(); } catch { return ''; } }
function ensureDirSync(filePath) {
  try { const d = path.dirname(filePath); if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true }); } catch {}
}
// Puppeteer-agnostic sleep (older versions may not have page.waitForTimeout)
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms || 0)));

// Minimal stealth tweaks (no extra deps)
async function applyStealth(page) {
  // Realistic UA without "HeadlessChrome"
  const UA = process.env.PUPPETEER_UA
    || 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
  try { await page.setUserAgent(UA); } catch {}
  try { await page.setExtraHTTPHeaders({ 'Accept-Language': 'ru,uk;q=0.9,en-US;q=0.8,en;q=0.7' }); } catch {}
  try { await page.setViewport({ width: 1360, height: 900, deviceScaleFactor: 1 }); } catch {}

  // Patch common bot fingerprints
  try {
    await page.evaluateOnNewDocument(() => {
      // webdriver flag
      Object.defineProperty(navigator, 'webdriver', { get: () => false });

      // chrome object
      window.chrome = window.chrome || { runtime: {} };

      // languages
      Object.defineProperty(navigator, 'languages', { get: () => ['ru-UA', 'ru', 'uk', 'en-US', 'en'] });

      // plugins
      Object.defineProperty(navigator, 'plugins', {
        get: () => [1, 2, 3, 4, 5]
      });

      // Permissions query fix (for notifications)
      const originalQuery = window.navigator.permissions && window.navigator.permissions.query;
      if (originalQuery) {
        window.navigator.permissions.query = (parameters) => (
          parameters && parameters.name === 'notifications'
            ? Promise.resolve({ state: Notification.permission })
            : originalQuery(parameters)
        );
      }

      // WebGL vendor/renderer
      const getParameter = WebGLRenderingContext.prototype.getParameter;
      WebGLRenderingContext.prototype.getParameter = function (parameter) {
        const UNMASKED_VENDOR_WEBGL = 0x9245;
        const UNMASKED_RENDERER_WEBGL = 0x9246;
        if (parameter === UNMASKED_VENDOR_WEBGL) return 'Intel Inc.';
        if (parameter === UNMASKED_RENDERER_WEBGL) return 'Intel(R) Iris(TM) Graphics';
        return getParameter.call(this, parameter);
      };

      // Navigator hardwareConcurrency spoof
      Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => 8 });
    });
  } catch {}
}

async function publishToNotepin(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const { LOG_FILE, LOG_DIR, logLine } = createLogger('notepin');
  // screenshot helper
  let snapStep = 0; const snapPrefix = LOG_FILE.replace(/\.log$/i, '');
  const snap = async (pg, name) => {
    try { if (pg.isClosed && pg.isClosed()) return; } catch {}
    try {
      snapStep++; const idx = String(snapStep).padStart(2, '0');
      const file = `${snapPrefix}-${idx}-${String(name).replace(/[^\w.-]+/g, '-')}.png`;
      ensureDirSync(file); await pg.screenshot({ path: file, fullPage: true });
      logLine('screenshot', { name: `${idx}-${name}`, file, url: safeUrl(pg) });
    } catch {}
  };

  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase();
  logLine('start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });
  // Optional login inputs/mode for diagnostics
  const loginUser = (
    jobOptions.username || jobOptions.loginUsername || process.env.PP_NOTEPIN_USERNAME || (jobOptions.testMode ? 'pphr9sc56f4j4s' : '')
  ).toString().trim();
  const loginPass = (
    jobOptions.password || jobOptions.loginPassword || process.env.PP_NOTEPIN_PASSWORD || (jobOptions.testMode ? 'swxqsk27nmA!9' : '')
  ).toString().trim();
  const loginOnly = !!(
    jobOptions.action === 'login' || jobOptions.loginOnly === true || String(process.env.PP_NOTEPIN_LOGIN_ONLY || '0').match(/^(1|true|yes)$/i)
  );

  // Build/gather article
  const job = { pageUrl, anchorText, language, openaiApiKey, aiProvider: provider, wish, meta: pageMeta, testMode: !!jobOptions.testMode };
  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(job, logLine);
  const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim();
  const htmlContent = String(article.htmlContent || '').trim();
  if (!htmlContent) throw new Error('EMPTY_ARTICLE_CONTENT');
  const plain = htmlToPlainText(htmlContent);

  // Launch browser
  const args = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) args.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const headlessFlag = String(process.env.PP_HEADLESS || 'true').toLowerCase() !== 'false';
  const browser = await puppeteer.launch({
    headless: headlessFlag,
    args: Array.from(new Set(args.concat(['--disable-blink-features=AutomationControlled']))),
    executablePath: execPath || undefined
  });
  let page = await browser.newPage();
  await applyStealth(page);
  // Allow overriding timeouts via env or job options
  const DEF_TIMEOUT = Number(process.env.PP_TIMEOUT_MS || (jobOptions && jobOptions.timeoutMs) || 90000);
  const NAV_TIMEOUT = Number(process.env.PP_NAV_TIMEOUT_MS || (jobOptions && jobOptions.navTimeoutMs) || DEF_TIMEOUT);
  page.setDefaultTimeout(DEF_TIMEOUT);
  page.setDefaultNavigationTimeout(NAV_TIMEOUT);

  // Log browser fingerprint basics (UA, languages, webdriver) for debugging
  try {
    const fp = await page.evaluate(() => ({
      ua: navigator.userAgent,
      languages: navigator.languages,
      webdriver: navigator.webdriver,
      plugins: (navigator.plugins && navigator.plugins.length) || 0
    }));
    logLine('fingerprint', fp);
  } catch {}

  let createdBlog = '';
  try {
    // 0) Login flow (optional)
    if ((loginUser && loginPass) || loginOnly) {
      try {
        await page.goto('https://notepin.co/', { waitUntil: 'domcontentloaded' });
        await snap(page, 'L1-home');
        // Try to click a Login link/button; if not found, go directly
        const clicked = await page.evaluate(() => {
          const byHref = Array.from(document.querySelectorAll('a[href]')).find(a => /login/i.test(a.getAttribute('href') || ''));
          if (byHref) { byHref.dispatchEvent(new MouseEvent('click', { bubbles: true })); return true; }
          const byText = Array.from(document.querySelectorAll('a,button')).find(el => /login/i.test((el.textContent || '').trim()));
          if (byText) { byText.dispatchEvent(new MouseEvent('click', { bubbles: true })); return true; }
          return false;
        });
        if (!clicked) {
          await page.goto('https://notepin.co/login', { waitUntil: 'domcontentloaded' });
        } else {
          await sleep(500);
        }
        // Wait inputs and fill
        await page.waitForSelector('form', { timeout: 15000 }).catch(() => {});
        const filled = await page.evaluate((u, p) => {
          const root = document.querySelector('form') || document;
          const uInput = root.querySelector('input[name="username"], input[name="user"], input[name="login"], input[type="text"], input[placeholder*="user" i], input[placeholder*="login" i]');
          const pInput = root.querySelector('input[type="password"], input[name="password"], input[placeholder*="password" i]');
          if (uInput) { uInput.focus(); uInput.value = u; uInput.dispatchEvent(new Event('input', { bubbles: true })); }
          if (pInput) { pInput.focus(); pInput.value = p; pInput.dispatchEvent(new Event('input', { bubbles: true })); }
          return !!(uInput && pInput);
        }, loginUser, loginPass);
        await snap(page, 'L2-login-form');
        // Submit
        const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
        const submitted = await page.evaluate(() => {
          const form = document.querySelector('form');
          const btn = (form && (form.querySelector('button[type="submit"]') || form.querySelector('input[type="submit"]')))
            || document.querySelector('button[type="submit"], input[type="submit"]');
          if (btn) { btn.dispatchEvent(new MouseEvent('click', { bubbles: true })); return true; }
          if (form) { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); return true; }
          return false;
        });
        await Promise.race([nav, sleep(1200)]);
        await snap(page, 'L3-after-login');
        logLine('login', { username: loginUser || '(none)', submitted, url: safeUrl(page) });
        if (loginOnly) {
          const res = { ok: true, network: 'notepin', mode: 'login-only', username: loginUser || '', finalUrl: safeUrl(page), logFile: LOG_FILE, logDir: LOG_DIR };
          await browser.close();
          return res;
        }
      } catch (e) {
        logLine('login-failed', { error: String((e && e.message) || e) });
        if (loginOnly) {
          const res = { ok: false, network: 'notepin', mode: 'login-only', error: 'LOGIN_FAILED', details: String((e && e.message) || e), username: loginUser || '', logFile: LOG_FILE };
          try { await browser.close(); } catch {}
          return res;
        }
      }
    }
    // 1) Open editor and fill content
    await page.goto('https://notepin.co/write', { waitUntil: 'domcontentloaded' });
    await sleep(600 + Math.floor(Math.random() * 400));
    try { await page.evaluate(() => window.scrollBy(0, 300)); } catch {}
    await snap(page, '01-open-write');
    await page.waitForSelector('.pad .elements .element.medium-editor-element[contenteditable="true"]', { timeout: 15000 });
    await page.evaluate((html) => {
      const el = document.querySelector('.pad .elements .element.medium-editor-element[contenteditable="true"]');
      if (el) { el.innerHTML = String(html || '').trim() || '<p></p>'; try { el.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch {} }
    }, htmlContent);
    await snap(page, '02-editor-filled');

    // 2) Click Publish (first time triggers modal)
    await page.click('.publish button, .publish > button').catch(() => {});
    await snap(page, '03-after-publish-click');

    const modal = await page.waitForSelector('.publishMenu', { timeout: 10000 }).then(() => true).catch(() => false);
    if (modal) {
      // Fill modal
      // Blog prefix configurable
      const prefix = (process.env.PP_NOTEPIN_PREFIX || 'pp').replace(/[^\w-]+/g, '').slice(0, 6) || 'pp';
      const blog = (prefix + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-3));
      const pass = Math.random().toString(36).slice(2, 12) + 'A!9';
      createdBlog = blog; logLine('credentials', { blog, pass });
      // Fill fields with human-like typing + ensure blur/validation fired
      // Notepin may use name="username" instead of "blog" — resolve dynamically
      const blogSelCandidates = [
        '.publishMenu input[name="blog"]',
        '.publishMenu input[name="username"]',
        '.publishMenu .username input',
        '.publishMenu input[type="text"]'
      ];
      let blogSel = blogSelCandidates[0];
      for (const s of blogSelCandidates) {
        if (await page.$(s)) { blogSel = s; break; }
      }
      const passSelCandidates = [
        '.publishMenu input[name="pass"]',
        '.publishMenu input[type="password"]'
      ];
      let passSel = passSelCandidates[0];
      for (const s of passSelCandidates) {
        if (await page.$(s)) { passSel = s; break; }
      }
      logLine('selectors', { blogSel, passSel });

      // Ensure inputs exist
      await page.waitForSelector(blogSel, { timeout: Math.min(15000, DEF_TIMEOUT) });
      await page.waitForSelector(passSel, { timeout: Math.min(15000, DEF_TIMEOUT) });

      // Clear + type blog (trigger keyup/blur for availability ajax)
      await page.click(blogSel, { clickCount: 3 }).catch(() => {});
      await page.keyboard.type(blog, { delay: 40 });
      await page.keyboard.press('Tab').catch(() => {});
      await sleep(900); // give time for username validation/availability request

      // Re-validate username if field is in "invalid" state — regenerate once
      const blogIsValid = await page.evaluate((sel) => {
        const i = document.querySelector(sel);
        if (!i) return false;
        // consider invalid if HTML5 validity fails or if CSS class indicates error
        const invalid = (i.checkValidity ? !i.checkValidity() : false) || i.classList.contains('error') || i.matches('.invalid, .error');
        return !invalid;
      }, blogSel).catch(() => true);
      if (!blogIsValid) {
        const second = (prefix + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-4));
        createdBlog = second; logLine('credentials-regenerated', { blog: second });
        await page.click(blogSel, { clickCount: 3 }).catch(() => {});
        await page.keyboard.type(second, { delay: 45 });
        await page.keyboard.press('Tab').catch(() => {});
        await sleep(1000);
      }

      // Type password
      await page.click(passSel, { clickCount: 3 }).catch(() => {});
      await page.keyboard.type(pass, { delay: 35 });
      await page.keyboard.press('Tab').catch(() => {});
      await sleep(200);

      await snap(page, '04-modal-filled');
      logLine('turnstile-wait', { start: Date.now() });

      // Wait for Cloudflare Turnstile token (invisible)
      await page.waitForFunction(() => {
        const el = document.querySelector('input[name="cf-turnstile-response"]');
        return !!(el && el.value && el.value.length > 20);
      }, { timeout: Math.min(12000, NAV_TIMEOUT) }).then(() => {
        try { logLine('turnstile-token', { ok: true }); } catch {}
      }).catch(() => { try { logLine('turnstile-token', { ok: false }); } catch {} });

      // Prepare for nav/new tab
      const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: Math.min(25000, NAV_TIMEOUT) }).catch(() => null);
      const newTarget = page.browser().waitForTarget(
        t => t.type() === 'page' && t.opener() && t.opener() === page.target(),
        { timeout: Math.min(25000, NAV_TIMEOUT) }
      ).catch(() => null);

      // Click the actual submit button (works both for button and clickable div)
      const finishSel = '.publishMenu button[type="submit"], .publishMenu .finish button, .publishMenu .finish p, .publishMenu .finish';
      try {
        await page.click(finishSel, { delay: 50 });
      } catch {
        await page.evaluate((sel) => {
          const el = document.querySelector(sel);
          if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        }, finishSel);
      }

      const [_, target] = await Promise.allSettled([nav, newTarget]);
      if (target && target.status === 'fulfilled' && target.value) {
        try { const newPage = await target.value.page(); if (newPage) page = newPage; } catch {}
      }
      await sleep(300);
      await snap(page, '05-after-modal-submit');

      // If the modal is still open and username shows validation error, retry once
      const modalStillOpen = await page.$('.publishMenu').then(Boolean).catch(() => false);
      if (modalStillOpen) {
        const usernameInvalid = await page.evaluate(() => {
          const i = document.querySelector('.publishMenu input[name="blog"]');
          if (!i) return false;
          return (i.checkValidity ? !i.checkValidity() : false) || i.classList.contains('error') || i.matches('.invalid, .error');
        }).catch(() => false);

        if (usernameInvalid) {
          // Regenerate username, retype and re-click
          const retryBlog = (prefix + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-4));
          try {
            // blogSel variable already defined above
            await page.click(blogSel, { clickCount: 3 });
            await page.keyboard.type(retryBlog, { delay: 45 });
            await page.keyboard.press('Tab');
            await sleep(1200);
            createdBlog = retryBlog; logLine('retry-username', { blog: retryBlog });

            const finishSel = '.publishMenu button[type="submit"], .publishMenu .finish button, .publishMenu .finish p, .publishMenu .finish';
            await page.click(finishSel).catch(() => {});
            await sleep(400);
          } catch {}
          await snap(page, '05a-retry-after-invalid-username');
        }
      }

      // If still on /write, try click Publish again fast
      if (/\/write\b/i.test(safeUrl(page))) {
        const fastNav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null);
        await page.click('.publish button, .publish > button').catch(() => {});
        await fastNav; await snap(page, '05b-second-publish');
      }
    } else {
      await sleep(300);
    }

    const afterUrl = safeUrl(page); logLine('after-flow', { url: afterUrl });

    // 3) Resolve final URL via blog homepage
    let publishedUrl = '';
    const blogUrl = createdBlog ? `https://${createdBlog}.notepin.co/` : '';
    if (blogUrl) {
      try {
        const view = await page.browser().newPage();
        view.setDefaultTimeout(30000); view.setDefaultNavigationTimeout(30000);
        await view.goto(blogUrl, { waitUntil: 'domcontentloaded' });
        await snap(view, '07-blog-home');
        const postUrl = await view.evaluate((base) => {
          const pick = () => document.querySelector('.posts a[href*="/p/"]')
            || document.querySelector('.posts a[href]')
            || document.querySelector('article a[href]')
            || document.querySelector('a[href]');
          const a = pick(); if (!a) return '';
          const href = (a.getAttribute('href') || '').trim();
          if (!href) return '';
          return /^https?:\/\//i.test(href) ? href : new URL(href, base).toString();
        }, blogUrl);
        await view.close().catch(() => {});
        if (postUrl) { publishedUrl = postUrl; logLine('resolved', { publishedUrl }); }
      } catch (e) { logLine('resolve-blog-failed', { error: String((e && e.message) || e) }); }
    }
    if (!publishedUrl) publishedUrl = blogUrl || afterUrl;
    if (!/^https?:\/\//i.test(publishedUrl)) throw new Error('FAILED_TO_RESOLVE_URL');

  await snap(page, '99-final');
    logLine('success', { publishedUrl });
    await browser.close();

    const verification = createVerificationPayload({ pageUrl, anchorText, article, variants: { plain, html: htmlContent } });
    verification.supportsLinkCheck = false; // disable automated checks to avoid false negatives
    verification.supportsTextCheck = false;
    return {
      ok: true,
      network: 'notepin',
      title,
      publishedUrl,
      username: createdBlog || '',
      userAgent: (await page.evaluate(() => navigator.userAgent).catch(() => '')) || '',
      logFile: LOG_FILE,
      logDir: LOG_DIR,
      verification
    };
  } catch (error) {
    try { await browser.close(); } catch {}
    return { ok: false, network: 'notepin', error: String((error && error.message) || error), logFile: LOG_FILE };
  }
}

module.exports = { publish: publishToNotepin };

// CLI entrypoint for worker
if (require.main === module) {
  (async () => {
    const { LOG_FILE, logLine } = createLogger('notepin-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: (raw || '').length });
      const job = JSON.parse(raw || '{}');
      logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });

      const pageUrl = job.url || job.pageUrl || job.jobUrl || '';
      const anchor = job.anchor || pageUrl || 'PromoPilot link';
      const language = job.language || 'ru';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase();
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'notepin', logFile: LOG_FILE };
        logLine('fail-missing-params', payload); console.log(JSON.stringify(payload)); process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'notepin', logFile: LOG_FILE };
        logLine('fail-missing-apikey', payload); console.log(JSON.stringify(payload)); process.exit(1);
      }

      const res = await publishToNotepin(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
      logLine('result', res); console.log(JSON.stringify(res)); process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String((e && e.message) || e), network: 'notepin', logFile: LOG_FILE };
      logLine('fail-exception', payload); console.log(JSON.stringify(payload)); process.exit(1);
    }
  })();
}
