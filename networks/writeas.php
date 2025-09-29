<?php
return [
    'slug' => 'writeas',
    'title' => 'Write.as',
    'description' => 'Write.as/new — минималистичная платформа для мгновенной публикации Markdown-статей без регистрации.',
    'handler' => __DIR__ . '/writeas.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://write.as',
        'regions' => [
            'Global',
            'US', 'Canada', 'UK', 'EU', 'CIS', 'LATAM', 'APAC',
            'Australia', 'New Zealand', 'India', 'Singapore',
            'Germany', 'France', 'Spain', 'Italy', 'Poland', 'Ukraine'
        ],
        'topics' => [
            'General', 'Blogging', 'Tech/IT', 'AI/ML', 'Marketing/SEO', 'Business/Startups',
            'Productivity', 'Education', 'Lifestyle', 'Travel', 'Health & Wellness', 'Creative Writing', 'Finance'
        ],
    ],
];
