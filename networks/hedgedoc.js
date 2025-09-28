'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'hedgedoc',
  contentFormat: 'markdown',
  ensureContentType: 'text/markdown; charset=utf-8',
  buildRequest: async ({ body }) => ({
    url: 'https://demo.hedgedoc.org/new',
    method: 'POST',
    body
  }),
  parseResponse: async ({ response }) => {
    const location = response.headers.get('location');
    if (location) {
      return { url: location.startsWith('http') ? location : `https://demo.hedgedoc.org${location}` };
    }
    const text = await response.text();
    const match = text.match(/https?:\/\/demo\.hedgedoc\.org[^"'\s<>]+/);
    return { url: match ? match[0] : response.url };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
