'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'pasteofcode',
  baseUrl: 'https://paste.ofcode.org/',
  contentFormat: 'text',
  waitForSelector: 'textarea[name="code"]',
  titleSelectors: ['input[name="title"]', 'input[placeholder*="title" i]'],
  contentSelectors: ['textarea[name="code"]', 'textarea#code', 'textarea'],
  submitSelectors: ['input[type="submit"]', 'button[type="submit"]'],
  beforeSubmit: async ({ page }) => {
    try { await page.select('select[name="lang"]', 'text'); } catch (_) {}
  },
  resolveResult: async ({ page }) => {
    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {});
    let current = '';
    try { current = page.url(); } catch (_) {}
    if (current && /https?:\/\/paste\.ofcode\.org\//i.test(current) && !current.includes('/new')) {
      return current;
    }
    const maybe = await page.evaluate(() => {
      const link = document.querySelector('a[href^="https://paste.ofcode.org/"]');
      return link ? link.href.trim() : '';
    });
    return maybe || current;
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
