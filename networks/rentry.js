'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'rentry',
  baseUrl: 'https://rentry.co/',
  contentFormat: 'markdown',
  waitForSelector: 'form textarea',
  titleSelectors: ['input#id_title', 'input[name="title"]'],
  contentSelectors: ['textarea#id_content', 'textarea[name="content"]'],
  submitSelectors: ['button[type="submit"]', 'button.btn-primary'],
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
