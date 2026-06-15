<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Задачи<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент</p>
        <h1 class="aui-display">Задачи</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск задачи…" data-table-search="#tbl-tasks">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/tasks/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-tasks" data-enhance>
        <thead><tr>
            <th data-sort>Название</th><th>Описание</th><th class="num" data-sort>Мин, мин</th><th class="num" data-sort>Макс, мин</th>
            <th data-sort>Тип</th><th class="num" data-sort>Сложность</th><th data-sort>Лимит</th><th>Параллельно</th><th>Прерываемая</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($tasks as $task): ?>
            <tr>
                <td class="strong"><?= esc($task['name']) ?></td>
                <td class="aui-muted text-truncate"><?= esc($task['description']) ?></td>
                <td class="num"><?= esc($task['min_duration']) ?></td>
                <td class="num"><?= esc($task['max_duration']) ?></td>
                <td><?= esc($task['type']) ?></td>
                <td class="num"><?= esc($task['difficulty_level']) ?></td>
                <td><?= $task['execution_limit'] == 0 ? '<span class="aui-muted">без огр.</span>' : esc($task['execution_limit']) ?></td>
                <td><?= $task['parallel_execution_allowed'] ? '<span class="aui-badge aui-badge--success">да</span>' : '<span class="aui-badge">нет</span>' ?></td>
                <td><?= $task['interruptible'] ? '<span class="aui-badge aui-badge--success">да</span>' : '<span class="aui-badge">нет</span>' ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/tasks/edit/' . $task['id']) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($tasks)): ?><tr><td colspan="10" class="aui-table__empty">Нет задач</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
