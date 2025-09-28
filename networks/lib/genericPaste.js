'use strict';

const puppeteer = require('puppeteer');
const { createLogger } = require('./logger');
const { generateArticle } = require('./articleGenerator');
const { htmlToMarkdown, htmlToPlainText } = require('./contentFormats');
const { fillTitleField, fillContentField, clickSubmit, waitForResult, sleep } = require('./puppeteerUtils');

function pickContentVariant(format, variants) {
  switch ((format || '').toLowerCase()) {
    case 'markdown':
      return variants.markdown || variants.html;
    case 'text':
    case 'plaintext':
      return variants.plain || variants.markdown || variants.html;
    case 'html':
    default:
      return variants.html;
  }
}

function createGenericPastePublisher(config) {
  if (!config || !config.slug) {
    throw new Error('Generic paste publisher requires config.slug');
  }

  const slug = config.slug;

  async function publish(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta) {
    const { LOG_FILE, LOG_DIR, logLine, logDebug } = createLogger(slug);
    logLine('Publish start', { slug, pageUrl, anchorText, language, provider: aiProvider || process.env.PP_AI_PROVIDER || 'openai' });

    const job = { pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta: pageMeta };

    const article = await generateArticle(job, logLine);
    logDebug('Article link stats', article.linkStats);

    const variants = {
      html: article.htmlContent,
      markdown: htmlToMarkdown(article.htmlContent),
      plain: htmlToPlainText(article.htmlContent)
    };

    let body = pickContentVariant(config.contentFormat || 'html', variants);
    if (config.prepareBody) {
      body = await config.prepareBody({ ...job, article, variants, logLine, logDebug }) || body;
    }

    const title = (config.prepareTitle ? await config.prepareTitle({ ...job, article, logLine, logDebug }) : article.title) || article.title;

    const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
    if (Array.isArray(config.extraLaunchArgs)) {
      launchArgs.push(...config.extraLaunchArgs.filter(Boolean));
    }
    if (process.env.PUPPETEER_ARGS) {
      launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
    }

    const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
    const launchOpts = { headless: config.headless !== false, args: Array.from(new Set(launchArgs)) };
    if (execPath) launchOpts.executablePath = execPath;

    logDebug('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
    const browser = await puppeteer.launch(launchOpts);
    let page = null;
    let popupPage = null;
    try {
      page = await browser.newPage();
      if (config.viewport) {
        try { await page.setViewport(config.viewport); } catch (_) {}
      } else {
        try { await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 }); } catch (_) {}
      }
      page.setDefaultTimeout(config.defaultTimeoutMs || 300000);
      page.setDefaultNavigationTimeout(config.defaultNavigationTimeoutMs || 300000);

      browser.on('targetcreated', async (target) => {
        try {
          if (target.type() === 'page') {
            const newPage = await target.page();
            popupPage = newPage;
          }
        } catch (_) {}
      });

      if (config.beforeGoto) {
        await config.beforeGoto({ page, job, article, variants, logLine, logDebug, browser });
      }

      const goToUrl = config.getStartUrl ? await config.getStartUrl({ job, article, variants }) : config.baseUrl;
      logLine('Goto start', { url: goToUrl });
      await page.goto(goToUrl, { waitUntil: config.waitUntil || 'networkidle2' });

      if (config.afterGoto) {
        await config.afterGoto({ page, job, article, variants, logLine, logDebug, browser });
      }

      if (config.waitForSelector) {
        try { await page.waitForSelector(config.waitForSelector, { timeout: config.waitForTimeoutMs || 20000 }); } catch (_) {}
      }

      const usePageForContent = config.contentContext === 'popup' && popupPage ? popupPage : page;
      if (config.preFill) {
        await config.preFill({ page, popupPage, article, variants, logLine, logDebug, body, title, browser, job });
      }

      if (config.disableTitle !== true) {
        const titleTarget = config.fillTitle ? await config.fillTitle({ page, popupPage, title, article, variants, job, logLine, logDebug }) : await fillTitleField(usePageForContent, title, config);
        logDebug('Title filled', { ok: !!titleTarget });
      }

      let bodyToUse = body;
      if (config.transformBodyBeforeFill) {
        bodyToUse = await config.transformBodyBeforeFill({ body, article, variants, job, logLine, logDebug }) || body;
      }

      let filled = false;
      if (config.fillContent) {
        filled = await config.fillContent({ page, popupPage, article, variants, job, body: bodyToUse, logLine, logDebug });
      } else {
        filled = await fillContentField(usePageForContent, bodyToUse, config);
      }
      logDebug('Content filled', { ok: filled });

      if (!filled && config.contentFallbackFormat && config.contentFallbackFormat !== config.contentFormat) {
        const fallbackBody = pickContentVariant(config.contentFallbackFormat, variants);
        logLine('Content fallback', { format: config.contentFallbackFormat });
        if (config.fillContent) {
          await config.fillContent({ page, popupPage, article, variants, job, body: fallbackBody, logLine, logDebug });
        } else {
          await fillContentField(usePageForContent, fallbackBody, config);
        }
      }

      if (config.beforeSubmit) {
        await config.beforeSubmit({ page, popupPage, article, variants, job, body: bodyToUse, browser, logLine, logDebug });
      }

      const startUrl = page.url();

      if (config.manualSubmit) {
        await config.manualSubmit({ page, popupPage, article, variants, job, logLine, logDebug, browser });
      } else {
        await clickSubmit(page, config);
        if (popupPage && config.clickSubmitOnPopup) {
          await sleep(500);
          try { await clickSubmit(popupPage, config); } catch (_) {}
        }
      }

      if (config.afterSubmit) {
        await config.afterSubmit({ page, popupPage, article, variants, job, logLine, logDebug, browser });
      }

      let publishedUrl = '';
      if (config.resolveResult) {
        publishedUrl = await config.resolveResult({ page, popupPage, article, variants, job, startUrl, logLine, logDebug, browser });
      } else {
        const target = popupPage && config.resultFromPopup ? popupPage : page;
        publishedUrl = await waitForResult(target || page, startUrl, config);
      }

      if (!publishedUrl && popupPage) {
        try { publishedUrl = popupPage.url(); } catch (_) {}
      }

      if (!publishedUrl || publishedUrl === startUrl) {
        try {
          const fallback = await page.evaluate(() => {
            const input = document.querySelector('input[value*="http"], input[readonly][value^="http"], textarea');
            if (input) {
              const val = input.value || input.innerText || '';
              return val.trim();
            }
            return '';
          });
          if (fallback && /https?:\/\//i.test(fallback)) {
            publishedUrl = fallback.trim();
          }
        } catch (_) {}
      }

      if (!publishedUrl || !/https?:\/\//i.test(publishedUrl)) {
        throw new Error('FAILED_TO_RESOLVE_URL');
      }

      logLine('Publish success', { publishedUrl });

      if (config.keepBrowserOpen) {
        // do nothing
      } else {
        await browser.close();
      }

      return {
        ok: true,
        network: slug,
        title,
        publishedUrl,
        format: config.contentFormat || 'html',
        logFile: LOG_FILE,
        logDir: LOG_DIR
      };
    } catch (error) {
      try { await browser.close(); } catch (_) {}
      logLine('Publish failed', { error: String(error && error.message || error) });
      return {
        ok: false,
        network: slug,
        error: String(error && error.message || error),
        logFile: LOG_FILE
      };
    }
  }

  return { publish };
}

function runCli(moduleObj, publishFn, slug) {
  if (!moduleObj || moduleObj !== require.main) {
    return;
  }
  (async () => {
    const { createLogger } = require('./logger');
    const { LOG_FILE, logLine } = createLogger(slug + '-cli');
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: raw.length });
      const job = JSON.parse(raw);
      logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: slug, logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: slug, logFile: LOG_FILE };
        logLine('Run failed (missing openai key)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }

      const res = await publishFn(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null);
      logLine('Success result', res);
      console.log(JSON.stringify(res));
      process.exit(res.ok ? 0 : 1);
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: slug };
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}

module.exports = { createGenericPastePublisher, runCli };
