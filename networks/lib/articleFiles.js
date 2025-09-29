'use strict';

const puppeteer = require('puppeteer');
const { htmlToPlainText } = require('./contentFormats');

function sanitizeForFilename(name) {
  const cleaned = String(name || '')
    .replace(/[^a-z0-9\-_]+/gi, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 80);
  return cleaned || 'article';
}

function escapeAttribute(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
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

function composeHtmlDocument({ article, variants, job }) {
  const htmlContent = variants && variants.html ? variants.html : (article && article.htmlContent) || '';
  const plain = variants && variants.plain ? variants.plain : htmlToPlainText(htmlContent);
  const slugPart = sanitizeForFilename(article && article.title);
  const timestamp = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
  const filenameBase = `${slugPart || 'article'}-${timestamp}`;
  const pageUrl = job && job.pageUrl ? String(job.pageUrl) : '';
  const pageMeta = job && job.meta ? job.meta : {};
  const lang = (article && article.language) || pageMeta.lang || (job && job.language) || 'ru';
  const descriptionSource = (pageMeta && pageMeta.description) || '';
  const description = descriptionSource ? String(descriptionSource).trim() : String(plain || '').replace(/\s+/g, ' ').slice(0, 180);
  const title = article && article.title ? String(article.title).trim() : 'PromoPilot Publication';
  const author = article && article.author ? String(article.author).trim() : 'PromoPilot Contributor';

  const styles = `
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #1f2933; background: #f8fafc; }
    header { background: linear-gradient(135deg, #2563eb, #1e40af); color: #fff; padding: 48px 24px; text-align: center; }
    header h1 { margin: 0 0 12px; font-size: clamp(28px, 4vw, 42px); }
    header p { max-width: 720px; margin: 0 auto; font-size: 18px; line-height: 1.6; opacity: 0.85; }
    main { max-width: 960px; margin: -48px auto 64px; padding: 0 24px; }
    article { background: #fff; border-radius: 16px; box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1); padding: 48px clamp(24px, 5vw, 64px); }
    article h2 { margin-top: 40px; color: #1d4ed8; font-size: clamp(22px, 3vw, 30px); }
    article p { line-height: 1.8; font-size: 17px; margin: 18px 0; }
    article a { color: #2563eb; text-decoration: none; border-bottom: 1px solid rgba(37, 99, 235, 0.35); transition: border-color 0.2s ease, color 0.2s ease; }
    article a:hover { color: #1e3a8a; border-color: currentColor; }
    footer { max-width: 960px; margin: 0 auto 40px; padding: 0 24px; text-align: center; color: #475569; }
    footer a { color: inherit; }
  `;

  const html = `<!DOCTYPE html>
<html lang="${escapeAttribute(lang)}">
  <head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>${escapeAttribute(title)}</title>
    <meta name="description" content="${escapeAttribute(description)}" />
    <meta name="robots" content="index, follow" />
    ${pageUrl ? `<link rel="canonical" href="${escapeAttribute(pageUrl)}" />` : ''}
    <meta name="author" content="${escapeAttribute(author)}" />
    <meta property="og:type" content="article" />
    <meta property="og:title" content="${escapeAttribute(title)}" />
    ${pageUrl ? `<meta property="og:url" content="${escapeAttribute(pageUrl)}" />` : ''}
    <meta property="og:description" content="${escapeAttribute(description)}" />
    <style>${styles}</style>
  </head>
  <body>
    <header>
      <h1>${escapeAttribute(title)}</h1>
      <p>${escapeAttribute(description)}</p>
    </header>
    <main>
      <article>
        ${htmlContent}
      </article>
    </main>
    <footer>
      <p>Источник ссылки: ${pageUrl ? `<a href="${escapeAttribute(pageUrl)}" rel="noopener noreferrer">${escapeAttribute(pageUrl)}</a>` : 'PromoPilot Project'}</p>
      <p>Создано в PromoPilot для продвижения проекта.</p>
    </footer>
  </body>
</html>`;

  return {
    filenameBase,
    html,
    title,
    description,
    author,
    lang,
    pageUrl
  };
}

function buildHtmlFile(opts) {
  const { filenameBase, html } = composeHtmlDocument(opts);
  return {
    filename: `${filenameBase}.html`,
    buffer: Buffer.from(html, 'utf8'),
    mime: 'text/html; charset=utf-8'
  };
}

async function buildPdfFile(opts) {
  const { filenameBase, html, title, author } = composeHtmlDocument(opts);

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;

  const browser = await puppeteer.launch(launchOpts);
  try {
    const page = await browser.newPage();
    try { await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 }); } catch (_) {}
    await page.setContent(html, { waitUntil: 'networkidle0' });
    await page.emulateMediaType('screen');
    const pdfBuffer = await page.pdf({
      format: 'A4',
      printBackground: true,
      margin: { top: '20mm', bottom: '22mm', left: '18mm', right: '18mm' },
      displayHeaderFooter: false,
      preferCSSPageSize: true
    });
    return {
      filename: `${filenameBase}.pdf`,
      buffer: pdfBuffer,
      mime: 'application/pdf'
    };
  } finally {
    try { await browser.close(); } catch (_) {}
  }
}

module.exports = { buildTextFile, buildHtmlFile, buildPdfFile, composeHtmlDocument };
