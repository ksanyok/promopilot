<?php
return [
    'slug' => 'controlc',
    'title' => 'ControlC',
    'description' => 'Площадка для анонимных текстовых паст и заметок ControlC.com. Поддерживает быстрые публикации с Markdown и ссылками.',
    'handler' => __DIR__ . '/controlc.js',
    'handler_type' => 'node',
    'priority' => 100,
    'level' => '1,2,3',
    'meta' => [
        'url' => 'https://controlc.com',
        'regions' => [
            'Global',
            'US', 'UK', 'EU', 'CIS', 'LATAM', 'APAC', 'MENA',
        ],
        'topics' => [
            'General', 'News', 'IT/Technology', 'Developers', 'Marketing/SEO',
            'Education', 'Lifestyle', 'Finance', 'Productivity', 'Crypto/Web3'
        ],
    ],
];
