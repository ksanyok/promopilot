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

// Unified AI call: try Responses API first, fallback-friendly parsing
async function generateTextWithAI(prompt, openaiApiKey) {
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
    if (!r.ok) {
      logLine('OpenAI HTTP error', { status: r.status, statusText: r.statusText, body: raw.slice(0, 200) });
      return '';
    }

    let data = null;
    try { data = JSON.parse(raw); } catch(_) {}

    let text = '';
    if (data) {
      if (typeof data.output_text === 'string') text = data.output_text;
      else if (Array.isArray(data.output) && data.output[0] && data.output[0].content && data.output[0].content[0] && data.output[0].content[0].text) {
        text = data.output[0].content[0].text;
      } else if (Array.isArray(data.choices) && data.choices[0] && data.choices[0].message && data.choices[0].message.content) {
        text = data.choices[0].message.content;
      }
    }
    return String(text || '').trim();
  } catch (e) {
    logLine('OpenAI request failed', { error: String(e && e.message || e) });
    return '';
  }
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

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey) {
  logLine('Publish start', { pageUrl, language });
  const meta = await extractPageMeta(pageUrl);

  const simpleRules = `Правила форматирования:\n- Используй подзаголовки только тегом <h3> (не <h2>). Минимум 3 подзаголовка.\n- Обычный текст — в <p>. Без пустых <p> и без <br> для отступов.\n- Списки — <ul>/<ol> с <li>.\n- Одна органичная ссылка внутри предложения: <a href="${pageUrl}">${anchorText}</a>. Не добавляй другие ссылки.`;

  const prompts = {
    title: (
      `Напиши лаконичный и конкретный заголовок (${language}). Без кавычек и точки в конце.\n` +
      `Ориентируйся на:\nTitle: "${meta.title || ''}"\nDescription: "${meta.description || ''}"\nURL: ${pageUrl}`
    ),
    author: (
      `Предложи нейтральное имя автора на языке ${language}.` +
      ` Используй алфавит ${language}. 1–2 слова. Ответь только именем.`
    ),
    content: (
      `Подготовь статью на языке ${language} объёмом не менее 3000 знаков по странице: ${pageUrl}.\n` +
      `Контекст: title: "${meta.title || ''}", description: "${meta.description || ''}".\n` +
      `${simpleRules}\n` +
      `Разметка HTML только: <p>, <h3>, <ul>, <ol>, <li>, <a>, <strong>, <em>, <blockquote>. Без картинок и стилей.`
    )
  };

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const rawTitle = await generateTextWithAI(prompts.title, openaiApiKey);
  await sleep(400);
  const rawAuthor = await generateTextWithAI(prompts.author, openaiApiKey);
  await sleep(400);
  const rawContent = await generateTextWithAI(prompts.content, openaiApiKey);

  const title = cleanTitle(rawTitle);
  const author = String(rawAuthor || '').split(/\r?\n/)[0].replace(/["'«»“”„]+/g, '').trim() || 'PromoPilot';

  let content = toTelegraphHtml(rawContent);
  if (!content) {
    // Simple fallback to avoid empty articles
    const safeTitle = title !== 'Untitled' ? title : (meta.title || 'Обзор и ключевые моменты');
    content = [
      `<h3>${safeTitle}</h3>`,
      `<p>Этот материал основан на открытых данных страницы и кратко описывает ключевые моменты и преимущества решения.</p>`,
      `<p>Подробнее см. в материале <a href="${pageUrl}">${anchorText}</a>, где приводятся практические детали и контекст.</p>`,
      `<h3>Основные особенности</h3>`,
      `<ul><li>Краткое описание ценности</li><li>Сценарии применения</li><li>Полезные выводы</li></ul>`,
      `<h3>Итоги</h3>`,
      `<p>Сводя воедино, подход демонстрирует практическую эффективность и зрелость технологии для повседневных задач.</p>`
    ].join('\n');
  }

  // Ensure we have at least one inline anchor to pageUrl
  const escapeRegExp = (str) => String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const hrefRe = new RegExp(`<a\\s+[^>]*href=[\"\']${escapeRegExp(pageUrl)}[\"\']`, 'i');
  if (!hrefRe.test(content)) {
    content += `\n<p>Подробнее: <a href="${pageUrl}">${anchorText}</a></p>`;
  }

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default' });

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
    }, content);

    // Publish
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 120000 }),
      page.evaluate(() => {
        let btn = document.querySelector('button.publish_button, button.button.primary, button.button');
        if (!btn) {
          const buttons = Array.from(document.querySelectorAll('button'));
          btn = buttons.find(b => /publish/i.test(b.textContent || '')) || null;
        }
        if (!btn) throw new Error('Publish button not found');
        btn.click();
      })
    ]);

    const publishedUrl = page.url();
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
