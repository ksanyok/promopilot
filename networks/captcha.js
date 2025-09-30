'use strict';

const fetch = require('node-fetch');

let antiCaptchaClient = null;
let antiCaptchaModuleName = '';
try {
  antiCaptchaClient = require('@antiadmin/anticaptchaofficial');
  antiCaptchaModuleName = '@antiadmin/anticaptchaofficial';
} catch (_) {
  try {
    antiCaptchaClient = require('anticaptchaofficial');
    antiCaptchaModuleName = 'anticaptchaofficial';
  } catch (_) {
    antiCaptchaClient = null;
  }
}
let antiCaptchaConfiguredKey = '';
let antiCaptchaUnavailableLogged = false;

function ensureAntiCaptchaClient(apiKey, logger) {
  if (!antiCaptchaClient) {
    if (!antiCaptchaUnavailableLogged && logger) {
      logger('AntiCaptcha SDK unavailable', { module: antiCaptchaModuleName || 'not-installed' });
      antiCaptchaUnavailableLogged = true;
    }
    return null;
  }
  if (!apiKey) return null;
  try {
    if (antiCaptchaConfiguredKey !== apiKey && typeof antiCaptchaClient.setAPIKey === 'function') {
      antiCaptchaClient.setAPIKey(apiKey);
      antiCaptchaConfiguredKey = apiKey;
    }
    return antiCaptchaClient;
  } catch (e) {
    if (logger) logger('AntiCaptcha SDK init error', { error: String(e && e.message || e) });
    return null;
  }
}

function readConfig() {
  let provider = String(process.env.PP_CAPTCHA_PROVIDER || '').toLowerCase();
  let apiKey = String(process.env.PP_CAPTCHA_API_KEY || '').trim();
  let fallbackProvider = String(process.env.PP_CAPTCHA_FALLBACK_PROVIDER || '').toLowerCase();
  let fallbackApiKey = String(process.env.PP_CAPTCHA_FALLBACK_API_KEY || '').trim();
  try {
    const job = JSON.parse(process.env.PP_JOB || '{}');
    if (job && job.captcha) {
      if (job.captcha.provider) provider = String(job.captcha.provider).toLowerCase();
      if (job.captcha.apiKey) apiKey = String(job.captcha.apiKey);
      if (job.captcha.fallback && job.captcha.fallback.provider) fallbackProvider = String(job.captcha.fallback.provider).toLowerCase();
      if (job.captcha.fallback && job.captcha.fallback.apiKey) fallbackApiKey = String(job.captcha.fallback.apiKey);
    }
  } catch (_) {}
  return { provider: provider || 'none', apiKey, fallbackProvider: fallbackProvider || '', fallbackApiKey };
}

