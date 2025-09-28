'use strict';

const { generateText, cleanLLMOutput } = require('../ai_client');
const { htmlToPlainText } = require('./contentFormats');

async function generatePoll(job, logLine) {
  const pageUrl = job.pageUrl || '';
  const anchorText = job.anchorText || pageUrl;
  const language = job.language || 'ru';
  const meta = job.meta || {};
  const topicTitle = (meta.title || '').toString().trim();
  const topicDesc = (meta.description || '').toString().trim();
  const aiProvider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  const openaiApiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
  const wish = job.wish || '';

  const basePrompt = `Сформируй опрос на ${language}. Используй тему: ${topicTitle || anchorText}${topicDesc ? ' — ' + topicDesc : ''}.`;
  const prompt = `${basePrompt}\n` +
    `Нужно вернуть JSON строго такого вида: {"question":"...","options":["...","...","...","..."],"description":"..."}.\n` +
    `Требования:\n` +
    `- Вопрос 12-18 слов, без кавычек и без вопросительных слов в начале типа "Как" подряд.\n` +
    `- Ровно 4 варианта ответа, каждый 3-6 слов, без повторов анкора.\n` +
    `- Описание короткое (1-2 предложения), можно упомянуть ${anchorText}.\n` +
    `- Не используй символы \\" или \\n внутри значений.\n` +
    `- Не добавляй другие поля.`;

  const aiOpts = {
    provider: aiProvider,
    openaiApiKey,
    temperature: 0.4,
    keepRaw: true,
    systemPrompt: 'Ты ассистент, который генерирует опросы и возвращает JSON. Никакого пояснительного текста.'
  };

  const raw = await generateText(prompt, aiOpts);
  const cleaned = cleanLLMOutput(raw);
  logLine && logLine('Poll raw', { preview: String(cleaned || '').slice(0, 200) });

  let parsed = null;
  try {
    parsed = JSON.parse(cleaned);
  } catch (e) {
    try {
      const jsonMatch = cleaned.match(/\{[\s\S]*\}/);
      if (jsonMatch) {
        parsed = JSON.parse(jsonMatch[0]);
      }
    } catch (_) {}
  }

  if (!parsed || typeof parsed !== 'object') {
    const lines = cleaned.split(/\n+/).map((l) => l.trim()).filter(Boolean);
    const question = lines.shift() || `${anchorText}: что выберете?`;
    const options = lines.slice(0, 4);
    while (options.length < 4) {
      options.push(`Вариант ${options.length + 1}`);
    }
    parsed = { question, options: options.slice(0, 4), description: wish || topicDesc || '' };
  }

  parsed.question = String(parsed.question || '').trim() || `${anchorText}: ваш выбор?`;
  const options = Array.isArray(parsed.options) ? parsed.options : [];
  parsed.options = options.map((opt, idx) => {
    const text = String(opt || '').trim();
    return text || `Вариант ${idx + 1}`;
  }).slice(0, 4);
  while (parsed.options.length < 4) {
    parsed.options.push(`Вариант ${parsed.options.length + 1}`);
  }
  parsed.description = String(parsed.description || '').trim();

  const descriptionFallback = () => {
    const plain = htmlToPlainText(parsed.question);
    return wish || topicDesc || plain.slice(0, 240);
  };
  if (!parsed.description) {
    parsed.description = descriptionFallback();
  }

  return parsed;
}

module.exports = { generatePoll };
