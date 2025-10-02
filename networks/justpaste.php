<?php
/**
 * Network: JustPaste.it Publication
 * Description: Автоматическая публикация материалов на https://justpaste.it/ через Puppeteer, с поддержкой HTML-вкладки редактора.
 */

return [
    'slug' => 'justpaste',
    'title' => 'JustPaste.it',
    'description' => 'Глобальная площадка для публикации статей (JustPaste.it) с генерацией контента через OpenAI. Поддерживает вставку чистого HTML через вкладку Html.',
    'handler' => __DIR__ . '/justpaste.js',
    'handler_type' => 'node',
    'priority' => 10,
    'level' => '2,3',
    'meta' => [
        'supports' => ['articles', 'longform', 'html'],
        'docs' => 'https://justpaste.it/',
        'url' => 'https://justpaste.it',
        // Регионы: сервис глобальный, хорошо подходит для международных публикаций и СНГ/Европы
        'regions' => [
            'Global', 'US', 'UK', 'EU', 'CIS', 'APAC', 'LATAM', 'MENA',
            'RU', 'UA', 'BY', 'KZ', 'GE',
            'DE', 'PL', 'FR', 'ES', 'IT', 'TR',
            'CA', 'AU', 'NZ',
            'IN', 'SG', 'ID', 'MY', 'TH', 'VN', 'PH', 'JP', 'KR',
            'BR', 'MX', 'AR', 'CL', 'CO',
            'ZA', 'NG', 'EG', 'SA', 'AE',
        ],
        // Тематики: универсальная платформа — от общего контента до тех/бизнес тематик
        'topics' => [
            'General', 'News', 'Opinions',
            'IT/Technology', 'Developers', 'AI/ML', 'Cybersecurity', 'Open Source',
            'Business/Startups', 'Marketing/SEO', 'eCommerce', 'Productivity',
            'Education', 'Lifestyle', 'Travel', 'Culture',
            'Health', 'Wellness', 'Finance', 'Investing', 'Fintech',
            'Crypto/Web3', 'Gaming', 'Science'
        ],
    ],
];
