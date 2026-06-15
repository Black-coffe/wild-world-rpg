<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Советы в игре<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции</p>
        <h1 class="aui-display">Советы в игре</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск совета…" data-table-search="#tbl-tips">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/tips/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-tips" data-enhance>
        <thead><tr>
            <th data-sort>Название (RU)</th><th data-sort>EN</th><th data-sort>Тип</th><th>Содержание</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($tips as $tip): ?>
            <tr>
                <td class="strong"><?= esc($tip['title_ru']) ?></td>
                <td class="aui-muted"><?= esc($tip['title_en']) ?></td>
                <td><span class="aui-badge"><?= esc($tip['tip_type']) ?></span></td>
                <td class="aui-muted text-truncate"><?= esc(mb_substr((string) $tip['content'], 0, 110)) ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/tips/edit/' . $tip['id']) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                        <form action="<?= site_url('admin/tips/delete/' . $tip['id']) ?>" method="post" onsubmit="return confirm('Удалить совет?');"><?= csrf_field() ?><button class="aui-btn aui-btn--danger aui-btn--icon" title="Удалить"><i class="ri-delete-bin-line"></i></button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($tips)): ?><tr><td colspan="5" class="aui-table__empty">Нет советов</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
