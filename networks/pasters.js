'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'pasters',
  contentFormat: 'text',
  ensureContentType: 'text/plain; charset=utf-8',
  buildRequest: async ({ body }) => ({
    url: 'https://paste.rs',
    method: 'POST',
    body
  }),
  parseResponse: async ({ response }) => {
    const text = await response.text();
    const match = text.match(/https?:\/\/paste\.rs\/[\w]+/i);
    return { url: match ? match[0] : text.trim() };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
