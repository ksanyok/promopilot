'use strict';

const fetch = require('node-fetch');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { runCli } = require('./lib/genericPaste');

function buildSource(text) {
  const lines = String(text || '').split(/\n/).map((line) => `# ${line}`);
  return lines.join('\n') + '\nprint("Article shared via PromoPilot")\n';
}

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const slug = 'godbolt';
  const { LOG_FILE, logLine } = createLogger(slug);
  const job = { pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta: pageMeta };
  logLine('Publish start', { pageUrl, anchorText });

  const article = await generateArticle(job, logLine);
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
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE };
}

module.exports = { publish };

runCli(module, publish, 'godbolt');
