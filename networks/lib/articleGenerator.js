'use strict';

const { generateText, cleanLLMOutput } = require('../ai_client');
const { htmlToPlainText } = require('./contentFormats');
const { prepareTextSample } = require('./verification');

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

function hashString(str) {
  let hash = 0;
  const input = String(str || '');
  for (let i = 0; i < input.length; i++) {
    hash = (hash << 5) - hash + input.charCodeAt(i);
    hash |= 0; // Keep 32-bit
  }
  return hash;
}

const AUTHOR_CANDIDATES = {
  ru: ['Алексей Петров', 'Мария Смирнова', 'Иван Орлов', 'Ольга Кузнецова', 'Дмитрий Соколов', 'Елена Васильева'],
  en: ['Alex Parker', 'Taylor Morgan', 'Jordan Lee', 'Casey Bennett', 'Morgan Reed', 'Jamie Collins'],
  es: ['Lucía García', 'Diego Ramos', 'Elena Fernández', 'Mateo Álvarez'],
  fr: ['Camille Laurent', 'Julien Moreau', 'Sophie Bernard'],
  de: ['Lena Fischer', 'Jonas Becker', 'Mia Schneider'],
  generic: ['PromoPilot Contributor']
};

function pickAuthorName(language, seed) {
  const code = String(language || '').toLowerCase().split(/[-_]/)[0];
  const pool = AUTHOR_CANDIDATES[code] || AUTHOR_CANDIDATES.generic;
  if (!Array.isArray(pool) || pool.length === 0) {
    return 'PromoPilot Contributor';
  }
  const base = hashString(seed || '') % pool.length;
  const index = ((base % pool.length) + pool.length) % pool.length;
  return pool[index];
}

function buildDiagnosticArticle(pageUrl, anchorText) {
  const externalLink = 'https://ru.wikipedia.org/wiki/Веб-аналитика';
  const altAnchor = 'интерактивная панель PromoPilot';
  const sections = [
    `<h2>Введение</h2>
<p>Диагностика рекламных кампаний часто затягивается из-за разрозненных данных и несогласованных показателей. Ссылка <a href="${pageUrl}">${anchorText}</a> открывает компактную витрину со сводкой ключевых метрик, которые нужны маркетологу в первые минуты проверки.</p>
<p>PromoPilot собирает историю публикаций, частоту отклонений и оперативные подсказки по оптимизации. Это экономит время специалиста и позволяет сразу увидеть, где именно кампания теряет охваты или конверсии.</p>`,
    `<h2>Как устроена ссылка диагностики</h2>
<p>На странице доступны интерактивные графики, таблица с последними публикациями и рекомендации по каждой сети. Кнопка перехода на <a href="${pageUrl}">${altAnchor}</a> ведет к расширенной панели, где можно сравнить показатели за разные недели и отметить проблемные площадки.</p>
<p>Секция «Действия» показывает, какие шаги уже предприняла команда: повторный запуск, обновление креативов или запрос в службу поддержки сети. Это помогает не дублировать работу и удерживать контекст внутри одной ссылки.</p>`,
    `<h2>Мониторинг ошибок</h2>
<p>Через диагностику удобно отслеживать тайминги публикаций, коды ответов и частоту разрывов по капче. Для каждой сети указано, когда фиксировался последний сбой и какие параметры стоит перепроверить. Это особенно полезно для команд, работающих в нескольких часовых поясах.</p>
<p>Журнал событий формируется автоматически: наименование сети, шаг скрипта, краткое описание проблемы и подсказка, какие настройки изменить. Благодаря этому распределённая команда быстрее синхронизируется и исключает повторные простои.</p>`,
    `<h2>Интеграция с аналитикой</h2>
<p>PromoPilot собирает данные из рекламных кабинетов и CMS, чтобы аналитики видели причину падения трафика ещё до встречи. При необходимости можно открыть <a href="${externalLink}">обзор по веб-аналитике</a> и сравнить метрики с отраслевыми ориентирами.</p>
<p>Расширенный режим позволяет выгружать отчёт в CSV и делиться ссылкой с подрядчиками. Даже если они не имеют доступа к основному проекту, диагностическая страница поможет им понять контекст задачи.</p>`,
    `<h2>Выводы</h2>
<p>Когда команда использует единую ссылку диагностики, исчезают «слепые зоны»: статус каждой публикации прозрачен, а история шагов фиксируется автоматически. Это повышает скорость реакции на сбой и улучшает качество кампаний.</p>
<p>Сохраняйте ссылку в закладках и возвращайтесь к ней после каждой проверки. Она служит живым центром знаний по проекту и позволяет специалистам PromoPilot работать слаженно даже в условиях постоянных изменений.</p>`
  ];

  return {
    title: 'PromoPilot: диагностика сетей',
    author: 'Алексей Петров',
    html: sections.join('\n\n')
  };
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
    const preset = buildDiagnosticArticle(pageUrl, anchorText);
    const stats = analyzeLinks(preset.html, pageUrl, anchorText);
    if (typeof logLine === 'function') {
      logLine('Diagnostic article prepared', { links: stats, length: preset.html.length, author: preset.author });
    }
    return {
      title: preset.title,
      htmlContent: preset.html,
      language: pageLang,
      linkStats: stats,
      author: preset.author,
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

  const authorFromMeta = (pageMeta.author || '').toString().trim();
  const author = authorFromMeta || pickAuthorName(pageLang, `${pageUrl}|${anchorText}|${topicTitle}|${topicDesc}|${region}`);

  if (typeof logLine === 'function') {
    logLine('Article generated', {
      language: pageLang,
      author,
      length: String(content || '').length,
      links: stat
    });
  }

  const htmlContent = String(content || '').trim();
  const plainText = htmlToPlainText(htmlContent);
  const verificationSample = prepareTextSample([plainText]);

  return {
    title: titleClean || topicTitle || anchorText,
    htmlContent,
    language: pageLang,
    linkStats: stat,
    author,
    plainText,
    verificationSample,
  };
}

module.exports = { generateArticle, analyzeLinks };
