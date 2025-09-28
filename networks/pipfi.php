<?php
return [
    'slug' => 'pipfi',
    'title' => 'p.ip.fi',
    'description' => 'p.ip.fi — короткие текстовые страницы через POST-запрос.',
    'handler' => __DIR__ . '/pipfi.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://p.ip.fi',
        'regions' => [
            'Global', 'EU', 'US', 'CIS'
        ],
        'topics' => [
            'Developers', 'IT/Technology', 'General', 'Documentation'
        ],
    ],
];