async function detectCaptcha(page) {
  const detectInContext = async (ctx) => {
    try {
      return await ctx.evaluate(() => {
        const found = (sel) => !!document.querySelector(sel);
        const hasText = (t) => (document.body?.innerText || '').toLowerCase().includes(t);
        const visible = (el) => {
          if (!el) return false;
          const style = window.getComputedStyle(el);
          const rect = el.getBoundingClientRect();
          return style && style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 2 && rect.height > 2;
        };
        const master = document.querySelector('.captchaPanelMaster');
        const panel = document.querySelector('.captchaPanelMaster .captchaPanel');
        const grid = document.querySelector('.captchaPanelMaster .captchaGridContainer');
        const tiles = Array.from(document.querySelectorAll('.captchaPanelMaster .clickableImage'));
        const word = (document.querySelector('.captchaPanelMaster .CaptchaHead .correctWord')?.textContent || '').trim();
        const gridVisible = visible(panel) && (visible(grid) || tiles.some(visible));
        if (gridVisible || (master && visible(master) && (grid || tiles.length))) {
          return {
            found: true,
            type: 'grid',
            word,
            debug: {
              hasMaster: !!master,
              hasPanel: !!panel,
              hasGrid: !!grid,
              tileCount: tiles.length,
              panelVisible: visible(panel),
              masterVisible: visible(master)
            }
          };
        }
        // reCAPTCHA detection nuances: distinguish v2 widget/challenge vs v3 script/badge.
  const hasAnchor = !!document.querySelector('iframe[src*="/recaptcha/api2/anchor"], div.g-recaptcha[data-sitekey], div#recaptcha[data-sitekey]');
  const hasBframe = !!document.querySelector('iframe[src*="/recaptcha/api2/bframe"], iframe[src*="/recaptcha/enterprise/anchor/frame"], iframe[src*="/recaptcha/enterprise/bframe"]');
        const hasBadge = !!document.querySelector('.grecaptcha-badge');
        const hasV3Script = Array.from(document.scripts || []).some(s => (s.src||'').includes('recaptcha/api.js') && (s.src||'').includes('render='));
        if (hasBframe) return { found: true, type: 'recaptcha-challenge' };
        if (hasAnchor && !hasBframe) return { found: true, type: 'recaptcha-anchor' };
        if (hasV3Script && !hasAnchor && !hasBframe) return { found: true, type: 'recaptcha-v3' };
        if (hasBadge && !hasAnchor && !hasBframe) {
          // Badge alone is typical for reCAPTCHA v3 or invisible v2; avoid forcing solve until challenge appears.
          return { found: true, type: 'recaptcha-v3' };
        }
        if (found('iframe[src*="hcaptcha"], .h-captcha')) return { found: true, type: 'hcaptcha' };
        if (hasText('captcha') || hasText('verify you are human') || found('#cf-challenge-running')) return { found: true, type: 'generic' };
        return {
          found: false,
          debug: {
            hasMaster: !!master,
            hasPanel: !!panel,
            hasGrid: !!grid,
            tileCount: tiles.length,
            masterVisible: master ? visible(master) : false,
            panelVisible: panel ? visible(panel) : false
          }
        };
      });
    } catch (_) {
      return { found: false, error: 'EVAL_FAILED' };
    }
  };

  let summaryDebug = { contexts: [] };
  const mainRes = await detectInContext(page);
  if (mainRes && mainRes.found) {
    return { ...mainRes, context: 'main', frameUrl: page.url ? page.url() : '' };
  }
  if (mainRes && mainRes.debug) summaryDebug.contexts.push({ context: 'main', debug: mainRes.debug });

  const frames = typeof page.frames === 'function' ? page.frames() : [];
  for (const frame of frames) {
    let frameUrl = '';
    try { frameUrl = frame.url(); } catch (_) { frameUrl = ''; }
    if (frameUrl && /hcaptcha\.com/i.test(frameUrl)) {
      return { found: true, type: 'hcaptcha', context: 'frame-url', frameUrl };
    }
    if (frameUrl && /google\.com\/recaptcha/i.test(frameUrl)) {
      if (/api2\/bframe|enterprise\/bframe/i.test(frameUrl)) {
        return { found: true, type: 'recaptcha-challenge', context: 'frame-url', frameUrl };
      }
      if (/api2\/anchor|enterprise\/anchor/i.test(frameUrl)) {
        // anchor alone is not a challenge
        return { found: true, type: 'recaptcha-anchor', context: 'frame-url', frameUrl };
      }
    }
    const frameRes = await detectInContext(frame);
    if (frameRes && frameRes.found) {
      return { ...frameRes, context: 'frame', frameUrl: frame.url ? frame.url() : '' };
    }
    if (frameRes && frameRes.debug) {
      summaryDebug.contexts.push({ context: 'frame', frameUrl: frame.url ? frame.url() : '', debug: frameRes.debug });
    }
  }
  return { found: false, debug: summaryDebug.contexts.length ? summaryDebug : undefined };
}

async function waitForGridReady(page, logger, timeoutMs = 45000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    try {
      const state = await page.evaluate(() => {
        const wrap = document.querySelector('.captchaPanelMaster .captchaPanel');
        if (!wrap) return { found: false };
        const checking = !!Array.from(wrap.querySelectorAll('*')).find(el => /checking your browser/i.test(el.textContent||''));
        const tiles = Array.from(document.querySelectorAll('.captchaPanelMaster .clickableImage'));
        const ready = tiles.length > 0 && tiles.some(t => {
          const cs = window.getComputedStyle(t);
          const bg = cs.backgroundImage || '';
          return /url\(.*\)/i.test(bg) && !/loader|loading|spinner/i.test(bg);
        });
        return { found: true, checking, ready, tiles: tiles.length };
      });
      if (!state.found) return false;
      if (state.ready && !state.checking) return true;
    } catch {}
    await new Promise(r => setTimeout(r, 700));
  }
  logger && logger('Captcha grid not ready (timeout)');
  return false;
}

