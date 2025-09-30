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

function safeUrl(p) { try { return p.url(); } catch { return ''; } }
function ensureDirSync(filePath) {
  try { const d = path.dirname(filePath); if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true }); } catch {}
}
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms || 0)));

// Minimal stealth: UA, headers, viewport, and navigator patches to reduce bot detection
async function applyStealth(page) {
  try { await page.setUserAgent(process.env.PUPPETEER_UA || 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'); } catch {}
  try { await page.setExtraHTTPHeaders({ 'Accept-Language': 'ru,uk;q=0.9,en-US;q=0.8,en;q=0.7' }); } catch {}
  try { await page.setViewport({ width: 1360, height: 900, deviceScaleFactor: 1 }); } catch {}
  try {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => false });
      window.chrome = window.chrome || { runtime: {} };
      Object.defineProperty(navigator, 'languages', { get: () => ['ru', 'uk', 'en-US', 'en'] });
      Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3] });
    });
  } catch {}
}

async function waitForVisible(page, selector, timeout = 15000) {
  await page.waitForSelector(selector, { timeout }).catch(() => {});
  const ok = await page.waitForFunction((sel) => {
    const el = document.querySelector(sel);
    if (!el) return false;
    const st = window.getComputedStyle(el);
    const r = el.getBoundingClientRect();
    return st && st.display !== 'none' && st.visibility !== 'hidden' && parseFloat(st.opacity || '1') > 0.1 && r.width > 0 && r.height > 0;
  }, { timeout }, selector).then(() => true).catch(() => false);
  return ok;
}

async function clickAny(page, selectors) {
  for (const sel of selectors) {
    const el = await page.$(sel).catch(() => null);
    if (!el) continue;
    try { await el.click({ delay: 10 }); return true; } catch {}
    try { await page.evaluate((s) => { const e = document.querySelector(s); if (e) e.dispatchEvent(new MouseEvent('click', { bubbles: true })); }, sel); return true; } catch {}
  }
  return false;
}

async function pickVisibleSelector(page, candidates, timeout = 15000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    for (const sel of candidates) {
      const ok = await waitForVisible(page, sel, 300).catch(() => false);
      if (ok) {
        const disabled = await page.evaluate((s) => {
          const el = document.querySelector(s);
          return !!(el && el instanceof HTMLInputElement && el.disabled);
        }, sel).catch(() => false);
        if (!disabled) return sel;
      }
    }
    await sleep(100);
  }
  return null;
}

