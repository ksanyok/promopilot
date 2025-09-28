'use strict';

const { stripTags } = require('./contentFormats');

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function isElementVisible(handle) {
  if (!handle) return false;
  try {
    return await handle.evaluate((el) => {
      if (!el) return false;
      const style = window.getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      return (
        !!style &&
        style.display !== 'none' &&
        style.visibility !== 'hidden' &&
        rect.width > 2 &&
        rect.height > 2 &&
        rect.top < (window.innerHeight || 0) + 200
      );
    });
  } catch (_) {
    return false;
  }
}

async function findVisibleHandle(page, selectors) {
  const list = Array.isArray(selectors) ? selectors : [selectors];
  for (const sel of list) {
    let handles = [];
    try {
      handles = await page.$$(sel);
    } catch (_) {
      continue;
    }
    for (const handle of handles) {
      const visible = await isElementVisible(handle);
      if (visible) {
        return { handle, selector: sel };
      }
      try { await handle.dispose(); } catch (_) {}
    }
  }
  return null;
}

async function focusAndType(handle, value) {
  if (!handle) return false;
  const text = String(value || '');
  try { await handle.focus(); } catch (_) {}
  try { await handle.click({ clickCount: 3 }); } catch (_) {}
  try { await handle.press('Backspace'); } catch (_) {}
  try { await handle.evaluate((el) => { if ('value' in el) el.value = ''; }); } catch (_) {}
  let typed = false;
  try {
    await handle.type(text, { delay: 12 });
    typed = true;
  } catch (_) {
    typed = false;
  }
  if (!typed) {
    try {
      await handle.evaluate((el, val) => {
        if ('value' in el) {
          el.value = val;
          try {
            ['input', 'change', 'keyup', 'blur'].forEach((evt) => {
              try { el.dispatchEvent(new Event(evt, { bubbles: true })); } catch (_) {}
            });
          } catch (_) {}
        }
      }, text);
      typed = true;
    } catch (_) {
      // ignore
    }
  }
  return typed;
}

async function fillTitleField(page, title, hints = {}) {
  const text = String(title || '').trim();
  if (!text) return false;
  const candidates = [
    ...(hints.titleSelectors || []),
    'input[placeholder*="Title" i]',
    'input[placeholder*="Заголовок" i]',
    'input[name*="title" i]',
    'input[name*="subject" i]',
    'input[type="text"]',
    'textarea[name*="title" i]' // fallback
  ];
  const info = await findVisibleHandle(page, candidates);
  if (!info || !info.handle) return false;
  const success = await focusAndType(info.handle, text.slice(0, 160));
  try { await info.handle.dispose(); } catch (_) {}
  return success;
}

async function fillContentField(context, html, hints = {}) {
  if (!context) return false;
  const value = String(html || '').trim();
  const selectorsTextarea = [
    ...(hints.contentSelectors || []),
    'textarea[name*="content" i]',
    'textarea[name*="text" i]',
    'textarea[name*="body" i]',
    'textarea[name*="message" i]',
    'textarea[name*="description" i]',
    'textarea[name*="code" i]',
    'textarea',
  ];

  const autoFill = async (target) => {
    if (!target) return false;
    return await target.evaluate((el, val) => {
      const fire = (node) => {
        try {
          ['input', 'change', 'keyup', 'blur', 'paste'].forEach((evt) => {
            try { node.dispatchEvent(new Event(evt, { bubbles: true })); } catch (_) {}
          });
        } catch (_) {}
      };
      if ('value' in el) {
        el.focus();
        el.value = val;
        fire(el);
        return true;
      }
      if (el && typeof el.innerHTML !== 'undefined') {
        el.focus();
        el.innerHTML = val;
        fire(el);
        return true;
      }
      return false;
    }, value);
  };

  const info = await findVisibleHandle(context, selectorsTextarea);
  if (info && info.handle) {
    const ok = await autoFill(info.handle);
    try { await info.handle.dispose(); } catch (_) {}
    if (ok) return true;
  }

  // Contenteditable fallback
  const editableSelectors = [
    ...(hints.editableSelectors || []),
    '[contenteditable="true"]',
    '[contenteditable]'
  ];
  const editable = await findVisibleHandle(context, editableSelectors);
  if (editable && editable.handle) {
    const ok = await autoFill(editable.handle);
    try { await editable.handle.dispose(); } catch (_) {}
    if (ok) return true;
  }

  // Iframe fallback
  let frames = [];
  try {
    frames = context.frames ? context.frames() : [];
  } catch (_) {
    frames = [];
  }
  for (const frame of frames) {
    try {
      const ok = await fillContentField(frame, value, hints);
      if (ok) return true;
    } catch (_) {}
  }

  return false;
}

async function clickSubmit(page, hints = {}) {
  const selectors = [
    ...(hints.submitSelectors || []),
    'button[type="submit"]',
    'input[type="submit"]',
    'button[class*="submit" i]',
    'button[class*="create" i]',
    'button[class*="publish" i]',
    'button[class*="save" i]',
    'a[class*="submit" i]'
  ];
  const texts = ['publish', 'create', 'save', 'submit', 'post', 'send', 'generate'];

  const handles = [];
  for (const sel of selectors) {
    try {
      const found = await page.$$(sel);
      for (const handle of found) {
        const visible = await isElementVisible(handle);
        if (!visible) {
          try { await handle.dispose(); } catch (_) {}
          continue;
        }
        handles.push(handle);
      }
    } catch (_) {}
  }

  for (const handle of handles) {
    let text = '';
    try {
      text = await handle.evaluate((el) => (el.innerText || el.value || '').trim().toLowerCase());
    } catch (_) {
      text = '';
    }
    const matches = !text ? false : texts.some((t) => text.includes(t));
    try {
      await handle.click();
      await sleep(250);
    } catch (_) {
      continue;
    }
    if (matches) {
      handles.forEach((h) => { if (h !== handle) { try { h.dispose(); } catch (_) {} } });
      return true;
    }
  }

  return false;
}

async function waitForResult(page, startUrl, hints = {}) {
  const timeout = hints.resultTimeoutMs || 120000;
  const deadline = Date.now() + timeout;
  let lastUrl = '';

  while (Date.now() < deadline) {
    try {
      const current = page.url();
      if (current && current !== 'about:blank') {
        if (!startUrl || current !== startUrl) {
          return current;
        }
        lastUrl = current;
      }
    } catch (_) {}

    if (hints.resultSelector) {
      try {
        const handle = await page.$(hints.resultSelector);
        if (handle) {
          const val = await handle.evaluate((el) => (el.value || el.innerText || '').trim());
          if (val && /https?:\/\//i.test(val)) {
            try { await handle.dispose(); } catch (_) {}
            return val.trim();
          }
          try { await handle.dispose(); } catch (_) {}
        }
      } catch (_) {}
    }

    if (hints.resultEval) {
      try {
        const maybe = await page.evaluate(hints.resultEval);
        if (maybe && typeof maybe === 'string' && /https?:\/\//i.test(maybe)) {
          return maybe.trim();
        }
      } catch (_) {}
    }

    await sleep(500);
  }

  return lastUrl;
}

function formatForConsole(title, url) {
  const cleanTitle = stripTags(title || '').slice(0, 140);
  return `${cleanTitle} -> ${url}`;
}

module.exports = {
  findVisibleHandle,
  fillTitleField,
  fillContentField,
  clickSubmit,
  waitForResult,
  formatForConsole,
  sleep,
};
