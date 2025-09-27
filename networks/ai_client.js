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

// BYOA через @gradio/client как в примере (space + '/chat')
async function generateWithBYOA(prompt, opts = {}) {
  const space = String(
    opts.byoaBaseUrl || process.env.PP_BYOA_BASE_URL ||
    opts.byoaModel  || process.env.PP_BYOA_MODEL  || 'amd/gpt-oss-120b-chatbot'
  ).trim();

  const systemPrompt = String(opts.systemPrompt || process.env.PP_BYOA_SYSTEM_PROMPT || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.7;
  const { Client } = await import('@gradio/client');
  const app = await Client.connect(space);

  async function run(withTemp) {
    const payload = {
      message: String(prompt || ''),
      system_prompt: systemPrompt,
      ...(withTemp ? { temperature } : {})
    };
    const stream = app.submit('/chat', payload);
    let last = '';
    for await (const ev of stream) {
      if (ev.type === 'data') {
        const out = Array.isArray(ev.data) ? ev.data[0] : (ev.data?.response ?? ev.data ?? '');
        last = typeof out === 'string' ? out : JSON.stringify(out);
      }
    }
    return String(last || '').trim();
  }

  try { return await run(true); }
  catch (e) {
    if (/temperat/i.test(String(e?.message||''))) return await run(false);
    throw e;
  }
}

async function generateText(prompt, opts = {}) {
  const provider = (opts.provider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
  return provider === 'byoa' ? generateWithBYOA(prompt, opts) : generateWithOpenAI(prompt, opts);
}

module.exports = { generateText, generateWithOpenAI, generateWithBYOA };
