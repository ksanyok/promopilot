'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');

const config = {
  slug: 'zerobin',
  baseUrl: 'https://0bin.net/',
  contentFormat: 'markdown',
  waitForSelector: 'textarea#message',
  contentSelectors: ['textarea#message', 'textarea[name="text"]'],
  submitSelectors: ['button#encrypt-btn', 'button#paste', 'button[type="submit"]'],
  disableTitle: true,
  resolveResult: async ({ page }) => {
    await waitForTimeoutSafe(page, 1500);
    try {
      const current = page.url();
      if (current && /https?:\/\//i.test(current) && current.includes('#')) {
        return current;
      }
      const maybe = await page.evaluate(() => {
        const link = document.querySelector('input.copy-paste-url');
        if (link && link.value) return link.value.trim();
        return window.location.href;
      });
      return maybe;
    } catch (_) {
      return page.url();
    }
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
