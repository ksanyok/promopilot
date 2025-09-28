'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'pipfi',
  contentFormat: 'text',
  buildRequest: async ({ body }) => ({
    url: 'https://p.ip.fi',
    method: 'POST',
    body
  }),
  parseResponse: async ({ response }) => {
    const text = await response.text();
    const match = text.match(/https?:\/\/p\.ip\.fi\/[\w]+/i);
    return { url: match ? match[0] : text.trim() };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
