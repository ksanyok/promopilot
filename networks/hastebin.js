'use strict';

const fetch = require('node-fetch');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { runCli } = require('./lib/genericPaste');

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const slug = 'hastebin';
  const { LOG_FILE, logLine } = createLogger(slug);
  logLine('Publish start', { pageUrl, anchorText, language });

  const article = await generateArticle({ pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta: pageMeta }, logLine);
  const text = htmlToPlainText(article.htmlContent);
  const body = text || `${article.title}\n\n${article.htmlContent}`;

  const response = await fetch('https://toptal.com/developers/hastebin/documents', {
    method: 'POST',
    body,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8'
    },
  });
  if (!response.ok) {
    throw new Error(`HTTP_${response.status}`);
  }
  const data = await response.json();
  if (!data || !data.key) {
    throw new Error('INVALID_RESPONSE');
  }
  const key = String(data.key).trim();
  const publishedUrl = `https://toptal.com/developers/hastebin/${key}`;
  logLine('Publish success', { publishedUrl });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE };
}

module.exports = { publish };

runCli(module, publish, 'hastebin');
