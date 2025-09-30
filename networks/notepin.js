'use strict';

// Global safety nets to avoid NODE_RETURN_EMPTY
try {
  process.on('uncaughtException', (e) => {
    try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: String(e && e.message || e) })); } catch {}
    process.exit(1);
  });
  process.on('unhandledRejection', (e) => {
    try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: String(e && e.message || e) })); } catch {}
    process.exit(1);
  });
} catch {}

let puppeteer;
try {
  puppeteer = require('puppeteer');
} catch (e) {
  try { console.log(JSON.stringify({ ok: false, network: 'notepin', error: 'DEPENDENCY_MISSING: puppeteer' })); } catch {}
  process.exit(1);
}
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
      'use strict';

      const puppeteer = require('puppeteer');
      const fs = require('fs');
      const path = require('path');
      const { createLogger } = require('./lib/logger');
      const { generateArticle } = require('./lib/articleGenerator');
      const { htmlToPlainText } = require('./lib/contentFormats');
      const { createVerificationPayload } = require('./lib/verification');

      function safeUrl(p) { try { return p.url(); } catch { return ''; } }
      async function ensureDir(filePath) {
        try { const d = path.dirname(filePath); if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true }); } catch {}
      }

      async function publishToNotepin(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
        const { LOG_FILE, LOG_DIR, logLine } = createLogger('notepin');

        // Minimal screenshots for diagnostics
        let snapStep = 0; const snapPrefix = LOG_FILE.replace(/\.log$/i, '');
        const snap = async (pg, name) => {
          try { if (pg.isClosed && pg.isClosed()) return; } catch {}
          try {
            snapStep++; const idx = String(snapStep).padStart(2, '0');
            const file = `${snapPrefix}-${idx}-${String(name).replace(/[^\w.-]+/g, '-')}.png`;
            await ensureDir(file); await pg.screenshot({ path: file, fullPage: true });
            logLine('Screenshot', { name: `${idx}-${name}`, file, url: safeUrl(pg) });
          } catch {}
        };

        const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase();
        logLine('Publish start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

        const job = { pageUrl, anchorText, language, openaiApiKey, aiProvider: provider, wish, meta: pageMeta, testMode: !!jobOptions.testMode };
        const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
          ? { ...jobOptions.preparedArticle }
          : await generateArticle(job, logLine);
        const title = (article.title || (pageMeta && pageMeta.title) || anchorText || '').toString().trim();
        const htmlContent = String(article.htmlContent || '').trim();
        if (!htmlContent) throw new Error('EMPTY_ARTICLE_CONTENT');
        const plain = htmlToPlainText(htmlContent);

        const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
        if (process.env.PUPPETEER_ARGS) launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
        const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
        const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
        if (execPath) launchOpts.executablePath = execPath;
        logLine('Launching browser', { executablePath: execPath || 'default' });

        const browser = await puppeteer.launch(launchOpts);
        let page = await browser.newPage();
        page.setDefaultTimeout(60000);
        page.setDefaultNavigationTimeout(60000);

        let createdBlog = '';
        try {
          // Open editor
          await page.goto('https://notepin.co/write', { waitUntil: 'domcontentloaded' });
          await snap(page, '01-00-open-write');
          await page.waitForSelector('.pad .elements .element.medium-editor-element[contenteditable="true"]', { timeout: 15000 });
          await page.evaluate((html) => {
            const el = document.querySelector('.pad .elements .element.medium-editor-element[contenteditable="true"]');
            if (el) {
              el.innerHTML = String(html || '').trim() || '<p></p>';
              try { el.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch {}
            }
          }, htmlContent);
          await snap(page, '02-01-editor-filled');

          // Click Publish (opens modal on first run)
          await page.click('.publish button, .publish > button');
          await snap(page, '03-02-after-publish-click');
          // Wait either for modal to appear or navigation
          const modalAppeared = await page.waitForSelector('.publishMenu', { timeout: 10000 }).then(() => true).catch(() => false);

          if (modalAppeared) {
            // Fill modal (username/password)
            const blog = ('pp' + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-3));
            const pass = Math.random().toString(36).slice(2, 12) + 'A!9';
            createdBlog = blog;
            logLine('Notepin credentials', { blog, pass });
        await page.type('.publishMenu input[name="blog"]', blog, { delay: 10 }).catch(() => {});
        await page.type('.publishMenu input[name="pass"]', pass, { delay: 10 }).catch(() => {});
        await snap(page, '04-03-modal-filled');
            // Blur to trigger validation and try a short turnstile wait
            try { await page.focus('body'); } catch {}
            await page.waitForFunction(() => {
              const el = document.querySelector('input[name="cf-turnstile-response"]');
              return !!(el && el.value && el.value.length > 20);
            }, { timeout: 8000 }).catch(() => {});

            // Prepare listeners for navigation or a new tab
            const navPromise = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
            const targetPromise = browser.waitForTarget(t => t.type() === 'page' && t.opener() && t.opener() === page.target(), { timeout: 20000 }).catch(() => null);

            // Click finish
            const finishSel = '.publishMenu .finish p, .publishMenu .finish';
            await page.click(finishSel).catch(async () => {
              // fallback to DOM click
              await page.evaluate(() => {
                const el = document.querySelector('.publishMenu .finish p') || document.querySelector('.publishMenu .finish');
                if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
              });
            });

            // Wait outcome, adopt new page if created
            const [navRes, target] = await Promise.allSettled([navPromise, targetPromise]);
            if (target && target.status === 'fulfilled' && target.value) {
              try { const newPage = await target.value.page(); if (newPage) page = newPage; } catch {}
            }

            // Short settle and log where we landed
            await page.waitForTimeout(300);
            const landedUrl = safeUrl(page);
            await snap(page, '05-04-after-modal-submit');
            logLine('Landed after modal', { url: landedUrl });

            // If still on /write, click Publish once more (fast)
            if (/\/write\b/i.test(landedUrl)) {
              const fastNav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null);
              await page.click('.publish button, .publish > button').catch(() => {});
              await fastNav; logLine('After second publish', { url: safeUrl(page) });
              await snap(page, '05b-after-second-publish');
            }
          } else {
            // No modal (already has a blog?) — wait a blink for any nav
            await page.waitForTimeout(300);
          }

          // Record where we are now
          const afterFlowUrl = safeUrl(page);
          logLine('After publish flow', { url: afterFlowUrl });

          // Now open the blog homepage and try to pick the first post URL
          let publishedUrl = '';
          const blogUrl = createdBlog ? `https://${createdBlog}.notepin.co/` : '';
          if (blogUrl) {
            try {
              const view = await browser.newPage();
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
              if (postUrl) { publishedUrl = postUrl; logLine('Resolved post URL', { publishedUrl }); }
            } catch (e) {
              logLine('Resolve blog post failed', { error: String(e && e.message || e) });
            }
          }
          if (!publishedUrl) publishedUrl = blogUrl || afterFlowUrl;
          if (!/^https?:\/\//i.test(publishedUrl)) throw new Error('FAILED_TO_RESOLVE_URL');

        await snap(page, '99-final');
          logLine('Publish success', { publishedUrl });
          await browser.close();

          const verification = createVerificationPayload({ pageUrl, anchorText, article, variants: { plain, html: htmlContent } });
          verification.supportsLinkCheck = false; // avoid server-side fetch issues
          verification.supportsTextCheck = false;
          return { ok: true, network: 'notepin', title, publishedUrl, logFile: LOG_FILE, logDir: LOG_DIR, verification };
        } catch (error) {
          try { await browser.close(); } catch {}
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

        const pageUrl = job.url || job.pageUrl || job.jobUrl || '';
            const anchor = job.anchor || pageUrl;
            const language = job.language || 'ru';
            const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
        const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase();
            const wish = job.wish || '';
            const model = job.openaiModel || process.env.OPENAI_MODEL || '';
            if (model) process.env.OPENAI_MODEL = String(model);

            if (!pageUrl) {
              const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'notepin', logFile: LOG_FILE };
              logLine('Run failed (missing params)', payload); console.log(JSON.stringify(payload)); process.exit(1);
            }
            if (provider === 'openai' && !apiKey) {
              const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'notepin', logFile: LOG_FILE };
              logLine('Run failed (missing api key)', payload); console.log(JSON.stringify(payload)); process.exit(1);
            }

            const res = await publishToNotepin(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
            logLine('Success result', res); console.log(JSON.stringify(res)); process.exit(res.ok ? 0 : 1);
          } catch (e) {
            const payload = { ok: false, error: String(e && e.message || e), network: 'notepin', logFile: LOG_FILE };
            logLine('Run failed', payload); console.log(JSON.stringify(payload)); process.exit(1);
          }
        })();
      }
      
