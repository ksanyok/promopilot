<?php
return [
    'slug' => 'paste2',
    'title' => 'Paste2.org',
    'description' => 'Paste2.org — публичные текстовые пасты и сниппеты.',
    'handler' => __DIR__ . '/paste2.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://paste2.org',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'General', 'Education'
        ],
    ],
];
