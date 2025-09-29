'use strict';

const puppeteer = require('puppeteer');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { fillTitleField, clickSubmit, waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { createVerificationPayload } = require('./lib/verification');
const { runCli } = require('./lib/genericPaste');

const NOTE_URL_REGEX = /^https?:\/\/anotepad\.com\/(?:notes?|note)\/[A-Za-z0-9]+/i;

function normalizePlainArticle(plain) {
  const text = String(plain || '').replace(/\r\n?/g, '\n').trim();
  if (!text) return '';
  const lines = text.split('\n');
  const cleaned = [];
  let buffer = [];
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed) {
      if (buffer.length) {
        cleaned.push(buffer.join(' '));
        buffer = [];
      }
      cleaned.push('');
      continue;
    }
    buffer.push(trimmed.replace(/\s+/g, ' '));
  }
  if (buffer.length) {
    cleaned.push(buffer.join(' '));
  }
  const compact = [];
  let empty = false;
  for (const ln of cleaned) {
    if (!ln) {
      if (!empty) {
        compact.push('');
      }
      empty = true;
    } else {
      compact.push(ln);
      empty = false;
    }
  }
  return compact.join('\n');
}

async function ensureRichTextMode(page, logLine) {
  try {
    await page.waitForSelector('#notetypeLabel', { timeout: 15000 });
  } catch (_) {}
  try {
    await page.evaluate(() => {
      const label = document.querySelector('#notetypeLabel');
      if (label) label.click();
    });
    await waitForTimeoutSafe(page, 200);
    await page.evaluate(() => {
      const items = Array.from(document.querySelectorAll('a.dropdown-item'));
      const rich = items.find((el) => /rich\s*text/i.test((el.textContent || '').trim())) || null;
      if (rich) rich.click();
    });
    await waitForTimeoutSafe(page, 300);
  } catch (error) {
    if (typeof logLine === 'function') {
      logLine('Rich text mode fallback', { error: String(error && error.message || error) });
    }
  }
}

async function fillTinyMCE(page, text, logLine) {
  const content = normalizePlainArticle(text);
  if (!content) return false;

  try {
    await page.waitForFunction(
      () => window.tinymce && window.tinymce.activeEditor && window.tinymce.activeEditor.initialized,
      { timeout: 20000 }
    );
  } catch (error) {
    if (typeof logLine === 'function') {
      logLine('TinyMCE not ready', { error: String(error && error.message || error) });
    }
    return false;
  }

  await page.evaluate((value) => {
    const editor = window.tinymce && window.tinymce.activeEditor;
    if (!editor) return;
    const lines = value.split('\n');
    const paragraphs = [];
    let current = [];
    for (const line of lines) {
      if (!line.trim()) {
        if (current.length) {
          paragraphs.push(current.join(' '));
          current = [];
        }
        paragraphs.push('');
        continue;
      }
      current.push(line.trim());
    }
    if (current.length) {
      paragraphs.push(current.join(' '));
    }
    const html = paragraphs
      .map((block) => {
        if (!block) return '<p><br /></p>';
        return `<p>${editor.dom.encode(block)}</p>`;
      })
      .join('');
    editor.setContent(html || `<p>${editor.dom.encode(value)}</p>`, { format: 'html' });
    editor.undoManager.clear();
    editor.undoManager.add();

    const hidden = document.querySelector('#edit_textarea');
    if (hidden) {
      hidden.value = value;
    }
  }, content);

  return true;
}

async function resolvePublishedUrl(page) {
  let current = '';
  try { current = page.url(); } catch (_) {}
  if (NOTE_URL_REGEX.test(current)) {
    return current;
  }
  const maybe = await page.evaluate(() => {
    const link = document.querySelector('a.btnShare, a[href*="anotepad.com/notes/"], a[href*="anotepad.com/note/"]');
    return link ? (link.href || '').trim() : '';
  });
  return NOTE_URL_REGEX.test(maybe) ? maybe : '';
}

async function publishToAnotepad(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const slug = 'anotepad';
  const { LOG_FILE, logLine } = createLogger(slug);
  const provider = (jobOptions.aiProvider || aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();

  logLine('Publish start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

  const articleJob = {
    pageUrl,
    anchorText,
    language,
    openaiApiKey: jobOptions.openaiApiKey || openaiApiKey,
    aiProvider: provider,
    wish: jobOptions.wish || wish,
    meta: jobOptions.page_meta || jobOptions.meta || pageMeta,
    testMode: !!jobOptions.testMode,
  };

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(articleJob, logLine);

  const plain = htmlToPlainText(article.htmlContent);
  const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim().slice(0, 160) || 'PromoPilot Publication';

  const verification = createVerificationPayload({
    pageUrl,
    anchorText,
    article,
    extraTexts: [plain],
  });

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || process.env.PP_CHROME_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) {
    launchOpts.executablePath = execPath;
  }

  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });

  const browser = await puppeteer.launch(launchOpts);
  let page = null;
  try {
    page = await browser.newPage();
    page.setDefaultTimeout(300000);
    page.setDefaultNavigationTimeout(300000);

    await page.goto('https://anotepad.com/notes/new', { waitUntil: 'networkidle2' });
    await ensureRichTextMode(page, logLine);

    const titleFilled = await fillTitleField(page, title, { titleSelectors: ['#edit_title', 'input[name="notetitle"]'] });
    logLine('Title filled', { ok: titleFilled });

    const bodyFilled = await fillTinyMCE(page, plain, logLine);
    logLine('Body filled', { ok: bodyFilled, length: plain.length });

    if (!bodyFilled) {
      throw new Error('BODY_FILL_FAILED');
    }

    await waitForTimeoutSafe(page, 200);
    await clickSubmit(page, { submitSelectors: ['#btnSaveNote'] });
    await waitForTimeoutSafe(page, 500);

    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    const publishedUrl = await resolvePublishedUrl(page);
    if (!publishedUrl) {
      throw new Error('FAILED_TO_RESOLVE_URL');
    }

    logLine('Publish success', { publishedUrl });

    await browser.close();

    return {
      ok: true,
      network: slug,
      title,
      publishedUrl,
      logFile: LOG_FILE,
      verification,
    };
  } catch (error) {
    try { await browser.close(); } catch (_) {}
    logLine('Publish failed', { error: String(error && error.message || error) });
    return {
      ok: false,
      network: slug,
      error: String(error && error.message || error),
      logFile: LOG_FILE,
    };
  }
}

module.exports = { publish: publishToAnotepad };

runCli(module, publishToAnotepad, 'anotepad');
