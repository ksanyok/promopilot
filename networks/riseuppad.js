'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { htmlToPlainText } = require('./lib/contentFormats');

function randomSlug() {
  return 'promo-' + Math.random().toString(36).slice(2, 10);
}

const config = {
  slug: 'riseuppad',
  baseUrl: 'https://pad.riseup.net/p/',
  contentFormat: 'text',
  disableTitle: false,
  getStartUrl: async () => `https://pad.riseup.net/p/${randomSlug()}`,
  waitForSelector: 'iframe[name="ace_outer"]',
  fillTitle: async ({ page, title }) => {
    try {
      const frameHandle = await page.waitForSelector('iframe[name="ace_title"]', { timeout: 20000 });
      if (!frameHandle) return false;
      const frame = await frameHandle.contentFrame();
      if (!frame) return false;
      await frame.evaluate((text) => {
        const doc = document.getElementById('innerdocbody');
        if (!doc) return;
        doc.innerHTML = '';
        const div = document.createElement('div');
        div.textContent = text;
        doc.appendChild(div);
        try { doc.dispatchEvent(new Event('input', { bubbles: true })); } catch (_) {}
      }, title.slice(0, 140));
      return true;
    } catch (_) {
      return false;
    }
  },
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
        const evt = new Event('input', { bubbles: true });
        body.dispatchEvent(evt);
      }, plain);
      return true;
    } catch (_) {
      return false;
    }
  },
  manualSubmit: async () => {},
  resolveResult: async ({ page }) => {
    await page.waitForTimeout(1500);
    try { return page.url(); } catch (_) { return ''; }
  }
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
