'use strict';

const puppeteer = require('puppeteer');
const { createLogger } = require('./lib/logger');
const { generateArticle, analyzeLinks } = require('./lib/articleGenerator');
const { htmlToMarkdown, htmlToPlainText } = require('./lib/contentFormats');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { createVerificationPayload } = require('./lib/verification');

const DPasteBaseUrl = 'https://dpaste.org/';

function normalizeDpasteUrl(value, baseUrl = DPasteBaseUrl) {
  const raw = String(value || '').trim();
  if (!raw) {
    return '';
  }

  let urlObject;
  try {
    urlObject = new URL(raw, baseUrl);
  } catch (_) {
    return '';
  }

  const hostname = urlObject.hostname.toLowerCase();
  if (!hostname.endsWith('dpaste.org')) {
    return '';
  }

  const slugMatch = urlObject.pathname.match(/^\/?([A-Za-z0-9]{2,})\/?$/);
  if (!slugMatch) {
    return '';
  }

  const slug = slugMatch[1];
  const normalized = `https://dpaste.org/${slug}`;
  return normalized;
}

async function resolvePublishedUrl(page, logDebug) {
  let baseUrl = DPasteBaseUrl;
  try {
    const current = page.url();
    if (current) {
      baseUrl = current;
    }
  } catch (_) {}

  let candidates = [];
  try {
    candidates = await page.evaluate(() => {
      const results = [];
      const push = (val) => {
        if (!val) return;
        const text = String(val).trim();
        if (!text) return;
        results.push(text);
      };

      const selectors = [
        'input#id_shortlink',
        'input#id_permalink',
        'input[name="permalink"]',
        'input[name="shortlink"]',
        'input[name="short_url"]',
        'input[readonly][value*="dpaste.org"]',
        'textarea#id_permalink',
        'textarea[name="permalink"]'
      ];
      selectors.forEach((selector) => {
        const el = document.querySelector(selector);
        if (!el) return;
        push(el.value || el.getAttribute('value') || '');
      });

      document.querySelectorAll('a[href]').forEach((el) => {
        const rawHref = el.getAttribute('href') || '';
        if (!rawHref || /^\s*$/.test(rawHref)) {
          return;
        }
        if (/^javascript:/i.test(rawHref)) {
          return;
        }
        push(rawHref);
        try {
          const abs = new URL(rawHref, window.location.href).toString();
          push(abs);
        } catch (_) {}
      });

      const canonical = document.querySelector('link[rel="canonical"]');
      if (canonical) {
        push(canonical.href || canonical.getAttribute('href') || '');
      }
      const metaOg = document.querySelector('meta[property="og:url"], meta[name="og:url"]');
      if (metaOg) {
        push(metaOg.content || metaOg.getAttribute('content') || '');
      }

      const shareInput = document.querySelector('input[type="text"][value^="https://dpaste.org"], input[type="url"]');
      if (shareInput) {
        push(shareInput.value || shareInput.getAttribute('value') || '');
      }

      return results;
    });
  } catch (_) {
    candidates = [];
  }

  if (!Array.isArray(candidates)) {
    candidates = [];
  }

  if (typeof logDebug === 'function') {
    logDebug('dpaste url candidates', { candidates, baseUrl });
  }

  const seen = new Set();
  for (const item of candidates) {
    const normalized = normalizeDpasteUrl(item, baseUrl);
    if (!normalized || seen.has(normalized)) {
      continue;
    }
    seen.add(normalized);
    return normalized;
  }

  // Fallback: scan entire DOM for snippet-like URLs
  try {
    const html = await page.content();
    const regex = /https?:\/\/dpaste\.org\/([A-Za-z0-9]{2,})(?:\b|\/|\?|#)/g;
    let match;
    const found = [];
    while ((match = regex.exec(html)) !== null) {
      const id = match[1];
      if (!id) {
        continue;
      }
      const candidate = `https://dpaste.org/${id}`;
      if (!found.includes(candidate)) {
        found.push(candidate);
      }
    }
    if (found.length && typeof logDebug === 'function') {
      logDebug('dpaste url fallback matches', { found });
    }
    for (const url of found) {
      const normalized = normalizeDpasteUrl(url, DPasteBaseUrl);
      if (normalized) {
        return normalized;
      }
    }
  } catch (err) {
    if (typeof logDebug === 'function') {
      logDebug('dpaste url fallback error', { message: err && err.message });
    }
  }

  // fallback: use current page URL if it already points to dpaste snippet
  const fallback = normalizeDpasteUrl(baseUrl, DPasteBaseUrl);
  if (!fallback || fallback === DPasteBaseUrl || fallback === `${DPasteBaseUrl.replace(/\/?$/, '')}/`) {
    return '';
  }
  return fallback;
}

function ensureAnchorInMarkdown(markdown, pageUrl, anchorText) {
  let body = String(markdown || '').trim();
  const url = String(pageUrl || '').trim();
  if (!url) {
    return body;
  }
  if (body.includes(url)) {
    return body;
  }
  const anchor = String(anchorText || '').trim() || url;
  const safeAnchor = anchor.replace(/\]\(/g, ')').replace(/\[/g, '').trim();
  const linkLine = `[${safeAnchor || url}](${url})`;
  body = body ? `${body}\n\n${linkLine}\n` : `${linkLine}\n`;
  return body.trim();
}

async function publishToDpaste(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const { LOG_FILE, logLine, logDebug } = createLogger('dpaste');
  logLine('Publish start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

  const articleJob = {
    pageUrl,
    anchorText,
    language,
    openaiApiKey,
    aiProvider: provider,
    wish,
    meta: pageMeta,
    testMode: !!jobOptions.testMode
  };

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(articleJob, logLine);

  const titleSource = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString();
  const title = titleSource.trim().slice(0, 140) || 'PromoPilot статья';
  const author = (article.author || 'PromoPilot Автор').toString().trim().slice(0, 120);
  const htmlContent = String(article.htmlContent || '').trim();
  if (!htmlContent) {
    throw new Error('EMPTY_ARTICLE_CONTENT');
  }

  const variants = {
    html: htmlContent,
    markdown: htmlToMarkdown(htmlContent),
    plain: htmlToPlainText(htmlContent)
  };

  let markdownBody = variants.markdown || variants.plain || variants.html;
  if (!markdownBody) {
    throw new Error('FAILED_TO_PREPARE_CONTENT');
  }
  markdownBody = ensureAnchorInMarkdown(markdownBody, pageUrl, anchorText);
  if (markdownBody.length > 25000) {
    markdownBody = markdownBody.slice(0, 25000);
  }

  const linkStats = analyzeLinks(htmlContent, pageUrl, anchorText);
  logDebug('Article link stats', linkStats);

  const verification = createVerificationPayload({
    pageUrl,
    anchorText,
    article,
    variants: { ...variants, markdown: markdownBody },
    extraTexts: [title, author]
  });

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
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

    logLine('Goto dpaste', { url: 'https://dpaste.org/' });
    await page.goto('https://dpaste.org/', { waitUntil: 'networkidle2' });

    await page.waitForSelector('form textarea[name="content"], form textarea#id_content', { timeout: 20000 });

    logLine('Fill title');
    await page.evaluate((value) => {
      const el = document.querySelector('input[name="title"], input#id_title, input[placeholder*="Title" i]');
      if (el) {
        el.value = value;
        try {
          ['input', 'change', 'keyup', 'blur'].forEach((evt) => {
            el.dispatchEvent(new Event(evt, { bubbles: true }));
          });
        } catch (_) {}
      }
    }, title);
    await waitForTimeoutSafe(page, 80);

    logLine('Fill author');
    await page.evaluate((value) => {
      const el = document.querySelector('input[name="author"], input#id_author, input[placeholder*="Author" i]');
      if (el) {
        el.value = value;
        try {
          ['input', 'change', 'keyup', 'blur'].forEach((evt) => {
            el.dispatchEvent(new Event(evt, { bubbles: true }));
          });
        } catch (_) {}
      }
    }, author);
    await waitForTimeoutSafe(page, 60);

    logLine('Fill content (markdown)', { length: markdownBody.length });
    await page.evaluate((value) => {
      const el = document.querySelector('textarea[name="content"], textarea#id_content');
      if (el) {
        el.value = value;
        try {
          ['input', 'change', 'keyup', 'blur', 'paste'].forEach((evt) => {
            el.dispatchEvent(new Event(evt, { bubbles: true }));
          });
        } catch (_) {}
      }
    }, markdownBody);
    await waitForTimeoutSafe(page, 120);

    logLine('Select syntax markdown');
    try {
      await page.select('select[name="lexer"], select#id_lexer', '_markdown');
    } catch (err) {
      logDebug('Select syntax failed', { message: err && err.message });
    }

    logLine('Select expiry 1 year');
    try {
      await page.select('select[name="expires"], select#id_expires', '31536000');
    } catch (err) {
      logDebug('Select expiry failed', { message: err && err.message });
    }

    if (jobOptions.testMode) {
      logLine('Test mode delay before submit');
      await waitForTimeoutSafe(page, 500);
    }

    logLine('Submit form');
  const navigationPromise = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
    try {
      const clicked = await page.evaluate(() => {
        const btn = document.querySelector('form button[type="submit"], form button.btn-primary, form input[type="submit"]');
        if (btn) {
          btn.click();
          return true;
        }
        return false;
      });
      if (!clicked) {
        throw new Error('SUBMIT_BUTTON_NOT_FOUND');
      }
    } catch (err) {
      logLine('Submit click error', { error: String(err && err.message || err) });
      throw err;
    }

    await navigationPromise;
    await waitForTimeoutSafe(page, 500);

    try {
      logDebug('dpaste post-submit url', { url: await page.url() });
    } catch (_) {}

  const publishedUrl = await resolvePublishedUrl(page, logDebug);
    if (!publishedUrl) {
      throw new Error('FAILED_TO_RESOLVE_URL');
    }

    logLine('Publish success', { publishedUrl });

    await browser.close();

    return {
      ok: true,
      network: 'dpaste',
      title,
      author,
      publishedUrl,
      format: 'markdown',
      logFile: LOG_FILE,
      verification
    };
  } catch (error) {
    try {
      await browser.close();
    } catch (_) {}
    logLine('Publish failed', { error: String(error && error.message || error) });
    return {
      ok: false,
      network: 'dpaste',
      error: String(error && error.message || error),
      logFile: LOG_FILE
    };
  }
}

module.exports = { publish: publishToDpaste };

if (require.main === module) {
  (async () => {
    const { createLogger } = require('./lib/logger');
    const { LOG_FILE, logLine } = createLogger('dpaste-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: raw.length });
      const job = JSON.parse(raw);
      logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });

      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'dpaste', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'dpaste', logFile: LOG_FILE };
        logLine('Run failed (missing api key)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }

      const res = await publishToDpaste(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
      logLine('Success result', res);
      console.log(JSON.stringify(res));
      process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'dpaste', logFile: LOG_FILE };
      logLine('Run failed', payload);
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}
