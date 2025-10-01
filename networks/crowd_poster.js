#!/usr/bin/env node
// Generic crowd poster using Puppeteer
// Reads job from env PP_JOB and prints a single-line JSON as the last line of stdout

const puppeteer = require('puppeteer');

function pick(selAll) {
  if (!selAll) return null;
  for (const el of selAll) {
    if (el && typeof el.click === 'function') return el;
  }
  return null;
}

async function detectForm(page) {
  // Heuristics: look for forms with textarea or inputs that look like message/comment
  const formHandle = await page.evaluateHandle(() => {
    const lc = (s) => (s || '').toLowerCase();
    const hasMsgField = (form) => {
      const fields = Array.from(form.querySelectorAll('textarea,input[type="text"],input[type="search"],input[type="url"],input[type="email"],input[type="submit"],button'));
      let score = 0;
      for (const f of fields) {
        const name = lc(f.getAttribute('name'));
        const id = lc(f.id);
        const ph = lc(f.getAttribute('placeholder'));
        const type = lc(f.getAttribute('type'));
        const txt = lc(f.textContent || '');
        if (f.tagName.toLowerCase() === 'textarea') score += 2;
        if (/comment|message|content|text|reply|body/.test(name+id+ph)) score += 2;
        if (/comment|reply|коммент|ответ|сообщ/.test(txt)) score += 1;
        if (type === 'submit') score += 1;
      }
      return score;
    };
    let best = null; let bestScore = 0;
    for (const form of document.querySelectorAll('form')) {
      const sc = hasMsgField(form);
      if (sc > bestScore) { best = form; bestScore = sc; }
    }
    return best;
  });
  return formHandle;
}

async function detectLoginRequired(page) {
  // If page contains a password field or obvious login keywords, return true
  const hasPassword = await page.$('input[type="password"]').then(Boolean).catch(()=>false);
  if (hasPassword) return true;
  const text = await page.content().then(html => html.toLowerCase()).catch(()=> '');
  if (/login|sign\s*in|log\s*in|парол|войти|авторизац/.test(text)) return true;
  return false;
}

async function fillFields(page, form, job) {
  const { message, targetUrl, name, email } = job;
  const selectors = [
    { sel: 'textarea[name*="comment" i], textarea[name*="message" i], textarea[name*="content" i], textarea[name*="text" i], textarea[id*="comment" i], textarea[id*="message" i], textarea[id*="content" i], textarea[id*="text" i]', val: message },
    { sel: 'input[name*="name" i][type="text"], input[id*="name" i][type="text"], input[name*="author" i], input[id*="author" i]', val: name },
    { sel: 'input[type="email"], input[name*="mail" i], input[id*="mail" i]', val: email },
    { sel: 'input[type="url"], input[name*="url" i], input[name*="website" i], input[name*="site" i], input[name*="homepage" i], input[id*="url" i], input[id*="website" i]', val: targetUrl || '' },
    { sel: 'input[name*="subject" i], input[name*="title" i]', val: message.substring(0, 60) }
  ];
  for (const { sel, val } of selectors) {
    try {
      const el = await form.$(sel);
      if (el && val) {
        await el.focus();
        await el.click({ clickCount: 3 }).catch(()=>{});
        await el.type(val, { delay: 5 }).catch(()=>{});
      }
    } catch {}
  }
}

async function findSubmit(page, form) {
  const handles = await form.$$('button[type="submit"], input[type="submit"], button, input[type="button"]');
  for (const h of handles) {
    const txt = (await page.evaluate(el => (el.innerText||el.value||'').toLowerCase(), h)).trim();
    if (/comment|reply|post|send|submit|отправ|коммент|ответ/i.test(txt)) return h;
  }
  // fallback: first submit
  if (handles.length) return handles[0];
  return null;
}

async function getIndexStatus(page) {
  const robotsMeta = await page.$eval('meta[name="robots" i]', el => (el.getAttribute('content')||'').toLowerCase()).catch(()=> '');
  if (!robotsMeta) return 'unknown';
  if (robotsMeta.includes('noindex')) return 'noindex';
  if (robotsMeta.includes('index')) return 'index';
  return 'unknown';
}

