<?php
return [
    'slug' => 'wandbox',
    'title' => 'Wandbox',
    'description' => 'Wandbox.org — онлайн-компиляторы и шаринг ссылок на код.',
    'handler' => __DIR__ . '/wandbox.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://wandbox.org',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'Programming', 'Education', 'DevOps'
        ],
    ],
];
