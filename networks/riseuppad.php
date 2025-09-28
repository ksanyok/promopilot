<?php
return [
    'slug' => 'riseuppad',
    'title' => 'Riseup Pad',
    'description' => 'Riseup Pad — Etherpad для совместного редактирования без регистрации.',
    'handler' => __DIR__ . '/riseuppad.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://pad.riseup.net',
        'regions' => [
            'Global', 'EU', 'US', 'CIS'
        ],
        'topics' => [
            'Collaboration', 'IT/Technology', 'Education', 'Activism', 'General'
        ],
    ],
];
