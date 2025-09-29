'use strict';

const fetch = require('node-fetch');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { runCli } = require('./lib/genericPaste');
const { createVerificationPayload } = require('./lib/verification');

function buildSource(text) {
  const lines = String(text || '').split(/\n/).map((line) => `# ${line}`);
  return lines.join('\n') + '\nprint("Article shared via PromoPilot")\n';
}

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const slug = 'godbolt';
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
  const source = buildSource(plain);

  const payload = {
    session: {
      language: 'python',
      source,
      compilers: []
    }
  };

  const response = await fetch('https://godbolt.org/api/share', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  if (!response.ok) {
    throw new Error(`HTTP_${response.status}`);
  }
  const data = await response.json();
  const url = data && data.url ? data.url : '';
  const publishedUrl = url && url.startsWith('http') ? url : (url ? `https://godbolt.org${url}` : '');
  if (!publishedUrl) {
    throw new Error('NO_GODBOLT_URL');
  }
  logLine('Publish success', { publishedUrl });
  const verification = createVerificationPayload({ pageUrl, anchorText, article, extraTexts: [plain] });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE, verification };
}

module.exports = { publish };

runCli(module, publish, 'godbolt');