async function getSiteKeys(page) {
  try {
    return await page.evaluate(() => {
      const out = { recaptcha: '', hcaptcha: '' };
      const findParam = (src, key) => {
        try { const u = new URL(src, location.href); return u.searchParams.get(key) || ''; }
        catch { return ''; }
      };
      const r1 = document.querySelector('div.g-recaptcha[data-sitekey]');
      if (r1) out.recaptcha = r1.getAttribute('data-sitekey') || '';
      if (!out.recaptcha) {
        const r2 = Array.from(document.querySelectorAll('iframe[src*="recaptcha"]'))
          .map(f => findParam(f.getAttribute('src')||'', 'k') || findParam(f.getAttribute('src')||'', 'sitekey'))
          .find(Boolean);
        if (r2) out.recaptcha = r2;
      }
      const h1 = document.querySelector('div.h-captcha[data-sitekey], .h-captcha[data-sitekey]');
      if (h1) out.hcaptcha = h1.getAttribute('data-sitekey') || '';
      if (!out.hcaptcha) {
        const h2 = Array.from(document.querySelectorAll('iframe[src*="hcaptcha.com"]'))
          .map(f => findParam(f.getAttribute('src')||'', 'sitekey'))
          .find(Boolean);
        if (h2) out.hcaptcha = h2;
      }
      return out;
    });
  } catch { return { recaptcha: '', hcaptcha: '' }; }
}

async function getRecaptchaContext(page) {
  try {
    return await page.evaluate(() => {
      const findParam = (src, key) => {
        try { const u = new URL(src, location.href); return u.searchParams.get(key) || ''; }
        catch { return ''; }
      };
      const frames = Array.from(document.querySelectorAll('iframe[src*="recaptcha"]'));
      let sitekey = '';
      let isEnterprise = false;
      let s = '';
      for (const f of frames) {
        const src = f.getAttribute('src') || '';
        if (!src) continue;
        if (/recaptcha\/enterprise/i.test(src)) isEnterprise = true;
        const k = findParam(src, 'k') || findParam(src, 'sitekey');
        if (k) sitekey = sitekey || k;
        const ds = findParam(src, 's');
        if (ds) s = s || ds;
      }
      if (!sitekey) {
        const r1 = document.querySelector('div.g-recaptcha[data-sitekey], div#recaptcha[data-sitekey]');
        if (r1) sitekey = r1.getAttribute('data-sitekey') || '';
      }
      // Fallback: inspect scripts for enterprise
      if (!isEnterprise) {
        isEnterprise = Array.from(document.scripts || []).some(scp => (scp.src||'').includes('/recaptcha/enterprise'));
      }
      return { sitekey, isEnterprise, sToken: s };
    });
  } catch {
    return { sitekey: '', isEnterprise: false, sToken: '' };
  }
}

async function solveGridAntiCaptcha(page, apiKey, logger, takeScreenshot) {
  try {
    const ready = await waitForGridReady(page, logger);
    if (!ready) return false;
    const info = await page.evaluate(() => {
      const word = (document.querySelector('.captchaPanelMaster .CaptchaHead .correctWord')?.textContent || '').trim();
      const tiles = Array.from(document.querySelectorAll('.captchaPanelMaster .clickableImage'));
      const rects = tiles.map(t => t.getBoundingClientRect());
      if (rects.length === 0) return null;
      let left = Infinity, top = Infinity, right = -Infinity, bottom = -Infinity;
      rects.forEach(r => { left = Math.min(left, r.left); top = Math.min(top, r.top); right = Math.max(right, r.right); bottom = Math.max(bottom, r.bottom); });
      const centers = rects.map(r => ({ x: r.left + r.width/2, y: r.top + r.height/2 }));
      return { word, clip: { x: left, y: top, width: right-left, height: bottom-top }, centers };
    });
    if (!info) return false;
    const { word, clip, centers } = info;
    logger && logger('Captcha detected', { type: 'grid', word });
    await new Promise(r => setTimeout(r, 700));
    const buf = await page.screenshot({ clip: { x: Math.max(0, clip.x), y: Math.max(0, clip.y), width: Math.max(1, clip.width), height: Math.max(1, clip.height) } });
    const b64 = buf.toString('base64');
    const instructions = `Select all images with: ${word}`;

    const client = ensureAntiCaptchaClient(apiKey, logger);
    let solutionPoints = [];
    if (client && typeof client.solveImageToCoordinates === 'function') {
      try {
        const sdkRes = await client.solveImageToCoordinates(b64, instructions);
        solutionPoints = normalizeCoordinateSolution(sdkRes);
        if (solutionPoints.length) {
          logger && logger('Captcha solver sdk', { module: antiCaptchaModuleName, points: solutionPoints.length });
        }
      } catch (e) {
        logger && logger('Captcha solver sdk error', { error: String(e && e.message || e) });
      }
    }
    if (!solutionPoints.length) {
      const fallback = await solveGridAntiCaptchaViaHttp(apiKey, b64, instructions, logger);
      solutionPoints = normalizeCoordinateSolution(fallback);
    }
    if (!solutionPoints.length) return false;

    const clickedIdx = new Set();
    for (const point of solutionPoints) {
      const px = Number(point.x);
      const py = Number(point.y);
      if (!isFinite(px) || !isFinite(py)) continue;
      const ax = clip.x + px;
      const ay = clip.y + py;
      let best = -1;
      let bestDist = Infinity;
      centers.forEach((c, i) => {
        const dx = c.x - ax;
        const dy = c.y - ay;
        const dist = dx * dx + dy * dy;
        if (dist < bestDist) { bestDist = dist; best = i; }
      });
      if (best >= 0 && !clickedIdx.has(best)) {
        clickedIdx.add(best);
        await page.evaluate((index) => {
          const tiles = Array.from(document.querySelectorAll('.captchaPanelMaster .clickableImage'));
          const el = tiles[index];
          if (el) { el.scrollIntoView({ block: 'center' }); el.click(); }
        }, best);
        await new Promise(r => setTimeout(r, 150));
      }
    }

    let shotPath = '';
    if (typeof takeScreenshot === 'function') {
      try { shotPath = await takeScreenshot('captcha'); } catch (_) {}
    }
    await page.evaluate(() => { const b = document.querySelector('.captchaPanelMaster .CaptchaButtonVerify'); if (b) (b).click(); });
    await new Promise(r => setTimeout(r, 1500));
    const after = await page.evaluate(() => !!document.querySelector('.captchaPanelMaster .captchaPanel .captchaGridContainer'));
    if (!after) { logger && logger('Captcha solved'); return { solved: true, screenshot: shotPath }; }
    await page.evaluate(() => { const r = document.querySelector('.captchaPanelMaster .CaptchaBottom .btn.btn-danger'); if (r) (r).click(); });
    return false;
  } catch { return false; }
}

