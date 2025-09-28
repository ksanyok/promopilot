
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
    logDbg('Title filled', { selector: titleSel, length: titleVal.length });
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
    logDbg('Html tab toggled');
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
    logDbg('Filled via textarea', { selector: taSel });
  } catch (e) {
    logLine('Fill editor failed', { error: String(e && e.message || e) });
  }

  // Screenshots helper (minimal logging)
  async function takeScreenshot(label){
    try {
      const fname = `justpaste-${label}-${Date.now()}.png`;
      const fpath = path.join(LOG_DIR, fname);
      await page.screenshot({ path: fpath, fullPage: true });
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
  try { await page.click('.editArticleBottomButtons .publishButton'); } catch (_) {}
  screenshots.after_click = await takeScreenshot('after-click');
  const deadline = Date.now() + 90000; // up to 90s
  while (Date.now() < deadline) {
    // Try solve captcha if it appears
    try {
      const det = await detectCaptcha(page);
      if (det && det.found && !screenshots.captcha) {
        screenshots.captcha = await takeScreenshot('captcha');
      }
      const solved = await solveIfCaptcha(page, logLine);
      if (solved) {
        await new Promise(r=>setTimeout(r, 1000));
        // If still on editor, click Publish again to finalize
        try {
          const href = await page.evaluate(() => location.href);
          if (/\/edit|justpaste\.it\/?$/.test(href)) {
            await page.click('.editArticleBottomButtons .publishButton').catch(()=>{});
          }
        } catch (_) {}
      }
    } catch (_) {}
    // Check if navigated to an article-like URL
    try {
      const href = await page.evaluate(() => location.href);
      if (href && href !== startUrl && /https?:\/\/(?:www\.)?justpaste\.it\//.test(href) && !/https?:\/\/(?:www\.)?justpaste\.it\/?$/.test(href)) break;
    } catch (_) {}
    // If navigation finished, break anyway
    const navDone = await Promise.race([
      navPromise,
      new Promise(r => setTimeout(r, 500))
    ]);
    if (navDone) break;
  }

  // If we still are on homepage, try to extract article URL from DOM
  async function extractPublishedUrl(p) {
    return await page.evaluate(() => {
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
  let publishedUrl = page.url();
  if (!publishedUrl || /^https?:\/\/(?:www\.)?justpaste\.it\/?$/.test(publishedUrl)) {
    const found = await findArticlePage();
    if (found && found.url) { publishedUrl = found.url; targetPage = found.page || page; }
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
  logLine('Published', { publishedUrl });
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
