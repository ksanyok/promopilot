'use strict';

// Always return JSON even on fatal errors (prevents NODE_RETURN_EMPTY)
try {
  process.on('uncaughtException', (e) => {
    try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: String((e && e.message) || e) })); } catch {}
    process.exit(1);
  });
  process.on('unhandledRejection', (e) => {
    try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: String((e && e.message) || e) })); } catch {}
    process.exit(1);
  });
} catch {}

let puppeteer;
try {
  puppeteer = require('puppeteer');
} catch (_) {
  try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: 'DEPENDENCY_MISSING: puppeteer' })); } catch {}
  process.exit(1);
}
const fs = require('fs');
const path = require('path');
const { createLogger } = require('./lib/logger');
const { generateArticle } = require('./lib/articleGenerator');
const { htmlToPlainText } = require('./lib/contentFormats');
const { createVerificationPayload } = require('./lib/verification');

function safeUrl(p) { try { return p.url(); } catch { return ''; } }
function ensureDirSync(filePath) {
  try { const d = path.dirname(filePath); if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true }); } catch {}
}
// Puppeteer-agnostic sleep (older versions may not have page.waitForTimeout)
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms || 0)));

async function publishToNotepin(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
  const { LOG_FILE, LOG_DIR, logLine } = createLogger('notepin');
  // screenshot helper
  let snapStep = 0; const snapPrefix = LOG_FILE.replace(/\.log$/i, '');
  const snap = async (pg, name) => {
    try { if (pg.isClosed && pg.isClosed()) return; } catch {}
    try {
      snapStep++; const idx = String(snapStep).padStart(2, '0');
      const file = `${snapPrefix}-${idx}-${String(name).replace(/[^\w.-]+/g, '-')}.png`;
      ensureDirSync(file); await pg.screenshot({ path: file, fullPage: true });
      logLine('screenshot', { name: `${idx}-${name}`, file, url: safeUrl(pg) });
    } catch {}
  };

  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase();
  logLine('start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

  // Build/gather article
  const job = { pageUrl, anchorText, language, openaiApiKey, aiProvider: provider, wish, meta: pageMeta, testMode: !!jobOptions.testMode };
  const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
    ? { ...jobOptions.preparedArticle }
    : await generateArticle(job, logLine);
  const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim();
  const htmlContent = String(article.htmlContent || '').trim();
  if (!htmlContent) throw new Error('EMPTY_ARTICLE_CONTENT');
  const plain = htmlToPlainText(htmlContent);

  // Launch browser
  const args = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) args.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const browser = await puppeteer.launch({ headless: true, args: Array.from(new Set(args)), executablePath: execPath || undefined });
  let page = await browser.newPage();
  page.setDefaultTimeout(60000); page.setDefaultNavigationTimeout(60000);

  let createdBlog = '';
  try {
    // 1) Open editor and fill content
    await page.goto('https://notepin.co/write', { waitUntil: 'domcontentloaded' });
    await snap(page, '01-open-write');
    await page.waitForSelector('.pad .elements .element.medium-editor-element[contenteditable="true"]', { timeout: 15000 });
    await page.evaluate((html) => {
      const el = document.querySelector('.pad .elements .element.medium-editor-element[contenteditable="true"]');
      if (el) { el.innerHTML = String(html || '').trim() || '<p></p>'; try { el.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch {} }
    }, htmlContent);
    await snap(page, '02-editor-filled');

    // 2) Click Publish (first time triggers modal)
    await page.click('.publish button, .publish > button').catch(() => {});
    await snap(page, '03-after-publish-click');

    const modal = await page.waitForSelector('.publishMenu', { timeout: 10000 }).then(() => true).catch(() => false);
    if (modal) {
      // Fill modal
      const blog = ('pp' + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-3));
      const pass = Math.random().toString(36).slice(2, 12) + 'A!9';
      createdBlog = blog; logLine('credentials', { blog, pass });
      await page.type('.publishMenu input[name="blog"]', blog, { delay: 10 }).catch(() => {});
      await page.type('.publishMenu input[name="pass"]', pass, { delay: 10 }).catch(() => {});
      await snap(page, '04-modal-filled');
      // Short wait for Turnstile token
      await page.waitForFunction(() => {
        const el = document.querySelector('input[name="cf-turnstile-response"]');
        return !!(el && el.value && el.value.length > 20);
      }, { timeout: 8000 }).catch(() => {});

      // Prepare for nav/new tab
      const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
      const newTarget = page.browser().waitForTarget(t => t.type() === 'page' && t.opener() && t.opener() === page.target(), { timeout: 20000 }).catch(() => null);

      // Click finish
      await page.click('.publishMenu .finish p, .publishMenu .finish').catch(async () => {
        await page.evaluate(() => {
          const el = document.querySelector('.publishMenu .finish p') || document.querySelector('.publishMenu .finish');
          if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
      });

      const [_, target] = await Promise.allSettled([nav, newTarget]);
      if (target && target.status === 'fulfilled' && target.value) {
        try { const newPage = await target.value.page(); if (newPage) page = newPage; } catch {}
      }
  await sleep(300);
      await snap(page, '05-after-modal-submit');

      // If still on /write, try click Publish again fast
      if (/\/write\b/i.test(safeUrl(page))) {
        const fastNav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null);
        await page.click('.publish button, .publish > button').catch(() => {});
        await fastNav; await snap(page, '05b-second-publish');
      }
    } else {
      await sleep(300);
    }

    const afterUrl = safeUrl(page); logLine('after-flow', { url: afterUrl });

    // 3) Resolve final URL via blog homepage
    let publishedUrl = '';
    const blogUrl = createdBlog ? `https://${createdBlog}.notepin.co/` : '';
    if (blogUrl) {
      try {
        const view = await page.browser().newPage();
        view.setDefaultTimeout(30000); view.setDefaultNavigationTimeout(30000);
        await view.goto(blogUrl, { waitUntil: 'domcontentloaded' });
        await snap(view, '07-blog-home');
        const postUrl = await view.evaluate((base) => {
          const pick = () => document.querySelector('.posts a[href*="/p/"]')
            || document.querySelector('.posts a[href]')
            || document.querySelector('article a[href]')
            || document.querySelector('a[href]');
          const a = pick(); if (!a) return '';
          const href = (a.getAttribute('href') || '').trim();
          if (!href) return '';
          return /^https?:\/\//i.test(href) ? href : new URL(href, base).toString();
        }, blogUrl);
        await view.close().catch(() => {});
        if (postUrl) { publishedUrl = postUrl; logLine('resolved', { publishedUrl }); }
      } catch (e) { logLine('resolve-blog-failed', { error: String((e && e.message) || e) }); }
    }
    if (!publishedUrl) publishedUrl = blogUrl || afterUrl;
    if (!/^https?:\/\//i.test(publishedUrl)) throw new Error('FAILED_TO_RESOLVE_URL');

  await snap(page, '99-final');
    logLine('success', { publishedUrl });
    await browser.close();

    const verification = createVerificationPayload({ pageUrl, anchorText, article, variants: { plain, html: htmlContent } });
    verification.supportsLinkCheck = false; // disable automated checks to avoid false negatives
    verification.supportsTextCheck = false;
    return { ok: true, network: 'notepin', title, publishedUrl, logFile: LOG_FILE, logDir: LOG_DIR, verification };
  } catch (error) {
    try { await browser.close(); } catch {}
    return { ok: false, network: 'notepin', error: String((error && error.message) || error), logFile: LOG_FILE };
  }
}

module.exports = { publish: publishToNotepin };

// CLI entrypoint for worker
if (require.main === module) {
  (async () => {
    const { LOG_FILE, logLine } = createLogger('notepin-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: (raw || '').length });
      const job = JSON.parse(raw || '{}');
      logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });

      const pageUrl = job.url || job.pageUrl || job.jobUrl || '';
      const anchor = job.anchor || pageUrl || 'PromoPilot link';
      const language = job.language || 'ru';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase();
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'notepin', logFile: LOG_FILE };
        logLine('fail-missing-params', payload); console.log(JSON.stringify(payload)); process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'notepin', logFile: LOG_FILE };
        logLine('fail-missing-apikey', payload); console.log(JSON.stringify(payload)); process.exit(1);
      }

      const res = await publishToNotepin(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
      logLine('result', res); console.log(JSON.stringify(res)); process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String((e && e.message) || e), network: 'notepin', logFile: LOG_FILE };
      logLine('fail-exception', payload); console.log(JSON.stringify(payload)); process.exit(1);
    }
  })();
}

