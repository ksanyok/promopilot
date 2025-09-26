'use strict';

// Minimal, clean implementation for Telegraph publishing
// - Generates title, author, and article via OpenAI (gpt-3.5-turbo)
// - Collects microdata/SEO from the target page to guide content
// - Uses one organic inline link to the target URL
// - Uses <h2> subheadings as requested

const puppeteer = require('puppeteer');
const fetch = require('node-fetch');
const fs = require('fs');
const path = require('path');

// Simple file logger similar to previous implementation
const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
const LOG_FILE = process.env.PP_LOG_FILE || path.join(
  LOG_DIR,
  `network-telegraph-${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}.log`
);
function ensureDirSync(dir){
  try { fs.mkdirSync(dir, { recursive: true }); } catch(_) {}
}
ensureDirSync(LOG_DIR);
function safeStringify(obj){
  try { return JSON.stringify(obj); } catch(_) { return String(obj); }
}
function logLine(msg, data){
  const ts = new Date().toISOString();
  const line = `[${ts}] ${msg}` + (data !== undefined ? ` | ${safeStringify(data)}` : '');
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch(_) {}
  // Also mirror to stdout for quick debugging
  try { console.log(line); } catch(_) {}
}

async function generateTextWithChat(prompt, openaiApiKey) {
  logLine('OpenAI request', { promptPreview: String(prompt || '').slice(0, 160) });
  const res = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${openaiApiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model: 'gpt-3.5-turbo',
      messages: [{ role: 'user', content: String(prompt || '') }],
      temperature: 0.8,
    })
  });
  if (!res.ok) {
    const body = await res.text().catch(()=> '');
    logLine('OpenAI HTTP error', { status: res.status, statusText: res.statusText, body: body.slice(0, 400) });
    return '';
  }
  const data = await res.json().catch(()=> null);
  const content = data && data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content || '';
  logLine('OpenAI response ok', { length: String(content).length });
  return String(content).trim();
}

function stripTags(html) { return String(html || '').replace(/<[^>]+>/g, '').trim(); }
function extractAttr(tagHtml, attr) {
  const m = String(tagHtml || '').match(new RegExp(attr + '\\s*=\\s*([\"\'])(.*?)\\1', 'i'));
  return m ? m[2] : '';
}

