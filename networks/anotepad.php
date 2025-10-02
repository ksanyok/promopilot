<?php
return [
    'slug' => 'anotepad',
    'title' => 'aNotepad',
    'description' => 'aNotepad — онлайн-блокнот для публичных заметок без регистрации.',
    'handler' => __DIR__ . '/anotepad.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://anotepad.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'LATAM', 'APAC'
        ],
        'topics' => [
            'General', 'Marketing/SEO', 'Education', 'Lifestyle', 'Productivity'
        ],
    ],
];
