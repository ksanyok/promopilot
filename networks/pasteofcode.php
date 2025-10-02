<?php
return [
    'slug' => 'pasteofcode',
    'title' => 'paste.ofcode.org',
    'description' => 'Paste.ofcode.org — сервис для публикации кода с подсветкой.',
    'handler' => __DIR__ . '/pasteofcode.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://paste.ofcode.org',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'Education', 'Cybersecurity'
        ],
    ],
];
