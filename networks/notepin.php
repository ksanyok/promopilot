<?php
return [
    'slug' => 'notepin',
    'title' => 'Notepin',
    'description' => 'Notepin — простой онлайн‑редактор заметок и постов с мгновенной публикацией.',
    'handler' => __DIR__ . '/notepin.js',
    'handler_type' => 'node',
    'priority' => 63,
    'level' => '1,2,3',
    'meta' => [
        'supports' => ['articles', 'longform', 'markdown', 'html'],
        'docs' => 'https://notepin.co/write',
        'url' => 'https://notepin.co',
        'regions' => ['Global', 'US', 'EU', 'CIS', 'APAC'],
        'topics' => ['General', 'Blogging', 'IT/Technology', 'Education', 'Marketing/SEO'],
    ],
];
