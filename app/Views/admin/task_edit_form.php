<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Редактирование задачи<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент · задачи</p>
        <h1 class="aui-display">Редактирование задачи</h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/tasks') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?= view('admin/partials/_task_form', ['mode' => 'edit', 'task' => $task, 'taskHandlers' => $taskHandlers ?? []]) ?>

<?= $this->endSection() ?>
