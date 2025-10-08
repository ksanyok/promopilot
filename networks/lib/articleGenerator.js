'use strict';

const { generateText, cleanLLMOutput } = require('../ai_client');
const { htmlToPlainText } = require('./contentFormats');
const { prepareTextSample } = require('./verification');
const { generateImage } = require('./generateImage');

let lastGeneratedArticle = null;

const LANGUAGE_LABELS = {
  ru: { nameEn: 'Russian', nameRuIn: 'русском языке' },
  uk: { nameEn: 'Ukrainian', nameRuIn: 'украинском языке' },
  ua: { nameEn: 'Ukrainian', nameRuIn: 'украинском языке' },
  en: { nameEn: 'English', nameRuIn: 'английском языке' },
  es: { nameEn: 'Spanish', nameRuIn: 'испанском языке' },
  de: { nameEn: 'German', nameRuIn: 'немецком языке' },
  fr: { nameEn: 'French', nameRuIn: 'французском языке' },
  it: { nameEn: 'Italian', nameRuIn: 'итальянском языке' },
  pt: { nameEn: 'Portuguese', nameRuIn: 'португальском языке' },
  pl: { nameEn: 'Polish', nameRuIn: 'польском языке' },
  tr: { nameEn: 'Turkish', nameRuIn: 'турецком языке' },
  cs: { nameEn: 'Czech', nameRuIn: 'чешском языке' },
  sk: { nameEn: 'Slovak', nameRuIn: 'словацком языке' },
  sl: { nameEn: 'Slovenian', nameRuIn: 'словенском языке' },
  hr: { nameEn: 'Croatian', nameRuIn: 'хорватском языке' },
  sr: { nameEn: 'Serbian', nameRuIn: 'сербском языке' },
  ro: { nameEn: 'Romanian', nameRuIn: 'румынском языке' },
  hu: { nameEn: 'Hungarian', nameRuIn: 'венгерском языке' },
  bg: { nameEn: 'Bulgarian', nameRuIn: 'болгарском языке' },
  el: { nameEn: 'Greek', nameRuIn: 'греческом языке' },
  nl: { nameEn: 'Dutch', nameRuIn: 'нидерландском языке' },
  sv: { nameEn: 'Swedish', nameRuIn: 'шведском языке' },
  fi: { nameEn: 'Finnish', nameRuIn: 'финском языке' },
  da: { nameEn: 'Danish', nameRuIn: 'датском языке' },
  no: { nameEn: 'Norwegian', nameRuIn: 'норвежском языке' },
  et: { nameEn: 'Estonian', nameRuIn: 'эстонском языке' },
  lv: { nameEn: 'Latvian', nameRuIn: 'латышском языке' },
  lt: { nameEn: 'Lithuanian', nameRuIn: 'литовском языке' },
  be: { nameEn: 'Belarusian', nameRuIn: 'белорусском языке' },
  kk: { nameEn: 'Kazakh', nameRuIn: 'казахском языке' },
  uz: { nameEn: 'Uzbek', nameRuIn: 'узбекском языке' },
  az: { nameEn: 'Azerbaijani', nameRuIn: 'азербайджанском языке' },
  ka: { nameEn: 'Georgian', nameRuIn: 'грузинском языке' },
  hy: { nameEn: 'Armenian', nameRuIn: 'армянском языке' },
  zh: { nameEn: 'Chinese', nameRuIn: 'китайском языке' },
  ja: { nameEn: 'Japanese', nameRuIn: 'японском языке' },
  ko: { nameEn: 'Korean', nameRuIn: 'корейском языке' },
  th: { nameEn: 'Thai', nameRuIn: 'тайском языке' },
  vi: { nameEn: 'Vietnamese', nameRuIn: 'вьетнамском языке' },
  id: { nameEn: 'Indonesian', nameRuIn: 'индонезийском языке' },
  ms: { nameEn: 'Malay', nameRuIn: 'малайском языке' },
  hi: { nameEn: 'Hindi', nameRuIn: 'языке хинди' },
  bn: { nameEn: 'Bengali', nameRuIn: 'бенгальском языке' },
  ur: { nameEn: 'Urdu', nameRuIn: 'языке урду' },
  fa: { nameEn: 'Persian', nameRuIn: 'персидском языке' },
  ar: { nameEn: 'Arabic', nameRuIn: 'арабском языке' },
  he: { nameEn: 'Hebrew', nameRuIn: 'ивритском языке' }
};

