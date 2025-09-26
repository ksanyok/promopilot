'use strict';

// Minimal, clean implementation for Telegraph publishing
// - Generates title, author, and article via OpenAI
// - Collects microdata/SEO from the target page to guide content
// - Uses one organic inline link to the target URL
// - Uses <h3> subheadings (Telegraph-compatible)

const puppeteer = require('puppeteer');
const fetch = require('node-fetch');
const fs = require('fs');
const path = require('path');

// Simple file logger similar to previous implementation
const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
const LOG_FILE = process.env.PP_LOG_FILE || path.join(
  LOG_DIR,
  `network-telegraph-${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}.log`
);
function ensureDirSync(dir){
  try { fs.mkdirSync(dir, { recursive: true }); } catch(_) {}
}
ensureDirSync(LOG_DIR);
function safeStringify(obj){
  try { return JSON.stringify(obj); } catch(_) { return String(obj); }
}
function logLine(msg, data){
  const ts = new Date().toISOString();
  const line = `[${ts}] ${msg}` + (data !== undefined ? ` | ${safeStringify(data)}` : '');
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch(_) {}
  // Also mirror to stdout for quick debugging
  try { console.log(line); } catch(_) {}
}

// Unified AI call with logging and small retries
async function generateTextWithAI(prompt, openaiApiKey, kind = 'generic', attempt = 1) {
  const started = Date.now();
  logLine('OpenAI request', { kind, attempt, promptChars: String(prompt || '').length });
  try {
    const r = await fetch('https://api.openai.com/v1/responses', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${openaiApiKey}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ model: 'gpt-5-mini', input: String(prompt || ''), reasoning: { effort: 'low' } })
    });
    const raw = await r.text().catch(()=> '');
    const ms = Date.now() - started;
    let data = null;
    try { data = JSON.parse(raw); } catch(_) {}

    if (!r.ok) {
      logLine('OpenAI response', { kind, attempt, status: r.status, ms, bodyPreview: raw.slice(0, 200) });
      return '';
    }

    let text = '';
    if (data) {
      if (typeof data.output_text === 'string') text = data.output_text;
      else if (Array.isArray(data.output) && data.output[0] && data.output[0].content && data.output[0].content[0] && data.output[0].content[0].text) {
        text = data.output[0].content[0].text;
      } else if (Array.isArray(data.choices) && data.choices[0] && data.choices[0].message && data.choices[0].message.content) {
        text = data.choices[0].message.content;
      }
    }
    text = String(text || '').trim();
    logLine('OpenAI parsed', { kind, attempt, ms, textLen: text.length, textPreview: text.slice(0, 120) });
    return text;
  } catch (e) {
    const ms = Date.now() - started;
    logLine('OpenAI request failed', { kind, attempt, ms, error: String(e && e.message || e) });
    return '';
  }
}

async function aiWithRetries(kind, prompt, key, tries = 3) {
  let out = '';
  for (let i = 1; i <= Math.max(1, tries); i++) {
    out = await generateTextWithAI(prompt, key, kind, i);
    if (out && out.trim().length > 3) return out.trim();
    await new Promise(r => setTimeout(r, 500 * i));
  }
  return String(out || '').trim();
}

function stripTags(html) { return String(html || '').replace(/<[^>]+>/g, '').trim(); }
function extractAttr(tagHtml, attr) {
  const m = String(tagHtml || '').match(new RegExp(attr + '\\s*=\\s*([\"\'])(.*?)\\1', 'i'));
  return m ? m[2] : '';
}

