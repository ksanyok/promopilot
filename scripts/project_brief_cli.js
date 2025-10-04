const { generateText } = require('../networks/ai_client');

(async () => {
  try {
    const payload = JSON.parse(process.env.PP_JOB || '{}');
    const text = await generateText(payload.prompt || '', {
      provider: (payload.provider || 'openai').toLowerCase(),
      openaiApiKey: payload.openaiApiKey || process.env.OPENAI_API_KEY || '',
      model: payload.model || process.env.OPENAI_MODEL,
      temperature: typeof payload.temperature === 'number' ? payload.temperature : 0.3,
      systemPrompt: payload.systemPrompt || undefined,
      keepRaw: true,
    });
    process.stdout.write(JSON.stringify({ ok: true, text }) + '\n');
    process.exit(0);
  } catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, error: error && error.message ? error.message : String(error) }) + '\n');
    process.exit(1);
  }
})();
