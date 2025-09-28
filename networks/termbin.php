<?php
return [
    'slug' => 'termbin',
    'title' => 'termbin.com',
    'description' => 'Termbin — минималистичный paste-сервис через TCP (nc/curl).',
    'handler' => __DIR__ . '/termbin.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://termbin.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'DevOps', 'General'
        ],
    ],
];
