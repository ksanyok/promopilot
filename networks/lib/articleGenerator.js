'use strict';

const { generateText, cleanLLMOutput } = require('../ai_client');
const { htmlToPlainText } = require('./contentFormats');
const { prepareTextSample } = require('./verification');
const { generateImage } = require('./generateImage');

let lastGeneratedArticle = null;

function normalizeContextArray(value) {
  if (!Array.isArray(value)) return [];
  return value
    .map((item) => {
      if (!item || typeof item !== 'object') return null;
      const title = String(item.title || '').trim();
      const summary = String(item.summary || '').trim();
      const keywords = Array.isArray(item.keywords) ? item.keywords.filter(Boolean).map((kw) => String(kw).trim()).filter(Boolean).slice(0, 8) : [];
      const description = String(item.description || '').trim();
      const excerpt = String(item.excerpt || '').trim();
      if (!title && !summary && keywords.length === 0 && !description) {
        return null;
      }
      return {
        url: String(item.url || '').trim(),
        title,
        summary,
        description,
        keywords,
        language: String(item.language || '').trim(),
        headings: Array.isArray(item.headings) ? item.headings.filter(Boolean).map((h) => String(h).trim()).filter(Boolean).slice(0, 6) : [],
        excerpt,
      };
    })
    .filter(Boolean);
}

function buildCascadeGuidance(cascade, language) {
  if (!cascade || typeof cascade !== 'object') {
    return { intro: '', bullets: [], reminder: '', titleReminder: '', detailSnippets: [], focusKeywords: [] };
  }
  const isRu = String(language || '').toLowerCase().startsWith('ru');
  const intro = isRu ? 'Контекст предыдущих уровней:' : 'Context from previous levels:';
  const summaryLabel = isRu ? 'Суть' : 'Focus';
  const keywordsLabel = isRu ? 'Ключевые темы' : 'Key topics';
  const titleLabel = isRu ? 'Заголовок' : 'Title';
  const bullet = isRu ? '—' : '-';
  const reminder = isRu
    ? 'Опирайся на эти материалы: подчеркни связь с родительской статьёй, используй схожие темы и упомяни исходные выводы.'
    : 'Use these materials to stay aligned with the parent article, reinforce shared themes, and reference prior conclusions naturally.';
  const titleReminder = isRu
    ? 'Заголовок должен отражать связь с темой родительской статьи.'
    : 'Title should reflect the connection to the parent article topic.';

  const trail = normalizeContextArray(cascade.ancestorTrail);
  const parentContext = cascade.parentContext ? normalizeContextArray([cascade.parentContext]) : [];
  const contexts = trail.length ? trail : parentContext;
  const focusKeywordsMap = new Map();
  const addKeyword = (value) => {
    const trimmed = String(value || '').trim();
    if (!trimmed) return;
    const lower = trimmed.toLowerCase();
    if (!focusKeywordsMap.has(lower)) {
      focusKeywordsMap.set(lower, trimmed);
    }
  };
  const bullets = contexts.slice(0, 5).map((ctx) => {
    const parts = [];
    if (ctx.title) parts.push(`${titleLabel}: ${ctx.title}`);
    if (ctx.summary) parts.push(`${summaryLabel}: ${ctx.summary}`);
    if (!ctx.summary && ctx.description) parts.push(`${summaryLabel}: ${ctx.description}`);
    if (ctx.keywords && ctx.keywords.length) parts.push(`${keywordsLabel}: ${ctx.keywords.slice(0, 6).join(', ')}`);
    if (Array.isArray(ctx.keywords)) {
      ctx.keywords.forEach(addKeyword);
    }
    if (ctx.summary) {
      ctx.summary.split(/[,.]/).forEach((piece) => {
        piece.split(/\s+/).forEach((word) => {
          if (!word) return;
          const normalized = word.replace(/[^\p{L}\p{N}\-]+/gu, '');
          if (!normalized) return;
          if (normalized.length < 4) return;
          addKeyword(normalized);
        });
      });
    }
    if (ctx.headings && ctx.headings.length) {
      ctx.headings.forEach((heading) => {
        heading.split(/\s+/).forEach((word) => {
          const normalized = word.replace(/[^\p{L}\p{N}\-]+/gu, '');
          if (!normalized) return;
          if (normalized.length < 4) return;
          addKeyword(normalized);
        });
      });
    }
    if (ctx.excerpt && !ctx.summary) {
      const trimmed = ctx.excerpt.length > 220 ? `${ctx.excerpt.slice(0, 220)}…` : ctx.excerpt;
      parts.push(`${summaryLabel}: ${trimmed}`);
    }
    return `${bullet} ${parts.join('; ')}`;
  });

  const detailSnippets = contexts
    .map((ctx) => ctx.excerpt || ctx.summary || ctx.description)
    .filter(Boolean)
    .map((text) => {
      const flattened = String(text).replace(/\s+/g, ' ').trim();
      if (flattened.length > 320) {
        return `${flattened.slice(0, 320)}…`;
      }
      return flattened;
    })
    .slice(0, 3);

  const focusKeywords = Array.from(focusKeywordsMap.values()).slice(0, 8);

  return {
    intro: bullets.length ? intro : '',
    bullets,
    reminder: bullets.length ? reminder : '',
    titleReminder: bullets.length ? titleReminder : '',
    detailSnippets,
    focusKeywords,
  };
}

