<?php
return [
    'slug' => 'privatebin',
    'title' => 'PrivateBin',
    'description' => 'PrivateBin — шифрованные пасты с шифрованием на стороне клиента. Подходит для безопасных публикаций ссылок.',
    'handler' => __DIR__ . '/privatebin.js',
    'handler_type' => 'node',
    'priority' => 59,
    'level' => '2,3',
    'meta' => [
        'url' => 'https://privatebin.net',
        'regions' => [
            'Global', 'US', 'EU', 'CIS', 'APAC'
        ],
        'topics' => [
            'IT/Technology', 'Cybersecurity', 'Developers', 'Crypto/Web3', 'General'
        ],
    ],
];
