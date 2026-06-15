<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Объекты мира<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент</p>
        <h1 class="aui-display">Объекты мира</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск объекта…" data-table-search="#tbl-objects">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/world-objects/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-objects" data-enhance>
        <thead><tr>
            <th data-sort>Название</th><th data-sort>EN</th><th data-sort>Биом</th>
            <th class="num" data-sort>Макс.</th><th class="num" data-sort>Респаун, ч</th><th data-sort>Статус</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($objects as $object): ?>
            <tr>
                <td class="strong"><?= esc($object['name']) ?></td>
                <td class="aui-muted"><?= esc($object['name_en']) ?></td>
                <td><?= esc($object['biome_name']) ?></td>
                <td class="num"><?= esc($object['max_count']) ?></td>
                <td class="num"><?= esc($object['respawn_time']) ?></td>
                <td><?= $object['status'] === 'active' ? '<span class="aui-badge aui-badge--success"><span class="aui-dot"></span> активен</span>' : '<span class="aui-badge">неактивен</span>' ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/world-objects/edit/' . $object['id']) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                        <form action="<?= site_url('admin/world-objects/delete/' . $object['id']) ?>" method="post" onsubmit="return confirm('Удалить объект?');"><?= csrf_field() ?><button class="aui-btn aui-btn--danger aui-btn--icon" title="Удалить"><i class="ri-delete-bin-line"></i></button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($objects)): ?><tr><td colspan="7" class="aui-table__empty">Нет объектов</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
