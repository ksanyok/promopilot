'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'paste2',
  baseUrl: 'https://paste2.org/new-paste',
  contentFormat: 'text',
  waitForSelector: 'textarea[name="code"], #code',
  titleSelectors: ['input[name="title"]', 'input[name="poster"]', 'input[placeholder*="Title" i]'],
  contentSelectors: ['textarea[name="code"]', 'textarea#code', 'textarea'],
  submitSelectors: ['input[type="submit"]', 'button[type="submit"]'],
  resultSelector: 'input[value^="https://paste2.org/"]',
  resolveResult: async ({ page }) => {
    await page.waitForTimeout(2000).catch(() => {});
    let current = '';
    try { current = page.url(); } catch (_) {}
    if (current && /https?:\/\/paste2\.org\//i.test(current) && !current.includes('new-paste')) {
      return current;
    }
    const maybe = await page.evaluate(() => {
      const input = document.querySelector('input[value^="https://paste2.org/"]');
      if (input && input.value) return input.value.trim();
      const link = document.querySelector('a[href^="https://paste2.org/"]');
      return link ? link.href.trim() : '';
    });
    return maybe || current;
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
