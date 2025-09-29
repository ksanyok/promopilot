'use strict';

const fetch = require('node-fetch');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { runCli } = require('./lib/genericPaste');
const { createVerificationPayload } = require('./lib/verification');

function buildCode(text) {
  const escaped = String(text || '')
    .replace(/\\/g, '\\\\')
    .replace(/`/g, '\\`');
  return `text = """${escaped.replace(/"""/g, '\\"\\"\\"')}"""\nprint(text)`;
}

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const slug = 'wandbox';
  const { LOG_FILE, logLine } = createLogger(slug);
  const provider = (jobOptions.aiProvider || aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const job = {
    pageUrl,
    anchorText,
    language: jobOptions.language || language,
    openaiApiKey: jobOptions.openaiApiKey || openaiApiKey,
    aiProvider: provider,
    wish: jobOptions.wish || wish,
    meta: jobOptions.page_meta || jobOptions.meta || pageMeta,
    testMode: !!jobOptions.testMode
  };
  logLine('Publish start', { pageUrl, anchorText, provider, testMode: job.testMode });

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(job, logLine);
  const plain = htmlToPlainText(article.htmlContent);
  const code = buildCode(plain);

  const payload = {
    compiler: 'python-3.11.2',
    code,
    stdin: '',
    'compiler-option-raw': '',
    save: true
  };

  const response = await fetch('https://wandbox.org/api/compile.json', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(payload)
  });
  if (!response.ok) {
    throw new Error(`HTTP_${response.status}`);
  }
  const data = await response.json();
  const url = data && (data.url || data.permlink) || '';
  const publishedUrl = url && url.startsWith('http') ? url : (url ? `https://wandbox.org/` + url.replace(/^\//, '') : '');
  if (!publishedUrl) {
    throw new Error('NO_WANDBOX_URL');
  }
  logLine('Publish success', { publishedUrl });
  const verification = createVerificationPayload({ pageUrl, anchorText, article, extraTexts: [plain] });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE, verification };
}

module.exports = { publish };

runCli(module, publish, 'wandbox');
