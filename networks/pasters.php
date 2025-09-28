<?php
return [
    'slug' => 'pasters',
    'title' => 'paste.rs',
    'description' => 'paste.rs — paste-сервис с публикацией через curl и короткими ссылками.',
    'handler' => __DIR__ . '/pasters.js',
    'handler_type' => 'node',
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
