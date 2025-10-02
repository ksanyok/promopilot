<?php
return [
    'slug' => 'transfersh',
    'title' => 'transfer.sh',
    'description' => 'transfer.sh — временный файлохостинг с загрузкой через HTTP без регистрации.',
    'handler' => __DIR__ . '/transfersh.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://transfer.sh',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'File Hosting', 'Developers', 'IT/Technology', 'DevOps', 'General'
        ],
    ],
];
