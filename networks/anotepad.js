'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'anotepad',
  baseUrl: 'https://anotepad.com/notes/new',
  contentFormat: 'markdown',
  waitForSelector: 'input#noteName',
  titleSelectors: ['input#noteName', 'input[name="title"]'],
  contentSelectors: ['textarea#noteContent', 'textarea[name="content"]'],
  submitSelectors: ['button#btnSaveNote', 'button[type="submit"]'],
  resolveResult: async ({ page }) => {
    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    let current = '';
    try { current = page.url(); } catch (_) {}
    if (current && /https?:\/\//i.test(current) && !current.includes('/new')) {
      return current;
    }
    const maybe = await page.evaluate(() => {
      const link = document.querySelector('a.btnShare, a[href*="anotepad.com/note"]');
      return link ? (link.href || '').trim() : '';
    });
    return maybe && /https?:\/\//i.test(maybe) ? maybe : current;
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
