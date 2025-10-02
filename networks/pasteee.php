<?php
return [
    'slug' => 'pasteee',
    'title' => 'Paste.ee',
    'description' => 'Paste.ee — сервис для форматированных текстов и кода с поддержкой Markdown и подсветки.',
    'handler' => __DIR__ . '/pasteee.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://paste.ee',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC', 'LATAM'
        ],
        'topics' => [
            'General', 'IT/Technology', 'Developers', 'Cybersecurity', 'Marketing/SEO',
            'Education', 'Productivity', 'Science'
        ],
    ],
];
