<?php
/**
 * Главная wildworld.fun — на дизайн-системе ADR-062 («Найденная фотоплёнка», flat-stencil).
 *
 * @var list<array<string,mixed>> $latest
 * @var list<array{key:string,title:string,count:int}> $wikiSections
 * @var list<array<string,mixed>> $factions
 */
$social = config('Social');
$tg     = $social->botLink;
$group  = $social->groupLink;

$features = [
    ['🗺', 'Исследование',     'Открывай клетки карты, рассеивай туман войны и находи редкие локации и стратегические объекты.'],
    ['⛏', 'Добыча и крафт',    'Собирай сырьё по биомам и создавай снаряжение — от бинтов до легендарного оружия на верстаках трёх уровней.'],
    ['🏗', 'База и постройки', 'Ставь лагерь, возводи мастерские, теплицы, арсеналы и оборону, нанимай роботов-помощников.'],
    ['⚔', 'Фракции и PvP',    'Выбери сторону, бейся в полевых дуэлях и веди мир к одному из финальных сценариев.'],
];
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<!-- HERO -->
<section class="hero">
    <div class="container">
        <div>
            <div class="row gap-1">
                <span class="stamp">Сводка · Wild World</span>
                <span class="badge mono">текстовая MMORPG · Telegram</span>
            </div>
            <h1 class="h-display">Мир рухнул.<br>Твоя&nbsp;история — только&nbsp;начинается.</h1>
            <p class="lead">Огромный открытый мир 100×100&nbsp;км. Исследуй земли, добывай ресурсы, крафти снаряжение, строй базу, вступай во фракции и сражайся с другими выжившими — прямо в чате Telegram.</p>
            <div class="row mt-3">
                <a class="btn primary lg" href="<?= esc($tg, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
                <a class="btn lg" href="<?= base_url('wiki') ?>">Изучить мир</a>
            </div>
            <div class="meta">
                <span><b>Платформа</b> — Telegram</span>
                <span><b>Жанр</b> — MMORPG · постапок · текст</span>
                <span><b>P2W</b> — нет, прогресс за&nbsp;время и&nbsp;решения</span>
            </div>
        </div>
        <aside class="hero-aside">
            <p>Караваны теперь чаще приходят в&nbsp;Реки.</p>
            <p>В&nbsp;Тундре зафиксированы вспышки. Прогноз — метеоритный дождь после полуночи серверного.</p>
            <p>Дойдут не&nbsp;все. Запомним кто.</p>
        </aside>
    </div>
</section>

<!-- ЧТО ВНУТРИ -->
<section class="block">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num">§ 01</span>
                <h2 class="mt-1 mb-0">Что вас ждёт в Wild World</h2>
            </div>
            <div class="desc">Четыре опоры геймплея. Каждая работает как с&nbsp;первой минуты, так и&nbsp;через 200&nbsp;часов.</div>
        </div>
        <div class="grid grid-4">
            <?php foreach ($features as [$ico, $name, $desc]): ?>
                <div class="card feature">
                    <div class="ico" aria-hidden="true"><?= $ico ?></div>
                    <h4 class="mb-0"><?= esc($name) ?></h4>
                    <p class="mt-1"><?= esc($desc) ?></p>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</section>

