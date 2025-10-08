
'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { waitForTimeoutSafe, clickSubmit } = require('./lib/puppeteerUtils');
const { solveIfCaptcha, detectCaptcha } = require('./captcha');
const { stripTags } = require('./lib/contentFormats');

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

function decodeBasicEntities(text) {
  return String(text || '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")
    .replace(/&#8211;/gi, '–')
    .replace(/&#8212;/gi, '—')
    .replace(/&#8230;/gi, '…')
    .replace(/&#160;/gi, ' ');
}

function wrapAnchorForControlC(match, href, inner) {
  const cleanText = decodeBasicEntities(stripTags(inner)) || href;
  const cleanHref = decodeBasicEntities(href).trim();
  if (!cleanHref) {
    return cleanText;
  }
  const text = (cleanText || '').trim();
  if (!text) {
    return cleanHref;
  }
  if (text.includes(cleanHref)) {
    return text;
  }
  return `${text} (${cleanHref})`;
}

function extractAttribute(fragment, name) {
  const regex = new RegExp(`${name}=["']([^"']+)["']`, 'i');
  const match = regex.exec(fragment || '');
  return match ? decodeBasicEntities(match[1]) : '';
}

function convertFigureToControlC(fragment) {
  return '';
}

function formatHeading(inner, size) {
  const text = decodeBasicEntities(stripTags(inner));
  if (!text) {
    return '';
  }
  return `\n[tpsize=${size}]${text}[/tpsize]\n`;
}

function convertHtmlToControlCMarkup(html) {
  let out = String(html || '');
  if (!out.trim()) {
    return '';
  }

  out = out.replace(/<figure[^>]*>[\s\S]*?<img[\s\S]*?>[\s\S]*?<\/figure>/gi, () => '');
  out = out.replace(/<img[^>]*>/gi, () => '');
  out = out.replace(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi, wrapAnchorForControlC);

  out = out.replace(/<h1[^>]*>([\s\S]*?)<\/h1>/gi, (_, inner) => formatHeading(inner, 7));
  out = out.replace(/<h2[^>]*>([\s\S]*?)<\/h2>/gi, (_, inner) => formatHeading(inner, 6));
  out = out.replace(/<h3[^>]*>([\s\S]*?)<\/h3>/gi, (_, inner) => formatHeading(inner, 5));
  out = out.replace(/<h4[^>]*>([\s\S]*?)<\/h4>/gi, (_, inner) => formatHeading(inner, 4));
  out = out.replace(/<h5[^>]*>([\s\S]*?)<\/h5>/gi, (_, inner) => formatHeading(inner, 3));
  out = out.replace(/<h6[^>]*>([\s\S]*?)<\/h6>/gi, (_, inner) => formatHeading(inner, 2));

  out = out.replace(/<blockquote[^>]*>([\s\S]*?)<\/blockquote>/gi, (_, inner) => {
    const text = decodeBasicEntities(stripTags(inner));
    return text ? `\n[quote]${text}[/quote]\n` : '';
  });

  out = out.replace(/<(?:strong|b)[^>]*>([\s\S]*?)<\/(?:strong|b)>/gi, (_, inner) => {
    const text = decodeBasicEntities(stripTags(inner));
    return text ? `[b]${text}[/b]` : '';
  });

  out = out.replace(/<(?:em|i)[^>]*>([\s\S]*?)<\/(?:em|i)>/gi, (_, inner) => {
    const text = decodeBasicEntities(stripTags(inner));
    return text ? `[i]${text}[/i]` : '';
  });

  out = out.replace(/<li[^>]*>([\s\S]*?)<\/li>/gi, (_, inner) => {
    const text = decodeBasicEntities(stripTags(inner));
    return text ? `\n- ${text}` : '';
  });
  out = out.replace(/<\/(?:ul|ol)>/gi, '\n');
  out = out.replace(/<(?:ul|ol)[^>]*>/gi, '\n');

  out = out.replace(/<p[^>]*>([\s\S]*?)<\/p>/gi, (_, inner) => {
    const text = decodeBasicEntities(stripTags(inner));
    return text ? `\n${text}\n` : '\n';
  });

  out = out.replace(/<br\s*\/?>/gi, '\n');
  out = out.replace(/<div[^>]*>/gi, '\n');
  out = out.replace(/<\/(?:div|span)>/gi, '\n');
  out = out.replace(/<span[^>]*>/gi, '');
  out = out.replace(/<[^>]+>/g, ' ');

  out = decodeBasicEntities(out);
  out = out.replace(/[ \t]+/g, ' ');
  out = out.replace(/ *(\n) */g, '$1');
  out = out.replace(/\n{3,}/g, '\n\n');
  out = out.replace(/\s+\[img\]/g, '\n[img]');
  out = out.replace(/\[img\]\s+/g, '[img]');
  out = out.replace(/\n-\s*\n/g, '\n');

  return out.trim();
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
        'input#directlink',
        'input[name="direct_link"]',
        'input[name="directlink"]',
        'textarea#codes',
        'textarea[name="codes"]',
        'textarea[name="content"]',
        'textarea#copylink',
        'textarea#copytextarea',
        '#copytextarea',
        '#copylink',
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

      const widgets = Array.from(document.querySelectorAll('[data-shortlink], [data-directlink]'));
      widgets.forEach((node) => {
        const direct = node.getAttribute('data-directlink') || node.getAttribute('data-shortlink');
        if (direct) push(direct);
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

async function isRecaptchaSolved(page) {
  try {
    return await page.evaluate(() => {
      const hasResponseField = () => {
        const field = document.querySelector('#g-recaptcha-response, textarea[name="g-recaptcha-response"], textarea#g-recaptcha-response');
        return !!(field && typeof field.value === 'string' && field.value.trim().length > 0);
      };
      try {
        if (window.grecaptcha && typeof window.grecaptcha.getResponse === 'function') {
          const response = window.grecaptcha.getResponse();
          if (typeof response === 'string' && response.trim().length > 0) return true;
        }
      } catch (_) {}
      return hasResponseField();
    });
  } catch (_) {
    return false;
  }
}

async function resetRecaptcha(page) {
  try {
    await page.evaluate(() => {
      if (window.grecaptcha && typeof window.grecaptcha.reset === 'function') {
        window.grecaptcha.reset();
      }
      const field = document.querySelector('#g-recaptcha-response, textarea[name="g-recaptcha-response"], textarea#g-recaptcha-response');
      if (field) field.value = '';
    });
  } catch (_) {}
}

async function ensureRecaptchaSolved(page, logLine, { attempts = 3, label = 'beforeSubmit' } = {}) {
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    if (await isRecaptchaSolved(page)) {
      logLine('ControlC captcha already solved', { stage: label, attempt });
      return true;
    }

    logLine('ControlC captcha solve attempt', { stage: label, attempt });
    const result = await solveIfCaptcha(page, logLine);
    const success = Boolean(result && (result === true || result.solved));
    if (success) {
      try {
        await page.waitForFunction(
          () => {
            const field = document.querySelector('#g-recaptcha-response, textarea[name="g-recaptcha-response"], textarea#g-recaptcha-response');
            if (field && field.value && field.value.trim().length > 0) return true;
            if (window.grecaptcha && typeof window.grecaptcha.getResponse === 'function') {
              const resp = window.grecaptcha.getResponse();
              return typeof resp === 'string' && resp.trim().length > 0;
            }
            return false;
          },
          { timeout: 15000 }
        );
      } catch (_) {}

      if (await isRecaptchaSolved(page)) {
        logLine('ControlC captcha solved', { stage: label, attempt });
        return true;
      }
    }

    logLine('ControlC captcha attempt failed', { stage: label, attempt, success });
    await resetRecaptcha(page);
    await waitForTimeoutSafe(page, 2000);

    try {
      const det = await detectCaptcha(page);
      if (!det || !det.found) {
        logLine('ControlC captcha no longer detected', { stage: label, attempt });
        if (await isRecaptchaSolved(page)) return true;
        // If no captcha is detected anymore, allow caller to proceed and handle later.
        return false;
      }
    } catch (_) {}
  }

  logLine('ControlC captcha unresolved', { stage: label, attempts });
  return false;
}

const config = {
  slug: 'controlc',
  baseUrl: 'https://controlc.com/',
  contentFormat: 'text',
  waitUntil: 'domcontentloaded',
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
  // Register a new account before publishing to keep sessions durable
  beforeGoto: async ({ page, logLine, job }) => {
    function randInt(min, max){ return Math.floor(Math.random()*(max-min+1))+min; }
    function randomString(len){ const s='abcdefghijklmnopqrstuvwxyz0123456789'; let out=''; for(let i=0;i<len;i++) out+=s[randInt(0,s.length-1)]; return out; }
    function randomUser(){ return randomString(randInt(8,12)); }
    function randomEmail(){ const domains=['gmail.com','outlook.com','yahoo.com','proton.me','me.com']; return `${randomString(randInt(9,14))}@${domains[randInt(0,domains.length-1)]}`; }
    function randomPassword(){ const a='ABCDEFGHJKLMNPQRSTUVWXYZ'; const b='abcdefghjkmnpqrstuvwxyz'; const c='23456789'; const d='!@#$%^&*?'; const pick=(p,n)=>Array.from({length:n},()=>p[randInt(0,p.length-1)]).join(''); const base=pick(a,2)+pick(b,4)+pick(c,3)+pick(d,1)+randomString(randInt(2,4)); return base.split('').sort(()=>Math.random()-0.5).join(''); }

    // Quick check: if already logged in (presence of Logout link)
    try {
      await page.goto('https://controlc.com/', { waitUntil: 'domcontentloaded' });
      const already = await page.evaluate(() => !!Array.from(document.querySelectorAll('a')).find(a=>/logout/i.test(a.textContent||'')));
      if (already) { logLine('ControlC already logged in'); return; }
    } catch(_) {}

    // Start registration
  const username = randomUser();
  const email = randomEmail();
  const password = randomPassword();
  // Temporarily log full password for debugging purposes
  logLine('ControlC register credentials', { username, email, password });
    try {
  await page.goto('https://controlc.com/register/', { waitUntil: 'domcontentloaded' });
      await waitForTimeoutSafe(page, 500);
      await page.type('input[name="login"]', username, { delay: 20 }).catch(()=>{});
      await page.type('input[name="email"]', email, { delay: 20 }).catch(()=>{});
      await page.type('input[name="password"]', password, { delay: 20 }).catch(()=>{});
      await page.type('input[name="password2"]', password, { delay: 20 }).catch(()=>{});
      // Agree TOS
      await page.evaluate(() => { const cb = document.querySelector('input#tosagree, input[name="agreed"]'); if (cb && !cb.checked) cb.click(); });
      // Solve captcha
      const det = await detectCaptcha(page); if (det && det.found) { await ensureRecaptchaSolved(page, logLine, { attempts: 3, label: 'register' }); }
      // Submit
      await page.evaluate(() => { const btn = document.querySelector('input[type="submit"].submit, input[type="submit"][value="Register"], input[type="submit"]'); if (btn) btn.click(); });
      await Promise.race([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(()=>null),
        waitForTimeoutSafe(page, 2500)
      ]);
      logLine('ControlC registration submitted');
    } catch (e) {
      logLine('ControlC registration error', { error: String(e && e.message || e) });
    }
  },
  afterGoto: async ({ page, logLine }) => {
    // Small stabilization pause to avoid race with dynamic widgets causing frame detach
    try { await waitForTimeoutSafe(page, 500); } catch (_) {}
    try { await page.waitForSelector('body', { timeout: 5000 }); } catch (_) {}
    logLine('ControlC afterGoto stabilized');
  },
  // Pre-fill paste options (enable code highlighting to improve formatting of markdown-like text)
  preFill: async ({ page, logLine }) => {
    try {
      await page.evaluate(() => {
        const sel = document.querySelector('select[name="code"]');
        // Disable code highlighting to avoid rendering markdown markers as code
        if (sel) { sel.value = '0'; try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch(_) {} }
      });
      logLine('ControlC preFill: code highlighting disabled');
    } catch (e) {
      logLine('ControlC preFill error', { error: String(e && e.message || e) });
    }
  },
  prepareBody: async ({ variants }) => convertHtmlToControlCMarkup(variants.html),
  beforeSubmit: async ({ page, logLine }) => {
    try {
      const solved = await ensureRecaptchaSolved(page, logLine, { attempts: 3, label: 'beforeSubmit' });
      if (!solved) {
        // If captcha is no longer detected or is a non-blocking variant (v3/anchor), proceed and handle in afterSubmit.
        try {
          const det = await detectCaptcha(page);
          if (!det || !det.found || det.type === 'recaptcha-v3' || det.type === 'recaptcha-anchor') {
            logLine('ControlC captcha not blocking before submit, proceeding', { detected: det && det.type ? det.type : 'none' });
          } else {
            logLine('ControlC captcha not solved before submit, will proceed and handle afterSubmit', { type: det.type });
          }
        } catch (_) {
          // ignore detection errors and proceed
        }
      }
      await page.evaluate(() => {
        try {
          if (typeof window.tpOnSubmit === 'function') {
            window.tpOnSubmit();
          }
        } catch (_) {}
      });
    } catch (error) {
      logLine('ControlC captcha solve error', { stage: 'beforeSubmit', error: String(error && error.message || error) });
      // Do not block submission here; afterSubmit will try to resolve captcha again if needed.
    }
  },
  afterSubmit: async ({ page, logLine }) => {
    try {
      await Promise.race([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null),
        waitForTimeoutSafe(page, 2500)
      ]);

      const sameUrl = await page.url();
      if (sameUrl && /^https?:\/\/controlc\.com\/?$/i.test(sameUrl) && !(await isRecaptchaSolved(page))) {
        // Give the page a moment to surface a challenge
        await waitForTimeoutSafe(page, 1500);
        for (let i = 0; i < 2; i += 1) {
          const det = await detectCaptcha(page);
          if (det && det.found && det.type !== 'recaptcha-v3' && det.type !== 'recaptcha-anchor') {
            logLine('ControlC captcha re-challenge detected', { stage: 'afterSubmit', type: det.type, attempt: i + 1 });
            const ok = await ensureRecaptchaSolved(page, logLine, { attempts: 2, label: 'afterSubmit' });
            if (ok) break;
          } else if (!det || !det.found) {
            break;
          }
          await waitForTimeoutSafe(page, 1200);
        }
      }
    } catch (error) {
      logLine('ControlC captcha solve error', { stage: 'afterSubmit', error: String(error && error.message || error) });
    }
  },
  resolveResult: async ({ page, popupPage, startUrl, logLine }) => {
    const target = popupPage || page;
    const seen = new Set();
    const deadline = Date.now() + RESULT_TIMEOUT_MS;
    let lastRetryTs = 0;

    while (Date.now() < deadline) {
      const { urls } = await collectCandidates(target);
      urls.forEach((url) => seen.add(url));

      const picked = pickBestControlCUrl(urls);
      if (picked) {
        return picked.trim();
      }

      // If we're still on the homepage without candidates, try to gently re-submit and solve captcha again
      try {
        const cur = await target.url();
        const now = Date.now();
        if (/^https?:\/\/controlc\.com\/?$/i.test(cur) && (now - lastRetryTs > 5000)) {
          lastRetryTs = now;
          // Attempt a quick captcha solve and re-click submit
          try {
            await ensureRecaptchaSolved(target, logLine, { attempts: 1, label: 'resolve' });
          } catch (_) {}
          try {
            await clickSubmit(target, {
              submitSelectors: [
                'button[type="submit"]',
                '#submit',
                '#submitbutton',
                '#submit_button',
                'button#submitbutton',
                'button[name="Submit"]',
                'input[type="submit"]',
                'input[name="submit"]'
              ]
            });
          } catch (_) {}
          await waitForTimeoutSafe(target, 1500);
        }
      } catch (_) {}

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
