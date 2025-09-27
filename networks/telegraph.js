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

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const aiOpts = {
    provider,
    openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '',
    model: process.env.OPENAI_MODEL || undefined,
    temperature: 0.2
  };
  logLine('Publish start', { pageUrl, anchorText, language, provider });

  const extraNote = wish ? `\nNote (use if helpful): ${wish}` : '';

  const prompts = {
    title: `Write a clear, specific article title in ${language} about: ${pageUrl}.\n` +
           `Constraints: no quotes, no emojis, no markdown, no disclaimers about browsing; do not mention the URL; concise (6–12 words). If context is limited, infer a suitable general topic from the URL and the anchor "${anchorText}". Reply with the title only.`,
    author: `Suggest a neutral human author's name in ${language}. One or two words.\n` +
            `Constraints: reply with the name only (no extra words), no emojis, no quotes, no punctuation except spaces or hyphen.`,
    content:
      `Write an article in ${language} (>=3000 characters) based on ${pageUrl}.${extraNote}\n` +
      `Hard requirements:\n` +
      `- Integrate exactly one active link with the anchor text "${anchorText}" as <a href="${pageUrl}">${anchorText}</a> naturally in the first half of the article.\n` +
      `- Output must be simple HTML only using <p> for paragraphs and <h2> for subheadings. No other tags, no markdown, no code blocks.\n` +
      `- Keep it informative with 3–5 sections and a short conclusion.\n` +
      `- Do not include any other links or URLs.\n` +
      `- Do not mention limitations (e.g., browsing), and do not include analysis or commentary about the instructions. Output the article body only.`
  };
  logLine('Prompts prepared');

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  const rawTitle = await generateTextWithChat(prompts.title, { ...aiOpts, systemPrompt: 'Return only the final article title. No quotes, no emojis, no markdown, no analysis. No explanations.' });
  await sleep(1000);
  const rawAuthor = await generateTextWithChat(prompts.author, { ...aiOpts, systemPrompt: 'Return only the author name (1-2 words). No quotes, no emojis, no extra text.' });
  await sleep(1000);
  const content = await generateTextWithChat(prompts.content, { ...aiOpts, systemPrompt: 'Return only the article body as simple HTML (<p>, <h2> only). No markdown, no analysis, no explanations.' });

  // Light sanitization for title/author to avoid Analysis/Response leakages and emojis
  const sanitize = (s) => String(s || '')
    .replace(/^[#>*_\-\s]+/g,'')
    .replace(/^\s*(analysis|response)\s*[:：-]+\s*/i,'')
    .replace(/[\r\n]+/g,' ')
    .trim();
  const stripEmoji = (s) => String(s||'').replace(/[\u{1F000}-\u{1FFFF}]/gu, '');
  const looksLikeDisclaimer = (s) => /\b(не могу|cannot|i\s*can\'?t|can\'?t)\b/i.test(String(s||''));
  let title = stripEmoji(sanitize(rawTitle)).replace(/[\"']/g, '').trim();
  let author = stripEmoji(sanitize(rawAuthor)).replace(/[^\p{L}\s\-]+/gu, '').trim();
  if (!title || looksLikeDisclaimer(title) || title.length > 120) {
    try {
      const fallbackTitlePrompt = `Кратко и конкретно сформулируй заголовок (${language}) по теме: ${anchorText}. Без кавычек, без эмодзи, 6–12 слов. Ответь только заголовком.`;
      const t2 = await generateTextWithChat(fallbackTitlePrompt, { ...aiOpts, systemPrompt: 'Только заголовок. Без кавычек, без эмодзи, без пояснений.' });
      title = stripEmoji(sanitize(t2)).replace(/[\"']/g, '').trim() || title || 'Untitled';
    } catch(_) {}
  }
  if (!author || /\s/.test(author) && author.split(/\s+/).length > 3 || /analysis|response/i.test(author)) {
    author = language && /^ru/i.test(language) ? 'Саша Тихий' : 'Alex Kim';
  }
  if (!title) title = 'Untitled';
  if (!author) author = 'PromoPilot';

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
  await page.keyboard.type(title);

  logLine('Fill author');
  await page.waitForSelector('address[data-placeholder="Your name"]');
  await page.click('address[data-placeholder="Your name"]');
  await page.keyboard.type(author);

  logLine('Fill content');
  // Click into the content area to ensure editor is initialized
  await page.waitForSelector('p[data-placeholder="Your story..."]');
  await page.click('p[data-placeholder="Your story..."]');
  const cleanedContent = (() => {
    let s = String(content || '');
    // Drop obvious analysis/disclaimer paragraphs if present
    s = s.replace(/<h1[^>]*>.*?(analysis|response).*?<\/h1>/is, '');
    s = s.replace(/<p[^>]*>[^<]*(Я не могу открывать веб[\u2011\u2013\-]страницы|I cannot open web pages)[^<]*<\/p>/i, '');
    return s.trim();
  })();
  await page.evaluate((html) => {
    const root = document.querySelector('article .tl_article_content .ql-editor') || document.querySelector('.tl_article_content .ql-editor') || document.querySelector('.ql-editor');
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

      const res = await publishToTelegraph(pageUrl, anchor, language, apiKey, provider, wish);
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