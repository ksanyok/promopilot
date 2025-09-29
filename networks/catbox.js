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
    return {
      url: 'https://catbox.moe/user/api.php',
      method: 'POST',
      formData: {
        reqtype: 'fileupload',
        fileToUpload: { value: file.buffer, options: { filename: file.filename, contentType: file.mime } }
      }
    };
  },
  parseResponse: async ({ response }) => {
    const text = (await response.text()).trim();
    if (/^https?:\/\//i.test(text)) {
      return { url: text };
    }
    return { url: '' };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
