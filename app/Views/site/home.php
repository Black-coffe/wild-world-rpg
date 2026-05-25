<?php
/**
 * @var list<array<string,mixed>> $latest
 * @var list<array{key:string,title:string,count:int}> $wikiSections
 * @var list<array<string,mixed>> $factions
 */
$tgUser = env('telegram.BOT_USERNAME', '@wildworldrpg_bot');
$tgUser = is_string($tgUser) ? ltrim($tgUser, '@') : 'wildworldrpg_bot';
$tg     = 'https://t.me/' . $tgUser;
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="ww-hero">
    <div class="container">
        <div class="ww-hero-inner">
            <span class="ww-eyebrow">Текстовая MMORPG в Telegram</span>
            <h1 class="ww-hero-title">Мир рухнул. <br>Твоя история — только начинается.</h1>
            <p class="ww-hero-lead">Огромный открытый мир 100×100&nbsp;км. Исследуй земли, добывай ресурсы, крафти снаряжение, строй базу, вступай во фракции и сражайся с другими выжившими — прямо в чате Telegram.</p>
            <div class="ww-hero-cta">
                <a class="ww-btn ww-btn-play ww-btn-lg" href="<?= esc($tg, 'url') ?>" target="_blank" rel="noopener">▶ Играть бесплатно</a>
                <a class="ww-btn ww-btn-ghost ww-btn-lg" href="<?= base_url('wiki') ?>">Изучить мир</a>
            </div>
        </div>
    </div>
</section>

<section class="ww-section">
    <div class="container">
        <div class="row g-4">
            <?php
            $features = [
                ['🗺', 'Исследование', 'Открывай клетки карты, рассеивай туман войны и находи редкие локации и стратегические объекты.'],
                ['⛏', 'Добыча и крафт', 'Собирай сырьё по биомам и создавай снаряжение — от бинтов до легендарного оружия на верстаках трёх уровней.'],
                ['🏗', 'База и постройки', 'Ставь лагерь, возводи мастерские, теплицы, арсеналы и оборону, нанимай роботов-помощников.'],
                ['⚔', 'Фракции и PvP', 'Выбери сторону, бейся в полевых дуэлях и веди мир к одному из финальных сценариев.'],
            ];
            foreach ($features as $f): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="ww-feature">
                        <div class="ww-feature-ico"><?= $f[0] ?></div>
                        <h3><?= esc($f[1]) ?></h3>
                        <p><?= esc($f[2]) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($latest !== []): ?>
<section class="ww-section ww-section-alt">
    <div class="container">
        <div class="ww-section-head">
            <h2>Девблог и новости</h2>
            <a class="ww-link" href="<?= base_url('devblog') ?>">Все записи →</a>
        </div>
        <div class="row g-4">
            <?php foreach ($latest as $p): ?>
                <?php
                $slug  = is_string($p['slug'] ?? null) ? $p['slug'] : '';
                $img   = is_string($p['featured_image'] ?? null) && $p['featured_image'] !== '' ? base_url($p['featured_image']) : null;
                $date  = is_string($p['published_at'] ?? null) ? date('d.m.Y', (int) strtotime($p['published_at'])) : '';
                $exc   = is_string($p['excerpt'] ?? null) ? mb_substr($p['excerpt'], 0, 140) : '';
                ?>
                <div class="col-md-6 col-lg-4">
                    <a class="ww-card" href="<?= base_url(esc($slug, 'url')) ?>">
                        <?php if ($img !== null): ?>
                            <div class="ww-card-img" style="background-image:url('<?= esc($img, 'attr') ?>')"></div>
                        <?php else: ?>
                            <div class="ww-card-img ww-card-img-empty"></div>
                        <?php endif; ?>
                        <div class="ww-card-body">
                            <?php if ($date !== ''): ?><span class="ww-card-date"><?= esc($date) ?></span><?php endif; ?>
                            <h3 class="ww-card-title"><?= esc($p['title'] ?? '') ?></h3>
                            <p class="ww-card-exc"><?= esc($exc) ?><?= mb_strlen((string)($p['excerpt'] ?? '')) > 140 ? '…' : '' ?></p>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($factions !== []): ?>
<section class="ww-section">
    <div class="container">
        <div class="ww-section-head"><h2>Фракции мира</h2><a class="ww-link" href="<?= base_url('wiki/factions') ?>">Подробнее →</a></div>
        <div class="row g-4">
            <?php foreach ($factions as $f): ?>
                <?php $fimg = is_string($f['image'] ?? null) && $f['image'] !== '' ? base_url($f['image']) : null; ?>
                <div class="col-6 col-lg-3">
                    <div class="ww-faction">
                        <?php if ($fimg !== null): ?><div class="ww-faction-img" style="background-image:url('<?= esc($fimg, 'attr') ?>')"></div><?php endif; ?>
                        <h3><?= esc($f['name'] ?? '') ?></h3>
                        <p><?= esc(mb_substr((string)($f['unique_ability'] ?? $f['description'] ?? ''), 0, 90)) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="ww-section ww-section-alt">
    <div class="container">
        <div class="ww-section-head"><h2>Вики мира</h2><a class="ww-link" href="<?= base_url('wiki') ?>">Открыть вики →</a></div>
        <div class="ww-chips">
            <?php foreach ($wikiSections as $s): ?>
                <a class="ww-chip" href="<?= base_url('wiki/' . esc($s['key'], 'url')) ?>"><?= esc($s['title']) ?> <span><?= (int) $s['count'] ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="ww-cta-band">
    <div class="container text-center">
        <h2>Готов выжить в Wild World?</h2>
        <p>Запусти бота в Telegram и начни свою историю прямо сейчас — бесплатно.</p>
        <a class="ww-btn ww-btn-play ww-btn-lg" href="<?= esc($tg, 'url') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
    </div>
</section>

<?= $this->endSection() ?>
