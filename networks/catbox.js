'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { buildPdfFile, buildHtmlFile } = require('./lib/articleFiles');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'catbox',
  contentFormat: 'html',
  buildRequest: async ({ article, variants, job, logLine }) => {
    let file = null;
    try {
      file = await buildPdfFile({ article, variants, job });
      logLine('Catbox PDF generated', { filename: file.filename, size: file.buffer.length });
    } catch (error) {
      logLine('Catbox PDF generation failed', { error: String(error && error.message || error) });
      const fallback = buildHtmlFile({ article, variants, job });
      file = { ...fallback, filename: fallback.filename.replace(/\.html$/i, '.fallback.html') };
    }
    const formData = {
      reqtype: 'fileupload',
      fileToUpload: { value: file.buffer, options: { filename: file.filename, contentType: file.mime } }
    };
    const userHash = (job && job.meta && job.meta.catbox_userhash) || process.env.CATBOX_USERHASH || process.env.PP_CATBOX_USERHASH;
    if (userHash) {
      formData.userhash = String(userHash);
    }
    return {
      url: 'https://catbox.moe/user/api.php',
      method: 'POST',
      formData
    };
  },
  parseResponse: async ({ response, logLine }) => {
    const raw = await response.text();
    const text = raw.trim();
    if (typeof logLine === 'function') {
      logLine('Catbox response received', {
        status: response.status,
        body: text.length > 500 ? text.slice(0, 500) + '…' : text
      });
    }
    const match = text.match(/https?:\/\/\S+/);
    if (match && match[0]) {
      const cleanUrl = match[0].replace(/[)>\]]+$/, '');
      return { url: cleanUrl };
    }
    const errSnippet = text || `HTTP ${response.status} ${response.statusText || ''}`;
    throw new Error(errSnippet.trim() || 'CATBOX_EMPTY_RESPONSE');
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
