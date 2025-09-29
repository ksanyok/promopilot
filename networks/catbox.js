'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { buildHtmlFile } = require('./lib/articleFiles');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'catbox',
  contentFormat: 'html',
  buildRequest: async ({ article, variants, job }) => {
    const file = buildHtmlFile({ article, variants, job });
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