function normalizeCoordinateSolution(raw) {
  if (!raw) return [];
  const list = Array.isArray(raw) ? raw : (raw && typeof raw === 'object' && Array.isArray(raw.coordinates) ? raw.coordinates : [raw]);
  return list.map((item) => {
    if (Array.isArray(item)) {
      return { x: Number(item[0]) || 0, y: Number(item[1]) || 0 };
    }
    if (item && typeof item === 'object') {
      const x = Number(item.x);
      const y = Number(item.y);
      if (isFinite(x) && isFinite(y)) return { x, y };
    }
    return null;
  }).filter(Boolean);
}

async function solveGridAntiCaptchaViaHttp(apiKey, imageBase64, comment, logger) {
  if (!apiKey) return null;
  try {
    const createResp = await fetch('https://api.anti-captcha.com/createTask', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        clientKey: apiKey,
        task: {
          type: 'ImageToCoordinatesTask',
          body: imageBase64,
          comment
        }
      })
    });
    const createData = await createResp.json().catch(() => ({ errorId: 1 }));
    if (!createData || createData.errorId) {
      logger && logger('AntiCaptcha http create error', { errorId: createData && createData.errorId, details: createData });
      return null;
    }
    const taskId = createData.taskId;
    const deadline = Date.now() + 180000;
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 5000));
      const resultResp = await fetch('https://api.anti-captcha.com/getTaskResult', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ clientKey: apiKey, taskId })
      });
      const data = await resultResp.json().catch(() => ({ errorId: 1 }));
      if (data && !data.errorId && data.status === 'ready') {
        return data.solution || null;
      }
      if (data && data.errorId) {
        logger && logger('AntiCaptcha http result error', { errorId: data.errorId, details: data });
        return null;
      }
    }
    logger && logger('AntiCaptcha http timeout', { taskId });
  } catch (e) {
    logger && logger('AntiCaptcha http exception', { error: String(e && e.message || e) });
  }
  return null;
}

