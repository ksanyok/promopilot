'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
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

  // Try to locate the add article editor (supports Html tab)
  try { await page.waitForSelector('.editArticleMiddle .tabsBar', { timeout: 15000 }); } catch(_) {}

  // Set title first (input.titleInput)
  try {
    await page.waitForSelector('.editArticleMiddle input.titleInput', { timeout: 15000 });
    await page.click('.editArticleMiddle input.titleInput', { clickCount: 3 }).catch(()=>{});
    await page.keyboard.press('Backspace').catch(()=>{});
    await page.keyboard.type(titleClean || (topicTitle || anchorText), { delay: 10 });
  } catch (e) { logLine('Title fill error', { error: String(e && e.message || e) }); }

  // Switch to Html tab (button with text 'Html' has class addArticleTabOff when inactive)
  try {
    const htmlTabSelector = '.editArticleMiddle .tabsBar a';
    await page.waitForSelector(htmlTabSelector, { timeout: 15000 });
    // Click Html tab
    await page.evaluate(() => {
      const tabs = Array.from(document.querySelectorAll('.editArticleMiddle .tabsBar a'));
      const html = tabs.find(a => (a.textContent||'').trim().toLowerCase() === 'html');
      if (html) { html.click(); }
    });
    await sleep(400);
    // Verify Html tab active; retry once if not
    const isHtmlActive = await page.evaluate(() => {
      const tabs = Array.from(document.querySelectorAll('.editArticleMiddle .tabsBar a'));
      const html = tabs.find(a => (a.textContent||'').trim().toLowerCase() === 'html');
      return !!(html && html.classList.contains('addArticleTabOn'));
    });
    if (!isHtmlActive) {
      await page.evaluate(() => {
        const tabs = Array.from(document.querySelectorAll('.editArticleMiddle .tabsBar a'));
        const html = tabs.find(a => (a.textContent||'').trim().toLowerCase() === 'html');
        if (html) { html.click(); }
      });
      await sleep(400);
    }
  } catch (e) { logLine('Switch tab error', { error: String(e && e.message || e) }); }

  // Helper: wait for any of selectors
  async function waitForAny(page, selectors, totalTimeout = 20000, poll = 300) {
    const start = Date.now();
    while ((Date.now() - start) < totalTimeout) {
      for (const sel of selectors) {
        const h = await page.$(sel);
        if (h) return sel;
      }
      await page.waitForTimeout(poll);
    }
    throw new Error('none of selectors appeared: ' + selectors.join(', '));
  }

  // Fill TinyMCE via API if possible, else via iframe; fallback to textarea as last resort, then re-sync
  try {
    // 1) Try API (tinymce/tinyMCE)
    const apiUsed = await page.evaluate((html) => {
      try {
        const t1 = (window.tinymce && typeof window.tinymce.get === 'function') ? window.tinymce : null;
        const t2 = (window.tinyMCE && typeof window.tinyMCE.get === 'function') ? window.tinyMCE : null;
        const api = t1 || t2;
        if (api) {
          const ed = api.get('tinyMCEEditor');
          if (ed && typeof ed.setContent === 'function') {
            ed.setContent(html);
            try { ed.fire && ed.fire('change'); } catch(_) {}
            return 'api';
          }
        }
      } catch(_) {}
      return 'none';
    }, cleanedContent);
    if (apiUsed === 'api') { logLine('Filled via TinyMCE API'); }
    else {
      // 2) Prefer iframe (wait up to ~12s), then fallback to textarea
      let iframeSel = '';
      const iframeCandidates = ['iframe#tinyMCEEditor_ifr', 'iframe.tox-edit-area__iframe', '#htmlAreaDIV iframe'];
      const start = Date.now();
      while ((Date.now() - start) < 12000 && !iframeSel) {
        for (const s of iframeCandidates) { if (await page.$(s)) { iframeSel = s; break; } }
        if (!iframeSel) { await sleep(300); }
      }
      if (iframeSel) {
        const fh = await page.$(iframeSel); const fr = await fh.contentFrame();
        await fr.waitForSelector('body#tinymce, body', { timeout: 10000 });
        await fr.evaluate((html) => { document.body.innerHTML = html; }, cleanedContent);
        logLine('Filled via iframe', { selector: iframeSel });
      } else {
        // 3) Fallback: textarea (hidden) + events
        const taSel = (await page.$('textarea#tinyMCEEditor')) ? 'textarea#tinyMCEEditor' : 'textarea.mainTextarea';
        await page.$eval(taSel, (el, val) => {
          el.value = val;
          const ev = (t) => { try { el.dispatchEvent(new Event(t, { bubbles: true })); } catch(_) {} };
          ev('input'); ev('change');
        }, cleanedContent);
        logLine('Filled via textarea', { selector: taSel });
        // 4) Post-fallback: if iframe appears soon after, push content into it to ensure sync
        await sleep(800);
        for (let i=0; i<8; i++) {
          for (const s of iframeCandidates) {
            const h = await page.$(s);
            if (h) {
              const fr = await h.contentFrame();
              try {
                await fr.waitForSelector('body#tinymce, body', { timeout: 2000 });
                await fr.evaluate((html) => { document.body.innerHTML = html; }, cleanedContent);
                logLine('Resynced via iframe after textarea', { selector: s });
                i = 99; break;
              } catch(_) {}
            }
          }
          await sleep(250);
        }
      }
    }
  } catch (e) {
    logLine('Fill editor failed', { error: String(e && e.message || e) });
  }

  // Ensure editor change is registered
  try {
    await page.focus('.editArticleMiddle input.titleInput');
    await page.keyboard.press('Tab').catch(()=>{});
    await sleep(200);
  } catch(_) {}

  // Extra: if page still on editor after publish try, retry once with re-toggling Html tab
  async function doPublish() {
    const startUrl = page.url();
    // Ensure button exists (robust to reflows)
    for (let i=0; i<40; i++) {
      const btn = await page.$('.editArticleBottomButtons .publishButton');
      if (btn) break; await sleep(250);
    }
    // Click via DOM to avoid stale element/frame linkage
    await page.evaluate(() => {
      const btn = document.querySelector('.editArticleBottomButtons .publishButton');
      if (btn) btn.click();
    });
    // Wait for URL change or navigation event (whichever comes first)
    try {
      await Promise.race([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 }),
        page.waitForFunction((u) => location.href !== u, { timeout: 90000 }, startUrl)
      ]);
    } catch(_) {}
    const nowUrl = page.url();
    if (nowUrl === startUrl) {
      throw new Error('PUBLISH_NO_NAVIGATION');
    }
  }
  try { await doPublish(); }
  catch (e) {
    logLine('Publish attempt failed, retrying after toggling Html', { error: String(e && e.message || e) });
    try {
      const htmlTabSelector = '.editArticleMiddle .tabsBar a';
      const tabs = await page.$$(htmlTabSelector);
      for (const t of tabs) {
        const text = (await page.evaluate(el => (el.textContent || '').trim(), t)).toLowerCase();
        if (text === 'editor' || text === 'html') { await t.click(); await sleep(150); }
      }
    } catch(_) {}
    await sleep(300);
    await doPublish();
  }

  const publishedUrl = page.url();
  logLine('Published', { publishedUrl });
  await browser.close();
  logLine('Browser closed');
  return { ok: true, network: 'justpaste', publishedUrl, title: titleClean, logFile: LOG_FILE };
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
