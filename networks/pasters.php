<?php
return [
    'slug' => 'pasters',
    'title' => 'paste.rs',
    'description' => 'paste.rs — paste-сервис с публикацией через curl и короткими ссылками.',
    'handler' => __DIR__ . '/pasters.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://paste.rs',
        'regions' => [
            'Global', 'EU', 'US', 'CIS'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'DevOps', 'General'
        ],
    ],
];
