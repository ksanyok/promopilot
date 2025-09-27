
'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const fetch = require('node-fetch');
const { generateText, cleanLLMOutput } = require('./ai_client');

// Logger
const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
const LOG_FILE = process.env.PP_LOG_FILE || path.join(
  LOG_DIR,
  `network-justpaste-${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}.log`
);
function ensureDirSync(dir){ try { if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true }); } catch(_) {} }
ensureDirSync(LOG_DIR);
function safeStringify(obj){ try { return JSON.stringify(obj); } catch(_) { return String(obj); } }
function logLine(msg, data){
  const line = `[${new Date().toISOString()}] ${msg}${data ? ' ' + safeStringify(data) : ''}\n`;
  try { fs.appendFileSync(LOG_FILE, line); } catch(_) {}
}

const sleep = (ms) => new Promise(res => setTimeout(res, ms));

async function generateTextWithChat(prompt, opts) {
  const provider = (opts && opts.provider) || process.env.PP_AI_PROVIDER || 'openai';
  logLine('AI request', { provider, promptPreview: String(prompt||'').slice(0,160) });
  try { const out = await generateText(String(prompt||''), opts||{}); logLine('AI response ok', { length: String(out||'').length }); return out; }
  catch (e) { logLine('AI error', { error: String(e && e.message || e) }); throw e; }
}

function analyzeLinks(html, url, anchor) {
  try {
    const str = String(html || '');
    const matches = Array.from(str.matchAll(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/ig));
    const total = matches.length;
    let our = 0, ext = 0, hasExact = false; const domains = new Set();
    const normalize = (t) => String(t||'').replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim().toLowerCase();
    const expect = normalize(anchor);
    for (const m of matches) {
      const href = m[1]; const inner = normalize(m[2]);
      if (href === url) { our++; if (expect && inner === expect) hasExact = true; }
      else { ext++; const dm = /^(?:https?:\/\/)?([^\/]+)/i.exec(href); if (dm && dm[1]) domains.add(dm[1].toLowerCase()); }
    }
    return { totalLinks: total, ourLinkCount: our, externalCount: ext, hasOurAnchorText: hasExact, externalDomains: Array.from(domains).slice(0,3) };
  } catch { return { totalLinks:0, ourLinkCount:0, externalCount:0, hasOurAnchorText:false }; }
}

