'use strict';

// Ultra-minimal Telegraph publisher: generate title, author, and content via ai_client.js and publish.
// No language mapping, no microdata fetching, no HTML post-processing.

const puppeteer = require('puppeteer');
const { generateText } = require('./ai_client');

async function generateTextWithChat(prompt, opts) {
  // Thin wrapper over our unified AI client
  return generateText(String(prompt || ''), opts || {});
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish) {
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const aiOpts = {
    provider,
    openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '',
    model: process.env.OPENAI_MODEL || undefined
  };

  const extraNote = wish ? `\nNote (use if helpful): ${wish}` : '';

  const prompts = {
    title: `Write a clear, specific article title in ${language} about: ${pageUrl}. No quotes.`,
    author: `Suggest a neutral author's name in ${language}. One or two words. Reply with the name only.`,
    content:
      `Write an article in ${language} (>=3000 characters) based on ${pageUrl}.${extraNote}\n` +
      `Requirements:\n` +
      `- Integrate exactly one active link with the anchor text "${anchorText}" as <a href="${pageUrl}">${anchorText}</a> naturally in the first half of the article.\n` +
      `- Use simple HTML only: <p> for paragraphs and <h2> for subheadings. No markdown, no code blocks.\n` +
      `- Keep it informative and readable with 3–5 sections and a short conclusion.\n` +
      `- Do not include any other links.`
  };

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  const rawTitle = await generateTextWithChat(prompts.title, aiOpts);
  await sleep(1000);
  const rawAuthor = await generateTextWithChat(prompts.author, aiOpts);
  await sleep(1000);
  const content = await generateTextWithChat(prompts.content, aiOpts);

  const title = String(rawTitle || '').replace(/["']/g, '').trim() || 'Untitled';
  const author = String(rawAuthor || '').trim() || 'PromoPilot';

  // Launch browser and publish
  const launchOpts = { headless: true };
  const browser = await puppeteer.launch(launchOpts);
  const page = await browser.newPage();
  await page.goto('https://telegra.ph/', { waitUntil: 'networkidle2' });

  await page.waitForSelector('h1[data-placeholder="Title"]');
  await page.click('h1[data-placeholder="Title"]');
  await page.keyboard.type(title);

  await page.waitForSelector('address[data-placeholder="Your name"]');
  await page.click('address[data-placeholder="Your name"]');
  await page.keyboard.type(author);

  await page.evaluate((html) => {
    const el = document.querySelector('p[data-placeholder="Your story..."]');
    if (el) el.innerHTML = html;
  }, content);

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('button.publish_button')
  ]);

  const publishedUrl = page.url();
  await browser.close();
  return { ok: true, network: 'telegraph', publishedUrl, title, author };
}

module.exports = { publish: publishToTelegraph };

// CLI entrypoint for PromoPilot runner (reads PP_JOB from env)
if (require.main === module) {
  (async () => {
    try {
      const job = JSON.parse(process.env.PP_JOB || '{}');
      const pageUrl = job.url || job.pageUrl || '';
      const anchor = job.anchor || pageUrl;
      const language = job.language || 'ru';
      const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
      const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
      const wish = job.wish || '';
      const model = job.openaiModel || process.env.OPENAI_MODEL || '';
      if (model) process.env.OPENAI_MODEL = String(model);

      if (!pageUrl) {
        console.log(JSON.stringify({ ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'telegraph' }));
        process.exit(1);
      }
      if (provider === 'openai' && !apiKey) {
        console.log(JSON.stringify({ ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'telegraph' }));
        process.exit(1);
      }

      const res = await publishToTelegraph(pageUrl, anchor, language, apiKey, provider, wish);
      console.log(JSON.stringify(res));
      process.exit(0);
    } catch (e) {
      console.log(JSON.stringify({ ok: false, error: String(e && e.message || e), network: 'telegraph' }));
      process.exit(1);
    }
  })();
}