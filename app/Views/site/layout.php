<?php
/**
 * Публичный layout сайта Wild World (ADR-052). Самодостаточный (не зависит от
 * агентских front-партиалов). Динамические SEO-мета через $meta.
 *
 * @var array<string,mixed> $meta
 */
$m = is_array($meta ?? null) ? $meta : [];
$title       = is_string($m['title'] ?? null) ? $m['title'] : 'Wild World';
$description = is_string($m['description'] ?? null) ? $m['description'] : '';
$canonical   = is_string($m['canonical'] ?? null) ? $m['canonical'] : rtrim(base_url(), '/');
$robots      = is_string($m['robots'] ?? null) ? $m['robots'] : 'index,follow';
$ogType      = is_string($m['ogType'] ?? null) ? $m['ogType'] : 'website';
$ogImage     = is_string($m['ogImage'] ?? null) && $m['ogImage'] !== '' ? $m['ogImage'] : null;
$ogImageUrl  = $ogImage ?? base_url('og-default.jpg');           // дефолтная соц-карта
$keywords    = is_string($m['keywords'] ?? null) ? $m['keywords'] : '';
$publishedAt = is_string($m['publishedTime'] ?? null) ? $m['publishedTime'] : null;
$modifiedAt  = is_string($m['modifiedTime'] ?? null) ? $m['modifiedTime'] : null;
$artSection  = is_string($m['section'] ?? null) ? $m['section'] : null;
$breadcrumbs = is_array($m['breadcrumbs'] ?? null) ? $m['breadcrumbs'] : [];
$extraLd     = is_array($m['jsonld'] ?? null) ? $m['jsonld'] : [];

$social    = config('Social');
$tgLink    = $social->botLink;   // CTA — играть в боте
$groupLink = $social->groupLink; // инфо/сообщество — группа

$siteUrl = rtrim(base_url(), '/');

