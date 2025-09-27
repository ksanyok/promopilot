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

function parseGenericAIResponse(payload) {
  if (payload && typeof payload === 'object') {
    if (typeof payload.response === 'string') return payload.response;
    if (typeof payload.text === 'string') return payload.text;
    if (Array.isArray(payload.data) && typeof payload.data[0] === 'string') return payload.data[0];
    const ch = payload.choices && payload.choices[0];
    const msg = ch && ch.message && ch.message.content;
    if (typeof msg === 'string') return msg;
  }
  if (typeof payload === 'string') return payload;
  try { return JSON.stringify(payload); } catch { return String(payload ?? ''); }
}

// BYOA: прямой POST на {BASE}{ENDPOINT} с JSON как в примере (/chat)
async function generateWithBYOA(prompt, opts = {}) {
  const DEBUG = process.env.PP_AI_DEBUG === '1';
  const stamp = () => new Date().toISOString();
  const mask = (s) => { if (!s) return ''; const t = String(s); return t.length <= 8 ? '****' : `${t.slice(0,4)}…${t.slice(-4)}`; };
  const dlog = (scope, data) => { if (!DEBUG) return; try { console.log(`[${stamp()}] BYOA:${scope}`, typeof data==='string'?data:JSON.stringify(data)); } catch { console.log(`[${stamp()}] BYOA:${scope}`, data); } };

  let base = String(opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL || opts.byoaModel || process.env.PP_BYOA_MODEL || '').trim();
  if (!base) base = 'https://amd-gpt-oss-120b-chatbot.hf.space';
  if (!/^https?:\/\//i.test(base)) {
    base = base.includes('/') ? `https://${base.replace(/\//g,'-')}.hf.space` : `https://${base}.hf.space`;
  }
  base = base.replace(/\/$/, '');

  let endpoint = String(opts.byoaEndpoint || process.env.PP_BYOA_ENDPOINT || '/chat');
  if (!endpoint.startsWith('/')) endpoint = '/' + endpoint;
  const url = base + endpoint;

  const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : undefined;
  const model = opts.byoaModel || process.env.PP_BYOA_MODEL || undefined;
  const token = String(opts.byoaAuthToken || process.env.PP_BYOA_TOKEN || process.env.PP_BYOA_API_KEY || '').trim();

  const headers = { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) };
  const baseBody = { message: String(prompt||''), system_prompt: systemPrompt, ...(model ? { model } : {}) };
  dlog('cfg', { base, endpoint, url, hasToken: !!token, tokenPreview: mask(token), model: model || null });

  async function callDirect(withTemp) {
    const bodyObj = { ...baseBody, ...(withTemp && typeof temperature === 'number' ? { temperature } : {}) };
    const body = JSON.stringify(bodyObj);
    dlog('request', { withTemp, headers: { ...headers, Authorization: headers.Authorization ? `Bearer ${mask(token)}` : undefined }, bodyLens: { message: bodyObj.message.length, system_prompt: bodyObj.system_prompt.length } });
    const r = await fetch(url, { method: 'POST', headers, body });
    const ct = String(r.headers.get('content-type') || '').toLowerCase();
    const raw = await (ct.includes('application/json') ? r.json().catch(()=> ({})) : r.text().catch(()=> ''));
    if (!r.ok) {
      const text = typeof raw === 'string' ? raw : JSON.stringify(raw);
      dlog('resp_err', { status: r.status, ct, textSample: String(text).slice(0, 400) });
      const err = new Error(`BYOA ${r.status}: ${String(text).slice(0,400)}`);
      err._status = r.status; err._text = text; throw err;
    }
    dlog('resp_ok', { status: r.status, ct, kind: typeof raw, sample: (typeof raw==='string'?raw:String(raw)).slice(0,120) });
    return parseGenericAIResponse(raw);
  }

  try { const out = await callDirect(true); dlog('done', { outLen: String(out||'').length }); return String(out||'').trim(); }
  catch (e) {
    if (e._status === 400 && /temperat/i.test(String(e._text||'')) && /unsupported|invalid/i.test(String(e._text||''))) {
      dlog('retry_no_temp', {});
      const out = await callDirect(false); dlog('done_no_temp', { outLen: String(out||'').length }); return String(out||'').trim();
    }
    throw e;
  }
}

