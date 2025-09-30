<?php
/**
 * Network: mssg.me (Link-in-bio) – Account registration and later page publishing
 * Description: Automates signup and site creation on https://mssg.me/ via Puppeteer.
 * Notes: First iteration implements only registration (email+password). Further steps (create page, add blocks, publish) can be added next.
 */

return [
    'slug' => 'mssgme',
    'title' => 'mssg.me',
    'description' => 'Link-in-bio платформа. Автоматизация регистрации, в дальнейшем — создание страницы и публикация.',
    'handler' => __DIR__ . '/mssgme.js',
    'handler_type' => 'node',
    'enabled' => false,
    'meta' => [
        'supports' => ['bio', 'links', 'landing'],
        'docs' => 'https://www.mssg.me/',
        'url' => 'https://www.mssg.me/',
        'regions' => ['Global', 'UA', 'RU', 'CIS', 'EU', 'US'],
        'topics' => ['General', 'Social', 'Marketing/SEO', 'Personal', 'Creators'],
    ],
];
