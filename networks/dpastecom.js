'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'dpastecom',
  baseUrl: 'https://dpaste.com/',
  contentFormat: 'markdown',
  waitForSelector: 'textarea',
  titleSelectors: ['input[name="title"]', 'input[placeholder*="Title" i]'],
  contentSelectors: ['textarea[name="content"]', 'textarea'],
  submitSelectors: ['button[type="submit"]', 'button.btn-primary', 'input[type="submit"]'],
  beforeSubmit: async ({ page }) => {
    try { await page.select('select[name="lexer"]', 'markdown'); } catch (_) {}
    try { await page.select('select[name="expiry"]', '2592000'); } catch (_) {}
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
