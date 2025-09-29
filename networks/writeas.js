'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');

const config = {
  slug: 'writeas',
  baseUrl: 'https://write.as/notes',
  contentFormat: 'markdown',
  waitForSelector: 'textarea[name="body"], textarea#post-body, textarea',
  titleSelectors: ['input[name="title"]', 'input[placeholder*="Title" i]'],
  contentSelectors: ['textarea[name="body"]', 'textarea#post-body', 'textarea'],
  submitSelectors: ['button[type="submit"]', 'button.btn-primary'],
  disableTitle: false,
  resolveResult: async ({ page }) => {
    await waitForTimeoutSafe(page, 2000);
    let current = '';
    try { current = page.url(); } catch (_) {}
    if (current && /https?:\/\/write\.as\//i.test(current) && !current.includes('/notes')) {
      return current;
    }
    const maybe = await page.evaluate(() => {
      const link = document.querySelector('a[href^="https://write.as/"]');
      if (link) return link.href.trim();
      return '';
    });
    return maybe || current;
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
