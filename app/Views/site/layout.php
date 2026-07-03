<?php
/**
 * Публичный layout сайта Wild World.
 *
 * ИСТОРИЯ:
 * - ADR-052 (2026-05-25) — миграция сайта WordPress → CI4.
 * - ADR-062 (2026-05-27) — flat-stencil design system «Найденная фотоплёнка».
 *   Стили — public/assets/css/wildworld-ui.css (единый source of truth).
 *   Скрипт — public/assets/js/wildworld-ui.js (только burger/drawer/dropdown).
 *   Living styleguide — /ui-kit.html.
 *
 * Контракт переменных (без изменений с ADR-052):
 * @var array<string,mixed> $meta
 *
 * Секции (CI4 view layout):
 * - head     — дополнительные теги в <head> (например, страничный CSS-override)
 * - content  — основной контент страницы (внутри <main id="ww-main">)
 * - scripts  — дополнительные скрипты в конце <body>
 *
 * 🔴 ПРАВИЛО: при добавлении новой страницы — extend этого layout, секции «content».
 * Если нужен новый компонент — сначала добавить в /ui-kit.html и wildworld-ui.css.
 */
$m = is_array($meta ?? null) ? $meta : [];
$title       = is_string($m['title'] ?? null) ? $m['title'] : 'Wild World — постапокалиптическая MMORPG в Telegram';
$description = is_string($m['description'] ?? null) ? $m['description'] : 'Постапокалиптическая текстовая MMORPG в Telegram. Исследуй, выживай, крафти, сражайся и строй базу в большом открытом мире.';
$canonical   = is_string($m['canonical'] ?? null) ? $m['canonical'] : rtrim(base_url(), '/');
$robots      = is_string($m['robots'] ?? null) ? $m['robots'] : 'index,follow';
$ogType      = is_string($m['ogType'] ?? null) ? $m['ogType'] : 'website';
$ogImage     = is_string($m['ogImage'] ?? null) && $m['ogImage'] !== '' ? $m['ogImage'] : null;
$ogImageUrl  = $ogImage ?? base_url('og-default.jpg');
$keywords    = is_string($m['keywords'] ?? null) ? $m['keywords'] : '';
$publishedAt = is_string($m['publishedTime'] ?? null) ? $m['publishedTime'] : null;
$modifiedAt  = is_string($m['modifiedTime'] ?? null) ? $m['modifiedTime'] : null;
$artSection  = is_string($m['section'] ?? null) ? $m['section'] : null;
$breadcrumbs = is_array($m['breadcrumbs'] ?? null) ? $m['breadcrumbs'] : [];
$extraLd     = is_array($m['jsonld'] ?? null) ? $m['jsonld'] : [];

$social    = config('Social');
$tgLink    = $social->botLink;
$groupLink = $social->groupLink;
$siteUrl   = rtrim(base_url(), '/');

// JSON-LD @graph (Schema.org): Organization + WebSite + крошки + per-page.
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

// Контракт для partials — CI4 $this->include() НЕ пробрасывает scope (см. memory
// reference_ci4_view_helpers), поэтому передаём данные через view() helper явно.
$metaData = [
    'title'       => $title,
    'description' => $description,
    'canonical'   => $canonical,
    'robots'      => $robots,
    'ogType'      => $ogType,
    'ogImageUrl'  => $ogImageUrl,
    'ogImage'     => $ogImage,
    'keywords'    => $keywords,
    'publishedAt' => $publishedAt,
    'modifiedAt'  => $modifiedAt,
    'artSection'  => $artSection,
    'jsonLd'      => $jsonLd,
];
// CTA-ссылки шапки/подвала несут src-метку атрибуции ($tgLink выше остаётся голым — он идёт в schema.org sameAs).
$headerData = ['navCats' => $navCats, 'uri' => $uri, 'tgLink' => $social->botStart('src_site_header')];
$footerData = ['navCats' => $navCats, 'tgLink' => $social->botStart('src_site_footer'), 'groupLink' => $groupLink];
?><!DOCTYPE html>
<html lang="ru" data-theme="ash">
<head>
<?= view('site/_layout/meta', $metaData) ?>
<?= $this->renderSection('head') ?>
</head>
<body>

<?= view('site/_layout/header', $headerData) ?>

<main id="ww-main" role="main">
<?= $this->renderSection('content') ?>
</main>

<?= view('site/_layout/footer', $footerData) ?>

<script src="<?= base_url('assets/js/wildworld-ui.js') ?>?v=1" defer></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
