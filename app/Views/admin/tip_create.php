<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новый совет</h2>

<?= view('admin/partials/_tip_form', ['mode' => 'create']) ?>

<?= $this->endSection() ?>
