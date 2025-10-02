<?php
return [
    'slug' => 'jsfiddle',
    'title' => 'JSFiddle',
    'description' => 'JSFiddle — популярная песочница для HTML/CSS/JS. Сохраняет сниппеты без регистрации, выдаёт ссылку.',
    'handler' => __DIR__ . '/jsfiddle.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://jsfiddle.net',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'Education', 'Productivity', 'AI/ML', 'Web Development'
        ],
    ],
];