async function extractPageMeta(url) {
  try {
    const r = await fetch(url, {
      method: 'GET',
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; PromoPilot/1.0; +https://example.com/bot)',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'ru,en;q=0.8'
      }
    });
    const html = await r.text();
    const out = { title: '', description: '' };

    const t = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
    const titleTag = t ? stripTags(t[1]) : '';

    const metaDesc = html.match(/<meta[^>]+name=[\"\']description[\"\'][^>]*>/i);
    const metaDescVal = metaDesc ? extractAttr(metaDesc[0], 'content') : '';

    const ogTitleTag = html.match(/<meta[^>]+property=[\"\']og:title[\"\'][^>]*>/i);
    const ogTitle = ogTitleTag ? extractAttr(ogTitleTag[0], 'content') : '';
    const ogDescTag = html.match(/<meta[^>]+property=[\"\']og:description[\"\'][^>]*>/i);
    const ogDesc = ogDescTag ? extractAttr(ogDescTag[0], 'content') : '';

    const twTitleTag = html.match(/<meta[^>]+name=[\"\']twitter:title[\"\'][^>]*>/i);
    const twTitle = twTitleTag ? extractAttr(twTitleTag[0], 'content') : '';
    const twDescTag = html.match(/<meta[^>]+name=[\"\']twitter:description[\"\'][^>]*>/i);
    const twDesc = twDescTag ? extractAttr(twDescTag[0], 'content') : '';

    const h1 = html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);
    const h1Text = h1 ? stripTags(h1[1]) : '';

    // JSON-LD
    let ldTitle = '', ldDesc = '';
    const ldBlocks = html.match(/<script[^>]+type=[\"\']application\/ld\+json[\"\'][^>]*>[\s\S]*?<\/script>/gi) || [];
    for (const block of ldBlocks) {
      const jsonText = (block.match(/>([\s\S]*?)<\/script>/i) || [, ''])[1];
      try {
        const data = JSON.parse(jsonText);
        const arr = Array.isArray(data) ? data : [data];
        for (const item of arr) {
          const candTitle = item.headline || item.name || (item.article && item.article.headline) || '';
          const candDesc = item.description || (item.article && item.article.description) || '';
          if (!ldTitle && candTitle) ldTitle = String(candTitle);
          if (!ldDesc && candDesc) ldDesc = String(candDesc);
        }
      } catch (_) {}
      if (ldTitle && ldDesc) break;
    }

    out.title = (ldTitle || ogTitle || twTitle || h1Text || titleTag || '').trim();
    out.description = (ldDesc || ogDesc || twDesc || metaDescVal || '').trim();

    // Clean noisy suffixes like categories separated by dashes
    out.title = out.title.replace(/[\n\r\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();
    out.description = out.description.replace(/[\n\r\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();

    logLine('Meta extracted', { title: out.title.slice(0, 100), desc: out.description.slice(0, 140) });
    return out;
  } catch (e) {
    logLine('Meta extract failed', { error: String(e && e.message || e) });
    return { title: '', description: '' };
  }
}

function cleanTitle(t) {
  t = String(t || '').trim();
  t = t.replace(/["'«»“”„]+/g, '').replace(/[.]+$/g, '').trim();
  if (!t) t = 'Untitled';
  return t;
}

function toTelegraphHtml(raw) {
  let s = String(raw || '').trim();
  if (!s) return '';
  // Normalize headings to <h3> (Telegraph uses h3)
  s = s.replace(/<h1\b[^>]*>/gi, '<h3>');
  s = s.replace(/<\/h1>/gi, '</h3>');
  s = s.replace(/<h2\b[^>]*>/gi, '<h3>');
  s = s.replace(/<\/h2>/gi, '</h3>');
  // Basic cleanup
  s = s.replace(/<br\s*\/?\s*>/gi, '');
  // Allow only a basic set of tags; leave text otherwise
  // Note: Keep <p>, <h3>, <ul>, <ol>, <li>, <a>, <strong>, <em>, <blockquote>
  return s;
}

function ensureInlineAnchorInFirstParagraph(html, pageUrl, anchorText) {
  let s = String(html || '');
  const escapeRegExp = (str) => String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const hrefRe = new RegExp(`<a\\s+[^>]*href=[\"\']${escapeRegExp(pageUrl)}[\"\']`, 'i');

  // If multiple anchors exist, keep the first, remove the rest
  if (hrefRe.test(s)) {
    const anchorRe = new RegExp('(<a[^>]+href=["\']' + escapeRegExp(pageUrl) + '["\'][^>]*>[^<]*<\\/a>)', 'i');
    const parts = s.split(anchorRe);
    if (parts.length > 3) {
      let seen = false;
      s = parts.map(p => {
        if (/<a/i.test(p)) {
          if (!seen) { seen = true; return p; }
          return p.replace(/<a[^>]+>([^<]*)<\/a>/gi, '$1');
        }
        return p;
      }).join('');
    }
    return s;
  }

  // Insert into first <p>
  const pMatch = s.match(/<p[^>]*>[\s\S]*?<\/p>/i);
  if (pMatch) {
    const full = pMatch[0];
    const inner = full.replace(/^<p[^>]*>/i, '').replace(/<\/p>$/i, '');
    let injected = inner;
    const dot = injected.indexOf('.');
    if (dot !== -1 && dot < 300) {
      injected = injected.slice(0, dot + 1) + ` <a href="${pageUrl}">${anchorText}</a>` + injected.slice(dot + 1);
    } else {
      injected = ` <a href="${pageUrl}">${anchorText}</a> ` + injected;
    }
    s = s.replace(full, `<p>${injected}</p>`);
    return s;
  }
  return `<p><a href="${pageUrl}">${anchorText}</a></p>\n` + s;
}

async function maybeRephraseTitleIfCopied(metaTitle, currentTitle, language, openaiApiKey) {
  const a = String(metaTitle || '').toLowerCase().trim();
  const b = String(currentTitle || '').toLowerCase().trim();
  if (!a || !b) return currentTitle;
  const tooSimilar = a === b || a.includes(b) || b.includes(a);
  if (!tooSimilar) return currentTitle;
  const prompt = (
    `Перефразируй заголовок (${language}) так, чтобы он не повторял исходный, ` +
    `был конкретным и без кавычек и точки в конце.\nИсходный: "${currentTitle}"`
  );
  const re = await aiWithRetries('title_rephrase', prompt, openaiApiKey, 2);
  const cleaned = cleanTitle(re) || currentTitle;
  if (cleaned !== currentTitle) logLine('Title rephrased', { from: currentTitle.slice(0, 80), to: cleaned.slice(0, 80) });
  return cleaned;
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey) {
  logLine('Publish start', { pageUrl, language });
  const meta = await extractPageMeta(pageUrl);

  // Generate organic anchor text if missing or looks like URL
  let anchorTextEffective = String(anchorText || '').trim();
  if (!anchorTextEffective || /^https?:/i.test(anchorTextEffective)) {
    const anchorPrompt = (
      `Сгенерируй короткий якорный текст (2–5 слов) на языке ${language}, ` +
      `который органично впишется в статью по теме страницы ${pageUrl}. ` +
      `Только слова, без кавычек и без URL. Примеры формата: "подробный обзор", "что нового", "подробности здесь".`
    );
    const aiAnchor = await aiWithRetries('anchor', anchorPrompt, openaiApiKey, 3);
    anchorTextEffective = (aiAnchor || '').split(/\r?\n/)[0].replace(/["'«»“”„]+/g, '').trim() || 'подробности здесь';
  }

  const simpleRules = `Правила форматирования:\n- Подзаголовки — только <h3>. 4–6 подзаголовков.\n- Абзацы — <p>. Без пустых <p> и без <br>.\n- Списки — <ul>/<ol> с <li>.\n- Ровно ОДНА органичная ссылка в одном из первых 3 абзацев: <a href=\"${pageUrl}\">${anchorTextEffective}</a>. Никаких других ссылок.`;

  const prompts = {
    title: (
      `Сгенерируй новый заголовок (${language}) для статьи по теме страницы ниже.\n` +
      `Не копируй исходный Title дословно, перефразируй и сделай понятным и конкретным. Без кавычек и точки в конце.\n` +
      `Контекст:\nTitle: "${meta.title || ''}"\nDescription: "${meta.description || ''}"\nURL: ${pageUrl}`
    ),
    author: (
      `Сгенерируй нейтральное имя автора на языке ${language}. 1–2 слова. Ответ должен содержать только имя автора без кавычек.`
    ),
    content: (
      `Сгенерируй оригинальную article-разметку HTML на языке ${language} объёмом не менее 3000 знаков по теме страницы: ${pageUrl}.\n` +
      `Контекст: title: "${meta.title || ''}", description: "${meta.description || ''}".\n` +
      `${simpleRules}\n` +
      `Разрешённые теги: <p>, <h3>, <ul>, <ol>, <li>, <a>, <strong>, <em>, <blockquote>. Без картинок, без инлайновых стилей.`
    )
  };

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const rawTitle = await aiWithRetries('title', prompts.title, openaiApiKey, 3);
  await sleep(250);
  const rawAuthor = await aiWithRetries('author', prompts.author, openaiApiKey, 3);
  await sleep(250);
  const rawContent = await aiWithRetries('content', prompts.content, openaiApiKey, 3);

  logLine('AI outputs', { titleLen: rawTitle.length, authorLen: rawAuthor.length, contentLen: rawContent.length, anchorText: anchorTextEffective });

  let title = cleanTitle(rawTitle);
  title = await maybeRephraseTitleIfCopied(meta.title, title, language, openaiApiKey);
  const author = String(rawAuthor || '').split(/\r?\n/)[0].replace(/["'«»“”„]+/g, '').trim() || 'PromoPilot';

  let content = toTelegraphHtml(rawContent);
  let usedFallback = false;
  if (!content) {
    usedFallback = true;
    const safeTitle = title !== 'Untitled' ? title : 'Обзор и ключевые моменты';
    content = [
      `<h3>${safeTitle}</h3>`,
      `<p>Этот материал основан на открытых данных страницы и кратко описывает ключевые моменты и преимущества решения.</p>`,
      `<p>Подробнее см. в материале <a href="${pageUrl}">${anchorTextEffective}</a>, где приводятся практические детали и контекст.</p>`,
      `<h3>Основные особенности</h3>`,
      `<ul><li>Краткое описание ценности</li><li>Сценарии применения</li><li>Полезные выводы</li></ul>`,
      `<h3>Итоги</h3>`,
      `<p>Сводя воедино, подход демонстрирует практическую эффективность и зрелость технологии для повседневных задач.</p>`
    ].join('\n');
  }

  // Ensure single inline anchor presence in early paragraph
  content = ensureInlineAnchorInFirstParagraph(content, pageUrl, anchorTextEffective);
  logLine('Content prepared', { usedFallback, hasAnchor: /<a\s+[^>]*href=/.test(content), contentPreview: content.slice(0, 160) });

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  let effectiveExecPath = execPath;
  try {
    const bad = !execPath || !fs.existsSync(execPath) || (/linux/i.test(execPath) && process.platform === 'darwin');
    if (bad) effectiveExecPath = '';
  } catch(_) { effectiveExecPath = ''; }
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (effectiveExecPath) launchOpts.executablePath = effectiveExecPath;
  logLine('Launching browser', { executablePath: effectiveExecPath || 'default' });

  const browser = await puppeteer.launch(launchOpts);
  let page;
  try {
    page = await browser.newPage();
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 120000 });

    // Title
    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 60000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(title);

    // Author
    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 60000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author);

    // Content: always write into the Quill editor root, not the placeholder <p>
    await page.waitForSelector('.tl_article .ql-editor, div.ql-editor', { timeout: 60000 });
    await page.evaluate((html) => {
      const root = document.querySelector('.tl_article .ql-editor') || document.querySelector('div.ql-editor');
      if (!root) throw new Error('Telegraph editor not found');
      root.innerHTML = html;
      // Trigger change so Telegraph registers content
      const evt = new InputEvent('input', { bubbles: true, cancelable: true });
      root.dispatchEvent(evt);
      try {
        // Nudge Quill to register change
        root.focus();
        document.execCommand && document.execCommand('insertText', false, ' ');
        document.execCommand && document.execCommand('delete', false);
      } catch(_) {}
    }, content);

    // Scroll to bottom to ensure the Publish button is in view
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));

    // Publish
    const oldUrl = page.url();
    await page.evaluate(() => {
      const candidates = Array.from(document.querySelectorAll('button, a'));
      const btn = candidates.find(b => /publish|опубликовать/i.test((b.textContent || '').trim()))
        || document.querySelector('button.publish_button, button.button.primary, button.button');
      if (!btn) throw new Error('Publish button not found');
      (btn).click();
    });

    // Wait for resulting article page
    const urlRegex = /^https?:\/\/telegra\.ph\/[A-Za-z0-9\-_%]+-\d{2}-\d{2}(?:-\d{2})?\/?$/;

    let targetPage = null;
    try {
      const maybeTarget = await Promise.race([
        (async () => {
          try {
            await page.waitForFunction((re) => new RegExp(re).test(location.href), { timeout: 120000 }, urlRegex.source);
            return null;
          } catch { return null; }
        })(),
        (async () => {
          try {
            const t = await browser.waitForTarget(t => {
              const u = t.url();
              return urlRegex.test(u);
            }, { timeout: 120000 });
            return t || null;
          } catch { return null; }
        })()
      ]);
      if (maybeTarget && typeof maybeTarget.page === 'function') {
        try { targetPage = await maybeTarget.page(); } catch(_) { targetPage = null; }
      }
    } catch (_) { /* swallow */ }

    const finalPage = targetPage || page;

    try { await finalPage.waitForFunction((re) => new RegExp(re).test(location.href), { timeout: 60000 }, urlRegex.source); } catch(_) {}

    const publishedUrl = finalPage.url();
    if (!urlRegex.test(publishedUrl)) {
      throw new Error(`Unexpected publish URL: ${publishedUrl}`);
    }
    logLine('Published', { publishedUrl });

    return { ok: true, network: 'telegraph', publishedUrl, title, author, logFile: LOG_FILE };
  } catch (e) {
    logLine('Publish failed', { error: String(e && e.message || e) });
    throw e;
  } finally {
    try { if (page) await page.close(); } catch(_) {}
    try { await browser.close(); } catch(_) {}
  }
}

module.exports = { publish: publishToTelegraph };

// CLI entrypoint for PromoPilot runner (reads PP_JOB from env)
if (require.main === module) {
  (async () => {
    try {
      const raw = process.env.PP_JOB || '{}';
      const job = JSON.parse(raw);
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      if (!pageUrl || !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url or openaiApiKey missing', network: 'telegraph', logFile: LOG_FILE };
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      const res = await publishToTelegraph(pageUrl, anchor, language, apiKey);
      console.log(JSON.stringify(res));
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'telegraph', logFile: LOG_FILE };
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}