async function solveGrid2Captcha(page, apiKey, logger, takeScreenshot) {
  try {
    const ready = await waitForGridReady(page, logger);
    if (!ready) return false;
    const info = await page.evaluate(() => {
      const word = (document.querySelector('.captchaPanelMaster .CaptchaHead .correctWord')?.textContent || '').trim();
      const tiles = Array.from(document.querySelectorAll('.captchaPanelMaster .clickableImage'));
      const rects = tiles.map(t => t.getBoundingClientRect());
      if (rects.length === 0) return null;
      let left = Infinity, top = Infinity, right = -Infinity, bottom = -Infinity;
      rects.forEach(r => { left = Math.min(left, r.left); top = Math.min(top, r.top); right = Math.max(right, r.right); bottom = Math.max(bottom, r.bottom); });
      const centers = rects.map(r => ({ x: r.left + r.width/2, y: r.top + r.height/2 }));
      return { word, clip: { x: left, y: top, width: right-left, height: bottom-top }, centers };
    });
    if (!info) return false;
    const { word, clip, centers } = info;
    logger && logger('Captcha detected', { type: 'grid', word });
  // small settle to ensure all tiles fully painted
  await new Promise(r => setTimeout(r, 700));
  const buf = await page.screenshot({ clip: { x: Math.max(0, clip.x), y: Math.max(0, clip.y), width: Math.max(1, clip.width), height: Math.max(1, clip.height) } });
    const b64 = buf.toString('base64');
    const params = new URLSearchParams();
    params.set('key', apiKey);
    params.set('method', 'base64');
    params.set('coordinatescaptcha', '1');
    params.set('json', '1');
    params.set('body', b64);
    params.set('textinstructions', `Select all images with: ${word}`);
    const inResp = await fetch('https://2captcha.com/in.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() });
    const inData = await inResp.json().catch(() => ({}));
    if (!inData || inData.status !== 1) return false;
    const id = String(inData.request);
    const deadline = Date.now() + 180000;
    let coords = null;
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 5000));
      const ps = new URLSearchParams(); ps.set('key', apiKey); ps.set('action','get'); ps.set('id', id); ps.set('json','1');
      const r = await fetch('https://2captcha.com/res.php?' + ps.toString());
      const d = await r.json().catch(() => ({}));
      if (d && d.status === 1 && d.request) { coords = String(d.request); break; }
      if (d && d.request && d.request !== 'CAPCHA_NOT_READY') return false;
    }
    if (!coords) return false;
  const points = coords.split('|').map(p => p.split(',').map(n => parseFloat(n))).filter(a => a.length === 2 && a.every(v => isFinite(v)));
    const clickedIdx = new Set();
    for (const [px, py] of points) {
      const ax = clip.x + px; const ay = clip.y + py;
      let best = -1; let bestDist = Infinity;
      centers.forEach((c, i) => { const dx = c.x - ax, dy = c.y - ay; const dist = dx*dx + dy*dy; if (dist < bestDist) { bestDist = dist; best = i; } });
      if (best >= 0 && !clickedIdx.has(best)) {
        clickedIdx.add(best);
        await page.evaluate((index) => {
          const tiles = Array.from(document.querySelectorAll('.captchaPanelMaster .clickableImage'));
          const el = tiles[index];
          if (el) { el.scrollIntoView({block:'center'}); el.click(); }
        }, best);
        await new Promise(r => setTimeout(r, 150));
      }
    }
    // Take screenshot when captcha is fully loaded and selections are made, just before Verify
    let shotPath = '';
    if (typeof takeScreenshot === 'function') {
      try { shotPath = await takeScreenshot('captcha'); } catch (_) {}
    }
    await page.evaluate(() => { const b = document.querySelector('.captchaPanelMaster .CaptchaButtonVerify'); if (b) (b).click(); });
  await new Promise(r => setTimeout(r, 1500));
    const after = await page.evaluate(() => !!document.querySelector('.captchaPanelMaster .captchaPanel .captchaGridContainer'));
  if (!after) { logger && logger('Captcha solved'); return { solved: true, screenshot: shotPath }; }
    await page.evaluate(() => { const r = document.querySelector('.captchaPanelMaster .CaptchaBottom .btn.btn-danger'); if (r) (r).click(); });
    return false;
  } catch { return false; }
}

async function solveTokenCaptcha(page, type, provider, apiKey, pageUrl, logger) {
  try {
    const keys = await getSiteKeys(page);
    const rCtx = type === 'hcaptcha' ? { sitekey: keys.hcaptcha, isEnterprise: false, sToken: '' } : await getRecaptchaContext(page);
    const sitekey = type === 'hcaptcha' ? keys.hcaptcha : rCtx.sitekey;
    if (!sitekey) return false;
    const targetUrl = pageUrl || (await page.url());
    logger && logger('Captcha solving context', { type, provider, sitekeyPresent: Boolean(sitekey), isEnterprise: rCtx.isEnterprise, sToken: rCtx.sToken ? 'present' : 'absent', pageUrl: targetUrl });
    let token = '';
    if (provider === 'anti-captcha') {
      token = await solveTokenCaptchaAntiCaptcha(apiKey, type, sitekey, targetUrl, logger, rCtx.isEnterprise, rCtx.sToken);
    } else if (provider === '2captcha') {
      token = await solveTokenCaptcha2Captcha(apiKey, type, sitekey, targetUrl, logger, rCtx.isEnterprise, rCtx.sToken);
    } else if (provider === 'capsolver') {
      token = await solveTokenCaptchaCapSolver(apiKey, type, sitekey, targetUrl, logger, rCtx.isEnterprise, rCtx.sToken);
    } else {
      return false;
    }
    if (!token) return false;
    const injected = await injectCaptchaToken(page, type, token);
    if (injected) logger && logger('Captcha solved');
    return injected;
  } catch { return false; }
}

