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

// Unified AI call: try Responses API (gpt-5-mini), fallback to Chat Completions
async function generateTextWithAI(prompt, openaiApiKey) {
  try {
    const r = await fetch('https://api.openai.com/v1/responses', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${openaiApiKey}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ model: 'gpt-5-mini', input: String(prompt || ''), reasoning: { effort: 'low' } })
    });
    const raw = await r.text().catch(()=> '');
    if (!r.ok) {
      logLine('OpenAI HTTP error', { status: r.status, statusText: r.statusText, body: raw.slice(0, 200) });
      return '';
    }

    let data = null;
    try { data = JSON.parse(raw); } catch(_) {}

    let text = '';
    if (data) {
      if (typeof data.output_text === 'string') text = data.output_text;
      else if (Array.isArray(data.output) && data.output[0] && data.output[0].content && data.output[0].content[0] && data.output[0].content[0].text) {
        text = data.output[0].content[0].text;
      } else if (Array.isArray(data.choices) && data.choices[0] && data.choices[0].message && data.choices[0].message.content) {
        text = data.choices[0].message.content;
      }
    }
    return String(text || '').trim();
  } catch (e) {
    logLine('OpenAI request failed', { error: String(e && e.message || e) });
    return '';
  }
}

function stripTags(html) { return String(html || '').replace(/<[^>]+>/g, '').trim(); }
function extractAttr(tagHtml, attr) {
  const m = String(tagHtml || '').match(new RegExp(attr + '\\s*=\\s*([\"\'])(.*?)\\1', 'i'));
  return m ? m[2] : '';
}

async function extractPageMeta(url) {
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

    logLine('Meta extracted', { title: out.title.slice(0, 100), desc: out.description.slice(0, 140) });
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

function isHeadingCandidate(text) {
  const t = String(text || '').trim();
  if (t.length < 3 || t.length > 100) return false;
  const hasPeriod = /\./.test(t);
  const endsQorEx = /[!?]$/.test(t);
  const hasColon = /:/.test(t);
  const tooManySentences = /[.!?].+?[.!?]/.test(t);
  if (tooManySentences) return false;
  if (hasColon || endsQorEx) return true;
  // Short and no period looks like a section label
  if (!hasPeriod && /^[^\n]{3,80}$/.test(t)) return true;
  return false;
}

function normalizeArticleHtml(raw, pageUrl, anchorText) {
  let s = String(raw || '').replace(/\r\n/g, '\n');

  // First, break existing paragraphs by <br> into atomic blocks
  s = s.replace(/<p[^>]*>([\s\S]*?)<\/p>/gi, (m, inner) => {
    const txt = String(inner).replace(/<br[^>]*>/gi, '\n');
    const chunks = txt.split(/\n{2,}|\n/g).map(t => t.trim()).filter(Boolean);
    if (!chunks.length) return '';
    return chunks.map(chunk => (isHeadingCandidate(chunk) ? `<h2>${chunk}</h2>` : `<p>${chunk}</p>`)).join('\n');
  });

  // Trim and collapse excessive blank lines
  s = s.replace(/\n{3,}/g, '\n\n').trim();

  // Remove all remaining <br>
  s = s.replace(/<br[^>]*>/gi, '');

  // If content is mostly plain text, split into paragraphs by blank lines
  if (!/<\s*(p|h2|ul|ol|li|blockquote|a)\b/i.test(s)) {
    const blocks = s.split(/\n{2,}/).map(x => x.trim()).filter(Boolean);
    s = blocks.map(p => (isHeadingCandidate(p) ? `<h2>${p}</h2>` : `<p>${p}</p>`)).join('\n');
  }

  // Convert markdown-like headings to <h2>
  s = s.replace(/^[\t ]*##[\t ]+(.+)$/gmi, '<h2>$1</h2>');

  // Convert bold-only or colon-ending paragraphs to <h2>
  s = s.replace(/<p>\s*(?:<strong>|<b>)?([^<]{3,120}?)(?:<\/strong>|<\/b>)?\s*:<\/p>/gi, '<h2>$1</h2>');
  // Convert short title-like paragraphs into <h2>
  s = s.replace(/<p>([\s\S]*?)<\/p>/gi, (full, inner) => {
    const t = stripTags(inner);
    return isHeadingCandidate(t) ? `<h2>${t}</h2>` : full;
  });

  // Convert bullet-like paragraphs to <ul><li>
  s = s.replace(/(?:\s*<p>\s*(?:[-*•·–—]|\d+[.)])\s+([^<]+?)\s*<\/p>\s*){2,}/gi, (full) => {
    const items = Array.from(full.matchAll(/<p>\s*(?:[-*•·–—]|\d+[.)])\s+([^<]+?)\s*<\/p>/gi)).map(m => `<li>${m[1].trim()}</li>`);
    return `<ul>${items.join('')}</ul>`;
  });

  // Remove empty bullet-only paragraphs and empty paragraphs
  s = s.replace(/<p>\s*[•·–—-]\s*<\/p>/gi, '');
  s = s.replace(/<p>\s*<\/p>/gi, '');

  // NOTE: do NOT strip anchors anymore; we keep the model-produced link intact

  // Deduplicate excessive newlines
  s = s.replace(/\n{3,}/g, '\n\n').trim();

  // Ensure at least one <h2>
  if (!/<h2[\s>]/i.test(s)) {
    const firstP = s.match(/<p[\s>][\s\S]*?<\/p>/i);
    if (firstP) {
      const text = stripTags(firstP[0]);
      if (isHeadingCandidate(text)) {
        s = s.replace(firstP[0], `<h2>${text}</h2>`);
      } else {
        s = `<h2>Основные моменты</h2>\n` + s;
      }
    } else {
      s = `<h2>Основные моменты</h2>`;
    }
  }

  // Simple validator (log only): ensure one anchor present and not only last paragraph
  try {
    const links = Array.from(s.matchAll(new RegExp(`<a\\s+[^>]*href=\\"${pageUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\"[^>]*>([\\s\\S]*?)<\\/a>`, 'gi')));
    const hasExactText = links.some(m => stripTags(m[1]) === anchorText);
    const paras = s.match(/<p[\s>][\s\S]*?<\/p>/gi) || [];
    const idx = links.length ? (paras.findIndex(p => p.includes(links[0][0])) ) : -1;
    const okHalf = idx >= 0 ? idx < Math.ceil(paras.length * 0.5) : false;
    if (!links.length || !hasExactText || !okHalf) {
      logLine('Anchor validation', { links: links.length, hasExactText, okHalf, note: 'Content not auto-fixed by design' });
    }
  } catch(_) {}

  return s;
}

