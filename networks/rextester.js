'use strict';

const fetch = require('node-fetch');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { runCli } = require('./lib/genericPaste');
const { createVerificationPayload } = require('./lib/verification');

function escapePyString(text) {
  return String(text || '')
    .replace(/\\/g, '\\\\')
    .replace(/"""/g, '\\"\\"\\"')
    .replace(/\r/g, '')
    .replace(/\n/g, '\n');
}

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const slug = 'rextester';
  const { LOG_FILE, logLine } = createLogger(slug);
  const job = { pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta: pageMeta };
  logLine('Publish start', { pageUrl, anchorText });

  const article = await generateArticle(job, logLine);
  const plain = htmlToPlainText(article.htmlContent);
  const code = `text = """${escapePyString(plain)}"""\nprint(text)`;

  const params = new URLSearchParams();
  params.append('LanguageChoice', '24');
  params.append('Program', code);
  params.append('Input', '');
  params.append('CompilerArgs', '');
  params.append('Save', 'true');

  const response = await fetch('https://rextester.com/rundotnet/api', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
    body: params.toString()
  });
  if (!response.ok) {
    throw new Error(`HTTP_${response.status}`);
  }
  const data = await response.json();
  if (!data || !data.Result) {
    logLine('Rextester response', data);
  }
  const url = data && (data.Result && data.Result.Url ? data.Result.Url : data.Url || data.SResultUrl || data.ResultUrl);
  const publishedUrl = url && url.startsWith('http') ? url : (url ? `https://rextester.com/${url.replace(/^\/*/, '')}` : '');
  if (!publishedUrl) {
    throw new Error('NO_REX_URL');
  }
  logLine('Publish success', { publishedUrl });
  const verification = createVerificationPayload({ pageUrl, anchorText, article, extraTexts: [plain] });
  return { ok: true, network: slug, title: article.title, publishedUrl, logFile: LOG_FILE, verification };
}

module.exports = { publish };

runCli(module, publish, 'rextester');
