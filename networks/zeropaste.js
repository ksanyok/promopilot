'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'zeropaste',
  baseUrl: 'https://0paste.com/',
  contentFormat: 'markdown',
  waitForSelector: 'textarea',
  titleSelectors: ['input[name="title"]', 'input[placeholder*="title" i]'],
  contentSelectors: ['textarea[name="content"]', 'textarea'],
  submitSelectors: ['button[type="submit"]', 'input[type="submit"]'],
  resultSelector: 'input[value*="http"], textarea',
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