function validateStructure(html, pageUrl, anchorText) {
  const reasons = [];
  const linkRe = new RegExp(`<a\\s+[^>]*href=\\"${pageUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\"[^>]*>([\\s\\S]*?)<\\/a>`, 'i');
  const linkMatches = Array.from(html.matchAll(linkRe));
  if (linkMatches.length !== 1) reasons.push('link_count');
  const text = linkMatches[0] ? stripTags(linkMatches[0][1]) : '';
  if (text !== anchorText) reasons.push('link_text');

  const paras = html.match(/<p[\s>][\s\S]*?<\/p>/gi) || [];
  const linkParaIndex = linkMatches.length ? paras.findIndex(p => linkMatches[0][0] && p.includes(linkMatches[0][0])) : -1;
  if (linkParaIndex < 0) reasons.push('link_not_in_paragraph');
  const firstHalfLimit = Math.max(1, Math.ceil(paras.length * 0.5));
  if (linkParaIndex >= firstHalfLimit) reasons.push('link_not_in_first_half');
  if (linkParaIndex >= 0) {
    const pPlain = stripTags(paras[linkParaIndex]);
    // Disallow trailing placement after the final period/question/exclamation
    if (/([.!?])\s*$/.test(pPlain)) reasons.push('link_at_end_of_paragraph');
    // Disallow adjacency to filler words
    if (/(подробнее|детальнее|здесь|по\s+ссылке)\s*[.!?]?$/.i.test(pPlain)) reasons.push('link_with_filler_words');
  }

  const h2Count = (html.match(/<h2[\s>]/gi) || []).length;
  if (h2Count < 3) reasons.push('h2_missing');

  // List checks: ensure <ul> exists when there are 2+ bullet-like items, and no blank gaps
  const hasUl = /<ul[\s>][\s\S]*?<\/ul>/i.test(html);
  const bulletParas = (html.match(/<p>\s*(?:[-*•·–—]|\d+[.)])\s+[^<]+?<\/p>/gi) || []).length;
  if (bulletParas >= 2 && !hasUl) reasons.push('list_not_wrapped');
  if (/<ul[\s>][\s\S]*?<\/ul>/i.test(html)) {
    const ul = html.match(/<ul[\s>][\s\S]*?<\/ul>/i)[0];
    if (/(<li[\s>][\s\S]*?<\/li>)\s*<p>/i.test(ul) || /<br\b/i.test(ul)) reasons.push('list_has_gaps');
  }

  return { ok: reasons.length === 0, reasons };
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey) {
  logLine('Publish start', { pageUrl, language });
  const meta = await extractPageMeta(pageUrl);

  const strictAnchorRule = `Use exactly ONE hyperlink with anchor text exactly: ${anchorText} and href exactly: ${pageUrl}. It must appear INSIDE a running sentence in the FIRST HALF of the article (not the second half), not as a standalone sentence, not at the very end of a paragraph, and not near filler words like «подробнее», «детальнее», «здесь». Do not use any other links.`;

  const formatRules = `Format rules:\n- Use <h2> subheadings (at least 3). Do not simulate headings with <p> or <br>.\n- Use <p> for paragraphs only. No empty <p>, no <br> for spacing.\n- If a list is needed, use one <ul> with adjacent <li> items (no blank lines or <p> between <li>).`;

  const exampleRU = `Example (fragment):\n<h2>Ключевые обновления платформы</h2>\n<p>Компания представила новое решение, в котором <a href="${pageUrl}">${anchorText}</a> раскрывает практическую пользу технологии для реальных сценариев.</p>`;

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
      `${formatRules}\n${strictAnchorRule}\n` +
      `Allowed HTML only: <p>, <h2>, <ul>, <li>, <a>, <strong>, <em>, <blockquote>. No images, scripts or inline styles.\n` +
      `${exampleRU}`
    )
  };

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const rawTitle = await generateTextWithAI(prompts.title, openaiApiKey);
  await sleep(800);
  const rawAuthor = await generateTextWithAI(prompts.author, openaiApiKey);
  await sleep(800);

  let attempts = 0;
  let content;
  while (attempts < 3) {
    const rawContent = await generateTextWithAI(prompts.content + (attempts ? `\n\nFix issues noted previously and regenerate.` : ''), openaiApiKey);
    const title = cleanTitle(rawTitle);
    const author = String(rawAuthor || '').split(/\r?\n/)[0].replace(/["'«»“”„]+/g, '').trim() || 'PromoPilot';

    // Keep normalization light to avoid moving anchor; only cleaning layout
    content = normalizeArticleHtml(rawContent, pageUrl, anchorText);
    const v = validateStructure(content, pageUrl, anchorText);
    if (v.ok) break;

    // Strengthen prompt with feedback
    const feedback = `Fix the following problems: ${v.reasons.join(', ')}. Preserve the required anchor <a href="${pageUrl}">${anchorText}</a> inside a sentence in the first half. Use at least three <h2>. Keep list compact.`;
    prompts.content += `\n\nCRITICAL FIX: ${feedback}`;
    attempts++;
  }

  const title = cleanTitle(rawTitle);
  const author = String(rawAuthor || '').split(/\r?\n/)[0].replace(/["'«»“”„]+/g, '').trim() || 'PromoPilot';

  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
  if (process.env.PUPPETEER_ARGS) {
    launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
  }
  const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
  const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
  if (execPath) launchOpts.executablePath = execPath;
  logLine('Launching browser', { executablePath: execPath || 'default' });

  const browser = await puppeteer.launch(launchOpts);
  let page;
  try {
    page = await browser.newPage();
    await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2', timeout: 120000 });

    await page.waitForSelector('h1[data-placeholder="Title"]', { timeout: 60000 });
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(title);

    await page.waitForSelector('address[data-placeholder="Your name"]', { timeout: 60000 });
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author);

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

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 120000 }),
      page.click('button.publish_button')
    ]);

    const publishedUrl = page.url();
    logLine('Published', { publishedUrl });

    return { ok: true, network: 'telegraph', publishedUrl, title, author, logFile: LOG_FILE };
  } catch (e) {
    logLine('Publish failed', { error: String(e && e.message || e) });
    throw e;
  } finally {
    try { if (page) await page.close(); } catch(_) {}
    try { await browser.close(); } catch(_) {}
  }
}

module.exports = { publish: publishToTelegraph };

// CLI entrypoint for PromoPilot runner (reads PP_JOB from env)
if (require.main === module) {
  (async () => {
    try {
      const raw = process.env.PP_JOB || '{}';
      const job = JSON.parse(raw);
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      if (!pageUrl || !apiKey) {
        const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url or openaiApiKey missing', network: 'telegraph', logFile: LOG_FILE };
        console.log(JSON.stringify(payload));
        process.exit(1);
      }
      const res = await publishToTelegraph(pageUrl, anchor, language, apiKey);
      console.log(JSON.stringify(res));
    } catch (e) {
      const payload = { ok: false, error: String(e && e.message || e), network: 'telegraph', logFile: LOG_FILE };
      console.log(JSON.stringify(payload));
      process.exit(1);
    }
  })();
}
