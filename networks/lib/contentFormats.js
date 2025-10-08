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
  const convertImage = (fragment) => {
    if (!fragment) return '';
    const srcMatch = fragment.match(/src=["']([^"']+)["']/i);
    const src = srcMatch && srcMatch[1] ? srcMatch[1].trim() : '';
    if (!src) return '';
    const altMatch = fragment.match(/alt=["']([^"']*)["']/i);
    let altText = altMatch && altMatch[1] ? altMatch[1].trim() : '';
    if (!altText) {
      const captionMatch = fragment.match(/<figcaption[^>]*>([\s\S]*?)<\/figcaption>/i);
      if (captionMatch && captionMatch[1]) {
        altText = stripTags(captionMatch[1]);
      }
    }
    const safeAlt = altText.replace(/[\[\]]/g, '').trim();
    return `\n\n![${safeAlt}](${src})\n\n`;
  };

  out = convertAnchors(out, (text, href) => `[${text}](${href})`);
  out = out.replace(/<figure[^>]*>[\s\S]*?<img[^>]*>[\s\S]*?<\/figure>/gi, (m) => convertImage(m));
  out = out.replace(/<img[^>]*>/gi, (m) => convertImage(m));
  out = out.replace(/<(strong|b)[^>]*>([\s\S]*?)<\/(strong|b)>/gi, (m, tag, inner) => `**${stripTags(inner)}**`);
  out = out.replace(/<(em|i)[^>]*>([\s\S]*?)<\/(em|i)>/gi, (m, tag, inner) => `*${stripTags(inner)}*`);
  out = out.replace(/<(del|s)[^>]*>([\s\S]*?)<\/(del|s)>/gi, (m, tag, inner) => `~~${stripTags(inner)}~~`);
  out = out.replace(/<h1[^>]*>([\s\S]*?)<\/h1>/gi, (m, inner) => `\n# ${stripTags(inner)}\n\n`);
  out = out.replace(/<h2[^>]*>([\s\S]*?)<\/h2>/gi, (m, inner) => `\n## ${stripTags(inner)}\n\n`);
  out = out.replace(/<h3[^>]*>([\s\S]*?)<\/h3>/gi, (m, inner) => `\n### ${stripTags(inner)}\n\n`);
  out = out.replace(/<h4[^>]*>([\s\S]*?)<\/h4>/gi, (m, inner) => `\n#### ${stripTags(inner)}\n\n`);
  out = out.replace(/<h5[^>]*>([\s\S]*?)<\/h5>/gi, (m, inner) => `\n##### ${stripTags(inner)}\n\n`);
  out = out.replace(/<h6[^>]*>([\s\S]*?)<\/h6>/gi, (m, inner) => `\n###### ${stripTags(inner)}\n\n`);
  out = out.replace(/<li[^>]*>([\s\S]*?)<\/li>/gi, (m, inner) => `\n- ${stripTags(inner)}`);
  out = out.replace(/<\/(?:ul|ol)>/gi, '\n\n');
  out = out.replace(/<blockquote[^>]*>([\s\S]*?)<\/blockquote>/gi, (m, inner) => {
    const text = stripTags(inner);
    if (!text) return '\n';
    const lines = text.split(/\n+/).map((line) => line.trim()).filter(Boolean);
    if (!lines.length) return '\n';
    return `\n> ${lines.join('\n> ')}\n\n`;
  });
  out = out.replace(/<p[^>]*>([\s\S]*?)<\/p>/gi, (m, inner) => `\n${stripTags(inner)}\n`);
  out = out.replace(/<br\s*\/?\s*>/gi, '\n');
  out = out.replace(/<[^>]+>/g, '');
  out = out.replace(/\n{3,}/g, '\n\n');
  return out.trim();
}

module.exports = { htmlToPlainText, htmlToMarkdown, stripTags };
