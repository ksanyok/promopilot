'use strict';

const fetch = require('node-fetch');

/** Debug / logging toggler: set PP_AI_DEBUG=1 to see verbose logs */
const DEBUG = process.env.PP_AI_DEBUG === '1';
const stamp = () => new Date().toISOString();
const log = (scope, payload) => { if (!DEBUG) return; try { console.log(`[${stamp()}] ${scope} ${typeof payload==='string'?payload:JSON.stringify(payload)}`); } catch { console.log(`[${stamp()}] ${scope}`, payload); } };

// --- Global minimal cleanup to drop "Analysis/Response" scaffolding ---
function _cutAfterResponseMarker(s) {
  const text = String(s || '');
  const regex = /(\n|^)\s*[\*\s>\-]*?(?:💬\s*)?(Response|Ответ)\s*:\s*/ig;
  let last = -1; let m;
  while ((m = regex.exec(text)) !== null) { last = m.index + m[0].length; }
  return last >= 0 ? text.slice(last) : text;
}
function _stripLeadingAnalysis(s) {
  let t = String(s || '');
  t = t.replace(/^\s*(?:\*\*|__)?\s*🤔?\s*(Analysis|Анализ)\s*:?(?:\*\*|__)?\s*\n+/i, '');
  // Drop one leading italic meta-instruction line like *We need to ...*
  t = t.replace(/^\s*\*[^\n]*\b(We need to|Нужно|Следует|Надо)\b[^\n]*\*\s*\n+/i, '');
  // Drop a leading horizontal rule
  t = t.replace(/^\s*[-*_]{3,}\s*\n+/, '');
  return t;
}
function cleanLLMOutput(s) {
  try {
    let t = String(s || '').replace(/\r\n/g, '\n');
    const after = _cutAfterResponseMarker(t);
    if (after !== t) t = after;
    t = _stripLeadingAnalysis(t);
    return t.trim();
  } catch { return String(s || ''); }
}

// --- OpenAI (оставляем как есть) ---
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

// --- BYOA «как в примере», серверная часть через @gradio/client (predict, без стрима) ---
async function generateWithBYOA(prompt, opts = {}) {
  const space = String(opts.byoaModel || 'amd/gpt-oss-120b-chatbot'); // без .env, дефолтный space
  const system_prompt = String(opts.systemPrompt || 'You are a helpful assistant.');
  const temperature = typeof opts.temperature === 'number' ? opts.temperature : 0.7;
  log('BYOA:space', { space, systemLen: system_prompt.length, promptLen: String(prompt||'').length, temperature });

  let Client;
  try {
    ({ Client } = await import('@gradio/client'));
    log('BYOA:pkg_ok', { module: '@gradio/client' });
  } catch (e) {
    log('BYOA:pkg_err', { error: String(e && e.message || e) });
    throw new Error('BYOA_PKG_MISSING: @gradio/client not found. Run: npm i @gradio/client');
  }

  const app = await Client.connect(space);

  const run = async (withTemp) => {
    const payload = { message: String(prompt||''), system_prompt, ...(withTemp ? { temperature } : {}) };
    log('BYOA:req', { withTemp, len: { m: payload.message.length, s: payload.system_prompt.length } });
    const resp = await app.predict('/chat', payload);
    const out = parseGenericAIResponse(resp);
    log('BYOA:ok', { outLen: String(out||'').length });
    return String(out||'').trim();
  };

  try {
    return await run(true);
  } catch (e) {
    const msg = String(e && e.message || '').toLowerCase();
    if (/temperat/.test(msg)) { log('BYOA:retry_no_temp', {}); return await run(false); }
    log('BYOA:err', { error: String(e && e.message || e) });
    throw e;
  } finally {
    // Try to explicitly close any persistent connections to avoid keeping the Node process alive
    try {
      if (app && typeof app.close === 'function') {
        await app.close();
      } else if (app && typeof app.disconnect === 'function') {
        await app.disconnect();
      } else if (app && app.client && typeof app.client.close === 'function') {
        await app.client.close();
      }
    } catch (_) { /* ignore */ }
  }
}

async function generateText(prompt, opts = {}) {
  const provider = (opts.provider || 'openai').toLowerCase();
  const raw = provider === 'byoa' ? await generateWithBYOA(prompt, opts) : await generateWithOpenAI(prompt, opts);
  if (opts && opts.keepRaw) { return raw; }
  const cleaned = cleanLLMOutput(raw);
  log('AI cleaned', {
    inLen: String(raw||'').length,
    outLen: String(cleaned||'').length,
    inPrev: String(raw||'').slice(0,160),
    outPrev: String(cleaned||'').slice(0,160)
  });
  return cleaned;
}

module.exports = { generateText, generateWithOpenAI, generateWithBYOA, cleanLLMOutput };