async function solveTokenCaptchaAntiCaptcha(apiKey, type, sitekey, pageUrl, logger, isEnterprise = false, sToken = '') {
  if (!apiKey) return '';
  let token = '';
  const client = ensureAntiCaptchaClient(apiKey, logger);
  if (client) {
    try {
      if (type === 'hcaptcha' && typeof client.solveHCaptchaProxyless === 'function') {
        token = await client.solveHCaptchaProxyless(sitekey, pageUrl);
      } else if ((type === 'recaptcha' || type === 'recaptcha-challenge') && isEnterprise) {
        // Try enterprise via HTTP (SDK method may be unavailable)
        token = '';
      } else if ((type === 'recaptcha' || type === 'recaptcha-challenge') && typeof client.solveRecaptchaV2Proxyless === 'function') {
        token = await client.solveRecaptchaV2Proxyless(sitekey, pageUrl);
      }
      if (token && typeof token === 'object') {
        const obj = token;
        token = obj.token || obj.gRecaptchaResponse || obj.solution || '';
      }
    } catch (e) {
      logger && logger('Captcha solver sdk error', { stage: 'token', type, error: String(e && e.message || e) });
      token = '';
    }
  }
  if (!token || typeof token !== 'string') {
    token = await solveTokenCaptchaAntiCaptchaHttp(apiKey, type, sitekey, pageUrl, logger, isEnterprise, sToken);
  }
  return typeof token === 'string' ? token : '';
}

async function solveTokenCaptchaAntiCaptchaHttp(apiKey, type, sitekey, pageUrl, logger, isEnterprise = false, sToken = '') {
  try {
    const create = await fetch('https://api.anti-captcha.com/createTask', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        clientKey: apiKey,
        task: (
          type === 'hcaptcha' ? {
            type: 'HCaptchaTaskProxyless',
            websiteURL: pageUrl,
            websiteKey: sitekey
          } : (
            isEnterprise ? {
              type: 'RecaptchaV2EnterpriseTaskProxyless',
              websiteURL: pageUrl,
              websiteKey: sitekey,
              enterprisePayload: sToken ? { s: sToken } : {}
            } : {
              type: 'NoCaptchaTaskProxyless',
              websiteURL: pageUrl,
              websiteKey: sitekey
            }
          )
        )
      })
    });
    const data = await create.json().catch(() => ({ errorId: 1 }));
    if (!data || data.errorId) {
      logger && logger('AntiCaptcha http create error', { stage: 'token', errorId: data && data.errorId, details: data });
      return '';
    }
    const taskId = data.taskId;
    const deadline = Date.now() + 180000;
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 5000));
      const resp = await fetch('https://api.anti-captcha.com/getTaskResult', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ clientKey: apiKey, taskId })
      });
      const result = await resp.json().catch(() => ({ errorId: 1 }));
      if (result && !result.errorId && result.status === 'ready') {
        const sol = result.solution || {};
        return sol.gRecaptchaResponse || sol.token || '';
      }
      if (result && result.errorId) {
        logger && logger('AntiCaptcha http result error', { stage: 'token', errorId: result.errorId, details: result });
        return '';
      }
    }
    logger && logger('AntiCaptcha http timeout', { stage: 'token', taskId });
  } catch (e) {
    logger && logger('AntiCaptcha http exception', { stage: 'token', error: String(e && e.message || e) });
  }
  return '';
}

async function solveTokenCaptchaCapSolver(apiKey, type, sitekey, pageUrl, logger, isEnterprise = false, sToken = '') {
  // https://api.capsolver.com/createTask / getTaskResult
  try {
    const task = (type === 'hcaptcha')
      ? { type: 'HCaptchaTaskProxyless', websiteURL: pageUrl, websiteKey: sitekey }
      : (isEnterprise ? {
          type: 'ReCaptchaV2EnterpriseTaskProxyless',
          websiteURL: pageUrl,
          websiteKey: sitekey,
          enterprisePayload: sToken ? { s: sToken } : {}
        } : {
          type: 'ReCaptchaV2TaskProxyless',
          websiteURL: pageUrl,
          websiteKey: sitekey
        });
    const createResp = await fetch('https://api.capsolver.com/createTask', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ clientKey: apiKey, task })
    });
    const createData = await createResp.json().catch(() => ({ errorId: 1 }));
    if (!createData || createData.errorId) {
      logger && logger('CapSolver create error', { stage: 'token', errorId: createData && createData.errorId, details: createData });
      return '';
    }
    const taskId = createData.taskId;
    const deadline = Date.now() + 180000;
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 5000));
      const res = await fetch('https://api.capsolver.com/getTaskResult', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ clientKey: apiKey, taskId })
      });
      const data = await res.json().catch(() => ({ errorId: 1 }));
      if (data && !data.errorId && data.status === 'ready') {
        const sol = data.solution || {};
        return sol.gRecaptchaResponse || sol.token || '';
      }
      if (data && data.errorId) {
        logger && logger('CapSolver result error', { stage: 'token', errorId: data.errorId, details: data });
        return '';
      }
    }
    logger && logger('CapSolver timeout', { stage: 'token', taskId });
  } catch (e) {
    logger && logger('CapSolver exception', { stage: 'token', error: String(e && e.message || e) });
  }
  return '';
}

