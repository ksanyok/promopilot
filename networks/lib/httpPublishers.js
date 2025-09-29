'use strict';

const fetch = require('node-fetch');
const FormData = require('form-data');
const net = require('net');
const { createLogger } = require('./logger');
const { generateArticle } = require('./articleGenerator');
const { htmlToMarkdown, htmlToPlainText } = require('./contentFormats');
const { createVerificationPayload } = require('./verification');

function pickVariant(format, variants) {
  switch ((format || '').toLowerCase()) {
    case 'markdown':
      return variants.markdown || variants.html || variants.plain || '';
    case 'text':
    case 'plaintext':
      return variants.plain || variants.markdown || variants.html || '';
    case 'html':
    default:
      return variants.html || variants.markdown || variants.plain || '';
  }
}

async function composeArticle(job, logLine, preparedArticle) {
  const article = (preparedArticle && preparedArticle.htmlContent)
    ? { ...preparedArticle }
    : await generateArticle(job, logLine);
  const variants = {
    html: article.htmlContent,
    markdown: htmlToMarkdown(article.htmlContent),
    plain: htmlToPlainText(article.htmlContent)
  };
  return { article, variants };
}

function createHttpPublisher(config) {
  if (!config || !config.slug) {
    throw new Error('HTTP publisher requires slug');
  }
  if (typeof config.buildRequest !== 'function') {
    throw new Error('HTTP publisher requires buildRequest');
  }
  if (typeof config.parseResponse !== 'function') {
    throw new Error('HTTP publisher requires parseResponse');
  }

  const slug = config.slug;

  async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
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

    const { article, variants } = await composeArticle(job, logLine, jobOptions.preparedArticle);
    let body = pickVariant(config.contentFormat || 'markdown', variants);
    if (config.prepareBody) {
      body = await config.prepareBody({ job, article, variants, logLine }) || body;
    }

    const requestOpts = await config.buildRequest({ job, article, variants, body, logLine });
    const url = requestOpts.url;
    if (!url) {
      throw new Error('REQUEST_URL_MISSING');
    }

    const fetchOpts = {
      method: requestOpts.method || 'POST',
      headers: requestOpts.headers || {},
      body: requestOpts.body,
      redirect: requestOpts.redirect || 'follow',
    };

    if (requestOpts.formData) {
      const fd = new FormData();
      const form = requestOpts.formData;
      Object.keys(form).forEach((key) => {
        const value = form[key];
        if (value && value.value && value.options) {
          fd.append(key, value.value, value.options);
        } else {
          fd.append(key, value);
        }
      });
      fetchOpts.body = fd;
      fetchOpts.headers = { ...(fetchOpts.headers || {}), ...fd.getHeaders() };
    }

    if (!fetchOpts.body && body) {
      fetchOpts.body = body;
    }

    if (config.ensureContentType && !fetchOpts.headers['Content-Type']) {
      fetchOpts.headers['Content-Type'] = config.ensureContentType;
    }

    const response = await fetch(url, fetchOpts);
    const result = await config.parseResponse({ response, article, variants, body, logLine });

    const publishedUrl = result && result.url ? String(result.url).trim() : '';
    if (!publishedUrl) {
      throw new Error('NO_URL_RETURNED');
    }

    logLine('Publish success', { publishedUrl });
    const verification = createVerificationPayload({ pageUrl, anchorText, article, variants });
    return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE, verification };
  }

  return { publish };
}

function createTcpPublisher(config) {
  if (!config || !config.slug) {
    throw new Error('TCP publisher requires slug');
  }
  if (!config.host || !config.port) {
    throw new Error('TCP publisher requires host/port');
  }
  if (typeof config.parseResponse !== 'function') {
    throw new Error('TCP publisher requires parseResponse');
  }

  const slug = config.slug;

  async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
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

    const { article, variants } = await composeArticle(job, logLine, jobOptions.preparedArticle);
    const body = await (config.prepareBody ? config.prepareBody({ job, article, variants, logLine }) : null) || pickVariant(config.contentFormat || 'text', variants);

    const payload = config.wrapPayload ? config.wrapPayload(body, { article, variants, job }) : body;

    const responseChunks = [];
    const socket = new net.Socket();

    await new Promise((resolve, reject) => {
      socket.setTimeout(config.timeout || 15000);
      socket.once('timeout', () => {
        socket.destroy();
        reject(new Error('TCP_TIMEOUT'));
      });
      socket.once('error', (err) => {
        socket.destroy();
        reject(err);
      });
      socket.connect(config.port, config.host, () => {
        socket.write(payload);
        if (!payload.endsWith('\n')) {
          socket.write('\n');
        }
      });
      socket.on('data', (chunk) => {
        responseChunks.push(chunk);
      });
      socket.on('close', () => {
        resolve();
      });
    });

    const raw = Buffer.concat(responseChunks).toString('utf8').trim();
    const info = await config.parseResponse({ raw, article, variants, logLine });
    const publishedUrl = info && info.url ? String(info.url).trim() : '';
    if (!publishedUrl) {
      throw new Error('NO_URL_FROM_TCP');
    }
    logLine('Publish success', { publishedUrl });
    const verification = createVerificationPayload({ pageUrl, anchorText, article, variants });
    return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE, verification };
  }

  return { publish };
}

module.exports = { createHttpPublisher, createTcpPublisher };