function cleanupGeneratedHtml(html) {
  let s = String(html || '');
  if (!s) {
    return '';
  }
  s = s.replace(/&nbsp;/gi, ' ');
  s = s.replace(/<br[^>]*>/gi, '');
  s = s.replace(/<p[^>]*>\s*<\/p>/gi, '');
  s = s.replace(/<blockquote[^>]*>\s*<\/blockquote>/gi, '');
  s = s.replace(/<p>\s*\$\s*<\/p>/gi, '');
  s = s.replace(/\s*\$+\s*$/g, '');
  return s.trim();
      const titlePromptLines = isRu
        ? [
            `На ${pageLang} сформулируй чёткий конкретный заголовок по теме: ${topicLine}.`,
            isHigherLevel
              ? `Передай связь с целевой страницей, но не повторяй дословно фразу "${anchorText}".`
              : `Укажи фокус: ${anchorText}.`,
            'Требования: без кавычек и эмодзи, без упоминания URL, 6–12 слов. Ответь только заголовком.'
          ]
        : [
            `Write a clear, specific headline in ${pageLang} for: ${topicLine}.`,
            isHigherLevel
              ? `Show the connection to the target page without repeating the exact phrase "${anchorText}".`
              : `Highlight the key phrase "${anchorText}".`,
            'Requirements: no quotes or emoji, no URLs, 6–12 words. Return only the headline.'
          ];
      let titlePrompt = titlePromptLines.join('\n');
      if (keywordReminder) {
        titlePrompt += `\n${keywordReminder}`;
      }
      if (cascadeInfo.titleReminder) {
        titlePrompt += `\n${cascadeInfo.titleReminder}`;
      }

      const prompts = {
        title: titlePrompt,
        content: contentPrompt,
      };
  const headingMatches = [...source.matchAll(/<h3[^>]*>([\s\S]*?)<\/h3>/gi)];
  headingMatches.forEach((match) => {
    const text = extractPlainText(match[1]);
    if (text) {
      listCandidates.push(text);
    }
  });
  if (listCandidates.length < 3) {
    const paragraphMatches = [...source.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/gi)];
    paragraphMatches.forEach((match) => {
      const sentences = extractPlainText(match[1])
        .split(/(?<=[.!?])\s+/)
        .map((item) => item.trim())
        .filter((item) => item && item.length > 30);
      listCandidates.push(...sentences);
    });
  }
  listCandidates = [...new Set(listCandidates)].filter(Boolean).slice(0, 4);
  if (listCandidates.length < 2) {
    listCandidates = isRu
      ? [
          'Главные премьеры сезона и их ключевые особенности',
          'Независимые проекты, о которых стоит рассказать аудитории',
          'Новые авторы и тенденции, формирующие рынок 2025 года',
        ]
      : [
          'Headline releases that will dominate the 2025 schedule',
          'Independent projects worth sharing with engaged audiences',
          'Emerging creators and trends shaping the 2025 market',
        ];
  }
  return listCandidates;
}

function buildFallbackListHtml(html, options = {}) {
  const items = buildFallbackListItems(html, options);
  return `<ul>${items.map((item) => `<li>${item}</li>`).join('')}</ul>`;
}

