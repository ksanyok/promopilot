
'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { generateText, cleanLLMOutput } = require('./ai_client');
const { solveIfCaptcha, detectCaptcha } = require('./captcha');

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
// Verbose switch to reduce noise
const VERBOSE = /^(1|true|yes)$/i.test(String(process.env.PP_VERBOSE || '0'));
const logDbg = (msg, data) => { if (VERBOSE) logLine(msg, data); };

const sleep = (ms) => new Promise(res => setTimeout(res, ms));

async function isElementVisible(handle) {
  if (!handle) return false;
  try {
    return await handle.evaluate((el) => {
      if (!el || typeof el !== 'object') return false;
      const style = window.getComputedStyle(el);
      if (!style) return false;
      const rect = el.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 2 && rect.height > 2 && rect.top < (window.innerHeight || 0) + 200;
    });
  } catch (_) {
    return false;
  }
}

async function findVisibleHandle(page, selectors) {
  const list = Array.isArray(selectors) ? selectors : [selectors];
  for (const sel of list) {
    try {
      const handles = await page.$$(sel);
      for (const handle of handles) {
        const visible = await isElementVisible(handle);
        if (visible) {
          return { handle, selector: sel };
        }
        try { await handle.dispose(); } catch (_) {}
      }
    } catch (_) {}
  }
  return null;
}

async function fillContentInContext(ctx, html) {
  if (!ctx) return null;
  try {
    return await ctx.evaluate((val) => {
      const isVisible = (el) => {
        if (!el) return false;
        const style = window.getComputedStyle(el);
        if (!style) return false;
        const rect = el.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 2 && rect.height > 2 && rect.top < (window.innerHeight || 0) + 200;
      };
      const fire = (el) => {
        try {
          ['input','change','keyup','blur','paste'].forEach(evt => {
            try { el.dispatchEvent(new Event(evt, { bubbles: true })); } catch (_) {}
          });
        } catch (_) {}
      };
      const selectors = [
        '#htmlAreaDIV textarea#tinyMCEEditor',
        '#htmlAreaDIV .mainTextarea',
        'textarea#tinyMCEEditor',
        'textarea[name="content"]',
        'textarea[name="text"]',
        'textarea[name="body"]',
        'textarea[name="message"]',
        'textarea[name="description"]',
        'textarea[name="article"]',
        'textarea#article_content',
        'textarea.articleTextarea',
        'form textarea',
        'textarea'
      ];
      for (const sel of selectors) {
        const candidate = Array.from(document.querySelectorAll(sel)).find(isVisible);
        if (candidate && typeof candidate.value !== 'undefined') {
          candidate.focus();
          candidate.value = val;
          fire(candidate);
          return { via: 'textarea', selector: sel }; 
        }
      }
      const contentEditable = Array.from(document.querySelectorAll('[contenteditable="true"], [contenteditable=""]')).find(isVisible);
      if (contentEditable) {
        contentEditable.focus();
        contentEditable.innerHTML = val;
        fire(contentEditable);
        return { via: 'contenteditable', selector: '[contenteditable]'};
      }
      const iframes = Array.from(document.querySelectorAll('iframe')).filter(isVisible);
      for (const iframe of iframes) {
        try {
          const doc = iframe.contentDocument || iframe.contentWindow && iframe.contentWindow.document;
          if (!doc) continue;
          const bodyTarget = doc.querySelector('body#tinymce, body#tinyMCE, body.mce-content-body, body');
          if (!bodyTarget) continue;
          bodyTarget.innerHTML = val;
          try {
            ['input','change','keyup','blur','paste'].forEach(evt => {
              try { bodyTarget.dispatchEvent(new Event(evt, { bubbles: true })); } catch (_) {}
            });
          } catch (_) {}
          return { via: 'iframe', selector: iframe.id || iframe.name || 'iframe' };
        } catch (_) {}
      }
      return null;
    }, html);
  } catch (_) {
    return null;
  }
}

