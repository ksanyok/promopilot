'use strict';

const fetch = require('node-fetch');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { runCli } = require('./lib/genericPaste');

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const slug = 'jsbin';
  const { LOG_FILE, logLine } = createLogger(slug);
  logLine('Publish start', { pageUrl, anchorText, language });

  const article = await generateArticle({ pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta: pageMeta }, logLine);

  const html = `<!DOCTYPE html><html lang="${language || 'en'}"><head><meta charset="utf-8"><title>${article.title}</title></head><body>${article.htmlContent}</body></html>`;

  const response = await fetch('https://jsbin.com/api/save', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      html,
      css: 'body { font-family: Arial, sans-serif; line-height: 1.6; padding: 16px; } h2 { margin-top: 1.4em; }',
      js: '',
      settings: {
        layout: 'html,output'
      },
      title: article.title,
      description: `Auto post for ${anchorText}`
    })
  });

  if (!response.ok) {
    throw new Error(`HTTP_${response.status}`);
  }

  const data = await response.json();
  const link = data && (data.url || data.latest || data.root); // guess fields
  if (!link) {
    throw new Error('INVALID_RESPONSE');
  }

  let publishedUrl = '';
  if (typeof link === 'string' && link.startsWith('http')) {
    publishedUrl = link;
  } else if (typeof link === 'string') {
    publishedUrl = `https://jsbin.com/${link.replace(/^\//, '')}`;
  } else if (data && data.url) {
    publishedUrl = `https://jsbin.com/${String(data.url).replace(/^\//, '')}`;
  }

  if (!publishedUrl) {
    throw new Error('NO_URL_IN_RESPONSE');
  }

  logLine('Publish success', { publishedUrl });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE };
}

module.exports = { publish };

runCli(module, publish, 'jsbin');
