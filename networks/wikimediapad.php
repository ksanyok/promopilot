<?php
return [
    'slug' => 'wikimediapad',
    'title' => 'Wikimedia Etherpad',
    'description' => 'Etherpad от Wikimedia для совместного редактирования текста.',
    'handler' => __DIR__ . '/wikimediapad.js',
    'handler_type' => 'node',
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
