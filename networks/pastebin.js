'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'pastebin',
  baseUrl: 'https://pastebin.com/',
  contentFormat: 'markdown',
  waitForSelector: '#postform-text',
  titleSelectors: ['#postform-name', 'input[name="PostForm[name]"]'],
  contentSelectors: ['#postform-text', 'textarea[name="PostForm[text]"]'],
  submitSelectors: ['button[type="submit"]', 'button.btn-create'],
  beforeSubmit: async ({ page }) => {
    try { await page.select('#postform-format', 'text'); } catch (_) {}
    try { await page.select('#postform-expiration', 'N'); } catch (_) {}
    try { await page.select('#postform-status', '1'); } catch (_) {} // public
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
