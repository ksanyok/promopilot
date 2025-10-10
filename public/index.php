<?php
$pp_container = true;
$pp_container_class = 'container-wide landing-container';

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/promotion/settings.php';

$promotionSettings = pp_promotion_settings();
$formatNumber = static function ($value): string {
  return number_format((int)$value, 0, '.', ' ');
};
$formatRange = static function ($min, $max) use ($formatNumber): string {
  return $formatNumber($min) . '–' . $formatNumber($max);
};

$level1Enabled = !empty($promotionSettings['level1_enabled']);
$level2Enabled = $level1Enabled && !empty($promotionSettings['level2_enabled']);
$level3Enabled = $level2Enabled && !empty($promotionSettings['level3_enabled']);

$level1Count = $level1Enabled ? max(1, (int)($promotionSettings['level1_count'] ?? 1)) : 0;
$level2Per = $level2Enabled ? max(1, (int)($promotionSettings['level2_per_level1'] ?? 1)) : 0;
$level3Per = $level3Enabled ? max(1, (int)($promotionSettings['level3_per_level2'] ?? 1)) : 0;

$level1Length = $level1Enabled ? $formatRange($promotionSettings['level1_min_len'] ?? 0, $promotionSettings['level1_max_len'] ?? 0) : '';
$level2Length = $level2Enabled ? $formatRange($promotionSettings['level2_min_len'] ?? 0, $promotionSettings['level2_max_len'] ?? 0) : '';
$level3Length = $level3Enabled ? $formatRange($promotionSettings['level3_min_len'] ?? 0, $promotionSettings['level3_max_len'] ?? 0) : '';

$crowdPerPublication = max(0, (int)($promotionSettings['crowd_per_article'] ?? 0));
$hasCrowd = !empty($promotionSettings['crowd_enabled']) && $crowdPerPublication > 0;
$crowdLabel = $hasCrowd
  ? sprintf(__('Crowd-слой: до %s проверенных площадок на публикацию'), $formatNumber($crowdPerPublication))
  : __('Crowd-слой подключаем точечно под нишу');

$levelImages = [
  1 => ['src' => 'img/level1.png', 'alt' => __('Уровень 1')],
  2 => ['src' => 'img/level2.png', 'alt' => __('Уровень 2')],
  3 => ['src' => 'img/level3.png', 'alt' => __('Уровень 3')],
];

$level1Total = $level1Count;
$level2Total = $level2Enabled ? ($level1Total * $level2Per) : 0;
$level3Total = $level3Enabled ? ($level2Total * $level3Per) : 0;

$levels = [];
$cumulative = 0;

if ($level1Enabled) {
  $cumulative += $level1Total;
  $levels[] = [
    'index' => 1,
    'title' => __('Уровень 1 — фундаментальные активы'),
    'description' => __('Web 2.0 хабы с прямыми ссылками, уникальным AI-контентом и выверенным анкор-листом.'),
    'count' => $level1Total,
    'cumulative' => $cumulative,
    'length' => $level1Length ? sprintf(__('Объём: %s знаков'), $level1Length) : __('Объём уточняется после брифа'),
    'per' => null,
    'perLabel' => '',
  ];
}

if ($level2Enabled) {
  $cumulative += $level2Total;
  $levels[] = [
    'index' => 2,
    'title' => __('Уровень 2 — тематические волны'),
    'description' => $hasCrowd
      ? __('Контентные статьи и первые крауд-цепочки связывают фундамент с нишевыми площадками и публикациями.')
      : __('Контентные статьи и подборки закрепляют фундаментальный уровень в тематических кластерах.'),
    'count' => $level2Total,
    'cumulative' => $cumulative,
    'length' => $level2Length ? sprintf(__('Объём: %s знаков'), $level2Length) : __('Объём подстраивается под нишу'),
    'per' => $level2Per,
    'perLabel' => __('публикаций на L1'),
  ];
}

