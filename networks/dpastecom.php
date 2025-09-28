<?php
return [
    'slug' => 'dpastecom',
    'title' => 'dpaste.com',
    'description' => 'dpaste.com — анонимные публичные пасты с поддержкой подсветки кода и Markdown.',
    'handler' => __DIR__ . '/dpastecom.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://dpaste.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'Cybersecurity', 'General', 'Education'
        ],
    ],
];
