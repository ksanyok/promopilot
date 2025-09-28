'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');
const { Readable } = require('stream');

const config = {
  slug: 'zerox0',
  contentFormat: 'text',
  buildRequest: async ({ body }) => ({
    url: 'https://0x0.st',
    method: 'POST',
    formData: {
      file: {
        value: Readable.from([body]),
        options: {
          filename: 'content.txt',
          contentType: 'text/plain; charset=utf-8'
        }
      }
    }
  }),
  parseResponse: async ({ response }) => {
    const text = (await response.text()).trim();
    const match = text.match(/https?:\/\/0x0\.st\/[a-zA-Z0-9]+/);
    return { url: match ? match[0] : text };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
