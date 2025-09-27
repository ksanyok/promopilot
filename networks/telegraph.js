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
    model: process.env.OPENAI_MODEL || undefined
  };
  logLine('Publish start', { pageUrl, anchorText, language, provider });

  const extraNote = wish ? `\nNote (use if helpful): ${wish}` : '';

  const prompts = {
    title: `Write a clear, specific article title in ${language} about: ${pageUrl}. No quotes.`,
    author: `Suggest a neutral author's name in ${language}. One or two words. Reply with the name only.`,
    content:
      `Write an article in ${language} (>=3000 characters) based on ${pageUrl}.${extraNote}\n` +
      `Requirements:\n` +
      `- Integrate exactly one active link with the anchor text "${anchorText}" as <a href="${pageUrl}">${anchorText}</a> naturally in the first half of the article.\n` +
      `- Use simple HTML only: <p> for paragraphs and <h2> for subheadings. No markdown, no code blocks.\n` +
      `- Keep it informative and readable with 3–5 sections and a short conclusion.\n` +
      `- Do not include any other links.`
  };
  logLine('Prompts prepared');

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  const rawTitle = await generateTextWithChat(prompts.title, aiOpts);
  await sleep(1000);
  const rawAuthor = await generateTextWithChat(prompts.author, aiOpts);
  await sleep(1000);
  const content = await generateTextWithChat(prompts.content, aiOpts);

  const title = String(rawTitle || '').replace(/["']/g, '').trim() || 'Untitled';
  const author = String(rawAuthor || '').trim() || 'PromoPilot';

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
  await page.evaluate((html) => {
    const el = document.querySelector('p[data-placeholder="Your story..."]');
    if (el) el.innerHTML = html;
  }, content);

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