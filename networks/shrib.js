'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

function randomSlug() {
  return 'promo-' + Math.random().toString(36).slice(2, 10);
}

const config = {
  slug: 'shrib',
  baseUrl: 'https://shrib.com/',
  contentFormat: 'markdown',
  disableTitle: true,
  getStartUrl: async () => `https://shrib.com/${randomSlug()}`,
  fillContent: async ({ page, body }) => {
    try {
      const result = await page.evaluate((val) => {
        const textarea = document.querySelector('textarea, #note, #editor');
        if (textarea && typeof textarea.value !== 'undefined') {
          textarea.value = val;
          try { textarea.dispatchEvent(new Event('input', { bubbles: true })); } catch (_) {}
          return true;
        }
        const editable = document.querySelector('[contenteditable="true"], [contenteditable]');
        if (editable) {
          editable.innerText = val;
          try { editable.dispatchEvent(new Event('input', { bubbles: true })); } catch (_) {}
          return true;
        }
        return false;
      }, body);
      return result;
    } catch (_) {
      return false;
    }
  },
  manualSubmit: async ({ page }) => {
    try {
      await page.evaluate(() => {
        const share = document.querySelector('button.save-button, button#save, button[data-action="save"]');
        if (share) share.click();
      });
    } catch (_) {}
  },
  resolveResult: async ({ page }) => {
    await page.waitForTimeout(1500);
    try { return page.url(); } catch (_) { return ''; }
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
