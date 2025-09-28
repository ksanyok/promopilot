'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'pasteee',
  baseUrl: 'https://paste.ee/',
  contentFormat: 'markdown',
  waitForSelector: 'textarea',
  titleSelectors: ['input#id_title', 'input[name="title"]'],
  contentSelectors: ['textarea#id_content', 'textarea[name="content"]'],
  submitSelectors: ['button[type="submit"]', 'button.btn-primary'],
  beforeSubmit: async ({ page }) => {
    try { await page.select('select#id_expire', 'never'); } catch (_) {}
    try { await page.select('select#id_visibility', 'public'); } catch (_) {}
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