if ($level3Enabled) {
  $cumulative += $level3Total;
  $levels[] = [
    'index' => 3,
    'title' => __('Уровень 3 — crowd-слой и сигналы'),
    'description' => $hasCrowd
      ? __('Форумы, Q&A, соцплощадки и каталоги создают сотни живых упоминаний, пинги и доводят сигнал до индексации.')
      : __('Широкий охват из новостных, соц и нишевых площадок усиливает предыдущие уровни и ускоряет индексацию.'),
    'count' => $level3Total,
    'cumulative' => $cumulative,
    'length' => $level3Length ? sprintf(__('Объём: %s знаков'), $level3Length) : __('Объём гибко распределяем по публикациям'),
    'per' => $level3Per,
    'perLabel' => __('сигналов на L2'),
  ];
}

$cascadeTotal = max(0, $level1Total + $level2Total + $level3Total);
$levelsCount = count($levels);

$pricePerLink = max(0.0, (float)($promotionSettings['price_per_link'] ?? 0.0));
$priceFormatted = $pricePerLink > 0 ? format_currency($pricePerLink) : null;
$pricePerPublication = ($cascadeTotal > 0 && $pricePerLink > 0) ? ($pricePerLink / $cascadeTotal) : null;

$schemeParts = [];
if ($level1Enabled) {
  $schemeParts[] = sprintf(__('L1: %s фундаментальных активов'), $formatNumber($level1Total));
}
if ($level2Enabled) {
  $level2Descriptor = $hasCrowd
    ? __('крауд-цепочек и тематических веток')
    : __('поддерживающих публикаций и тематических волн');
  $schemeParts[] = sprintf(__('L2: %s %s'), $formatNumber($level2Total), $level2Descriptor);
}
if ($level3Enabled) {
  $level3Descriptor = $hasCrowd ? __('crowd-сигналов, пингов и живых упоминаний') : __('охватных публикаций и сигналов');
  $schemeParts[] = sprintf(__('L3: %s %s'), $formatNumber($level3Total), $level3Descriptor);
}
$schemeSummary = $schemeParts ? implode(' → ', $schemeParts) : __('Схема будет зафиксирована после брифа.');
$crowdSummary = $hasCrowd
  ? sprintf(__('Crowd-слой: до %s живых касаний на публикацию с мониторингом'), $formatNumber($crowdPerPublication))
  : '';
$levelBreakdownParts = [];
if ($level1Enabled) {
  $levelBreakdownParts[] = sprintf(__('L1: %s'), $formatNumber($level1Total));
}
if ($level2Enabled) {
  $levelBreakdownParts[] = sprintf(__('L2: %s'), $formatNumber($level2Total));
}
if ($level3Enabled) {
  $levelBreakdownParts[] = sprintf(__('L3: %s'), $formatNumber($level3Total));
}
$levelBreakdown = $levelBreakdownParts ? implode(' • ', $levelBreakdownParts) : __('Конфигурация будет зафиксирована после брифа');
$heroLeadParts = [
  sprintf(__('Активная схема: %s.'), $schemeSummary),
  $crowdSummary ?: __('Crowd-слой подключаем, когда нише нужна дополнительная сигнализация.'),
  __('Управляем таймингом, анкорами и индексацией из одного дашборда.'),
];
$heroLead = trim(implode(' ', array_filter($heroLeadParts)));

$googleEnabled = get_setting('google_oauth_enabled', '0') === '1';
$googleStartUrl = $googleEnabled ? pp_url('public/google_oauth_start.php') : '';
$currencyCode = get_currency_code();

$testimonialQuote = __('PromoPilot позволяет запускать каскадные кампании за часы, а не недели. Мы контролируем анкор-листы, тайминги и индексацию, чтобы рост выглядел естественно.');
$testimonialAuthor = 'Александр Крикун';
$testimonialRole = __('Основатель PromoPilot, SEO-эксперт BuyReadySite');

$baseUrl = function_exists('pp_guess_base_url') ? pp_guess_base_url() : pp_url('');
$metaPriceAmount = $pricePerLink > 0 ? number_format($pricePerLink, 2, '.', '') : null;
$metaImage = asset_url('img/hero_main.jpg');
$pp_meta = [
  'title' => __('PromoPilot — линкбилдинг каскадом с живым crowd-слоем'),
  'description' => $heroLead,
  'url' => $baseUrl . '/',
  'image' => $metaImage,
  'site_name' => 'PromoPilot',
  'author' => $testimonialAuthor,
  'developer' => 'BuyReadySite',
  'price_amount' => $metaPriceAmount,
  'price_currency' => $currencyCode,
];

