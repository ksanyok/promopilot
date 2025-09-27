'use strict';

// Minimal, clean implementation for Telegraph publishing
// - Generates title, author, and article via AI provider (OpenAI or BYOA)
// - Collects microdata/SEO from the target page to guide content
// - Uses one organic inline link to the target URL
// - Uses <h2> subheadings as requested

const puppeteer = require('puppeteer');
const fetch = require('node-fetch');
const fs = require('fs');
const path = require('path');
const { generateText } = require('./ai_client');

// Simple file logger similar to previous implementation
const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
const LOG_FILE = process.env.PP_LOG_FILE || path.join(
  LOG_DIR,
  `network-telegraph-${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}.log`
);
function ensureDirSync(dir){
  try { if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true }); } } catch(_) {}
}
ensureDirSync(LOG_DIR);
function safeStringify(obj){
  try { return JSON.stringify(obj); } catch(_) { return String(obj); }
}
function logLine(msg, data){
  const line = `[${new Date().toISOString()}] ${msg}${data ? ' ' + safeStringify(data) : ''}\n`;
  try { fs.appendFileSync(LOG_FILE, line); } catch(_) {}
}

async function generateTextWithChat(prompt, aiOptions) {
  // Backward-compatible wrapper to call unified AI client
  // aiOptions: { provider, openaiApiKey, model, temperature, systemPrompt, byoaModel, byoaEndpoint }
  logLine('AI request', { provider: (aiOptions && aiOptions.provider) || process.env.PP_AI_PROVIDER || 'openai', promptPreview: String(prompt || '').slice(0, 160) });
  try {
    const out = await generateText(prompt, aiOptions || {});
    logLine('AI response ok', { length: String(out || '').length });
    return out;
  } catch (e) {
    logLine('AI error', { error: String(e && e.message || e) });
    return '';
  }
}

function stripTags(html) { return String(html || '').replace(/<[^>]+>/g, '').trim(); }
function extractAttr(tagHtml, attr) {
  const m = String(tagHtml || '').match(new RegExp(attr + '\\s*=\\s*([\"\'])(.*?)\\1', 'i'));
  return m ? m[2] : '';
}

// Выделяет финальный ответ из типового вывода Space с блоками Analysis/Response
function extractFinalAnswer(raw){
  let t = String(raw || '').trim();
  if (!t) return '';
  // Попробуем взять часть после последнего "Response:" (с учётом эмодзи/markdown, без глобальных RegExp)
  const lc = t.toLowerCase();
  const rKey = 'response:';
  let pos = lc.lastIndexOf(rKey);
  if (pos !== -1) {
    t = t.slice(pos + rKey.length).trim();
  } else {
    // Если нет "Response:", взять весь текст, убрав возможные заголовки аналитики
    t = t.replace(/^\s{0,3}[*_]{0,3}\s*[*_]{0,3}\s*[^\n\r]{0,40}analysis\s*:\s*.*$/gim, '').trim();
  }
  // Сносим частые разделители
  t = t.replace(/^(?:[-*_]{3,}\s*)+/gmi, '').trim();
  return t;
}

