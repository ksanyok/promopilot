'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');

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
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
