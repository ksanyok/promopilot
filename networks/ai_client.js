'use strict';

const fetch = require('node-fetch');

/** Debug / logging toggler: set PP_AI_DEBUG=1 to see verbose logs */
const DEBUG = process.env.PP_AI_DEBUG === '1';
const stamp = () => new Date().toISOString();
const log = (scope, payload) => {
  if (!DEBUG) return;
  try {
    const msg = typeof payload === 'string' ? payload : JSON.stringify(payload);
    console.log(`[${stamp()}] ${scope} ${msg}`);
  } catch {
    console.log(`[${stamp()}] ${scope}`, payload);
  }
};
const mask = (s) => {
  if (!s) return '';
  const t = String(s);
  return t.length <= 8 ? '****' : `${t.slice(0,4)}…${t.slice(-4)}`;
};

/** Gradio response normalizer: supports string | array | {response}|{data} */
function normalizeGradioResponse(raw) {
  try {
    if (raw == null) return '';
    if (typeof raw === 'string') return raw;
    if (Array.isArray(raw)) return normalizeGradioResponse(raw[0]);
    if (typeof raw === 'object') {
      if (Object.prototype.hasOwnProperty.call(raw, 'response')) return normalizeGradioResponse(raw.response);
      if (Object.prototype.hasOwnProperty.call(raw, 'data')) return normalizeGradioResponse(raw.data);
      return JSON.stringify(raw);
    }
    return String(raw);
  } catch {
    return String(raw);
  }
}

/**
 * Try to extract the final answer part from verbose model outputs
 * (handles patterns like "**💬 Response:**", "Response:", "Final Answer:")
 */
function extractFinalAnswer(text) {
  if (!text) return '';
  let t = String(text);

  // Unwrap code fences if the whole answer is inside ```
  t = t.trim().replace(/^```(?:\w+)?\s*/i, '').replace(/\s*```$/i, '').trim();

  // Common markers to split on (take the content after the last marker)
  const markers = [
    /(?:^|\n)\s*(?:\*\*\s*)?(?:💬\s*)?Response\s*:\s*/i,
    /(?:^|\n)\s*(?:Final\s*Answer)\s*:\s*/i,
    /(?:^|\n)\s*(?:Ответ)\s*:\s*/i
  ];

  let cutIndex = -1;
  for (const rx of markers) {
    const mAll = [...t.matchAll(rx)];
    if (mAll.length) {
      const m = mAll[mAll.length - 1];
      cutIndex = Math.max(cutIndex, m.index + m[0].length);
    }
  }
  if (cutIndex >= 0) t = t.slice(cutIndex);

  // Drop obvious analysis headers and separators if still present
  t = t.replace(/(?:^|\n)\s*\*\*\s*:?[\s]*🤔?\s*Analysis\s*\*\*:?[\s\S]*?(?=(?:^|\n)\s*(?:[-*_]{3,}|(?:\*\*\s*)?(?:💬\s*)?Response\s*:|$))/gi, '');
  t = t.replace(/^(?:[-*_]{3,}\s*)+/gm, '').trim();

  // Strip surrounding quotes if the result is a single quoted line
  if ((t.startsWith('"') && t.endsWith('"')) || (t.startsWith('“') && t.endsWith('”')) || (t.startsWith('«') && t.endsWith('»'))) {
    t = t.slice(1, -1).trim();
  }

  // Normalize line breaks: collapse excessive blank lines
  t = t.replace(/\n{3,}/g, '\n\n').trim();

  return t;
}

// OpenAI: простой вызов с ретраем без temperature при 400 unsupported_value
async function generateWithOpenAI(prompt, opts = {}) {
  const apiKey = String(opts.openaiApiKey || process.env.OPENAI_API_KEY || '').trim();
  if (!apiKey) throw new Error('OpenAI API key is missing');
  const model = String(opts.model || process.env.OPENAI_MODEL || 'gpt-3.5-turbo');
  const sys = (opts.systemPrompt || '').trim();
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : undefined;

  log('OPENAI:cfg', { model, temperature, sysLen: sys.length, promptLen: String(prompt||'').length });

  const buildBody = (withTemp) => ({
    model,
    messages: [ ...(sys ? [{ role:'system', content: sys }] : []), { role:'user', content: String(prompt||'') } ],
    ...(withTemp && typeof temperature === 'number' ? { temperature } : {})
  });

  async function callOpenAI(withTemp) {
    const body = buildBody(withTemp);
    log('OPENAI:req', { withTemp, model: body.model, messages: body.messages.map(m => ({ role: m.role, len: String(m.content||'').length })) });
    const res = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const text = await res.text().catch(()=> '');
    if (!res.ok) {
      let payload = null; try { payload = JSON.parse(text); } catch {}
      const err = new Error(`OpenAI error ${res.status}: ${res.statusText} ${text.slice(0,400)}`);
      err._payload = payload; err._status = res.status; throw err;
    }
    const data = JSON.parse(text);
    const out = String(data?.choices?.[0]?.message?.content || '');
    log('OPENAI:ok', { status: res.status, outLen: out.length });
    return out.trim();
  }

  try { return await callOpenAI(true); }
  catch (e) {
    const p = e && e._payload && e._payload.error;
    const msg = String(p?.message || '');
    const code = String(p?.code || '');
    if (e._status === 400 && (code === 'unsupported_value' || /Unsupported value/i.test(msg)) && /temperature/i.test(msg)) {
      // Повтор без temperature
      return await callOpenAI(false);
    }
    throw e;
  }
}

