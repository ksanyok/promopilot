'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'ixio',
  contentFormat: 'text',
  buildRequest: async ({ body }) => ({
    url: 'https://ix.io',
    method: 'POST',
    formData: {
      'f:1': body
    }
  }),
  parseResponse: async ({ response }) => {
    const text = await response.text();
    const match = text.match(/https?:\/\/ix\.io\/[\w]+/i);
    return { url: match ? match[0] : text.trim() };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
