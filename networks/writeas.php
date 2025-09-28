<?php
return [
    'slug' => 'writeas',
    'title' => 'Write.as Notes',
    'description' => 'Анонимные заметки на Write.as/notes без регистрации.',
    'handler' => __DIR__ . '/writeas.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://write.as/notes',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'LATAM', 'APAC'
        ],
        'topics' => [
            'Blogging', 'General', 'Marketing/SEO', 'Education', 'Lifestyle'
        ],
    ],
];
