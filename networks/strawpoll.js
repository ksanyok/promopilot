'use strict';

const puppeteer = require('puppeteer');
const { createLogger } = require('./lib/logger');
const { generatePoll } = require('./lib/pollGenerator');
const { runCli } = require('./lib/genericPaste');

async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
  const slug = 'strawpoll';
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
    await page.goto('https://strawpoll.com/create', { waitUntil: 'networkidle2' });

    await page.waitForSelector('input, textarea', { timeout: 20000 });

    await page.evaluate((pollData) => {
      const setValue = (element, value) => {
        if (!element) return;
        element.focus();
        if ('value' in element) {
          element.value = value;
        } else if ('innerHTML' in element) {
          element.innerHTML = value;
        }
        ['input', 'change', 'keyup', 'blur'].forEach((evt) => {
          try { element.dispatchEvent(new Event(evt, { bubbles: true })); } catch (_) {}
        });
      };

      const findQuestionInput = () => {
        const selectors = [
          'input[name="title"]',
          'input[placeholder*="Question" i]',
          'textarea[placeholder*="Question" i]',
          'textarea[name="title"]',
          'input[type="text"]'
        ];
        for (const sel of selectors) {
          const node = document.querySelector(sel);
          if (node) return node;
        }
        return null;
      };

      const questionInput = findQuestionInput();
      if (questionInput) {
        setValue(questionInput, pollData.question);
      }

      const ensureOptionInputs = (count) => {
        const optionSelectors = [
          'input[placeholder*="Option" i]',
          'input[name^="option" i]',
          'input[data-testid*="option" i]',
          'input[type="text"]'
        ];
        const findAddButton = () => {
          const candidates = Array.from(document.querySelectorAll('button, a, div[role="button"]'));
          return candidates.find((el) => /add/i.test(el.textContent || '') && /option|choice|answer/i.test(el.textContent || ''));
        };

        const getOptions = () => {
          const seen = new Set();
          const nodes = [];
          for (const sel of optionSelectors) {
            document.querySelectorAll(sel).forEach((el) => {
              if (seen.has(el)) return;
              if (questionInput && el === questionInput) return;
              seen.add(el);
              nodes.push(el);
            });
          }
          return nodes;
        };

        while (getOptions().length < count) {
          const btn = findAddButton();
          if (!btn) break;
          btn.click();
        }

        return getOptions();
      };

      const options = ensureOptionInputs(pollData.options.length);
      options.forEach((input, idx) => {
        const value = pollData.options[idx] || pollData.options[pollData.options.length - 1];
        setValue(input, value);
      });

      const descriptionField = document.querySelector('textarea[placeholder*="Description" i], textarea[name="description"], textarea[rows], textarea');
      if (descriptionField) {
        setValue(descriptionField, pollData.description || '');
      }
    }, poll);

    await page.waitForTimeout(500);

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
    await page.waitForTimeout(1200);

    const publishedUrl = await page.evaluate(() => {
      const possible = [window.location.href];
      document.querySelectorAll('a[href*="strawpoll.com/"]').forEach((a) => possible.push(a.href));
      return possible.find((url) => /https?:\/\/strawpoll\.com\//i.test(url) && !/create/.test(url));
    });

    if (!publishedUrl) {
      throw new Error('POLL_URL_NOT_FOUND');
    }

    logLine('Publish success', { publishedUrl });
    await browser.close();
    return { ok: true, network: slug, publishedUrl, title: poll.question, logFile: LOG_FILE };
  } catch (error) {
    await browser.close().catch(() => {});
    logLine('Publish failed', { error: String(error && error.message || error) });
    return { ok: false, network: slug, error: String(error && error.message || error), logFile: LOG_FILE };
  }
}

module.exports = { publish };

runCli(module, publish, 'strawpoll');
