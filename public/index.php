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
  $levelListItems[] = [
    '@type' => 'ListItem',
    'position' => $position + 1,
    'item' => [
      '@type' => 'CreativeWork',
      'name' => sprintf('L%s — %s', $level['index'], $level['title']),
      'description' => $level['description'],
      'isPartOf' => $creativeSeriesSchema['@id'] ?? ($baseUrl . '/#creativeSeries'),
      'additionalProperty' => [
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
      ],
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

$howToSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'HowTo',
  'name' => __('Как запустить каскад PromoPilot'),
  'description' => __('Трёхшаговый план запуска многоуровневого линкбилдинга с живым краудом.'),
  'step' => [
    [
      '@type' => 'HowToStep',
      'position' => 1,
      'name' => __('Собрать бриф и анкор-лист'),
      'text' => __('Вы сообщаете целевые URL, географию и акценты, а мы формируем анкорный микс и сети площадок.'),
    ],
    [
      '@type' => 'HowToStep',
      'position' => 2,
      'name' => __('Синхронизировать контент и крауд'),
      'text' => __('Мы создаём фундаментальные активы, запускаем поддерживающие ветви и готовим живые аккаунты для крауд-волны.'),
    ],
    [
      '@type' => 'HowToStep',
      'position' => 3,
      'name' => __('Отслеживать прогресс и индексацию'),
      'text' => __('Дашборд показывает статусы публикаций, появление ссылок и динамику crowd-сигналов в реальном времени.'),
    ],
  ],
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

$faqSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'FAQPage',
  'mainEntity' => [
    [
      '@type' => 'Question',
      'name' => __('Что входит в линкбилдинг PromoPilot?'),
      'acceptedAnswer' => [
        '@type' => 'Answer',
        'text' => trim(sprintf(__('Текущая схема: %s.'), $schemeSummary) . ' ' . ($crowdSummary ?: __('Дополнительно подключаем крауд-слой, когда это критично для ниши.'))),
      ],
    ],
    [
      '@type' => 'Question',
      'name' => __('Сколько crowd-сигналов вы подключаете?'),
      'acceptedAnswer' => [
        '@type' => 'Answer',
        'text' => $crowdSummary ? sprintf(__('Мы планируем до %s живых крауд-ссылок и упоминаний на публикацию и мониторим каждую площадку.'), $formatNumber($crowdPerPublication)) : __('Crowd-слой масштабируется индивидуально: мы выбираем площадки и частоту после анализа ниши и конкурентов.'),
      ],
    ],
    [
      '@type' => 'Question',
      'name' => __('Можно ли адаптировать схему под нишу и бюджет?'),
      'acceptedAnswer' => [
        '@type' => 'Answer',
        'text' => __('Да. Мы регулируем количество уровней, плотность публикаций, долю crowd-ссылок и темп выхода в зависимости от рынка, сезонности и вашего бюджета. Все изменения видны в личном кабинете.'),
      ],
    ],
  ],
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
<section class="landing-hero" id="hero">
      <div class="row align-items-center g-5 flex-column-reverse flex-lg-row">
        <div class="col-lg-6">
          <div class="hero-intro fade-in">
            <span class="hero-pill"><?php echo __('Линкбилдинг каскадом под вашу нишу'); ?></span>
            <h1 class="hero-title fw-bold mb-3"><?php echo __('PromoPilot — линкбилдинг каскадом с живым crowd-слоем'); ?></h1>
            <p class="lead mb-4 hero-lead"><?php echo htmlspecialchars($heroLead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
            <ul class="hero-points list-unstyled mb-4">
              <li><i class="bi bi-diagram-3-fill"></i><span><?php echo sprintf(__('Структура %s уровня(ов): выстраиваем анкор-листы, объём и географию'), $levelsCount); ?></span></li>
              <li><i class="bi bi-graph-up-arrow"></i><span><?php echo $hasCrowd ? __('Живой crowd и тематические волны из проверенных аккаунтов') : __('Готовим площадки и тематические волны под ваш запрос'); ?></span></li>
              <li><i class="bi bi-clock-history"></i><span><?php echo __('Тайминг на 2–3 месяца: управляем скоростью и пингами без всплесков'); ?></span></li>
              <li><i class="bi bi-shield-check"></i><span><?php echo __('Мониторинг индексации, статусов и отчётов в личном кабинете'); ?></span></li>
            </ul>
            <div class="card bg-dark border border-primary-subtle hero-scheme mb-4 shadow-sm">
              <div class="card-body py-3 px-4">
                <div class="small text-uppercase text-primary fw-semibold mb-1"><?php echo __('Текущая схема PromoPilot'); ?></div>
                <div class="d-flex flex-column flex-md-row gap-2 align-items-start">
                  <span class="fw-semibold"><?php echo htmlspecialchars($schemeSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                  <?php if ($hasCrowd && $crowdSummary): ?>
                  <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?php echo htmlspecialchars($crowdSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-3 mb-4">
              <a href="#cta" class="btn btn-gradient btn-lg"><i class="bi bi-rocket-takeoff me-2"></i><?php echo __('Запустить каскад'); ?></a>
              <a href="#levels" class="btn btn-outline-light btn-lg"><i class="bi bi-info-circle me-2"></i><?php echo __('Посмотреть структуру'); ?></a>
            </div>
            <div class="hero-metrics d-flex flex-wrap gap-3">
              <div class="metric-badge"><strong><?php echo $formatNumber($cascadeTotal); ?></strong><span><?php echo __('публикаций в пакете'); ?></span></div>
              <div class="metric-badge"><strong><?php echo $levelsCount; ?></strong><span><?php echo __('уровня каскада'); ?></span></div>
              <div class="metric-badge"><strong><?php echo htmlspecialchars($levelBreakdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong><span><?php echo __('разбивка по уровням'); ?></span></div>
              <?php if ($priceFormatted): ?>
              <div class="metric-badge"><strong><?php echo htmlspecialchars($priceFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong><span><?php echo __('стоимость за ссылку'); ?></span></div>
              <?php endif; ?>
              <?php if ($pricePerPublication): ?>
              <div class="metric-badge"><strong><?php echo htmlspecialchars(number_format($pricePerPublication, 2, '.', ' '), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong><span><?php echo __('за публикацию в каскаде'); ?></span></div>
              <?php endif; ?>
              <?php if ($hasCrowd): ?>
              <div class="metric-badge"><strong><?php echo sprintf(__('до %s'), $formatNumber($crowdPerPublication)); ?></strong><span><?php echo __('крауд-касаний на публикацию'); ?></span></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="hero-media position-relative text-center">
            <picture>
              <source srcset="<?php echo asset_url('img/hero_main.jpg'); ?>" type="image/jpeg">
              <img src="<?php echo asset_url('img/hero_main.jpg'); ?>" alt="<?php echo __('Схема многоуровневого продвижения'); ?>" class="img-fluid rounded-4 shadow hero-image mb-3" loading="lazy" width="1600" height="900">
            </picture>
            <figcaption class="small text-muted"><?php echo sprintf(__('Схема сейчас: %s (%s).'), htmlspecialchars($schemeSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), htmlspecialchars($levelBreakdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></figcaption>
            <?php if (!is_logged_in()): ?>
            <div class="auth-panel card mt-4 text-start">
              <div class="card-body p-4">
                <h2 class="h5 mb-3"><?php echo __('Войти и посмотреть демо-дашборд'); ?></h2>
                <?php if ($googleEnabled): ?>
                <a href="<?php echo htmlspecialchars($googleStartUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="btn btn-google w-100 mb-3"><i class="bi bi-google me-2"></i><?php echo __('Войти через Google'); ?></a>
                <div class="auth-divider text-center mb-3"><span><?php echo __('или продолжить классически'); ?></span></div>
                <?php endif; ?>
                <ul class="nav nav-pills mb-3 gap-2 auth-tabs" id="pills-auth" role="tablist">
                  <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-login" data-bs-toggle="pill" data-bs-target="#pane-login" type="button" role="tab" aria-controls="pane-login" aria-selected="true"><?php echo __('Вход'); ?></button>
                  </li>
                  <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-register" data-bs-toggle="pill" data-bs-target="#pane-register" type="button" role="tab" aria-controls="pane-register" aria-selected="false"><?php echo __('Регистрация'); ?></button>
                  </li>
                </ul>
                <div class="tab-content" id="pills-authContent">
                  <div class="tab-pane fade show active" id="pane-login" role="tabpanel" aria-labelledby="tab-login">
                    <form method="post" action="<?php echo pp_url('auth/login.php'); ?>" class="auth-form" novalidate>
                      <?php echo csrf_field(); ?>
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Email или логин'); ?></label>
                        <input type="text" name="login" class="form-control form-control-lg" required autocomplete="username">
                      </div>
                      <div class="mb-3">
                        <label class="form-label d-flex justify-content-between align-items-center"><span><?php echo __('Пароль'); ?></span> <a href="<?php echo pp_url('auth/login.php'); ?>#recover" class="small text-decoration-none"><?php echo __('Забыли?'); ?></a></label>
                        <input type="password" name="password" class="form-control form-control-lg" required autocomplete="current-password">
                      </div>
                      <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i><?php echo __('Войти'); ?></button>
                        <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-outline-light btn-sm"><?php echo __('Создать аккаунт отдельно'); ?></a>
                      </div>
                    </form>
                  </div>
                  <div class="tab-pane fade" id="pane-register" role="tabpanel" aria-labelledby="tab-register">
                    <form method="post" action="<?php echo pp_url('auth/register.php'); ?>" class="auth-form" novalidate>
                      <?php echo csrf_field(); ?>
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Email'); ?></label>
                        <input type="email" name="email" class="form-control form-control-lg" required autocomplete="email">
                      </div>
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Логин'); ?></label>
                        <input type="text" name="username" class="form-control form-control-lg" required autocomplete="username">
                      </div>
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Пароль'); ?></label>
                        <input type="password" name="password" class="form-control form-control-lg" required autocomplete="new-password">
                      </div>
                      <div class="d-grid gap-2">
                        <button class="btn btn-success btn-lg" type="submit"><i class="bi bi-person-plus me-2"></i><?php echo __('Зарегистрироваться'); ?></button>
                        <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-outline-light btn-sm"><?php echo __('У меня уже есть аккаунт'); ?></a>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <section class="landing-section" id="levels">
      <div class="section-head">
        <h2 class="h3 fw-bold mb-2"><?php echo __('Автообновляемая структура каскада'); ?></h2>
        <p class="text-muted mb-0"><?php echo __('Данные берутся из текущих настроек PromoPilot — измените конфигурацию в админке, и лендинг обновится автоматически.'); ?></p>
      </div>
      <div class="row g-4 align-items-start mt-1">
        <div class="col-xl-5">
          <div class="levels-desc pe-xl-3">
            <?php foreach ($levels as $level): ?>
              <div class="mb-3 level-block">
                <h6 class="text-primary mb-1"><span class="badge bg-primary-subtle text-primary-emphasis"><?php echo $level['index']; ?></span> <?php echo htmlspecialchars($level['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h6>
                <p class="mb-2 small"><?php echo htmlspecialchars($level['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                <div class="level-meta small text-muted d-flex flex-wrap gap-3">
                  <span><i class="bi bi-bar-chart me-1"></i><?php echo sprintf(__('Публикаций: %s'), $formatNumber($level['count'])); ?></span>
                  <span><i class="bi bi-collection me-1"></i><?php echo sprintf(__('Накопительно: %s'), $formatNumber($level['cumulative'])); ?></span>
                  <span><i class="bi bi-text-paragraph me-1"></i><?php echo htmlspecialchars($level['length'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="ratio ratio-16x9 rounded bg-gradient position-relative overflow-hidden mb-3 border border-1 border-opacity-25 border-primary-subtle">
              <img src="<?php echo asset_url('img/diagram_levels.svg'); ?>" alt="<?php echo __('Диаграмма уровней'); ?>" class="w-100 h-100 object-fit-contain p-3" loading="lazy">
            </div>
            <p class="small text-muted mb-0"><i class="bi bi-lightning-charge me-1"></i><?php echo htmlspecialchars($crowdLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
          </div>
        </div>
        <div class="col-xl-7">
          <div class="table-responsive shadow-sm rounded overflow-hidden level-table-wrapper mb-4">
            <table class="table table-dark table-striped table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?php echo __('Уровень'); ?></th>
                  <th><?php echo __('Описание'); ?></th>
                  <th><?php echo __('Публикаций на уровне'); ?></th>
                  <th><?php echo __('Всего накопительно'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($levels as $level): ?>
                <tr>
                  <td class="fw-bold">L<?php echo $level['index']; ?></td>
                  <td><?php echo htmlspecialchars($level['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                  <td><?php echo $formatNumber($level['count']); ?></td>
                  <td><?php echo $formatNumber($level['cumulative']); ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="table-secondary text-dark">
                  <th colspan="2" class="text-end"><?php echo __('Итого'); ?></th>
                  <th><?php echo $formatNumber($cascadeTotal); ?></th>
                  <th><?php echo $formatNumber($cascadeTotal); ?></th>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="row g-3 level-cards">
            <?php foreach ($levels as $level): ?>
              <?php $media = $levelImages[$level['index']] ?? null; ?>
              <div class="col-md-<?php echo $levelsCount >= 3 ? '4' : '6'; ?>">
                <div class="card h-100 text-center level-card">
                  <div class="card-body p-3">
                    <?php if ($media): ?>
                      <img src="<?php echo asset_url($media['src']); ?>" alt="<?php echo htmlspecialchars($media['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="img-fluid rounded level-image mb-2" loading="lazy">
                    <?php endif; ?>
                    <h6 class="fw-bold mb-1">L<?php echo $level['index']; ?></h6>
                    <p class="small mb-0 text-muted"><?php echo htmlspecialchars($level['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <section class="landing-section" id="benefits">
      <div class="row g-5 align-items-center">
        <div class="col-lg-6 order-2 order-lg-1">
          <h2 class="h3 fw-bold mb-3"><?php echo __('Что даёт каскад PromoPilot'); ?></h2>
          <p><?php echo __('Комбинация AI-контента, распределённого расписания и крауд-мониторинга создаёт безопасный рост ссылочного профиля и ускоряет индексацию.'); ?></p>
          <div class="value-grid mb-4">
            <div class="value-card">
              <i class="bi bi-stars"></i>
              <h6><?php echo __('AI-копирайтинг + иллюстрации'); ?></h6>
              <p class="small text-muted mb-0"><?php echo __('Каждая публикация получает тематический текст и обложку, проверенные перед выгрузкой.'); ?></p>
            </div>
            <div class="value-card">
              <i class="bi bi-stopwatch"></i>
              <h6><?php echo __('Плавный тайминг 2–3 месяца'); ?></h6>
              <p class="small text-muted mb-0"><?php echo __('Алгоритм растягивает публикации, чтобы траст рос естественно и без фильтров.'); ?></p>
            </div>
            <div class="value-card">
              <i class="bi bi-speedometer2"></i>
              <h6><?php echo __('Дашборд с метриками'); ?></h6>
              <p class="small text-muted mb-0"><?php echo __('Видите статусы, индексацию, ссылки и экспортируете отчёты по каждому уровню.'); ?></p>
            </div>
          </div>
          <ul class="list-unstyled benefit-list mb-4">
            <li><i class="bi bi-check-circle"></i><?php echo __(' Управляем анкорами и географией площадок'); ?></li>
            <li><i class="bi bi-check-circle"></i><?php echo __(' Пингуем и проверяем появление публикаций автоматически'); ?></li>
            <li><i class="bi bi-check-circle"></i><?php echo __(' Предиктивно распределяем нагрузку, чтобы профиль выглядел натурально'); ?></li>
          </ul>
          <div class="d-flex gap-3 flex-wrap">
            <a href="#process" class="btn btn-primary btn-lg"><i class="bi bi-lightning-charge me-2"></i><?php echo __('Посмотреть этапы'); ?></a>
            <a href="#faq" class="btn btn-outline-light btn-lg"><i class="bi bi-question-circle me-2"></i><?php echo __('Ответы на вопросы'); ?></a>
          </div>
        </div>
        <div class="col-lg-6 order-1 order-lg-2 text-center">
          <div class="stat-card shadow">
            <h5 class="mb-3"><?php echo __('Сводка по вашему каскаду'); ?></h5>
            <div class="stat-card__grid">
              <div class="stat-item">
                <span class="label"><?php echo __('Пакет публикаций'); ?></span>
                <strong><?php echo $formatNumber($cascadeTotal); ?></strong>
                <span class="hint"><?php echo htmlspecialchars($levelBreakdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
              </div>
              <div class="stat-item">
                <span class="label"><?php echo __('Стоимость за ссылку'); ?></span>
                <strong><?php echo $priceFormatted ? htmlspecialchars($priceFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : __('по запросу'); ?></strong>
                <span class="hint"><?php echo $pricePerPublication ? sprintf(__('~%s %s за публикацию'), htmlspecialchars(number_format($pricePerPublication, 2, '.', ' '), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $currencyCode) : __('индивидуальный расчёт'); ?></span>
              </div>
              <div class="stat-item">
                <span class="label"><?php echo __('Крауд-касания'); ?></span>
                <strong><?php echo $hasCrowd ? sprintf(__('до %s/статью'), $formatNumber($crowdPerPublication)) : __('ручной отбор'); ?></strong>
                <span class="hint"><?php echo $crowdSummary ? htmlspecialchars($crowdSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : __('Площадки и частоту фиксируем в дашборде'); ?></span>
              </div>
            </div>
          </div>
          <article class="testimonial-card mt-4" id="testimonial">
            <div class="testimonial-header">
              <img src="<?php echo asset_url('img/photo.jpeg'); ?>" alt="<?php echo htmlspecialchars($testimonialAuthor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="testimonial-photo" loading="lazy" width="120" height="120">
              <div>
                <strong><?php echo htmlspecialchars($testimonialAuthor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                <span class="small text-muted d-block"><?php echo htmlspecialchars($testimonialRole, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
              </div>
            </div>
            <p class="testimonial-quote">“<?php echo htmlspecialchars($testimonialQuote, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>”</p>
          </article>
        </div>
      </div>
    </section>

    <section class="landing-section" id="process">
      <div class="section-head">
        <h2 class="h4 fw-bold mb-2"><?php echo __('Этапы запуска и контроля'); ?></h2>
        <p class="text-muted mb-0"><?php echo __('Прозрачный маршрут от технического задания до первых индексаций в поиске.'); ?></p>
      </div>
      <div class="row g-4 process-steps">
        <div class="col-md-4">
          <div class="step-item h-100 p-4 rounded border position-relative">
            <div class="step-number">1</div>
            <h6 class="fw-bold mb-2"><?php echo __('Сбор брифа и анкор-листа'); ?></h6>
            <p class="small mb-0"><?php echo __('Фиксируем целевые страницы, пропорции типов анкоров и географию площадок.'); ?></p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="step-item h-100 p-4 rounded border position-relative">
            <div class="step-number">2</div>
            <h6 class="fw-bold mb-2"><?php echo __('Генерация каскада и публикации'); ?></h6>
            <p class="small mb-0"><?php echo __('AI-модели готовят тексты и визуалы, PromoPilot распределяет тайминг и размещает материалы.'); ?></p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="step-item h-100 p-4 rounded border position-relative">
            <div class="step-number">3</div>
            <h6 class="fw-bold mb-2"><?php echo __('Мониторинг и адаптация'); ?></h6>
            <p class="small mb-0"><?php echo __('Пингуем, проверяем индексацию, докручиваем крауд-ссылки и публикуем отчёты.'); ?></p>
          </div>
        </div>
      </div>
      <div class="process-flow mt-4 text-center">
        <picture>
          <source srcset="<?php echo asset_url('img/process_flow.svg'); ?>" type="image/svg+xml">
          <img src="<?php echo asset_url('img/process_flow.png'); ?>" alt="<?php echo __('Поток этапов: бриф → публикация → индекс'); ?>" class="img-fluid process-flow-image rounded-3 shadow" loading="lazy" width="1400" height="500">
        </picture>
        <div class="small text-muted mt-2"><?php echo __('Средний горизонт — 10–12 недель, первые индексации обычно появляются в течение 14 дней после старта.'); ?></div>
      </div>
    </section>

    <section class="landing-section" id="faq">
      <div class="section-head">
        <h2 class="h4 fw-bold mb-2"><?php echo __('Частые вопросы'); ?></h2>
        <p class="text-muted mb-0"><?php echo __('Если нужен индивидуальный разбор — заполните бриф в личном кабинете или напишите в поддержку.'); ?></p>
      </div>
      <div class="accordion faq-accordion" id="faqAccordion">
        <div class="accordion-item">
          <h2 class="accordion-header" id="faqOne">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseOne" aria-expanded="true" aria-controls="faqCollapseOne">
              <?php echo __('Как PromoPilot гарантирует безопасный тайминг?'); ?>
            </button>
          </h2>
          <div id="faqCollapseOne" class="accordion-collapse collapse show" aria-labelledby="faqOne" data-bs-parent="#faqAccordion">
            <div class="accordion-body">
              <?php echo __('Мы распределяем публикации на 2–3 месяца, чередуем площадки и автоматически пингуем URL. При отклонениях система уведомит и скорректирует график.'); ?>
            </div>
          </div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header" id="faqTwo">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseTwo" aria-expanded="false" aria-controls="faqCollapseTwo">
              <?php echo __('Можно ли изменить количество уровней или объём статей?'); ?>
            </button>
          </h2>
          <div id="faqCollapseTwo" class="accordion-collapse collapse" aria-labelledby="faqTwo" data-bs-parent="#faqAccordion">
            <div class="accordion-body">
              <?php echo __('Да, все параметры — количество статей, длина текстов, наличие уровня L3 — управляются в настройках. Лендинг и калькуляция обновятся без правок кода.'); ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="landing-section cta-final" id="cta">
      <div class="card cta-card overflow-hidden">
        <div class="card-body p-5 p-md-5 text-center text-md-start position-relative">
          <div class="row align-items-center g-4">
            <div class="col-md-7">
              <h2 class="h3 fw-bold mb-3"><?php echo __('Готовы запустить каскад?'); ?></h2>
              <p class="mb-4 lead mb-md-3"><?php echo __('Создайте аккаунт и получите доступ к демо-проекту, отчётам по публикациям и настройкам своей структуры.'); ?></p>
              <div class="d-flex flex-wrap gap-3">
                <a href="<?php echo pp_url('auth/register.php'); ?>" class="btn btn-gradient btn-lg"><i class="bi bi-person-plus me-2"></i><?php echo __('Зарегистрироваться'); ?></a>
                <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-outline-light btn-lg"><i class="bi bi-box-arrow-in-right me-2"></i><?php echo __('Войти'); ?></a>
              </div>
              <?php if ($googleEnabled): ?>
              <p class="small text-muted mt-3 mb-0"><i class="bi bi-google me-1"></i><?php echo __('Есть Google-аккаунт? Войдите одним кликом через Google OAuth.'); ?></p>
              <?php endif; ?>
              <p class="small text-muted mt-1 mb-0"><i class="bi bi-shield-lock me-1"></i><?php echo __('Данные защищены. Никакого спама, только прогресс-уведомления.'); ?></p>
            </div>
            <div class="col-md-5 text-center text-md-end">
              <img src="<?php echo asset_url('img/dashboard_preview.png'); ?>" alt="<?php echo __('Превью панели'); ?>" class="img-fluid rounded-4 shadow-lg dashboard-preview" loading="lazy" width="900" height="640">
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="landing-section note-section">
      <div class="p-4 rounded border bg-dark-subtle text-dark">
        <p class="small mb-1"><strong><?php echo __('Примечание'); ?>:</strong> <?php echo __('Объём и конфигурация структуры автоматически адаптируются под нишу, язык и конкуренцию. Нужно больше уровня L3 или крауда — укажите в брифе.'); ?></p>
        <p class="small text-muted mb-0"><?php echo __('Статусы публикаций, индексации и крауд-касаций доступны после старта проекта в личном кабинете PromoPilot.'); ?></p>
      </div>
    </section>

  <?php include __DIR__ . '/../includes/footer.php'; ?>