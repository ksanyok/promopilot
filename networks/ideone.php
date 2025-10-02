<?php
return [
    'slug' => 'ideone',
    'title' => 'Ideone',
    'description' => 'Ideone.com — онлайн-компилятор и песочница для кода с публичными ссылками на результаты.',
    'handler' => __DIR__ . '/ideone.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://ideone.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'Education', 'Data Science', 'General'
        ],
    ],
];