async function loginAndPublish(job) {
  const { LOG_FILE, LOG_DIR, logLine } = createLogger('notepin');
  const username = job.username || job.loginUsername || process.env.PP_NOTEPIN_USERNAME || '';
  const password = job.password || job.loginPassword || process.env.PP_NOTEPIN_PASSWORD || '';

  // Screenshot helper
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

  // Launch minimal browser
  const args = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) args.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const headless = String(process.env.PP_HEADLESS || 'true').toLowerCase() !== 'false';
  const browser = await puppeteer.launch({ headless, args: Array.from(new Set(args)), executablePath: execPath || undefined });
  let page = await browser.newPage();
  await applyStealth(page);
  page.setDefaultTimeout(Number(process.env.PP_TIMEOUT_MS || 90000));
  page.setDefaultNavigationTimeout(Number(process.env.PP_NAV_TIMEOUT_MS || 90000));

  try {
    // 1) Open homepage
    await page.goto('https://notepin.co/', { waitUntil: 'domcontentloaded' });
    await sleep(600);
    await snap(page, 'L1-home');

    // 2) Open login modal
    await waitForVisible(page, '.menu .log, p.log', 15000);
    await clickAny(page, ['.menu .log', 'p.log']);
    await waitForVisible(page, '.login', 15000);
    // Let animations finish a tick
    await sleep(350);
    // Pick robust selectors (support both blog/username naming)
    const userSel = await pickVisibleSelector(page, [
      '.login input[name="blog"]',
      '.login input[name="username"]',
      '.login .username input',
      '.login input[type="text"]'
    ], 4000);
    const passSel = await pickVisibleSelector(page, [
      '.login input[name="pass"]',
      '.login input[type="password"]'
    ], 4000);
    logLine('login-selectors', { userSel, passSel });
    await snap(page, 'L2a-login-ready');

    // 3) Fill credentials with real typing after focusing fields
    if (userSel && username) {
      await page.click(userSel, { clickCount: 3 }).catch(() => {});
      await page.type(userSel, String(username), { delay: 25 }).catch(() => {});
    }
    if (passSel && password) {
      await page.click(passSel, { clickCount: 3 }).catch(() => {});
      await page.type(passSel, String(password), { delay: 25 }).catch(() => {});
    }
    // Confirm values
    const confirmed = await page.evaluate(({ uSel, pSel }) => {
      const uEl = uSel ? document.querySelector(uSel) : null;
      const pEl = pSel ? document.querySelector(pSel) : null;
      const uVal = (uEl && 'value' in uEl) ? (uEl).value : '';
      const pVal = (pEl && 'value' in pEl) ? (pEl).value : '';
      return { uVal: String(uVal || ''), pLen: String(pVal || '').length };
    }, { uSel: userSel, pSel: passSel }).catch(() => ({ uVal: '', pLen: 0 }));
    logLine('login-confirm-typed', confirmed);
    // Fallback: force-set values if typing didn't stick
    if ((confirmed.uVal || '') !== String(username || '') || (confirmed.pLen || 0) < 1) {
      await page.evaluate(({ uSel, pSel, u, p }) => {
        const uEl = uSel ? document.querySelector(uSel) : null;
        const pEl = pSel ? document.querySelector(pSel) : null;
        if (uEl && 'value' in uEl) { uEl.value = u; uEl.dispatchEvent(new Event('input', { bubbles: true })); }
        if (pEl && 'value' in pEl) { pEl.value = p; pEl.dispatchEvent(new Event('input', { bubbles: true })); }
      }, { uSel: userSel, pSel: passSel, u: String(username || ''), p: String(password || '') }).catch(() => {});
    }
    const confirmed2 = await page.evaluate(({ uSel, pSel }) => {
      const uEl = uSel ? document.querySelector(uSel) : null;
      const pEl = pSel ? document.querySelector(pSel) : null;
      const uVal = (uEl && 'value' in uEl) ? (uEl).value : '';
      const pVal = (pEl && 'value' in pEl) ? (pEl).value : '';
      return { uVal: String(uVal || ''), pLen: String(pVal || '').length };
    }, { uSel: userSel, pSel: passSel }).catch(() => ({ uVal: '', pLen: 0 }));
    logLine('login-confirm-forced', confirmed2);
    if ((confirmed2.uVal || '') !== String(username || '') || (confirmed2.pLen || 0) < 1) {
      await snap(page, 'L2c-fill-failed');
      return { ok: false, network: 'notepin', error: 'CANNOT_FILL_LOGIN_FORM', details: { userSel, passSel, confirmed: confirmed2 } };
    }
    // Unmask password only for the screenshot so it's visible in diagnostics, then revert
  try { await page.evaluate((sel) => { const p = document.querySelector(sel) || document.querySelector('.login input[name="pass"]'); if (p) p.setAttribute('type', 'text'); }, passSel || '.login input[name="pass"]'); } catch {}
  await snap(page, 'L2b-login-filled');
  try { await page.evaluate((sel) => { const p = document.querySelector(sel) || document.querySelector('.login input[name="pass"]'); if (p) p.setAttribute('type', 'password'); }, passSel || '.login input[name="pass"]'); } catch {}

    // 4) Submit
    const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
    const hostChange = page.waitForFunction(() => /\.notepin\.co$/i.test(location.hostname) && location.hostname !== 'notepin.co', { timeout: 25000 }).catch(() => null);
    await clickAny(page, ['.login .finish p', '.login .finish']);
    const dashWait = page.waitForFunction(() => /\/dash\b/.test(location.pathname), { timeout: 25000 }).catch(() => null);
    await Promise.race([nav, hostChange, dashWait, sleep(1500)]);
    if (!/\/dash\b/i.test(safeUrl(page) || '')) {
      await snap(page, 'L3-no-dash');
      return { ok: false, network: 'notepin', error: 'NO_DASH_REDIRECT' };
    }
    await snap(page, 'L3-dash');

    // 5) If job requests login-only, exit here
    if (job.loginOnly || /^(1|true|yes)$/i.test(String(process.env.PP_NOTEPIN_LOGIN_ONLY || ''))) {
      const finalUrl = safeUrl(page);
      await browser.close();
      return { ok: true, network: 'notepin', mode: 'login-only', username: username || '', finalUrl, logFile: LOG_FILE, logDir: LOG_DIR };
    }

    // 6) Click "new post" to go to the write page (stay in same session/context)
    const current = new URL(safeUrl(page) || 'https://notepin.co');
    let origin = current.origin;
    let navWrite = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
    let newTarget = page.browser().waitForTarget(
      t => t.type() === 'page' && t.opener() && t.opener() === page.target(),
      { timeout: 20000 }
    ).catch(() => null);
    let clicked = await page.evaluate(() => {
      const el = document.querySelector('a[href="write"] .newPost, p.newPost, a[href="write"], a[href$="/write"]');
      if (el && typeof el.dispatchEvent === 'function') { el.dispatchEvent(new MouseEvent('click', { bubbles: true })); return true; }
      return false;
    }).catch(() => false);
    if (!clicked) {
      // Try direct navigation to /write on current origin first
      await page.goto(`${origin}/write`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    } else {
      const res = await Promise.race([navWrite, newTarget, sleep(1200)]);
      if (res && typeof res.page === 'function') {
        try { const np = await res.page(); if (np) page = np; } catch {}
      }
    }
    // If still not on /write and we have a username, try the blog subdomain then retry
    if (!/\/write\b/i.test(safeUrl(page)) && username) {
      try {
        await page.goto(`https://${username}.notepin.co/`, { waitUntil: 'domcontentloaded' });
        await snap(page, 'L3b-blog-home');
        navWrite = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
        newTarget = page.browser().waitForTarget(
          t => t.type() === 'page' && t.opener() && t.opener() === page.target(),
          { timeout: 20000 }
        ).catch(() => null);
        clicked = await page.evaluate(() => {
          const el = document.querySelector('a[href="write"] .newPost, p.newPost, a[href="write"], a[href$="/write"]');
          if (el && typeof el.dispatchEvent === 'function') { el.dispatchEvent(new MouseEvent('click', { bubbles: true })); return true; }
          return false;
        }).catch(() => false);
        if (!clicked) {
          await page.goto(`https://${username}.notepin.co/write`, { waitUntil: 'domcontentloaded' }).catch(() => {});
        } else {
          const res2 = await Promise.race([navWrite, newTarget, sleep(1200)]);
          if (res2 && typeof res2.page === 'function') {
            try { const np = await res2.page(); if (np) page = np; } catch {}
          }
        }
      } catch {}
    }
    await snap(page, 'P1-write-page');

    // 8) Generate or use provided article
    const genJob = {
      pageUrl: job.url || job.pageUrl || job.jobUrl || '',
      anchorText: job.anchor || 'PromoPilot link',
      language: job.language || 'ru',
      aiProvider: (job.aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase(),
      openaiApiKey: job.openaiApiKey || process.env.OPENAI_API_KEY || '',
      wish: job.wish || '',
      meta: job.page_meta || job.meta || null,
      testMode: !!job.testMode
    };
    const article = (job.preparedArticle && job.preparedArticle.htmlContent)
      ? { ...job.preparedArticle }
      : await generateArticle(genJob, logLine);
    const htmlContent = String(article.htmlContent || '<p></p>');
    const title = (article.title || (genJob.meta && genJob.meta.title) || genJob.anchorText || 'New Post').toString().slice(0, 120);

    // 9) Fill editor content
    await page.waitForSelector('.pad .elements .element.medium-editor-element[contenteditable="true"]', { timeout: 15000 });
    await page.evaluate((html) => {
      const el = document.querySelector('.pad .elements .element.medium-editor-element[contenteditable="true"]');
      if (el) { el.innerHTML = String(html); try { el.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch {} }
    }, htmlContent).catch(() => {});
    await snap(page, 'P2-editor-filled');

    // 10) Publish — handle logged-in modal (title/visibility)
    const navPub = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
    const newTargetPub = page.browser().waitForTarget(
      t => t.type() === 'page' && t.opener() && t.opener() === page.target(),
      { timeout: 25000 }
    ).catch(() => null);
    await page.click('.publish button, .publish > button').catch(async () => {
      await page.evaluate(() => { const b = document.querySelector('.publish button, .publish > button'); if (b && typeof b.dispatchEvent === 'function') b.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
    });
    // Wait for publish modal and fill if present
    const modalAppeared = await page.waitForSelector('.publishMenu', { timeout: 15000 }).then(() => true).catch(() => false);
    if (modalAppeared) {
      const isLoggedInModal = await page.$('.publishMenu .titleInp').then(Boolean).catch(() => false);
      const hasSignupFields = await page.$('.publishMenu input[name="blog"], .publishMenu input[name="username"], .publishMenu input[name="pass"]').then(Boolean).catch(() => false);
      if (isLoggedInModal && !hasSignupFields) {
        // Title
        await page.click('.publishMenu .titleInp', { clickCount: 3 }).catch(() => {});
        await page.type('.publishMenu .titleInp', title, { delay: 15 }).catch(() => {});
        // Public visibility
        await page.evaluate(() => {
          const btn = document.querySelector('.publishMenu .options[data-do="visible"] button[data-type="public"]');
          if (btn && !btn.classList.contains('chosen')) btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        }).catch(() => {});
        // CF token
        await page.waitForFunction(() => {
          const el = document.querySelector('input[name="cf-turnstile-response"]');
          return !!(el && el instanceof HTMLInputElement && el.value && el.value.length > 20);
        }, { timeout: 20000 }).catch(() => null);
        await snap(page, 'P3-modal-filled');
        const navSubmit = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
        const newTargetSubmit = page.browser().waitForTarget(
          t => t.type() === 'page' && t.opener() && t.opener() === page.target(),
          { timeout: 25000 }
        ).catch(() => null);
        await page.click('.publishMenu .finish p, .publishMenu .finish').catch(async () => {
          await page.evaluate(() => {
            const el = document.querySelector('.publishMenu .finish p') || document.querySelector('.publishMenu .finish');
            if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
          });
        });
        const after = await Promise.race([
          navSubmit,
          newTargetSubmit,
          page.waitForFunction(() => /\/p\//.test(location.pathname), { timeout: 15000 }).catch(() => null),
          sleep(1200)
        ]);
        if (after && typeof after.page === 'function') {
          try { const np = await after.page(); if (np) page = np; } catch {}
        }
        await snap(page, 'P4-after-submit');
      } else {
        // Unexpected: signup modal visible after login
        await snap(page, 'P3-unexpected-signup-modal');
      }
    }

    // If still no nav, wait briefly for URL change to a post page
    await Promise.race([
      navPub,
      page.waitForFunction(() => /\/p\//.test(location.pathname), { timeout: 8000 }).catch(() => null),
      sleep(800)
    ]);
    await snap(page, 'P5-final');

  const finalUrl = safeUrl(page);
    await browser.close();
    return { ok: true, network: 'notepin', mode: 'publish', username: username || '', publishedUrl: finalUrl, logFile: LOG_FILE, logDir: LOG_DIR };
  } catch (error) {
    try { await snap(page, 'Lx-error'); } catch {}
    try { await browser.close(); } catch {}
    return { ok: false, network: 'notepin', mode: 'publish', error: String((error && error.message) || error) };
  }
}

module.exports = { publish: loginAndPublish };

// CLI entrypoint (login-only by default)
if (require.main === module) {
  (async () => {
    const { LOG_FILE, logLine } = createLogger('notepin-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: (raw || '').length });
      const job = JSON.parse(raw || '{}');
      logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });

      const res = await loginAndPublish(job);
      logLine('result', res); console.log(JSON.stringify(res)); process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String((e && e.message) || e), network: 'notepin' };
      logLine('fail-exception', payload); console.log(JSON.stringify(payload)); process.exit(1);
    }
  })();
}
