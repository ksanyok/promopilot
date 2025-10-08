'use strict';

const fetch = require('node-fetch');
const FormData = require('form-data');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { runCli } = require('./lib/genericPaste');
const { createVerificationPayload } = require('./lib/verification');

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const slug = 'jsfiddle';
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
  logLine('Publish start', { pageUrl, anchorText, language: job.language, provider, testMode: job.testMode });

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(job, logLine);

  const html = `<!DOCTYPE html><html lang="${job.language || 'en'}"><head><meta charset="utf-8"><title>${article.title}</title></head><body>${article.htmlContent}</body></html>`;
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
  const verification = createVerificationPayload({ pageUrl, anchorText, article });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE, verification };
}

module.exports = { publish };

runCli(module, publish, 'jsfiddle');
