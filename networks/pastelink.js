'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');

const config = {
  slug: 'pastelink',
  baseUrl: 'https://pastelink.net/create',
  contentFormat: 'html',
  waitForSelector: 'form textarea, textarea[name], #content',
  titleSelectors: ['input[name="title"]', 'input#title', 'input[placeholder*="Title" i]'],
  contentSelectors: ['textarea[name="content"]', 'textarea#content', 'textarea'],
  submitSelectors: ['button[type="submit"]', '#submit'],
  resultSelector: 'input#generated-url, input[value^="https://pastelink.net/"]',
  resolveResult: async ({ page }) => {
    await waitForTimeoutSafe(page, 2000);
    let current = '';
    try { current = page.url(); } catch (_) {}
    if (current && /https?:\/\/pastelink\.net\//i.test(current) && !current.includes('/create')) {
      return current;
    }
    const maybe = await page.evaluate(() => {
      const input = document.querySelector('input[value^="https://pastelink.net/"]');
      if (input && input.value) return input.value.trim();
      const link = document.querySelector('a[href^="https://pastelink.net/"]');
      if (link) return link.href.trim();
      return '';
    });
    return maybe || current;
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
