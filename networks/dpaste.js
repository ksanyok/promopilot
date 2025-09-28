'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'dpaste',
  baseUrl: 'https://dpaste.org/',
  contentFormat: 'markdown',
  waitForSelector: 'textarea',
  titleSelectors: ['input[name="title"]', 'input[placeholder*="Title" i]'],
  contentSelectors: ['textarea[name="content"]', 'textarea#id_content'],
  submitSelectors: ['button[type="submit"]', 'button.btn-primary'],
  beforeSubmit: async ({ page }) => {
    try { await page.select('select[name="syntax"]', 'markdown'); } catch (_) {}
    try { await page.select('select[name="expiry"]', 'onemonth'); } catch (_) {}
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