async function solveTokenCaptcha2Captcha(apiKey, type, sitekey, pageUrl, logger, isEnterprise = false, sToken = '') {
  try {
    const params = new URLSearchParams();
    params.set('key', apiKey);
    params.set('json', '1');
    params.set('method', type === 'hcaptcha' ? 'hcaptcha' : 'userrecaptcha');
    params.set(type === 'hcaptcha' ? 'sitekey' : 'googlekey', sitekey);
    params.set('pageurl', pageUrl);
    if (type !== 'hcaptcha' && isEnterprise) params.set('enterprise', '1');
    if (type !== 'hcaptcha' && sToken) params.set('data-s', sToken);
    const resp = await fetch('https://2captcha.com/in.php?' + params.toString());
    const data = await resp.json().catch(() => ({ status: 0 }));
    if (!data || data.status !== 1) {
      logger && logger('2Captcha create error', { status: data && data.status, details: data });
      return '';
    }
    const id = data.request;
    const deadline = Date.now() + 180000;
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 5000));
      const ps = new URLSearchParams();
      ps.set('key', apiKey);
      ps.set('action', 'get');
      ps.set('id', id);
      ps.set('json', '1');
      const r = await fetch('https://2captcha.com/res.php?' + ps.toString());
      const d = await r.json().catch(() => ({ status: 0 }));
      if (d && d.status === 1) {
        return typeof d.request === 'string' ? d.request : '';
      }
      if (d && typeof d.request === 'string' && d.request !== 'CAPCHA_NOT_READY') {
        logger && logger('2Captcha result error', { request: d.request });
        return '';
      }
    }
    logger && logger('2Captcha timeout', { id });
  } catch (e) {
    logger && logger('2Captcha exception', { error: String(e && e.message || e) });
  }
  return '';
}

async function injectCaptchaToken(page, type, token) {
  if (!token) return false;
  try {
    return await page.evaluate((captType, tok) => {
      const names = captType === 'hcaptcha'
        ? ['h-captcha-response']
        : ['g-recaptcha-response','g-recaptcha-response-100000'];

      const createHidden = (container, name) => {
        try {
          let el = container.querySelector(`textarea[name="${name}"]`) || container.querySelector(`#${name}`);
          if (!el) {
            el = document.createElement('textarea');
            el.name = name;
            el.id = name;
            el.style.display = 'none';
            container.appendChild(el);
          }
          el.value = tok;
          ['input', 'change', 'keyup', 'blur'].forEach(evt => {
            try { el.dispatchEvent(new Event(evt, { bubbles: true })); } catch(_) {}
          });
          return true;
        } catch(_) { return false; }
      };

      let placed = false;
      // 1) Prefer putting tokens inside forms that look like auth forms
      const forms = Array.from(document.querySelectorAll('form'));
      const candidateForms = forms.filter(f => {
        const txt = (f.textContent || '').toLowerCase();
        const hasAuthInputs = f.querySelector('input[type=email], input[name=email], input[type=password], input[name=password]');
        const hasSubmit = f.querySelector('button[type=submit], input[type=submit]');
        return hasAuthInputs || hasSubmit || txt.includes('sign') || txt.includes('login') || txt.includes('register');
      });
      if (candidateForms.length) {
        for (const form of candidateForms) {
          for (const n of names) { if (createHidden(form, n)) placed = true; }
        }
      }
      // 2) Also add to any form that contains a visible submit button to be safe
      if (!placed) {
        for (const form of forms) {
          const hasSubmit = form.querySelector('button[type=submit], input[type=submit]');
          if (hasSubmit) { for (const n of names) { if (createHidden(form, n)) placed = true; } }
        }
      }
      // 3) Fallback: add to body
      for (const n of names) { if (createHidden(document.body, n)) placed = true; }

      // Provide an escape hatch for SPA listeners
      try { window.__pp_grecaptcha_token = tok; } catch(_) {}

      // Monkey-patch grecaptcha APIs so app-level calls can pick our token
      try {
        if (window.grecaptcha) {
          // Patch non-enterprise getResponse
          try {
            if (typeof window.grecaptcha.getResponse === 'function') {
              const orig = window.grecaptcha.getResponse.bind(window.grecaptcha);
              window.grecaptcha.getResponse = function(...args) {
                try { return tok || orig(...args); } catch(_) { return tok; }
              };
            }
          } catch(_) {}
          // Patch enterprise getToken to resolve our token
          try {
            if (window.grecaptcha.enterprise && typeof window.grecaptcha.enterprise.getToken === 'function') {
              const origEnt = window.grecaptcha.enterprise.getToken.bind(window.grecaptcha.enterprise);
              window.grecaptcha.enterprise.getToken = function(...args) {
                return Promise.resolve(tok);
              };
            }
          } catch(_) {}
        }
      } catch(_) {}

      return placed;
    }, type, token);
  } catch {
    return false;
  }
}

