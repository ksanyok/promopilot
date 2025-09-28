'use strict';

const { createTcpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'termbin',
  host: 'termbin.com',
  port: 9999,
  contentFormat: 'text',
  wrapPayload: (body) => `${body}\n`,
  parseResponse: async ({ raw }) => {
    const match = String(raw || '').match(/https?:\/\/termbin\.com\/[\w-]+/i);
    return { url: match ? match[0] : raw };
  }
};

const { publish } = createTcpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
