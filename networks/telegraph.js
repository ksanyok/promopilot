'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { generateArticle, analyzeLinks, attachArticleToResult } = require('./lib/articleGenerator');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { createVerificationPayload } = require('./lib/verification');

// Simple file logger (one log file per process run)
const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
const LOG_FILE = process.env.PP_LOG_FILE || path.join(
  LOG_DIR,
  `network-telegraph-${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}.log`
);
function ensureDirSync(dir){
  try { if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true }); } } catch(_) {}
}
ensureDirSync(LOG_DIR);
function safeStringify(obj){
  try { return JSON.stringify(obj); } catch(_) { return String(obj); }
}
function logLine(msg, data){
  const line = `[${new Date().toISOString()}] ${msg}${data ? ' ' + safeStringify(data) : ''}\n`;
  try { fs.appendFileSync(LOG_FILE, line); } catch(_) {}
}
function normalizeContent(html) {
  let s = String(html || '');
  if (!s.trim()) {
    return '';
  }

  const sanitizeText = (text) => String(text || '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/\s+/g, ' ')
    .replace(/[<>"']/g, '')
    .trim();

  // Remove scripts/styles and inline event handlers
  s = s.replace(/<(script|style)[^>]*>[\s\S]*?<\/\1>/gi, '');
  s = s.replace(/ on[a-z]+="[^"]*"/gi, '');

  // Drop outer figures but keep content for re-normalization
  s = s.replace(/<figure[^>]*>[\s\S]*?<\/figure>/gi, (match) => {
    const imgMatch = match.match(/<img[\s\S]*?>/i);
    return imgMatch ? imgMatch[0] : '';
  });

  // Normalize images into standalone figure blocks
  s = s.replace(/<img[^>]*>/gi, (raw) => {
    const srcMatch = raw.match(/src=["']([^"']+)["']/i);
    if (!srcMatch || !srcMatch[1]) {
      return '';
    }
    const altMatch = raw.match(/alt=["']([^"']*)["']/i);
    const altText = sanitizeText(altMatch ? altMatch[1] : '') || 'Article illustration';
    return `<figure><img src="${srcMatch[1]}" alt="${altText}" loading="lazy" /></figure>`;
  });

  // Remove article title — Telegraph manages the title separately
  s = s.replace(/<h1[^>]*>[\s\S]*?<\/h1>/gi, '');

  // Convert secondary headings to h3 (preferred structure inside Telegraph body)
  s = s.replace(/<h2/gi, '<h3').replace(/<\/h2>/gi, '<\/h3>');

  // Collapse excessive breaks and whitespace
  s = s.replace(/(<br[^>]*>\s*){2,}/gi, '<br>');
  s = s.replace(/&nbsp;/gi, ' ');

  // Remove empty structural nodes
  s = s.replace(/<h3[^>]*>(?:\s|<br[^>]*>)*<\/h3>/gi, '');
  s = s.replace(/<blockquote[^>]*>(?:\s|<br[^>]*>)*<\/blockquote>/gi, '');
  s = s.replace(/<p[^>]*>(?:\s|<br[^>]*>)*<\/p>/gi, '');

  // Clean stray dollars and trailing whitespace
  s = s.replace(/<p>\s*\$\s*<\/p>/gi, '');
  s = s.replace(/\s*\$+\s*$/g, '');

  // Drop orphaned "Key Takeaways" heading if it has no content afterwards
  const headingRegex = /<h3[^>]*>[^<]*key\s*takeaways[^<]*<\/h3>/i;
  const headingMatcher = new RegExp(headingRegex.source, 'gi');
  let match;
  while ((match = headingMatcher.exec(s))) {
    const start = match.index;
    const afterHeading = start + match[0].length;
    const remainder = s.slice(afterHeading);
    const nextHeadingOffset = remainder.search(/<h[1-6][^>]*>/i);
    const sectionEnd = nextHeadingOffset === -1 ? s.length : afterHeading + nextHeadingOffset;
    const section = s.slice(afterHeading, sectionEnd);
    const hasList = /<(ul|ol)[^>]*>/i.test(section);
    const hasMeaningfulText = section
      .replace(/<p[^>]*>(?:\s|&nbsp;|<br[^>]*>)*<\/p>/gi, '')
      .replace(/<[^>]+>/g, '')
      .trim().length > 0;
    if (!hasList && !hasMeaningfulText) {
      s = `${s.slice(0, start)}${s.slice(sectionEnd)}`;
      headingMatcher.lastIndex = Math.max(0, start - 1);
    }
  }

  return s.trim();
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  logLine('Publish start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

  const meta = jobOptions.page_meta || jobOptions.meta || pageMeta;
  const articleJob = {
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

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(articleJob, logLine);

  const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim();
  const author = (article.author || '').toString().trim() || 'PromoPilot Автор';
  const rawContent = String(article.htmlContent || '').trim();
  if (!rawContent) {
    throw new Error('EMPTY_ARTICLE_CONTENT');
  }
  const initialLinks = article.linkStats || analyzeLinks(rawContent, pageUrl, anchorText);
  logLine('Article ready', { title: title.slice(0, 140), author, links: initialLinks });

  const launchArgs = ['--no-sandbox','--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  page.setDefaultTimeout(300000);
  page.setDefaultNavigationTimeout(300000);
  logLine('Goto Telegraph');
  await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2' });

  // 1) Fill content first to avoid Telegraph auto-overriding title from first <h2>
  logLine('Fill content');
  const editorSelector = '.tl_article_content .ql-editor, article .tl_article_content .ql-editor, article .ql-editor, .ql-editor';
  await page.waitForSelector(editorSelector);
  try { await page.click(editorSelector); } catch (_) {}
  const cleanedContent = normalizeContent(rawContent);
  logLine('Normalized link analysis', analyzeLinks(cleanedContent, pageUrl, anchorText));
  await page.evaluate((html) => {
    const root = document.querySelector('.tl_article_content .ql-editor')
      || document.querySelector('article .tl_article_content .ql-editor')
      || document.querySelector('article .ql-editor')
      || document.querySelector('.ql-editor');
    if (!root) {
      return;
    }

    const normalizeEmptyBlocks = () => {
      const isEmptyNode = (node) => {
        const text = (node.textContent || '').replace(/\u200b/g, '').trim();
        const hasMedia = node.querySelector('img, figure');
        return !text && !hasMedia;
      };

      const removeEmptyParagraphs = () => {
        root.querySelectorAll('p').forEach((node) => {
          if (!node) return;
          const hasMedia = node.querySelector('img, figure');
          if (hasMedia) return;
          const html = (node.innerHTML || '').replace(/<br[^>]*>/gi, '').replace(/&nbsp;/gi, ' ').trim();
          const text = (node.textContent || '').replace(/\u200b/g, '').trim();
          if (!html && !text) {
            node.remove();
          }
        });
      };

      const selectors = ['h3', 'blockquote', 'h4'];
      selectors.forEach((sel) => {
        root.querySelectorAll(sel).forEach((node) => {
          if (isEmptyNode(node)) {
            node.remove();
          }
        });
      });

      removeEmptyParagraphs();

      root.querySelectorAll('br').forEach((br) => {
        br.removeAttribute('class');
      });

      if (!root.textContent || !root.textContent.trim()) {
        root.innerHTML = '<p></p>';
      }
    };

    try { root.focus(); } catch (_) {}
    const quill = root.__quill || (window.Quill && window.Quill.find ? window.Quill.find(root) : null);
    if (quill && quill.clipboard && typeof quill.clipboard.dangerouslyPasteHTML === 'function') {
      try { quill.setContents([]); } catch (_) {}
      quill.clipboard.dangerouslyPasteHTML(html || '', 'user');
      if (quill.history && typeof quill.history.clear === 'function') {
        try { quill.history.clear(); } catch (_) {}
      }
    } else {
      root.innerHTML = html || '<p></p>';
    }
    normalizeEmptyBlocks();
    try {
      if (typeof requestAnimationFrame === 'function') {
        requestAnimationFrame(() => normalizeEmptyBlocks());
      } else {
        setTimeout(() => normalizeEmptyBlocks(), 60);
      }
    } catch (_) {
      setTimeout(() => normalizeEmptyBlocks(), 60);
    }
    try { root.dispatchEvent(new Event('input', { bubbles: true })); } catch (_) {}
  }, cleanedContent);
  await waitForTimeoutSafe(page, 120);

  // Helper to set text in editable fields (title/author) via keyboard only
  const setEditableText = async (selector, value) => {
    await page.waitForSelector(selector);
    const text = String(value || '').trim();
    const success = await page.evaluate((sel, val) => {
      const node = document.querySelector(sel);
      if (!node) {
        return false;
      }
      try {
        if (typeof node.focus === 'function') {
          node.focus();
        }
      } catch (_) {}
      while (node.firstChild) {
        node.removeChild(node.firstChild);
      }
      if (val) {
        const doc = node.ownerDocument || document;
        node.appendChild(doc.createTextNode(val));
        if (node.classList) {
          node.classList.remove('empty');
        }
      } else {
        node.innerHTML = '<br />';
        if (node.classList) {
          node.classList.add('empty');
        }
      }
      try {
        node.dispatchEvent(new Event('input', { bubbles: true }));
        node.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (_) {}
      return true;
    }, selector, text);
    if (!success) {
      throw new Error(`Unable to locate editable field ${selector}`);
    }
    if (text) {
      const applied = await page.$eval(selector, (el) => (el.innerText || '').trim());
      if (!applied) {
        throw new Error(`Telegraph field ${selector} remained empty after update`);
      }
    }
  };

  // 2) Set title and 3) author
  logLine('Fill title');
  await setEditableText('h1[data-placeholder="Title"]', title);
  try {
    await page.evaluate((text) => {
      const headerTitle = document.querySelector('header.tl_article_header h1');
      if (headerTitle) {
        headerTitle.textContent = text || '';
      }
    }, title);
  } catch (_) {}
  await waitForTimeoutSafe(page, 80);

  logLine('Fill author');
  await setEditableText('address[data-placeholder="Your name"]', author);
  try {
    await page.evaluate((text) => {
      const headerAddress = document.querySelector('header.tl_article_header address');
      if (!headerAddress) {
        return;
      }
      const link = headerAddress.querySelector('a[rel="author"]');
      if (link) {
        link.textContent = text || '';
      } else {
        headerAddress.textContent = text || '';
      }
    }, author);
  } catch (_) {}
  await waitForTimeoutSafe(page, 80);

  // Diagnostic: check final DOM title
  try {
    const finalTitle = await page.$eval('h1[data-placeholder="Title"]', el => (el.innerText || '').trim());
    logLine('DOM title check', { finalTitle });
  } catch (_) {}

  logLine('Publish click');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('button.publish_button')
  ]);

  const publishedUrl = page.url();
  logLine('Published', { publishedUrl });
  await browser.close();
  logLine('Browser closed');
  const verification = createVerificationPayload({ pageUrl, anchorText, article, extraTexts: [title, author] });
  return { ok: true, network: 'telegraph', publishedUrl, title, author, logFile: LOG_FILE, verification, article };
}

module.exports = { publish: publishToTelegraph };

// CLI entrypoint for PromoPilot runner (reads PP_JOB from env)
if (require.main === module) {
  (async () => {
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: raw.length });
      const job = JSON.parse(raw);
      logLine('PP_JOB parsed');
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'telegraph', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'telegraph', logFile: LOG_FILE };
        logLine('Run failed (missing openai key)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }

  let res = await publishToTelegraph(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
  res = attachArticleToResult(res, job);
  logLine('Success result', res);
  console.log(JSON.stringify(res));
  process.exit(res && res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'telegraph', logFile: LOG_FILE };
      logLine('Run failed', { error: payload.error, stack: e && e.stack });
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}
