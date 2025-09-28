<?php
return [
    'slug' => 'rextester',
    'title' => 'Rextester',
    'description' => 'Rextester.com — онлайн-запуск кода и обмен ссылками на результаты.',
    'handler' => __DIR__ . '/rextester.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://rextester.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'Programming', 'Education', 'Data Science'
        ],
    ],
];
