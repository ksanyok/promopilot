<?php
return [
    'slug' => 'strawpoll',
    'title' => 'StrawPoll',
    'description' => 'StrawPoll — создание опросов без регистрации.',
    'handler' => __DIR__ . '/strawpoll.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://strawpoll.com',
        'regions' => [
            'Global', 'US', 'EU', 'CIS'
        ],
        'topics' => [
            'Surveys', 'Marketing/SEO', 'Product Research', 'General'
        ],
    ],
];
