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
const { createLogger } = require('./lib/logger');
const { generateArticle, attachArticleToResult } = require('./lib/articleGenerator');

function safeUrl(p) { try { return p.url(); } catch { return ''; } }
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms || 0)));

async function loginNotepin(username, password, job = {}) {
  const { LOG_FILE, LOG_DIR, logLine } = createLogger('notepin');

  // No screenshots in this mode

  // Launch minimal browser
  const args = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) args.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const headless = String(process.env.PP_HEADLESS || 'true').toLowerCase() !== 'false';
  const browser = await puppeteer.launch({ headless, args: Array.from(new Set(args)), executablePath: execPath || undefined });
  const page = await browser.newPage();
  page.setDefaultTimeout(Number(process.env.PP_TIMEOUT_MS || 90000));
  page.setDefaultNavigationTimeout(Number(process.env.PP_NAV_TIMEOUT_MS || 90000));

  try {
    // 1) Open homepage
  await page.goto('https://notepin.co/', { waitUntil: 'domcontentloaded' });

    // 2) Open login modal
    await page.evaluate(() => {
      const el = document.querySelector('.menu .log, p.log');
      if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });
    await page.waitForSelector('.login', { timeout: 15000 });
    await page.waitForSelector('.login input[name="blog"]', { timeout: 15000 });
    await page.waitForSelector('.login input[name="pass"]', { timeout: 15000 });

    // 3) Fill credentials
    if (username) { await page.type('.login input[name="blog"]', String(username), { delay: 20 }); }
    if (password) { await page.type('.login input[name="pass"]', String(password), { delay: 20 }); }
  // Credentials filled

    // 4) Submit
    const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
    const hostChange = page.waitForFunction(() => /\.notepin\.co$/i.test(location.hostname) && location.hostname !== 'notepin.co', { timeout: 25000 }).catch(() => null);
    await page.click('.login .finish p, .login .finish').catch(async () => {
      await page.evaluate(() => {
        const el = document.querySelector('.login .finish p') || document.querySelector('.login .finish');
        if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      });
    });
  await Promise.race([nav, hostChange, sleep(1200)]);

    // If login-only requested, return right away
    if (/^(1|true|yes)$/i.test(String(process.env.PP_NOTEPIN_LOGIN_ONLY || ''))) {
      const finalUrl = safeUrl(page);
      await browser.close();
      return { ok: true, network: 'notepin', mode: 'login-only', username: username || '', finalUrl, logFile: LOG_FILE, logDir: LOG_DIR };
    }

  // 5) Should be on /dash

    // 6) Click "new post" and go to write page
    const navWrite = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
    await page.evaluate(() => {
      const el = document.querySelector('a[href="write"] .newPost') || document.querySelector('a[href="write"]') || document.querySelector('p.newPost');
      if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });
    await Promise.race([navWrite, sleep(1000)]);
    if (!/\/write\b/i.test(safeUrl(page) || '')) {
      // Fallback: direct navigation to /write
      try { const base = new URL(safeUrl(page) || 'https://notepin.co'); await page.goto(`${base.origin}/write`, { waitUntil: 'domcontentloaded' }); } catch {}
    }
  // On write page

    // 7) Generate or use provided article
    const meta = job.page_meta || job.meta || null;
    const genJob = {
      ...job,
      pageUrl: job.url || job.pageUrl || job.jobUrl || '',
      anchorText: job.anchor || 'PromoPilot link',
      language: job.language || 'ru',
      aiProvider: (job.aiProvider || process.env.PP_AI_PROVIDER || 'byoa').toLowerCase(),
      openaiApiKey: job.openaiApiKey || process.env.OPENAI_API_KEY || '',
      wish: job.wish || '',
      testMode: !!job.testMode,
      meta,
      page_meta: meta,
      disableImages: true,
    };
    let article = null;
    try {
      article = (job.preparedArticle && job.preparedArticle.htmlContent) ? { ...job.preparedArticle } : await generateArticle(genJob, logLine);
    } catch (_) {
      article = { title: 'PromoPilot Post', htmlContent: `<h2>PromoPilot Post</h2><p>Automated post content.</p>` };
    }
    const htmlContent = String(article.htmlContent || '<p></p>');
    const title = (article.title || (genJob.meta && genJob.meta.title) || genJob.anchorText || 'PromoPilot Post').toString().slice(0, 120);

    // 8) Fill editor
    await page.waitForSelector('.pad .elements .element.medium-editor-element[contenteditable="true"]', { timeout: 15000 });
    // Convert http(s) links to protocol-relative to avoid 'https' substring in href
    const contentForEditor = String(htmlContent || '')
      .replace(/(href\s*=\s*["'])https?:\/\//gi, '$1//')
      .replace(/(src\s*=\s*["'])https?:\/\//gi, '$1//')
      .replace(/(srcset\s*=\s*["'])([^"']*)(["'])/gi, (match, prefix, urls, suffix) => `${prefix}${urls.replace(/https?:\/\//gi, '//')}${suffix}`)
      // remove target and rel to reduce filtering by platform
      .replace(/\s+target\s*=\s*(["'])[^"]*?\1/gi, '')
      .replace(/\s+target\s*=\s*(['])(?:[^']*?)\1/gi, '')
      .replace(/\s+rel\s*=\s*(["'])[^"]*?\1/gi, '')
      .replace(/\s+rel\s*=\s*(['])(?:[^']*?)\1/gi, '');

    const absoluteBase = (() => {
      const raw = String(process.env.PP_BASE_URL || '').trim();
      if (!raw) return '';
      return /^https?:\/\//i.test(raw) ? raw.replace(/\/?$/, '').replace(/\/+$/, '') : '';
    })();

    const preparedContent = contentForEditor
      .replace(/<figure[^>]*>[\s\S]*?<\/figure>/gi, (fragment) => {
        const imgMatch = fragment.match(/<img[^>]*>/i);
        return imgMatch ? imgMatch[0] : '';
      })
      .replace(/<img([^>]*?)src=["'](\/[^"']*)["']([^>]*)>/gi, ($0, before, pathValue, after) => {
        if (!absoluteBase) {
          return $0;
        }
        return `<img${before}src="${absoluteBase}${pathValue}"${after}>`;
      });
    await page.evaluate((html) => {
      const el = document.querySelector('.pad .elements .element.medium-editor-element[contenteditable="true"]');
      if (el) {
        el.innerHTML = String(html);
        // Safety: normalize anchors inside editor after insertion
        try {
          const links = Array.from(el.querySelectorAll('a[href]'));
          for (const a of links) {
            const href = a.getAttribute('href') || '';
            const m = href.match(/^https?:\/\/(.*)$/i);
            if (m && m[1]) a.setAttribute('href', `//${m[1]}`);
            a.removeAttribute('target');
            a.removeAttribute('rel');
          }
        } catch {}
        try {
          const images = Array.from(el.querySelectorAll('img[src]'));
          for (const img of images) {
            const src = img.getAttribute('src') || '';
            const matchHttp = src.match(/^https?:\/\/(.*)$/i);
            if (matchHttp && matchHttp[1]) {
              img.setAttribute('src', `//${matchHttp[1]}`);
            }
            const srcset = img.getAttribute('srcset');
            if (srcset) {
              img.setAttribute('srcset', srcset.replace(/https?:\/\//gi, '//'));
            }
          }
        } catch {}
        try { el.dispatchEvent(new InputEvent('input', { bubbles: true })); } catch {}
      }
    }, preparedContent).catch(() => {});

    // 9) Click Publish -> wait for logged-in modal
    const navPub = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
    await page.click('.publish button, .publish > button').catch(async () => {
      await page.evaluate(() => {
        const b = document.querySelector('.publish button, .publish > button');
        if (b) b.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      });
    });
    const modalAppeared = await page.waitForSelector('.publishMenu', { timeout: 15000 }).then(() => true).catch(() => false);
    if (!modalAppeared) {
      await browser.close();
      return { ok: false, network: 'notepin', error: 'PUBLISH_MODAL_NOT_FOUND', logFile: LOG_FILE, logDir: LOG_DIR };
    }
    // Publish modal opened

    // 10) Fill Title, ensure Public, wait for CF token
    await page.click('.publishMenu .titleInp', { clickCount: 3 }).catch(() => {});
    await page.type('.publishMenu .titleInp', title, { delay: 15 }).catch(() => {});
    await page.evaluate(() => {
      const btn = document.querySelector('.publishMenu .options[data-do="visible"] button[data-type="public"]');
      if (btn && !btn.classList.contains('chosen')) btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    }).catch(() => {});
    // Try description if enabled (often disabled)
    await page.evaluate(() => {
      const d = document.querySelector('.publishMenu input[name="description"]');
      if (d && d instanceof HTMLInputElement && !d.disabled) { d.value = 'Published via PromoPilot'; d.dispatchEvent(new Event('input', { bubbles: true })); }
    }).catch(() => {});
    await page.waitForFunction(() => {
      const el = document.querySelector('input[name="cf-turnstile-response"]');
      return !!(el && el instanceof HTMLInputElement && el.value && el.value.length > 20);
    }, { timeout: 20000 }).catch(() => null);

    // 11) Submit modal
    const navSubmit = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
    await page.click('.publishMenu .finish p, .publishMenu .finish').catch(async () => {
      await page.evaluate(() => {
        const el = document.querySelector('.publishMenu .finish p') || document.querySelector('.publishMenu .finish');
        if (el) el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      });
    });
    await Promise.race([
      navSubmit,
      page.waitForFunction(() => /\/p\//.test(location.pathname), { timeout: 15000 }).catch(() => null),
      sleep(1200)
    ]);
    // Published

    // Raw URL right after publish (may be editor/preview, not public)
    const publishedUrlRaw = safeUrl(page);

    // 12) Go to blog homepage and pick the newest post URL
    let blogHome = '';
    try { blogHome = `https://${String(username).trim()}.notepin.co/`; } catch {}
    let candidates = [];
    let blogFinal = '';
    try {
      if (blogHome) {
        const navBlog = page.goto(blogHome, { waitUntil: 'domcontentloaded' }).catch(() => null);
        await Promise.race([navBlog, sleep(1500)]);
        // Poll up to 3 times in case listing lags
        for (let i = 0; i < 3; i++) {
          const { items } = await page.evaluate(() => {
            const items = [];
            const org = location.origin;
            const aTags = Array.from(document.querySelectorAll('.posts a[href]'));
            for (const a of aTags) {
              const href = a.getAttribute('href') || '';
              let url = href;
              try { if (/^\//.test(href)) url = new URL(href, org).href; } catch {}
              // Try to extract some nearby text context
              let text = a.textContent || '';
              let block = '';
              try {
                const post = a.closest('.post');
                if (post) block = post.innerText || '';
              } catch {}
              items.push({ url, text, block });
            }
            return { items };
          }).catch(() => ({ items: [] }));

          // Prefer items whose text/block include our title
          const t = (title || '').trim().toLowerCase();
          const titleMatched = t ? items.filter(it =>
            it && it.url && (!/\/-----$/.test(it.url)) && /\/[^/]{3,}$/.test(it.url) &&
            (!blogHome || it.url.startsWith(blogHome)) &&
            ((it.text || '').toLowerCase().includes(t) || (it.block || '').toLowerCase().includes(t))
          ) : [];
          if (titleMatched.length) {
            candidates = Array.from(new Set(titleMatched.map(x => x.url)));
            break;
          }

          // Else filter by shape
          const filtered = Array.from(new Set(items.map(x => x.url)))
            .filter(u => u && (!blogHome || u.startsWith(blogHome)))
            .filter(u => !/\/-----$/.test(u))
            .filter(u => /\/[^/]{3,}$/.test(u));
          if (filtered.length) { candidates = filtered; break; }
          await sleep(1200);
          await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
        }
        // Prefer the first candidate as newest
        if (candidates.length) blogFinal = candidates[0];
      }
    } catch {}

    const publishedUrl = blogFinal || publishedUrlRaw || blogHome || '';
    await browser.close();
    return {
      ok: true,
      network: 'notepin',
      mode: 'publish',
      username: username || '',
      publishedUrl,
      publishedUrlRaw,
      blogHome,
      candidates,
      title,
      logFile: LOG_FILE,
      logDir: LOG_DIR,
      article
    };
  } catch (error) {
    try { await snap(page, 'Lx-error'); } catch {}
    try { await browser.close(); } catch {}
    return { ok: false, network: 'notepin', mode: 'login-only', error: String((error && error.message) || error) };
  }
}

module.exports = { publish: loginNotepin };

// CLI entrypoint (login-only by default)
if (require.main === module) {
  (async () => {
    const { LOG_FILE, logLine } = createLogger('notepin-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: (raw || '').length });
      const job = JSON.parse(raw || '{}');
      logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });

  const username = job.username || job.loginUsername || process.env.PP_NOTEPIN_USERNAME || 'pphr9sc56f4j4s';
  const password = job.password || job.loginPassword || process.env.PP_NOTEPIN_PASSWORD || 'swxqsk27nmA!9';

  let res = await loginNotepin(username, password, job);
    res = attachArticleToResult(res, job);
    logLine('result', res); console.log(JSON.stringify(res)); process.exit(res && res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String((e && e.message) || e), network: 'notepin' };
      logLine('fail-exception', payload); console.log(JSON.stringify(payload)); process.exit(1);
    }
  })();
}
