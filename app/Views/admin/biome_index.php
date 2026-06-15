<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Биомы<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент</p>
        <h1 class="aui-display">Биомы</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск биома…" data-table-search="#tbl-biomes">
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-biomes" data-enhance>
        <thead><tr>
            <th data-sort>Название</th><th data-sort>Опасность</th><th class="num" data-sort>Ур.</th>
            <th data-sort>Сложность выживания</th><th class="num" data-sort>Ур.</th><th class="num" data-sort>Встречаемость, %</th><th data-sort>Тип</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($biomes as $biome): ?>
            <tr>
                <td class="strong"><?= esc($biome['name']) ?></td>
                <td><?= esc($biome['danger_level_text']) ?></td>
                <td class="num"><?= esc($biome['danger_level']) ?></td>
                <td><?= esc($biome['survival_difficulty_text']) ?></td>
                <td class="num"><?= esc($biome['survival_difficulty']) ?></td>
                <td class="num"><?= esc($biome['occurrence_rate']) ?></td>
                <td><?= esc($biome['biome_type']) ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/biomes/edit/' . $biome['id']) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/biomes/' . $biome['id'] . '/resources') ?>" title="Ресурсы биома"><i class="ri-box-3-line"></i></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($biomes)): ?><tr><td colspan="8" class="aui-table__empty">Нет биомов</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