// BYOA через @gradio/client как в примере (space + '/chat')
async function generateWithBYOA(prompt, opts = {}) {
  const space = String(
    opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL ||
    opts.byoaModel  || process.env.PP_BYOA_MODEL  || 'amd/gpt-oss-120b-chatbot'
  ).trim();

  const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.7;

  const token = String(
    opts.byoaAuthToken ||
    process.env.PP_BYOA_TOKEN ||
    process.env.PP_BYOA_API_KEY ||
    process.env.HF_TOKEN ||
    process.env.HUGGINGFACEHUB_API_TOKEN ||
    ''
  ).trim();

  log('BYOA:cfg', {
    space,
    hasToken: Boolean(token),
    systemPromptLen: systemPrompt.length,
    promptLen: String(prompt || '').length,
    temperature
  });

  const { Client } = await import('@gradio/client');
  const connectOpts = token ? { hf_token: token } : undefined;

  log('BYOA:connect', { space, connectOpts: connectOpts ? { hf_token: mask(token) } : undefined });
  const app = await Client.connect(space, connectOpts);

  const basePayload = {
    message: String(prompt || ''),
    system_prompt: systemPrompt
  };
  const payloadWithTemp = { ...basePayload, temperature };

  async function runPredict(withTemp) {
    const body = withTemp ? payloadWithTemp : basePayload;
    log('BYOA:predict:req', { withTemp, messageLen: body.message.length, sysLen: body.system_prompt.length, hasTemp: Object.prototype.hasOwnProperty.call(body, 'temperature') });
    const res = await app.predict('/chat', body);
    log('BYOA:predict:raw', Array.isArray(res) ? { type: 'array', len: res.length } : { type: typeof res });
    const out = normalizeGradioResponse(res);
    const cleaned = extractFinalAnswer(out);
    log('BYOA:clean', { mode: 'predict', rawLen: out.length, cleanLen: cleaned.length, cleanPreview: cleaned.slice(0,120) });
    return cleaned.trim();
  }

  async function runStream(withTemp) {
    const body = withTemp ? payloadWithTemp : basePayload;
    log('BYOA:stream:req', { withTemp, messageLen: body.message.length, sysLen: body.system_prompt.length, hasTemp: Object.prototype.hasOwnProperty.call(body, 'temperature') });
    const stream = app.submit('/chat', body);

    let last = '';
    let seenData = false;
    const watchdogMs = Number(process.env.PP_BYOA_STREAM_TIMEOUT_MS || 30000);
    let watchdog;
    const resetWatch = () => {
      if (watchdog) clearTimeout(watchdog);
      watchdog = setTimeout(() => {
        log('BYOA:stream:watchdog', { timeoutMs: watchdogMs, seenData });
        try { stream.cancel && stream.cancel(); } catch {}
      }, watchdogMs);
    };
    resetWatch();

    try {
      for await (const ev of stream) {
        resetWatch();
        if (ev.type === 'data') {
          const out = Array.isArray(ev.data) ? ev.data[0] : (ev.data?.response ?? ev.data ?? '');
          const chunk = typeof out === 'string' ? out : JSON.stringify(out);
          seenData = true;
          last = chunk;
          log('BYOA:stream:data', { len: chunk.length });
        } else if (ev.type === 'status') {
          log('BYOA:stream:status', { stage: ev.stage });
        } else {
          log('BYOA:stream:event', { type: ev.type });
        }
      }
    } finally {
      if (watchdog) clearTimeout(watchdog);
    }

    const out = normalizeGradioResponse(last);
    const cleaned = extractFinalAnswer(out).trim();
    log('BYOA:stream:done', { seenData, rawLen: out.length, cleanLen: cleaned.length, cleanPreview: cleaned.slice(0, 120) });
    return cleaned;
  }

  // Strategy:
  // 1) try predict with temperature
  // 2) if temperature unsupported -> predict without temperature
  // 3) if empty -> try stream with temperature
  // 4) if still empty -> stream without temperature
  try {
    const text = await runPredict(true);
    if (text) return text;
    log('BYOA:fallback', 'predict empty -> stream with temp');
    const s1 = await runStream(true);
    if (s1) return s1;
    log('BYOA:fallback', 'stream with temp empty -> stream without temp');
    return await runStream(false);
  } catch (e) {
    const msg = String(e?.message || '');
    log('BYOA:error', { message: msg, stack: e?.stack });
    if (/temperat/i.test(msg) && /unsupported|invalid/i.test(msg)) {
      log('BYOA:fallback', 'temperature unsupported -> predict without temp');
      return await runPredict(false);
    }
    throw e;
  }
}

async function generateText(prompt, opts = {}) {
  const provider = (opts.provider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  return provider === 'byoa' ? generateWithBYOA(prompt, opts) : generateWithOpenAI(prompt, opts);
}

module.exports = { generateText, generateWithOpenAI, generateWithBYOA };
