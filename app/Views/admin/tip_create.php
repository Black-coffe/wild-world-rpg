<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новый совет</h2>

<?= $this->include('admin/partials/_tip_form', ['mode' => 'create']) ?>

<?= $this->endSection() ?>