async function publishToJustPaste(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, captchaCfg) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const aiOpts = { provider, openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '', model: process.env.OPENAI_MODEL || undefined, temperature: 0.2 };
  const meta = pageMeta || {};
  const pageLang = language || meta.lang || 'ru';
  const topicTitle = (meta.title || '').toString().trim();
  const topicDesc = (meta.description || '').toString().trim();
  const region = (meta.region || '').toString().trim();
  const extraNote = wish ? `\nNote (use if helpful): ${wish}` : '';

  // Captcha config
  const captcha = captchaCfg || (() => { try { const j = JSON.parse(process.env.PP_JOB || '{}'); return j.captcha || {}; } catch(_) { return {}; } })();
  const captchaProvider = String((captcha && captcha.provider) || process.env.PP_CAPTCHA_PROVIDER || 'none').toLowerCase();
  const captchaApiKey = String((captcha && captcha.apiKey) || process.env.PP_CAPTCHA_API_KEY || '').trim();

  logLine('Publish start', { pageUrl, anchorText, language: pageLang, provider, captchaProvider: captchaProvider || 'none' });

  const prompts = {
    title: `На ${pageLang} сформулируй чёткий конкретный заголовок по теме: ${topicTitle || anchorText}${topicDesc ? ' — ' + topicDesc : ''}. Укажи фокус: ${anchorText}.\n` +
      `Требования: без кавычек и эмодзи, без упоминания URL, 6–12 слов. Ответь только заголовком.`,
    content:
      `Напиши статью на ${pageLang} (>=3000 знаков) по теме: ${topicTitle || anchorText}${topicDesc ? ' — ' + topicDesc : ''}.${region ? ' Регион: ' + region + '.' : ''}${extraNote}\n` +
      `Требования:\n` +
      `- Ровно три активные ссылки в статье (формат строго <a href="...">...</a>):\n` +
      `  1) Ссылка на наш URL с точным анкором "${anchorText}": <a href="${pageUrl}">${anchorText}</a> — естественно в первой половине текста.\n` +
      `  2) Вторая ссылка на наш же URL, но с другим органичным анкором (не "${anchorText}").\n` +
      `  3) Одна ссылка на авторитетный внешний источник (например, Wikipedia/энциклопедия/официальный сайт), релевантный теме; URL не должен быть битым/фиктивным; язык предпочтительно ${pageLang} (или en).\n` +
      `- Только простой HTML: <p> абзацы и <h2> подзаголовки. Без markdown и кода.\n` +
      `- 3–5 смысловых секций и короткое заключение.\n` +
      `- Кроме указанных трёх ссылок — никаких иных ссылок или URL.\n` +
      `Ответь только телом статьи.`,
  };

  const rawTitle = await generateTextWithChat(prompts.title, { ...aiOpts, systemPrompt: 'Только финальный заголовок. Без кавычек и пояснений.', keepRaw: true });
  const rawContent = await generateTextWithChat(prompts.content, { ...aiOpts, systemPrompt: 'Только тело статьи в HTML (<p>, <h2>), без markdown и пояснений.', keepRaw: true });

  const titleClean = cleanLLMOutput(rawTitle).replace(/^\s*["'«»]+|["'«»]+\s*$/g, '').trim();
  let content = cleanLLMOutput(rawContent);

  // Validate links, regenerate once if needed
  const wantOur = 2, wantExternal = 1, wantTotal = 3;
  let stat = analyzeLinks(content, pageUrl, anchorText);
  if (!(stat.ourLinkCount >= wantOur && stat.externalCount >= wantExternal && stat.totalLinks === wantTotal)) {
    const stricter = prompts.content + `\nСТРОГО: включи ровно ${wantTotal} ссылки: две на ${pageUrl} (одна с анкором "${anchorText}", вторая с другим анкором) и одну на внешний авторитетный источник. Больше ссылок не добавляй.`;
    const retry = await generateTextWithChat(stricter, { ...aiOpts, systemPrompt: 'Соблюдай требования ссылок строго. Только HTML тело.', keepRaw: true });
    const retryClean = cleanLLMOutput(retry);
    const retryStat = analyzeLinks(retryClean, pageUrl, anchorText);
    if (retryStat.ourLinkCount + retryStat.externalCount >= stat.ourLinkCount + stat.externalCount) {
      content = retryClean; stat = retryStat;
    }
  }

  function normalizeContent(html) {
    let s = String(html || '').trim();
    s = s.replace(/<h1[^>]*>([\s\S]*?)<\/h1>/gi, '<h2>$1</h2>');
    s = s.replace(/<li[^>]*>([\s\S]*?)<\/li>/gi, (m, inner) => `<p>— ${inner.trim()}</p>`);
    s = s.replace(/<\/(?:ul|ol)>/gi, '').replace(/<(?:ul|ol)[^>]*>/gi, '');
    s = s.replace(/<p([^>]*)>\s*[-–—•∙·]\s+(.*?)<\/p>/gi, '<p$1>— $2</p>');
    s = s.replace(/<p[^>]*>(?:\s|<br[^>]*>)*<\/p>/gi, '');
    return s;
  }
  const cleanedContent = normalizeContent(content);
  logLine('Link analysis', { before: stat, after: analyzeLinks(cleanedContent, pageUrl, anchorText) });

  // Puppeteer: open JustPaste.it editor and switch to Html tab
  const launchArgs = ['--no-sandbox','--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  page.setDefaultTimeout(300000); page.setDefaultNavigationTimeout(300000);
  await page.goto('https://justpaste.it/', { waitUntil: 'networkidle2' });
  // Log UA for diagnostics
  try { const ua = await page.evaluate(() => navigator.userAgent); logLine('UA', { userAgent: ua }); } catch(_) {}

  // Try to locate the add article editor (supports Html tab)
  try { await page.waitForSelector('#editArticleWidget .tabsBar, .editArticleMiddle .tabsBar', { timeout: 15000 }); } catch(_) {}

  // Set title first (explicit selector per UI: .titleColumn input.titleInput)
  try {
    const titleVal = (titleClean || topicTitle || anchorText || '').toString().trim().slice(0, 100);
    const titleSelCandidates = [
      '#editArticleWidget .titleColumn input.titleInput',
      '.editArticleMiddle .titleColumn input.titleInput',
      '#editArticleWidget input.titleInput',
      '.editArticleMiddle input.titleInput',
      'input.titleInput'
    ];
    let titleSel = '';
    for (const s of titleSelCandidates) { if (await page.$(s)) { titleSel = s; break; } }
    if (!titleSel) throw new Error('TITLE_INPUT_NOT_FOUND');
    await page.focus(titleSel).catch(()=>{});
    await page.click(titleSel, { clickCount: 3 }).catch(()=>{});
    await page.keyboard.press('Backspace').catch(()=>{});
    await page.type(titleSel, titleVal, { delay: 10 });
    await page.$eval(titleSel, (el) => { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
    logLine('Title filled', { selector: titleSel, length: titleVal.length });
  } catch (e) { logLine('Title fill error', { error: String(e && e.message || e) }); }

  // Switch to Html tab (use explicit Html tab; robust to different containers)
  try {
    await page.waitForSelector('#editArticleWidget .tabsBar a, .editArticleMiddle .tabsBar a', { timeout: 15000 });
    await page.evaluate(() => {
      const tabs = Array.from(document.querySelectorAll('#editArticleWidget .tabsBar a, .editArticleMiddle .tabsBar a'));
      const html = tabs.find(a => (a.textContent||'').trim().toLowerCase() === 'html');
      if (html) { html.scrollIntoView({block:'center'}); html.click(); }
    });
    await sleep(500);
    // If not active yet, click one more time
    const isHtmlActive = await page.evaluate(() => {
      const tabs = Array.from(document.querySelectorAll('#editArticleWidget .tabsBar a, .editArticleMiddle .tabsBar a'));
      const html = tabs.find(a => (a.textContent||'').trim().toLowerCase() === 'html');
      return !!(html && html.classList.contains('addArticleTabOn'));
    });
    if (!isHtmlActive) {
      await page.evaluate(() => {
        const tabs = Array.from(document.querySelectorAll('#editArticleWidget .tabsBar a, .editArticleMiddle .tabsBar a'));
        const html = tabs.find(a => (a.textContent||'').trim().toLowerCase() === 'html');
        if (html) { html.click(); }
      });
      await sleep(500);
    }
    logLine('Html tab toggled');
  } catch (e) { logLine('Switch tab error', { error: String(e && e.message || e) }); }

  // Fill via Html textarea only (avoid TinyMCE/iframes to prevent frame detaches)
  try {
    // Ensure Html area is visible
    await page.waitForFunction(() => {
      const ta = document.querySelector('#htmlAreaDIV textarea#tinyMCEEditor') || document.querySelector('#htmlAreaDIV .mainTextarea, textarea#tinyMCEEditor');
      if (!ta) return false;
      const rect = ta.getBoundingClientRect();
      const cs = window.getComputedStyle(ta);
      return cs.display !== 'none' && cs.visibility !== 'hidden' && rect.height > 0 && rect.width > 0;
    }, { timeout: 15000 });
    const taSel = (await page.$('#htmlAreaDIV textarea#tinyMCEEditor')) ? '#htmlAreaDIV textarea#tinyMCEEditor' : (await page.$('#htmlAreaDIV .mainTextarea')) ? '#htmlAreaDIV .mainTextarea' : 'textarea#tinyMCEEditor';
    await page.$eval(taSel, (el, val) => {
      el.focus();
      el.value = val;
      const ev = (t) => { try { el.dispatchEvent(new Event(t, { bubbles: true })); } catch(_) {} };
      ev('input'); ev('change'); ev('keyup');
    }, cleanedContent);
    logLine('Filled via textarea', { selector: taSel });
  } catch (e) {
    logLine('Fill editor failed', { error: String(e && e.message || e) });
  }

  // Ensure editor change is registered
  try {
    await page.focus('.editArticleMiddle input.titleInput');
    await page.keyboard.press('Tab').catch(()=>{});
    await sleep(200);
  } catch(_) {}
  // Screenshot just before publish
  async function takeScreenshot(label){
    try {
      const fname = `justpaste-${label}-${Date.now()}.png`;
      const fpath = path.join(LOG_DIR, fname);
      await page.screenshot({ path: fpath, fullPage: true });
      logLine('Screenshot', { label, path: fpath, url: page.url() });
      return fpath;
    } catch (e) { logLine('Screenshot error', { label, error: String(e && e.message || e) }); return ''; }
  }
  await takeScreenshot('pre-publish');

  // Extra: if page still on editor after publish try, retry once with re-toggling Html tab
  async function extractPublishedUrl() {
    // Prefer article-like URLs (not homepage), e.g., https://justpaste.it/abc12 or with a slug after domain
    return await page.evaluate(() => {
      const collect = new Set();
      const add = (u) => { if (u && typeof u === 'string') collect.add(u.trim()); };
      const isArticleUrl = (s) => {
        if (typeof s !== 'string') return false;
        if (!/^https?:\/\//i.test(s)) return false; // only absolute URLs
        try {
          const u = new URL(s);
          if (!/^(?:www\.)?justpaste\.it$/i.test(u.hostname)) return false;
          const path = (u.pathname || '/').replace(/\/+/g,'/');
          if (path === '/' || path === '') return false; // exclude homepage
          // Require at least one non-empty segment of 3+ chars (common pattern)
          const segs = path.split('/').filter(Boolean);
          return segs.some(seg => /[A-Za-z0-9_-]{3,}/.test(seg));
        } catch { return false; }
      };
      // Gather anchors, excluding obvious ad/premium containers
      for (const a of Array.from(document.querySelectorAll('a[href]'))) {
        const withinAd = !!a.closest('.becomePremiumPanel, .ads, .advert, .premium, .ad, .sponsored');
        const txt = (a.textContent || '').trim().toLowerCase();
        if (withinAd) continue;
        if (/premium/.test(txt)) continue;
        add(a.href);
      }
      // Gather values from inputs/textareas ONLY if they look like absolute URLs
      for (const el of Array.from(document.querySelectorAll('input[value], textarea'))) {
        const v = (el.value || '').trim();
        if (/^https?:\/\//i.test(v)) add(v);
      }
      // Also parse visible text for URLs
      const text = document.body ? (document.body.innerText || '') : '';
      const rx = /https?:\/\/(?:www\.)?justpaste\.it\/[A-Za-z0-9][A-Za-z0-9_-]{2,}[^\s"']*/g;
      let m; while ((m = rx.exec(text)) !== null) add(m[0]);
      // Rank by length (prefer deeper/slugged URLs)
      const cands = Array.from(collect).filter(isArticleUrl).sort((a,b) => b.length - a.length);
      return cands[0] || null;
    });
  }

  async function validateCandidateUrl(candidateUrl) {
    // Open in a temp page to avoid messing with the main page
    if (!candidateUrl || !/^https?:\/\//i.test(candidateUrl)) return { ok: false, reason: 'INVALID_URL' };
    let temp;
    try {
      temp = await browser.newPage();
      await temp.goto(candidateUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(()=>{});
      const res = await temp.evaluate((expectedUrl, expectedTitle) => {
        const body = document.body;
        const html = body ? (body.innerHTML || '') : '';
        const text = body ? (body.innerText || '') : '';
        const hasBacklink = expectedUrl ? html.includes(expectedUrl) : false;
        const t = (document.title || '').trim();
        const h = (document.querySelector('h1,h2')?.innerText || '').trim();
        const titleHit = expectedTitle ? (t.includes(expectedTitle) || h.includes(expectedTitle)) : false;
        const isAd = !!document.querySelector('.becomePremiumPanel, .ads, .advert, .premium, .ad, .sponsored');
        const premiumText = /premium\b/i.test(text) && /\$\s*\d+(?:\.\d{1,2})?/.test(text);
        return { hasBacklink, titleHit, isAd, premiumText };
      }, pageUrl, (titleClean || '').slice(0, 60));
      const ok = (res.hasBacklink || res.titleHit) && !res.isAd && !res.premiumText;
      return { ok, details: res };
    } catch (e) {
      return { ok: false, reason: 'VALIDATION_ERROR', error: String(e && e.message || e) };
    } finally {
      try { if (temp) await temp.close(); } catch(_) {}
    }
  }

  async function doPublish() {
    const startUrl = page.url();
    const tryClick = async () => {
      // Check terms/agree checkbox if present
      await page.evaluate(() => {
        const sel = 'input[type="checkbox"][name*="agree" i], input[type="checkbox"][id*="agree" i], input[type="checkbox"][name*="terms" i], input[type="checkbox"][id*="terms" i]';
        const cb = document.querySelector(sel);
        if (cb && !cb.checked && !cb.disabled) { try { cb.click(); } catch(_) {} }
      }).catch(()=>{});
      await sleep(100);

      const info = await page.evaluate(() => {
        const visible = (el) => {
          if (!el) return false;
          const cs = window.getComputedStyle(el);
          const rect = el.getBoundingClientRect();
          if (cs.display === 'none' || cs.visibility === 'hidden') return false;
          if (rect.width <= 0 || rect.height <= 0) return false;
          return true;
        };
        const all = Array.from(document.querySelectorAll('button, a, input[type="submit"]'));
        const label = (el) => (el.innerText || el.textContent || '').trim();
        const isPublish = (t, el) => /(^|\b)publish(\b|$)/i.test(t) || (el && el.classList && el.classList.contains('publishButton')) || /опубликовать/i.test(t);
        const cands = [];
        all.forEach((el, idx) => {
          const text = label(el);
          const cand = {
            idx,
            tag: el.tagName,
            id: el.id || '',
            classes: el.className || '',
            text,
            inBottom: !!el.closest('.editArticleBottomButtons'),
            disabled: !!el.disabled,
            visible: visible(el),
            isPublish: isPublish(text, el)
          };
          if (cand.visible && cand.isPublish && !cand.disabled) cands.push(cand);
        });
        cands.sort((a,b) => Number(b.inBottom) - Number(a.inBottom));
        const chosen = cands[0] || null;
        if (chosen) {
          const el = all[chosen.idx];
          try { el.scrollIntoView({block:'center'}); } catch(_) {}
          try { el.click(); } catch(_) {}
        }
        return { candidates: cands, chosen };
      });
      try { logLine('Publish click info', info); } catch(_) {}
      if (!info || !info.chosen) throw new Error('PUBLISH_BUTTON_NOT_FOUND');
    };

    // Try up to 3 click attempts, each with polling for navigation or a justpaste article URL in DOM
    for (let attempt = 0; attempt < 3; attempt++) {
      await takeScreenshot(`attempt${attempt+1}-pre-click`);
      await tryClick();
      await sleep(350);
      await takeScreenshot(`attempt${attempt+1}-post-click`);
      const t0 = Date.now();
      let found = null;
      while (Date.now() - t0 < 45000) { // 45s per attempt
        // Navigation or URL change to an article-like URL
        const now = await page.evaluate(() => location.href).catch(()=>null);
        if (now && now !== startUrl && /https?:\/\/(?:www\.)?justpaste\.it\//.test(now) && !/https?:\/\/(?:www\.)?justpaste\.it\/?$/.test(now)) {
          return now;
        }
        // Try extract from DOM
        found = await extractPublishedUrl();
        if (found) {
          // Validate candidate (avoid premium/ad links)
          try {
            const v = await validateCandidateUrl(found);
            if (v && v.ok) return found;
            else logLine('Discarded candidate URL', { found, validation: v });
          } catch(_) {}
        }
        await sleep(500);
      }
      // If not found yet, small wait and retry
      await sleep(1000);
    }
    throw new Error('PUBLISH_NO_NAVIGATION');
  }
  let resultUrl = null;
  try { resultUrl = await doPublish(); }
  catch (e) {
    logLine('Publish attempt failed, retrying after toggling Html', { error: String(e && e.message || e) });
    try {
      await page.evaluate(() => {
        const tabs = Array.from(document.querySelectorAll('#editArticleWidget .tabsBar a, .editArticleMiddle .tabsBar a'));
        for (const t of tabs) {
          const txt = (t.textContent||'').trim().toLowerCase();
          if (txt === 'editor' || txt === 'html') t.click();
        }
      });
    } catch(_) {}
    await sleep(400);
    resultUrl = await doPublish();
  }

  // Resolve final published URL: prefer detected article URL, fallback to current page URL, avoid homepage
  let publishedUrl = resultUrl || page.url();
  if (/^https?:\/\/(?:www\.)?justpaste\.it\/?$/.test(publishedUrl)) {
    // Last-ditch attempt to extract from DOM if we somehow ended on homepage
    const last = await extractPublishedUrl();
    if (last) { publishedUrl = last; try { await page.goto(publishedUrl, { waitUntil: 'domcontentloaded' }); } catch(_) {} }
  }
  // Guard: ensure it’s an absolute justpaste URL; otherwise try to re-extract
  if (!/^https?:\/\//i.test(publishedUrl) || !/^(?:https?:\/\/)(?:www\.)?justpaste\.it\//i.test(publishedUrl)) {
    const last2 = await extractPublishedUrl();
    if (last2) publishedUrl = last2;
  }
  // Final validation; if fails, keep the URL but mark as requiresCaptcha unless user opted otherwise
  try {
    const vfinal = await validateCandidateUrl(publishedUrl);
    if (vfinal && !vfinal.ok) {
      logLine('Final URL seems not an article, likely ad or blocked', { publishedUrl, validation: vfinal });
    }
  } catch(_) {}
  await takeScreenshot('post-publish');
  // Try to load the article URL (to observe redirects/captcha)
  let requiresCaptcha = false; let captchaType = '';
  const detectCaptcha = async () => {
    return await page.evaluate(() => {
      const found = (sel) => !!document.querySelector(sel);
      const hasText = (t) => (document.body?.innerText || '').toLowerCase().includes(t);
      // reCAPTCHA
      if (found('iframe[src*="recaptcha"], div.g-recaptcha, div#recaptcha, .grecaptcha-badge')) return { found: true, type: 'recaptcha' };
      // hCaptcha
      if (found('iframe[src*="hcaptcha"], .h-captcha')) return { found: true, type: 'hcaptcha' };
      // Cloudflare challenge
      if (hasText('just a moment') || found('#cf-challenge-running') || found('div#challenge-form')) return { found: true, type: 'cloudflare' };
      // Generic
      if (hasText('captcha') || hasText('verify you are human') || hasText('подтвердите что вы не робот')) return { found: true, type: 'generic' };
      return { found: false, type: '' };
    });
  };
  try {
    if (publishedUrl && /^https?:\/\//i.test(publishedUrl)) {
      await page.goto(publishedUrl, { waitUntil: 'networkidle2', timeout: 120000 }).catch(()=>{});
      const res = await detectCaptcha();
      if (res && res.found) { requiresCaptcha = true; captchaType = res.type || 'unknown'; }
    }
  } catch(_) {}

  // If captcha is present, capture a screenshot for diagnostics
  let captchaScreenshot = '';
  let captchaSolved = false;
  if (requiresCaptcha) {
    try {
      const fname = `justpaste-captcha-${Date.now()}.png`;
      const fpath = path.join(LOG_DIR, fname);
      await page.screenshot({ path: fpath, fullPage: true }).catch(()=>{});
      captchaScreenshot = fpath;
      logLine('Captcha detected', { captchaType, screenshot: fpath, url: page.url() });
    } catch(_) {}

    // Attempt auto-solve if configured
    if (captchaProvider !== 'none' && captchaApiKey) {
      try {
        const siteKey = await (async () => {
          try {
            return await page.evaluate(() => {
              const out = { recaptcha: '', hcaptcha: '' };
              const findParam = (src, key) => {
                try {
                  const u = new URL(src, location.href);
                  return u.searchParams.get(key) || '';
                } catch { return ''; }
              };
              // reCAPTCHA: data-sitekey or iframe k/sitekey param
              const r1 = document.querySelector('div.g-recaptcha[data-sitekey]');
              if (r1) out.recaptcha = r1.getAttribute('data-sitekey') || '';
              if (!out.recaptcha) {
                const r2 = Array.from(document.querySelectorAll('iframe[src*="recaptcha"]')).map(f => findParam(f.getAttribute('src')||'', 'k') || findParam(f.getAttribute('src')||'', 'sitekey')).find(Boolean);
                if (r2) out.recaptcha = r2;
              }
              // hCaptcha: data-sitekey or iframe sitekey param
              const h1 = document.querySelector('div.h-captcha[data-sitekey], .h-captcha[data-sitekey]');
              if (h1) out.hcaptcha = h1.getAttribute('data-sitekey') || '';
              if (!out.hcaptcha) {
                const h2 = Array.from(document.querySelectorAll('iframe[src*="hcaptcha.com"]')).map(f => findParam(f.getAttribute('src')||'', 'sitekey')).find(Boolean);
                if (h2) out.hcaptcha = h2;
              }
              return out;
            });
          } catch { return { recaptcha:'', hcaptcha:'' }; }
        })();

        const sitekey = captchaType === 'hcaptcha' ? (siteKey && siteKey.hcaptcha) : (siteKey && siteKey.recaptcha);
        logLine('Captcha sitekey', { type: captchaType, sitekey: sitekey ? (sitekey.slice(0,6)+'...') : '' });
        if (sitekey) {
          const solve2Captcha = async () => {
            const isH = captchaType === 'hcaptcha';
            const inUrl = 'http://2captcha.com/in.php';
            const resUrl = 'http://2captcha.com/res.php';
            const params = new URLSearchParams();
            params.set('key', captchaApiKey);
            params.set('json', '1');
            params.set('method', isH ? 'hcaptcha' : 'userrecaptcha');
            if (isH) params.set('sitekey', sitekey); else params.set('googlekey', sitekey);
            params.set('pageurl', publishedUrl);
            let resp = await fetch(`${inUrl}?${params.toString()}`);
            let data = await resp.json().catch(()=>({status:0,request:'JSON_ERR'}));
            if (!data || data.status !== 1) throw new Error('2captcha in.php error: ' + (data && data.request));
            const id = data.request;
            const poll = async () => {
              const ps = new URLSearchParams(); ps.set('key', captchaApiKey); ps.set('action','get'); ps.set('id', id); ps.set('json','1');
              let r; let d;
              const deadline = Date.now() + 180000; // 3min
              while (Date.now() < deadline) {
                await sleep(5000);
                r = await fetch(`${resUrl}?${ps.toString()}`);
                d = await r.json().catch(()=>({status:0,request:'JSON_ERR'}));
                if (d && d.status === 1) return d.request;
                if (d && typeof d.request === 'string' && d.request !== 'CAPCHA_NOT_READY') break;
              }
              throw new Error('2captcha res.php timeout or error: ' + (d && d.request));
            };
            return await poll();
          };

          const solveAntiCaptcha = async () => {
            const isH = captchaType === 'hcaptcha';
            const createUrl = 'https://api.anti-captcha.com/createTask';
            const resultUrl = 'https://api.anti-captcha.com/getTaskResult';
            const type = isH ? 'HCaptchaTaskProxyless' : 'NoCaptchaTaskProxyless';
            const createPayload = { clientKey: captchaApiKey, task: { type, websiteURL: publishedUrl, websiteKey: sitekey } };
            let resp = await fetch(createUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(createPayload) });
            let data = await resp.json().catch(()=>({errorId:1,errorCode:'JSON_ERR'}));
            if (!data || data.errorId) throw new Error('anti-captcha createTask error: ' + (data && (data.errorCode||data.errorDescription)));
            const taskId = data.taskId;
            const pollPayload = { clientKey: captchaApiKey, taskId };
            const deadline = Date.now() + 180000;
            while (Date.now() < deadline) {
              await sleep(5000);
              const r = await fetch(resultUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(pollPayload) });
              const d = await r.json().catch(()=>({errorId:1,errorCode:'JSON_ERR'}));
              if (d && !d.errorId && d.status === 'ready') {
                const sol = d.solution || {};
                return sol.gRecaptchaResponse || sol.token || '';
              }
              if (d && d.errorId) throw new Error('anti-captcha getTaskResult error: ' + (d.errorCode||d.errorDescription));
            }
            throw new Error('anti-captcha timeout');
          };

          const token = captchaProvider === '2captcha' ? await solve2Captcha() : await solveAntiCaptcha();
          logLine('Captcha token obtained', { len: (token||'').length, provider: captchaProvider });
          // Inject token and try to proceed
          const injected = await page.evaluate((type, tok) => {
            const inject = (name) => {
              let el = document.querySelector(`textarea[name="${name}"]`) || document.querySelector(`#${name}`);
              if (!el) { el = document.createElement('textarea'); el.name = name; el.id = name; el.style.display = 'none'; document.body.appendChild(el); }
              el.value = tok;
              try { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); } catch {}
              return !!el;
            };
            let ok = false;
            if (type === 'hcaptcha') { ok = inject('h-captcha-response'); }
            else { ok = inject('g-recaptcha-response'); }
            // Try to submit a containing form or click a visible continue/verify button
            const form = document.querySelector('form');
            if (form) { try { form.submit(); } catch {} }
            const btn = Array.from(document.querySelectorAll('button, input[type="submit"], a'))
              .find(el => /continue|verify|submit|продолжить|подтвердить/i.test(el.textContent||el.value||''));
            if (btn) { try { btn.click(); } catch {} }
            return ok;
          }, captchaType, token);
          await sleep(3000);
          try { await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(()=>{}); } catch {}
          // Re-check
          const after = await detectCaptcha();
          if (!after || !after.found) { captchaSolved = true; requiresCaptcha = false; captchaType = ''; logLine('Captcha solved'); }
        } else {
          logLine('Captcha sitekey not found, auto-solve skipped');
        }
      } catch (e) {
        logLine('Captcha solve error', { error: String(e && e.message || e) });
      }
    }
  }

  logLine('Published', { publishedUrl });
  await browser.close();
  logLine('Browser closed');
  return { ok: true, network: 'justpaste', publishedUrl, title: titleClean, logFile: LOG_FILE, requiresCaptcha, captchaType, captchaScreenshot, captchaSolved };
}

module.exports = { publish: publishToJustPaste };

// CLI entrypoint
if (require.main === module) {
  (async () => {
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
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'justpaste', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload); console.log(JSON.stringify(payload)); process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'justpaste', logFile: LOG_FILE };
        logLine('Run failed (missing openai key)', payload); console.log(JSON.stringify(payload)); process.exit(1);
      }

  const res = await publishToJustPaste(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job.captcha || null);
      logLine('Success result', res); console.log(JSON.stringify(res)); process.exit(0);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'justpaste', logFile: LOG_FILE };
      logLine('Run failed', { error: payload.error, stack: e && e.stack });
      console.log(JSON.stringify(payload)); process.exit(1);
    }
  })();
}
