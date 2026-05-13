<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новый ресурс</h2>

<?= view('admin/partials/_resource_form', ['mode' => 'create', 'biomes' => $biomes]) ?>

<?= $this->endSection() ?>
