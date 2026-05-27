<?php
/**
 * Главная вики Wild World — на дизайн-системе ADR-062.
 *
 * @var list<array{key:string,title:string,count:int}> $sections
 * @var list<array{name:string,url:string}> $breadcrumbs
 */
$icons = [
    'biomes'        => '🌍',
    'resources'     => '⛏',
    'factions'      => '⚔',
    'buildings'     => '🏗',
    'weapons'       => '🔫',
    'outfits'       => '🛡',
    'world-objects' => '📍',
];

$descriptions = [
    'biomes'        => '9 биомов с уникальными ресурсами, signature-добычей и опасностями.',
    'resources'     => 'Сырьё и компоненты — что где собирать и куда применять.',
    'factions'      => 'Кланы выживших, их буфы и фракционные проекты.',
    'buildings'     => 'Постройки базы: верстаки, склады, оборона, инфраструктура.',
    'weapons'       => 'Оружие — от заточки до легендарных образцов с обвесом.',
    'outfits'       => 'Броня и одежда — resistance, armor, set-бонусы.',
    'world-objects' => 'Стратегические точки, события, аномалии мира.',
];
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $breadcrumbs ?? []]) ?>

<section class="block" style="padding-top:24px">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num">§ ВИКИ</span>
                <h1 class="mt-1 mb-0">Вики мира Wild&nbsp;World</h1>
            </div>
            <p class="desc mb-0">Справочник по миру игры. Данные берутся прямо из&nbsp;игровой БД — всегда актуальны. <span class="dim">Если в&nbsp;игре переименовали — здесь сразу.</span></p>
        </div>

        <?php if ($sections === []): ?>
            <div class="card center" style="padding:48px 24px">
                <div style="font-family:var(--font-display);font-size:48px;color:var(--text-muted);letter-spacing:.1em">∅</div>
                <h4>Вики пуста</h4>
                <p class="dim">Контент ещё не&nbsp;загружен. Загляни позже.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($sections as $s): ?>
                    <a class="card feature" href="<?= base_url('wiki/' . esc($s['key'], 'url')) ?>" style="text-decoration:none;color:inherit">
                        <div class="ico" aria-hidden="true"><?= $icons[$s['key']] ?? '§' ?></div>
                        <div class="row" style="justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:nowrap">
                            <h4 class="mb-0"><?= esc($s['title']) ?></h4>
                            <span class="badge mono"><?= (int) $s['count'] ?></span>
                        </div>
                        <p class="mt-1 mb-0" style="font-size:13px;color:var(--text-dim)"><?= esc($descriptions[$s['key']] ?? 'Раздел вики.') ?></p>
                    </a>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>
</section>

<?= $this->endSection() ?>