// ── Единый JSON-LD @graph (Schema.org): Organization + WebSite + крошки + per-page.
$graph = [
    [
        '@type'  => 'Organization',
        '@id'    => $siteUrl . '/#organization',
        'name'   => 'Wild World',
        'url'    => $siteUrl . '/',
        'logo'   => base_url('favicon-512x512.png'),
        'sameAs' => [$tgLink, $groupLink],
    ],
    [
        '@type'      => 'WebSite',
        '@id'        => $siteUrl . '/#website',
        'name'       => 'Wild World',
        'url'        => $siteUrl . '/',
        'inLanguage' => 'ru',
        'publisher'  => ['@id' => $siteUrl . '/#organization'],
    ],
];
if ($breadcrumbs !== []) {
    $crumbItems = [];
    $pos        = 1;
    foreach ($breadcrumbs as $bc) {
        if (! is_array($bc)) {
            continue;
        }
        $crumbItems[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => is_string($bc['name'] ?? null) ? $bc['name'] : '',
            'item'     => is_string($bc['url'] ?? null) ? $bc['url'] : '',
        ];
    }
    if ($crumbItems !== []) {
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbItems];
    }
}
foreach ($extraLd as $ld) {
    if (is_array($ld)) {
        $graph[] = $ld;
    }
}
$jsonLd = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$navCats = (new \App\Models\SiteCategoryModel())->orderBy('sort')->findAll();
$uri     = uri_string();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title) ?></title>
    <meta name="description" content="<?= esc($description) ?>">
    <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= esc($keywords) ?>">
    <?php endif; ?><meta name="robots" content="<?= esc($robots) ?>">
    <meta name="theme-color" content="#0d1118">
    <link rel="canonical" href="<?= esc($canonical) ?>">

    <!-- Open Graph -->
    <meta property="og:site_name" content="Wild World">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:type" content="<?= esc($ogType) ?>">
    <meta property="og:title" content="<?= esc($title) ?>">
    <meta property="og:description" content="<?= esc($description) ?>">
    <meta property="og:url" content="<?= esc($canonical) ?>">
    <meta property="og:image" content="<?= esc($ogImageUrl) ?>">
    <?php if ($ogImage === null): ?>
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <?php endif; ?>
    <?php if ($ogType === 'article'): ?>
    <?php if ($publishedAt !== null): ?><meta property="article:published_time" content="<?= esc($publishedAt) ?>">
    <?php endif; ?><?php if ($modifiedAt !== null): ?><meta property="article:modified_time" content="<?= esc($modifiedAt) ?>">
    <?php endif; ?><?php if ($artSection !== null): ?><meta property="article:section" content="<?= esc($artSection) ?>">
    <?php endif; ?>
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= esc($title) ?>">
    <meta name="twitter:description" content="<?= esc($description) ?>">
    <meta name="twitter:image" content="<?= esc($ogImageUrl) ?>">

    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= base_url('favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= base_url('favicon-16x16.png') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
    <link rel="manifest" href="<?= base_url('site.webmanifest') ?>">
    <link rel="stylesheet" href="<?= base_url('css/vendors/bootstrap.min.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=PT+Sans:ital,wght@0,400;0,700;1,400&display=swap">
    <link rel="stylesheet" href="<?= base_url('css/site.css') ?>">
    <script type="application/ld+json"><?= $jsonLd ?></script>
    <?= $this->renderSection('head') ?>
</head>
<body class="ww-body">

<header class="ww-header">
    <div class="container">
        <nav class="navbar navbar-expand-lg">
            <a class="ww-brand" href="<?= base_url() ?>">WILD<span>WORLD</span></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#wwNav" aria-label="Меню">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="wwNav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link <?= $uri === '' ? 'active' : '' ?>" href="<?= base_url() ?>">Главная</a></li>
                    <li class="nav-item"><a class="nav-link <?= $uri === 'devblog' ? 'active' : '' ?>" href="<?= base_url('devblog') ?>">Девблог</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Разделы</a>
                        <ul class="dropdown-menu dropdown-menu-end ww-dropdown">
                            <?php foreach ($navCats as $c): ?>
                                <?php if (is_array($c) && is_string($c['slug'] ?? null)): ?>
                                    <li><a class="dropdown-item" href="<?= base_url(esc($c['slug'], 'url')) ?>"><?= esc($c['name'] ?? $c['slug']) ?></a></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="nav-item"><a class="nav-link <?= str_starts_with($uri, 'wiki') ? 'active' : '' ?>" href="<?= base_url('wiki') ?>">Вики</a></li>
                    <li class="nav-item ms-lg-3 mt-2 mt-lg-0">
                        <a class="ww-btn ww-btn-play" href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</header>

<main class="ww-main">
    <?= $this->renderSection('content') ?>
</main>

<footer class="ww-footer">
    <div class="container">
        <div class="row gy-4">
            <div class="col-lg-5">
                <a class="ww-brand" href="<?= base_url() ?>">WILD<span>WORLD</span></a>
                <p class="ww-muted mt-2">Постапокалиптическая текстовая MMORPG в Telegram. Исследуй, выживай, крафти, сражайся и строй базу в огромном открытом мире.</p>
                <a class="ww-btn ww-btn-play" href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener">▶ Начать играть</a>
            </div>
            <div class="col-lg-3 col-6">
                <div class="ww-foot-h">Разделы</div>
                <ul class="ww-foot-links">
                    <li><a href="<?= base_url('devblog') ?>">Девблог</a></li>
                    <li><a href="<?= base_url('wiki') ?>">Вики мира</a></li>
                    <?php foreach (array_slice(is_array($navCats) ? $navCats : [], 0, 4) as $c): ?>
                        <?php if (is_array($c) && is_string($c['slug'] ?? null)): ?>
                            <li><a href="<?= base_url(esc($c['slug'], 'url')) ?>"><?= esc($c['name'] ?? $c['slug']) ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="col-lg-4 col-6">
                <div class="ww-foot-h">Сообщество</div>
                <ul class="ww-foot-links">
                    <li><a href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener">🎮 Играть в боте</a></li>
                    <li><a href="<?= esc($groupLink, 'attr') ?>" target="_blank" rel="noopener">💬 Новости и обсуждение</a></li>
                </ul>
            </div>
        </div>
        <div class="ww-copy">© <?= date('Y') ?> Wild World. Текстовая MMORPG в Telegram.</div>
    </div>
</footer>

<script src="<?= base_url('js/vendors/bootstrap.bundle.min.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