$levelCascadePathParts = [];
if ($level1Enabled) {
  $levelCascadePathParts[] = $formatNumber($level1Total);
}
if ($level2Enabled) {
  $levelCascadePathParts[] = $formatNumber($level2Total);
}
if ($level3Enabled) {
  $levelCascadePathParts[] = $formatNumber($level3Total);
}
$levelCascadePath = $levelCascadePathParts ? implode(' → ', $levelCascadePathParts) : __('Настраиваем каскад после брифа');

$metricChips = [
  [
    'label' => __('Общий объём каскада'),
    'value' => $cascadeTotal ? $formatNumber($cascadeTotal) : __('по запросу'),
    'hint' => __('публикаций во всех уровнях'),
  ],
  [
    'label' => __('Разбивка уровней'),
    'value' => $levelCascadePath,
    'hint' => $levelBreakdown,
  ],
  [
    'label' => __('Crowd-слой'),
    'value' => $hasCrowd ? sprintf(__('до %s касаний'), $formatNumber($crowdPerPublication)) : __('подключаем точечно'),
    'hint' => $hasCrowd ? __('живых упоминаний на публикацию') : __('включаем при необходимости'),
  ],
  [
    'label' => __('Тайминг каскада'),
    'value' => __('2–3 месяца'),
    'hint' => __('постепенные волны без пиков'),
  ],
];

if ($priceFormatted) {
  $metricChips[] = [
    'label' => __('Стоимость за ссылку'),
    'value' => $priceFormatted,
    'hint' => __('динамический расчёт по каскаду'),
  ];
}

if ($pricePerPublication) {
  $metricChips[] = [
    'label' => __('Средняя цена публикации'),
    'value' => number_format($pricePerPublication, 2, '.', ' ') . ' ' . $currencyCode,
    'hint' => __('ориентир по текущей конфигурации'),
  ];
}

$comparisonCards = [
  'single' => [
    'title' => __('Одиночная покупка ссылок'),
    'subtitle' => __('Риск и отсутствие контроля'),
    'points' => [
      __('Один домен передаёт риск напрямую на целевой проект.'),
      __('Сложно управлять анкорами и скоростью появления ссылок.'),
      __('Нет прозрачных отчётов по индексации и статусам.'),
    ],
  ],
  'network' => [
    'title' => __('Классический линкбилдинг'),
    'subtitle' => __('Вручную, медленно, без живых сигналов'),
    'points' => [
      __('Нужны собственные PBN/сетки и команда модераторов.'),
      __('Crowd-поддержка и пинги подключаются эпизодически.'),
      __('Любые ошибки в тайминге выглядят как всплески.'),
    ],
  ],
  'cascade' => [
    'title' => __('Каскад PromoPilot'),
    'subtitle' => __('Многоуровневая защита и автоматизация'),
    'points' => [
      sprintf(__('Стартуем с %s L1-активов и усиливаем уровни 5 → 10 → 100.'), $formatNumber($level1Total ?: 5)),
      __('Crowd-слой снимает риски, сигналы идут через промежуточные площадки.'),
      __('Отчёты, индексация, API и корректировки живут в одном кабинете.'),
    ],
  ],
];

$safetyPoints = [
  [
    'icon' => 'shield-lock',
    'title' => __('Разделяем риски по уровням'),
    'text' => __('Целевая страница получает вес через L1, а эксперименты остаются на L2–L3.'),
  ],
  [
    'icon' => 'activity',
    'title' => __('Контролируем темп и сигналы'),
    'text' => __('Автоматический таймлайн растягивает публикации на 2–3 месяца и пингует только нужные URL.'),
  ],
  [
    'icon' => 'people',
    'title' => __('Crowd с ручной валидацией'),
    'text' => __('Аккаунты проходят проверку, ответы модерируются, спам и дубли отсекаются.'),
  ],
  [
    'icon' => 'card-checklist',
    'title' => __('Отчёты по каждому уровню'),
    'text' => __('Видите статусы публикаций, анкоры и индексацию без ожидания сводной таблицы.'),
  ],
];