async function solveIfCaptcha(page, logger, takeScreenshot) {
  const { provider, apiKey, fallbackProvider, fallbackApiKey } = readConfig();
  const log = typeof logger === 'function' ? logger : null;
  log && log('Captcha solver init', { provider, apiKeyPresent: Boolean(apiKey) });
  if (!apiKey || provider === 'none') {
    log && log('Captcha solver skipped', { reason: !apiKey ? 'missing_api_key' : 'provider_none' });
    return false;
  }
  const det = await detectCaptcha(page);
  if (!det || !det.found) {
    log && log('Captcha detection', { provider, found: false, type: det && det.type ? det.type : 'unknown', debug: det && det.debug ? det.debug : undefined });
    return false;
  }
  log && log('Captcha detection', { provider, found: true, type: det.type, details: det });
  let result = false;
  if (det.type === 'recaptcha-v3' || det.type === 'recaptcha-anchor') {
    // reCAPTCHA v3 should not be proactively solved; wait until site actually presents a challenge.
    log && log('Captcha solve skipped', { reason: 'recaptcha_badge_or_anchor_only' });
    return false;
  } else if (det.type === 'grid') {
    if (provider === 'anti-captcha') {
      result = await solveGridAntiCaptcha(page, apiKey, logger, takeScreenshot);
    } else if (provider === '2captcha') {
      result = await solveGrid2Captcha(page, apiKey, logger, takeScreenshot);
    } else {
      log && log('Captcha solve result', { provider, type: det.type, success: false, reason: 'unsupported_provider' });
      return false;
    }
  } else if (det.type === 'recaptcha' || det.type === 'recaptcha-challenge' || det.type === 'hcaptcha') {
    result = await solveTokenCaptcha(page, det.type, provider, apiKey, await page.url(), logger);
  }
  const success = Boolean(result && (result === true || result.solved));
  log && log('Captcha solve result', { provider, type: det.type, success });
  if (!success && fallbackProvider && fallbackApiKey && fallbackProvider !== provider) {
    log && log('Captcha fallback attempt', { from: provider, to: fallbackProvider });
    // Quick swap: temporarily override env for nested call
    const original = { PP_CAPTCHA_PROVIDER: process.env.PP_CAPTCHA_PROVIDER, PP_CAPTCHA_API_KEY: process.env.PP_CAPTCHA_API_KEY };
    try {
      process.env.PP_CAPTCHA_PROVIDER = fallbackProvider;
      process.env.PP_CAPTCHA_API_KEY = fallbackApiKey;
      const det2 = await detectCaptcha(page);
      if (det2 && det2.found) {
        let result2 = false;
        if (det2.type === 'grid') {
          if (fallbackProvider === 'anti-captcha') result2 = await solveGridAntiCaptcha(page, fallbackApiKey, logger, takeScreenshot);
          else if (fallbackProvider === '2captcha') result2 = await solveGrid2Captcha(page, fallbackApiKey, logger, takeScreenshot);
        } else if (det2.type === 'recaptcha' || det2.type === 'recaptcha-challenge' || det2.type === 'hcaptcha') {
          result2 = await solveTokenCaptcha(page, det2.type, fallbackProvider, fallbackApiKey, await page.url(), logger);
        }
        const ok2 = Boolean(result2 && (result2 === true || result2.solved));
        log && log('Captcha fallback result', { provider: fallbackProvider, type: det2.type, success: ok2 });
        if (ok2) return result2;
      }
    } finally {
      process.env.PP_CAPTCHA_PROVIDER = original.PP_CAPTCHA_PROVIDER || '';
      process.env.PP_CAPTCHA_API_KEY = original.PP_CAPTCHA_API_KEY || '';
    }
  }
  return result;
}

module.exports = { detectCaptcha, solveIfCaptcha };
