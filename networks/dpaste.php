<?php
return [
    'slug' => 'dpaste',
    'title' => 'dpaste.org',
    'description' => 'dpaste.org — сервис с подсветкой синтаксиса и публичными ссылками для текстов и кода.',
    'handler' => __DIR__ . '/dpaste.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://dpaste.org',
        'regions' => [
            'Global', 'EU', 'US', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'Cybersecurity', 'General', 'Education'
        ],
    ],
];
