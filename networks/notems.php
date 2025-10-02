<?php
return [
    'slug' => 'notems',
    'title' => 'Note.ms',
    'description' => 'Note.ms — минималистичные публичные заметки по прямой ссылке.',
    'handler' => __DIR__ . '/notems.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://note.ms',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'General', 'Marketing/SEO', 'Education', 'Lifestyle', 'Productivity'
        ],
    ],
];