function getLanguageMeta(language) {
  const raw = (language || '').toString().trim();
  if (!raw) {
    return {
      code: '',
      nameEn: 'the page language',
      nameEnWithCode: 'the page language',
      nameRuIn: 'языке страницы',
    };
  }
  const lower = raw.toLowerCase();
  const base = lower.split(/[-_]/)[0] || lower;
  const preset = LANGUAGE_LABELS[base] || null;
  if (preset) {
    return {
      code: base,
      nameEn: preset.nameEn,
      nameEnWithCode: preset.nameEnWithCode || `${preset.nameEn} (${base})`,
      nameRuIn: preset.nameRuIn,
    };
  }
  const codeLabel = base || raw.toLowerCase();
  const fallbackEn = codeLabel ? `the page language (${codeLabel})` : 'the page language';
  return {
    code: codeLabel,
    nameEn: fallbackEn,
    nameEnWithCode: fallbackEn,
    nameRuIn: 'языке страницы',
  };
}

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
}

function extractPlainText(html) {
  return String(html || '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function buildFallbackListItems(source, options = {}) {
  const { isRu = false } = options;
  const raw = String(source || '');
  const listCandidates = [];

  const headingMatches = [...raw.matchAll(/<h3[^>]*>([\s\S]*?)<\/h3>/gi)];
  headingMatches.forEach((match) => {
    const text = extractPlainText(match[1]);
    if (text) {
      listCandidates.push(text);
    }
  });

  if (listCandidates.length < 3) {
    const paragraphMatches = [...raw.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/gi)];
    paragraphMatches.forEach((match) => {
      const sentences = extractPlainText(match[1])
        .split(/(?<=[.!?])\s+/)
        .map((item) => item.trim())
        .filter((item) => item && item.length > 30);
      listCandidates.push(...sentences);
    });
  }

  let items = [...new Set(listCandidates)].filter(Boolean).slice(0, 4);

  if (items.length < 2) {
    items = isRu
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

  return items;
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


function buildTitlePrompt({
  isRu,
  languageMeta,
  topicLine,
  anchorText,
  isHigherLevel,
  keywordReminder,
  cascadeInfo,
}) {
  const lines = isRu
    ? [
        `На ${languageMeta.nameRuIn} сформулируй чёткий конкретный заголовок по теме: ${topicLine}.`,
        isHigherLevel
          ? 'Передай связь с целевой страницей и подчеркни основную выгоду, не цитируя точные формулировки анкоров.'
          : `Укажи фокус: ${anchorText}.`,
        `Заголовок держи на ${languageMeta.nameRuIn}; без кавычек и эмодзи, без упоминания URL, 6–12 слов. Ответь только заголовком.`,
      ]
    : [
        `Write a clear, specific headline in ${languageMeta.nameEn} for: ${topicLine}.`,
        isHigherLevel
          ? 'Highlight the connection to the target page and reinforce the key benefit without quoting any anchor phrases.'
          : `Emphasize the key phrase "${anchorText}".`,
        `Keep the entire headline in ${languageMeta.nameEnWithCode}; no quotes, emoji, or URLs. Limit it to 6–12 words and return only the headline.`,
      ];

  if (keywordReminder) {
    lines.push(keywordReminder);
  }
  if (cascadeInfo && cascadeInfo.titleReminder) {
    lines.push(cascadeInfo.titleReminder);
  }
  return lines.filter(Boolean).join('\n');
}

function buildSystemPrompts({ isRu, languageMeta }) {
  if (isRu) {
    return {
      title: `Только финальный заголовок на ${languageMeta.nameRuIn}. Без кавычек и пояснений.`,
      content: `Только тело статьи в HTML (<p>, <h3>, <ul>/<ol>, <blockquote>). Весь текст держи на ${languageMeta.nameRuIn}. Без посторонних комментариев.`,
    };
  }
  return {
    title: `Return only the final headline. It must be written in ${languageMeta.nameEnWithCode}. No quotes or explanations.`,
    content: `Return only the article body in HTML (<p>, <h3>, <ul>/<ol>, <blockquote>). All visible text must be in ${languageMeta.nameEnWithCode}; do not switch languages or add commentary.`,
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
  const languageMeta = getLanguageMeta(pageLang);
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
    contentLines.push(`Напиши статью на ${languageMeta.nameRuIn} по теме: ${topicLine}.`);
    contentLines.push(`Все заголовки, абзацы и анкоры держи на ${languageMeta.nameRuIn}; не переключайся на другие языки.`);
  } else {
    contentLines.push(`Write an article in ${languageMeta.nameEn} about: ${topicLine}.`);
    contentLines.push(`Keep every heading, paragraph, and anchor strictly in ${languageMeta.nameEnWithCode}. Do not switch languages.`);
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
  if (isHigherLevel) {
    contentLines.push(isRu
      ? `Ссылки на ${pageUrl} вставляй только там, где они усиливают мысль абзаца. Анкоры формируй из слов предложения (2–4 слова) на ${languageMeta.nameRuIn}, без кавычек и слов вроде «тут»/«здесь».`
      : `Place links to ${pageUrl} only where they reinforce the paragraph. Derive each anchor from the sentence itself (2–4 words) in ${languageMeta.nameEnWithCode}, no quotes and no fillers like "here".`
    );
    contentLines.push(isRu
      ? 'Следи, чтобы анкоры различались и выглядели естественно внутри текста.'
      : 'Make sure each anchor text is distinct and feels natural in-line with the prose.'
    );
  } else {
    contentLines.push(isRu
      ? `Каждую ссылку на ${pageUrl} подавай с естественным анкором на ${languageMeta.nameRuIn}, отражающим выгоду для читателя.`
      : `When linking to ${pageUrl}, use a natural ${languageMeta.nameEnWithCode} anchor that explains the benefit for the reader.`
    );
  }
  contentLines.push(avoidGeneric);
  contentLines.push(isRu
    ? `Проверь, что весь текст остаётся на ${languageMeta.nameRuIn}; если встречается другой язык, перепиши фрагмент на ${languageMeta.nameRuIn}.`
    : `Double-check that the entire article stays in ${languageMeta.nameEnWithCode}; rewrite any sentence that slips into another language back into ${languageMeta.nameEnWithCode}.`
  );

  const requirementLines = [];
  if (lengthHints.requirementLine) {
    requirementLines.push(lengthHints.requirementLine);
  }
  if (isHigherLevel) {
    requirementLines.push(
      isRu
        ? '- Ровно три активные ссылки (формат <a href="...">...</a>) и ни одной лишней.'
        : '- Use exactly three active links (format <a href="...">...</a>) and nothing extra.'
    );
    requirementLines.push(
      isRu
        ? `  • Две ссылки ведут на ${pageUrl}; подбирай для них естественные анкоры на ${languageMeta.nameRuIn} (2–4 слова) без кавычек и слов вроде «тут»/«здесь».`
        : `  • Two links must point to ${pageUrl}; craft natural ${languageMeta.nameEnWithCode} anchors (2–4 words) with no quotes and no fillers like “here”.`
    );
    requirementLines.push(
      isRu
        ? '  • Анкоры должны различаться и располагаться внутри абзацев, а не в списках или заголовках.'
        : '  • Anchors must be different from each other and stay inside body paragraphs, not lists or headings.'
    );
    requirementLines.push(
      isRu
        ? '  • Первую ссылку поставь в первой половине статьи, вторую — ближе к завершению.'
        : '  • Put the first link within the first half of the article and the second closer to the conclusion.'
    );
    requirementLines.push(
      isRu
        ? (languageMeta.code === 'en'
            ? '  • Третья ссылка — на авторитетный внешний источник (Wikipedia, официальные отчёты, отраслевые исследования) на английском языке; URL действительный.'
            : `  • Третья ссылка — на авторитетный внешний источник (Wikipedia, официальные отчёты, отраслевые исследования) на ${languageMeta.nameRuIn} или английском языке; URL действительный.`)
        : (languageMeta.code === 'en'
            ? '  • The third link must point to a reputable external source (Wikipedia, official reports, industry research) in English with a valid URL.'
            : `  • The third link must point to a reputable external source (Wikipedia, official reports, industry research) in ${languageMeta.nameEn} or English with a valid URL.`)
    );
  } else {
    requirementLines.push(
      isRu
        ? '- Ровно три активные ссылки в статье (формат строго <a href="...">...</a>):'
        : '- Exactly three active links in the article (format <a href="...">...</a>):'
    );
    requirementLines.push(
      isRu
        ? `  1) В первой половине статьи вставь ссылку на ${pageUrl} с естественным анкором (2–4 слова) на ${languageMeta.nameRuIn}, который помогает понять пользу.`
        : `  1) Within the first half of the article add a link to ${pageUrl} with a natural ${languageMeta.nameEnWithCode} anchor (2–4 words) that highlights the benefit.`
    );
    requirementLines.push(
      isRu
        ? `  2) Во второй половине размести ещё одну ссылку на ${pageUrl} с другим органичным анкором; не повторяй первый.`
        : `  2) In the latter half place another link to ${pageUrl} with a different organic anchor; do not repeat the first one.`
    );
    requirementLines.push(
      isRu
        ? (languageMeta.code === 'en'
            ? '  3) Одна ссылка на авторитетный внешний источник (Wikipedia/энциклопедия/официальный сайт), релевантный теме; URL не должен быть битым; язык предпочтительно английский.'
            : `  3) Одна ссылка на авторитетный внешний источник (Wikipedia/энциклопедия/официальный сайт), релевантный теме; URL не должен быть битым; язык предпочтительно ${languageMeta.nameRuIn} (или английский).`)
        : (languageMeta.code === 'en'
            ? '  3) One link to a reputable external source (e.g., Wikipedia, encyclopedias, official site) relevant to the topic; ensure the URL is valid and preferably in English.'
            : `  3) One link to a reputable external source (e.g., Wikipedia, encyclopedias, official site) relevant to the topic; ensure the URL is valid and preferably in ${languageMeta.nameEn} (or English).`)
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
  const titlePrompt = buildTitlePrompt({
    isRu,
    languageMeta,
    topicLine,
    anchorText,
    isHigherLevel,
    keywordReminder,
    cascadeInfo,
  });
  const prompts = {
    title: titlePrompt,
    content: contentPrompt,
  };
  const systemPrompts = buildSystemPrompts({ isRu, languageMeta });

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const rawTitle = await generateText(prompts.title, { ...aiOpts, systemPrompt: systemPrompts.title, keepRaw: true });
  await sleep(500);
  const rawContent = await generateText(prompts.content, { ...aiOpts, systemPrompt: systemPrompts.content, keepRaw: true });

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
    const strictInstruction = isRu
      ? `СТРОГО: оставь ровно ${wantTotal} ссылки. Две ссылки ведут на ${pageUrl} с разными органичными анкорами на ${languageMeta.nameRuIn} (2–4 слова, без слов «тут»/«здесь»), третья — на авторитетный внешний источник. Другие ссылки не добавляй.`
      : `STRICT: keep exactly ${wantTotal} links. Two links must point to ${pageUrl} with distinct natural ${languageMeta.nameEnWithCode} anchors (2–4 words, no fillers like “here”), and the third link must go to a reputable external source. Do not add any other links.`;
    const stricter = `${prompts.content}
${strictInstruction}`;
    const retry = await generateText(stricter, { ...aiOpts, systemPrompt: systemPrompts.content, keepRaw: true });
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