<!-- ДЕВБЛОГ -->
<?php if ($latest !== []): ?>
<section class="block">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num">§ 02</span>
                <h2 class="mt-1 mb-0">Девблог и новости</h2>
            </div>
            <a class="btn ghost sm" href="<?= base_url('devblog') ?>">Все записи →</a>
        </div>
        <div class="grid grid-3">
            <?php foreach ($latest as $p): ?>
                <?php
                    $slug = is_string($p['slug'] ?? null) ? $p['slug'] : '';
                    $img  = is_string($p['featured_image'] ?? null) && $p['featured_image'] !== '' ? base_url($p['featured_image']) : null;
                    $date = is_string($p['published_at'] ?? null) ? date('d.m.Y', (int) strtotime($p['published_at'])) : '';
                    $exc  = is_string($p['excerpt'] ?? null) ? mb_substr(strip_tags($p['excerpt']), 0, 140) : '';
                ?>
                <article class="item-card">
                    <a href="<?= base_url(esc($slug, 'url')) ?>" style="display:contents;color:inherit">
                        <div class="img" <?= $img !== null ? 'style="background-image:url(\'' . esc($img, 'attr') . '\')"' : '' ?>>
                            <?php if ($img === null): ?><div class="placeholder">§</div><?php endif ?>
                            <div class="sheen"></div>
                        </div>
                        <div class="meta">
                            <?php if ($date !== ''): ?><div class="caption"><?= esc($date) ?></div><?php endif ?>
                            <div class="name"><?= esc($p['title'] ?? '') ?></div>
                            <p class="dim mb-0" style="font-size:13px;line-height:1.5"><?= esc($exc) ?><?= mb_strlen((string)($p['excerpt'] ?? '')) > 140 ? '…' : '' ?></p>
                        </div>
                    </a>
                </article>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>

<!-- ФРАКЦИИ -->
<?php if ($factions !== []): ?>
<section class="block">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num">§ 03</span>
                <h2 class="mt-1 mb-0">Фракции мира</h2>
            </div>
            <a class="btn ghost sm" href="<?= base_url('wiki/factions') ?>">Все фракции →</a>
        </div>
        <div class="grid grid-4">
            <?php foreach ($factions as $f): ?>
                <?php $fimg = is_string($f['image'] ?? null) && $f['image'] !== '' ? base_url($f['image']) : null; ?>
                <article class="item-card">
                    <div class="img" <?= $fimg !== null ? 'style="background-image:url(\'' . esc($fimg, 'attr') . '\')"' : '' ?>>
                        <?php if ($fimg === null): ?><div class="placeholder">⚑</div><?php endif ?>
                    </div>
                    <div class="meta">
                        <div class="name"><?= esc($f['name'] ?? '') ?></div>
                        <p class="dim mb-0" style="font-size:13px;line-height:1.5"><?= esc(mb_substr(strip_tags((string)($f['unique_ability'] ?? $f['description'] ?? '')), 0, 110)) ?></p>
                    </div>
                </article>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>

<!-- ВИКИ -->
<?php if ($wikiSections !== []): ?>
<section class="block">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num">§ 04</span>
                <h2 class="mt-1 mb-0">Вики мира</h2>
            </div>
            <a class="btn ghost sm" href="<?= base_url('wiki') ?>">Открыть вики →</a>
        </div>
        <div class="row gap-2">
            <?php foreach ($wikiSections as $s): ?>
                <a class="ticker" href="<?= base_url('wiki/' . esc($s['key'], 'url')) ?>" style="text-decoration:none;color:var(--text)">
                    <span class="ico" aria-hidden="true">§</span>
                    <b><?= esc($s['title']) ?></b>
                    <span class="dim"><?= (int) $s['count'] ?></span>
                </a>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>

<!-- CTA -->
<section class="block">
    <div class="container">
        <div class="cta">
            <div>
                <span class="kicker">Сводка из&nbsp;Wild World</span>
                <h2 class="mb-0">Готов выжить?<br>Запусти бота&nbsp;— и&nbsp;через минуту ты&nbsp;в&nbsp;мире.</h2>
                <p class="lead mt-2">Никаких регистраций. Никакого P2W. Только Telegram, ты&nbsp;и&nbsp;100×100&nbsp;км пустоши.</p>
                <div class="row mt-3">
                    <a class="btn primary lg" href="<?= esc($tg, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
                    <a class="btn lg" href="<?= esc($group, 'attr') ?>" target="_blank" rel="noopener">💬 Сообщество</a>
                </div>
            </div>
            <div class="stack" style="gap:14px">
                <div class="quote">Связь с&nbsp;«Тропой Огней» восстановлена. Они&nbsp;дошли. Все.<span class="src">— Радист, сводка №&nbsp;42</span></div>
                <div class="row mono" style="gap:18px;color:var(--text-dim);font-size:13px">
                    <span>биомов · <b style="color:var(--accent)">9</b></span>
                    <span>фракций · <b>4</b></span>
                    <span>зданий · <b>16</b></span>
                </div>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
