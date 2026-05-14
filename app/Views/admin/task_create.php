<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новую задачу</h2>

<?= view('admin/partials/_task_form', ['mode' => 'create', 'taskHandlers' => $taskHandlers ?? []]) ?>

<?= $this->endSection() ?>
