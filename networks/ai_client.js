'use strict';

// Unified AI client for all networks
// Provider options:
// - provider: 'openai' | 'byoa' (default 'openai')
// - openaiApiKey: string (required for openai)
// - model: string (OpenAI model, default 'gpt-3.5-turbo')
// - temperature: number
// - systemPrompt: string (optional, used if provider supports)
// - byoaModel: string (Gradio Space name like 'owner/space' or custom model id)
// - byoaBaseUrl: string (either Gradio base URL like 'https://owner-space.hf.space' OR your custom API base URL)
// - byoaEndpoint: string (endpoint path, default '/chat')
// - byoaMode: 'auto' | 'gradio' | 'json' (default 'auto')
// - byoaAuthToken: string (optional, used for Authorization: Bearer when byoaMode=json)

const fetch = require('node-fetch');

async function generateWithOpenAI(prompt, opts = {}) {
  const apiKey = String(opts.openaiApiKey || process.env.OPENAI_API_KEY || '').trim();
  if (!apiKey) {
    throw new Error('OpenAI API key is missing');
  }
  const model = String(opts.model || process.env.OPENAI_MODEL || 'gpt-3.5-turbo');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.8;
  const messages = [];
  const sys = (opts.systemPrompt || '').trim();
  if (sys) messages.push({ role: 'system', content: sys });
  messages.push({ role: 'user', content: String(prompt || '') });

  const res = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ model, messages, temperature })
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`OpenAI error ${res.status}: ${res.statusText} ${body.slice(0, 400)}`);
  }
  const data = await res.json().catch(() => null);
  const content = data && data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content || '';
  return String(content || '').trim();
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

// Generic response parser for custom JSON APIs and fallbacks
function parseGenericAIResponse(payload) {
  try {
    // Prefer explicit fields
    if (payload && typeof payload === 'object') {
      if (typeof payload.response === 'string') return payload.response;
      if (typeof payload.text === 'string') return payload.text;
      if (Array.isArray(payload.data) && typeof payload.data[0] === 'string') return payload.data[0];
      // OpenAI-like
      const ch = payload.choices && payload.choices[0];
      const msg = ch && ch.message && ch.message.content;
      if (typeof msg === 'string') return msg;
    }
    // If it was already a string
    if (typeof payload === 'string') return payload;
    return JSON.stringify(payload);
  } catch (_) {
    return String(payload ?? '');
  }
}

// Custom JSON API mode ("личное" API): POST JSON {message, system_prompt, temperature, model?}
async function generateWithCustomJSON(prompt, opts = {}) {
  const base = String(opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL || '').replace(/\/$/, '');
  if (!base) throw new Error('BYOA base URL is missing');
  let endpoint = String(opts.byoaEndpoint || process.env.PP_BYOA_ENDPOINT || '/chat');
  if (!endpoint.startsWith('/')) endpoint = '/' + endpoint;

  const url = base + endpoint;
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.7;
  const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const model = opts.byoaModel || process.env.PP_BYOA_MODEL || undefined;
  const token = String(opts.byoaAuthToken || process.env.PP_BYOA_TOKEN || process.env.PP_BYOA_API_KEY || '').trim();

  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const body = { message: String(prompt || ''), system_prompt: systemPrompt, temperature };
  if (model) body.model = model;

  const res = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body) });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`BYOA(JSON) error ${res.status}: ${res.statusText} ${text.slice(0, 400)}`);
  }
  const contentType = String(res.headers.get('content-type') || '').toLowerCase();
  let out;
  if (contentType.includes('application/json')) {
    out = await res.json().catch(() => ({}));
  } else {
    out = await res.text().catch(() => '');
  }
  const text = parseGenericAIResponse(out);
  return String(text || '').trim();
}

async function generateWithBYOA(prompt, opts = {}) {
  const baseResolved = opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL || opts.byoaModel || process.env.PP_BYOA_MODEL || 'amd/gpt-oss-120b-chatbot';
  const base = resolveGradioBaseUrl(baseResolved);
  if (!base) throw new Error('BYOA base URL/model is missing');
  let endpoint = String(opts.byoaEndpoint || process.env.PP_BYOA_ENDPOINT || '/chat');
  if (!endpoint.startsWith('/')) endpoint = '/' + endpoint;

  const mode = String(opts.byoaMode || process.env.PP_BYOA_MODE || 'auto').toLowerCase();
  const isGradio = mode === 'gradio' || (mode === 'auto' && /\.(hf|hf\.space|spaces)\b|\.hf\.space$/i.test(base));

  if (!isGradio) {
    // Use custom JSON API
    return generateWithCustomJSON(prompt, { ...opts, byoaBaseUrl: base, byoaEndpoint: endpoint });
  }

  const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.7;
  const payload = { message: String(prompt || ''), system_prompt: systemPrompt, temperature };

  // Discover parameter order to build the data array; fallback to [message, system_prompt, temperature]
  const paramNames = await discoverGradioParams(base, endpoint);
  let dataArray;
  if (paramNames.length) {
    const map = { message: payload.message, system_prompt: payload.system_prompt, temperature: payload.temperature };
    dataArray = paramNames.map(n => (n in map ? map[n] : null));
  } else {
    dataArray = [payload.message, payload.system_prompt, payload.temperature];
  }

  // POST to /api/predict{endpoint}
  const url = `${base}/api/predict${endpoint}`;
  const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ data: dataArray }) });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`BYOA(Gradio) error ${res.status}: ${res.statusText} ${body.slice(0, 400)}`);
  }
  const out = await res.json().catch(() => null);
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
