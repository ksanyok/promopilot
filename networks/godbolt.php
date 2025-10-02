<?php
return [
    'slug' => 'godbolt',
    'title' => 'Compiler Explorer',
    'description' => 'Compiler Explorer (godbolt.org) — исследование ассемблера и шаринг кода.',
    'handler' => __DIR__ . '/godbolt.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://godbolt.org',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'Programming', 'Systems', 'Education'
        ],
    ],
];
