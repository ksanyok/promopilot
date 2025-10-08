<?php
return [
    'slug' => 'rentry',
    'title' => 'Rentry Markdown',
    'description' => 'Markdown-платформа Rentry.co с поддержкой изображений, таблиц и расширенной разметки. Подходит для публикации сверстанных гайдов и лендингов.',
    'handler' => __DIR__ . '/rentry.js',
    'handler_type' => 'node',
    'priority' => 76,
    'level' => '1,2,3',
    'meta' => [
        'url' => 'https://rentry.co',
        'docs' => 'https://rentry.co/help',
        'supports' => ['markdown', 'images', 'longform', 'landing-pages'],
        'regions' => [
            'Global',
            'US', 'UK', 'EU', 'CIS', 'LATAM', 'APAC', 'MENA',
            'RU', 'UA', 'BY', 'KZ', 'GE',
            'DE', 'FR', 'ES', 'IT', 'PL', 'TR',
            'CA', 'AU', 'NZ',
            'IN', 'SG', 'ID', 'MY', 'TH', 'VN', 'PH', 'JP', 'KR',
            'BR', 'MX', 'AR', 'CL', 'CO',
            'ZA', 'NG', 'EG', 'SA', 'AE'
        ],
        'topics' => [
            'General', 'Marketing/SEO', 'Productivity', 'Education',
            'IT/Technology', 'Developers', 'AI/ML', 'Design/UI',
            'Business/Startups', 'Finance', 'Crypto/Web3',
            'Lifestyle', 'Travel', 'Culture', 'Gaming',
            'Health', 'Wellness', 'Science', 'News', 'Opinions'
        ],
    ],
];
