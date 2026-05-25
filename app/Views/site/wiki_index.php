<?php
/**
 * @var list<array{key:string,title:string,count:int}> $sections
 */
$icons = [
    'biomes' => '🌍', 'resources' => '⛏', 'factions' => '⚔', 'buildings' => '🏗',
    'weapons' => '🔫', 'outfits' => '🛡', 'world-objects' => '📍',
];
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="ww-page-head">
    <div class="container">
        <h1>Вики мира Wild World</h1>
        <p class="ww-muted">Справочник по миру игры. Данные берутся прямо из игры — всегда актуальны.</p>
    </div>
</section>

<section class="ww-section">
    <div class="container">
        <div class="row g-4">
            <?php foreach ($sections as $s): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <a class="ww-wiki-card" href="<?= base_url('wiki/' . esc($s['key'], 'url')) ?>">
                        <span class="ww-wiki-ico"><?= $icons[$s['key']] ?? '📖' ?></span>
                        <span class="ww-wiki-title"><?= esc($s['title']) ?></span>
                        <span class="ww-wiki-count"><?= (int) $s['count'] ?></span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
