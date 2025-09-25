// Network: Telegraph Publication
// Description: Publishes rich articles to https://telegra.ph/ using Puppeteer automation.

const puppeteer = require('puppeteer');
let fetch;
try {
  // node-fetch v2 (CommonJS)
  fetch = require('node-fetch');
} catch (error) {
  if (typeof global.fetch === 'function') {
    fetch = global.fetch;
  } else {
    throw error;
  }
}

const fs = require('fs');
const path = require('path');

function ensureDirSync(dir) {
  try { fs.mkdirSync(dir, { recursive: true }); } catch (_) {}
}

function ts() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return (
    d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
    pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
  );
}

function redactSecrets(obj) {
  const mask = (v) => (typeof v === 'string' && v.length > 6 ? v.slice(0, 3) + '***' + v.slice(-3) : '***');
  const walk = (v) => {
    if (v == null) return v;
    if (Array.isArray(v)) return v.map(walk);
    if (typeof v === 'object') {
      const out = {};
      for (const [k, val] of Object.entries(v)) {
        if (/key|token|authorization|password|secret/i.test(k)) out[k] = mask(String(val || ''));
        else out[k] = walk(val);
      }
      return out;
    }
    if (typeof v === 'string') return v;
    return v;
  };
  return walk(obj);
}

const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
ensureDirSync(LOG_DIR);
const LOG_FILE = process.env.PP_LOG_FILE || path.join(
  LOG_DIR,
  `telegraph-${new Date().toISOString().replace(/[:.]/g, '-')}--${process.pid}.log`
);

function logLine(message, data) {
  try {
    const line = `[${ts()}] ${message}` + (data !== undefined ? ` | ${JSON.stringify(redactSecrets(data))}` : '') + '\n';
    fs.appendFileSync(LOG_FILE, line);
  } catch (_) {}
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function generateTextWithChat(prompt, openaiApiKey) {
  const started = Date.now();
  const payload = {
    model: 'gpt-3.5-turbo',
    messages: [{ role: 'user', content: prompt }],
    temperature: 0.8,
  };
  logLine('OpenAI request start', { url: 'https://api.openai.com/v1/chat/completions', payload });
  const response = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${openaiApiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  const ms = Date.now() - started;
  if (!response.ok) {
    const text = await response.text();
    logLine('OpenAI request failed', { status: response.status, statusText: response.statusText, durationMs: ms, body: text.slice(0, 4000) });
    throw new Error(`OpenAI request failed: ${response.status} ${response.statusText} -> ${text}`);
  }

  const data = await response.json();
  const content = data.choices?.[0]?.message?.content?.trim() || '';
  logLine('OpenAI request success', { durationMs: ms, contentPreview: content.slice(0, 200), length: content.length });
  return content;
}

async function publishToTelegraph(job) {
  logLine('Job received', job);
  const {
    url: pageUrl,
    anchor = '',
    language = 'ru',
    openaiApiKey,
    projectName = '',
    wish = '',
  } = job;

  if (!pageUrl) {
    logLine('Validation error: missing target url');
    throw new Error('Missing target url');
  }
  if (!openaiApiKey) {
    logLine('Validation error: missing OpenAI API key');
    throw new Error('Missing OpenAI API key');
  }

  const anchorText = anchor || pageUrl;

  const prompts = {
    title: `What would be a good title for an article about this link without using quotes? ${pageUrl}`,
    author: `What is a suitable author's name for an article in ${language}? Avoid using region-specific names.`,
    content: `Please write a text in ${language} with at least 3000 characters based on the following link: ${pageUrl}. The article must include the anchor text "${anchorText}" as part of a single active link in the format <a href="${pageUrl}">${anchorText}</a>. This link should be naturally integrated into the content, ideally in the first half of the article. The content should be informative, cover the topic comprehensively, and include headings. Use <h2></h2> tags for subheadings. Please ensure the article contains only this one link and focuses on integrating the anchor text naturally within the content’s flow.${wish ? ` Additional context for the article: ${wish}` : ''}${projectName ? ` This article is part of the project ${projectName}.` : ''}`,
  };
  logLine('Prompts prepared', { titlePromptPreview: prompts.title, authorPromptPreview: prompts.author, contentPromptPreview: prompts.content.slice(0, 160) + '...' });

  const wait = Number.isFinite(job.waitBetweenCallsMs) ? Number(job.waitBetweenCallsMs) : 5000;

  logLine('Generating title...');
  const title = (await generateTextWithChat(prompts.title, openaiApiKey)) || 'Untitled';
  await sleep(wait);

  logLine('Generating author...');
  const author = (await generateTextWithChat(prompts.author, openaiApiKey)) || 'PromoPilot';
  await sleep(wait);

  logLine('Generating content...');
  const content = await generateTextWithChat(prompts.content, openaiApiKey);
  logLine('Content generated', { length: content.length });

  const cleanTitle = title.replace(/["']+/g, '').trim() || 'PromoPilot Article';
  logLine('Launching browser');
  const browser = await puppeteer.launch({ headless: 'new' });
  let page;
  try {
    page = await browser.newPage();
    logLine('Navigating to Telegraph');
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 120000 });

    logLine('Filling title');
    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 60000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(cleanTitle, { delay: 30 });

    logLine('Filling author');
    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 60000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author, { delay: 30 });

    logLine('Injecting content into editor');
    await page.evaluate((articleHtml) => {
      const editable = document.querySelector('p[data-placeholder="Your story..."]');
      if (!editable) throw new Error('Telegraph editor not ready');
      editable.innerHTML = articleHtml;
    }, content);

    logLine('Publishing...');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 120000 }),
      page.click('button.publish_button'),
    ]);

    const publishedUrl = page.url();
    logLine('Published', { publishedUrl });
    if (!publishedUrl || !publishedUrl.includes('https://telegra.ph/')) {
      throw new Error(`Unexpected Telegraph URL: ${publishedUrl}`);
    }

    const result = {
      ok: true,
      network: 'telegraph',
      publishedUrl,
      title: cleanTitle,
      author,
      logFile: LOG_FILE,
    };
    logLine('Success result', result);
    return result;
  } finally {
    if (page) {
      try { await page.close(); logLine('Page closed'); } catch (_) { logLine('Page close error (ignored)'); }
    }
    try { await browser.close(); logLine('Browser closed'); } catch (_) { logLine('Browser close error (ignored)'); }
  }
}

function readJob() {
  const raw = process.env.PP_JOB;
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    logLine('PP_JOB parsed');
    return parsed;
  } catch (error) {
    logLine('PP_JOB parse error', { error: String(error) });
    return {};
  }
}

(async () => {
  const job = readJob();
  try {
    const result = await publishToTelegraph(job);
    console.log(JSON.stringify(result));
  } catch (error) {
    console.error(error);
    const payload = {
      ok: false,
      error: error.message || 'UNEXPECTED_ERROR',
      network: 'telegraph',
      logFile: LOG_FILE,
    };
    logLine('Run failed', { error: String(error), stack: error && error.stack });
    console.log(JSON.stringify(payload));
    process.exit(1);
  }
})();

