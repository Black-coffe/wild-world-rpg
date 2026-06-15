<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Квесты<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент</p>
        <h1 class="aui-display">Квесты</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск квеста…" data-table-search="#tbl-quests">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/quests/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-quests" data-enhance>
        <thead><tr>
            <th data-sort>Название</th><th data-sort>EN</th><th data-sort>Статус</th><th>Описание</th>
            <th class="num" data-sort>Награда</th><th data-sort>Тип награды</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($quests as $quest): ?>
            <tr>
                <td class="strong"><?= esc($quest['title_ru']) ?></td>
                <td class="aui-muted"><?= esc($quest['title_en']) ?></td>
                <td><span class="aui-badge"><?= esc($quest['status']) ?></span></td>
                <td class="aui-muted text-truncate"><?= esc(mb_substr((string) $quest['description'], 0, 100)) ?></td>
                <td class="num"><?= esc($quest['reward']) ?></td>
                <td><?= esc($quest['reward_type']) ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/quests/edit/' . $quest['id']) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                        <form action="<?= site_url('admin/quests/delete/' . $quest['id']) ?>" method="post" onsubmit="return confirm('Удалить квест?');"><?= csrf_field() ?><button class="aui-btn aui-btn--danger aui-btn--icon" title="Удалить"><i class="ri-delete-bin-line"></i></button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($quests)): ?><tr><td colspan="7" class="aui-table__empty">Нет квестов</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
