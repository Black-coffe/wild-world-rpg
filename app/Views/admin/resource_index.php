<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Ресурсы<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент</p>
        <h1 class="aui-display">Ресурсы</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск ресурса…" data-table-search="#tbl-resources">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/resources/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-resources" data-enhance>
        <thead><tr>
            <th data-sort>Название</th><th data-sort>EN</th><th>Иконка</th><th data-sort>Тип</th>
            <th data-sort>Биом</th><th class="num" data-sort>Цена</th><th data-sort>Редкость</th>
            <th class="num" data-sort>Торговцу</th><th class="num" data-sort>Ур.</th><th>Видимость</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($resources as $resource): ?>
            <tr>
                <td class="strong"><?= esc($resource['name']) ?></td>
                <td class="aui-muted"><?= esc($resource['name_en']) ?></td>
                <td class="aui-emoji"><?= $resource['icon_text'] ?></td>
                <td><?= esc($resource['translated_types']) ?></td>
                <td class="aui-muted"><?= esc($resource['biome_names']) ?></td>
                <td class="num"><?= esc($resource['price']) ?></td>
                <td><?= esc($resource['rarity']) ?></td>
                <td class="num"><?= esc($resource['initial_quantity']) ?></td>
                <td class="num"><?= esc($resource['level_required']) ?></td>
                <td><?= $resource['is_tradeable'] ? '<span class="aui-badge aui-badge--success">Да</span>' : '<span class="aui-badge">Нет</span>' ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/resources/edit/' . $resource['id']) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                        <form action="<?= site_url('admin/resources/delete/' . $resource['id']) ?>" method="post" onsubmit="return confirm('Удалить ресурс?');"><?= csrf_field() ?><button class="aui-btn aui-btn--danger aui-btn--icon" title="Удалить"><i class="ri-delete-bin-line"></i></button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($resources)): ?><tr><td colspan="11" class="aui-table__empty">Нет ресурсов</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
