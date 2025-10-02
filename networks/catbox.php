<?php
return [
    'slug' => 'catbox',
    'title' => 'Catbox',
    'description' => 'Catbox.moe — хостинг файлов и изображений без регистрации.',
    'handler' => __DIR__ . '/catbox.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://catbox.moe',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'File Hosting', 'Developers', 'IT/Technology', 'Media', 'General'
        ],
    ],
];
