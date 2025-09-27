'use strict';

// Ultra-minimal Telegraph publisher: generate title, author, and content via ai_client.js and publish.
// No language mapping, no microdata fetching, no HTML post-processing.

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { generateText } = require('./ai_client');

// Simple file logger (one log file per process run)
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

async function generateTextWithChat(prompt, opts) {
  // Thin wrapper over our unified AI client with logging
  const provider = (opts && opts.provider) || process.env.PP_AI_PROVIDER || 'openai';
  logLine('AI request', { provider, promptPreview: String(prompt||'').slice(0,160) });
  try {
    const out = await generateText(String(prompt || ''), opts || {});
    logLine('AI response ok', { length: String(out||'').length });
    return out;
  } catch (e) {
    logLine('AI error', { error: String(e && e.message || e) });
    throw e;
  }
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const aiOpts = {
    provider,
    openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '',
    model: process.env.OPENAI_MODEL || undefined,
    temperature: 0.2
  };
  logLine('Publish start', { pageUrl, anchorText, language, provider });

  const meta = pageMeta || {};
  const pageLang = language || meta.lang || 'ru';
  const topicTitle = (meta.title || '').toString().trim();
  const topicDesc = (meta.description || '').toString().trim();
  const region = (meta.region || '').toString().trim();
  const extraNote = wish ? `\nNote (use if helpful): ${wish}` : '';

  const prompts = {
    title: `На ${pageLang} сформулируй чёткий конкретный заголовок по теме: ${topicTitle || anchorText}${topicDesc ? ' — ' + topicDesc : ''}. Укажи фокус: ${anchorText}.\n` +
      `Требования: без кавычек и эмодзи, без упоминания URL, 6–12 слов. Ответь только заголовком.`,
    author: `Предложи нейтральное имя автора на ${pageLang} (1–2 слова).\n` +
            `Constraints: reply with the name only (no extra words), no emojis, no quotes, no punctuation except spaces or hyphen.`,
    content:
      `Напиши статью на ${pageLang} (>=3000 знаков) по теме: ${topicTitle || anchorText}${topicDesc ? ' — ' + topicDesc : ''}.${region ? ' Регион: ' + region + '.' : ''}${extraNote}\n` +
      `Требования:\n` +
      `- Ровно одна активная ссылка с анкором "${anchorText}" как <a href="${pageUrl}">${anchorText}</a> — естественно в первой половине текста.\n` +
      `- Только простой HTML: <p> абзацы и <h2> подзаголовки. Без markdown и кода.\n` +
      `- 3–5 смысловых секций и короткое заключение.\n` +
      `- Больше никаких ссылок или URL.\n` +
      `Ответь только телом статьи.`
  };
  logLine('Prompts prepared');

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  const rawTitle = await generateTextWithChat(prompts.title, { ...aiOpts, systemPrompt: 'Только финальный заголовок. Без кавычек, эмодзи и пояснений.', keepRaw: true });
  await sleep(1000);
  const rawAuthor = await generateTextWithChat(prompts.author, { ...aiOpts, systemPrompt: 'Только имя автора (1–2 слова). Без кавычек, эмодзи и пояснений.', keepRaw: true });
  await sleep(1000);
  const rawContent = await generateTextWithChat(prompts.content, { ...aiOpts, systemPrompt: 'Только тело статьи в простом HTML (<p>, <h2>). Без markdown и пояснений.', keepRaw: true });

  // Clean via global cleaner (mirrors ai_client behavior) for debug visibility
  const { cleanLLMOutput } = require('./ai_client');
  const titleClean = cleanLLMOutput(rawTitle);
  const authorClean = cleanLLMOutput(rawAuthor);
  const content = cleanLLMOutput(rawContent);
  logLine('AI raw/clean', {
    title: { rawPrev: String(rawTitle||'').slice(0,120), cleanPrev: String(titleClean||'').slice(0,120) },
    author: { rawPrev: String(rawAuthor||'').slice(0,120), cleanPrev: String(authorClean||'').slice(0,120) },
    content: { rawPrev: String(rawContent||'').slice(0,120), cleanPrev: String(content||'').slice(0,120) }
  });

  // Minimal cleanup only (global cleanup happens in ai_client)
  let title = String(titleClean || '').replace(/^\s*[\"'«»]+|[\"'«»]+\s*$/g, '').replace(/^\*+|\*+$/g,'').trim();
  let author = String(authorClean || '').replace(/[\"'«»]/g, '').trim();
  if (author) author = author.split(/\s+/).slice(0,2).join(' ');
  // Minimal fallbacks (no hardcoded names):
  if (!title || title.replace(/[*_\-\s]+/g,'') === '') {
    title = topicTitle || anchorText;
  }
  if (!author) {
    try {
      const retryAuthor = await generateTextWithChat(`Имя автора на ${pageLang} нейтральное (1–2 слова). Ответь только именем, без кавычек и эмодзи.`, { ...aiOpts, systemPrompt: 'Только имя автора (1–2 слова). Без кавычек, эмодзи и пояснений.' });
      author = String(retryAuthor || '').replace(/[\"'«»]/g, '').trim().split(/\s+/).slice(0,2).join(' ');
    } catch (_) { /* leave empty if still none */ }
  }

  // Launch browser and publish
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
  logLine('Goto Telegraph');
  await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2' });

  logLine('Fill title');
  await page.waitForSelector('h1[data-placeholder="Title"]');
  await page.click('h1[data-placeholder="Title"]');
  await page.keyboard.type(title, { delay: 10 });

  logLine('Fill author');
  await page.waitForSelector('address[data-placeholder="Your name"]');
  await page.click('address[data-placeholder="Your name"]');
  await page.keyboard.type(author, { delay: 10 });

  logLine('Fill content');
  // Ensure editor is present (Telegraph uses Quill: .ql-editor)
  const editorSelector = '.tl_article_content .ql-editor, article .tl_article_content .ql-editor, article .ql-editor, .ql-editor';
  await page.waitForSelector(editorSelector);
  await page.click('h1[data-placeholder="Title"]'); // small nudge to ensure editor initialized
  let cleanedContent = String(content || '').trim();
  // Content: drop a single leading heading (h1/h2/h3) if present to avoid Telegraph mistaking it as page title
  try {
    const before = cleanedContent;
    const after = before.replace(/^[\s\uFEFF\xA0]*<h[1-3][^>]*>.*?<\/h[1-3]>\s*/is, '');
    if (after !== before) {
      cleanedContent = after;
      logLine('Content normalized', { removedLeadingHeading: true });
    }
  } catch(_) {}
  await page.evaluate((html) => {
    const root = document.querySelector('.tl_article_content .ql-editor') || document.querySelector('article .tl_article_content .ql-editor') || document.querySelector('article .ql-editor') || document.querySelector('.ql-editor');
    if (root) {
      root.innerHTML = html || '<p></p>';
      try {
        const evt = new InputEvent('input', { bubbles: true });
        root.dispatchEvent(evt);
      } catch(_) {
        try { root.dispatchEvent(new Event('input', { bubbles: true })); } catch(__) {}
      }
    }
  }, cleanedContent);

  // Force-set fields to exact values and verify read-back
  const forceSetEditable = async (selector, value) => {
    try {
      await page.focus(selector);
      // Try select-all via Ctrl/Cmd+A then replace
      try { await page.keyboard.down('Control'); await page.keyboard.press('KeyA'); await page.keyboard.up('Control'); } catch(_) {}
      try { await page.keyboard.down('Meta'); await page.keyboard.press('KeyA'); await page.keyboard.up('Meta'); } catch(_) {}
      await page.keyboard.press('Backspace');
      await page.keyboard.type(value, { delay: 10 });
      // Fallback: set via DOM and dispatch input
      const ok = await page.$eval(selector, (el, val) => {
        const txt = (el.innerText||'').trim();
        if (txt !== val) {
          try {
            el.textContent = val;
            el.dispatchEvent(new InputEvent('input', { bubbles: true }));
            return true;
          } catch { return false; }
        }
        return true;
      }, value);
      return ok;
    } catch (e) {
      logLine('forceSetEditable error', { selector, error: String(e && e.message || e) });
      return false;
    }
  };
  await forceSetEditable('h1[data-placeholder="Title"]', title);
  await forceSetEditable('address[data-placeholder="Your name"]', author);
  // Read back for logs
  try {
    const rbTitle = await page.$eval('h1[data-placeholder="Title"]', el => (el.innerText||'').trim());
    const rbAuthor = await page.$eval('address[data-placeholder="Your name"]', el => (el.innerText||'').trim());
    logLine('Readback', { title: rbTitle, author: rbAuthor });
  } catch(_) {}

  logLine('Publish click');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('button.publish_button')
  ]);

  const publishedUrl = page.url();
  logLine('Published', { publishedUrl });
  await browser.close();
  logLine('Browser closed');
  return { ok: true, network: 'telegraph', publishedUrl, title, author, logFile: LOG_FILE };
}

    // Content: drop a single leading heading (h1/h2/h3) if present to avoid confusion like "Введение" becoming perceived title
    try {
      const before = cleanedContent;
      const after = before.replace(/^[\s\uFEFF\xA0]*<h[1-3][^>]*>.*?<\/h[1-3]>\s*/is, '');
      if (after !== before) {
        cleanedContent = after;
        logLine('Content normalized', { removedLeadingHeading: true });
      }
    } catch(_) {}
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
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'telegraph', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'telegraph', logFile: LOG_FILE };
        logLine('Run failed (missing openai key)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }

  const res = await publishToTelegraph(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null);
      logLine('Success result', res);
      console.log(JSON.stringify(res));
      process.exit(0);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'telegraph', logFile: LOG_FILE };
      logLine('Run failed', { error: payload.error, stack: e && e.stack });
      // Try to save debug artifacts if a page was left open
      try {
        // In this minimal CLI we don't retain a page reference; leave advanced capture to the publish function
      } catch (_) {}
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}