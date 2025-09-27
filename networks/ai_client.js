'use strict';

// Unified AI client for all networks
// Provider options:
// - provider: 'openai' | 'byoa' (default 'openai')
// - openaiApiKey: string (required for openai)
// - model: string (OpenAI model, default 'gpt-3.5-turbo')
// - temperature: number
// - systemPrompt: string (optional)
// - byoaBaseUrl: string (Hugging Face Space URL or owner/space)
// - byoaEndpoint: string (named endpoint, e.g. '/chat')

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
        throw err;
      }
    }
    throw err;
  }
}

// ============ BYOA (Hugging Face Spaces via plain HTTP) ============
function resolveSpaceBaseUrl(spaceOrUrl) {
  const s = String(spaceOrUrl || '').trim();
  if (!s) return '';
  if (/^https?:\/\//i.test(s)) return s.replace(/\/$/, '');
  if (s.includes('/')) return `https://${s.replace(/\//g, '-')}.hf.space`;
  return `https://${s}.hf.space`;
}

async function safeJson(res) {
  const t = await res.text().catch(() => '');
  try { return JSON.parse(t); } catch { return { __raw: t }; }
}

async function fetchJson(url) {
  const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
  if (!r.ok) return null;
  try { return await r.json(); } catch { return null; }
}

async function discoverSpec(baseUrl, endpoint) {
  const out = { paramNames: [], fn_index: null, predictUrl: null, tried: [] };
  const ep = endpoint.startsWith('/') ? endpoint : '/' + endpoint;
  // Try /info (new Gradio)
  try {
    const info = await fetchJson(`${baseUrl}/info`);
    if (info && (info.named_endpoints || info.endpoints)) {
      const named = info.named_endpoints || info.endpoints || {};
      let entry = named[ep] || named[ep.replace(/\/$/, '')] || null;
      if (!entry) {
        const key = Object.keys(named).find(k => (k === ep || k === ep.replace(/\/$/, '')));
        if (key) entry = named[key];
      }
      if (entry) {
        if (Array.isArray(entry.parameters)) {
          out.paramNames = entry.parameters.map(p => p.name).filter(Boolean);
        }
        if (entry.api_path) {
          out.predictUrl = `${baseUrl}${entry.api_path}`; // usually /api/predict/<hash or name>
        } else if (entry.path) {
          out.predictUrl = `${baseUrl}${entry.path}`; // may already include /api/predict
        } else {
          out.predictUrl = `${baseUrl}/api/predict${ep}`;
        }
        return out;
      }
    }
  } catch (_) {}

  // Try /config (legacy)
  try {
    const cfg = await fetchJson(`${baseUrl}/config`);
    const deps = (cfg && cfg.dependencies) || [];
    for (const d of deps) {
      const apiName = (d && (d.api_name || d.route || d.path)) || '';
      const match = apiName && (apiName === ep || apiName === ep.replace(/\/$/, ''));
      if (match) {
        if (Array.isArray(d.inputs)) {
          out.paramNames = d.inputs.map(i => (i && (i.name || i.label || i.id)) || '').filter(Boolean);
        }
        if (Number.isInteger(d.fn_index)) out.fn_index = d.fn_index;
        // When fn_index exists, use /api/predict without suffix
        out.predictUrl = `${baseUrl}/api/predict`;
        return out;
      }
    }
  } catch (_) {}

  // Fallback: try reasonable defaults
  out.predictUrl = `${baseUrl}/api/predict${ep}`;
  return out;
}

async function tryPostJson(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const data = await safeJson(res);
  if (!res.ok) {
    const piece = typeof data === 'string' ? data.slice(0, 300) : JSON.stringify(data).slice(0, 300);
    const err = new Error(`BYOA HTTP ${res.status} at ${url}: ${piece}`);
    err.status = res.status;
    err.url = url;
    err.body = body;
    throw err;
  }
  return data;
}

function buildDataArray(paramNames, payload) {
  if (Array.isArray(paramNames) && paramNames.length) {
    const map = {
      message: payload.message,
      system_prompt: payload.system_prompt,
      temperature: payload.temperature
    };
    return paramNames.map(n => (n in map ? map[n] : null));
  }
  return [payload.message, payload.system_prompt, payload.temperature];
}

async function generateWithBYOA(prompt, opts = {}) {
  const base = resolveSpaceBaseUrl(
    opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL || opts.byoaModel || process.env.PP_BYOA_MODEL || ''
  );
  if (!base) throw new Error('BYOA base URL/model is missing');
  const endpoint = String(opts.byoaEndpoint || process.env.PP_BYOA_ENDPOINT || '/chat');

  const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.7;
  const payload = { message: String(prompt || ''), system_prompt: systemPrompt, temperature };

  const spec = await discoverSpec(base, endpoint);
  const dataArray = buildDataArray(spec.paramNames, payload);

  // Ordered attempts without external libs
  const attempts = [];
  if (spec.fn_index !== null) {
    attempts.push({ url: `${base}/api/predict`, body: { data: dataArray, fn_index: spec.fn_index } });
  }
  if (spec.predictUrl) {
    attempts.push({ url: spec.predictUrl, body: { data: dataArray } });
  }
  const ep = endpoint.startsWith('/') ? endpoint : '/' + endpoint;
  const epNoSlash = ep.replace(/^\//, '');
  attempts.push({ url: `${base}/api/predict${ep}` , body: { data: dataArray } });
  attempts.push({ url: `${base}/api/predict/${epNoSlash}`, body: { data: dataArray } });
  attempts.push({ url: `${base}/run${ep}`, body: { data: dataArray } });
  attempts.push({ url: `${base}/run/${epNoSlash}`, body: { data: dataArray } });

  let lastErr = null;
  for (const att of attempts) {
    try {
      const out = await tryPostJson(att.url, att.body);
      const d = out && (out.data || out);
      const text = Array.isArray(d) ? d[0] : (d && (d.response ?? d.__raw ?? d) || '');
      const s = String(text || '').trim();
      if (s) return s;
      // If empty string, keep trying other paths
      lastErr = new Error(`BYOA empty response at ${att.url}`);
    } catch (e) {
      lastErr = e;
      // For 404/405/422 try next; for 500 also try next; otherwise keep trying
      continue;
    }
  }
  throw lastErr || new Error('BYOA: all attempts failed');
}

async function generateText(prompt, opts = {}) {
  const provider = (opts.provider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  if (provider === 'byoa') {
    return generateWithBYOA(prompt, opts);
  }
  return generateWithOpenAI(prompt, opts);
}

module.exports = { generateText, generateWithOpenAI, generateWithBYOA };
