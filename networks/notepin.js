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

function safeUrl(p) { try { return p.url(); } catch { return ''; } }
function ensureDirSync(filePath) {
  try { const d = path.dirname(filePath); if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true }); } catch {}
}
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms || 0)));

async function loginNotepin(username, password) {
  const { LOG_FILE, LOG_DIR, logLine } = createLogger('notepin');

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
  const page = await browser.newPage();
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

    const finalUrl = safeUrl(page);
    await browser.close();
    return { ok: true, network: 'notepin', mode: 'login-only', username: username || '', finalUrl, logFile: LOG_FILE, logDir: LOG_DIR };
  } catch (error) {
    try { await snap(page, 'Lx-error'); } catch {}
    try { await browser.close(); } catch {}
    return { ok: false, network: 'notepin', mode: 'login-only', error: String((error && error.message) || error) };
  }
}

module.exports = { publish: loginNotepin };

// CLI entrypoint (login-only by default)
if (require.main === module) {
  (async () => {
    const { LOG_FILE, logLine } = createLogger('notepin-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: (raw || '').length });
      const job = JSON.parse(raw || '{}');
      logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });

      const username = job.username || job.loginUsername || process.env.PP_NOTEPIN_USERNAME || 'pphr9sc56f4j4s';
      const password = job.password || job.loginPassword || process.env.PP_NOTEPIN_PASSWORD || 'swxqsk27nmA!9';

      const res = await loginNotepin(username, password);
      logLine('result', res); console.log(JSON.stringify(res)); process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String((e && e.message) || e), network: 'notepin' };
      logLine('fail-exception', payload); console.log(JSON.stringify(payload)); process.exit(1);
    }
  })();
}
