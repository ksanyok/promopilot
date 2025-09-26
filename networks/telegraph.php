<?php
/**
 * Network: Telegraph Publication
 * Description: Автоматическая публикация материалов на https://telegra.ph/ через Puppeteer.
 */

return [
    'slug' => 'telegraph',
    'title' => 'Telegraph',
    'description' => 'Публикация статей на платформе Telegra.ph с генерацией контента через OpenAI.',
    'handler' => __DIR__ . '/telegraph.js',
    'handler_type' => 'node',
    'meta' => [
        'supports' => ['articles', 'longform'],
        'docs' => 'https://telegra.ph/',
        // New: structured classification
        'url' => 'https://telegra.ph',
        'regions' => ['Global'],
        'topics' => ['Blogging/Pages (micro-articles)'],
    ],
];

