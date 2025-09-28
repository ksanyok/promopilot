'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'sprunge',
  contentFormat: 'text',
  buildRequest: async ({ body }) => ({
    url: 'http://sprunge.us',
    method: 'POST',
    formData: {
      sprunge: body
    }
  }),
  parseResponse: async ({ response }) => {
    const text = await response.text();
    const match = text.match(/https?:\/\/sprunge\.us\/[\w]+/i);
    return { url: match ? match[0] : text.trim() };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
