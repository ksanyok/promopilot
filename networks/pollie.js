'use strict';

const puppeteer = require('puppeteer');
const { createLogger } = require('./lib/logger');
const { generatePoll } = require('./lib/pollGenerator');
const { runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { createVerificationPayload } = require('./lib/verification');

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const slug = 'pollie';
  const { LOG_FILE, logLine } = createLogger(slug);
  const job = { pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta: pageMeta };
  logLine('Publish start', { pageUrl, anchorText });

  const poll = await generatePoll(job, logLine);
  logLine('Poll prepared', poll);

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;

  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  page.setDefaultTimeout(180000);
  page.setDefaultNavigationTimeout(180000);

  try {
    await page.goto('https://pollie.app/create', { waitUntil: 'networkidle2' });
    await page.waitForSelector('input, textarea', { timeout: 20000 });

    await page.evaluate((pollData) => {
      const setValue = (el, value) => {
        if (!el) return;
        el.focus();
        if ('value' in el) {
          el.value = value;
        } else if ('innerHTML' in el) {
          el.innerHTML = value;
        }
        ['input', 'change', 'keyup', 'blur'].forEach((evt) => {
          try { el.dispatchEvent(new Event(evt, { bubbles: true })); } catch (_) {}
        });
      };

      const question = document.querySelector('input[name="question"], textarea[name="question"], input[placeholder*="Question" i], textarea[placeholder*="Question" i]');
      if (question) {
        setValue(question, pollData.question);
      }

      const addButton = () => Array.from(document.querySelectorAll('button, a, div[role="button"]')).find((el) => /add/i.test(el.textContent || '') && /option|answer/i.test(el.textContent || ''));

      const optionSelectors = [
        'input[name^="choice" i]',
        'input[placeholder*="Option" i]',
        'input[placeholder*="Answer" i]',
        'textarea[name^="choice" i]'
      ];

      const collectOptions = () => {
        const seen = new Set();
        const nodes = [];
        optionSelectors.forEach((sel) => {
          document.querySelectorAll(sel).forEach((node) => {
            if (seen.has(node)) return;
            seen.add(node);
            nodes.push(node);
          });
        });
        return nodes;
      };

      while (collectOptions().length < pollData.options.length) {
        const btn = addButton();
        if (!btn) break;
        btn.click();
      }

      const options = collectOptions();
      options.forEach((node, idx) => {
        const value = pollData.options[idx] || pollData.options[pollData.options.length - 1];
        setValue(node, value);
      });

      const description = document.querySelector('textarea[name="description"], textarea[placeholder*="Description" i]');
      if (description) {
        setValue(description, pollData.description || '');
      }
    }, poll);

  await waitForTimeoutSafe(page, 500);

    const createButton = await page.evaluateHandle(() => {
      const candidates = Array.from(document.querySelectorAll('button, a, div[role="button"]'));
      return candidates.find((el) => /create/i.test(el.textContent || '') && /poll/i.test(el.textContent || '')) || null;
    });

    if (createButton) {
      try {
        await createButton.click();
      } catch (_) {
        await page.evaluate((btn) => btn && btn.click(), createButton);
      }
    } else {
      await page.keyboard.press('Enter').catch(() => {});
    }

  await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
  await waitForTimeoutSafe(page, 1200);

    const publishedUrl = await page.evaluate(() => {
      const candidates = [window.location.href];
      document.querySelectorAll('a[href*="pollie.app/"]').forEach((a) => candidates.push(a.href));
      return candidates.find((url) => /https?:\/\/pollie\.app\//i.test(url) && !/create/.test(url));
    });

    if (!publishedUrl) {
      throw new Error('POLL_URL_NOT_FOUND');
    }

  logLine('Publish success', { publishedUrl });
  await browser.close();
  const extraTexts = [poll.question, poll.description, Array.isArray(poll.options) ? poll.options.join(' ') : ''];
  const verification = createVerificationPayload({ pageUrl, anchorText, extraTexts });
  return { ok: true, network: slug, publishedUrl, title: poll.question, logFile: LOG_FILE, verification };
  } catch (error) {
    await browser.close().catch(() => {});
    logLine('Publish failed', { error: String(error && error.message || error) });
    return { ok: false, network: slug, error: String(error && error.message || error), logFile: LOG_FILE };
  }
}

module.exports = { publish };

runCli(module, publish, 'pollie');
