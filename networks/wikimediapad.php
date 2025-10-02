<?php
return [
    'slug' => 'wikimediapad',
    'title' => 'Wikimedia Etherpad',
    'description' => 'Etherpad от Wikimedia для совместного редактирования текста.',
    'handler' => __DIR__ . '/wikimediapad.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://etherpad.wikimedia.org',
        'regions' => [
            'Global', 'EU', 'US', 'CIS', 'APAC'
        ],
        'topics' => [
            'Collaboration', 'Education', 'IT/Technology', 'Open Knowledge', 'General'
        ],
    ],
];