$processSteps = [
  [
    'title' => __('Бриф и настройка каскада'),
    'description' => __('Получаем цели, анкоры, географию. Фиксируем требования к площадкам и crowd-слою.'),
  ],
  [
    'title' => __('Производство и публикации'),
    'description' => __('AI готовит тексты и визуалы, редакторы проверяют, PromoPilot разворачивает 5 → 10 → 100.'),
  ],
  [
    'title' => __('Мониторинг и адаптация'),
    'description' => __('Пингуем, проверяем индексацию, докручиваем crowd и обновляем отчёты в реальном времени.'),
  ],
];

$faqItems = [
  [
    'question' => __('Можно ли изменить пропорции уровней?'),
    'answer' => __('Да. Настройте количество публикаций, длину и наличие crowd-слоя в админке — лендинг и схемы обновятся автоматически.'),
  ],
  [
    'question' => __('Как обеспечивается безопасность каскада?'),
    'answer' => __('Риск разделён по уровням. Целевая ссылка живёт на L1, а L2–L3 создают фон и сигналы. Тайминг управляется автоматически.'),
  ],
  [
    'question' => __('Что входит в отчёты?'),
    'answer' => __('Для каждого уровня вы видите статусы, индексацию, анкоры, пинги и crowd-касания. Доступен экспорт и API.'),
  ],
  [
    'question' => __('Сколько занимает запуск?'),
    'answer' => __('После брифа мы за 48 часов разворачиваем каскад и запускаем первые публикации. Полный цикл занимает 2–3 месяца.'),
  ],
];

$organizationSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'Organization',
  '@id' => $baseUrl . '/#organization',
  'name' => 'PromoPilot',
  'url' => $baseUrl,
  'logo' => asset_url('img/logo.svg'),
  'sameAs' => [
    'https://buyreadysite.com/',
    'https://t.me/buyreadysite',
  ],
  'founder' => [
    '@type' => 'Person',
    'name' => $testimonialAuthor,
    'jobTitle' => 'Founder',
    'image' => asset_url('img/photo.jpeg'),
  ],
  'contactPoint' => [
    [
      '@type' => 'ContactPoint',
      'contactType' => 'customer support',
      'email' => 'team@buyreadysite.com',
      'availableLanguage' => ['ru', 'en'],
      'areaServed' => ['RU', 'UA', 'KZ', 'EU'],
    ],
  ],
];

$serviceOffer = [
  '@type' => 'Offer',
  'availability' => 'https://schema.org/InStock',
  'url' => $baseUrl . '/',
  'priceCurrency' => $currencyCode,
  'eligibleQuantity' => [
    '@type' => 'QuantitativeValue',
    'value' => $cascadeTotal,
    'unitCode' => 'PUB',
    'description' => sprintf(__('%s публикаций в %s уровнях каскада'), $formatNumber($cascadeTotal), $levelsCount),
  ],
  'category' => 'SEO Services',
];
if ($pricePerLink > 0) {
  $serviceOffer['price'] = number_format($pricePerLink, 2, '.', '');
}

$serviceSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'Service',
  '@id' => $baseUrl . '/#service',
  'name' => __('Каскадное продвижение PromoPilot'),
  'serviceType' => 'LinkBuildingService',
  'provider' => [
    '@type' => 'Organization',
    '@id' => $baseUrl . '/#organization',
    'name' => 'PromoPilot',
    'url' => $baseUrl,
  ],
  'areaServed' => ['European Union', 'United States', 'CIS'],
  'description' => trim(sprintf(__('Активная схема: %s.'), $schemeSummary) . ' ' . ($crowdSummary ?: __('Crowd-слой включается по запросу.'))),
  'offers' => $serviceOffer,
  'review' => [
    '@type' => 'Review',
    'author' => [
      '@type' => 'Person',
      'name' => $testimonialAuthor,
    ],
    'reviewBody' => $testimonialQuote,
    'reviewRating' => [
      '@type' => 'Rating',
      'ratingValue' => '5',
      'bestRating' => '5',
      'worstRating' => '4',
    ],
  ],
];

