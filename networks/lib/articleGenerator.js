'use strict';

const { generateText, cleanLLMOutput } = require('../ai_client');
const { htmlToPlainText } = require('./contentFormats');
const { prepareTextSample } = require('./verification');
const { generateImage } = require('./generateImage');

let lastGeneratedArticle = null;

const LANGUAGE_LABELS = {
  ru: {
    nameEn: 'Russian',
    nameEnWithCode: 'Russian',
    nameRuIn: 'русском языке',
    iso: 'ru',
  },
  uk: {
    nameEn: 'Ukrainian',
    nameRuIn: 'украинском языке',
    iso: 'uk',
  },
  ua: {
    nameEn: 'Ukrainian',
    nameRuIn: 'украинском языке',
    iso: 'uk',
  },
  en: {
    nameEn: 'English',
    nameEnWithCode: 'English',
    nameRuIn: 'английском языке',
    iso: 'en',
  },
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

const DEFAULT_LANGUAGE_META = {
  code: '',
  nameEn: 'the page language',
  nameEnWithCode: 'the page language',
  nameRuIn: 'языке страницы',
  nativeIn: '',
  nativeContentReminder: '',
  nativeTitleReminder: '',
  nativeSystemTitle: '',
  nativeSystemContent: '',
  nativeFinalCheck: '',
  languageCodeReminder: '',
  iso: '',
};

const hasIntlDisplayNames = typeof Intl !== 'undefined' && typeof Intl.DisplayNames === 'function';
const languageDisplayByLocale = {};

function getDisplayNamesInstance(locale) {
  if (!hasIntlDisplayNames) {
    return null;
  }
  if (!languageDisplayByLocale[locale]) {
    try {
      languageDisplayByLocale[locale] = new Intl.DisplayNames([locale], { type: 'language' });
    } catch (_) {
      languageDisplayByLocale[locale] = null;
    }
  }
  return languageDisplayByLocale[locale];
}

function getLanguageDisplayName(locale, code) {
  if (!code) {
    return null;
  }
  const instance = getDisplayNamesInstance(locale);
  if (!instance) {
    return null;
  }
  try {
    return instance.of(code) || null;
  } catch (_) {
    return null;
  }
}

function withLanguageMetaDefaults(meta = {}) {
  return { ...DEFAULT_LANGUAGE_META, ...meta };
}

function buildFallbackLanguageMeta(baseCode) {
  const code = (baseCode || '').trim();
  if (!code) {
    return withLanguageMetaDefaults();
  }
  const iso = code;
  const isoLabel = iso ? iso.toUpperCase() : '';
  const displayEn = iso ? getLanguageDisplayName('en', iso) : null;
  const nameEn = displayEn ? `${displayEn}` : (isoLabel ? `language (${isoLabel})` : DEFAULT_LANGUAGE_META.nameEn);
  const nameEnWithCode = displayEn && iso ? `${displayEn} language (ISO code: ${iso})` : (iso ? `language (ISO code: ${iso})` : DEFAULT_LANGUAGE_META.nameEnWithCode);
  const nameRuIn = iso ? `языке с ISO-кодом ${iso}` : DEFAULT_LANGUAGE_META.nameRuIn;

  return withLanguageMetaDefaults({
    code,
    iso,
    nameEn,
    nameEnWithCode,
    nameRuIn,
    languageCodeReminder: iso ? `Строго придерживайся языка с ISO-кодом ${iso}.` : '',
  });
}

function getLanguageMeta(language) {
  const raw = (language || '').toString().trim();
  if (!raw) {
    return withLanguageMetaDefaults();
  }
  const lower = raw.toLowerCase();
  const base = lower.split(/[-_]/)[0] || lower;
  const preset = LANGUAGE_LABELS[base] || null;
  if (preset) {
    const iso = (preset.iso || base || '').trim();
    const fallback = buildFallbackLanguageMeta(iso || base);
    return withLanguageMetaDefaults({
      ...fallback,
      ...preset,
      code: base,
      iso,
      nameEn: preset.nameEn || fallback.nameEn,
      nameEnWithCode: preset.nameEnWithCode || fallback.nameEnWithCode,
      nameRuIn: preset.nameRuIn || fallback.nameRuIn,
      nativeIn: preset.nativeIn || fallback.nativeIn,
      nativeContentReminder: preset.nativeContentReminder || fallback.nativeContentReminder,
      nativeTitleReminder: preset.nativeTitleReminder || fallback.nativeTitleReminder,
      nativeSystemTitle: preset.nativeSystemTitle || fallback.nativeSystemTitle,
      nativeSystemContent: preset.nativeSystemContent || fallback.nativeSystemContent,
      nativeFinalCheck: preset.nativeFinalCheck || fallback.nativeFinalCheck,
      languageCodeReminder: preset.languageCodeReminder || fallback.languageCodeReminder,
    });
  }
  return buildFallbackLanguageMeta(base);
}

function resolveLanguageCode(languageMeta = {}) {
  return String(languageMeta.iso || languageMeta.code || '').trim();
}

function buildLanguageLabel(languageMeta = {}, { includePreposition = true } = {}) {
  const code = resolveLanguageCode(languageMeta);
  const base = code ? `языке с ISO-кодом ${code}` : 'указанном языке';
  return includePreposition ? `на ${base}` : base;
}

function buildLanguageOrEnglishLabel(languageMeta = {}) {
  const code = resolveLanguageCode(languageMeta).toLowerCase();
  if (!code || code === 'en') {
    return 'английском языке';
  }
  return `языке с ISO-кодом ${code} или английском языке`;
}

const compact = (items) => (Array.isArray(items) ? items.filter(Boolean) : []);

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

function buildCascadeGuidance(cascade) {
  if (!cascade || typeof cascade !== 'object') {
    return { intro: '', bullets: [], reminder: '', titleReminder: '', detailSnippets: [], focusKeywords: [] };
  }
  const intro = 'Контекст предыдущих уровней:';
  const summaryLabel = 'Суть';
  const keywordsLabel = 'Ключевые темы';
  const titleLabel = 'Заголовок';
  const bullet = '—';
  const reminder = 'Опирайся на эти материалы: подчеркни связь с родительской статьёй, используй схожие темы и упомяни исходные выводы.';
  const titleReminder = 'Заголовок должен отражать связь с темой родительской статьи.';

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

function buildFallbackListItems(source) {
  const raw = String(source || '');
  const items = [];

  const headingMatches = [...raw.matchAll(/<h3[^>]*>([\s\S]*?)<\/h3>/gi)];
  headingMatches.forEach((match) => {
    const text = extractPlainText(match[1]);
    if (!text) {
      return;
    }
    const sentences = text
      .split(/[.!?]+/)
      .map((item) => item.trim())
      .filter((item) => item.length > 30)
      .slice(0, 2);
    items.push(...sentences);
  });

  if (!items.length) {
    const paragraphMatches = [...raw.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/gi)];
    paragraphMatches.forEach((match) => {
      const text = extractPlainText(match[1]);
      if (!text) {
        return;
      }
      const sentences = text
        .split(/[.!?]+/)
        .map((item) => item.trim())
        .filter((item) => item.length > 40);
      if (sentences.length) {
        items.push(sentences[0]);
      }
    });
  }

  const uniqueItems = Array.from(new Set(items.map((item) => item.trim()))).filter(Boolean).slice(0, 5);

  if (uniqueItems.length < 2) {
    return [];
  }

  return uniqueItems;
}

function buildFallbackListHtml(html) {
  const items = buildFallbackListItems(html);
  if (!items.length) {
    return '';
  }
  return `<ul>${items.map((item) => `<li>${item}</li>`).join('')}</ul>`;
}

function injectFallbackList(html) {
  const listHtml = buildFallbackListHtml(html);
  if (!listHtml) {
    return html;
  }
  if (/<h3/i.test(html)) {
    return html.replace(/<h3/i, `${listHtml}\n<h3`);
  }
  const firstParagraphEnd = html.indexOf('</p>');
  if (firstParagraphEnd !== -1) {
    return `${html.slice(0, firstParagraphEnd + 4)}\n${listHtml}\n${html.slice(firstParagraphEnd + 4)}`;
  }
  return `${html}\n${listHtml}`;
}

function injectFallbackQuote(html, { anchorText }) {
  const quoteText = `«${anchorText}» — надежный ориентир для читателей, которые следят за ключевыми тенденциями 2025 года. Эксперты PromoPilot рекомендуют регулярно обращаться к аналитическим материалам, чтобы не упустить важные возможности.`;
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

function buildLevelPromptHints({ level, languageMeta, minLength, maxLength }) {
  const normalizedMin = normalizeLengthHint(minLength);
  const baseMin = normalizedMin ? Math.max(normalizedMin, 4200) : 4200;
  const defaultLengthSentence = `Объем материала — не меньше ${baseMin} знаков (без учета HTML).`;
  const defaultRequirement = `- Минимум ${baseMin} знаков (без учета HTML).`;
  const targetLanguage = languageMeta && languageMeta.nameRuIn ? languageMeta.nameRuIn : 'целевом языке';

  /* =====================================================
    === БЛОК НАСТРОЕК ГЕНЕРАЦИИ ДЛЯ ПЕРВОГО УРОВНЯ ===
    ===================================================== */
  if (level === 1) {
    const min = Math.max(normalizedMin || 0, 7000);
    let max = normalizeLengthHint(maxLength);
    if (!max || max < min + 800) {
      max = Math.max(min + 2000, 11000);
    } else {
      max = Math.min(Math.max(max, min + 800), 12000);
    }
    const lengthSentence = `Целься в объем ${min}–${max} знаков (без учета HTML-разметки; оптимально 7800–10500).`;
    const requirementLine = `- Объем ${min}–${max} знаков (без учета HTML).`;
    const extraRequirementLines = [
      '- Добавь минимум один структурированный список (<ul>/<ol> с <li>).',
      '- Используй хотя бы один блок <blockquote> с цитатой, статистикой или ключевой мыслью.',
      '- В основных разделах приводи конкретные примеры, данные или наблюдения из практики.',
      '- Каждый смысловой блок содержит минимум три насыщенных абзаца (по 4–5 предложений).',
      '- Включи дополнительные аналитические детали: цифры, практические случаи, сравнительные таблицы в тексте.',
    ];
    const toneLines = [
      'Стиль — экспертный и аналитический: объясняй причины, опирайся на исследования/данные и давай практические рекомендации.',
      'Структурируй материал: введение, глубокие аналитические блоки, практические советы и убедительное заключение.',
      'Поддерживай непрерывное повествование и завершай материал сильным выводом без обрыва.',
      `Следи, чтобы каждое предложение оставалось на ${targetLanguage}; если появляется другой язык, перепиши фрагмент заново.`,
    ];
    return { lengthSentence, requirementLine, extraRequirementLines, toneLines };
  }

  /* =====================================================
    === БЛОК НАСТРОЕК ГЕНЕРАЦИИ ДЛЯ ВТОРОГО/ТРЕТЬЕГО УРОВНЕЙ ===
    ===================================================== */
  if (level === 2 || level === 3) {
    const min = Math.max(normalizedMin || 0, 4500);
    let max = normalizeLengthHint(maxLength);
    if (!max) {
      max = min + 1600;
    }
    if (max < min + 400) {
      max = min + 400;
    }
    if (max > 6500) {
      max = 6500;
    }
    const lengthSentence = `Целься в объем ${min}–${max} знаков (без учета HTML-разметки; оптимально около 5000–5800).`;
    const requirementLine = `- Объем ${min}–${max} знаков (без учета HTML).`;
    const extraRequirementLines = [
      '- Структура: краткое введение, 2–3 смысловых блока и финальный вывод.',
      '- Обязательно покажи связь с материалами предыдущего уровня и добавь конкретные детали из темы.',
      '- Каждый раздел раскрывай в двух и более насыщенных абзацах; избегай поверхностных перечислений.',
    ];
    const toneLines = [
      'Тон — практический и основанный на фактах: без воды, с конкретными наблюдениями и выводами.',
      'Завершай статью четким выводом или рекомендациями — без резкого окончания.',
      `Любые цитаты и формулировки оставляй на ${targetLanguage}; если встречается иной язык — перепиши отрывок.`,
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


function buildTitlePrompt({ languageMeta, topicLine, anchorText, isHigherLevel, keywordReminder, cascadeInfo }) {
  const languageInstruction = buildLanguageLabel(languageMeta);
  const trimmedAnchor = (anchorText || '').toString().trim();
  const lines = [
    `Сформулируй чёткий конкретный заголовок ${languageInstruction} по теме: ${topicLine}.`,
    isHigherLevel
      ? 'Передай связь с целевой страницей и подчеркни основную выгоду, не цитируя точные формулировки анкоров.'
      : `Укажи фокус: ${anchorText}.`,
    `Заголовок держи ${languageInstruction}; без кавычек и эмодзи, без упоминания URL, 6–12 слов. Ответь только заголовком.`,
  ];

  if (trimmedAnchor) {
    lines.push(`Не используй точную фразу ${trimmedAnchor} или её прямые вариации в заголовке и не превращай анкор в заголовок.`);
  }

  if (keywordReminder) {
    lines.push(keywordReminder);
  }
  if (cascadeInfo && cascadeInfo.titleReminder) {
    lines.push(cascadeInfo.titleReminder);
  }
  return lines.filter(Boolean).join('\n');
}

function buildSystemPrompts({ languageMeta }) {
  const languageInstruction = buildLanguageLabel(languageMeta);
  let titlePrompt = `Только финальный заголовок ${languageInstruction}. Без кавычек и пояснений.`;
  let contentPrompt = `Только тело статьи в HTML (<p>, <h3>, <ul>/<ol>, <blockquote>). Весь текст держи ${languageInstruction}. Без посторонних комментариев.`;
  if (languageMeta.iso) {
    titlePrompt += ` Соблюдай ISO-код языка ${languageMeta.iso}.`;
    contentPrompt += ` Соблюдай ISO-код языка ${languageMeta.iso}.`;
  }
  if (languageMeta.nativeIn) {
    contentPrompt += ` Весь видимый текст должен быть написан ${languageMeta.nativeIn}.`;
  }
  if (languageMeta.nativeSystemTitle) {
    titlePrompt += ` ${languageMeta.nativeSystemTitle}`;
  } else if (languageMeta.nativeTitleReminder) {
    titlePrompt += ` ${languageMeta.nativeTitleReminder}`;
  }
  if (languageMeta.nativeSystemContent) {
    contentPrompt += ` ${languageMeta.nativeSystemContent}`;
  } else if (languageMeta.nativeContentReminder) {
    contentPrompt += ` ${languageMeta.nativeContentReminder}`;
  }
  if (languageMeta.nativeFinalCheck) {
    contentPrompt += ` ${languageMeta.nativeFinalCheck}`;
  }
  return { title: titlePrompt, content: contentPrompt };
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

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function escapeRegExp(value) {
  return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function normalizeUrlForCompare(url) {
  return String(url || '')
    .trim()
    .replace(/^https?:\/\//i, '')
    .replace(/^\/\//, '')
    .replace(/#.*$/, '')
    .replace(/\/+$/, '')
    .toLowerCase();
}

function collectAnchorMatches(html) {
  const matches = [];
  const regex = /<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi;
  const source = String(html || '');
  let match;
  while ((match = regex.exec(source))) {
    matches.push({
      href: match[1],
      inner: match[2],
      start: match.index,
      end: regex.lastIndex,
    });
  }
  return matches;
}

function normalizeAnchorInnerText(value) {
  return String(value || '')
    .replace(/<[^>]+>/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
}

function injectAnchorIntoParagraph(html, anchorHtml, { prefer = 'start', allowNewParagraph = true } = {}) {
  const source = String(html || '');
  if (!source.trim()) {
    return allowNewParagraph ? `<p>${anchorHtml}</p>` : anchorHtml;
  }

  const paragraphRegex = /<p[^>]*>[\s\S]*?<\/p>/gi;
  const paragraphs = Array.from(source.matchAll(paragraphRegex));
  if (!paragraphs.length) {
    const separator = source.endsWith('</h1>') ? '\n' : '\n\n';
    return allowNewParagraph ? `${source}${separator}<p>${anchorHtml}</p>` : `${anchorHtml}\n${source}`;
  }

  let target = null;
  if (typeof prefer === 'number' && Number.isFinite(prefer)) {
    target = paragraphs[Math.min(paragraphs.length - 1, Math.max(0, Math.floor(prefer)))] || paragraphs[0];
  } else if (prefer === 'end') {
    target = paragraphs[paragraphs.length - 1];
  } else if (prefer === 'middle') {
    target = paragraphs[Math.floor(paragraphs.length / 2)] || paragraphs[0];
  } else {
    target = paragraphs[0];
  }

  if (!target) {
    return allowNewParagraph ? `${source}\n<p>${anchorHtml}</p>` : `${anchorHtml}\n${source}`;
  }

  const start = target.index;
  const end = start + target[0].length;
  const anchorMatch = /<a[^>]*>([\s\S]*?)<\/a>/i.exec(anchorHtml);
  const anchorText = anchorMatch ? anchorMatch[1].replace(/<[^>]+>/g, '').trim() : '';
  if (anchorText) {
    const pattern = new RegExp(`(${escapeRegExp(anchorText)})`, 'i');
    const updatedTarget = target[0].replace(pattern, anchorHtml);
    if (updatedTarget !== target[0]) {
      return `${source.slice(0, start)}${updatedTarget}${source.slice(end)}`;
    }
  }
  const closingIndex = source.lastIndexOf('</p>', end);
  const insertionPoint = closingIndex !== -1 ? closingIndex : end - 4;
  const before = source.slice(0, insertionPoint);
  const after = source.slice(insertionPoint);
  const spacer = /[\s>]/.test(before.slice(-1)) ? '' : ' ';
  return `${before}${spacer}${anchorHtml}${after}`;
}

function mergeStandaloneAnchorParagraphs(html, { targetUrl }) {
  let content = String(html || '');
  if (!content.trim()) {
    return content;
  }
  const normalizedTarget = normalizeUrlForCompare(targetUrl);
  if (!normalizedTarget) {
    return content;
  }

  const paragraphRegex = /<p[^>]*>[\s\S]*?<\/p>/gi;
  const nodes = [];
  let lastIndex = 0;
  let match;
  while ((match = paragraphRegex.exec(content))) {
    if (match.index > lastIndex) {
      nodes.push({ type: 'text', value: content.slice(lastIndex, match.index) });
    }
    const value = match[0];
    const inner = value.replace(/^<p[^>]*>/i, '').replace(/<\/p>$/i, '');
    const anchorMatches = Array.from(inner.matchAll(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi));
    const nonAnchorText = inner
      .replace(/<a[^>]*>[\s\S]*?<\/a>/gi, '')
      .replace(/[\s.,;:!?«»"'\-–—()\[\]{}]+/g, '')
      .trim();
    const isAnchorOnly = anchorMatches.length === 1
      && !nonAnchorText
      && normalizeUrlForCompare(anchorMatches[0][1]) === normalizedTarget;
    nodes.push({ type: 'paragraph', value, anchorMatches, isAnchorOnly });
    lastIndex = match.index + match[0].length;
  }
  if (lastIndex < content.length) {
    nodes.push({ type: 'text', value: content.slice(lastIndex) });
  }

  if (!nodes.some((node) => node.type === 'paragraph' && node.isAnchorOnly)) {
    return content;
  }

  const resultNodes = [];
  let pendingAnchor = null;

  const injectPending = (paragraphValue, prefer) => {
    if (!pendingAnchor) {
      return paragraphValue;
    }
    const updated = injectAnchorIntoParagraph(paragraphValue, pendingAnchor, { prefer, allowNewParagraph: false });
    pendingAnchor = null;
    return updated;
  };

  nodes.forEach((node) => {
    if (node.type === 'text') {
      resultNodes.push(node);
      return;
    }

    if (node.isAnchorOnly) {
      const anchorHtml = node.anchorMatches[0][0];
      let merged = false;
      for (let i = resultNodes.length - 1; i >= 0; i -= 1) {
        const candidate = resultNodes[i];
        if (candidate.type === 'paragraph') {
          candidate.value = injectAnchorIntoParagraph(candidate.value, anchorHtml, { prefer: 'end', allowNewParagraph: false });
          merged = true;
          break;
        }
      }
      if (!merged) {
        pendingAnchor = anchorHtml;
      }
      return;
    }

    let paragraphValue = node.value;
    if (pendingAnchor) {
      paragraphValue = injectPending(paragraphValue, 'start');
    }
    resultNodes.push({ type: 'paragraph', value: paragraphValue });
  });

  if (pendingAnchor) {
    let merged = false;
    for (let i = resultNodes.length - 1; i >= 0; i -= 1) {
      const candidate = resultNodes[i];
      if (candidate.type === 'paragraph') {
        candidate.value = injectAnchorIntoParagraph(candidate.value, pendingAnchor, { prefer: 'end', allowNewParagraph: false });
        merged = true;
        break;
      }
    }
    if (!merged) {
      resultNodes.push({ type: 'paragraph', value: `<p>${pendingAnchor}</p>` });
    }
    pendingAnchor = null;
  }

  return resultNodes.map((node) => node && typeof node.value === 'string' ? node.value : '').join('');
}

function removeAnchorsFromHeadings(html, { targetUrl }) {
  let content = String(html || '');
  if (!content.trim()) {
    return content;
  }
  const normalizedTarget = normalizeUrlForCompare(targetUrl);
  if (!normalizedTarget) {
    return content;
  }

  const headingRegex = /<h([1-6])[^>]*>[\s\S]*?<\/h\1>/gi;
  let match;
  const pieces = [];
  let lastIndex = 0;
  let touched = false;

  while ((match = headingRegex.exec(content))) {
    const start = match.index;
    const end = start + match[0].length;
    const heading = match[0];
    const anchors = Array.from(heading.matchAll(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi));
    const targetAnchors = anchors.filter((item) => normalizeUrlForCompare(item[1]) === normalizedTarget);
    if (!targetAnchors.length) {
      continue;
    }
    pieces.push(content.slice(lastIndex, start));
    let replacement = heading;
    targetAnchors.forEach((anchorMatch) => {
      replacement = replacement.replace(anchorMatch[0], anchorMatch[2]);
    });
    pieces.push(replacement);
    lastIndex = end;
    touched = true;
  }

  if (!touched) {
    return content;
  }

  pieces.push(content.slice(lastIndex));
  return pieces.join('');
}

const INTERNAL_ANCHOR_FALLBACKS = {
  ru: ['Подробнее на сайте', 'Проверить официальную страницу', 'Узнать детали на сайте'],
  uk: ['Детальніше на сайті', 'Переглянути сторінку сервісу', 'Отримати деталі на сайті'],
  en: ['Explore the site', 'View the service page', 'Learn more on the website'],
  default: ['Подробнее на сайте', 'Перейти на страницу сервиса', 'Узнать подробности'],
};

function pickLanguageFallbackAnchor(languageMeta) {
  const code = resolveLanguageCode(languageMeta).toLowerCase();
  const pool = INTERNAL_ANCHOR_FALLBACKS[code] && INTERNAL_ANCHOR_FALLBACKS[code].length
    ? INTERNAL_ANCHOR_FALLBACKS[code]
    : INTERNAL_ANCHOR_FALLBACKS.default;
  return pool[0] || 'Подробнее на сайте';
}

const EXTERNAL_LINK_FALLBACKS = [
  {
    url: 'https://en.wikipedia.org/wiki/Digital_marketing',
    anchor: {
      ru: 'Обзор по digital-маркетингу на Wikipedia',
      uk: 'Огляд digital-маркетингу на Wikipedia',
      en: 'Digital marketing overview on Wikipedia',
      default: 'Digital marketing overview on Wikipedia',
    },
  },
];

function buildInternalAnchorFallbacks({ anchorValue, hasProvidedAnchor, languageMeta, wantCount }) {
  const texts = [];
  const trimmedAnchor = String(anchorValue || '').trim();
  if (hasProvidedAnchor && trimmedAnchor) {
    texts.push(trimmedAnchor);
  }
  const code = resolveLanguageCode(languageMeta).toLowerCase();
  const pool = (INTERNAL_ANCHOR_FALLBACKS[code] && INTERNAL_ANCHOR_FALLBACKS[code].length)
    ? INTERNAL_ANCHOR_FALLBACKS[code]
    : INTERNAL_ANCHOR_FALLBACKS.default;
  let index = 0;
  while (texts.length < (wantCount || 0)) {
    const next = pool[index % pool.length];
    if (next) {
      texts.push(next);
    }
    index += 1;
    if (index > 12) {
      break;
    }
  }
  return texts.slice(0, wantCount);
}

function pickExternalFallbackLink(languageMeta = {}) {
  const code = resolveLanguageCode(languageMeta).toLowerCase();
  for (const option of EXTERNAL_LINK_FALLBACKS) {
    const anchorMap = option.anchor || {};
    const anchor = anchorMap[code] || anchorMap.default || anchorMap.en || Object.values(anchorMap)[0];
    if (option.url && anchor) {
      return { url: option.url, anchor };
    }
  }
  return {
    url: 'https://en.wikipedia.org/wiki/Digital_marketing',
    anchor: 'Digital marketing research overview',
  };
}

function trimExtraAnchors(html, { totalLimit, targetUrl }) {
  const limit = Number(totalLimit);
  if (!Number.isFinite(limit) || limit <= 0) {
    return String(html || '');
  }
  const source = String(html || '');
  const matches = collectAnchorMatches(source);
  if (matches.length <= limit) {
    return source;
  }

  const targetNormalized = normalizeUrlForCompare(targetUrl);
  const toRemove = [];
  let remaining = matches.length - limit;

  for (let i = matches.length - 1; i >= 0 && remaining > 0; i -= 1) {
    const match = matches[i];
    const isOur = normalizeUrlForCompare(match.href) === targetNormalized;
    if (!isOur) {
      toRemove.push(match);
      remaining -= 1;
    }
  }

  for (let i = matches.length - 1; i >= 0 && remaining > 0; i -= 1) {
    const match = matches[i];
    if (toRemove.includes(match)) {
      continue;
    }
    toRemove.push(match);
    remaining -= 1;
  }

  if (!toRemove.length) {
    return source;
  }

  toRemove.sort((a, b) => b.start - a.start);
  let output = source;
  toRemove.forEach((match) => {
    const replacement = String(match.inner || '').replace(/<[^>]+>/g, '').trim();
    output = `${output.slice(0, match.start)}${replacement}${output.slice(match.end)}`;
  });
  return output;
}

function ensureLinksWithFallback(html, {
  pageUrl,
  anchorText,
  hasProvidedAnchor,
  languageMeta,
  wantOur,
  wantExternal,
  wantTotal,
  expectedAnchorText,
}) {
  const content = String(html || '');
  const stats = analyzeLinks(content, pageUrl, expectedAnchorText);
  return { html: content, stats, modified: false };
}

function enforceAnchorTextExpectations(html, {
  pageUrl,
  expectedAnchor,
  hasProvidedAnchor,
  languageMeta,
  desiredOurCount,
}) {
  const content = String(html || '');
  return { html: content, changed: false };
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

async function generateAuthorName({ languageMeta, aiOpts, logLine }) {
  const languageInstruction = buildLanguageLabel(languageMeta, { includePreposition: false });
  const nameLanguage = languageMeta && languageMeta.nativeIn ? languageMeta.nativeIn : languageInstruction || 'целевом языке';
  const promptLines = [
    `Придумай реалистичное имя и фамилию автора статьи на ${nameLanguage}.`,
    'Имя должно подходить культурному контексту языка и не содержать латинских букв, если язык использует другую письменность.',
    'Без сокращений, титулов, кавычек и дополнительных комментариев.',
    'Ответь только именем и фамилией.'
  ];
  const prompt = promptLines.join('\n');
  const systemPrompt = `Верни только имя и фамилию автора на ${nameLanguage}. Без пояснений.`;
  try {
    const raw = await generateText(prompt, { ...aiOpts, systemPrompt, temperature: 0.3, keepRaw: true });
    const clean = cleanLLMOutput(raw).replace(/["'«»<>\[\]]+/g, '').replace(/\s+/g, ' ').trim();
    if (clean && clean.split(' ').length >= 2 && clean.length <= 64) {
      if (typeof logLine === 'function') {
        logLine('article.debug.author_generated', { author: clean, language: languageMeta ? languageMeta.code || languageMeta.iso || null : null });
      }
      return clean;
    }
  } catch (error) {
    if (typeof logLine === 'function') {
      logLine('article.debug.author_generation_failed', { error: String(error && error.message ? error.message : error) });
    }
  }
  const fallback = languageMeta && languageMeta.code === 'en' ? 'Alex Taylor' : 'PromoPilot Автор';
  return fallback;
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
  const languageMeta = getLanguageMeta(pageLang);
  const wishLine = wish ? `Учитывай пожелание клиента: ${wish}.` : '';
  const cascadeSource = (cascade && typeof cascade === 'object') ? cascade : {};
  const cascadeNormalized = {
    level: cascadeSource.level !== undefined ? cascadeSource.level : (articleHints.level ?? null),
    parentUrl: cascadeSource.parentUrl !== undefined ? cascadeSource.parentUrl : (articleHints.parentUrl || null),
    parentContext: cascadeSource.parentContext !== undefined ? cascadeSource.parentContext : (articleHints.parentContext || null),
    ancestorTrail: Array.isArray(cascadeSource.ancestorTrail)
      ? cascadeSource.ancestorTrail
      : (Array.isArray(articleHints.ancestorTrail) ? articleHints.ancestorTrail : []),
  };
  const cascadeInfo = buildCascadeGuidance(cascadeNormalized);
  const anchorValue = (anchorText || '').toString().trim();
  const hasProvidedAnchor = anchorValue.length > 0;
  const anchorDisplay = hasProvidedAnchor ? `«${anchorValue}»` : 'указанный анкор';

  const insightLabel = 'Ключевые идеи родительской статьи';
  const insightBullet = '•';
  const insightBlock = Array.isArray(cascadeInfo.detailSnippets) && cascadeInfo.detailSnippets.length
    ? `${insightLabel}:\n${cascadeInfo.detailSnippets.map((line) => `${insightBullet} ${line}`).join('\n')}\n`
    : '';
  const keywordReminder = Array.isArray(cascadeInfo.focusKeywords) && cascadeInfo.focusKeywords.length
    ? `Сфокусируйся на темах: ${cascadeInfo.focusKeywords.join(', ')}. Упомяни каждую из них конкретно и развёрнуто.`
    : '';
  const avoidGeneric = 'Не используй шаблонные фразы вроде «разбор кейса», «быстрый обзор», «пошаговый гайд» без конкретики. Излагай факты и контекст исходной темы.';
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
    languageMeta,
    minLength: articleHints.minLength,
    maxLength: articleHints.maxLength,
  });
  const levelNumber = Number.isFinite(inferredLevel) ? inferredLevel : 1;
  const isHigherLevel = levelNumber >= 2;
  if (typeof logLine === 'function') {
    logLine('article.debug.input_context', {
      pageLang,
      requestedLanguage: language || null,
      languageMeta,
      targetUrl: pageUrl,
      anchorText: anchorValue,
      hasProvidedAnchor,
      levelNumber,
      isHigherLevel,
      cascadeLevel: cascadeNormalized.level ?? null,
      cascadeTrailLength: Array.isArray(cascadeNormalized.ancestorTrail) ? cascadeNormalized.ancestorTrail.length : 0,
      linkPlan: isHigherLevel
        ? {
            mode: 'higher-level',
            internalLinks: 2,
            internalAnchorStrategy: 'organic 2–4 слова',
            externalLinks: 1,
            externalSourceExamples: ['Wikipedia', 'официальные исследования', 'отраслевые обзоры'],
          }
        : {
            mode: 'level-1',
            firstAnchor: hasProvidedAnchor ? anchorValue : 'органичный 2–4 слова',
            secondAnchor: 'другой органичный 2–4 слова',
            externalLinks: 1,
            externalSourceExamples: ['Wikipedia', 'официальные исследования', 'отраслевые обзоры'],
          }
    });
  }
  const isTest = !!testMode;
  if (typeof logLine === 'function') {
    logLine('article.job.language_snapshot', {
      pageLang,
      requestedLanguage: language || null,
      articleConfigLanguage: articleHints.language || null,
      pageMetaLang: pageMeta.lang || null,
      metaLang: meta && typeof meta === 'object' ? (meta.lang || null) : null,
      inferredLevel,
      levelNumber,
      isHigherLevel,
      cascadeLevel: cascadeNormalized.level ?? null,
      cascadeTrail: Array.isArray(cascadeNormalized.ancestorTrail) ? cascadeNormalized.ancestorTrail.length : 0,
    });
  }

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
  const languageInstruction = buildLanguageLabel(languageMeta);
  const languageIn = languageMeta.nameRuIn || 'целевом языке';
  const contentLines = [];
  contentLines.push(`Напиши статью ${languageInstruction} по теме: ${topicLine}.`);
  contentLines.push(`Все заголовки, абзацы и анкоры держи на ${languageIn}; не переключайся на другие языки.`);
  contentLines.push('Ссылки размещай только внутри абзацев <p>, вплетая их в предложение; не создавай отдельные параграфы, состоящие только из ссылки, и не вставляй ссылки в заголовки или списки.');
  if (languageMeta.iso) {
    contentLines.push(`Строго соблюдай язык с ISO-кодом ${languageMeta.iso}. Любые фрагменты на другом языке перепиши перед сдачей текста.`);
  }
  if (region) {
    contentLines.push(`Регион публикации: ${region}.`);
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
  /* =====================================================
    === БЛОК ПРОМПТОВ ДЛЯ ВТОРОГО/ТРЕТЬЕГО УРОВНЕЙ ===
    ===================================================== */
  if (isHigherLevel) {
    contentLines.push(`Ссылки на ${pageUrl} вставляй только там, где они усиливают мысль абзаца. Анкоры формируй из слов предложения (2–4 слова) на ${languageIn}, без кавычек и слов вроде «тут» или «здесь».`);
    if (hasProvidedAnchor) {
      contentLines.push(`Первая ссылка на ${pageUrl} обязана использовать анкор ${anchorDisplay} без изменений и стоять в первой половине статьи.`);
      contentLines.push(`Вторая ссылка на ${pageUrl} должна появиться ближе к завершению с другим органичным анкором на ${languageIn} (2–4 слова); не повторяй ${anchorDisplay}.`);
    } else {
      contentLines.push(`Две ссылки на ${pageUrl} размещай в разных частях статьи: первую — в первой половине, вторую — ближе к завершению, подбирая различные органичные анкоры на ${languageIn} (2–4 слова).`);
    }
    contentLines.push('Третью ссылку добавь на авторитетный внешний источник, когда аргумент поддерживает вывод статьи.');
    contentLines.push('Следи, чтобы анкоры различались и выглядели естественно внутри текста; не вставляй ссылки в заголовки или списки и не выноси их отдельными абзацами.');
    contentLines.push('Не используй заголовки статьи или длинные названия разделов в качестве анкора — бери короткие фразы из актуального абзаца.');
  } else {
   /* =====================================================
     === БЛОК ПРОМПТОВ ДЛЯ ПЕРВОГО УРОВНЯ ===
     ===================================================== */
    if (hasProvidedAnchor) {
      contentLines.push(`Первая ссылка на ${pageUrl} должна стоять в первой половине статьи и использовать анкор ${anchorDisplay} без изменений и дополнительных слов.`);
    } else {
      contentLines.push(`В первой половине статьи вставь ссылку на ${pageUrl} с естественным анкором на ${languageIn}, который подчёркивает выгоду для читателя.`);
    }
    contentLines.push(`Во второй половине статьи размести ещё одну ссылку на ${pageUrl} с другим органичным анкором на ${languageIn}, чтобы она выглядела естественно и не повторяла первую.`);
    contentLines.push('Третья ссылка — на авторитетный внешний источник (например, Wikipedia, отраслевые обзоры или официальные исследования); вставляй её там, где она подкрепляет выводы статьи.');
  }
  contentLines.push(avoidGeneric);
  contentLines.push(`Проверь, что весь текст остаётся на ${languageIn}; если появляется другой язык — сразу перепиши соответствующий фрагмент.`);

  const requirementLines = [];
  if (lengthHints.requirementLine) {
    requirementLines.push(lengthHints.requirementLine);
  }
  /* =====================================================
    === БЛОК ТРЕБОВАНИЙ ПО ССЫЛКАМ ДЛЯ ВТОРОГО/ТРЕТЬЕГО УРОВНЕЙ ===
    ===================================================== */
  if (isHigherLevel) {
    requirementLines.push('- Ровно три активные ссылки (формат <a href="...">...</a>) и ни одной лишней.');
    requirementLines.push(`  • Две ссылки ведут на ${pageUrl}; подбирай для них естественные анкоры на ${languageIn} (2–4 слова) без кавычек и слов вроде «тут» или «здесь».`);
    if (hasProvidedAnchor) {
      requirementLines.push(`    ◦ Первая ссылка использует анкор ${anchorDisplay} без изменений и стоит в первой половине статьи.`);
      requirementLines.push(`    ◦ Вторая ссылка ставится ближе к завершению с другим органичным анкором на ${languageIn}; не повторяй ${anchorDisplay}.`);
    } else {
      requirementLines.push('    ◦ Первую ссылку поставь в первой половине статьи, вторую — ближе к завершению; анкоры должны отличаться.');
    }
  requirementLines.push('  • Анкоры должны различаться и располагаться внутри абзацев, без заголовков, списков и отдельных пустых параграфов.');
    const externalLanguage = buildLanguageOrEnglishLabel(languageMeta);
    requirementLines.push(`  • Третья ссылка — на авторитетный внешний источник (Wikipedia, официальные отчёты, отраслевые исследования); URL действительный, язык материала — на ${externalLanguage}.`);
  } else {
   /* =====================================================
     === БЛОК ТРЕБОВАНИЙ ПО ССЫЛКАМ ДЛЯ ПЕРВОГО УРОВНЯ ===
     ===================================================== */
    requirementLines.push('- Ровно три активные ссылки в статье (формат строго <a href="...">...</a>):');
    if (hasProvidedAnchor) {
      requirementLines.push(`  1) В первой половине статьи вставь ссылку на ${pageUrl} строго с анкором ${anchorDisplay}; не добавляй к нему лишних слов.`);
    } else {
      requirementLines.push(`  1) В первой половине статьи вставь ссылку на ${pageUrl} с естественным анкором (2–4 слова) на ${languageIn}, который раскрывает выгоду.`);
    }
    const secondLinkText = hasProvidedAnchor
      ? `  2) Во второй половине размести ещё одну ссылку на ${pageUrl} с другим органичным анкором на ${languageIn}; не повторяй первый и не используй ${anchorDisplay}.`
      : `  2) Во второй половине размести ещё одну ссылку на ${pageUrl} с другим органичным анкором на ${languageIn}; не повторяй первый.`;
    requirementLines.push(secondLinkText);
    const externalLanguage = buildLanguageOrEnglishLabel(languageMeta);
  requirementLines.push(`  3) Одна ссылка на авторитетный внешний источник (Wikipedia/энциклопедия/официальный сайт), релевантный теме; URL не должен быть битым; предпочтительный язык материала — на ${externalLanguage}.`);
  requirementLines.push('  • Обе ссылки на целевую страницу должны стоять внутри содержательных абзацев: не размещай их в заголовках, списках или отдельных пустых параграфах.');
  }
  if (Array.isArray(lengthHints.extraRequirementLines) && lengthHints.extraRequirementLines.length) {
    requirementLines.push(...lengthHints.extraRequirementLines);
  }
  requirementLines.push(
    '- Используй только простой HTML: абзацы <p>, подзаголовки <h3>, списки (<ul>/<ol>) и цитаты <blockquote>. Без markdown и кода.',
    '- 3–5 смысловых секций и короткое заключение.',
    '- Заверши статью полноценным выводом: последний абзац кратко подытоживает ключевые мысли без внезапного обрыва.',
    '- Кроме указанных трёх ссылок — никаких иных ссылок или URL.'
  );
  const requirementsHeading = 'Требования:';
  const returnBodyLine = 'Ответь только телом статьи.';
  const requirementsBlock = [requirementsHeading, ...requirementLines, returnBodyLine].join('\n');
  contentLines.push(requirementsBlock);

  const contentPrompt = contentLines.filter(Boolean).join('\n');
  const titlePrompt = buildTitlePrompt({
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
  const systemPrompts = buildSystemPrompts({ languageMeta });

  if (typeof logLine === 'function') {
    logLine('article.debug.prompt_overview', {
      titlePromptPreview: titlePrompt,
      contentPromptPreview: contentPrompt,
      systemPrompts,
    });
  }

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const rawTitle = await generateText(prompts.title, { ...aiOpts, systemPrompt: systemPrompts.title, keepRaw: true });
  await sleep(500);
  const rawContent = await generateText(prompts.content, { ...aiOpts, systemPrompt: systemPrompts.content, keepRaw: true });

  const titleClean = cleanLLMOutput(rawTitle).replace(/^\s*["'«»]+|["'«»]+\s*$/g, '').trim();
  let finalTitle = titleClean;
  if (hasProvidedAnchor && anchorValue && finalTitle) {
    const normalizedAnchor = normalizeAnchorInnerText(anchorValue);
    const normalizedTitle = normalizeAnchorInnerText(finalTitle);
    if (normalizedAnchor && normalizedTitle && normalizedTitle.includes(normalizedAnchor)) {
      const rewritePrompt = [
        `Исходный заголовок: "${finalTitle}"`,
        `Перепиши его ${languageInstruction}, сохранив смысл и длину 6–12 слов, но полностью исключив анкор "${anchorValue}" и любые прямые цитаты URL.`,
        'Не используй кавычки, эмодзи и точные названия ссылок. Ответь только новым заголовком.'
      ].join('\n');
      try {
        await sleep(250);
        const rewritten = await generateText(rewritePrompt, { ...aiOpts, systemPrompt: systemPrompts.title, keepRaw: true });
        const cleanRewritten = cleanLLMOutput(rewritten).replace(/^\s*["'«»]+|["'«»]+\s*$/g, '').trim();
        if (cleanRewritten) {
          const normalizedRewritten = normalizeAnchorInnerText(cleanRewritten);
          if (!normalizedAnchor || !normalizedRewritten.includes(normalizedAnchor)) {
            finalTitle = cleanRewritten;
          }
        }
      } catch (error) {
        if (typeof logLine === 'function') {
          logLine('article.debug.title_rewrite_failed', { error: String(error && error.message ? error.message : error) });
        }
      }
    }
  }

  let content = cleanLLMOutput(rawContent);

  // Add H1 title
  content = `<h1>${finalTitle}</h1>\n${content}`;

  let imageUrl = null;
  if (!disableImages) {
    // Generate image prompt
    const uniquenessToken = Math.random().toString(36).slice(2, 10);
    const imagePromptText = `Вот моя статья: ${htmlToPlainText(content)}\n\nСоставь промт для генерации изображения по теме этой статьи. Промт должен быть на английском языке, детальным и подходящим для Stable Diffusion. Обязательно подчеркни, что это реалистичная профессиональная фотография в формате 16:9 без текста, логотипов и водяных знаков, с натуральным светом и живой композицией. Добавь вариативность и уникальные детали, ориентируясь на код ${uniquenessToken}, но не выводи этот код текстом на изображении. Ответь только промтом.`;
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
      finalImagePrompt += `, inspired by concept ${uniquenessToken}`;
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
        requirements.push('- Добавь структурированный список (<ul>/<ol> с <li>), который подытоживает ключевые выводы.');
        content = cleanupGeneratedHtml(injectFallbackList(content));
      }
      if (!hasQuote) {
        requirements.push('- Вставь содержательный блок <blockquote> с аналитической мыслью или фактом по теме.');
      }
      const adjustPrompt =
        `Отредактируй HTML статьи, сохранив структуру, заголовок <h1>, все подзаголовки <h3> и ссылки.\n\n${requirements.join('\n')}\nНе добавляй новые URL, не удаляй существующие <a href> и их текст. Можно расширить существующие абзацы.\n\nВерни только готовый HTML.\n\nСТАТЬЯ:\n${content}`;
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
      content = cleanupGeneratedHtml(injectFallbackQuote(content, { anchorText }));
    } else {
      content = cleanupGeneratedHtml(content);
    }
    if (!/<(ul|ol)\b/i.test(content)) {
      content = cleanupGeneratedHtml(injectFallbackList(content));
    }
    if (!/<blockquote\b/i.test(content)) {
      content = cleanupGeneratedHtml(injectFallbackQuote(content, { anchorText }));
    }
    if (!/<(ul|ol)\b/i.test(content)) {
      content = cleanupGeneratedHtml(injectFallbackList(content));
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
  const listHtml = buildFallbackListHtml(content);
    const cleanedSection = sectionContent.replace(/^(?:\s|<p[^>]*>(?:\s|&nbsp;|<br[^>]*>)*<\/p>)+/gi, '');
    content = `${content.slice(0, headingEnd)}\n${listHtml}\n${cleanedSection}${content.slice(sectionEnd)}`;
    content = cleanupGeneratedHtml(content);
  };
  const wantOur = 2;
  const wantExternal = 1;
  const wantTotal = wantOur + wantExternal;
  const meetsLinkRequirements = (linkStat) => (
    linkStat &&
    linkStat.ourLinkCount >= wantOur &&
    linkStat.externalCount >= wantExternal &&
    linkStat.totalLinks === wantTotal
  );

  ensureKeyTakeawaysList();
  content = removeAnchorsFromHeadings(content, { targetUrl: pageUrl });
  let stat = analyzeLinks(content, pageUrl, anchorText);
  if (!meetsLinkRequirements(stat)) {
    const strictInstruction = isHigherLevel
      ? `СТРОГО: оставь ровно ${wantTotal} ссылки. Две ссылки ведут на ${pageUrl} с разными органичными анкорами на ${languageIn} (2–4 слова, без слов «тут» или «здесь»), третья — на авторитетный внешний источник. Другие ссылки не добавляй.`
      : (hasProvidedAnchor
        ? `СТРОГО: оставь ровно ${wantTotal} ссылки. В первой половине статьи поставь ссылку на ${pageUrl} с анкором ${anchorDisplay} без изменений. Во второй половине размести вторую ссылку на ${pageUrl} с другим органичным анкором на ${languageIn}. Третья ссылка — на авторитетный внешний источник (Wikipedia, официальные исследования и т.п.). Другие ссылки не добавляй.`
        : `СТРОГО: оставь ровно ${wantTotal} ссылки. Две ссылки ведут на ${pageUrl} с разными органичными анкорами на ${languageIn} (2–4 слова), третья — на авторитетный внешний источник. Другие ссылки не добавляй.`);
    const stricter = `${prompts.content}
${strictInstruction}`;
    const retry = await generateText(stricter, { ...aiOpts, systemPrompt: systemPrompts.content, keepRaw: true });
    const retryClean = cleanLLMOutput(retry);
    const retryStat = analyzeLinks(retryClean, pageUrl, anchorText);
    if (meetsLinkRequirements(retryStat) || (
      retryStat.ourLinkCount >= stat.ourLinkCount &&
      retryStat.externalCount >= stat.externalCount &&
      retryStat.totalLinks >= stat.totalLinks
    )) {
      content = retryClean;
      stat = retryStat;
    }
  }

  await ensureStructureWithFallback();
  ensureKeyTakeawaysList();
  content = removeAnchorsFromHeadings(content, { targetUrl: pageUrl });
  stat = analyzeLinks(content, pageUrl, anchorText);

  let linkFallbackApplied = false;
  let fallbackBeforeStats = null;
  if (!meetsLinkRequirements(stat)) {
    fallbackBeforeStats = { ...stat };
    const enforced = ensureLinksWithFallback(content, {
      pageUrl,
      anchorText: anchorValue,
      hasProvidedAnchor,
      languageMeta,
      wantOur,
      wantExternal,
      wantTotal,
      expectedAnchorText: anchorText,
    });
    if (enforced.modified) {
      linkFallbackApplied = true;
    }
    content = cleanupGeneratedHtml(enforced.html);
    stat = analyzeLinks(content, pageUrl, anchorText);
  }

  if (linkFallbackApplied && typeof logLine === 'function') {
    logLine('article.debug.link_fallback_applied', {
      before: fallbackBeforeStats,
      required: { wantOur, wantExternal, wantTotal },
      finalLinkStats: stat,
      targetUrl: pageUrl,
    });
  }

  let anchorAdjusted = false;
  if (hasProvidedAnchor && anchorValue) {
    const enforcedAnchor = enforceAnchorTextExpectations(content, {
      pageUrl,
      expectedAnchor: anchorValue,
      hasProvidedAnchor,
      languageMeta,
      desiredOurCount: wantOur,
    });
    if (enforcedAnchor.changed) {
      content = cleanupGeneratedHtml(enforcedAnchor.html);
      content = removeAnchorsFromHeadings(content, { targetUrl: pageUrl });
      stat = analyzeLinks(content, pageUrl, anchorText);
      anchorAdjusted = true;
    }
  }

  content = removeAnchorsFromHeadings(content, { targetUrl: pageUrl });
  stat = analyzeLinks(content, pageUrl, anchorText);
  const mergedAnchorsContent = mergeStandaloneAnchorParagraphs(content, { targetUrl: pageUrl });
  if (mergedAnchorsContent !== content) {
    content = cleanupGeneratedHtml(mergedAnchorsContent);
    stat = analyzeLinks(content, pageUrl, anchorText);
  }

  if (!meetsLinkRequirements(stat) && typeof logLine === 'function') {
    logLine('article.debug.link_requirement_unmet', {
      finalLinkStats: stat,
      required: { wantOur, wantExternal, wantTotal },
      targetUrl: pageUrl,
      anchorAdjusted,
    });
  }

  if (typeof logLine === 'function') {
    logLine('article.debug.output_links', {
      finalLinkStats: stat,
      hasProvidedAnchor,
      anchorUsed: hasProvidedAnchor ? anchorValue : null,
      isHigherLevel,
      targetUrl: pageUrl,
    });
  }

  const authorFromMeta = (pageMeta.author || '').toString().trim();
  let author = authorFromMeta;
  if (!author) {
    author = await generateAuthorName({ languageMeta, aiOpts, logLine });
  }

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
    title: finalTitle || topicTitle || anchorText,
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
