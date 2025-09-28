<?php
return [
    'slug' => 'zerobin',
    'title' => '0bin.net',
    'description' => '0bin.net — анонимные зашифрованные пасты на базе PrivateBin/ZeroBin.',
    'handler' => __DIR__ . '/zerobin.js',
    'handler_type' => 'node',
    'meta' => [
        'url' => 'https://0bin.net',
        'regions' => [
            'Global', 'EU', 'US', 'CIS'
        ],
        'topics' => [
            'Cybersecurity', 'IT/Technology', 'Developers', 'Crypto/Web3', 'General'
        ],
    ],
];
