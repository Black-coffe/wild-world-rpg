<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новую задачу</h2>

<?= $this->include('admin/partials/_task_form', ['mode' => 'create']) ?>

<?= $this->endSection() ?>
