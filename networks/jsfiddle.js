'use strict';

const fetch = require('node-fetch');
const FormData = require('form-data');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { runCli } = require('./lib/genericPaste');

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const slug = 'jsfiddle';
  const { LOG_FILE, logLine } = createLogger(slug);
  logLine('Publish start', { pageUrl, anchorText, language });

  const article = await generateArticle({ pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta: pageMeta }, logLine);

  const html = `<!DOCTYPE html><html lang="${language || 'en'}"><head><meta charset="utf-8"><title>${article.title}</title></head><body>${article.htmlContent}</body></html>`;
  const form = new FormData();
  form.append('title', article.title);
  form.append('description', `Auto-generated article for ${anchorText}`);
  form.append('wrap', 'd');
  form.append('html', html);
  form.append('css', 'body { font-family: Arial, sans-serif; line-height: 1.6; padding: 16px; } h2 { margin-top: 1.4em; }');
  form.append('js', '');
  form.append('resources', '');

  const response = await fetch('https://jsfiddle.net/api/post/library/pure/', {
    method: 'POST',
    body: form,
    redirect: 'manual'
  });

  if (![200, 201, 302].includes(response.status)) {
    throw new Error(`HTTP_${response.status}`);
  }

  const location = response.headers.get('location');
  let publishedUrl = '';
  if (location) {
    publishedUrl = location.startsWith('http') ? location : `https://jsfiddle.net${location}`;
  } else {
    const text = await response.text();
    const match = text.match(/https?:\/\/jsfiddle\.net[^"'\s]+/i);
    if (match) {
      publishedUrl = match[0];
    }
  }

  if (!publishedUrl) {
    throw new Error('NO_LOCATION_HEADER');
  }

  logLine('Publish success', { publishedUrl });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE };
}

module.exports = { publish };

runCli(module, publish, 'jsfiddle');
