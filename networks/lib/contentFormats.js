'use strict';

function stripTags(html) {
  return String(html || '')
    .replace(/\s+/g, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function convertAnchors(html, formatter) {
  return String(html || '').replace(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi, (match, href, inner) => {
    const text = stripTags(inner) || href;
    return formatter(text, href);
  });
}

function htmlToPlainText(html) {
  if (!html) return '';
  let out = String(html);
  out = out.replace(/<\/(?:p|div|h[1-6]|li)>/gi, '$&\n');
  out = convertAnchors(out, (text, href) => `${text}: ${href}`);
  out = out.replace(/<[^>]+>/g, ' ');
  out = out.replace(/\n{3,}/g, '\n\n');
  out = out.replace(/[ \t]+/g, ' ');
  out = out.replace(/ *(\n) */g, '$1');
  return out.trim();
}

function htmlToMarkdown(html) {
  if (!html) return '';
  let out = String(html);
  out = convertAnchors(out, (text, href) => `[${text}](${href})`);
  out = out.replace(/<h1[^>]*>([\s\S]*?)<\/h1>/gi, (m, inner) => `\n# ${stripTags(inner)}\n\n`);
  out = out.replace(/<h2[^>]*>([\s\S]*?)<\/h2>/gi, (m, inner) => `\n## ${stripTags(inner)}\n\n`);
  out = out.replace(/<h3[^>]*>([\s\S]*?)<\/h3>/gi, (m, inner) => `\n### ${stripTags(inner)}\n\n`);
  out = out.replace(/<li[^>]*>([\s\S]*?)<\/li>/gi, (m, inner) => `\n- ${stripTags(inner)}`);
  out = out.replace(/<\/(?:ul|ol)>/gi, '\n\n');
  out = out.replace(/<p[^>]*>([\s\S]*?)<\/p>/gi, (m, inner) => `\n${stripTags(inner)}\n`);
  out = out.replace(/<br\s*\/?\s*>/gi, '\n');
  out = out.replace(/<[^>]+>/g, '');
  out = out.replace(/\n{3,}/g, '\n\n');
  return out.trim();
}

module.exports = { htmlToPlainText, htmlToMarkdown, stripTags };