async function extractPageMeta(url) {
  logLine('Fetch page for meta', { url });
  try {
    const r = await fetch(url, {
      method: 'GET',
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; PromoPilot/1.0; +https://example.com/bot)',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'ru,en;q=0.8'
      }
    });
    const html = await r.text();
    const out = { title: '', description: '' };

    const t = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
    const titleTag = t ? stripTags(t[1]) : '';

    const metaDesc = html.match(/<meta[^>]+name=[\"\']description[\"\'][^>]*>/i);
    const metaDescVal = metaDesc ? extractAttr(metaDesc[0], 'content') : '';

    const ogTitleTag = html.match(/<meta[^>]+property=[\"\']og:title[\"\'][^>]*>/i);
    const ogTitle = ogTitleTag ? extractAttr(ogTitleTag[0], 'content') : '';
    const ogDescTag = html.match(/<meta[^>]+property=[\"\']og:description[\"\'][^>]*>/i);
    const ogDesc = ogDescTag ? extractAttr(ogDescTag[0], 'content') : '';

    const twTitleTag = html.match(/<meta[^>]+name=[\"\']twitter:title[\"\'][^>]*>/i);
    const twTitle = twTitleTag ? extractAttr(twTitleTag[0], 'content') : '';
    const twDescTag = html.match(/<meta[^>]+name=[\"\']twitter:description[\"\'][^>]*>/i);
    const twDesc = twDescTag ? extractAttr(twDescTag[0], 'content') : '';

    const h1 = html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);
    const h1Text = h1 ? stripTags(h1[1]) : '';

    // JSON-LD
    let ldTitle = '', ldDesc = '';
    const ldBlocks = html.match(/<script[^>]+type=[\"\']application\/ld\+json[\"\'][^>]*>[\s\S]*?<\/script>/gi) || [];
    for (const block of ldBlocks) {
      const jsonText = (block.match(/>([\s\S]*?)<\/script>/i) || [,''])[1];
      try {
        const data = JSON.parse(jsonText);
        const arr = Array.isArray(data) ? data : [data];
        for (const item of arr) {
          const candTitle = item.headline || item.name || (item.article && item.article.headline) || '';
          const candDesc = item.description || (item.article && item.article.description) || '';
          if (!ldTitle && candTitle) ldTitle = String(candTitle);
          if (!ldDesc && candDesc) ldDesc = String(candDesc);
        }
      } catch (_) {}
      if (ldTitle && ldDesc) break;
    }

    out.title = (ldTitle || ogTitle || twTitle || h1Text || titleTag || '').trim();
    out.description = (ldDesc || ogDesc || twDesc || metaDescVal || '').trim();

    // Clean noisy suffixes like categories separated by dashes
    out.title = out.title.replace(/[\n\r\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();
    out.description = out.description.replace(/[\n\r\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();

    logLine('Meta extracted', { title: out.title, descriptionPreview: out.description.slice(0, 160) });
    return out;
  } catch (e) {
    logLine('Meta extract failed', { error: String(e && e.message || e) });
    return { title: '', description: '' };
  }
}

function cleanTitle(t) {
  t = String(t || '').trim();
  t = t.replace(/["'«»“”„]+/g, '').replace(/[.]+$/g, '').trim();
  if (!t) t = 'Untitled';
  return t;
}

function integrateSingleAnchor(html, pageUrl, anchorText) {
  let s = String(html || '');
  // Remove all anchors except those that point to pageUrl
  s = s.replace(/<a\s+([^>]*?)>([\s\S]*?)<\/a>/gi, (full, attrs, text) => {
    const m = String(attrs || '').match(/href=[\"\']([^\"\']+)[\"\']/i);
    const href = m && m[1] ? m[1] : '';
    if (href && href.replace(/\/$/, '') === String(pageUrl).replace(/\/$/, '')) return full; // keep only our link
    return text; // unwrap others
  });

  const linkRe = new RegExp('<a\\s+[^>]*href=[\\"\']' + pageUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[\\"\']', 'i');
  if (linkRe.test(s)) return s; // already present

  // Otherwise, inject into the first sufficiently long paragraph
  const paras = s.match(/<p[\s>][\s\S]*?<\/p>/gi) || [];
  if (paras.length) {
    for (let i = 0; i < paras.length; i++) {
      const p = paras[i];
      const text = stripTags(p);
      if (text.length < 120) continue;
      const injected = p.replace(/<\/p>\s*$/i, ` <a href="${pageUrl}">${anchorText}</a></p>`);
      return s.replace(p, injected);
    }
  }
  // Fallback: prepend link as a separate paragraph under the first heading
  return s.replace(/(<h2[\s>][\s\S]*?<\/h2>)/i, `$1\n<p><a href="${pageUrl}">${anchorText}</a></p>`);
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey) {
  logLine('Publish start', { pageUrl, anchorText, language });
  const meta = await extractPageMeta(pageUrl);

  const prompts = {
    title: (
      `Write a concise, specific ${language} title that reflects the topic. ` +
      `No quotes, no trailing dots. Avoid generic placeholders like Introduction/Введение.\n` +
      `Base it on:\nTitle: "${meta.title || ''}"\nDescription: "${meta.description || ''}"\nURL: ${pageUrl}`
    ),
    author: (
      `Suggest a neutral author's name in ${language}. ` +
      `Use ${language} alphabet. One or two words. Reply with the name only.`
    ),
    content: (
      `Write an article in ${language} of at least 3000 characters based on the page ${pageUrl}. ` +
      `Use this context: title: "${meta.title || ''}", description: "${meta.description || ''}".\n` +
      `Requirements:\n` +
      `- Use clear structure: short intro, 3–5 sections with <h2> subheadings, one bulleted list where relevant, and a brief conclusion.\n` +
      `- Include exactly one active link to <a href="${pageUrl}">${anchorText}</a> inside a paragraph in the first half of the article (organically).\n` +
      `- Use only simple HTML tags: <p>, <h2>, <ul>, <li>, <a>, <strong>, <em>, <blockquote>. No images, scripts or inline styles.\n` +
      `- Stay strictly on-topic and do not add unrelated content.`
    )
  };
  logLine('Prompts prepared');

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // Generate title, author, content with small pauses
  const rawTitle = await generateTextWithChat(prompts.title, openaiApiKey);
  await sleep(1500);
  const rawAuthor = await generateTextWithChat(prompts.author, openaiApiKey);
  await sleep(1500);
  let rawContent = await generateTextWithChat(prompts.content, openaiApiKey);

  const title = cleanTitle(rawTitle);
  const author = String(rawAuthor || '').split(/\r?\n/)[0].replace(/["'«»“”„]+/g, '').trim() || 'PromoPilot';
  logLine('Generated', { title, author, contentLen: String(rawContent || '').length });

  // Basic content cleanup and link normalization
  let content = String(rawContent || '').trim();
  // Remove disallowed tags
  content = content.replace(/<(?!\/?(p|h2|ul|li|a|strong|em|blockquote)\b)[^>]*>/gi, '');
  // Wrap free text lines into <p> if no tags present
  if (!/<\s*(p|h2|ul|li|blockquote|a)\b/i.test(content)) {
    const parts = content.split(/\n{2,}/).map(p => p.trim()).filter(Boolean).map(p => `<p>${p}</p>`);
    content = parts.join('\n');
  }
  // Ensure at least one <h2>
  if (!/<h2[\s>]/i.test(content)) {
    // Convert markdown-like headings to <h2>
    content = content.replace(/^[\t ]*##[\t ]+(.+)$/gmi, '<h2>$1</h2>');
  }
  content = integrateSingleAnchor(content, pageUrl, anchorText);

  // Launch Puppeteer with optional explicit executable path
  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });

  const browser = await puppeteer.launch(launchOpts);
  let page;
  try {
    page = await browser.newPage();
    logLine('Goto Telegraph');
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 120000 });

    logLine('Fill title');
    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 60000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(title);

    logLine('Fill author');
    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 60000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author);

    logLine('Fill content');
    await page.evaluate((html) => {
      const el = document.querySelector('p[data-placeholder="Your story..."]');
      if (el) {
        el.innerHTML = html;
      } else {
        const root = document.querySelector('.tl_article .ql-editor') || document.querySelector('div.ql-editor');
        if (!root) throw new Error('Telegraph editor not found');
        root.innerHTML = html;
      }
    }, content);

    logLine('Publish click');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 120000 }),
      page.click('button.publish_button')
    ]);

    const publishedUrl = page.url();
    logLine('Published', { publishedUrl });

    const result = { ok: true, network: 'telegraph', publishedUrl, title, author, logFile: LOG_FILE };
    logLine('Success result', result);
    return result;
  } catch (e) {
    logLine('Publish failed', { error: String(e && e.message || e), stack: e && e.stack });
    throw e;
  } finally {
    try { if (page) await page.close(); } catch(_) { logLine('Page close failed'); }
    try { await browser.close(); logLine('Browser closed'); } catch(_) { logLine('Browser close failed'); }
  }
}

module.exports = { publish: publishToTelegraph };

// CLI entrypoint for PromoPilot runner (reads PP_JOB from env)
if (require.main === module) {
  (async () => {
    try {
      const raw = process.env.PP_JOB || '{}';
      logLine('PP_JOB raw', { length: raw.length });
      const job = JSON.parse(raw);
      logLine('PP_JOB parsed');
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      if (!pageUrl || !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url or openaiApiKey missing', network: 'telegraph', logFile: LOG_FILE };
        logLine('Run failed (missing params)', payload);
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      const res = await publishToTelegraph(pageUrl, anchor, language, apiKey);
      console.log(JSON.stringify(res));
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'telegraph', logFile: LOG_FILE };
      logLine('Run failed', { error: payload.error, stack: e && e.stack });
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}