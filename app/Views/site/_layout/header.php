<?php
/**
 * Шапка публичного сайта: topbar (desktop nav) + off-canvas drawer (mobile).
 *
 * Контракт переменных:
 * @var array<int,array{slug:string,name:string}|array<string,mixed>> $navCats
 * @var string $uri
 * @var string $tgLink
 *
 * Поведение:
 * - Активная ссылка определяется по $uri (см. layout.php).
 * - На ≤900px вместо горизонтальной навигации показан burger → drawer.
 * - JS-логика burger/drawer/dropdown — public/assets/js/wildworld-ui.js.
 */
$navItems = [
    ['href' => base_url(),              'label' => 'Главная', 'match' => fn($u) => $u === ''],
    ['href' => base_url('devblog'),     'label' => 'Девблог', 'match' => fn($u) => $u === 'devblog'],
    ['href' => base_url('wiki'),        'label' => 'Вики',    'match' => fn($u) => str_starts_with($u, 'wiki')],
    ['href' => base_url('map'),         'label' => 'Карта',   'match' => fn($u) => $u === 'map'],
    ['href' => base_url('achievements'), 'label' => '🏅 Достижения', 'match' => fn($u) => $u === 'achievements'],
];
?>
<a class="skip-link" href="#ww-main">К содержимому</a>

<header class="topbar" role="banner">
    <div class="container topbar-inner">
        <a class="brand" href="<?= base_url() ?>" aria-label="Wild World — главная">
            <span class="mark" aria-hidden="true"></span>
            Wild&nbsp;World
        </a>

        <nav class="topnav desktop-nav" aria-label="Основная навигация">
            <?php foreach ($navItems as $n): ?>
                <a href="<?= esc($n['href'], 'attr') ?>" class="<?= $n['match']($uri) ? 'is-active' : '' ?>"><?= esc($n['label']) ?></a>
            <?php endforeach ?>

            <?php if (is_array($navCats) && $navCats !== []): ?>
            <div class="dropdown">
                <a href="#" data-dropdown-trigger class="<?= preg_match('~^(devblog|informacija|mestnost|syre|letopis-mira|npc|kraft)$~', $uri) ? 'is-active' : '' ?>">Разделы ▾</a>
                <div class="menu">
                    <?php foreach ($navCats as $c): ?>
                        <?php if (is_array($c) && is_string($c['slug'] ?? null)): ?>
                            <a class="item" href="<?= base_url(esc($c['slug'], 'url')) ?>"><?= esc($c['name'] ?? $c['slug']) ?></a>
                        <?php endif ?>
                    <?php endforeach ?>
                </div>
            </div>
            <?php endif ?>

            <a class="btn primary sm" href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener" style="margin-left:8px">▶ Играть</a>
        </nav>

        <button class="burger" id="ww-burger" aria-label="Меню" aria-expanded="false" aria-controls="ww-drawer">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- Off-canvas mobile drawer -->
<div class="drawer" id="ww-drawer" aria-hidden="true">
    <div class="overlay" data-close="drawer"></div>
    <div class="panel">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:8px;">
            <span class="caption">МЕНЮ</span>
            <button class="btn ghost sm icon-only" data-close="drawer" aria-label="Закрыть">✕</button>
        </div>
        <?php foreach ($navItems as $n): ?>
            <a href="<?= esc($n['href'], 'attr') ?>" class="<?= $n['match']($uri) ? 'is-active' : '' ?>"><?= esc($n['label']) ?></a>
        <?php endforeach ?>
        <?php if (is_array($navCats) && $navCats !== []): ?>
            <div class="caption" style="margin-top:14px;padding-bottom:6px;border-bottom:1px solid var(--border)">РАЗДЕЛЫ</div>
            <?php foreach ($navCats as $c): ?>
                <?php if (is_array($c) && is_string($c['slug'] ?? null)): ?>
                    <a href="<?= base_url(esc($c['slug'], 'url')) ?>"><?= esc($c['name'] ?? $c['slug']) ?></a>
                <?php endif ?>
            <?php endforeach ?>
        <?php endif ?>
        <a class="btn primary full" style="margin-top:16px" href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
    </div>
</div>
