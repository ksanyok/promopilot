'use strict';

const { generateText, cleanLLMOutput } = require('../ai_client');

function analyzeLinks(html, url, anchor) {
  try {
    const str = String(html || '');
    const matches = Array.from(str.matchAll(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/ig));
    const totalLinks = matches.length;
    let ourLinkCount = 0;
    let externalCount = 0;
    let hasExactAnchor = false;
    const externalDomains = new Set();
    const normalize = (t) => String(t || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
    const expected = normalize(anchor);
    for (const match of matches) {
      const href = match[1];
      const inner = normalize(match[2]);
      if (href === url) {
        ourLinkCount++;
        if (expected && inner === expected) {
          hasExactAnchor = true;
        }
      } else {
        externalCount++;
        const dm = /^(?:https?:\/\/)?([^\/]*)/i.exec(href);
        if (dm && dm[1]) {
          externalDomains.add(dm[1].toLowerCase());
        }
      }
    }
    return {
      totalLinks,
      ourLinkCount,
      externalCount,
      hasExactAnchor,
      externalDomains: Array.from(externalDomains).slice(0, 4)
    };
  } catch (_) {
    return { totalLinks: 0, ourLinkCount: 0, externalCount: 0, hasExactAnchor: false, externalDomains: [] };
  }
}

async function generateArticle({ pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, meta, testMode }, logLine) {
  const pageMeta = meta || {};
  const pageLang = language || pageMeta.lang || 'ru';
  const topicTitle = (pageMeta.title || '').toString().trim();
  const topicDesc = (pageMeta.description || '').toString().trim();
  const region = (pageMeta.region || '').toString().trim();
  const extraNote = wish ? `\nNote (use if helpful): ${wish}` : '';
  const isTest = !!testMode;

  if (isTest) {
    const title = `PromoPilot: диагностика сетей`;
    const body = `<p>Это тестовая публикация PromoPilot для проверки сетей. Сервис пытается разместить диагностическую ссылку <a href="${pageUrl}">${anchorText}</a> и убедиться, что площадка принимает стандартный HTML-контент.</p>`;
    const stats = analyzeLinks(body, pageUrl, anchorText);
    if (typeof logLine === 'function') {
      logLine('Diagnostic article prepared', { links: stats, length: body.length });
    }
    return {
      title,
      htmlContent: body,
      language: pageLang,
      linkStats: stats,
    };
  }

  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const aiOpts = {
    provider,
    openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '',
    model: process.env.OPENAI_MODEL || undefined,
    temperature: 0.2
  };

  const prompts = {
    title: `На ${pageLang} сформулируй чёткий конкретный заголовок по теме: ${topicTitle || anchorText}${topicDesc ? ' — ' + topicDesc : ''}. Укажи фокус: ${anchorText}.\n` +
      `Требования: без кавычек и эмодзи, без упоминания URL, 6–12 слов. Ответь только заголовком.`,
    content:
      `Напиши статью на ${pageLang} (>=3000 знаков) по теме: ${topicTitle || anchorText}${topicDesc ? ' — ' + topicDesc : ''}.${region ? ' Регион: ' + region + '.' : ''}${extraNote}\n` +
      `Требования:\n` +
      `- Ровно три активные ссылки в статье (формат строго <a href="...">...</a>):\n` +
      `  1) Ссылка на наш URL с точным анкором "${anchorText}": <a href="${pageUrl}">${anchorText}</a> — естественно в первой половине текста.\n` +
      `  2) Вторая ссылка на наш же URL, но с другим органичным анкором (не "${anchorText}").\n` +
      `  3) Одна ссылка на авторитетный внешний источник (например, Wikipedia/энциклопедия/официальный сайт), релевантный теме; URL не должен быть битым/фиктивным; язык предпочтительно ${pageLang} (или en).\n` +
      `- Только простой HTML: <p> абзацы и <h2> подзаголовки. Без markdown и кода.\n` +
      `- 3–5 смысловых секций и короткое заключение.\n` +
      `- Кроме указанных трёх ссылок — никаких иных ссылок или URL.\n` +
      `Ответь только телом статьи.`,
  };

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const rawTitle = await generateText(prompts.title, { ...aiOpts, systemPrompt: 'Только финальный заголовок. Без кавычек и пояснений.', keepRaw: true });
  await sleep(500);
  const rawContent = await generateText(prompts.content, { ...aiOpts, systemPrompt: 'Только тело статьи в HTML (<p>, <h2>). Без markdown и пояснений.', keepRaw: true });

  const titleClean = cleanLLMOutput(rawTitle).replace(/^\s*["'«»]+|["'«»]+\s*$/g, '').trim();
  let content = cleanLLMOutput(rawContent);

  const wantOur = 2;
  const wantExternal = 1;
  const wantTotal = 3;
  let stat = analyzeLinks(content, pageUrl, anchorText);

  if (!(stat.ourLinkCount >= wantOur && stat.externalCount >= wantExternal && stat.totalLinks === wantTotal)) {
    const stricter = prompts.content + `\nСТРОГО: включи ровно ${wantTotal} ссылки: две на ${pageUrl} (одна с анкором "${anchorText}", вторая с другим анкором) и одну на внешний авторитетный источник. Другие ссылки не добавляй.`;
    const retry = await generateText(stricter, { ...aiOpts, systemPrompt: 'Соблюдай требования ссылок строго. Только HTML тело.', keepRaw: true });
    const retryClean = cleanLLMOutput(retry);
    const retryStat = analyzeLinks(retryClean, pageUrl, anchorText);
    if ((retryStat.ourLinkCount >= stat.ourLinkCount && retryStat.externalCount >= stat.externalCount) || retryStat.totalLinks === wantTotal) {
      content = retryClean;
      stat = retryStat;
    }
  }

  return {
    title: titleClean || topicTitle || anchorText,
    htmlContent: String(content || '').trim(),
    language: pageLang,
    linkStats: stat
  };
}

module.exports = { generateArticle, analyzeLinks };
