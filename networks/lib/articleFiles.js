'use strict';

const { htmlToPlainText } = require('./contentFormats');

function sanitizeForFilename(name) {
  const cleaned = String(name || '')
    .replace(/[^a-z0-9\-_]+/gi, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 80);
  return cleaned || 'article';
}

function buildTextFile({ article, variants, job }) {
  const plain = variants && variants.plain ? variants.plain : htmlToPlainText(article && article.htmlContent);
  const slugPart = sanitizeForFilename(article && article.title);
  const timestamp = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
  const filename = `${slugPart || 'article'}-${timestamp}.txt`;
  const headerLines = [
    `Title: ${article && article.title ? article.title : ''}`,
    `Target URL: ${job && job.pageUrl ? job.pageUrl : ''}`,
    `Anchor: ${job && job.anchorText ? job.anchorText : ''}`,
    '',
  ];
  const content = headerLines.join('\n') + (plain || '');
  return {
    filename,
    buffer: Buffer.from(content, 'utf8'),
    mime: 'text/plain; charset=utf-8'
  };
}

module.exports = { buildTextFile };
