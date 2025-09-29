
'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { solveIfCaptcha } = require('./captcha');

const CONTROL_C_URL_REGEX = /^https?:\/\/controlc\.com\/(?:index\.php\?id=)?[a-z0-9]+$/i;
const RESULT_POLL_INTERVAL = 1500;
const RESULT_TIMEOUT_MS = 180000;
const CONTROL_C_NAV_PATHS = new Set([
  '',
  '/',
  'login',
  'register',
  'terms',
  'press',
  'contact',
  'getpaid.php',
  'index.php?act=submit'
]);

function isValidControlCUrl(url) {
  return CONTROL_C_URL_REGEX.test(String(url || '').trim());
}

function pickBestControlCUrl(urls) {
  const scored = [];
  for (const raw of urls || []) {
    const url = String(raw || '').trim();
    if (!isValidControlCUrl(url)) continue;
    let score = 0;
    const lower = url.toLowerCase();
    const pathMatch = lower.match(/controlc\.com\/(.*)$/);
    const path = pathMatch ? pathMatch[1].split(/[?#]/)[0] : '';
    if (path && CONTROL_C_NAV_PATHS.has(path)) continue;
    if (/index\.php\?id=/i.test(path)) score += 50;
    const idMatch = path.replace(/^index\.php\?id=/, '');
    if (/[0-9]/.test(idMatch)) score += 20;
    if (/^[a-f0-9]{6,12}$/i.test(idMatch)) score += 30;
    if (idMatch.length >= 6 && idMatch.length <= 16) score += 10;
    score += Math.max(0, 20 - path.length);
    scored.push({ url, score });
  }
  scored.sort((a, b) => b.score - a.score);
  return scored.length ? scored[0].url : '';
}

async function collectCandidates(target) {
  try {
    return await target.evaluate(() => {
      const collected = new Set();
      const push = (val) => {
        if (!val || typeof val !== 'string') return;
        const trimmed = val.trim();
        if (trimmed) collected.add(trimmed);
      };

      const pattern = /https?:\/\/controlc\.com\/(?:index\.php\?id=)?[a-z0-9]{4,}/i;

      const bySelectors = [
        'input#link',
        'input[name="link"]',
        'input#siteurl',
        'input#shortlink',
        'input#copylink',
        'textarea#codes',
        'textarea[name="codes"]',
        'textarea[name="content"]',
      ];

      bySelectors.forEach((selector) => {
        const node = document.querySelector(selector);
        if (!node) return;
        const value = node.value || node.getAttribute('value') || node.innerText || node.textContent || '';
        if (value) {
          if (/https?:\/\//i.test(value)) {
            push(value);
          } else {
            const match = value.match(pattern);
            if (match && match[0]) push(match[0]);
          }
        }
      });

      const anchors = Array.from(document.querySelectorAll('a[href*="controlc.com/"]'));
      anchors.forEach((anchor) => {
        if (anchor && anchor.href) push(anchor.href);
      });

      const bodyText = document.body ? document.body.innerText : '';
      if (bodyText) {
        const bodyMatches = bodyText.match(/https?:\/\/controlc\.com\/(?:index\.php\?id=)?[a-z0-9]{4,}/gi) || [];
        bodyMatches.forEach(push);
      }

      const currentUrl = location.href;
      if (currentUrl) push(currentUrl);

      const urls = Array.from(collected);
      return { urls };
    });
  } catch (_) {
    return { urls: [] };
  }
}

const config = {
  slug: 'controlc',
  baseUrl: 'https://controlc.com/',
  contentFormat: 'markdown',
  waitForSelector: 'form textarea',
  submitSelectors: [
    'button[type="submit"]',
    '#submit',
    '#submitbutton',
    '#submit_button',
    'button#submitbutton',
    'button[name="Submit"]',
    'input[type="submit"]',
    'input[name="submit"]'
  ],
  titleSelectors: ['input#paste_title', 'input[name="title"]'],
  resultTimeoutMs: RESULT_TIMEOUT_MS,
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
      try {
        await Promise.race([
          page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => null),
          waitForTimeoutSafe(page, 2000)
        ]);
      } catch (_) {}
    } catch (error) {
      logLine('ControlC captcha solve error', { stage: 'afterSubmit', error: String(error && error.message || error) });
    }
  },
  resolveResult: async ({ page, popupPage, startUrl, logLine }) => {
    const target = popupPage || page;
    const seen = new Set();
    const deadline = Date.now() + RESULT_TIMEOUT_MS;

    while (Date.now() < deadline) {
      const { urls } = await collectCandidates(target);
      urls.forEach((url) => seen.add(url));

      const picked = pickBestControlCUrl(urls);
      if (picked) {
        return picked.trim();
      }

      if (Date.now() + RESULT_POLL_INTERVAL >= deadline) {
        break;
      }

      await waitForTimeoutSafe(target, RESULT_POLL_INTERVAL);
    }

    const allCandidates = Array.from(seen).filter(Boolean);
    logLine('ControlC result invalid', { candidates: allCandidates, startUrl });
    throw new Error('FAILED_TO_RESOLVE_URL');
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
