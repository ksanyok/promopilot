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
  contentFormat: 'markdown',
  waitForSelector: '#writer',
  disableTitle: true,
  contentSelectors: ['#writer'],
  submitSelectors: ['#publish'],
  resultTimeoutMs: 180000,
  beforeSubmit: async ({ page, logLine }) => {
    try {
      await page.waitForFunction(() => {
        const btn = document.querySelector('#publish');
        const content = document.querySelector('#writer');
        if (!btn || !content) return false;
        const hasText = typeof content.value === 'string' && content.value.trim().length > 0;
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
  resolveResult: async ({ page, logLine, startUrl }) => {
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

    return best;
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
