<?php
return [
    'slug' => 'pollie',
    'title' => 'Pollie',
    'description' => 'Pollie.app — лёгкое создание опросов без регистрации.',
    'handler' => __DIR__ . '/pollie.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://pollie.app',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'Surveys', 'Marketing/SEO', 'Product Research', 'General'
        ],
    ],
];
