'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'privatebin',
  baseUrl: 'https://privatebin.net/',
  contentFormat: 'markdown',
  waitForSelector: 'textarea#message',
  contentSelectors: ['textarea#message'],
  submitSelectors: ['button#sendbutton', 'button#paste', 'button[type="submit"]'],
  disableTitle: true,
  resolveResult: async ({ page }) => {
    await page.waitForFunction(() => window.location.href.includes('#'), { timeout: 60000 }).catch(() => {});
    try {
      const current = page.url();
      if (current && /https?:\/\//i.test(current) && current.includes('#')) {
        return current;
      }
      const extracted = await page.evaluate(() => {
        const link = document.querySelector('input.copy-link, input#shortenurlurl');
        if (link && link.value) return link.value.trim();
        const anchor = document.querySelector('a[href*="privatebin"]');
        return anchor ? (anchor.href || '').trim() : '';
      });
      if (extracted && /https?:\/\//i.test(extracted)) {
        return extracted;
      }
    } catch (_) {}
    return page.url();
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
