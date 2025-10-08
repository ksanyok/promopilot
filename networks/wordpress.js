'use strict';

const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');
const { createLogger } = require('./lib/logger');
const { generateArticle, attachArticleToResult } = require('./lib/articleGenerator');
const { waitForTimeoutSafe, findVisibleHandle } = require('./lib/puppeteerUtils');
const { solveIfCaptcha, detectCaptcha } = require('./captcha');

function ensureDirSync(dir){ try { if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true }); } catch(_) {} }
function randInt(min, max){ return Math.floor(Math.random() * (max - min + 1)) + min; }
function randomString(len){ const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789'; let s = ''; for (let i=0;i<len;i++) s += alphabet[randInt(0, alphabet.length-1)]; return s; }
function randomEmail(){
  // Local-part must be only letters and digits
  const lettersDigits = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const len = randInt(9, 14);
  let local = '';
  for (let i = 0; i < len; i++) local += lettersDigits[randInt(0, lettersDigits.length - 1)];
  const domains = ['gmail.com','outlook.com','yahoo.com','proton.me','me.com'];
  const domain = domains[randInt(0, domains.length-1)];
  return `${local}@${domain}`;
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
  const meta = jobOptions.page_meta || jobOptions.meta || pageMeta;
  const job = {
    ...jobOptions,
    pageUrl,
    anchorText,
    language: jobOptions.language || language || 'ru',
    openaiApiKey: jobOptions.openaiApiKey || openaiApiKey,
    aiProvider: (jobOptions.aiProvider || aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase(),
    wish: jobOptions.wish || wish,
    testMode: !!jobOptions.testMode,
    meta,
    page_meta: meta,
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

  // Step 1: Onboarding as described
  const email = randomEmail();
  const username = `pp${randomString(8)}`;
  logLine('Credentials', { email, username });

  try {
    await page.goto('https://wordpress.com/setup/onboarding/user/ru?ref=logged-out-homepage-lp', { waitUntil: 'networkidle2' });
    await shot('wp-onboarding-open', page);
  } catch (e) { logLine('Goto onboarding error', { error: String(e && e.message || e) }); }

  // Click the email registration button ("Чтобы продолжить, введите адрес эл. почты")
  try {
    const clicked = await page.evaluate(() => {
      const list = Array.from(document.querySelectorAll('button.components-button, button, [role="button"]'));
      const btn = list.find(b => /введите адрес эл\. почты/i.test((b.innerText||'').trim()));
      if (btn) { btn.click(); return true; }
      return false;
    });
    logLine('Click email option', { clicked });
    await waitForTimeoutSafe(page, 800);
    await shot('wp-email-option', page);
  } catch (e) { logLine('Click email option error', { error: String(e && e.message || e) }); }

  // Fill the email in form (#signup-email) and submit
  try {
    await page.waitForSelector('#signup-email', { timeout: 20000 }).catch(()=>{});
    const emailInfo = await findVisibleHandle(page, ['#signup-email', 'input.signup-form__passwordless-email', 'input[name="email"]', 'input[type="email"]']);
    if (emailInfo && emailInfo.handle) { await emailInfo.handle.type(email, { delay: 15 }); try { await emailInfo.handle.dispose(); } catch(_) {} }
    await shot('wp-email-form', page);
    await page.evaluate(() => {
      const btn = document.querySelector('button.signup-form__submit, form .components-button[type="submit"]');
      if (btn) btn.click();
    });
  } catch (e) { logLine('Email submit error', { error: String(e && e.message || e) }); }
  await waitForTimeoutSafe(page, 1500);
  await shot('wp-email-submitted', page);

  // Captcha if any
  try { const det = await detectCaptcha(page); if (det && det.found) { await solveIfCaptcha(page, logLine, (label) => shot(label, page)); } } catch(_) {}

  // Step 2: Domain search page (as in provided HTML)
  // Wait for search input and submit button
  try {
    await page.waitForSelector('input[type="search"], #components-search-control-0', { timeout: 30000 }).catch(()=>{});
  } catch(_) {}
  await shot('wp-domain-search-open', page);

  // Generate domain base name (letters+digits) and search
  const domainBase = `${username}${randomString(4)}`;
  try {
    const searchInfo = await findVisibleHandle(page, ['#components-search-control-0', 'input[type="search"]']);
    if (searchInfo && searchInfo.handle) { await searchInfo.handle.click().catch(()=>{}); await searchInfo.handle.type(domainBase, { delay: 15 }); try { await searchInfo.handle.dispose(); } catch(_) {} }
    await waitForTimeoutSafe(page, 500);
    await page.evaluate(() => {
      const btn = document.querySelector('button.domain-search-controls__submit');
      if (btn) btn.click();
    });
  } catch (e) { logLine('Domain search error', { error: String(e && e.message || e) }); }
  await waitForTimeoutSafe(page, 2500);
  await shot('wp-domain-search-results', page);

  // Step 3: Choose free wordpress.com subdomain: click "Пропустить покупку"
  let siteSlug = '';
  try {
    const clickedSkip = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('.domain-search-skip-suggestion'));
      let btn = null;
      if (cards.length) {
        btn = cards[0].querySelector('button.domain-search-skip-suggestion__btn');
      }
      if (!btn) {
        btn = Array.from(document.querySelectorAll('button, [role="button"]')).find(b => /пропустить покупку/i.test((b.innerText||'').toLowerCase()));
      }
      if (btn) { btn.click(); return btn.getAttribute('aria-label') || 'clicked'; }
      return '';
    });
    logLine('Skip purchase clicked', { aria: clickedSkip });
    // Parse slug from aria-label if present
    if (clickedSkip && typeof clickedSkip === 'string') {
      const m = clickedSkip.match(/\b([a-z0-9-]+)\.wordpress\.com\b/i);
      if (m) siteSlug = m[1].toLowerCase();
    }
  } catch (e) { logLine('Skip purchase error', { error: String(e && e.message || e) }); }
  await waitForTimeoutSafe(page, 2500);
  await shot('wp-skip-purchase', page);

  // Resolve site URL (best effort)
  let siteUrl = '';
  if (siteSlug) siteUrl = `https://${siteSlug}.wordpress.com/`;
  if (!siteUrl) {
    try {
      siteUrl = await page.evaluate(() => {
        const a = Array.from(document.querySelectorAll('a')).map(x => x.href).find(h => /https?:\/\/[a-z0-9-]+\.wordpress\.com(\/|$)/i.test(h));
        return a || '';
      });
    } catch(_) {}
  }

  await browser.close();
  logLine('Done', { siteUrl, email, username });
  return {
    ok: !!siteUrl,
    network: 'wordpress',
    siteUrl,
    email,
    username,
    logFile: LOG_FILE,
    screenshots,
    article
  };
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
  let res = await publish(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
  res = attachArticleToResult(res, job);
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
