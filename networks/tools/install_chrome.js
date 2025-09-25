// Helper to install/download Chromium for PromoPilot networks
const fs = require('fs');
const path = require('path');

function ensureDirSync(dir) {
  try { fs.mkdirSync(dir, { recursive: true }); } catch (_) {}
}

function log(payload) {
  try { console.log(JSON.stringify(payload)); } catch (_) {
    process.stdout.write('{}');
  }
}

(async () => {
  const puppeteer = require('puppeteer');
  const cacheDir = process.env.PP_CHROME_CACHE || path.join(process.cwd(), '.cache', 'puppeteer');
  ensureDirSync(cacheDir);
  const buildId = process.env.PP_CHROME_BUILD || '127.0.6533.88';
const fetcher = puppeteer.createBrowserFetcher({ path: cacheDir, product: 'chrome' });
const existing = fetcher.localRevisions();
if (existing.includes(buildId)) {
  const info = fetcher.revisionInfo(buildId);
  try {
    const infoFile = path.join(cacheDir, 'chrome-info.json');
    ensureDirSync(path.dirname(infoFile));
    fs.writeFileSync(infoFile, JSON.stringify({
      path: info.executablePath,
      source: 'downloaded',
      timestamp: new Date().toISOString(),
      suggestions: [],
      buildId,
    }, null, 2));
  } catch (error) { /* ignore */ }
  log({ ok: true, installed: false, message: 'Chromium already available', path: info.executablePath, buildId, cacheDir });
  return;
}
  const info = await fetcher.download(buildId);
  const result = {
    ok: true,
    installed: true,
    path: info.executablePath,
    buildId,
    cacheDir,
  };
  const infoFile = path.join(cacheDir, 'chrome-info.json');
  try {
    ensureDirSync(path.dirname(infoFile));
    fs.writeFileSync(infoFile, JSON.stringify({
      path: info.executablePath,
      source: 'downloaded',
      timestamp: new Date().toISOString(),
      suggestions: [],
      buildId,
    }, null, 2));
    result.infoFile = infoFile;
  } catch (error) {
    result.infoFileError = String(error);
  }
  log(result);
})().catch((error) => {
  log({ ok: false, error: error.message || String(error), stack: error && error.stack });
  process.exit(1);
});
