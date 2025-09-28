<?php
return [
    'slug' => 'shrib',
    'title' => 'Shrib',
    'description' => 'Shrib.com — онлайн-блокнот с автосохранением и публичными ссылками.',
    'handler' => __DIR__ . '/shrib.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://shrib.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'LATAM'
        ],
        'topics' => [
            'General', 'Education', 'Marketing/SEO', 'Lifestyle', 'Productivity'
        ],
    ],
];
