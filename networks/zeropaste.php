<?php
return [
    'slug' => 'zeropaste',
    'title' => '0paste.com',
    'description' => '0paste.com — минималистичный сервис для анонимных паст без регистрации.',
    'handler' => __DIR__ . '/zeropaste.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://0paste.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'General', 'IT/Technology', 'Marketing/SEO', 'Education', 'Developers'
        ],
    ],
];
