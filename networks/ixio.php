<?php
return [
    'slug' => 'ixio',
    'title' => 'ix.io',
    'description' => 'ix.io — короткие paste-ссылки, публикация через curl.',
    'handler' => __DIR__ . '/ixio.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://ix.io',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'DevOps', 'General'
        ],
    ],
];
