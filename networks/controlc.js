'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'controlc',
  baseUrl: 'https://controlc.com/',
  contentFormat: 'markdown',
  waitForSelector: 'form textarea',
  submitSelectors: ['button[type="submit"]', '#submit'],
  resultSelector: 'input#siteurl',
  titleSelectors: ['input#paste_title', 'input[name="title"]'],
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
