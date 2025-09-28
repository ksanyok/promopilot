'use strict';

const { createHttpPublisher } = require('./lib/httpPublishers');
const { runCli } = require('./lib/genericPaste');

const config = {
  slug: 'txties',
  contentFormat: 'markdown',
  buildRequest: async ({ body, article }) => {
    const formData = {
      title: article.title,
      body,
      syntax: 'markdown'
    };
    return {
      url: 'https://txti.es',
      method: 'POST',
      formData
    };
  },
  parseResponse: async ({ response }) => {
    const text = await response.text();
    let url = response.url;
    const location = response.headers.get('location');
    if (location) {
      url = location.startsWith('http') ? location : `https://txti.es${location}`;
    }
    if (!url || /\/\s*$/.test(url) || url.includes('txti.es/')) {
      const match = text.match(/https?:\/\/txti\.es\/[a-zA-Z0-9\-_.]+/);
      if (match) {
        url = match[0];
      }
    }
    return { url };
  }
};

const { publish } = createHttpPublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