function injectFallbackList(html, { isRu } = {}) {
  const listHtml = buildFallbackListHtml(html, { isRu });
  if (/<h3/i.test(html)) {
    return html.replace(/<h3/i, `${listHtml}\n<h3`);
  }
  const firstParagraphEnd = html.indexOf('</p>');
  if (firstParagraphEnd !== -1) {
    return `${html.slice(0, firstParagraphEnd + 4)}\n${listHtml}\n${html.slice(firstParagraphEnd + 4)}`;
  }
  return `${html}\n${listHtml}`;
}

function injectFallbackQuote(html, { isRu, anchorText }) {
  const quoteText = isRu
    ? `«${anchorText}» — надежный ориентир для читателей, которые следят за ключевыми тенденциями 2025 года. Эксперты PromoPilot рекомендуют регулярно обращаться к аналитическим материалам, чтобы не упустить важные возможности.`
    : `"${anchorText}" is a trusted reference point for readers who follow the defining trends of 2025. PromoPilot analysts recommend revisiting the analytical coverage regularly to stay ahead of new opportunities.`;
  const quoteHtml = `<blockquote>${quoteText}</blockquote>`;
  const firstParagraphEnd = html.indexOf('</p>');
  if (firstParagraphEnd !== -1) {
    return `${html.slice(0, firstParagraphEnd + 4)}\n${quoteHtml}\n${html.slice(firstParagraphEnd + 4)}`;
  }
  return `${quoteHtml}\n${html}`;
}

function normalizeLengthHint(value) {
  const num = Number(value);
  if (!Number.isFinite(num)) {
    return null;
  }
  const rounded = Math.round(num);
  return rounded > 0 ? rounded : null;
}

function buildLevelPromptHints({ level, isRu, minLength, maxLength }) {
  const normalizedMin = normalizeLengthHint(minLength);
  const baseMin = normalizedMin ? Math.max(normalizedMin, 3000) : 3000;
  const defaultLengthSentence = isRu
    ? `Объем материала — не меньше ${baseMin} знаков (без учета HTML).`
    : `Length must be at least ${baseMin} characters (excluding HTML markup).`;
  const defaultRequirement = isRu
    ? `- Минимум ${baseMin} знаков (без учета HTML).`
    : `- Minimum ${baseMin} characters (excluding HTML).`;

  if (level === 1) {
    const min = Math.max(normalizedMin || 0, 5000);
    let max = normalizeLengthHint(maxLength);
    if (!max || max < min + 200) {
      max = Math.max(10000, min + 200);
    } else {
      max = Math.max(max, min + 200);
    }
    const lengthSentence = isRu
      ? `Целься в объем ${min}–${max} знаков (без учета HTML-разметки; оптимально 5000–10000).`
      : `Aim for ${min}-${max} characters (excluding HTML markup; ideally around 5000–10000).`;
    const requirementLine = isRu
      ? `- Объем ${min}–${max} знаков (без учета HTML).`
      : `- Length ${min}-${max} characters (excluding HTML).`;
    const extraRequirementLines = [
      isRu
        ? '- Добавь минимум один структурированный список (<ul>/<ol> с <li>).'
        : '- Include at least one structured list (<ul>/<ol> with <li> items).',
      isRu
        ? '- Используй хотя бы один блок <blockquote> с цитатой, статистикой или ключевой мыслью.'
        : '- Use at least one <blockquote> containing a quote, statistic, or key insight.',
      isRu
        ? '- В основных разделах приводи конкретные примеры, данные или наблюдения из практики.'
        : '- In the main sections, provide concrete examples, data, or field observations.',
    ];
    const toneLines = [
      isRu
        ? 'Стиль — экспертный и аналитический: объясняй причины, опирайся на исследования/данные и давай практические рекомендации.'
        : 'Tone — expert and analytical: explain underlying causes, reference research or data, and give actionable recommendations.',
      isRu
        ? 'Структурируй материал: введение, глубокие аналитические блоки, практические советы и убедительное заключение.'
        : 'Structure the piece with an introduction, deep analytical sections, practical guidance, and a persuasive conclusion.',
    ];
    return { lengthSentence, requirementLine, extraRequirementLines, toneLines };
  }

  if (level === 2 || level === 3) {
    const min = Math.max(normalizedMin || 0, 3000);
    let max = normalizeLengthHint(maxLength);
    if (!max) {
      max = 4000;
    }
    if (max < min + 200) {
      max = min + 200;
    }
    if (max > 4200) {
      max = 4200;
    }
    const lengthSentence = isRu
      ? `Целься в объем ${min}–${max} знаков (без учета HTML-разметки; оптимально около 3200–3800).`
      : `Aim for ${min}-${max} characters (excluding HTML markup; ideally around 3200-3800).`;
    const requirementLine = isRu
      ? `- Объем ${min}–${max} знаков (без учета HTML).`
      : `- Length ${min}-${max} characters (excluding HTML).`;
    const extraRequirementLines = [
      isRu
        ? '- Структура: краткое введение, 2–3 смысловых блока и финальный вывод.'
        : '- Structure: short intro, 2–3 focused sections, and a concise takeaway.',
      isRu
        ? '- Обязательно покажи связь с материалами предыдущего уровня и добавь конкретные детали из темы.'
        : '- Show the connection to the previous-level material and add concrete details from the topic.'
    ];
    const toneLines = [
      isRu
        ? 'Тон — практический и основанный на фактах: без воды, с конкретными наблюдениями и выводами.'
        : 'Tone — practical and fact-driven: avoid fluff, include concrete observations and conclusions.'
    ];
    return { lengthSentence, requirementLine, extraRequirementLines, toneLines };
  }

  return {
    lengthSentence: defaultLengthSentence,
    requirementLine: defaultRequirement,
    extraRequirementLines: [],
    toneLines: [],
  };
}

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

  const title = 'PromoPilot: диагностика сетей';
  const html = sections.join('\n\n');

  return {
    title,
    author: 'Алексей Петров',
    html
  };
}

