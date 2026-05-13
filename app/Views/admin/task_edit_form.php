<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать задачу</h2>

<?= view('admin/partials/_task_form', ['mode' => 'edit', 'task' => $task]) ?>

<?= $this->endSection() ?>
