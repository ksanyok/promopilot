'use strict';

const fs = require('fs');
const path = require('path');

let browser = null;

function safeConsole(payload) {
  try {
    console.log(JSON.stringify(payload));
  } catch (err) {
    try { console.log('{"ok":false,"error":"JSON_SERIALIZE_FAILED"}'); } catch {}
  }
}

function parseJob() {
  const raw = process.env.PP_JOB || '{}';
  try {
    const job = JSON.parse(raw);
    if (!job || typeof job !== 'object') return {};
    return job;
  } catch (err) {
    return {};
  }
}

let puppeteer;
try {
  puppeteer = require('puppeteer');
} catch (err) {
  safeConsole({ ok: false, error: 'DEPENDENCY_MISSING: puppeteer' });
  process.exit(1);
}

process.on('uncaughtException', (err) => {
  if (browser) { browser.close().catch(() => {}); }
  safeConsole({ ok: false, error: String(err && err.message ? err.message : err) });
  process.exit(1);
});
process.on('unhandledRejection', (err) => {
  if (browser) { browser.close().catch(() => {}); }
  safeConsole({ ok: false, error: String(err && err.message ? err.message : err) });
  process.exit(1);
});

(async () => {
  const job = parseJob();
  const targetUrl = String(job.targetUrl || '').trim();
  const outputPath = String(job.outputPath || '').trim();
  if (!targetUrl || !/^https?:\/\//i.test(targetUrl)) {
    safeConsole({ ok: false, error: 'INVALID_TARGET_URL' });
    return;
  }
  if (!outputPath) {
    safeConsole({ ok: false, error: 'INVALID_OUTPUT_PATH' });
    return;
  }

  const dir = path.dirname(outputPath);
  fs.mkdirSync(dir, { recursive: true });

  const args = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    const extra = String(process.env.PUPPETEER_ARGS)
      .split(/\s+/)
      .map((v) => v.trim())
      .filter(Boolean);
    args.push(...extra);
  }
  const headless = String(process.env.PP_HEADLESS || 'true').toLowerCase() !== 'false';
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || process.env.PP_CHROME_PATH || '';

  browser = await puppeteer.launch({
    headless,
    args: Array.from(new Set(args)),
    executablePath: execPath || undefined,
  });

  const page = await browser.newPage();
  const viewport = job.viewport || {};
  await page.setViewport({
    width: Math.max(320, Number(viewport.width) || 1280),
    height: Math.max(400, Number(viewport.height) || 720),
    deviceScaleFactor: Number(viewport.deviceScaleFactor) > 0 ? Number(viewport.deviceScaleFactor) : 1.2,
  });
  const timeoutMs = Number(job.timeoutMs || process.env.PP_TIMEOUT_MS || 60000);
  page.setDefaultTimeout(timeoutMs);
  page.setDefaultNavigationTimeout(Number(job.navTimeoutMs || process.env.PP_NAV_TIMEOUT_MS || timeoutMs));

  if (job.userAgent) {
    try { await page.setUserAgent(String(job.userAgent)); } catch (_) {}
  }
  if (job.headers && typeof job.headers === 'object') {
    const headers = {};
    for (const [key, value] of Object.entries(job.headers)) {
      headers[String(key)] = String(value);
    }
    try { await page.setExtraHTTPHeaders(headers); } catch (_) {}
  }
  if (Array.isArray(job.cookies) && job.cookies.length > 0) {
    try { await page.setCookie(...job.cookies); } catch (_) {}
  }

  let response = null;
  try {
    response = await page.goto(targetUrl, {
      waitUntil: job.waitUntil || 'networkidle2',
      timeout: Number(job.gotoTimeoutMs || timeoutMs),
    });
  } catch (err) {
    if (String(job.ignoreGotoErrors || '').toLowerCase() !== 'true') {
      throw err;
    }
  }

  if (Number(job.delayMs || 0) > 0) {
    await new Promise((resolve) => setTimeout(resolve, Number(job.delayMs)));
  }
  if (job.waitForSelector) {
    try {
      await page.waitForSelector(String(job.waitForSelector), {
        timeout: Number(job.selectorTimeoutMs || timeoutMs / 2),
        visible: !!job.waitForVisible,
      });
    } catch (err) {
      if (!job.ignoreSelectorTimeout) {
        throw err;
      }
    }
  }

  const screenshotOptions = {
    path: outputPath,
    type: job.imageType || 'webp',
    fullPage: !!job.fullPage,
    omitBackground: !!job.omitBackground,
  };
  if (screenshotOptions.type === 'jpeg' || screenshotOptions.type === 'webp') {
    const q = Number(job.quality || 82);
    if (!Number.isNaN(q) && q >= 1 && q <= 100) {
      screenshotOptions.quality = q;
    }
  }
  if (job.clip && typeof job.clip === 'object') {
    screenshotOptions.clip = {
      x: Math.max(0, Number(job.clip.x) || 0),
      y: Math.max(0, Number(job.clip.y) || 0),
      width: Math.max(1, Number(job.clip.width) || 1),
      height: Math.max(1, Number(job.clip.height) || 1),
    };
  }

  await page.screenshot(screenshotOptions);

  const finalUrl = page.url();

  await page.close();
  await browser.close();
  browser = null;

  const stats = fs.statSync(outputPath);
  safeConsole({
    ok: true,
    previewPath: outputPath,
    bytes: stats.size,
    modified: stats.mtimeMs,
    finalUrl,
    status: response ? response.status() : null,
  });
})().catch(async (err) => {
  if (browser) {
    try { await browser.close(); } catch (_) {}
    browser = null;
  }
  safeConsole({ ok: false, error: String(err && err.message ? err.message : err) });
  process.exit(1);
});
