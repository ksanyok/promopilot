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
      if (typeof pg.isClosed === 'function' && pg.isClosed()) {
        let closedUrl = '';
        try { closedUrl = pg.url(); } catch(_) {}
        logLine('Screenshot skipped - page closed', { name, url: closedUrl });
        return;
      }
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

    // Helpers for Publish button state
    const readPublishBtnState = async () => {
      try {
        return await page.evaluate(() => {
          const btn = document.querySelector('.publish button, .publish > button');
          if (!btn) return { exists: false, text: '', disabled: false };
          const text = (btn.textContent || '').trim();
          const disabled = !!(btn instanceof HTMLButtonElement && btn.disabled);
          return { exists: true, text, disabled };
        });
      } catch (_) {
        return { exists: false, text: '', disabled: false };
      }
    };
    const waitForPublishLoadingToSettle = async (label, timeoutMs = 120000) => {
      const start = Date.now();
      let last = await readPublishBtnState();
      logLine('Publish button state (start)', { label, ...last });
      while (Date.now() - start < timeoutMs) {
        const st = await readPublishBtnState();
        if (st.exists && !st.disabled && !/loading/i.test(st.text)) {
          logLine('Publish button ready', { label, ...st });
          return true;
        }
        if (JSON.stringify(st) !== JSON.stringify(last)) {
          logLine('Publish button state (change)', { label, ...st });
          last = st;
        }
        await waitForTimeoutSafe(page, 800);
      }
      const final = await readPublishBtnState();
      logLine('Publish button wait timed out', { label, ...final });
      return false;
    };

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

        // Trigger validation (blur inputs) so username uniqueness check runs
        try {
          await page.evaluate(() => {
            const u = document.querySelector('.publishMenu input[name="blog"]');
            const p = document.querySelector('.publishMenu input[name="pass"]');
            if (u instanceof HTMLElement) u.blur();
            if (p instanceof HTMLElement) p.blur();
          });
          await waitForTimeoutSafe(page, 200);
        } catch(_) {}

        // Ensure username is accepted (remove invalid). Retry a couple times by tweaking username.
        try {
          for (let i = 0; i < 3; i++) {
            const invalid = await page.evaluate(() => {
              const el = document.querySelector('.publishMenu input[name="blog"]');
              return !!(el && el.classList && el.classList.contains('invalid'));
            });
            if (!invalid) break;
            const suffix = Math.random().toString(36).slice(2, 5);
            await page.evaluate((suf) => {
              const el = document.querySelector('.publishMenu input[name="blog"]');
              if (el) {
                el.value = `${el.value || ''}${suf}`;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
                (el instanceof HTMLElement) && el.blur();
              }
            }, suffix);
            await waitForTimeoutSafe(page, 500);
          }
          const finalInvalid = await page.evaluate(() => {
            const el = document.querySelector('.publishMenu input[name="blog"]');
            return !!(el && el.classList && el.classList.contains('invalid'));
          });
          logLine('Modal: username validation', { invalid: finalInvalid });
        } catch(_) {}

        // Wait for Cloudflare Turnstile token (if present) to be ready to reduce anti-bot flakiness
        try {
          await page.waitForFunction(() => {
            const el = document.querySelector('input[name="cf-turnstile-response"]');
            return !!(el && el.value && el.value.length > 20);
          }, { timeout: 25000 });
          logLine('Modal: Turnstile token ready');
        } catch(_) {
          logLine('Modal: Turnstile token not detected or not ready in time — proceeding');
        }

        // Click "Publish My Blog" and handle either same-tab navigation or a popup/new tab.
        // We'll try up to 2 attempts in case the first click is ignored by anti-bot.
        for (let attempt = 1; attempt <= 2; attempt++) {
          logLine('Modal: click finish and wait for navigation/new tab', { attempt });
          try { await page.waitForSelector('.publishMenu .finish p, .publishMenu .finish', { visible: true, timeout: 20000 }); } catch(_) {}
          // Scroll into view
          try { await page.evaluate(() => { const el = document.querySelector('.publishMenu .finish p') || document.querySelector('.publishMenu .finish'); if (el) el.scrollIntoView({ block: 'center' }); }); } catch(_) {}
          const targetPromise = (async () => {
            try {
              return await browser.waitForTarget(t => t.type() === 'page' && /notepin\.co/i.test(t.url()), { timeout: 60000 });
            } catch { return null; }
          })();
          const navAfterModal = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null);
          let clicked = false;
          // Try real mouse click at center to avoid event blockers
          try {
            const handle = await page.$('.publishMenu .finish p') || await page.$('.publishMenu .finish');
            if (handle) {
              const box = await handle.boundingBox();
              if (box) {
                await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
                await waitForTimeoutSafe(page, 50);
                await page.mouse.down();
                await waitForTimeoutSafe(page, 30);
                await page.mouse.up();
                clicked = true;
              }
            }
          } catch(_) {}
          // Fallback to page.click if bounding box failed
          if (!clicked) { try { await page.click('.publishMenu .finish p', { delay: 40 }); clicked = true; } catch(_) {} }
          if (!clicked) { try { await page.click('.publishMenu .finish', { delay: 40 }); clicked = true; } catch(_) {} }
          // As a last resort, dispatch DOM click
          if (!clicked) {
            try {
              await page.evaluate(() => {
                const el = document.querySelector('.publishMenu .finish p') || document.querySelector('.publishMenu .finish');
                if (el) {
                  const evt = new MouseEvent('click', { bubbles: true, cancelable: true, view: window });
                  el.dispatchEvent(evt);
                }
              });
              clicked = true;
            } catch(_) {}
          }
          if (!clicked) {
            // fallback: press Enter on password
            try { await page.focus('.publishMenu input[name="pass"]'); await page.keyboard.press('Enter'); clicked = true; } catch(_) {}
          }

          // Wait for either navigation or popup
          const target = await targetPromise;
          await navAfterModal;

          // Adopt new page if popup opened or original closed
          let adopted = false;
          if (target && typeof target.page === 'function') {
            try {
              const newPg = await target.page();
              if (newPg) {
                if (page && typeof page.isClosed === 'function' && !page.isClosed() && newPg !== page) {
                  let curUrl = '';
                  try { curUrl = page.url(); } catch(_) {}
                  if (/\/write\b/i.test(curUrl)) {
                    page = newPg; adopted = true;
                  }
                } else {
                  page = newPg; adopted = true;
                }
              }
            } catch(_) {}
          }
          if (page && typeof page.isClosed === 'function' && page.isClosed()) {
            // Original page was closed; pick another open notepin page
            try {
              const pages = await browser.pages();
              const cand = pages.reverse().find(p => { try { return /notepin\.co/i.test(p.url()); } catch(_) { return false; } });
              if (cand) { page = cand; adopted = true; }
            } catch(_) {}
          }
          await waitForTimeoutSafe(page, 400);
          let afterFinishUrl = '';
          try { afterFinishUrl = page.url(); } catch(_) {}
          // Check if modal is gone
          let modalStillVisible = true;
          try {
            await page.waitForFunction(() => {
              const el = document.querySelector('.publishMenu');
              if (!el) return true;
              const styleNone = getComputedStyle(el).display === 'none' || getComputedStyle(el).visibility === 'hidden' || el.classList.contains('bringUp');
              return styleNone;
            }, { timeout: 8000 });
            modalStillVisible = false;
          } catch(_) {
            try {
              modalStillVisible = await page.$eval('.publishMenu', el => !!el && (getComputedStyle(el).display !== 'none') && !el.classList.contains('bringUp'));
            } catch { modalStillVisible = false; }
          }
          logLine('After finish click', { attempt, url: afterFinishUrl, adoptedNewPage: adopted, modalStillVisible });
          await snap(page, '04-after-modal-submit');
          if (!modalStillVisible) break;
        }

        // If modal is gone but we remained on /write (common pattern), try to click publish again to publish the post
        try {
          let nowUrl = '';
          try { nowUrl = page.url(); } catch(_) {}
          if (/\/write\b/i.test(nowUrl)) {
            logLine('Still on /write after modal — clicking Publish again');
            const sel = '.publish button, .publish > button';
            await page.waitForSelector(sel, { timeout: 15000 }).catch(() => {});
            const navPost = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
            try { await page.click(sel); } catch(_) {}
            await navPost;
            // Wait for Loading.. to finish if button exists
            await waitForPublishLoadingToSettle('after-second-publish-from-write', 90000);
            let afterSecondPublish = '';
            try { afterSecondPublish = page.url(); } catch(_) {}
            logLine('After second publish click', { url: afterSecondPublish });
            await snap(page, '05b-after-second-publish');
          }
        } catch(_) {}

        // Open the first draft/editor link and publish the post explicitly
        try {
          let dashUrl = '';
          try { dashUrl = page.url(); } catch(_) {}
          logLine('Dashboard ready, looking for draft link', { url: dashUrl });
          const opened = await page.evaluate(() => {
            const a = document.querySelector('.posts a[href*="write?id="]');
            if (a && a instanceof HTMLElement) { a.click(); return true; }
            return false;
          });
          if (opened) {
            const draftTarget = (async () => {
              try { return await window.__pp_noop__, null; } catch(_) { return null; }
            })();
            // Handle potential same-tab nav
            await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
            // If a new tab opened for editor, adopt it
            try {
              const pages = await browser.pages();
              const cand = pages.reverse().find(p => { try { return /notepin\.co\/.+write\?id=/i.test(p.url()); } catch(_) { return false; } });
              if (cand) page = cand;
            } catch(_) {}
            // Ensure editor is ready, then (re)fill to be safe
            let draftUrl = '';
            try { draftUrl = page.url(); } catch(_) {}
            logLine('Draft/editor opened', { url: draftUrl });
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
              let beforePostPublishUrl = '';
              try { beforePostPublishUrl = page.url(); } catch(_) {}
              logLine('About to publish post (editor)', { url: beforePostPublishUrl });
              const navPost = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null);
              const sel = '.publish button, .publish > button';
              await page.waitForSelector(sel, { timeout: 15000 }).catch(() => {});
              try { await page.click(sel); } catch(_) {}
              await navPost;
              // Wait for Loading.. to finish if button exists
              await waitForPublishLoadingToSettle('post-editor-publish', 120000);
              let afterPostPublishUrl = '';
              try { afterPostPublishUrl = page.url(); } catch(_) {}
              logLine('Post publish navigation complete', { url: afterPostPublishUrl });
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

    // If we only have blog homepage, try to open it in a fresh page and extract first post URL
    if (blogUrl && /^https?:\/\//i.test(blogUrl) && (!publishedUrl || publishedUrl.replace(/\/?$/, '/') === blogUrl.replace(/\/?$/, '/'))) {
      try {
        let pageClosed = false; try { pageClosed = page.isClosed(); } catch(_) {}
        logLine('Open blog homepage to resolve post URL', { blogUrl, pageClosed });
        const viewPage = await browser.newPage();
        viewPage.setDefaultTimeout(300000);
        viewPage.setDefaultNavigationTimeout(300000);
        await viewPage.goto(blogUrl, { waitUntil: 'networkidle2' });
        // Wait for any likely post link
        try { await viewPage.waitForSelector('.posts a, article a, a[href*="/p/"], a[href]', { timeout: 25000 }); } catch(_) {}
        await snap(viewPage, '07-blog-home');
        const firstPost = await viewPage.evaluate((base) => {
          const pick = () => {
            return document.querySelector('.posts a[href]')
                || document.querySelector('article a[href]')
                || Array.from(document.querySelectorAll('a[href]')).find(a => /\/(post|p)\//i.test(a.getAttribute('href')||''))
                || document.querySelector('a[href]');
          };
          const a = pick();
          if (!a) return '';
          const href = (a.getAttribute('href') || '').trim();
          if (!href) return '';
          if (/^https?:\/\//i.test(href)) return href;
          try { const u = new URL(href, base); return u.toString(); } catch(_) { return ''; }
        }, blogUrl);
        try { await viewPage.close(); } catch(_) {}
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
