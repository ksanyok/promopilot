'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { generateArticle, analyzeLinks, attachArticleToResult } = require('./lib/articleGenerator');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { createVerificationPayload } = require('./lib/verification');

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
function normalizeContent(html) {
  let s = String(html || '').trim();
  s = s.replace(/<h1[^>]*>([\s\S]*?)<\/h1>/gi, '<h2>$1</h2>');
  s = s.replace(/<li[^>]*>([\s\S]*?)<\/li>/gi, (m, inner) => `<p>— ${inner.trim()}</p>`);
  s = s.replace(/<\/(?:ul|ol)>/gi, '').replace(/<(?:ul|ol)[^>]*>/gi, '');
  s = s.replace(/<p([^>]*)>\s*[-–—•∙·]\s+(.*?)<\/p>/gi, '<p$1>— $2</p>');
  s = s.replace(/<p[^>]*>(?:\s|<br[^>]*>)*<\/p>/gi, '');
  return s;
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  logLine('Publish start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

  const articleJob = {
    pageUrl,
    anchorText,
    language,
    openaiApiKey,
    aiProvider: provider,
    wish,
    meta: pageMeta,
    testMode: !!jobOptions.testMode
  };

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(articleJob, logLine);

  const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim();
  const author = (article.author || '').toString().trim() || 'PromoPilot Автор';
  const rawContent = String(article.htmlContent || '').trim();
  if (!rawContent) {
    throw new Error('EMPTY_ARTICLE_CONTENT');
  }
  const initialLinks = article.linkStats || analyzeLinks(rawContent, pageUrl, anchorText);
  logLine('Article ready', { title: title.slice(0, 140), author, links: initialLinks });

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

  // 1) Fill content first to avoid Telegraph auto-overriding title from first <h2>
  logLine('Fill content');
  const editorSelector = '.tl_article_content .ql-editor, article .tl_article_content .ql-editor, article .ql-editor, .ql-editor';
  await page.waitForSelector(editorSelector);
  try { await page.click(editorSelector); } catch (_) {}
  const cleanedContent = normalizeContent(rawContent);
  logLine('Normalized link analysis', analyzeLinks(cleanedContent, pageUrl, anchorText));
  await page.evaluate((html) => {
    const root = document.querySelector('.tl_article_content .ql-editor') || document.querySelector('article .tl_article_content .ql-editor') || document.querySelector('article .ql-editor') || document.querySelector('.ql-editor');
    if (root) {
      root.innerHTML = html || '<p></p>';
      try { root.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch(_) { try { root.dispatchEvent(new Event('input', { bubbles: true })); } catch(__) {} }
    }
  }, cleanedContent);
  await waitForTimeoutSafe(page, 120);

  // Helper to set text in editable fields (title/author) via keyboard only
  const setEditableText = async (selector, value) => {
    await page.waitForSelector(selector);
    await page.click(selector, { clickCount: 3 }).catch(()=>{});
    try { await page.keyboard.press('Backspace'); } catch (_) {}
    await page.keyboard.type(String(value || ''), { delay: 10 });
  };

  // 2) Set title and 3) author
  logLine('Fill title');
  await setEditableText('h1[data-placeholder="Title"]', title);
  await waitForTimeoutSafe(page, 80);

  logLine('Fill author');
  await setEditableText('address[data-placeholder="Your name"]', author);
  await waitForTimeoutSafe(page, 80);

  // Diagnostic: check final DOM title
  try {
    const finalTitle = await page.$eval('h1[data-placeholder="Title"]', el => (el.innerText || '').trim());
    logLine('DOM title check', { finalTitle });
  } catch (_) {}

  logLine('Publish click');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('button.publish_button')
  ]);

  const publishedUrl = page.url();
  logLine('Published', { publishedUrl });
  await browser.close();
  logLine('Browser closed');
  const verification = createVerificationPayload({ pageUrl, anchorText, article, extraTexts: [title, author] });
  return { ok: true, network: 'telegraph', publishedUrl, title, author, logFile: LOG_FILE, verification, article };
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

  let res = await publishToTelegraph(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
  res = attachArticleToResult(res, job);
  logLine('Success result', res);
  console.log(JSON.stringify(res));
  process.exit(res && res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'telegraph', logFile: LOG_FILE };
      logLine('Run failed', { error: payload.error, stack: e && e.stack });
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}