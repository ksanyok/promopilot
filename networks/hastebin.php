<?php
return [
    'slug' => 'hastebin',
    'title' => 'Hastebin (Toptal)',
    'description' => 'Hastebin от Toptal — быстрые временные пасты по ссылке для обмена текстом и кодом.',
    'handler' => __DIR__ . '/hastebin.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://toptal.com/developers/hastebin',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'General', 'IT/Technology', 'Developers', 'Marketing/SEO', 'Education', 'Productivity'
        ],
    ],
];