async function generateArticle({
  pageUrl,
  anchorText,
  language,
  openaiApiKey,
  aiProvider,
  wish,
  meta,
  testMode,
  cascade,
  article: articleConfig,
  disableImages
}, logLine) {
  const pageMeta = meta || {};
  const pageLang = language || pageMeta.lang || 'ru';
  const topicTitle = (pageMeta.title || '').toString().trim();
  const topicDesc = (pageMeta.description || '').toString().trim();
  const region = (pageMeta.region || '').toString().trim();
  const articleHints = (articleConfig && typeof articleConfig === 'object') ? articleConfig : {};
  const isRu = String(pageLang || '').toLowerCase().startsWith('ru');
  const wishLine = wish
    ? (isRu ? `Учитывай пожелание клиента: ${wish}.` : `Consider this client note: ${wish}.`)
    : '';
  const cascadeSource = (cascade && typeof cascade === 'object') ? cascade : {};
  const cascadeNormalized = {
    level: cascadeSource.level !== undefined ? cascadeSource.level : (articleHints.level ?? null),
    parentUrl: cascadeSource.parentUrl !== undefined ? cascadeSource.parentUrl : (articleHints.parentUrl || null),
    parentContext: cascadeSource.parentContext !== undefined ? cascadeSource.parentContext : (articleHints.parentContext || null),
    ancestorTrail: Array.isArray(cascadeSource.ancestorTrail)
      ? cascadeSource.ancestorTrail
      : (Array.isArray(articleHints.ancestorTrail) ? articleHints.ancestorTrail : []),
  };
  const cascadeInfo = buildCascadeGuidance(cascadeNormalized, pageLang);
  const insightLabel = isRu ? 'Ключевые идеи родительской статьи' : 'Key ideas from the parent article';
  const insightBullet = isRu ? '•' : '-';
  const insightBlock = Array.isArray(cascadeInfo.detailSnippets) && cascadeInfo.detailSnippets.length
    ? `${insightLabel}:\n${cascadeInfo.detailSnippets.map((line) => `${insightBullet} ${line}`).join('\n')}\n`
    : '';
  const keywordReminder = Array.isArray(cascadeInfo.focusKeywords) && cascadeInfo.focusKeywords.length
    ? (isRu
        ? `Сфокусируйся на темах: ${cascadeInfo.focusKeywords.join(', ')}. Упомяни каждую из них конкретно.`
        : `Center the article around: ${cascadeInfo.focusKeywords.join(', ')}. Mention each of these topics explicitly.`)
    : '';
  const avoidGeneric = isRu
    ? 'Не используй шаблонные фразы вроде «разбор кейса», «быстрый обзор», «пошаговый гайд» без конкретики. Излагай факты и контекст из исходной темы.'
    : 'Avoid generic phrases like “case study breakdown”, “quick overview”, or “step-by-step guide” without concrete detail. Ground every section in the original topic context.';
  const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const aiOpts = {
    provider,
    openaiApiKey: openaiApiKey || process.env.OPENAI_API_KEY || '',
    model: process.env.OPENAI_MODEL || undefined,
    temperature: 0.2,
  };
  const toInt = (value) => {
    const num = Number(value);
    return Number.isFinite(num) ? num : null;
  };
  const inferredLevel = toInt(articleHints.level) ?? toInt(cascadeNormalized.level);
  const lengthHints = buildLevelPromptHints({
    level: inferredLevel,
    isRu,
    minLength: articleHints.minLength,
    maxLength: articleHints.maxLength,
  });
  const levelNumber = Number.isFinite(inferredLevel) ? inferredLevel : 1;
  const isHigherLevel = levelNumber >= 2;
  const anchorUsageInstruction = isHigherLevel
    ? (isRu
        ? `Анкоры ссылок подбирай из смысловых фраз текста (2–5 слов), не повторяй подзаголовки и не используй точное выражение "${anchorText}".`
        : `Choose 2–5 word anchor phrases that grow naturally from the sentences; do not reuse headings or the exact phrase "${anchorText}".`)
    : '';
  const isTest = !!testMode;

  if (isTest) {
    const preset = buildDiagnosticArticle(pageUrl, anchorText);
    const stats = analyzeLinks(preset.html, pageUrl, anchorText);
    if (typeof logLine === 'function') {
      logLine('Diagnostic article prepared', { links: stats, length: preset.html.length, author: preset.author });
    }
    let generatedArticle = {
      title: preset.title,
      htmlContent: preset.html,
      language: pageLang,
      linkStats: stats,
      author: preset.author,
    };

    // Add H1 and image for test
    generatedArticle.htmlContent = `<h1>${generatedArticle.title}</h1>\n${generatedArticle.htmlContent}`;

    // For test, add fake image
    const firstPIndex = generatedArticle.htmlContent.indexOf('<p>');
    if (firstPIndex !== -1) {
      const insertPos = generatedArticle.htmlContent.indexOf('</p>', firstPIndex) + 4;
      const imageMarkdown = `![Image description](https://i.snap.as/D1yn3zC.png)\n\n`;
      generatedArticle.htmlContent = generatedArticle.htmlContent.slice(0, insertPos) + imageMarkdown + generatedArticle.htmlContent.slice(insertPos);
    }

    lastGeneratedArticle = { ...generatedArticle };
    return generatedArticle;
  }

  const baseTopic = topicTitle || anchorText;
  const topicLine = topicDesc ? `${baseTopic} — ${topicDesc}` : baseTopic;
  const contentLines = [];
  if (isRu) {
    contentLines.push(`Напиши статью на ${pageLang} по теме: ${topicLine}.`);
  } else {
    contentLines.push(`Write an article in ${pageLang} about: ${topicLine}.`);
  }
  if (region) {
    contentLines.push(isRu ? `Регион публикации: ${region}.` : `Geographic focus: ${region}.`);
  }
  if (wishLine) {
    contentLines.push(wishLine);
  }
  if (lengthHints.lengthSentence) {
    contentLines.push(lengthHints.lengthSentence);
  }
  if (cascadeInfo.intro) {
    contentLines.push(cascadeInfo.intro);
    if (cascadeInfo.bullets && cascadeInfo.bullets.length) {
      contentLines.push(cascadeInfo.bullets.join('\n'));
    }
  }
  if (insightBlock) {
    contentLines.push(insightBlock.trim());
  }
  if (cascadeInfo.reminder) {
    contentLines.push(cascadeInfo.reminder);
  }
  if (keywordReminder) {
    contentLines.push(keywordReminder);
  }
  if (Array.isArray(lengthHints.toneLines) && lengthHints.toneLines.length) {
    lengthHints.toneLines.forEach((line) => {
      if (line) {
        contentLines.push(line);
      }
    });
  }
  if (anchorUsageInstruction) {
    contentLines.push(anchorUsageInstruction);
  }
  contentLines.push(avoidGeneric);

  const requirementLines = [];
  if (lengthHints.requirementLine) {
    requirementLines.push(lengthHints.requirementLine);
  }
  if (isHigherLevel) {
    requirementLines.push(
      isRu
        ? '- Всего три активные ссылки (формат <a href="...">...</a>) и ни одной лишней.'
        : '- Use exactly three active links (format <a href="...">...</a>) and nothing else.'
    );
    requirementLines.push(
      isRu
        ? `  • Две ссылки ведут на ${pageUrl} с разными органичными анкорами (2–5 слов), отличными от подзаголовков и без фразы "${anchorText}".`
        : `  • Two links must point to ${pageUrl} with distinct natural 2–5 word anchors that differ from headings and avoid the exact phrase "${anchorText}".`
    );
    requirementLines.push(
      isRu
        ? '  • Первую ссылку размести в первой половине статьи, вторую — ближе к заключению; обе внутри абзацев, а не списков или заголовков.'
        : '  • Place the first internal link in the first half of the article and the second near the conclusion; both must live inside paragraphs, not lists or headings.'
    );
    requirementLines.push(
      isRu
        ? `  • Третья ссылка — на авторитетный внешний источник (Wikipedia, официальные отчёты, отраслевые исследования) на языке ${pageLang} или en; URL действительный.`
        : `  • The third link points to a reputable external source (Wikipedia, official reports, industry research) in ${pageLang} or English with a valid URL.`
    );
  } else {
    requirementLines.push(
      isRu
        ? '- Ровно три активные ссылки в статье (формат строго <a href="...">...</a>):'
        : '- Exactly three active links in the article (format <a href="...">...</a>):'
    );
    requirementLines.push(
      isRu
        ? `  1) Ссылка на наш URL с точным анкором "${anchorText}": <a href="${pageUrl}">${anchorText}</a> — естественно в первой половине текста.`
        : `  1) Link to our URL with the exact anchor "${anchorText}": <a href="${pageUrl}">${anchorText}</a>, placed naturally in the first half of the article.`
    );
    requirementLines.push(
      isRu
        ? `  2) Вторая ссылка на наш же URL, но с другим органичным анкором (не "${anchorText}").`
        : `  2) A second link to the same URL with a different organic anchor (not "${anchorText}").`
    );
    requirementLines.push(
      isRu
        ? `  3) Одна ссылка на авторитетный внешний источник (например, Wikipedia/энциклопедия/официальный сайт), релевантный теме; URL не должен быть битым/фиктивным; язык предпочтительно ${pageLang} (или en).`
        : `  3) One link to a reputable external source (e.g., Wikipedia, encyclopedias, official site) relevant to the topic; the URL must be valid and ideally in ${pageLang} or English.`
    );
  }
  if (Array.isArray(lengthHints.extraRequirementLines) && lengthHints.extraRequirementLines.length) {
    requirementLines.push(...lengthHints.extraRequirementLines);
  }
  requirementLines.push(
    isRu
      ? '- Используй только простой HTML: абзацы <p>, подзаголовки <h3>, списки (<ul>/<ol>) и цитаты <blockquote>. Без markdown и кода.'
      : '- Use only basic HTML: paragraphs <p>, subheadings <h3>, lists (<ul>/<ol>), and blockquotes <blockquote>. No markdown or code.',
    isRu
      ? '- 3–5 смысловых секций и короткое заключение.'
      : '- Provide 3–5 meaningful sections and a short conclusion.',
    isRu
      ? '- Кроме указанных трёх ссылок — никаких иных ссылок или URL.'
      : '- Do not add any links beyond the three specified above.'
  );
  const requirementsHeading = isRu ? 'Требования:' : 'Requirements:';
  const returnBodyLine = isRu ? 'Ответь только телом статьи.' : 'Return only the article body.';
  const requirementsBlock = [requirementsHeading, ...requirementLines, returnBodyLine].join('\n');
  contentLines.push(requirementsBlock);

  const contentPrompt = contentLines.filter(Boolean).join('\n');

  const prompts = {
    title:
      `На ${pageLang} сформулируй чёткий конкретный заголовок по теме: ${topicLine}. Укажи фокус: ${anchorText}.\n` +
      `Требования: без кавычек и эмодзи, без упоминания URL, 6–12 слов. Ответь только заголовком.` +
      (keywordReminder ? `\n${keywordReminder}` : '') +
      (cascadeInfo.titleReminder ? `\n${cascadeInfo.titleReminder}` : ''),
    content: contentPrompt,
  };

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const rawTitle = await generateText(prompts.title, { ...aiOpts, systemPrompt: 'Только финальный заголовок. Без кавычек и пояснений.', keepRaw: true });
  await sleep(500);
  const rawContent = await generateText(prompts.content, { ...aiOpts, systemPrompt: 'Только тело статьи в HTML (<p>, <h3>, <ul>/<ol>, <blockquote>). Без markdown и пояснений.', keepRaw: true });

  const titleClean = cleanLLMOutput(rawTitle).replace(/^\s*["'«»]+|["'«»]+\s*$/g, '').trim();
  let content = cleanLLMOutput(rawContent);

  // Add H1 title
  content = `<h1>${titleClean}</h1>\n${content}`;

  let imageUrl = null;
  if (!disableImages) {
    // Generate image prompt
    const imagePromptText = `Вот моя статья: ${htmlToPlainText(content)}\n\nСоставь промт для генерации изображения по теме этой статьи. Промт должен быть на английском языке, детальным и подходящим для Stable Diffusion. Обязательно подчеркни, что это реалистичная профессиональная фотография в формате 16:9 без текста, логотипов и водяных знаков, с натуральным светом и живой композицией. Ответь только промтом.`;
    const imagePrompt = await generateText(imagePromptText, { ...aiOpts, systemPrompt: 'Только промт для изображения.', keepRaw: true });
    await sleep(500);
    const cleanImagePrompt = cleanLLMOutput(imagePrompt).trim();
    let finalImagePrompt = cleanImagePrompt;

    if (finalImagePrompt) {
      finalImagePrompt = finalImagePrompt.replace(/\b(illustration|cartoon|drawing|render|digital painting|concept art)\b/gi, 'photograph');
      const enforcedPhrases = [
        'photorealistic',
        'professional photograph',
        'natural lighting',
        'ultra-detailed',
        '16:9 aspect ratio',
        'no text',
        'no watermark',
        'no logo',
        'no captions'
      ];
      enforcedPhrases.forEach((phrase) => {
        const phraseRegex = new RegExp(phrase.replace(/\s+/g, '\\s+'), 'i');
        if (!phraseRegex.test(finalImagePrompt)) {
          finalImagePrompt += `, ${phrase}`;
        }
      });
    }

    // Generate image
    try {
      if (finalImagePrompt) {
        imageUrl = await generateImage(finalImagePrompt);
      }
    } catch (e) {
      // Ignore image generation errors for now
    }
  }

  // Insert image after first paragraph
  if (imageUrl) {
    const firstPIndex = content.indexOf('<p>');
    if (firstPIndex !== -1) {
      const insertPos = content.indexOf('</p>', firstPIndex) + 4;
      const imageHtml = `<figure><img src="${imageUrl}" alt="Article illustration" loading="lazy" /></figure>\n`;
      content = content.slice(0, insertPos) + '\n' + imageHtml + content.slice(insertPos);
    }
  }

  content = content.replace(/<h2/gi, '<h3').replace(/<\/h2>/gi, '</h3>');
  content = cleanupGeneratedHtml(content);

  const enforceStructure = async () => {
    const maxAttempts = 2;
    let attempt = 0;
    while (attempt < maxAttempts) {
      const hasList = /<(ul|ol)\b/i.test(content);
      const hasQuote = /<blockquote\b/i.test(content);
      if (hasList && hasQuote) {
        break;
      }
      const requirements = [];
      if (!hasList) {
        requirements.push(isRu
          ? '- Добавь структурированный список (<ul>/<ol> с <li>), который подытоживает ключевые выводы.'
          : '- Add a structured list (<ul>/<ol> with <li>) that summarizes the key takeaways.');
        content = cleanupGeneratedHtml(injectFallbackList(content, { isRu }));
      }
      if (!hasQuote) {
        requirements.push(isRu
          ? '- Вставь содержательный блок <blockquote> с аналитической мыслью или фактом по теме.'
          : '- Insert an informative <blockquote> that highlights an analytical insight or fact.');
      }
      const adjustPrompt =
        `Отредактируй HTML статьи, сохранив структуру, заголовок <h1>, все подзаголовки <h3> и ссылки.\n` +

        `${requirements.join('\n')}\n` +
        (isRu
          ? 'Не добавляй новые URL, не удаляй существующие <a href> и их текст. Можно расширить существующие абзацы.'
          : 'Do not add new URLs, keep existing <a href> blocks and their text intact. You may expand existing paragraphs.') +
        `\nВерни только готовый HTML.\n\nСТАТЬЯ:\n${content}`;
      try {
        await sleep(300);
        const adjusted = await generateText(adjustPrompt, { ...aiOpts, systemPrompt: 'Верни только HTML статьи.', keepRaw: true });
        const adjustedClean = cleanupGeneratedHtml(cleanLLMOutput(adjusted).replace(/<h2/gi, '<h3').replace(/<\/h2>/gi, '</h3>'));
        if (adjustedClean) {
          content = adjustedClean;
        }
      } catch (_) {
        // ignore
      }
      attempt++;
    }
  };
  const ensureStructureWithFallback = async () => {
    content = content.replace(/<h2/gi, '<h3').replace(/<\/h2>/gi, '</h3>');
    content = cleanupGeneratedHtml(content);
    await enforceStructure();
    if (!/<blockquote\b/i.test(content)) {
      content = cleanupGeneratedHtml(injectFallbackQuote(content, { isRu, anchorText }));
    } else {
      content = cleanupGeneratedHtml(content);
    }
    if (!/<(ul|ol)\b/i.test(content)) {
      content = cleanupGeneratedHtml(injectFallbackList(content, { isRu }));
    }
    if (!/<blockquote\b/i.test(content)) {
      content = cleanupGeneratedHtml(injectFallbackQuote(content, { isRu, anchorText }));
    }
    if (!/<(ul|ol)\b/i.test(content)) {
      content = cleanupGeneratedHtml(injectFallbackList(content, { isRu }));
    }
    content = cleanupGeneratedHtml(content);
  };
  await ensureStructureWithFallback();


  const ensureKeyTakeawaysList = () => {
    const keyRegex = /<h3[^>]*>([\s\S]*?key\s*takeaways[\s\S]*?)<\/h3>/i;
    const match = keyRegex.exec(content);
    if (!match) {
      return;
    }
    const headingStart = match.index;
    const headingEnd = headingStart + match[0].length;
    const rest = content.slice(headingEnd);
    const nextHeadingMatch = rest.match(/<h[1-3][^>]*>/i);
    const sectionEnd = nextHeadingMatch ? headingEnd + nextHeadingMatch.index : content.length;
    const sectionContent = content.slice(headingEnd, sectionEnd);
    if (/<(ul|ol)\b/i.test(sectionContent) && /<li\b/i.test(sectionContent)) {
      return;
    }
    const listHtml = buildFallbackListHtml(content, { isRu });
    const cleanedSection = sectionContent.replace(/^(?:\s|<p[^>]*>(?:\s|&nbsp;|<br[^>]*>)*<\/p>)+/gi, '');
    content = `${content.slice(0, headingEnd)}\n${listHtml}\n${cleanedSection}${content.slice(sectionEnd)}`;
    content = cleanupGeneratedHtml(content);
  };
  const wantOur = 2;
  const wantExternal = 1;
  ensureKeyTakeawaysList();
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
  await ensureStructureWithFallback();
  ensureKeyTakeawaysList();

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

  const generatedArticle = {
    title: titleClean || topicTitle || anchorText,
    htmlContent,
    language: pageLang,
    linkStats: stat,
    author,
    plainText,
    verificationSample,
  };
  lastGeneratedArticle = { ...generatedArticle };
  return generatedArticle;
}

function getLastGeneratedArticle() {
  if (!lastGeneratedArticle || typeof lastGeneratedArticle !== 'object') {
    return null;
  }
  return { ...lastGeneratedArticle };
}

function attachArticleToResult(result, job = {}) {
  if (!result || typeof result !== 'object') {
    return result;
  }
  const hasArticle = result.article && typeof result.article === 'object' && result.article.htmlContent;
  let article = hasArticle ? result.article : null;
  if (!article || !article.htmlContent) {
    const prepared = job && job.preparedArticle && job.preparedArticle.htmlContent ? job.preparedArticle : null;
    const fallback = getLastGeneratedArticle();
    article = prepared || fallback;
  }
  if (article && article.htmlContent && (!result.article || result.article !== article)) {
    const enriched = { ...article };
    if (!enriched.language) {
      const jobLang = job && (job.language || (job.article && job.article.language) || (job.page_meta && job.page_meta.lang));
      if (jobLang) {
        enriched.language = jobLang;
      }
    }
    result.article = enriched;
  }
  if (job && job.article && typeof job.article === 'object' && !result.articleMeta) {
    result.articleMeta = { ...job.article };
  }
  return result;
}

module.exports = { generateArticle, analyzeLinks, getLastGeneratedArticle, attachArticleToResult };
