<?php
return [
    'slug' => 'rentry',
    'title' => 'Rentry.co',
    'description' => 'Markdown-страницы без регистрации на Rentry.co. Позволяет быстро публиковать статьи с форматированием и ссылками.',
    'handler' => __DIR__ . '/rentry.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://rentry.co',
        'regions' => [
            'Global',
            'US', 'UK', 'EU', 'CIS', 'LATAM', 'APAC'
        ],
        'topics' => [
            'General', 'IT/Technology', 'Developers', 'Marketing/SEO', 'Education',
            'Lifestyle', 'Finance', 'Productivity', 'Crypto/Web3', 'Science'
        ],
    ],
];
