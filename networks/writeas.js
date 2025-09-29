'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');

function normalizeWriteAsUrl(rawUrl) {
  if (!rawUrl) return '';
  let normalized = String(rawUrl).trim();
  try {
    const url = new URL(normalized);
    if (/\.write\.as$/i.test(url.hostname) || /(^|\.)write\.as$/i.test(url.hostname)) {
      url.hash = '';
      url.search = '';
      url.pathname = url.pathname.replace(/\/+/g, '/');
      if (url.pathname.endsWith('.md')) {
        url.pathname = url.pathname.slice(0, -3);
      }
      if (url.pathname !== '/' && url.pathname.endsWith('/')) {
        url.pathname = url.pathname.slice(0, -1);
      }
      normalized = url.toString();
    }
  } catch (_) {
    // ignore
  }
  return normalized;
}

const config = {
  slug: 'writeas',
  baseUrl: 'https://write.as/new',
  // Write.as expects Markdown in the main textarea
  contentFormat: 'markdown',
  // As a safety, we can later consider plain text fallback if needed
  // Support legacy/new selectors
  waitForSelector: '#post-body, #writer, textarea#post-body',
  disableTitle: true,
  // Try common editor targets first
  contentSelectors: ['#post-body', 'textarea#post-body', '#writer'],
  submitSelectors: ['#publish'],
  resultTimeoutMs: 180000,
  preFill: async ({ page, logLine }) => {
    try {
      await page.evaluate(() => {
        const ensureFormatAttributes = () => {
          try {
            if (window.localStorage) {
              try { window.localStorage.setItem('writeas-format', 'markdown'); } catch (_) {}
              try { window.localStorage.setItem('format', 'markdown'); } catch (_) {}
            }
          } catch (_) {}
          document.documentElement && document.documentElement.setAttribute('data-format', 'markdown');
          if (document.body) { document.body.setAttribute('data-format', 'markdown'); }
          const targets = Array.from(document.querySelectorAll('input[name="format"], input#format, input#post-format, select[name="format"], #format'));
          targets.forEach(el => {
            try {
              if ('value' in el) {
                el.value = 'markdown';
              }
              el.setAttribute('value', 'markdown');
              if (typeof el.dispatchEvent === 'function') {
                ['input', 'change'].forEach(evt => el.dispatchEvent(new Event(evt, { bubbles: true })));
              }
            } catch (_) {}
          });
          const form = document.querySelector('form[action], form#new-post, form.post-form');
          if (form) {
            form.setAttribute('data-format', 'markdown');
            if (form.dataset) { form.dataset.format = 'markdown'; }
          }
        };

        // Try to force markdown mode if there is a format control
        const setMarkdownValue = () => {
          // Try radio first
          const radio = document.querySelector('input[type="radio"][value="markdown"], input[type="radio"][data-format="markdown"]');
          if (radio) {
            try { radio.click(); return true; } catch (_) {}
          }
          const inp = document.querySelector('input[name="format"], select[name="format"], #format');
          if (!inp) return false;
          const tag = (inp.tagName || '').toLowerCase();
          if (tag === 'select') {
            try { inp.value = 'markdown'; inp.dispatchEvent(new Event('change', { bubbles: true })); } catch (_) {}
            return true;
          }
          if ('value' in inp) {
            try { inp.value = 'markdown'; ['input', 'change'].forEach(e=>inp.dispatchEvent(new Event(e, { bubbles: true }))); } catch (_) {}
            return true;
          }
          return false;
        };

        const openMenus = () => {
          const openers = Array.from(document.querySelectorAll('button, a'))
            .filter(el => {
              const text = ((el.innerText || el.value || el.title || el.getAttribute('aria-label') || '') + '').toLowerCase();
              if (!text) return false;
              return (
                text.includes('more') || text.includes('options') || text.includes('menu') ||
                text.includes('format') || text.includes('preferences') || text.includes('настрой') || text.includes('опц')
              );
            });
          for (const el of openers) {
            try { el.click(); } catch (_) {}
          }
        };

        const tryToggleMarkdown = () => {
          // After menus opened, try to click Markdown toggles
          const candidates = Array.from(document.querySelectorAll('button, a, label, input'))
            .filter(el => {
              const text = ((el.innerText || el.value || el.title || el.getAttribute('aria-label') || '') + '').toLowerCase();
              return text.includes('markdown');
            });
          for (const el of candidates) {
            try {
              const pressed = el.getAttribute('aria-pressed');
              if (pressed === 'true') return true;
              el.click();
              return true;
            } catch (_) {}
          }
          return false;
        };

        setMarkdownValue();
        openMenus();
        setTimeout(tryToggleMarkdown, 50);
        ensureFormatAttributes();
        setTimeout(ensureFormatAttributes, 120);
        setTimeout(tryToggleMarkdown, 180);
      });
    } catch (e) {
      logLine('Write.as preFill markdown toggle failed', { error: String(e && e.message || e) });
    }
  },
  prepareBody: async ({ article, variants, logLine }) => {
    let body = variants && variants.markdown ? String(variants.markdown) : String((variants && variants.html) || '');
    body = body.replace(/\r\n?/g, '\n');
    const title = article && typeof article.title === 'string' ? article.title.trim() : '';
    const ensureTitleHeading = (text) => {
      if (!title) { return text.trim(); }
      const lines = (text || '').split(/\n+/);
      let firstContentIndex = -1;
      for (let i = 0; i < lines.length; i++) {
        if (lines[i].trim() !== '') { firstContentIndex = i; break; }
      }
      const sameTitle = () => {
        if (firstContentIndex === -1) { return false; }
        const firstLine = lines[firstContentIndex];
        const cleaned = firstLine.replace(/^#+\s+/, '').trim();
        return cleaned && cleaned.localeCompare(title, undefined, { sensitivity: 'accent' }) === 0;
      };
      if (!sameTitle()) {
        const heading = `# ${title}`;
        return `${heading}\n\n${text.trim()}`.trim();
      }
      return text.trim();
    };

    const normalized = ensureTitleHeading(body);
    let finalText = normalized.replace(/\n{3,}/g, '\n\n');
    if (!/\n\s*$/.test(finalText)) { finalText = `${finalText}\n`; }
    if (!/^#\s+/m.test(finalText) && title) {
      finalText = `# ${title}\n\n${finalText.trim()}`;
    }
    const sampleDiagnostics = finalText.slice(0, 160);
    logLine('Write.as prepared markdown snapshot', { snippet: sampleDiagnostics });
    return finalText;
  },
  transformBodyBeforeFill: async ({ body }) => {
    let text = String(body || '');
    // Fix cases like: [тут](<a href="https://...">https://...</a>) → [тут](https://...)
    text = text.replace(/\]\(\s*<a [^>]*href=["']([^"']+)["'][^>]*>[\s\S]*?<\/a>\s*\)/gi, ']($1)');
    // Remove any stray HTML tags left inside markdown
    text = text.replace(/<\/?(?:p|div|span|strong|em|code|blockquote)>/gi, '');
    // Normalize line endings and ensure blank line at top to help parsers
    text = text.replace(/\r\n?/g, '\n');
    if (!/^\s*#/.test(text) && !/^\s*\n/.test(text)) { text = '\n' + text; }
    if (!/\n\s*$/.test(text)) text = text + '\n'; // trailing newline
    return text;
  },
  beforeSubmit: async ({ page, logLine }) => {
    try {
      await page.waitForFunction(() => {
        const btn = document.querySelector('#publish');
        const content = document.querySelector('#post-body, #writer, textarea#post-body');
        if (!btn || !content) return false;
        let raw = '';
        if (typeof content.value === 'string') raw = content.value;
        else if (typeof content.innerText === 'string') raw = content.innerText;
        else if (typeof content.textContent === 'string') raw = content.textContent;
        const hasText = !!raw && raw.trim().length > 0;
        const enabled = !btn.disabled && !btn.classList.contains('disabled');
        return hasText && enabled;
      }, { timeout: 15000 });
    } catch (error) {
      logLine('Write.as publish button enable wait failed', { error: String(error && error.message || error) });
      try {
        await page.evaluate(() => {
          const btn = document.querySelector('#publish');
          if (btn) {
            btn.disabled = false;
            btn.classList.remove('disabled');
          }
        });
      } catch (forceErr) {
        logLine('Write.as publish button force enable error', { error: String(forceErr && forceErr.message || forceErr) });
      }
    }
  },
  afterSubmit: async ({ page, logLine }) => {
    try {
      await Promise.race([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null),
        waitForTimeoutSafe(page, 1500)
      ]);
    } catch (error) {
      logLine('Write.as wait after submit error', { error: String(error && error.message || error) });
    }
  },
  resolveResult: async ({ page, logLine, startUrl, variants }) => {
    const poll = async () => {
      try {
        return await page.evaluate(() => {
          const canonical = document.querySelector('link[rel="canonical"]');
          if (canonical && canonical.href) return canonical.href.trim();
          const share = document.querySelector('a[href^="https://write.as/"]');
          if (share && share.href) return share.href.trim();
          return window.location ? window.location.href : '';
        });
      } catch (_) {
        let current = '';
        try { current = page.url(); } catch (err) { current = ''; }
        return current;
      }
    };

    let attempts = 0;
    let best = '';
    const deadline = Date.now() + 180000;
    while (Date.now() < deadline) {
      attempts += 1;
      const candidate = normalizeWriteAsUrl(await poll());
      if (candidate && candidate !== startUrl) {
        best = candidate;
        break;
      }
      await waitForTimeoutSafe(page, 1000);
    }

    if (!best) {
      let current = '';
      try { current = normalizeWriteAsUrl(page.url()); } catch (_) { current = ''; }
      best = current;
    }

    if (!best) {
      logLine('Write.as resolve result fallback empty', { attempts });
    } else {
      logLine('Write.as resolve result', { attempts, best });
    }

    // Post-publish check: if page shows raw markdown (e.g., '## ' visible and no headings), try to edit and re-publish with markdown mode enforced
    try {
      if (best) {
        // Ensure we're on the published page
        try { await page.goto(best, { waitUntil: 'domcontentloaded', timeout: 30000 }); } catch (_) {}
        const looksRaw = await page.evaluate(() => {
          const body = document.querySelector('.e-content, #post-body, article');
          if (!body) return false;
          const html = (body.innerHTML || '').toLowerCase();
          const text = (body.innerText || '').toLowerCase();
          const hasMdSymbols = /\n?\s*##\s+/.test(text) || /\[[^\]]+\]\([^\)]+\)/.test(text);
          const hasRendered = /<h1|<h2|<h3|<a\s/i.test(html);
          return hasMdSymbols && !hasRendered;
        });
        if (looksRaw) {
          logLine('Write.as detected raw markdown after publish — attempting auto-fix via Edit');
          // Try to click Edit link/button
          try {
            const clicked = await page.evaluate(() => {
              const edits = Array.from(document.querySelectorAll('a,button'))
                .filter(el => {
                  const href = (el.getAttribute('href') || '').toLowerCase();
                  const txt = ((el.innerText || el.title || el.getAttribute('aria-label') || '') + '').toLowerCase();
                  return href.endsWith('/edit') || href.includes('/edit?') || txt.includes('edit') || txt.includes('редакт');
                });
              for (const el of edits) {
                try { el.click(); return true; } catch (_) {}
              }
              return false;
            });
            if (clicked) {
              try { await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }); } catch (_) {}
            }
          } catch (_) {}

          // Ensure editor is ready
          try { await page.waitForSelector('#post-body, #writer, textarea#post-body', { timeout: 15000 }); } catch (_) {}
          // Force markdown mode (re-use preFill logic inline)
          try {
            await page.evaluate(() => {
              const radio = document.querySelector('input[type="radio"][value="markdown"], input[type="radio"][data-format="markdown"]');
              if (radio) { try { radio.click(); } catch (_) {} }
              const inp = document.querySelector('input[name="format"], select[name="format"], #format');
              if (inp) {
                const tag = (inp.tagName || '').toLowerCase();
                if (tag === 'select') { try { inp.value = 'markdown'; inp.dispatchEvent(new Event('change', { bubbles: true })); } catch (_) {} }
                if ('value' in inp) { try { inp.value = 'markdown'; ['input','change'].forEach(e=>inp.dispatchEvent(new Event(e, { bubbles: true }))); } catch (_) {} }
              }
              const openers = Array.from(document.querySelectorAll('button, a'))
                .filter(el => {
                  const t = ((el.innerText || el.value || el.title || el.getAttribute('aria-label') || '') + '').toLowerCase();
                  return t.includes('format') || t.includes('options') || t.includes('more') || t.includes('markdown');
                });
              for (const el of openers) { try { el.click(); } catch (_) {} }
              const mdToggle = Array.from(document.querySelectorAll('button, a, label, input'))
                .find(el => ((el.innerText || el.value || el.title || el.getAttribute('aria-label') || '') + '').toLowerCase().includes('markdown'));
              if (mdToggle) { try { mdToggle.click(); } catch (_) {} }
            });
          } catch (_) {}

          // Refill content with our markdown variant (sanitized by transform)
          const bodyMarkdown = (variants && variants.markdown) ? variants.markdown : '';
          if (bodyMarkdown) {
            try {
              await page.evaluate((val) => {
                const el = document.querySelector('#post-body, textarea#post-body, #writer');
                if (el) {
                  if ('value' in el) { el.value = val; ['input','change','keyup','blur'].forEach(e=>el.dispatchEvent(new Event(e,{bubbles:true}))); }
                  else if (typeof el.innerHTML !== 'undefined') { el.innerHTML = val; }
                }
              }, bodyMarkdown);
            } catch (_) {}
          }

          // Re-publish
          try {
            await page.evaluate(() => { const btn = document.querySelector('#publish'); if (btn) btn.click(); });
            try { await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }); } catch (_) {}
          } catch (_) {}

          // Re-resolve canonical URL
          try {
            const fixed = await page.evaluate(() => {
              const canonical = document.querySelector('link[rel="canonical"]');
              if (canonical && canonical.href) return canonical.href.trim();
              return window.location ? window.location.href : '';
            });
            if (fixed) { best = normalizeWriteAsUrl(fixed); }
          } catch (_) {}
        }
      }
    } catch (e) {
      logLine('Write.as post-fix failed (continuing)', { error: String(e && e.message || e) });
    }

    return best;
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
