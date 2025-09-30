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
  page.setDefaultTimeout(Number(process.env.PP_TIMEOUT_MS || 90000));
  page.setDefaultNavigationTimeout(Number(process.env.PP_NAV_TIMEOUT_MS || 90000));

  try {
    // 1) Open homepage
    await page.goto('https://notepin.co/', { waitUntil: 'domcontentloaded' });
    await snap(page, 'L1-home');

    // 2) Open login modal
    await page.evaluate(() => {
      const el = document.querySelector('.menu .log, p.log');
      if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });
    await page.waitForSelector('.login', { timeout: 15000 });
    await page.waitForSelector('.login input[name="blog"]', { timeout: 15000 });
    await page.waitForSelector('.login input[name="pass"]', { timeout: 15000 });

    // 3) Fill credentials
    if (username) { await page.type('.login input[name="blog"]', String(username), { delay: 20 }); }
    if (password) { await page.type('.login input[name="pass"]', String(password), { delay: 20 }); }
    // Unmask password only for the screenshot so it's visible in diagnostics, then revert
    try { await page.evaluate(() => { const p = document.querySelector('.login input[name="pass"]'); if (p) p.setAttribute('type', 'text'); }); } catch {}
    await snap(page, 'L2-login-modal');
    try { await page.evaluate(() => { const p = document.querySelector('.login input[name="pass"]'); if (p) p.setAttribute('type', 'password'); }); } catch {}

    // 4) Submit
    const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
    const hostChange = page.waitForFunction(() => /\.notepin\.co$/i.test(location.hostname) && location.hostname !== 'notepin.co', { timeout: 25000 }).catch(() => null);
    await page.click('.login .finish p, .login .finish').catch(async () => {
      await page.evaluate(() => {
        const el = document.querySelector('.login .finish p') || document.querySelector('.login .finish');
        if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      });
    });
    await Promise.race([nav, hostChange, sleep(1200)]);
    await snap(page, 'L3-after-login');

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
      if (res && res.targetInfo) {
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
          if (res2 && res2.targetInfo) {
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
        if (after && after.targetInfo) {
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
