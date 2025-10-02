<?php
return [
    'slug' => 'hedgedoc',
    'title' => 'HedgeDoc Demo',
    'description' => 'Demo.hedgedoc.org — совместный Markdown-редактор с публичными ссылками.',
    'handler' => __DIR__ . '/hedgedoc.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://demo.hedgedoc.org',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Collaboration', 'Developers', 'IT/Technology', 'Education', 'Documentation'
        ],
    ],
];