// Patch generateWithBYOA to add hf.space fallback on 404
// NOTE: Keep the function body minimal; reuse computed vars and add small fallback logic.
(function patchBYOA(){
  const orig = generateWithBYOA;
  generateWithBYOA = async function(prompt, opts = {}){
    const DEBUG = process.env.PP_AI_DEBUG === '1';
    const stamp = () => new Date().toISOString();
    const dlog = (scope, data) => { if (!DEBUG) return; try { console.log(`[${stamp()}] BYOA:${scope}`, typeof data==='string'?data:JSON.stringify(data)); } catch { console.log(`[${stamp()}] BYOA:${scope}`, data); } };

    // Recompute small subset we need to decide fallbacks
    let base = String(opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL || opts.byoaModel || process.env.PP_BYOA_MODEL || '').trim();
    if (!base) base = 'https://amd-gpt-oss-120b-chatbot.hf.space';
    if (!/^https?:\/\//i.test(base)) {
      base = base.includes('/') ? `https://${base.replace(/\//g,'-')}.hf.space` : `https://${base}.hf.space`;
    }
    base = base.replace(/\/$/, '');

    try {
      return await orig(prompt, opts);
    } catch (e) {
      const is404 = e && e._status === 404;
      const isHF = /\.hf\.space$/i.test(base);
      if (!(is404 && isHF)) throw e;

      // Try Gradio predict endpoints
      const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
      const temperature = typeof opts.temperature === 'number' ? opts.temperature : undefined;
      const dataArrWithTemp = [ String(prompt||''), systemPrompt, temperature ];
      const dataArrNoTemp   = [ String(prompt||''), systemPrompt ];

      const tryPredict = async (suffix, withTemp) => {
        const url = `${base}/api/predict${suffix}`;
        const payload = { data: withTemp ? dataArrWithTemp : dataArrNoTemp };
        dlog('fallback_req', { url, withTemp, payloadLen: { m: payload.data[0].length, s: payload.data[1].length } });
        const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const ct = String(r.headers.get('content-type') || '').toLowerCase();
        const raw = await (ct.includes('application/json') ? r.json().catch(()=> ({})) : r.text().catch(()=> ''));
        if (!r.ok) {
          const text = typeof raw === 'string' ? raw : JSON.stringify(raw);
          dlog('fallback_err', { status: r.status, url, text: String(text).slice(0,200) });
          const err = new Error(`BYOA(Gradio) ${r.status}: ${String(text).slice(0,200)}`);
          err._status = r.status; err._text = text; throw err;
        }
        dlog('fallback_ok', { url, status: r.status });
        const d = raw && raw.data;
        const out = Array.isArray(d) ? d[0] : (raw && (raw.response ?? raw) || '');
        return String(out || '').trim();
      };

      // Try /chat first, then root
      try {
        return await tryPredict('/chat', true);
      } catch (e1) {
        if (e1._status === 400 && /temperat/i.test(String(e1._text||'')) && /unsupported|invalid/i.test(String(e1._text||''))) {
          try { return await tryPredict('/chat', false); } catch (_) {}
        }
        try {
          return await tryPredict('', true);
        } catch (e2) {
          if (e2._status === 400 && /temperat/i.test(String(e2._text||'')) && /unsupported|invalid/i.test(String(e2._text||''))) {
            return await tryPredict('', false);
          }
          throw e; // fall back to original error
        }
      }
    }
  };
})();

async function generateText(prompt, opts = {}) {
  const provider = (opts.provider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  return provider === 'byoa' ? generateWithBYOA(prompt, opts) : generateWithOpenAI(prompt, opts);
}

module.exports = { generateText, generateWithOpenAI, generateWithBYOA };
