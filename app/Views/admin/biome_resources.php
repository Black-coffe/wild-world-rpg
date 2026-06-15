<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Ресурсы биома<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Контент · биом #<?= esc($biome['id']) ?></p>
        <h1 class="aui-display">📦 Ресурсы биома: <?= esc($biome['name']) ?></h1>
    </div>
    <div class="aui-toolbar">
        <a href="<?= site_url('admin/biomes') ?>" class="aui-btn aui-btn--ghost aui-btn--sm"><i class="ri-arrow-left-line"></i> К списку биомов</a>
    </div>
</div>

<div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-5)">
    <i class="ri-information-line"></i>
    <div>
        Read-only превью. Резолв через <span class="aui-mono">FIND_IN_SET(<?= esc($biome['id']) ?>, biome_id)</span> по
        <span class="aui-mono">resources.biome_id</span> CSV (та же логика, что у runtime gather'а).
        Редактировать привязку — на странице <a href="<?= site_url('admin/resources') ?>">ресурсов</a>.
    </div>
</div>

<?php if (empty($resources)): ?>
    <div class="aui-card"><div class="aui-empty">
        <i class="ri-inbox-line"></i>
        <p>В этом биоме не настроено ни одного добываемого ресурса.</p>
    </div></div>
<?php else: ?>
    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-stack-line"></i><span class="aui-card__title">Всего ресурсов: <?= count($resources) ?></span></div>
        <div class="aui-tablewrap">
        <table class="aui-table" data-enhance>
            <thead><tr>
                <th class="num" data-sort>#</th><th data-sort>Название</th><th data-sort>name_en</th>
                <th data-sort>Редкость</th><th class="num" data-sort>Цена</th><th class="num" data-sort>Lv</th>
                <th data-sort>Тип</th><th>biome_id (CSV)</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($resources as $r): ?>
                <tr>
                    <td class="num"><?= esc($r['id']) ?></td>
                    <td class="strong"><?= esc($r['name']) ?></td>
                    <td><span class="aui-mono"><?= esc($r['name_en'] ?? '—') ?></span></td>
                    <td><?= esc($r['rarity'] ?? '—') ?></td>
                    <td class="num"><?= esc($r['price'] ?? 0) ?></td>
                    <td class="num"><?= esc($r['level_required'] ?? 1) ?></td>
                    <td class="aui-muted"><?= esc($r['type'] ?? '—') ?></td>
                    <td><span class="aui-mono"><?= esc($r['biome_id'] ?? '—') ?></span></td>
                    <td>
                        <div class="aui-actions">
                            <a href="<?= site_url('admin/resources/edit/' . $r['id']) ?>" class="aui-btn aui-btn--subtle aui-btn--icon" title="Редактировать ресурс" aria-label="Редактировать ресурс"><i class="ri-pencil-line"></i></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
