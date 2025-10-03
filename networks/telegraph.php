<?php
/**
 * Network: Telegraph Publication
 * Description: Автоматическая публикация материалов на https://telegra.ph/ через Puppeteer.
 */

return [
    'slug' => 'telegraph',
    'title' => 'Telegraph',
    'description' => 'Глобальная площадка для публикации статей (Telegra.ph) с генерацией контента через OpenAI. Подходит для широкого круга тематик и регионов.',
    'handler' => __DIR__ . '/telegraph.js',
    'handler_type' => 'node',
    'priority' => 88,
    'level' => '1,2,3',
    'meta' => [
        'supports' => ['articles', 'longform'],
        'docs' => 'https://telegra.ph/',
        // New: structured classification (regions/topics) used for project matching
        'url' => 'https://telegra.ph',
        'regions' => [
            // Global coverage
            'Global',
            // Major geos and aggregates
            'US', 'UK', 'EU', 'CIS', 'MENA', 'APAC', 'LATAM',
            // Country-level highlights
            'RU', 'UA', 'BY', 'KZ', 'GE',
            'DE', 'PL', 'FR', 'ES', 'IT', 'TR',
            'CA', 'AU', 'NZ',
            'IN', 'SG', 'ID', 'MY', 'TH', 'VN', 'PH', 'JP', 'KR',
            'BR', 'MX', 'AR', 'CL', 'CO',
            'ZA', 'NG', 'EG', 'SA', 'AE',
        ],
        'topics' => [
            // General purpose
            'General',
            // News & media
            'News', 'Opinions',
            // Tech
            'IT/Technology', 'Developers', 'AI/ML', 'Cybersecurity', 'Open Source',
            // Business & marketing
            'Business/Startups', 'Marketing/SEO', 'eCommerce', 'Productivity',
            // Society & lifestyle
            'Education', 'Lifestyle', 'Travel', 'Culture',
            // Health & finance
            'Health', 'Wellness', 'Finance', 'Investing', 'Fintech',
            // Other
            'Crypto/Web3', 'Gaming', 'Science'
        ],
    ],
];

