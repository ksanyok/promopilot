<?php
return [
    'slug' => 'pastebin',
    'title' => 'Pastebin',
    'description' => 'Классический сервис Pastebin.com для публикации текстовых заметок и сниппетов без регистрации.',
    'handler' => __DIR__ . '/pastebin.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://pastebin.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'General', 'News', 'IT/Technology', 'Developers', 'Cybersecurity',
            'Marketing/SEO', 'Education', 'Finance', 'Crypto/Web3'
        ],
    ],
];
