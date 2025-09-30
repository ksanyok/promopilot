'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { createLogger } = require('./lib/logger');
const { waitForTimeoutSafe, findVisibleHandle } = require('./lib/puppeteerUtils');
const { solveIfCaptcha, detectCaptcha } = require('./captcha');

// Utilities
function ensureDirSync(dir){ try { if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true }); } catch(_) {} }
function randInt(min, max){ return Math.floor(Math.random() * (max - min + 1)) + min; }
function randomString(len){ const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789'; let s = ''; for (let i=0;i<len;i++) s += alphabet[randInt(0, alphabet.length-1)]; return s; }
function randomEmail(){
  const user = randomString(randInt(7, 12));
  const domains = ['gmail.com','outlook.com','yahoo.com','proton.me','mail.com','gmx.com','zoho.com'];
  const domain = domains[randInt(0, domains.length-1)];
  return `${user}+pp${Date.now().toString(36)}@${domain}`;
}
function randomPassword(){
  const sets = [
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    'abcdefghjkmnpqrstuvwxyz',
    '23456789',
    '!@#$%^&*?'
  ];
  const pick = (pool, n) => Array.from({length:n}, () => pool[randInt(0, pool.length-1)]).join('');
  let pwd = pick(sets[0], 2) + pick(sets[1], 4) + pick(sets[2], 3) + pick(sets[3], 1) + randomString(randInt(2,5));
  return pwd.split('').sort(() => Math.random() - 0.5).join('');
}

async function mssgRegister({ language = 'uk' } = {}){
  const { LOG_DIR, LOG_FILE, logLine } = createLogger('mssgme');
  ensureDirSync(LOG_DIR);
  const screenshots = {};

  const takeScreenshot = async (label, page) => {
    try {
      const fname = `mssgme-${label}-${Date.now()}.png`;
      const fpath = path.join(LOG_DIR, fname);
      await page.screenshot({ path: fpath, fullPage: true });
      logLine('Screenshot', { label, path: fpath });
      return fpath;
    } catch (e) {
      logLine('Screenshot error', { label, error: String(e && e.message || e) });
      return '';
    }
  };

  const launchArgs = ['--no-sandbox','--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  page.setDefaultTimeout(300000);
  page.setDefaultNavigationTimeout(300000);

  try {
    const ctaUrl = `https://next.mssg.me/auth/signup?utm_source=website&utm_medium=sign_up_click&utm_campaign=mssgme_website&lang=${encodeURIComponent(language || 'uk')}`;
    logLine('Goto signup CTA', { ctaUrl });
    await page.goto(ctaUrl, { waitUntil: 'networkidle2' });
  } catch (e) {
    logLine('Goto signup error', { error: String(e && e.message || e) });
  }
  screenshots.signup_open = await takeScreenshot('signup-open', page);

  // Detect captcha presence early (for logging)
  try { const det = await detectCaptcha(page); if (det && det.found) logLine('Captcha detected early', det); } catch(_) {}

  // Fill email and password
  const email = randomEmail();
  const password = randomPassword();
  logLine('Credentials generated', { email, passwordPreview: password.slice(0,3) + '***', language });

  try {
    // Email
    const emailInfo = await findVisibleHandle(page, ['input#email', 'input[name="email"]', 'input[type="email"]', 'input[placeholder*="email" i]']);
    if (!emailInfo || !emailInfo.handle) throw new Error('EMAIL_INPUT_NOT_FOUND');
    await emailInfo.handle.click({ clickCount: 3 }).catch(()=>{});
    await emailInfo.handle.type(email, { delay: 15 });
    try { await emailInfo.handle.dispose(); } catch(_) {}

    // Password
    const passInfo = await findVisibleHandle(page, ['input#password', 'input[name="password"]', 'input[type="password"]']);
    if (!passInfo || !passInfo.handle) throw new Error('PASSWORD_INPUT_NOT_FOUND');
    await passInfo.handle.click({ clickCount: 3 }).catch(()=>{});
    await passInfo.handle.type(password, { delay: 15 });
    try { await passInfo.handle.dispose(); } catch(_) {}
  } catch (e) {
    logLine('Fill form error', { error: String(e && e.message || e) });
  }
  screenshots.signup_filled = await takeScreenshot('signup-filled', page);

  // Enable submit button if disabled
  try {
    await page.evaluate(() => {
      const btn = document.querySelector('button#button-signup-email, button[type="submit"], [id="button-signup-email"]');
      if (btn) {
        btn.removeAttribute('disabled');
        btn.disabled = false;
      }
    });
  } catch(_) {}

  // Attempt to solve captcha if present
  try {
    const res = await solveIfCaptcha(page, logLine, async (label) => await takeScreenshot(label, page));
    if (res && (res === true || res.solved)) {
      logLine('Captcha solved before submit');
    }
  } catch(_) {}

  // Submit form
  let submitClicked = false;
  try {
    const sel = ['button#button-signup-email', 'button[type="submit"]', 'form button'];
    const info = await findVisibleHandle(page, sel);
    if (info && info.handle) {
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(()=>null),
        info.handle.click().catch(()=>{})
      ]);
      submitClicked = true;
      try { await info.handle.dispose(); } catch(_) {}
    } else {
      // fallback enter
      await page.keyboard.press('Enter').catch(()=>{});
    }
  } catch (e) {
    logLine('Submit click error', { error: String(e && e.message || e) });
  }
  screenshots.after_submit = await takeScreenshot('after-submit', page);

  // If captcha challenged after submit, try solving and resubmit once
  try {
    const det = await detectCaptcha(page);
    if (det && det.found) {
      logLine('Captcha after submit', det);
      const solved = await solveIfCaptcha(page, logLine, async (label) => await takeScreenshot(label, page));
      if (solved) {
        await waitForTimeoutSafe(page, 1200);
        try {
          await page.evaluate(() => {
            const btn = document.querySelector('button#button-signup-email, button[type="submit"]');
            if (btn) btn.click();
          });
        } catch(_) {}
        await Promise.race([
          page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(()=>null),
          waitForTimeoutSafe(page, 3000)
        ]);
      }
    }
  } catch(_) {}

  // Check result: success redirects likely to /pages, or show validation
  let status = 'unknown';
  let currentUrl = '';
  try { currentUrl = page.url(); } catch (_) { currentUrl = ''; }
  if (/\/pages(\/|$)/i.test(currentUrl)) {
    status = 'registered';
  } else {
    // Inspect for errors
    try {
      const err = await page.evaluate(() => {
        const texts = Array.from(document.querySelectorAll('[class*="error" i]')).map(n => (n.innerText||'').trim()).filter(Boolean);
        const alerts = Array.from(document.querySelectorAll('[role="alert"], .alert, .error')).map(n => (n.innerText||'').trim()).filter(Boolean);
        return (texts[0] || alerts[0] || '').slice(0, 500);
      });
      if (err) {
        status = 'error';
        logLine('Registration error text', { err });
      }
    } catch(_) {}
  }
  screenshots.final = await takeScreenshot('final', page);

  await browser.close();
  logLine('Browser closed');

  return { ok: status === 'registered', network: 'mssgme', step: 'register', status, url: currentUrl, email, password, logFile: LOG_FILE, screenshots };
}

module.exports = { register: mssgRegister };

// CLI: we keep same contract as other handlers, but for now only registration
if (require.main === module) {
  (async () => {
    const { logLine } = createLogger('mssgme');
    try {
      const raw = process.env.PP_JOB || '{}';
      const job = JSON.parse(raw);
      const language = job.language || 'uk';
      const res = await mssgRegister({ language });
      logLine('Result', res);
      console.log(JSON.stringify(res));
      process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, network: 'mssgme', step: 'register', error: String(e && e.message || e) };
      logLine('Run failed', payload);
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}