function normalizeTitle(raw){
  let t = extractFinalAnswer(raw);
  t = stripTags(t);
  // Берём весь текст, очищаем
  t = t.replace(/\r?\n/g, ' ').replace(/\s{2,}/g, ' ').trim();
  // Убираем markdown-маркировки и кавычки
  t = t.replace(/^#+\s*/,'').replace(/[*_`~]+/g,'').replace(/["'«»“”„]+/g,'');
  // Убираем точку в конце и обрезаем длину
  t = t.replace(/[.。]+$/,'').trim();
  if (!t) t = 'Untitled';
  if (t.length > 140) t = t.slice(0, 140);
  return t;
}

function normalizeAuthor(raw){
  let a = extractFinalAnswer(raw);
  a = stripTags(a).replace(/\r?\n/g, ' ').replace(/\s{2,}/g, ' ').trim();
  a = a.replace(/["'«»“”„]+/g,'').trim();
  // Оставляем буквы/пробел/дефис
  a = a.replace(/[^A-Za-zА-Яа-яЁё\s\-]/g, '').replace(/\s{2,}/g,' ').trim();
  if (!a) a = 'PromoPilot';
  if (a.length > 40) a = a.slice(0, 40);
  return a;
}

function normalizeContent(raw){
  let c = extractFinalAnswer(raw);
  c = String(c || '').trim();
  // Убираем возможные markdown артефакты в начале
  c = c.replace(/^\s*[*_`~]+\s*/g, '').trim();
  return c;
}

async function extractPageMeta(url) {
  logLine('Fetch page for meta', { url });
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
      const jsonText = (block.match(/>([\s\S]*?)<\/script>/i) || [,''])[1];
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

    logLine('Meta extracted', { title: out.title, descriptionPreview: out.description.slice(0, 160) });
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

function integrateSingleAnchor(html, pageUrl, anchorText) {
  let s = String(html || '');
  // Remove all anchors except those that point to pageUrl
  s = s.replace(/<a\s+([^>]*?)>([\s\S]*?)<\/a>/gi, (full, attrs, text) => {
    const m = String(attrs || '').match(/href=[\"\']([^\"\']+)[\"\']/i);
    const href = m && m[1] ? m[1] : '';
    if (href && href.replace(/\/$/, '') === String(pageUrl).replace(/\/$/, '')) return full; // keep only our link
    return text; // unwrap others
  });

  const linkRe = new RegExp('<a\\s+[^>]*href=[\\"\']' + pageUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[\\"\']', 'i');
  if (linkRe.test(s)) return s; // already present

  // Otherwise, inject into the first sufficiently long paragraph
  const paras = s.match(/<p[\s>][\s\S]*?<\/p>/gi) || [];
  if (paras.length) {
    for (let i = 0; i < paras.length; i++) {
      const p = paras[i];
      const text = stripTags(p);
      if (text.length < 120) continue;
      const injected = p.replace(/<\/p>\s*$/i, ` <a href="${pageUrl}">${anchorText}</a></p>`);
      return s.replace(p, injected);
    }
  }
  // Fallback: prepend link as a separate paragraph under the first heading
  return s.replace(/(<h2[\s>][\s\S]*?<\/h2>)/i, `$1\n<p><a href="${pageUrl}">${anchorText}</a></p>`);
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey, aiProvider) {
  logLine('Publish start', { pageUrl, anchorText, language, aiProvider: aiProvider || process.env.PP_AI_PROVIDER || 'openai' });
  const meta = await extractPageMeta(pageUrl);

  const prompts = {
    title: (
      `Write a concise, specific ${language} title that reflects the topic. ` +
      `No quotes, no trailing dots. Avoid generic placeholders like Introduction/Введение.\n` +
      `Base it on:\nTitle: "${meta.title || ''}"\nDescription: "${meta.description || ''}"\nURL: ${pageUrl}`
    ),
    author: (
      `Suggest a neutral author's name in ${language}. ` +
      `Use ${language} alphabet. One or two words. Reply with the name only.`
    ),
    content: (
      `Write an article in ${language} of at least 3000 characters based on the page ${pageUrl}. ` +
      `Use this context: title: "${meta.title || ''}", description: "${meta.description || ''}".\n` +
      `Requirements:\n` +
      `- Use clear structure: short intro, 3–5 sections with <h2> subheadings, one bulleted list where relevant, and a brief conclusion.\n` +
      `- Include exactly one active link to <a href="${pageUrl}">${anchorText}</a> inside a paragraph in the first half of the article (organically).\n` +
      `- Use only simple HTML tags: <p>, <h2>, <ul>, <li>, <a>, <strong>, <em>, <blockquote>. No images, scripts or inline styles.\n` +
      `- Stay strictly on-topic and do not add unrelated content.`
    )
  };
  logLine('Prompts prepared');

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // AI options based on provider/key
  const aiOptionsBase = {
    provider: (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase(),
    openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '',
    model: process.env.OPENAI_MODEL || undefined,
    systemPrompt: '',
  };

  // Generate title, author, content with small pauses
  const rawTitle = await generateTextWithChat(prompts.title, { ...aiOptionsBase, temperature: 0.7 });
  await sleep(1500);
  const rawAuthor = await generateTextWithChat(prompts.author, { ...aiOptionsBase, temperature: 0.6 });
  await sleep(1500);
  let rawContent = await generateTextWithChat(prompts.content, { ...aiOptionsBase, temperature: 0.8 });

  // Новая нормализация от «болтовни» модели
  const title = normalizeTitle(rawTitle);
  const author = normalizeAuthor(rawAuthor);
  logLine('Generated', { title, author, contentLen: String(rawContent || '').length });

  // Если ИИ не сгенерировал контент — не тратим время на браузер
  if (!rawContent || String(rawContent).trim().length < 200) {
    const err = `AI_CONTENT_EMPTY`; logLine('Publish failed', { error: err });
    throw new Error(err);
  }

  // Очистка контента
  let content = normalizeContent(rawContent);
  // Remove disallowed tags
  content = content.replace(/<(?!\/?(p|h2|ul|li|a|strong|em|blockquote)\b)[^>]*>/gi, '');
  // Wrap free text lines into <p> if no tags present
  if (!/<\s*(p|h2|ul|li|blockquote|a)\b/i.test(content)) {
    const parts = content.split(/\n{2,}/).map(p => p.trim()).filter(Boolean).map(p => `<p>${p}</p>`);
    content = parts.join('\n');
  }
  // Ensure at least one <h2>
  if (!/<h2[\s>]/i.test(content)) {
    // Convert markdown-like headings to <h2>
    content = content.replace(/^[\t ]*##[\t ]+(.+)$/gmi, '<h2>$1</h2>');
  }
  content = integrateSingleAnchor(content, pageUrl, anchorText);

  // Launch Puppeteer with optional explicit executable path
  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });

  const browser = await puppeteer.launch(launchOpts);
  let page;
  try {
    page = await browser.newPage();
    page.setDefaultTimeout(300000); // 5 minutes
    page.setDefaultNavigationTimeout(300000);
    logLine('Goto Telegraph');
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 120000 });

    logLine('Fill title');
    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 300000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(title);

    logLine('Fill author');
    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 300000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author);

    logLine('Fill content');
    await page.evaluate((html) => {
      const el = document.querySelector('p[data-placeholder="Your story..."]');
      if (el) {
        el.innerHTML = html;
      } else {
        const root = document.querySelector('.tl_article .ql-editor') || document.querySelector('div.ql-editor');
        if (!root) throw new Error('Telegraph editor not found');
        root.innerHTML = html;
      }
    }, content);

    logLine('Publish click');
    // Ищем кнопку публикации
    let publishBtn = await page.$('button.publish_button');
    if (!publishBtn) {
      publishBtn = await page.$x("//button[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'),'publish')]").then(arr=>arr[0]).catch(()=>null);
    }
    if (!publishBtn) throw new Error('Publish button not found');

    await publishBtn.click();

    // Ждём появления финального URL статьи (а не главной страницы)
    const articleUrlRegex = /^(https?:\/\/(?:telegra\.ph|graph\.org)\/[^\s#/?]+(?:\.html)?)$/i;
    try {
      await page.waitForFunction((rxStr) => {
        try { return new RegExp(rxStr).test(location.href); } catch { return false; }
      }, { timeout: 300000 }, articleUrlRegex.source);
    } catch (_) {}

    // Доп. попытка: иногда dom уже на статье, но навигация не сработала явно
    const publishedUrl = page.url();
    if (!articleUrlRegex.test(publishedUrl)) {
      // Пытаемся достать canonical
      const can = await page.$eval('link[rel="canonical"]', el => el && el.href || '').catch(()=> '');
      if (articleUrlRegex.test(can)) {
        logLine('Published', { publishedUrl: can });
        const result = { ok: true, network: 'telegraph', publishedUrl: can, title, author, logFile: LOG_FILE };
        logLine('Success result', result);
        return result;
      }
      throw new Error('Publish result page not detected');
    }

    logLine('Published', { publishedUrl });
    const result = { ok: true, network: 'telegraph', publishedUrl, title, author, logFile: LOG_FILE };
    logLine('Success result', result);
    return result;
  } catch (e) {
    try {
      if (page && !page.isClosed()) {
        const ts = Date.now();
        const shot = path.join(LOG_DIR, `telegraph-fail-${ts}.png`);
        const htmlPath = path.join(LOG_DIR, `telegraph-fail-${ts}.html`);
        await page.screenshot({ path: shot, fullPage: true }).catch(() => {});
        const html = await page.content().catch(() => '');
        if (html) { try { fs.writeFileSync(htmlPath, html); } catch {} }
        logLine('Debug saved', { screenshot: shot, html: htmlPath });
      }
    } catch (_) {}
    logLine('Publish failed', { error: String(e && e.message || e), stack: e && e.stack });
    throw e;
  } finally {
    try { if (page) await page.close(); } catch(_) { logLine('Page close failed'); }
    try { await browser.close(); logLine('Browser closed'); } catch(_) { logLine('Browser close failed'); }
  }
}

module.exports = { publish: publishToTelegraph };

// CLI entrypoint for PromoPilot runner (reads PP_JOB from env)
if (require.main === module) {
  (async () => {
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: raw.length });
      const job = JSON.parse(raw);
      logLine('PP_JOB parsed');
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
      const jobModel = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (jobModel) process.env.OPENAI_MODEL = String(jobModel);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'telegraph', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      // If provider is openai, ensure key present; for BYOA allow empty
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'telegraph', logFile: LOG_FILE };
        logLine('Run failed (missing openai key)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }

      const res = await publishToTelegraph(pageUrl, anchor, language, apiKey, provider);
      console.log(JSON.stringify(res));
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'telegraph', logFile: LOG_FILE };
      logLine('Run failed', { error: payload.error, stack: e && e.stack });
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}