$linkSeriesParts = [];
foreach ($levels as $level) {
  $linkSeriesParts[] = [
    '@type' => 'CreativeWork',
    'name' => sprintf('L%s', $level['index']),
    'position' => (int)$level['index'],
    'description' => $level['description'],
    'size' => (int)$level['count'],
  ];
}

$creativeSeriesSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'CreativeWorkSeries',
  '@id' => $baseUrl . '/#creativeSeries',
  'name' => __('Каскад линкбилдинга PromoPilot'),
  'description' => trim($schemeSummary . ($crowdSummary ? ' • ' . $crowdSummary : '')),
  'creator' => [
    '@type' => 'Organization',
    'name' => 'PromoPilot',
    'url' => $baseUrl,
  ],
  'hasPart' => $linkSeriesParts,
  'keywords' => ['link building', 'crowd marketing', 'PromoPilot'],
];
if ($crowdSummary) {
  $creativeSeriesSchema['additionalProperty'] = [
    '@type' => 'PropertyValue',
    'name' => 'CrowdLayer',
    'value' => $crowdSummary,
  ];
}

$websiteSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'WebSite',
  '@id' => $baseUrl . '/#website',
  'name' => 'PromoPilot',
  'url' => $baseUrl . '/',
  'inLanguage' => $current_lang ?? 'ru',
  'potentialAction' => [
    '@type' => 'SearchAction',
    'target' => $baseUrl . '/public/?q={search_term_string}',
    'query-input' => 'required name=search_term_string',
  ],
];

$webPageSchema = [
  '@context' => 'https://schema.org',
  '@type' => ['WebPage', 'LandingPage'],
  '@id' => $baseUrl . '/#webpage',
  'url' => $baseUrl . '/',
  'name' => __('PromoPilot — линкбилдинг каскадом'),
  'isPartOf' => [
    '@type' => 'WebSite',
    '@id' => $baseUrl . '/#website',
    'name' => 'PromoPilot',
    'url' => $baseUrl . '/',
  ],
  'description' => strip_tags($heroLead),
  'inLanguage' => $current_lang ?? 'ru',
  'about' => [
    '@type' => 'Service',
    '@id' => $baseUrl . '/#service',
    'name' => __('Каскадное продвижение PromoPilot'),
  ],
  'primaryImageOfPage' => [
    '@type' => 'ImageObject',
    'url' => asset_url('img/hero_main.jpg'),
    'width' => 1600,
    'height' => 900,
  ],
  'breadcrumb' => [
    '@type' => 'BreadcrumbList',
    'itemListElement' => $breadcrumbSchema['itemListElement'],
  ],
  'speakable' => [
    '@type' => 'SpeakableSpecification',
    'xpath' => [
      '/html/head/title',
      "//section[@id='hero']//h1",
    ],
  ],
  'potentialAction' => [
    [
      '@type' => 'ReadAction',
      'target' => $baseUrl . '/',
      'expectsAcceptanceOf' => $serviceOffer,
    ],
    [
      '@type' => 'RegisterAction',
      'target' => pp_url('auth/register.php'),
      'result' => [
        '@type' => 'Thing',
        'name' => __('Создание аккаунта PromoPilot'),
      ],
    ],
  ],
  'mainEntity' => [
    '@type' => 'Service',
    '@id' => $baseUrl . '/#service',
  ],
];

$levelListItems = [];
foreach ($levels as $position => $level) {
  $additionalProperties = [
    [
      '@type' => 'PropertyValue',
      'name' => __('Публикаций на уровне'),
      'value' => (int)$level['count'],
    ],
    [
      '@type' => 'PropertyValue',
      'name' => __('Накопительно'),
      'value' => (int)$level['cumulative'],
    ],
  ];

  if (!empty($level['per']) && !empty($level['perLabel'])) {
    $additionalProperties[] = [
      '@type' => 'PropertyValue',
      'name' => $level['perLabel'],
      'value' => (int)$level['per'],
    ];
  }

  if (!empty($level['length'])) {
    $additionalProperties[] = [
      '@type' => 'PropertyValue',
      'name' => __('Объём контента'),
      'value' => $level['length'],
    ];
  }

  $levelListItems[] = [
    '@type' => 'ListItem',
    'position' => $position + 1,
    'item' => [
      '@type' => 'CreativeWork',
      'name' => sprintf('L%s — %s', $level['index'], $level['title']),
      'description' => $level['description'],
      'isPartOf' => $creativeSeriesSchema['@id'] ?? ($baseUrl . '/#creativeSeries'),
      'additionalProperty' => $additionalProperties,
    ],
  ];
}

$levelListSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'ItemList',
  'name' => __('Структура каскада PromoPilot'),
  'description' => $schemeSummary,
  'numberOfItems' => count($levels),
  'itemListElement' => $levelListItems ?: [],
];

$howToSteps = [];
foreach ($processSteps as $position => $step) {
  $howToSteps[] = [
    '@type' => 'HowToStep',
    'position' => $position + 1,
    'name' => $step['title'],
    'text' => $step['description'],
  ];
}

$howToSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'HowTo',
  'name' => __('Как запустить каскад PromoPilot'),
  'description' => __('Трёхшаговый план запуска многоуровневого линкбилдинга с живым краудом.'),
  'step' => $howToSteps,
  'tool' => [
    [
      '@type' => 'HowToTool',
      'name' => 'PromoPilot Platform',
    ],
  ],
  'supply' => [
    [
      '@type' => 'HowToSupply',
      'name' => __('Анкор-лист и целевые URL'),
    ],
  ],
];

$breadcrumbSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'BreadcrumbList',
  'itemListElement' => [
    [
      '@type' => 'ListItem',
      'position' => 1,
      'name' => __('Главная'),
      'item' => $baseUrl . '/',
    ],
    [
      '@type' => 'ListItem',
      'position' => 2,
      'name' => __('Линкбилдинг каскадом'),
      'item' => $baseUrl . '/public/',
    ],
  ],
];

$faqSchemaEntities = [];
foreach ($faqItems as $item) {
  $faqSchemaEntities[] = [
    '@type' => 'Question',
    'name' => $item['question'],
    'acceptedAnswer' => [
      '@type' => 'Answer',
      'text' => $item['answer'],
    ],
  ];
}

$faqSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'FAQPage',
  'mainEntity' => $faqSchemaEntities,
];

$structuredData = [
  $organizationSchema,
  $serviceSchema,
  $creativeSeriesSchema,
  $websiteSchema,
  $webPageSchema,
  $levelListSchema,
  $howToSchema,
  $breadcrumbSchema,
  $faqSchema,
];

include __DIR__ . '/../includes/header.php';

foreach ($structuredData as $schema) {
  if (!is_array($schema) || empty($schema)) { continue; }
  echo '<script type="application/ld+json">' . PHP_EOL;
  echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
  echo '</script>' . PHP_EOL;
}

