<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Добавить новый объект в игровой мир</h2>

<?= $this->include('admin/partials/_world_object_form', ['mode' => 'create', 'biomes' => $biomes]) ?>

<?= $this->endSection() ?>
