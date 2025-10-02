<?php
return [
    'slug' => 'pastelink',
    'title' => 'Pastelink',
    'description' => 'Pastelink.net — публичные страницы с текстом и ссылками без регистрации.',
    'handler' => __DIR__ . '/pastelink.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://pastelink.net',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'LATAM', 'APAC'
        ],
        'topics' => [
            'General', 'Marketing/SEO', 'Education', 'Lifestyle', 'News', 'Finance'
        ],
    ],
];
