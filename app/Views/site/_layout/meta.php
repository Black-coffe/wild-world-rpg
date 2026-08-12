<?php
/**
 * Head-блок публичного layout. SEO + OG + Twitter + JSON-LD + шрифты + дизайн-система.
 *
 * Контракт переменных (см. layout.php):
 * @var string             $title
 * @var string             $description
 * @var string             $canonical
 * @var string             $robots
 * @var string             $ogType
 * @var string             $ogImageUrl
 * @var string|null        $ogImage
 * @var string             $keywords
 * @var string|null        $publishedAt
 * @var string|null        $modifiedAt
 * @var string|null        $artSection
 * @var string             $jsonLd
 *
 * ADR-062 — flat-stencil design system. Используем wildworld-ui.css.
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title><?= esc($title) ?></title>
<meta name="description" content="<?= esc($description) ?>">
<?php if ($keywords !== ''): ?>
<meta name="keywords" content="<?= esc($keywords) ?>">
<?php endif ?>
<meta name="robots" content="<?= esc($robots) ?>">
<meta name="theme-color" content="#0E0B07">
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
<?php endif ?>
<?php if ($ogType === 'article'): ?>
    <?php if ($publishedAt !== null): ?><meta property="article:published_time" content="<?= esc($publishedAt) ?>"><?php endif ?>
    <?php if ($modifiedAt !== null): ?><meta property="article:modified_time" content="<?= esc($modifiedAt) ?>"><?php endif ?>
    <?php if ($artSection !== null): ?><meta property="article:section" content="<?= esc($artSection) ?>"><?php endif ?>
<?php endif ?>

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= esc($title) ?>">
<meta name="twitter:description" content="<?= esc($description) ?>">
<meta name="twitter:image" content="<?= esc($ogImageUrl) ?>">

<!-- Favicons -->
<link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
<link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= base_url('favicon-32x32.png') ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= base_url('favicon-16x16.png') ?>">
<link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
<link rel="manifest" href="<?= base_url('site.webmanifest') ?>">

<!-- Шрифты — Oswald (display) · Manrope (body) · JetBrains Mono (numbers) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap">

<!-- Дизайн-система (ADR-062) -->
<link rel="stylesheet" href="<?= base_url('assets/css/wildworld-ui.css') ?>?v=4">

<!-- Schema.org JSON-LD -->
<script type="application/ld+json"><?= $jsonLd ?></script>
