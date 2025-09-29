'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { htmlToPlainText } = require('./lib/contentFormats');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');

function randomSlug() {
  return 'promo-' + Math.random().toString(36).slice(2, 10);
}

const config = {
  slug: 'wikimediapad',
  baseUrl: 'https://etherpad.wikimedia.org/p/',
  contentFormat: 'text',
  disableTitle: true,
  getStartUrl: async () => `https://etherpad.wikimedia.org/p/${randomSlug()}`,
  waitForSelector: 'iframe[name="ace_outer"]',
  fillContent: async ({ page, article, variants }) => {
    const plain = variants.plain || htmlToPlainText(article.htmlContent);
    try {
      const outerHandle = await page.waitForSelector('iframe[name="ace_outer"]', { timeout: 30000 });
      if (!outerHandle) return false;
      const outerFrame = await outerHandle.contentFrame();
      if (!outerFrame) return false;
      const innerHandle = await outerFrame.waitForSelector('iframe[name="ace_inner"]', { timeout: 30000 });
      if (!innerHandle) return false;
      const innerFrame = await innerHandle.contentFrame();
      if (!innerFrame) return false;
      await innerFrame.evaluate((text) => {
        const body = document.querySelector('#innerdocbody');
        if (!body) return;
        body.innerHTML = '';
        const lines = String(text || '').split(/\n+/);
        lines.forEach((line) => {
          const div = document.createElement('div');
          div.textContent = line;
          body.appendChild(div);
        });
        try { body.dispatchEvent(new Event('input', { bubbles: true })); } catch (_) {}
      }, plain);
      return true;
    } catch (_) {
      return false;
    }
  },
  manualSubmit: async () => {},
  resolveResult: async ({ page }) => {
    await waitForTimeoutSafe(page, 1200);
    try { return page.url(); } catch (_) { return ''; }
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
