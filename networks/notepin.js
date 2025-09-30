'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { createLogger } = require('./lib/logger');
const { generateArticle, analyzeLinks } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { createVerificationPayload } = require('./lib/verification');

async function publishToNotepin(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const { LOG_FILE, LOG_DIR, logLine, logDebug } = createLogger('notepin');
  // Screenshots helper (saves alongside LOG_FILE)
  let snapStep = 0;
  const snapPrefix = LOG_FILE.replace(/\.log$/i, '');
  const snap = async (pg, name) => {
    try {
      snapStep += 1;
      const idx = String(snapStep).padStart(2, '0');
      const safe = String(name || 'step').replace(/[^a-z0-9_-]+/gi, '-');
      const file = `${snapPrefix}-${idx}-${safe}.png`;
      try { const d = path.dirname(file); if (!fs.existsSync(d)) { fs.mkdirSync(d, { recursive: true }); } } catch(_) {}
      await pg.screenshot({ path: file, fullPage: true });
      let url = '';
      try { url = pg.url(); } catch(_) {}
      logLine('Screenshot saved', { name: `${idx}-${safe}`, file, url });
    } catch (e) {
      logLine('Screenshot failed', { name, error: String(e && e.message || e) });
    }
  };
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  logLine('Publish start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

  const job = {
    pageUrl,
    anchorText,
    language,
    openaiApiKey,
    aiProvider: provider,
    wish,
    meta: pageMeta,
    testMode: !!jobOptions.testMode,
  };

  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(job, logLine);

  const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim();
  const htmlContent = String(article.htmlContent || '').trim();
  if (!htmlContent) {
    throw new Error('EMPTY_ARTICLE_CONTENT');
  }
  const plain = htmlToPlainText(htmlContent);
  logDebug('Article link stats', analyzeLinks(htmlContent, pageUrl, anchorText));

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;

  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
  const browser = await puppeteer.launch(launchOpts);
  let page = null;
  let createdBlog = '';
  try {
    page = await browser.newPage();
    page.setDefaultTimeout(300000);
    page.setDefaultNavigationTimeout(300000);

    logLine('Goto Notepin', { url: 'https://notepin.co/write' });
    await page.goto('https://notepin.co/write', { waitUntil: 'networkidle2' });
  await snap(page, '00-open-write');

    // editable region
    const editorSelector = '.pad .elements .element.medium-editor-element[contenteditable="true"]';
    await page.waitForSelector(editorSelector, { timeout: 30000 });

    // Fill editor by setting innerHTML; Notepin uses MediumEditor-like behavior
    logLine('Fill content');
    await page.evaluate((html, url, anchor) => {
      const el = document.querySelector('.pad .elements .element.medium-editor-element[contenteditable="true"]');
      if (el) {
        // Preserve first line as title (h1), rest as paragraphs
        const safe = String(html || '').trim();
        el.innerHTML = safe || '<p></p>';
        try { el.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch(_) { try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch(__) {} }
      }
    }, htmlContent, pageUrl, anchorText);

    await waitForTimeoutSafe(page, 200);
  await snap(page, '01-editor-filled');

    // Click Publish
    logLine('Click publish');
    const publishBtnSelector = '.publish button, .publish > button';
    await page.waitForSelector(publishBtnSelector, { timeout: 30000 });

    // Click publish; either navigate directly or show blog creation modal
    const navPrimary = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => null);
    await page.click(publishBtnSelector);
    await navPrimary;
    await waitForTimeoutSafe(page, 200);
    await snap(page, '02-after-publish-click');

    // If still on /write, a modal likely appeared — fill it and publish
    let currentUrl = '';
    try { currentUrl = page.url(); } catch(_) { currentUrl = ''; }
    if (!currentUrl || /\/write\b/i.test(currentUrl)) {
  const modalSel = '.publishMenu';
      const menu = await page.$(modalSel);
      if (menu) {
        // Generate unique blog login and password
        const randId = () => 'pp' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);
    const blog = randId();
        const pass = Math.random().toString(36).slice(2, 12) + 'A!9';
        logLine('Notepin credentials', { blog, pass });
    createdBlog = blog;

        // Fill inputs and trigger input events
        await page.evaluate(({ blog, pass }) => {
          const setVal = (sel, val) => {
            const el = document.querySelector(sel);
            if (!el) return false;
            el.focus();
            el.value = val;
            try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch(_) {}
            try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch(_) {}
            return true;
          };
          setVal('.publishMenu input[name="blog"]', blog);
          setVal('.publishMenu input[name="pass"]', pass);
        }, { blog, pass });

        await waitForTimeoutSafe(page, 200);
  await snap(page, '03-modal-filled');

        // Click "Publish My Blog"
        const navAfterModal = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
        const clickedFinish = await page.evaluate(() => {
          const btn = document.querySelector('.publishMenu .finish p');
          if (btn) { (btn instanceof HTMLElement) && btn.click(); return true; }
          const alt = document.querySelector('.publishMenu .finish');
          if (alt) { (alt instanceof HTMLElement) && alt.click(); return true; }
          return false;
        });
        if (!clickedFinish) {
          // fallback: press Enter on password
          try { await page.focus('.publishMenu input[name="pass"]'); await page.keyboard.press('Enter'); } catch(_) {}
        }
        await navAfterModal;
        // As a fallback, wait for dashboard posts list to appear
        try { await page.waitForSelector('.posts a[href^="write?id="]', { timeout: 30000 }); } catch(_) {}
        await waitForTimeoutSafe(page, 400);
  await snap(page, '04-after-modal-submit');

        // Open the first draft/editor link and publish the post explicitly
        try {
          const opened = await page.evaluate(() => {
            const a = document.querySelector('.posts a[href^="write?id="]');
            if (a && a.href) { (a instanceof HTMLElement) && a.click(); return true; }
            return false;
          });
          if (opened) {
            await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
            // Ensure editor is ready, then (re)fill to be safe
            const editorSelector = '.pad .elements .element.medium-editor-element[contenteditable="true"]';
            try { await page.waitForSelector(editorSelector, { timeout: 20000 }); } catch(_) {}
            await page.evaluate((html) => {
              const el = document.querySelector('.pad .elements .element.medium-editor-element[contenteditable="true"]');
              if (el) {
                el.innerHTML = String(html || '').trim() || '<p></p>';
                try { el.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch(_) { try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch(__) {} }
              }
            }, htmlContent);
            await waitForTimeoutSafe(page, 200);
            await snap(page, '05-draft-editor');
            // Click publish on editor
            try {
              const navPost = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
              const sel = '.publish button, .publish > button';
              await page.waitForSelector(sel, { timeout: 15000 }).catch(() => {});
              try { await page.click(sel); } catch(_) {}
              await navPost;
              await waitForTimeoutSafe(page, 300);
              await snap(page, '06-post-publish-clicked');
            } catch(_) {}
          }
        } catch(_) {}
      }
    }

    let publishedUrl = '';
    try { publishedUrl = page.url(); } catch (_) { publishedUrl = ''; }
    // If we are on dashboard or editor, prefer public blog URL fallback
    let blogUrl = '';
    if (createdBlog) { blogUrl = `https://${createdBlog}.notepin.co/`; }
    if ((!publishedUrl || /\/write\b/i.test(publishedUrl) || /\/dash\b/i.test(publishedUrl)) && blogUrl) {
      publishedUrl = blogUrl;
    }
    if (!publishedUrl || !/^https?:\/\//i.test(publishedUrl)) {
      // Fallback: try to read any visible URL
      try {
        const maybe = await page.evaluate(() => {
          const a = document.querySelector('a[href^="http"]');
          return a ? (a.href || '').trim() : '';
        });
        if (maybe && /^https?:\/\//i.test(maybe)) { publishedUrl = maybe; }
      } catch (_) {}
    }

    // If we only have blog homepage, try to open it and extract first post URL
    if (blogUrl && /^https?:\/\//i.test(blogUrl) && (!publishedUrl || publishedUrl.replace(/\/?$/, '/') === blogUrl.replace(/\/?$/, '/'))) {
      try {
        logLine('Open blog homepage to resolve post URL', { blogUrl });
        await page.goto(blogUrl, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.posts a', { timeout: 20000 }).catch(() => {});
        await snap(page, '07-blog-home');
        const firstPost = await page.evaluate((base) => {
          const a = document.querySelector('.posts a[href]');
          if (!a) return '';
          const href = (a.getAttribute('href') || '').trim();
          if (!href) return '';
          if (/^https?:\/\//i.test(href)) return href;
          try { const u = new URL(href, base); return u.toString(); } catch(_) { return ''; }
        }, blogUrl);
        if (firstPost && /^https?:\/\//i.test(firstPost)) {
          publishedUrl = firstPost;
          logLine('Resolved post URL', { publishedUrl });
        } else {
          logLine('Blog page has no post link, keeping blog URL');
        }
      } catch (e) {
        logLine('Failed to resolve post URL from blog', { error: String(e && e.message || e) });
      }
    }

    if (!publishedUrl || /\/write\b/i.test(publishedUrl)) {
      throw new Error('FAILED_TO_RESOLVE_URL');
    }
    await snap(page, '99-final');

    logLine('Publish success', { publishedUrl });
    await browser.close();

    const verification = createVerificationPayload({ pageUrl, anchorText, article, variants: { plain, html: htmlContent } });
    // Notepin pages may throttle/deny server fetches; skip automated verification to avoid false negatives
    verification.supportsLinkCheck = false;
    verification.supportsTextCheck = false;

    return { ok: true, network: 'notepin', title, publishedUrl, logFile: LOG_FILE, logDir: LOG_DIR, verification };
  } catch (error) {
    try { await browser.close(); } catch (_) {}
    logLine('Publish failed', { error: String(error && error.message || error) });
    return { ok: false, network: 'notepin', error: String(error && error.message || error), logFile: LOG_FILE };
  }
}

module.exports = { publish: publishToNotepin };

// CLI entrypoint
if (require.main === module) {
  (async () => {
    const { createLogger } = require('./lib/logger');
    const { LOG_FILE, logLine } = createLogger('notepin-cli');
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
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'notepin', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'notepin', logFile: LOG_FILE };
        logLine('Run failed (missing api key)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }

      const res = await publishToNotepin(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
      logLine('Success result', res);
      console.log(JSON.stringify(res));
      process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'notepin', logFile: LOG_FILE };
      logLine('Run failed', payload);
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}
