'use strict';

const { htmlToPlainText } = require('./contentFormats');

function normalizeWhitespace(value) {
  if (value === null || value === undefined) {
    return '';
  }
  let text = String(value);
  if (/<[a-z][\s\S]*>/i.test(text)) {
    text = htmlToPlainText(text);
  }
  text = text.replace(/\s+/g, ' ').trim();
  return text;
}

function clipSample(sample) {
  if (!sample) return '';
  return sample.length > 500 ? sample.slice(0, 500) : sample;
}

function pickSample(candidates) {
  if (!Array.isArray(candidates) || candidates.length === 0) {
    return '';
  }
  const seen = new Set();
  const normalized = [];
  candidates.forEach((candidate) => {
    const norm = normalizeWhitespace(candidate);
    if (!norm) return;
    if (seen.has(norm)) return;
    seen.add(norm);
    normalized.push(norm);
  });
  if (normalized.length === 0) {
    return '';
  }
  const ideal = normalized.find((text) => text.length >= 200 && text.length <= 500);
  if (ideal) {
    return clipSample(ideal);
  }
  const medium = normalized.find((text) => text.length >= 120);
  if (medium) {
    return clipSample(medium);
  }
  if (normalized.length > 1) {
    const combined = normalizeWhitespace(normalized.join(' '));
    if (combined.length >= 120) {
      return clipSample(combined);
    }
  }
  return clipSample(normalized[0]);
}

function prepareTextSample(source) {
  if (!source) {
    return '';
  }
  const pieces = [];
  if (Array.isArray(source)) {
    source.forEach((item) => {
      if (!item) return;
      pieces.push(item);
    });
  } else {
    pieces.push(source);
  }
  return pickSample(pieces);
}

function createVerificationPayload({ pageUrl, anchorText, article, variants, extraTexts } = {}) {
  const pieces = [];
  if (article) {
    if (article.verificationSample) pieces.push(article.verificationSample);
    if (article.plainText) pieces.push(article.plainText);
    if (article.htmlContent) pieces.push(article.htmlContent);
  }
  if (variants) {
    ['plain', 'markdown', 'text', 'html'].forEach((key) => {
      if (variants[key]) {
        pieces.push(variants[key]);
      }
    });
  }
  if (extraTexts) {
    if (Array.isArray(extraTexts)) {
      const joined = extraTexts.filter(Boolean).join(' ');
      if (joined) pieces.push(joined);
      extraTexts.forEach((item) => {
        if (item) pieces.push(item);
      });
    } else {
      pieces.push(extraTexts);
    }
  }
  const sample = pickSample(pieces);
  const payload = {
    linkUrl: pageUrl ? String(pageUrl) : '',
    anchorText: anchorText ? String(anchorText) : '',
    supportsLinkCheck: !!pageUrl,
    supportsTextCheck: sample !== '',
  };
  if (sample !== '') {
    payload.textSample = sample;
  }
  return payload;
}

module.exports = { createVerificationPayload, prepareTextSample };
