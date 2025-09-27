'use strict';

// Unified AI client for all networks
// Provider options:
// - provider: 'openai' | 'byoa' (default 'openai')
// - openaiApiKey: string (required for openai)
// - model: string (OpenAI model, default 'gpt-3.5-turbo')
// - temperature: number
// - systemPrompt: string (optional, used if provider supports)
// - byoaModel: string (Gradio Space name like 'owner/space', default from env PP_BYOA_MODEL or 'amd/gpt-oss-120b-chatbot')
// - byoaBaseUrl: string (explicit Gradio base URL like 'https://owner-space.hf.space')
// - byoaEndpoint: string (Gradio endpoint path, default '/chat')

const fetch = require('node-fetch');

async function generateWithOpenAI(prompt, opts = {}) {
  const apiKey = String(opts.openaiApiKey || process.env.OPENAI_API_KEY || '').trim();
  if (!apiKey) {
    throw new Error('OpenAI API key is missing');
  }
  const model = String(opts.model || process.env.OPENAI_MODEL || 'gpt-3.5-turbo');
  const messages = [];
  const sys = (opts.systemPrompt || '').trim();
  if (sys) messages.push({ role: 'system', content: sys });
  messages.push({ role: 'user', content: String(prompt || '') });

  const basePayload = { model, messages };
  // Only include sampling params if explicitly provided
  if (typeof opts.temperature === 'number') basePayload.temperature = opts.temperature;
  if (typeof opts.topP === 'number') basePayload.top_p = opts.topP;
  if (typeof opts.maxTokens === 'number') basePayload.max_tokens = opts.maxTokens;

  async function call(payload) {
    const res = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload)
    });
    const bodyText = await res.text().catch(() => '');
    if (!res.ok) {
      throw new Error(`OpenAI error ${res.status}: ${res.statusText} ${bodyText.slice(0, 400)}`);
    }
    try { return JSON.parse(bodyText); } catch { return null; }
  }

  try {
    const data = await call(basePayload);
    const content = data && data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content || '';
    return String(content || '').trim();
  } catch (err) {
    const msg = String(err && err.message || '');
    // Retry once without temperature/top_p if server complains about params
    const complainsSampling = /temperature|top_p|Unknown parameter|invalid .*parameter|not allowed/i.test(msg);
    if ((basePayload.temperature !== undefined || basePayload.top_p !== undefined) && complainsSampling) {
      const retryPayload = { ...basePayload };
      delete retryPayload.temperature;
      delete retryPayload.top_p;
      try {
        const data = await call(retryPayload);
        const content = data && data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content || '';
        return String(content || '').trim();
      } catch (_) {
        throw err; // original error
      }
    }
    throw err;
  }
}

// --- BYOA (Gradio Space) via plain HTTP, no extra libs ---
function resolveGradioBaseUrl(spaceOrUrl) {
  const s = String(spaceOrUrl || '').trim();
  if (!s) return '';
  if (/^https?:\/\//i.test(s)) {
    // Looks like a full URL already
    return s.replace(/\/$/, '');
  }
  // Expect format owner/space, convert to https://owner-space.hf.space
  if (s.includes('/')) {
    const base = `https://${s.replace(/\//g, '-')}.hf.space`;
    return base;
  }
  // Fallback: treat as already hyphenated subdomain
  return `https://${s}.hf.space`;
}

async function discoverGradioParams(baseUrl, endpoint) {
  // Try /info first (Gradio >=4), then /config (legacy)
  try {
    const r = await fetch(`${baseUrl}/info`, { headers: { 'Accept': 'application/json' } });
    if (r.ok) {
      const info = await r.json().catch(() => null);
      const named = info && (info.named_endpoints || info.endpoints || {});
      const ep = named && named[endpoint];
      if (ep && Array.isArray(ep.parameters)) {
        return ep.parameters.map(p => p.name).filter(Boolean);
      }
    }
  } catch (_) {}
  try {
    const r = await fetch(`${baseUrl}/config`, { headers: { 'Accept': 'application/json' } });
    if (r.ok) {
      const cfg = await r.json().catch(() => null);
      // Best-effort mapping: find dependency with route == endpoint and read inputs names
      const deps = (cfg && cfg.dependencies) || [];
      for (const d of deps) {
        if (!d || !Array.isArray(d.targets)) continue;
        const hasRoute = (d && (d.route || d.path || d.api_name)) === endpoint;
        if (!hasRoute) continue;
        const inputs = Array.isArray(d.inputs) ? d.inputs : [];
        return inputs.map(i => (i && (i.name || i.label || i.id)) || '').filter(Boolean);
      }
    }
  } catch (_) {}
  return [];
}

async function generateWithBYOA(prompt, opts = {}) {
  const base = resolveGradioBaseUrl(
    opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL || opts.byoaModel || process.env.PP_BYOA_MODEL || 'amd/gpt-oss-120b-chatbot'
  );
  if (!base) throw new Error('BYOA base URL/model is missing');
  let endpoint = String(opts.byoaEndpoint || process.env.PP_BYOA_ENDPOINT || '/chat');
  if (!endpoint.startsWith('/')) endpoint = '/' + endpoint;

  const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.7;
  const payload = { message: String(prompt || ''), system_prompt: systemPrompt, temperature };

  // Discover parameter order to build the data array; fallback to [message, system_prompt, temperature]
  const paramNames = await discoverGradioParams(base, endpoint);
  let dataArray;
  if (paramNames.length) {
    const map = {
      message: payload.message,
      system_prompt: payload.system_prompt,
      temperature: payload.temperature
    };
    dataArray = paramNames.map(n => (n in map ? map[n] : null));
  } else {
    dataArray = [payload.message, payload.system_prompt, payload.temperature];
  }

  // POST to /api/predict{endpoint}
  const url = `${base}/api/predict${endpoint}`;
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: dataArray })
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`BYOA error ${res.status}: ${res.statusText} ${body.slice(0, 400)}`);
  }
  const out = await res.json().catch(() => null);
  // Gradio returns { data: [...] }
  const d = out && out.data;
  const text = Array.isArray(d) ? d[0] : (d && (d.response ?? d) || out || '');
  return String(text || '').trim();
}

async function generateText(prompt, opts = {}) {
  const provider = (opts.provider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  if (provider === 'byoa') {
    return generateWithBYOA(prompt, opts);
  }
  // default to OpenAI
  return generateWithOpenAI(prompt, opts);
}

module.exports = { generateText, generateWithOpenAI, generateWithBYOA };
