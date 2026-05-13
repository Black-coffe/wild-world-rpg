<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Добавить новый квест</h2>

<?= $this->include('admin/partials/_quest_form', ['mode' => 'create']) ?>

<?= $this->endSection() ?>