async function getLangRegion(page) {
  const lang = await page.$eval('html', el => (el.getAttribute('lang')||'')).catch(()=> '');
  if (!lang) return { language: '', region: '' };
  const parts = lang.toLowerCase().split('-');
  if (parts.length === 2) return { language: parts[0], region: parts[1].toUpperCase() };
  return { language: parts[0], region: '' };
}

async function checkLinkFollow(page, targetUrl) {
  if (!targetUrl) return { linkFound: false, followType: 'unknown' };
  const found = await page.$(`a[href^="${CSS.escape(targetUrl)}"]`);
  if (!found) return { linkFound: false, followType: 'missing' };
  const rel = await page.evaluate(el => (el.getAttribute('rel')||'').toLowerCase(), found);
  if (rel.includes('nofollow')) return { linkFound: true, followType: 'nofollow' };
  return { linkFound: true, followType: 'follow' };
}

(async () => {
  const jobRaw = process.env.PP_JOB || '{}';
  let job;
  try { job = JSON.parse(jobRaw); } catch { job = {}; }
  const url = job.url || '';
  const message = job.message || '';
  const targetUrl = job.targetUrl || '';
  const timeout = Math.max(5, Math.min(300, job.timeoutSec || 60)) * 1000;
  let name = job.name || 'Promo QA';
  let email = job.email || '';
  if (!email) {
    const domains = ['gmail.com','yahoo.com','outlook.com','mail.com','proton.me'];
    const d = domains[Math.floor(Math.random()*domains.length)];
    const rand = Math.random().toString(36).slice(2,8);
    email = `qa.${rand}@${d}`;
  }

  if (!url) {
    console.log(JSON.stringify({ ok: false, error: 'NO_URL' }));
    return;
  }
  let browser;
  try {
    const launchOpts = { headless: 'new', args: (process.env.PUPPETEER_ARGS||'').split(' ').filter(Boolean) };
    if (process.env.PUPPETEER_EXECUTABLE_PATH) {
      launchOpts.executablePath = process.env.PUPPETEER_EXECUTABLE_PATH;
    }
    browser = await puppeteer.launch(launchOpts);
    const page = await browser.newPage();
    await page.setUserAgent('PromoPilotCrowdBot/1.0 (+https://promopilot.ai)');
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
    const status = resp ? resp.status() : 0;
    const loginNeeded = await detectLoginRequired(page);
    const form = await detectForm(page);
    if (!form) {
      const { language, region } = await getLangRegion(page);
      const indexStatus = await getIndexStatus(page);
      const error = loginNeeded ? 'LOGIN_REQUIRED' : 'FORM_NOT_FOUND';
      console.log(JSON.stringify({ ok: false, error, httpStatus: status, finalUrl: page.url(), language, region, indexStatus }));
      await browser.close();
      return;
    }
    await fillFields(page, form, { message, targetUrl, name, email });
    const submit = await findSubmit(page, form);
    if (!submit) {
      const { language, region } = await getLangRegion(page);
      const indexStatus = await getIndexStatus(page);
      console.log(JSON.stringify({ ok: false, error: 'SUBMIT_NOT_FOUND', httpStatus: status, finalUrl: page.url(), language, region, indexStatus }));
      await browser.close();
      return;
    }
    await Promise.all([
      submit.click().catch(()=>{}),
      page.waitForNavigation({ waitUntil: ['domcontentloaded','networkidle2'], timeout }).catch(()=>{})
    ]);
    const finalUrl = page.url();
    const { language, region } = await getLangRegion(page);
    const indexStatus = await getIndexStatus(page);
    // Verify presence
    const content = await page.content();
    const normMsg = (message||'').toLowerCase();
    const messageFound = normMsg ? content.toLowerCase().includes(normMsg) : false;
    const { linkFound, followType } = await checkLinkFollow(page, targetUrl);

    const result = {
      ok: messageFound || linkFound,
      httpStatus: status,
      finalUrl,
      messageFound,
      linkFound,
      followType: followType || 'unknown',
      indexStatus: indexStatus || 'unknown',
      language: language || '',
      region: region || ''
    };
    console.log(JSON.stringify(result));
    await browser.close();
  } catch (e) {
    try { if (browser) await browser.close(); } catch {}
    console.log(JSON.stringify({ ok: false, error: 'BROWSER_ERROR', details: String(e && e.message || e) }));
  }
})();
