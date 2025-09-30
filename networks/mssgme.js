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

  // Stealth-ish evasion: user agent, language, timezone, webdriver, plugins
  try {
    const ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    await page.setUserAgent(ua);
    await page.setViewport({ width: 1280, height: 800, deviceScaleFactor: 1 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'uk-UA,uk;q=0.9,ru-RU;q=0.8,ru;q=0.7,en-US;q=0.6,en;q=0.5' });
    const context = browser.defaultBrowserContext();
    try { await context.overridePermissions('https://next.mssg.me', ['clipboard-read','clipboard-write']); } catch(_) {}
    await page.evaluateOnNewDocument(() => {
      try { Object.defineProperty(navigator, 'webdriver', { get: () => false }); } catch(_) {}
      try { window.chrome = window.chrome || { runtime: {} }; } catch(_) {}
      try { Object.defineProperty(navigator, 'plugins', { get: () => [1,2,3] }); } catch(_) {}
      try { Object.defineProperty(navigator, 'languages', { get: () => ['uk-UA','uk','ru-RU','ru','en-US','en'] }); } catch(_) {}
      try {
        const originalQuery = window.navigator.permissions && window.navigator.permissions.query;
        if (originalQuery) {
          window.navigator.permissions.query = (parameters) => (parameters && parameters.name === 'notifications')
            ? Promise.resolve({ state: Notification.permission }) : originalQuery(parameters);
        }
      } catch(_) {}
    });
  } catch(_) {}

  try {
    const ctaUrl = `https://next.mssg.me/auth/signup?utm_source=website&utm_medium=sign_up_click&utm_campaign=mssgme_website&lang=${encodeURIComponent(language || 'uk')}`;
    logLine('Goto signup CTA', { ctaUrl });
    await page.goto(ctaUrl, { waitUntil: 'networkidle2' });
  } catch (e) {
    logLine('Goto signup error', { error: String(e && e.message || e) });
  }
  screenshots.signup_open = await takeScreenshot('signup-open', page);

  // Try to accept cookies/consent if present
  try {
    await page.evaluate(() => {
      const clickIfVisible = (sel) => {
        const el = document.querySelector(sel);
        if (!el) return false;
        const r = el.getBoundingClientRect();
        if (r.width > 2 && r.height > 2) { try { el.click(); return true; } catch(_) {} }
        return false;
      };
      const texts = ['Прийняти','Принять','Accept','I agree','Згоден'];
      const btn = Array.from(document.querySelectorAll('button, a, [role="button"]')).find(b => {
        const t = (b.innerText || b.textContent || '').trim();
        return t && texts.some(x => t.toLowerCase().includes(x.toLowerCase()));
      });
      if (btn) { try { btn.click(); } catch(_) {} }
      clickIfVisible('#onetrust-accept-btn-handler');
    });
  } catch(_) {}

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

  // Tick required consent/terms checkboxes if present (language-agnostic)
  try {
    await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return r.width > 2 && r.height > 2 && cs.display !== 'none' && cs.visibility !== 'hidden';
      };
      const candidates = Array.from(document.querySelectorAll('input[type="checkbox"]'))
        .filter(cb => {
          const name = (cb.name || cb.id || '').toLowerCase();
          return /agree|term|policy|privacy|gdpr|rules/.test(name) && isVisible(cb);
        });
      candidates.forEach(cb => { if (!cb.checked) cb.click(); });
    });
  } catch(_) {}

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

  // Do NOT try to pre-solve captcha: many sites trigger invisible captcha only on submit

  // Submit form (deterministic: by id, then form submit)
  let submitClicked = false;
  try {
    await page.evaluate(() => {
      const btn = document.getElementById('button-signup-email') || document.querySelector('button#button-signup-email');
      if (btn) {
        btn.removeAttribute('disabled');
        btn.disabled = false;
        btn.click();
        return 'clicked-button';
      }
      const form = document.querySelector('form');
      if (form) {
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
        return 'submitted-form';
      }
      return 'no-form';
    }).then(tag => logLine('Submit action', { method: tag })).catch(() => {});
    submitClicked = true;
  } catch (e) {
    logLine('Submit click error', { error: String(e && e.message || e) });
  }
  if (!(typeof page.isClosed === 'function' && page.isClosed())) {
    screenshots.after_submit = await takeScreenshot('after-submit', page);
  }

  // If captcha challenged after submit, try solving and resubmit once
  // Poll for SPA redirect or captcha and handle accordingly
  try {
    const deadline = Date.now() + 90000; // up to 90s
    let captchaSolveAttempts = 0;
    while (Date.now() < deadline) {
      // Success check
      let href = '';
      try { href = await page.evaluate(() => location.href); } catch(_) {}
      if (href && /\/pages(\/?|$)/i.test(href)) {
        logLine('Registration success detected', { href });
        break;
      }
      // Captcha check
      let det = null;
      try { det = await detectCaptcha(page); } catch(_) { det = null; }
      if (det && det.found) {
        if (det.type === 'recaptcha-v3' || det.type === 'recaptcha-anchor') {
          logLine('Captcha badge/script detected (v3) - waiting for visible challenge');
        } else {
          if (captchaSolveAttempts >= 3) {
            logLine('Captcha attempts limit reached', { attempts: captchaSolveAttempts });
            break;
          }
          logLine('Captcha challenge detected', det);
          const solved = await solveIfCaptcha(page, logLine, async (label) => await takeScreenshot(label, page));
          captchaSolveAttempts += 1;
          if (solved) {
            // Ensure fields still contain values (some flows clear password)
            try {
              await page.evaluate((emailVal, passVal) => {
                const em = document.querySelector('input#email, input[name="email"], input[type="email"]');
                const pw = document.querySelector('input#password, input[name="password"], input[type="password"]');
                if (em && !em.value) em.value = emailVal;
                if (pw && !pw.value) pw.value = passVal;
              }, email, password);
            } catch(_) {}
            logLine('Captcha solved, re-submitting');
            await waitForTimeoutSafe(page, 800);
            try {
              await page.evaluate(() => {
                const btn = document.getElementById('button-signup-email') || document.querySelector('button#button-signup-email, button[type="submit"]');
                if (btn) btn.click(); else {
                  const f = document.querySelector('form'); if (f) { if (typeof f.requestSubmit==='function') f.requestSubmit(); else f.submit(); }
                }
              });
            } catch(_) {}
            await waitForTimeoutSafe(page, 1500);
            try { const href2 = await page.evaluate(() => location.href); logLine('Post-captcha href', { href: href2 }); } catch(_) {}
          }
        }
      }
      await waitForTimeoutSafe(page, 1000);
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
  // Final screenshot only if page still open
  if (!(typeof page.isClosed === 'function' && page.isClosed())) {
    screenshots.final = await takeScreenshot('final', page);
  }

  await browser.close();
  logLine('Browser closed');

  return { ok: status === 'registered', network: 'mssgme', step: 'register', status, url: currentUrl, email, password, logFile: LOG_FILE, screenshots };
}

async function mssgLogin({ email, password, language = 'uk' } = {}) {
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

  if (!email || !password) {
    const msg = 'LOGIN_MISSING_CREDENTIALS';
    logLine('Login input error', { emailPresent: Boolean(email), passwordPresent: Boolean(password) });
    return { ok: false, network: 'mssgme', step: 'login', status: msg, url: '', email: email || '', logFile: LOG_FILE, screenshots };
  }

  // Launch
  const launchArgs = ['--no-sandbox','--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  page.setDefaultTimeout(300000); page.setDefaultNavigationTimeout(300000);

  // Stealth setup
  try {
    const ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    await page.setUserAgent(ua);
    await page.setViewport({ width: 1280, height: 800, deviceScaleFactor: 1 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'uk-UA,uk;q=0.9,ru-RU;q=0.8,ru;q=0.7,en-US;q=0.6,en;q=0.5' });
    const context = browser.defaultBrowserContext();
    try { await context.overridePermissions('https://next.mssg.me', ['clipboard-read','clipboard-write']); } catch(_) {}
    await page.evaluateOnNewDocument(() => {
      try { Object.defineProperty(navigator, 'webdriver', { get: () => false }); } catch(_) {}
      try { window.chrome = window.chrome || { runtime: {} }; } catch(_) {}
      try { Object.defineProperty(navigator, 'plugins', { get: () => [1,2,3] }); } catch(_) {}
      try { Object.defineProperty(navigator, 'languages', { get: () => ['uk-UA','uk','ru-RU','ru','en-US','en'] }); } catch(_) {}
      try {
        const originalQuery = window.navigator.permissions && window.navigator.permissions.query;
        if (originalQuery) {
          window.navigator.permissions.query = (parameters) => (parameters && parameters.name === 'notifications')
            ? Promise.resolve({ state: Notification.permission }) : originalQuery(parameters);
        }
      } catch(_) {}
    });
  } catch(_) {}

  // Navigate to login, try several candidates until inputs visible
  const candidates = [
    `https://next.mssg.me/auth/signin?lang=${encodeURIComponent(language||'uk')}`,
    `https://next.mssg.me/auth/login?lang=${encodeURIComponent(language||'uk')}`,
    `https://next.mssg.me/auth?lang=${encodeURIComponent(language||'uk')}`
  ];
  let atLogin = false;
  for (const url of candidates) {
    try {
      logLine('Goto login', { url });
      await page.goto(url, { waitUntil: 'networkidle2' });
      screenshots.login_open = await takeScreenshot('login-open', page);
      const emailInfo = await findVisibleHandle(page, ['input#email', 'input[name="email"]', 'input[type="email"]']);
      const passInfo = await findVisibleHandle(page, ['input#password', 'input[name="password"]', 'input[type="password"]']);
      if (emailInfo && emailInfo.handle && passInfo && passInfo.handle) {
        try { await emailInfo.handle.dispose(); await passInfo.handle.dispose(); } catch(_) {}
        atLogin = true; break;
      }
    } catch(_) {}
  }
  if (!atLogin) {
    // fallback to signup page login tab if present
    try {
      const fallback = `https://next.mssg.me/auth/signup?lang=${encodeURIComponent(language||'uk')}`;
      logLine('Goto fallback (signup)', { url: fallback });
      await page.goto(fallback, { waitUntil: 'networkidle2' });
      screenshots.login_open = await takeScreenshot('login-open', page);
    } catch(_) {}
  }

  // Accept cookies if any
  try {
    await page.evaluate(() => {
      const texts = ['Прийняти','Принять','Accept','I agree','Згоден'];
      const btn = Array.from(document.querySelectorAll('button, a, [role="button"]')).find(b => {
        const t = (b.innerText || b.textContent || '').trim();
        return t && texts.some(x => t.toLowerCase().includes(x.toLowerCase()));
      });
      if (btn) { try { btn.click(); } catch(_) {} }
      const one = document.querySelector('#onetrust-accept-btn-handler');
      if (one) { try { one.click(); } catch(_) {} }
    });
  } catch(_) {}

  // Fill credentials
  try {
    const emailInfo = await findVisibleHandle(page, ['input#email', 'input[name="email"]', 'input[type="email"]']);
    const passInfo = await findVisibleHandle(page, ['input#password', 'input[name="password"]', 'input[type="password"]']);
    if (!emailInfo || !emailInfo.handle || !passInfo || !passInfo.handle) throw new Error('LOGIN_INPUTS_NOT_FOUND');
    await emailInfo.handle.click({ clickCount: 3 }).catch(()=>{});
    await emailInfo.handle.type(email, { delay: 20 });
    await passInfo.handle.click({ clickCount: 3 }).catch(()=>{});
    await passInfo.handle.type(password, { delay: 20 });
    try { await emailInfo.handle.dispose(); await passInfo.handle.dispose(); } catch(_) {}
  } catch (e) {
    logLine('Login fill error', { error: String(e && e.message || e) });
  }
  screenshots.login_filled = await takeScreenshot('login-filled', page);

  // Submit
  try {
    await page.evaluate(() => {
      const btn = document.querySelector('#button-login-email, #button-signin-email, button#button-login-email, button#button-signin-email, button[type="submit"], form button');
      if (btn) { (btn).removeAttribute('disabled'); (btn).disabled = false; (btn).click(); return 'clicked'; }
      const form = document.querySelector('form'); if (form) { if (typeof form.requestSubmit==='function') form.requestSubmit(); else form.submit(); return 'form-submit'; }
      return 'no-submit';
    }).then(tag => logLine('Login submit', { method: tag })).catch(()=>{});
  } catch (e) {
    logLine('Login submit error', { error: String(e && e.message || e) });
  }

  // Poll for success or captcha
  let status = 'unknown';
  try {
    const deadline = Date.now() + 90000;
    let captchaAttempts = 0;
    while (Date.now() < deadline) {
      let href = '';
      try { href = await page.evaluate(() => location.href); } catch(_) {}
      if (href && (/\/pages(\/?|$)/i.test(href) || /\/dashboard(\/?|$)/i.test(href))) { status = 'logged_in'; break; }

      let det = null;
      try { det = await detectCaptcha(page); } catch(_) { det = null; }
      if (det && det.found) {
        if (det.type === 'recaptcha-v3' || det.type === 'recaptcha-anchor') {
          logLine('Login captcha badge/anchor detected - waiting');
        } else {
          if (captchaAttempts >= 2) { logLine('Login captcha attempts limit reached', { attempts: captchaAttempts }); break; }
          logLine('Login captcha challenge detected', det);
          const solved = await solveIfCaptcha(page, logLine, async (label) => await takeScreenshot(label, page));
          captchaAttempts += 1;
          if (solved) {
            await waitForTimeoutSafe(page, 800);
            try {
              await page.evaluate(() => {
                const btn = document.querySelector('#button-login-email, #button-signin-email, button#button-login-email, button#button-signin-email, button[type="submit"], form button');
                if (btn) btn.click(); else { const f = document.querySelector('form'); if (f) { if (typeof f.requestSubmit==='function') f.requestSubmit(); else f.submit(); } }
              });
            } catch(_) {}
            await waitForTimeoutSafe(page, 1500);
          }
        }
      }
      await waitForTimeoutSafe(page, 1000);
    }
  } catch(_) {}

  let currentUrl = '';
  try { currentUrl = page.url(); } catch(_) {}
  if (!(typeof page.isClosed === 'function' && page.isClosed())) {
    screenshots.login_final = await takeScreenshot('login-final', page);
  }
  await browser.close();
  logLine('Browser closed');

  return { ok: status === 'logged_in', network: 'mssgme', step: 'login', status, url: currentUrl, email, logFile: LOG_FILE, screenshots };
}

module.exports = { register: mssgRegister, login: mssgLogin };

// CLI: we keep same contract as other handlers, but for now only registration
if (require.main === module) {
  (async () => {
    const { logLine } = createLogger('mssgme');
    try {
      const raw = process.env.PP_JOB || '{}';
      const job = JSON.parse(raw);
      const language = job.language || 'uk';
      const step = job.step || job.action || 'register';
      let res;
      if (String(step).toLowerCase() === 'login') {
        const email = job.email || (job.credentials && job.credentials.email) || '';
        const password = job.password || (job.credentials && job.credentials.password) || '';
        res = await mssgLogin({ email, password, language });
      } else {
        res = await mssgRegister({ language });
      }
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
