<?php
return [
    'slug' => 'sprunge',
    'title' => 'sprunge.us',
    'description' => 'sprunge.us — paste-сервис с простым curl-интерфейсом.',
    'handler' => __DIR__ . '/sprunge.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'http://sprunge.us',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'DevOps', 'General'
        ],
    ],
];
