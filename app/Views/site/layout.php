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

$botUser = env('telegram.BOT_USERNAME', '@wildworldrpg_bot');
$botUser = is_string($botUser) ? ltrim($botUser, '@') : 'wildworldrpg_bot';
$tgLink  = 'https://t.me/' . $botUser;

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
    <meta name="robots" content="<?= esc($robots) ?>">
    <link rel="canonical" href="<?= esc($canonical) ?>">

    <!-- Open Graph -->
    <meta property="og:site_name" content="Wild World">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:type" content="<?= esc($ogType) ?>">
    <meta property="og:title" content="<?= esc($title) ?>">
    <meta property="og:description" content="<?= esc($description) ?>">
    <meta property="og:url" content="<?= esc($canonical) ?>">
    <?php if ($ogImage !== null): ?>
    <meta property="og:image" content="<?= esc($ogImage) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $ogImage !== null ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= esc($title) ?>">
    <meta name="twitter:description" content="<?= esc($description) ?>">
    <?php if ($ogImage !== null): ?>
    <meta name="twitter:image" content="<?= esc($ogImage) ?>">
    <?php endif; ?>

    <link rel="icon" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('css/vendors/bootstrap.min.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=PT+Sans:ital,wght@0,400;0,700;1,400&display=swap">
    <link rel="stylesheet" href="<?= base_url('css/site.css') ?>">
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
                        <a class="ww-btn ww-btn-play" href="<?= esc($tgLink, 'url') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
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
                <a class="ww-btn ww-btn-play" href="<?= esc($tgLink, 'url') ?>" target="_blank" rel="noopener">▶ Начать играть</a>
            </div>
            <div class="col-lg-3 col-6">
                <h6 class="ww-foot-h">Разделы</h6>
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
                <h6 class="ww-foot-h">Сообщество</h6>
                <ul class="ww-foot-links">
                    <li><a href="<?= esc($tgLink, 'url') ?>" target="_blank" rel="noopener">Telegram-бот</a></li>
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
