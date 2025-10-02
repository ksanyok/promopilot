<?php
return [
    'slug' => 'txties',
    'title' => 'txti.es',
    'description' => 'txti.es — сверхпростые веб-страницы с текстом и Markdown без регистрации.',
    'handler' => __DIR__ . '/txties.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://txti.es',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'LATAM'
        ],
        'topics' => [
            'General', 'Marketing/SEO', 'Education', 'Lifestyle', 'News'
        ],
    ],
];
