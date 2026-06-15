<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>События<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент</p>
        <h1 class="aui-display">События</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск события…" data-table-search="#tbl-events">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/events/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-events" data-enhance>
        <thead><tr>
            <th data-sort>Название</th><th data-sort>EN</th><th data-sort>Тип</th><th>Биомы</th>
            <th class="num" data-sort>Длит., мин</th><th data-sort>Эффект</th><th class="num" data-sort>Значение</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td class="strong"><?= esc($event['name']) ?></td>
                <td class="aui-muted"><?= esc($event['name_english']) ?></td>
                <td><?= esc($event['event_type']) ?></td>
                <td class="aui-muted"><?= esc($event['biome_ids']) ?></td>
                <td class="num"><?= esc($event['duration']) ?></td>
                <td><?= esc($event['effect_type']) ?></td>
                <td class="num"><?= esc($event['effect_value']) ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/events/edit/' . $event['event_id']) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($events)): ?><tr><td colspan="8" class="aui-table__empty">Нет событий</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
