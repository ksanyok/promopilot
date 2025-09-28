'use strict';

const fetch = require('node-fetch');

function readConfig() {
  let provider = String(process.env.PP_CAPTCHA_PROVIDER || '').toLowerCase();
  let apiKey = String(process.env.PP_CAPTCHA_API_KEY || '').trim();
  try {
    const job = JSON.parse(process.env.PP_JOB || '{}');
    if (job && job.captcha) {
      if (job.captcha.provider) provider = String(job.captcha.provider).toLowerCase();
      if (job.captcha.apiKey) apiKey = String(job.captcha.apiKey);
    }
  } catch (_) {}
  return { provider: provider || 'none', apiKey };
}

async function detectCaptcha(page) {
  try {
    return await page.evaluate(() => {
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
      const grid = document.querySelector('.captchaPanelMaster .captchaPanel .captchaGridContainer');
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
      // reCAPTCHA
      if (found('iframe[src*="recaptcha"], div.g-recaptcha, div#recaptcha, .grecaptcha-badge')) return { found: true, type: 'recaptcha' };
      // hCaptcha
      if (found('iframe[src*="hcaptcha"], .h-captcha')) return { found: true, type: 'hcaptcha' };
      // Cloudflare/generic
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
  } catch { return { found: false }; }
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

async function solveGridAntiCaptcha(page, apiKey, logger, takeScreenshot) {
  try {
    // Wait until tiles are actually loaded (avoid spinner state)
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
    const createResp = await fetch('https://api.anti-captcha.com/createTask', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ clientKey: apiKey, task: { type: 'ImageToCoordinatesTask', body: b64, comment: `Select all images with: ${word}` } })
    });
    const createData = await createResp.json().catch(() => ({ errorId: 1 }));
    if (!createData || createData.errorId) return false;
    const taskId = createData.taskId;
    const deadline = Date.now() + 180000;
    let solution = null;
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 5000));
      const r = await fetch('https://api.anti-captcha.com/getTaskResult', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ clientKey: apiKey, taskId })
      });
      const d = await r.json().catch(() => ({ errorId: 1 }));
      if (d && !d.errorId && d.status === 'ready') { solution = d.solution; break; }
      if (d && d.errorId) return false;
    }
    if (!solution || !Array.isArray(solution.coordinates) || solution.coordinates.length === 0) return false;
  const points = solution.coordinates.map(c => [Number(c.x)||0, Number(c.y)||0]);
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
    // try refresh button once
    await page.evaluate(() => { const r = document.querySelector('.captchaPanelMaster .CaptchaBottom .btn.btn-danger'); if (r) (r).click(); });
    return false;
  } catch { return false; }
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
    const sitekey = type === 'hcaptcha' ? keys.hcaptcha : keys.recaptcha;
    if (!sitekey) return false;
    if (provider === 'anti-captcha') {
      const create = await fetch('https://api.anti-captcha.com/createTask', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ clientKey: apiKey, task: { type: type === 'hcaptcha' ? 'HCaptchaTaskProxyless' : 'NoCaptchaTaskProxyless', websiteURL: pageUrl || (await page.url()), websiteKey: sitekey } })
      });
      const data = await create.json().catch(() => ({ errorId: 1 }));
      if (!data || data.errorId) return false;
      const taskId = data.taskId;
      const deadline = Date.now() + 180000;
      let token = '';
      while (Date.now() < deadline) {
        await new Promise(r => setTimeout(r, 5000));
        const r = await fetch('https://api.anti-captcha.com/getTaskResult', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ clientKey: apiKey, taskId }) });
        const d = await r.json().catch(() => ({ errorId: 1 }));
        if (d && !d.errorId && d.status === 'ready') { const sol = d.solution || {}; token = sol.gRecaptchaResponse || sol.token || ''; break; }
        if (d && d.errorId) return false;
      }
      if (!token) return false;
      const injected = await page.evaluate((captType, tok) => {
        const inject = (name) => {
          let el = document.querySelector(`textarea[name="${name}"]`) || document.querySelector(`#${name}`);
          if (!el) { el = document.createElement('textarea'); el.name = name; el.id = name; el.style.display = 'none'; document.body.appendChild(el); }
          el.value = tok; try { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); } catch {}
          return !!el;
        };
        const ok = captType === 'hcaptcha' ? inject('h-captcha-response') : inject('g-recaptcha-response');
        const form = document.querySelector('form'); if (form) { try { form.submit(); } catch {} }
        const btn = Array.from(document.querySelectorAll('button, input[type="submit"], a'))
          .find(el => /continue|verify|submit|продолжить|подтвердить/i.test(el.textContent||el.value||''));
        if (btn) { try { btn.click(); } catch {} }
        return ok;
      }, type, token);
  logger && logger('Captcha solved');
  return !!injected;
    } else if (provider === '2captcha') {
      const params = new URLSearchParams();
      params.set('key', apiKey);
      params.set('json', '1');
      params.set('method', type === 'hcaptcha' ? 'hcaptcha' : 'userrecaptcha');
      params.set(type === 'hcaptcha' ? 'sitekey' : 'googlekey', sitekey);
      params.set('pageurl', pageUrl || (await page.url()));
      const resp = await fetch('https://2captcha.com/in.php?' + params.toString());
      const data = await resp.json().catch(() => ({ status: 0 }));
      if (!data || data.status !== 1) return false;
      const id = data.request;
      const deadline = Date.now() + 180000;
      let token = '';
      while (Date.now() < deadline) {
        await new Promise(r => setTimeout(r, 5000));
        const ps = new URLSearchParams(); ps.set('key', apiKey); ps.set('action','get'); ps.set('id', id); ps.set('json','1');
        const r = await fetch('https://2captcha.com/res.php?' + ps.toString());
        const d = await r.json().catch(() => ({ status: 0 }));
        if (d && d.status === 1) { token = d.request; break; }
        if (d && typeof d.request === 'string' && d.request !== 'CAPCHA_NOT_READY') return false;
      }
      if (!token) return false;
      await page.evaluate((captType, tok) => {
        const inject = (name) => {
          let el = document.querySelector(`textarea[name="${name}"]`) || document.querySelector(`#${name}`);
          if (!el) { el = document.createElement('textarea'); el.name = name; el.id = name; el.style.display = 'none'; document.body.appendChild(el); }
          el.value = tok; try { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); } catch {}
        };
        if (captType === 'hcaptcha') inject('h-captcha-response'); else inject('g-recaptcha-response');
        const form = document.querySelector('form'); if (form) { try { form.submit(); } catch {} }
        const btn = Array.from(document.querySelectorAll('button, input[type="submit"], a'))
          .find(el => /continue|verify|submit|продолжить|подтвердить/i.test(el.textContent||el.value||''));
        if (btn) { try { btn.click(); } catch {} }
      }, type, token);
      logger && logger('Captcha solved');
      return true;
    }
    return false;
  } catch { return false; }
}

async function solveIfCaptcha(page, logger, takeScreenshot) {
  const { provider, apiKey } = readConfig();
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
  if (det.type === 'grid') {
    if (provider === 'anti-captcha') {
      result = await solveGridAntiCaptcha(page, apiKey, logger, takeScreenshot);
    } else if (provider === '2captcha') {
      result = await solveGrid2Captcha(page, apiKey, logger, takeScreenshot);
    } else {
      log && log('Captcha solve result', { provider, type: det.type, success: false, reason: 'unsupported_provider' });
      return false;
    }
  } else if (det.type === 'recaptcha' || det.type === 'hcaptcha') {
    result = await solveTokenCaptcha(page, det.type, provider, apiKey, await page.url(), logger);
  }
  const success = Boolean(result && (result === true || result.solved));
  log && log('Captcha solve result', { provider, type: det.type, success });
  return result;
}

module.exports = { detectCaptcha, solveIfCaptcha };
