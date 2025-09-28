'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'ideone',
  baseUrl: 'https://ideone.com/',
  contentFormat: 'markdown',
  waitForSelector: 'textarea#source',
  titleSelectors: ['input#file', 'input[name="file"]', 'input[name="title"]'],
  contentSelectors: ['textarea#source', 'textarea[name="source"]'],
  submitSelectors: ['input[type="submit"]', 'button[type="submit"]'],
  beforeSubmit: async ({ page }) => {
    try {
      await page.evaluate(() => {
        const select = document.querySelector('#lang, select[name="language"]');
        if (!select) return;
        const options = Array.from(select.options || []);
        const textOption = options.find((opt) => /text|plain/i.test(opt.textContent || '')) || options[0];
        if (textOption) {
          select.value = textOption.value;
          const evt = new Event('change', { bubbles: true });
          select.dispatchEvent(evt);
        }
      });
    } catch (_) {}
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
