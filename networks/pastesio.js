'use strict';

const { createGenericPastePublisher, runCli } = require('./lib/genericPaste');

const config = {
  slug: 'pastesio',
  baseUrl: 'https://pastes.io/create',
  contentFormat: 'markdown',
  waitForSelector: 'form textarea',
  titleSelectors: ['input[name="title"]', 'input[placeholder*="Title" i]'],
  contentSelectors: ['textarea[name="content"]', 'textarea'],
  submitSelectors: ['button[type="submit"]', 'button.btn-primary'],
  resultSelector: 'input[href], input[value*="https"], textarea[value*="https"], textarea',
};

const { publish } = createGenericPastePublisher(config);

module.exports = { publish };

runCli(module, publish, config.slug);
