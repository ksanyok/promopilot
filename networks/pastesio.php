<?php
return [
    'slug' => 'pastesio',
    'title' => 'Pastes.io',
    'description' => 'Простой глобальный сервис Pastes.io для публичных текстовых паст с поддержкой Markdown.',
    'handler' => __DIR__ . '/pastesio.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://pastes.io',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'LATAM', 'APAC'
        ],
        'topics' => [
            'General', 'IT/Technology', 'Developers', 'Marketing/SEO',
            'Education', 'Productivity', 'Crypto/Web3', 'News'
        ],
    ],
];
