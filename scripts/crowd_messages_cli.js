'use strict';

const { generateText, cleanLLMOutput } = require('../networks/ai_client');

(async () => {
  try {
    const payloadRaw = process.env.PP_JOB || '{}';
    const payload = JSON.parse(payloadRaw);
    const provider = String(payload.provider || 'openai').toLowerCase();
    const systemPrompt = payload.systemPrompt ? String(payload.systemPrompt) : undefined;
    const model = payload.model ? String(payload.model) : undefined;
    const prompt = String(payload.prompt || '');
    const temperature = typeof payload.temperature === 'number' ? payload.temperature : 0.65;

    const raw = await generateText(prompt, {
      provider,
      openaiApiKey: payload.openaiApiKey || process.env.OPENAI_API_KEY || '',
      model: model || process.env.OPENAI_MODEL,
      temperature,
      systemPrompt,
      keepRaw: true,
    });

    const cleaned = cleanLLMOutput(raw || '');
    const textCandidate = cleaned && cleaned.trim() ? cleaned.trim() : String(raw || '').trim();

    let jsonSlice = textCandidate;
    const startIdx = textCandidate.indexOf('[');
    const endIdx = textCandidate.lastIndexOf(']');
    if (startIdx !== -1 && endIdx !== -1 && endIdx > startIdx) {
      jsonSlice = textCandidate.slice(startIdx, endIdx + 1);
    }

    let parsed = null;
    try {
      parsed = JSON.parse(jsonSlice);
    } catch (err) {
      const lines = textCandidate
        .split(/\n+/)
        .map((line) => line.trim())
        .filter(Boolean);
      if (lines.length > 0) {
        parsed = lines;
      }
    }

    const response = {
      ok: true,
      raw: raw,
      text: textCandidate,
      parsed,
    };
    process.stdout.write(JSON.stringify(response) + '\n');
    process.exit(0);
  } catch (error) {
    const message = error && error.message ? error.message : String(error || '');
    process.stdout.write(JSON.stringify({ ok: false, error: message }) + '\n');
    process.exit(1);
  }
})();
