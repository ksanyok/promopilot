'use strict';

const fetch = require('node-fetch');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { runCli } = require('./lib/genericPaste');
const { createVerificationPayload } = require('./lib/verification');

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const slug = 'transfersh';
  const { LOG_FILE, logLine } = createLogger(slug);
  const provider = (jobOptions.aiProvider || aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const meta = jobOptions.page_meta || jobOptions.meta || pageMeta;
  const job = {
    ...jobOptions,
    pageUrl,
    anchorText,
    language: jobOptions.language || language,
    openaiApiKey: jobOptions.openaiApiKey || openaiApiKey,
    aiProvider: provider,
    wish: jobOptions.wish || wish,
    testMode: !!jobOptions.testMode,
    meta,
    page_meta: meta,
  };
  logLine('Publish start', { pageUrl, anchorText, provider, testMode: job.testMode });

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(job, logLine);
  const plain = htmlToPlainText(article.htmlContent);
  const content = [
    `Title: ${article.title || ''}`,
    `URL: ${pageUrl}`,
    `Anchor: ${anchorText}`,
    '',
    plain
  ].join('\n');

  const timestamp = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
  const filename = `${(article.title || 'article').replace(/[^a-z0-9\-_]+/gi, '-').slice(0, 60) || 'article'}-${timestamp}.txt`;
  const uploadUrl = `https://transfer.sh/${filename}`;

  const response = await fetch(uploadUrl, {
    method: 'PUT',
    headers: {
      'Content-Type': 'text/plain; charset=utf-8'
    },
    body: content
  });

  if (!response.ok) {
    logLine('Upload failed', { status: response.status, statusText: response.statusText });
    throw new Error(`HTTP_${response.status}`);
  }

  const text = (await response.text()).trim();
  const urlMatch = text.match(/https?:\/\/[^\s]+/i);
  const publishedUrl = urlMatch ? urlMatch[0] : text;

  if (!/^https?:\/\//i.test(publishedUrl)) {
    throw new Error('INVALID_TRANSFER_URL');
  }

  logLine('Publish success', { publishedUrl });
  const verification = createVerificationPayload({ pageUrl, anchorText, article, extraTexts: [plain] });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE, verification };
}

module.exports = { publish };

runCli(module, publish, 'transfersh');
