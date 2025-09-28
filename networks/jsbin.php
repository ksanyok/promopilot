<?php
return [
    'slug' => 'jsbin',
    'title' => 'JSBin',
    'description' => 'JSBin — песочница для HTML/CSS/JS, позволяет сохранять снапшоты без регистрации.',
    'handler' => __DIR__ . '/jsbin.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://jsbin.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'Education', 'Productivity', 'Web Development'
        ],
    ],
];