?>
<section class="hero-modern" id="hero">
  <div class="hero-modern__copy">
    <span class="hero-badge"><?php echo __('Новый формат линкбилдинга'); ?></span>
    <h1 class="hero-title"><?php echo __('PromoPilot — безопасный каскад ссылок вместо одиночных покупок'); ?></h1>
    <p class="hero-lead"><?php echo htmlspecialchars($heroLead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
    <ul class="hero-list">
      <li><i class="bi bi-stars"></i><span><?php echo __('Редакционные L1-активы с ручной модерацией и тематическим контентом.'); ?></span></li>
      <li><i class="bi bi-diagram-3"></i><span><?php echo __('Каскад 5 → 10 → 100 усиливает ссылку уровня за уровнем.'); ?></span></li>
      <li><i class="bi bi-shield-lock"></i><span><?php echo __('Риск остаётся на внешних площадках, целевая страница получает чистый вес.'); ?></span></li>
    </ul>
    <div class="hero-actions">
      <a href="#cta" class="btn btn-primary btn-lg"><i class="bi bi-rocket-takeoff me-2"></i><?php echo __('Запустить каскад'); ?></a>
      <a href="#cascade" class="btn btn-outline-light btn-lg"><i class="bi bi-diagram-3 me-2"></i><?php echo __('Посмотреть уровни'); ?></a>
    </div>
  </div>
  <div class="hero-modern__card">
    <div class="hero-card">
      <div class="hero-card__head">
        <h2><?php echo __('Сводка активной схемы'); ?></h2>
        <span class="hero-card__label"><?php echo __('Автообновление из настроек'); ?></span>
      </div>
      <dl class="hero-card__stats">
        <div class="hero-card__row">
          <dt><?php echo __('Уровней'); ?></dt>
          <dd><?php echo $levelsCount; ?></dd>
        </div>
        <div class="hero-card__row">
          <dt><?php echo __('Структура'); ?></dt>
          <dd><?php echo htmlspecialchars($schemeSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>
        </div>
        <div class="hero-card__row">
          <dt><?php echo __('Всего ссылок'); ?></dt>
          <dd><?php echo $formatNumber($cascadeTotal); ?></dd>
        </div>
        <?php if ($priceFormatted): ?>
        <div class="hero-card__row">
          <dt><?php echo __('Стоимость'); ?></dt>
          <dd><?php echo htmlspecialchars($priceFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($hasCrowd && $crowdSummary): ?>
        <div class="hero-card__row">
          <dt><?php echo __('Crowd-слой'); ?></dt>
          <dd><?php echo htmlspecialchars($crowdSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>
        </div>
        <?php endif; ?>
      </dl>
      <p class="hero-card__note"><?php echo __('Меняйте параметры в админке — лендинг адаптируется автоматически.'); ?></p>
      <div class="hero-card__actions">
        <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-person-plus me-2"></i><?php echo __('Создать аккаунт'); ?></a>
        <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-link btn-sm text-decoration-none text-muted"><?php echo __('Войти'); ?></a>
      </div>
    </div>
  </div>
</section>

<section class="landing-section metrics-panel" id="metrics">
  <div class="section-head">
    <h2><?php echo __('Цифры каскада из вашей конфигурации'); ?></h2>
    <p class="text-muted"><?php echo __('Никаких маркетинговых обещаний — только данные из активных настроек PromoPilot.'); ?></p>
  </div>
  <div class="metric-grid">
    <?php foreach ($metricChips as $chip): ?>
      <article class="metric-chip">
        <span class="metric-chip__label"><?php echo htmlspecialchars($chip['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
        <strong><?php echo htmlspecialchars($chip['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
        <span class="metric-chip__hint"><?php echo htmlspecialchars($chip['hint'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="landing-section" id="comparison">
  <div class="section-head">
    <h2><?php echo __('Почему каскад выгоднее одиночной ссылки'); ?></h2>
    <p class="text-muted"><?php echo __('Сравнили рынок и PromoPilot, чтобы показать, где появляется добавленная ценность.'); ?></p>
  </div>
  <div class="comparison-grid">
    <?php foreach ($comparisonCards as $slug => $card): ?>
      <article class="comparison-card comparison-card--<?php echo htmlspecialchars($slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <h3><?php echo htmlspecialchars($card['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
        <p class="comparison-subtitle"><?php echo htmlspecialchars($card['subtitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
        <ul class="comparison-list">
          <?php foreach ($card['points'] as $point): ?>
            <li><i class="bi bi-dot"></i><span><?php echo htmlspecialchars($point, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></li>
          <?php endforeach; ?>
        </ul>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="landing-section" id="cascade">
  <div class="section-head">
    <h2><?php echo __('Структура каскада по уровням'); ?></h2>
    <p class="text-muted"><?php echo __('Значения берутся из настроек и обновляются автоматически при изменениях.'); ?></p>
  </div>
  <div class="cascade-flow">
    <?php foreach ($levels as $level): ?>
      <article class="flow-step">
        <span class="flow-step__badge">L<?php echo $level['index']; ?></span>
        <h3><?php echo htmlspecialchars($level['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
        <p><?php echo htmlspecialchars($level['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
        <dl class="flow-step__stats">
          <div class="flow-step__stat">
            <dt><?php echo __('Публикаций на уровне'); ?></dt>
            <dd><?php echo $formatNumber($level['count']); ?></dd>
          </div>
          <div class="flow-step__stat">
            <dt><?php echo __('Накопительно'); ?></dt>
            <dd><?php echo $formatNumber($level['cumulative']); ?></dd>
          </div>
          <?php if (!empty($level['per']) && !empty($level['perLabel'])): ?>
          <div class="flow-step__stat">
            <dt><?php echo htmlspecialchars($level['perLabel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dt>
            <dd><?php echo $formatNumber($level['per']); ?></dd>
          </div>
          <?php endif; ?>
          <?php if (!empty($level['length'])): ?>
          <div class="flow-step__stat">
            <dt><?php echo __('Объём'); ?></dt>
            <dd><?php echo htmlspecialchars($level['length'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>
          </div>
          <?php endif; ?>
        </dl>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($crowdSummary): ?>
    <p class="cascade-note text-muted"><i class="bi bi-lightning-charge me-2"></i><?php echo htmlspecialchars($crowdSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
  <?php endif; ?>
</section>

<section class="landing-section" id="safety">
  <div class="section-head">
    <h2><?php echo __('Безопасность встроена в каскад'); ?></h2>
    <p class="text-muted"><?php echo __('Каждый уровень выполняет свою роль и страхует предыдущий.'); ?></p>
  </div>
  <div class="safety-grid">
    <?php foreach ($safetyPoints as $point): ?>
      <article class="safety-card">
        <span class="safety-icon"><i class="bi bi-<?php echo htmlspecialchars($point['icon'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"></i></span>
        <h3><?php echo htmlspecialchars($point['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
        <p><?php echo htmlspecialchars($point['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="landing-section" id="process">
  <div class="section-head">
    <h2><?php echo __('Как запускается каскад'); ?></h2>
    <p class="text-muted"><?php echo __('От брифа до отчёта — три прозрачных шага.'); ?></p>
  </div>
  <div class="process-modern">
    <?php foreach ($processSteps as $index => $step): ?>
      <article class="process-card">
        <span class="process-step">0<?php echo $index + 1; ?></span>
        <h3><?php echo htmlspecialchars($step['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
        <p><?php echo htmlspecialchars($step['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="landing-section" id="faq">
  <div class="section-head">
    <h2><?php echo __('Частые вопросы'); ?></h2>
    <p class="text-muted"><?php echo __('Не нашли ответ — напишите нам или заполните бриф, и команда всё настроит.'); ?></p>
  </div>
  <div class="accordion faq-accordion" id="faqAccordion">
    <?php foreach ($faqItems as $idx => $item): ?>
      <?php $collapseId = 'faqCollapse' . $idx; $headingId = 'faqHeading' . $idx; ?>
      <div class="accordion-item">
        <h2 class="accordion-header" id="<?php echo htmlspecialchars($headingId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <button class="accordion-button <?php echo $idx === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($collapseId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" aria-expanded="<?php echo $idx === 0 ? 'true' : 'false'; ?>" aria-controls="<?php echo htmlspecialchars($collapseId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($item['question'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </button>
        </h2>
        <div id="<?php echo htmlspecialchars($collapseId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>" aria-labelledby="<?php echo htmlspecialchars($headingId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-bs-parent="#faqAccordion">
          <div class="accordion-body">
            <?php echo htmlspecialchars($item['answer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="landing-section" id="cta">
  <div class="cta-modern">
    <div class="cta-modern__content">
      <span class="cta-badge"><?php echo __('Демо и онбординг'); ?></span>
      <h2><?php echo __('Запустим ваш каскад за два дня'); ?></h2>
      <p><?php echo __('Создайте аккаунт, заполните короткий бриф и получите доступ к отчётам и API.'); ?></p>
      <div class="cta-actions">
        <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-primary btn-lg"><i class="bi bi-person-plus me-2"></i><?php echo __('Зарегистрироваться'); ?></a>
        <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-outline-light btn-lg"><i class="bi bi-box-arrow-in-right me-2"></i><?php echo __('Войти'); ?></a>
      </div>
      <?php if ($googleEnabled): ?>
      <p class="cta-note"><i class="bi bi-google me-1"></i><?php echo __('Можно войти через Google OAuth — без паролей.'); ?></p>
      <?php endif; ?>
    </div>
    <div class="cta-modern__visual">
      <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="PromoPilot" class="cta-logo" loading="lazy" width="260" height="92">
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>