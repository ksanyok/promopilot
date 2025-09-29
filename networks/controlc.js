'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForResult } = require('./lib/puppeteerUtils');
const { solveIfCaptcha } = require('./captcha');

const RESULT_SELECTOR = 'input#link, input[name="link"], input#siteurl, input#shortlink';
const CONTROL_C_URL_REGEX = /^https?:\/\/controlc\.com\/(?:index\.php\?id=)?[a-z0-9]{4,}$/i;

function isValidControlCUrl(url) {
  return CONTROL_C_URL_REGEX.test(String(url || '').trim());
}

const config = {
  slug: 'controlc',
  baseUrl: 'https://controlc.com/',
  contentFormat: 'markdown',
  waitForSelector: 'form textarea',
  submitSelectors: ['button[type="submit"]', '#submit'],
  resultSelector: RESULT_SELECTOR,
  titleSelectors: ['input#paste_title', 'input[name="title"]'],
  resultTimeoutMs: 180000,
  beforeSubmit: async ({ page, logLine }) => {
    try {
      const solved = await solveIfCaptcha(page, logLine);
      if (solved) {
        logLine('ControlC captcha solved', { stage: 'beforeSubmit' });
      }
    } catch (error) {
      logLine('ControlC captcha solve error', { stage: 'beforeSubmit', error: String(error && error.message || error) });
    }
  },
  afterSubmit: async ({ page, logLine }) => {
    try {
      const solved = await solveIfCaptcha(page, logLine);
      if (solved) {
        logLine('ControlC captcha solved', { stage: 'afterSubmit' });
      }
    } catch (error) {
      logLine('ControlC captcha solve error', { stage: 'afterSubmit', error: String(error && error.message || error) });
    }
  },
  resolveResult: async ({ page, popupPage, startUrl, logLine }) => {
    const target = popupPage || page;
    const resolved = await waitForResult(target, startUrl, {
      resultSelector: RESULT_SELECTOR,
      resultTimeoutMs: 180000,
      resultEval: () => {
        const input = document.querySelector('input#link, input[name="link"], input#siteurl, input#shortlink');
        if (input) {
          const val = input.value || input.getAttribute('value') || input.innerText || '';
          if (val) return val.trim();
        }
        const textarea = document.querySelector('textarea#codes, textarea[name="codes"]');
        if (textarea) {
          const raw = (textarea.value || textarea.innerText || '').trim();
          const match = raw.match(/https?:\/\/controlc\.com\/[a-z0-9]+/i);
          if (match) return match[0];
        }
        const anchor = document.querySelector('a[href*="controlc.com/"]');
        if (anchor) return anchor.href;
        const bodyText = document.body ? document.body.innerText : '';
        const bodyMatch = bodyText.match(/https?:\/\/controlc\.com\/[a-z0-9]+/i);
        return bodyMatch ? bodyMatch[0] : '';
      },
    });

    const candidates = [];
    if (resolved) {
      candidates.push(resolved);
    }

    try {
      const domValue = await target.evaluate(() => {
        const input = document.querySelector('input#link, input[name="link"], input#siteurl, input#shortlink');
        if (input) {
          const val = input.value || input.getAttribute('value') || input.innerText || '';
          return val.trim();
        }
        const textarea = document.querySelector('textarea#codes, textarea[name="codes"]');
        if (textarea) {
          const raw = (textarea.value || textarea.innerText || '').trim();
          const match = raw.match(/https?:\/\/controlc\.com\/[a-z0-9]+/i);
          if (match) return match[0];
        }
        return '';
      });
      if (domValue) {
        candidates.push(domValue);
      }
    } catch (_) {
      // ignore
    }

    try {
      const currentUrl = typeof target.url === 'function' ? target.url() : '';
      if (currentUrl && currentUrl !== startUrl) {
        candidates.push(currentUrl);
      }
    } catch (_) {
      // ignore
    }

    const finalUrl = candidates.find(isValidControlCUrl);
    if (finalUrl) {
      return finalUrl.trim();
    }

    logLine('ControlC result invalid', { candidates });
    throw new Error('FAILED_TO_RESOLVE_URL');
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
