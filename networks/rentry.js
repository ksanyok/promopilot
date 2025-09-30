'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');
const { htmlToPlainText } = require('./lib/contentFormats');

const config = {
  slug: 'rentry',
  baseUrl: 'https://rentry.co/',
  contentFormat: 'markdown',
  waitForSelector: 'form textarea',
  titleSelectors: ['input#id_title', 'input[name="title"]'],
  contentSelectors: ['textarea#id_content', 'textarea[name="content"]'],
  submitSelectors: ['button[type="submit"]', 'button.btn-primary'],
  
  // Build a Rentry metadata block from job/article meta and prepend it to Markdown body
  async prepareBody({ article, variants, job, logLine }) {
    try {
      const meta = job && (job.meta || job.page_meta) ? (job.meta || job.page_meta) : {};
      const rentryMeta = (meta && typeof meta.rentry === 'object') ? meta.rentry : {};

      const lines = [];

      const title = (article && article.title) || meta.title || job.anchorText || '';
      const plain = (article && article.plainText) || htmlToPlainText(article && article.htmlContent || variants && variants.html || '');
      const clip = (str, n) => String(str || '').replace(/\s+/g, ' ').trim().slice(0, n);

      // Core preview fields
      if (title) lines.push(`PAGE_TITLE = ${title}`);
      const descr = clip(meta.description || (meta.desc) || (meta.summary) || plain, 240);
      if (descr) lines.push(`PAGE_DESCRIPTION = ${descr}`);

      const pageImage = rentryMeta.PAGE_IMAGE || meta.image || meta.ogImage || meta.preview_image || '';
      if (pageImage) lines.push(`PAGE_IMAGE = ${pageImage}`);
      const pageIcon = rentryMeta.PAGE_ICON || meta.icon || '';
      if (pageIcon) lines.push(`PAGE_ICON = ${pageIcon}`);

      const shareTitle = rentryMeta.SHARE_TITLE || meta.share_title || title;
      const shareDescription = rentryMeta.SHARE_DESCRIPTION || meta.share_description || descr;
      const shareImage = rentryMeta.SHARE_IMAGE || meta.share_image || pageImage;
      if (shareTitle) lines.push(`SHARE_TITLE = ${shareTitle}`);
      if (shareDescription) lines.push(`SHARE_DESCRIPTION = ${shareDescription}`);
      if (shareImage) lines.push(`SHARE_IMAGE = ${shareImage}`);

      // Options and access preferences
      const toBool = (v) => typeof v === 'string' ? /^(1|true|yes|on)$/i.test(v) : !!v;
      const optDisableViews = (rentryMeta.OPTION_DISABLE_VIEWS !== undefined) ? toBool(rentryMeta.OPTION_DISABLE_VIEWS) : toBool(meta.disable_views);
      const optNoIndex = (rentryMeta.OPTION_DISABLE_SEARCH_ENGINE !== undefined) ? toBool(rentryMeta.OPTION_DISABLE_SEARCH_ENGINE) : toBool(meta.noindex || meta.no_index);
      const optUseOrigDate = (rentryMeta.OPTION_USE_ORIGINAL_PUB_DATE !== undefined) ? toBool(rentryMeta.OPTION_USE_ORIGINAL_PUB_DATE) : toBool(meta.original_date || meta.pub_original_date);
      if (optDisableViews) lines.push(`OPTION_DISABLE_VIEWS = true`);
      if (optNoIndex) lines.push(`OPTION_DISABLE_SEARCH_ENGINE = true`);
      if (optUseOrigDate) lines.push(`OPTION_USE_ORIGINAL_PUB_DATE = true`);

      const theme = rentryMeta.ACCESS_RECOMMENDED_THEME || meta.theme || meta.color_scheme || '';
      if (theme) lines.push(`ACCESS_RECOMMENDED_THEME = ${String(theme).toLowerCase()}`);

      // Safety flags (optional)
      const pageFlag = rentryMeta.SAFETY_PAGE_FLAG || meta.page_flag || '';
      if (pageFlag) lines.push(`SAFETY_PAGE_FLAG = ${pageFlag}`);
      const warn = rentryMeta.SAFETY_PAGE_WARNING || meta.warning || '';
      const warnDesc = rentryMeta.SAFETY_PAGE_WARNING_DESCRIPTION || meta.warning_description || '';
      if (warn) lines.push(`SAFETY_PAGE_WARNING = ${warn}`);
      if (warnDesc) lines.push(`SAFETY_PAGE_WARNING_DESCRIPTION = ${warnDesc}`);

      // Secret verification (helps confirm authorship if ever needed)
      const secretVerify = rentryMeta.SECRET_VERIFY || meta.verify || '';
      if (secretVerify) lines.push(`SECRET_VERIFY = ${secretVerify}`);

      // Any raw passthrough pairs from rentryMeta that we didn't map explicitly
      // Accept keys in ALL_CAPS = value form
      Object.entries(rentryMeta).forEach(([k, v]) => {
        if (!k || v === undefined || v === null) return;
        const up = String(k).toUpperCase();
        const already = lines.some((ln) => ln.startsWith(up + ' ='));
        if (already) return;
        if (/^[A-Z0-9_]+$/.test(up)) {
          if (typeof v === 'boolean') lines.push(`${up} = ${v ? 'true' : 'false'}`);
          else if (typeof v === 'number') lines.push(`${up} = ${v}`);
          else {
            const sval = String(v).trim();
            if (sval) lines.push(`${up} = ${sval}`);
          }
        }
      });

      // If no metadata provided, return original markdown
      const markdown = (variants && variants.markdown) || '';
      if (!lines.length) return markdown;
      const header = lines.join('\n');
      const body = markdown || '';
      const composed = `${header}\n\n${body}`.trim();
      logLine('Rentry metadata prepared', { lines: lines.length });
      return composed;
    } catch (e) {
      if (typeof logLine === 'function') logLine('Rentry metadata error', { error: String(e && e.message || e) });
      return (variants && variants.markdown) || '';
    }
  },
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