async function generateTextWithChat(prompt, opts) {
  const provider = (opts && opts.provider) || process.env.PP_AI_PROVIDER || 'openai';
  logDbg('AI request', { provider, promptPreview: String(prompt||'').slice(0,160) });
  try { const out = await generateText(String(prompt||''), opts||{}); logDbg('AI response ok', { length: String(out||'').length }); return out; }
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

async function publishToJustPaste(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const aiOpts = { provider, openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '', model: process.env.OPENAI_MODEL || undefined, temperature: 0.2 };
  const meta = pageMeta || {};
  const pageLang = language || meta.lang || 'ru';
  const topicTitle = (meta.title || '').toString().trim();
  const topicDesc = (meta.description || '').toString().trim();
  const region = (meta.region || '').toString().trim();
  const extraNote = wish ? `\nNote (use if helpful): ${wish}` : '';

  logLine('Publish start', { pageUrl, anchorText, language: pageLang, provider });

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
  logDbg('Link analysis', { before: stat, after: analyzeLinks(cleanedContent, pageUrl, anchorText) });

  // Puppeteer: open JustPaste.it editor and switch to Html tab
  const launchArgs = ['--no-sandbox','--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logDbg('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  try { await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 }); } catch (_) {}
  page.setDefaultTimeout(300000); page.setDefaultNavigationTimeout(300000);
  await page.goto('https://justpaste.it/', { waitUntil: 'networkidle2' });
  // Log UA for diagnostics
  try { const ua = await page.evaluate(() => navigator.userAgent); logDbg('UA', { userAgent: ua }); } catch(_) {}

  // Try to locate the add article editor (supports Html tab)
  try { await page.waitForSelector('#editArticleWidget .tabsBar, .editArticleMiddle .tabsBar', { timeout: 15000 }); } catch(_) {}

  // Set title first (explicit selector per UI: .titleColumn input.titleInput)
  try {
    const titleVal = (titleClean || topicTitle || anchorText || '').toString().trim().slice(0, 140);
    const titleCandidates = [
      '#editArticleWidget .titleColumn input.titleInput',
      '.editArticleMiddle .titleColumn input.titleInput',
      '#editArticleWidget input.titleInput',
      '.editArticleMiddle input.titleInput',
      'input.titleInput',
      'input[name="title"]',
      'input[name="post_title"]',
      'input[name="article_title"]',
      'input[name="subject"]',
      'input#title',
      'input#article_title',
      'input[placeholder*="Title" i]',
      'input[placeholder*="Заголовок" i]',
      'input[class*="title" i]',
      'form input[type="text"]'
    ];
    const titleHandleInfo = await findVisibleHandle(page, titleCandidates);
    if (!titleHandleInfo || !titleHandleInfo.handle) throw new Error('TITLE_INPUT_NOT_FOUND');
    const { handle: titleHandle, selector: titleSel } = titleHandleInfo;
    await titleHandle.focus().catch(()=>{});
    try { await titleHandle.click({ clickCount: 3 }); } catch (_) {}
    await page.keyboard.press('Backspace').catch(()=>{});
    let typed = false;
    try {
      await titleHandle.type(titleVal, { delay: 12 });
      typed = true;
    } catch (_) {}
    if (!typed) {
      await titleHandle.evaluate((el, val) => { el.value = val; }, titleVal);
    }
    await titleHandle.evaluate((el) => {
      try {
        ['input','change','keyup','blur'].forEach(evt => el.dispatchEvent(new Event(evt, { bubbles: true })));
      } catch (_) {}
    });
    logDbg('Title filled', { selector: titleSel, length: titleVal.length });
    try { await titleHandle.dispose(); } catch (_) {}
  } catch (e) { logLine('Title fill error', { error: String(e && e.message || e) }); }

  // Switch to Html tab (use explicit Html tab; robust to different containers)
  try {
    await sleep(500);
    const toggleResult = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const style = window.getComputedStyle(el);
        if (!style) return false;
        const rect = el.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 2 && rect.height > 2;
      };
      const keywords = ['html', 'код', 'source', 'исходный'];
      const selectors = [
        '#editArticleWidget .tabsBar a',
        '.editArticleMiddle .tabsBar a',
        '.tabsBar a',
        '[data-tab="html"]',
        '[role="tab"]',
        'button',
        'a'
      ];
      const visited = new Set();
      const pool = [];
      selectors.forEach(sel => {
        try {
          Array.from(document.querySelectorAll(sel)).forEach(el => {
            if (!el || visited.has(el) || !isVisible(el)) return;
            visited.add(el);
            pool.push({ el, selector: sel });
          });
        } catch (_) {}
      });
      const candidate = pool.find(({ el }) => {
        const text = (el.textContent || el.getAttribute('title') || '').trim().toLowerCase();
        return keywords.some(k => text.includes(k));
      });
      if (!candidate) return { clicked: false };
      const { el, selector } = candidate;
      try { el.scrollIntoView({ block: 'center' }); } catch (_) {}
      try { el.click(); } catch (_) {}
      const active = (el.classList && el.classList.contains('addArticleTabOn')) || el.getAttribute('aria-selected') === 'true';
      return { clicked: true, selector, text: (el.textContent || '').trim(), active };
    });
    if (!toggleResult || !toggleResult.clicked) throw new Error('HTML_TAB_NOT_FOUND');
    await sleep(500);
    logDbg('Html tab toggled', toggleResult);
  } catch (e) { logLine('Switch tab error', { error: String(e && e.message || e) }); }

  // Fill via Html textarea only (avoid TinyMCE/iframes to prevent frame detaches)
  try {
    let fillResult = await fillContentInContext(page, cleanedContent);
    if (!fillResult) {
      try {
        const frames = page.frames ? page.frames() : [];
        for (const frame of frames) {
          fillResult = await fillContentInContext(frame, cleanedContent);
          if (fillResult) {
            fillResult.frameUrl = frame.url();
            break;
          }
        }
      } catch (_) {}
    }
    if (!fillResult) throw new Error('CONTENT_INPUT_NOT_FOUND');
    logDbg('Content filled', fillResult);
  } catch (e) {
    let frameInfo = [];
    try { frameInfo = (page.frames ? page.frames() : []).map(f => f.url()).filter(Boolean).slice(0,5); } catch (_) {}
    logLine('Fill editor failed', { error: String(e && e.message || e), frames: frameInfo });
  }

  // Screenshots helper (minimal logging)
  async function takeScreenshot(label, targetPage = page){
    try {
      const fname = `justpaste-${label}-${Date.now()}.png`;
      const fpath = path.join(LOG_DIR, fname);
      if (!targetPage || (typeof targetPage.isClosed === 'function' && targetPage.isClosed())) throw new Error('PAGE_CLOSED');
      await targetPage.screenshot({ path: fpath, fullPage: true });
      logLine('Screenshot', { label, path: fpath });
      return fpath;
    } catch (e) { logLine('Screenshot error', { label, error: String(e && e.message || e) }); return ''; }
  }
  const screenshots = {};
  screenshots.filled = await takeScreenshot('filled');

  // Minimal publish-first flow with shared captcha solver
  try { await page.waitForSelector('.editArticleBottomButtons .publishButton', { timeout: 15000 }); } catch (_) {}
  const startUrl = page.url();
  const navPromise = page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => null);
  let redirectDetected = false;
  let redirectUrl = '';
  let navigationResolved = false;
  try {
    await page.click('.editArticleBottomButtons .publishButton');
    logLine('Publish button clicked', { startUrl });
  } catch (_) {}
  screenshots.after_click = await takeScreenshot('after-click');
  const deadline = Date.now() + 120000; // up to 120s to allow captcha to fully load/solve
  let lastPageCount = 0;
  while (Date.now() < deadline) {
    let captchaSolved = false;
    try {
      const pagesForCaptcha = await browser.pages();
      if (pagesForCaptcha.length !== lastPageCount) {
        logDbg('Window count update', { count: pagesForCaptcha.length });
        lastPageCount = pagesForCaptcha.length;
      }
      for (const candidate of pagesForCaptcha) {
        if (!candidate || (typeof candidate.isClosed === 'function' && candidate.isClosed())) continue;
        let candidateUrl = '';
        try { candidateUrl = candidate.url(); } catch (_) {}
        if (!screenshots.captcha) {
          try {
            const det = await detectCaptcha(candidate);
            if (det && det.found && !screenshots.captcha) {
              screenshots.captcha = await takeScreenshot('captcha-early', candidate);
              logDbg('Captcha presence detected', { candidateUrl, type: det.type, details: det.debug || det.details || null });
            }
          } catch (_) {}
        }
        const solvedRes = await solveIfCaptcha(candidate, logLine, async (label) => await takeScreenshot(label, candidate));
        const solved = !!(solvedRes && (solvedRes === true || solvedRes.solved));
        if (solved) {
          captchaSolved = true;
          if (candidate !== page) {
            logLine('Captcha solved on secondary page', { candidateUrl });
          }
          if (typeof solvedRes === 'object' && solvedRes.screenshot) {
            screenshots.captcha = solvedRes.screenshot;
          }
          await sleep(1200);
          try {
            await page.evaluate(() => {
              const panel = document.querySelector('.captchaPanelMaster .captchaPanel');
              if (panel) {
                const btn = panel.querySelector('button.btn.btn-danger.CaptchaButtonVerify');
                if (btn) btn.click();
              }
            });
          } catch (_) {}
          try {
            const href = await page.evaluate(() => location.href);
            if (/\/edit|justpaste\.it\/?$/.test(href)) {
              await page.evaluate(() => {
                const premium = document.querySelector('.becomePremiumPanel a.btn.btn-sm.btn-outline-danger');
                if (premium) premium.removeAttribute('data-auto-click');
                const btn = document.querySelector('.editArticleBottomButtons .publishButton');
                if (btn) (btn).click();
              });
            }
          } catch (_) {}
          break;
        }
      }
    } catch (_) {}
    if (captchaSolved) break;
    // Check if navigated to an article-like URL
    try {
      const href = await page.evaluate(() => location.href);
      if (href && href !== startUrl && /https?:\/\/(?:www\.)?justpaste\.it\//.test(href) && !/https?:\/\/(?:www\.)?justpaste\.it\/?$/.test(href)) {
        redirectDetected = true;
        redirectUrl = href;
        logLine('Redirect detected', { from: startUrl, to: href });
        break;
      }
    } catch (_) {}
    // If navigation finished, break anyway
    const navDone = await Promise.race([
      navPromise,
      new Promise(r => setTimeout(r, 500))
    ]);
    if (navDone) { navigationResolved = true; break; }
    await sleep(500);
  }

  const currentUrl = page.url();
  logLine('Navigation summary', { startUrl, currentUrl, redirectDetected, redirectUrl, navigationResolved });

  // If we still are on homepage, try to extract article URL from DOM
  async function extractPublishedUrl(p) {
    if (!p || typeof p.evaluate !== 'function') return null;
    return await p.evaluate(() => {
      const collect = new Set();
      const add = (u) => { if (u && typeof u === 'string') collect.add(u.trim()); };
      const isArticleUrl = (s) => {
        if (typeof s !== 'string') return false;
        if (!/^https?:\/\//i.test(s)) return false;
        try {
          const u = new URL(s);
          if (!/^(?:www\.)?justpaste\.it$/i.test(u.hostname)) return false;
          const p = (u.pathname || '/').replace(/\/+/g,'/');
          if (p === '/' || p === '') return false;
          if (/^\/(privacy|privacypolicy|terms|faq|pricing|premium|about)(?:\/|$)/i.test(p)) return false;
          const segs = p.split('/').filter(Boolean);
          return segs.some(seg => /[A-Za-z0-9_-]{3,}/.test(seg));
        } catch { return false; }
      };
      Array.from(document.querySelectorAll('a[href]')).forEach(a => add(a.href));
      const text = document.body ? (document.body.innerText || '') : '';
      const rx = /https?:\/\/(?:www\.)?justpaste\.it\/[A-Za-z0-9][A-Za-z0-9_-]{2,}[^\s"']*/g;
      let m; while ((m = rx.exec(text)) !== null) add(m[0]);
      const cands = Array.from(collect).filter(isArticleUrl).sort((a,b) => b.length - a.length);
      return cands[0] || null;
    });
  }

  // Helper: find article page among all browser tabs
  async function findArticlePage() {
    try {
      const pages = await browser.pages();
      // 1) Prefer page whose current URL already looks like an article
      for (const p of pages) {
        const href = p.url();
        if (/^https?:\/\/(?:www\.)?justpaste\.it\//.test(href) && !/^https?:\/\/(?:www\.)?justpaste\.it\/?$/.test(href)) {
          return { page: p, url: href };
        }
      }
      // 2) Otherwise try to extract candidate URL from DOM of each page
      for (const p of pages) {
        try {
          const cand = await p.evaluate(() => {
            const collect = new Set();
            const add = (u) => { if (u && typeof u === 'string') collect.add(u.trim()); };
            const isArticleUrl = (s) => {
              if (typeof s !== 'string') return false;
              if (!/^https?:\/\//i.test(s)) return false;
              try {
                const u = new URL(s);
                if (!/^(?:www\.)?justpaste\.it$/i.test(u.hostname)) return false;
                const pth = (u.pathname || '/').replace(/\/+/g,'/');
                if (pth === '/' || pth === '') return false;
                if (/^\/(privacy|privacypolicy|terms|faq|pricing|premium|about)(?:\/|$)/i.test(pth)) return false;
                const segs = pth.split('/').filter(Boolean);
                return segs.some(seg => /[A-Za-z0-9_-]{3,}/.test(seg));
              } catch { return false; }
            };
            Array.from(document.querySelectorAll('a[href]')).forEach(a => add(a.href));
            const text = document.body ? (document.body.innerText || '') : '';
            const rx = /https?:\/\/(?:www\.)?justpaste\.it\/[A-Za-z0-9][A-Za-z0-9_-]{2,}[^\s"']*/g;
            let m; while ((m = rx.exec(text)) !== null) add(m[0]);
            const cands = Array.from(collect).filter(isArticleUrl).sort((a,b) => b.length - a.length);
            return cands[0] || null;
          });
          if (cand) return { page: p, url: cand };
        } catch {}
      }
    } catch {}
    return null;
  }

  // Determine final page and URL
  let targetPage = page;
  let publishedUrl = currentUrl;
  const isHomepage = (url) => !url || /^https?:\/\/(?:www\.)?justpaste\.it\/?$/.test(url);
  if (isHomepage(publishedUrl)) {
    try {
      const extractedHere = await extractPublishedUrl(page);
      if (extractedHere) {
        publishedUrl = extractedHere;
        logLine('Article candidate located', { method: 'dom-extract', url: extractedHere });
      }
    } catch (_) {}
  }
  if (isHomepage(publishedUrl)) {
    const found = await findArticlePage();
    if (found && found.url) {
      publishedUrl = found.url;
      targetPage = found.page || page;
      logLine('Article candidate located', { method: 'secondary-tab', url: publishedUrl });
    }
  }
  // If still only homepage URL but we extracted candidate earlier, open it
  if (targetPage && publishedUrl && targetPage.url() !== publishedUrl) {
    try { await targetPage.goto(publishedUrl, { waitUntil: 'networkidle2', timeout: 60000 }).catch(()=>{}); } catch (_) {}
  }
  try {
    if (targetPage && !(targetPage.isClosed && targetPage.isClosed())) {
      screenshots.published = await (async () => await targetPage.screenshot({ path: path.join(LOG_DIR, `justpaste-published-${Date.now()}.png`), fullPage: true }))();
    }
  } catch (e) { logLine('Screenshot error', { label: 'published', error: String(e && e.message || e) }); }
  logLine('Published', { publishedUrl, redirected: Boolean(startUrl && publishedUrl && startUrl !== publishedUrl) });
  await browser.close();
  logLine('Browser closed');
  return { ok: true, network: 'justpaste', publishedUrl, title: titleClean, logFile: LOG_FILE, screenshots };
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

  const res = await publishToJustPaste(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null);
      logLine('Success result', res); console.log(JSON.stringify(res)); process.exit(0);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'justpaste', logFile: LOG_FILE };
      logLine('Run failed', { error: payload.error, stack: e && e.stack });
      console.log(JSON.stringify(payload)); process.exit(1);
    }
  })();
}
