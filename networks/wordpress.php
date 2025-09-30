<?php
return [
    'slug' => 'wordpress',
    'title' => 'WordPress.com',
    'description' => 'Автоматическая регистрация аккаунта на WordPress.com, создание бесплатного сайта (*.wordpress.com) и публикация поста.',
    'handler' => __DIR__ . '/wordpress.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://wordpress.com',
        'regions' => ['Global'],
        'topics' => [
            'General', 'IT/Technology', 'Marketing/SEO', 'Education', 'Lifestyle', 'Finance', 'Productivity'
        ],
    ],
];
