'use strict';

const fetch = require('node-fetch');

// OpenAI: простой вызов с ретраем без temperature при 400 unsupported_value
async function generateWithOpenAI(prompt, opts = {}) {
  const apiKey = String(opts.openaiApiKey || process.env.OPENAI_API_KEY || '').trim();
  if (!apiKey) throw new Error('OpenAI API key is missing');
  const model = String(opts.model || process.env.OPENAI_MODEL || 'gpt-3.5-turbo');
  const sys = (opts.systemPrompt || '').trim();
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : undefined;

  const buildBody = (withTemp) => ({
    model,
    messages: [ ...(sys ? [{ role:'system', content: sys }] : []), { role:'user', content: String(prompt||'') } ],
    ...(withTemp && typeof temperature === 'number' ? { temperature } : {})
  });

  async function callOpenAI(withTemp) {
    const res = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
      body: JSON.stringify(buildBody(withTemp))
    });
    const text = await res.text().catch(()=> '');
    if (!res.ok) {
      let payload = null; try { payload = JSON.parse(text); } catch {}
      const err = new Error(`OpenAI error ${res.status}: ${res.statusText} ${text.slice(0,400)}`);
      err._payload = payload; err._status = res.status; throw err;
    }
    const data = JSON.parse(text);
    return String(data?.choices?.[0]?.message?.content || '').trim();
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

// Универсальный парсер ответа для кастомного API
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

// BYOA: прямой POST как в примере — URL: {BASE}/{ENDPOINT}, body: { message, system_prompt, temperature? }
async function generateWithBYOA(prompt, opts = {}) {
  let base = String(opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL || opts.byoaModel || process.env.PP_BYOA_MODEL || '').trim();
  if (!base) base = 'https://amd-gpt-oss-120b-chatbot.hf.space';
  if (!/^https?:\/\//i.test(base)) {
    // Разрешаем формат owner/space -> https://owner-space.hf.space
    base = base.includes('/') ? `https://${base.replace(/\//g,'-')}.hf.space` : `https://${base}.hf.space`;
  }
  base = base.replace(/\/$/, '');

  let endpoint = String(opts.byoaEndpoint || process.env.PP_BYOA_ENDPOINT || '/chat');
  if (!endpoint.startsWith('/')) endpoint = '/' + endpoint;
  const url = base + endpoint;

  const sys = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : undefined;
  const model = opts.byoaModel || process.env.PP_BYOA_MODEL || undefined;
  const token = String(opts.byoaAuthToken || process.env.PP_BYOA_TOKEN || process.env.PP_BYOA_API_KEY || '').trim();

  const headers = { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) };
  const baseBody = { message: String(prompt||''), system_prompt: sys, ...(model ? { model } : {}) };

  async function callDirect(withTemp) {
    const body = JSON.stringify({ ...baseBody, ...(withTemp && typeof temperature === 'number' ? { temperature } : {}) });
    const r = await fetch(url, { method: 'POST', headers, body });
    const ct = String(r.headers.get('content-type') || '').toLowerCase();
    const raw = await (ct.includes('application/json') ? r.json().catch(()=> ({})) : r.text().catch(()=> ''));
    if (!r.ok) {
      const text = typeof raw === 'string' ? raw : JSON.stringify(raw);
      const err = new Error(`BYOA ${r.status}: ${text.slice(0,400)}`);
      err._status = r.status; err._text = text; throw err;
    }
    return parseGenericAIResponse(raw);
  }

  try { return String((await callDirect(true)) || '').trim(); }
  catch (e) {
    // Фолбэк без temperature, если модель его не поддерживает
    if (e._status === 400 && /temperat/i.test(String(e._text||'')) && /unsupported|invalid/i.test(String(e._text||''))) {
      return String((await callDirect(false)) || '').trim();
    }
    throw e;
  }
}

async function generateText(prompt, opts = {}) {
  const provider = (opts.provider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  return provider === 'byoa' ? generateWithBYOA(prompt, opts) : generateWithOpenAI(prompt, opts);
}

module.exports = { generateText, generateWithOpenAI, generateWithBYOA };
