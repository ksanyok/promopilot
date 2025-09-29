'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');

function randomSlug() {
  return 'promo-' + Math.random().toString(36).slice(2, 9);
}

const config = {
  slug: 'notems',
  baseUrl: 'https://note.ms/',
  contentFormat: 'markdown',
  disableTitle: true,
  getStartUrl: async () => `https://note.ms/${randomSlug()}`,
  contentSelectors: ['textarea#note', 'textarea'],
  submitSelectors: [],
  manualSubmit: async () => {},
  resolveResult: async ({ page }) => {
    await waitForTimeoutSafe(page, 1200);
    try { return page.url(); } catch (_) { return ''; }
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
