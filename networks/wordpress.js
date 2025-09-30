'use strict';

const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { waitForTimeoutSafe, findVisibleHandle } = require('./lib/puppeteerUtils');
const { solveIfCaptcha, detectCaptcha } = require('./captcha');

function ensureDirSync(dir){ try { if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true }); } catch(_) {} }
function randInt(min, max){ return Math.floor(Math.random() * (max - min + 1)) + min; }
function randomString(len){ const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789'; let s = ''; for (let i=0;i<len;i++) s += alphabet[randInt(0, alphabet.length-1)]; return s; }
function randomEmail(){
  const user = randomString(randInt(8, 12));
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
  let pwd = pick(sets[0], 2) + pick(sets[1], 5) + pick(sets[2], 3) + pick(sets[3], 1) + randomString(randInt(2,5));
  return pwd.split('').sort(() => Math.random() - 0.5).join('');
}

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const { LOG_DIR, LOG_FILE, logLine } = createLogger('wordpress');
  ensureDirSync(LOG_DIR);
  const screenshots = {};

  const shot = async (label, page) => {
    try {
      const name = `wordpress-${label}-${Date.now()}.png`;
      const full = path.join(LOG_DIR, name);
      await page.screenshot({ path: full, fullPage: true });
      logLine('Screenshot', { label, path: full });
      screenshots[label] = full;
      return full;
    } catch (e) {
      logLine('Screenshot error', { label, error: String(e && e.message || e) });
      return '';
    }
  };

  // Prepare content
  const job = {
    pageUrl,
    anchorText,
    language: jobOptions.language || language || 'ru',
    openaiApiKey: jobOptions.openaiApiKey || openaiApiKey,
    aiProvider: (jobOptions.aiProvider || aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase(),
    wish: jobOptions.wish || wish,
    meta: jobOptions.page_meta || jobOptions.meta || pageMeta,
    testMode: !!jobOptions.testMode
  };
  logLine('Start', { pageUrl, anchorText, language: job.language, provider: job.aiProvider, testMode: job.testMode });

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(job, logLine);

  const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim();
  const html = String(article.htmlContent || '').trim();
  if (!html) {
    return { ok: false, network: 'wordpress', error: 'EMPTY_ARTICLE_CONTENT', logFile: LOG_FILE };
  }

  // Browser
  const launchArgs = ['--no-sandbox','--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  page.setDefaultTimeout(300000); page.setDefaultNavigationTimeout(300000);

  try {
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'ru-RU,ru;q=0.9,en-US;q=0.6,en;q=0.5' });
  } catch (_) {}

  // Step 1: Register
  const email = randomEmail();
  const password = randomPassword();
  const username = `pp${randomString(6)}`; // used for site slug too
  logLine('Credentials', { email, username, passwordPreview: password.slice(0,3) + '***' });

  try {
    await page.goto('https://wordpress.com/start/account/user', { waitUntil: 'networkidle2' });
    await shot('wp-start', page);
  } catch (e) { logLine('Goto signup error', { error: String(e && e.message || e) }); }

  // Fill email & username & password (WP flows may vary)
  try {
    const emailInfo = await findVisibleHandle(page, [
      'input#email', 'input[name="email"]', 'input[type="email"]', 'input[autocomplete="email"]'
    ]);
    if (emailInfo && emailInfo.handle) { await emailInfo.handle.type(email, { delay: 15 }); try { await emailInfo.handle.dispose(); } catch(_) {} }
    const userInfo = await findVisibleHandle(page, [
      'input#username', 'input[name="username"]'
    ]);
    if (userInfo && userInfo.handle) { await userInfo.handle.type(username, { delay: 15 }); try { await userInfo.handle.dispose(); } catch(_) {} }
    const passInfo = await findVisibleHandle(page, [
      'input#password', 'input[name="password"]', 'input[type="password"]'
    ]);
    if (passInfo && passInfo.handle) { await passInfo.handle.type(password, { delay: 15 }); try { await passInfo.handle.dispose(); } catch(_) {} }
  } catch (e) { logLine('Fill signup error', { error: String(e && e.message || e) }); }

  // Accept cookies if present
  try { await page.evaluate(() => { const b = Array.from(document.querySelectorAll('button, [role="button"], a')).find(x => /accept|agree|consent/i.test(x.innerText||'')); if (b) b.click(); }); } catch(_) {}

  // Submit signup
  try {
    await page.evaluate(() => {
      const btn = document.querySelector('button[type="submit"], button.signup__submit, button.is-primary');
      if (btn) btn.click();
    });
  } catch (_) {}
  await waitForTimeoutSafe(page, 1500);
  await shot('wp-after-signup', page);

  // Captcha handling if appears
  try { const det = await detectCaptcha(page); if (det && det.found) { await solveIfCaptcha(page, logLine, (label) => shot(label, page)); } } catch(_) {}

  // Step 2: Create free site
  // Navigate to site creation wizard if not already
  try {
    if (!/wordpress\.com\/(home|setup|start)/i.test(page.url())) {
      await page.goto('https://wordpress.com/start/site-style', { waitUntil: 'networkidle2' });
    }
  } catch(_) {}
  await shot('wp-wizard', page);

  // Pick free domain variant
  const siteSlug = `${username}-${Date.now().toString(36)}`;
  try {
    // Bypass many questions by going directly to domain search
    await page.goto('https://wordpress.com/start/domain', { waitUntil: 'networkidle2' });
    await waitForTimeoutSafe(page, 1000);
    const domainInput = await findVisibleHandle(page, ['input[type="search"]', 'input#domain-search-input', 'input[name="domain"]']);
    if (domainInput && domainInput.handle) {
      await domainInput.handle.type(siteSlug, { delay: 20 });
      try { await domainInput.handle.dispose(); } catch(_) {}
    }
    await waitForTimeoutSafe(page, 800);
    // Click on free suggestion *.wordpress.com
    await page.evaluate(() => {
      const cands = Array.from(document.querySelectorAll('button, .domain-suggestion, [role="button"]'));
      const btn = cands.find(el => /wordpress\.com/i.test(el.innerText||'') && /free/i.test(el.innerText||''));
      if (btn) btn.click();
    });
    await waitForTimeoutSafe(page, 1500);
    await shot('wp-domain-picked', page);
  } catch (e) { logLine('Domain pick error', { error: String(e && e.message || e) }); }

  // Confirm free plan
  try {
    await page.goto('https://wordpress.com/plan/selection', { waitUntil: 'networkidle2' });
    await waitForTimeoutSafe(page, 800);
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('button, [role="button"]')).find(x => /start with free|free/i.test((x.innerText||'').toLowerCase()));
      if (btn) btn.click();
    });
  } catch(_) {}
  await waitForTimeoutSafe(page, 2000);
  await shot('wp-free-plan', page);

  // Step 3: Create a post and publish
  let publishedUrl = '';
  try {
    await page.goto('https://wordpress.com/post', { waitUntil: 'networkidle2' });
    await shot('wp-post-editor-open', page);
    // Title
    try {
      const titleH = await findVisibleHandle(page, ['textarea[placeholder*="Add title" i]', 'textarea.editor-post-title__input', 'h1[role="textbox"]']);
      if (titleH && titleH.handle) { await titleH.handle.click({ clickCount: 3 }).catch(()=>{}); await titleH.handle.type(title, { delay: 15 }); try { await titleH.handle.dispose(); } catch(_) {} }
    } catch(_) {}
    // Body: use slash command to add Paragraph block and paste HTML as quotes or paragraphs
    await waitForTimeoutSafe(page, 400);
    await page.keyboard.press('Tab').catch(()=>{});
    await page.keyboard.type(article.plainText || ' ', { delay: 1 }).catch(()=>{});
    // Open publish panel and publish
    await waitForTimeoutSafe(page, 600);
    await page.evaluate(() => {
      const clickByText = (txt) => {
        const el = Array.from(document.querySelectorAll('button, [role="button"], .components-button')).find(b => (b.innerText||'').toLowerCase().includes(txt));
        if (el) { el.click(); return true; }
        return false;
      };
      return clickByText('publish');
    });
    await waitForTimeoutSafe(page, 1000);
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('button, [role="button"], .components-button')).find(b => /publish/i.test(b.innerText||''));
      if (btn) btn.click();
    });
    await waitForTimeoutSafe(page, 3000);
    await shot('wp-post-published', page);
    try {
      publishedUrl = await page.evaluate(() => {
        const link = Array.from(document.querySelectorAll('a')).map(a => a.href).find(h => /https?:\/\/.*\.wordpress\.com\//i.test(h));
        return link || '';
      });
    } catch(_) {}
  } catch (e) {
    logLine('Post publish error', { error: String(e && e.message || e) });
  }

  if (!publishedUrl) {
    try { publishedUrl = page.url(); } catch(_) { publishedUrl = ''; }
  }

  await browser.close();
  logLine('Done', { publishedUrl });
  return { ok: !!publishedUrl, network: 'wordpress', publishedUrl, title, email, username, password, logFile: LOG_FILE, screenshots };
}

// CLI entry for PromoPilot runner
if (require.main === module) {
  (async () => {
    const { createLogger } = require('./lib/logger');
    const { LOG_FILE, logLine } = createLogger('wordpress-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      const job = JSON.parse(raw);
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'wordpress', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'wordpress', logFile: LOG_FILE };
        logLine('Run failed (missing openai key)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      const res = await publish(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
      logLine('Success result', res);
      console.log(JSON.stringify(res));
      process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'wordpress' };
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}

module.exports = { publish };
